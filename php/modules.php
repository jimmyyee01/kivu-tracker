<?php
/* =====================================================================
   KIVU Tracker / Kivu Systems — php/modules.php
   Business-module (vertical) engine: resolution by path/domain,
   theme config -> CSS variables, dynamic pages/navigation, and the
   admin builder primitives. Additive to the telematics core.
   ===================================================================== */
declare(strict_types=1);

function module_reserved_slugs(): array {
    return ['api','app','admin','super','login','logout','register','signup','billing',
        'pay','reports','track','cms','reseller','download','manual','p','public',
        'css','js','img','icons','tools','data','php','install','module','modules','m'];
}

/* ----------------------------------------------------------- DB access -- */
function module_seed_defaults(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        ensure_schema();
        $n = (int)db()->query('SELECT COUNT(*) FROM business_modules')->fetchColumn();
        if ($n > 0) return;
        $now = now_dt();
        $defaults = [
            ['telematics', 'Kivu Tracker', 'GPS tracking and fleet management', null,
                jenc(['primary' => '#2f9cf4', 'accent' => '#16a085', 'bg' => '#0f1420',
                      'surface' => '#161d2d', 'text' => '#dfe6f2', 'font' => 'system-ui, Segoe UI, Arial, sans-serif',
                      'mode' => 'dark', 'system_url' => 'https://telematics.example.com', 'subdomain' => 'telematics']),
                1, 10],
            ['agriculture', 'Kivu AgriSmart', 'Farm, crop, livestock and yield management', null,
                jenc(['primary' => '#15803d', 'accent' => '#84cc16', 'bg' => '#f4fbf4',
                      'surface' => '#ffffff', 'text' => '#102015', 'font' => 'system-ui, Segoe UI, Arial, sans-serif',
                      'mode' => 'light', 'system_url' => 'https://agriculture.example.com', 'subdomain' => 'agriculture']),
                1, 20],
            ['health', 'Kivu HealthConnect', 'Tele-nursing, scheduling and care-plan management', null,
                jenc(['primary' => '#0f766e', 'accent' => '#38bdf8', 'bg' => '#f4fbfb',
                      'surface' => '#ffffff', 'text' => '#102022', 'font' => 'system-ui, Segoe UI, Arial, sans-serif',
                      'mode' => 'light', 'system_url' => 'https://health.example.com', 'subdomain' => 'health']),
                1, 30],
        ];
        $st = db()->prepare('INSERT INTO business_modules
            (module_slug,module_name,tagline,logo_path,theme_config,is_active,sort_order,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($defaults as $d) $st->execute(array_merge($d, [$now, $now]));
        module_seed_default_pages();
    } catch (Throwable $e) { /* never break requests on seed */ }
}

function module_seed_default_pages(): void {
    try {
        $mods = db()->query("SELECT id, module_slug, module_name, tagline FROM business_modules WHERE module_slug IN ('telematics','agriculture','health')")->fetchAll();
        $st = db()->prepare('SELECT id FROM dynamic_pages WHERE module_id = ? AND account_id IS NULL LIMIT 1');
        $ins = db()->prepare('INSERT INTO dynamic_pages
            (module_id,account_id,title,slug,content,content_type,nav_position,show_in_nav,status,meta_desc,created_at,updated_at,updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $now = now_dt();
        foreach ($mods as $m) {
            $st->execute([$m['id']]);
            if ($st->fetch()) continue;
            $content = jenc(['blocks' => module_starter_blocks((string)$m['module_slug'], (string)$m['module_name'], (string)($m['tagline'] ?? ''))]);
            $ins->execute([(int)$m['id'], null, $m['module_name'], 'home', $content, 'blocks', 10, 1, 'published', $m['tagline'], $now, $now, 'system']);
        }
    } catch (Throwable $e) { /* ignore */ }
}

function module_starter_blocks(string $slug, string $name, string $tagline): array {
    $system = '';
    try { $m = module_by_slug($slug); if ($m) $system = module_system_url($m, jdec($m['theme_config'] ?? null, [])); } catch (Throwable $e) {}
    $launch = $system !== '' ? $system : ('https://' . $slug . '.example.com');
    $commonCta = [
        ['type' => 'cta', 'props' => ['label' => 'Open ' . $name, 'href' => $launch, 'primary' => true, 'external' => $system !== '']],
        ['type' => 'cta', 'props' => ['label' => 'Request a demo', 'href' => '/register']],
    ];
    $features = [
        'telematics' => [['title' => 'Live GPS tracking', 'body' => 'Real-time positions, status, and device connectivity on the telematics subdomain.'],
                         ['title' => 'Trips & geofences', 'body' => 'Detect trips/parking and enter/exit events with alerts.'],
                         ['title' => 'Reports & billing', 'body' => 'Operational reports, CSV export, and subscription management.']],
        'agriculture' => [['title' => 'Crop cycle tracking', 'body' => 'Plan, plant, monitor and harvest by field and season.'],
                          ['title' => 'Livestock & inventory', 'body' => 'Track animals, feed, inputs, and stock levels.'],
                          ['title' => 'Yield analytics', 'body' => 'Measure harvests and revenue over time.']],
        'health' => [['title' => 'Tele-nursing scheduling', 'body' => 'Book and manage consultations and home visits.'],
                     ['title' => 'Vitals & care plans', 'body' => 'Record vitals and maintain structured care plans.'],
                     ['title' => 'Secure messaging', 'body' => 'Coordinate care with audited, role-based access.']],
    ];
    $blocks = [['type' => 'hero', 'props' => ['title' => $name, 'subtitle' => $tagline ?: 'Powered by Kivu Systems', 'eyebrow' => 'Kivu Systems']],
               ['type' => 'actions', 'props' => ['items' => $commonCta]]];
    foreach (($features[$slug] ?? [['title' => $name, 'body' => $tagline]]) as $f) {
        $blocks[] = ['type' => 'feature', 'props' => $f];
    }
    return $blocks;
}

function module_by_slug(string $slug): ?array {
    module_seed_defaults();
    try {
        $st = db()->prepare('SELECT * FROM business_modules WHERE module_slug = ? LIMIT 1');
        $st->execute([$slug]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}
function module_by_id($id): ?array {
    if (!$id) return null;
    try {
        $st = db()->prepare('SELECT * FROM business_modules WHERE id = ? LIMIT 1');
        $st->execute([(int)$id]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}
function modules_enabled_list(): array {
    module_seed_defaults();
    try {
        return db()->query('SELECT * FROM business_modules WHERE is_active = 1 ORDER BY sort_order, module_name')->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}
function module_by_host(string $host): ?array {
    $host = strtolower(trim(preg_replace('/:\d+$/', '', $host)));
    if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') return null;
    try {
        $st = db()->prepare('SELECT m.* FROM module_domains d JOIN business_modules m ON m.id=d.module_id WHERE d.host=? AND m.is_active=1 LIMIT 1');
        $st->execute([$host]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/* ----------------------------------------------------------- resolution -- */
function module_resolve(?string $hint = null): array {
    module_seed_defaults();
    // Priority for a website+subdomains deployment: 1) explicit hint/param,
    // 2) host/subdomain mapping (module_domains), 3) subdomain label match,
    // 4) path prefix (local/dev), 5) platform default.
    $slug = '';
    if ($hint !== null && $hint !== '') $slug = strtolower(trim($hint));
    if ($slug === '') $slug = strtolower(trim((string)($_GET['module'] ?? qp('module', '') ?? '')));

    $m = null;
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!$m && $host !== '') $m = module_by_host($host);
    if (!$m && $host !== '') {
        $label = strtolower(preg_replace('/\..*$/', '', preg_replace('/:\d+$/', '', $host)));
        if ($label !== '' && $label !== 'www' && !in_array($label, ['localhost','127'], true)) {
            $cand = module_by_slug($label);
            if ($cand) $m = $cand;
        }
    }
    if (!$m && $slug !== '') $m = module_by_slug($slug);
    if (!$m && $slug === '') {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
        if (preg_match('#^/([a-z0-9\-]{2,64})(?:/|$)#i', $path, $mm)) {
            $cand = strtolower($mm[1]);
            if (!in_array($cand, module_reserved_slugs(), true)) $m = module_by_slug($cand);
        }
    }
    if (!$m) $m = module_by_slug((string)setting('default_module_slug', 'telematics'));
    if (!$m) {
        $list = modules_enabled_list();
        $m = $list[0] ?? ['id' => 0, 'module_slug' => 'telematics', 'module_name' => setting('platform_name', APP_NAME),
            'tagline' => 'GPS tracking and fleet management', 'logo_path' => branding_logo_url(),
            'theme_config' => null, 'is_active' => 1];
    }
    if (!(int)($m['is_active'] ?? 1)) {
        $def = module_by_slug((string)setting('default_module_slug', 'telematics'));
        if ($def && (int)$def['is_active']) $m = $def;
    }
    return module_public($m);
}

function module_current(): array {
    static $cur = null;
    if ($cur === null) $cur = module_resolve();
    return $cur;
}

function module_system_url(array $m, array $theme = []): string {
    // Subdomain/external system URL (the real system lives off this website).
    $url = trim((string)($theme['system_url'] ?? $theme['console_url'] ?? $theme['url'] ?? ''));
    if ($url !== '' && preg_match('#^https?://#i', $url)) return $url;
    return '';
}

function module_public(array $m): array {
    $raw = jdec($m['theme_config'] ?? null, []);
    $theme = module_theme_normalize($raw);
    $logo = trim((string)($m['logo_path'] ?? '')) ?: branding_logo_url();
    $slug = (string)($m['module_slug'] ?? 'telematics');
    $systemUrl = module_system_url($m, is_array($raw) ? $raw : []);
    return [
        'id' => (int)($m['id'] ?? 0),
        'slug' => $slug,
        'name' => (string)($m['module_name'] ?? 'Kivu'),
        'tagline' => (string)($m['tagline'] ?? ''),
        'logo' => $logo,
        'theme' => $theme,
        'is_active' => (int)($m['is_active'] ?? 1),
        'base_path' => '/' . $slug,
        'console_path' => module_console_path($slug, $m),
        'system_url' => $systemUrl,
        'launch_url' => $systemUrl !== '' ? $systemUrl : ('/' . $slug),
        'subdomain' => trim((string)($raw['subdomain'] ?? $slug)),
    ];
}

function module_console_path(string $slug, $m = null): string {
    // Prefer configured subdomain/system URL (systems live off the main website).
    if (is_array($m)) {
        $url = module_system_url($m, jdec($m['theme_config'] ?? null, []));
        if ($url !== '') return rtrim($url, '/');
    } elseif (!$m) {
        $row = module_by_slug($slug);
        if ($row) {
            $url = module_system_url($row, jdec($row['theme_config'] ?? null, []));
            if ($url !== '') return rtrim($url, '/');
        }
    }
    if ($slug === 'telematics') return '/app';
    return '/m/' . $slug;
}

function module_theme_normalize($cfg): array {
    $d = is_array($cfg) ? $cfg : [];
    $out = [
        'primary' => module_color($d['primary'] ?? $d['primary_color'] ?? '#2f9cf4', '#2f9cf4'),
        'accent'  => module_color($d['accent'] ?? $d['accent_color'] ?? '#16a085', '#16a085'),
        'bg'      => module_color($d['bg'] ?? $d['background'] ?? '#0f1420', '#0f1420'),
        'surface' => module_color($d['surface'] ?? $d['panel'] ?? '#161d2d', '#161d2d'),
        'text'    => module_color($d['text'] ?? $d['color'] ?? '#dfe6f2', '#dfe6f2'),
        'font'    => preg_replace('/[^a-zA-Z0-9 ,\-\'"()]/', '', (string)($d['font'] ?? $d['font_family'] ?? 'system-ui, Segoe UI, Arial, sans-serif')) ?: 'system-ui, Segoe UI, Arial, sans-serif',
        'mode'    => in_array(($d['mode'] ?? 'dark'), ['dark','light','auto'], true) ? $d['mode'] : 'dark',
    ];
    // Preserve deployment/routing metadata used by the marketing website.
    foreach (['system_url','console_url','url','subdomain','domain'] as $k) {
        if (isset($d[$k]) && trim((string)$d[$k]) !== '') $out[$k] = trim((string)$d[$k]);
    }
    return $out;
}
function module_color($v, string $fallback): string {
    $v = trim((string)$v);
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v)) return $v;
    if (preg_match('/^(rgb|hsl)a?\([0-9.,% ]+\)$/', $v)) return $v;
    return $fallback;
}
function module_theme_css(array $m): string {
    $t = $m['theme'] ?? module_theme_normalize([]);
    $font = str_replace('"', "'", (string)$t['font']);
    return ":root{--primary-color:{$t['primary']};--accent-color:{$t['accent']};"
        . "--module-bg:{$t['bg']};--module-surface:{$t['surface']};--module-text:{$t['text']};"
        . "--font-body:{$font};--accent:{$t['primary']};--accent-2:{$t['accent']};}"
        . "body{font-family:var(--font-body),var(--font);}";
}
function module_theme_style_tag(?array $m = null): string {
    $m = $m ?? module_current();
    return '<style id="module-theme">' . module_theme_css($m) . '</style>';
}

/* --------------------------------------------------------- dynamic pages -- */
function module_nav_pages(int $moduleId, ?string $accountId = null): array {
    try {
        $st = db()->prepare("SELECT id,module_id,title,slug,nav_position,show_in_nav,status,meta_desc
            FROM dynamic_pages WHERE module_id=? AND account_id <=> ? AND status='published' AND show_in_nav=1
            ORDER BY nav_position, title");
        $st->execute([$moduleId, $accountId]);
        $rows = $st->fetchAll() ?: [];
        return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'slug' => $r['slug'],
            'nav_position' => (int)$r['nav_position'], 'url' => module_page_url((int)$moduleId, (string)$r['slug'])], $rows);
    } catch (Throwable $e) { return []; }
}
function module_page_url(int $moduleId, string $slug): string {
    $m = module_by_id($moduleId);
    $ms = $m ? (string)$m['module_slug'] : '';
    if ($ms === '') return '/';
    $base = '';
    if ($m) $base = module_system_url($m, jdec($m['theme_config'] ?? null, []));
    if ($base !== '') {
        if ($slug === 'home' || $slug === '') return rtrim($base, '/');
        return rtrim($base, '/') . '/p/' . $slug;
    }
    // Local/dev fallback (path on current host)
    if ($slug === 'home' || $slug === '') return '/' . $ms;
    return '/' . $ms . '/p/' . $slug;
}
function module_dynamic_page(int $moduleId, string $slug, ?string $accountId = null): ?array {
    try {
        $st = db()->prepare('SELECT * FROM dynamic_pages WHERE module_id=? AND slug=? AND account_id <=> ? LIMIT 1');
        $st->execute([$moduleId, $slug, $accountId]);
        $r = $st->fetch();
        if (!$r && $accountId !== null) {
            $st->execute([$moduleId, $slug, null]);
            $r = $st->fetch();
        }
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}
function module_render_content(string $content, string $type = 'blocks'): string {
    if ($type === 'html') return module_sanitize_html($content);
    $doc = jdec($content, []);
    $blocks = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : (is_array($doc) && isset($doc[0]) ? $doc : []);
    $out = '';
    foreach ($blocks as $b) {
        if (!is_array($b)) continue;
        $kind = (string)($b['type'] ?? $b['kind'] ?? 'text');
        $p = is_array($b['props'] ?? null) ? $b['props'] : $b;
        $out .= module_render_block($kind, $p);
    }
    return $out;
}
function module_render_block(string $kind, array $p): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $title = $h($p['title'] ?? $p['label'] ?? '');
    $body = $h($p['body'] ?? $p['text'] ?? $p['subtitle'] ?? '');
    $href = $h($p['href'] ?? $p['url'] ?? '#');
    switch ($kind) {
        case 'hero':
            return '<section class="m-hero"><p class="eyebrow">'.$h($p['eyebrow'] ?? 'Kivu Systems').'</p><h1>'.$title.'</h1>'
                 . ($body ? '<p class="lead">'.$body.'</p>' : '') . '</section>';
        case 'feature':
            return '<article class="m-card"><h3>'.$title.'</h3>' . ($body ? '<p>'.$body.'</p>' : '') . '</article>';
        case 'cta':
            $cls = !empty($p['primary']) ? 'btn primary' : 'btn ghost';
            return '<a class="'.$cls.'" href="'.$href.'">'.$title.'</a>';
        case 'actions':
            $items = is_array($p['items'] ?? null) ? $p['items'] : [];
            $inner = '';
            foreach ($items as $it) if (is_array($it)) $inner .= module_render_block('cta', $it);
            return '<div class="m-actions">'.$inner.'</div>';
        case 'kpi':
            return '<div class="m-kpi"><div class="v">'.$h($p['value'] ?? '—').'</div><div class="l">'.$title.'</div></div>';
        case 'html':
            return module_sanitize_html((string)($p['html'] ?? $p['body'] ?? ''));
        default:
            return ($title ? '<h3>'.$title.'</h3>' : '') . ($body ? '<p>'.$body.'</p>' : '');
    }
}
/* Basic sanitizer for trusted-admin HTML; strips scripts/handlers. */
function module_sanitize_html(string $html): string {
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('#<(iframe|object|embed)\b#i', '<div data-blocked', $html);
    return $html;
}

/* ----------------------------------------------------------- admin CRUD -- */
function module_require_builder(?array $u = null): array {
    $u = $u ?? auth_user();
    need(is_admin($u), 'Administrator rights required', 403);
    return $u;
}
function module_save(array $in, ?array $u = null): array {
    module_require_builder($u);
    ensure_schema();
    $id = isset($in['id']) && $in['id'] !== '' ? (int)$in['id'] : 0;
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string)($in['module_slug'] ?? $in['slug'] ?? ''))));
    $name = trim((string)($in['module_name'] ?? $in['name'] ?? ''));
    need($slug !== '' && $name !== '', 'Module slug and name are required');
    need(strlen($slug) <= 64, 'Slug too long');
    need(!in_array($slug, module_reserved_slugs(), true), 'That slug is reserved: ' . $slug);
    $existing = module_by_slug($slug);
    if ($existing && (int)$existing['id'] !== $id) throw new ApiError('Module slug already exists: ' . $slug, 409);
    $theme = module_theme_normalize(is_array($in['theme_config'] ?? null) ? $in['theme_config'] : jdec($in['theme_config'] ?? null, []));
    $row = [
        'module_slug' => $slug,
        'module_name' => $name,
        'tagline' => trim((string)($in['tagline'] ?? '')) ?: null,
        'logo_path' => trim((string)($in['logo_path'] ?? $in['logo'] ?? '')) ?: null,
        'theme_config' => jenc($theme),
        'is_active' => !empty($in['is_active']) || ($in['is_active'] ?? '1') === '1' ? 1 : 0,
        'sort_order' => (int)($in['sort_order'] ?? 100),
        'updated_at' => now_dt(),
    ];
    if ($id > 0) {
        $sets = []; $vals = [];
        foreach ($row as $k => $v) { $sets[] = "`$k`=?"; $vals[] = $v; }
        $vals[] = $id;
        db()->prepare('UPDATE business_modules SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
    } else {
        $row['created_at'] = now_dt();
        $cols = array_keys($row); $vals = array_values($row);
        db()->prepare('INSERT INTO business_modules (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
        $id = (int)db()->lastInsertId();
        module_seed_pages_for($id, $slug, $name, (string)($row['tagline'] ?? ''));
    }
    audit('module_saved', 'business_module', (string)$id, ['slug' => $slug, 'by' => $u['username'] ?? '']);
    return module_public(module_by_id($id) ?: $row + ['id' => $id]);
}

function module_seed_pages_for(int $moduleId, string $slug, string $name, string $tagline): void {
    try {
        $st = db()->prepare('SELECT id FROM dynamic_pages WHERE module_id=? AND account_id IS NULL LIMIT 1');
        $st->execute([$moduleId]);
        if ($st->fetch()) return;
        $content = jenc(['blocks' => module_starter_blocks($slug, $name, $tagline)]);
        $now = now_dt();
        db()->prepare('INSERT INTO dynamic_pages (module_id,account_id,title,slug,content,content_type,nav_position,show_in_nav,status,meta_desc,created_at,updated_at,updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$moduleId, null, $name, 'home', $content, 'blocks', 10, 1, 'published', $tagline, $now, $now, 'system']);
    } catch (Throwable $e) { /* ignore */ }
}

function module_delete(int $id, ?array $u = null): void {
    module_require_builder($u);
    $m = module_by_id($id);
    need($m, 'Module not found', 404);
    $slug = (string)$m['module_slug'];
    need(!in_array($slug, ['telematics','agriculture','health'], true), 'Core modules cannot be deleted; disable them instead.', 409);
    db()->prepare('DELETE FROM dynamic_pages WHERE module_id=?')->execute([$id]);
    try { db()->prepare('DELETE FROM module_domains WHERE module_id=?')->execute([$id]); } catch (Throwable $e) {}
    db()->prepare('DELETE FROM business_modules WHERE id=?')->execute([$id]);
    audit('module_deleted', 'business_module', (string)$id, ['slug' => $slug, 'by' => $u['username'] ?? '']);
}

/* ------------------------------------------------------- dynamic pages CRUD */
function module_page_save(array $in, ?array $u = null): array {
    module_require_builder($u);
    ensure_schema();
    $id = isset($in['id']) && $in['id'] !== '' ? (int)$in['id'] : 0;
    $moduleId = (int)($in['module_id'] ?? 0);
    need($moduleId > 0 && module_by_id($moduleId), 'Valid module_id required');
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string)($in['slug'] ?? ''))));
    $title = trim((string)($in['title'] ?? ''));
    need($slug !== '' && $title !== '', 'Page slug and title are required');
    $content = $in['content'] ?? ['blocks' => []];
    if (is_array($content)) $content = jenc($content);
    $accountId = trim((string)($in['account_id'] ?? ''));
    $row = [
        'module_id' => $moduleId,
        'account_id' => $accountId !== '' ? $accountId : null,
        'title' => $title,
        'slug' => $slug,
        'content' => (string)$content,
        'content_type' => in_array(($in['content_type'] ?? 'blocks'), ['blocks','html'], true) ? $in['content_type'] : 'blocks',
        'nav_position' => (int)($in['nav_position'] ?? 100),
        'show_in_nav' => !empty($in['show_in_nav']) ? 1 : 0,
        'status' => in_array(($in['status'] ?? 'draft'), ['draft','published'], true) ? $in['status'] : 'draft',
        'meta_desc' => trim((string)($in['meta_desc'] ?? '')) ?: null,
        'updated_by' => $u['username'] ?? 'admin',
        'updated_at' => now_dt(),
    ];
    if ($id > 0) {
        $sets = []; $vals = [];
        foreach ($row as $k => $v) { $sets[] = "`$k`=?"; $vals[] = $v; }
        $vals[] = $id;
        db()->prepare('UPDATE dynamic_pages SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
    } else {
        $row['created_at'] = now_dt();
        $cols = array_keys($row); $vals = array_values($row);
        db()->prepare('INSERT INTO dynamic_pages (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
        $id = (int)db()->lastInsertId();
    }
    audit('module_page_saved', 'dynamic_page', (string)$id, ['module_id' => $moduleId, 'slug' => $slug]);
    $st = db()->prepare('SELECT * FROM dynamic_pages WHERE id=?'); $st->execute([$id]);
    $out = $st->fetch(); $out['content'] = jdec($out['content'] ?? null, null);
    return $out;
}
function module_page_delete(int $id, ?array $u = null): void {
    module_require_builder($u);
    db()->prepare('DELETE FROM dynamic_pages WHERE id=?')->execute([$id]);
    audit('module_page_deleted', 'dynamic_page', (string)$id, []);
}

/* ----------------------------------------------------------- meta payload -- */
function module_meta_payload(?array $user = null): array {
    $cur = module_current();
    $moduleId = (int)$cur['id'];
    $nav = $moduleId > 0 ? module_nav_pages($moduleId, null) : [];
    $enabled = array_map('module_public', modules_enabled_list());
    return [
        'module' => $cur,
        'modules' => $enabled,
        'module_nav' => $nav,
        'modules_enabled' => (int)setting('modules_enabled', 1) === 1,
    ];
}
?>