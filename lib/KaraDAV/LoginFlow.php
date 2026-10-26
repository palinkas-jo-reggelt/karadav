<?php

namespace KaraDAV;

/**
 * Nextcloud login flow v2, how an application gets an app password:
 *
 *  1. the application asks for a flow and receives a login URL, to open in a browser, and its own
 *     secret poll token. Only the login token is in the URL, the poll token never goes through the browser
 *  2. the user logs in if needed and is asked to confirm, seeing which application and which address asked
 *  3. the application polls with its token; once the user has approved, the first poll gets an app
 *     password and the flow is deleted
 *
 * A flow expires after 10 minutes and can be used once.
 */
class LoginFlow
{
	const LIFETIME = '+10 minutes';
	const MAX_PER_IP = 30;

	static protected function hash(string $token): string
	{
		return hash('sha256', $token);
	}

	/**
	 * @return object with login_token and poll_token, in clear: this is the only time they are known
	 * @throws \OverflowException if this address has too many unfinished flows
	 */
	static public function create(string $user_agent, string $ip): object
	{
		$db = DB::getInstance();
		$db->run('DELETE FROM login_flows WHERE expiry <= datetime();');

		if ($db->firstColumn('SELECT COUNT(*) FROM login_flows WHERE ip = ?;', $ip) >= self::MAX_PER_IP) {
			throw new \OverflowException('Too many login requests');
		}

		$login = bin2hex(random_bytes(16));
		$poll = bin2hex(random_bytes(24));

		$db->run('INSERT INTO login_flows (login_token, poll_token, user_agent, ip, expiry) VALUES (?, ?, ?, ?, datetime(\'now\', ?));',
			self::hash($login), self::hash($poll), mb_substr($user_agent, 0, 200), $ip, self::LIFETIME);

		return (object) ['login_token' => $login, 'poll_token' => $poll];
	}

	/**
	 * A flow, from the token in the login URL, if it is still waiting for an answer
	 */
	static public function find(string $login_token): ?object
	{
		if (!preg_match('/^[a-f0-9]{32}$/D', $login_token)) {
			return null;
		}

		return DB::getInstance()->first('SELECT * FROM login_flows WHERE login_token = ? AND user IS NULL AND expiry > datetime();',
			self::hash($login_token));
	}

	/** The user approved: the application can now collect its password */
	static public function grant(object $flow, User $user): bool
	{
		$db = DB::getInstance();
		$db->run('UPDATE login_flows SET user = ? WHERE id = ? AND user IS NULL AND expiry > datetime();', $user->id, $flow->id);
		return $db->changes() === 1;
	}

	static public function deny(object $flow): void
	{
		DB::getInstance()->run('DELETE FROM login_flows WHERE id = ? AND user IS NULL;', $flow->id);
	}

	/**
	 * Exchange the poll token of an approved flow for an app password. Works once.
	 * @return array{login: string, password: string}|null
	 */
	static public function redeem(string $poll_token, Users $users): ?array
	{
		$db = DB::getInstance();
		$flow = $db->first('SELECT * FROM login_flows WHERE poll_token = ? AND user IS NOT NULL AND expiry > datetime();', self::hash($poll_token));

		if (!$flow) {
			return null;
		}

		// Only one of two simultaneous requests gets it
		$db->run('DELETE FROM login_flows WHERE id = ?;', $flow->id);

		if ($db->changes() !== 1 || !($user = $users->getById((int) $flow->user))) {
			return null;
		}

		$session = $users->appSessionCreateFor($user);

		return ['login' => $user->login, 'password' => $session->token . ':' . $session->password];
	}
}
