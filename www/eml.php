<?php

namespace KaraDAV;

require_once __DIR__ . '/_inc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo '{}';
	exit;
}

$raw = (string) file_get_contents('php://input', false, null, 0, EmlPreview::MAX_SIZE + 1);

if (strlen($raw) > EmlPreview::MAX_SIZE) {
	http_response_code(413);
	echo '{}';
	exit;
}

echo json_encode(EmlPreview::parse($raw), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
