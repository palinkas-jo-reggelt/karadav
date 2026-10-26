<?php

namespace KaraDAV;

/**
 * Public page for share links: /s/<token>
 *
 *   /s/<token>                    landing page (file info, or folder listing; ?p=sub/dir to navigate)
 *   /s/<token>/download           download the shared file, or a file inside a shared folder (?p=sub/dir/file.ext)
 *   /s/<token>/zip                download a shared folder (or a sub-folder, ?p=...) as a ZIP archive
 *
 * No login is required; shares may be protected by a password and/or an expiration date.
 */
class PublicShare
{
	protected object $share;
	protected ?string $root = null;      // real path of the shared file/folder
	protected ?User $owner = null;
	protected bool $is_dir = false;

	const PREVIEW_IMAGES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
		'webp' => 'image/webp', 'bmp' => 'image/bmp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml'];
	const PREVIEW_VIDEO = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/mp4'];
	const PREVIEW_AUDIO = ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
		'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'flac' => 'audio/flac', 'opus' => 'audio/ogg'];
	const PREVIEW_TEXT = ['txt', 'md', 'log', 'json', 'xml', 'yml', 'yaml', 'ini', 'conf', 'sql', 'html', 'htm', 'css', 'js', 'php', 'py', 'sh', 'ps1', 'bat', 'ics', 'vcf'];
	const PREVIEW_EML = ['eml'];
	const PREVIEW_DOCX = ['docx'];
	const PREVIEW_SHEET = ['xlsx', 'xlsm', 'xls', 'ods'];
	const LIGHTBOX_JS = <<<'JS'
(function(){
var links=[].slice.call(document.querySelectorAll('a[data-lb]'));
if(!links.length)return;
var i=0,box=null,img,cap,cnt,dl,touchX=null;
function build(){
 box=document.createElement('div');box.className='lb';box.setAttribute('role','dialog');box.setAttribute('aria-modal','true');
 box.innerHTML='<button class="lb-x" aria-label="Close">&times;</button><button class="lb-p" aria-label="Previous">&#8249;</button><button class="lb-n" aria-label="Next">&#8250;</button><div class="lb-c"><img alt=""></div><div class="lb-b"><span class="lb-cap"></span> <span class="lb-cnt"></span> <a class="lb-dl">Download</a></div>';
 document.body.appendChild(box);
 img=box.querySelector('img');cap=box.querySelector('.lb-cap');cnt=box.querySelector('.lb-cnt');dl=box.querySelector('.lb-dl');
 box.querySelector('.lb-x').onclick=close;box.querySelector('.lb-p').onclick=function(e){e.stopPropagation();go(-1)};box.querySelector('.lb-n').onclick=function(e){e.stopPropagation();go(1)};
 box.onclick=function(e){if(e.target===box||e.target.className==='lb-c')close()};
 box.addEventListener('touchstart',function(e){touchX=e.touches[0].clientX},{passive:true});
 box.addEventListener('touchend',function(e){if(touchX===null)return;var d=e.changedTouches[0].clientX-touchX;touchX=null;if(Math.abs(d)>50)go(d<0?1:-1)});
}
function show(n){
 i=(n+links.length)%links.length;var a=links[i];
 img.removeAttribute('src');img.src=a.getAttribute('data-lb');
 cap.textContent=a.textContent.replace(/^\s*\S+\s/,'');cnt.textContent='('+(i+1)+' / '+links.length+')';dl.href=a.getAttribute('data-dl');
 box.classList.toggle('single',links.length<2);
 [1,-1].forEach(function(d){var p=new Image();p.src=links[(i+d+links.length)%links.length].getAttribute('data-lb')});
}
function go(d){show(i+d)}
function open(n){if(!box)build();box.style.display='flex';document.body.style.overflow='hidden';show(n);box.querySelector('.lb-x').focus()}
function close(){box.style.display='none';document.body.style.overflow='';img.removeAttribute('src')}
links.forEach(function(a,n){a.addEventListener('click',function(e){if(e.ctrlKey||e.metaKey||e.shiftKey||e.button)return;e.preventDefault();open(n)})});
document.addEventListener('keydown',function(e){if(!box||box.style.display!=='flex')return;
 if(e.key==='ArrowLeft'){go(-1);e.preventDefault()}else if(e.key==='ArrowRight'){go(1);e.preventDefault()}else if(e.key==='Escape'){close()}});
})();
JS;
	const PREVIEW_OFFICE_MAX = 20 * 1024 * 1024;
	const PREVIEW_TEXT_MAX = 256 * 1024;

	/**
	 * Kind of preview for a file name: image, video, audio, pdf, text, docx, sheet, or null
	 */
	static public function previewKind(string $name): ?string
	{
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

		return match (true) {
			isset(self::PREVIEW_IMAGES[$ext]) => 'image',
			isset(self::PREVIEW_VIDEO[$ext]) => 'video',
			isset(self::PREVIEW_AUDIO[$ext]) => 'audio',
			$ext === 'pdf' => 'pdf',
			in_array($ext, self::PREVIEW_TEXT, true) => 'text',
			in_array($ext, self::PREVIEW_EML, true) => 'eml',
			FilePreview::kind($name) !== null => 'fp',
			in_array($ext, self::PREVIEW_DOCX, true) => 'docx',
			in_array($ext, self::PREVIEW_SHEET, true) => 'sheet',
			default => null,
		};
	}

	static public function route(string $relative_uri): bool
	{
		if (!preg_match('~^/s/([A-Za-z0-9]{6,64})(?:/(download|zip|view))?/?$~', $relative_uri, $m)) {
			return false;
		}

		(new self)->handle($m[1], $m[2] ?? 'page');
		return true;
	}

	protected function handle(string $token, string $action): void
	{
		header('X-Content-Type-Options: nosniff');
		header('Referrer-Policy: no-referrer');
		header('X-Robots-Tag: noindex, nofollow');

		$share = Shares::byToken($token);

		if (!$share || Shares::isExpired($share)) {
			$this->error(404, 'This link does not exist or has expired.');
			return;
		}

		$owner = (new Users)->getById((int) $share->user);

		if (!$owner) {
			$this->error(404, 'This link does not exist or has expired.');
			return;
		}

		$root = Shares::resolve($owner->path, $share->path);

		if ($root === null || Shares::isHidden($share->path)) {
			$this->error(404, 'The shared item does not exist any more.');
			return;
		}

		$this->share = $share;
		$this->owner = $owner;
		$this->root = $root;
		$this->is_dir = is_dir($root);

		if (!empty($share->password) && !$this->checkPassword()) {
			return;
		}

		$sub = $this->is_dir ? Shares::normalizePath((string) ($_GET['p'] ?? '')) : '';

		switch ($action) {
			case 'download':
				$this->download($sub);
				break;
			case 'view':
				$this->download($sub, true);
				break;
			case 'zip':
				$this->zip($sub);
				break;
			default:
				$this->page($sub);
		}
	}

	// ---- password -------------------------------------------------------------------------

	protected function cookieName(): string
	{
		return 'ks_' . $this->share->token;
	}

	protected function cookieValue(): string
	{
		return WebDAV::hmac(['share', $this->share->token, $this->share->password]);
	}

	protected function checkPassword(): bool
	{
		$cookie = $_COOKIE[$this->cookieName()] ?? '';

		if ($cookie !== '' && hash_equals($this->cookieValue(), (string) $cookie)) {
			return true;
		}

		$message = null;

		if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
			if (password_verify((string) $_POST['password'], $this->share->password)) {
				setcookie($this->cookieName(), $this->cookieValue(), [
					'expires'  => 0,
					'path'     => '/',
					'secure'   => !empty($_SERVER['HTTPS']),
					'httponly' => true,
					'samesite' => 'Lax',
				]);

				// Post/Redirect/Get
				header('Location: ' . $_SERVER['REQUEST_URI'], true, 303);
				return false;
			}

			usleep(700000); // slow down guessing
			$message = 'Wrong password.';
		}

		$form = '<form method="post"><p>This item is protected by a password.</p>'
			. ($message ? '<p class="error">' . self::e($message) . '</p>' : '')
			. '<p><input type="password" name="password" autofocus required placeholder="Password"> '
			. '<button type="submit">Continue</button></p></form>';

		http_response_code($message ? 403 : 200);
		$this->layout('Password required', $form);
		return false;
	}

	// ---- pages ----------------------------------------------------------------------------

	protected function page(string $sub): void
	{
		$name = basename($this->root);
		$owner = self::e($this->owner->label);

		if (!$this->is_dir) {
			$this->preview($this->root, '', $name);
			return;
		}

		$dir = Shares::resolve($this->root, $sub);

		if ($dir !== null && is_file($dir)) {
			$parent = dirname($sub);
			$this->preview($dir, $sub, basename($dir), $parent === '.' ? '' : $parent);
			return;
		}

		if ($dir === null || !is_dir($dir)) {
			$this->error(404, 'This folder does not exist.');
			return;
		}

		// Breadcrumb
		$crumbs = ['<a href="' . self::e($this->link('')) . '">' . self::e($name) . '</a>'];
		$acc = '';

		foreach (array_filter(explode('/', $sub), 'strlen') as $part) {
			$acc .= ($acc === '' ? '' : '/') . $part;
			$crumbs[] = '<a href="' . self::e($this->link('', $acc)) . '">' . self::e($part) . '</a>';
		}

		$rows = '';
		$items = scandir($dir) ?: [];
		$dirs = $files = [];

		foreach ($items as $item) {
			if ($item === '.' || $item === '..' || $item[0] === '.') {
				continue;
			}

			$full = $dir . '/' . $item;

			// Never follow links out of the shared folder
			if (null === Shares::resolve($this->root, ltrim($sub . '/' . $item, '/'))) {
				continue;
			}

			if (is_dir($full)) {
				$dirs[$item] = $full;
			}
			else {
				$files[$item] = $full;
			}
		}

		foreach ($dirs as $item => $full) {
			$rel = ltrim($sub . '/' . $item, '/');
			$rows .= sprintf('<tr><td class="n"><a href="%s">&#128193; %s</a></td><td class="s"></td><td class="d">%s</td><td class="a"><a href="%s">ZIP</a></td></tr>',
				self::e($this->link('', $rel)), self::e($item), date('Y-m-d H:i', filemtime($full)),
				self::e($this->link('zip', $rel)));
		}

		$has_images = false;
		$total = 0;

		foreach ($files as $item => $full) {
			$rel = ltrim($sub . '/' . $item, '/');
			$is_img = self::previewKind($item) === 'image';
			$total += (int) @filesize($full);
			$has_images = $has_images || $is_img;
			$lb = $is_img ? sprintf(' data-lb="%s" data-dl="%s"', self::e($this->link('view', $rel)), self::e($this->link('download', $rel))) : '';
			$rows .= sprintf('<tr><td class="n"><a href="%s"%s>&#128196; %s</a></td><td class="s">%s</td><td class="d">%s</td><td class="a"><a href="%s">Download</a></td></tr>',
				self::e($this->link(self::previewKind($item) ? '' : 'download', $rel)), $lb, self::e($item), self::size(filesize($full)),
				date('Y-m-d H:i', filemtime($full)), self::e($this->link('download', $rel)));
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="4" class="empty">This folder is empty.</td></tr>';
		}

		$zip = class_exists('ZipArchive')
			? sprintf('<p><a class="btn" href="%s">Download all as ZIP</a></p>', self::e($this->link('zip', $sub)))
			: '';

		$stats = count($files) . (count($files) === 1 ? ' file' : ' files')
			. (count($dirs) ? ', ' . count($dirs) . (count($dirs) === 1 ? ' folder' : ' folders') : '')
			. ' &middot; ' . ($total ? self::size($total) : '0 B');

		$body = sprintf('<h1>%s</h1><p class="meta">Shared by %s &middot; %s</p>%s<table>%s</table>',
			implode(' / ', $crumbs), $owner, $stats, $zip, $rows);
		$csp = $scripts = null;

		if ($has_images) {
			$nonce = base64_encode(random_bytes(16));
			$csp = ["img-src 'self'", "script-src 'nonce-" . $nonce . "'"];
			$scripts = '<script nonce="' . $nonce . '">' . self::LIGHTBOX_JS . '</script>';
		}

		$this->layout($name, $body, $csp ?? [], $scripts ?? '');
	}

	/**
	 * Preview page for a single file (image, PDF, text, audio/video, Word, Excel)
	 */
	protected function preview(string $file, string $sub, string $name, ?string $back = null): void
	{
		$kind = self::previewKind($name);
		$size = filesize($file);
		$view = self::e($this->link('view', $sub));
		$dl = self::e($this->link('download', $sub));
		$csp = [];
		$scripts = '';
		$content = '';

		if ($kind !== null && in_array($kind, ['docx', 'sheet'], true) && $size > self::PREVIEW_OFFICE_MAX) {
			$kind = null;
		}

		switch ($kind) {
			case 'image':
				$content = sprintf('<div class="pv"><img src="%s" alt="%s"></div>', $view, self::e($name));
				$csp[] = "img-src 'self'";
				break;
			case 'pdf':
				$content = sprintf('<iframe class="pv pdf" src="%s" title="%s"></iframe>', $view, self::e($name));
				$csp[] = "frame-src 'self'";
				break;
			case 'video':
				$content = sprintf('<video class="pv" src="%s" controls preload="metadata"></video>', $view);
				$csp[] = "media-src 'self'";
				break;
			case 'audio':
				$content = sprintf('<audio src="%s" controls preload="metadata" style="width:100%%"></audio>', $view);
				$csp[] = "media-src 'self'";
				break;
			case 'text':
				$fp = fopen($file, 'rb');
				$txt = (string) fread($fp, self::PREVIEW_TEXT_MAX);
				fclose($fp);
				$txt = mb_convert_encoding($txt, 'UTF-8', 'UTF-8');
				$pretty = false;

				if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'json' && $size <= self::PREVIEW_TEXT_MAX
					&& null !== ($p = EmlPreview::prettyJSON($txt))) {
					$txt = $p;
					$pretty = true;
				}

				$note = $size > self::PREVIEW_TEXT_MAX ? '<p class="meta">Showing the first ' . self::size(self::PREVIEW_TEXT_MAX) . ' only.</p>' : '';
				$content = $note . '<pre class="pv txt">' . self::e($txt) . '</pre>';
				break;
			case 'fp':
				$fp = FilePreview::render($file, $name);
				$content = '<div class="pv">' . $fp['html'] . '</div>';
				break;
			case 'eml':
				if ($size > EmlPreview::MAX_SIZE) {
					$content = '<p class="meta">This message is too large to preview. Please download it.</p>';
					break;
				}

				$eml = EmlPreview::parse((string) file_get_contents($file));
				$rows = '';

				foreach ($eml['headers'] as [$k, $v]) {
					$rows .= '<tr><th>' . self::e($k) . '</th><td>' . self::e($v) . '</td></tr>';
				}

				$att = '';

				if (count($eml['attachments'])) {
					$att = '<p class="meta">Attachments: ' . implode(', ', array_map(
						fn($a) => self::e($a['name']) . ' (' . self::size($a['size']) . ')', $eml['attachments'])) . '</p>';
				}

				$content = '<table class="emlh">' . $rows . '</table><pre class="pv txt">' . self::e($eml['text']) . '</pre>' . $att;
				break;
			case 'docx':
			case 'sheet':
				$nonce = base64_encode(random_bytes(16));
				$lib = $kind === 'docx' ? 'mammoth.browser.min.js' : 'xlsx.full.min.js';
				$csp[] = "script-src 'self' 'nonce-" . $nonce . "'";
				$csp[] = "connect-src 'self'";
				$csp[] = "img-src data:";
				$content = '<div id="doc" class="pv doc">Loading preview&hellip;</div>';
				$js = $kind === 'docx'
					? "mammoth.convertToHtml({arrayBuffer:buf}).then(function(r){doc.innerHTML=r.value||'(empty document)'})"
					: "var wb=XLSX.read(buf,{type:'array'});var h='';wb.SheetNames.forEach(function(n){h+='<h2>'+n.replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c]})+'</h2><div class=\"sheet\">'+XLSX.utils.sheet_to_html(wb.Sheets[n],{header:'',footer:''})+'</div>'});doc.innerHTML=h;var m=document.querySelector(\"main\");var ov=false;Array.prototype.forEach.call(doc.querySelectorAll(\".sheet\"),function(e){if(e.scrollWidth>e.clientWidth+1)ov=true});if(ov)m.className+=\" wide\";";
				$scripts = sprintf('<script src="%spreview/%s"></script><script nonce="%s">'
					. 'var doc=document.getElementById("doc");fetch(%s,{credentials:"same-origin"}).then(function(r){if(!r.ok)throw 0;return r.arrayBuffer()})'
					. '.then(function(buf){%s}).catch(function(){doc.textContent="This file could not be previewed. Please download it instead."});</script>',
					WWW_URL, $lib, $nonce, json_encode($this->link('download', $sub)), $js);
				break;
		}

		$nav = '';

		if ($back !== null) {
			$nav = sprintf('<p><a href="%s">&larr; Back to folder</a></p>', self::e($this->link('', $back)));
		}

		$note = in_array($kind, ['docx', 'sheet'], true) ? '<span class="pill">Text Preview of Document</span>' : '';

		if ($kind === 'eml') {
			$note = '<span class="pill">Email Preview</span>';
		}
		elseif ($kind === 'fp' && !empty($fp['pill'])) {
			$note = '<span class="pill">' . self::e($fp['pill']) . '</span>';
		}
		elseif (!empty($pretty)) {
			$note = '<span class="pill">Formatted JSON</span>';
		}

		$body = sprintf('%s<h1>%s</h1><p class="meta metarow"><span>%s &middot; shared by %s</span>%s</p><p><a class="btn" href="%s">Download</a></p>%s',
			$nav, self::e($name), self::size($size), self::e($this->owner->label), $note, $dl, $content);

		if ($kind === 'video') {
			$prev = $next = null;

			if ($back !== null) {
				$vids = [];

				foreach (scandir(dirname($file)) ?: [] as $item) {
					if ($item[0] !== '.' && is_file(dirname($file) . '/' . $item) && self::previewKind($item) === 'video') {
						$vids[] = $item;
					}
				}

				$n = count($vids);
				$i = array_search($name, $vids, true);

				if ($n > 1 && $i !== false) {
					$prev = $this->link('', ltrim($back . '/' . $vids[($i + $n - 1) % $n], '/'));
					$next = $this->link('', ltrim($back . '/' . $vids[($i + 1) % $n], '/'));
				}
			}

			$nonce = base64_encode(random_bytes(16));
			$csp[] = "script-src 'nonce-" . $nonce . "'";
			$bar = sprintf('<div class="vbar"><span class="vl">%s</span><span class="vn">%s</span><span class="vr"><a class="vb" href="%s">Download</a></span></div>',
				$back !== null ? sprintf('<a class="vb" href="%s">&#10006; Close</a>', self::e($this->link('', $back))) : '',
				self::e($name), $dl);
			$navs = $prev ? sprintf('<a class="vnav vp" rel="prev" href="%s" aria-label="Previous video">&#8249;</a><a class="vnav vx" rel="next" href="%s" aria-label="Next video">&#8250;</a>', self::e($prev), self::e($next)) : '';
			$scripts = '<script nonce="' . $nonce . '">(function(){'
				. 'document.addEventListener("keydown",function(e){var l=document.querySelector("a[rel=prev]"),r=document.querySelector("a[rel=next]");'
				. 'if(e.key==="ArrowLeft"&&l&&e.target.tagName!=="VIDEO"){location.href=l.href}else if(e.key==="ArrowRight"&&r&&e.target.tagName!=="VIDEO"){location.href=r.href}});})();</script>';
			$body = $bar . '<p class="meta">' . self::size($size) . ' &middot; shared by ' . self::e($this->owner->label) . '</p>' . $content . $navs;
		}

		$this->layout($name, $body, $csp, $scripts, $kind === 'pdf');
	}

	protected function link(string $action, string $sub = ''): string
	{
		$url = WWW_URL . 's/' . $this->share->token . ($action ? '/' . $action : '');
		return $url . ($sub !== '' ? '?p=' . rawurlencode($sub) : '');
	}

	// ---- downloads ------------------------------------------------------------------------

	protected function download(string $sub, bool $inline = false): void
	{
		$file = $this->root;

		if ($this->is_dir) {
			$file = Shares::resolve($this->root, $sub);
		}

		if ($file === null || !is_file($file) || !is_readable($file)) {
			$this->error(404, 'File not found.');
			return;
		}

		$mime = null;

		if ($inline) {
			$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
			$mime = (self::PREVIEW_IMAGES + self::PREVIEW_VIDEO + self::PREVIEW_AUDIO + ['pdf' => 'application/pdf'])[$ext] ?? null;

			if ($mime === null) {
				$this->error(404, 'No preview available for this file.');
				return;
			}

			// An SVG opened directly must never run scripts
			if ($ext === 'svg') {
				header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
			}
		}

		$this->sendFile($file, basename($file), $mime);
	}

	protected function sendFile(string $file, string $name, ?string $inline_mime = null): void
	{
		$size = filesize($file);
		$start = 0;
		$end = $size - 1;

		// Single byte-range support (resumable downloads)
		if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m) && ($m[1] !== '' || $m[2] !== '')) {
			if ($m[1] === '') {
				$start = max(0, $size - (int) $m[2]);
			}
			else {
				$start = (int) $m[1];
				$end = $m[2] !== '' ? min((int) $m[2], $end) : $end;
			}

			if ($start > $end || $start >= $size) {
				http_response_code(416);
				header('Content-Range: bytes */' . $size);
				return;
			}

			http_response_code(206);
			header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
		}

		$ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $name);

		if ($inline_mime) {
			header('Content-Type: ' . $inline_mime);
			header(sprintf('Content-Disposition: inline; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name)));
		}
		else {
			header('Content-Type: application/octet-stream');
			header(sprintf('Content-Disposition: attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name)));
		}

		header('Accept-Ranges: bytes');
		header('Content-Length: ' . ($end - $start + 1));
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT');

		if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {
			return;
		}

		@set_time_limit(0);

		while (ob_get_level()) {
			ob_end_clean();
		}

		$fp = fopen($file, 'rb');
		fseek($fp, $start);
		$left = $end - $start + 1;

		while ($left > 0 && !feof($fp) && !connection_aborted()) {
			$chunk = fread($fp, min(1024 * 1024, $left));

			if ($chunk === false || $chunk === '') {
				break;
			}

			echo $chunk;
			$left -= strlen($chunk);
			flush();
		}

		fclose($fp);
	}

	protected function zip(string $sub): void
	{
		if (!class_exists('ZipArchive')) {
			$this->error(501, 'ZIP downloads are not available on this server (PHP zip extension missing).');
			return;
		}

		$base = $this->is_dir ? Shares::resolve($this->root, $sub) : null;

		if ($base === null || !is_dir($base)) {
			$this->error(404, 'Folder not found.');
			return;
		}

		@set_time_limit(0);
		ignore_user_abort(false);

		$tmp = tempnam(sys_get_temp_dir(), 'kdzip');
		$zip = new \ZipArchive;

		if (true !== $zip->open($tmp, \ZipArchive::OVERWRITE)) {
			@unlink($tmp);
			$this->error(500, 'Could not create the archive.');
			return;
		}

		$prefix = strlen($base) + 1;
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($it as $item) {
			$path = $item->getPathname();
			$local = str_replace('\\', '/', substr($path, $prefix));

			// skip hidden files and anything resolving outside the shared folder
			if (preg_match('~(^|/)\.~', $local)) {
				continue;
			}

			$rel = ltrim(($sub !== '' ? $sub . '/' : '') . $local, '/');

			if (null === Shares::resolve($this->root, $rel)) {
				continue;
			}

			if ($item->isDir()) {
				$zip->addEmptyDir($local);
			}
			elseif ($item->isFile() && $item->isReadable()) {
				$zip->addFile($path, $local);
			}
		}

		$zip->close();

		$name = ($sub !== '' ? basename($sub) : basename($this->root)) . '.zip';
		$this->sendFile($tmp, $name);
		@unlink($tmp);
	}

	// ---- output ---------------------------------------------------------------------------

	static protected function e(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	static protected function size(int|float $bytes): string
	{
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$i = 0;

		while ($bytes >= 1024 && $i < count($units) - 1) {
			$bytes /= 1024;
			$i++;
		}

		return ($i === 0 ? (string) (int) $bytes : number_format($bytes, 1)) . ' ' . $units[$i];
	}

	protected function error(int $code, string $message): void
	{
		http_response_code($code);
		$this->layout('Not available', '<p>' . self::e($message) . '</p>');
	}

	protected function bannerLogo(): string
	{
		$logo = trim((string) SHARE_LOGO_URL);

		if ($logo === '') {
			return '';
		}

		return preg_match('~^(https?:)?//~i', $logo) ? $logo : WWW_URL . ltrim($logo, '/');
	}

	protected function layout(string $title, string $body, array $csp = [], string $scripts = '', bool $wide = false): void
	{
		header('Content-Type: text/html; charset=utf-8');

		$logo = $this->bannerLogo();
		$name = trim((string) SHARE_SITE_NAME);

		// Build the CSP, merging directives (a repeated directive would be ignored by browsers)
		$directives = ['default-src' => ["'none'"], 'style-src' => ["'unsafe-inline'"], 'form-action' => ["'self'"], 'base-uri' => ["'none'"]];

		if ($logo !== '') {
			$csp[] = "img-src 'self'";

			if (preg_match('~^(?:https?:)?//[^/]+~i', $logo, $m)) {
				$csp[] = 'img-src ' . $m[0];
			}
		}

		foreach ($csp as $line) {
			[$k, $v] = explode(' ', $line, 2) + [1 => ''];
			$directives[$k] = array_values(array_unique(array_merge($directives[$k] ?? [], [$v])));
		}

		header('Content-Security-Policy: ' . implode('; ', array_map(fn($k, $v) => $k . ' ' . implode(' ', $v), array_keys($directives), $directives)));

		$banner = '';

		if ($name !== '' || $logo !== '') {
			$banner = '<header class="banner">'
				. ($logo !== '' ? '<img src="' . self::e($logo) . '" alt="">' : '')
				. ($name !== '' ? '<span>' . self::e($name) . '</span>' : '')
				. '</header>';
		}

		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . self::e($title) . '</title><style>'
			. ':root{color-scheme:light dark;--bg:#fff;--fg:#111;--muted:#6b6b6b;--line:#e0e0e0;--soft:#f6f6f6;--danger:#b00020}'
			. '@media(prefers-color-scheme:dark){:root{--bg:#0b0b0b;--fg:#f0f0f0;--muted:#9a9a9a;--line:#2c2c2c;--soft:#151515;--danger:#ff7b7b}}'
			. 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);margin:0;color:var(--fg)}'
			. '.banner{box-sizing:border-box;height:30px;display:flex;align-items:center;justify-content:center;gap:.5rem;padding:0 1rem;background:var(--bg);border-bottom:1px solid var(--line);font-size:.9rem;font-weight:600;overflow:hidden}'
			. '.banner img{max-height:24px;max-width:120px;width:auto;height:auto;display:block}'
			. 'main{max-width:860px;margin:0 auto;background:var(--bg);padding:.5rem 1.2rem 1rem}'
			. 'main p{margin:.4rem 0}h1{font-size:1.3rem;margin:.1rem 0 .4rem;word-break:break-word}.meta{color:var(--muted);margin-top:0}'
			. 'a{color:var(--fg);text-underline-offset:.15em;text-decoration-color:var(--muted)}.btn{display:inline-block;background:var(--fg);color:var(--bg);padding:.5rem 1rem;border-radius:6px;text-decoration:none}.btn:hover{opacity:.8}'
			. 'a:focus-visible,.btn:focus-visible,input:focus-visible,button:focus-visible{outline:2px solid var(--fg);outline-offset:2px}'
			. 'table{width:100%;border-collapse:collapse;margin-top:1rem}td{padding:.45rem .3rem;border-bottom:1px solid var(--line)}'
			. '.s,.d{color:var(--muted);white-space:nowrap;font-size:.9rem}.a{text-align:right;white-space:nowrap}.n{word-break:break-word}'
			. '.empty{color:var(--muted);text-align:center}.error{color:var(--danger)}'
			. 'input{padding:.45rem;background:var(--bg);color:var(--fg);border:1px solid var(--muted);border-radius:6px}button{padding:.45rem 1rem;background:var(--fg);color:var(--bg);border:0;border-radius:6px;cursor:pointer}'
			. '.emlh{width:auto;margin:.6rem 0 0}.emlh th{text-align:left;color:var(--muted);font-weight:500;padding:.15rem .9rem .15rem 0;vertical-align:top;white-space:nowrap}.emlh td{border:0;padding:.15rem 0;word-break:break-word}'
			. '.metarow{display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap}'
			. '.pill{background:var(--fg);color:var(--bg);font-size:.8rem;padding:.2rem .7rem;border-radius:999px;white-space:nowrap}'
			. 'main.wide{max-width:none;margin:0;padding:.5rem 1rem 1rem}'
			. '.pv{display:block;max-width:100%;margin-top:1rem}img.pv{max-height:80vh;margin-left:auto;margin-right:auto}video.pv{width:100%}'
			. 'iframe.pdf{width:100%;height:85vh;border:1px solid var(--line);background:#fff}pre.txt{white-space:pre-wrap;word-break:break-word;background:var(--soft);padding:1rem;border-radius:4px;font-size:.85rem;max-height:80vh;overflow:auto}'
			. '.pv table{width:auto}.pv td,.pv th{border:1px solid var(--line);padding:.25rem .5rem;text-align:left}.pv th{background:var(--soft)}.pv td.a,.pv th.a{text-align:right}.pv .doc{max-height:none}'
			. '.doc{overflow:auto;line-height:1.5}.doc table{width:auto}.doc td,.doc th{border:1px solid var(--line);padding:.25rem .5rem}.doc img{max-width:100%}.sheet{overflow:auto;max-height:70vh}.doc h2{font-size:1rem;margin:1.2rem 0 .3rem}'
			. '.lb{display:none;position:fixed;inset:0;background:#000d;z-index:9;align-items:center;justify-content:center;flex-direction:column}'
			. '.lb-c{flex:1;display:flex;align-items:center;justify-content:center;width:100%;min-height:0;padding:3rem 4rem 0}.lb-c img{max-width:100%;max-height:100%;object-fit:contain;box-shadow:0 0 20px #000}'
			. '.lb button{position:absolute;background:#fff2;color:#fff;border:0;cursor:pointer;border-radius:50%;width:3rem;height:3rem;font-size:2rem;line-height:1;padding:0}.lb button:hover{background:#fff4}'
			. '.lb-x{top:.7rem;right:.7rem}.lb-p{left:.7rem;top:50%}.lb-n{right:.7rem;top:50%}.lb.single .lb-p,.lb.single .lb-n{display:none}'
			. '.lb-b{color:#eee;padding:.7rem;text-align:center;font-size:.9rem}.lb-b a{color:#cde;margin-left:.6rem;cursor:pointer}'
			. '.vbar{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:.5rem;border:1px solid var(--line);border-radius:6px;padding:.3rem .5rem;margin:.3rem 0}'
			. '.vn{text-align:center;font-weight:600;word-break:break-word;min-width:0}.vr{text-align:right;white-space:nowrap}.vb{display:inline-block;background:var(--fg);color:var(--bg);padding:.35rem .8rem;border-radius:6px;text-decoration:none;font:inherit;font-size:.9rem;border:0;cursor:pointer}'
			. '.vnav{position:fixed;top:50%;z-index:5;width:2.6rem;height:2.6rem;border-radius:50%;background:#0009;color:#fff;font-size:1.8rem;line-height:2.5rem;text-align:center;text-decoration:none;transform:translateY(-50%)}.vp{left:.5rem}.vx{right:.5rem}'
			. '@media(max-width:600px){.vbar{grid-template-columns:auto 1fr;}.vr{grid-column:1/3}.vn{text-align:right}}'
			. '@media(max-width:600px){main{margin:0;border:0;border-radius:0;padding:1rem}.d{display:none}}'
			. '</style></head><body>' . $banner . '<main' . ($wide ? ' class="wide"' : '') . '>' . $body . '</main>' . $scripts . '</body></html>';
	}
}
