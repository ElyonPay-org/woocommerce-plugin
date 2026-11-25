<?php
/**
 * Just enough WordPress and WooCommerce to run the gateway outside WordPress.
 * Not shipped (tests/ is in .distignore).
 */

define('ABSPATH', __DIR__ . '/');
define('ELYONPAY_WC_PLUGIN_URL', 'https://shop.test/wp-content/plugins/elyonpay-for-woocommerce/');
define('ELYONPAY_WC_PLUGIN_PATH', dirname(__DIR__) . '/');

final class Stubs
{
    public static $options = array();
    public static $transients = array();
    public static $cron = array();
    public static $orders = array();
    public static $notices = array();
    public static $currency = 'XAF';

    public static function reset()
    {
        self::$options = self::$transients = self::$cron = self::$orders = self::$notices = array();
        self::$currency = 'XAF';
    }
}

// --- i18n / escaping / sanitising -------------------------------------------------------------
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function absint($v) { return abs((int) $v); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function wp_unslash($v) { return $v; }
function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
function wc_format_decimal($v) { return (string) (0 + $v); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_create_nonce($action) { return 'nonce'; }
function wp_generate_password($length = 12, $special = true, $extra = false)
{
    return substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz0123456789', 3)), 0, $length);
}
function add_query_arg(array $args, $url)
{
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args);
}

// --- hooks (unused here) ----------------------------------------------------------------------
function add_action() {}
function add_filter() {}

// --- options, transients, cron ----------------------------------------------------------------
function delete_option($name) { unset(Stubs::$options[$name]); return true; }
function update_option($name, $value, $autoload = null) { Stubs::$options[$name] = $value; return true; }
function get_woocommerce_currency() { return Stubs::$currency; }
function get_option($name, $default = false) { return array_key_exists($name, Stubs::$options) ? Stubs::$options[$name] : $default; }
function get_transient($name) { return isset(Stubs::$transients[$name]) ? Stubs::$transients[$name] : false; }
function set_transient($name, $value, $ttl) { Stubs::$transients[$name] = $value; return true; }
function wp_schedule_single_event($ts, $hook, $args) { Stubs::$cron[] = array($ts, $hook, $args); return true; }
function wp_next_scheduled($hook, $args)
{
    foreach (Stubs::$cron as $e) {
        if ($e[1] === $hook && $e[2] === $args) {
            return $e[0];
        }
    }
    return false;
}
function wp_unschedule_event($ts, $hook, $args)
{
    Stubs::$cron = array_values(array_filter(Stubs::$cron, static function ($e) use ($ts, $hook, $args) {
        return !($e[0] === $ts && $e[1] === $hook && $e[2] === $args);
    }));
}

/** Only the three statements the gateway's lock issues. */
final class Fake_WPDB
{
    public $options = 'wp_options';

    public function prepare($query, ...$args)
    {
        $i = 0;
        return preg_replace_callback('/%[sd]/', static function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $query);
    }

    public function query($sql)
    {
        if (preg_match("/^INSERT IGNORE INTO wp_options .* VALUES \\('([^']+)', '([^']+)', 'no'\\)$/", $sql, $m)) {
            if (array_key_exists($m[1], Stubs::$options)) {
                return 0;
            }
            Stubs::$options[$m[1]] = $m[2];
            return 1;
        }
        if (preg_match("/^DELETE FROM wp_options WHERE option_name = '([^']+)' AND option_value < (\\d+)$/", $sql, $m)) {
            if (isset(Stubs::$options[$m[1]]) && (int) Stubs::$options[$m[1]] < (int) $m[2]) {
                unset(Stubs::$options[$m[1]]);
                return 1;
            }
            return 0;
        }
        if (preg_match("/^DELETE FROM wp_options WHERE option_name = '([^']+)'$/", $sql, $m)) {
            $had = isset(Stubs::$options[$m[1]]);
            unset(Stubs::$options[$m[1]]);
            return $had ? 1 : 0;
        }
        throw new LogicException('Unexpected SQL: ' . $sql);
    }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

// --- WooCommerce ------------------------------------------------------------------------------
function WC()
{
    static $wc;
    if (!$wc) {
        $wc = new class {
            public $cart = null;
            public function api_request_url($name) { return 'https://shop.test/?wc-api=' . $name; }
        };
    }
    return $wc;
}
function wc_get_order($id) { return isset(Stubs::$orders[$id]) ? Stubs::$orders[$id] : false; }
function wc_add_notice($message, $type = 'success') { Stubs::$notices[] = array($type, $message); }

class WC_Payment_Gateway
{
    public $id;
    public $enabled = 'no';
    public $title;
    public $description;
    public $method_title;
    public $method_description;
    public $has_fields;
    public $supports = array();
    public $form_fields = array();
    public $settings = array();

    public function init_settings()
    {
        $saved = get_option('woocommerce_' . $this->id . '_settings', array());
        foreach ($this->form_fields as $key => $field) {
            $this->settings[$key] = array_key_exists($key, $saved) ? $saved[$key] : (isset($field['default']) ? $field['default'] : '');
        }
    }

    public function get_option($key, $default = null)
    {
        return isset($this->settings[$key]) && $this->settings[$key] !== '' ? $this->settings[$key] : ($default === null ? '' : $default);
    }

    public function is_available() { return true; }
    public function process_admin_options() { return true; }
    public function get_method_title() { return $this->method_title; }
    public function get_method_description() { return $this->method_description; }
    public function generate_settings_html() {}
}

final class WC_Order
{
    public $id;
    public $status = 'pending';
    public $total;
    public $meta = array();
    public $notes = array();
    public $transaction_id = '';
    public $currency = null;
    public $payment_complete_calls = 0;
    public $billing = array(
        'phone'      => '690000001',
        'country'    => 'CM',
        'first_name' => 'Jean',
        'last_name'  => 'Mballa',
        'email'      => 'jean@example.com',
    );

    public function __construct($id, $total)
    {
        $this->id    = $id;
        $this->total = $total;
        Stubs::$orders[$id] = $this;
    }

    public function get_id() { return $this->id; }
    public function get_order_key() { return 'wc_order_key' . $this->id; }
    public function get_order_number() { return (string) $this->id; }
    public function get_total() { return $this->total; }
    public function get_currency() { return $this->currency === null ? Stubs::$currency : $this->currency; }
    public function get_payment_method() { return 'elyonpay'; }
    public function get_billing_phone() { return $this->billing['phone']; }
    public function get_billing_country() { return $this->billing['country']; }
    public function get_billing_first_name() { return $this->billing['first_name']; }
    public function get_billing_last_name() { return $this->billing['last_name']; }
    public function get_billing_email() { return $this->billing['email']; }
    public function get_status() { return $this->status; }
    public function has_status($status) { return in_array($this->status, (array) $status, true); }
    public function is_paid() { return in_array($this->status, array('processing', 'completed'), true); }
    public function get_meta($key) { return isset($this->meta[$key]) ? $this->meta[$key] : ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function save() {}
    public function save_meta_data() {}
    public function add_order_note($note) { $this->notes[] = $note; }
    public function get_checkout_order_received_url() { return 'https://shop.test/received/' . $this->id; }
    public function get_checkout_payment_url() { return 'https://shop.test/pay/' . $this->id; }

    public function update_status($status, $note = '')
    {
        $this->status = $status;
        if ($note !== '') {
            $this->notes[] = $note;
        }
    }

    public function payment_complete($transaction_id = '')
    {
        $this->payment_complete_calls++;
        $this->transaction_id = $transaction_id;
        $this->status         = 'processing';
        return true;
    }
}
