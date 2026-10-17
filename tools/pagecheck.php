<?php
/* =====================================================================
   KIVU Tracker — tools/pagecheck.php
   Static cross-check between the HTML pages and the JavaScript they load:
     * every /js/... script referenced by a page exists,
     * every element id the script asks for exists in the HTML or is
       created dynamically by the same script,
     * every id used by getElementById()/$('#id') is checked too.
   Catches the "works until you click it" typos without a browser.
   ===================================================================== */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__) . '/public/';
$pages = ['index.html', 'login.html', 'app.html', 'admin.html', 'reports.html', 'super.html', 'track.html'];
$problems = 0;

foreach ($pages as $page) {
    $html = (string)@file_get_contents($root . $page);
    if ($html === '') { echo "MISSING $page\n"; $problems++; continue; }
    $ids = [];
    if (preg_match_all('/\sid="([^"]+)"/', $html, $m)) $ids = $m[1];
    $scripts = [];
    if (preg_match_all('#<script src="([^"]+)"#', $html, $m)) $scripts = $m[1];

    $jsIds = $ids;                  // ids already present in the markup
    $jsCode = '';
    foreach ($scripts as $src) {
        if (strpos($src, 'http') === 0) continue;      // CDN (Leaflet)
        $file = $root . ltrim($src, '/');
        if (!is_file($file)) { echo "BROKEN $page -> $src (file not found)\n"; $problems++; continue; }
        $jsCode .= "\n" . file_get_contents($file);
    }
    /* ids the scripts create themselves */
    if (preg_match_all('/id:\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/', $jsCode, $m)) $jsIds = array_merge($jsIds, $m[1]);
    if (preg_match_all('/id=\\\\?[\'"]([A-Za-z0-9_\-]+)\\\\?[\'"]/', $jsCode, $m)) $jsIds = array_merge($jsIds, $m[1]);
    /* inline script on the page counts as page code as well */
    if (preg_match_all('/id:\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/', $html, $m)) $jsIds = array_merge($jsIds, $m[1]);

    $wanted = [];
    if (preg_match_all('/\$\$?\(\s*[\'"]#([A-Za-z0-9_\-]+)[\'"]/', $jsCode . $html, $m)) $wanted = array_merge($wanted, $m[1]);
    if (preg_match_all('/getElementById\(\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/', $jsCode . $html, $m)) $wanted = array_merge($wanted, $m[1]);

    $missing = array_values(array_unique(array_diff($wanted, $jsIds)));
    /* '#topbar' is optional by design — common.js inserts the bar at the top
       of <body> when a page has no placeholder element for it. */
    $missing = array_values(array_diff($missing, ['topbar']));
    $unused = array_values(array_unique(array_diff($ids, $wanted)));
    if ($missing) {
        $problems++;
        echo "FAIL  $page — ids referenced but never defined: " . implode(', ', $missing) . "\n";
    } else {
        printf("OK    %-13s %2d ids, %2d scripts, %2d selectors\n", $page, count($ids),
            count(array_filter($scripts, fn($s) => strpos($s, 'http') !== 0)), count(array_unique($wanted)));
    }
}
echo $problems === 0 ? "\nAll pages and scripts line up.\n" : "\n$problems problem group(s).\n";
exit($problems === 0 ? 0 : 1);
