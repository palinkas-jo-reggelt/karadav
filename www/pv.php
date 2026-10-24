<?php

namespace KaraDAV;

require_once __DIR__ . '/_inc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$file = FilePreview::resolve($logged_user, (string) ($_GET['p'] ?? ''));

if ($file === null || FilePreview::kind($file) === null) {
	http_response_code(404);
	echo '{}';
	exit;
}

echo json_encode(FilePreview::render($file, $file), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
