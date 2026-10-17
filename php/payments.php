<?php
/* =====================================================================
   KIVU Tracker — php/payments.php
   Subscriptions, free trials and payment gateways.

   Self-service signup creates an account with a free trial (default 5
   days, `settings.trial_days`). When the trial ends — or when a client
   wants to upgrade — the billing screens create a `payments` row
   (invoice) and hand the client to a gateway:

       stripe      cards (Visa / Mastercard / Amex / Apple & Google Pay)
       pesapal     cards + mobile money across East Africa
       mpesa       M-Pesa STK push (Safaricom Daraja)
       flutterwave cards, mobile money and bank across Africa
       paypal      PayPal
       sandbox     built-in simulator, no money moves (tests/demos)
       bank        manual transfer, confirmed by the super admin

   Every driver returns either a redirect URL (hosted checkout) or, for
   M-Pesa, a push confirmation. Callbacks land on /api/webhooks/<gateway>
   and are verified before a payment is marked paid; `payment_events`
   makes callbacks idempotent so a gateway retry never bills twice.

   Credentials live in `settings.billing_json` (written by the super
   administrator in Accounts → Plans & billing).
   ===================================================================== */
declare(strict_types=1);

/* --------------------------------------------------------- gateway table -- */
/* fields  = credential keys stored in billing_json[<gateway>]
   methods = what the client pays with (drives the UI labels)
   mode    = redirect (hosted page) | push (STK) | local (simulator) | manual */
function payment_gateways(): array {
    return [
        'stripe' => [
            'label' => 'Card — Visa / Mastercard / Amex (Stripe)', 'short' => 'Stripe',
            'methods' => ['card'], 'methods_label' => 'Card',
            'currencies' => ['USD', 'EUR', 'GBP', 'KES', 'ZAR', 'NGN', 'GHS', 'UGX', 'TZS', 'AED', 'INR'],
            'fields' => ['secret_key' => 'Secret key (sk_live_… / sk_test_…)',
                'publishable_key' => 'Publishable key (pk_…)',
                'webhook_secret' => 'Webhook signing secret (whsec_…)'],
            'base' => 'https://api.stripe.com', 'docs' => 'https://dashboard.stripe.com/apikeys',
            'mode' => 'redirect'],
        'pesapal' => [
            'label' => 'Pesapal — cards + mobile money (East Africa)', 'short' => 'Pesapal',
            'methods' => ['card', 'mobile_money'], 'methods_label' => 'Card or mobile money',
            'currencies' => ['KES', 'UGX', 'TZS', 'USD'],
            'fields' => ['consumer_key' => 'Consumer key', 'consumer_secret' => 'Consumer secret',
                'ipn_id' => 'IPN id (optional — created automatically)'],
            'base' => 'https://cybqa.pesapal.com/pesapalv3', 'docs' => 'https://developer.pesapal.com',
            'mode' => 'redirect'],
        'mpesa' => [
            'label' => 'M-Pesa — STK push to the client phone (Safaricom)', 'short' => 'M-Pesa',
            'methods' => ['mobile_money'], 'methods_label' => 'Mobile money (STK push)',
            'currencies' => ['KES'],
            'fields' => ['consumer_key' => 'Consumer key', 'consumer_secret' => 'Consumer secret',
                'shortcode' => 'Business short code (Paybill/Till)', 'passkey' => 'Lipa na M-Pesa passkey'],
            'base' => 'https://sandbox.safaricom.co.ke', 'docs' => 'https://developer.safaricom.co.ke',
            'mode' => 'push'],
        'momo' => [
            'label' => 'MTN MoMo — mobile money (Ghana, Uganda, Cameroon, …)', 'short' => 'MTN MoMo',
            'methods' => ['mobile_money'], 'methods_label' => 'Mobile money (MTN MoMo)',
            'currencies' => ['GHS', 'UGX', 'XAF', 'XOF', 'RWF'],
            'fields' => ['subscription_key' => 'Collections subscription key',
                'subscription_secret' => 'Collections subscription secret'],
            'base' => 'https://sandbox.momodeveloper.mtn.com', 'docs' => 'https://momodeveloper.mtn.com',
            'mode' => 'push'],
        'airtel' => [
            'label' => 'Airtel Money — mobile money (Kenya, Tanzania, Uganda, …)', 'short' => 'Airtel Money',
            'methods' => ['mobile_money'], 'methods_label' => 'Mobile money (Airtel Money)',
            'currencies' => ['KES', 'TZS', 'UGX', 'RWF', 'ZMW', 'MGA'],
            'fields' => ['client_id' => 'Client id', 'client_secret' => 'Client secret',
                'country' => 'Country code (KE, TZ, UG, RW, ZM, MG)'],
            'base' => 'https://openapi.airtel.africa', 'docs' => 'https://developers.airtel.africa',
            'mode' => 'push'],
        'flutterwave' => [
            'label' => 'Flutterwave — cards, mobile money, bank transfer', 'short' => 'Flutterwave',
            'methods' => ['card', 'mobile_money', 'bank'], 'methods_label' => 'Card, mobile money or bank',
            'currencies' => ['USD', 'KES', 'NGN', 'GHS', 'UGX', 'TZS', 'ZAR'],
            'fields' => ['public_key' => 'Public key', 'secret_key' => 'Secret key',
                'encryption_key' => 'Encryption key', 'webhook_hash' => 'Webhook secret hash (verif-hash)'],
            'base' => 'https://api.flutterwave.com', 'docs' => 'https://dashboard.flutterwave.com',
            'mode' => 'redirect'],
        'paypal' => [
            'label' => 'PayPal — cards and PayPal balance', 'short' => 'PayPal',
            'methods' => ['card', 'paypal'], 'methods_label' => 'PayPal or card',
            'currencies' => ['USD', 'EUR', 'GBP'],
            'fields' => ['client_id' => 'Client ID', 'secret' => 'Secret'],
            'base' => 'https://api-m.sandbox.paypal.com', 'docs' => 'https://developer.paypal.com',
            'mode' => 'redirect'],
        'sandbox' => [
            'label' => 'Test / sandbox — simulated gateway (no money moves)', 'short' => 'Sandbox',
            'methods' => ['sandbox'], 'methods_label' => 'Simulated payment',
            'currencies' => ['*'], 'fields' => [],
            'base' => '', 'docs' => '', 'mode' => 'local'],
        'bank' => [
            'label' => 'Bank transfer — invoice, confirmed manually', 'short' => 'Bank',
            'methods' => ['bank'], 'methods_label' => 'Bank transfer',
            'currencies' => ['*'],
            'fields' => ['instructions' => 'Bank details shown to the client'],
            'base' => '', 'docs' => '', 'mode' => 'manual'],
    ];
}

/* ------------------------------------------------------- gateway config -- */
function billing_config(bool $withSecrets = false): array {
    $cfg = jdec(setting('billing_json', null), []);
    if (!is_array($cfg)) $cfg = [];
    foreach (payment_gateways() as $code => $g) {
        $row = isset($cfg[$code]) && is_array($cfg[$code]) ? $cfg[$code] : [];
        $row['base'] = !empty($row['base']) ? rtrim((string)$row['base'], '/') : (string)$g['base'];
        $row['env'] = $row['env'] ?? (setting('gateway_mode', 'sandbox') === 'live' ? 'live' : 'sandbox');
        $row['configured'] = gateway_credentials_present($code, $row);
        /* the super admin switches every gateway on or off independently */
        $row['enabled'] = array_key_exists('enabled', $row) ? (bool)$row['enabled'] : true;
        $row['active'] = $row['enabled'] && $row['configured'];
        if (!$withSecrets) {
            foreach (array_keys($g['fields']) as $k) {
                if (in_array($k, ['instructions'], true)) continue;
                if (!empty($row[$k])) $row[$k] = '••••' . substr((string)$row[$k], -4);
            }
        }
        $cfg[$code] = $row;
    }
    return $cfg;
}
/* A gateway is offered to clients when it is switched on AND has its keys. */
function gateway_active(string $code): bool {
    $row = gateway_config($code);
    return !empty($row['active']);
}
function gateway_config(string $code): array {
    $all = billing_config(true);
    return $all[$code] ?? [];
}
function gateway_credentials_present(string $code, ?array $row = null): bool {
    $g = payment_gateways()[$code] ?? null;
    if ($g === null) return false;
    if (in_array($code, ['sandbox', 'bank'], true)) return true;
    if ($row === null) {
        $cfg = jdec(setting('billing_json', null), []);
        $row = is_array($cfg[$code] ?? null) ? $cfg[$code] : [];
    }
    foreach (array_keys($g['fields']) as $k) {
        if (in_array($k, ['ipn_id', 'webhook_hash'], true)) continue;      // optional
        if (empty($row[$k])) return false;
    }
    return true;
}
/* Gateways offered for one plan: switched on, keys present, currency supported. */
function gateways_for_plan(array $plan): array {
    $out = [];
    $cur = strtoupper((string)($plan['currency'] ?? 'USD'));
    foreach (payment_gateways() as $code => $g) {
        $row = gateway_config($code);
        if (empty($row['active'])) continue;                 // disabled by the super admin
        if (!in_array('*', $g['currencies'], true) && !in_array($cur, $g['currencies'], true)) continue;
        $out[] = ['code' => $code, 'label' => $g['label'], 'short' => $g['short'],
            'methods' => $g['methods'], 'methods_label' => $g['methods_label'],
            'mode' => $g['mode'], 'phone_required' => $code === 'mpesa', 'sandbox' => $code === 'sandbox',
            'enabled' => true, 'configured' => !empty($row['configured'])];
    }
    return $out;
}

/* Absolute base URL for gateway callbacks / webhooks / return pages.
   Set billing_json.site_url in the super console when the server runs
   behind a proxy or must expose a public hostname to the gateways. */
function app_base_url(): string {
    $cfg = billing_config(true);
    $saved = trim((string)($cfg['site_url'] ?? ''));
    if ($saved !== '') return rtrim($saved, '/');
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return ($https ? 'https' : 'http') . '://' . (string)$_SERVER['HTTP_HOST'];
    }
    return 'http://127.0.0.1:8099';
}

/* ------------------------------------------------------------ http client - */
/* One small cURL wrapper used by every driver:
   ['ok'=>bool,'status'=>int,'json'=>array|null,'raw'=>string,'error'=>string] */
function pay_http(string $method, string $url, array $opts = []): array {
    $ch = curl_init($url);
    $headers = (array)($opts['headers'] ?? []);
    $headers[] = 'Accept: application/json';
    $body = null;
    if (isset($opts['json'])) {
        $body = jenc($opts['json']);
        $headers[] = 'Content-Type: application/json';
    } elseif (isset($opts['form'])) {
        $body = http_build_query($opts['form'], '', '&');
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif (isset($opts['raw'])) {
        $body = (string)$opts['raw'];
        $headers[] = 'Content-Type: ' . ($opts['content_type'] ?? 'application/json');
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int)($opts['timeout'] ?? 25),
        CURLOPT_CONNECTTIMEOUT => (int)($opts['connect_timeout'] ?? 10),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if (!empty($opts['basic'])) curl_setopt($ch, CURLOPT_USERPWD, (string)$opts['basic']);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return ['ok' => $raw !== false && $status >= 200 && $status < 300,
        'status' => $status, 'json' => is_array($json) ? $json : null,
        'raw' => is_string($raw) ? $raw : '', 'error' => $err];
}
/* Human-readable error text from a gateway response. */
function pay_error_text(array $res, string $fallback = 'The payment gateway refused the request'): string {
    $j = $res['json'] ?? null;
    if (is_array($j)) {
        foreach (['error_description', 'error', 'message', 'errorMessage', 'detail',
                  'error_message', 'statusMessage', 'ResponseDescription', 'error_message'] as $k) {
            if (!empty($j[$k]) && is_string($j[$k])) return $j[$k];
            if (!empty($j[$k]) && is_array($j[$k]) && !empty($j[$k]['message'])) return (string)$j[$k]['message'];
        }
    }
    if (($res['error'] ?? '') !== '') return 'Gateway unreachable: ' . $res['error'];
    return $fallback . ' (HTTP ' . $res['status'] . ')';
}

/* ------------------------------------------------------------- plan data -- */
function plans_all(bool $onlyEnabled = true): array {
    $sql = 'SELECT * FROM plans' . ($onlyEnabled ? ' WHERE enabled = 1' : '') . ' ORDER BY sort_order, price';
    try { $rows = db()->query($sql)->fetchAll(); } catch (Throwable $e) { return []; }
    return $rows;
}
function plan_by_id(string $id): ?array {
    $st = db()->prepare('SELECT * FROM plans WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function plan_by_code(string $code): ?array {
    $st = db()->prepare('SELECT * FROM plans WHERE code = ?');
    $st->execute([$code]);
    return $st->fetch() ?: null;
}
function plan_public(array $p): array {
    $extra = (float)($p['extra_unit_price'] ?? 0);
    $features = jdec($p['features_json'] ?? null, []);
    /* make the flexibility visible on every plan card */
    if ($extra > 0) {
        $features[] = 'Extra devices beyond ' . (int)$p['unit_limit'] . ': '
            . number_format($extra, 2) . ' ' . $p['currency'] . ' each per '
            . ($p['interval'] === 'year' ? 'year' : 'month');
    }
    return ['id' => $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'tagline' => $p['tagline'],
        'price' => (float)$p['price'], 'currency' => $p['currency'], 'interval' => $p['interval'],
        'interval_label' => ($p['interval'] === 'year' ? 'year' : 'month'),
        'unit_limit' => (int)$p['unit_limit'], 'extra_unit_price' => $extra,
        'storage_days' => (int)$p['storage_days'],
        'trial_days' => (int)$p['trial_days'], 'features' => $features,
        'enabled' => (int)$p['enabled'], 'sort_order' => (int)$p['sort_order']];
}
/* Paid period granted by one successful payment for this plan. */
function plan_period_days(array $plan): int {
    return ($plan['interval'] ?? 'month') === 'year' ? 365 : 30;
}

/* ----------------------------------------------------------------- money -- */
/* Currencies Stripe/PayPal treat as zero-decimal (amount is not ×100). */
function pay_zero_decimal(): array {
    return ['JPY', 'KRW', 'VND', 'CLP', 'XOF', 'XAF', 'UGX', 'RWF', 'MGA', 'PYG', 'BIF', 'DJF',
        'GNF', 'ISK', 'KMF', 'VUV', 'XPF'];
}
function pay_minor_units($amount, string $currency): int {
    $amount = (float)$amount;
    return in_array(strtoupper($currency), pay_zero_decimal(), true)
        ? (int)round($amount) : (int)round($amount * 100);
}
function pay_money($amount, string $currency = ''): string {
    return strtoupper($currency === '' ? '' : $currency . ' ') . number_format((float)$amount, 2);
}
/* Invoice numbers are unique per prefix and day. The sequence looks at the
   highest number already used today, so deleting an invoice can never make
   the next one collide (payment_create() also retries on a duplicate). */
function invoice_number(int $bump = 0): string {
    $prefix = strtoupper(trim((string)setting('invoice_prefix', 'KIVU'))) ?: 'KIVU';
    $day = date('Ymd');
    $like = $prefix . '-' . $day . '-%';
    $st = db()->prepare('SELECT MAX(CAST(SUBSTRING_INDEX(invoice_no, \'-\', -1) AS UNSIGNED)) FROM payments
                         WHERE invoice_no LIKE ?');
    $st->execute([$like]);
    $seq = (int)$st->fetchColumn() + 1 + $bump;
    return $prefix . '-' . $day . '-' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
}

/* --------------------------------------------------- subscriptions/trial -- */
function account_by_id(string $id): ?array {
    $st = db()->prepare('SELECT * FROM accounts WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function subscription_current(string $accountId): ?array {
    try {
        $st = db()->prepare('SELECT * FROM subscriptions WHERE account_id = ?
                             ORDER BY created_at DESC, id DESC LIMIT 1');
        $st->execute([$accountId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}
/* Days of payment grace after a period ends before an account is cut off. */
function billing_grace_days(): int { return 7; }
/* Free-trial length for new accounts (super admin can change it). */
function trial_days(): int { return max(1, (int)setting('trial_days', 5)); }

/* Starts a subscription row for a brand-new account: free trial, 5 days. */
function subscription_start_trial(string $accountId, int $days): string {
    $id = uid('sub');
    $end = date('Y-m-d H:i:s', time() + $days * 86400);
    db()->prepare('INSERT INTO subscriptions (id,account_id,plan_id,status,gateway,trial_start,trial_end,
                   created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $accountId, null, 'trialing', null, now_dt(), $end, now_dt(), now_dt()]);
    return $id;
}

/* Authoritative billing state for one account. Reads the newest subscription,
   derives the effective status from the calendar and persists transitions so
   the accounts list, the consoles and the API always agree. */
function billing_state(string $accountId, ?array $account = null): array {
    $acc = $account ?? account_by_id($accountId);
    if (!$acc) {
        return ['account_id' => $accountId, 'status' => 'expired', 'allowed' => false,
            'reason' => 'Account not found', 'plan_id' => null, 'plan' => null, 'plan_code' => null,
            'trial_ends_at' => null, 'trial_days_left' => 0, 'period_start' => null, 'period_end' => null,
            'days_left' => 0, 'grace_days_left' => 0, 'cancel_at_period_end' => false, 'gateway' => null,
            'currency' => 'USD', 'units_limit' => 0, 'storage_days' => 0, 'subscription_id' => null];
    }
    $now = time();
    $sub = subscription_current($accountId);
    $status = (string)(($acc['status'] ?? '') ?: 'trialing');
    $planId = $acc['plan_id'] ?? null;
    $trialRaw = $acc['trial_ends_at'] ?? null;
    $trialEnd = $trialRaw ? strtotime((string)$trialRaw) : null;
    $periodEnd = $sub && $sub['period_end'] ? strtotime((string)$sub['period_end']) : null;
    $cancel = $sub ? (int)$sub['cancel_at_period_end'] === 1 : false;

    /* derive the effective status from the calendar */
    if ($status === 'comp' || $status === 'suspended') {
        /* manual states persist until the super admin changes them */
    } elseif ($periodEnd !== null && $periodEnd > $now) {
        $status = 'active';
    } elseif (in_array($status, ['active', 'past_due'], true)) {
        $status = ($periodEnd === null || ($now - $periodEnd) > billing_grace_days() * 86400)
            ? 'expired' : 'past_due';
    } else {
        $status = ($trialEnd === null || $trialEnd < $now) ? 'expired' : 'trialing';
    }

    $trialLeft = $trialEnd === null ? 0 : (int)max(0, ceil(($trialEnd - $now) / 86400));
    $daysLeft = $periodEnd === null ? 0 : (int)ceil(($periodEnd - $now) / 86400);
    $graceLeft = ($status === 'past_due' && $periodEnd !== null)
        ? (int)max(0, billing_grace_days() - floor(($now - $periodEnd) / 86400)) : 0;
    $allowed = in_array($status, ['trialing', 'active', 'past_due', 'comp'], true);

    if ($status !== (string)($acc['status'] ?? '')) {    // persist the transition once
        try {
            db()->prepare('UPDATE accounts SET status = ? WHERE id = ?')->execute([$status, $accountId]);
            if ($sub && in_array($status, ['expired', 'past_due'], true) && $sub['status'] !== $status) {
                db()->prepare('UPDATE subscriptions SET status = ?, updated_at = ? WHERE id = ?')
                    ->execute([$status, now_dt(), $sub['id']]);
            }
            audit('subscription_' . $status, 'account', $accountId, ['plan_id' => $planId]);
        } catch (Throwable $e) { /* never break a read */ }
    }

    $plan = $planId ? plan_by_id((string)$planId) : null;
    $reason = '';
    if (!$allowed) {
        $reason = $status === 'suspended'
            ? 'This account is suspended — contact support'
            : ($periodEnd !== null
                ? 'Your subscription has ended — renew to keep tracking'
                : 'Your ' . trial_days() . '-day free trial has ended — choose a plan to keep tracking');
    }
    return ['account_id' => $accountId, 'status' => $status, 'allowed' => $allowed, 'reason' => $reason,
        'plan_id' => $planId, 'plan' => $plan ? plan_public($plan) : null,
        'plan_code' => $acc['plan'] ?? null, 'currency' => $acc['currency'] ?? 'USD',
        'units_limit' => (int)($acc['units_limit'] ?? 0), 'storage_days' => (int)($acc['storage_days'] ?? 0),
        'trial_ends_at' => $trialRaw ? iso($trialRaw) : null, 'trial_days_left' => $trialLeft,
        'period_start' => $sub ? iso($sub['period_start']) : null,
        'period_end' => $sub ? iso($sub['period_end']) : null, 'days_left' => max(0, $daysLeft),
        'grace_days_left' => $graceLeft, 'cancel_at_period_end' => $cancel,
        'gateway' => $sub['gateway'] ?? null, 'subscription_id' => $sub['id'] ?? null];
}

/* Enforcement used by the API: the super admin is never blocked; account
   users are blocked (reads stay open) once the trial/period has ended. */
function account_access(?array $user): array {
    if ($user === null) return ['allowed' => true, 'status' => 'comp', 'reason' => '', 'super' => true];
    if (is_super($user)) return ['allowed' => true, 'status' => 'comp', 'reason' => '', 'super' => true];
    $accountId = (string)($user['account_id'] ?? '');
    if ($accountId === '') return ['allowed' => true, 'status' => 'comp', 'reason' => '', 'super' => false];
    $state = billing_state($accountId);
    $state['super'] = false;
    return $state;
}

/* ----------------------------------------------------- plan application -- */
/* Copies the plan's limits onto the account (unit limit, storage window). */
function billing_apply_plan(string $accountId, array $plan): void {
    db()->prepare('UPDATE accounts SET plan_id = ?, plan = ?, units_limit = ?, storage_days = ? WHERE id = ?')
        ->execute([$plan['id'], $plan['code'], (int)$plan['unit_limit'], (int)$plan['storage_days'], $accountId]);
    audit('plan_applied', 'account', $accountId, ['plan' => $plan['code'], 'units_limit' => (int)$plan['unit_limit']]);
}
/* Optional hard stop: refuse new units once the plan limit is reached. */
function billing_unit_limit(string $accountId): int {
    $acc = account_by_id($accountId);
    return $acc ? (int)$acc['units_limit'] : 0;
}
function billing_units_used(string $accountId): int {
    $st = db()->prepare('SELECT COUNT(*) FROM units WHERE account_id = ?');
    $st->execute([$accountId]);
    return (int)$st->fetchColumn();
}
/* How many devices the account runs beyond its plan limit, and what they
   cost this billing period — the basis of the flexible overage billing.
   The account's own limit wins (super admins may override it per account). */
function billing_extra_units(string $accountId, ?array $plan): int {
    if (!$plan) return 0;
    $acc = account_by_id($accountId);
    $limit = $acc ? (int)$acc['units_limit'] : (int)$plan['unit_limit'];
    if ($limit <= 0) return 0;                               // unlimited
    return max(0, billing_units_used($accountId) - $limit);
}
function billing_overage_amount(string $accountId, ?array $plan): float {
    if (!$plan) return 0.0;
    $extra = billing_extra_units($accountId, $plan);
    $price = (float)($plan['extra_unit_price'] ?? 0);
    return $extra > 0 && $price > 0 ? round($extra * $price, 2) : 0.0;
}
/* Shape for the billing page / checkout message. */
function billing_overage_shape(array $acc, ?array $plan): array {
    $extra = billing_extra_units((string)$acc['id'], $plan);
    $price = $plan ? (float)($plan['extra_unit_price'] ?? 0) : 0.0;
    return ['extra_units' => $extra,
        'unit_limit' => (int)($acc['units_limit'] ?? ($plan ? $plan['unit_limit'] : 0)),
        'extra_unit_price' => $price,
        'amount' => ($extra > 0 && $price > 0) ? round($extra * $price, 2) : 0.0,
        'currency' => $plan ? (string)$plan['currency'] : (string)($acc['currency'] ?? 'USD')];
}

function billing_check_unit_limit(string $accountId): void {
    $limit = billing_unit_limit($accountId);
    if ($limit <= 0) return;                                 // unlimited
    $used = billing_units_used($accountId);
    if ($used < $limit) return;
    /* a paid account may exceed the plan limit — the extra devices are billed
       automatically (plan extra_unit_price), which keeps billing flexible */
    $acc = account_by_id($accountId);
    if ($acc) {
        $state = billing_state($accountId, $acc);
        if ($state['status'] === 'active') {
            $plan = $acc['plan_id'] ? plan_by_id((string)$acc['plan_id']) : null;
            if ($plan && (float)($plan['extra_unit_price'] ?? 0) > 0) return;
        }
    }
    throw new ApiError('Your plan allows ' . $limit . ' unit(s) and you already have ' . $used
        . ($acc && ($acc['status'] ?? '') === 'trialing'
            ? ' — start paying to keep adding devices, or choose a bigger plan in Billing.'
            : ' — upgrade the plan in Billing (this plan does not sell extra devices).'), 402);
}

/* --------------------------------------------------------------- payments - */
function payment_by_id(string $id): ?array {
    $st = db()->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function payment_by_ref(string $gateway, string $ref): ?array {
    $st = db()->prepare('SELECT * FROM payments WHERE gateway = ? AND gateway_ref = ? ORDER BY created_at DESC LIMIT 1');
    $st->execute([$gateway, $ref]);
    return $st->fetch() ?: null;
}
function payment_public(array $p): array {
    $meta = jdec($p['meta_json'] ?? null, []);
    return ['id' => $p['id'], 'invoice_no' => $p['invoice_no'], 'account_id' => $p['account_id'],
        'plan_id' => $p['plan_id'] ?? null, 'gateway' => $p['gateway'], 'method' => $p['method'] ?? null,
        'amount' => (float)$p['amount'], 'currency' => $p['currency'], 'status' => $p['status'],
        'period_days' => (int)($p['period_days'] ?? 0), 'payer_email' => $p['payer_email'] ?? null,
        'payer_name' => $p['payer_name'] ?? null, 'payer_phone' => $p['payer_phone'] ?? null,
        'gateway_ref' => $p['gateway_ref'] ?? null, 'checkout_url' => $p['checkout_url'] ?? null,
        'failure_reason' => $p['failure_reason'] ?? null,
        'receipt' => $meta['receipt'] ?? null, 'verified' => $meta['verified'] ?? null,
        'created_at' => iso($p['created_at'] ?? null), 'paid_at' => iso($p['paid_at'] ?? null)];
}
/* Creates the invoice (pending payment) a client is about to settle.
   Retries on the (very unlikely) invoice-number race instead of failing. */
function payment_create(array $account, ?array $plan, string $gateway, array $opts = []): array {
    $cur = strtoupper((string)($plan['currency'] ?? $account['currency'] ?? 'USD'));
    /* the invoice covers the plan plus any extra devices beyond the limit
       (flexible overage — paid accounts are never hard-blocked) */
    $overage = $plan ? billing_overage_amount((string)$account['id'], $plan) : 0.0;
    $amount = $plan ? round((float)$plan['price'] + $overage, 2) : (float)($opts['amount'] ?? 0);
    $extraUnits = $plan ? billing_extra_units((string)$account['id'], $plan) : 0;
    $lastError = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $id = uid('pay');
        $row = [
            'id' => $id, 'invoice_no' => invoice_number($attempt), 'account_id' => $account['id'],
            'plan_id' => $plan['id'] ?? null, 'gateway' => $gateway,
            'method' => $opts['method'] ?? (payment_gateways()[$gateway]['methods'][0] ?? null),
            'amount' => $amount, 'currency' => $cur,
            'status' => 'pending',
            'period_days' => $plan ? plan_period_days($plan) : (int)($opts['period_days'] ?? 30),
            'payer_name' => $opts['payer_name'] ?? ($account['contact_name'] ?? $account['name']),
            'payer_email' => $opts['payer_email'] ?? ($account['billing_email'] ?? $account['contact_email']),
            'payer_phone' => $opts['payer_phone'] ?? ($account['contact_phone'] ?? null),
            'gateway_ref' => null, 'checkout_url' => null, 'failure_reason' => null, 'paid_at' => null,
            'meta_json' => jenc(array_merge(
                ['plan_code' => $plan['code'] ?? null, 'interval' => $plan['interval'] ?? null,
                 'requested_by' => $opts['requested_by'] ?? null, 'ip' => ip_addr()]
                + ($extraUnits > 0 ? ['extra_units' => $extraUnits, 'overage' => $overage] : []),
                !empty($opts['save_method']) ? ['save_method' => true] : [],
                !empty($opts['auto_renew']) ? ['auto_renew' => true] : [],
                is_array($opts['meta'] ?? null) ? $opts['meta'] : [])),
            'created_at' => now_dt(), 'updated_at' => now_dt(),
        ];
        $cols = array_keys($row);
        try {
            db()->prepare('INSERT INTO payments (`' . implode('`,`', $cols) . '`) VALUES ('
                . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
            return $row;
        } catch (PDOException $e) {
            $lastError = $e;
            if (strpos($e->getMessage(), '1062') === false) throw $e;   // not a duplicate key
        }
    }
    throw new ApiError('Could not allocate an invoice number: ' . ($lastError ? $lastError->getMessage() : ''), 500);
}
function payment_touch(string $id, array $fields): void {
    if (!$fields) return;
    $fields['updated_at'] = now_dt();
    $sets = []; $vals = [];
    foreach ($fields as $k => $v) { $sets[] = "`$k` = ?"; $vals[] = $v; }
    $vals[] = $id;
    db()->prepare('UPDATE payments SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
}

/* Records a gateway callback. Returns true when it is new, false when the
   event was already processed (gateways retry — never bill/extend twice). */
function billing_record_event(string $gateway, string $eventRef, ?string $kind = null,
                              ?string $status = null, ?string $paymentId = null, ?array $payload = null): bool {
    try {
        $st = db()->prepare('INSERT INTO payment_events (gateway,event_ref,kind,status,payment_id,payload_json,created_at)
                             VALUES (?,?,?,?,?,?,?)');
        $st->execute([$gateway, $eventRef, $kind, $status, $paymentId,
            $payload ? jenc($payload) : null, now_dt()]);
        return true;
    } catch (Throwable $e) {
        return false;      // duplicate key = already handled
    }
}

/* Marks an invoice paid and activates/extends the subscription. Idempotent:
   calling it again for an already-paid invoice changes nothing. */
function billing_mark_paid(array $payment, array $ctx = []): array {
    $fresh = payment_by_id((string)$payment['id']) ?? $payment;
    if ($fresh['status'] === 'paid') {
        $sub = subscription_current((string)$fresh['account_id']);
        return ['already_paid' => true, 'payment_id' => $fresh['id'], 'invoice_no' => $fresh['invoice_no'],
            'period_end' => $sub ? iso($sub['period_end']) : null];
    }
    $account = account_by_id((string)$fresh['account_id']);
    if (!$account) {
        /* the invoice may belong to a reseller portal (annual, full-year pay) */
        $preMeta = jdec($fresh['meta_json'] ?? null, []);
        $portalId = (string)($preMeta['portal_id'] ?? '');
        if ($portalId !== '' && portal_by_id($portalId)) {
            $ref2 = (string)($ctx['gateway_ref'] ?? $fresh['gateway_ref'] ?? '');
            payment_touch((string)$fresh['id'], ['status' => 'paid', 'paid_at' => now_dt(),
                'meta_json' => jenc(array_merge($preMeta, $ctx['meta'] ?? [],
                    $ref2 !== '' ? ['receipt' => $ref2] : []))]);
            $portal = portal_activate($portalId, 365);
            audit('payment_received', 'payment', (string)$fresh['id'],
                ['portal_id' => $portalId, 'amount' => (float)$fresh['amount'],
                 'currency' => $fresh['currency'], 'invoice' => $fresh['invoice_no']]);
            return ['already_paid' => false, 'payment_id' => $fresh['id'],
                'invoice_no' => $fresh['invoice_no'], 'portal_id' => $portalId,
                'portal_period_end' => $portal ? iso($portal['period_end']) : null];
        }
    }
    need($account, 'Account not found for this payment', 404);
    $plan = $fresh['plan_id'] ? plan_by_id((string)$fresh['plan_id']) : null;
    $days = max(1, (int)$fresh['period_days']);

    /* extend from the current period end when it is still in the future */
    $sub = subscription_current((string)$account['id']);
    $base = time();
    if ($sub && !empty($sub['period_end'])) {
        $cur = strtotime((string)$sub['period_end']);
        if ($cur !== false && $cur > $base) $base = $cur;
    }
    $start = date('Y-m-d H:i:s', $base);
    $end = date('Y-m-d H:i:s', $base + $days * 86400);
    $ref = (string)($ctx['gateway_ref'] ?? $fresh['gateway_ref'] ?? '');
    /* the gateway reference stays stable so a retried callback can still find
       this invoice; the gateway's own receipt/transaction id goes into meta. */
    $fields = ['status' => 'paid', 'paid_at' => now_dt(),
        'method' => $ctx['method'] ?? $fresh['method'],
        'meta_json' => jenc(array_merge(jdec($fresh['meta_json'] ?? null, []), $ctx['meta'] ?? [],
            $ref !== '' ? ['receipt' => $ref] : []))];
    if (trim((string)($fresh['gateway_ref'] ?? '')) === '' && $ref !== '') $fields['gateway_ref'] = $ref;
    payment_touch((string)$fresh['id'], $fields);

    /* store the reusable payment method the client saved at checkout */
    $paidMeta = jdec($fields['meta_json'], []);
    if (!empty($paidMeta['save_method'])) {
        $pmIn = is_array($paidMeta['pm'] ?? null) ? $paidMeta['pm'] : [];
        if (!empty($paidMeta['pm_token'])) $pmIn['token'] = (string)$paidMeta['pm_token'];
        if (!empty($paidMeta['pm_brand'])) $pmIn['brand'] = (string)$paidMeta['pm_brand'];
        if (!empty($paidMeta['pm_last4'])) $pmIn['last4'] = (string)$paidMeta['pm_last4'];
        if (!empty($paidMeta['pm_customer'])) $pmIn['customer_ref'] = (string)$paidMeta['pm_customer'];
        payment_method_save($account, (string)$fresh['gateway'], $pmIn);
    }

    if ($sub && $sub['status'] === 'trialing' && empty($sub['period_end'])) {
        /* the first payment converts the trial row in place */
        db()->prepare('UPDATE subscriptions SET plan_id=?, status=?, gateway=?, gateway_ref=?, trial_end=?,
                       period_start=?, period_end=?, cancel_at_period_end=0, updated_at=? WHERE id=?')
            ->execute([$fresh['plan_id'], 'active', $fresh['gateway'], $ref, now_dt(), $start, $end,
                now_dt(), $sub['id']]);
    } else {
        db()->prepare('INSERT INTO subscriptions (id,account_id,plan_id,status,gateway,gateway_ref,
                       period_start,period_end,cancel_at_period_end,created_at,updated_at)
                       VALUES (?,?,?,?,?,?,?,?,0,?,?)')
            ->execute([uid('sub'), $account['id'], $fresh['plan_id'], 'active', $fresh['gateway'], $ref,
                $start, $end, now_dt(), now_dt()]);
        if ($sub && $sub['status'] === 'trialing') {
            db()->prepare('UPDATE subscriptions SET status = ?, updated_at = ? WHERE id = ?')
                ->execute(['active', now_dt(), $sub['id']]);
        }
    }
    db()->prepare('UPDATE accounts SET status = ?, trial_ends_at = NULL WHERE id = ?')
        ->execute(['active', $account['id']]);
    if ($plan) billing_apply_plan((string)$account['id'], $plan);
    audit('payment_received', 'payment', (string)$fresh['id'], ['gateway' => $fresh['gateway'],
        'amount' => (float)$fresh['amount'], 'currency' => $fresh['currency'],
        'invoice' => $fresh['invoice_no'], 'period_end' => $end]);
    billing_queue_receipt($account, $fresh, $plan);
    return ['already_paid' => false, 'payment_id' => $fresh['id'], 'invoice_no' => $fresh['invoice_no'],
        'period_start' => iso($start), 'period_end' => iso($end), 'days' => $days,
        'plan' => $plan ? $plan['code'] : null, 'account_id' => $account['id']];
}
function billing_mark_failed(array $payment, string $reason, array $meta = []): void {
    payment_touch((string)$payment['id'], ['status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 250),
        'meta_json' => jenc(array_merge(jdec($payment['meta_json'] ?? null, []), $meta))]);
    audit('payment_failed', 'payment', (string)$payment['id'], ['gateway' => $payment['gateway'],
        'reason' => mb_substr($reason, 0, 200)]);
}
/* Renewal / dunning housekeeping, called from /api/tasks/run. Automatic
   billing runs first (charging saved methods), then the states are counted. */
function billing_renewals(): array {
    $auto = billing_auto_renew();
    $expired = 0; $pastDue = 0;
    $portalsExpired = portals_expire();                      // reseller portals: full-year pay
    foreach (db()->query('SELECT id, status FROM accounts WHERE enabled = 1')->fetchAll() as $a) {
        $before = (string)$a['status'];
        $state = billing_state((string)$a['id']);
        if ($state['status'] === $before) continue;
        if ($state['status'] === 'expired') $expired++;
        if ($state['status'] === 'past_due') $pastDue++;
    }
    return ['expired' => $expired, 'past_due' => $pastDue,
        'renewed' => $auto['renewed'], 'declined' => $auto['declined'],
        'pending' => $auto['pending'], 'renewal_attempted' => $auto['attempted'],
        'renewal_skipped' => $auto['reasons'], 'portals_expired' => $portalsExpired];
}
/* Receipt queue: outbound e-mail is not wired (see README), so the rendered
   receipt is journalled exactly like notification e-mails are. */
function billing_queue_receipt(array $account, array $payment, ?array $plan): void {
    try {
        db()->prepare('INSERT INTO audit_log (account_id,user_id,username,ts,action,entity,entity_id,details_json,ip)
                       VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$account['id'], null, 'billing', now_dt(), 'receipt_queued', 'payment', $payment['id'],
                jenc(['to' => $payment['payer_email'], 'invoice' => $payment['invoice_no'],
                    'amount' => (float)$payment['amount'], 'currency' => $payment['currency'],
                    'plan' => $plan['name'] ?? null]), 'system']);
    } catch (Throwable $e) { /* receipts are best-effort */ }
}

/* ------------------------------------------------------------- checkout -- */
/* Creates the invoice and hands the client to the chosen gateway.
   Returns ['payment'=>…, 'checkout_url'=>…, 'gateway_ref'=>…, 'pending'=>bool]. */
function checkout_start(array $account, ?array $plan, string $gateway, array $opts = []): array {
    ensure_schema();
    $gw = payment_gateways()[$gateway] ?? null;
    need($gw, 'Unknown payment gateway: ' . $gateway, 400);
    $cfg = gateway_config($gateway);
    /* switched off by the super admin — refuse before anything else */
    need(empty($cfg['enabled']) ? false : true,
        $gw['short'] . ' payments are switched off. The platform administrator can enable this method '
        . 'under Accounts → Plans & billing.', 400);
    need(gateway_credentials_present($gateway, $cfg),
        $gw['short'] . ' is not configured yet — the platform administrator must add its API keys first.', 503);
    if ($plan) {
        $cur = strtoupper((string)$plan['currency']);
        need(in_array('*', $gw['currencies'], true) || in_array($cur, $gw['currencies'], true),
            $gw['short'] . ' cannot charge ' . $cur . '. Choose a plan in a supported currency or another payment method.', 400);
    }
    $phone = preg_replace('/[^0-9+]/', '', (string)($opts['phone'] ?? ''));
    if (in_array($gateway, ['mpesa', 'momo', 'airtel'], true)) {
        need(strlen(preg_replace('/[^0-9]/', '', (string)$phone)) >= 9,
            'Enter the mobile-money number that should receive the payment prompt', 400);
        $opts['payer_phone'] = $phone;
    }
    /* the first payment normally stores a reusable method so the renewal can
       be charged automatically — pass save_method=false to opt out */
    $opts['save_method'] = array_key_exists('save_method', $opts) ? (bool)$opts['save_method'] : true;
    $payment = payment_create($account, $plan, $gateway, $opts);
    try {
        $res = pay_driver_checkout($gateway, $payment, $plan, $cfg, $opts);
    } catch (ApiError $e) {
        billing_mark_failed($payment, $e->getMessage());
        throw $e;
    } catch (Throwable $e) {
        billing_mark_failed($payment, $e->getMessage());
        throw new ApiError('Payment could not be started: ' . $e->getMessage(), 502);
    }
    if (!empty($res['gateway_ref']) || !empty($res['checkout_url'])) {
        payment_touch((string)$payment['id'], ['gateway_ref' => $res['gateway_ref'] ?? null,
            'checkout_url' => $res['checkout_url'] ?? null]);
        $payment['gateway_ref'] = $res['gateway_ref'] ?? null;
        $payment['checkout_url'] = $res['checkout_url'] ?? null;
    }
    if (is_array($res['pm'] ?? null)) {
        /* the driver already knows the instrument (M-Pesa number …) — keep it
           with the invoice so billing_mark_paid can store it once paid */
        $meta = jdec($payment['meta_json'] ?? null, []);
        $meta['pm'] = $res['pm'];
        payment_touch((string)$payment['id'], ['meta_json' => jenc($meta)]);
        $payment['meta_json'] = jenc($meta);
    }
    audit('checkout_started', 'payment', (string)$payment['id'], ['gateway' => $gateway,
        'invoice' => $payment['invoice_no'], 'amount' => (float)$payment['amount'],
        'currency' => $payment['currency'], 'plan' => $plan['code'] ?? null]);
    return ['payment' => payment_public($payment), 'checkout_url' => $res['checkout_url'] ?? null,
        'gateway_ref' => $res['gateway_ref'] ?? null, 'pending' => !empty($res['pending']),
        'message' => $res['message'] ?? ''];
}
function pay_driver_checkout(string $gateway, array $pay, ?array $plan, array $cfg, array $opts): array {
    switch ($gateway) {
        case 'sandbox': return pay_sandbox_checkout($pay);
        case 'bank': return pay_bank_checkout($pay, $cfg);
        case 'stripe': return pay_stripe_checkout($pay, $plan, $cfg);
        case 'pesapal': return pay_pesapal_checkout($pay, $plan, $cfg);
        case 'mpesa': return pay_mpesa_checkout($pay, $plan, $cfg, (string)($opts['phone'] ?? ''));
        case 'momo': return pay_momo_checkout($pay, $plan, $cfg, (string)($opts['phone'] ?? ''));
        case 'airtel': return pay_airtel_checkout($pay, $plan, $cfg, (string)($opts['phone'] ?? ''));
        case 'flutterwave': return pay_flutterwave_checkout($pay, $plan, $cfg);
        case 'paypal': return pay_paypal_checkout($pay, $plan, $cfg);
    }
    throw new ApiError('No driver for the ' . $gateway . ' gateway', 500);
}
/* Built-in simulator: the client lands on our own /pay/<invoice> page. */
function pay_sandbox_checkout(array $pay): array {
    return ['checkout_url' => app_base_url() . '/pay/' . rawurlencode((string)$pay['id']),
        'gateway_ref' => 'sbx_' . $pay['id'], 'pending' => true,
        'message' => 'Sandbox payment created — confirm it on the simulated gateway page.'];
}
/* Manual bank transfer: the invoice stays pending until the super admin
   confirms it (Accounts → Plans & billing → payments → Mark paid). */
function pay_bank_checkout(array $pay, array $cfg): array {
    return ['checkout_url' => app_base_url() . '/billing?payment=' . rawurlencode((string)$pay['id'])
            . '&status=bank', 'gateway_ref' => null, 'pending' => true,
        'message' => 'Invoice ' . $pay['invoice_no'] . ' created. '
            . (trim((string)($cfg['instructions'] ?? '')) !== ''
                ? trim((string)$cfg['instructions'])
                : 'Transfer the amount and quote the invoice number; your account is activated when the payment is confirmed.')];
}

/* ----------------------------------------------------------- stripe (card) - */
function pay_stripe_checkout(array $pay, ?array $plan, array $cfg): array {
    $base = rtrim((string)($cfg['base'] ?? ''), '/') ?: 'https://api.stripe.com';
    $cur = strtoupper((string)$pay['currency']);
    $product = ($plan['name'] ?? 'KIVU Tracker subscription')
        . ($plan ? ' — ' . ($plan['interval'] === 'year' ? 'annual' : 'monthly') : '');
    $form = [
        'mode' => 'payment',
        'success_url' => app_base_url() . '/billing?payment=' . rawurlencode((string)$pay['id']) . '&status=success',
        'cancel_url' => app_base_url() . '/billing?payment=' . rawurlencode((string)$pay['id']) . '&status=cancel',
        'client_reference_id' => (string)$pay['id'],
        'line_items[0][quantity]' => 1,
        'line_items[0][price_data][currency]' => strtolower($cur),
        'line_items[0][price_data][unit_amount]' => pay_minor_units($pay['amount'], $cur),
        'line_items[0][price_data][product_data][name]' => $product,
        'metadata[payment_id]' => (string)$pay['id'],
        'metadata[account_id]' => (string)$pay['account_id'],
        'metadata[invoice_no]' => (string)$pay['invoice_no'],
        'payment_intent_data[description]' => 'Invoice ' . $pay['invoice_no'] . ' — ' . $product,
    ];
    if (!empty($pay['payer_email'])) $form['customer_email'] = (string)$pay['payer_email'];
    if (!empty($plan['code'])) $form['metadata[plan_code]'] = (string)$plan['code'];
    $res = pay_http('POST', $base . '/v1/checkout/sessions', ['form' => $form,
        'headers' => ['Authorization: Bearer ' . (string)($cfg['secret_key'] ?? '')]]);
    if (!$res['ok']) throw new ApiError('Stripe: ' . pay_error_text($res), 502);
    $url = (string)($res['json']['url'] ?? '');
    need($url !== '', 'Stripe did not return a checkout URL', 502);
    return ['checkout_url' => $url, 'gateway_ref' => (string)($res['json']['id'] ?? ''), 'pending' => true,
        'message' => 'Opening the secure Stripe card page (Visa / Mastercard / Amex)…'];
}
/* Verifies the Stripe-Signature header: HMAC-SHA256 of "<t>.<body>". */
function pay_stripe_verify(array $cfg, string $rawBody, string $sigHeader): bool {
    $secret = (string)($cfg['webhook_secret'] ?? '');
    if ($secret === '') return false;
    $parts = [];
    foreach (explode(',', $sigHeader) as $kv) {
        $p = explode('=', $kv, 2);
        if (count($p) === 2) $parts[$p[0]][] = $p[1];
    }
    $t = (int)($parts['t'][0] ?? 0);
    $sig = (string)($parts['v1'][0] ?? '');
    if ($t === 0 || $sig === '') return false;
    $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
    return hash_equals($expected, $sig) && abs(time() - $t) <= 300;
}
/* Signs a Stripe-style payload — used by the test suite and the tools. */
function pay_stripe_sign(string $secret, string $rawBody, ?int $ts = null): string {
    $t = $ts ?? time();
    return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $rawBody, $secret);
}

/* ------------------------------------------------------------- pesapal ---- */
function pay_pesapal_token(array $cfg): string {
    static $cache = [];
    $key = (string)($cfg['consumer_key'] ?? '');
    if (isset($cache[$key]) && $cache[$key]['exp'] > time()) return $cache[$key]['token'];
    $res = pay_http('POST', rtrim((string)$cfg['base'], '/') . '/api/Auth/RequestToken',
        ['json' => ['consumer_key' => $key, 'consumer_secret' => (string)($cfg['consumer_secret'] ?? '')]]);
    if (!$res['ok'] || empty($res['json']['token'])) {
        throw new ApiError('Pesapal authentication failed: ' . pay_error_text($res), 502);
    }
    $cache[$key] = ['token' => (string)$res['json']['token'], 'exp' => time() + 240];
    return $cache[$key]['token'];
}
/* Registers our IPN endpoint once and remembers the id in billing_json. */
function pay_pesapal_ipn_id(array $cfg): string {
    if (trim((string)($cfg['ipn_id'] ?? '')) !== '') return (string)$cfg['ipn_id'];
    $token = pay_pesapal_token($cfg);
    $res = pay_http('POST', rtrim((string)$cfg['base'], '/') . '/api/URLSetup/RegisterIPN',
        ['json' => ['url' => app_base_url() . '/api/webhooks/pesapal', 'ipn_notification_type' => 'GET'],
         'headers' => ['Authorization: Bearer ' . $token]]);
    $id = (string)($res['json']['ipn_id'] ?? '');
    if (!$res['ok'] || $id === '') throw new ApiError('Pesapal IPN registration failed: ' . pay_error_text($res), 502);
    $all = jdec(setting('billing_json', null), []);
    if (!is_array($all)) $all = [];
    $all['pesapal']['ipn_id'] = $id;
    settings_save(['billing_json' => jenc($all)]);
    return $id;
}
function pay_pesapal_checkout(array $pay, ?array $plan, array $cfg): array {
    $base = rtrim((string)$cfg['base'], '/');
    $token = pay_pesapal_token($cfg);
    $ipn = pay_pesapal_ipn_id($cfg);
    $nameParts = preg_split('/\s+/', trim((string)($pay['payer_name'] ?? 'Client'))) ?: ['Client'];
    $body = [
        'id' => (string)$pay['invoice_no'],
        'currency' => strtoupper((string)$pay['currency']),
        'amount' => (float)$pay['amount'],
        'description' => ($plan['name'] ?? 'KIVU Tracker subscription') . ' — invoice ' . $pay['invoice_no'],
        'callback_url' => app_base_url() . '/billing?payment=' . rawurlencode((string)$pay['id']) . '&status=pesapal',
        'notification_id' => $ipn,
        'billing_address' => ['email_address' => (string)($pay['payer_email'] ?? ''),
            'phone_number' => (string)($pay['payer_phone'] ?? ''),
            'first_name' => (string)$nameParts[0], 'last_name' => (string)($nameParts[1] ?? '')],
    ];
    $res = pay_http('POST', $base . '/api/Transactions/SubmitOrderRequest',
        ['json' => $body, 'headers' => ['Authorization: Bearer ' . $token]]);
    if (!$res['ok']) throw new ApiError('Pesapal: ' . pay_error_text($res), 502);
    $url = (string)($res['json']['redirect_url'] ?? '');
    need($url !== '', 'Pesapal did not return a redirect URL', 502);
    return ['checkout_url' => $url, 'gateway_ref' => (string)($res['json']['order_tracking_id'] ?? ''),
        'pending' => true, 'message' => 'Opening the Pesapal page (card or mobile money)…'];
}
/* Authoritative Pesapal status check (IPN handler + poll endpoint). */
function pay_pesapal_status(array $cfg, string $trackingId): array {
    $token = pay_pesapal_token($cfg);
    $res = pay_http('GET', rtrim((string)$cfg['base'], '/') . '/api/Transactions/GetTransactionStatus?orderTrackingId='
        . rawurlencode($trackingId), ['headers' => ['Authorization: Bearer ' . $token]]);
    if (!$res['ok']) throw new ApiError('Pesapal status check failed: ' . pay_error_text($res), 502);
    $j = $res['json'] ?? [];
    $desc = strtoupper((string)($j['payment_status_description'] ?? ''));
    return ['paid' => in_array($desc, ['COMPLETED', 'SUCCESS', 'PAID'], true),
        'failed' => in_array($desc, ['FAILED', 'INVALID', 'REVERSED'], true),
        'status' => (string)($j['payment_status_description'] ?? ''),
        'status_code' => (int)($j['status_code'] ?? 0), 'amount' => (float)($j['amount'] ?? 0),
        'currency' => (string)($j['currency'] ?? ''), 'receipt' => (string)($j['confirmation_code'] ?? ''),
        'raw' => $j];
}

/* --------------------------------------------------------------- m-pesa --- */
/* Kenyan MSISDN normaliser: 07xx/01xx/+254… -> 2547xxxxxxxx */
function pay_mpesa_phone(string $phone): string {
    $d = preg_replace('/[^0-9]/', '', $phone);
    if ($d === '') return '';
    if (strpos($d, '254') === 0) return $d;
    if (strpos($d, '0') === 0) return '254' . substr($d, 1);
    if (strlen($d) === 9) return '254' . $d;
    return $d;
}
function pay_mpesa_token(array $cfg): string {
    static $cache = [];
    $key = (string)($cfg['consumer_key'] ?? '');
    if (isset($cache[$key]) && $cache[$key]['exp'] > time()) return $cache[$key]['token'];
    $url = rtrim((string)$cfg['base'], '/') . '/oauth/v1/generate?grant_type=client_credentials';
    $res = pay_http('GET', $url, ['basic' => $key . ':' . (string)($cfg['consumer_secret'] ?? ''),
        'headers' => ['Authorization: Basic ' . base64_encode($key . ':' . (string)($cfg['consumer_secret'] ?? ''))]]);
    if (!$res['ok'] || empty($res['json']['access_token'])) {
        throw new ApiError('M-Pesa authentication failed: ' . pay_error_text($res), 502);
    }
    $cache[$key] = ['token' => (string)$res['json']['access_token'], 'exp' => time() + 3000];
    return $cache[$key]['token'];
}
/* STK push: the client's phone rings and they enter their M-Pesa PIN. */
function pay_mpesa_checkout(array $pay, ?array $plan, array $cfg, string $phone): array {
    $base = rtrim((string)$cfg['base'], '/');
    $token = pay_mpesa_token($cfg);
    $shortcode = (string)($cfg['shortcode'] ?? '');
    $ts = date('YmdHis');
    $password = base64_encode($shortcode . (string)($cfg['passkey'] ?? '') . $ts);
    $msisdn = pay_mpesa_phone($phone);
    need($msisdn !== '', 'A valid phone number is required for M-Pesa', 400);
    $body = [
        'BusinessShortCode' => $shortcode,
        'Password' => $password,
        'Timestamp' => $ts,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => (int)round((float)$pay['amount']),          // M-Pesa takes whole shillings
        'PartyA' => $msisdn, 'PartyB' => $shortcode, 'PhoneNumber' => $msisdn,
        'CallBackURL' => app_base_url() . '/api/webhooks/mpesa',
        'AccountReference' => substr((string)$pay['invoice_no'], 0, 12),
        'TransactionDesc' => substr(($plan['name'] ?? 'Subscription'), 0, 13) . ' ' . $pay['invoice_no'],
    ];
    $res = pay_http('POST', $base . '/mpesa/stkpush/v1/processrequest',
        ['json' => $body, 'headers' => ['Authorization: Bearer ' . $token]]);
    if (!$res['ok'] || (string)($res['json']['ResponseCode'] ?? '') !== '0') {
        throw new ApiError('M-Pesa: ' . pay_error_text($res, (string)($res['json']['errorMessage'] ?? 'STK push refused')), 502);
    }
    return ['checkout_url' => null, 'gateway_ref' => (string)($res['json']['CheckoutRequestID'] ?? ''),
        'pending' => true,
        'pm' => ['token' => $msisdn, 'brand' => 'M-Pesa', 'phone' => $msisdn,
            'last4' => substr($msisdn, -4)],
        'message' => (string)($res['json']['CustomerMessage'] ?? 'STK push sent — enter your M-Pesa PIN to complete the payment.')];
}
/* Optional status query (used by the poll endpoint when the callback is late). */
function pay_mpesa_query(array $cfg, string $checkoutId): array {
    $base = rtrim((string)$cfg['base'], '/');
    $token = pay_mpesa_token($cfg);
    $shortcode = (string)($cfg['shortcode'] ?? '');
    $ts = date('YmdHis');
    $res = pay_http('POST', $base . '/mpesa/stkpushquery/v1/query', ['json' => [
        'BusinessShortCode' => $shortcode,
        'Password' => base64_encode($shortcode . (string)($cfg['passkey'] ?? '') . $ts),
        'Timestamp' => $ts, 'CheckoutRequestID' => $checkoutId],
        'headers' => ['Authorization: Bearer ' . $token]]);
    $code = (string)($res['json']['ResultCode'] ?? '');
    return ['paid' => $code === '0', 'failed' => $code !== '' && $code !== '0',
        'status' => (string)($res['json']['ResultDesc'] ?? ''), 'raw' => $res['json'] ?? []];
}

/* --------------------------------------------------------- MTN MoMo ----- */
/* MTN MoMo Collections API: requesttopay pushes a payment prompt to the
   customer's phone; the money is confirmed by our callback (which re-queries
   the request status server-to-server) or by the poll endpoint. */
function pay_momo_msisdn(string $phone): string {
    $n = preg_replace('/[^0-9]/', '', (string)$phone);
    return strlen($n) >= 9 ? $n : '';
}
function pay_momo_token(array $cfg): string {
    static $cache = [];
    $key = (string)($cfg['subscription_key'] ?? '') . ':' . (string)($cfg['subscription_secret'] ?? '');
    if (isset($cache[$key])) return $cache[$key];
    $res = pay_http('POST', rtrim((string)$cfg['base'], '/') . '/collection/token/',
        ['headers' => ['Authorization: Basic ' . base64_encode(
            (string)($cfg['subscription_key'] ?? '') . ':' . (string)($cfg['subscription_secret'] ?? ''))]]);
    $token = (string)($res['json']['access_token'] ?? '');
    if (!$res['ok'] || $token === '') {
        throw new ApiError('MTN MoMo authentication failed: ' . pay_error_text($res), 502);
    }
    $cache[$key] = $token;
    return $token;
}
function pay_momo_checkout(array $pay, ?array $plan, array $cfg, string $phone): array {
    $base = rtrim((string)$cfg['base'], '/');
    $msisdn = pay_momo_msisdn($phone);
    need($msisdn !== '', 'Enter the MTN MoMo number that should receive the payment prompt', 400);
    $ref = 'momo_' . (string)$pay['id'];
    $res = pay_http('POST', $base . '/collection/v1_0/requesttopay', ['json' => [
        'amount' => (string)round((float)$pay['amount'], 2),
        'currency' => strtoupper((string)$pay['currency']),
        'externalId' => (string)$pay['invoice_no'],
        'payer' => ['partyIdType' => 'MSISDN', 'partyId' => $msisdn],
        'payerMessage' => 'KIVU Tracker ' . (string)($plan['name'] ?? 'subscription'),
        'payeeNote' => 'Invoice ' . (string)$pay['invoice_no']],
        'headers' => ['Authorization: Bearer ' . pay_momo_token($cfg),
            'X-Reference-Id' => $ref,
            'X-Target-Environment' => (($cfg['env'] ?? 'sandbox') === 'live' ? 'production' : 'sandbox'),
            'Ocp-Apim-Subscription-Key' => (string)($cfg['subscription_key'] ?? ''),
            'X-Callback-Url' => app_base_url() . '/api/webhooks/momo']]);
    if (!$res['ok']) throw new ApiError('MTN MoMo: ' . pay_error_text($res), 502);
    return ['checkout_url' => null, 'gateway_ref' => $ref, 'pending' => true,
        'pm' => ['token' => $msisdn, 'brand' => 'MTN MoMo', 'phone' => $msisdn,
            'last4' => substr($msisdn, -4)],
        'message' => 'Approve the MTN MoMo prompt on the phone to complete the payment.'];
}
/* Status of a request-to-pay: SUCCESSFUL / PENDING / FAILED. */
function pay_momo_status(array $cfg, string $ref): array {
    $base = rtrim((string)$cfg['base'], '/');
    $res = pay_http('GET', $base . '/collection/v1_0/requesttopay/' . rawurlencode($ref),
        ['headers' => ['Authorization: Bearer ' . pay_momo_token($cfg),
            'Ocp-Apim-Subscription-Key' => (string)($cfg['subscription_key'] ?? '')]]);
    $st = strtoupper((string)($res['json']['status'] ?? ''));
    return ['paid' => $st === 'SUCCESSFUL', 'failed' => $st === 'FAILED', 'status' => $st,
        'raw' => $res['json'] ?? []];
}

/* ------------------------------------------------------- Airtel Money ---- */
/* Airtel Africa Payments API: the payment call prompts the customer's phone
   (PIN entry); the server-to-server notification settles the invoice. */
function pay_airtel_token(array $cfg): string {
    static $cache = [];
    $key = (string)($cfg['client_id'] ?? '');
    if (isset($cache[$key])) return $cache[$key];
    $res = pay_http('POST', rtrim((string)$cfg['base'], '/') . '/auth/oauth2/token',
        ['form' => ['client_id' => $key, 'client_secret' => (string)($cfg['client_secret'] ?? ''),
            'grant_type' => 'client_credentials']]);
    $token = (string)($res['json']['access_token'] ?? '');
    if (!$res['ok'] || $token === '') {
        throw new ApiError('Airtel Money authentication failed: ' . pay_error_text($res), 502);
    }
    $cache[$key] = $token;
    return $token;
}
function pay_airtel_checkout(array $pay, ?array $plan, array $cfg, string $phone): array {
    $base = rtrim((string)$cfg['base'], '/');
    $msisdn = pay_momo_msisdn($phone);
    need($msisdn !== '', 'Enter the Airtel Money number that should receive the payment prompt', 400);
    $country = strtoupper((string)($cfg['country'] ?? 'KE'));
    $currency = strtoupper((string)$pay['currency']);
    $corr = 'airtel_' . (string)$pay['id'];
    $res = pay_http('POST', $base . '/standard/v1/payments', ['json' => [
        'reference' => (string)$pay['invoice_no'],
        'transaction' => ['amount' => (string)round((float)$pay['amount'], 2),
            'country' => $country, 'currency' => $currency],
        'customer' => ['msisdn' => $msisdn, 'email' => (string)($pay['payer_email'] ?? ''),
            'firstName' => (string)($pay['payer_name'] ?? 'KIVU client')]],
        'headers' => ['Authorization: Bearer ' . pay_airtel_token($cfg),
            'X-Correlation-Id' => $corr, 'X-Country' => $country, 'X-Currency' => $currency,
            'X-Callback-Url' => app_base_url() . '/api/webhooks/airtel']]);
    $code = (string)($res['json']['status']['code'] ?? '');
    if (!$res['ok'] || !in_array($code, ['2000', '0'], true)) {
        throw new ApiError('Airtel Money: '
            . pay_error_text($res, (string)($res['json']['status']['message'] ?? '')), 502);
    }
    return ['checkout_url' => null, 'gateway_ref' => $corr, 'pending' => true,
        'pm' => ['token' => $msisdn, 'brand' => 'Airtel Money', 'phone' => $msisdn,
            'last4' => substr($msisdn, -4)],
        'message' => 'Approve the Airtel Money prompt on the phone to complete the payment.'];
}

/* ----------------------------------------------------------- flutterwave -- */
function pay_flutterwave_checkout(array $pay, ?array $plan, array $cfg): array {
    $base = rtrim((string)($cfg['base'] ?? ''), '/') ?: 'https://api.flutterwave.com';
    $body = [
        'tx_ref' => (string)$pay['invoice_no'] . '-' . substr((string)$pay['id'], -6),
        'amount' => (float)$pay['amount'],
        'currency' => strtoupper((string)$pay['currency']),
        'redirect_url' => app_base_url() . '/billing?payment=' . rawurlencode((string)$pay['id']) . '&status=flutterwave',
        'payment_options' => 'card,mobilemoney,banktransfer',
        'customer' => ['email' => (string)($pay['payer_email'] ?? 'client@example.com'),
            'phonenumber' => (string)($pay['payer_phone'] ?? ''), 'name' => (string)($pay['payer_name'] ?? '')],
        'customizations' => ['title' => 'KIVU Tracker',
            'description' => ($plan['name'] ?? 'Subscription') . ' — invoice ' . $pay['invoice_no']],
        'meta' => ['payment_id' => (string)$pay['id'], 'account_id' => (string)$pay['account_id']],
    ];
    $res = pay_http('POST', $base . '/v3/payments', ['json' => $body,
        'headers' => ['Authorization: Bearer ' . (string)($cfg['secret_key'] ?? '')]]);
    $link = (string)($res['json']['data']['link'] ?? '');
    if (!$res['ok'] || $link === '') throw new ApiError('Flutterwave: ' . pay_error_text($res), 502);
    return ['checkout_url' => $link, 'gateway_ref' => (string)$body['tx_ref'], 'pending' => true,
        'message' => 'Opening the Flutterwave page (card, mobile money or bank)…'];
}
/* Server-side verification of a Flutterwave transaction reference. */
function pay_flutterwave_verify(array $cfg, string $txRef, string $txId = ''): array {
    $base = rtrim((string)($cfg['base'] ?? ''), '/') ?: 'https://api.flutterwave.com';
    $url = $txId !== ''
        ? $base . '/v3/transactions/' . rawurlencode($txId) . '/verify'
        : $base . '/v3/transactions/verify_by_reference?tx_ref=' . rawurlencode($txRef);
    $res = pay_http('GET', $url, ['headers' => ['Authorization: Bearer ' . (string)($cfg['secret_key'] ?? '')]]);
    $d = $res['json']['data'] ?? [];
    $status = strtolower((string)($d['status'] ?? ''));
    return ['ok' => $res['ok'], 'paid' => $res['ok'] && $status === 'successful',
        'failed' => in_array($status, ['failed', 'cancelled'], true),
        'status' => (string)($d['status'] ?? ''), 'amount' => (float)($d['amount'] ?? 0),
        'currency' => (string)($d['currency'] ?? ''), 'receipt' => (string)($d['id'] ?? ''),
        'raw' => $res['json'] ?? []];
}
function pay_flutterwave_webhook_ok(array $cfg, string $hash): bool {
    $secret = trim((string)($cfg['webhook_hash'] ?? ''));
    if ($secret === '') return false;
    return hash_equals($secret, trim($hash));
}

/* ----------------------------------------------------------------- paypal - */
function pay_paypal_token(array $cfg): string {
    static $cache = [];
    $key = (string)($cfg['client_id'] ?? '');
    if (isset($cache[$key]) && $cache[$key]['exp'] > time()) return $cache[$key]['token'];
    $base = rtrim((string)($cfg['base'] ?? ''), '/') ?: 'https://api-m.sandbox.paypal.com';
    $credentials = $key . ':' . (string)($cfg['secret'] ?? '');
    $res = pay_http('POST', $base . '/v1/oauth2/token',
        ['form' => ['grant_type' => 'client_credentials'],
         'basic' => $credentials,
         'headers' => ['Authorization: Basic ' . base64_encode($credentials)]]);
    if (!$res['ok'] || empty($res['json']['access_token'])) {
        throw new ApiError('PayPal authentication failed: ' . pay_error_text($res), 502);
    }
    $cache[$key] = ['token' => (string)$res['json']['access_token'],
        'exp' => time() + max(60, (int)($res['json']['expires_in'] ?? 3000) - 60)];
    return $cache[$key]['token'];
}
function pay_paypal_checkout(array $pay, ?array $plan, array $cfg): array {
    $base = rtrim((string)($cfg['base'] ?? ''), '/') ?: 'https://api-m.sandbox.paypal.com';
    $token = pay_paypal_token($cfg);
    $body = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => (string)$pay['invoice_no'],
            'custom_id' => (string)$pay['id'],
            'description' => ($plan['name'] ?? 'KIVU Tracker subscription') . ' — invoice ' . $pay['invoice_no'],
            'amount' => ['currency_code' => strtoupper((string)$pay['currency']),
                'value' => number_format((float)$pay['amount'], 2, '.', '')],
        ]],
        'application_context' => [
            'brand_name' => 'KIVU Tracker', 'user_action' => 'PAY_NOW',
            'return_url' => app_base_url() . '/billing?payment=' . rawurlencode((string)$pay['id']) . '&status=paypal',
            'cancel_url' => app_base_url() . '/billing?payment=' . rawurlencode((string)$pay['id']) . '&status=cancel',
        ],
    ];
    $res = pay_http('POST', $base . '/v2/checkout/orders',
        ['json' => $body, 'headers' => ['Authorization: Bearer ' . $token]]);
    if (!$res['ok']) throw new ApiError('PayPal: ' . pay_error_text($res), 502);
    $approve = '';
    foreach ((array)($res['json']['links'] ?? []) as $link) {
        if (($link['rel'] ?? '') === 'approve') $approve = (string)($link['href'] ?? '');
    }
    need($approve !== '', 'PayPal did not return an approval link', 502);
    return ['checkout_url' => $approve, 'gateway_ref' => (string)($res['json']['id'] ?? ''), 'pending' => true,
        'message' => 'Opening the PayPal page (PayPal balance or card)…'];
}
/* Captures an approved PayPal order (called when the client returns). */
function pay_paypal_capture(array $cfg, string $orderId): array {
    $base = rtrim((string)($cfg['base'] ?? ''), '/') ?: 'https://api-m.sandbox.paypal.com';
    $token = pay_paypal_token($cfg);
    $res = pay_http('POST', $base . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture',
        ['raw' => '{}', 'content_type' => 'application/json',
         'headers' => ['Authorization: Bearer ' . $token, 'PayPal-Request-Id: capture-' . $orderId]]);
    $status = strtoupper((string)($res['json']['status'] ?? ''));
    $capture = (array)($res['json']['purchase_units'][0]['payments']['captures'][0] ?? []);
    return ['ok' => $res['ok'], 'paid' => $status === 'COMPLETED', 'status' => $status,
        'receipt' => (string)($capture['id'] ?? ''),
        'amount' => (float)($capture['amount']['value'] ?? 0),
        'currency' => (string)($capture['amount']['currency_code'] ?? ''), 'raw' => $res['json'] ?? []];
}

/* -------------------------------------------------------------- webhooks -- */
/* Case-insensitive header lookup that works on every SAPI. */
function req_header(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) return (string)$_SERVER[$key];
    if (strcasecmp($name, 'Content-Type') === 0 && isset($_SERVER['CONTENT_TYPE'])) {
        return (string)$_SERVER['CONTENT_TYPE'];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strcasecmp($k, $name) === 0) return (string)$v;
    }
    return '';
}
/* Raw request body (webhook signatures are computed over the exact bytes). */
function req_raw_body(): string {
    static $raw = null;
    if ($raw === null) $raw = (string)file_get_contents('php://input');
    return $raw;
}
/* Looks up the invoice a callback refers to, using every id the gateways
   give us: our payment id, the invoice number or the gateway reference. */
function billing_find_payment(array $candidates, string $gateway = ''): ?array {
    foreach ($candidates as $c) {
        $c = trim((string)$c);
        if ($c === '') continue;
        $p = payment_by_id($c);
        if ($p) return $p;
        $st = db()->prepare('SELECT * FROM payments WHERE invoice_no = ? ORDER BY created_at DESC LIMIT 1');
        $st->execute([$c]);
        if ($row = $st->fetch()) return $row;
        if ($gateway !== '') {
            $p = payment_by_ref($gateway, $c);
            if ($p) return $p;
        } else {
            $st = db()->prepare('SELECT * FROM payments WHERE gateway_ref = ? ORDER BY created_at DESC LIMIT 1');
            $st->execute([$c]);
            if ($row = $st->fetch()) return $row;
        }
    }
    return null;
}

/* Handles one gateway callback: verifies it, records it (idempotently) and
   settles the invoice. Always returns a payload the gateway can accept. */
function webhook_handle(string $gateway, array $payload, array $headers = []): array {
    ensure_schema();
    $cfg = gateway_config($gateway);
    $raw = req_raw_body();
    $gw = payment_gateways()[$gateway]['short'] ?? $gateway;
    $paid = false; $failed = false; $payment = null; $verified = false;
    $eventRef = ''; $kind = ''; $receipt = ''; $pmMeta = [];

    if ($gateway === 'stripe') {
        $sig = (string)($headers['Stripe-Signature'] ?? req_header('Stripe-Signature'));
        $secret = (string)($cfg['webhook_secret'] ?? '');
        $verified = $secret !== '' && pay_stripe_verify($cfg, $raw, $sig);
        if ($secret !== '' && !$verified) {
            return ['ok' => false, 'gateway' => $gw, 'error' => 'Invalid Stripe signature'];
        }
        $kind = (string)($payload['type'] ?? '');
        $obj = (array)($payload['data']['object'] ?? []);
        $eventRef = (string)($payload['id'] ?? '');
        $payment = billing_find_payment([(string)($obj['metadata']['payment_id'] ?? ''),
            (string)($obj['client_reference_id'] ?? ''), (string)($obj['metadata']['invoice_no'] ?? ''),
            (string)($obj['id'] ?? '')], 'stripe');
        if (in_array($kind, ['checkout.session.completed', 'payment_intent.succeeded', 'charge.succeeded'], true)) {
            $status = strtolower((string)($obj['payment_status'] ?? 'paid'));
            $paid = in_array($status, ['paid', 'succeeded', 'no_payment_required'], true);
            $receipt = (string)($obj['payment_intent'] ?? $obj['id'] ?? '');
            /* reusable card details for automatic renewal (if the client saved) */
            $cust = (string)($obj['customer'] ?? '');
            $pmId = is_array($obj['payment_method'] ?? null) ? (string)($obj['payment_method']['id'] ?? '') : (string)($obj['payment_method'] ?? '');
            if ($pmId === '' && $cust !== '' && !empty($obj['payment_intent'])) {
                $pi = pay_http('GET', rtrim((string)($cfg['base'] ?: 'https://api.stripe.com'), '/')
                    . '/v1/payment_intents/' . rawurlencode((string)$obj['payment_intent'])
                    . '?expand[]=payment_method',
                    ['headers' => ['Authorization: Bearer ' . (string)($cfg['secret_key'] ?? '')]]);
                $got = $pi['json']['payment_method'] ?? null;
                if (is_array($got)) {
                    $pmId = (string)($got['id'] ?? '');
                    $pmMeta = ['pm_brand' => (string)($got['card']['brand'] ?? 'Card'),
                        'pm_last4' => (string)($got['card']['last4'] ?? '')];
                } elseif (is_string($got)) { $pmId = $got; }
            } elseif (is_array($obj['payment_method'] ?? null)) {
                $pmMeta = ['pm_brand' => (string)($obj['payment_method']['card']['brand'] ?? 'Card'),
                    'pm_last4' => (string)($obj['payment_method']['card']['last4'] ?? '')];
            }
            if ($pmId !== '') {
                $pmMeta['pm_token'] = $pmId;
                if ($cust !== '') $pmMeta['pm_customer'] = $cust;
            }
        } elseif (in_array($kind, ['checkout.session.expired', 'payment_intent.payment_failed', 'charge.failed'], true)) {
            $failed = true;
        }
    } elseif ($gateway === 'pesapal') {
        $kind = (string)($payload['OrderNotificationType'] ?? 'IPNCHANGE');
        $eventRef = (string)($payload['OrderTrackingId'] ?? '');
        $payment = billing_find_payment([(string)($payload['OrderMerchantReference'] ?? ''), $eventRef], 'pesapal');
        if ($payment && $eventRef !== '') {
            $st = pay_pesapal_status($cfg, $eventRef);      // always confirm with Pesapal
            $paid = $st['paid']; $failed = $st['failed']; $receipt = $st['receipt'];
            $verified = true;
        }
    } elseif ($gateway === 'mpesa') {
        $cb = (array)($payload['Body']['stkCallback'] ?? $payload);
        $kind = 'stkCallback';
        $eventRef = (string)($cb['CheckoutRequestID'] ?? '');
        $payment = billing_find_payment([$eventRef], 'mpesa');
        $code = (string)($cb['ResultCode'] ?? '1');
        $paid = $code === '0';
        $failed = !$paid && $payment !== null;
        $verified = $payment !== null;                      // matched to a pending STK push
        foreach ((array)($cb['CallbackMetadata']['Item'] ?? []) as $item) {
            if (($item['Name'] ?? '') === 'MpesaReceiptNumber') $receipt = (string)($item['Value'] ?? '');
        }
        if ($failed) {
            billing_mark_failed($payment, (string)($cb['ResultDesc'] ?? 'M-Pesa payment failed'),
                ['result_code' => $code]);
        }
    } elseif ($gateway === 'momo') {
        /* MTN notifies us; the money is confirmed by re-querying the request */
        $kind = 'requesttopay';
        $eventRef = (string)($payload['X-Reference-Id'] ?? ($payload['financialTransactionId'] ?? ''));
        $payment = billing_find_payment([(string)($payload['externalId'] ?? ''),
            (string)($payload['X-Reference-Id'] ?? '')], 'momo');
        if ($payment && (string)($payment['gateway_ref'] ?? '') !== '') {
            $st = pay_momo_status($cfg, (string)$payment['gateway_ref']);
            $paid = $st['paid']; $failed = $st['failed']; $verified = true;
            $receipt = (string)($payload['financialTransactionId'] ?? $st['status']);
        }
    } elseif ($gateway === 'airtel') {
        /* server-to-server notification: {reference, status:{code}, transaction:{id}} */
        $kind = 'payment notification';
        $st = is_array($payload['status'] ?? null) ? $payload['status'] : [];
        $code = (string)($st['code'] ?? '');
        $tx = is_array($payload['transaction'] ?? null) ? $payload['transaction'] : [];
        $eventRef = (string)($tx['id'] ?? ($payload['reference'] ?? ''));
        $payment = billing_find_payment([(string)($payload['reference'] ?? ''),
            (string)($tx['id'] ?? '')], 'airtel');
        $paid = in_array($code, ['2000', '0'], true);
        $failed = $code !== '' && !$paid;
        $verified = $payment !== null;
        $receipt = (string)($tx['id'] ?? $code);
    } elseif ($gateway === 'flutterwave') {
        $hash = (string)($headers['verif-hash'] ?? req_header('verif-hash'));
        $secret = trim((string)($cfg['webhook_hash'] ?? ''));
        if ($secret !== '') {
            /* a configured webhook hash is authoritative: a wrong hash means the
               callback did not come from Flutterwave, so it is rejected outright */
            if (!pay_flutterwave_webhook_ok($cfg, $hash)) {
                return ['ok' => false, 'gateway' => $gw, 'error' => 'Invalid Flutterwave webhook hash'];
            }
            $verified = true;
        }
        $data = (array)($payload['data'] ?? $payload);
        $kind = (string)($payload['event'] ?? 'charge.completed');
        $txRef = (string)($data['tx_ref'] ?? '');
        $eventRef = (string)($data['id'] ?? $txRef);
        $payment = billing_find_payment([(string)($data['meta']['payment_id'] ?? ''), $txRef], 'flutterwave');
        if ($payment && !$verified && (string)($cfg['secret_key'] ?? '') !== '') {
            /* no hash configured: confirm server-to-server instead */
            $v = pay_flutterwave_verify($cfg, $txRef, (string)($data['id'] ?? ''));
            $verified = $v['ok'];
        }
        $status = strtolower((string)($data['status'] ?? ''));
        $paid = $status === 'successful';
        $failed = in_array($status, ['failed', 'cancelled'], true);
        $receipt = (string)($data['id'] ?? '');
        /* tokenised card the client chose to save, when Flutterwave returns one */
        $card = is_array($data['card'] ?? null) ? $data['card'] : [];
        $fwToken = (string)($card['token'] ?? $data['token'] ?? '');
        if ($fwToken !== '') {
            $pmMeta = ['pm_token' => $fwToken, 'pm_brand' => (string)($card['type'] ?? 'Card'),
                'pm_last4' => (string)($card['last_4digits'] ?? '')];
        }
    } elseif ($gateway === 'paypal') {
        $kind = (string)($payload['event_type'] ?? '');
        $res = (array)($payload['resource'] ?? []);
        $eventRef = (string)($payload['id'] ?? ($res['id'] ?? ''));
        $payment = billing_find_payment([(string)($res['custom_id'] ?? ''), (string)($res['id'] ?? '')], 'paypal');
        $verified = $payment !== null;
        if ($kind === 'CHECKOUT.ORDER.APPROVED' && $payment
            && (string)($payment['gateway_ref'] ?? '') !== '' && (string)($cfg['client_id'] ?? '') !== '') {
            $cap = pay_paypal_capture($cfg, (string)$payment['gateway_ref']);
            $paid = $cap['paid']; $receipt = $cap['receipt'];
        } elseif (in_array($kind, ['PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.COMPLETED'], true)) {
            $paid = true; $receipt = (string)($res['id'] ?? '');
        } elseif (in_array($kind, ['PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REVERSED'], true)) {
            $failed = true;
        }
    } else {
        return ['ok' => false, 'gateway' => $gw, 'error' => 'Unsupported gateway callback'];
    }

    return billing_settle_result($gateway, $payment, $paid, $failed, $verified, $eventRef, $kind, $receipt,
        $payload, $pmMeta);
}
/* Shared tail of every callback: idempotent record, then settle if paid. */
function billing_settle_result(string $gateway, ?array $payment, bool $paid, bool $failed, bool $verified,
                               string $eventRef, string $kind, string $receipt, array $payload,
                               array $pmMeta = []): array {
    $gw = payment_gateways()[$gateway]['short'] ?? $gateway;
    if ($payment === null) {
        return ['ok' => false, 'gateway' => $gw, 'error' => 'No matching invoice', 'event' => $eventRef];
    }
    if ($eventRef === '') $eventRef = (string)$payment['id'] . ':' . $kind . ':' . ($paid ? 'paid' : 'x');
    $isNew = billing_record_event($gateway, $eventRef, $kind,
        $paid ? 'paid' : ($failed ? 'failed' : 'ignored'), (string)$payment['id'], $payload);
    if (!$isNew) {
        return ['ok' => true, 'gateway' => $gw, 'duplicate' => true, 'payment_id' => $payment['id'],
            'message' => 'Event already processed'];
    }
    if (!$paid) {
        return ['ok' => true, 'gateway' => $gw, 'payment_id' => $payment['id'],
            'status' => $failed ? 'failed' : 'ignored',
            'message' => $failed ? 'Payment failed' : 'Event noted'];
    }
    $settled = billing_mark_paid($payment, [
        'gateway_ref' => $receipt !== '' ? $receipt : (string)($payment['gateway_ref'] ?? ''),
        'meta' => array_merge(['verified' => $verified, 'kind' => $kind, 'receipt' => $receipt,
            'event_ref' => $eventRef, 'gateway' => $gateway], $pmMeta)]);
    return ['ok' => true, 'gateway' => $gw, 'payment_id' => $payment['id'], 'status' => 'paid',
        'verified' => $verified, 'already_paid' => !empty($settled['already_paid']),
        'invoice_no' => $payment['invoice_no'], 'period_end' => $settled['period_end'] ?? null,
        'message' => 'Subscription activated'];
}

/* Polls the gateway when the client comes back from a hosted page (and after
   the sandbox simulator finishes). Settles the invoice when the gateway
   confirms the money. Bank transfers are confirmed by the super admin. */
function billing_poll_payment(array $payment): array {
    $gateway = (string)$payment['gateway'];
    $cfg = gateway_config($gateway);
    $settled = null; $note = '';
    if ($gateway === 'sandbox') {
        return ['status' => $payment['status'],
            'message' => 'Sandbox payments are confirmed on the simulated gateway page.',
            'payment' => payment_public(payment_by_id((string)$payment['id']) ?? $payment)];
    }
    if ($gateway === 'bank') {
        return ['status' => $payment['status'],
            'message' => 'Waiting for the transfer to be confirmed by the platform administrator.',
            'payment' => payment_public(payment_by_id((string)$payment['id']) ?? $payment)];
    }
    try {
        if ($gateway === 'stripe') {
            $res = pay_http('GET', rtrim((string)$cfg['base'], '/') . '/v1/checkout/sessions/'
                . rawurlencode((string)$payment['gateway_ref']),
                ['headers' => ['Authorization: Bearer ' . (string)$cfg['secret_key']]]);
            $st = strtolower((string)($res['json']['payment_status'] ?? ''));
            if ($st === 'paid') {
                $settled = billing_mark_paid($payment, ['gateway_ref' => (string)($res['json']['payment_intent']
                    ?? $payment['gateway_ref']), 'meta' => ['verified' => true, 'source' => 'poll']]);
            } elseif ($st !== '') { $note = 'Stripe reports "' . $st . '"'; }
        } elseif ($gateway === 'pesapal') {
            $st = pay_pesapal_status($cfg, (string)$payment['gateway_ref']);
            if ($st['paid']) {
                $settled = billing_mark_paid($payment, ['gateway_ref' => $st['receipt'] !== ''
                    ? $st['receipt'] : $payment['gateway_ref'], 'meta' => ['verified' => true, 'source' => 'poll']]);
            } elseif ($st['failed']) { $note = 'Pesapal reports "' . $st['status'] . '"'; }
        } elseif ($gateway === 'mpesa') {
            $st = pay_mpesa_query($cfg, (string)$payment['gateway_ref']);
            if ($st['paid']) {
                $settled = billing_mark_paid($payment, ['meta' => ['verified' => true, 'source' => 'poll']]);
            } elseif ($st['failed']) { $note = 'M-Pesa reports "' . $st['status'] . '"'; }
        } elseif ($gateway === 'momo') {
            $st = pay_momo_status($cfg, (string)$payment['gateway_ref']);
            if ($st['paid']) {
                $settled = billing_mark_paid($payment, ['gateway_ref' => (string)$payment['gateway_ref'],
                    'meta' => ['verified' => true, 'source' => 'poll']]);
            } elseif ($st['failed']) { $note = 'MTN MoMo reports "' . $st['status'] . '"'; }
        } elseif ($gateway === 'airtel') {
            /* Airtel settles through its server-to-server notification */
            return ['status' => $payment['status'],
                'message' => 'Approve the Airtel Money prompt on the phone — the notification settles'
                    . ' the invoice automatically.',
                'payment' => payment_public(payment_by_id((string)$payment['id']) ?? $payment)];
        } elseif ($gateway === 'flutterwave') {
            $st = pay_flutterwave_verify($cfg, (string)$payment['gateway_ref']);
            if ($st['paid']) {
                $settled = billing_mark_paid($payment, ['gateway_ref' => $st['receipt'] !== ''
                    ? $st['receipt'] : $payment['gateway_ref'], 'meta' => ['verified' => true, 'source' => 'poll']]);
            } elseif ($st['failed']) { $note = 'Flutterwave reports "' . $st['status'] . '"'; }
        } elseif ($gateway === 'paypal') {
            $cap = pay_paypal_capture($cfg, (string)$payment['gateway_ref']);
            if ($cap['paid']) {
                $settled = billing_mark_paid($payment, ['gateway_ref' => $cap['receipt'] !== ''
                    ? $cap['receipt'] : $payment['gateway_ref'], 'meta' => ['verified' => true, 'source' => 'poll']]);
            } elseif ($cap['status'] !== '') { $note = 'PayPal reports "' . $cap['status'] . '"'; }
        }
    } catch (Throwable $e) {
        $note = $e->getMessage();
    }
    $fresh = payment_by_id((string)$payment['id']) ?? $payment;
    return ['status' => $fresh['status'], 'settled' => $settled !== null,
        'period_end' => $settled['period_end'] ?? null,
        'message' => $settled ? 'Payment confirmed — your subscription is active.'
            : ($note !== '' ? 'Not confirmed yet: ' . $note : 'The gateway has not confirmed the payment yet.'),
        'payment' => payment_public($fresh)];
}

/* ------------------------------------------------------ self registration - */
/* Creates a client account from the public signup form: the account, its first
   administrator and a free-trial subscription (default 5 days). */
function signup_create_account(array $in): array {
    ensure_schema();
    need((int)setting('signup_enabled', 1) === 1,
        'Self-service registration is currently disabled — ask the platform administrator for an account.', 403);
    $company = trim((string)($in['company'] ?? ''));
    $email = trim((string)($in['email'] ?? ''));
    $username = trim((string)($in['username'] ?? ''));
    $password = (string)($in['password'] ?? '');
    $fullName = trim((string)($in['full_name'] ?? ''));
    $phone = trim((string)($in['phone'] ?? ''));
    need($company !== '', 'Company / fleet name is required');
    need($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === $email, 'A valid e-mail address is required');
    need(strlen($password) >= 6, 'The password must be at least 6 characters');
    if ($username === '') {
        $local = (string)strstr($email, '@', true);
        $username = strtolower((string)preg_replace('/[^a-z0-9._-]+/i', '', $local !== '' ? $local : $email));
        $username = substr($username === '' ? 'client' : $username, 0, 40);
    }
    need(preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username) === 1,
        'Usernames may only use letters, digits, dot, dash and underscore (3 characters or more)');

    /* light abuse guard: at most 5 self-service signups per IP per hour */
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM accounts WHERE signup_ip = ? AND signup_source = ?
                             AND created_at > ?');
        $st->execute([ip_addr(), 'self', date('Y-m-d H:i:s', time() - 3600)]);
        need((int)$st->fetchColumn() < 5,
            'Too many signups from this address — try again later or contact support.', 429);
    } catch (Throwable $e) { /* fresh install: no column yet */ }

    $st = db()->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $st->execute([$username, $email]);
    if ($st->fetch()) {
        $username = $username . random_int(10, 99);
        $st->execute([$username, $email]);
        need(!$st->fetch(), 'That e-mail address is already registered — sign in instead.', 409);
    }
    $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $company), '-'));
    if ($slug === '') $slug = 'client';
    $slug = substr($slug, 0, 60);
    $base = $slug; $n = 1;
    while (true) {
        $st = db()->prepare('SELECT id FROM accounts WHERE slug = ?');
        $st->execute([$slug]);
        if (!$st->fetch()) break;
        $slug = $base . '-' . (++$n);
    }

    $plan = null;
    $planCode = trim((string)($in['plan'] ?? ''));
    if ($planCode !== '') {
        $plan = plan_by_code($planCode);
        need($plan && (int)$plan['enabled'] === 1, 'Unknown or disabled plan: ' . $planCode, 400);
    } else {
        $all = plans_all(true);
        $plan = $all[0] ?? null;
    }
    $days = $plan ? (int)$plan['trial_days'] : trial_days();
    if ($days <= 0) $days = trial_days();

    /* the type of asset the fleet tracks (lorry, motorcycle, bicycle …) is
       captured at registration and becomes the default for new units */
    $assetType = (string)($in['asset_type'] ?? '');
    if ($assetType !== '') {
        need(asset_type_valid($assetType) !== '',
            'Unknown asset type: ' . $assetType, 400);
        $assetType = asset_type_valid($assetType);
    }

    $accountId = uid('acc');
    db()->prepare('INSERT INTO accounts (id,name,slug,plan,plan_id,status,trial_ends_at,units_limit,
                   storage_days,currency,timezone,contact_email,contact_name,contact_phone,billing_email,
                   default_asset_type,signup_ip,signup_source,enabled,created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)')
        ->execute([$accountId, $company, $slug, $plan['code'] ?? 'trial', $plan['id'] ?? null, 'trialing',
            date('Y-m-d H:i:s', time() + $days * 86400),
            (int)($plan['unit_limit'] ?? 10),
            (int)($plan['storage_days'] ?? (int)setting('retention_days', 90)),
            (string)($in['currency'] ?? ($plan['currency'] ?? setting('default_currency', 'USD'))),
            (string)($in['timezone'] ?? 'Africa/Nairobi'), $email, $fullName !== '' ? $fullName : null,
            $phone !== '' ? $phone : null, $email, $assetType !== '' ? $assetType : null,
            ip_addr(), 'self', now_dt()]);
    $userId = uid('usr');
    db()->prepare('INSERT INTO users (id,account_id,username,email,phone,full_name,role,password_hash,salt,enabled,created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,1,?)')
        ->execute([$userId, $accountId, $username, $email, $phone !== '' ? $phone : null,
            $fullName !== '' ? $fullName : $company, 'admin',
            password_hash($password, PASSWORD_BCRYPT), bin2hex(random_bytes(8)), now_dt()]);
    subscription_start_trial($accountId, $days);
    audit('signup', 'account', $accountId, ['company' => $company, 'email' => $email,
        'plan' => $plan['code'] ?? null, 'trial_days' => $days, 'ip' => ip_addr()]);
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$userId]);
    return ['account' => account_by_id($accountId), 'user' => $st->fetch(),
        'trial_days' => $days, 'plan' => $plan ? plan_public($plan) : null];
}

/* ============================================== payment reminder e-mails == */
/* The super admin activates payment reminders and chooses the offsets
   (default 14, 5 and 2 days before an account is deactivated — trial end,
   paid period end, or the end of the past-due grace window). Everything is
   stored inside billing_json, so no settings migration is needed. */

function billing_reminders_enabled(): bool {
    $cfg = jdec(setting('billing_json', null), []);
    if (!is_array($cfg) || !array_key_exists('reminders_enabled', $cfg)) return true;   // default: on
    return filter_var($cfg['reminders_enabled'], FILTER_VALIDATE_BOOLEAN);
}
function billing_reminder_days(): array {
    $cfg = jdec(setting('billing_json', null), []);
    $raw = is_array($cfg) && isset($cfg['reminder_days']) ? (string)$cfg['reminder_days'] : '14,5,2';
    $out = [];
    foreach (preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $d) {
        $d = (int)$d;
        if ($d >= 1 && $d <= 120 && !in_array($d, $out, true)) $out[] = $d;
    }
    if (!$out) $out = [14, 5, 2];
    rsort($out);                                              // biggest offset first
    return $out;
}

/* ------------------------------------------------------------- mailer --- */
/* Two real transports (a built-in SMTP client and PHP mail()) plus a `log`
   transport that appends to data/mail.log for demos and the test suites. */
function mail_config(bool $withSecret = false): array {
    $cfg = jdec(setting('billing_json', null), []);
    $m = is_array($cfg['mail'] ?? null) ? $cfg['mail'] : [];
    $out = [
        'transport' => in_array($m['transport'] ?? '', ['auto', 'smtp', 'mail', 'log'], true)
            ? $m['transport'] : 'auto',
        'from' => trim((string)($m['from'] ?? '')),
        'from_name' => trim((string)($m['from_name'] ?? '')) ?: (string)setting('platform_name', APP_NAME),
        'smtp_host' => trim((string)($m['smtp_host'] ?? '')),
        'smtp_port' => ((int)($m['smtp_port'] ?? 587) > 0 ? (int)($m['smtp_port'] ?? 587) : 587),
        'smtp_encryption' => in_array($m['smtp_encryption'] ?? 'tls', ['none', 'tls', 'ssl'], true)
            ? ($m['smtp_encryption'] ?? 'tls') : 'tls',
        'smtp_user' => (string)($m['smtp_user'] ?? ''),
        'smtp_pass' => (string)($m['smtp_pass'] ?? ''),
    ];
    $out['configured'] = $out['smtp_host'] !== '';
    if (!$withSecret && $out['smtp_pass'] !== '') $out['smtp_pass'] = '••••' . substr($out['smtp_pass'], -4);
    return $out;
}
function mail_transport(): string {
    $m = mail_config();
    if ($m['transport'] !== 'auto') return $m['transport'];
    return $m['smtp_host'] !== '' ? 'smtp' : 'mail';
}
function mail_from_address(): string {
    $m = mail_config();
    if ($m['from'] !== '' && filter_var($m['from'], FILTER_VALIDATE_EMAIL)) return $m['from'];
    $host = parse_url(app_base_url(), PHP_URL_HOST) ?: 'localhost';
    return 'billing@' . $host;
}
/* Non-ASCII subjects must be RFC 2047 encoded. */
function mail_subject(string $s): string {
    if (!preg_match('/[^\x20-\x7E]/', $s)) return $s;
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}
function mail_mime_encode(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}
function mail_body(string $fromName, string $fromAddr, string $to, string $subject, string $html): string {
    return 'From: ' . mail_mime_encode($fromName) . ' <' . $fromAddr . ">\r\n"
        . 'To: ' . $to . "\r\n"
        . 'Subject: ' . mail_subject($subject) . "\r\n"
        . 'Date: ' . date('r') . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . 'X-KIVU-Reminder: payment' . "\r\n"
        . "\r\n" . $html;
}
function mail_send(string $to, string $subject, string $html): array {
    $m = mail_config(true);
    $t = mail_transport();
    $from = mail_from_address();
    $fromName = $m['from_name'] !== '' ? $m['from_name'] : (string)setting('platform_name', APP_NAME);
    try {
        if ($t === 'log') {
            $dir = APP_ROOT . '/data';
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $line = jenc(['ts' => now_dt(), 'to' => $to, 'subject' => $subject,
                'from' => $from, 'transport' => 'log']) . "\n";
            $ok = @file_put_contents($dir . '/mail.log', $line, FILE_APPEND) !== false;
            return ['ok' => $ok, 'transport' => 'log',
                'error' => $ok ? null : 'data/mail.log is not writable'];
        }
        if ($t === 'smtp') return smtp_send($m, $to, $subject, $html, $from, $fromName);
        $headers = 'From: ' . mail_mime_encode($fromName) . ' <' . $from . ">\r\n"
            . 'MIME-Version: 1.0' . "\r\n"
            . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
            . 'X-KIVU-Reminder: payment';
        $ok = @mail($to, mail_subject($subject), $html, $headers);
        return ['ok' => $ok, 'transport' => 'mail', 'error' => $ok ? null
            : 'PHP mail() failed — configure an SMTP host under Plans & billing, or check sendmail.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'transport' => $t, 'error' => $e->getMessage()];
    }
}

/* Minimal SMTP client (EHLO → optional STARTTLS → optional AUTH LOGIN →
   MAIL/RCPT/DATA). No dependencies, which is how this project ships. */
function smtp_send(array $m, string $to, string $subject, string $html, string $from, string $fromName): array {
    $host = $m['smtp_host'];
    $port = $m['smtp_port'];
    $enc = $m['smtp_encryption'];
    $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 10);
    if (!$fp) return ['ok' => false, 'transport' => 'smtp',
        'error' => 'Cannot connect to ' . $host . ':' . $port . ' — ' . ($errstr ?: 'error ' . $errno)];
    stream_set_timeout($fp, 15);
    $read = function () use ($fp): string {
        $buf = '';
        for ($i = 0; $i < 60; $i++) {
            $line = fgets($fp, 1024);
            if ($line === false) break;
            $buf .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;    // final reply line
            if (strlen($line) < 4) break;
        }
        return $buf;
    };
    $say = function (string $cmd) use ($fp, $read): string {
        fwrite($fp, $cmd . "\r\n");
        return $read();
    };
    $code = fn(string $r): int => (int)substr($r, 0, 3);
    $fail = function (string $error) use ($fp): array {
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return ['ok' => false, 'transport' => 'smtp', 'error' => $error];
    };
    try {
        $r = $read();                                            // 220 greeting
        if ($code($r) !== 220) return $fail('SMTP greeting failed: ' . trim($r));
        $ehlo = 'EHLO ' . (gethostname() ?: 'kivu-tracker.local');
        $r = $say($ehlo);
        if ($code($r) !== 250) return $fail('EHLO rejected: ' . trim($r));
        if ($enc === 'tls') {
            $r = $say('STARTTLS');
            if ($code($r) !== 220) return $fail('STARTTLS refused: ' . trim($r));
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return $fail('TLS negotiation with ' . $host . ' failed');
            }
            $r = $say($ehlo);
            if ($code($r) !== 250) return $fail('EHLO after STARTTLS rejected: ' . trim($r));
        }
        if ($m['smtp_user'] !== '') {
            $r = $say('AUTH LOGIN');
            if ($code($r) !== 334) return $fail('AUTH LOGIN refused: ' . trim($r));
            $r = $say(base64_encode($m['smtp_user']));
            if ($code($r) !== 334) return $fail('SMTP username rejected: ' . trim($r));
            $r = $say(base64_encode($m['smtp_pass']));
            if ($code($r) !== 235) return $fail('SMTP authentication failed: ' . trim($r));
        }
        $r = $say('MAIL FROM:<' . $from . '>');
        if ($code($r) !== 250) return $fail('MAIL FROM rejected: ' . trim($r));
        $r = $say('RCPT TO:<' . $to . '>');
        if (!in_array($code($r), [250, 251], true)) return $fail('Recipient rejected: ' . trim($r));
        $r = $say('DATA');
        if ($code($r) !== 354) return $fail('DATA refused: ' . trim($r));
        $payload = preg_replace('/^\./m', '..', mail_body($fromName, $from, $to, $subject, $html));
        fwrite($fp, $payload . "\r\n.\r\n");
        $r = $read();
        if ($code($r) !== 250) return $fail('Message rejected: ' . trim($r));
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return ['ok' => true, 'transport' => 'smtp', 'error' => null];
    } catch (Throwable $e) {
        @fclose($fp);
        return ['ok' => false, 'transport' => 'smtp', 'error' => $e->getMessage()];
    }
}

/* ---------------------------------------------------------- recipients --- */
function account_reminder_email(array $acc): ?string {
    foreach ([$acc['contact_email'] ?? null, $acc['billing_email'] ?? null] as $e) {
        $e = trim((string)$e);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
    }
    $st = db()->prepare("SELECT email FROM users WHERE account_id = ? AND role = 'admin' AND enabled = 1
                         ORDER BY created_at ASC");
    $st->execute([(string)$acc['id']]);
    foreach ($st->fetchAll() as $u) {
        $e = trim((string)($u['email'] ?? ''));
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
    }
    return null;
}

/* The moment the account stops working: trial end, paid period end, or the
   end of the past-due grace window. null = never (comp / suspended / no plan). */
function billing_cutoff_ts(array $acc, array $state): ?int {
    if ($state['status'] === 'trialing') {
        $ts = !empty($acc['trial_ends_at']) ? strtotime((string)$acc['trial_ends_at']) : false;
        return ($ts !== false && $ts > 0) ? $ts : null;
    }
    if (in_array($state['status'], ['active', 'past_due'], true)) {
        $ts = !empty($state['period_end']) ? strtotime((string)$state['period_end']) : false;
        if ($ts === false || $ts <= 0) return null;
        if ($state['status'] === 'past_due') $ts += billing_grace_days() * 86400;
        return $ts;
    }
    return null;
}

/* ------------------------------------------------------------ rendering -- */
/* Local HTML helpers — the JS console has its own h(). */
function re_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function re_money(float $v, string $cur): string { return number_format($v, 2) . ' ' . re_h($cur); }

function billing_reminder_render(array $acc, array $state, int $daysLeft, string $cutoffLabel): array {
    $platform = (string)setting('platform_name', APP_NAME);
    $plan = $state['plan'] ?? null;
    $what = $state['status'] === 'trialing' ? 'free trial' : 'subscription';
    $price = '';
    if ($plan) {
        $price = '<p style="margin:6px 0"><b>Amount due:</b> '
            . re_money((float)$plan['price'], (string)$plan['currency'])
            . ($plan['interval'] === 'year' ? ' per year' : ' per month')
            . ' — ' . re_h((string)$plan['name']) . '</p>';
    }
    $payUrl = re_h(rtrim(app_base_url(), '/') . '/billing');
    $urgent = $daysLeft <= 2;
    $subject = ($urgent ? 'FINAL REMINDER — ' : 'Payment reminder — ')
        . $acc['name'] . ' deactivates in ' . $daysLeft . ($daysLeft === 1 ? ' day' : ' days');
    /* the platform logo heads the message (absolute URL so mail clients can load it) */
    $logo = branding_logo_url();
    $logoHtml = '';
    if ($logo !== '') {
        $src = preg_match('#^https?://#i', $logo) === 1 ? $logo : rtrim(app_base_url(), '/') . $logo;
        $logoHtml = '<img src="' . re_h($src) . '" alt="' . re_h($platform)
            . '" style="max-height:52px;max-width:240px;margin:0 0 10px">';
    }
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:15px;line-height:1.55;color:#16202b">'
        . $logoHtml
        . '<h2 style="margin:0 0 10px;color:' . ($urgent ? '#c0392b' : '#12395b') . '">'
        . ($urgent ? 'Final payment reminder' : 'Payment reminder') . '</h2>'
        . '<p>Hello ' . re_h($acc['name']) . ',</p>'
        . '<p>Your <b>' . $what . '</b> for <b>' . re_h($platform) . '</b> ends on <b>'
        . re_h($cutoffLabel) . '</b> — in <b>' . $daysLeft
        . ($daysLeft === 1 ? ' day' : ' days') . '</b>.'
        . ($urgent
            ? ' After that the account is <b>deactivated</b>: trackers stop reporting and no new'
              . ' positions are stored.'
            : ' After that the account will be <b>deactivated</b> until you pay — trackers stop'
              . ' reporting and no new positions are stored.') . '</p>'
        . $price
        . '<p style="margin:14px 0"><a href="' . $payUrl . '" style="background:#1f6feb;color:#fff;'
        . 'padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block">'
        . 'Choose a plan &amp; pay now</a></p>'
        . '<p style="color:#5b6b7c;font-size:13px">Pay by card, M-Pesa, Pesapal, Flutterwave, PayPal'
        . ' or bank transfer — tracking continues the moment the payment is confirmed.</p>'
        . '<p style="color:#8a97a5;font-size:12px">' . re_h($platform) . ' payment reminders &mdash; sent '
        . implode(', ', billing_reminder_days()) . ' days before deactivation.</p></div>';
    return [$subject, $html];
}

/* --------------------------------------------------------------- send ---- */
/* One row per (account, kind, deactivation date): the unique key makes the
   sweep idempotent, while $force lets the super admin resend manually. */
function billing_reminder_send(array $acc, string $kind, int $daysBefore, string $cutoff,
                               int $daysLeft, bool $force = false): array {
    $recipient = account_reminder_email($acc);
    if ($recipient === null) return ['ok' => false, 'skipped' => 'no_recipient'];
    $state = billing_state((string)$acc['id'], $acc);
    [$subject, $html] = billing_reminder_render($acc, $state, $daysLeft,
        date('d M Y, H:i', strtotime($cutoff)));
    $st = db()->prepare('SELECT * FROM billing_reminders WHERE account_id = ? AND kind = ? AND cutoff_at = ?');
    $st->execute([(string)$acc['id'], $kind, $cutoff]);
    $row = $st->fetch();
    if ($row && (string)$row['status'] === 'sent' && !$force) {
        return ['ok' => false, 'skipped' => 'already_sent', 'id' => (string)$row['id']];
    }
    if ($row && (int)$row['attempts'] >= 3 && !$force) {
        return ['ok' => false, 'skipped' => 'gave_up', 'id' => (string)$row['id']];
    }
    $res = mail_send($recipient, $subject, $html);
    $now = now_dt();
    $status = $res['ok'] ? 'sent' : 'failed';
    $error = $res['ok'] ? null : mb_substr((string)($res['error'] ?? 'send failed'), 0, 255);
    $id = $row ? (string)$row['id'] : uid('rem');
    if ($row) {
        db()->prepare('UPDATE billing_reminders SET days_before = ?, days_left = ?, recipient = ?, subject = ?,
                       body = ?, channel = ?, status = ?, attempts = attempts + 1, error = ?,
                       sent_at = ? WHERE id = ?')
            ->execute([$daysBefore, $daysLeft, $recipient, $subject, $html, $res['transport'],
                $status, $error, $status === 'sent' ? $now : null, $id]);
    } else {
        db()->prepare('INSERT INTO billing_reminders (id, account_id, kind, days_before, cutoff_at, days_left,
                       recipient, subject, body, channel, status, attempts, error, sent_at, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)')
            ->execute([$id, (string)$acc['id'], $kind, $daysBefore, $cutoff, $daysLeft, $recipient,
                $subject, $html, $res['transport'], $status, $error, $status === 'sent' ? $now : null, $now]);
    }
    try {
        audit('payment_reminder', 'account', (string)$acc['id'],
            ['kind' => $kind, 'to' => $recipient, 'status' => $status,
             'days_left' => $daysLeft, 'cutoff' => $cutoff, 'transport' => $res['transport']]);
    } catch (Throwable $e) { /* the reminder itself is the record */ }
    return ['ok' => $res['ok'], 'id' => $id, 'status' => $status, 'error' => $error,
        'recipient' => $recipient, 'subject' => $subject];
}

/* The sweep: called from /api/tasks/run (cron) and the IPS daemon's hourly
   timer. Accounts are walked once; each applicable offset mails at most once
   per deactivation date. */
function billing_reminders_sweep(?string $accountId = null): array {
    ensure_schema();
    if (!billing_reminders_enabled()) {
        return ['enabled' => false, 'ran' => true, 'sent' => 0, 'failed' => 0, 'skipped' => 0,
            'no_recipient' => 0];
    }
    $thresholds = billing_reminder_days();                     // descending
    $sql = 'SELECT * FROM accounts WHERE enabled = 1';
    $prm = [];
    if ($accountId !== null && $accountId !== '') { $sql .= ' AND id = ?'; $prm[] = $accountId; }
    $st = db()->prepare($sql);
    $st->execute($prm);
    $sent = 0; $failed = 0; $skipped = 0; $noRecipient = 0;
    foreach ($st->fetchAll() as $acc) {
        $state = billing_state((string)$acc['id'], $acc);
        $cutoffTs = billing_cutoff_ts($acc, $state);
        if ($cutoffTs === null || $cutoffTs <= time()) { $skipped++; continue; }
        $left = (int)ceil(($cutoffTs - time()) / 86400);
        /* smallest threshold that still covers the remaining time — the
           offsets partition the countdown: 6-14d → 2-week mail, 3-5d →
           5-day mail, 1-2d → final mail (a fresh 5-day trial starts at the
           5-day mark instead of receiving an impossible 2-week notice). */
        $band = null;
        foreach (array_reverse($thresholds) as $t) {
            if ($left <= $t) { $band = $t; break; }
        }
        if ($band === null) { $skipped++; continue; }
        if (account_reminder_email($acc) === null) { $noRecipient++; continue; }
        $r = billing_reminder_send($acc, $band . 'd', $band, date('Y-m-d H:i:s', $cutoffTs), $left);
        if (!empty($r['ok'])) $sent++;
        elseif (!in_array($r['skipped'] ?? '', ['already_sent', 'gave_up'], true)) $failed++;
    }
    return ['enabled' => true, 'ran' => true, 'sent' => $sent, 'failed' => $failed,
        'skipped' => $skipped, 'no_recipient' => $noRecipient];
}

function billing_reminders_list(?string $accountId = null, int $limit = 100): array {
    ensure_schema();
    $sql = 'SELECT r.*, a.name AS account_name FROM billing_reminders r
            LEFT JOIN accounts a ON a.id = r.account_id WHERE 1=1';
    $prm = [];
    if ($accountId !== null && $accountId !== '') { $sql .= ' AND r.account_id = ?'; $prm[] = $accountId; }
    $sql .= ' ORDER BY r.created_at DESC LIMIT ' . max(1, min(500, $limit));
    $st = db()->prepare($sql);
    $st->execute($prm);
    return array_map(fn($r) => ['id' => $r['id'], 'account_id' => $r['account_id'],
        'account_name' => $r['account_name'] ?? '(deleted)', 'kind' => $r['kind'],
        'days_before' => (int)$r['days_before'], 'cutoff_at' => iso($r['cutoff_at']),
        'days_left' => (int)$r['days_left'], 'recipient' => $r['recipient'],
        'subject' => $r['subject'], 'channel' => $r['channel'], 'status' => $r['status'],
        'attempts' => (int)$r['attempts'], 'error' => $r['error'], 'sent_at' => iso($r['sent_at']),
        'created_at' => iso($r['created_at'])], $st->fetchAll());
}

/* ------------------------------------------------------ reseller portals -- */
/* A reseller buys a FULL YEAR of their own portal; once the annual invoice is
   paid (any gateway, including the bank/manual confirmation) the portal
   activates for 365 days and the owner (role: reseller) can create client
   accounts inside it. */
function portal_price(): float { return max(0, (float)setting('portal_price', 999)); }
function portal_by_id(string $id): ?array {
    ensure_schema();
    $st = db()->prepare('SELECT * FROM portals WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function portal_by_owner(string $userId): ?array {
    ensure_schema();
    $st = db()->prepare('SELECT * FROM portals WHERE owner_user_id = ? ORDER BY created_at DESC LIMIT 1');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}
function portal_shape(array $p): array {
    $end = !empty($p['period_end']) ? strtotime((string)$p['period_end']) : false;
    $left = ($end !== false && $end > time()) ? (int)ceil(($end - time()) / 86400) : 0;
    $status = (string)$p['status'];
    if ($status === 'active' && $end !== false && $end <= time()) $status = 'expired';
    return ['id' => $p['id'], 'name' => $p['name'], 'slug' => $p['slug'],
        'owner_user_id' => $p['owner_user_id'], 'status' => $status,
        'price' => (float)$p['price'], 'currency' => $p['currency'],
        'period_start' => iso($p['period_start']), 'period_end' => iso($p['period_end']),
        'days_left' => $left, 'contact_email' => $p['contact_email'],
        'created_at' => iso($p['created_at'])];
}
/* Activates (or extends) a portal for one full year — called when its annual
   invoice is settled, from billing_mark_paid, for every gateway. */
function portal_activate(string $portalId, int $days = 365): ?array {
    $portal = portal_by_id($portalId);
    if (!$portal) return null;
    $base = time();
    $cur = !empty($portal['period_end']) ? strtotime((string)$portal['period_end']) : false;
    if ($cur !== false && $cur > $base) $base = $cur;      // renewals stack
    $end = date('Y-m-d H:i:s', $base + max(1, $days) * 86400);
    db()->prepare('UPDATE portals SET status = ?, period_start = COALESCE(period_start, ?),
                   period_end = ? WHERE id = ?')
        ->execute(['active', now_dt(), $end, $portalId]);
    audit('portal_activated', 'portal', $portalId, ['period_end' => $end, 'days' => $days]);
    return portal_by_id($portalId);
}
/* Expired portals stop working: called from the renewal sweep. */
function portals_expire(): int {
    $n = 0;
    foreach (db()->query("SELECT id, status, period_end FROM portals WHERE status = 'active'")->fetchAll() as $p) {
        $end = !empty($p['period_end']) ? strtotime((string)$p['period_end']) : false;
        if ($end === false || $end > time()) continue;
        db()->prepare('UPDATE portals SET status = ? WHERE id = ?')->execute(['expired', (string)$p['id']]);
        audit('portal_expired', 'portal', (string)$p['id'], []);
        $n++;
    }
    return $n;
}

/* ============================================ billing profile & methods == */
/* Invoicing details must exist before the first payment: name + a valid
   billing e-mail are required, address/phone complete the profile. */
function billing_profile_missing(array $acc): array {
    $miss = [];
    if (trim((string)($acc['contact_name'] ?? '')) === ''
        && trim((string)($acc['name'] ?? '')) === '') $miss[] = 'contact_name';
    $mail = trim((string)($acc['billing_email'] ?? '')) ?: trim((string)($acc['contact_email'] ?? ''));
    if ($mail === '' || filter_var($mail, FILTER_VALIDATE_EMAIL) !== $mail) $miss[] = 'billing_email';
    return $miss;
}
function billing_profile_complete(array $acc): bool { return !billing_profile_missing($acc); }
function billing_profile_shape(array $acc): array {
    $miss = billing_profile_missing($acc);
    return ['complete' => !$miss, 'missing' => $miss,
        'address' => $acc['billing_address'] ?? null, 'city' => $acc['billing_city'] ?? null,
        'country' => $acc['billing_country'] ?? null];
}

/* Saved payment methods: one default per account, raw token never exposed. */
function payment_method_save(array $account, string $gateway, array $pm): ?array {
    $token = trim((string)($pm['token'] ?? ''));
    if ($token === '') return null;
    ensure_schema();
    try {
        db()->prepare('UPDATE payment_methods SET is_default = 0 WHERE account_id = ?')
            ->execute([(string)$account['id']]);
        $id = uid('pm');
        db()->prepare('INSERT INTO payment_methods (id,account_id,gateway,token,brand,last4,exp_month,
                       exp_year,holder,phone,customer_ref,status,is_default,last_used_at,created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,\'active\',1,NULL,?)')
            ->execute([$id, (string)$account['id'], $gateway, $token,
                (string)($pm['brand'] ?? '') ?: null,
                (string)($pm['last4'] ?? '') ?: null,
                isset($pm['exp_month']) && $pm['exp_month'] !== '' ? (int)$pm['exp_month'] : null,
                isset($pm['exp_year']) && $pm['exp_year'] !== '' ? (int)$pm['exp_year'] : null,
                (string)($pm['holder'] ?? '') ?: null,
                (string)($pm['phone'] ?? '') ?: null,
                (string)($pm['customer_ref'] ?? '') ?: null, now_dt()]);
        audit('payment_method_saved', 'account', (string)$account['id'],
            ['gateway' => $gateway, 'last4' => $pm['last4'] ?? null]);
        $st = db()->prepare('SELECT * FROM payment_methods WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}
function payment_method_default(string $accountId): ?array {
    ensure_schema();
    $st = db()->prepare('SELECT * FROM payment_methods WHERE account_id = ? AND status = ?
                         ORDER BY is_default DESC, created_at DESC LIMIT 1');
    $st->execute([$accountId, 'active']);
    $row = $st->fetch();
    return $row ?: null;
}
/* API shape — no raw token, ever. */
function payment_methods_list(string $accountId): array {
    ensure_schema();
    $st = db()->prepare('SELECT * FROM payment_methods WHERE account_id = ? ORDER BY is_default DESC, created_at DESC');
    $st->execute([$accountId]);
    return array_map(fn($m) => ['id' => $m['id'], 'gateway' => $m['gateway'], 'brand' => $m['brand'],
        'last4' => $m['last4'], 'exp_month' => $m['exp_month'] ? (int)$m['exp_month'] : null,
        'exp_year' => $m['exp_year'] ? (int)$m['exp_year'] : null, 'phone' => $m['phone'],
        'is_default' => (int)$m['is_default'], 'status' => $m['status'],
        'last_used_at' => iso($m['last_used_at']), 'created_at' => iso($m['created_at'])],
        $st->fetchAll());
}
function payment_method_delete(string $accountId, string $id): bool {
    $st = db()->prepare('SELECT * FROM payment_methods WHERE id = ? AND account_id = ?');
    $st->execute([$id, $accountId]);
    $row = $st->fetch();
    if (!$row) return false;
    db()->prepare('DELETE FROM payment_methods WHERE id = ?')->execute([$id]);
    audit('payment_method_removed', 'account', $accountId, ['gateway' => $row['gateway']]);
    return true;
}

/* ------------------------------------------------- automatic billing ----- */
/* Gateways that can charge a saved method server-side. Pesapal, PayPal and
   bank transfers have no tokenised repeat charge wired here — those accounts
   renew through the invoice + grace-window reminders instead. */
function gateway_supports_auto_charge(string $code): bool {
    return in_array($code, ['sandbox', 'mpesa', 'momo', 'airtel', 'stripe', 'flutterwave'], true);
}
/* Charges the saved method directly (off-session). Returns
   ['ok'=>bool, 'pending'=>bool, 'gateway_ref'=>?, 'error'=>?] — `pending`
   means the gateway needs the customer (M-Pesa STK push) and will call back. */
function pay_driver_charge(string $gateway, array $pay, ?array $plan, array $cfg, array $pm): array {
    if ($gateway === 'sandbox') {
        $token = (string)($pm['token'] ?? '');
        if (strpos($token, 'fail') !== false) {
            throw new ApiError('Card declined by the sandbox (test decline token)', 402);
        }
        return ['ok' => true, 'pending' => false, 'gateway_ref' => 'sbx_chg_' . $pay['id']];
    }
    if ($gateway === 'mpesa' || $gateway === 'momo' || $gateway === 'airtel') {
        /* the customer's phone gets the prompt — same flow as checkout */
        $phone = (string)($pm['phone'] ?? $pm['token'] ?? '');
        if ($gateway === 'mpesa') {
            $res = pay_mpesa_checkout($pay, $plan, $cfg, $phone);
        } elseif ($gateway === 'momo') {
            $res = pay_momo_checkout($pay, $plan, $cfg, $phone);
        } else {
            $res = pay_airtel_checkout($pay, $plan, $cfg, $phone);
        }
        return ['ok' => false, 'pending' => true, 'gateway_ref' => $res['gateway_ref'] ?? '',
            'error' => null];
    }
    if ($gateway === 'stripe') {
        $base = rtrim((string)($cfg['base'] ?? ''), '/') ?: 'https://api.stripe.com';
        $customer = (string)($pm['customer_ref'] ?? '');
        $token = (string)($pm['token'] ?? '');
        if ($customer === '' || $token === '') {
            return ['ok' => false, 'pending' => false, 'unsupported' => true,
                'error' => 'Stripe: no saved customer + payment method on file'];
        }
        $res = pay_http('POST', $base . '/v1/charges', ['form' => [
            'amount' => pay_minor_units($pay['amount'], (string)$pay['currency']),
            'currency' => strtolower((string)$pay['currency']),
            'customer' => $customer, 'payment_method' => $token, 'confirm' => 'true',
            'off_session' => 'true', 'description' => 'Renewal invoice ' . $pay['invoice_no'],
            'metadata[payment_id]' => (string)$pay['id']],
            'headers' => ['Authorization: Bearer ' . (string)($cfg['secret_key'] ?? '')]]);
        if (!$res['ok'] || empty($res['json']['id'])) {
            return ['ok' => false, 'pending' => false, 'error' => 'Stripe: ' . pay_error_text($res)];
        }
        return ['ok' => true, 'pending' => false, 'gateway_ref' => (string)$res['json']['id']];
    }
    if ($gateway === 'flutterwave') {
        $base = rtrim((string)($cfg['base'] ?? ''), '/') ?: 'https://api.flutterwave.com';
        $token = (string)($pm['token'] ?? '');
        if ($token === '') {
            return ['ok' => false, 'pending' => false, 'unsupported' => true,
                'error' => 'Flutterwave: no saved card token on file'];
        }
        $country = ['USD' => 'NG', 'KES' => 'KE', 'GHS' => 'GH', 'UGX' => 'UG', 'TZS' => 'TZ',
            'ZAR' => 'ZA', 'RWF' => 'RW', 'XAF' => 'CM', 'XOF' => 'CI'][strtoupper((string)$pay['currency'])] ?? 'NG';
        $res = pay_http('POST', $base . '/v3/charges', ['json' => [
            'token' => $token, 'email' => (string)($pay['payer_email'] ?? 'client@example.com'),
            'amount' => (float)$pay['amount'], 'currency' => strtoupper((string)$pay['currency']),
            'country' => $country, 'tx_ref' => 'ren-' . (string)$pay['invoice_no'],
            'narration' => 'Renewal invoice ' . (string)$pay['invoice_no']],
            'headers' => ['Authorization: Bearer ' . (string)($cfg['secret_key'] ?? '')]]);
        $st = strtolower((string)($res['json']['status'] ?? ''));
        if (!$res['ok'] || !in_array($st, ['success', 'ok'], true)) {
            return ['ok' => false, 'pending' => false, 'error' => 'Flutterwave: ' . pay_error_text($res)];
        }
        return ['ok' => true, 'pending' => false,
            'gateway_ref' => (string)($res['json']['data']['id'] ?? $res['json']['data']['tx_ref'] ?? '')];
    }
    return ['ok' => false, 'pending' => false, 'unsupported' => true,
        'error' => $gateway . ' cannot charge a saved method automatically'];
}

/* Charges saved methods whose trial/paid period has just run out. Runs from
   /api/tasks/run and the IPS daemon; per account:
     auto_renew off            → skipped (the client opted out)
     no saved method           → skipped (renewal by invoice + reminders)
     gateway off / unsupported → skipped (fallback behaviour)
     3 recent declines         → stopped until the client acts
   A successful charge goes through billing_mark_paid (idempotent), a pending
   M-Pesa STK push keeps its own callback, declines mark the invoice failed. */
function billing_auto_renew(): array {
    $res = ['attempted' => 0, 'renewed' => 0, 'declined' => 0, 'pending' => 0, 'skipped' => 0,
        'reasons' => []];
    /* why accounts were skipped — surfaced in the job response so the super
       console can explain a subscription that did not renew automatically */
    $skip = function (string $why) use (&$res) {
        $res['skipped']++;
        $res['reasons'][$why] = ($res['reasons'][$why] ?? 0) + 1;
    };
    try {
        $rows = db()->query('SELECT * FROM accounts WHERE enabled = 1')->fetchAll();
    } catch (Throwable $e) { return $res; }
    foreach ($rows as $acc) {
        $state = billing_state((string)$acc['id'], $acc);
        if (in_array($state['status'], ['comp', 'suspended'], true)) {
            $skip('manual:' . $state['status']); continue;
        }
        if ((int)($acc['auto_renew'] ?? 1) !== 1) { $skip('auto_renew_off'); continue; }
        $pm = payment_method_default((string)$acc['id']);
        if (!$pm) { $skip('no_method'); continue; }
        /* the moment that just passed: trial end first, else the paid period */
        $cutoff = null;
        if (!empty($acc['trial_ends_at'])) {
            $cutoff = strtotime((string)$acc['trial_ends_at']);
        }
        if ($cutoff === false || $cutoff === null) {
            $sub = subscription_current((string)$acc['id']);
            $cutoff = ($sub && !empty($sub['period_end'])) ? strtotime((string)$sub['period_end']) : false;
        }
        if ($cutoff === false || $cutoff === null) { $skip('no_cutoff'); continue; }
        if ($cutoff > time()) { $skip('still_in_period'); continue; }
        /* only renew shortly after the cut-off (grace window) — never months later */
        if (time() - $cutoff > billing_grace_days() * 86400) { $skip('outside_grace_window'); continue; }
        if (!gateway_active((string)$pm['gateway'])) { $skip('gateway_off:' . $pm['gateway']); continue; }
        if (!gateway_supports_auto_charge((string)$pm['gateway'])) {
            $skip('gateway_unsupported:' . $pm['gateway']); continue;      // invoice + reminders
        }
        $plan = $acc['plan_id'] ? plan_by_id((string)$acc['plan_id']) : null;
        if (!$plan) { $skip('no_plan'); continue; }             // nothing to charge
        /* one in-flight renewal invoice per account */
        $st = db()->prepare("SELECT COUNT(*) FROM payments WHERE account_id = ? AND status = 'pending'
                             AND created_at > ?");
        $st->execute([(string)$acc['id'], date('Y-m-d H:i:s', $cutoff)]);
        if ((int)$st->fetchColumn() > 0) { $skip('invoice_in_flight'); continue; }
        /* stop after three failed automatic charges for this cut-off */
        $st = db()->prepare("SELECT COUNT(*) FROM payments WHERE account_id = ? AND status = 'failed'
                             AND created_at > ? AND meta_json LIKE '%auto_renew%'");
        $st->execute([(string)$acc['id'], date('Y-m-d H:i:s', $cutoff)]);
        if ((int)$st->fetchColumn() >= 3) { $skip('decline_cap'); continue; }

        $payment = payment_create($acc, $plan, (string)$pm['gateway'],
            ['requested_by' => 'auto-renew', 'auto_renew' => true, 'save_method' => false]);
        $res['attempted']++;
        try {
            $r = pay_driver_charge((string)$pm['gateway'], $payment, $plan,
                gateway_config((string)$pm['gateway']), $pm);
        } catch (ApiError $e) {
            billing_mark_failed($payment, $e->getMessage(), ['auto_renew' => true]);
            $res['declined']++;
            audit('auto_renew_declined', 'account', (string)$acc['id'],
                ['invoice' => $payment['invoice_no'], 'error' => $e->getMessage()]);
            continue;
        } catch (Throwable $e) {
            billing_mark_failed($payment, 'Renewal charge failed: ' . $e->getMessage(),
                ['auto_renew' => true]);
            $res['declined']++;
            continue;
        }
        if (!empty($r['pending'])) {
            payment_touch((string)$payment['id'], ['gateway_ref' => $r['gateway_ref'] ?? null]);
            $res['pending']++;
            audit('auto_renew_prompted', 'account', (string)$acc['id'],
                ['invoice' => $payment['invoice_no'], 'gateway' => $pm['gateway']]);
            continue;
        }
        if (!empty($r['ok'])) {
            billing_mark_paid($payment, ['gateway_ref' => (string)($r['gateway_ref'] ?? ''),
                'meta' => ['auto_renew' => true, 'source' => 'auto_renew',
                    'verified' => true]]);
            db()->prepare('UPDATE payment_methods SET last_used_at = ? WHERE id = ?')
                ->execute([now_dt(), (string)$pm['id']]);
            $res['renewed']++;
            audit('subscription_auto_renewed', 'account', (string)$acc['id'],
                ['invoice' => $payment['invoice_no'], 'gateway' => $pm['gateway'],
                 'amount' => (float)$payment['amount']]);
        } else {
            billing_mark_failed($payment, (string)($r['error'] ?? 'Renewal charge failed'),
                ['auto_renew' => true]);
            $res['declined']++;
        }
    }
    return $res;
}

