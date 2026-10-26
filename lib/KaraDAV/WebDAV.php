<?php

namespace KaraDAV;

use KD2\WebDAV\Server as WebDAV_Server;

class WebDAV extends WebDAV_Server
{
	protected function html_directory(string $uri, iterable $list): ?string
	{
		$out = parent::html_directory($uri, $list);

		if (null !== $out) {
			$options = [
				'wopi_discovery_url' => WOPI_DISCOVERY_URL,
				'server_url'         => WWW_URL,
				'webdav_url'         => $this->storage->getUserURL(),
				'autosave'           => true,
				'has_trash'          => true,
			];

			$uri = $this->storage->getUserURL() . $uri;
			$js = WWW_URL . (DEV ? 'browser/boot.js' : 'browser.min.js');

			$out = str_replace('</head>', sprintf('<script type="text/javascript" src="%s"></script>
				<script type="text/javascript">window.onload = () => { try { browser.init(%s, %s); } catch (e) { document.body.style.opacity = \'unset\'; throw e; } };</script>', $js, json_encode($uri), json_encode($options)), $out);
			$out = str_replace('<body>', '<body>
				<style type="text/css">body { opacity: 0; }</style>
				<noscript>
					<style type="text/css">body { opacity: unset; }</style>
					Javascript is required to manage files, you will only be able to browse and download files.
				</noscript>', $out);
		}

		return $out;
	}

	/**
	 * Content types a browser renders as an active document, able to run scripts with the
	 * origin of this server: they must never be displayed inline (a user, or someone who can
	 * write in a folder shared with them, could store a page that attacks the people opening it).
	 */
	const ACTIVE_CONTENT_TYPES = [
		'text/html', 'application/xhtml+xml', 'image/svg+xml',
		'text/xml', 'application/xml', 'text/xsl', 'application/xslt+xml', 'text/xslt',
		'application/mathml+xml', 'application/rdf+xml', 'application/atom+xml', 'application/rss+xml',
	];

	public function http_head(string $uri, array &$props = []): ?string
	{
		$out = parent::http_head($uri, $props);

		// Never let a browser guess a different type than the one we declare
		header('X-Content-Type-Options: nosniff');

		$type = null;

		foreach (headers_list() as $h) {
			if (0 === stripos($h, 'Content-Type:')) {
				$type = strtolower(trim(explode(';', substr($h, 13))[0]));
			}
		}

		if ($type !== null && in_array($type, self::ACTIVE_CONTENT_TYPES, true)) {
			$name = basename($uri);
			$ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $name);

			// Download instead of display, and if it is displayed anyway, as a unique origin without scripts
			header(sprintf('Content-Disposition: attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name)));
			header('Content-Security-Policy: sandbox; default-src \'none\'');
		}

		return $out;
	}

	public function http_options(): void
	{
		parent::http_options();

		if (ACCESS_CONTROL_ALL) {
			header('Access-Control-Allow-Origin: *');
			header('Access-Control-Allow-Credentials: true');
			header('Access-Control-Allow-Headers: Authorization, *');
			header('Access-Control-Allow-Methods: GET,HEAD,PUT,DELETE,COPY,MOVE,PROPFIND,MKCOL,LOCK,UNLOCK');
		}
	}

	public function log(string $message, ...$params)
	{
		http_log('DAV: ' . $message, ...$params);
	}

	/**
	 * Utility function to create HMAC hash of data, useful for NextCloud and WOPI
	 */
	static public function hmac(array $data, string $key = '')
	{
		$key = SECRET_KEY . sha1($key);
		return parent::hmac($data, $key);
	}
}
