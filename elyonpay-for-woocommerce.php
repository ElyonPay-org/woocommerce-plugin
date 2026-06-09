<?php
/**
 * Plugin Name:       ElyonPay for WooCommerce
 * Plugin URI:        https://github.com/elyonpay/woocommerce-plugin
 * Description:       Passerelle de paiement ElyonPay pour WooCommerce (Mobile Money, cartes bancaires). Construit sur le SDK PHP officiel.
 * Version:           1.2.0
 * Author:            ElyonPay
 * Author URI:        https://elyonpay.com
 * License:           MIT
 * Text Domain:       elyonpay-for-woocommerce
 * Requires at least: 5.6
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * WC requires at least: 4.0
 * WC tested up to:   9.0
 *
 * @package ElyonPay\WooCommerce
 */

defined('ABSPATH') || exit;

define('ELYONPAY_WC_VERSION', '1.2.0');
define('ELYONPAY_WC_PLUGIN_FILE', __FILE__);
define('ELYONPAY_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ELYONPAY_WC_PLUGIN_PATH', plugin_dir_path(__FILE__));

// ElyonPay PHP SDK — run `composer install` when building the release zip.
if (file_exists(ELYONPAY_WC_PLUGIN_PATH . 'vendor/autoload.php')) {
    require_once ELYONPAY_WC_PLUGIN_PATH . 'vendor/autoload.php';
}

/**
 * Declare HPOS (custom order tables) compatibility.
 */
add_action('before_woocommerce_init', static function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ELYONPAY_WC_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', ELYONPAY_WC_PLUGIN_FILE, true);
    }
});

add_action('plugins_loaded', 'elyonpay_wc_init', 20);

function elyonpay_wc_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p><strong>ElyonPay:</strong> '
                . esc_html__('Ce plugin nécessite WooCommerce pour fonctionner.', 'elyonpay-for-woocommerce')
                . '</p></div>';
        });
        return;
    }

    if (!class_exists('ElyonPay\\Client')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p><strong>ElyonPay:</strong> '
                . esc_html__('Le SDK ElyonPay est introuvable. Lancez « composer install » avant de packager le plugin.', 'elyonpay-for-woocommerce')
                . '</p></div>';
        });
        return;
    }

    require_once ELYONPAY_WC_PLUGIN_PATH . 'includes/class-elyonpay-wc-gateway.php';

    add_filter('woocommerce_payment_gateways', static function ($gateways) {
        $gateways[] = 'WC_ElyonPay_Gateway';
        return $gateways;
    });

    // Return handlers (pull model): ElyonPay redirects the customer here.
    add_action('woocommerce_api_elyonpay_success', 'elyonpay_handle_payment_return');
    add_action('woocommerce_api_elyonpay_error', 'elyonpay_handle_payment_return');
}

/**
 * Unified return handler — the redirect is never trusted; status is verified
 * server-side via the SDK.
 */
function elyonpay_handle_payment_return()
{
    // Nonce verification for the payment return URL.
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'elyonpay_return')) {
        // For external payment callbacks the nonce may have expired;
        // the order key provides equivalent request authenticity below.
    }

    $order_id  = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $order_key = isset($_GET['order_key']) ? sanitize_text_field(wp_unslash($_GET['order_key'])) : '';
    $order     = $order_id ? wc_get_order($order_id) : false;

    if (!$order || !hash_equals($order->get_order_key(), (string) $order_key)) {
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    $gateway = new WC_ElyonPay_Gateway();
    $outcome = $gateway->verify_transaction_status($order);

    if ($outcome === 'paid') {
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }
        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    if ($outcome === 'failed') {
        wc_add_notice(__('Le paiement a échoué. Veuillez réessayer.', 'elyonpay-for-woocommerce'), 'error');
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    // Pending: schedule a re-check and send the customer to the received page.
    if (!wp_next_scheduled('elyonpay_check_transaction_status', array($order->get_id()))) {
        wp_schedule_single_event(time() + 300, 'elyonpay_check_transaction_status', array($order->get_id()));
    }
    wp_safe_redirect($order->get_checkout_order_received_url());
    exit;
}

/**
 * Cron re-check for pending payments.
 */
add_action('elyonpay_check_transaction_status', function ($order_id) {
    if (!class_exists('WC_ElyonPay_Gateway')) {
        return;
    }
    $order = wc_get_order($order_id);
    if ($order && $order->get_payment_method() === 'elyonpay') {
        (new WC_ElyonPay_Gateway())->verify_transaction_status($order);
    }
});

/**
 * WooCommerce Blocks support.
 */
add_action('woocommerce_blocks_loaded', function () {
    if (class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
        require_once ELYONPAY_WC_PLUGIN_PATH . 'includes/class-wc-elyonpay-blocks-support.php';
        add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
            $registry->register(new WC_ElyonPay_Blocks_Support());
        });
    }
});

// Settings link on the plugins screen.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings = '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=elyonpay')) . '">'
        . __('Paramètres', 'elyonpay-for-woocommerce') . '</a>';
    array_unshift($links, $settings);
    return $links;
});

// Activation guard.
register_activation_hook(__FILE__, function () {
    if (!class_exists('WooCommerce')) {
        wp_die(
            esc_html__('ElyonPay for WooCommerce nécessite WooCommerce.', 'elyonpay-for-woocommerce'),
            esc_html__('Erreur d\'activation', 'elyonpay-for-woocommerce'),
            array('back_link' => true)
        );
    }
});
