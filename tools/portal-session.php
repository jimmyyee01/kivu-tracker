<?php
/* =====================================================================
   KIVU Tracker — tools/portal-session.php
   Development helper for the browser harnesses: provisions a reseller
   portal, pays its annual invoice with the sandbox gateway and prints the
   reseller's session token (nothing else on stdout), so pages can be driven
   with ?token=… headlessly.

       php tools/portal-session.php [base-url]           # create + print token
       php tools/portal-session.php [base-url] --cleanup # remove it again

   The portal is always named "Harness portal" and its owner is
   harness-portal / Portal@1234, so --cleanup can find it and remove the
   portal, its owner login and any client accounts inside it.
   ===================================================================== */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../php/config.php';

$args = array_slice($argv, 1);
$cleanup = in_array('--cleanup', $args, true) || in_array('--clean', $args, true);
$urls = array_values(array_filter($args, fn($a) => strpos((string)$a, '--') !== 0));
$base = rtrim($urls[0] ?? 'http://127.0.0.1:8099', '/');

function ps_req(string $base, string $method, string $path, $body = null, ?string $token = null): array {
    $ch = curl_init($base . $path);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'X-Auth-Token: ' . $token;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$raw, true);
    return ['status' => $code, 'json' => is_array($json) ? $json : null, 'raw' => (string)$raw];
}

/* sign in once — the portal list, the cleanup and the create all need it */
$super = ps_req($base, 'POST', '/api/auth/login',
    ['username' => 'superadmin', 'password' => 'SuperAdmin@123'])['json']['token'] ?? '';
if ($super === '') { fwrite(STDERR, "cannot sign in as superadmin\n"); exit(1); }

/* the harness portal + owner, as provisioned by a previous run */
$portals = ps_req($base, 'GET', '/api/portals', null, $super)['json']['portals'] ?? [];
$existing = null;
foreach ($portals as $p) if ((string)($p['name'] ?? '') === 'Harness portal') $existing = $p;

if ($cleanup) {
    ensure_schema();
    $removed = 0;
    foreach ($portals as $p) {
        if ((string)($p['name'] ?? '') !== 'Harness portal') continue;
        $st = db()->prepare('SELECT id FROM accounts WHERE portal_id = ?');
        $st->execute([(string)$p['id']]);
        foreach ($st->fetchAll() as $a) {
            ps_req($base, 'DELETE', '/api/accounts/' . $a['id'] . '?force=1', null, $super);
        }
        db()->prepare('DELETE FROM portals WHERE id = ?')->execute([(string)$p['id']]);
        if (!empty($p['owner_user_id'])) {
            db()->prepare("DELETE FROM users WHERE id = ? AND role = 'reseller'")
                ->execute([(string)$p['owner_user_id']]);
        }
        db()->prepare('DELETE FROM payments WHERE meta_json LIKE ?')->execute(['%"' . $p['id'] . '"%']);
        $removed++;
    }
    fwrite(STDERR, "removed $removed harness portal(s)\n");
    exit(0);
}

if (!$existing) {
    $made = ps_req($base, 'POST', '/api/portals', ['name' => 'Harness portal',
        'contact_email' => 'harness-portal@example.com', 'owner_username' => 'harness-portal',
        'owner_password' => 'Portal@1234', 'gateway' => 'sandbox'], $super);
    if ($made['status'] !== 200) {
        fwrite(STDERR, "portal create failed ({$made['status']}): " . (string)($made['json']['error'] ?? '') . "\n");
        exit(1);
    }
    $pay = (string)($made['json']['payment']['id'] ?? '');
    $done = ps_req($base, 'POST', '/api/pay/' . $pay . '/complete', ['outcome' => 'paid']);
    if ($done['status'] !== 200) {
        /* the gateway may need the payer phone for mobile money: fall back to a manual activation */
        ps_req($base, 'POST', '/api/portals/' . (string)($made['json']['portal']['id'] ?? '') . '/billing',
            ['action' => 'activate'], $super);
    }
}

$tok = ps_req($base, 'POST', '/api/auth/login',
    ['username' => 'harness-portal', 'password' => 'Portal@1234'])['json']['token'] ?? '';
if ($tok === '') { fwrite(STDERR, "cannot sign in as the portal owner\n"); exit(1); }
echo $tok;
