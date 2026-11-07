<?php
/* =====================================================================
   KIVU Tracker — php/routes_cms.php
   Content management for the public website.

   A page (cms_pages) is an ordered list of blocks (cms_blocks) rendered by
   php/cms.php at /p/<slug> — or at / when is_home = 1. The manager UI lives
   at /cms (public/cms.html + public/js/cms.js).

   Every route here is super-admin only, and the whole module refuses the
   mobile app: the Android client loads /app and must never expose the
   content manager, not even by typing the URL (see cms_is_mobile_app()).
   ===================================================================== */
declare(strict_types=1);

/* --------------------------------------------------------- block kinds -- */
/* What the editor can insert. 'html' is raw markup and therefore trusted
   input: only a super admin can reach this module. */
function cms_block_kinds(): array {
    return [
        'heading' => 'Heading',
        'text'    => 'Paragraph',
        'feature' => 'Feature card',
        'button'  => 'Button / link',
        'list'    => 'Bullet list (one per line)',
        'image'   => 'Image',
        'quote'   => 'Quote',
        'code'    => 'Code / command block',
        'divider' => 'Divider',
        'html'    => 'Raw HTML (trusted)',
    ];
}
function cms_block_kind(?string $k): string {
    $k = strtolower(trim((string)$k));
    return isset(cms_block_kinds()[$k]) ? $k : 'text';
}

/* ------------------------------------------------------------ page types -- */
/* What a page is for. 'landing' is the account's public front page, 'help'
   and 'terms' are surfaced inside that account's console (see /api/meta). */
function cms_page_types(): array {
    return [
        'landing' => 'Landing page',
        'help'    => 'Help / how-to',
        'terms'   => 'Terms & conditions',
        'faq'     => 'FAQ',
        'custom'  => 'Custom page',
    ];
}
function cms_page_type(?string $t): string {
    $t = strtolower(trim((string)$t));
    return isset(cms_page_types()[$t]) ? $t : 'custom';
}

/* -------------------------------------------------- account scope + rights -- */
/* Which content may this user manage?  '' = the platform website,
   'all' = every account (super admin browsing), else an account id. */
function cms_scope_account(?array $user): string {
    if (is_super($user)) {
        $a = trim((string)(qp('account_id') ?: bstr('account_id', '')));
        return $a === 'all' ? 'all' : $a;
    }
    need($user !== null && (string)$user['role'] === 'admin',
        'Only a platform or account administrator can manage website content', 403);
    need(!empty($user['account_id']), 'This user is not attached to an account', 403);
    return (string)$user['account_id'];
}
/* Platform content: super admin only. */
function cms_need_platform(?array $user): void {
    need(is_super($user), 'Only the platform super administrator can manage the platform website', 403);
}
/* An account page may be edited by the super admin or by that account's admin. */
function cms_may_edit(?array $user, array $row): bool {
    if (is_super($user)) return true;
    return $user !== null && (string)$user['role'] === 'admin'
        && (string)($row['account_id'] ?? '') !== ''
        && (string)$row['account_id'] === (string)$user['account_id'];
}
function cms_need_edit(?array $user, ?array $row): void {
    need($row !== null, 'That page no longer exists', 404);
    need(cms_may_edit($user, $row), 'You can only manage your own account\'s content', 403);
}
/* Slugs are unique per account (NULL account = the platform site). */
function cms_slug_taken(string $slug, string $accountId, string $exceptId = ''): bool {
    $st = db()->prepare('SELECT id FROM cms_pages WHERE slug = ? AND account_id <=> ? AND id <> ?');
    $st->execute([$slug, $accountId === '' ? null : $accountId, $exceptId]);
    return $st->fetch() !== false;
}
function cms_account_slug(string $accountId): string {
    static $cache = [];
    if ($accountId === '') return '';
    if (isset($cache[$accountId])) return $cache[$accountId];
    $st = db()->prepare('SELECT slug FROM accounts WHERE id = ?');
    $st->execute([$accountId]);
    return $cache[$accountId] = (string)($st->fetchColumn() ?: '');
}
function cms_account_name(string $accountId): string {
    if ($accountId === '') return (string)setting('platform_name', APP_NAME);
    $st = db()->prepare('SELECT name FROM accounts WHERE id = ?');
    $st->execute([$accountId]);
    return (string)($st->fetchColumn() ?: '');
}
/* Public address of a page: /p/<slug> for the platform, /p/<account>/<slug>
   for an account's own content. */
function cms_page_url(array $row): string {
    $acc = (string)($row['account_id'] ?? '');
    if ($acc === '') return '/p/' . $row['slug'];
    $slug = cms_account_slug($acc);
    return $slug === '' ? '/p/' . $row['slug'] : '/p/' . $slug . '/' . $row['slug'];
}
/* Accounts the caller may pick from (super admin only). */
function cms_account_options(?array $user): array {
    if (!is_super($user)) return [];
    try {
        $st = db()->query("SELECT id, name, slug, status FROM accounts
                           WHERE status <> 'deleted' ORDER BY name LIMIT 500");
        return array_map(fn($r) => ['id' => (string)$r['id'], 'name' => (string)$r['name'],
            'slug' => (string)$r['slug'], 'status' => (string)$r['status']], $st->fetchAll());
    } catch (Throwable $e) {
        return [];
    }
}
/* Typed links for the console of one account (used by /api/meta). */
function cms_account_links(string $accountId): array {
    if ($accountId === '') return [];
    $out = [];
    try {
        $st = db()->prepare("SELECT slug, title, type FROM cms_pages
                             WHERE account_id = ? AND status = 'published' AND type IN ('help','landing','terms')
                             ORDER BY sort_order, title LIMIT 20");
        $st->execute([$accountId]);
        $accSlug = cms_account_slug($accountId);
        if ($accSlug === '') return [];
        foreach ($st->fetchAll() as $r) {
            $type = cms_page_type((string)$r['type']);
            $key = $type . '_url';
            if (isset($out[$key])) continue;
            $out[$key] = '/p/' . $accSlug . '/' . $r['slug'];
            $out[$type . '_title'] = (string)$r['title'];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/* ----------------------------------------------------- mobile-app guard -- */
/* The Android client sends "KIVU-Tracker-Android/<version>" and every
   Android WebView also carries "; wv" in its user agent. Both are refused,
   so the CMS never opens inside the mobile app. A normal mobile *browser*
   is unaffected (public pages stay public). */
function cms_is_mobile_app(): bool {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return false;
    foreach (['kivu-tracker-android', 'kivutracker-android', 'kivu-app'] as $token) {
        if (stripos($ua, $token) !== false) return true;
    }
    if (stripos($ua, 'android') === false) return false;
    return stripos($ua, 'wv') !== false;
}
/* Called by every CMS handler: the API answers 403 for the mobile app. */
function cms_deny_app(): void {
    if (cms_is_mobile_app()) {
        throw new ApiError('The content manager is not available in the mobile app', 403);
    }
}

/* ------------------------------------------------------------- helpers -- */
function cms_slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
    return substr(trim($s, '-'), 0, 64);
}
function cms_text($v, int $max): string {
    return substr(trim((string)$v), 0, $max);
}
/* Only http(s), mailto:, tel: and site-relative links are accepted. */
function cms_url($v): string {
    $u = trim((string)$v);
    if ($u === '') return '';
    if (strlen($u) > 300) throw new ApiError('That link is too long (300 characters max)');
    if (strpos($u, '/') === 0) return $u;
    if (preg_match('#^https?://#i', $u)) return $u;
    if (preg_match('#^mailto:[^@\s]+@[^@\s]+$#i', $u)) return $u;
    if (preg_match('#^tel:\+?[0-9 ()-]{3,20}$#i', $u)) return $u;
    throw new ApiError('Links must start with / , http:// , https:// , mailto: or tel:');
}
function cms_page_row(?string $id): ?array {
    if ($id === null || $id === '') return null;
    $st = db()->prepare('SELECT * FROM cms_pages WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}
/* A page of the PLATFORM site by slug (account pages use cms_page_for). */
function cms_page_by_slug(string $slug): ?array {
    $st = db()->prepare('SELECT * FROM cms_pages WHERE slug = ? AND account_id IS NULL LIMIT 1');
    $st->execute([$slug]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}
function cms_blocks(string $pageId, bool $enabledOnly = false): array {
    $sql = 'SELECT * FROM cms_blocks WHERE page_id = ?'
        . ($enabledOnly ? ' AND enabled = 1' : '')
        . ' ORDER BY sort_order, created_at';
    $st = db()->prepare($sql);
    $st->execute([$pageId]);
    return $st->fetchAll();
}
/* Published pages that asked to be linked on the public site. $accountId ''
   = the platform site, else that account's own menu. */
function cms_nav_pages(string $accountId = ''): array {
    try {
        $st = db()->prepare("SELECT slug, title, type, account_id FROM cms_pages
                             WHERE status = 'published' AND show_in_nav = 1 AND account_id <=> ?
                             ORDER BY sort_order, title");
        $st->execute([$accountId === '' ? null : $accountId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];           /* table missing on an un-migrated install */
    }
}
/* One page of an account ('' = the platform site) by slug, or null. */
function cms_page_for(string $accountSlug, string $slug): ?array {
    $accountId = null;
    if ($accountSlug !== '') {
        $st = db()->prepare('SELECT id FROM accounts WHERE slug = ? LIMIT 1');
        $st->execute([$accountSlug]);
        $found = $st->fetchColumn();
        if ($found === false) return null;
        $accountId = (string)$found;
    }
    $st = db()->prepare('SELECT * FROM cms_pages WHERE slug = ? AND account_id <=> ? LIMIT 1');
    $st->execute([$slug, $accountId]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}
/* The published page of one type for an account (landing/help/terms/…). */
function cms_page_of_type(string $accountId, string $type): ?array {
    $st = db()->prepare("SELECT * FROM cms_pages
                         WHERE account_id <=> ? AND type = ? AND status = 'published'
                         ORDER BY sort_order, title LIMIT 1");
    $st->execute([$accountId === '' ? null : $accountId, cms_page_type($type)]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}
function cms_home_page(): ?array {
    try {
        $st = db()->query("SELECT * FROM cms_pages WHERE is_home = 1 AND status = 'published' LIMIT 1");
        $row = $st->fetch();
        return $row === false ? null : $row;
    } catch (Throwable $e) {
        return null;
    }
}
function cms_block_public(array $b): array {
    return [
        'id' => (string)$b['id'], 'kind' => cms_block_kind($b['kind'] ?? ''),
        'title' => (string)($b['title'] ?? ''), 'body' => (string)($b['body'] ?? ''),
        'url' => (string)($b['url'] ?? ''), 'sort_order' => (int)($b['sort_order'] ?? 0),
        'enabled' => (int)($b['enabled'] ?? 1),
    ];
}

/* ================================================================ routes == */
/* The manager routes are 'admin' level: the handlers decide what a caller may
   touch (cms_scope_account() forces an account admin into their own account,
   and platform-only operations require the super admin). Operators, viewers
   and resellers are refused inside the handlers. */
function routes_cms(): array {
    /* named captures: api_dispatch() only forwards named groups to the handler */
    $id = '(?P<id>[A-Za-z0-9_\-]{3,64})';
    $slug = '(?P<slug>[a-z0-9\-]{1,64})';
    return [
        ['GET',    '#^/cms/pages$#',                     'h_cms_pages',        'admin'],
        ['POST',   '#^/cms/pages$#',                     'h_cms_page_save',    'admin'],
        ['GET',    '#^/cms/page/' . $id . '$#',          'h_cms_page_get',     'admin'],
        ['PUT',    '#^/cms/page/' . $id . '$#',          'h_cms_page_save',    'admin'],
        ['POST',   '#^/cms/page/' . $id . '$#',          'h_cms_page_save',    'admin'],
        ['DELETE', '#^/cms/page/' . $id . '$#',          'h_cms_page_delete',  'admin'],
        ['POST',   '#^/cms/page/' . $id . '/blocks$#',   'h_cms_block_save',   'admin'],
        ['POST',   '#^/cms/page/' . $id . '/reorder$#',  'h_cms_reorder',      'admin'],
        ['PUT',    '#^/cms/block/' . $id . '$#',         'h_cms_block_save',   'admin'],
        ['POST',   '#^/cms/block/' . $id . '$#',         'h_cms_block_save',   'admin'],
        ['DELETE', '#^/cms/block/' . $id . '$#',         'h_cms_block_delete', 'admin'],
        ['GET',    '#^/cms/export$#',                    'h_cms_export',       'admin'],
        ['POST',   '#^/cms/import$#',                    'h_cms_import',       'admin'],
        ['POST',   '#^/cms/seed$#',                      'h_cms_seed',         'admin'],
        /* public read-only: the renderer and the public site use these */
        ['GET',    '#^/cms/public/pages$#',              'h_cms_public_pages', 'public'],
        ['GET',    '#^/cms/public/page/' . $slug . '$#', 'h_cms_public_page',  'public'],
        ['GET',    '#^/cms/public/type/(?P<type>[a-z]{3,16})$#', 'h_cms_public_type', 'public'],
    ];
}

/* ============================================================== handlers == */
function h_cms_pages($user, $p): array {
    cms_deny_app();
    ensure_schema();
    $scope = cms_scope_account($user);              /* '' | 'all' | account id */
    $type = trim((string)qp('type'));
    $asset = trim((string)qp('asset_type'));

    $where = [];
    $args = [];
    if ($scope !== 'all') {
        $where[] = 'account_id <=> ?';
        $args[] = $scope === '' ? null : $scope;
    }
    if ($type !== '' && isset(cms_page_types()[$type])) { $where[] = 'type = ?'; $args[] = $type; }
    if ($asset !== '') { $where[] = 'asset_type <=> ?'; $args[] = $asset === 'any' ? null : $asset; }
    $sql = 'SELECT * FROM cms_pages' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY is_home DESC, account_id, sort_order, title';
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    $counts = [];
    foreach (db()->query('SELECT page_id, COUNT(*) n FROM cms_blocks GROUP BY page_id')->fetchAll() as $r) {
        $counts[(string)$r['page_id']] = (int)$r['n'];
    }
    $pages = [];
    foreach ($rows as $r) {
        $acc = (string)($r['account_id'] ?? '');
        $pages[] = [
            'id' => (string)$r['id'], 'slug' => (string)$r['slug'], 'title' => (string)$r['title'],
            'type' => cms_page_type($r['type'] ?? 'custom'),
            'asset_type' => (string)($r['asset_type'] ?? ''),
            'account_id' => $acc, 'account_name' => $acc === '' ? '' : cms_account_name($acc),
            'account_slug' => $acc === '' ? '' : cms_account_slug($acc),
            'status' => (string)$r['status'], 'is_home' => (int)$r['is_home'],
            'show_in_nav' => (int)$r['show_in_nav'], 'meta_desc' => (string)($r['meta_desc'] ?? ''),
            'sort_order' => (int)$r['sort_order'], 'blocks' => $counts[(string)$r['id']] ?? 0,
            'updated_at' => (string)($r['updated_at'] ?? ''), 'updated_by' => (string)($r['updated_by'] ?? ''),
            'url' => cms_page_url($r),
            'editable' => cms_may_edit($user, $r),
        ];
    }
    return ['pages' => $pages, 'kinds' => cms_block_kinds(),
        'kinds_order' => array_keys(cms_block_kinds()),
        'page_types' => cms_page_types(),
        'page_types_order' => array_keys(cms_page_types()),
        'asset_types' => asset_types(),
        'accounts' => cms_account_options($user),
        'scope' => $scope,
        'can_platform' => is_super($user),
        'count' => count($pages)];
}

function h_cms_page_get($user, $p): array {
    cms_deny_app();
    ensure_schema();
    $row = cms_page_row((string)($p['id'] ?? ''));
    need($row !== null, 'That page no longer exists', 404);
    need(cms_may_edit($user, $row), 'You can only open your own account\'s content', 403);
    $blocks = [];
    foreach (cms_blocks((string)$row['id']) as $b) $blocks[] = cms_block_public($b);
    $acc = (string)($row['account_id'] ?? '');
    return ['page' => [
            'id' => (string)$row['id'], 'slug' => (string)$row['slug'], 'title' => (string)$row['title'],
            'type' => cms_page_type($row['type'] ?? 'custom'),
            'asset_type' => (string)($row['asset_type'] ?? ''),
            'account_id' => $acc, 'account_name' => $acc === '' ? '' : cms_account_name($acc),
            'account_slug' => $acc === '' ? '' : cms_account_slug($acc),
            'status' => (string)$row['status'], 'is_home' => (int)$row['is_home'],
            'show_in_nav' => (int)$row['show_in_nav'], 'meta_desc' => (string)($row['meta_desc'] ?? ''),
            'sort_order' => (int)$row['sort_order'], 'updated_at' => (string)($row['updated_at'] ?? ''),
            'updated_by' => (string)($row['updated_by'] ?? ''), 'url' => cms_page_url($row),
        ],
        'blocks' => $blocks, 'kinds' => cms_block_kinds(),
        'page_types' => cms_page_types(), 'asset_types' => asset_types(),
        'editable' => true, 'can_platform' => is_super($user)];
}


/* Create (POST /cms/pages) or update (PUT /cms/page/<id>). */
function h_cms_page_save($user, $p): array {
    cms_deny_app();
    ensure_schema();
    $id = (string)($p['id'] ?? '');
    $row = $id === '' ? null : cms_page_row($id);
    if ($id !== '') cms_need_edit($user, $row);          /* 404 + 403 */

    /* which account owns this page? '' = the platform website */
    if ($row !== null) {
        $accountId = (string)($row['account_id'] ?? '');
        if (is_super($user)) {                            /* super admin may move it */
            $want = trim((string)bval('account_id', $accountId));
            $accountId = ($want === 'platform' || $want === 'all') ? '' : $want;
        }
    } else {
        $accountId = is_super($user)
            ? trim((string)bval('account_id', cms_scope_account($user)))
            : (string)$user['account_id'];
        if ($accountId === 'platform' || $accountId === 'all') $accountId = '';
    }
    if ($accountId !== '') {
        need(cms_account_name($accountId) !== '', 'Unknown account', 404);
    }

    $title = cms_text(bval('title', $row['title'] ?? ''), 160);
    need($title !== '', 'Give the page a title');
    $slug = cms_slugify((string)bval('slug', $row['slug'] ?? $title));
    if ($slug === '') $slug = cms_slugify($title);
    need(preg_match('/^[a-z0-9\-]{1,64}$/', $slug) === 1,
        'The address can use lowercase letters, numbers and dashes only');
    $type = cms_page_type((string)bval('type', $row['type'] ?? 'custom'));
    $assetType = asset_type_valid((string)bval('asset_type', $row['asset_type'] ?? ''));
    $status = (string)bval('status', $row['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'published'], true)) $status = 'draft';
    $isHome = (int)(bool)bval('is_home', (bool)($row['is_home'] ?? 0));
    /* only the platform front page exists, and only the super admin sets it */
    if ($accountId !== '' || !is_super($user)) $isHome = 0;
    $inNav = (int)(bool)bval('show_in_nav', (bool)($row['show_in_nav'] ?? 0));
    $meta = cms_text(bval('meta_desc', $row['meta_desc'] ?? ''), 300);
    $sort = (int)bval('sort_order', $row['sort_order'] ?? 100);
    if ($sort < 0 || $sort > 9999) $sort = 100;

    /* the slug is the public address: it must stay unique inside the account */
    need(!cms_slug_taken($slug, $accountId, $id),
        'Another page of this account already uses ' . ($accountId === '' ? '/p/' : '/p/…/') . $slug);

    /* only one page can own the platform front page */
    if ($isHome === 1) {
        db()->prepare('UPDATE cms_pages SET is_home = 0 WHERE id <> ?')->execute([$id]);
    }
    if ($row === null) {
        $id = 'cms_' . bin2hex(random_bytes(6));
        db()->prepare('INSERT INTO cms_pages (id,account_id,slug,title,type,asset_type,status,is_home,
                       show_in_nav,meta_desc,sort_order,created_at,updated_at,updated_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $accountId === '' ? null : $accountId, $slug, $title, $type,
                $assetType === '' ? null : $assetType, $status, $isHome, $inNav, $meta, $sort,
                now_dt(), now_dt(), (string)$user['username']]);
        audit('cms_page_created', 'cms_page', $id, ['slug' => $slug, 'title' => $title,
            'type' => $type, 'account_id' => $accountId]);
        $message = 'Page created';
    } else {
        db()->prepare('UPDATE cms_pages SET account_id = ?, slug = ?, title = ?, type = ?, asset_type = ?,
                       status = ?, is_home = ?, show_in_nav = ?, meta_desc = ?, sort_order = ?,
                       updated_at = ?, updated_by = ? WHERE id = ?')
            ->execute([$accountId === '' ? null : $accountId, $slug, $title, $type,
                $assetType === '' ? null : $assetType, $status, $isHome, $inNav, $meta, $sort,
                now_dt(), (string)$user['username'], $id]);
        audit('cms_page_updated', 'cms_page', $id, ['slug' => $slug, 'status' => $status,
            'type' => $type, 'account_id' => $accountId]);
        $message = 'Page saved';
    }
    return h_cms_page_get($user, ['id' => $id]) + ['message' => $message];
}

function h_cms_page_delete($user, $p): array {
    cms_deny_app();
    ensure_schema();
    $row = cms_page_row((string)($p['id'] ?? ''));
    cms_need_edit($user, $row);
    db()->prepare('DELETE FROM cms_blocks WHERE page_id = ?')->execute([(string)$row['id']]);
    db()->prepare('DELETE FROM cms_pages WHERE id = ?')->execute([(string)$row['id']]);
    audit('cms_page_deleted', 'cms_page', (string)$row['id'],
        ['slug' => (string)$row['slug'], 'title' => (string)$row['title']]);
    return ['deleted' => (string)$row['id'], 'message' => 'Page deleted'];
}


/* Create a block (POST /cms/page/<id>/blocks) or update one (PUT /cms/block/<id>). */
function h_cms_block_save($user, $p): array {
    cms_deny_app();
    ensure_schema();
    $isBlockRoute = strpos(api_path(), '/cms/block/') === 0;
    if ($isBlockRoute) {
        $st = db()->prepare('SELECT * FROM cms_blocks WHERE id = ?');
        $st->execute([(string)($p['id'] ?? '')]);
        $row = $st->fetch();
        need($row !== false, 'That block no longer exists', 404);
        $pageId = (string)$row['page_id'];
        cms_need_edit($user, cms_page_row($pageId));
    } else {
        $row = null;
        $pageId = (string)($p['id'] ?? '');
        cms_need_edit($user, cms_page_row($pageId));
    }

    $kind = cms_block_kind((string)bval('kind', $row['kind'] ?? 'text'));
    $title = cms_text(bval('title', $row['title'] ?? ''), 200);
    $body = cms_text(bval('body', $row['body'] ?? ''), 20000);
    $url = cms_url(bval('url', $row['url'] ?? ''));
    $enabled = (int)(bool)bval('enabled', (bool)($row['enabled'] ?? 1));

    /* sensible per-kind requirements, so a published page never renders empty */
    if (in_array($kind, ['heading', 'feature', 'quote'], true)) {
        need($title !== '' || $body !== '', 'This block needs a title or some text');
    } elseif ($kind === 'button') {
        need($title !== '' || $body !== '', 'The button needs a label');
        need($url !== '', 'The button needs a link (https://… or /page)');
    } elseif ($kind === 'image') {
        need($url !== '', 'The image needs a source URL (https://… or /img/…)');
    } elseif ($kind !== 'divider') {
        need($body !== '', 'This block needs some content');
    }

    if ($row === null) {
        $next = (int)db()->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM cms_blocks
                                  WHERE page_id = ' . db()->quote($pageId))->fetchColumn();
        $id = 'cblk_' . bin2hex(random_bytes(6));
        db()->prepare('INSERT INTO cms_blocks (id,page_id,kind,title,body,url,sort_order,enabled,created_at,updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $pageId, $kind, $title, $body, $url, $next, $enabled, now_dt(), now_dt()]);
        audit('cms_block_created', 'cms_block', $id, ['page_id' => $pageId, 'kind' => $kind]);
        $message = 'Block added';
    } else {
        $id = (string)$row['id'];
        $sort = (int)bval('sort_order', $row['sort_order'] ?? 100);
        db()->prepare('UPDATE cms_blocks SET kind = ?, title = ?, body = ?, url = ?, sort_order = ?,
                       enabled = ?, updated_at = ? WHERE id = ?')
            ->execute([$kind, $title, $body, $url, $sort, $enabled, now_dt(), $id]);
        audit('cms_block_updated', 'cms_block', $id, ['kind' => $kind]);
        $message = 'Block saved';
    }
    db()->prepare('UPDATE cms_pages SET updated_at = ?, updated_by = ? WHERE id = ?')
        ->execute([now_dt(), (string)$user['username'], $pageId]);
    $fresh = h_cms_page_get($user, ['id' => $pageId]);
    return ['block' => ['id' => $id, 'kind' => $kind, 'title' => $title, 'body' => $body,
            'url' => $url, 'enabled' => $enabled],
        'page' => $fresh['page'], 'blocks' => $fresh['blocks'], 'message' => $message];
}

function h_cms_block_delete($user, $p): array {
    cms_deny_app();
    ensure_schema();
    $st = db()->prepare('SELECT * FROM cms_blocks WHERE id = ?');
    $st->execute([(string)($p['id'] ?? '')]);
    $row = $st->fetch();
    need($row !== false, 'That block no longer exists', 404);
    cms_need_edit($user, cms_page_row((string)$row['page_id']));
    db()->prepare('DELETE FROM cms_blocks WHERE id = ?')->execute([(string)$row['id']]);
    db()->prepare('UPDATE cms_pages SET updated_at = ?, updated_by = ? WHERE id = ?')
        ->execute([now_dt(), (string)$user['username'], (string)$row['page_id']]);
    audit('cms_block_deleted', 'cms_block', (string)$row['id'], ['page_id' => (string)$row['page_id']]);
    return ['deleted' => (string)$row['id'], 'message' => 'Block deleted',
        'blocks' => h_cms_page_get($user, ['id' => (string)$row['page_id']])['blocks']];
}

/* POST /cms/page/<id>/reorder  { "ids": ["cblk_a", "cblk_b", …] } */
function h_cms_reorder($user, $p): array {
    cms_deny_app();
    ensure_schema();
    $pageId = (string)($p['id'] ?? '');
    cms_need_edit($user, cms_page_row($pageId));
    $ids = bval('ids', []);
    need(is_array($ids) && $ids, 'Send the block ids in their new order');
    $own = [];
    foreach (cms_blocks($pageId) as $b) $own[(string)$b['id']] = true;
    $order = 10;
    $moved = 0;
    $upd = db()->prepare('UPDATE cms_blocks SET sort_order = ? WHERE id = ? AND page_id = ?');
    foreach ($ids as $id) {
        $id = (string)$id;
        if (!isset($own[$id])) continue;          /* ignore foreign/unknown ids */
        $upd->execute([$order, $id, $pageId]);
        $order += 10;
        $moved++;
    }
    db()->prepare('UPDATE cms_pages SET updated_at = ?, updated_by = ? WHERE id = ?')
        ->execute([now_dt(), (string)$user['username'], $pageId]);
    audit('cms_blocks_reordered', 'cms_page', $pageId, ['moved' => $moved]);
    return ['moved' => $moved, 'blocks' => h_cms_page_get($user, ['id' => $pageId])['blocks'],
        'message' => 'Order saved'];
}


/* ------------------------------------------------ backup, restore, starter */
function h_cms_export($user, $p): array {
    cms_deny_app();
    ensure_schema();
    /* a super admin exports everything (or the selected scope); an account
       admin exports their own account only */
    $scope = cms_scope_account($user);
    $where = '';
    $args = [];
    if ($scope !== 'all') {
        $where = ' WHERE account_id <=> ?';
        $args[] = $scope === '' ? null : $scope;
    }
    $st = db()->prepare('SELECT * FROM cms_pages' . $where . ' ORDER BY account_id, sort_order, title');
    $st->execute($args);
    $pages = [];
    foreach ($st->fetchAll() as $r) {
        $blocks = [];
        foreach (cms_blocks((string)$r['id']) as $b) {
            $blocks[] = ['kind' => cms_block_kind($b['kind'] ?? ''), 'title' => (string)($b['title'] ?? ''),
                'body' => (string)($b['body'] ?? ''), 'url' => (string)($b['url'] ?? ''),
                'sort_order' => (int)$b['sort_order'], 'enabled' => (int)$b['enabled']];
        }
        $pages[] = ['slug' => (string)$r['slug'], 'title' => (string)$r['title'],
            'type' => cms_page_type($r['type'] ?? 'custom'),
            'asset_type' => (string)($r['asset_type'] ?? ''),
            'account' => (string)($r['account_id'] ?? '') === '' ? ''
                : cms_account_slug((string)$r['account_id']),
            'status' => (string)$r['status'], 'is_home' => (int)$r['is_home'],
            'show_in_nav' => (int)$r['show_in_nav'], 'meta_desc' => (string)($r['meta_desc'] ?? ''),
            'sort_order' => (int)$r['sort_order'], 'blocks' => $blocks];
    }
    audit('cms_exported', 'cms_page', null, ['pages' => count($pages)]);
    return ['export' => ['version' => 1, 'exported_at' => now_dt(), 'pages' => $pages],
        'count' => count($pages)];
}

/* Restores an export payload; pages are matched by (account, slug) and replaced. */
function h_cms_import($user, $p): array {
    cms_deny_app();
    ensure_schema();
    $data = bval('export', null);
    if (!is_array($data)) $data = body();
    $pages = is_array($data['pages'] ?? null) ? $data['pages'] : null;
    need(is_array($pages) && $pages, 'Send an export payload ({"export":{"pages":[…]}})');
    $created = 0; $updated = 0; $blocks = 0;
    foreach ($pages as $pg) {
        if (!is_array($pg)) continue;
        $slug = cms_slugify((string)($pg['slug'] ?? ''));
        if ($slug === '') continue;
        /* which account does this page belong to? */
        $accountSlug = cms_slugify((string)($pg['account'] ?? ''));
        $accountId = '';
        if ($accountSlug !== '') {
            $st = db()->prepare('SELECT id FROM accounts WHERE slug = ? LIMIT 1');
            $st->execute([$accountSlug]);
            $accountId = (string)($st->fetchColumn() ?: '');
            if ($accountId === '') continue;                     /* unknown account: skip */
        }
        /* an account admin can only import into their own account */
        if (!is_super($user)) {
            $accountId = (string)$user['account_id'];
            if ($accountId === '') continue;
        }
        $title = cms_text($pg['title'] ?? $slug, 160);
        if ($title === '') $title = $slug;
        $type = cms_page_type($pg['type'] ?? 'custom');
        $assetType = asset_type_valid((string)($pg['asset_type'] ?? ''));
        $status = in_array((string)($pg['status'] ?? ''), ['published', 'draft'], true)
            ? (string)$pg['status'] : 'draft';
        $existing = cms_page_for($accountSlug, $slug);
        $id = $existing === null ? 'cms_' . bin2hex(random_bytes(6)) : (string)$existing['id'];
        $isHome = ($accountId === '' && is_super($user) && (int)($pg['is_home'] ?? 0) === 1) ? 1 : 0;
        if ($isHome === 1) db()->prepare('UPDATE cms_pages SET is_home = 0 WHERE id <> ?')->execute([$id]);
        if ($existing === null) {
            db()->prepare('INSERT INTO cms_pages (id,account_id,slug,title,type,asset_type,status,is_home,
                           show_in_nav,meta_desc,sort_order,created_at,updated_at,updated_by)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $accountId === '' ? null : $accountId, $slug, $title, $type,
                    $assetType === '' ? null : $assetType, $status, $isHome, (int)($pg['show_in_nav'] ?? 0),
                    cms_text($pg['meta_desc'] ?? '', 300), (int)($pg['sort_order'] ?? 100),
                    now_dt(), now_dt(), (string)$user['username']]);
            $created++;
        } else {
            db()->prepare('UPDATE cms_pages SET title = ?, type = ?, asset_type = ?, status = ?, is_home = ?,
                           show_in_nav = ?, meta_desc = ?, sort_order = ?, updated_at = ?, updated_by = ?
                           WHERE id = ?')
                ->execute([$title, $type, $assetType === '' ? null : $assetType, $status, $isHome,
                    (int)($pg['show_in_nav'] ?? 0), cms_text($pg['meta_desc'] ?? '', 300),
                    (int)($pg['sort_order'] ?? 100), now_dt(), (string)$user['username'], $id]);
            $updated++;
        }
        db()->prepare('DELETE FROM cms_blocks WHERE page_id = ?')->execute([$id]);
        $order = 10;
        foreach ((array)($pg['blocks'] ?? []) as $b) {
            if (!is_array($b)) continue;
            db()->prepare('INSERT INTO cms_blocks (id,page_id,kind,title,body,url,sort_order,enabled,
                           created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute(['cblk_' . bin2hex(random_bytes(6)), $id, cms_block_kind($b['kind'] ?? 'text'),
                    cms_text($b['title'] ?? '', 200), cms_text($b['body'] ?? '', 20000),
                    cms_text($b['url'] ?? '', 300), (int)($b['sort_order'] ?? $order),
                    (int)($b['enabled'] ?? 1) === 1 ? 1 : 0, now_dt(), now_dt()]);
            $order += 10;
            $blocks++;
        }
    }
    audit('cms_imported', 'cms_page', null, ['created' => $created, 'updated' => $updated, 'blocks' => $blocks]);
    return ['created' => $created, 'updated' => $updated, 'blocks' => $blocks,
        'message' => "Imported $created new page(s), updated $updated, $blocks block(s)"];
}


/* Inserts one page plus its blocks — used by the starter sets below. */
function cms_seed_page(string $accountId, string $slug, string $title, string $type,
                       int $inNav, int $isHome, array $blocks): string {
    $id = 'cms_' . bin2hex(random_bytes(6));
    db()->prepare('INSERT INTO cms_pages (id,account_id,slug,title,type,status,is_home,show_in_nav,
                   meta_desc,sort_order,created_at,updated_at,updated_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $accountId === '' ? null : $accountId, $slug, $title, $type, 'published',
            $isHome, $inNav, '', 100, now_dt(), now_dt(), 'seed']);
    $order = 10;
    $ins = db()->prepare('INSERT INTO cms_blocks (id,page_id,kind,title,body,url,sort_order,enabled,
                          created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,?,?)');
    foreach ($blocks as $b) {
        $ins->execute(['cblk_' . bin2hex(random_bytes(6)), $id, $b[0], $b[1], $b[2], $b[3], $order,
            now_dt(), now_dt()]);
        $order += 10;
    }
    return $id;
}

/* Starter content: the platform home page (when the platform site is empty)
   or a typed starter set for one account (landing + help + terms). */
function h_cms_seed($user, $p): array {
    cms_deny_app();
    ensure_schema();
    $scope = cms_scope_account($user);
    $accountId = ($scope === 'all') ? '' : $scope;
    if (!is_super($user)) $accountId = (string)$user['account_id'];
    if ($accountId === '' && !is_super($user)) {
        throw new ApiError('Only the platform super administrator can create the platform site', 403);
    }
    if ($accountId !== '') need(cms_account_name($accountId) !== '', 'Unknown account', 404);

    $st = db()->prepare('SELECT COUNT(*) FROM cms_pages WHERE account_id <=> ?');
    $st->execute([$accountId === '' ? null : $accountId]);
    $existing = (int)$st->fetchColumn();
    need($existing === 0, 'That site already has ' . $existing . ' page(s) — the starter is only for an empty site');

    $platform = (string)setting('platform_name', 'KIVU Tracker');

    /* ---- the platform website -------------------------------------------- */
    if ($accountId === '') {
        $id = cms_seed_page('', 'home', $platform . ' — GPS tracking and fleet management', 'landing', 0, 1, [
            ['heading', 'GPS tracking and fleet management, running on your own server.',
                'A complete, self-hosted fleet platform: a real Wialon IPS 1.1 TCP server for trackers, '
                . 'live monitoring, trip & parking detection, geofences, notification rules, fuel analysis '
                . 'and twelve report types with CSV export and printing.', ''],
            ['button', 'Start a 5-day free trial', '', '/register'],
            ['button', 'Open the monitoring console', '', '/app'],
            ['button', 'Sign in', '', '/login'],
            ['heading', 'Everything a fleet operator needs', '', ''],
            ['feature', 'Real device protocol',
                'Trackers connect to the built-in IPS server (#L# login, #D# data, #SD# messages, '
                . '#P# keep-alive) and receive commands back over the same link.', ''],
            ['feature', 'Monitoring built for real fleets',
                'Unit tree with status colours, live map, track playback with a timeline, trips, parkings, '
                . 'idle blocks, geofence enter-exit events and notification rules.', ''],
            ['feature', 'Reports you can hand to a client',
                'Twelve report types with charts, totals, CSV export and a printable layout.', ''],
            ['feature', 'Multi-account and roles',
                'Accounts with their own units, users, geofences and rules. Super admin, account admin, '
                . 'operator and viewer (a viewer can be limited to specific units).', ''],
            ['feature', 'No dependencies, no build step',
                'PHP 8 + MySQL/MariaDB + vanilla JavaScript. The map uses Leaflet when it can be '
                . 'downloaded and falls back to a built-in canvas map.', ''],
            ['feature', 'Tools included',
                'tools/simulator.php emulates a device, tools/gen_history.php creates demo history and '
                . 'tools/api-test.php runs an end-to-end API test suite.', ''],
        ]);
        audit('cms_seeded', 'cms_page', $id, ['account_id' => '', 'blocks' => 11]);
        return ['page_id' => $id, 'blocks' => 11, 'account_id' => '',
            'message' => 'Starter home page created — edit it, then it takes over the front page'];
    }

    /* ---- one account: landing + help + terms ------------------------------ */
    $name = cms_account_name($accountId);
    $ipsPort = (int)setting('ips_port', 20332);
    $support = (string)setting('support_email', '');
    $landing = cms_seed_page($accountId, 'landing', $name . ' — fleet tracking', 'landing', 0, 0, [
        ['heading', $name . ' — track your fleet live', '', ''],
        ['text', '', 'Welcome. Sign in to see your vehicles on the map, replay trips, check geofence '
            . 'events and export reports.', ''],
        ['button', 'Open the monitoring console', '', '/login'],
        ['button', 'Get the Android app', '', '/download'],
        ['heading', 'Your device settings', '', ''],
        ['feature', 'Server', 'Point your tracker at this platform (Wialon IPS 1.1, TCP port '
            . $ipsPort . ').', ''],
        ['feature', 'Login', 'Device login = the unit unique id, password = the unit device password '
            . '(set per unit in the console).', ''],
        ['feature', 'Support', $support !== '' ? $support : 'Contact your account administrator for help.', ''],
    ]);
    $help = cms_seed_page($accountId, 'help', 'Help & how-to', 'help', 1, 0, [
        ['heading', 'Getting started', '', ''],
        ['list', '', "1. Install the tracker (or the phone app) and power it up.\n"
            . "2. Point it at the platform: Wialon IPS 1.1 on TCP port $ipsPort.\n"
            . "3. Use the unit unique id as the device login and its device password.\n"
            . "4. The unit appears on the map within a minute.", ''],
        ['heading', 'Everyday tasks', '', ''],
        ['list', '', "Replay a trip: Monitoring -> pick the unit -> Track -> play.\n"
            . "Check a route: Monitoring -> Trips, or Reports -> Trips.\n"
            . "Draw a geofence: Administration -> Geofences -> draw on the map.\n"
            . "Export data: Reports -> choose a report -> CSV.", ''],
        ['heading', 'Still stuck?', '', ''],
        ['text', '', $support !== '' ? 'Write to ' . $support . ' with the unit name and the time of the '
            . 'problem.' : 'Contact your account administrator with the unit name and the time of the problem.', ''],
    ]);
    $terms = cms_seed_page($accountId, 'terms', 'Terms & conditions', 'terms', 1, 0, [
        ['heading', 'Terms & conditions', '', ''],
        ['text', '', 'Replace this starter text with your own terms: service scope, data retention, '
            . 'privacy, device installation responsibility and the billing conditions.', ''],
        ['divider', '', '', ''],
        ['text', '', 'Last updated ' . date('j F Y') . '.', ''],
    ]);
    audit('cms_seeded', 'cms_page', $landing, ['account_id' => $accountId, 'pages' => 3]);
    return ['page_id' => $landing, 'help_id' => $help, 'terms_id' => $terms,
        'account_id' => $accountId, 'blocks' => 18,
        'message' => 'Starter landing, help and terms pages created for ' . $name];
}

/* -------------------------------------------------------- public (read) -- */
function h_cms_public_pages($user, $p): array {
    $accountSlug = cms_slugify((string)qp('account'));
    $accountId = '';
    if ($accountSlug !== '') {
        $st = db()->prepare('SELECT id FROM accounts WHERE slug = ? LIMIT 1');
        $st->execute([$accountSlug]);
        $accountId = (string)($st->fetchColumn() ?: '');
        if ($accountId === '') throw new ApiError('Unknown account', 404);
    }
    $home = $accountId === '' ? cms_home_page() : cms_page_of_type($accountId, 'landing');
    return ['pages' => array_map(fn($r) => ['slug' => (string)$r['slug'], 'title' => (string)$r['title'],
            'type' => cms_page_type($r['type'] ?? 'custom')], cms_nav_pages($accountId)),
        'home_slug' => $home === null ? '' : (string)$home['slug'],
        'account' => $accountSlug];
}

function h_cms_public_page($user, $p): array {
    $accountSlug = cms_slugify((string)qp('account'));
    $row = cms_page_for($accountSlug, (string)($p['slug'] ?? ''));
    if ($row === null || (string)$row['status'] !== 'published') {
        throw new ApiError('That page is not published', 404);
    }
    $blocks = [];
    foreach (cms_blocks((string)$row['id'], true) as $b) $blocks[] = cms_block_public($b);
    return ['page' => ['slug' => (string)$row['slug'], 'title' => (string)$row['title'],
            'type' => cms_page_type($row['type'] ?? 'custom'),
            'meta_desc' => (string)($row['meta_desc'] ?? ''), 'is_home' => (int)$row['is_home'],
            'account' => $accountSlug, 'url' => cms_page_url($row),
            'updated_at' => (string)($row['updated_at'] ?? '')],
        'blocks' => $blocks];
}

/* GET /cms/public/type/<type>?account=<slug> — the published page of one type
   (what the console uses for its help / terms links). */
function h_cms_public_type($user, $p): array {
    $type = cms_page_type((string)($p['type'] ?? 'custom'));
    $accountSlug = cms_slugify((string)qp('account'));
    $accountId = '';
    if ($accountSlug !== '') {
        $st = db()->prepare('SELECT id FROM accounts WHERE slug = ? LIMIT 1');
        $st->execute([$accountSlug]);
        $accountId = (string)($st->fetchColumn() ?: '');
        if ($accountId === '') throw new ApiError('Unknown account', 404);
    }
    $row = cms_page_of_type($accountId, $type);
    if ($row === null) throw new ApiError('No published ' . $type . ' page', 404);
    $blocks = [];
    foreach (cms_blocks((string)$row['id'], true) as $b) $blocks[] = cms_block_public($b);
    return ['page' => ['slug' => (string)$row['slug'], 'title' => (string)$row['title'], 'type' => $type,
            'url' => cms_page_url($row), 'account' => $accountSlug], 'blocks' => $blocks];
}
