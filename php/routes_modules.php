<?php
/* =====================================================================
   KIVU Tracker / Kivu Systems — php/routes_modules.php
   API for business modules (verticals), dynamic pages/navigation, and
   the admin "Create New Business Model" builder.
   ===================================================================== */
declare(strict_types=1);

function routes_modules(): array {
    $id = '(?P<id>[0-9]{1,10})';
    $slug = '(?P<slug>[a-z0-9\-]{1,64})';
    return [
        ['GET',  '#^/modules$#',                    'h_modules_list',    'public'],
        ['GET',  '#^/modules/current$#',            'h_modules_current', 'public'],
        ['POST', '#^/modules$#',                    'h_modules_save',    'admin'],
        ['GET',  '#^/modules/' . $id . '$#',        'h_modules_get',     'admin'],
        ['PUT',  '#^/modules/' . $id . '$#',        'h_modules_save',    'admin'],
        ['POST', '#^/modules/' . $id . '$#',        'h_modules_save',    'admin'],
        ['DELETE','#^/modules/' . $id . '$#',       'h_modules_delete',  'admin'],
        ['POST', '#^/modules/' . $id . '/logo$#',   'h_modules_logo',    'admin'],
        ['GET',  '#^/modules/' . $id . '/pages$#',  'h_module_pages_list','admin'],
        ['POST', '#^/modules/' . $id . '/pages$#',  'h_module_page_save', 'admin'],
        ['GET',  '#^/module-pages/' . $id . '$#',   'h_module_page_get',  'admin'],
        ['PUT',  '#^/module-pages/' . $id . '$#',   'h_module_page_save', 'admin'],
        ['POST', '#^/module-pages/' . $id . '$#',   'h_module_page_save', 'admin'],
        ['DELETE','#^/module-pages/' . $id . '$#',  'h_module_page_delete','admin'],
        ['GET',  '#^/modules/public/' . $slug . '$#','h_module_public',   'public'],
    ];
}

function h_modules_list($user, $p): array {
    module_seed_defaults();
    $all = is_admin($user);
    try {
        $rows = $all
            ? db()->query('SELECT * FROM business_modules ORDER BY sort_order, module_name')->fetchAll()
            : modules_enabled_list();
    } catch (Throwable $e) { $rows = []; }
    return ['ok' => true, 'modules' => array_map('module_public', $rows ?: [])];
}
function h_modules_current($user, $p): array {
    $m = module_resolve((string)qp('slug', qp('module', '')));
    $nav = !empty($m['id']) ? module_nav_pages((int)$m['id']) : [];
    return ['ok' => true, 'module' => $m, 'nav' => $nav];
}
function h_modules_get($user, $p): array {
    module_require_builder($user);
    $m = module_by_id((int)$p['id']);
    need($m, 'Module not found', 404);
    $pub = module_public($m);
    $st = db()->prepare('SELECT * FROM dynamic_pages WHERE module_id=? ORDER BY nav_position, title');
    $st->execute([(int)$m['id']]);
    $pages = $st->fetchAll() ?: [];
    foreach ($pages as &$pg) $pg['content'] = jdec($pg['content'] ?? null, null);
    return ['ok' => true, 'module' => $pub, 'theme_config' => $pub['theme'], 'pages' => $pages];
}
function h_modules_save($user, $p): array {
    $in = body();
    if (!empty($p['id'])) $in['id'] = (int)$p['id'];
    $m = module_save($in, $user);
    return ['ok' => true, 'module' => $m, 'message' => 'Business module saved'];
}
function h_modules_delete($user, $p): array {
    module_delete((int)$p['id'], $user);
    return ['ok' => true, 'message' => 'Module deleted'];
}
function h_modules_logo($user, $p): array {
    module_require_builder($user);
    $id = (int)$p['id'];
    $m = module_by_id($id);
    need($m, 'Module not found', 404);
    $url = branding_store_upload($_FILES['logo'] ?? []);
    db()->prepare('UPDATE business_modules SET logo_path=?, updated_at=? WHERE id=?')->execute([$url, now_dt(), $id]);
    audit('module_logo_set', 'business_module', (string)$id, ['url' => $url, 'by' => $user['username'] ?? '']);
    return ['ok' => true, 'logo_path' => $url, 'module' => module_public(module_by_id($id) ?: $m)];
}
function h_module_pages_list($user, $p): array {
    module_require_builder($user);
    $mid = (int)$p['id'];
    $st = db()->prepare('SELECT * FROM dynamic_pages WHERE module_id=? ORDER BY nav_position, title');
    $st->execute([$mid]);
    $rows = $st->fetchAll() ?: [];
    foreach ($rows as &$r) $r['content'] = jdec($r['content'] ?? null, null);
    return ['ok' => true, 'pages' => $rows];
}
function h_module_page_get($user, $p): array {
    module_require_builder($user);
    $st = db()->prepare('SELECT * FROM dynamic_pages WHERE id=?');
    $st->execute([(int)$p['id']]);
    $r = $st->fetch();
    need($r, 'Page not found', 404);
    $r['content'] = jdec($r['content'] ?? null, null);
    return ['ok' => true, 'page' => $r];
}
function h_module_page_save($user, $p): array {
    $in = body();
    if (!empty($p['id'])) $in['id'] = (int)$p['id'];
    $page = module_page_save($in, $user);
    return ['ok' => true, 'page' => $page, 'message' => 'Page saved'];
}
function h_module_page_delete($user, $p): array {
    module_page_delete((int)$p['id'], $user);
    return ['ok' => true, 'message' => 'Page deleted'];
}
function h_module_public($user, $p): array {
    $m = module_by_slug((string)$p['slug']);
    need($m && (int)$m['is_active'], 'Module not found', 404);
    $pub = module_public($m);
    $nav = module_nav_pages((int)$m['id']);
    $home = module_dynamic_page((int)$m['id'], 'home', null);
    if ($home) $home['content'] = jdec($home['content'] ?? null, null);
    return ['ok' => true, 'module' => $pub, 'nav' => $nav, 'home' => $home];
}
?>