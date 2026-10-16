<?php
// A stand-in for Paystack used ONLY for local use and automated tests. It implements the few endpoints the app calls, with the
// documented response shapes, so the whole payment flow runs for real without money or internet. It is NOT Paystack.
//   php -S 127.0.0.1:9099 fake-paystack.php
// State lives in a small JSON file in the system temp folder so it survives between requests.

const KEY = 'sk_test_e2e';
$storeFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gospel-fake-paystack.json';

function store(string $file, callable $fn)
{
    $fh = fopen($file, 'c+');
    flock($fh, LOCK_EX);
    $data = json_decode(stream_get_contents($fh) ?: '{}', true) ?: ['next' => 910000000, 'txns' => []];
    $result = $fn($data);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    flock($fh, LOCK_UN);
    fclose($fh);

    return $result;
}
function out(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:9099';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

if (! str_starts_with($path, '/pay') && $auth !== 'Bearer '.KEY) {
    return out(401, ['status' => false, 'message' => 'Invalid key']);
}

if ($method === 'POST' && $path === '/transaction/initialize') {
    $b = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $ref = (string) ($b['reference'] ?? '');
    store($storeFile, function (&$d) use ($b, $ref) {
        $b['id'] = $d['next']++;
        $b['state'] = 'initialized';
        $d['txns'][$ref] = $b;
    });

    return out(200, ['status' => true, 'message' => 'Authorization URL created', 'data' => ['authorization_url' => "http://$host/pay/$ref", 'access_code' => 'ac_local', 'reference' => $ref]]);
}

if ($method === 'GET' && str_starts_with($path, '/transaction/verify/')) {
    $ref = rawurldecode(substr($path, strlen('/transaction/verify/')));
    $t = store($storeFile, fn (&$d) => $d['txns'][$ref] ?? null);
    if (! $t) {
        return out(404, ['status' => false, 'message' => 'Transaction reference not found']);
    }
    $status = $t['state'] === 'paid' ? 'success' : ($t['state'] === 'failed' ? 'failed' : 'abandoned');

    return out(200, ['status' => true, 'message' => 'Verification successful', 'data' => ['id' => $t['id'], 'status' => $status, 'reference' => $t['reference'] ?? $ref, 'amount' => $t['amount'] ?? 0,
        'currency' => $t['currency'] ?? 'NGN', 'channel' => 'card', 'domain' => 'test', 'gateway_response' => $status === 'success' ? 'Successful' : 'Declined', 'paid_at' => gmdate('c'), 'metadata' => $t['metadata'] ?? null]]);
}

if (str_starts_with($path, '/pay/')) {
    $parts = explode('/', trim($path, '/'));          // pay, <ref>, [complete|decline]
    $ref = rawurldecode($parts[1] ?? '');
    $action = $parts[2] ?? '';
    $t = store($storeFile, function (&$d) use ($ref, $action) {
        if (! isset($d['txns'][$ref])) {
            return null;
        }
        if ($action === 'complete' || $action === 'decline') {
            $d['txns'][$ref]['state'] = $action === 'complete' ? 'paid' : 'failed';
        }

        return $d['txns'][$ref];
    });
    if (! $t) {
        http_response_code(404);
        echo 'Unknown payment reference';

        return;
    }
    if ($action === 'complete' || $action === 'decline') {
        header('Location: '.($t['callback_url'] ?? '/').'?reference='.rawurlencode($ref).'&trxref='.rawurlencode($ref));
        http_response_code(302);

        return;
    }
    $amount = number_format(($t['amount'] ?? 0) / 100, 2, '.', '');
    $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Test payment page</title>'
        .'<body style="font-family:Arial,sans-serif;background:#f4f7f3;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center">'
        .'<main style="background:#fff;border:1px solid #cdd8cb;border-radius:8px;padding:32px;max-width:420px;width:90%;box-shadow:0 10px 30px rgba(0,0,0,.08)">'
        .'<p style="margin:0 0 8px;color:#b45309;font-weight:700;font-size:13px;letter-spacing:.05em">TEST MODE: PAYMENT SIMULATOR, NO REAL MONEY MOVES</p>'
        .'<h1 style="margin:0 0 16px;font-size:22px;color:#164b1c">Secure checkout</h1>'
        .'<p>Amount: <strong>NGN '.$e($amount).'</strong></p>'
        .'<p style="display:flex;gap:12px;margin-top:24px"><a id="pay" href="/pay/'.$e(rawurlencode($ref)).'/complete" style="flex:1;text-align:center;background:#207027;color:#fff;padding:12px;border-radius:4px;text-decoration:none;font-weight:700">Pay now</a>'
        .'<a id="decline" href="/pay/'.$e(rawurlencode($ref)).'/decline" style="flex:1;text-align:center;border:1px solid #9aa89a;color:#164b1c;padding:12px;border-radius:4px;text-decoration:none;font-weight:700">Decline</a></p></main></body></html>';

    return;
}

out(404, ['status' => false, 'message' => 'not found']);
