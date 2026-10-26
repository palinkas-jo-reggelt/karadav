<?php

namespace KaraDAV;

require_once __DIR__ . '/_inc.php';

$users = new Users;

// An application asks to be connected to the account:
// - "flow": Nextcloud login flow v2, started by the application (see LoginFlow)
// - "nc=redirect": flow v1, the answer goes back to the application through a nc:// address
// Either way nothing is given before the user has seen who asks and has confirmed.
$connect = null;
$flow = null;

if (isset($_GET['flow'])) {
	$connect = 'flow';
	$flow = is_string($_GET['flow']) ? LoginFlow::find($_GET['flow']) : null;
}
elseif (($_GET['nc'] ?? null) === 'redirect') {
	$connect = 'redirect';
}

if ($connect === null && $users->current()) {
	header('Location: ' . WWW_URL);
	exit;
}

$error = 0;
$wait = 0;

if (!$users->current() && !empty($_POST['login']) && !empty($_POST['password']) && csrf_check()) {
	$wait = Throttle::wait('user:' . strtolower(trim((string) $_POST['login'])));

	if ($wait > 0) {
		Throttle::sendHeaders($wait);
		$error = 2;
	}
	elseif ($users->login($_POST['login'], $_POST['password'], !empty($_POST['permanent']), true)) {
		if ($connect === null) {
			header('Location: ./');
			exit;
		}

		// Logged in: the confirmation is asked below
	}
	else {
		$error = 1;
	}
}

// ask, done, denied or invalid (expired, already used, or not started by an application)
$state = null;
$current = $users->current();

if ($connect === 'flow' && !$flow) {
	$state = 'invalid';
}
elseif ($connect !== null && $current) {
	if (!empty($_POST['deny']) && csrf_check()) {
		if ($flow) {
			LoginFlow::deny($flow);
		}

		if ($connect === 'redirect') {
			header('Location: ' . WWW_URL);
			exit;
		}

		$state = 'denied';
	}
	elseif (!empty($_POST['grant']) && csrf_check()) {
		if ($connect === 'redirect') {
			header('Location: ' . $users->appSessionCreateAndGetRedirectURL());
			exit;
		}

		$state = $flow && LoginFlow::grant($flow, $current) ? 'done' : 'invalid';
	}
	else {
		$state = 'ask';
	}
}

$tpl->assign('logged_user', null);

if ($state !== null) {
	$tpl->assign([
		'state'     => $state,
		'client'    => $flow ? (string) $flow->user_agent : '',
		'flow_ip'   => $flow ? $flow->ip : '',
		'my_ip'     => Throttle::ip(),
		'account'   => $current ? $current->label . ($current->label !== $current->login ? ' (' . $current->login . ')' : '') : '',
	]);
	$tpl->display('connect.tpl');
	exit;
}

$tpl->assign(compact('error', 'connect'));
$tpl->display('login.tpl');
