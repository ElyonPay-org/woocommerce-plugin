<?php
/**
 * Gateway tests against WordPress/WooCommerce stubs and a fake ElyonPay API.
 *
 *   php tests/run.php
 *
 * The SDK comes from vendor/ (composer install) or from ELYONPAY_SDK_SRC (a checkout's src/).
 */

require __DIR__ . '/stubs.php';

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
if (!method_exists('ElyonPay\\Client', 'usesApiKey')) {
    fwrite(STDERR, "ElyonPay PHP SDK >= 1.1 required (composer install, or ELYONPAY_SDK_SRC=/path/to/php-sdk/src).\n");
    exit(2);
}

require dirname(__DIR__) . '/includes/class-elyonpay-wc-gateway.php';

/** Fake ElyonPay API: records requests, answers from a queue. */
final class Fake_Api implements ElyonPay\Http\HttpClientInterface
{
    public $requests = array();
    private $responses;

    public function __construct(array $responses) { $this->responses = $responses; }

    public function request(string $method, string $url, array $headers = array(), ?string $body = null): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if (!$this->responses) {
            throw new LogicException("Unexpected request: $method $url");
        }
        $next = array_shift($this->responses);
        if ($next instanceof Exception) {
            throw $next;
        }
        return $next;
    }
}

const KEY = 'pk_live_egt_00112233445566778899aabbccddeeff';

function ok($status, $body) { return array('status' => $status, 'body' => is_string($body) ? $body : json_encode($body)); }
function link_response($uuid) { return ok(201, array('url' => 'https://app.elyonpay.net/t/' . $uuid . '?success=x&error=y')); }
function tx($uuid, $state, $amount) { return ok(201, array('uuid' => $uuid, 'state' => $state, 'amount' => $amount)); }
function notification($uuid, $event = 'transaction.pending')
{
    return json_encode(array('event' => $event, 'data' => array('transaction' => $uuid, 'state' => 'ANY', 'amount' => 1)));
}
function query_for($order) { return array('order_id' => (string) $order->get_id(), 'order_key' => $order->get_order_key()); }

/** A gateway configured like a shop in test mode, with the given settings and fake API. */
function gateway(array $settings, array $responses)
{
    Stubs::$options['woocommerce_elyonpay_settings'] = array_merge(array('enabled' => 'yes', 'testmode' => 'yes'), $settings);
    $g              = new ElyonPay_WC_Gateway();
    $g->http_client = new Fake_Api($responses);
    return $g;
}

/** An order that went through process_payment once, holding the given uuid. */
function paid_attempt_order($id, $total, array $uuids)
{
    $order = new WC_Order($id, $total);
    $order->meta[ElyonPay_WC_Gateway::META_ALL]    = $uuids;
    $order->meta[ElyonPay_WC_Gateway::META_LATEST] = end($uuids);
    return $order;
}

$failures = 0;
$count    = 0;
function check($cond, $label)
{
    global $failures, $count;
    $count++;
    if (!$cond) {
        $failures++;
        echo "  FAIL  $label\n";
    }
}
function test($name, callable $fn)
{
    Stubs::reset();
    echo "- $name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        check(false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

// ---------------------------------------------------------------------------------------------

test('process_payment uses the API key and sends webhook, external_id, customer', function () {
    $g     = gateway(array('test_api_key' => KEY), array(link_response('CM01aaa')));
    $order = new WC_Order(42, '15000');
    $res   = $g->process_payment(42);

    check($res['result'] === 'success' && strpos($res['redirect'], '/t/CM01aaa') !== false, 'redirects to the link');
    $req = $g->http_client->requests[0];
    check($req['url'] === 'https://api.elyonpay.net/api/request-to-pay/payment/link', 'sandbox link endpoint');
    check($req['headers']['Authorization'] === 'Bearer ' . KEY, 'Bearer API key');
    check(count($g->http_client->requests) === 1, 'no /login call');
    $body = json_decode($req['body'], true);
    check($body['msisdn'] === '+237690000001', 'msisdn normalised');
    check($body['webhook'] === 'https://shop.test/?wc-api=elyonpay_webhook&order_id=42&order_key=wc_order_key42', 'webhook URL carries the order');
    check((bool) preg_match('/^wc-42-[a-z0-9]{8}$/', $body['external_id']), 'external_id per attempt: ' . $body['external_id']);
    check($body['customer'] === array('name' => 'Jean Mballa', 'email' => 'jean@example.com', 'locale' => 'fr'), 'customer block');
    check($order->meta[ElyonPay_WC_Gateway::META_ALL] === array('CM01aaa'), 'uuid stored');
    check($order->status === 'pending', 'order pending');
    check(wp_next_scheduled('elyonpay_check_transaction_status', array(42)) !== false, 're-check scheduled');
});

test('a retry on the same order keeps both uuids and uses a new external_id', function () {
    $g     = gateway(array('test_api_key' => KEY), array(link_response('CM01aaa'), link_response('CM01bbb')));
    $order = new WC_Order(43, '5000');
    $g->process_payment(43);
    $first = $order->meta[ElyonPay_WC_Gateway::META_EXTERNAL_ID];
    $g->process_payment(43);

    check($order->meta[ElyonPay_WC_Gateway::META_ALL] === array('CM01aaa', 'CM01bbb'), 'both uuids kept');
    check($order->meta[ElyonPay_WC_Gateway::META_LATEST] === 'CM01bbb', 'latest is the new one');
    check($order->meta[ElyonPay_WC_Gateway::META_EXTERNAL_ID] !== $first, 'new external_id');
    check(count(Stubs::$cron) === 1, 'a single re-check scheduled');
});

test('webhook: PENDING (paid, awaiting delivery) completes the order after reading the API', function () {
    $order = paid_attempt_order(50, '15000', array('CM01aaa'));
    ElyonPay_WC_Gateway::schedule_check(50, 600);
    $g = gateway(array('test_api_key' => KEY), array(tx('CM01aaa', 'PENDING', 15000)));

    $res = $g->process_webhook(notification('CM01aaa'), array('HTTP_X_MIP_DELIVERY' => '1'), query_for($order));

    check($res === array(200, 'paid'), 'answers 200 paid: ' . json_encode($res));
    check($g->http_client->requests[0]['url'] === 'https://api.elyonpay.net/api/transactions/CM01aaa', 'reads the transaction');
    check($order->status === 'processing' && $order->transaction_id === 'CM01aaa', 'order paid with the uuid');
    check(Stubs::$cron === array(), 're-check cancelled');
    check(!isset(Stubs::$options['elyonpay_lock_50']), 'lock released');
});

test('webhook: the same notification twice completes the order once', function () {
    $order = paid_attempt_order(51, '15000', array('CM01aaa'));
    $g     = gateway(array('test_api_key' => KEY), array(tx('CM01aaa', 'DELIVERED', 15000), tx('CM01aaa', 'DELIVERED', 15000)));
    $g->process_webhook(notification('CM01aaa', 'transaction.delivered'), array(), query_for($order));
    $res = $g->process_webhook(notification('CM01aaa', 'transaction.delivered'), array(), query_for($order));

    check($res === array(200, 'paid'), 'second answer 200 paid');
    check($order->payment_complete_calls === 1, 'payment_complete called once');
});

test('webhook: a forged uuid (another paid transaction) is never looked at', function () {
    $order = paid_attempt_order(52, '90000', array('CM01mine'));
    $g     = gateway(array('test_api_key' => KEY), array());

    $res = $g->process_webhook(notification('CM01cheap_paid_elsewhere'), array(), query_for($order));

    check($res[0] === 200 && strpos($res[1], 'ignored') === 0, 'acknowledged and ignored');
    check($g->http_client->requests === array(), 'no API call');
    check($order->status === 'pending', 'order untouched');
});

test('webhook: wrong order key or unknown order is ignored without an API call', function () {
    $order = paid_attempt_order(53, '1000', array('CM01aaa'));
    $g     = gateway(array('test_api_key' => KEY), array());

    $bad = $g->process_webhook(notification('CM01aaa'), array(), array('order_id' => '53', 'order_key' => 'guess'));
    $no  = $g->process_webhook(notification('CM01aaa'), array(), array('order_id' => '999', 'order_key' => 'x'));

    check($bad[0] === 200 && $no[0] === 200, 'both acknowledged');
    check($g->http_client->requests === array() && $order->status === 'pending', 'nothing read, nothing changed');
});

test('webhook: malformed body answers 400', function () {
    $g = gateway(array('test_api_key' => KEY), array());
    check($g->process_webhook('{"hello":1}', array(), array())[0] === 400, '400');
});

test('webhook: an older attempt declined does not fail the order; the latest does', function () {
    $order = paid_attempt_order(54, '2000', array('CM01old', 'CM01new'));
    $g     = gateway(array('test_api_key' => KEY), array(tx('CM01old', 'DECLINED', 2000), tx('CM01new', 'CANCELLED', 2000)));

    $g->process_webhook(notification('CM01old', 'transaction.declined'), array(), query_for($order));
    check($order->status === 'pending', 'older declined attempt ignored');

    $g->process_webhook(notification('CM01new', 'transaction.cancelled'), array(), query_for($order));
    check($order->status === 'failed', 'latest cancelled attempt fails the order');
});

test('webhook: an older attempt paid late still completes the order', function () {
    $order = paid_attempt_order(55, '2000', array('CM01old', 'CM01new'));
    $order->status = 'failed';
    $g = gateway(array('test_api_key' => KEY), array(tx('CM01old', 'PENDING', 2000)));

    $res = $g->process_webhook(notification('CM01old'), array(), query_for($order));

    check($res === array(200, 'paid') && $order->status === 'processing' && $order->transaction_id === 'CM01old', 'paid with the older uuid');
});

test('webhook: paid for less than the order total puts the order on hold', function () {
    $order = paid_attempt_order(56, '15000', array('CM01aaa'));
    $g     = gateway(array('test_api_key' => KEY), array(tx('CM01aaa', 'DELIVERED', 150)));

    $res = $g->process_webhook(notification('CM01aaa'), array(), query_for($order));

    check($res === array(200, 'mismatch'), '200 mismatch');
    check($order->status === 'on-hold' && $order->payment_complete_calls === 0, 'on hold, not completed');
});

test('webhook: API unreachable answers 503 so ElyonPay retries', function () {
    $order = paid_attempt_order(57, '1000', array('CM01aaa'));
    $g     = gateway(array('test_api_key' => KEY), array(new ElyonPay\Exception\ApiException('HTTP transport error (28): timeout')));

    $res = $g->process_webhook(notification('CM01aaa'), array(), query_for($order));

    check($res === array(503, 'error'), '503');
    check($order->status === 'pending' && !isset(Stubs::$options['elyonpay_lock_57']), 'unchanged, lock released');
});

test('webhook: another check holding the order answers 503 without calling the API', function () {
    $order = paid_attempt_order(58, '1000', array('CM01aaa'));
    Stubs::$options['elyonpay_lock_58'] = (string) time();
    $g = gateway(array('test_api_key' => KEY), array());

    check($g->process_webhook(notification('CM01aaa'), array(), query_for($order)) === array(503, 'busy'), '503 busy');
    check($g->http_client->requests === array(), 'no API call');

    Stubs::$options['elyonpay_lock_58'] = (string) (time() - 120); // left by a crashed request
    $g->http_client = new Fake_Api(array(tx('CM01aaa', 'DELIVERED', 1000)));
    check($g->process_webhook(notification('CM01aaa'), array(), query_for($order)) === array(200, 'paid'), 'stale lock taken over');
});

test('webhook: a uuid the API does not know for this merchant is acknowledged', function () {
    $order = paid_attempt_order(59, '1000', array('CM01aaa'));
    $g     = gateway(array('test_api_key' => KEY), array(ok(400, array('message' => 'Action not authorized'))));

    check($g->process_webhook(notification('CM01aaa'), array(), query_for($order)) === array(200, 'unknown'), '200 unknown');
});

test('scheduled re-check: backs off while unpaid, stops when the delays run out', function () {
    $order = paid_attempt_order(60, '1000', array('CM01aaa'));
    $g     = gateway(array('test_api_key' => KEY), array_fill(0, 4, tx('CM01aaa', 'CREATED', 1000)));

    $delays = array();
    for ($i = 0; $i < 4; $i++) {
        Stubs::$cron = array(); // WP-Cron removes an event before running it
        $before = time();
        $g->run_scheduled_check($order);
        $next = wp_next_scheduled('elyonpay_check_transaction_status', array(60));
        $delays[] = $next === false ? null : $next - $before;
    }
    check(abs($delays[0] - 3600) <= 1 && abs($delays[1] - 6 * 3600) <= 1 && abs($delays[2] - 24 * 3600) <= 1, 'delays 1 h, 6 h, 24 h: ' . json_encode($delays));
    check($delays[3] === null, 'no fifth check');
    check($order->status === 'pending', 'still pending payment');
});

test('legacy login still works when no API key is set, and caches the JWT', function () {
    $jwt = 'h.' . rtrim(strtr(base64_encode(json_encode(array('exp' => time() + 3600))), '+/', '-_'), '=') . '.s';
    $g   = gateway(array('test_username' => 'm@example.com', 'test_password' => 'secret'), array(ok(200, array('token' => $jwt)), link_response('CM01leg')));
    new WC_Order(61, '1000');

    check($g->uses_legacy_login() && $g->is_available(), 'legacy mode, available');
    $res = $g->process_payment(61);

    check($res['result'] === 'success', 'link created');
    check(strpos($g->http_client->requests[0]['url'], '/login') !== false, 'logged in first');
    check($g->http_client->requests[1]['headers']['Authorization'] === 'Bearer ' . $jwt, 'JWT used');
    check(in_array($jwt, Stubs::$transients, true), 'JWT cached');
});

test('the API key wins over legacy credentials; nothing configured means unavailable', function () {
    $g = gateway(array('test_api_key' => KEY, 'test_username' => 'm@example.com', 'test_password' => 'x'), array());
    check(!$g->uses_legacy_login() && $g->is_available(), 'key mode');

    $g = gateway(array(), array());
    check(!$g->is_available(), 'unavailable without credentials');

    $g = gateway(array('testmode' => 'no', 'test_api_key' => KEY), array());
    check(!$g->is_available(), 'the sandbox key does not serve production');
});

test('process_payment: an API refusal is shown to the customer and noted on the order', function () {
    $g     = gateway(array('test_api_key' => KEY), array(ok(401, array('message' => 'Invalid API key.'))));
    $order = new WC_Order(62, '1000');

    $res = $g->process_payment(62);

    check($res['result'] === 'failure', 'failure');
    check(count(Stubs::$notices) === 1 && Stubs::$notices[0][0] === 'error', 'customer notice');
    check(in_array('ElyonPay: Invalid API key.', $order->notes, true), 'order note with the API message');
});

echo $failures === 0 ? "\nOK ($count checks)\n" : "\n$failures/$count checks FAILED\n";
exit($failures === 0 ? 0 : 1);
