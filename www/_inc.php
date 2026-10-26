<?php

namespace KaraDAV;

use function KD2\_;
use KD2\ErrorManager;
use KD2\Translate;
use KD2\Smartyer;

require_once __DIR__ . '/../init.php';

// The pages of the interface are not meant to be shown inside the frame of another site, nor to tell
// other sites which page they came from
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$users = new Users;
$logged_user = $users->current();

// SCRIPT_NAME, not PHP_SELF: PHP_SELF ends with the extra path of "/users/edit.php/login.php", which looked like the login page
$file = basename($_SERVER['SCRIPT_NAME'] ?? '');

if (!$logged_user
	&& !in_array($file, ['login.php', 'session.php'])) {
	header(sprintf('Location: %slogin.php', WWW_URL));
	exit;
}

$tpl = new Smartyer;
$tpl->setTemplatesDir(ROOT . '/templates');
$tpl->setCompiledDir(CACHE_PATH . '/compiled');

$tpl->assign('www_url', WWW_URL);
$tpl->assign('logo_url', LOGO_URL);
$tpl->assign('apps', EXTERNAL_APPS);
$tpl->assign(compact('logged_user', 'users'));

$tpl->register_function('form_csrf', function (): string {
	$expire = time() + 1800;
	$random = random_bytes(10);
	$action = $_SERVER['REQUEST_URI'];
	$token = hash_hmac('sha256', $expire . $random . $action, SECRET_KEY . session_id());

	return sprintf('<input type="hidden" name="_c_" value="%s:%s:%s" />', $token, base64_encode($random), $expire);
});

$tpl->register_modifier('format_mbytes', function ($bytes, string $unit = 'B'): string {
	if ($bytes >= 1000 * 1000) {
		return round($bytes / 1000 / 1000, 1) . ' T' . $unit;
	}
	elseif ($bytes >= 1000) {
		return round($bytes / 1000, 1) . ' G' . $unit;
	}
	else {
		return $bytes . ' M' . $unit;
	}
});

$tpl->assign('form_error' , null);

function form_exec_if($condition, callable $fn, ?string $redirect = null): void
{
    global $tpl;

    if (is_string($condition)) {
        $condition = !empty($_POST[$condition]);
    }

    if (!$condition) {
        return;
    }

    try {
        if (!csrf_check()) {
            throw new UserException(_('Temporary error, please re-submit the form'));
        }

        $fn();

        if ($redirect) {
            header('Location: ' . WWW_URL . $redirect);
            exit;
        }
    }
    catch (UserException|\UnexpectedValueException $e) {
        $tpl->assign('form_error', $e->getMessage());
    }
}


Translate::extendSmartyer($tpl);
Translate::setLocale('en_NZ');


function csrf_check(): bool
{
	if (empty($_POST['_c_']) || !is_string($_POST['_c_'])) {
		return false;
	}

	$verify = strtok($_POST['_c_'], ':');
	$random = base64_decode(strtok(':'));
	$expire = strtok(false);

	if ($expire < time()) {
		return false;
	}

	$action = $_SERVER['REQUEST_URI'];

	$token = hash_hmac('sha256', $expire . $random . $action, SECRET_KEY . session_id());

	return hash_equals($token, $verify);
}
