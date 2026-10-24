<?php

namespace KaraDAV;

/**
 * File name search over the `files` index (own files + folders shared with the user)
 */
class Search
{
	const LIMIT = 200;

	static protected function like(string $w): string
	{
		return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w) . '%';
	}

	/**
	 * @return array{results: array, truncated: bool}
	 */
	static public function run(User $user, string $query): array
	{
		$words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))), fn($w) => $w !== ''));
		$words = array_slice($words, 0, 5);

		if (!count($words) || mb_strlen(implode('', $words)) < 2) {
			return ['results' => [], 'truncated' => false];
		}

		$out = [];
		$truncated = false;

		// Own files
		self::collect($out, $truncated, $user->id, rtrim($user->path, '/') . '/', $words, null, '');

		// Folders shared with the user
		foreach (UserShares::forRecipient($user->id) as $share) {
			if ($truncated) {
				break;
			}

			$owner = (new Users)->getById((int) $share->owner);

			if (!$owner || null === Shares::resolve($owner->path, $share->path)) {
				continue;
			}

			self::collect($out, $truncated, $owner->id, rtrim($owner->path, '/') . '/', $words,
				Shares::normalizePath($share->path), $share->mount);
		}

		$first = $words[0];
		usort($out, function ($a, $b) use ($first) {
			$pa = mb_strpos(mb_strtolower($a['name']), $first) === 0 ? 0 : 1;
			$pb = mb_strpos(mb_strtolower($b['name']), $first) === 0 ? 0 : 1;
			return [$pa, mb_strtolower($a['name']), $a['path']] <=> [$pb, mb_strtolower($b['name']), $b['path']];
		});

		return ['results' => $out, 'truncated' => $truncated];
	}

	static protected function collect(array &$out, bool &$truncated, int $user_id, string $root, array $words, ?string $subtree, string $mount): void
	{
		$db = DB::getInstance();
		$sql = 'SELECT path, size, modified FROM files WHERE user = ?';
		$params = [$user_id];

		if ($subtree !== null) {
			$sql .= ' AND path >= ? AND path < ?';
			$params[] = $subtree . '/';
			$params[] = $subtree . '0';
		}

		// Native pre-filter on plain ASCII words, exact (accent-aware) check is done below
		foreach ($words as $w) {
			if (preg_match('/^[\x20-\x7e]+$/', $w)) {
				$sql .= ' AND path LIKE ? ESCAPE \'\\\'';
				$params[] = self::like($w);
			}
		}

		foreach ($db->iterate($sql, ...$params) as $row) {
			$rel = $row->path;
			$name = basename($rel);
			$lname = mb_strtolower($name);

			foreach ($words as $w) {
				if (mb_strpos($lname, $w) === false) {
					continue 2;
				}
			}

			// Skip hidden items (.trash, .thumbs...)
			if (preg_match('!(^|/)\.!', $rel)) {
				continue;
			}

			$full = $root . $rel;

			if (!file_exists($full)) {
				continue;
			}

			if ($subtree !== null) {
				$rel = $mount . '/' . substr($rel, strlen($subtree) + 1);
			}

			if (count($out) >= self::LIMIT) {
				$truncated = true;
				return;
			}

			$dir = is_dir($full);
			$out[] = [
				'name' => $name,
				'path' => $rel,
				'dir' => $dir,
				'size' => $dir ? 0 : (int) $row->size,
				'modified' => (int) $row->modified,
			];
		}
	}
}
