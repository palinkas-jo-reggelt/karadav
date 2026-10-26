<?php

namespace KaraDAV;

/**
 * Server-side previews for CSV/TSV (table), ZIP (contents), ODT/ODP, PPTX and RTF (text only).
 * Everything returned is already escaped HTML, built from text: nothing from the file is ever rendered as markup.
 */
class FilePreview
{
	const CSV_MAX_BYTES = 5 * 1024 * 1024;
	const CSV_MAX_ROWS = 1000;
	const ZIP_MAX_ENTRIES = 3000;
	const XML_MAX = 30 * 1024 * 1024;
	const TEXT_MAX = 1000000;
	const RTF_MAX_DEPTH = 200;

	const KINDS = [
		'csv' => 'table', 'tsv' => 'table',
		'zip' => 'zip',
		'odt' => 'odt', 'odp' => 'odt', 'pptx' => 'pptx', 'rtf' => 'rtf',
	];

	static public function kind(string $name): ?string
	{
		return self::KINDS[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
	}

	static protected function e(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	static protected function size(int $b): string
	{
		$u = ['B', 'KB', 'MB', 'GB', 'TB'];
		$i = 0;
		$f = (float) $b;

		while ($f >= 1024 && $i < 4) {
			$f /= 1024;
			$i++;
		}

		return ($i ? number_format($f, $f < 10 ? 1 : 0) : (string) $b) . ' ' . $u[$i];
	}

	/**
	 * Convert text from a legacy Windows/DOS code page ("CP1250", "CP1252", "CP850"...) to UTF-8.
	 * mbstring does not know CP1250, so iconv is used when available.
	 */
	static protected function legacy(string $s, string $cp): string
	{
		if (function_exists('iconv')) {
			$r = @iconv($cp, 'UTF-8//IGNORE', $s);

			if ($r !== false) {
				return $r;
			}
		}

		$fallback = ['CP1250' => 'ISO-8859-2', 'CP1252' => 'Windows-1252', 'CP1251' => 'Windows-1251', 'CP850' => 'CP850'];

		try {
			return mb_convert_encoding($s, 'UTF-8', $fallback[$cp] ?? 'Windows-1252');
		}
		catch (\Throwable $e) {
			return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
		}
	}

	/**
	 * Find the real file behind a path as the user sees it (own files, or a folder shared with them).
	 */
	static public function resolve(User $user, string $rel): ?string
	{
		$rel = Shares::normalizePath($rel);

		if ($rel === '' || Shares::isHidden($rel)) {
			return null;
		}

		$path = Shares::resolve($user->path, $rel);

		if ($path !== null && is_file($path)) {
			return $path;
		}

		[$first, $rest] = explode('/', $rel, 2) + [1 => ''];

		foreach (UserShares::forRecipient($user->id) as $share) {
			if ($share->mount !== $first) {
				continue;
			}

			$owner = (new Users)->getById((int) $share->owner);

			if (!$owner) {
				return null;
			}

			$base = Shares::resolve($owner->path, $share->path);
			$full = Shares::resolve($owner->path, $share->path . '/' . $rest);

			if ($base && $full && is_file($full) && 0 === strpos($full, $base . '/')) {
				return $full;
			}
		}

		return null;
	}

	/**
	 * @return array{html: string, pill: ?string}
	 */
	static public function render(string $file, string $name): array
	{
		try {
			$html = match (self::kind($name)) {
				'table' => self::table($file, strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'tsv'),
				'zip' => self::zip($file),
				'odt' => self::odt($file),
				'pptx' => self::pptx($file),
				'rtf' => self::rtf($file),
				default => null,
			};
		}
		catch (\Throwable $e) {
			$html = null;
		}

		if ($html === null) {
			return ['html' => '<p class="meta">This file could not be previewed. Please download it instead.</p>', 'pill' => null];
		}

		$pill = match (self::kind($name)) {
			'table' => 'Table Preview',
			'zip' => 'ZIP Contents',
			default => 'Text Preview of Document',
		};

		return ['html' => $html, 'pill' => $pill];
	}

	// ---- CSV / TSV --------------------------------------------------------------------------

	static protected function table(string $file, bool $tsv): string
	{
		$fp = fopen($file, 'rb');
		$data = (string) fread($fp, self::CSV_MAX_BYTES + 1);
		fclose($fp);
		$cut = strlen($data) > self::CSV_MAX_BYTES;

		if ($cut) {
			$data = substr($data, 0, (int) strrpos(substr($data, 0, self::CSV_MAX_BYTES), "\n") ?: self::CSV_MAX_BYTES);
		}

		if (str_starts_with($data, "\xEF\xBB\xBF")) {
			$data = substr($data, 3);
		}

		if (!mb_check_encoding($data, 'UTF-8')) {
			$data = self::legacy($data, 'CP1250');
		}

		$first = strtok($data, "\n") ?: '';
		$delim = ',';

		if ($tsv) {
			$delim = "\t";
		}
		else {
			$best = 0;

			foreach ([',', ';', "\t", '|'] as $d) {
				$n = substr_count($first, $d);

				if ($n > $best) {
					$best = $n;
					$delim = $d;
				}
			}
		}

		$fp = fopen('php://temp', 'r+');
		fwrite($fp, $data);
		rewind($fp);

		$rows = '';
		$count = 0;
		$maxcols = 0;

		while (($r = fgetcsv($fp, 0, $delim, '"', '')) !== false) {
			if ($r === [null]) {
				continue;
			}

			if ($count >= self::CSV_MAX_ROWS) {
				$cut = true;
				break;
			}

			$maxcols = max($maxcols, count($r));
			$tag = $count === 0 ? 'th' : 'td';
			$rows .= '<tr>' . implode('', array_map(fn($c) => '<' . $tag . '>' . self::e((string) $c) . '</' . $tag . '>', $r)) . '</tr>';
			$count++;
		}

		fclose($fp);

		$note = $cut ? '<p class="meta">Showing the first ' . $count . ' rows only.</p>' : '';
		return $note . '<div class="sheet"><table>' . $rows . '</table></div>';
	}

	// ---- ZIP --------------------------------------------------------------------------------

	/**
	 * Minimal ZIP central directory reader (no PHP extension needed).
	 * @return array{entries: array, data: string}|null
	 */
	static protected function zipEntries(string $file): ?array
	{
		$size = filesize($file);
		$fp = fopen($file, 'rb');

		if (!$fp || $size < 22) {
			return null;
		}

		$tail = min($size, 65557);
		fseek($fp, $size - $tail);
		$buf = (string) fread($fp, $tail);
		$pos = strrpos($buf, "PK\x05\x06");

		if ($pos === false) {
			fclose($fp);
			return null;
		}

		$e = unpack('vdisk/vcd/ventries/vtotal/Vcdsize/Vcdoffset', substr($buf, $pos + 4, 16));

		if ($e['total'] === 0xFFFF || $e['cdoffset'] === 0xFFFFFFFF || $e['cdsize'] > 32 * 1024 * 1024) {
			fclose($fp);
			return null; // zip64 or implausible
		}

		fseek($fp, $e['cdoffset']);
		$cd = (string) fread($fp, $e['cdsize']);
		fclose($fp);

		$entries = [];
		$o = 0;
		$len = strlen($cd);

		while ($o + 46 <= $len && substr($cd, $o, 4) === "PK\x01\x02" && count($entries) < 200000) {
			$h = unpack('vver/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/vint/Vext/Voffset', substr($cd, $o + 4, 42));
			$name = substr($cd, $o + 46, $h['nlen']);
			$o += 46 + $h['nlen'] + $h['elen'] + $h['clen'];

			if (!($h['flags'] & 0x800) && !mb_check_encoding($name, 'UTF-8')) {
				$name = self::legacy($name, 'CP850');
			}

			$entries[] = ['name' => $name, 'size' => $h['size'], 'csize' => $h['csize'], 'method' => $h['method'],
				'flags' => $h['flags'], 'offset' => $h['offset'], 'dir' => str_ends_with($name, '/')];
		}

		return $entries;
	}

	static protected function zipRead(string $file, array $entry, int $max = self::XML_MAX): ?string
	{
		if ($entry['flags'] & 1 || $entry['size'] > $max) {
			return null;
		}

		$fp = fopen($file, 'rb');
		fseek($fp, $entry['offset']);
		$lh = (string) fread($fp, 30);

		if (strlen($lh) < 30 || substr($lh, 0, 4) !== "PK\x03\x04") {
			fclose($fp);
			return null;
		}

		$l = unpack('vnlen/velen', substr($lh, 26, 4));
		fseek($fp, $entry['offset'] + 30 + $l['nlen'] + $l['elen']);
		$raw = (string) fread($fp, $entry['csize']);
		fclose($fp);

		if ($entry['method'] === 0) {
			return $raw;
		}

		if ($entry['method'] === 8) {
			$out = @gzinflate($raw, $max);
			return $out === false ? null : $out;
		}

		return null;
	}

	static protected function zipFind(array $entries, string $name): ?array
	{
		foreach ($entries as $e) {
			if ($e['name'] === $name) {
				return $e;
			}
		}

		return null;
	}

	static protected function zip(string $file): ?string
	{
		$entries = self::zipEntries($file);

		if ($entries === null) {
			return null;
		}

		$files = 0;
		$total = 0;
		$packed = 0;
		$rows = '';

		foreach ($entries as $i => $en) {
			if (!$en['dir']) {
				$files++;
				$total += $en['size'];
				$packed += $en['csize'];
			}

			if ($i < self::ZIP_MAX_ENTRIES) {
				$rows .= '<tr><td>' . self::e($en['name']) . '</td><td class="a">' . ($en['dir'] ? '' : self::e(self::size($en['size']))) . '</td></tr>';
			}
		}

		$summary = sprintf('<p class="meta">%d files, %s uncompressed (%s in the archive)%s</p>', $files, self::size($total), self::size($packed),
			count($entries) > self::ZIP_MAX_ENTRIES ? '. Showing the first ' . self::ZIP_MAX_ENTRIES . ' entries.' : '');

		return $summary . '<div class="sheet"><table><tr><th>Name</th><th class="a">Size</th></tr>' . $rows . '</table></div>';
	}

	// ---- ODT / ODP / PPTX -------------------------------------------------------------------

	static protected function paragraphs(string $text): string
	{
		$out = '';

		foreach (preg_split("/\n/", $text) as $line) {
			$line = rtrim($line);

			if ($line === '') {
				continue;
			}

			if (str_starts_with($line, "\x01")) {
				$out .= '<h2>' . self::e(substr($line, 1)) . '</h2>';
			}
			else {
				$out .= '<p>' . self::e($line) . '</p>';
			}
		}

		if (strlen($out) > self::TEXT_MAX * 2) {
			$out = substr($out, 0, self::TEXT_MAX * 2) . '<p class="meta">&hellip; (truncated)</p>';
		}

		return $out === '' ? '<p class="meta">(no text)</p>' : '<div class="doc">' . $out . '</div>';
	}

	static protected function odt(string $file): ?string
	{
		$entries = self::zipEntries($file);
		$c = $entries ? self::zipFind($entries, 'content.xml') : null;
		$x = $c ? self::zipRead($file, $c) : null;

		if ($x === null) {
			return null;
		}

		$x = preg_replace_callback('~<text:s(?:\s+text:c="(\d+)")?\s*/>~', fn($m) => str_repeat(' ', (int) ($m[1] ?? 1)), $x);
		$x = preg_replace('~<text:tab\s*/>~', "\t", $x);
		$x = preg_replace('~<text:line-break\s*/>~', "\n", $x);
		$n = 0;
		$x = preg_replace_callback('~<draw:page\b[^>]*>~', function () use (&$n) { return "\n\x01Slide " . (++$n) . "\n"; }, $x);
		$x = preg_replace('~</(?:text:p|text:h|table:table-row)>~', "\n", $x);
		$x = html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_XML1, 'UTF-8');

		return self::paragraphs($x);
	}

	static protected function pptx(string $file): ?string
	{
		$entries = self::zipEntries($file);

		if ($entries === null) {
			return null;
		}

		$slides = [];

		foreach ($entries as $en) {
			if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $en['name'], $m)) {
				$slides[(int) $m[1]] = $en;
			}
		}

		ksort($slides);
		$text = '';
		$total = 0;

		foreach ($slides as $num => $en) {
			$x = self::zipRead($file, $en, 5 * 1024 * 1024);

			if ($x === null) {
				continue;
			}

			$text .= "\n\x01Slide " . $num . "\n";
			preg_match_all('~<a:p\b[^>]*>(.*?)</a:p>~s', $x, $paras);

			foreach ($paras[1] as $p) {
				$p = preg_replace('~<a:br\s*/>~', '<a:t> </a:t>', $p);
				preg_match_all('~<a:t(?:\s[^>]*)?>(.*?)</a:t>~s', $p, $ts);
				$line = html_entity_decode(implode('', $ts[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');

				if (trim($line) !== '') {
					$text .= $line . "\n";
				}
			}

			$total += strlen($text);

			if ($total > self::TEXT_MAX) {
				break;
			}
		}

		return self::paragraphs($text);
	}

	// ---- RTF --------------------------------------------------------------------------------

	static protected function rtf(string $file): ?string
	{
		$fp = fopen($file, 'rb');
		$rtf = (string) fread($fp, 5 * 1024 * 1024);
		fclose($fp);

		if (!str_starts_with(ltrim($rtf), '{\\rtf')) {
			return null;
		}

		$cp = 'CP1252';

		if (preg_match('/\\\\ansicpg(125\d)/', $rtf, $m)) {
			$cp = 'CP' . $m[1];
		}

		$dest = ['fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'header', 'footer', 'headerl', 'headerr', 'footerl', 'footerr',
			'themedata', 'colorschememapping', 'datastore', 'latentstyles', 'listtable', 'listoverridetable', 'rsidtbl',
			'generator', 'xmlnstbl', 'object', 'fldinst', 'template', 'filetbl', 'revtbl', 'pgptbl', 'private'];

		$len = strlen($rtf);
		$out = '';
		$stack = [];
		$ignore = false;
		$skip = 0;
		$groupStart = false;
		// A byte of the code page always gives the same text: convert each one once, not each time it appears
		$bytes = [];

		for ($i = 0; $i < $len; $i++) {
			// What follows could not be shown anyway (see paragraphs())
			if (strlen($out) > self::TEXT_MAX * 2) {
				break;
			}

			$c = $rtf[$i];

			if ($c === '{') {
				// No real document nests groups that deep, a file made of "{" would only take up memory
				if (count($stack) >= self::RTF_MAX_DEPTH) {
					return null;
				}

				$stack[] = $ignore;
				$groupStart = true;
				continue;
			}

			if ($c === '}') {
				$ignore = array_pop($stack) ?? false;
				$groupStart = false;
				continue;
			}

			if ($c === "\r" || $c === "\n") {
				continue;
			}

			if ($c !== '\\') {
				if ($skip > 0) {
					$skip--;
				}
				elseif (!$ignore) {
					$out .= ord($c) >= 0x80 ? ($bytes[$c] ??= self::legacy($c, $cp)) : $c;
				}

				$groupStart = false;
				continue;
			}

			$n = $rtf[$i + 1] ?? '';

			if ($n === '\\' || $n === '{' || $n === '}') {
				if (!$ignore && $skip === 0) {
					$out .= $n;
				}

				$i++;
				$groupStart = false;
				continue;
			}

			if ($n === '*') {
				$ignore = true;
				$i++;
				continue;
			}

			if ($n === "'") {
				$hex = substr($rtf, $i + 2, 2);
				$i += 3;

				if ($skip > 0) {
					$skip--;
				}
				elseif (!$ignore && ctype_xdigit($hex)) {
					$byte = chr(hexdec($hex));
					$out .= $bytes[$byte] ??= self::legacy($byte, $cp);
				}

				$groupStart = false;
				continue;
			}

			if ($n === '~') { if (!$ignore) { $out .= "\u{a0}"; } $i++; continue; }
			if ($n === '_') { if (!$ignore) { $out .= '-'; } $i++; continue; }
			if ($n === '-' || $n === '|' || $n === ':') { $i++; continue; }

			if (preg_match('/\G\\\\([a-zA-Z]+)(-?\d+)? ?/', $rtf, $m, 0, $i)) {
				$i += strlen($m[0]) - 1;
				$word = $m[1];
				$param = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;

				if ($groupStart && in_array($word, $dest, true)) {
					$ignore = true;
				}

				$groupStart = false;

				if ($ignore) {
					continue;
				}

				switch ($word) {
					case 'par':
					case 'line':
					case 'sect':
					case 'page':
						$out .= "\n";
						break;
					case 'tab':
						$out .= "\t";
						break;
					case 'emdash':
						$out .= "\u{2014}";
						break;
					case 'endash':
						$out .= "\u{2013}";
						break;
					case 'bullet':
						$out .= "\u{2022}";
						break;
					case 'u':
						if ($param !== null) {
							$cpnt = $param < 0 ? $param + 65536 : $param;
							$out .= mb_chr($cpnt, 'UTF-8') ?: '';
							$skip = 1;
						}
						break;
				}

				continue;
			}

			$i++;
		}

		return self::paragraphs($out);
	}
}
