<?php
/* =====================================================================
   KIVU Tracker — php/cms-manager.php
   Serves the content-manager shell (public/cms.html) — but never to the
   mobile app.

   Three layers keep the CMS out of the app:
     1. common.js hides the "Website" link (and cms.js stops immediately)
     2. this file refuses the app's user agent, so no CMS markup at all
        reaches the Android client, even if the URL is typed by hand
     3. every /api/cms/* route refuses the app as well (cms_deny_app())

   Public CMS pages (/p/<slug>, /) are ordinary website content and stay
   reachable from anywhere, the app included.
   ===================================================================== */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/routes_cms.php';

header('Cache-Control: no-store');

if (cms_is_mobile_app()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Not available in the app</title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="landing" style="max-width:560px">
  <h1>Website content</h1>
  <p class="small">The content manager is a desktop console. It is not part of the mobile app —
    open it in a browser on a computer to edit the website.</p>
  <p><a class="btn primary" href="/app">Open the monitoring console</a></p>
</div>
</body>
</html><?php
    exit;
}

$file = dirname(__DIR__) . '/public/cms.html';
if (!is_file($file)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "public/cms.html is missing";
    exit;
}
header('Content-Type: text/html; charset=utf-8');
readfile($file);
