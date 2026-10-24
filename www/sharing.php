<?php

namespace KaraDAV;

use function KD2\_;

require_once __DIR__ . '/_inc.php';

$redirect = 'sharing.php';

form_exec_if('add', function () use ($logged_user, $users) {
	$recipient = $users->fetch(strtolower(trim((string) ($_POST['user'] ?? ''))));

	if (!$recipient) {
		throw new UserException(_('Please choose a user'));
	}

	try {
		UserShares::create($logged_user, (string) ($_POST['folder'] ?? ''), $recipient,
			!empty($_POST['write']) ? UserShares::PERM_WRITE : UserShares::PERM_READ);
	}
	catch (\InvalidArgumentException $e) {
		throw new UserException($e->getMessage());
	}
}, $redirect);

form_exec_if('update', function () use ($logged_user) {
	$share = UserShares::get((int) ($_POST['id'] ?? 0));

	if (!$share || (int) $share->owner !== $logged_user->id) {
		throw new UserException(_('This share does not exist'));
	}

	UserShares::update($share, !empty($_POST['write']) ? UserShares::PERM_WRITE : UserShares::PERM_READ);
}, $redirect);

form_exec_if('remove', function () use ($logged_user) {
	UserShares::delete((int) ($_POST['id'] ?? 0), $logged_user->id);
}, $redirect);

// Shared by me
$owned = [];

foreach (UserShares::forOwner($logged_user->id) as $share) {
	$r = $users->getById((int) $share->recipient);
	$share->recipient_label = $r ? $r->label . ($r->label !== $r->login ? ' (' . $r->login . ')' : '') : '?';
	$share->can_write = (bool) ($share->permissions & UserShares::PERM_WRITE);
	$owned[] = $share;
}

// Shared with me
$received = [];

foreach (UserShares::forRecipient($logged_user->id) as $share) {
	$o = $users->getById((int) $share->owner);
	$share->owner_label = $o ? $o->label : '?';
	$share->can_write = (bool) ($share->permissions & UserShares::PERM_WRITE);
	$received[] = $share;
}

// Folders that can be shared (up to 3 levels deep)
$folders = [];
$scan = function (string $rel, int $depth) use (&$scan, &$folders, $logged_user) {
	if ($depth > 3 || count($folders) >= 500) {
		return;
	}

	foreach (scandir($logged_user->path . $rel) ?: [] as $item) {
		if ($item === '.' || $item === '..' || $item[0] === '.') {
			continue;
		}

		$path = ltrim($rel . '/' . $item, '/');

		if (is_dir($logged_user->path . $path)) {
			$folders[] = $path;
			$scan($path, $depth + 1);
		}
	}
};
$scan('', 1);

$others = [];

foreach ($users->list() as $u) {
	if ($u->id !== $logged_user->id) {
		$others[] = $u;
	}
}

$tpl->assign(compact('owned', 'received', 'folders', 'others'));
$tpl->display('sharing.tpl');
