<?php
/* =====================================================================
   KIVU Tracker — php/routes_admin.php
   Platform administration: accounts (resources) and platform settings.
   ===================================================================== */
declare(strict_types=1);

function routes_admin(): array {
    $id = '(?P<id>[A-Za-z0-9_\-]{3,64})';
    return [
        ['GET', '#^/accounts$#', 'h_accounts_list', 'super'],
        ['POST', '#^/accounts$#', 'h_account_save', 'super'],
        ['PUT', '#^/accounts/' . $id . '$#', 'h_account_save', 'super'],
        ['DELETE', '#^/accounts/' . $id . '$#', 'h_account_delete', 'super'],
        ['GET', '#^/accounts/' . $id . '/summary$#', 'h_account_summary', 'super'],
        ['GET', '#^/settings$#', 'h_settings_get', 'admin'],
        ['PUT', '#^/settings$#', 'h_settings_save', 'super'],
        ['POST', '#^/settings$#', 'h_settings_save', 'super'],
        /* the platform logo: multipart upload + removal */
        ['POST', '#^/settings/logo$#', 'h_logo_upload', 'super'],
        ['DELETE', '#^/settings/logo$#', 'h_logo_delete', 'super'],
        /* the sign-in page's left panel: picture/video, uploaded in pieces */
        ['POST', '#^/settings/login-hero/chunk$#', 'h_login_hero_chunk', 'super'],
        ['POST', '#^/settings/login-hero/finish$#', 'h_login_hero_finish', 'super'],
        ['DELETE', '#^/settings/login-hero$#', 'h_login_hero_delete', 'super'],
    ];
}

function h_accounts_list($user, $p): array {
    $rows = db()->query("SELECT a.*, (SELECT COUNT(*) FROM units u WHERE u.account_id = a.id) AS units,
        (SELECT COUNT(*) FROM users us WHERE us.account_id = a.id) AS users,
        (SELECT COUNT(*) FROM geofences g WHERE g.account_id = a.id) AS geofences,
        (SELECT MAX(m.ts) FROM messages m JOIN units u2 ON u2.id = m.unit_id WHERE u2.account_id = a.id) AS last_data
        FROM accounts a ORDER BY a.name")->fetchAll();
    return ['accounts' => array_map(function ($a) {
        $state = billing_state((string)$a['id'], $a);
        return ['id' => $a['id'], 'name' => $a['name'], 'slug' => $a['slug'],
        'plan' => $a['plan'], 'plan_id' => $a['plan_id'], 'units_limit' => (int)$a['units_limit'],
        'storage_days' => (int)$a['storage_days'],
        'currency' => $a['currency'], 'timezone' => $a['timezone'], 'contact_email' => $a['contact_email'],
        'enabled' => (int)$a['enabled'], 'created_at' => iso($a['created_at']),
        'units' => (int)$a['units'], 'users' => (int)$a['users'], 'geofences' => (int)$a['geofences'],
        'last_data' => iso($a['last_data']),
        /* billing snapshot so the console can show trial/expiry at a glance */
        'status' => $state['status'], 'billing_allowed' => $state['allowed'],
        'trial_ends_at' => $state['trial_ends_at'], 'trial_days_left' => $state['trial_days_left'],
        'period_end' => $state['period_end'], 'days_left' => $state['days_left'],
        'signup_source' => $a['signup_source'], 'gateway' => $state['gateway']];
    }, $rows)];
}

function h_account_save($user, $p): array {
    $id = (string)($p['id'] ?? '');
    $name = bstr('name', '');
    need($name !== '', 'Account name is required');
    $slug = bstr('slug', '');
    if ($slug === '') $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
    $fields = ['name' => $name, 'slug' => $slug, 'plan' => bstr('plan', 'standard'),
        'units_limit' => max(1, bint('units_limit', 50)), 'storage_days' => max(1, bint('storage_days', 90)),
        'currency' => bstr('currency', 'USD'), 'timezone' => bstr('timezone', 'Africa/Nairobi'),
        'contact_email' => bstr('contact_email', '') ?: null, 'enabled' => bint('enabled', 1) ? 1 : 0];
    /* optional plan + free trial (super admin creating a client account) */
    $planRow = null;
    if (bstr('plan_id', '') !== '') {
        $planRow = plan_by_id(bstr('plan_id'));
        need($planRow, 'Plan not found', 404);
    } elseif (bstr('plan_code', '') !== '') {
        $planRow = plan_by_code(bstr('plan_code'));
        need($planRow, 'Plan not found', 404);
    }
    $trialDays = bval('trial_days', null) !== null ? max(0, bint('trial_days', trial_days())) : trial_days();
    $status = bstr('status', '');
    if (!in_array($status, ['trialing', 'active', 'comp', 'suspended', 'expired'], true)) $status = 'trialing';
    if ($id === '') {
        if ($planRow) {
            $fields['plan'] = $planRow['code'];
            $fields['plan_id'] = $planRow['id'];
            if (bval('units_limit', null) === null) $fields['units_limit'] = (int)$planRow['unit_limit'];
            if (bval('storage_days', null) === null) $fields['storage_days'] = (int)$planRow['storage_days'];
            if (bval('currency', null) === null) $fields['currency'] = $planRow['currency'];
        }
        $fields['status'] = $status;
        if ($status === 'trialing') $fields['trial_ends_at'] = date('Y-m-d H:i:s', time() + $trialDays * 86400);
        $fields['signup_source'] = 'admin';
        $fields['contact_name'] = bstr('contact_name', '') ?: null;
        $fields['contact_phone'] = bstr('contact_phone', '') ?: null;
        $fields['billing_email'] = bstr('contact_email', '') ?: null;
        $fields['notes'] = bstr('notes', '') ?: null;
        $st = db()->prepare('SELECT id FROM accounts WHERE slug = ?');
        $st->execute([$slug]);
        need(!$st->fetch(), 'Another account already uses that slug');
        $id = uid('acc');
        $fields['id'] = $id;
        $fields['created_at'] = now_dt();
        $cols = array_keys($fields);
        db()->prepare('INSERT INTO accounts (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($fields));
        audit('account_created', 'account', $id, ['name' => $name]);
        /* the free trial lives in `subscriptions` so the billing screens agree */
        if (($fields['status'] ?? 'trialing') === 'trialing') subscription_start_trial($id, $trialDays);
        /* optional first account administrator */
        $adminUser = bstr('admin_username', '');
        $adminPass = (string)bval('admin_password', '');
        $created = null;
        if ($adminUser !== '' && $adminPass !== '') {
            need(strlen($adminPass) >= 6, 'The account admin password must be at least 6 characters');
            $st = db()->prepare('SELECT id FROM users WHERE username = ?');
            $st->execute([$adminUser]);
            need(!$st->fetch(), 'That admin username is already taken');
            $adminId = uid('usr');
            db()->prepare('INSERT INTO users (id,account_id,username,email,full_name,role,salt,password_hash,enabled,created_at)
                           VALUES (?,?,?,?,?,?,?,?,1,?)')
                ->execute([$adminId, $id, $adminUser, bstr('admin_email', '') ?: null,
                    bstr('admin_full_name', '') ?: null, 'admin', bin2hex(random_bytes(8)),
                    password_hash($adminPass, PASSWORD_BCRYPT), now_dt()]);
            $created = $adminUser;
        }
        return ['account_id' => $id, 'admin_username' => $created, 'message' => 'Account created',
            'billing' => billing_state($id)];
    }
    $st = db()->prepare('SELECT * FROM accounts WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    need($a, 'Account not found', 404);
    $st = db()->prepare('SELECT id FROM accounts WHERE slug = ? AND id <> ?');
    $st->execute([$slug, $id]);
    need(!$st->fetch(), 'Another account already uses that slug');
    /* keep the trial end in sync when the status is set to trialing */
    if ($status === 'trialing' && empty($a['trial_ends_at'])) {
        $fields['trial_ends_at'] = date('Y-m-d H:i:s', time() + $trialDays * 86400);
    }
    $fields['status'] = $status;
    /* a blank form field must never null a NOT NULL column */
    foreach (['name', 'slug', 'plan', 'units_limit', 'storage_days', 'currency', 'timezone', 'enabled'] as $must) {
        if (array_key_exists($must, $fields) && $fields[$must] === null) unset($fields[$must]);
    }
    $sets = []; $vals = [];
    foreach ($fields as $k => $v) { $sets[] = "`$k` = ?"; $vals[] = $v; }
    $vals[] = $id;
    db()->prepare('UPDATE accounts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    audit('account_updated', 'account', $id, ['name' => $name, 'status' => $status]);
    return ['account_id' => $id, 'message' => 'Account updated', 'billing' => billing_state($id)];
}

function h_account_delete($user, $p): array {
    $id = (string)$p['id'];
    $st = db()->prepare('SELECT * FROM accounts WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    need($a, 'Account not found', 404);
    $st = db()->prepare('SELECT id FROM units WHERE account_id = ?');
    $st->execute([$id]);
    $ids = array_map(fn($r) => (string)$r['id'], $st->fetchAll());
    if ($ids && !bbool('force')) {
        throw new ApiError('This account still has ' . count($ids) . ' unit(s). Send force=1 to delete them as well.', 400);
    }
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (['messages', 'trips', 'sensors', 'maintenance', 'commands', 'geofence_events', 'events'] as $t) {
            db()->prepare("DELETE FROM $t WHERE unit_id IN ($in)")->execute($ids);
        }
    }
    foreach (['units', 'users', 'unit_groups', 'geofences', 'notifications', 'report_templates', 'drivers',
              'maintenance', 'events', 'geofence_events', 'commands', 'billing_reminders',
              'payment_methods'] as $t) {
        db()->prepare("DELETE FROM $t WHERE account_id = ?")->execute([$id]);
    }
    /* billing rows go with the account: invoices, subscription and the gateway
       callbacks that referenced its invoices (the audit log keeps the history) */
    $st = db()->prepare('SELECT id FROM payments WHERE account_id = ?');
    $st->execute([$id]);
    $payIds = array_map(fn($r) => (string)$r['id'], $st->fetchAll());
    if ($payIds) {
        $in = implode(',', array_fill(0, count($payIds), '?'));
        db()->prepare("DELETE FROM payment_events WHERE payment_id IN ($in)")->execute($payIds);
    }
    db()->prepare('DELETE FROM payments WHERE account_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM subscriptions WHERE account_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
    audit('account_deleted', 'account', $id, ['name' => $a['name'], 'units' => count($ids),
        'invoices' => count($payIds)]);
    return ['message' => 'Account deleted'];
}

function h_account_summary($user, $p): array {
    $id = (string)$p['id'];
    $st = db()->prepare('SELECT * FROM accounts WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    need($a, 'Account not found', 404);
    $units = units_list(['account_id' => $id], null);
    $byStatus = ['moving' => 0, 'idle' => 0, 'parked' => 0, 'offline' => 0, 'never' => 0];
    foreach ($units as $u) { $s = unit_state($u)['status']; $byStatus[$s] = ($byStatus[$s] ?? 0) + 1; }
    $st = db()->prepare('SELECT COUNT(*) FROM messages m JOIN units u ON u.id = m.unit_id WHERE u.account_id = ?');
    $st->execute([$id]);
    $messages = (int)$st->fetchColumn();
    $st = db()->prepare('SELECT COUNT(*) FROM messages m JOIN units u ON u.id = m.unit_id
                         WHERE u.account_id = ? AND m.ts >= ?');
    $st->execute([$id, date('Y-m-d H:i:s', time() - 86400)]);
    $messages24 = (int)$st->fetchColumn();
    $st = db()->prepare('SELECT COUNT(*) FROM sessions s JOIN users u ON u.id = s.user_id
                         WHERE u.account_id = ? AND s.expires_at > ?');
    $st->execute([$id, now_dt()]);
    $sessions = (int)$st->fetchColumn();
    return ['account' => ['id' => $a['id'], 'name' => $a['name'], 'slug' => $a['slug'], 'plan' => $a['plan'],
            'currency' => $a['currency'], 'timezone' => $a['timezone'], 'units_limit' => (int)$a['units_limit'],
            'storage_days' => (int)$a['storage_days'], 'enabled' => (int)$a['enabled']],
        'fleet' => ['total' => count($units)] + $byStatus,
        'counters' => ['messages' => $messages, 'messages_24h' => $messages24, 'active_sessions' => $sessions],
        'units' => array_map(fn($u) => unit_live_shape($u), $units)];
}

function h_settings_get($user, $p): array {
    $s = setting();
    unset($s['id'], $s['seed']);
    /* gateway credentials are platform secrets: super administrators only */
    if (!is_super($user)) unset($s['billing_json']);
    return ['settings' => $s];
}
/* ======================================================== platform logo === */
/* The super admin uploads a logo (or pastes a URL) and every console, the
   login/landing/sign-up/pay pages, the favicon and the payment e-mails pick it
   up. Uploaded files live under public/img/branding/ so they are served like
   any other static asset; replacing or removing a logo deletes the old file. */
function branding_logo_dir(): string {
    return dirname(__DIR__) . '/public/img/branding';
}
function branding_logo_max_bytes(): int { return 512 * 1024; }

/* Is this path one of our uploaded files? (only those may be deleted) */
function branding_is_local(string $url): bool {
    return strpos($url, '/img/branding/') === 0 && strpos($url, '..') === false;
}
function branding_delete_file(string $url): void {
    if (!branding_is_local($url)) return;
    $file = branding_logo_dir() . '/' . basename($url);
    if (is_file($file)) @unlink($file);
}
/* Validates + stores an uploaded file, returning the public URL. */
function branding_store_upload(array $file): string {
    $tmp = (string)($file['tmp_name'] ?? '');
    need($tmp !== '' && is_uploaded_file($tmp), 'No file was uploaded (multipart field name: "logo")');
    $size = (int)($file['size'] ?? 0);
    need($size > 0, 'The uploaded file is empty');
    need($size <= branding_logo_max_bytes(),
        'The logo is too large (' . round($size / 1024) . ' KB) — keep it under '
        . round(branding_logo_max_bytes() / 1024) . ' KB');
    $name = strtolower((string)($file['name'] ?? 'logo'));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowExt = ['png' => 'png', 'jpg' => 'jpg', 'jpeg' => 'jpg', 'webp' => 'webp', 'gif' => 'gif', 'svg' => 'svg'];
    need(isset($allowExt[$ext]), 'Use a PNG, JPG, WebP, GIF or SVG file');
    $info = @getimagesize($tmp);
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    if ($ext === 'svg') {
        /* SVG is served as an image but is really XML: refuse scripts/handlers */
        $raw = (string)@file_get_contents($tmp, false, null, 0, 200000);
        need(stripos($raw, '<svg') !== false, 'That file is not a valid SVG image');
        foreach (['<script', 'javascript:', 'onload=', 'onerror=', '<foreignobject', '<iframe'] as $bad) {
            need(stripos($raw, $bad) === false, 'The SVG contains active content (' . $bad . ') — export a plain image instead');
        }
    } else {
        need($mime !== '' && strpos($mime, 'image/') === 0, 'That file is not an image');
    }
    $dir = branding_logo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new ApiError('Cannot create ' . $dir . ' — check the folder permissions', 500);
    }
    $target = $dir . '/logo-' . substr(hash_file('sha256', $tmp), 0, 16) . '.' . $allowExt[$ext];
    need(@move_uploaded_file($tmp, $target) || @rename($tmp, $target),
        'Could not save the uploaded logo (check that public/img/branding is writable)', 500);
    @chmod($target, 0644);
    return '/img/branding/' . basename($target);
}
function h_logo_upload($user, $p): array {
    ensure_schema();
    $previous = (string)setting('logo_url', '');
    $url = branding_store_upload($_FILES['logo'] ?? []);
    db()->prepare('UPDATE settings SET logo_url = ? WHERE id = 1')->execute([$url]);
    if ($previous !== '' && $previous !== $url) branding_delete_file($previous);
    audit('platform_logo_set', 'settings', 'logo', ['url' => $url, 'by' => $user['username'],
        'bytes' => (int)($_FILES['logo']['size'] ?? 0)]);
    return ['logo_url' => $url, 'message' => 'Logo uploaded — it now appears in every console and e-mail.',
        'settings' => ['logo_url' => $url]];
}
function h_logo_delete($user, $p): array {
    ensure_schema();
    $previous = (string)setting('logo_url', '');
    db()->prepare('UPDATE settings SET logo_url = NULL WHERE id = 1')->execute();
    branding_delete_file($previous);
    audit('platform_logo_removed', 'settings', 'logo', ['url' => $previous, 'by' => $user['username']]);
    return ['logo_url' => '', 'message' => 'Logo removed — the default platform mark is back.',
        'settings' => ['logo_url' => '']];
}
/* ============================================ login page hero media === */
/* The sign-in page's left panel starts as artwork; the super admin can replace
   it with a picture or a video (Platform settings → Login page media). Whatever
   is set fills the panel edge to edge, and a video plays muted on a loop, so it
   never stops while somebody is on the page.

   Files arrive in 1 MB pieces and are appended under data/hero-upload/: the
   bundled web server runs `php -S`, whose upload_max_filesize is 2 MB, so a
   straight upload would fail for any real video. Only the finished file is moved
   into public/media/login/, which is served as a static asset (with byte-range
   support, so the browser can stream and loop it). */
function login_hero_dir(): string { return dirname(__DIR__) . '/public/media/login'; }
function login_hero_tmp_dir(): string { return APP_ROOT . '/data/hero-upload'; }
function login_hero_max_image_bytes(): int { return 8 * 1024 * 1024; }    /* 8 MB  */
function login_hero_max_video_bytes(): int { return 60 * 1024 * 1024; }   /* 60 MB */
function login_hero_chunk_bytes(): int { return 1024 * 1024; }            /* 1 MB  */
function login_hero_max_parts(): int { return 200; }
function login_hero_image_exts(): array { return ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg']; }
function login_hero_upload_id(): string {
    $id = strtolower(bstr('upload_id', ''));
    need(preg_match('/^[a-z0-9]{16,40}$/', $id) === 1, 'Bad upload id — start the upload again');
    return $id;
}
/* Is this path one of our uploaded hero files? (only those may be deleted) */
function login_hero_is_local(string $url): bool {
    return strpos($url, '/media/login/') === 0 && strpos($url, '..') === false;
}
function login_hero_delete_file(string $url): void {
    if (!login_hero_is_local($url)) return;
    $file = login_hero_dir() . '/' . basename($url);
    if (is_file($file)) @unlink($file);
}
/* Half-finished uploads never live longer than a day. */
function login_hero_tmp_purge(): void {
    $dir = login_hero_tmp_dir();
    if (!is_dir($dir)) return;
    $cut = time() - 86400;
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (@filemtime($f) < $cut) @unlink($f);
    }
}
/* A picture must really be a picture (SVG is XML, so it is screened). */
function login_hero_assert_image(string $file, string $ext): void {
    if ($ext === 'svg') {
        $raw = (string)@file_get_contents($file, false, null, 0, 200000);
        need(stripos($raw, '<svg') !== false, 'That file is not a valid SVG image');
        foreach (['<script', 'javascript:', 'onload=', 'onerror=', '<foreignobject', '<iframe'] as $bad) {
            need(stripos($raw, $bad) === false, 'The SVG contains active content (' . $bad . ') — export a plain image instead');
        }
        return;
    }
    $info = @getimagesize($file);
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    need($mime !== '' && strpos($mime, 'image/') === 0, 'That file is not an image — use PNG, JPG, WebP, GIF or SVG');
}
/* A video must carry a container signature (an extension alone proves nothing). */
function login_hero_assert_video(string $file, string $ext): void {
    $head = (string)@file_get_contents($file, false, null, 0, 64);
    need($head !== '', 'That video file is empty');
    $ok = strpos($head, 'ftyp') !== false                      // MP4, MOV, M4V
        || strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0         // WebM, MKV
        || strpos($head, 'OggS') === 0;                        // OGG
    need($ok, 'That file is not a playable MP4, WebM, OGG or MOV video — re-export it and try again');
}
/* Piece N of an upload: appended in order, so the server never holds the whole
   file in memory and no single request exceeds the server's upload limit. */
function h_login_hero_chunk($user, $p): array {
    $id = login_hero_upload_id();
    $index = bint('index', -1);
    $total = bint('total', 0);
    need($index >= 0, 'Missing piece number');
    need($total >= 1 && $total <= login_hero_max_parts(),
        'That file needs too many pieces (' . $total . ') — keep videos under '
        . round(login_hero_max_video_bytes() / 1048576) . ' MB');
    $file = $_FILES['media'] ?? null;
    need(is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK,
        'No piece was received (multipart field name: "media")');
    $tmp = (string)($file['tmp_name'] ?? '');
    need($tmp !== '' && is_uploaded_file($tmp), 'No piece was received');
    $size = (int)($file['size'] ?? 0);
    need($size > 0, 'An empty piece was received');
    need($size <= login_hero_chunk_bytes() + 262144, 'A piece is too large — send at most 1 MB per piece');

    $dir = login_hero_tmp_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new ApiError('Cannot create ' . $dir . ' — check the folder permissions', 500);
    }
    login_hero_tmp_purge();
    $metaFile = $dir . '/' . $id . '.json';
    $partFile = $dir . '/' . $id . '.part';
    $meta = is_file($metaFile) ? jdec((string)@file_get_contents($metaFile)) : [];
    if (!is_array($meta) || !$meta) {
        $meta = ['name' => substr(basename(bstr('filename', 'media')), 0, 120),
            'total' => $total, 'next' => 0, 'bytes' => 0, 'started' => time()];
    }
    $meta['total'] = $total;
    need($index === (int)$meta['next'], 'Piece ' . ($index + 1) . ' arrived out of order ('
        . ((int)$meta['next'] + 1) . ' was expected) — upload the file again');
    need((int)$meta['bytes'] + $size <= login_hero_max_video_bytes(),
        'That file is too large — images up to 8 MB, videos up to 60 MB');
    $out = @fopen($partFile, 'ab');
    need($out !== false, 'Cannot write ' . $partFile . ' — check that data/ is writable', 500);
    $in = @fopen($tmp, 'rb');
    if ($in === false) { fclose($out); throw new ApiError('Cannot read the uploaded piece', 500); }
    stream_copy_to_stream($in, $out);
    fclose($in); fclose($out);
    @unlink($tmp);
    $meta['next'] = $index + 1;
    $meta['bytes'] = (int)$meta['bytes'] + $size;
    @file_put_contents($metaFile, jenc($meta));
    return ['upload_id' => $id, 'received' => (int)$meta['next'], 'total' => $total,
        'bytes' => (int)$meta['bytes'],
        'percent' => (int)round((int)$meta['next'] * 100 / max(1, $total))];
}
/* Last piece arrived: validate the assembled file, publish it and make it the
   sign-in page media. The previous upload is deleted. */
function h_login_hero_finish($user, $p): array {
    ensure_schema();
    $id = login_hero_upload_id();
    $dir = login_hero_tmp_dir();
    $metaFile = $dir . '/' . $id . '.json';
    $partFile = $dir . '/' . $id . '.part';
    need(is_file($metaFile) && is_file($partFile), 'That upload is no longer on the server — start it again');
    $meta = jdec((string)@file_get_contents($metaFile));
    need((int)($meta['next'] ?? 0) === (int)($meta['total'] ?? -1) && (int)($meta['total'] ?? 0) > 0,
        'Not every piece arrived — upload the file again');
    $name = (string)($meta['name'] ?? 'media');
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    need(in_array($ext, array_merge(login_hero_image_exts(), login_hero_video_exts()), true),
        'Use a picture (PNG, JPG, WebP, GIF, SVG) or a video (MP4, WebM, OGG, MOV)');
    $kind = in_array($ext, login_hero_video_exts(), true) ? 'video' : 'image';
    $bytes = (int)filesize($partFile);
    $limit = $kind === 'video' ? login_hero_max_video_bytes() : login_hero_max_image_bytes();
    need($bytes <= $limit, 'That ' . $kind . ' is ' . round($bytes / 1048576, 1) . ' MB — the limit is '
        . round($limit / 1048576) . ' MB');
    if ($kind === 'video') login_hero_assert_video($partFile, $ext);
    else login_hero_assert_image($partFile, $ext);

    $store = login_hero_dir();
    if (!is_dir($store) && !@mkdir($store, 0775, true) && !is_dir($store)) {
        throw new ApiError('Cannot create ' . $store . ' — check the folder permissions', 500);
    }
    $savedExt = $ext === 'jpeg' ? 'jpg' : $ext;
    $target = $store . '/hero-' . substr(hash_file('sha256', $partFile), 0, 16) . '.' . $savedExt;
    need(@rename($partFile, $target) || (@copy($partFile, $target) && @unlink($partFile)),
        'Could not save the media (check that public/media/login is writable)', 500);
    @chmod($target, 0644);
    @unlink($metaFile);
    $url = '/media/login/' . basename($target);
    $previous = (string)setting('login_hero_url', '');
    settings_save(['login_hero_url' => $url, 'login_hero_type' => $kind]);
    if ($previous !== '' && $previous !== $url) login_hero_delete_file($previous);
    audit('login_hero_set', 'settings', 'login_hero', ['url' => $url, 'type' => $kind,
        'bytes' => $bytes, 'by' => $user['username']]);
    return ['login_hero' => login_hero_info(), 'message' => $kind === 'video'
        ? 'Video saved — it now fills the sign-in page and plays on a silent loop.'
        : 'Picture saved — it now fills the sign-in page panel.'];
}
function h_login_hero_delete($user, $p): array {
    ensure_schema();
    $previous = (string)setting('login_hero_url', '');
    settings_save(['login_hero_url' => null, 'login_hero_type' => 'image']);
    login_hero_delete_file($previous);
    foreach (glob(login_hero_tmp_dir() . '/*') ?: [] as $f) @unlink($f);
    audit('login_hero_removed', 'settings', 'login_hero', ['url' => $previous, 'by' => $user['username']]);
    return ['login_hero' => null, 'message' => 'Default artwork restored on the sign-in page.'];
}
function h_settings_save($user, $p): array {
    $allowed = ['platform_name', 'company_name', 'support_email', 'default_currency', 'map_tiles',
        'default_lat', 'default_lon', 'default_zoom', 'online_timeout_s', 'moving_speed_kmh', 'offline_alert_min',
        'retention_days', 'ips_port', 'theme_mode', 'portal_price',
        'apk_url', 'apk_version', 'apk_notes', 'logo_url', 'login_hero_url', 'powered_by'];
    $fields = [];
    foreach ($allowed as $k) {
        $v = bval($k, null);
        if ($v === null) continue;
        if (in_array($k, ['default_lat', 'default_lon', 'moving_speed_kmh'], true)) $fields[$k] = (float)$v;
        elseif ($k === 'offline_alert_min') $fields[$k] = max(0, min(1440, (int)$v));
        elseif (in_array($k, ['default_zoom', 'online_timeout_s', 'retention_days', 'ips_port'], true)) $fields[$k] = (int)$v;
        elseif ($k === 'portal_price') $fields[$k] = max(0, (float)$v);
        else $fields[$k] = trim((string)$v);
        if ($k === 'powered_by') {
            $chars = preg_match_all('/./us', $fields[$k]);
            need($chars !== false && $chars <= 180, 'Powered by text must be 180 characters or fewer');
        }
    }
    /* sign-in page media: a path (/media/login/…) or an http(s) URL. The type
       (image/video) follows the extension, and replacing it deletes our old file
       while an external URL is left alone. */
    if (array_key_exists('login_hero_url', $fields)) {
        $url = (string)$fields['login_hero_url'];
        need($url === '' || preg_match('#^(https?://[^\s]+|/[^\s]*)$#i', $url) === 1,
            'The sign-in page media must be an http(s) URL or a path starting with /');
        $previous = (string)setting('login_hero_url', '');
        if ($url !== $previous) login_hero_delete_file($previous);
        $fields['login_hero_url'] = $url === '' ? null : $url;
        $fields['login_hero_type'] = $url === '' ? 'image' : login_hero_type_of($url);
    }
    /* theme policy: everyone chooses, or the platform is locked to one theme */
    if (isset($fields['theme_mode'])
        && !in_array($fields['theme_mode'], ['user', 'dark', 'light'], true)) {
        $fields['theme_mode'] = 'user';
    }
    need($fields, 'Nothing to update');
    if (array_key_exists('platform_name', $fields) && $fields['platform_name'] === '') {
        throw new ApiError('Platform name cannot be empty');
    }
    settings_save($fields);
    audit('settings_updated', 'settings', null, array_keys($fields));
    $s = setting();
    unset($s['id'], $s['seed']);
    return ['settings' => $s, 'message' => 'Settings saved'];
}
