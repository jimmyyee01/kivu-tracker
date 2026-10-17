<?php
/* =====================================================================
   KIVU Tracker — tools/webhook-sink.php
   Minimal webhook receiver used by tools/api-test.php: logs every request
   body to data/webhook-sink.log and answers 200 {"ok":true,...}.

       php -S 127.0.0.1:8098 tools/webhook-sink.php

   It exists because notification webhooks must not point back at the same
   single-threaded `php -S` instance that is handling the API request (that
   would deadlock until the cURL timeout).
   ===================================================================== */
declare(strict_types=1);

$raw = (string)file_get_contents('php://input');
$line = date('c') . ' | ' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REQUEST_URI'] ?? '/')
    . ' | ' . ($_SERVER['CONTENT_TYPE'] ?? '') . ' | ' . $raw . PHP_EOL;
@file_put_contents(dirname(__DIR__) . '/data/webhook-sink.log', $line, FILE_APPEND);

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'received_bytes' => strlen($raw)], JSON_UNESCAPED_SLASHES);
