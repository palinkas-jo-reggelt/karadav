<?php

namespace KaraDAV;

/**
 * Minimal, safe e-mail (.eml) reader for previews: headers, readable body text, attachment list.
 * HTML bodies are reduced to plain text, nothing is ever rendered as HTML.
 */
class EmlPreview
{
	const MAX_SIZE = 8 * 1024 * 1024;
	const SHOW = ['From', 'To', 'Cc', 'Bcc', 'Reply-To', 'Date', 'Subject'];

	/**
	 * @return array{headers: array, text: string, attachments: array}
	 */
	static public function parse(string $raw): array
	{
		$msg = self::parsePart($raw);
		$headers = [];

		foreach (self::SHOW as $name) {
			$v = $msg['headers'][strtolower($name)] ?? null;

			if ($v !== null && trim($v) !== '') {
				$headers[] = [$name, trim(self::decodeHeader($v))];
			}
		}

		$plain = [];
		$html = [];
		$attachments = [];
		self::walk($msg, $plain, $html, $attachments, 0);

		if (count($plain)) {
			$text = trim(implode("\n\n", $plain));
		}
		else {
			$text = trim(self::htmlToText(implode("\n\n", $html)));
		}

		return ['headers' => $headers, 'text' => $text, 'attachments' => $attachments];
	}

	static protected function splitHeadBody(string $raw): array
	{
		if (preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
			return [substr($raw, 0, $m[0][1]), substr($raw, $m[0][1] + strlen($m[0][0]))];
		}

		return [$raw, ''];
	}

	static protected function parsePart(string $raw): array
	{
		[$head, $body] = self::splitHeadBody($raw);
		$head = preg_replace('/\r?\n[ \t]+/', ' ', $head);
		$headers = [];

		foreach (preg_split('/\r?\n/', $head) as $line) {
			if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
				$k = strtolower($m[1]);
				$headers[$k] ??= $m[2];
			}
		}

		return ['headers' => $headers, 'body' => $body];
	}

	static protected function param(string $header, string $name): ?string
	{
		// RFC 2231: filename*=utf-8''%C3%A1.txt
		if (preg_match('/;\s*' . preg_quote($name, '/') . '\*=([^\']*)\'[^\']*\'([^;\s]+)/i', $header, $m)) {
			return self::toUtf8(rawurldecode($m[2]), $m[1]);
		}

		if (preg_match('/;\s*' . preg_quote($name, '/') . '=(?:"([^"]*)"|([^;\s]+))/i', $header, $m)) {
			$v = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
			return self::decodeHeader($v);
		}

		return null;
	}

	static protected function toUtf8(string $s, ?string $charset): string
	{
		$charset = strtolower(trim((string) $charset, " \"'"));

		if ($charset === '' || $charset === 'utf-8' || $charset === 'utf8' || $charset === 'us-ascii') {
			return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
		}

		try {
			return mb_convert_encoding($s, 'UTF-8', $charset);
		}
		catch (\Throwable $e) {
			return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
		}
	}

	static protected function decodeHeader(string $v): string
	{
		// RFC 2047: white space between two adjacent encoded words is not part of the text
		$v = preg_replace('/(\?=)\s+(?==\?)/', '$1', $v);
		$v = @mb_decode_mimeheader($v);
		return mb_convert_encoding($v, 'UTF-8', 'UTF-8');
	}

	static protected function decodeBody(array $part): string
	{
		$enc = strtolower(trim($part['headers']['content-transfer-encoding'] ?? ''));
		$body = $part['body'];

		if ($enc === 'base64') {
			return (string) base64_decode($body);
		}

		if ($enc === 'quoted-printable') {
			return quoted_printable_decode($body);
		}

		return $body;
	}

	static protected function walk(array $part, array &$plain, array &$html, array &$attachments, int $depth): void
	{
		if ($depth > 6 || count($attachments) > 100) {
			return;
		}

		$ct = $part['headers']['content-type'] ?? 'text/plain';
		$type = strtolower(trim(explode(';', $ct)[0]));
		$disp = $part['headers']['content-disposition'] ?? '';
		$filename = self::param($disp, 'filename') ?? self::param($ct, 'name');

		if (str_starts_with($type, 'multipart/')) {
			$boundary = self::param($ct, 'boundary');

			if (!$boundary) {
				return;
			}

			$pieces = preg_split('/\r?\n?--' . preg_quote($boundary, '/') . '(?:--)?[ \t]*\r?\n?/', $part['body']);
			array_shift($pieces); // preamble

			foreach (array_slice($pieces, 0, 200) as $piece) {
				if (trim($piece) === '') {
					continue;
				}

				self::walk(self::parsePart($piece), $plain, $html, $attachments, $depth + 1);
			}

			return;
		}

		$is_attachment = stripos($disp, 'attachment') === 0 || ($filename !== null && !in_array($type, ['text/plain', 'text/html'], true))
			|| $type === 'message/rfc822' || !str_starts_with($type, 'text/');

		if ($is_attachment) {
			$data = self::decodeBody($part);
			$attachments[] = [
				'name' => $filename ?? ($type === 'message/rfc822' ? 'message.eml' : 'attachment'),
				'type' => $type,
				'size' => strlen($data),
			];
			return;
		}

		$text = self::toUtf8(self::decodeBody($part), self::param($ct, 'charset'));

		if ($type === 'text/html') {
			$html[] = $text;
		}
		elseif ($type === 'text/plain') {
			$plain[] = str_replace("\r\n", "\n", $text);
		}
	}

	static protected function htmlToText(string $html): string
	{
		$html = preg_replace('!<(script|style|head)\b[^>]*>.*?</\1>!is', '', $html);
		$html = preg_replace('!<br\s*/?>|</(p|div|tr|li|h[1-6])>!i', "\n", $html);
		$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace("/[ \t]+/", ' ', $text);
		return preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text));
	}

	/**
	 * Re-indents valid JSON without touching the values (big numbers and string escapes stay as they are).
	 */
	static public function prettyJSON(string $json): ?string
	{
		json_decode($json);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return null;
		}

		$out = '';
		$indent = 0;
		$in_str = false;
		$esc = false;
		$len = strlen($json);

		for ($i = 0; $i < $len; $i++) {
			$c = $json[$i];

			if ($in_str) {
				$out .= $c;

				if ($esc) {
					$esc = false;
				}
				elseif ($c === '\\') {
					$esc = true;
				}
				elseif ($c === '"') {
					$in_str = false;
				}

				continue;
			}

			if ($c === '"') {
				$in_str = true;
				$out .= $c;
			}
			elseif ($c === '{' || $c === '[') {
				$j = $i + 1;

				while ($j < $len && ctype_space($json[$j])) {
					$j++;
				}

				if ($j < $len && $json[$j] === ($c === '{' ? '}' : ']')) {
					$out .= $c . $json[$j];
					$i = $j;
				}
				else {
					$indent++;
					$out .= $c . "\n" . str_repeat('  ', $indent);
				}
			}
			elseif ($c === '}' || $c === ']') {
				$indent--;
				$out .= "\n" . str_repeat('  ', max(0, $indent)) . $c;
			}
			elseif ($c === ',') {
				$out .= ",\n" . str_repeat('  ', $indent);
			}
			elseif ($c === ':') {
				$out .= ': ';
			}
			elseif (!ctype_space($c)) {
				$out .= $c;
			}
		}

		return $out;
	}
}
