<?php
/* =====================================================================
   KIVU Tracker — php/routes_billing.php
   Public signup + plans, client billing (checkout, invoices, cancel),
   gateway webhooks and the super-admin billing console.
   ===================================================================== */
declare(strict_types=1);

function routes_billing(): array {
    $id = '(?P<id>[A-Za-z0-9_\-]{3,64})';
    $gw = '(?P<gateway>[a-z]{2,20})';
    return [
        /* ---- public: plans, self-service registration, gateway callbacks ---- */
        ['GET',  '#^/plans$#', 'h_plans_public', 'public'],
        ['POST', '#^/signup$#', 'h_signup', 'public'],
        ['GET',  '#^/pay/' . $id . '$#', 'h_pay_public', 'public'],
        ['POST', '#^/pay/' . $id . '/complete$#', 'h_pay_sandbox_complete', 'public'],
        ['POST', '#^/webhooks/' . $gw . '$#', 'h_webhook', 'public'],
        ['GET',  '#^/webhooks/' . $gw . '$#', 'h_webhook', 'public'],

        /* ---- client billing (never blocked by an expired trial) ------------ */
        ['GET',  '#^/billing$#', 'h_billing_get', 'billing'],
        ['POST', '#^/billing/checkout$#', 'h_billing_checkout', 'billing'],
        ['POST', '#^/billing/pay/' . $id . '/status$#', 'h_billing_pay_status', 'billing'],
        ['POST', '#^/billing/cancel$#', 'h_billing_cancel', 'billing'],
        ['PUT',  '#^/billing/account$#', 'h_billing_account', 'billing'],
        ['DELETE', '#^/billing/methods/' . $id . '$#', 'h_billing_method_delete', 'billing'],

        /* ---- super administrator: plans, payments, accounts, gateways ------ */
        ['GET',  '#^/billing/overview$#', 'h_billing_overview', 'super'],
        ['GET',  '#^/billing/payments$#', 'h_billing_payments', 'super'],
        ['GET',  '#^/billing/reminders$#', 'h_billing_reminders', 'super'],
        ['POST', '#^/billing/payments/' . $id . '$#', 'h_billing_payment_action', 'super'],
        ['POST', '#^/billing/plans$#', 'h_billing_plan_save', 'super'],
        ['PUT',  '#^/billing/plans/' . $id . '$#', 'h_billing_plan_save', 'super'],
        ['DELETE', '#^/billing/plans/' . $id . '$#', 'h_billing_plan_delete', 'super'],
        ['GET',  '#^/billing/settings$#', 'h_billing_settings_get', 'super'],
        ['PUT',  '#^/billing/settings$#', 'h_billing_settings_save', 'super'],
        ['POST', '#^/accounts/' . $id . '/billing$#', 'h_account_billing_action', 'super'],
    ];
}

/* Public plan catalogue — drives /register and the landing page. */
function h_plans_public($user, $p): array {
    ensure_schema();
    $plans = array_map('plan_public', plans_all(true));
    $gateways = [];
    foreach (payment_gateways() as $code => $g) {
        $row = gateway_config($code);
        $gateways[] = ['code' => $code, 'label' => $g['label'], 'short' => $g['short'],
            'methods_label' => $g['methods_label'], 'mode' => $g['mode'],
            'configured' => gateway_credentials_present($code),
            'enabled' => !empty($row['enabled']), 'active' => !empty($row['active'])];
    }
    return ['plans' => $plans, 'gateways' => $gateways,
        'trial_days' => trial_days(), 'signup_enabled' => (int)setting('signup_enabled', 1) === 1,
        'asset_types' => asset_types(),
        'currency' => setting('default_currency', 'USD'),
        'platform' => setting('platform_name', APP_NAME),
        'logo' => branding_logo_url()];
}

/* Self-service registration: account + admin user + free trial, signed in. */
function h_signup($user, $p): array {
    $created = signup_create_account([
        'company' => bstr('company'), 'email' => bstr('email'), 'username' => bstr('username'),

        'password' => (string)bval('password', ''), 'full_name' => bstr('full_name'),
        'phone' => bstr('phone'), 'plan' => bstr('plan_code', bstr('plan', '')),
        'currency' => bstr('currency', ''), 'timezone' => bstr('timezone', ''),
        'asset_type' => bstr('asset_type', '')]);
    $u = $created['user'];
    $token = create_session($u);
    $state = billing_state((string)$created['account']['id']);
    return ['account' => ['id' => $created['account']['id'], 'name' => $created['account']['name'],
            'slug' => $created['account']['slug'], 'status' => $state['status']],
        'trial_days' => $created['trial_days'], 'trial_ends_at' => $state['trial_ends_at'],
        'plan' => $created['plan'], 'token' => $token, 'user' => user_public($u),
        'billing' => $state,
        'message' => 'Welcome to ' . (setting('platform_name', APP_NAME) . '! Your '
            . $created['trial_days'] . '-day free trial has started.')];
}

/* Public invoice view used by the /pay/<id> sandbox page. */
function h_pay_public($user, $p): array {
    ensure_schema();
    $pay = payment_by_id((string)$p['id']);
    need($pay, 'Invoice not found', 404);
    $account = account_by_id((string)$pay['account_id']);
    $plan = $pay['plan_id'] ? plan_by_id((string)$pay['plan_id']) : null;
    return ['payment' => payment_public($pay), 'account' => $account ? $account['name'] : '',
        'plan' => $plan ? plan_public($plan) : null,
        'platform' => setting('platform_name', APP_NAME), 'logo' => branding_logo_url(),
        'simulated' => $pay['gateway'] === 'sandbox',
        'gateway_label' => payment_gateways()[$pay['gateway']]['label'] ?? $pay['gateway']];
}

/* Sandbox gateway page: "pay", "fail" or "cancel" — the only writer of the
   simulated gateway. Real gateways never use this endpoint. */
function h_pay_sandbox_complete($user, $p): array {
    $pay = payment_by_id((string)$p['id']);
    need($pay, 'Invoice not found', 404);
    need($pay['gateway'] === 'sandbox', 'This invoice belongs to a live gateway and cannot be settled here.', 403);
    $outcome = strtolower(bstr('outcome', 'paid'));
    billing_record_event('sandbox', (string)$pay['id'] . ':sim:' . $outcome, 'simulate',
        $outcome, (string)$pay['id'], ['outcome' => $outcome, 'by' => ip_addr()]);
    if ($outcome === 'paid') {
        /* mint (or accept) the reusable method for automatic renewal */
        $pmToken = trim((string)bstr('pm_token', ''));
        if ($pmToken === '') $pmToken = 'sbx_pm_' . substr(bin2hex(random_bytes(8)), 0, 12);
        $pmLast4 = (string)bstr('pm_last4', '');
        if ($pmLast4 === '') {
            $digits = preg_replace('/\D+/', '', $pmToken) ?? '';
            $pmLast4 = strlen($digits) >= 4 ? substr($digits, -4) : '4242';
        }
        $res = billing_mark_paid($pay, ['gateway_ref' => (string)($pay['gateway_ref'] ?: 'sbx_' . $pay['id']),
            'meta' => ['verified' => true, 'source' => 'sandbox',
                'pm_token' => $pmToken, 'pm_brand' => 'Sandbox card',
                'pm_last4' => substr($pmLast4, -4)]]);
        return ['status' => 'paid', 'settled' => true, 'period_end' => $res['period_end'] ?? null,
            'message' => 'Sandbox payment approved — subscription active.'];
    }
    if ($outcome === 'failed') {
        billing_mark_failed($pay, 'Declined in the sandbox gateway');
        return ['status' => 'failed', 'message' => 'Sandbox payment declined.'];
    }
    payment_touch((string)$pay['id'], ['status' => 'canceled']);
    return ['status' => 'canceled', 'message' => 'Sandbox payment cancelled.'];
}

/* Every gateway callback lands here (POST) — Pesapal also uses GET. */
function h_webhook($user, $p): array {
    ensure_schema();
    $gateway = (string)$p['gateway'];
    need(isset(payment_gateways()[$gateway]), 'Unknown gateway', 404);
    $body = body();
    if (!$body && req_raw_body() !== '') {
        $decoded = json_decode(req_raw_body(), true);
        if (is_array($decoded)) $body = $decoded;
    }
    $payload = array_merge($_GET, is_array($body) ? $body : []);
    unset($payload['r'], $payload['token'], $payload['account_id']);
    $res = webhook_handle($gateway, $payload, [
        'Stripe-Signature' => req_header('Stripe-Signature'),
        'verif-hash' => req_header('verif-hash'),
        'x-pesapal-signature' => req_header('x-pesapal-signature'),
    ]);
    if ($gateway === 'pesapal') {
        /* Pesapal expects this acknowledgement body (HTTP 200) */
        return ['orderNotificationType' => (string)($payload['OrderNotificationType'] ?? 'IPNCHANGE'),
            'orderTrackingId' => (string)($payload['OrderTrackingId'] ?? ''),
            'orderMerchantReference' => (string)($payload['OrderMerchantReference'] ?? ''),
            'status' => $res['ok'] ? 200 : 400];
    }
    if (empty($res['ok'])) {
        /* one response only: the dispatcher turns this into the JSON error body */
        throw new ApiError((string)($res['error'] ?? 'Callback rejected'), 400);
    }
    return $res;
}

/* ---------------------------- client billing console ---------------------- */
function billing_my_account($user): array {
    $id = scope_account($user);
    need($id !== null && $id !== '', 'This billing console belongs to a client account — sign in as an account user.', 403);
    $acc = account_by_id((string)$id);
    need($acc, 'Account not found', 404);
    return $acc;
}
function h_billing_get($user, $p): array {
    ensure_schema();
    $acc = billing_my_account($user);
    $state = billing_state((string)$acc['id'], $acc);
    $st = db()->prepare('SELECT * FROM payments WHERE account_id = ? ORDER BY created_at DESC LIMIT 50');
    $st->execute([$acc['id']]);
    $invoices = array_map('payment_public', $st->fetchAll());
    $plans = [];
    foreach (plans_all(true) as $pl) {
        $row = plan_public($pl);
        $row['gateways'] = gateways_for_plan($pl);
        $plans[] = $row;
    }
    return ['billing' => $state, 'account' => ['id' => $acc['id'], 'name' => $acc['name'],
            'slug' => $acc['slug'], 'currency' => $acc['currency'], 'timezone' => $acc['timezone'],
            'contact_name' => $acc['contact_name'], 'contact_email' => $acc['contact_email'] ?? $acc['billing_email'],
            'contact_phone' => $acc['contact_phone'], 'billing_email' => $acc['billing_email']],
        'usage' => ['units' => billing_units_used((string)$acc['id']),
            'units_limit' => (int)$acc['units_limit'], 'storage_days' => (int)$acc['storage_days']],
        'plans' => $plans, 'invoices' => $invoices, 'trial_days' => trial_days(),
        'reminders' => ['enabled' => billing_reminders_enabled(), 'days' => billing_reminder_days(),
            'recipient' => account_reminder_email($acc)],
        'profile' => billing_profile_shape($acc),
        'methods' => payment_methods_list((string)$acc['id']),
        'auto_renew' => (int)($acc['auto_renew'] ?? 1) === 1,
        'overage' => billing_overage_shape($acc, $acc['plan_id'] ? plan_by_id((string)$acc['plan_id']) : null),
        'gateway_mode' => setting('gateway_mode', 'sandbox'),
        'server_time' => date('c')];
}
/* Starts a checkout for the caller's own account. */
function h_billing_checkout($user, $p): array {
    $acc = billing_my_account($user);
    /* invoicing details first: the invoice and the renewal e-mails need them */
    $missing = billing_profile_missing($acc);
    need(!$missing,
        'Add your billing details first (' . implode(', ', $missing)
        . ') — we need them for the invoice and the renewal reminders.', 400);
    $gateway = strtolower(bstr('gateway', 'sandbox'));
    $planId = bstr('plan_id', '');
    $planCode = bstr('plan_code', bstr('plan', ''));
    $plan = $planId !== '' ? plan_by_id($planId) : ($planCode !== '' ? plan_by_code($planCode) : null);
    need($plan, 'Choose a plan before paying', 400);
    need((int)$plan['enabled'] === 1, 'That plan is no longer available', 400);
    $res = checkout_start($acc, $plan, $gateway, [
        'phone' => bstr('phone', (string)($acc['contact_phone'] ?? '')),
        'payer_email' => bstr('email', (string)($acc['billing_email'] ?? $acc['contact_email'] ?? '')),
        'payer_name' => bstr('payer_name', (string)($acc['contact_name'] ?? $acc['name'])),
        'save_method' => bval('save_method', null) === null ? true : bbool('save_method', true),
        'requested_by' => $user['username']]);
    $msg = $res['message'] !== '' ? $res['message'] : 'Invoice ' . $res['payment']['invoice_no'] . ' created.';
    /* spell the flexible overage out on the invoice message */
    $ov = billing_overage_shape($acc, $plan);
    if ($ov['extra_units'] > 0 && $ov['amount'] > 0) {
        $msg .= ' Includes ' . $ov['amount'] . ' ' . $ov['currency'] . ' for '
            . $ov['extra_units'] . ' extra device(s) beyond ' . $ov['unit_limit'] . '.';
    }
    /* array_merge, not +: the composed message must win over the driver's */
    return array_merge($res, ['message' => $msg, 'overage' => $ov]);
}
/* Polls the gateway for one of my invoices (used after the redirect back). */
function h_billing_pay_status($user, $p): array {
    $acc = billing_my_account($user);
    $pay = payment_by_id((string)$p['id']);
    need($pay && $pay['account_id'] === $acc['id'], 'Invoice not found', 404);
    $res = billing_poll_payment($pay);
    return $res + ['billing' => billing_state((string)$acc['id'])];
}
/* Cancels (or re-enables) auto-renewal at the end of the paid period. */
function h_billing_cancel($user, $p): array {
    $acc = billing_my_account($user);
    $sub = subscription_current((string)$acc['id']);
    need($sub, 'This account has no subscription yet', 400);
    $cancel = bbool('cancel', true);
    db()->prepare('UPDATE subscriptions SET cancel_at_period_end = ?, updated_at = ? WHERE id = ?')
        ->execute([$cancel ? 1 : 0, now_dt(), $sub['id']]);
    audit($cancel ? 'subscription_cancel_scheduled' : 'subscription_resumed', 'account', (string)$acc['id']);
    return ['cancel_at_period_end' => $cancel, 'billing' => billing_state((string)$acc['id']),
        'message' => $cancel
            ? 'Auto-renewal cancelled — the plan stays active until ' . (iso($sub['period_end']) ?: 'the period ends') . '.'
            : 'Auto-renewal re-enabled.'];
}
/* Billing contact details — the post-registration step (name, e-mail, phone,
   address) plus the automatic-billing opt-in/out. */
function h_billing_account($user, $p): array {
    $acc = billing_my_account($user);
    $fields = [];
    foreach (['contact_name', 'contact_phone', 'billing_email', 'contact_email', 'timezone', 'currency',
               'billing_address', 'billing_city', 'billing_country'] as $k) {
        if (bval($k, null) === null) continue;
        $v = trim((string)bval($k, ''));
        if ($k === 'billing_email' || $k === 'contact_email') {
            if ($v !== '') need(filter_var($v, FILTER_VALIDATE_EMAIL) === $v, 'That e-mail address is not valid');
        }
        $fields[$k] = $v === '' ? null : $v;
    }
    if (bval('auto_renew', null) !== null) $fields['auto_renew'] = bbool('auto_renew', true) ? 1 : 0;
    need($fields, 'Nothing to update');
    $sets = []; $vals = [];
    foreach ($fields as $k => $v) { $sets[] = "`$k` = ?"; $vals[] = $v; }
    $vals[] = $acc['id'];
    db()->prepare('UPDATE accounts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    audit('billing_details_updated', 'account', (string)$acc['id'], array_keys($fields));
    $fresh = account_by_id((string)$acc['id']) ?? $acc;
    return ['message' => 'Billing details saved',
        'profile' => billing_profile_shape($fresh),
        'auto_renew' => (int)($fresh['auto_renew'] ?? 1) === 1,
        'account' => ['contact_name' => $fields['contact_name'] ?? $acc['contact_name']]];
}
/* The client removes a saved payment method (the renewal falls back to
   invoice + reminders). */
function h_billing_method_delete($user, $p): array {
    $acc = billing_my_account($user);
    need(payment_method_delete((string)$acc['id'], (string)$p['id']), 'Payment method not found', 404);
    return ['message' => 'Payment method removed — billing falls back to manual payment at renewal.',
        'methods' => payment_methods_list((string)$acc['id'])];
}

/* ------------------------------- super administrator --------------------- */
/* Gateway metadata (labels + field list) shared by the overview and settings. */
function billing_gateway_meta(): array {
    return array_map(fn($g) => ['label' => $g['label'], 'methods_label' => $g['methods_label'],
        'fields' => $g['fields'], 'docs' => $g['docs'], 'currencies' => $g['currencies'],
        'mode' => $g['mode'], 'base' => $g['base']], payment_gateways());
}
/* Everything the Accounts → Plans & billing tab needs in one call. */
function h_billing_overview($user, $p): array {
    ensure_schema();
    $accounts = db()->query('SELECT * FROM accounts ORDER BY created_at DESC')->fetchAll();
    /* default payment method per account (never the token) */
    $pmByAcc = [];
    foreach (db()->query("SELECT account_id, gateway FROM payment_methods WHERE status = 'active'
                          AND is_default = 1")->fetchAll() as $mrow) {
        $pmByAcc[(string)$mrow['account_id']] = (string)$mrow['gateway'];
    }
    $rows = [];
    $mrr = 0.0; $trialing = 0; $active = 0; $expired = 0; $suspended = 0; $trialEnding = 0;
    foreach ($accounts as $a) {
        $state = billing_state((string)$a['id'], $a);
        $u = (int)db()->query('SELECT COUNT(*) FROM units WHERE account_id = ' . db()->quote((string)$a['id']))->fetchColumn();
        $rows[] = ['id' => $a['id'], 'name' => $a['name'], 'slug' => $a['slug'], 'plan' => $a['plan'],
            'plan_id' => $a['plan_id'], 'status' => $state['status'], 'trial_ends_at' => $state['trial_ends_at'],
            'trial_days_left' => $state['trial_days_left'], 'period_end' => $state['period_end'],
            'days_left' => $state['days_left'], 'units' => $u, 'units_limit' => (int)$a['units_limit'],
            'currency' => $a['currency'], 'created_at' => iso($a['created_at']),
            'signup_source' => $a['signup_source'], 'contact_email' => $a['contact_email'] ?? $a['billing_email'],
            'enabled' => (int)$a['enabled'], 'gateway' => $state['gateway'],
            'profile_ok' => billing_profile_complete($a),
            'auto_renew' => (int)($a['auto_renew'] ?? 1) === 1,
            'pm_gateway' => $pmByAcc[(string)$a['id']] ?? null,
            'default_asset_type' => $a['default_asset_type'] ?? null];
        if ($state['status'] === 'trialing') { $trialing++; if ($state['trial_days_left'] <= 2) $trialEnding++; }
        if ($state['status'] === 'active') {
            $active++;
            $plan = $a['plan_id'] ? plan_by_id((string)$a['plan_id']) : null;
            if ($plan) $mrr += (float)$plan['price'] / (($plan['interval'] === 'year') ? 12 : 1);
        }
        if ($state['status'] === 'expired' || $state['status'] === 'past_due') $expired++;
        if ($state['status'] === 'suspended') $suspended++;
    }
    $st = db()->prepare('SELECT * FROM payments ORDER BY created_at DESC LIMIT 40');
    $st->execute();
    $paidTotal = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'paid'")->fetchColumn();
    $paidCount = (int)db()->query("SELECT COUNT(*) FROM payments WHERE status = 'paid'")->fetchColumn();
    return ['accounts' => $rows, 'payments' => array_map('payment_public', $st->fetchAll()),
        'plans' => array_map('plan_public', plans_all(false)),
        'totals' => ['accounts' => count($rows), 'trialing' => $trialing, 'active' => $active,
            'expired' => $expired, 'suspended' => $suspended, 'trial_ending_soon' => $trialEnding,
            'mrr' => round($mrr, 2), 'collected' => round($paidTotal, 2), 'paid_invoices' => $paidCount],
        'settings' => billing_admin_settings(),
        'gateways' => billing_config(false), 'gateway_meta' => billing_gateway_meta(),
        'webhook_urls' => billing_webhook_urls(),
        'reminders' => billing_reminders_list(null, 50),
        'portals' => array_map('portal_shape',
            db()->query('SELECT * FROM portals ORDER BY created_at DESC')->fetchAll()),
        'portal_price' => portal_price()];
}
function h_billing_payments($user, $p): array {
    $sql = 'SELECT * FROM payments WHERE 1=1';
    $prm = [];
    if (qp('account_id')) { $sql .= ' AND account_id = ?'; $prm[] = qp('account_id'); }
    if (qp('status')) { $sql .= ' AND status = ?'; $prm[] = qp('status'); }
    if (qp('gateway')) { $sql .= ' AND gateway = ?'; $prm[] = qp('gateway'); }
    if (qp('q')) { $sql .= ' AND (invoice_no LIKE ? OR gateway_ref LIKE ? OR payer_email LIKE ?)';
        $like = '%' . qp('q') . '%'; array_push($prm, $like, $like, $like); }
    $sql .= ' ORDER BY created_at DESC LIMIT 300';
    $st = db()->prepare($sql);
    $st->execute($prm);
    return ['payments' => array_map('payment_public', $st->fetchAll())];
}
/* Recent payment reminders (super admin visibility + console table). */
function h_billing_reminders($user, $p): array {
    ensure_schema();
    $lim = (int)qp('limit');
    $lim = $lim > 0 ? min(500, $lim) : 100;
    return ['reminders' => billing_reminders_list((string)qp('account_id') ?: null, $lim),
        'enabled' => billing_reminders_enabled(), 'days' => billing_reminder_days(),
        'transport' => mail_transport()];
}
/* Super-admin actions on a single invoice (bank confirmation, refund…). */
function h_billing_payment_action($user, $p): array {
    $pay = payment_by_id((string)$p['id']);
    need($pay, 'Invoice not found', 404);
    $action = strtolower(bstr('action', 'mark_paid'));
    if ($action === 'mark_paid') {
        $res = billing_mark_paid($pay, ['gateway_ref' => (string)($pay['gateway_ref'] ?: 'manual-' . $pay['id']),
            'meta' => ['verified' => true, 'source' => 'admin', 'by' => $user['username']]]);
        return ['message' => 'Invoice ' . $pay['invoice_no'] . ' marked paid — subscription extended.',
            'settled' => $res, 'payment' => payment_public(payment_by_id((string)$pay['id']))];
    }
    if ($action === 'mark_failed') {
        billing_mark_failed($pay, bstr('reason', 'Marked failed by ' . $user['username']));
        return ['message' => 'Invoice marked failed', 'payment' => payment_public(payment_by_id((string)$pay['id']))];
    }
    if ($action === 'refund') {
        payment_touch((string)$pay['id'], ['status' => 'refunded']);
        audit('payment_refunded', 'payment', (string)$pay['id'], ['invoice' => $pay['invoice_no'],
            'amount' => (float)$pay['amount'], 'by' => $user['username']]);
        return ['message' => 'Invoice marked refunded', 'payment' => payment_public(payment_by_id((string)$pay['id']))];
    }
    if ($action === 'cancel') {
        payment_touch((string)$pay['id'], ['status' => 'canceled']);
        return ['message' => 'Invoice cancelled', 'payment' => payment_public(payment_by_id((string)$pay['id']))];
    }
    throw new ApiError('Unknown payment action: ' . $action, 400);
}

/* Plan CRUD (the super admin sells the plans). */
function h_billing_plan_save($user, $p): array {
    ensure_schema();
    $id = (string)($p['id'] ?? '');
    $name = bstr('name', '');
    need($name !== '', 'Plan name is required');
    $code = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', bstr('code', $name)), '-'));
    need($code !== '', 'Plan code is required');
    $interval = bstr('interval', 'month');
    $fields = ['code' => $code, 'name' => $name, 'tagline' => bstr('tagline', '') ?: null,
        'price' => max(0, bfloat('price', 0)), 'currency' => strtoupper(bstr('currency', 'USD')),
        'interval' => in_array($interval, ['month', 'year'], true) ? $interval : 'month',
        'unit_limit' => max(1, bint('unit_limit', 10)), 'storage_days' => max(1, bint('storage_days', 90)),
        'trial_days' => max(0, bint('trial_days', trial_days())),
        'features_json' => jenc(list_of('features')),
        'sort_order' => bint('sort_order', 0), 'enabled' => bbool('enabled', true) ? 1 : 0];
    /* flexible overage: only touch the extra-device price when it is sent, so
       plain edits never reset it */
    if (bval('extra_unit_price', null) !== null) {
        $fields['extra_unit_price'] = max(0, bfloat('extra_unit_price', 0));
    }
    if ($id === '') {
        $st = db()->prepare('SELECT id FROM plans WHERE code = ?');
        $st->execute([$code]);
        need(!$st->fetch(), 'Another plan already uses the code ' . $code);
        $id = uid('plan');
        $fields['id'] = $id;
        $fields['created_at'] = now_dt();
        $cols = array_keys($fields);
        db()->prepare('INSERT INTO plans (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($fields));
        audit('plan_created', 'plan', $id, ['code' => $code, 'price' => $fields['price']]);
    } else {
        $existing = plan_by_id($id);
        need($existing, 'Plan not found', 404);
        $sets = []; $vals = [];
        foreach ($fields as $k => $v) { $sets[] = "`$k` = ?"; $vals[] = $v; }
        $vals[] = $id;
        db()->prepare('UPDATE plans SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        audit('plan_updated', 'plan', $id, ['code' => $code, 'price' => $fields['price']]);
    }
    return ['plan' => plan_public(plan_by_id($id)), 'message' => 'Plan saved'];
}
function h_billing_plan_delete($user, $p): array {
    $id = (string)$p['id'];
    $plan = plan_by_id($id);
    need($plan, 'Plan not found', 404);
    $st = db()->prepare('SELECT COUNT(*) FROM accounts WHERE plan_id = ?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) {
        db()->prepare('UPDATE plans SET enabled = 0 WHERE id = ?')->execute([$id]);
        audit('plan_disabled', 'plan', $id, ['code' => $plan['code']]);
        return ['message' => 'Plan is in use, so it was disabled instead of deleted'];
    }
    db()->prepare('DELETE FROM plans WHERE id = ?')->execute([$id]);
    audit('plan_deleted', 'plan', $id, ['code' => $plan['code']]);
    return ['message' => 'Plan deleted'];
}

/* Gateway credentials + trial/signup switches (super admin only). Secrets are
   never returned in clear: only the last four characters are shown. */
function h_billing_settings_get($user, $p): array {
    ensure_schema();
    return ['gateways' => billing_config(false), 'gateway_meta' => billing_gateway_meta(),
        'settings' => billing_admin_settings(),
        'webhook_urls' => billing_webhook_urls()];
}
/* Webhook / callback URLs per gateway, keyed by gateway code (for the UI). */
function billing_webhook_urls(): array {
    $out = [];
    foreach (['stripe', 'pesapal', 'mpesa', 'flutterwave', 'paypal'] as $code) {
        $out[$code] = app_base_url() . '/api/webhooks/' . $code;
    }
    return $out;
}
function billing_admin_settings(): array {
    $cfg = jdec(setting('billing_json', null), []);
    return ['trial_days' => trial_days(), 'signup_enabled' => (int)setting('signup_enabled', 1) === 1,
        'gateway_mode' => setting('gateway_mode', 'sandbox'),
        'invoice_prefix' => setting('invoice_prefix', 'KIVU'),
        'site_url' => (string)(is_array($cfg) ? ($cfg['site_url'] ?? '') : ''),
        'reminders_enabled' => billing_reminders_enabled(),
        'reminder_days' => implode(',', billing_reminder_days()),
        'mail' => mail_config(false), 'mail_transport' => mail_transport()];
}
function h_billing_settings_save($user, $p): array {
    $cfg = jdec(setting('billing_json', null), []);
    if (!is_array($cfg)) $cfg = [];
    $incoming = bval('gateways', null);
    need(is_array($incoming), 'gateways must be an object of gateway settings');
    foreach (payment_gateways() as $code => $g) {
        if (!isset($incoming[$code]) || !is_array($incoming[$code])) continue;
        $src = $incoming[$code];
        $row = is_array($cfg[$code] ?? null) ? $cfg[$code] : [];
        foreach (array_keys($g['fields']) as $k) {
            if (!array_key_exists($k, $src)) continue;
            $v = trim((string)$src[$k]);
            if (strpos($v, '••••') === 0) continue;          // masked value echoed back unchanged
            $row[$k] = $v;
        }
        if (isset($src['base'])) {
            $base = rtrim(trim((string)$src['base']), '/');
            if ($base === '') $row['base'] = (string)$g['base'];
            elseif (filter_var($base, FILTER_VALIDATE_URL) === $base) $row['base'] = $base;
        }
        if (isset($src['env'])) {
            $row['env'] = in_array($src['env'], ['sandbox', 'live'], true) ? $src['env'] : 'sandbox';
        }
        /* the on/off switch the super admin toggles in the console */
        if (array_key_exists('enabled', $src)) {
            $row['enabled'] = filter_var($src['enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        $cfg[$code] = $row;
    }
    if (bval('site_url', null) !== null) $cfg['site_url'] = rtrim(trim((string)bval('site_url', '')), '/');
    /* payment reminders: the super admin's switch, the offsets and e-mail */
    if (bval('reminders_enabled', null) !== null) {
        $cfg['reminders_enabled'] = bbool('reminders_enabled', true) ? 1 : 0;
    }
    if (bval('reminder_days', null) !== null) {
        $clean = [];
        foreach (preg_split('/[,\s]+/', trim((string)bstr('reminder_days', '')), -1, PREG_SPLIT_NO_EMPTY) as $d) {
            $d = (int)$d;
            if ($d >= 1 && $d <= 120) $clean[$d] = 1;
        }
        if (!$clean) $clean = [14 => 1, 5 => 1, 2 => 1];
        $cfg['reminder_days'] = implode(',', array_keys($clean));
    }
    $mailIn = bval('mail', null);
    if (is_array($mailIn)) {
        $m = is_array($cfg['mail'] ?? null) ? $cfg['mail'] : [];
        if (isset($mailIn['transport'])) {
            $m['transport'] = in_array($mailIn['transport'], ['auto', 'smtp', 'mail', 'log'], true)
                ? $mailIn['transport'] : 'auto';
        }
        if (array_key_exists('from', $mailIn)) {
            $v = trim((string)$mailIn['from']);
            if ($v === '' || filter_var($v, FILTER_VALIDATE_EMAIL) === $v) $m['from'] = $v;
        }
        if (array_key_exists('from_name', $mailIn)) {
            $m['from_name'] = mb_substr(trim((string)$mailIn['from_name']), 0, 80);
        }
        if (array_key_exists('smtp_host', $mailIn)) $m['smtp_host'] = trim((string)$mailIn['smtp_host']);
        if (array_key_exists('smtp_port', $mailIn)) {
            $m['smtp_port'] = max(1, min(65535, (int)$mailIn['smtp_port']));
        }
        if (isset($mailIn['smtp_encryption'])) {
            $m['smtp_encryption'] = in_array($mailIn['smtp_encryption'], ['none', 'tls', 'ssl'], true)
                ? $mailIn['smtp_encryption'] : 'tls';
        }
        if (array_key_exists('smtp_user', $mailIn)) $m['smtp_user'] = (string)$mailIn['smtp_user'];
        if (array_key_exists('smtp_pass', $mailIn)) {
            $v = (string)$mailIn['smtp_pass'];
            if (strpos($v, '••••') !== 0) $m['smtp_pass'] = $v;   // masked echo = unchanged
        }
        $cfg['mail'] = $m;
    }
    $settings = ['billing_json' => jenc($cfg)];
    if (bval('trial_days', null) !== null) $settings['trial_days'] = max(1, min(90, bint('trial_days', trial_days())));
    if (bval('signup_enabled', null) !== null) $settings['signup_enabled'] = bbool('signup_enabled', true) ? 1 : 0;
    if (bval('gateway_mode', null) !== null) {
        $mode = bstr('gateway_mode', 'sandbox');
        $settings['gateway_mode'] = in_array($mode, ['sandbox', 'live'], true) ? $mode : 'sandbox';
    }
    if (bval('invoice_prefix', null) !== null) {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', bstr('invoice_prefix', 'KIVU'));
        $settings['invoice_prefix'] = strtoupper(substr($clean !== '' && $clean !== null ? $clean : 'KIVU', 0, 12));
    }
    settings_save($settings);
    audit('billing_settings_saved', 'settings', null, ['gateway_mode' => $settings['gateway_mode'] ?? null,
        'trial_days' => $settings['trial_days'] ?? null, 'keys' => array_keys($settings)]);
    return ['gateways' => billing_config(false), 'settings' => billing_admin_settings(),
        'message' => 'Billing settings saved'];
}

/* Super-admin actions on an account's subscription: switch plan, extend the
   trial or the paid period, activate, suspend, mark as complimentary. */
function h_account_billing_action($user, $p): array {
    ensure_schema();
    $accountId = (string)$p['id'];
    $acc = account_by_id($accountId);
    need($acc, 'Account not found', 404);
    $action = strtolower(bstr('action', ''));
    $days = (int)bval('days', 0);
    $state = null;
    switch ($action) {
        case 'set_plan':
            $plan = bval('plan_id', null) !== null ? plan_by_id(bstr('plan_id')) : plan_by_code(bstr('plan_code', bstr('plan', '')));
            need($plan, 'Plan not found', 404);
            billing_apply_plan($accountId, $plan);
            break;
        case 'extend_trial':
            $days = $days !== 0 ? $days : trial_days();
            $base = time();
            $cur = $acc['trial_ends_at'] ? strtotime((string)$acc['trial_ends_at']) : false;
            if ($cur !== false && $cur > $base) $base = $cur;
            db()->prepare('UPDATE accounts SET trial_ends_at = ?, status = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s', $base + $days * 86400), $days > 0 ? 'trialing' : 'expired', $accountId]);
            if ($days <= 0) {
                $sub = subscription_current($accountId);
                if ($sub) db()->prepare('UPDATE subscriptions SET status = ?, updated_at = ? WHERE id = ?')
                    ->execute(['expired', now_dt(), $sub['id']]);
            }
            audit('trial_extended', 'account', $accountId, ['days' => $days]);
            break;
        case 'extend_period':
        case 'activate':
        case 'comp':
            $days = $days !== 0 ? $days : 30;
            if ($action === 'comp') {
                db()->prepare('UPDATE accounts SET status = ? WHERE id = ?')->execute(['comp', $accountId]);
                $sub = subscription_current($accountId);
                if ($sub) db()->prepare('UPDATE subscriptions SET status = ?, cancel_at_period_end = 0, updated_at = ? WHERE id = ?')
                    ->execute(['comp', now_dt(), $sub['id']]);
                break;
            }
            $sub = subscription_current($accountId);
            $base = time();
            if ($sub && !empty($sub['period_end'])) {
                $cur = strtotime((string)$sub['period_end']);
                if ($cur !== false && $cur > $base) $base = $cur;
            }
            $start = date('Y-m-d H:i:s', $base);
            $end = date('Y-m-d H:i:s', $base + $days * 86400);
            if ($sub) {
                db()->prepare('UPDATE subscriptions SET status = ?, period_start = COALESCE(period_start, ?),
                               period_end = ?, cancel_at_period_end = 0, updated_at = ? WHERE id = ?')
                    ->execute(['active', $start, $end, now_dt(), $sub['id']]);
            } else {
                db()->prepare('INSERT INTO subscriptions (id,account_id,plan_id,status,period_start,period_end,
                               cancel_at_period_end,created_at,updated_at) VALUES (?,?,?,?,?,?,0,?,?)')
                    ->execute([uid('sub'), $accountId, $acc['plan_id'], 'active', $start, $end, now_dt(), now_dt()]);
            }
            db()->prepare('UPDATE accounts SET status = ?, trial_ends_at = NULL WHERE id = ?')
                ->execute(['active', $accountId]);
            audit($action === 'activate' ? 'subscription_activated' : 'subscription_extended',
                'account', $accountId, ['days' => $days, 'period_end' => $end, 'by' => $user['username']]);
            break;
        case 'suspend':
            db()->prepare('UPDATE accounts SET status = ? WHERE id = ?')->execute(['suspended', $accountId]);
            audit('account_suspended', 'account', $accountId, ['reason' => bstr('reason', '')]);
            break;
        case 'expire':
            /* cut access immediately (trial over or subscription ended) */
            db()->prepare('UPDATE accounts SET status = ?, trial_ends_at = NULL WHERE id = ?')
                ->execute(['expired', $accountId]);
            db()->prepare('UPDATE subscriptions SET status = ?, period_end = ?, updated_at = ? WHERE account_id = ?')
                ->execute(['expired', date('Y-m-d H:i:s', time() - 3600), now_dt(), $accountId]);
            audit('subscription_expired', 'account', $accountId, ['by' => $user['username']]);
            break;
        case 'cancel':
            $sub = subscription_current($accountId);
            need($sub, 'This account has no subscription', 400);
            db()->prepare('UPDATE subscriptions SET cancel_at_period_end = ?, updated_at = ? WHERE id = ?')
                ->execute([bbool('cancel', true) ? 1 : 0, now_dt(), $sub['id']]);
            audit('subscription_cancel_scheduled', 'account', $accountId, ['by' => $user['username']]);
            break;
        case 'send_reminder': {
            /* manual payment reminder for this account right now */
            $state = billing_state($accountId, $acc);
            $cutoffTs = billing_cutoff_ts($acc, $state);
            need($cutoffTs !== null && $cutoffTs > time(),
                'This account has no upcoming deactivation date to remind about.', 400);
            $left = (int)ceil(($cutoffTs - time()) / 86400);
            $band = null;
            foreach (array_reverse(billing_reminder_days()) as $t) {
                if ($left <= $t) { $band = $t; break; }
            }
            if ($band === null) $band = max(billing_reminder_days());
            $r = billing_reminder_send($acc, $band . 'd', $band, date('Y-m-d H:i:s', $cutoffTs),
                $left, true);
            need(($r['skipped'] ?? '') !== 'no_recipient',
                'This account has no e-mail address to send the reminder to.', 400);
            need(!empty($r['ok']), 'The reminder could not be sent: ' . (string)($r['error'] ?? 'unknown error'), 502);
            return ['account_id' => $accountId, 'action' => $action,
                'billing' => billing_state($accountId),
                'message' => 'Payment reminder sent to ' . $r['recipient'],
                'reminder' => $r];
        }
        default:
            throw new ApiError('Unknown billing action: ' . ($action === '' ? '(empty)' : $action), 400);
    }
    return ['account_id' => $accountId, 'action' => $action, 'billing' => billing_state($accountId),
        'message' => 'Account billing updated'];
}
