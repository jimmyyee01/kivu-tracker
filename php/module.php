<?php
/* =====================================================================
   Kivu Systems — php/module.php
   SSR renderer for business-module landing/pages. Applies dynamic
   theme (CSS variables + logo) and renders module navigation/content.
   Routes: /{module}  /{module}/p/{slug}  /m/{module} (console shell)
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/modules.php';

$slug = strtolower(trim((string)($_GET['module'] ?? '')));
$pageSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string)($_GET['slug'] ?? ''))));
$console = !empty($_GET['console']);

module_seed_defaults();
$m = module_by_slug($slug);
if (!$m || !(int)$m['is_active']) {
    $m = module_by_slug((string)setting('default_module_slug', 'telematics')) ?: module_by_id(1);
}
$mod = module_public($m ?: []);
$moduleId = (int)($mod['id'] ?? 0);

if ($console) {
    module_render_console($mod);
    exit;
}

$page = $pageSlug !== '' ? module_dynamic_page($moduleId, $pageSlug, null) : module_dynamic_page($moduleId, 'home', null);
if ($pageSlug !== '' && (!$page || ($page['status'] ?? '') !== 'published')) {
    http_response_code(404);
    $page = ['title' => 'Page not found', 'content' => jenc(['blocks' => [['type' => 'hero', 'props' => ['title' => 'Page not found', 'subtitle' => 'The requested page is not available.']]]]), 'content_type' => 'blocks', 'meta_desc' => ''];
} elseif (!$page) {
    $page = ['title' => $mod['name'], 'content' => jenc(['blocks' => module_starter_blocks($mod['slug'], $mod['name'], $mod['tagline'])]), 'content_type' => 'blocks', 'meta_desc' => $mod['tagline']];
}
$nav = module_nav_pages($moduleId, null);
$title = htmlspecialchars((string)($page['title'] ?? $mod['name']), ENT_QUOTES, 'UTF-8');
$metaDesc = htmlspecialchars((string)($page['meta_desc'] ?? $mod['tagline'] ?? ''), ENT_QUOTES, 'UTF-8');
$logo = htmlspecialchars((string)$mod['logo'], ENT_QUOTES, 'UTF-8');
$brand = htmlspecialchars((string)$mod['name'], ENT_QUOTES, 'UTF-8');
$themeTag = module_theme_style_tag($mod);
$bodyHtml = module_render_content((string)($page['content'] ?? ''), (string)($page['content_type'] ?? 'blocks'));
$moduleJson = jenc($mod);
$navJson = jenc($nav);
$base = htmlspecialchars((string)$mod['base_path'], ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en" data-module="<?php echo htmlspecialchars($mod['slug']); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo $title; ?> — <?php echo $brand; ?></title>
<?php if ($metaDesc !== ''): ?><meta name="description" content="<?php echo $metaDesc; ?>"><?php endif; ?>
<link rel="stylesheet" href="/css/style.css">
<?php echo $themeTag; ?>
<style>
.module-shell{min-height:100vh;display:flex;flex-direction:column}
.module-top{position:sticky;top:0;z-index:50;display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--module-surface,var(--panel));border-bottom:1px solid var(--border);flex-wrap:wrap}
.module-brand{display:flex;align-items:center;gap:10px;font-weight:700;color:var(--module-text,var(--text));text-decoration:none}
.module-brand img{height:34px;width:auto;max-width:160px;object-fit:contain}
.module-nav{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-left:auto}
.module-nav a{color:var(--muted);text-decoration:none;padding:7px 10px;border-radius:8px;font-size:14px}
.module-nav a:hover,.module-nav a.active{color:var(--text);background:var(--panel-2)}
.module-burger{display:none;margin-left:auto;background:var(--panel-2);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:7px 10px}
.module-main{flex:1;width:100%;max-width:1120px;margin:0 auto;padding:20px 14px 48px}
.m-hero{padding:28px 0 10px}.m-hero .eyebrow{text-transform:uppercase;letter-spacing:.08em;font-size:12px;color:var(--accent);margin:0 0 8px}
.m-hero h1{font-size:clamp(26px,4vw,42px);margin:0 0 10px;color:var(--module-text,var(--text))}
.m-hero .lead{font-size:17px;color:var(--muted);max-width:760px;margin:0}
.m-actions{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0 24px}
.m-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-top:12px}
.m-card,.m-kpi{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:14px}
.m-card h3{margin:0 0 6px;font-size:16px}.m-card p{margin:0;color:var(--muted);font-size:14px}
.m-kpi .v{font-size:28px;font-weight:700;color:var(--accent)}.m-kpi .l{color:var(--muted);font-size:12px}
.module-foot{border-top:1px solid var(--border);padding:14px;text-align:center;color:var(--dim);font-size:12px}
@media (max-width:760px){.module-burger{display:inline-flex}.module-nav{display:none;width:100%;margin:8px 0 0;flex-direction:column;align-items:stretch}
 .module-top.open .module-nav{display:flex}.module-nav a{padding:10px 12px}}
</style>
</head>
<body class="module-page">
<div class="module-shell">
  <header class="module-top" id="moduleTop">
    <a class="module-brand" href="<?php echo $base; ?>">
      <?php if ($logo !== ''): ?><img src="<?php echo $logo; ?>" alt="<?php echo $brand; ?> logo"><?php endif; ?>
      <span><?php echo $brand; ?></span>
    </a>
    <button class="module-burger" type="button" id="moduleBurger" aria-label="Menu">☰</button>
    <nav class="module-nav" id="moduleNav">
      <a href="<?php echo $base; ?>" class="<?php echo ($pageSlug===''||$pageSlug==='home')?'active':''; ?>">Home</a>
      <?php foreach ($nav as $n): ?>
        <a href="<?php echo htmlspecialchars($n['url'], ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $pageSlug===($n['slug']??'')?'active':''; ?>"><?php echo htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8'); ?></a>
      <?php endforeach; ?>
      <a href="/login">Sign in</a>
      <a class="btn primary sm" href="<?php echo htmlspecialchars($mod['launch_url'] ?: ($mod['system_url'] ?: $mod['console_path']), ENT_QUOTES, 'UTF-8'); ?>"<?php echo !empty($mod['system_url']) ? ' target="_blank" rel="noopener"' : ''; ?>>Open system</a>
    </nav>
  </header>
  <main class="module-main">
    <?php
      // wrap feature/kpi blocks in a grid for layout; render others inline
      $doc = jdec((string)($page['content'] ?? ''), []);
      $blocks = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [];
      $grid = ''; $out = '';
      $flush = function() use (&$grid,&$out){ if($grid!==''){ $out.='<div class="m-grid">'.$grid.'</div>'; $grid=''; } };
      foreach ($blocks as $b) {
        if (!is_array($b)) continue;
        $k = (string)($b['type'] ?? $b['kind'] ?? 'text');
        $html = module_render_block($k, is_array($b['props'] ?? null) ? $b['props'] : $b);
        if (in_array($k, ['feature','kpi'], true)) $grid .= $html; else { $flush(); $out .= $html; }
      }
      $flush();
      echo $out !== '' ? $out : $bodyHtml;
    ?>
  </main>
  <footer class="module-foot"><?php echo $brand; ?> · Powered by <?php echo htmlspecialchars(setting('powered_by', '') ?: setting('platform_name', APP_NAME), ENT_QUOTES, 'UTF-8'); ?></footer>
</div>
<script>
window.KivuModule=<?php echo $moduleJson; ?>; window.KivuModuleNav=<?php echo $navJson; ?>;
(function(){var b=document.getElementById('moduleBurger'),t=document.getElementById('moduleTop');
 if(b&&t)b.addEventListener('click',function(){t.classList.toggle('open');});})();
</script>
</body>
</html>
<?php

function module_render_console(array $mod): void {
    $slug = (string)($mod['slug'] ?? '');
    // Reuse existing telematics console; other modules get the shared dashboard shell.
    if ($slug === 'telematics') {
        $file = dirname(__DIR__) . '/public/app.html';
        if (is_file($file)) {
            header('Content-Type: text/html; charset=utf-8');
            $html = file_get_contents($file);
            $inject = module_theme_style_tag($mod) . '<script>window.KivuModule=' . jenc($mod) . ';</script>';
            echo str_replace('</head>', $inject . '</head>', $html);
            return;
        }
    }
    $file = dirname(__DIR__) . '/public/module-console.html';
    if (is_file($file)) {
        header('Content-Type: text/html; charset=utf-8');
        $html = file_get_contents($file);
        $inject = module_theme_style_tag($mod) . '<script>window.KivuModule=' . jenc($mod) . ';</script>';
        echo str_replace('</head>', $inject . '</head>', $html);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset=utf-8>' . module_theme_style_tag($mod)
       . '<link rel="stylesheet" href="/css/style.css"><div class="pad"><h2>'
       . htmlspecialchars($mod['name']) . '</h2><p>Console coming online. <a href="/login">Sign in</a></p></div>';
}
?>