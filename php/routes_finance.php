<?php
/* =====================================================================
   KIVU Tracker — php/routes_finance.php
   Platform reports for the super admin:
     · /api/finance/financial   revenue, collections, MRR, plan/gateway mix
     · /api/finance/invoicing   invoice ledger, statuses and ageing buckets
     · /api/finance/alerts      invoicing alerts (overdue, trials, portals…)
     · /api/finance/performance per-account operational + billing KPIs
   Every report also answers ?format=csv (spreadsheet export).
   ===================================================================== */
declare(strict_types=1);

function routes_finance(): array {
    return [
        ['GET', '#^/finance/financial$#', 'h_fin_financial', 'super'],
        ['GET', '#^/finance/invoicing$#', 'h_fin_invoicing', 'super'],
        ['GET', '#^/finance/alerts$#', 'h_fin_alerts', 'super'],
        ['GET', '#^/finance/performance$#', 'h_fin_performance', 'super'],
    ];
}

/* from/to as Y-m-d (or ISO); defaults to the last 30 days. */
function fin_range(): array {
    $from = (string)qp('from', '');
    $to = (string)qp('to', '');
    $fromTs = $from !== '' ? strtotime($from) : strtotime('-29 days');
    $toTs = $to !== '' ? strtotime($to) : time();
    if ($fromTs === false) $fromTs = strtotime('-29 days');
    if ($toTs === false) $toTs = time();
    if ($fromTs > $toTs) { $tmp = $fromTs; $fromTs = $toTs; $toTs = $tmp; }
    return [date('Y-m-d 00:00:00', $fromTs), date('Y-m-d 23:59:59', $toTs),
        date('Y-m-d', $fromTs), date('Y-m-d', $toTs)];
}
function fin_csv(string $filename, array $headers, array $rows): array {
    $out = '';
    $line = [];
    foreach ($headers as $h) $line[] = '"' . str_replace('"', '""', (string)$h) . '"';
    $out .= implode(',', $line) . "\n";
    foreach ($rows as $r) {
        $line = [];
        foreach ($headers as $h) $line[] = '"' . str_replace('"', '""', (string)($r[$h] ?? '')) . '"';
        $out .= implode(',', $line) . "\n";
    }
    return ['__raw' => $out, '__content_type' => 'text/csv', '__filename' => $filename];
}

/* ------------------------------------------------ financial report ------- */
function h_fin_financial($user, $p): array {
    ensure_schema();
    [$from, $to, $fromDay, $toDay] = fin_range();
    $byCurrency = [];
    $st = db()->prepare("SELECT currency, status, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
                         FROM payments WHERE created_at BETWEEN ? AND ? GROUP BY currency, status");
    $st->execute([$from, $to]);
    $counts = ['paid' => 0, 'pending' => 0, 'failed' => 0, 'refunded' => 0, 'canceled' => 0];
    foreach ($st->fetchAll() as $r) {
        $cur = (string)$r['currency'];
        $byCurrency[$cur] = $byCurrency[$cur] ?? ['currency' => $cur, 'collected' => 0.0,
            'pending' => 0.0, 'failed' => 0.0, 'refunded' => 0.0, 'paid_count' => 0];
        $total = (float)$r['total']; $n = (int)$r['n']; $status = (string)$r['status'];
        if (isset($counts[$status])) $counts[$status] += $n;
        if ($status === 'paid') { $byCurrency[$cur]['collected'] += $total; $byCurrency[$cur]['paid_count'] += $n; }
        elseif ($status === 'pending') $byCurrency[$cur]['pending'] += $total;
        elseif ($status === 'failed') $byCurrency[$cur]['failed'] += $total;
        elseif ($status === 'refunded') $byCurrency[$cur]['refunded'] += $total;
    }
    /* MRR from paying accounts (annual plans divided by 12) */
    $mrr = 0.0; $activeAccounts = 0;
    foreach (db()->query('SELECT plan_id, status FROM accounts WHERE enabled = 1')->fetchAll() as $a) {
        if ((string)$a['status'] !== 'active') continue;
        $activeAccounts++;
        $plan = $a['plan_id'] ? plan_by_id((string)$a['plan_id']) : null;
        if ($plan) $mrr += (float)$plan['price'] / (($plan['interval'] === 'year') ? 12 : 1);
    }
    /* day series (invoiced vs collected) + gateway mix */
    $days = [];
    $st = db()->prepare("SELECT DATE(created_at) AS d,
                         SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) AS collected,
                         SUM(amount) AS invoiced, COUNT(*) AS n
                         FROM payments WHERE created_at BETWEEN ? AND ? GROUP BY d ORDER BY d");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) {
        $d = (string)$r['d'];
        $days[$d] = $days[$d] ?? ['date' => $d, 'invoiced' => 0.0, 'collected' => 0.0, 'invoices' => 0];
        $days[$d]['invoiced'] += (float)$r['invoiced'];
        $days[$d]['collected'] += (float)$r['collected'];
        $days[$d]['invoices'] += (int)$r['n'];
    }
    $gw = [];
    $st = db()->prepare("SELECT gateway, status, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total,
                         MAX(currency) AS currency
                         FROM payments WHERE created_at BETWEEN ? AND ? GROUP BY gateway, status");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) {
        $g = (string)$r['gateway'];
        $gw[$g] = $gw[$g] ?? ['gateway' => $g, 'invoices' => 0, 'collected' => 0.0,
            'currency' => (string)$r['currency']];
        $gw[$g]['invoices'] += (int)$r['n'];
        if ((string)$r['status'] === 'paid') $gw[$g]['collected'] += (float)$r['total'];
    }
    /* plan mix (paid invoices) + growth counters */
    $planMix = [];
    $st = db()->prepare("SELECT plan_id, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total, MAX(currency) AS currency
                         FROM payments WHERE created_at BETWEEN ? AND ? AND status = 'paid' GROUP BY plan_id");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) {
        $plan = $r['plan_id'] ? plan_by_id((string)$r['plan_id']) : null;
        $name = $plan ? (string)$plan['name'] : 'Portal / no plan';
        $planMix[$name] = $planMix[$name] ?? ['plan' => $name, 'payments' => 0, 'collected' => 0.0,
            'currency' => (string)$r['currency']];
        $planMix[$name]['payments'] += (int)$r['n'];
        $planMix[$name]['collected'] += (float)$r['total'];
    }
    $st = db()->prepare('SELECT COUNT(*) FROM accounts WHERE created_at BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    $newAccounts = (int)$st->fetchColumn();
    $st = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE created_at BETWEEN ? AND ?
                         AND status = 'paid' AND meta_json LIKE '%portal_id%'");
    $st->execute([$from, $to]);
    $portalRevenue = (float)$st->fetchColumn();
    $out = ['from' => $fromDay, 'to' => $toDay,
        'totals' => ['collected' => round(array_sum(array_column($byCurrency, 'collected')), 2),
            'mrr' => round($mrr, 2), 'active_accounts' => $activeAccounts,
            'new_accounts' => $newAccounts, 'portal_revenue' => $portalRevenue,
            'invoices' => $counts],
        'by_currency' => array_values($byCurrency), 'by_day' => array_values($days),
        'by_gateway' => array_values($gw), 'by_plan' => array_values($planMix)];
    if (strtolower((string)qp('format', '')) === 'csv') {
        $rows = [];
        foreach ($out['by_currency'] as $c) {
            $rows[] = ['section' => 'currency', 'key' => $c['currency'], 'collected' => $c['collected'],
                'pending' => $c['pending'], 'failed' => $c['failed'], 'refunded' => $c['refunded'],
                'invoices' => $c['paid_count']];
        }
        foreach ($out['by_gateway'] as $g) {
            $rows[] = ['section' => 'gateway', 'key' => $g['gateway'], 'collected' => $g['collected'],
                'pending' => '', 'failed' => '', 'refunded' => '', 'invoices' => $g['invoices']];
        }
        foreach ($out['by_plan'] as $pl) {
            $rows[] = ['section' => 'plan', 'key' => $pl['plan'], 'collected' => $pl['collected'],
                'pending' => '', 'failed' => '', 'refunded' => '', 'invoices' => $pl['payments']];
        }
        foreach ($out['by_day'] as $d) {
            $rows[] = ['section' => 'day', 'key' => $d['date'], 'collected' => $d['collected'],
                'pending' => '', 'failed' => '', 'refunded' => '', 'invoices' => $d['invoices']];
        }
        return fin_csv('financial-report-' . $fromDay . '_' . $toDay . '.csv',
            ['section', 'key', 'collected', 'pending', 'failed', 'refunded', 'invoices'], $rows);
    }
    return $out;
}

/* ------------------------------------------------ invoicing report ------- */
function h_fin_invoicing($user, $p): array {
    ensure_schema();
    [$from, $to, $fromDay, $toDay] = fin_range();
    $status = strtolower((string)qp('status', ''));
    $sql = 'SELECT p.*, a.name AS account_name FROM payments p
            LEFT JOIN accounts a ON a.id = p.account_id
            WHERE p.created_at BETWEEN ? AND ?';
    $prm = [$from, $to];
    if (in_array($status, ['paid', 'pending', 'failed', 'refunded', 'canceled'], true)) {
        $sql .= ' AND p.status = ?';
        $prm[] = $status;
    }
    $sql .= ' ORDER BY p.created_at DESC LIMIT 300';
    $st = db()->prepare($sql);
    $st->execute($prm);
    $buckets = ['b0_7' => 0, 'b8_30' => 0, 'b31_60' => 0, 'b60' => 0];
    $rows = [];
    $now = time();
    foreach ($st->fetchAll() as $r) {
        $age = max(0, (int)floor(($now - strtotime((string)$r['created_at'])) / 86400));
        if ((string)$r['status'] === 'pending') {
            if ($age <= 7) $buckets['b0_7']++;
            elseif ($age <= 30) $buckets['b8_30']++;
            elseif ($age <= 60) $buckets['b31_60']++;
            else $buckets['b60']++;
        }
        $rows[] = ['invoice_no' => $r['invoice_no'], 'account' => $r['account_name'] ?? $r['account_id'],
            'plan_id' => $r['plan_id'], 'gateway' => $r['gateway'], 'amount' => (float)$r['amount'],
            'currency' => $r['currency'], 'status' => $r['status'], 'age_days' => $age,
            'created_at' => iso($r['created_at']), 'paid_at' => iso($r['paid_at']),
            'payer_email' => $r['payer_email'], 'failure_reason' => $r['failure_reason']];
    }
    $totals = [];
    foreach (db()->query("SELECT status, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total, MAX(currency) AS currency
                          FROM payments WHERE created_at BETWEEN " . db()->quote($from) . ' AND '
                          . db()->quote($to) . ' GROUP BY status')->fetchAll() as $t) {
        $totals[] = ['status' => $t['status'], 'invoices' => (int)$t['n'],
            'amount' => (float)$t['total'], 'currency' => (string)($t['currency'] ?? 'USD')];
    }
    $out = ['from' => $fromDay, 'to' => $toDay, 'status_filter' => $status,
        'totals' => $totals, 'aging' => $buckets, 'rows' => $rows];
    if (strtolower((string)qp('format', '')) === 'csv') {
        return fin_csv('invoicing-report-' . $fromDay . '_' . $toDay . '.csv',
            ['invoice_no', 'account', 'gateway', 'amount', 'currency', 'status', 'age_days',
             'created_at', 'paid_at', 'payer_email', 'failure_reason'], $rows);
    }
    return $out;
}

/* --------------------------------------------- invoicing alerts ---------- */
function fin_alert(string $severity, string $kind, string $ref, string $message, array $extra = []): array {
    return array_merge(['severity' => $severity, 'kind' => $kind, 'ref' => $ref,
        'message' => $message], $extra);
}
function h_fin_alerts($user, $p): array {
    ensure_schema();
    $overdueDays = max(0, (int)qp('overdue_days', 7));
    $alerts = [];
    /* unpaid invoices older than the grace the platform expects (<= so an
       invoice created in the same second is still counted when overdue_days=0) */
    $cut = date('Y-m-d H:i:s', time() - $overdueDays * 86400);
    $st = db()->prepare("SELECT p.*, a.name AS account_name FROM payments p
                         LEFT JOIN accounts a ON a.id = p.account_id
                         WHERE p.status = 'pending' AND p.created_at <= ? ORDER BY p.created_at ASC LIMIT 100");
    $st->execute([$cut]);
    foreach ($st->fetchAll() as $r) {
        $age = (int)floor((time() - strtotime((string)$r['created_at'])) / 86400);
        $alerts[] = fin_alert($age > 30 ? 'danger' : 'warn', 'overdue_invoice', (string)$r['invoice_no'],
            'Invoice ' . $r['invoice_no'] . ' for ' . ($r['account_name'] ?? $r['account_id'])
            . ' is unpaid after ' . $age . ' day(s)',
            ['amount' => (float)$r['amount'], 'currency' => $r['currency'], 'age_days' => $age,
             'gateway' => $r['gateway']]);
    }
    /* accounts: grace, trials about to end, expired */
    foreach (db()->query('SELECT * FROM accounts WHERE enabled = 1')->fetchAll() as $a) {
        $state = billing_state((string)$a['id'], $a);
        if ($state['status'] === 'past_due') {
            $alerts[] = fin_alert('danger', 'account_past_due', (string)$a['id'],
                $a['name'] . ' is past due — ' . $state['grace_days_left'] . ' grace day(s) left',
                ['days_left' => (int)$state['grace_days_left']]);
        } elseif ($state['status'] === 'trialing' && (int)$state['trial_days_left'] <= 2) {
            $alerts[] = fin_alert('warn', 'trial_ending', (string)$a['id'],
                $a['name'] . ' trial ends in ' . $state['trial_days_left'] . ' day(s)',
                ['days_left' => (int)$state['trial_days_left']]);
        } elseif ($state['status'] === 'expired') {
            $alerts[] = fin_alert('warn', 'account_expired', (string)$a['id'],
                $a['name'] . ' subscription/trial has ended', []);
        }
    }
    /* reseller portals: unpaid, expiring, expired */
    foreach (db()->query('SELECT * FROM portals')->fetchAll() as $pt) {
        $shape = portal_shape($pt);
        if ($shape['status'] === 'pending') {
            $alerts[] = fin_alert('warn', 'portal_unpaid', (string)$pt['id'],
                'Portal ' . $pt['name'] . ' has not paid the full year (' . $shape['price'] . ' '
                . $shape['currency'] . ')', ['amount' => $shape['price']]);
        } elseif ($shape['status'] === 'expired') {
            $alerts[] = fin_alert('danger', 'portal_expired', (string)$pt['id'],
                'Portal ' . $pt['name'] . ' year has ended — renew to keep the reseller working', []);
        } elseif ($shape['days_left'] <= 30) {
            $alerts[] = fin_alert('warn', 'portal_expiring', (string)$pt['id'],
                'Portal ' . $pt['name'] . ' expires in ' . $shape['days_left'] . ' day(s)',
                ['days_left' => (int)$shape['days_left']]);
        }
    }
    /* failed automatic renewals + undelivered reminders (last 7 days) */
    $st = db()->prepare("SELECT COUNT(*) FROM payments WHERE status = 'failed'
                         AND meta_json LIKE '%auto_renew%' AND created_at > ?");
    $st->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]);
    $autoFailed = (int)$st->fetchColumn();
    if ($autoFailed > 0) {
        $alerts[] = fin_alert('warn', 'auto_renew_failed', 'payments',
            $autoFailed . ' automatic renewal(s) failed in the last 7 days', ['count' => $autoFailed]);
    }
    $st = db()->prepare("SELECT COUNT(*) FROM billing_reminders WHERE status = 'failed' AND created_at > ?");
    $st->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]);
    $remFailed = (int)$st->fetchColumn();
    if ($remFailed > 0) {
        $alerts[] = fin_alert('info', 'reminder_send_failed', 'billing_reminders',
            $remFailed . ' payment reminder e-mail(s) could not be delivered (check the SMTP settings)',
            ['count' => $remFailed]);
    }
    $out = ['generated_at' => date('c'), 'overdue_days' => $overdueDays,
        'count' => count($alerts), 'alerts' => $alerts];
    if (strtolower((string)qp('format', '')) === 'csv') {
        return fin_csv('invoicing-alerts.csv',
            ['severity', 'kind', 'ref', 'message', 'amount', 'days_left'], $alerts);
    }
    return $out;
}

/* --------------------------------------------- performance report -------- */
/* Per-account operational + billing KPIs: units (online/total), positions
   processed, trips, distance, plan utilisation and revenue in the range. */
function h_fin_performance($user, $p): array {
    ensure_schema();
    [$from, $to, $fromDay, $toDay] = fin_range();
    $rows = [];
    $totUnits = 0; $totOnline = 0; $totMessages = 0; $totTrips = 0; $totKm = 0.0;
    foreach (db()->query('SELECT * FROM accounts ORDER BY name')->fetchAll() as $a) {
        $id = (string)$a['id'];
        $units = units_list(['account_id' => $id]);
        $online = 0;
        foreach ($units as $u) if (!empty($u['online'])) $online++;
        $st = db()->prepare('SELECT COUNT(*) FROM messages m JOIN units u ON u.id = m.unit_id
                             WHERE u.account_id = ? AND m.ts BETWEEN ? AND ?');
        $st->execute([$id, $from, $to]);
        $msgs = (int)$st->fetchColumn();
        $st = db()->prepare('SELECT COUNT(*) AS trips, COALESCE(SUM(distance_km),0) AS km FROM trips
                             WHERE account_id = ? AND start_ts BETWEEN ? AND ?');
        $st->execute([$id, $from, $to]);
        $trip = $st->fetch() ?: ['trips' => 0, 'km' => 0];
        $st = db()->prepare("SELECT COALESCE(SUM(amount),0) AS revenue, MAX(currency) AS currency
                             FROM payments WHERE account_id = ? AND status = 'paid'
                             AND created_at BETWEEN ? AND ?");
        $st->execute([$id, $from, $to]);
        $rev = $st->fetch() ?: ['revenue' => 0, 'currency' => $a['currency']];
        $limit = (int)$a['units_limit'];
        $state = billing_state($id, $a);
        $rows[] = ['account' => $a['name'], 'status' => $state['status'], 'plan' => $a['plan'],
            'units' => count($units), 'units_online' => $online, 'units_limit' => $limit,
            'utilisation_pct' => $limit > 0 ? round(count($units) * 100 / $limit, 1) : 0.0,
            'messages' => $msgs, 'trips' => (int)$trip['trips'], 'distance_km' => round((float)$trip['km'], 1),
            'revenue' => (float)$rev['revenue'], 'currency' => (string)($rev['currency'] ?? $a['currency'])];
        $totUnits += count($units); $totOnline += $online;
        $totMessages += $msgs; $totTrips += (int)$trip['trips']; $totKm += (float)$trip['km'];
    }
    $spanDays = max(1, (int)round((strtotime($to) - strtotime($from)) / 86400) + 1);
    $st = db()->prepare('SELECT COUNT(*) FROM accounts WHERE created_at BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    $newAccounts = (int)$st->fetchColumn();
    $out = ['from' => $fromDay, 'to' => $toDay, 'span_days' => $spanDays,
        'platform' => ['accounts' => count($rows), 'new_accounts' => $newAccounts,
            'units' => $totUnits, 'units_online' => $totOnline,
            'online_pct' => $totUnits > 0 ? round($totOnline * 100 / $totUnits, 1) : 0.0,
            'messages' => $totMessages, 'messages_per_day' => (int)round($totMessages / $spanDays),
            'trips' => $totTrips, 'distance_km' => round($totKm, 1),
            'positions_per_unit_day' => $totUnits > 0
                ? (int)round($totMessages / ($totUnits * $spanDays)) : 0],
        'accounts' => $rows];
    if (strtolower((string)qp('format', '')) === 'csv') {
        return fin_csv('performance-report-' . $fromDay . '_' . $toDay . '.csv',
            ['account', 'status', 'plan', 'units', 'units_online', 'units_limit', 'utilisation_pct',
             'messages', 'trips', 'distance_km', 'revenue', 'currency'], $rows);
    }
    return $out;
}
