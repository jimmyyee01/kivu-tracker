<?php
/* =====================================================================
   KIVU Tracker — tools/payments-test.php
   Gateway integration tests: Stripe, Pesapal, M-Pesa, Flutterwave and
   PayPal are driven for real (their request/response handling, signature
   checks and callbacks) against tools/gateway-stub.php, so no API keys
   and no money are involved.

       php tools/payments-test.php [console-url] [stub-port]

   For every gateway the test verifies:
     * the checkout request the driver sends (amount, currency, callback,
       phone, API base + credentials used)
     * the redirect/push response handling (checkout_url / gateway_ref)
     * the callback (webhook) settling the invoice + idempotency
   ===================================================================== */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$stubPort = (int)($argv[2] ?? 8097);
$stub = 'http://127.0.0.1:' . $stubPort;
$pass = 0; $fail = 0; $failures = [];

function req(string $method, string $path, $body = null, ?string $token = null, ?array $extra = null): array {
    global $base;
    $ch = curl_init($base . $path);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'X-Auth-Token: ' . $token;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    foreach ((array)$extra as $k => $v) $headers[] = $k . ': ' . $v;
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$raw, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => (string)$raw];
}
function stub_req(string $method, string $path): array {
    global $stub;
    $ch = curl_init($stub . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $json = json_decode((string)$raw, true);
    return is_array($json) ? $json : [];
}
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; $failures[] = $name . ($detail !== '' ? " — $detail" : ''); echo "  FAIL  $name  ($detail)\n"; }
}
function section(string $t): void { echo "\n== $t ==\n"; }
/* the last stub request whose URI matches a pattern */
function stub_last(array $requests, string $needle): ?array {
    $found = null;
    foreach ($requests as $r) if (strpos((string)$r['uri'], $needle) !== false) $found = $r;
    return $found;
}
/* exact match on method + path (e.g. the order create, not its capture) */
function stub_exact(array $requests, string $method, string $path): ?array {
    $found = null;
    foreach ($requests as $r) {
        if (strtoupper((string)$r['method']) === strtoupper($method) && (string)$r['uri'] === $path) $found = $r;
    }
    return $found;
}
function stub_post(string $url, array $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $json = json_decode((string)$raw, true);
    return is_array($json) ? $json : [];
}
/* dotted lookup: apath($data, 'payment.status') */
function apath($data, string $path) {
    foreach (explode('.', $path) as $key) {
        if (!is_array($data) || !array_key_exists($key, $data)) return null;
        $data = $data[$key];
    }
    return $data;
}

/* ------------------------------------------------------------- stub start */
$probe = @fsockopen('127.0.0.1', $stubPort, $e, $s, 0.5);
$stubProc = null;
if (!$probe) {
    $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $stubPort . ' '
        . escapeshellarg(dirname(__DIR__) . '/tools/gateway-stub.php');
    $desc = [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
             1 => ['file', dirname(__DIR__) . '/data/stub.out', 'a'],
             2 => ['file', dirname(__DIR__) . '/data/stub.err', 'a']];
    $stubProc = @proc_open($cmd, $desc, $pipes);
    for ($i = 0; $i < 40; $i++) {
        usleep(250000);
        $p = @fsockopen('127.0.0.1', $stubPort, $e2, $s2, 0.3);
        if ($p) { fclose($p); break; }
    }
} else { fclose($probe); }
$up = (bool)@fsockopen('127.0.0.1', $stubPort, $e3, $s3, 0.5);
echo 'Gateway stub on ' . $stub . ' — ' . ($up ? "ready\n" : "NOT RUNNING\n");
if (!$up) { echo "  cannot continue without the stub\n"; exit(1); }

$tag = substr((string)time(), -6);
echo "KIVU Tracker gateway driver tests — console $base, stub $stub, tag $tag\n";

/* ------------------------------------------------------- platform setup */
$super = req('POST', '/api/auth/login', ['username' => 'superadmin', 'password' => 'SuperAdmin@123'])['json']['token'] ?? '';
check('super admin signed in', $super !== '');

/* The platform is pointed at the stub: gateway bases + dummy credentials. */
$gateways = [
    'stripe' => ['secret_key' => 'sk_test_stub', 'publishable_key' => 'pk_test_stub',
        'webhook_secret' => 'whsec_stub_' . $tag, 'base' => $stub, 'env' => 'sandbox'],
    'pesapal' => ['consumer_key' => 'pesa_key', 'consumer_secret' => 'pesa_secret', 'base' => $stub, 'env' => 'sandbox'],
    'mpesa' => ['consumer_key' => 'mpesa_key', 'consumer_secret' => 'mpesa_secret',
        'shortcode' => '174379', 'passkey' => 'mpesa_passkey', 'base' => $stub, 'env' => 'sandbox'],
    'flutterwave' => ['public_key' => 'flw_pub', 'secret_key' => 'flw_secret', 'encryption_key' => 'flw_enc',
        'webhook_hash' => 'flw_hash_' . $tag, 'base' => $stub, 'env' => 'sandbox'],
    'paypal' => ['client_id' => 'paypal_client', 'secret' => 'paypal_secret', 'base' => $stub, 'env' => 'sandbox'],
];
$setup = req('PUT', '/api/billing/settings', ['gateway_mode' => 'sandbox', 'signup_enabled' => true,
    'reminders_enabled' => false,
    'trial_days' => 5, 'invoice_prefix' => 'KIVU', 'site_url' => $base, 'gateways' => $gateways], $super);
check('gateway credentials + stub base URLs saved', $setup['status'] === 200,
    'status ' . $setup['status'] . ' ' . substr($setup['raw'], 0, 160));
$cfg = req('GET', '/api/billing/settings', null, $super);
foreach (['stripe', 'pesapal', 'mpesa', 'flutterwave', 'paypal'] as $code) {
    $row = $cfg['json']['gateways'][$code] ?? [];
    check($code . ' is reported as configured', !empty($row['configured']), json_encode($row));
}
$bankRow = $cfg['json']['gateways']['bank'] ?? [];
check('the bank gateway can carry payment instructions',
    req('PUT', '/api/billing/settings', ['gateways' => ['bank' => ['instructions' => 'Pay to KIVU 12345']]], $super)['status'] === 200);

/* a client account for the payment runs */
$client = req('POST', '/api/signup', ['company' => 'Gateway client ' . $tag,
    'email' => 'gateway-' . $tag . '@example.com', 'password' => 'Gate@1234',
    'full_name' => 'Gateway Tester', 'phone' => '254712345678', 'plan_code' => 'starter'], null);
$token = $client['json']['token'] ?? '';
$accountId = $client['json']['account']['id'] ?? '';
check('a client account signed up for the payment runs', $token !== '' && $accountId !== '',
    substr($client['raw'], 0, 160));
$plans = req('GET', '/api/plans', null, null)['json']['plans'] ?? [];
$usdPlan = null; $kesPlan = null;
foreach ($plans as $p) {
    if ($p['code'] === 'starter') $usdPlan = $p;
    if ($p['currency'] === 'KES') $kesPlan = $p;
}
check('a USD and a KES plan are available', $usdPlan !== null && $kesPlan !== null);

/* ----------------------------------------------------------------- Stripe */
section('Stripe (cards: Visa / Mastercard / Amex)');
stub_req('GET', '/_reset');
$co = req('POST', '/api/billing/checkout', ['plan_id' => $usdPlan['id'], 'gateway' => 'stripe'], $token);
$payId = $co['json']['payment']['id'] ?? null;
check('stripe checkout created the invoice', is_string($payId), substr($co['raw'], 0, 200));
check('stripe returned its hosted checkout URL',
    strpos((string)($co['json']['checkout_url'] ?? ''), '/stub/stripe') !== false,
    (string)($co['json']['checkout_url'] ?? ''));
$log = stub_req('GET', '/_requests');
$sess = stub_last($log['requests'] ?? [], '/v1/checkout/sessions');
$body = $sess['body'] ?? [];
check('stripe received mode=payment and the client invoice reference',
    ($body['mode'] ?? '') === 'payment' && ($body['client_reference_id'] ?? '') === $payId,
    json_encode($body));
check('stripe was charged in minor units with the plan currency',
    (int)($body['line_items'][0]['price_data']['unit_amount'] ?? 0) === (int)round($usdPlan['price'] * 100)
    && ($body['line_items'][0]['price_data']['currency'] ?? '') === strtolower($usdPlan['currency']),
    json_encode([$body['line_items'][0]['price_data']['unit_amount'] ?? null,
        $body['line_items'][0]['price_data']['currency'] ?? null]));
check('stripe got the success/cancel URLs of the billing page',
    strpos((string)($body['success_url'] ?? ''), '/billing?payment=' . $payId) !== false
    && strpos((string)($body['cancel_url'] ?? ''), '/billing?payment=' . $payId) !== false);
check('stripe received our metadata for reconciliation',
    ($body['metadata']['payment_id'] ?? '') === $payId && ($body['metadata']['account_id'] ?? '') === $accountId);
check('the secret key was sent as a bearer token',
    stripos((string)($sess['headers']['authorization'] ?? ''), 'bearer sk_test_stub') === 0,
    (string)($sess['headers']['authorization'] ?? ''));
/* the client comes back and the invoice is confirmed with Stripe */
$poll = req('POST', '/api/billing/pay/' . $payId . '/status', [], $token);
check('polling the stripe session settles the invoice',
    ($poll['json']['settled'] ?? false) === true && ($poll['json']['status'] ?? '') === 'paid',
    substr($poll['raw'], 0, 200));
check('stripe was asked for the session status',
    stub_last(stub_req('GET', '/_requests')['requests'] ?? [], ($co['json']['gateway_ref'] ?? 'cs_stub_1')) !== null);
$stripeWebhook = json_encode(['id' => 'evt_stripe_' . $tag, 'type' => 'checkout.session.completed',
    'data' => ['object' => ['id' => 'cs_stub_1', 'payment_status' => 'paid',
        'payment_intent' => 'pi_stub_' . $tag, 'customer' => 'cus_stub_' . $tag,
        'payment_method' => ['id' => 'pm_stub_' . $tag, 'card' => ['brand' => 'Visa', 'last4' => '4242']],
        'metadata' => ['payment_id' => $payId]]]]);
$stamp = time();
$sig = 't=' . $stamp . ',v1=' . hash_hmac('sha256', $stamp . '.' . $stripeWebhook, 'whsec_stub_' . $tag);
$wh = req('POST', '/api/webhooks/stripe', $stripeWebhook, null, ['Stripe-Signature' => $sig]);
check('a signed stripe webhook is accepted',
    $wh['status'] === 200 && ($wh['json']['verified'] ?? false) === true, substr($wh['raw'], 0, 200));
check('the same stripe webhook replayed is idempotent',
    (req('POST', '/api/webhooks/stripe', $stripeWebhook, null, ['Stripe-Signature' => $sig])['json']['duplicate'] ?? false) === true);
check('an unsigned stripe webhook is rejected (400)',
    req('POST', '/api/webhooks/stripe', $stripeWebhook, null,
        ['Stripe-Signature' => 't=' . $stamp . ',v1=deadbeef'])['status'] === 400);
check('a tampered stripe body is rejected (400)',
    req('POST', '/api/webhooks/stripe', str_replace('"paid"', '"unpaid"', $stripeWebhook), null,
        ['Stripe-Signature' => $sig])['status'] === 400);

/* --------------------------------------------------------------- Pesapal */
section('Pesapal (cards + mobile money, East Africa)');
$co = req('POST', '/api/billing/checkout', ['plan_id' => $kesPlan['id'], 'gateway' => 'pesapal'], $token);
$pesId = $co['json']['payment']['id'] ?? null;
check('pesapal checkout created a KES invoice',
    is_string($pesId) && ($co['json']['payment']['currency'] ?? '') === 'KES', substr($co['raw'], 0, 200));
check('pesapal returned its redirect URL',
    strpos((string)($co['json']['checkout_url'] ?? ''), '/stub/pesapal') !== false,
    (string)($co['json']['checkout_url'] ?? ''));
$log = stub_req('GET', '/_requests');
$auth = stub_last($log['requests'] ?? [], '/api/Auth/RequestToken');
check('pesapal was authenticated with the consumer key/secret',
    ($auth['body']['consumer_key'] ?? '') === 'pesa_key'
    && ($auth['body']['consumer_secret'] ?? '') === 'pesa_secret', json_encode($auth['body'] ?? []));
$ipn = stub_last($log['requests'] ?? [], '/api/URLSetup/RegisterIPN');
check('pesapal IPN registration points at our webhook',
    strpos((string)($ipn['body']['url'] ?? ''), '/api/webhooks/pesapal') !== false
    && ($ipn['body']['ipn_notification_type'] ?? '') === 'GET', json_encode($ipn['body'] ?? []));
$order = stub_last($log['requests'] ?? [], '/api/Transactions/SubmitOrderRequest');
check('pesapal received the invoice amount, currency and callback',
    (float)($order['body']['amount'] ?? 0) === (float)$kesPlan['price']
    && ($order['body']['currency'] ?? '') === 'KES'
    && ($order['body']['id'] ?? '') === ($co['json']['payment']['invoice_no'] ?? '')
    && strpos((string)($order['body']['callback_url'] ?? ''), '/billing?payment=' . $pesId) !== false,
    json_encode($order['body'] ?? []));
check('pesapal received the client billing address',
    ($order['body']['billing_address']['email_address'] ?? '') !== ''
    && ($order['body']['billing_address']['phone_number'] ?? '') !== '',
    json_encode($order['body']['billing_address'] ?? []));
check('the pesapal bearer token was used',
    stripos((string)($order['headers']['authorization'] ?? ''), 'bearer stub-pesapal-token') === 0,
    (string)($order['headers']['authorization'] ?? ''));
/* Pesapal confirms through the IPN (GET) and we re-check the status */
$ipnCall = req('GET', '/api/webhooks/pesapal?OrderTrackingId=' . ($co['json']['gateway_ref'] ?? 'ot_stub_1')
    . '&OrderMerchantReference=' . rawurlencode((string)($co['json']['payment']['invoice_no'] ?? ''))
    . '&OrderNotificationType=IPNCHANGE', null, null);
check('the pesapal IPN is acknowledged with HTTP 200',
    $ipnCall['status'] === 200 && (int)($ipnCall['json']['status'] ?? 0) === 200, substr($ipnCall['raw'], 0, 200));
check('pesapal was asked to confirm the transaction status',
    stub_last(stub_req('GET', '/_requests')['requests'] ?? [], 'GetTransactionStatus') !== null);
$payRow = null;
foreach (req('POST', '/api/billing/pay/' . $pesId . '/status', [], $token)['json'] as $k => $v) {
    if ($k === 'payment') $payRow = $v;
}
check('the pesapal payment is settled and the subscription active',
    ($payRow['status'] ?? '') === 'paid'
    && (req('GET', '/api/billing', null, $token)['json']['billing']['status'] ?? '') === 'active',
    json_encode($payRow));

/* --------------------------------------------------------------- M-Pesa */
section('M-Pesa (mobile money, STK push)');
stub_req('GET', '/_reset');
$co = req('POST', '/api/billing/checkout',
    ['plan_id' => $kesPlan['id'], 'gateway' => 'mpesa', 'phone' => '0712345678'], $token);
$mpId = $co['json']['payment']['id'] ?? null;
check('mpesa checkout created the invoice', is_string($mpId), substr($co['raw'], 0, 200));
check('the STK push response is reported to the client',
    ($co['json']['pending'] ?? false) === true && (string)($co['json']['message'] ?? '') !== '',
    (string)($co['json']['message'] ?? ''));
check('no redirect URL is used for an STK push', ($co['json']['checkout_url'] ?? null) === null);
$log = stub_req('GET', '/_requests');
$stk = stub_last($log['requests'] ?? [], '/mpesa/stkpush/v1/processrequest');
$b = $stk['body'] ?? [];
check('mpesa received a whole-shilling amount in KES',
    (int)($b['Amount'] ?? 0) === (int)round($kesPlan['price']) && (int)($b['Amount'] ?? 0) === (int)$kesPlan['price'],
    json_encode($b['Amount'] ?? null));
check('the phone number was normalised to 2547…',
    ($b['PhoneNumber'] ?? '') === '254712345678' && ($b['PartyA'] ?? '') === '254712345678',
    (string)($b['PartyNumber'] ?? $b['PhoneNumber'] ?? ''));
check('mpesa received the business short code and an encrypted password',
    ($b['BusinessShortCode'] ?? '') === '174379' && strlen((string)($b['Password'] ?? '')) > 20
    && ($b['TransactionType'] ?? '') === 'CustomerPayBillOnline', json_encode($b['BusinessShortCode'] ?? null));
check('the mpesa callback URL points at our webhook',
    strpos((string)($b['CallBackURL'] ?? ''), '/api/webhooks/mpesa') !== false, (string)($b['CallBackURL'] ?? ''));
check('the invoice number travels as the account reference',
    strpos((string)($b['AccountReference'] ?? ''), 'KIVU') === 0, (string)($b['AccountReference'] ?? ''));
check('the oauth token was used for the push',
    stripos((string)($stk['headers']['authorization'] ?? ''), 'bearer stub-mpesa-token') === 0,
    (string)($stk['headers']['authorization'] ?? ''));
/* Safaricom calls back with the result of the STK push */
$mpCallback = json_encode(['Body' => ['stkCallback' => ['MerchantRequestID' => 'mreq_stub_1',
    'CheckoutRequestID' => (string)($co['json']['gateway_ref'] ?? 'ws_CO_stub_1'), 'ResultCode' => 0,
    'ResultDesc' => 'The service request is processed successfully.',
    'CallbackMetadata' => ['Item' => [['Name' => 'Amount', 'Value' => (float)$kesPlan['price']],
        ['Name' => 'MpesaReceiptNumber', 'Value' => 'QK' . $tag],
        ['Name' => 'PhoneNumber', 'Value' => 254712345678]]]]]]);
$cb = req('POST', '/api/webhooks/mpesa', $mpCallback, null);
check('the mpesa result callback settles the invoice',
    $cb['status'] === 200 && ($cb['json']['status'] ?? '') === 'paid', substr($cb['raw'], 0, 220));
check('the mpesa receipt was stored on the payment',
    (string)apath(req('POST', '/api/billing/pay/' . $mpId . '/status', [], $token)['json'], 'payment.receipt')
        === 'QK' . $tag);
check('the mpesa receipt did not replace the gateway reference',
    (string)apath(req('POST', '/api/billing/pay/' . $mpId . '/status', [], $token)['json'], 'payment.gateway_ref')
        === (string)($co['json']['gateway_ref'] ?? ''));
check('a replayed mpesa callback is idempotent',
    (req('POST', '/api/webhooks/mpesa', $mpCallback, null)['json']['duplicate'] ?? false) === true);
$mpFail = req('POST', '/api/billing/checkout',
    ['plan_id' => $kesPlan['id'], 'gateway' => 'mpesa', 'phone' => '0712345678'], $token);
$failCall = json_encode(['Body' => ['stkCallback' => ['CheckoutRequestID' => $mpFail['json']['gateway_ref'],
    'ResultCode' => 1032, 'ResultDesc' => 'Request cancelled by the user']]]);
check('a cancelled STK push marks the invoice failed',
    (req('POST', '/api/webhooks/mpesa', $failCall, null)['json']['status'] ?? '') === 'failed',
    substr(req('POST', '/api/webhooks/mpesa', $failCall, null)['raw'], 0, 160));
/* Safaricom would report the cancellation on a status query as well */
stub_post($stub . '/_outcome', ['id' => (string)$mpFail['json']['gateway_ref'], 'result_code' => '1032']);
check('a failed mpesa invoice stays failed when the platform re-checks it',
    (string)apath(req('POST', '/api/billing/pay/' . $mpFail['json']['payment']['id'] . '/status', [], $token)['json'],
        'payment.status') === 'failed',
    (string)apath(req('POST', '/api/billing/pay/' . $mpFail['json']['payment']['id'] . '/status', [], $token)['json'],
        'payment.status'));
check('the account phone number is used when the checkout sends none', (function () use ($token, $kesPlan, $stub) {
    $r = req('POST', '/api/billing/checkout', ['plan_id' => $kesPlan['id'], 'gateway' => 'mpesa'], $token);
    if (($r['status'] ?? 0) !== 200) return false;
    $log = stub_req('GET', '/_requests');
    $last = stub_last($log['requests'] ?? [], '/mpesa/stkpush/v1/processrequest');
    return (string)($last['body']['PhoneNumber'] ?? '') === '254712345678';
})());

/* ----------------------------------------------------------- Flutterwave */
section('Flutterwave (cards, mobile money, bank)');
stub_req('GET', '/_reset');
$co = req('POST', '/api/billing/checkout', ['plan_id' => $usdPlan['id'], 'gateway' => 'flutterwave'], $token);
$flwId = $co['json']['payment']['id'] ?? null;
check('flutterwave checkout created the invoice', is_string($flwId), substr($co['raw'], 0, 200));
check('flutterwave returned the hosted payment link',
    strpos((string)($co['json']['checkout_url'] ?? ''), '/stub/flutterwave') !== false,
    (string)($co['json']['checkout_url'] ?? ''));
$flw = stub_last(stub_req('GET', '/_requests')['requests'] ?? [], '/v3/payments');
$b = $flw['body'] ?? [];
check('flutterwave received amount, currency and our transaction reference',
    (float)($b['amount'] ?? 0) === (float)$usdPlan['price'] && ($b['currency'] ?? '') === 'USD'
    && strpos((string)($b['tx_ref'] ?? ''), 'KIVU-') === 0, json_encode([$b['amount'] ?? null, $b['tx_ref'] ?? null]));
check('flutterwave was offered cards, mobile money and bank transfer',
    strpos((string)($b['payment_options'] ?? ''), 'card') !== false
    && strpos((string)($b['payment_options'] ?? ''), 'mobilemoney') !== false
    && strpos((string)($b['payment_options'] ?? ''), 'banktransfer') !== false, (string)($b['payment_options'] ?? ''));
check('flutterwave got the client e-mail and the payment metadata',
    ($b['customer']['email'] ?? '') !== '' && ($b['meta']['payment_id'] ?? '') === $flwId,
    json_encode($b['meta'] ?? []));
check('the flutterwave secret key was used',
    stripos((string)($flw['headers']['authorization'] ?? ''), 'bearer flw_secret') === 0);
$flwWebhook = json_encode(['event' => 'charge.completed', 'data' => ['id' => (int)('555' . $tag),
    'tx_ref' => $b['tx_ref'] ?? '', 'status' => 'successful', 'amount' => (float)$usdPlan['price'],
    'currency' => 'USD', 'meta' => ['payment_id' => $flwId],
    'card' => ['token' => 'flwc_' . $tag, 'type' => 'Visa', 'last_4digits' => '4242']]]);
check('a flutterwave webhook with the wrong hash is rejected',
    req('POST', '/api/webhooks/flutterwave', $flwWebhook, null, ['verif-hash' => 'wrong-hash'])['status'] === 400);
$goodHash = req('POST', '/api/webhooks/flutterwave', $flwWebhook, null, ['verif-hash' => 'flw_hash_' . $tag]);
check('a flutterwave webhook with the right hash settles the invoice',
    $goodHash['status'] === 200 && ($goodHash['json']['status'] ?? '') === 'paid', substr($goodHash['raw'], 0, 200));
check('flutterwave transactions can also be verified by reference',
    (req('POST', '/api/billing/pay/' . $flwId . '/status', null, $token)['json']['status'] ?? '') === 'paid');

/* ---------------------------------------------------------------- PayPal */
section('PayPal');
$co = req('POST', '/api/billing/checkout', ['plan_id' => $usdPlan['id'], 'gateway' => 'paypal'], $token);
$ppId = $co['json']['payment']['id'] ?? null;
check('paypal checkout created the invoice', is_string($ppId), substr($co['raw'], 0, 200));
check('paypal returned the approval link',
    strpos((string)($co['json']['checkout_url'] ?? ''), '/stub/paypal') !== false,
    (string)($co['json']['checkout_url'] ?? ''));
$log = stub_req('GET', '/_requests');
$order = stub_exact($log['requests'] ?? [], 'POST', '/v2/checkout/orders');
$b = $order['body'] ?? [];
check('paypal received a CAPTURE order with the right amount',
    ($b['intent'] ?? '') === 'CAPTURE'
    && ($b['purchase_units'][0]['amount']['value'] ?? '') === number_format((float)$usdPlan['price'], 2, '.', '')
    && ($b['purchase_units'][0]['amount']['currency_code'] ?? '') === 'USD',
    json_encode($b['purchase_units'][0]['amount'] ?? []));
check('paypal carries our invoice id as custom_id',
    ($b['purchase_units'][0]['custom_id'] ?? '') === $ppId, json_encode($b['purchase_units'][0] ?? []));
check('paypal got return and cancel URLs',
    strpos((string)($b['application_context']['return_url'] ?? ''), '/billing?payment=' . $ppId) !== false
    && strpos((string)($b['application_context']['cancel_url'] ?? ''), '/billing?payment=' . $ppId) !== false);
check('paypal was authenticated with a bearer token for the order', 
    stripos((string)($order['headers']['authorization'] ?? ''), 'bearer stub-paypal-token') === 0,
    (string)($order['headers']['authorization'] ?? ''));
check('the paypal oauth call used the client id/secret as basic auth',
    (function () use ($stub) {
        $t = stub_exact(stub_req('GET', '/_requests')['requests'] ?? [], 'POST', '/v1/oauth2/token');
        return ($t['headers']['authorization'] ?? '') === 'Basic ' . base64_encode('paypal_client:paypal_secret')
            || ($t['headers']['authorization'] ?? '') === 'basic ' . base64_encode('paypal_client:paypal_secret');
    })());
check('the paypal order is captured when the client returns',
    (req('POST', '/api/billing/pay/' . $ppId . '/status', null, $token)['json']['settled'] ?? false) === true);
check('paypal captured the order with an idempotency key',
    (stub_last(stub_req('GET', '/_requests')['requests'] ?? [], '/capture')['headers']['paypal-request-id'] ?? '') !== '');
$ppWebhook = json_encode(['id' => 'WH-PP-' . $tag, 'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
    'resource' => ['id' => 'CAP-STUB-2', 'custom_id' => $ppId,
        'amount' => ['value' => '19.00', 'currency_code' => 'USD']]]);
check('a paypal capture webhook for a known invoice is accepted',
    (req('POST', '/api/webhooks/paypal', $ppWebhook, null)['json']['ok'] ?? false) === true,
    substr(req('POST', '/api/webhooks/paypal', $ppWebhook, null)['raw'], 0, 160));
check('a paypal webhook for an unknown order is rejected',
    req('POST', '/api/webhooks/paypal', json_encode(['id' => 'WH-unknown',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'x', 'custom_id' => 'pay_missing']]),
        null)['status'] === 400);

/* ------------------------------------------- super-admin on/off switch ---- */
section('Super admin switches an integration off and on');
$codes = function (array $plans): array {
    $out = [];
    foreach (($plans ?? []) as $p2) foreach (($p2['gateways'] ?? []) as $g2) $out[] = (string)($g2['code'] ?? '');
    return $out;
};
$before = $codes(req('GET', '/api/billing', null, $token)['json']['plans'] ?? []);
check('pesapal is offered to clients while switched on', in_array('pesapal', $before, true),
    implode(',', $before));
check('a live integration can be switched off with its keys still present',
    req('PUT', '/api/billing/settings', ['gateways' => ['pesapal' => ['enabled' => false]]],
        $super)['status'] === 200);
$afterOff = $codes(req('GET', '/api/billing', null, $token)['json']['plans'] ?? []);
check('a switched-off integration disappears from the client offers',
    !in_array('pesapal', $afterOff, true), implode(',', $afterOff));
$offCo = req('POST', '/api/billing/checkout', ['plan_code' => 'starter', 'gateway' => 'pesapal'], $token);
check('checking out through it is refused with a clear message',
    ($offCo['status'] ?? 0) === 400
    && stripos((string)($offCo['json']['error'] ?? ''), 'switched off') !== false,
    'status ' . ($offCo['status'] ?? '?') . ' ' . substr((string)($offCo['raw'] ?? ''), 0, 160));
check('switching it back on is accepted',
    req('PUT', '/api/billing/settings', ['gateways' => ['pesapal' => ['enabled' => true]]],
        $super)['status'] === 200);
$afterOn = $codes(req('GET', '/api/billing', null, $token)['json']['plans'] ?? []);
check('the integration is offered again after switching it back on',
    in_array('pesapal', $afterOn, true), implode(',', $afterOn));

/* ------------------------------- automatic renewal charges the method --- */
section('Automatic billing renews the subscription');
$pmList = req('GET', '/api/billing', null, $token)['json']['methods'] ?? [];
check('a saved payment method survived the gateway runs', count($pmList) >= 1, json_encode($pmList));
$pmGw = count($pmList) ? (string)$pmList[0]['gateway'] : '';
check('the default method came from an auto-chargeable gateway',
    in_array($pmGw, ['flutterwave', 'mpesa', 'stripe'], true), $pmGw);
/* invoices left pending by the earlier STK phone checks block the renewal on
   purpose (a push in flight may still settle) — settle/cancel them first */
$stalePending = req('GET', '/api/billing/payments?account_id=' . $accountId . '&status=pending',
    null, $super)['json']['payments'] ?? [];
foreach ($stalePending as $pend) {
    req('POST', '/api/billing/payments/' . $pend['id'], ['action' => 'cancel'], $super);
}
check('leftover pending invoices are cancelled before the renewal test',
    count(req('GET', '/api/billing/payments?account_id=' . $accountId . '&status=pending',
        null, $super)['json']['payments'] ?? []) === 0, (string)count($stalePending));
check('the paid period can be lapsed for the renewal test', (function () use ($accountId, $super, $token) {
    /* the suite's settlements stack (each extends the period), so compute the
       offset from the current countdown instead of guessing */
    $daysLeft = (int)(req('GET', '/api/billing', null, $token)['json']['billing']['days_left'] ?? 30);
    return req('POST', '/api/accounts/' . $accountId . '/billing',
        ['action' => 'extend_period', 'days' => -($daysLeft + 3)], $super)['status'] === 200;
})());
$ren = req('POST', '/api/tasks/run', [], $super)['json']['billing'] ?? [];
check('the sweep charged the saved method automatically', (int)($ren['renewed'] ?? 0) >= 1,
    json_encode($ren));
$chargeHit = stub_last(stub_req('GET', '/_requests')['requests'] ?? [],
    $pmGw === 'flutterwave' ? '/v3/charges' : ($pmGw === 'stripe' ? '/v1/charges'
        : '/mpesa/stkpush/v1/processrequest'));
check('the gateway received the renewal charge request', $chargeHit !== null,
    'default gateway ' . $pmGw);
if ($pmGw === 'flutterwave') {
    check('the tokenised charge carried the saved card token',
        (string)($chargeHit['body']['token'] ?? '') === 'flwc_' . $tag,
        json_encode($chargeHit['body'] ?? []));
}
$renewRow = null;
foreach (req('GET', '/api/billing', null, $token)['json']['invoices'] ?? [] as $iv) {
    if ((string)($iv['status'] ?? '') === 'paid' && (string)($iv['id'] ?? '') !== (string)$ppId) {
        $renewRow = $iv; break;
    }
}
check('the automatic renewal produced a paid invoice',
    $renewRow !== null && (string)($renewRow['gateway'] ?? '') === $pmGw, json_encode($renewRow));

/* -------------------------------------------------------- restore + report */
section('Cleanup');
check('the gateway credentials are cleared again',
    req('PUT', '/api/billing/settings', ['reminders_enabled' => true, 'gateways' => [
        'stripe' => ['secret_key' => '', 'publishable_key' => '', 'webhook_secret' => ''],
        'pesapal' => ['consumer_key' => '', 'consumer_secret' => '', 'ipn_id' => ''],
        'mpesa' => ['consumer_key' => '', 'consumer_secret' => '', 'shortcode' => '', 'passkey' => ''],
        'flutterwave' => ['public_key' => '', 'secret_key' => '', 'encryption_key' => '', 'webhook_hash' => ''],
        'paypal' => ['client_id' => '', 'secret' => ''],
        'bank' => ['instructions' => '']]], $super)['status'] === 200);
check('the test client account is removed',
    req('DELETE', '/api/accounts/' . $accountId . '?force=1', null, $super)['status'] === 200);
check('no real gateway reports itself as configured after the cleanup',
    (function () use ($super) {
        $cfg = req('GET', '/api/billing/settings', null, $super);
        foreach (['stripe', 'pesapal', 'mpesa', 'flutterwave', 'paypal'] as $code) {
            if (!empty($cfg['json']['gateways'][$code]['configured'])) return false;
        }
        return true;
    })());

echo "\n== Result ==\n  passed: $pass   failed: $fail\n";
if ($fail) foreach ($failures as $f) echo "   - $f\n";
if ($stubProc) {
    $st = proc_get_status($stubProc);
    $pid = (int)($st['pid'] ?? 0);
    if (PHP_OS_FAMILY === 'Windows' && $pid > 0) @exec('taskkill /F /T /PID ' . $pid . ' 2>&1');
    @proc_terminate($stubProc);
    echo "  gateway stub stopped\n";
}
exit($fail ? 1 : 0);
