<?php

namespace KaraDAV;

if (!empty($_SERVER['PATH_INFO'])) {
	require __DIR__ . '/_router.php';
	exit;
}

require_once __DIR__ . '/_inc.php';

if (isset($_GET['logout'])) {
	$logged_user->logout();
	header(sprintf('Location: %slogin.php?logout=1', WWW_URL));
	exit;
}

form_exec_if('empty_trash', function () use ($logged_user) {
	$logged_user->emptyTrash();
}, 'index.php');

$quota = $logged_user->quota(true);
$percent = $quota->total ? floor(($quota->used / $quota->total)*100) . '%' : '100%';

$tpl->assign(compact('quota', 'percent'));

$tpl->display('index.tpl');
