<?php

namespace KaraDAV;

use KD2\WebDAV\Exception as WebDAV_Exception;
use KD2\WebDAV\WebDAV as WebDAV_Base;

/**
 * Storage that adds the folders shared by other users: each one is shown in the root of the
 * current user as a folder, and every operation inside is executed on the owner's storage,
 * after checking the share permissions.
 */
class MountStorage extends Storage
{
	protected ?array $mounts = null;
	protected array $owner_storages = [];

	public function setUser(User $user): void
	{
		parent::setUser($user);
		$this->mounts = null;
		$this->owner_storages = [];
	}

	/**
	 * Valid mounts of the current user, by mount name
	 * @return object[]
	 */
	public function mounts(): array
	{
		if (null !== $this->mounts) {
			return $this->mounts;
		}

		$this->mounts = [];
		$users = new Users;

		foreach (UserShares::forRecipient($this->user->id) as $share) {
			$owner = $users->getById((int) $share->owner);

			if (!$owner || null === Shares::resolve($owner->path, $share->path)) {
				continue; // owner or folder is gone
			}

			$share->owner_user = $owner;
			$this->mounts[$share->mount] = $share;
		}

		return $this->mounts;
	}

	public function resetMounts(): void
	{
		$this->mounts = null;
	}

	protected function ownerStorage(object $mount): Storage
	{
		$id = (int) $mount->owner;

		if (!isset($this->owner_storages[$id])) {
			$st = new Storage($this->nextcloud);
			$st->setUser($mount->owner_user);
			$this->owner_storages[$id] = $st;
		}

		return $this->owner_storages[$id];
	}

	/**
	 * @return array|null [Storage $storage, string $uri in that storage, object $mount, bool $is_mount_root]
	 */
	protected function resolve(string $uri): ?array
	{
		$uri = trim($uri, '/');

		if ($uri === '') {
			return null;
		}

		[$first, $rest] = explode('/', $uri, 2) + [1 => ''];
		$mount = $this->mounts()[$first] ?? null;

		if (!$mount) {
			return null;
		}

		// Never allow to climb out of the shared folder
		if ($rest !== '' && (str_contains($rest, "\0") || str_contains($rest, '\\')
			|| preg_match('~(?:^|/)\.\.?(?:/|$)~', $rest))) {
			throw new WebDAV_Exception('Invalid path', 403);
		}

		return [$this->ownerStorage($mount), $mount->path . ($rest !== '' ? '/' . $rest : ''), $mount, $rest === ''];
	}

	protected function requireWrite(object $mount): void
	{
		if (!($mount->permissions & UserShares::PERM_WRITE)) {
			throw new WebDAV_Exception('This shared folder is read-only', 403);
		}
	}

	// ---- Reading --------------------------------------------------------------------------

	public function list(string $uri, ?array $properties): iterable
	{
		if ($r = $this->resolve($uri)) {
			return $r[0]->list($r[1], $properties);
		}

		$list = parent::list($uri, $properties);

		if (trim($uri, '/') === '') {
			$mounts = array_map(fn() => null, $this->mounts());
			$list = $mounts + (array) $list;
		}

		return $list;
	}

	public function get(string $uri): ?array
	{
		return ($r = $this->resolve($uri)) ? $r[0]->get($r[1]) : parent::get($uri);
	}

	public function fetch(string $uri): ?string
	{
		return ($r = $this->resolve($uri)) ? $r[0]->fetch($r[1]) : parent::fetch($uri);
	}

	public function exists(string $uri): bool
	{
		return ($r = $this->resolve($uri)) ? $r[0]->exists($r[1]) : parent::exists($uri);
	}

	public function propfind(string $uri, ?array $properties, int $depth): ?array
	{
		if (!($r = $this->resolve($uri))) {
			return parent::propfind($uri, $properties, $depth);
		}

		$out = $r[0]->propfind($r[1], $properties, $depth);

		return $out === null ? null : $this->adjustProperties($out, $r[2], $r[3]);
	}

	public function get_file_property(string $uri, string $name, int $depth)
	{
		if (!($r = $this->resolve($uri))) {
			$v = parent::get_file_property($uri, $name, $depth);

			// The root ETag must change when something changes inside a shared folder,
			// otherwise sync clients would not look for changes in it
			if ($name === 'DAV::getetag' && trim($uri, '/') === '' && count($this->mounts())) {
				$parts = [$v];

				foreach ($this->mounts() as $m) {
					$parts[] = $m->mount . ':' . $this->ownerStorage($m)->get_file_property($m->path, 'DAV::getetag', 0);
				}

				$v = md5(implode('|', $parts));
			}

			return $v;
		}

		$v = $r[0]->get_file_property($r[1], $name, $depth);

		if ($v !== null) {
			$tmp = $this->adjustProperties([$name => $v], $r[2], $r[3]);
			$v = $tmp[$name] ?? $v;
		}

		return $v;
	}

	/**
	 * Permissions the current user has on an item inside a shared folder
	 */
	protected function adjustProperties(array $props, object $mount, bool $is_root): array
	{
		$write = (bool) ($mount->permissions & UserShares::PERM_WRITE);
		$is_dir = isset($props['DAV::resourcetype']) && $props['DAV::resourcetype'] === 'collection';

		if (array_key_exists('http://owncloud.org/ns:permissions', $props)) {
			$perms = 'G';

			if ($is_root) {
				$perms = 'S' . $perms . 'D' . ($write ? 'CK' : ''); // D: leave the share (unshare)
			}
			elseif ($write) {
				$perms .= 'WDNV' . ($is_dir ? 'CK' : '');
			}

			$props['http://owncloud.org/ns:permissions'] = $perms;
		}

		foreach (['http://open-collaboration-services.org/ns:share-permissions', 'http://nextcloud.org/ns:share-permissions'] as $key) {
			if (array_key_exists($key, $props)) {
				$props[$key] = (string) UserShares::toNextcloudPermissions((int) $mount->permissions);
			}
		}

		if ($is_root && array_key_exists('DAV::displayname', $props)) {
			$props['DAV::displayname'] = $mount->mount;
		}

		return $props;
	}

	public function getFileId(string $path): ?int
	{
		return ($r = $this->resolve($path)) ? $r[0]->getFileId($r[1]) : parent::getFileId($path);
	}

	public function getFilePathFromId(int $id): ?string
	{
		$path = parent::getFilePathFromId($id);

		if ($path !== null) {
			return $path;
		}

		// Files of shared folders
		$row = DB::getInstance()->first('SELECT user, path FROM files WHERE id = ?;', $id);

		if (!$row) {
			return null;
		}

		foreach ($this->mounts() as $m) {
			if ((int) $m->owner !== (int) $row->user) {
				continue;
			}

			if ($row->path === $m->path) {
				return $m->mount;
			}

			if (0 === strpos($row->path, $m->path . '/')) {
				return $m->mount . substr($row->path, strlen($m->path));
			}
		}

		return null;
	}

	public function createFileCache(string $uri): void
	{
		if ($r = $this->resolve($uri)) {
			$r[0]->createFileCache($r[1]);
		}
		else {
			parent::createFileCache($uri);
		}
	}

	// ---- Locks and properties --------------------------------------------------------------

	public function getLock(string $uri, ?string $token = null): ?string
	{
		return ($r = $this->resolve($uri)) ? $r[0]->getLock($r[1], $token) : parent::getLock($uri, $token);
	}

	public function lock(string $uri, string $token, string $scope): void
	{
		if ($r = $this->resolve($uri)) {
			$this->requireWrite($r[2]);
			$r[0]->lock($r[1], $token, $scope);
		}
		else {
			parent::lock($uri, $token, $scope);
		}
	}

	public function unlock(string $uri, string $token): void
	{
		if ($r = $this->resolve($uri)) {
			$r[0]->unlock($r[1], $token);
		}
		else {
			parent::unlock($uri, $token);
		}
	}

	public function getResourceProperties(string $uri): \KaraDAV\Properties
	{
		return ($r = $this->resolve($uri)) ? $r[0]->getResourceProperties($r[1]) : parent::getResourceProperties($uri);
	}

	public function proppatch(string $uri, array $properties): array
	{
		if ($r = $this->resolve($uri)) {
			$this->requireWrite($r[2]);
			return $r[0]->proppatch($r[1], $properties);
		}

		return parent::proppatch($uri, $properties);
	}

	// ---- Writing ---------------------------------------------------------------------------

	/**
	 * Where a file must be physically written: [Storage, uri in that storage, real path]
	 * Checks the write permission, used for chunked uploads.
	 */
	public function resolveForWrite(string $uri): array
	{
		if (!($r = $this->resolve($uri))) {
			return parent::resolveForWrite($uri);
		}

		$this->requireWrite($r[2]);

		if ($r[3]) {
			throw new WebDAV_Exception('Target is a directory', 409);
		}

		return [$r[0], $r[1], $r[0]->root . $r[1]];
	}

	public function put(string $uri, $pointer, ?string $hash_algo = null, ?string $hash = null): bool
	{
		if (!($r = $this->resolve($uri))) {
			return parent::put($uri, $pointer, $hash_algo, $hash);
		}

		$this->requireWrite($r[2]);

		if ($r[3]) {
			throw new WebDAV_Exception('Target is a directory', 409);
		}

		return $r[0]->put($r[1], $pointer, $hash_algo, $hash);
	}

	public function mkcol(string $uri): void
	{
		if (!($r = $this->resolve($uri))) {
			parent::mkcol($uri);
			return;
		}

		if ($r[3]) {
			throw new WebDAV_Exception('This folder already exists', 405);
		}

		$this->requireWrite($r[2]);
		$r[0]->mkcol($r[1]);
	}

	public function touch(string $uri, \DateTimeInterface $datetime): bool
	{
		if (!($r = $this->resolve($uri))) {
			return parent::touch($uri, $datetime);
		}

		$this->requireWrite($r[2]);
		return $r[0]->touch($r[1], $datetime);
	}

	public function delete(string $uri): void
	{
		if (!($r = $this->resolve($uri))) {
			parent::delete($uri);
			return;
		}

		// Deleting the shared folder itself = leaving the share, the owner keeps the files
		if ($r[3]) {
			UserShares::delete((int) $r[2]->id, $this->user->id);
			$this->resetMounts();
			return;
		}

		$this->requireWrite($r[2]);
		$r[0]->delete($r[1]);
	}

	public function copymove(bool $move, string $uri, string $destination): bool
	{
		$src = $this->resolve($uri);
		$dst = $this->resolve($destination);

		if (!$src && !$dst) {
			return parent::copymove($move, $uri, $destination);
		}

		if ($dst && $dst[3]) {
			throw new WebDAV_Exception('The destination is a shared folder', 403);
		}

		if ($src && $src[3] && $move) {
			throw new WebDAV_Exception('A shared folder cannot be moved or renamed', 403);
		}

		if ($dst) {
			$this->requireWrite($dst[2]);
		}

		if ($src && $move) {
			$this->requireWrite($src[2]);
		}

		// Same owner storage: let it do the work
		if ($src && $dst && $src[0] === $dst[0] && !$src[3]) {
			return $src[0]->copymove($move, $src[1], $dst[1]);
		}

		return $this->crossCopy($move, $uri, $destination);
	}

	protected function physicalPath(string $uri): string
	{
		return ($r = $this->resolve($uri)) ? $r[0]->root . $r[1] : $this->root . $uri;
	}

	/**
	 * Copy or move between different storages (own files <-> shared folder, or two shared folders)
	 */
	protected function crossCopy(bool $move, string $uri, string $destination): bool
	{
		$source = $this->physicalPath($uri);

		if (!file_exists($source)) {
			throw new WebDAV_Exception('File not found', 404);
		}

		$existed = $this->exists($destination);

		if ($existed) {
			$this->delete($destination);
		}

		$this->copyTree($source, $destination);

		if ($move) {
			$this->delete($uri);
		}

		return $existed;
	}

	protected function copyTree(string $source, string $destination): void
	{
		if (is_dir($source)) {
			$this->mkcol($destination);

			foreach (scandir($source) ?: [] as $item) {
				if ($item === '.' || $item === '..' || $item === '.trash') {
					continue;
				}

				$this->copyTree($source . '/' . $item, $destination . '/' . $item);
			}

			return;
		}

		$fp = fopen($source, 'rb');
		$this->put($destination, $fp);
		$this->touch($destination, new \DateTime('@' . filemtime($source)));
	}
}
