<?php
/**
 * ElyonPay WooCommerce Blocks integration.
 *
 * @package ElyonPay\WooCommerce
 */

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_ElyonPay_Blocks_Support extends AbstractPaymentMethodType
{
    protected $name = 'elyonpay';

    /** @var WC_ElyonPay_Gateway|false */
    private $gateway = false;

    public function initialize()
    {
        $this->settings = get_option('woocommerce_elyonpay_settings', array());
        $gateways       = WC()->payment_gateways->payment_gateways();
        $this->gateway  = isset($gateways[$this->name]) ? $gateways[$this->name] : false;
    }

    public function is_active()
    {
        return $this->gateway && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {
        $script_path       = 'assets/js/elyonpay-blocks.js';
        $script_asset_path = ELYONPAY_WC_PLUGIN_PATH . 'assets/js/elyonpay-blocks.asset.php';
        $script_asset      = file_exists($script_asset_path)
            ? require $script_asset_path
            : array(
                'dependencies' => array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'),
                'version'      => ELYONPAY_WC_VERSION,
            );

        wp_register_script(
            'wc-elyonpay-payments-blocks',
            ELYONPAY_WC_PLUGIN_URL . $script_path,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('wc-elyonpay-payments-blocks', 'elyonpay-woocommerce', ELYONPAY_WC_PLUGIN_PATH . 'languages');
        }

        return array('wc-elyonpay-payments-blocks');
    }

    public function get_payment_method_data()
    {
        return array(
            'title'       => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'supports'    => $this->gateway ? array_filter($this->gateway->supports, array($this->gateway, 'supports')) : array('products'),
            'icon'        => $this->gateway ? $this->gateway->icon : '',
            'testmode'    => $this->gateway ? (bool) $this->gateway->testmode : false,
        );
    }

    protected function get_setting($key, $default = '')
    {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }
}
