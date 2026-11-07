<?php
/* =====================================================================
   KIVU Tracker — php/cms.php
   Public renderer for the content manager. Serves

       /p/<slug>   a published CMS page
       /           the page flagged "front page"; until one is published the
                   sign-in page at /login is the entry point, so the console
                   opens first. The marketing site stays reachable at /welcome
                   (public/index.html). A broken or missing database can never
                   hide the way in, because the redirect needs no content.

   Everything is rendered server-side: no JavaScript is required, so the
   pages work in any browser, in search engines and even with the console
   offline. The manager itself is /cms (super admin only) and is refused
   for the mobile app — public pages stay public everywhere.
   ===================================================================== */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/routes_cms.php';       /* helpers + block kinds */

function cms_esc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* Until a front page is published in the content manager, "/" sends the
   visitor to the sign-in page: the console is the product, and /login already
   skips the form when a valid session is present. No database is touched here,
   so the way in keeps working even if the content store is down. */
function cms_go_login(): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Location: /login', true, 302);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<title>' . cms_esc(APP_NAME) . '</title>'
        . '<meta http-equiv="refresh" content="0;url=/login"></head><body>'
        . '<p><a href="/login">Sign in to ' . cms_esc(APP_NAME) . '</a></p>'
        . '</body></html>';
}

/* ------------------------------------------------------- block -> HTML -- */
function cms_block_html(array $b, bool $first): string {
    $kind = cms_block_kind($b['kind'] ?? 'text');
    $title = (string)($b['title'] ?? '');
    $body = (string)($b['body'] ?? '');
    $url = (string)($b['url'] ?? '');
    switch ($kind) {
        case 'heading':
            $tag = $first ? 'h1' : 'h2';
            $out = '<' . $tag . '>' . cms_esc($title !== '' ? $title : $body) . '</' . $tag . '>';
            if ($title !== '' && $body !== '') $out .= '<p>' . nl2br(cms_esc($body)) . '</p>';
            return $out;
        case 'text':
            return '<p>' . nl2br(cms_esc($body)) . '</p>';
        case 'feature':
            return '<div class="feature"><h3>' . cms_esc($title) . '</h3>'
                . ($body === '' ? '' : '<p class="small">' . nl2br(cms_esc($body)) . '</p>') . '</div>';
        case 'button':
            $label = $title !== '' ? $title : $body;
            return '<a class="btn primary" href="' . cms_esc($url) . '">' . cms_esc($label) . '</a>';
        case 'list':
            $items = preg_split('/\r?\n/', $body) ?: [];
            $out = '<ul>';
            foreach ($items as $it) {
                if (trim($it) === '') continue;
                $out .= '<li>' . cms_esc(trim($it)) . '</li>';
            }
            return $out . '</ul>';
        case 'image':
            return '<img src="' . cms_esc($url) . '" alt="' . cms_esc($title) . '"'
                . ' style="max-width:100%;height:auto;border-radius:10px">';
        case 'quote':
            return '<blockquote style="border-left:3px solid var(--border);padding-left:12px;margin:12px 0">'
                . nl2br(cms_esc($body))
                . ($title === '' ? '' : '<footer class="hint">— ' . cms_esc($title) . '</footer>')
                . '</blockquote>';
        case 'code':
            return '<pre class="mono small" style="overflow:auto;background:#0e1421;padding:12px;'
                . 'border-radius:8px">' . cms_esc($body) . '</pre>';
        case 'divider':
            return '<hr style="border:0;border-top:1px solid var(--border);margin:18px 0">';
        case 'html':
            return $body;                        /* trusted: super admin only */
    }
    return '';
}

/* Consecutive feature cards and buttons are grouped, like the landing page. */
function cms_blocks_html(array $blocks): string {
    $out = '';
    $group = '';          /* group currently open: 'features' | 'buttons' | '' */
    $buf = '';
    $flush = function () use (&$out, &$group, &$buf) {
        if ($group === '' || $buf === '') { $group = ''; $buf = ''; return; }
        $out .= $group === 'features'
            ? '<div class="features">' . $buf . '</div>'
            : '<div class="row wrap" style="margin-top:14px">' . $buf . '</div>';
        $group = '';
        $buf = '';
    };
    $first = true;
    foreach ($blocks as $b) {
        $kind = cms_block_kind($b['kind'] ?? 'text');
        $want = $kind === 'feature' ? 'features' : ($kind === 'button' ? 'buttons' : '');
        if ($want === '' || $want !== $group) { $flush(); $group = $want; }
        $html = cms_block_html($b, $first);
        $first = false;
        if ($want === '') $out .= $html; else $buf .= $html;
    }
    $flush();
    return $out;
}

/* ------------------------------------------------------------- template -- */
function cms_render_page(array $page, array $blocks, string $accountId = ''): void {
    $title = (string)$page['title'];
    $desc = (string)($page['meta_desc'] ?? '');
    $logo = (string)setting('logo_url', '');
    $powered = (string)setting('powered_by', '');
    $brand = $accountId === '' ? (string)setting('platform_name', APP_NAME) : cms_account_name($accountId);
    if ($brand === '') $brand = (string)setting('platform_name', APP_NAME);
    $nav = cms_nav_pages($accountId);
    $type = cms_page_type($page['type'] ?? 'custom');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= cms_esc($title) ?></title>
<?php if ($desc !== ''): ?><meta name="description" content="<?= cms_esc($desc) ?>"><?php endif; ?>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0b1220">
<?php if ($logo !== ''): ?><link rel="icon" href="<?= cms_esc($logo) ?>"><?php endif; ?>
<link rel="stylesheet" href="/css/style.css">
</head>
<body>
<div class="landing">
  <div class="row wrap" style="align-items:center;gap:10px;margin-bottom:18px">
    <span class="logo"<?= $logo !== '' ? ' style="background-image:url(' . cms_esc($logo) . ')"' : '' ?>></span>
    <b><?= cms_esc($brand) ?></b>
<?php if ($type !== 'landing' && $type !== 'custom'): ?>
    <span class="chip info"><?= cms_esc(cms_page_types()[$type] ?? '') ?></span>
<?php endif; ?>
    <span class="spacer"></span>
<?php foreach ($nav as $n): ?>
    <a class="navlink" href="<?= cms_esc(cms_page_url($n)) ?>"><?= cms_esc($n['title']) ?></a>
<?php endforeach; ?>
    <a class="btn sm ghost" href="/login">Sign in</a>
  </div>

<?= cms_blocks_html($blocks) ?>

  <p class="hint" style="margin-top:30px">
    <?= cms_esc($brand) ?><?= $powered !== '' ? ' · ' . cms_esc($powered) : '' ?>
  </p>
</div>
</body>
</html><?php
}

function cms_render_missing(string $slug): void {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Page not found</title><link rel="stylesheet" href="/css/style.css"></head>
<body><div class="landing">
<h1>Page not found</h1>
<p class="small">There is no published page at <span class="mono">/p/<?= cms_esc($slug) ?></span>.</p>
<p><a class="btn" href="/login">Sign in</a>
   <a class="btn ghost" href="/welcome">Website</a></p>
</div></body></html><?php
}

/* ------------------------------------------------------------- dispatch -- */
/*  /            the platform front page, or a redirect to the sign-in page
    /p/<slug>    a platform page
    /p/<acct>    an account's landing page (its 'landing' page)
    /p/<acct>/<slug>  a page that belongs to one account          */
$slug = strtolower(trim((string)($_GET['slug'] ?? '')));
$accountSlug = cms_slugify((string)($_GET['account'] ?? ''));
$wantsHome = ($slug === '' || $slug === 'home');

try {
    /* ---- an account's own content ---------------------------------------- */
    if ($accountSlug !== '') {
        $st = db()->prepare('SELECT id FROM accounts WHERE slug = ? LIMIT 1');
        $st->execute([$accountSlug]);
        $accountId = (string)($st->fetchColumn() ?: '');
        if ($accountId === '') { cms_render_missing($accountSlug); exit; }
        $page = $slug === '' ? cms_page_of_type($accountId, 'landing') : cms_page_for($accountSlug, $slug);
        if ($page === null || (string)$page['status'] !== 'published') {
            cms_render_missing($slug === '' ? $accountSlug : $accountSlug . '/' . $slug);
            exit;
        }
        cms_render_page($page, cms_blocks((string)$page['id'], true), $accountId);
        exit;
    }

    /* ---- the platform website -------------------------------------------- */
    $page = $wantsHome ? cms_home_page() : cms_page_by_slug($slug);
    if ($page === null && !$wantsHome) {
        /* /p/<name> is also an account's landing page when no platform page
           uses that address (platform pages win, so nothing breaks) */
        $st = db()->prepare('SELECT id FROM accounts WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
        $accId = (string)($st->fetchColumn() ?: '');
        if ($accId !== '') {
            $landing = cms_page_of_type($accId, 'landing');
            if ($landing !== null && (string)$landing['status'] === 'published') {
                cms_render_page($landing, cms_blocks((string)$landing['id'], true), $accId);
                exit;
            }
        }
    }
    if ($page === null) {
        if ($wantsHome) { cms_go_login(); exit; }   /* no CMS front page yet -> sign in */
        cms_render_missing($slug);
        exit;
    }
    if ((string)$page['status'] !== 'published') {
        if ($wantsHome) { cms_go_login(); exit; }
        cms_render_missing($slug);
        exit;
    }
    cms_render_page($page, cms_blocks((string)$page['id'], true));
} catch (Throwable $e) {
    /* a broken or missing database must never hide the way in */
    if ($wantsHome && $accountSlug === '') { cms_go_login(); exit; }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Page unavailable</title>'
        . '<link rel="stylesheet" href="/css/style.css"></head><body><div class="landing">'
        . '<h1>This page is unavailable</h1><p class="small">The content store could not be read.</p>'
        . '<p><a class="btn" href="/">Front page</a></p></div></body></html>';
}
