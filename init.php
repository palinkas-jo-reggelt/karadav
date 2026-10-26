<?php

namespace KaraDAV;

use KD2\ErrorManager;
use KD2\Translate;

require __DIR__ . '/lib/KD2/ErrorManager.php';

// Hide error details from visitors until the configuration says otherwise (see ERRORS_SHOW below)
ErrorManager::enable(ErrorManager::PRODUCTION);

if (PHP_INT_SIZE === 4 && !function_exists('curl_init')) {
	throw new \LogicException('Extension "curl" is required for 32-bits systems.');
}

const ROOT = __DIR__;

require_once __DIR__ . '/lib/compat.php';

/**
 * On Windows, realpath() returns backslashes, but all the path checks in KaraDAV compare
 * against forward-slash paths. Because this function lives in the KaraDAV namespace, every
 * unqualified realpath() call in KaraDAV code uses it instead of the built-in one.
 * (PHP on Windows accepts forward slashes everywhere.) On Linux this is a no-op.
 */
function realpath(string $path): string|false
{
	$real = \realpath($path);
	return $real === false ? false : str_replace('\\', '/', $real);
}

spl_autoload_register(function ($class) {
    $class = str_replace('\\', '/', $class);
    require_once ROOT . '/lib/' . $class . '.php';
});

ErrorManager::setLogFile(ROOT . '/error.log');

$cfg_file = ROOT . '/config.local.php';

if (file_exists($cfg_file)) {
	require $cfg_file;
}

if (!defined(__NAMESPACE__ . '\DATA_ROOT')) {
	define(__NAMESPACE__ . '\DATA_ROOT', ROOT . '/data');
}

// Default configuration constants
$defaults = [
	'DEV'                    => false,
	'DEFAULT_QUOTA'          => 200,
	'DEFAULT_TRASHBIN_DELAY' => 60*60*24*30,
	'ENABLE_THUMBNAILS'      => true,
	'STORAGE_PATH'           => DATA_ROOT . '/%s',
	'CACHE_PATH'             => DATA_ROOT . '/.cache',
	'DB_FILE'                => DATA_ROOT . '/db.sqlite',
	'DB_JOURNAL_MODE'        => 'TRUNCATE',
	'WOPI_DISCOVERY_URL'     => null,
	'ACCESS_CONTROL_ALL'     => false,
	'EXTERNAL_APPS'          => null,
	'EXTERNAL_API_KEY'       => null,
	'LOG_FILE'               => null,
	'ENABLE_XSENDFILE'       => false,
	'ERRORS_SHOW'            => false,
	'ERRORS_EMAIL'           => null,
	'ERRORS_LOG'             => ROOT . '/error.log',
	'ERRORS_REPORT_URL'      => null,
	'AUTH_CALLBACK'          => null,
	'LDAP_HOST'              => null,
	'LDAP_PORT'              => null,
	'LDAP_SECURE'            => null,
	'LDAP_LOGIN'             => null,
	'LDAP_BASE'              => null,
	'LDAP_DISPLAY_NAME'      => null,
	'LDAP_FIND_USER'         => null,
	'LDAP_FIND_IS_ADMIN'     => null,
	'BLOCK_IOS_APPS'         => true,
	'CLIENT_IP_HEADER'       => null,
	'THROTTLE_WINDOW'        => 900,
	'THROTTLE_PER_IP'        => 50,
	'THROTTLE_PER_ACCOUNT_AND_IP' => 10,
	'THROTTLE_PER_ACCOUNT'   => 100,
	'SHARE_SITE_NAME'        => '',
	'SHARE_LOGO_URL'         => '',
];

foreach ($defaults as $const => $value) {
	if (!defined('KaraDAV\\' . $const)) {
		define('KaraDAV\\' . $const, $value);
	}
}

class UserException extends \RuntimeException {}

if (ERRORS_SHOW) {
	ErrorManager::setEnvironment(ErrorManager::DEVELOPMENT);
}

if (ERRORS_EMAIL) {
	ErrorManager::setEmail(ERRORS_EMAIL);
}

// Errors are always logged, by default in error.log in the KaraDAV root folder
ErrorManager::setLogFile(ERRORS_LOG ?: ROOT . '/error.log');

if (ERRORS_REPORT_URL) {
	ErrorManager::setRemoteReporting(ERRORS_REPORT_URL, true);
}

// Detect thumbnails support
$th = ENABLE_THUMBNAILS && (class_exists('imagick', false) || function_exists('imagecreatefromwebp'));
define('KaraDAV\ENABLE_THUMBNAILS_OK', $th);

// Create random secret key
if (!defined('KaraDAV\SECRET_KEY')) {
	$cfg_is_new = !file_exists($cfg_file);
	$cfg = $cfg_is_new ? "<?php\nnamespace KaraDAV;\n\n" : file_get_contents($cfg_file);

	if (false == strpos($cfg, 'SECRET_KEY')) {
		$secret = base64_encode(random_bytes(16));

		$c = sprintf("\n\n// Randomly generated secret key, please change only if necessary\nconst SECRET_KEY = %s;\n\n",
			var_export($secret, true));

		if ((file_exists($cfg_file) && !is_writeable($cfg_file)) || !is_writeable(dirname($cfg_file))) {
			echo "<h2>Configuration missing</h2>";
			echo "<h4>KaraDAV cannot write to <tt>config.local.php</tt></h4>";
			echo "<p>Please append the following code to the <tt>config.local.php</tt> file:</p>";
			printf('<textarea onclick="this.select();" cols="70" rows="5">%s</textarea>', htmlspecialchars($c));
			exit(1);
		}

		$cfg = preg_replace('/\?>\s*$|$/', $c, $cfg, 1);

		if ($cfg_is_new) {
			// Created by us and about to contain the secret key: for us only
			@touch($cfg_file);
			@chmod($cfg_file, 0600);
		}

		file_put_contents($cfg_file, $cfg);

		// The file now contains the secret key: take away the access of others, if it is ours to change
		if (!$cfg_is_new && DIRECTORY_SEPARATOR === '/' && ($perms = @fileperms($cfg_file)) !== false && ($perms & 0007)
			&& (!function_exists('posix_geteuid') || @fileowner($cfg_file) === posix_geteuid())) {
			@chmod($cfg_file, $perms & 0770);
		}

		// The next request must not run a cached copy of the file, without the key
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($cfg_file, true);
		}

		define('KaraDAV\SECRET_KEY', $secret);
		unset($secret, $cfg_file, $cfg);
	}
	// The file has the key but the constant is not defined: the file was written by the request before this
	// one and we are still running an older version of it, read the key from the file
	elseif (preg_match('/const\s+SECRET_KEY\s*=\s*([\'"])(.*?)\1\s*;/s', $cfg, $m)) {
		define('KaraDAV\SECRET_KEY', stripslashes($m[2]));
	}
}

if (!defined('KaraDAV\WWW_URL')) {
	$https = (!empty($_SERVER['HTTPS']) || $_SERVER['SERVER_PORT'] == 443) ? 's' : '';
	$name = $_SERVER['SERVER_NAME'];

	if ($name === '0.0.0.0') {
		$name = 'localhost';
	}

	$port = !in_array($_SERVER['SERVER_PORT'], [80, 443]) ? ':' . $_SERVER['SERVER_PORT'] : '';
	$root = '/';

	define('KaraDAV\WWW_URL', sprintf('http%s://%s%s%s', $https, $name, $port, $root));
}

if (!defined('KaraDAV\LOGO_URL')) {
	define('KaraDAV\LOGO_URL', WWW_URL . 'logo.svg');
}

// Init database
if (!file_exists(DB_FILE)) {
	$parent = dirname(DB_FILE);

	if (!file_exists($parent)) {
		@mkdir($parent, 0770, true);
	}

	if (!is_writable($parent)) {
		throw new \RuntimeException('Cannot create database in directory: ' . $parent);
	}

	$db = DB::getInstance();
	$db->begin();
	$db->exec(file_get_contents(ROOT . '/sql/schema.sql'));

	if (!LDAP::enabled()) {
		$users = new Users;
		$p = 'karadavdemo';
		$user = new User;
		$user->create([
			'login'    => 'demo',
			'password' => $p,
			'quota'    => 10,
			'is_admin' => true,
		]);
		$users->login('demo', $p);
		$_SESSION['install_password'] = $p;
	}

	$db->exec('PRAGMA user_version = ' . DB::VERSION . ';');
	$db->commit();
}
else {
	$db = DB::getInstance();
	$db->upgradeVersion();
}

/**
 * Passwords, tokens and cookies must not end up in the log file, as it is usually
 * readable by more people than the data of the users
 */
function http_log_redact(string $message): string
{
	// Authorization: Basic xxx, Bearer xxx…
	$message = preg_replace('/^(\s*Authorization:).*$/mi', '$1 ***', $message);

	// Cookie and Set-Cookie: keep the names, hide the values
	$message = preg_replace_callback('/^(\s*(?:Set-)?Cookie:)(.*)$/mi',
		fn ($m) => $m[1] . preg_replace('/=[^;,\s]*/', '=***', $m[2]), $message);

	// Parameters of the query string: tokens of WOPI, direct download, login flow…
	$message = preg_replace('/([?&;](?:access_token|token|h|flow|password|api_key|apikey|secret|_c_)=)[^&\s"]+/i', '$1***', $message);

	// JSON replies containing app passwords or tokens
	$message = preg_replace('/("(?:appPassword|password|access_token|token|login_token|poll_token|secret)"\s*:\s*")[^"]*(")/i', '$1***$2', $message);

	// nc://login/server:…&user:…&password:…
	$message = preg_replace('/(&password:)\S+/i', '$1***', $message);

	// The cookie of a password-protected public link has the token of the link in its name
	$message = preg_replace('/\bks_[A-Za-z0-9]+(?==)/', 'ks_***', $message);

	// Public links: /s/<token>
	$message = preg_replace('~(?<![A-Za-z_-])(/s/)[A-Za-z0-9]{16}(?![A-Za-z0-9])~', '$1***', $message);

	return $message;
}

function http_log(string $message, ...$params): void
{
	if (!LOG_FILE) {
		return;
	}

	$msg = http_log_redact(vsprintf($message, $params)) . "\n\n";

	if (!file_exists(LOG_FILE)) {
		@touch(LOG_FILE);
		@chmod(LOG_FILE, 0600);
	}

	file_put_contents(LOG_FILE, $msg, FILE_APPEND);
}

if (LOG_FILE && isset($_SERVER['REMOTE_ADDR'])) {
	$method = $_SERVER['REQUEST_METHOD'] ?? $_SERVER['REDIRECT_REQUEST_METHOD'];
	$uri = parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH);

	$qs = $_SERVER['QUERY_STRING'] ?? null;
	$headers = apache_request_headers();

	http_log("===== ROUTER: Got new request: %s from %s =====", date('d/m/Y H:i:s'), $_SERVER['REMOTE_ADDR']);

	http_log("ROUTER: <= %s %s (User: %s)\nRequest headers:\n  %s",
		$method,
		$uri . ($qs ? '?' : '') . $qs,
		$_SERVER['PHP_AUTH_USER'] ?? 'none',
		implode("\n  ", array_map(fn ($v, $k) => $k . ': ' . $v, $headers, array_keys($headers)))
	);

	if ($method === 'PROPFIND' || $method === 'PROPPATCH' || $method === 'REPORT') {
		http_log("ROUTER: <= Request body:\n%s", file_get_contents('php://input'));
	}
}
