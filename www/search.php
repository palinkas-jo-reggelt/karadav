<?php

namespace KaraDAV;

require_once __DIR__ . '/_inc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(Search::run($logged_user, (string) ($_GET['q'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
