<?php

namespace KaraDAV;

/**
 * Public share links ("share by link"), stored in the `shares` table.
 *
 * Paths are stored relative to the owner's root, without leading slash.
 * Only read-only link shares are supported.
 */
class Shares
{
	const PERM_READ = 1;
	const TOKEN_LENGTH = 16;
	const FORBIDDEN_ROOTS = ['.trash'];

	static public function normalizePath(string $path): string
	{
		// The path is already decoded (database, WebDAV URI, query string, form): decoding it again would turn
		// the name of a file like "a%41.txt" into "aA.txt", another file
		$path = str_replace('\\', '/', $path);
		$path = preg_replace('!/{2,}!', '/', $path);
		return trim($path, '/');
	}

	/**
	 * Resolve a share-relative path to a real filesystem path, making sure it stays inside $root.
	 * Returns null if it does not exist or tries to escape.
	 */
	static public function resolve(string $root, string $rel): ?string
	{
		$root = rtrim(realpath($root) ?: '', '/');

		if ($root === '') {
			return null;
		}

		$rel = self::normalizePath($rel);

		if (preg_match('!(?:^|/)\.\.(?:$|/)!', $rel) || str_contains($rel, "\0")) {
			return null;
		}

		$full = realpath($root . '/' . $rel);

		if ($full === false) {
			return null;
		}

		if ($full !== $root && 0 !== strpos($full, $root . '/')) {
			return null;
		}

		return $full;
	}

	static public function isHidden(string $rel): bool
	{
		$first = explode('/', self::normalizePath($rel))[0];
		return in_array($first, self::FORBIDDEN_ROOTS, true);
	}

	static protected function generateToken(): string
	{
		$chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$out = '';

		for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
			$out .= $chars[random_int(0, strlen($chars) - 1)];
		}

		return $out;
	}

	static public function normalizeExpiry(?string $date): ?string
	{
		$date = trim((string) $date);

		if ($date === '') {
			return null;
		}

		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException('Invalid expiration date, expected YYYY-MM-DD');
		}

		return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
	}

	static public function isExpired(object $share): bool
	{
		if (empty($share->expiry)) {
			return false;
		}

		return time() > strtotime($share->expiry . ' 23:59:59 UTC');
	}

	static public function create(User $user, string $path, ?string $password, ?string $expiry, ?string $label, ?int $creator = null): object
	{
		$path = self::normalizePath($path);

		if ($path === '' || self::isHidden($path)) {
			throw new \InvalidArgumentException('Cannot share this path');
		}

		if (null === self::resolve($user->path, $path)) {
			throw new \InvalidArgumentException('File or folder not found');
		}

		// A link made from a folder shared by someone else: the recipient needs edit rights on that folder
		if ($creator !== null && $creator !== $user->id && !UserShares::recipientCanWrite($user->id, $creator, $path)) {
			throw new \InvalidArgumentException('You need edit rights on this shared folder to create a public link');
		}

		$expiry = self::normalizeExpiry($expiry);
		$password = (string) $password;
		$hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
		$label = trim((string) $label) ?: null;

		$db = DB::getInstance();
		$db->run('INSERT INTO shares (user, path, token, password, expiry, permissions, label, created, creator) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);',
			$user->id, $path, self::generateToken(), $hash, $expiry, self::PERM_READ, $label, time(), $creator === $user->id ? null : $creator);

		return self::get($db->lastInsertRowID(), $creator ?? $user->id);
	}

	static public function get(int $id, int $user_id): ?object
	{
		return DB::getInstance()->first('SELECT * FROM shares WHERE id = ? AND COALESCE(creator, user) = ?;', $id, $user_id);
	}

	static public function byToken(string $token): ?object
	{
		return DB::getInstance()->first('SELECT * FROM shares WHERE token = ?;', $token);
	}

	/**
	 * @return object[]
	 */
	static public function list(int $user_id, ?string $path = null, bool $subfiles = false, ?int $owner_id = null): array
	{
		$db = DB::getInstance();
		$owner_id ??= $user_id;

		if ($path === null) {
			return iterator_to_array($db->iterate('SELECT * FROM shares WHERE COALESCE(creator, user) = ? ORDER BY id;', $user_id), false);
		}

		$path = self::normalizePath($path);

		if ($subfiles) {
			$sql = 'SELECT * FROM shares WHERE COALESCE(creator, user) = ? AND user = ? AND path LIKE ? ESCAPE \'\\\' ORDER BY id;';
			return iterator_to_array($db->iterate($sql, $user_id, $owner_id, $db->getPathLikeExpression($path)), false);
		}

		return iterator_to_array($db->iterate('SELECT * FROM shares WHERE COALESCE(creator, user) = ? AND user = ? AND path = ? ORDER BY id;', $user_id, $owner_id, $path), false);
	}

	/**
	 * Update password (null = unchanged, '' = remove), expiry (null = unchanged, '' = remove), label (null = unchanged).
	 */
	static public function update(object $share, ?string $password, ?string $expiry, ?string $label): object
	{
		$db = DB::getInstance();

		if ($password !== null) {
			$db->run('UPDATE shares SET password = ? WHERE id = ?;',
				$password === '' ? null : password_hash($password, PASSWORD_DEFAULT), $share->id);
		}

		if ($expiry !== null) {
			$db->run('UPDATE shares SET expiry = ? WHERE id = ?;', self::normalizeExpiry($expiry), $share->id);
		}

		if ($label !== null) {
			$db->run('UPDATE shares SET label = ? WHERE id = ?;', trim($label) ?: null, $share->id);
		}

		return self::get($share->id, $share->creator ?? $share->user);
	}

	/**
	 * All the links a user can see and revoke: links to their own files (including those made
	 * by people they share a folder with) and links they made from folders shared with them.
	 * @return object[] with owner_login, owner_label, creator_login, creator_label
	 */
	static public function listVisible(int $user_id): array
	{
		return iterator_to_array(DB::getInstance()->iterate('SELECT s.*,
				o.login AS owner_login, COALESCE(NULLIF(TRIM(o.display_name), \'\'), o.login) AS owner_label,
				c.login AS creator_login, COALESCE(NULLIF(TRIM(c.display_name), \'\'), c.login) AS creator_label
			FROM shares s
			INNER JOIN users o ON o.id = s.user
			LEFT JOIN users c ON c.id = s.creator
			WHERE s.user = ? OR s.creator = ?
			ORDER BY s.created DESC, s.id DESC;', $user_id, $user_id), false);
	}

	/** Revoke a link: allowed for the owner of the file and for the user who made the link */
	static public function revoke(int $id, int $user_id): bool
	{
		$db = DB::getInstance();
		$db->run('DELETE FROM shares WHERE id = ? AND (user = ? OR creator = ?);', $id, $user_id, $user_id);
		return $db->changes() > 0;
	}

	static public function delete(int $id, int $user_id): bool
	{
		$db = DB::getInstance();
		$db->run('DELETE FROM shares WHERE id = ? AND COALESCE(creator, user) = ?;', $id, $user_id);
		return $db->changes() > 0;
	}

	/**
	 * Called when a file or directory is deleted: remove its shares and those of its children.
	 */
	static public function removePath(int $user_id, string $path): void
	{
		$path = self::normalizePath($path);
		$db = DB::getInstance();
		$db->run('DELETE FROM shares WHERE user = ? AND (path = ? OR path LIKE ? ESCAPE \'\\\');',
			$user_id, $path, $db->getPathLikeExpression($path));
	}

	/**
	 * Called when a file or directory is moved/renamed: keep its shares working.
	 */
	static public function movePath(int $user_id, string $from, string $to): void
	{
		$from = self::normalizePath($from);
		$to = self::normalizePath($to);

		if ($from === '' || $from === $to) {
			return;
		}

		$db = DB::getInstance();
		$db->begin();
		$db->run('UPDATE shares SET path = ? WHERE user = ? AND path = ?;', $to, $user_id, $from);
		// children: replace the leading $from/ by $to/
		$db->run('UPDATE shares SET path = ? || substr(path, ?) WHERE user = ? AND path LIKE ? ESCAPE \'\\\';',
			$to, strlen($from) + 1, $user_id, $db->getPathLikeExpression($from));
		$db->commit();
	}

	static public function url(object $share): string
	{
		return WWW_URL . 's/' . $share->token;
	}
}
