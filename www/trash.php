<?php

namespace KaraDAV;

use function KD2\_;

require_once __DIR__ . '/_inc.php';

$tpl->register_modifier('hsize', function ($bytes): string {
	$bytes = (float) $bytes;
	$u = ['B', 'KB', 'MB', 'GB', 'TB'];
	$i = 0;

	while ($bytes >= 1024 && $i < 4) {
		$bytes /= 1024;
		$i++;
	}

	return ($i ? number_format($bytes, $bytes < 10 ? 1 : 0) : (string) (int) $bytes) . ' ' . $u[$i];
});

/**
 * A trash "context": whose trash, and (for a folder shared with the current user)
 * the only part of it that may be seen: items deleted from inside that folder.
 */
function trash_ctx(User $owner, ?object $mount = null): object
{
	$root = rtrim($owner->path, '/') . '/';

	@mkdir($root . '.trash/files', 0770, true);
	@mkdir($root . '.trash/info', 0770, true);

	return (object) [
		'owner' => $owner, 'root' => $root,
		'files' => $root . '.trash/files/', 'info' => $root . '.trash/info/',
		'mount' => $mount, 'scope' => $mount ? $mount->path : null,
	];
}

/** @return array [original path (no leading slash), deletion time, login of the user who deleted it] */
function trash_info(object $c, string $name): array
{
	$info = @parse_ini_file($c->info . $name . '.trashinfo', false, INI_SCANNER_RAW) ?: [];
	$path = isset($info['Path']) ? trim(rawurldecode($info['Path']), '/') : $name;

	if ($path === '' || preg_match('!(?:^|/)\.\.?(?:/|$)!', $path) || 0 === strpos($path, '.trash') || str_contains($path, "\0")) {
		$path = $name;
	}

	return [$path, isset($info['DeletionDate']) ? (int) strtotime($info['DeletionDate']) : 0, isset($info['DeletedBy']) ? rawurldecode($info['DeletedBy']) : ''];
}

/** Is this top-level trash item visible in this context? (always for your own trash) */
function trash_visible(object $c, string $name): bool
{
	if ($c->scope === null) {
		return true;
	}

	[$path] = trash_info($c, $name);
	return 0 === strpos($path, $c->scope . '/');
}

function trash_stats(string $path): array
{
	if (!is_dir($path)) {
		return [(int) @filesize($path), 1];
	}

	$size = $count = 0;

	foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $f) {
		if ($f->isFile()) {
			$size += $f->getSize();
			$count++;
		}
	}

	return [$size, $count];
}

function trash_forget_index(object $c, string $name): void
{
	$db = DB::getInstance();
	$p = '.trash/files/' . $name;
	$db->run('DELETE FROM files WHERE user = ? AND (path = ? OR path LIKE ? ESCAPE \'\\\');',
		$c->owner->id, $p, $db->getPathLikeExpression($p));
}

/** Move $src to $dest; folders that already exist are merged, files that already exist are kept and the restored one gets a new name. Returns the final path. */
function trash_move(string $src, string $dest): string
{
	if (!file_exists($dest)) {
		if (!@rename($src, $dest)) {
			throw new UserException(_('Could not restore: ') . basename($dest));
		}

		return $dest;
	}

	if (is_dir($src) && is_dir($dest)) {
		foreach (scandir($src) as $item) {
			if ($item !== '.' && $item !== '..') {
				trash_move($src . '/' . $item, $dest . '/' . $item);
			}
		}

		@rmdir($src);
		return $dest;
	}

	$ext = is_dir($src) ? '' : (pathinfo($dest, PATHINFO_EXTENSION) !== '' ? '.' . pathinfo($dest, PATHINFO_EXTENSION) : '');
	$base = $ext !== '' ? substr($dest, 0, -strlen($ext)) : $dest;
	$i = 1;

	do {
		$alt = $base . ' (restored' . ($i > 1 ? ' ' . $i : '') . ')' . $ext;
		$i++;
	} while (file_exists($alt));

	if (!@rename($src, $alt)) {
		throw new UserException(_('Could not restore: ') . basename($dest));
	}

	return $alt;
}

function trash_restore(object $c, string $name): void
{
	$src = $c->files . $name;

	if (!file_exists($src) || !trash_visible($c, $name)) {
		return;
	}

	[$path] = trash_info($c, $name);
	$dest = $c->root . $path;

	// Re-create missing parent folders
	$first_new = null;
	$acc = '';

	foreach (explode('/', dirname($path)) as $part) {
		if ($part === '' || $part === '.') {
			continue;
		}

		$acc .= ($acc === '' ? '' : '/') . $part;

		if (!file_exists($c->root . $acc)) {
			$first_new ??= $acc;
			@mkdir($c->root . $acc, 0770);
		}
	}

	$final = trash_move($src, $dest);

	@unlink($c->info . $name . '.trashinfo');
	trash_forget_index($c, $name);

	Storage::indexFiles($c->owner, $first_new ?? substr($final, strlen($c->root)));
}

function trash_purge(object $c, string $name): void
{
	$p = $c->files . $name;

	if (is_dir($p)) {
		Storage::deleteDirectory($p);
	}
	else {
		@unlink($p);
	}

	@unlink($c->info . $name . '.trashinfo');
	trash_forget_index($c, $name);
}

// ---- contexts available to the current user -------------------------------------------------

$own = trash_ctx($logged_user);

// Folders shared with me (with edit rights): I can see and restore what was deleted from inside them
$shared = [];

foreach (UserShares::forRecipient($logged_user->id) as $share) {
	if (!($share->permissions & UserShares::PERM_WRITE)) {
		continue;
	}

	$owner = $users->getById((int) $share->owner);

	if (!$owner || null === Shares::resolve($owner->path, $share->path)) {
		continue;
	}

	$c = trash_ctx($owner, $share);
	$c->label = $owner->label;
	$shared[(int) $share->id] = $c;
}

/** "0|name" is in my own trash, "<share id>|name" in the trash of a folder shared with me */
function trash_parse(string $value): array
{
	global $own, $shared;

	[$id, $name] = explode('|', $value, 2) + [1 => ''];
	$c = $id === '0' ? $own : ($shared[(int) $id] ?? null);

	if (!$c || $name === '' || $name !== basename($name) || $name === '.' || $name === '..'
		|| !file_exists($c->files . $name) || !trash_visible($c, $name)) {
		return [null, null];
	}

	return [$c, $name];
}

function trash_selected(bool $own_only = false): array
{
	$out = [];

	foreach ((array) ($_POST['items'] ?? []) as $value) {
		[$c, $name] = trash_parse((string) $value);

		if ($c && (!$own_only || $c->scope === null)) {
			$out[] = [$c, $name];
		}
	}

	if (!$out) {
		throw new UserException(_('Please select at least one item'));
	}

	return $out;
}

form_exec_if('restore', function () {
	$n = 0;

	foreach (trash_selected() as [$c, $name]) {
		trash_restore($c, $name);
		$n++;
	}

	header('Location: ' . WWW_URL . 'trash.php?restored=' . $n);
	exit;
});

form_exec_if('purge', function () {
	$n = 0;

	foreach (trash_selected(true) as [$c, $name]) {
		trash_purge($c, $name);
		$n++;
	}

	header('Location: ' . WWW_URL . 'trash.php?deleted=' . $n);
	exit;
});

$notice = null;

if (isset($_GET['restored'])) {
	$notice = sprintf(_('%d item(s) restored.'), (int) $_GET['restored']);
}
elseif (isset($_GET['deleted'])) {
	$notice = sprintf(_('%d item(s) deleted permanently.'), (int) $_GET['deleted']);
}

// ---- view ----------------------------------------------------------------------------------

$open = trim((string) ($_GET['open'] ?? ''), '/');
$mid = (string) ($_GET['m'] ?? '0');
$crumbs = [];
$items = [];
$total_size = 0;
$has_shared = $has_own = false;

$oc = $mid === '0' ? $own : ($shared[(int) $mid] ?? null);
$top = explode('/', $open, 2)[0];

if ($open !== '' && $oc && !preg_match('!(?:^|/)\.\.?(?:/|$)!', $open) && !str_contains($open, "\0")
	&& $top !== '' && trash_visible($oc, $top) && is_dir($oc->files . $open)) {
	// Inside a trashed folder (read-only view)
	$acc = '';

	foreach (explode('/', $open) as $part) {
		$acc .= ($acc === '' ? '' : '/') . $part;
		$crumbs[] = ['name' => $part, 'url' => 'trash.php?m=' . $mid . '&open=' . rawurlencode($acc)];
	}

	foreach (scandir($oc->files . $open) ?: [] as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}

		$full = $oc->files . $open . '/' . $item;
		$dir = is_dir($full);
		[$size, $count] = trash_stats($full);

		$items[] = (object) [
			'name' => $item, 'is_dir' => $dir, 'size' => $size, 'count' => $count,
			'date' => date('Y-m-d H:i', (int) @filemtime($full)),
			'url' => $dir ? 'trash.php?m=' . $mid . '&open=' . rawurlencode($open . '/' . $item) : null,
		];
		$total_size += $size;
	}

	usort($items, fn($a, $b) => [!$a->is_dir, strtolower($a->name)] <=> [!$b->is_dir, strtolower($b->name)]);
}
else {
	$open = '';

	foreach ([0 => $own] + $shared as $id => $c) {
		foreach (scandir($c->files) ?: [] as $item) {
			if ($item === '.' || $item === '..' || !trash_visible($c, $item)) {
				continue;
			}

			$full = $c->files . $item;
			$dir = is_dir($full);
			[$path, $deleted, $by] = trash_info($c, $item);
			$by_user = $by !== '' ? $users->fetch(strtolower($by)) : null;
			[$size, $count] = trash_stats($full);

			// Folders shared with me: show the path as I see it (under the name of the shared folder)
			$shown = $c->scope === null ? $path : $c->mount->mount . substr($path, strlen($c->scope));

			$items[] = (object) [
				'value' => $id . '|' . $item,
				'name' => $item, 'is_dir' => $dir, 'size' => $size, 'count' => $count,
				'path' => $shown, 'deleted' => $deleted,
				'by' => $by_user ? $by_user->label : $by,
				'shared' => $c->scope !== null,
				'owner' => $c->scope !== null ? $c->label : null,
				'date' => $deleted ? date('Y-m-d H:i', $deleted) : '',
				'url' => $dir ? 'trash.php?m=' . $id . '&open=' . rawurlencode($item) : null,
			];
			$total_size += $size;
			$has_shared = $has_shared || $c->scope !== null;
			$has_own = $has_own || $c->scope === null;
		}
	}

	// Most recently deleted first
	usort($items, fn($a, $b) => $b->deleted <=> $a->deleted);
}

$count_items = count($items);
$tpl->assign(compact('items', 'open', 'crumbs', 'notice', 'total_size', 'count_items', 'has_shared', 'has_own'));
$tpl->display('trash.tpl');
