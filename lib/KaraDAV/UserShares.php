<?php

namespace KaraDAV;

/**
 * Folder shares between users: a folder of the owner appears as a folder in the root
 * of the recipient, with read-only or read/write access.
 */
class UserShares
{
	const PERM_READ = 1;
	const PERM_WRITE = 2;

	/**
	 * Convert Nextcloud-style permission flags (1 read, 2 update, 4 create, 8 delete, 16 share) to ours
	 */
	static public function fromNextcloudPermissions(int $p): int
	{
		return ($p & (2 | 4 | 8)) ? self::PERM_WRITE | self::PERM_READ : self::PERM_READ;
	}

	static public function toNextcloudPermissions(int $p): int
	{
		return ($p & self::PERM_WRITE) ? 1 | 2 | 4 | 8 : 1;
	}

	static protected function mountName(User $recipient, string $path): string
	{
		$base = basename($path);
		$base = trim(str_replace(Storage::FORBIDDEN_CHARACTERS, '_', $base)) ?: 'Shared';
		$db = DB::getInstance();
		$name = $base;
		$i = 1;

		while ($db->firstColumn('SELECT 1 FROM user_shares WHERE recipient = ? AND mount = ?;', $recipient->id, $name)
			|| file_exists($recipient->path . $name)) {
			$i++;
			$name = sprintf('%s (%d)', $base, $i);
		}

		return $name;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	static public function create(User $owner, string $path, User $recipient, int $permissions): object
	{
		$path = Shares::normalizePath($path);

		if ($path === '' || Shares::isHidden($path)) {
			throw new \InvalidArgumentException('Cannot share this folder');
		}

		if ($owner->id === $recipient->id) {
			throw new \InvalidArgumentException('You cannot share with yourself');
		}

		$full = Shares::resolve($owner->path, $path);

		if ($full === null || !is_dir($full)) {
			throw new \InvalidArgumentException('Only existing folders can be shared with other users');
		}

		$db = DB::getInstance();

		if ($existing = $db->first('SELECT * FROM user_shares WHERE owner = ? AND path = ? AND recipient = ?;', $owner->id, $path, $recipient->id)) {
			return self::update($existing, $permissions);
		}

		$db->run('INSERT INTO user_shares (owner, path, recipient, mount, permissions, created) VALUES (?, ?, ?, ?, ?, ?);',
			$owner->id, $path, $recipient->id, self::mountName($recipient, $path),
			$permissions & self::PERM_WRITE ? self::PERM_READ | self::PERM_WRITE : self::PERM_READ, time());

		return self::get($db->lastInsertRowID());
	}

	static public function get(int $id): ?object
	{
		return DB::getInstance()->first('SELECT * FROM user_shares WHERE id = ?;', $id);
	}

	static public function update(object $share, int $permissions): object
	{
		$db = DB::getInstance();
		$write = (bool) ($permissions & self::PERM_WRITE);

		$db->run('UPDATE user_shares SET permissions = ? WHERE id = ?;',
			$write ? self::PERM_READ | self::PERM_WRITE : self::PERM_READ, $share->id);

		// Only users who can edit a folder may publish it: links made by a recipient who lost that right are removed
		if (!$write) {
			self::removeRecipientLinks($share);
		}

		return self::get($share->id);
	}

	/** Public links the recipient made from the shared folder */
	static protected function removeRecipientLinks(object $share): void
	{
		$db = DB::getInstance();
		$db->run('DELETE FROM shares WHERE user = ? AND creator = ? AND (path = ? OR path LIKE ? ESCAPE \'\\\');',
			$share->owner, $share->recipient, $share->path, $db->getPathLikeExpression($share->path));
	}

	/** Can $recipient edit $path (a path of $owner) through a folder shared with them? */
	static public function recipientCanWrite(int $owner_id, int $recipient_id, string $path): bool
	{
		$path = Shares::normalizePath($path);

		foreach (self::forRecipient($recipient_id) as $share) {
			if ((int) $share->owner !== $owner_id || !($share->permissions & self::PERM_WRITE)) {
				continue;
			}

			if ($path === $share->path || 0 === strpos($path, $share->path . '/')) {
				return true;
			}
		}

		return false;
	}

	/** Remove a share (by the owner, or by the recipient who leaves it) */
	static public function delete(int $id, int $user_id): bool
	{
		$db = DB::getInstance();
		$share = $db->first('SELECT * FROM user_shares WHERE id = ? AND (owner = ? OR recipient = ?);', $id, $user_id, $user_id);

		if (!$share) {
			return false;
		}

		$db->run('DELETE FROM user_shares WHERE id = ?;', $id);

		// Public links the recipient made from this folder stop working with the share
		self::removeRecipientLinks($share);

		return true;
	}

	/** @return object[] */
	static public function forRecipient(int $user_id): array
	{
		return iterator_to_array(DB::getInstance()->iterate('SELECT * FROM user_shares WHERE recipient = ? ORDER BY mount;', $user_id), false);
	}

	/** @return object[] */
	static public function forOwner(int $user_id, ?string $path = null): array
	{
		$db = DB::getInstance();

		if ($path !== null) {
			return iterator_to_array($db->iterate('SELECT * FROM user_shares WHERE owner = ? AND path = ? ORDER BY id;', $user_id, Shares::normalizePath($path)), false);
		}

		return iterator_to_array($db->iterate('SELECT * FROM user_shares WHERE owner = ? ORDER BY path, id;', $user_id), false);
	}

	/** Owner deleted a folder: stop sharing it (and its sub-folders) */
	static public function removePath(int $owner_id, string $path): void
	{
		$path = Shares::normalizePath($path);
		$db = DB::getInstance();
		$db->run('DELETE FROM user_shares WHERE owner = ? AND (path = ? OR path LIKE ? ESCAPE \'\\\');',
			$owner_id, $path, $db->getPathLikeExpression($path));
	}

	/** Owner renamed or moved a folder: keep the shares working */
	static public function movePath(int $owner_id, string $from, string $to): void
	{
		$from = Shares::normalizePath($from);
		$to = Shares::normalizePath($to);

		if ($from === '' || $from === $to) {
			return;
		}

		$db = DB::getInstance();
		$db->begin();
		$db->run('UPDATE user_shares SET path = ? WHERE owner = ? AND path = ?;', $to, $owner_id, $from);
		$db->run('UPDATE user_shares SET path = ? || substr(path, ?) WHERE owner = ? AND path LIKE ? ESCAPE \'\\\';',
			$to, strlen($from) + 1, $owner_id, $db->getPathLikeExpression($from));
		$db->commit();
	}
}
