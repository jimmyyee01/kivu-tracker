<?php
/* =====================================================================
   KIVU Tracker — php/routes_portals.php
   Reseller portals: the super admin sells a portal for a FULL YEAR
   (settings.portal_price). The annual invoice is paid through the normal
   gateways (sandbox, cards, mobile money, bank…); when it is settled the
   portal activates for 365 days and its owner (role: reseller) signs in at
   /reseller to create and manage client accounts inside the portal.
   ===================================================================== */
declare(strict_types=1);

function routes_portals(): array {
    $id = '(?P<id>[A-Za-z0-9_\-]{3,64})';
    return [
        /* ---- super administrator: sell and manage portals ---------------- */
        ['GET',    '#^/portals$#', 'h_portals_list', 'super'],
        ['POST',   '#^/portals$#', 'h_portals_create', 'super'],
        ['POST',   '#^/portals/' . $id . '/renew$#', 'h_portals_renew', 'super'],
        ['POST',   '#^/portals/' . $id . '/billing$#', 'h_portals_billing', 'super'],
        ['DELETE', '#^/portals/' . $id . '$#', 'h_portals_delete', 'super'],
        /* ---- the reseller's own console ---------------------------------- */
        ['GET',    '#^/reseller/state$#', 'h_reseller_state', 'reseller'],
        ['POST',   '#^/reseller/accounts$#', 'h_reseller_account_create', 'reseller'],
    ];
}

/* The annual invoice: full-year pay, settled through any enabled gateway. */
function portal_checkout(array $portal, string $gateway, array $user): array {
    $acc = ['id' => (string)$portal['id'], 'name' => (string)$portal['name'],
        'currency' => (string)$portal['currency'], 'contact_name' => (string)$portal['name'],
        'billing_email' => (string)$portal['contact_email'], 'contact_phone' => null,
        'units_limit' => 0, 'plan_id' => null];
    return checkout_start($acc, null, $gateway, [
        'amount' => (float)$portal['price'], 'period_days' => 365,
        'phone' => bstr('phone', ''),
        'payer_email' => (string)$portal['contact_email'],
        'payer_name' => (string)$portal['name'],
        'save_method' => false,
        'meta' => ['portal_id' => (string)$portal['id']],
        'requested_by' => (string)($user['username'] ?? 'super')]);
}

function h_portals_list($user, $p): array {
    ensure_schema();
    return ['portals' => array_map('portal_shape',
        db()->query('SELECT * FROM portals ORDER BY created_at DESC')->fetchAll()),
        'portal_price' => portal_price()];
}
function h_portals_create($user, $p): array {
    ensure_schema();
    $name = bstr('name', '');
    need($name !== '', 'Portal name is required');
    $contact = trim(bstr('contact_email', ''));
    need($contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL) === $contact,
        'A valid contact e-mail is required');
    $ownerUser = trim(bstr('owner_username', ''));
    $ownerPass = (string)bval('owner_password', '');
    need(preg_match('/^[A-Za-z0-9._-]{3,60}$/', $ownerUser) === 1,
        'Owner username may only use letters, digits, dot, dash and underscore');
    need(strlen($ownerPass) >= 6, 'The owner password must be at least 6 characters');
    $price = portal_price();
    need($price > 0, 'Set the reseller portal price (full year) in Platform settings first', 400);
    $st = db()->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute([$ownerUser]);
    need(!$st->fetch(), 'That username is already taken');
    $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-')) ?: 'portal';
    $base = $slug; $n = 1;
    while (true) {
        $st = db()->prepare('SELECT id FROM portals WHERE slug = ?');
        $st->execute([$slug]);
        if (!$st->fetch()) break;
        $slug = $base . '-' . (++$n);
    }
    $id = uid('por');
    $currency = strtoupper(bstr('currency', setting('default_currency', 'USD')));
    db()->prepare('INSERT INTO portals (id,name,slug,owner_user_id,status,price,currency,
                   period_start,period_end,contact_email,created_at)
                   VALUES (?,?,?,NULL,?,?,?,NULL,NULL,?,?)')
        ->execute([$id, $name, $slug, 'pending', $price, $currency, $contact, now_dt()]);
    $ownerId = uid('usr');
    db()->prepare("INSERT INTO users (id,account_id,username,email,full_name,role,password_hash,salt,enabled,created_at)
                   VALUES (?,NULL,?,?,?,'reseller',?,?,1,?)")
        ->execute([$ownerId, $ownerUser, $contact, bstr('owner_full_name', '') ?: $name,
            password_hash($ownerPass, PASSWORD_BCRYPT), bin2hex(random_bytes(8)), now_dt()]);
    db()->prepare('UPDATE portals SET owner_user_id = ? WHERE id = ?')->execute([$ownerId, $id]);
    audit('portal_created', 'portal', $id, ['name' => $name, 'price' => $price,
        'owner' => $ownerUser, 'by' => $user['username']]);
    $portal = portal_by_id($id);
    $res = portal_checkout($portal, strtolower(bstr('gateway', 'sandbox')), $user);
    return ['portal' => portal_shape($portal), 'payment' => $res['payment'],
        'checkout_url' => $res['checkout_url'] ?? null,
        'message' => 'Portal created. The full year (' . $price . ' ' . $currency
            . ') must be paid before the reseller can use it.'];
}
function h_portals_renew($user, $p): array {
    $portal = portal_by_id((string)$p['id']);
    need($portal, 'Portal not found', 404);
    $res = portal_checkout($portal, strtolower(bstr('gateway', 'sandbox')), $user);
    return ['portal' => portal_shape($portal), 'payment' => $res['payment'],
        'checkout_url' => $res['checkout_url'] ?? null,
        'message' => 'Renewal invoice created — the year is extended once it is paid.'];
}
function h_portals_billing($user, $p): array {
    $portal = portal_by_id((string)$p['id']);
    need($portal, 'Portal not found', 404);
    $action = strtolower(bstr('action', ''));
    if ($action === 'expire') {
        db()->prepare('UPDATE portals SET status = ? WHERE id = ?')->execute(['expired', $portal['id']]);
        audit('portal_expired', 'portal', $portal['id'], ['by' => $user['username']]);
    } elseif ($action === 'suspend') {
        db()->prepare('UPDATE portals SET status = ? WHERE id = ?')->execute(['suspended', $portal['id']]);
        audit('portal_suspended', 'portal', $portal['id'], ['by' => $user['username']]);
    } elseif ($action === 'activate') {
        portal_activate((string)$portal['id'], 365);
    } else {
        throw new ApiError('Unknown portal action: ' . ($action === '' ? '(empty)' : $action), 400);
    }
    return ['portal' => portal_shape(portal_by_id((string)$portal['id'])),
        'message' => 'Portal updated (' . $action . ')'];
}
function h_portals_delete($user, $p): array {
    $portal = portal_by_id((string)$p['id']);
    need($portal, 'Portal not found', 404);
    $st = db()->prepare('SELECT COUNT(*) FROM accounts WHERE portal_id = ?');
    $st->execute([(string)$portal['id']]);
    need((int)$st->fetchColumn() === 0,
        'This portal still has client accounts — delete them first', 400);
    if (!empty($portal['owner_user_id'])) {
        db()->prepare('DELETE FROM users WHERE id = ? AND role = ?')
            ->execute([(string)$portal['owner_user_id'], 'reseller']);
    }
    db()->prepare('DELETE FROM portals WHERE id = ?')->execute([(string)$portal['id']]);
    audit('portal_deleted', 'portal', (string)$portal['id'], ['by' => $user['username']]);
    return ['message' => 'Portal deleted'];
}

/* ------------------------------ the reseller's own console -------------- */
function reseller_portal(array $user): array {
    $portal = portal_by_owner((string)$user['id']);
    need($portal, 'No reseller portal is attached to this account', 403);
    return $portal;
}
function h_reseller_state($user, $p): array {
    $portal = reseller_portal($user);
    $st = db()->prepare('SELECT * FROM accounts WHERE portal_id = ? ORDER BY created_at DESC');
    $st->execute([(string)$portal['id']]);
    $accounts = [];
    foreach ($st->fetchAll() as $a) {
        $state = billing_state((string)$a['id'], $a);
        $accounts[] = ['id' => $a['id'], 'name' => $a['name'], 'slug' => $a['slug'],
            'status' => $state['status'], 'plan' => $a['plan'], 'units_limit' => (int)$a['units_limit'],
            'units' => billing_units_used((string)$a['id']),
            'contact_email' => $a['contact_email'] ?? $a['billing_email'],
            'created_at' => iso($a['created_at']), 'trial_ends_at' => $state['trial_ends_at'],
            'period_end' => $state['period_end'], 'enabled' => (int)$a['enabled']];
    }
    return ['portal' => portal_shape($portal), 'accounts' => $accounts,
        'plans' => array_map('plan_public', plans_all(true)),
        'can_create' => (string)$portal['status'] === 'active'
            && !empty($portal['period_end']) && strtotime((string)$portal['period_end']) > time()];
}
function h_reseller_account_create($user, $p): array {
    ensure_schema();
    $portal = reseller_portal($user);
    $active = (string)$portal['status'] === 'active'
        && !empty($portal['period_end']) && strtotime((string)$portal['period_end']) > time();
    need($active,
        'This portal has not been paid for a full year (or the year has ended) — contact the platform'
        . ' administrator to renew it.', 402);
    $name = bstr('name', '');
    need($name !== '', 'Account name is required');
    $plan = bval('plan_id', null) !== null && bstr('plan_id', '') !== ''
        ? plan_by_id(bstr('plan_id')) : plan_by_code(bstr('plan_code', ''));
    need($plan && (int)$plan['enabled'] === 1, 'Choose an available plan', 400);
    $adminUser = trim(bstr('admin_username', ''));
    $adminPass = (string)bval('admin_password', '');
    need(preg_match('/^[A-Za-z0-9._-]{3,60}$/', $adminUser) === 1,
        'The client admin username may only use letters, digits, dot, dash and underscore');
    need(strlen($adminPass) >= 6, 'The client admin password must be at least 6 characters');
    $st = db()->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute([$adminUser]);
    need(!$st->fetch(), 'That admin username is already taken');
    $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-')) ?: 'client';
    $base = $slug; $n = 1;
    while (true) {
        $st = db()->prepare('SELECT id FROM accounts WHERE slug = ?');
        $st->execute([$slug]);
        if (!$st->fetch()) break;
        $slug = $base . '-' . (++$n);
    }
    $contact = trim(bstr('contact_email', ''));
    need($contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL) === $contact,
        'A valid client contact e-mail is required');
    $days = max(0, bint('trial_days', (int)$plan['trial_days']));
    $accountId = uid('acc');
    db()->prepare('INSERT INTO accounts (id,name,slug,plan,plan_id,status,trial_ends_at,units_limit,
                   storage_days,currency,timezone,contact_email,billing_email,portal_id,
                   signup_source,enabled,created_at)
                   VALUES (?,?,?,?,?,\'trialing\',?,?,?,?,?,?,?,?,\'reseller\',1,?)')
        ->execute([$accountId, $name, $slug, $plan['code'], $plan['id'],
            $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null,
            (int)$plan['unit_limit'], (int)$plan['storage_days'],
            (string)$portal['currency'], 'Africa/Nairobi', $contact, $contact,
            (string)$portal['id'], now_dt()]);
    if ($days > 0) subscription_start_trial($accountId, $days);
    $adminId = uid('usr');
    db()->prepare('INSERT INTO users (id,account_id,username,email,full_name,role,password_hash,salt,enabled,created_at)
                   VALUES (?,?,?,?,?,\'admin\',?,?,1,?)')
        ->execute([$adminId, $accountId, $adminUser, $contact, bstr('admin_full_name', '') ?: $name,
            password_hash($adminPass, PASSWORD_BCRYPT), bin2hex(random_bytes(8)), now_dt()]);
    audit('account_created', 'account', $accountId, ['name' => $name, 'portal_id' => $portal['id'],
        'by_reseller' => $user['username']]);
    return ['account_id' => $accountId, 'admin_username' => $adminUser,
        'message' => 'Client account created inside your portal', 'billing' => billing_state($accountId)];
}
