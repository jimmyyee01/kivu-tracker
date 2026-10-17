<?php
/* =====================================================================
   KIVU Tracker — tools/gateway-stub.php
   A tiny stand-in for Stripe, Pesapal, Safaricom M-Pesa, Flutterwave and
   PayPal, used by tools/payments-test.php so the payment drivers can be
   exercised end to end without real credentials or real money.

       php -S 127.0.0.1:8097 tools/gateway-stub.php

   Every request is journalled to data/gateway-stub.log (JSON lines) and
   can also be read back with GET /_requests. GET /_reset empties it.
   ===================================================================== */
declare(strict_types=1);

$logFile = dirname(__DIR__) . '/data/gateway-stub.log';
$stateFile = dirname(__DIR__) . '/data/gateway-stub-state.json';
$uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$raw = (string)file_get_contents('php://input');
$json = json_decode($raw, true);
$query = $_GET;
$base = 'http://' . (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8097');

header('Content-Type: application/json; charset=utf-8');

if ($uri === '/_requests') {
    $lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    echo json_encode(['ok' => true, 'count' => count($lines),
        'requests' => array_map(fn($l) => json_decode($l, true), $lines)]);
    return true;
}
if ($uri === '/_reset') {
    @file_put_contents($logFile, '');
    echo json_encode(['ok' => true, 'reset' => true]);
    return true;
}

if ($uri === '/_outcome' && $method === 'POST') {
    /* the test declares what Safaricom would report for an STK push */
    $state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
    $state[$json['id'] ?? ''] = (string)($json['result_code'] ?? '0');
    @file_put_contents($stateFile, json_encode($state));
    echo json_encode(['ok' => true, 'outcomes' => $state]);
    return true;
}

/* journal the call before answering (form bodies are parsed too) */
$parsed = $json;
if ($parsed === null) {
    $ctype = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($ctype, 'x-www-form-urlencoded') !== false) {
        parse_str($raw, $form);
        $parsed = $form;
    }
}
$entry = ['ts' => date('c'), 'method' => $method, 'uri' => $uri, 'query' => $query,
    'headers' => stub_headers(), 'body' => $parsed ?? $raw];
@file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND);

function stub_headers(): array {
    $out = [];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            $k = strtolower((string)$k);
            if (in_array($k, ['authorization', 'content-type', 'paypal-request-id', 'verif-hash'], true)) {
                $out[$k] = (string)$v;
            }
        }
    }
    return $out;
}
function ok(array $payload): bool { echo json_encode($payload); return true; }
/* a different id per call, so callbacks can be matched without collisions */
$uq = substr(str_replace('.', '', (string)microtime(true)), -6) . random_int(10, 99);

/* ------------------------------------------------------------------ Stripe */
if (preg_match('#^/v1/checkout/sessions/([A-Za-z0-9_]+)$#', $uri, $m)) {
    return ok(['id' => $m[1], 'object' => 'checkout.session', 'payment_status' => 'paid',
        'payment_intent' => 'pi_stub_' . substr($m[1], -6), 'currency' => 'usd',
        'client_reference_id' => $query['client_reference_id'] ?? null]);
}
if ($uri === '/v1/checkout/sessions' && $method === 'POST') {
    parse_str($raw, $form);
    $amount = (int)($form['line_items'][0]['price_data']['unit_amount'] ?? 0);
    return ok(['id' => 'cs_stub_' . $uq, 'object' => 'checkout.session',
        'url' => $base . '/stub/stripe?amount=' . $amount, 'payment_status' => 'unpaid',
        'amount_total' => $amount, 'metadata' => ['payment_id' => $form['metadata']['payment_id'] ?? null]]);
}

/* ----------------------------------------------------------------- Pesapal */
if ($uri === '/api/Auth/RequestToken') {
    return ok(['token' => 'stub-pesapal-token', 'expiryDate' => date('c', time() + 300)]);
}
if ($uri === '/api/URLSetup/RegisterIPN') {
    return ok(['url' => $json['url'] ?? '', 'created_date' => date('c'), 'ipn_id' => 'ipn_stub_1',
        'ipn_notification_type_description' => 'GET', 'ipn_status' => 1]);
}
if ($uri === '/api/Transactions/SubmitOrderRequest') {
    return ok(['order_tracking_id' => 'ot_stub_' . $uq, 'merchant_reference' => $json['id'] ?? '',
        'redirect_url' => $base . '/stub/pesapal', 'error' => null, 'status' => '200']);
}
if ($uri === '/api/Transactions/GetTransactionStatus') {
    return ok(['payment_method' => 'MPESA', 'amount' => 4900, 'created_date' => date('c'),
        'confirmation_code' => 'PESAPAL-OK-1', 'payment_status_description' => 'Completed',
        'message' => 'Payment completed', 'status_code' => 1, 'currency' => 'KES']);
}

/* ------------------------------------------------------------------- M-Pesa */
if (strpos($uri, '/oauth/v1/generate') === 0) {
    return ok(['access_token' => 'stub-mpesa-token', 'expires_in' => '3599']);
}
if ($uri === '/mpesa/stkpush/v1/processrequest') {
    return ok(['MerchantRequestID' => 'mreq_stub_1', 'CheckoutRequestID' => 'ws_CO_stub_' . $uq,
        'ResponseCode' => '0', 'ResponseDescription' => 'Success. Request accepted for processing',
        'CustomerMessage' => 'Success. Request accepted for processing']);
}
if ($uri === '/mpesa/stkpushquery/v1/query') {
    /* the outcome declared through /_outcome (defaults to success) */
    $state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
    $id = (string)($json['CheckoutRequestID'] ?? '');
    $code = (string)($state[$id] ?? '0');
    return ok(['ResponseCode' => '0', 'MerchantRequestID' => 'mreq_stub_1', 'CheckoutRequestID' => $id,
        'ResultCode' => $code,
        'ResultDesc' => $code === '0' ? 'The service request is processed successfully.'
            : 'Request cancelled by the user']);
}

/* -------------------------------------------------------------- MTN MoMo */
if ($uri === '/collection/token/') {
    return ok(['access_token' => 'stub-momo-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
}
if ($uri === '/collection/v1_0/requesttopay' && $method === 'POST') {
    return ok(['status' => 'SUCCESSFUL']);               // MTN answers 202; the driver only checks ok
}
if (preg_match('#^/collection/v1_0/requesttopay/([A-Za-z0-9_\-]+)$#', $uri, $m)) {
    return ok(['status' => 'SUCCESSFUL', 'externalId' => 'INV-STUB',
        'financialTransactionId' => 'ft_momo_' . substr($m[1], -8)]);
}

/* --------------------------------------------------------- Airtel Money -- */
if ($uri === '/auth/oauth2/token') {
    return ok(['access_token' => 'stub-airtel-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
}
if ($uri === '/standard/v1/payments' && $method === 'POST') {
    return ok(['status' => ['code' => '2000', 'message' => 'Success'],
        'data' => ['transactionId' => 'airteltx_stub_' . $uq, 'status' => 'Successful']]);
}

/* ------------------------------------------------------------ Flutterwave */
if ($uri === '/v3/payments' && $method === 'POST') {
    return ok(['status' => 'success', 'message' => 'Hosted Link',
        'data' => ['link' => $base . '/stub/flutterwave']]);
}
if (strpos($uri, '/v3/transactions/verify_by_reference') === 0) {
    return ok(['status' => 'success', 'message' => 'Verified', 'data' => ['id' => 987654,
        'tx_ref' => $query['tx_ref'] ?? '', 'status' => 'successful', 'amount' => 19, 'currency' => 'USD']]);
}
if (preg_match('#^/v3/transactions/[0-9]+/verify$#', $uri)) {
    return ok(['status' => 'success', 'data' => ['id' => 987654, 'status' => 'successful',
        'amount' => 19, 'currency' => 'USD']]);
}

/* ------------------------------------ renewal charges on saved methods --- */
if ($uri === '/v1/charges' && $method === 'POST') {
    /* Stripe: off-session charge of a saved payment method */
    parse_str($raw, $form);
    return ok(['id' => 'ch_stub_' . $uq, 'object' => 'charge', 'status' => 'succeeded',
        'amount' => (int)($form['amount'] ?? 0), 'currency' => (string)($form['currency'] ?? 'usd'),
        'customer' => (string)($form['customer'] ?? ''), 'paid' => true]);
}
if ($uri === '/v3/charges' && $method === 'POST') {
    /* Flutterwave: tokenised charge of a saved card */
    return ok(['status' => 'success', 'message' => 'Charge successful',
        'data' => ['id' => 'flwchg_stub_' . $uq, 'tx_ref' => (string)($json['tx_ref'] ?? ''),
            'status' => 'successful', 'amount' => (float)($json['amount'] ?? 0),
            'currency' => (string)($json['currency'] ?? 'USD')]]);
}

/* ------------------------------------------------------------------ PayPal */
if ($uri === '/v1/oauth2/token') {
    return ok(['access_token' => 'stub-paypal-token', 'token_type' => 'Bearer', 'expires_in' => 3000]);
}
if ($uri === '/v2/checkout/orders' && $method === 'POST') {
    return ok(['id' => 'ORDER-STUB-' . $uq, 'status' => 'CREATED',
        'links' => [['rel' => 'self', 'href' => $base . '/v2/checkout/orders/ORDER-STUB-1'],
            ['rel' => 'approve', 'href' => $base . '/stub/paypal']]]);
}
if (preg_match('#^/v2/checkout/orders/([A-Za-z0-9\-]+)/capture$#', $uri, $m)) {
    return ok(['id' => $m[1], 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [
        ['id' => 'CAP-STUB-1', 'status' => 'COMPLETED',
            'amount' => ['value' => '19.00', 'currency_code' => 'USD']]]]]]]);
}

/* The "hosted pages" the drivers redirect to. */
if (strpos($uri, '/stub/') === 0) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:24px">'
        . '<h2>Gateway stub</h2><p>Simulated ' . htmlspecialchars(substr($uri, 6))
        . ' page served by tools/gateway-stub.php.</p></body></html>';
    return true;
}

return fail(404, 'stub: no route for ' . $method . ' ' . $uri);
