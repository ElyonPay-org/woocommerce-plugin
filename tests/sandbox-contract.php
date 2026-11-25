<?php
/**
 * Contract check against the real ElyonPay sandbox, with the merchant's sandbox API key:
 * the calls the plugin makes, and the answers it relies on. Creates one unpaid payment link.
 *
 *   ELYONPAY_API_KEY=pk_live_egt_... php tests/sandbox-contract.php
 *
 * Optional: WEBHOOK_URL=https://... to also have the link notify that address.
 * The SDK comes from vendor/ or from ELYONPAY_SDK_SRC (a checkout's src/).
 */

$sdk = getenv('ELYONPAY_SDK_SRC');
if ($sdk) {
    spl_autoload_register(static function ($class) use ($sdk) {
        if (strpos($class, 'ElyonPay\\') === 0) {
            $file = rtrim($sdk, '/') . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
} else {
    require dirname(__DIR__) . '/vendor/autoload.php';
}

use ElyonPay\Client;
use ElyonPay\Environment;
use ElyonPay\Exception\ApiException;
use ElyonPay\Http\CurlHttpClient;
use ElyonPay\TransactionStatus;

$key = (string) getenv('ELYONPAY_API_KEY');
if ($key === '') {
    fwrite(STDERR, "Set ELYONPAY_API_KEY to the sandbox account's API key.\n");
    exit(2);
}

$failed = 0;
function step($ok, $label)
{
    global $failed;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

$client      = new Client(array('api_key' => $key, 'environment' => Environment::SANDBOX));
$external_id = 'wc-contract-' . bin2hex(random_bytes(4));
$payload     = array(
    'amount'      => 100,
    'user_lang'   => 'fr',
    'msisdn'      => '+237600000001',
    'success'     => 'https://shop.test/?wc-api=elyonpay_success&order_id=1&order_key=k',
    'error'       => 'https://shop.test/?wc-api=elyonpay_error&order_id=1&order_key=k',
    'external_id' => $external_id,
    'customer'    => array('name' => 'Contract Check', 'locale' => 'fr'),
);
if (getenv('WEBHOOK_URL')) {
    $payload['webhook'] = getenv('WEBHOOK_URL');
}

echo "1. Payment link with the API key\n";
$link = $client->createPaymentLink($payload);
preg_match('#/t/([A-Za-z0-9]+)#', (string) parse_url($link['url'], PHP_URL_PATH), $m);
$uuid = isset($m[1]) ? $m[1] : '';
step($uuid !== '', 'link ' . $link['url']);

echo "2. Transaction read back with the API key\n";
$tx = $client->getTransaction($uuid);
step(isset($tx['uuid']) && $tx['uuid'] === $uuid, 'uuid ' . (isset($tx['uuid']) ? $tx['uuid'] : '-'));
step(isset($tx['state']) && TransactionStatus::isPending($tx['state']), 'state ' . (isset($tx['state']) ? $tx['state'] : '-') . ' (not paid yet)');
// 100 in XAF/XOF, 10000 (cents) for an account in a decimal currency such as EUR.
step(isset($tx['amount']) && in_array((int) $tx['amount'], array(100, 10000), true), 'amount ' . (isset($tx['amount']) ? $tx['amount'] : '-'));
step(isset($tx['externalId']) && $tx['externalId'] === $external_id, 'externalId ' . (isset($tx['externalId']) ? $tx['externalId'] : '-'));

echo "3. The same external_id twice is refused\n";
try {
    $client->createPaymentLink($payload);
    step(false, 'expected a 409');
} catch (ApiException $e) {
    step($e->getHttpStatus() === 409, $e->getHttpStatus() . ' ' . $e->getMessage());
}

echo "4. The key does not open account endpoints\n";
$res = (new CurlHttpClient(15))->request('GET', Environment::baseUrl(Environment::SANDBOX) . '/merchant/api-key', array(
    'Authorization' => 'Bearer ' . $key,
    'Accept'        => 'application/json',
));
step($res['status'] === 403, 'GET /merchant/api-key -> ' . $res['status']);

echo $failed === 0 ? "\nContract OK\n" : "\n$failed step(s) failed\n";
exit($failed === 0 ? 0 : 1);
