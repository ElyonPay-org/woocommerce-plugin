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
use ElyonPay\Exception\ElyonPayException;

class WC_ElyonPay_Gateway extends WC_Payment_Gateway
{
    /** Declared to avoid PHP 8.2+ dynamic property deprecations. */
    public $testmode;
    public $username;
    public $password;
    public $role;
    public $user_lang;

    public function __construct()
    {
        $this->id                 = 'elyonpay';
        $this->icon               = ELYONPAY_WC_PLUGIN_URL . 'assets/elyonpay-logo.png';
        $this->has_fields         = false;
        $this->method_title       = __('ElyonPay', 'elyonpay-woocommerce');
        $this->method_description = __('Acceptez les paiements via ElyonPay (Mobile Money, cartes bancaires).', 'elyonpay-woocommerce');
        $this->supports           = array('products');

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled     = $this->get_option('enabled');
        $this->testmode    = 'yes' === $this->get_option('testmode');
        $this->username    = $this->testmode ? $this->get_option('test_username') : $this->get_option('live_username');
        $this->password    = $this->testmode ? $this->get_option('test_password') : $this->get_option('live_password');
        $this->role        = $this->get_option('role', 'ROLE_MERCHANT_ADMIN');
        $this->user_lang   = $this->get_option('user_lang', 'fr');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function is_available()
    {
        if ('yes' !== $this->enabled) {
            return false;
        }
        if (empty($this->username) || empty($this->password)) {
            return false;
        }
        return parent::is_available();
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Activer/Désactiver', 'elyonpay-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Activer ElyonPay', 'elyonpay-woocommerce'),
                'default' => 'no',
            ),
            'title' => array(
                'title'    => __('Titre', 'elyonpay-woocommerce'),
                'type'     => 'text',
                'default'  => __('Mobile Money / Carte (ElyonPay)', 'elyonpay-woocommerce'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title'   => __('Description', 'elyonpay-woocommerce'),
                'type'    => 'textarea',
                'default' => __('Payez avec MTN, Orange, Wave, Moov, Airtel ou votre carte bancaire.', 'elyonpay-woocommerce'),
            ),
            'testmode' => array(
                'title'       => __('Mode test', 'elyonpay-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Activer le mode test (sandbox)', 'elyonpay-woocommerce'),
                'default'     => 'yes',
                'description' => __('Sandbox : api.elyonpay.net — aucune transaction réelle.', 'elyonpay-woocommerce'),
            ),
            'role' => array(
                'title'       => __('Rôle', 'elyonpay-woocommerce'),
                'type'        => 'text',
                'default'     => 'ROLE_MERCHANT_ADMIN',
                'description' => __('Production : ROLE_MERCHANT_ADMIN. Le compte de test documenté utilise ROLE_CUSTOMER — si l\'auth sandbox échoue, essayez ROLE_CUSTOMER.', 'elyonpay-woocommerce'),
                'desc_tip'    => true,
            ),
            'test_username' => array(
                'title'   => __('Identifiant (test)', 'elyonpay-woocommerce'),
                'type'    => 'text',
                'default' => 'test@test.com',
            ),
            'test_password' => array(
                'title'   => __('Mot de passe (test)', 'elyonpay-woocommerce'),
                'type'    => 'password',
                'default' => 'TestPassword1',
            ),
            'live_username' => array(
                'title' => __('Identifiant marchand (production)', 'elyonpay-woocommerce'),
                'type'  => 'email',
            ),
            'live_password' => array(
                'title' => __('Mot de passe marchand (production)', 'elyonpay-woocommerce'),
                'type'  => 'password',
            ),
            'user_lang' => array(
                'title'   => __('Langue de l\'interface de paiement', 'elyonpay-woocommerce'),
                'type'    => 'select',
                'default' => 'fr',
                'options' => array('fr' => 'Français', 'en' => 'English'),
            ),
        );
    }

    /**
     * Build an SDK client, reusing a cached JWT from a transient when valid.
     */
    private function client()
    {
        $config = array(
            'username'    => (string) $this->username,
            'password'    => (string) $this->password,
            'role'        => (string) ($this->role ?: 'ROLE_MERCHANT_ADMIN'),
            'environment' => $this->testmode ? Environment::SANDBOX : Environment::PRODUCTION,
        );

        $cached = get_transient($this->token_key());
        if (is_string($cached) && $cached !== '') {
            $config['token'] = $cached;
        }

        return new Client($config);
    }

    private function token_key()
    {
        return 'elyonpay_jwt_' . md5(($this->testmode ? 'test:' : 'live:') . $this->username);
    }

    /**
     * Persist the (possibly refreshed) JWT, deriving the TTL from its exp claim.
     */
    private function cache_token(Client $client)
    {
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
     * Create the payment link and redirect the customer.
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        $phone = $this->normalize_msisdn($order->get_billing_phone(), $order->get_billing_country());
        if ($phone === '') {
            wc_add_notice(__('Un numéro de téléphone valide est requis pour le paiement.', 'elyonpay-woocommerce'), 'error');
            return array('result' => 'failure');
        }

        $return_base = add_query_arg(
            array('order_id' => $order->get_id(), 'order_key' => $order->get_order_key()),
            WC()->api_request_url('elyonpay_success')
        );
        $error_url = add_query_arg(
            array('order_id' => $order->get_id(), 'order_key' => $order->get_order_key()),
            WC()->api_request_url('elyonpay_error')
        );

        $client = $this->client();
        try {
            $link = $client->createPaymentLink(array(
                'amount'    => (float) $order->get_total(),
                'user_lang' => $this->user_lang,
                'msisdn'    => $phone,
                'success'   => $return_base,
                'error'     => $error_url,
            ));
        } catch (ElyonPayException $e) {
            $order->add_order_note('ElyonPay: ' . $e->getMessage());
            wc_add_notice(__('Erreur lors de l\'initialisation du paiement. Veuillez réessayer.', 'elyonpay-woocommerce'), 'error');
            return array('result' => 'failure');
        }
        $this->cache_token($client);

        if (preg_match('#/t/([A-Za-z0-9]+)#', (string) wp_parse_url($link['url'], PHP_URL_PATH), $m)) {
            $order->update_meta_data('_elyonpay_transaction_id', $m[1]);
        }
        // Do NOT reduce stock or empty the cart here — only once the payment is
        // confirmed server-side (WooCommerce reduces stock on payment_complete).
        $order->update_status('pending', __('En attente du paiement ElyonPay.', 'elyonpay-woocommerce'));
        $order->save();

        return array('result' => 'success', 'redirect' => $link['url']);
    }

    /**
     * Verify the definitive status server-side and update the order.
     *
     * @return string One of 'paid' | 'failed' | 'pending'.
     */
    public function verify_transaction_status($order)
    {
        $reference = (string) $order->get_meta('_elyonpay_transaction_id');
        if ($reference === '') {
            return 'pending';
        }

        $client = $this->client();
        try {
            $tx = $client->getTransaction($reference);
        } catch (ElyonPayException $e) {
            $order->add_order_note(sprintf(__('Erreur de vérification ElyonPay : %s', 'elyonpay-woocommerce'), $e->getMessage()));
            return 'pending';
        }
        $this->cache_token($client);

        $state = isset($tx['state']) ? $tx['state'] : (isset($tx['status']) ? $tx['status'] : null);

        if (TransactionStatus::isPaid($state)) {
            if (!$order->is_paid()) {
                $order->payment_complete($reference);
                $order->add_order_note(sprintf(__('Paiement ElyonPay confirmé (%s).', 'elyonpay-woocommerce'), $state));
            }
            return 'paid';
        }

        if (TransactionStatus::isFailed($state)) {
            if (!in_array($order->get_status(), array('processing', 'completed'), true)) {
                $order->update_status('failed', sprintf(__('Paiement ElyonPay échoué (%s).', 'elyonpay-woocommerce'), $state));
            }
            return 'failed';
        }

        $order->update_status('on-hold', sprintf(__('Paiement ElyonPay en attente (%s).', 'elyonpay-woocommerce'), $state));
        return 'pending';
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
                . esc_html__('Mode TEST actif (api.elyonpay.net). Compte de test : test@test.com / TestPassword1.', 'elyonpay-woocommerce')
                . '</p></div>';
        }
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';

        $states = array(
            array('CREATED', __('Transaction créée, pas encore démarrée', 'elyonpay-woocommerce')),
            array('PENDING', __('En cours de traitement', 'elyonpay-woocommerce')),
            array('WAITING_FOR_PAYMENT', __('En attente du paiement du client', 'elyonpay-woocommerce')),
            array('ACCEPTED', __('Approuvée et validée (payée)', 'elyonpay-woocommerce')),
            array('DELIVERED', __('Fonds transférés (payée)', 'elyonpay-woocommerce')),
            array('REJECTED', __('Refusée définitivement (échec)', 'elyonpay-woocommerce')),
            array('DECLINED', __('Refusée par l\'institution financière (échec)', 'elyonpay-woocommerce')),
            array('CANCELLED', __('Annulée (échec)', 'elyonpay-woocommerce')),
        );
        echo '<h3>' . esc_html__('États des transactions ElyonPay', 'elyonpay-woocommerce') . '</h3>';
        echo '<table class="widefat"><thead><tr><th>Code</th><th>Description</th></tr></thead><tbody>';
        foreach ($states as $row) {
            echo '<tr><td><code>' . esc_html($row[0]) . '</code></td><td>' . esc_html($row[1]) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
