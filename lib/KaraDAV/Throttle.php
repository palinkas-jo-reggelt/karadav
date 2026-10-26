<?php

namespace KaraDAV;

/**
 * Slows down password guessing.
 *
 * Every failed attempt is recorded under three scopes, and an attempt is refused (the caller
 * answers "429 Too Many Requests") while any of them has reached its limit in the last THROTTLE_WINDOW seconds:
 *
 *  - the client IP address (guessing many accounts from one place)
 *  - the account + the IP address (guessing one account from one place)
 *  - the account alone (guessing one account from many places)
 *
 * The "subject" is what is being attacked: 'user:<login>', 'share:<token>' or 'api'.
 * Refused attempts are not recorded, so the table cannot grow beyond the limits.
 * Setting a limit to 0 disables the rule.
 */
class Throttle
{
	/**
	 * Address of the client. When KaraDAV is behind a reverse proxy, set CLIENT_IP_HEADER to the
	 * header the proxy fills (and make sure clients cannot send it directly), or every visitor
	 * will share the address of the proxy.
	 */
	static public function ip(): string
	{
		$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

		if (CLIENT_IP_HEADER && !empty($_SERVER[CLIENT_IP_HEADER])) {
			// Several addresses (X-Forwarded-For): the last one was added by our own proxy
			$list = array_map('trim', explode(',', (string) $_SERVER[CLIENT_IP_HEADER]));
			$forwarded = end($list);

			if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
				$ip = $forwarded;
			}
		}

		return $ip;
	}

	/** @return array<string, int> scope => limit */
	static protected function scopes(string $subject): array
	{
		$ip = self::ip();
		$subject = substr($subject, 0, 120);

		return [
			'ip:' . $ip                    => THROTTLE_PER_IP,
			'pair:' . $ip . '|' . $subject => THROTTLE_PER_ACCOUNT_AND_IP,
			'subject:' . $subject          => THROTTLE_PER_ACCOUNT,
		];
	}

	/**
	 * Seconds to wait before this client may try again (0 = go ahead)
	 */
	static public function wait(string $subject): int
	{
		$db = DB::getInstance();
		$since = time() - THROTTLE_WINDOW;
		$wait = 0;

		foreach (self::scopes($subject) as $scope => $limit) {
			if ($limit <= 0) {
				continue;
			}

			$row = $db->first('SELECT COUNT(*) AS n, MIN(time) AS oldest FROM auth_failures WHERE scope = ? AND time > ?;', $scope, $since);

			if ($row && $row->n >= $limit) {
				// Allowed again as soon as the oldest failure leaves the window
				$wait = max($wait, (int) $row->oldest + THROTTLE_WINDOW - time() + 1);
			}
		}

		return $wait;
	}

	static public function fail(string $subject): void
	{
		$db = DB::getInstance();
		$now = time();
		$db->begin();

		foreach (self::scopes($subject) as $scope => $limit) {
			if ($limit > 0) {
				$db->run('INSERT INTO auth_failures (scope, time) VALUES (?, ?);', $scope, $now);
			}
		}

		// Forget old failures from time to time
		if (random_int(1, 20) === 1) {
			$db->run('DELETE FROM auth_failures WHERE time <= ?;', $now - THROTTLE_WINDOW);
		}

		$db->commit();
	}

	/**
	 * Refuse the request with "429 Too Many Requests" and a Retry-After header
	 */
	static public function sendHeaders(int $wait): void
	{
		http_response_code(429);
		header('Retry-After: ' . $wait);
	}
}
