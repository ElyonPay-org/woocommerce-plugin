<?php
/**
 * ElyonPay WooCommerce payment gateway (built on the ElyonPay PHP SDK).
 *
 * @package ElyonPay\WooCommerce
 */

defined('ABSPATH') || exit;

use ElyonPay\Client;
use ElyonPay\Environment;
use ElyonPay\TransactionStatus;
use ElyonPay\Webhook;
use ElyonPay\Exception\ApiException;
use ElyonPay\Exception\ElyonPayException;

class ElyonPay_WC_Gateway extends WC_Payment_Gateway
{
    /** Order meta: uuid of the latest payment link created for the order. */
    const META_LATEST = '_elyonpay_transaction_id';
    /** Order meta: every uuid created for the order (a customer may retry on the "pay for order" page). */
    const META_ALL = '_elyonpay_transaction_ids';
    /** Order meta: external_id sent with the latest link. */
    const META_EXTERNAL_ID = '_elyonpay_external_id';
    /** Order meta: number of scheduled status re-checks already run. */
    const META_CHECKS = '_elyonpay_checks';

    /** Re-check delays, in seconds after the previous one: the safety net when no webhook gets through. */
    const CHECK_DELAYS = array(600, 3600, 6 * 3600, 24 * 3600);

    /** ElyonPay waits 10 s for the webhook acknowledgement: the status read must fit inside. */
    const WEBHOOK_HTTP_TIMEOUT = 7;

    /** Declared to avoid PHP 8.2+ dynamic property deprecations. */
    public $testmode;
    public $api_key;
    public $username;
    public $password;
    public $role;
    public $user_lang;

    /**
     * Optional ElyonPay\Http\HttpClientInterface (another HTTP stack, or a fake in tests).
     * Null: the SDK's own cURL client.
     */
    public $http_client = null;

    public function __construct()
    {
        $this->id                 = 'elyonpay';
        $this->has_fields         = false;
        $this->method_title       = __('ElyonPay', 'elyonpay-for-woocommerce');
        $this->method_description = __('Acceptez les paiements via ElyonPay (Mobile Money, cartes bancaires).', 'elyonpay-for-woocommerce');
        $this->supports           = array('products');

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled     = $this->get_option('enabled');
        $this->testmode    = 'yes' === $this->get_option('testmode');
        $this->api_key     = trim((string) ($this->testmode ? $this->get_option('test_api_key') : $this->get_option('live_api_key')));
        $this->username    = $this->testmode ? $this->get_option('test_username') : $this->get_option('live_username');
        $this->password    = $this->testmode ? $this->get_option('test_password') : $this->get_option('live_password');
        $this->role        = $this->get_option('role', 'ROLE_MERCHANT_ADMIN');
        $this->user_lang   = $this->get_option('user_lang', 'fr');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    /** True when the gateway still authenticates with the merchant e-mail and password. */
    public function uses_legacy_login()
    {
        return $this->api_key === '' && !empty($this->username) && !empty($this->password);
    }

    public function is_available()
    {
        if ('yes' !== $this->enabled) {
            return false;
        }
        if ($this->api_key === '' && !$this->uses_legacy_login()) {
            return false;
        }
        return parent::is_available();
    }

    public function get_icon()
    {
        $icons = array(
            array('logo_mtn.png', 'MTN MoMo'),
            array('orange-money.png', 'Orange Money'),
            array('wave-money.png', 'Wave'),
            array('moov.png', 'Moov Money'),
            array('airtel.jpg', 'Airtel Money'),
            array('visa.png', 'Visa'),
        );

        $base = ELYONPAY_WC_PLUGIN_URL . 'assets/images/payment/';
        $html = '';
        foreach ($icons as $icon) {
            $html .= '<img src="' . esc_url($base . $icon[0]) . '"'
                . ' alt="' . esc_attr($icon[1]) . '"'
                . ' style="height:24px;width:auto;border-radius:3px;object-fit:contain;margin-left:4px;" />';
        }

        return $html;
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Activer/Désactiver', 'elyonpay-for-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Activer ElyonPay', 'elyonpay-for-woocommerce'),
                'default' => 'no',
            ),
            'title' => array(
                'title'    => __('Titre', 'elyonpay-for-woocommerce'),
                'type'     => 'text',
                'default'  => __('Mobile Money / Carte (ElyonPay)', 'elyonpay-for-woocommerce'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title'   => __('Description', 'elyonpay-for-woocommerce'),
                'type'    => 'textarea',
                'default' => __('Payez avec MTN, Orange, Wave, Moov, Airtel ou votre carte bancaire.', 'elyonpay-for-woocommerce'),
            ),
            'testmode' => array(
                'title'       => __('Mode test', 'elyonpay-for-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Activer le mode test (sandbox)', 'elyonpay-for-woocommerce'),
                'default'     => 'yes',
                'description' => __('Sandbox : api.elyonpay.net — aucune transaction réelle.', 'elyonpay-for-woocommerce'),
            ),
            'api_keys' => array(
                'title'       => __('Clé d\'API', 'elyonpay-for-woocommerce'),
                'type'        => 'title',
                'description' => __('Copiez la clé d\'API depuis votre tableau de bord ElyonPay (elle commence par pk_live_egt_). Le compte sandbox et le compte de production ont chacun la leur. Elle n\'ouvre que les paiements et la consultation des transactions, et se régénère depuis le tableau de bord si elle fuit.', 'elyonpay-for-woocommerce'),
            ),
            'test_api_key' => array(
                'title' => __('Clé d\'API (sandbox)', 'elyonpay-for-woocommerce'),
                'type'  => 'password',
            ),
            'live_api_key' => array(
                'title' => __('Clé d\'API (production)', 'elyonpay-for-woocommerce'),
                'type'  => 'password',
            ),
            'user_lang' => array(
                'title'   => __('Langue de l\'interface de paiement', 'elyonpay-for-woocommerce'),
                'type'    => 'select',
                'default' => 'fr',
                'options' => array('fr' => 'Français', 'en' => 'English'),
            ),
            'legacy' => array(
                'title'       => __('Ancienne connexion par e-mail et mot de passe (dépréciée)', 'elyonpay-for-woocommerce'),
                'type'        => 'title',
                'description' => __('Utilisée seulement si la clé d\'API du mode choisi est vide. Le mot de passe ouvre tout votre compte marchand : passez à la clé d\'API, puis videz ces champs.', 'elyonpay-for-woocommerce'),
            ),
            'role' => array(
                'title'   => __('Rôle', 'elyonpay-for-woocommerce'),
                'type'    => 'text',
                'default' => 'ROLE_MERCHANT_ADMIN',
            ),
            'test_username' => array(
                'title' => __('Identifiant (sandbox)', 'elyonpay-for-woocommerce'),
                'type'  => 'text',
            ),
            'test_password' => array(
                'title' => __('Mot de passe (sandbox)', 'elyonpay-for-woocommerce'),
                'type'  => 'password',
            ),
            'live_username' => array(
                'title' => __('Identifiant marchand (production)', 'elyonpay-for-woocommerce'),
                'type'  => 'email',
            ),
            'live_password' => array(
                'title' => __('Mot de passe marchand (production)', 'elyonpay-for-woocommerce'),
                'type'  => 'password',
            ),
        );
    }

    /**
     * Build an SDK client: the API key when set, otherwise the legacy login with a cached JWT.
     *
     * @param int $timeout HTTP timeout in seconds
     */
    private function client($timeout = 30)
    {
        $environment = $this->testmode ? Environment::SANDBOX : Environment::PRODUCTION;

        if ($this->api_key !== '') {
            return new Client(array(
                'api_key'     => $this->api_key,
                'environment' => $environment,
                'timeout'     => (int) $timeout,
            ), $this->http_client);
        }

        $config = array(
            'username'    => (string) $this->username,
            'password'    => (string) $this->password,
            'role'        => (string) ($this->role ?: 'ROLE_MERCHANT_ADMIN'),
            'environment' => $environment,
            'timeout'     => (int) $timeout,
        );

        $cached = get_transient($this->token_key());
        if (is_string($cached) && $cached !== '') {
            $config['token'] = $cached;
        }

        return new Client($config, $this->http_client);
    }

    private function token_key()
    {
        return 'elyonpay_jwt_' . md5(($this->testmode ? 'test:' : 'live:') . $this->username);
    }

    /**
     * Persist the (possibly refreshed) JWT of the legacy login, deriving the TTL from its exp
     * claim. Nothing to cache with an API key.
     */
    private function cache_token(Client $client)
    {
        if ($client->usesApiKey()) {
            return;
        }
        try {
            $token = $client->getToken();
        } catch (ElyonPayException $e) {
            return;
        }
        if (!$token) {
            return;
        }

        $ttl   = 3000; // safe default (< 1h)
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $payload = json_decode($this->b64url_decode($parts[1]), true);
            if (is_array($payload) && !empty($payload['exp'])) {
                $ttl = max(60, (int) $payload['exp'] - time() - 300);
            }
        }
        set_transient($this->token_key(), $token, $ttl);
    }

    private function b64url_decode($data)
    {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode($data);
    }

    /**
     * Webhook URL given to ElyonPay for one order. The order id and key let the endpoint find the
     * order cheaply; they prove nothing — the status is always read back from the API.
     */
    public function webhook_url($order)
    {
        return add_query_arg(
            array(
                'order_id'  => $order->get_id(),
                'order_key' => $order->get_order_key(),
            ),
            WC()->api_request_url('elyonpay_webhook')
        );
    }

    /**
     * Create the payment link and redirect the customer.
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        $phone = $this->normalize_msisdn($order->get_billing_phone(), $order->get_billing_country());
        if ($phone === '') {
            wc_add_notice(__('Un numéro de téléphone valide est requis pour le paiement.', 'elyonpay-for-woocommerce'), 'error');
            return array('result' => 'failure');
        }

        $return_args = array(
            'order_id'  => $order->get_id(),
            'order_key' => $order->get_order_key(),
            '_wpnonce'  => wp_create_nonce('elyonpay_return'),
        );
        $return_base = add_query_arg($return_args, WC()->api_request_url('elyonpay_success'));
        $error_url   = add_query_arg($return_args, WC()->api_request_url('elyonpay_error'));
        $external_id = $this->external_id($order);

        $payload = array(
            'amount'      => (float) $order->get_total(),
            'user_lang'   => $this->user_lang,
            'msisdn'      => $phone,
            'success'     => $return_base,
            'error'       => $error_url,
            'webhook'     => $this->webhook_url($order),
            'external_id' => $external_id,
        );
        $customer = $this->customer_block($order);
        if (!empty($customer)) {
            $payload['customer'] = $customer;
        }

        $client = $this->client();
        try {
            $link = $client->createPaymentLink($payload);
        } catch (ElyonPayException $e) {
            $order->add_order_note('ElyonPay: ' . $e->getMessage());
            wc_add_notice(__('Erreur lors de l\'initialisation du paiement. Veuillez réessayer.', 'elyonpay-for-woocommerce'), 'error');
            return array('result' => 'failure');
        }
        $this->cache_token($client);

        if (preg_match('#/t/([A-Za-z0-9]+)#', (string) wp_parse_url($link['url'], PHP_URL_PATH), $m)) {
            $uuid  = $m[1];
            $known = $this->transaction_ids($order);
            if (!in_array($uuid, $known, true)) {
                $known[] = $uuid;
            }
            $order->update_meta_data(self::META_LATEST, $uuid);
            $order->update_meta_data(self::META_ALL, $known);
        }
        $order->update_meta_data(self::META_EXTERNAL_ID, $external_id);
        $order->update_meta_data(self::META_CHECKS, 0);
        // Do NOT reduce stock or empty the cart here — only once the payment is
        // confirmed server-side (WooCommerce reduces stock on payment_complete).
        $order->update_status('pending', __('En attente du paiement ElyonPay.', 'elyonpay-for-woocommerce'));
        $order->save();

        // Safety net if no webhook gets through (site unreachable, firewall…).
        self::schedule_check($order->get_id(), self::CHECK_DELAYS[0]);

        return array('result' => 'success', 'redirect' => $link['url']);
    }

    /**
     * external_id must be unique per merchant: one per attempt (the "pay for order" page creates a
     * new link for the same order), readable for reconciliation in the ElyonPay dashboard.
     */
    private function external_id($order)
    {
        $number = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $order->get_order_number());
        if ($number === '') {
            $number = (string) $order->get_id();
        }

        return 'wc-' . substr($number, 0, 40) . '-' . strtolower(wp_generate_password(8, false, false));
    }

    /**
     * The documented customer block. The payer's number travels as msisdn.
     */
    private function customer_block($order)
    {
        $customer = array();

        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if ($name !== '') {
            $customer['name'] = mb_substr($name, 0, 128);
        }
        $email = (string) $order->get_billing_email();
        if ($email !== '' && strlen($email) <= 190 && is_email($email)) {
            $customer['email'] = $email;
        }
        if (in_array($this->user_lang, array('fr', 'en'), true)) {
            $customer['locale'] = $this->user_lang;
        }

        return $customer;
    }

    /** Every transaction uuid created for this order, oldest first. */
    public function transaction_ids($order)
    {
        $all = $order->get_meta(self::META_ALL);
        $all = is_array($all) ? array_values(array_filter($all, 'is_string')) : array();

        // Orders paid before 1.3.0 only hold the latest uuid.
        $latest = (string) $order->get_meta(self::META_LATEST);
        if ($latest !== '' && !in_array($latest, $all, true)) {
            $all[] = $latest;
        }

        return $all;
    }

    /**
     * Read the definitive status of one of the order's transactions and update the order.
     *
     * @param WC_Order    $order
     * @param string|null $reference A uuid created for this order; the latest one by default
     * @param int         $timeout   HTTP timeout in seconds
     *
     * @return string 'paid' | 'failed' | 'pending' | 'mismatch' (paid, amount too low) |
     *                'unknown' (not a transaction of this order) | 'busy' (another check
     *                running) | 'error' (API unreachable, try again later)
     */
    public function verify_transaction_status($order, $reference = null, $timeout = 30)
    {
        $latest    = (string) $order->get_meta(self::META_LATEST);
        $reference = $reference === null ? $latest : (string) $reference;
        if ($reference === '' || !in_array($reference, $this->transaction_ids($order), true)) {
            return 'unknown';
        }

        if (!self::acquire_lock($order->get_id())) {
            return 'busy';
        }

        try {
            // Re-read under the lock: a concurrent check may just have completed the order.
            $fresh = wc_get_order($order->get_id());
            if ($fresh) {
                $order = $fresh;
            }

            $client = $this->client($timeout);
            try {
                $tx = $client->getTransaction($reference);
            } catch (ApiException $e) {
                // 400 on a uuid this site created itself is not final. The API answers 400 both for a
                // uuid it does not know for this key (environment or key changed since the payment)
                // and when it cannot reach the payment service to refresh the state ("[STATUS]
                // Provider cannot found transaction ..."), a passing outage. Retry on the usual
                // schedule, which runs out on its own.
                if ($e->getHttpStatus() === 400) {
                    /* translators: 1: ElyonPay transaction uuid, 2: error message returned by the API. */
                    $order->add_order_note(sprintf(__('ElyonPay n\'a pas pu lire la transaction %1$s : %2$s. Nouvel essai prévu.', 'elyonpay-for-woocommerce'), $reference, $e->getMessage()));
                    return 'error';
                }
                /* translators: %s is the error message returned by the ElyonPay API. */
                $order->add_order_note(sprintf(__('Erreur de vérification ElyonPay : %s', 'elyonpay-for-woocommerce'), $e->getMessage()));
                return 'error';
            } catch (ElyonPayException $e) {
                /* translators: %s is the error message returned by the ElyonPay API. */
                $order->add_order_note(sprintf(__('Erreur de vérification ElyonPay : %s', 'elyonpay-for-woocommerce'), $e->getMessage()));
                return 'error';
            }
            $this->cache_token($client);

            if (isset($tx['uuid']) && (string) $tx['uuid'] !== $reference) {
                return 'unknown';
            }

            return $this->apply_state($order, $reference, $reference === $latest, $tx);
        } finally {
            self::release_lock($order->get_id());
        }
    }

    /**
     * @param array<string,mixed> $tx The transaction as read from GET /transactions/{uuid}
     */
    private function apply_state($order, $reference, $is_latest, array $tx)
    {
        $state = isset($tx['state']) ? $tx['state'] : (isset($tx['status']) ? $tx['status'] : null);

        if (TransactionStatus::isPaid($state)) {
            // The API stores the amount as an integer in the merchant account's currency: cents for
            // a decimal currency (EUR: 12.34 -> 1234), truncated units otherwise (XAF: 15000.5 ->
            // 15000). Allowing one unit of difference never holds a correct payment in either case,
            // and still catches a link paid for a lower total (an order edited after the link).
            $amount = isset($tx['amount']) && is_numeric($tx['amount']) ? (float) $tx['amount'] : null;
            if ($amount !== null && $amount + 1 < (float) $order->get_total()) {
                if (!$order->is_paid() && !$order->has_status('on-hold')) {
                    $order->update_status('on-hold', sprintf(
                        /* translators: 1: ElyonPay transaction uuid, 2: amount paid, 3: order total. */
                        __('Paiement ElyonPay %1$s confirmé pour %2$s alors que la commande vaut %3$s : vérifiez avant d\'expédier.', 'elyonpay-for-woocommerce'),
                        $reference,
                        wc_format_decimal($amount),
                        wc_format_decimal($order->get_total())
                    ));
                }
                return 'mismatch';
            }

            if (!$order->is_paid()) {
                $order->payment_complete($reference);
                /* translators: 1: ElyonPay transaction status (e.g. PENDING, DELIVERED), 2: transaction uuid. */
                $order->add_order_note(sprintf(__('Paiement ElyonPay confirmé (%1$s, transaction %2$s).', 'elyonpay-for-woocommerce'), $state, $reference));
            }
            return 'paid';
        }

        if (TransactionStatus::isFailed($state)) {
            // Only the latest attempt decides a failure: an older link declined after the customer
            // retried says nothing about the order.
            if ($is_latest && !$order->is_paid() && !$order->has_status(array('failed', 'cancelled', 'refunded'))) {
                /* translators: %s is the ElyonPay transaction status (e.g. DECLINED, CANCELLED). */
                $order->update_status('failed', sprintf(__('Paiement ElyonPay échoué (%s).', 'elyonpay-for-woocommerce'), $state));
            }
            return 'failed';
        }

        // CREATED / WAITING_FOR_PAYMENT: the customer has not paid yet. The order stays "pending
        // payment", so WooCommerce's unpaid-order timeout still applies; a later payment revives it.
        return 'pending';
    }

    /**
     * Handle one webhook notification. Pure (no exit) so it can be tested.
     *
     * @param string              $body    Raw request body
     * @param array<string,mixed> $headers Request headers ($_SERVER shape is fine)
     * @param array<string,mixed> $query   Query string ($_GET)
     *
     * @return array{0:int, 1:string} HTTP status to answer, short reason
     */
    public function process_webhook($body, array $headers, array $query)
    {
        try {
            $notification = Webhook::parse((string) $body, $headers);
        } catch (InvalidArgumentException $e) {
            return array(400, 'not an ElyonPay notification');
        }

        $order_id  = isset($query['order_id']) ? absint($query['order_id']) : 0;
        $order_key = isset($query['order_key']) ? sanitize_text_field(wp_unslash($query['order_key'])) : '';
        $order     = $order_id ? wc_get_order($order_id) : false;

        // From here on, answer 2xx for anything ElyonPay cannot fix by retrying.
        if (!$order || !hash_equals($order->get_order_key(), (string) $order_key) || $order->get_payment_method() !== $this->id) {
            return array(200, 'ignored: unknown order');
        }

        // The body is unsigned: only a uuid this site created for this very order is looked at.
        $uuid = $notification['transaction'];
        if (!in_array($uuid, $this->transaction_ids($order), true)) {
            return array(200, 'ignored: not a transaction of this order');
        }

        $outcome = $this->verify_transaction_status($order, $uuid, self::WEBHOOK_HTTP_TIMEOUT);

        if ($outcome === 'busy' || $outcome === 'error') {
            return array(503, $outcome); // ElyonPay retries 3 s, 30 s, 60 s, 90 s later
        }
        if ($outcome === 'paid' || $outcome === 'failed') {
            self::unschedule_check($order->get_id());
        }

        return array(200, $outcome);
    }

    /**
     * Schedule a status re-check of an order (replaces any pending one).
     */
    public static function schedule_check($order_id, $delay)
    {
        self::unschedule_check($order_id);
        wp_schedule_single_event(time() + (int) $delay, 'elyonpay_check_transaction_status', array((int) $order_id));
    }

    public static function unschedule_check($order_id)
    {
        $args = array((int) $order_id);
        $next = wp_next_scheduled('elyonpay_check_transaction_status', $args);
        while ($next) {
            wp_unschedule_event($next, 'elyonpay_check_transaction_status', $args);
            $next = wp_next_scheduled('elyonpay_check_transaction_status', $args);
        }
    }

    /**
     * Scheduled re-check: verify, then schedule the next one with a growing delay until the
     * payment is decided or the delays run out.
     */
    public function run_scheduled_check($order)
    {
        if ($order->is_paid()) {
            return;
        }

        $outcome = $this->verify_transaction_status($order);
        if (in_array($outcome, array('paid', 'failed', 'unknown', 'mismatch'), true)) {
            return;
        }

        $done = (int) $order->get_meta(self::META_CHECKS) + 1;
        $order->update_meta_data(self::META_CHECKS, $done);
        $order->save_meta_data();

        if ($outcome === 'busy') {
            self::schedule_check($order->get_id(), 60);
        } elseif (array_key_exists($done, self::CHECK_DELAYS)) {
            self::schedule_check($order->get_id(), self::CHECK_DELAYS[$done]);
        }
    }

    /**
     * Per-order mutex, so the webhook, the customer's return and the re-check never complete the
     * same order twice. INSERT IGNORE on the unique option_name is atomic: only one caller gets a
     * row inserted. (add_option() is not: it upserts with ON DUPLICATE KEY UPDATE.)
     */
    private static function acquire_lock($order_id)
    {
        global $wpdb;
        $name = 'elyonpay_lock_' . (int) $order_id;

        // A lock older than a minute was left behind by a crashed request.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d", $name, time() - 60));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time()));

        return 1 === (int) $inserted;
    }

    private static function release_lock($order_id)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", 'elyonpay_lock_' . (int) $order_id));
    }

    /**
     * Normalise a phone number to international format using the billing country.
     * PHP 7.4-safe (no str_starts_with).
     */
    private function normalize_msisdn($phone, $country)
    {
        $phone = preg_replace('/[^0-9+]/', '', (string) $phone);
        if ($phone === '') {
            return '';
        }
        if (strpos($phone, '+') === 0) {
            return $phone;
        }
        $prefixes = array(
            'CM' => '237', 'CI' => '225', 'SN' => '221', 'ML' => '223', 'BF' => '226',
            'BJ' => '229', 'TG' => '228', 'NE' => '227', 'NG' => '234', 'GH' => '233',
            'UG' => '256', 'KE' => '254', 'TZ' => '255', 'ZM' => '260', 'CD' => '243',
            'FR' => '33',
        );
        $prefix = isset($prefixes[$country]) ? $prefixes[$country] : '';
        if ($prefix === '') {
            return '+' . ltrim($phone, '0'); // unknown country: best effort
        }
        return '+' . $prefix . ltrim($phone, '0');
    }

    public function admin_options()
    {
        echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';
        echo '<p>' . esc_html($this->get_method_description()) . '</p>';
        if ($this->testmode) {
            echo '<div class="notice notice-info inline"><p>'
                . esc_html__('Mode TEST actif (api.elyonpay.net) : renseignez la clé d\'API de votre compte sandbox.', 'elyonpay-for-woocommerce')
                . '</p></div>';
        }
        if ($this->uses_legacy_login()) {
            echo '<div class="notice notice-warning inline"><p>'
                . esc_html__('Ce mode utilise encore l\'e-mail et le mot de passe du compte marchand. Renseignez la clé d\'API : elle n\'ouvre que les paiements et se régénère si elle fuit.', 'elyonpay-for-woocommerce')
                . '</p></div>';
        }
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';

        echo '<h3>' . esc_html__('Webhooks', 'elyonpay-for-woocommerce') . '</h3>';
        echo '<p>' . esc_html__('Rien à configurer : chaque paiement transmet à ElyonPay l\'adresse à notifier, et la boutique relit le statut auprès de l\'API avant de valider la commande. Adresse appelée :', 'elyonpay-for-woocommerce')
            . ' <code>' . esc_html(WC()->api_request_url('elyonpay_webhook')) . '</code></p>';
        echo '<p>' . esc_html__('Elle doit être joignable depuis Internet (ni site local, ni pare-feu qui bloque les requêtes POST). Sinon, le statut est tout de même relu au retour du client puis à intervalles réguliers.', 'elyonpay-for-woocommerce') . '</p>';

        $states = array(
            array('CREATED', __('Transaction créée, pas encore payée', 'elyonpay-for-woocommerce')),
            array('WAITING_FOR_PAYMENT', __('En attente du paiement du client', 'elyonpay-for-woocommerce')),
            array('PENDING', __('Paiement encaissé, en attente de livraison. Malgré son nom, cet état signifie que l\'argent est arrivé (payée).', 'elyonpay-for-woocommerce')),
            array('ACCEPTED', __('Approuvée et validée (payée)', 'elyonpay-for-woocommerce')),
            array('DELIVERED', __('Fonds transférés (payée)', 'elyonpay-for-woocommerce')),
            array('REJECTED', __('Refusée définitivement (échec)', 'elyonpay-for-woocommerce')),
            array('DECLINED', __('Refusée par l\'institution financière (échec)', 'elyonpay-for-woocommerce')),
            array('CANCELLED', __('Annulée (échec)', 'elyonpay-for-woocommerce')),
        );
        echo '<h3>' . esc_html__('États des transactions ElyonPay', 'elyonpay-for-woocommerce') . '</h3>';
        echo '<table class="widefat"><thead><tr><th>Code</th><th>Description</th></tr></thead><tbody>';
        foreach ($states as $row) {
            echo '<tr><td><code>' . esc_html($row[0]) . '</code></td><td>' . esc_html($row[1]) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
