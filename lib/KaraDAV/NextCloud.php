<?php

namespace KaraDAV;

use KD2\WebDAV\NextCloud as WebDAV_NextCloud;
use KD2\WebDAV\Exception as WebDAV_Exception;
use KD2\Graphics\SVG\Avatar;
use KD2\Graphics\Image;

class NextCloud extends WebDAV_NextCloud
{
	protected Users $users;
	protected ?User $user;
	protected string $temporary_chunks_path;

	public function __construct(Users $users)
	{
		$this->users = $users;
		$this->temporary_chunks_path =  sprintf(STORAGE_PATH, '_chunks');
		$this->setRootURL(WWW_URL);

		// KaraDAV users are allowed to try iOS clients if they wish
		// @see https://github.com/kd2org/karadav/issues/22
		$this->block_ios_clients = BLOCK_IOS_APPS;

		$this->theme = [
			'name'                 => 'KaraDAV',
			'url'                  => 'https://fossil.kd2.org/karadav/',
			'slogan'               => 'lighter than NextCloud',
			'color'                => '#000000',
			'color-text'           => '#ffffff',
			'color-element'        => '#000000',
			'color-element-bright' => '#000000',
			'color-element-dark'   => '#000000',
			'logo'                 => '',
			'background'           => '#ffffff',
			'background-text'      => '#000000',
			'background-plain'     => false,
			'background-default'   => false,
			'logoheader'           => $this->root_url . '/logo.svg',
			'favicon'              => $this->root_url . '/logo.svg',
		];
	}

	public function auth(?string $login, ?string $password): bool
	{
		$user = $this->users->login($login, $password);

		if (!$user) {
			// Try app session
			$user = $this->users->appSessionLogin($login, $password);
		}

		if (!$user) {
			return false;
		}

		$this->user = $user;
		$this->storage->setUser($user);

		return true;
	}

	public function getUserName(): ?string
	{
		return $this->users->current()->login ?? null;
	}

	public function setUserName(string $login): bool
	{
		$ok = $this->users->setCurrent($login);

		if ($ok) {
			$this->user = $this->users->current();
			$this->storage->setUser($this->user);
		}

		return $ok;
	}

	public function getUserQuota(): array
	{
		$quota = (array) $this->user->quota();
		// Add bytes to quota values as this is what nextcloud is expecting
		$quota = array_map(fn($v) => $v ? strval($v) . '000000' : $v, $quota);
		return $quota;
	}

	public function generateToken(): string
	{
		return sha1(random_bytes(16));
	}

	public function validateToken(string $token): ?array
	{
		$session = $this->users->appSessionValidateToken($token);

		if (!$session) {
			return null;
		}

		return ['login' => $session->user->login, 'password' => $session->password];
	}

	public function getLoginURL(?string $token): string
	{
		if ($token) {
			return sprintf('%slogin.php?nc=%s', WWW_URL, $token);
		}
		else {
			return sprintf('%slogin.php?nc=redirect', WWW_URL);
		}
	}

	public function getDirectDownloadSecret(string $uri, string $login): string
	{
		$user = $this->users->get($login);

		if (!$user) {
			throw new WebDAV_Exception('No user with that name', 401);
		}

		return WebDAV::hmac([$uri, $user->login, $user->password]);
	}

	protected function cleanChunks(): void
	{
		$expire = time() - 36*3600;
		$list = glob($this->temporary_chunks_path . '/*/*');

		if (empty($list)) {
			return;
		}

		foreach ($list as $dir) {
			$dir_list = glob($dir . '/*');

			if (!$dir_list) {
				if (@filemtime($dir) < $expire) {
					Storage::deleteDirectory($dir);
				}

				continue;
			}

			$first_file = current($dir_list);

			if (filemtime($first_file) < $expire) {
				Storage::deleteDirectory($dir);
			}
		}
	}

	public function storeChunk(string $login, string $name, string $part, $pointer): void
	{
		$this->cleanChunks();

		$path = $this->temporary_chunks_path . '/' . $login . '/' . $name;
		@mkdir($path, 0777, true);

		$file_path = $path . '/' . $part;
		$out = fopen($file_path, 'wb');
		$quota = $this->user->quota();
		$size = Storage::getDirectorySize($path);

		while (!feof($pointer)) {
			$data = fread($pointer, 8192);
			$size = Storage::addNumbersSafe($size, strlen($data));

			if (Storage::compareQuotaSafe($quota->free, $size)) {
				$this->deleteChunks($login, $name);
				throw new WebDAV_Exception('Your quota does not allow for the upload of this file', 403);
			}

			fwrite($out, $data);
		}

		fclose($out);
		fclose($pointer);
	}

	public function listChunks(string $login, string $name): array
	{
		$path = $this->temporary_chunks_path . '/' . $login . '/' . $name;
		$list = glob($path . '/*');

		if (!$list) {
			return [];
		}

		$list = array_map(fn($a) => str_replace($path . '/', '', $a), $list);
		return $list;
	}

	public function deleteChunks(string $login, string $name): void
	{
		$path = $this->temporary_chunks_path . '/' . $login . '/' . $name;
		Storage::deleteDirectory($path);
	}

	public function assembleChunks(string $login, string $name, string $target, ?int $mtime): array
	{
		$uri = $target;

		// The target may be inside a folder shared by another user
		[$storage, $uri, $target] = $this->storage->resolveForWrite($uri);

		$storage->validatePath($target);

		$parent = dirname($target);

		if (!is_dir($parent)) {
			throw new WebDAV_Exception('Target parent directory does not exist', 409);
		}

		$path = $this->temporary_chunks_path . '/' . $login . '/' . $name;
		$exists = file_exists($target);

		if ($exists && is_dir($target)) {
			throw new WebDAV_Exception('Target exists and is a directory', 409);
		}

		$out = fopen($target, 'wb');
		$list = glob($path . '/*') ?: [];

		// Make sure chunks are ordered "naturally": 1, 2, 3, 10, 11, and no 1, 10, 11, 2, 3…
		natcasesort($list);

		foreach ($list as $file) {
			$in = fopen($file, 'rb');

			while (!feof($in)) {
				fwrite($out, fread($in, 8192));
			}

			fclose($in);
		}

		fclose($out);
		$this->deleteChunks($login, $name);

		if ($mtime) {
			touch($target, $mtime);
		}

		$storage->createFileCache($uri);

		return [
			'created' => !$exists,
			'etag'    => $storage->get_file_property($uri, 'DAV::getetag', 0),
		];
	}

	protected function nc_avatar(): void
	{
		header('Content-Type: image/svg+xml; charset=utf-8');
		echo Avatar::beam($_SERVER['REQUEST_URI'] ?? '', ['colors' => ['#009', '#ccf', '#9cf']]);
	}

	/**
	 * File preview, new version, requires a file ID
	 */
	protected function nc_preview_v2(): void
	{
		$id = $_GET['fileId'] ?? null;
		$w = $_GET['x'] ?? null;
		$h = $_GET['y'] ?? null;

		if (!ENABLE_THUMBNAILS_OK || !$id) {
			http_response_code(404);
			return;
		}

		$this->requireAuth();
		$uri = $this->storage->getFilePathFromId((int)$id);

		if (!$uri) {
			http_response_code(404);
			return;
		}

		$this->serveThumbnail($uri, $w, $h, false, true);
	}

	public function serveThumbnail(string $uri, int $width, int $height, bool $crop = false, bool $preview = false): void
	{
		if (!ENABLE_THUMBNAILS_OK
			|| !preg_match('/\.(?:jpe?g|gif|png|webp)$/', $uri)) {
			http_response_code(404);
			return;
		}

		$this->requireAuth();
		$uri = preg_replace(self::WEBDAV_BASE_REGEXP, '', $uri);

		if (!$this->storage->exists($uri)) {
			throw new WebDAV_Exception('Not found', 404);
		}

		if ($crop || $width < 300 || $height < 300) {
			$size = 150;
		}
		elseif ($width <= 600 || $height <= 600) {
			$size = 500;
		}
		else {
			$size = 1200;
		}

		$id = $this->storage->getFileId($uri);

		if (!$id) {
			throw new WebDAV_Exception('Not found', 404);
		}

		$cache_path = $this->storage->getThumbnailCachePath($id, $size);

		if (!file_exists($cache_path)) {
			$this->server->log('NC Creating thumbnail (%d): %s', $size, basename($cache_path));
			try {
				$i = new Image;
				$i->openFromBlob($this->storage->fetch($uri));

				if ($size === 150) {
					$i->cropResize($size);
				}
				else {
					$i->resize($size);
				}

				$perms = @fileperms(dirname(dirname(dirname($cache_path)))) ?: 0777;
				@mkdir(dirname($cache_path), $perms, true);
				$i->save($cache_path, 'webp');
				unset($i);
			}
			catch (\UnexpectedValueException $e) {
				throw new WebDAV_Exception('Not an image', 404);
			}
		}
		else {
			$this->server->log('NC Cached thumbnail (%d)', $size);
		}

		header('Content-Type: image/webp');
		readfile($cache_path);
	}

	// ---- Public link shares (OCS Share API) -----------------------------------------------

	public function nc_capabilities()
	{
		$c = parent::nc_capabilities();

		$c['ocs']['data']['capabilities']['files_sharing'] = [
			'api_enabled'         => true,
			'default_permissions' => Shares::PERM_READ,
			'group_sharing'       => false,
			'resharing'           => false,
			'sharebymail'         => ['enabled' => false],
			'public'              => [
				'enabled'            => true,
				'password'           => ['enforced' => false, 'askForOptionalPassword' => false],
				'expire_date'        => ['enabled' => false],
				'multiple_links'     => true,
				'send_mail'          => false,
				'upload'             => false,
				'upload_files_drop'  => false,
			],
			'user'                => ['send_mail' => false, 'expire_date' => ['enabled' => false]],
		];

		$c['ocs']['data']['capabilities']['public']['enabled'] = true;

		return $c;
	}

	protected function ocsFailure(int $code, string $message): array
	{
		http_response_code($code);

		return ['ocs' => [
			'meta' => ['status' => 'failure', 'statuscode' => $code, 'message' => $message],
			'data' => [],
		]];
	}

	protected function shareParams(): array
	{
		$params = $_GET;
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

		if ($method !== 'POST' && $method !== 'PUT') {
			return $params;
		}

		$raw = file_get_contents('php://input');
		$type = $_SERVER['CONTENT_TYPE'] ?? '';

		if (false !== stripos($type, 'json')) {
			$json = json_decode($raw, true);

			if (is_array($json)) {
				$params = array_merge($params, $json);
			}
		}
		elseif ($method === 'POST' && !empty($_POST)) {
			$params = array_merge($params, $_POST);
		}
		elseif ($raw !== '' && $raw !== false) {
			parse_str($raw, $body);
			$params = array_merge($params, $body);
		}

		return $params;
	}

	public function nc_user(): array
	{
		$out = parent::nc_user();

		// Show the display name (if any) in Nextcloud clients
		array_walk_recursive($out, function (&$v, $k) {
			if ($k === 'display-name' && $this->user) {
				$v = $this->user->label;
			}
		});

		return $out;
	}

	/**
	 * Where a path of the current user really lives: [owner, path in the owner's folder, creator id or null].
	 * A path inside a folder shared with the user belongs to the user who owns it.
	 */
	protected function shareTarget(string $path): array
	{
		$path = Shares::normalizePath($path);
		[$first, $rest] = explode('/', $path, 2) + [1 => ''];

		foreach (UserShares::forRecipient($this->user->id) as $m) {
			if ($m->mount !== $first) {
				continue;
			}

			$owner = $this->users->getById((int) $m->owner);

			if (!$owner) {
				break;
			}

			return [$owner, $m->path . ($rest !== '' ? '/' . $rest : ''), $this->user->id];
		}

		return [$this->user, $path, null];
	}

	/** Path as the current user sees it (inside a shared folder: under its mount name) */
	protected function shareDisplayPath(object $share): string
	{
		if ((int) $share->user === $this->user->id) {
			return $share->path;
		}

		foreach (UserShares::forRecipient($this->user->id) as $m) {
			if ((int) $m->owner === (int) $share->user && ($share->path === $m->path || 0 === strpos($share->path, $m->path . '/'))) {
				return $m->mount . substr($share->path, strlen($m->path));
			}
		}

		return $share->path;
	}

	protected function formatShare(object $share): array
	{
		$owner = (int) $share->user === $this->user->id ? $this->user : ($this->users->getById((int) $share->user) ?? $this->user);
		$dpath = $this->shareDisplayPath($share);
		$full = Shares::resolve($owner->path, $share->path);
		$is_dir = $full !== null && is_dir($full);
		$id = $this->storage->getFileId($dpath);
		$has_password = !empty($share->password);
		$login = $owner->login;
		$label = $owner->label;

		return [
			'id'                     => (string) $share->id,
			'share_type'             => 3,
			'uid_owner'              => $login,
			'displayname_owner'      => $label,
			'permissions'            => (int) $share->permissions,
			'can_edit'               => false,
			'can_delete'             => true,
			'stime'                  => (int) $share->created,
			'parent'                 => null,
			'expiration'             => $share->expiry ? $share->expiry . ' 00:00:00' : null,
			'token'                  => $share->token,
			'uid_file_owner'         => $login,
			'note'                   => '',
			'label'                  => (string) $share->label,
			'displayname_file_owner' => $label,
			'path'                   => '/' . $dpath,
			'item_type'              => $is_dir ? 'folder' : 'file',
			'mimetype'               => $is_dir ? 'httpd/unix-directory' : ($full ? Storage::mimeType($full) : 'application/octet-stream'),
			'has_preview'            => false,
			'storage_id'             => 'home::' . $login,
			'storage'                => 1,
			'item_source'            => (int) $id,
			'file_source'            => (int) $id,
			'file_parent'            => 0,
			'file_target'            => '/' . basename($share->path),
			'share_with'             => $has_password ? '***' : null,
			'share_with_displayname' => $has_password ? '(Shared link)' : '(Shared link)',
			'password'               => $has_password ? '***' : null,
			'send_password_by_talk'  => false,
			'url'                    => Shares::url($share),
			'mail_send'              => 0,
			'hide_download'          => 0,
			'attributes'             => null,
		];
	}

	const USER_SHARE_OFFSET = 1000000000;

	protected function formatUserShare(object $share): array
	{
		$recipient = $this->users->getById((int) $share->recipient);
		$login = $this->user->login;
		$id = $this->storage->getFileId($share->path);

		return [
			'id'                     => (string) (self::USER_SHARE_OFFSET + $share->id),
			'share_type'             => 0,
			'uid_owner'              => $login,
			'displayname_owner'      => $this->user->label,
			'permissions'            => UserShares::toNextcloudPermissions((int) $share->permissions),
			'can_edit'               => true,
			'can_delete'             => true,
			'stime'                  => (int) $share->created,
			'parent'                 => null,
			'expiration'             => null,
			'token'                  => null,
			'uid_file_owner'         => $login,
			'note'                   => '',
			'label'                  => '',
			'displayname_file_owner' => $this->user->label,
			'path'                   => '/' . $share->path,
			'item_type'              => 'folder',
			'mimetype'               => 'httpd/unix-directory',
			'has_preview'            => false,
			'storage_id'             => 'home::' . $login,
			'storage'                => 1,
			'item_source'            => (int) $id,
			'file_source'            => (int) $id,
			'file_parent'            => 0,
			'file_target'            => '/' . $share->mount,
			'share_with'             => $recipient ? $recipient->login : null,
			'share_with_displayname' => $recipient ? $recipient->label : null,
			'send_password_by_talk'  => false,
			'mail_send'              => 0,
			'hide_download'          => 0,
			'attributes'             => null,
		];
	}

	protected function userShareFromId(int $id): ?object
	{
		$share = UserShares::get($id - self::USER_SHARE_OFFSET);
		return $share && (int) $share->owner === $this->user->id ? $share : null;
	}

	public function nc_shares(string $uri = ''): array
	{
		$this->requireAuth();

		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$p = $this->shareParams();
		$id = preg_match('~/shares/(\d+)/?$~', $uri, $m) ? (int) $m[1] : null;

		if (preg_match('~/shares/pending/?$~', $uri)) {
			return $this->nc_ocs([]);
		}

		try {
			switch ($method) {
				case 'GET':
					if ($id !== null && $id >= self::USER_SHARE_OFFSET) {
						$share = $this->userShareFromId($id);

						return $share ? $this->nc_ocs([$this->formatUserShare($share)]) : $this->ocsFailure(404, 'Wrong share ID, share does not exist');
					}

					if ($id !== null) {
						$share = Shares::get($id, $this->user->id);

						if (!$share) {
							return $this->ocsFailure(404, 'Wrong share ID, share does not exist');
						}

						return $this->nc_ocs([$this->formatShare($share)]);
					}

					$path = isset($p['path']) && $p['path'] !== '' ? (string) $p['path'] : null;
					$subfiles = in_array(strtolower((string) ($p['subfiles'] ?? '')), ['1', 'true'], true);
					$owner_id = null;
					$opath = $path;

					if ($path !== null) {
						[$o, $opath, $creator] = $this->shareTarget($path);
						$owner_id = $o->id;
					}

					$list = Shares::list($this->user->id, $opath, $subfiles, $owner_id);

					$out = array_map([$this, 'formatShare'], $list);

					foreach (UserShares::forOwner($this->user->id, $subfiles ? null : $path) as $us) {
						if ($path === null || !$subfiles || 0 === strpos($us->path . '/', Shares::normalizePath($path) . '/')) {
							$out[] = $this->formatUserShare($us);
						}
					}

					return $this->nc_ocs($out);

				case 'POST':
					$type = (int) ($p['shareType'] ?? 3);

					if ($type === 0) {
						$with = (string) ($p['shareWith'] ?? '');
						$recipient = $with !== '' ? $this->users->fetch(strtolower($with)) : null;

						if (!$recipient) {
							return $this->ocsFailure(404, 'Wrong share recipient: no such user');
						}

						if (empty($p['path'])) {
							return $this->ocsFailure(400, 'Please specify a file or folder path');
						}

						$share = UserShares::create($this->user, (string) $p['path'], $recipient,
							UserShares::fromNextcloudPermissions((int) ($p['permissions'] ?? 1)));

						return $this->nc_ocs($this->formatUserShare($share));
					}

					if ($type !== 3) {
						return $this->ocsFailure(400, 'Only user shares (shareType=0) and public links (shareType=3) are supported');
					}

					if (in_array(strtolower((string) ($p['publicUpload'] ?? '')), ['1', 'true'], true)) {
						return $this->ocsFailure(403, 'Public upload is not supported');
					}

					if (empty($p['path'])) {
						return $this->ocsFailure(400, 'Please specify a file or folder path');
					}

					[$owner, $opath, $creator] = $this->shareTarget((string) $p['path']);

					$share = Shares::create(
						$owner,
						$opath,
						isset($p['password']) ? (string) $p['password'] : null,
						isset($p['expireDate']) ? (string) $p['expireDate'] : null,
						(string) ($p['label'] ?? $p['name'] ?? ''),
						$creator
					);

					return $this->nc_ocs($this->formatShare($share));

				case 'PUT':
					if ($id !== null && $id >= self::USER_SHARE_OFFSET) {
						$share = $this->userShareFromId($id);

						if (!$share) {
							return $this->ocsFailure(404, 'Wrong share ID, share does not exist');
						}

						if (isset($p['permissions'])) {
							$share = UserShares::update($share, UserShares::fromNextcloudPermissions((int) $p['permissions']));
						}

						return $this->nc_ocs($this->formatUserShare($share));
					}

					$share = $id !== null ? Shares::get($id, $this->user->id) : null;

					if (!$share) {
						return $this->ocsFailure(404, 'Wrong share ID, share does not exist');
					}

					$share = Shares::update(
						$share,
						array_key_exists('password', $p) ? (string) $p['password'] : null,
						array_key_exists('expireDate', $p) ? (string) $p['expireDate'] : null,
						array_key_exists('label', $p) ? (string) $p['label'] : (array_key_exists('name', $p) ? (string) $p['name'] : null)
					);

					return $this->nc_ocs($this->formatShare($share));

				case 'DELETE':
					if ($id !== null && $id >= self::USER_SHARE_OFFSET) {
						if (!$this->userShareFromId($id)) {
							return $this->ocsFailure(404, 'Wrong share ID, share does not exist');
						}

						UserShares::delete($id - self::USER_SHARE_OFFSET, $this->user->id);
						return $this->nc_ocs([]);
					}

					if ($id === null || !Shares::delete($id, $this->user->id)) {
						return $this->ocsFailure(404, 'Wrong share ID, share does not exist');
					}

					return $this->nc_ocs([]);
			}
		}
		catch (\InvalidArgumentException $e) {
			return $this->ocsFailure(404, $e->getMessage());
		}

		return $this->ocsFailure(405, 'Method not allowed');
	}

	public function nc_sharees(): array
	{
		$this->requireAuth();

		$search = strtolower(trim((string) ($_GET['search'] ?? '')));
		$exact = [];
		$users = [];

		foreach ($this->users->list() as $u) {
			if ($u->id === $this->user->id) {
				continue;
			}

			if ($search !== '' && false === strpos(strtolower($u->login), $search) && false === strpos(strtolower($u->label), $search)) {
				continue;
			}

			$item = [
				'label'  => $u->label,
				'value'  => ['shareType' => 0, 'shareWith' => $u->login],
				'shareWithDisplayNameUnique' => $u->login,
			];

			if ($search !== '' && strtolower($u->login) === $search) {
				$exact[] = $item;
			}
			else {
				$users[] = $item;
			}
		}

		return $this->nc_ocs([
			'exact'   => ['users' => $exact, 'groups' => [], 'remotes' => [], 'emails' => [], 'circles' => [], 'rooms' => []],
			'users'   => $users,
			'groups'  => [],
			'remotes' => [],
			'emails'  => [],
			'lookup'  => [],
		]);
	}
}
