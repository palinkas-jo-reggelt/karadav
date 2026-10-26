<?php

namespace KaraDAV;

use stdClass;

class Users
{
	protected ?User $current = null;

	public function __construct()
	{
		if (!session_id()) {
			// Protect the cookie : CSRF/JS stealing the cookie
			session_set_cookie_params([
				'samesite' => 'Strict',
				'httponly' => true,
				'secure'   => parse_url(WWW_URL, PHP_URL_SCHEME) === 'https',
			]);
		}
	}

	static public function generatePassword(): string
	{
		$password = base64_encode(random_bytes(16));
		$password = substr(str_replace(['/', '+', '='], '', $password), 0, 16);
		return $password;
	}

	public function list(): array
	{
		return array_map([User::class, 'from'], iterator_to_array(DB::getInstance()->iterate('SELECT * FROM users ORDER BY login;')));
	}

	public function fetch(string $login): ?User
	{
		return User::from(DB::getInstance()->first('SELECT * FROM users WHERE login = ?;', $login));
	}

	public function get(string $login): ?User
	{
		$user = $this->fetch($login);

		if (!$user && LDAP::enabled()) {
			$ldap = LDAP::getInstance();

			if (!User::isValidLogin($login) || !$ldap->checkUser($login)) {
				return null;
			}

			$user = new User;
			$user->create([
				'login' => $login,
				'password' => self::generatePassword(),
			]);

			$user->is_admin = $ldap->checkIsAdmin($login);
		}

		return $user;
	}

	public function getById(int $id): ?User
	{
		return User::from(DB::getInstance()->first('SELECT * FROM users WHERE id = ?;', $id));
	}

	public function current(): ?User
	{
		if ($this->current) {
			return $this->current;
		}

		$db = DB::getInstance();

		if (isset($_COOKIE[session_name()]) && !isset($_SESSION)) {
			session_start();
		}
		elseif (!empty($_COOKIE['permanent']) && is_string($_COOKIE['permanent'])
			&& ($user = $db->first('SELECT * FROM users WHERE session_id = ?;', self::hashToken($_COOKIE['permanent'])))) {
			$this->startSession(User::from($user), true);

			// Make sure this token cannot be reused
			$this->setPermanentSession($user->id);
		}

		// Old sessions (pre-refactor) stored a stdClass with stale, byte-based quota values
		// FIXME: remove in 1.0 release
		if (isset($_SESSION['user']) && $_SESSION['user'] instanceof stdClass) {
			$_SESSION['user'] = $this->getById($_SESSION['user']->id);
		}

		$this->current = $_SESSION['user'] ?? null;

		// A session does not survive a change of the password of its account, nor the deletion of the account
		if ($this->current && (!$this->current->id || !hash_equals(self::sessionMarker($this->current), (string) ($_SESSION['auth'] ?? '')))) {
			unset($_SESSION['user'], $_SESSION['auth']);
			$this->current = null;
		}

		return $this->current;
	}

	/**
	 * Value stored in the session, that stops matching when the password of the account changes
	 */
	static protected function sessionMarker(User $user): string
	{
		return hash_hmac('sha256', $user->password, SECRET_KEY);
	}

	static protected function hashToken(string $token): string
	{
		return hash('sha256', $token);
	}

	/**
	 * @param bool $new_id Give the session a new identifier, so that one chosen by someone else before the login is useless
	 */
	protected function startSession(User $user, bool $new_id = false): void
	{
		@session_start();

		if ($new_id && session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
			session_regenerate_id(true);
		}

		$_SESSION['user'] = $user;
		$_SESSION['auth'] = self::sessionMarker($user);
	}

	public function setCurrent(string $login): bool
	{
		$user = $this->get($login);

		if (!$user) {
			return false;
		}

		$this->current = $user;
		return true;
	}

	public function login(?string $login, ?string $password, bool $permanent = false, bool $new_session = false): ?User
	{
		$login = null !== $login ? strtolower(trim($login)) : null;

		// Check if user already has a session
		$current = $this->current();

		if ($current && (!$login || $current->login == $login)) {
			return $current;
		}

		if (!$login || !$password) {
			return null;
		}

		// Too many failed attempts: do not even look at the password
		$subject = 'user:' . $login;

		if (Throttle::wait($subject) > 0) {
			return null;
		}

		$user = $this->checkCredentials($login, $password, $permanent, $new_session);

		if (!$user) {
			Throttle::fail($subject);
		}

		return $user;
	}

	protected function checkCredentials(string $login, string $password, bool $permanent, bool $new_session = false): ?User
	{
		$ok = false;

		if (LDAP::enabled()) {
			$ldap = LDAP::getInstance();

			if (!$ldap->checkPassword($login, $password)) {
				return null;
			}

			$ok = true;
		}
		elseif (AUTH_CALLBACK) {
			$r = call_user_func(AUTH_CALLBACK, $login, $password);

			if ($r !== true) {
				return null;
			}

			$ok = true;
		}

		$user = $this->get($login);

		if (!$user && !$ok) {
			// Same work as for an existing account, so that the response time does not tell which logins exist
			static $dummy = null;
			$dummy ??= password_hash(bin2hex(random_bytes(8)), \PASSWORD_DEFAULT);
			password_verify(trim($password), $dummy);
			return null;
		}
		elseif (!$user && $ok) {
			if (!User::isValidLogin($login)) {
				return null;
			}

			$user = new User;
			$user->create(['login' => $login, 'password' => random_bytes(10)]);
			$user = $this->get($login);
		}

		if (!$ok && !password_verify(trim($password), $user->password)) {
			return null;
		}

		$this->startSession($user, $new_session);

		if ($permanent) {
			$this->setPermanentSession($user->id);
		}

		return $user;
	}

	protected function setPermanentSession(int $id_user)
	{
		// Not the session id: a token of its own, of which only a hash is stored
		$token = bin2hex(random_bytes(24));
		DB::getInstance()->run('UPDATE users SET session_id = ? WHERE id = ?;', self::hashToken($token), $id_user);

		setcookie('permanent', $token, [
			'expires'  => time() + 3600*24*365,
			'path'     => '/',
			'httponly' => true,
			'samesite' => 'Strict',
			'secure'   => parse_url(WWW_URL, PHP_URL_SCHEME) === 'https',
		]);
	}

	/**
	 * App password for a user: "token:password", the token finds the session and the password is checked
	 */
	public function appSessionCreateFor(User $user): stdClass
	{
		$password = $this->generatePassword();

		// The app password contains the user password hash
		// this way we can invalidate all sessions if we change
		// the user password
		$hash = password_hash($password . $user->password, \PASSWORD_DEFAULT);
		$token = $this->generatePassword();

		DB::getInstance()->run(
			'INSERT INTO app_sessions (user, password, expiry, token) VALUES (?, ?, datetime(\'now\', \'+1 month\'), ?);',
			$user->id, $hash, $token);

		return (object) compact('password', 'token');
	}

	public function appSessionCreate(): ?stdClass
	{
		$current = $this->current();
		return $current ? $this->appSessionCreateFor($current) : null;
	}

	public function appSessionCreateAndGetRedirectURL(): string
	{
		$session = $this->appSessionCreate();
		$current = $this->current();

		return sprintf(NextCloud::AUTH_REDIRECT_URL, WWW_URL, $current->login, $session->token . ':' . $session->password);
	}

	public function appSessionLogin(?string $login, ?string $app_password): ?User
	{
		// From time to time, clean up old sessions
		if (time() % 100 == 0) {
			DB::getInstance()->run('DELETE FROM app_sessions WHERE expiry < datetime();');
		}

		if (($user = $this->current()) && $login == $user->login) {
			return $user;
		}

		if (!$app_password) {
			return null;
		}

		$token = strtok($app_password, ':');
		$password = strtok('');

		$user = DB::getInstance()->first('SELECT s.password AS app_hash, u.*
			FROM app_sessions s INNER JOIN users u ON u.id = s.user
			WHERE s.token = ? AND s.expiry > datetime();', $token);

		if (!$user) {
			return null;
		}

		$password = trim($password) . $user->password;

		if (!password_verify($password, $user->app_hash)) {
			return null;
		}

		$user = User::from($user);
		$this->startSession($user);

		return $user;
	}

	public function indexAllFiles()
	{
		$db = DB::getInstance();
		$db->begin();

		foreach ($this->list() as $user) {
			Storage::indexFiles($user, null);
		}

		$db->commit();
	}
}
