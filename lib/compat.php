<?php
// Compatibility helpers for PHP running as CGI/FastCGI (eg. Apache + mod_fcgid on Windows),
// where apache_request_headers() may not exist.

if (!function_exists('apache_request_headers')) {
	function apache_request_headers(): array
	{
		$headers = [];

		foreach ($_SERVER as $key => $value) {
			if (0 === strpos($key, 'HTTP_')) {
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
				$headers[$name] = $value;
			}
			elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) && $value !== '') {
				$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))))] = $value;
			}
		}

		return $headers;
	}
}

if (!function_exists('getallheaders')) {
	function getallheaders(): array
	{
		return apache_request_headers();
	}
}

// The gettext extension is not always enabled (eg. on Windows): fall back to untranslated strings.
if (!function_exists('_')) {
	function _(string $message): string
	{
		return $message;
	}
}
