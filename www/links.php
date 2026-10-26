<?php

namespace KaraDAV;

use function KD2\_;

require_once __DIR__ . '/_inc.php';

form_exec_if('revoke', function () use ($logged_user) {
	if (!Shares::revoke((int) ($_POST['id'] ?? 0), $logged_user->id)) {
		throw new UserException(_('This link does not exist'));
	}
}, 'links.php');

$mounts = UserShares::forRecipient($logged_user->id);
$links = [];

foreach (Shares::listVisible($logged_user->id) as $s) {
	$mine = (int) $s->user === $logged_user->id;
	$path = $s->path;

	// Links made from a folder shared with me: show the path as I see it, under the name of the shared folder
	if (!$mine) {
		foreach ($mounts as $m) {
			if ((int) $m->owner === (int) $s->user && ($s->path === $m->path || 0 === strpos($s->path, $m->path . '/'))) {
				$path = $m->mount . substr($s->path, strlen($m->path));
				break;
			}
		}
	}

	$owner = $mine ? $logged_user : $users->getById((int) $s->user);
	$full = $owner ? Shares::resolve($owner->path, $s->path) : null;

	$links[] = (object) [
		'id'         => (int) $s->id,
		'url'        => Shares::url($s),
		'path'       => $path,
		'is_dir'     => $full !== null && is_dir($full),
		'missing'    => $full === null,
		'label'      => (string) $s->label,
		'protected'  => !empty($s->password),
		'expiry'     => (string) $s->expiry,
		'expired'    => Shares::isExpired($s),
		'created'    => date('Y-m-d H:i', (int) $s->created),
		// who made it: me, or the person I share the folder with
		'by'         => $s->creator === null ? $s->owner_label : $s->creator_label,
		'by_other'   => $s->creator !== null && (int) $s->creator !== $logged_user->id,
		'by_me'      => ($s->creator === null && $mine) || (int) $s->creator === $logged_user->id,
		'owner'      => $s->owner_label,
		'owner_other' => !$mine,
	];
}

$tpl->assign(compact('links'));
$tpl->display('links.tpl');
