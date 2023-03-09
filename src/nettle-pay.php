<?php

/**
 *
 * @link              https://nettlelabs.com
 * @since             1.0.0
 * @package           Nettle_Pay
 *
 * @wordpress-plugin
 * Plugin Name:       Nettle Pay
 * Plugin URI:        https://nettlelabs.com/pay
 * Description:       Nettle makes it easy to take crypto payments globally, reward customers and reduce your CO2e emissions, all in one place.
 * Version:           2.2.0
 * Author:            Nettle Labs Ltd
 * Author URI:        https://nettlelabs.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       nettle-pay
 */

add_action('plugins_loaded', 'nettle_pay_init');

function nettle_pay_init() {
    if (class_exists('WC_Payment_Gateway')) {
        /**
         * The core plugin class.
         *
         * This is used to define admin-specific hooks, and
         * public-facing site hooks.
         *
         * Also maintains the unique identifier of this plugin as well as the current
         * version of the plugin.
         *
         * @since      1.0.0
         * @package    Nettle_Pay
         * @author     Nettle Labs Ltd <tech@nettlelabs.com>
         */
        class WC_Nettle_Pay_Gateway extends WC_Payment_Gateway {
            /** @var bool Whether or not logging is enabled */
            public static $log_enabled = false;

            /** @var WC_Logger Logger instance */
            public static $log = false;

            /**
             * Constructor for the gateway.
             */
            public function __construct() {
                $this->id = 'nettle_pay';
                $this->icon = apply_filters('woocommerce_nettle_pay_icon', plugin_dir_url(__FILE__) . 'assets/images/nettle_icon-64x64.png');
                $this->has_fields = false;
                $this->method_title = __('Nettle Pay', 'nettle_pay'); // admin title
                $this->method_description = '<p>' . __('Accept cryptocurrency, reward customers and reduce your CO2e emissions.', 'nettle_pay') . '</p>';  // admin description
                $this->title = __('Nettle Pay', 'nettle_pay'); // title the customer sees at checkout
                $this->description = __('Pay with cryptocurrency, get rewarded and reduce your CO2e emissions', 'nettle_pay'); // description the customer sees at checkout

                // load the settings
                $this->init_form_fields();
                $this->init_settings();

                // define the admin set variables
                $this->logging = 'yes' === $this->get_option('logging', 'no');
                $this->order_button_text = $this->get_option('order_button_text');

                self::$log_enabled = $this->logging;

                // Actions
                if(is_admin()) {
                    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                }

                add_action('woocommerce_api_nettle_pay_webhook', array($this, 'check_ipn_response'));
                add_action('woocommerce_admin_order_data_after_order_details', array($this, 'update_order_extra_details'));
            }

            /**
             * Private functions
             */

            /**
             * Convenience function to convert an atomic unit to a standard unit.
             * @param int $atomic_amount The atomic amount
             * @param int $decimal The decimal to use in the conversion
             * @return float|int the supplied atomic unit as a standard unit
             */
            private function calculateStandardUnit($atomic_amount, $decimal) {
                $power = pow(10, $decimal);

                return $atomic_amount / $power;
            }

            /**
             * Creates an url for the transaction.
             * @param string $chain The chain for the transaction.
             * @param string $transaction_id The transaction ID.
             * @return string|null the url for the transaction or null
             */
            private function create_chain_transaction_url($chain, $transaction_id) {
                switch ($chain) {
                    case 'AlgorandMainNet':
                        return 'https://algoexplorer.io/tx/' . $transaction_id;
                    case 'AlgorandTestNet':
                        return 'https://testnet.algoexplorer.io/tx/' . $transaction_id;
                    case 'EthereumGoreli':
                        return 'https://goerli.etherscan.io/tx/' . $transaction_id;
                    case 'EthereumMainNet':
                        return 'https://etherscan.io/tx/' . $transaction_id;
                    default:
                        return null;
                }
            }

            /**
             * Updates the meta, if it exists, otherwise it adds it.
             * @param int $order_id The order the meta is stored.
             * @param string $key The key for the meta
             * @param mixed $value The value to add/update
             * @return void
             */
            private function upsert_order_item_meta($order_id, $key, $value) {
                $existing_value = wc_get_order_item_meta($order_id, $key);

                if ($existing_value != null) {
                    wc_update_order_item_meta($order_id, $key, $value);

                    return;
                }

                wc_add_order_item_meta($order_id, $key, $value);
            }

            /**
             * Protected functions
             */

            /**
             * Init the Nettle API class.
             */
            protected function init_api() {
                include_once dirname(__FILE__) . '/includes/nettle_api_handler.php';

                Nettle_API_Handler::$api_url = $this->get_option('api_url') ? $this->get_option('api_url') : 'https://api.nettlelabs.com';
                Nettle_API_Handler::$api_key_id = $this->get_option('api_key_id');
                Nettle_API_Handler::$api_key_secret = $this->get_option('api_key_secret');
                Nettle_API_Handler::$log = get_class($this) . '::log';
            }

            /**
             * Public functions
             */

            /**
             * Handle requests sent to webhook.
             */
            public function check_ipn_response() {
                $chain = isset($_REQUEST['chain']) ? sanitize_text_field($_REQUEST['chain']) : null;
                $chain_transaction_id = isset($_REQUEST['chain_transaction_id']) ? sanitize_text_field($_REQUEST['chain_transaction_id']) : null;
                $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field($_REQUEST['nonce']) : null;
                $order_id = isset($_REQUEST['order_id']) ? sanitize_title($_REQUEST['order_id']) : null;
                $token_amount = isset($_REQUEST['token_amount']) ? sanitize_title($_REQUEST['token_amount']) : null;
                $token_code = isset($_REQUEST['token_code']) ? sanitize_text_field($_REQUEST['token_code']) : null;
                $token_decimal = isset($_REQUEST['token_decimal']) ? sanitize_title($_REQUEST['token_decimal']) : null;
                $transaction_id = isset($_REQUEST['transaction_id']) ? sanitize_text_field($_REQUEST['transaction_id']) : null;

                if (is_null($order_id)) {
                    self::log('[INFO] check_ipn_response(): no order id found');
                    return;
                }

                if (is_null($nonce)) {
                    self::log('[INFO] check_ipn_response(): no nonce found');
                    return;
                }

                // check the nonce in the order metadata matches the incoming nonce
                if (wc_get_order_item_meta($order_id, 'ipn_nonce') != $nonce) {
                    self::log('[INFO] check_ipn_response(): the incoming nonce does not match the nonce of the order');
                    return;
                }

                $order = wc_get_order($order_id);

                // add/update the order with the transaction data
                self::upsert_order_item_meta($order_id, 'chain', $chain);
                self::upsert_order_item_meta($order_id, 'chain_transaction_id', $chain_transaction_id);
                self::upsert_order_item_meta($order_id, 'token_amount', $token_amount);
                self::upsert_order_item_meta($order_id, 'token_code', $token_code);
                self::upsert_order_item_meta($order_id, 'token_decimal', $token_decimal);
                self::upsert_order_item_meta($order_id, 'transaction_id', $transaction_id);

                // complete order
                $order->payment_complete();
                $order->reduce_order_stock();

                update_option('webhook_debug', $_REQUEST);

                self::log('[INFO] check_ipn_response(): updating order "' . $order_id . '" details');

                do_action('woocommerce_admin_order_data_after_order_details', $order);
            }

            /**
             * Updates the order details with extra information.
             * @param WC_Order $order Order object.
             * @return void
             */
            public function update_order_extra_details($order) {
                $amount = self::calculateStandardUnit((int)wc_get_order_item_meta($order->id, 'token_amount'), (int)wc_get_order_item_meta($order->id, 'token_decimal'));
                $chain_transaction_id = wc_get_order_item_meta($order->id, 'chain_transaction_id');
                $chain_transaction_url = null;

                if ($chain_transaction_id != null) {
                    $chain_transaction_url = self::create_chain_transaction_url(wc_get_order_item_meta($order->id, 'chain'), $chain_transaction_id);
                }

                ?>
                <div class="form-field form-field-wide">
                <?php
                    echo '<p><strong>' . __( 'Nettle Pay Amount' ) . ':</strong> ' . $amount . ' ' . wc_get_order_item_meta($order->id, 'token_code')  . '</p>';
                    echo '<p><strong>' . __( 'Nettle Pay Transaction ID' ) . ':</strong> ' . wc_get_order_item_meta($order->id, 'transaction_id') . '</p>';

                    if ($chain_transaction_url != null) {
                        echo '<p><strong>' . __( 'Chain Transaction ID' ) . ':</strong> ' . '<a href="' . $chain_transaction_url . '" target="_blank">' . $chain_transaction_id . '</a>' . '</p>';
                    }
                ?>
                </div>
                <?php
            }

            /**
             * Get the cancel url.
             * @param WC_Order $order Order object.
             * @return string
             */
            public function get_cancel_url($order) {
                $cancel_url = $order->get_cancel_order_url();

                if (is_ssl() || get_option('woocommerce_force_ssl_checkout') == 'yes')  {
                    $cancel_url = str_replace('http:', 'https:', $cancel_url);
                }

                return apply_filters('woocommerce_get_cancel_url', $cancel_url, $order);
            }

            /**
             * Initialise Gateway Settings Form Fields.
             */
            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'nettle_pay') ,
                        'type' => 'checkbox',
                        'label' => __('Enable Nettle Pay', 'nettle_pay') ,
                        'default' => 'yes',
                    ),
                    'order_button_text' => array(
                        'title' => __('Button Text', 'nettle_pay') ,
                        'type' => 'text',
                        'description' => __('This controls what the text the customer sees on the pay button.', 'nettle_pay') ,
                        'default' => __('Pay with Nettle', 'nettle_pay') ,
                        'desc_tip' => true,
                    ),
                    'api_url' => array(
                        'title' => __('API URL', 'nettle_pay') ,
                        'type' => 'text',
                        'default' => 'https://api.nettlelabs.com',
                        'desc_tip' => true,
                        'description' => __('Changes where requests are sent. You should not have to change this.', 'nettle_pay'),
                    ),
                    'api_key_id' => array(
                        'title' => __('API Key ID', 'nettle_pay') ,
                        'type' => 'text',
                        'default' => '',
                        'desc_tip' => true,
                        'description' => __('Your API key ID. Used to authenticate requests to the Nettle API.', 'nettle_pay'),
                    ),
                    'api_key_secret' => array(
                        'title' => __('API Key Secret', 'nettle_pay') ,
                        'type' => 'password',
                        'default' => '',
                        'desc_tip' => true,
                        'description' => __('Your API key secret. Used to authenticate requests to the Nettle API.', 'nettle_pay'),
                    ),
                    'logging' => array(
                        'title' => __('Enable/Disable Logging', 'nettle_pay') ,
                        'type' => 'checkbox',
                        'label' => __('Enable Logging', 'nettle_pay') ,
                        'default' => 'no',
                        'description' => sprintf(__('Logs events inside %s', 'nettle_pay') , '<code>' . WC_Log_Handler_File::get_log_file_path('nettle_pay_log') . '</code>') ,
                    ),
                );
            }

            /**
             * Logging method.
             *
             * @param string $message Log message.
             * @param string $level   Optional. Default 'info'.
             *     emergency|alert|critical|error|warning|notice|info|debug
             */
            public static function log($message, $level = 'info') {
                if (self::$log_enabled) {
                    if (empty(self::$log)) {
                        self::$log = wc_get_logger();
                    }

                    self::$log->log($level, $message, array(
                        'source' => 'nettle_pay_log'
                    ));
                }
            }

            /**
             * Process the payment and return the result.
             * @param  int $order_id
             * @return array
             */
            public function process_payment($order_id) {
                global $woocommerce;

                self::log('[INFO] started process_payment() with order id: "' . $order_id . '"...');

                if (empty($order_id)) {
                    self::log('[ERROR] process_payment(): order id is missing');
                    throw new \Exception('Order ID is missing, validation failed.');
                }

                $order = wc_get_order($order_id);

                if (false === $order) {
                    self::log('[ERROR] process_payment(): failed to get order details for order id "' . $order_id . '"');
                    throw new \Exception('Unable to retrieve the order details for order ID "' . $order_id . '", Unable to proceed.');
                }

                // create a nonce to use on the callback url
                $nonce = substr(str_shuffle(md5(microtime())), 0, 32);

                wc_add_order_item_meta($order_id, 'ipn_nonce', $nonce);

                $this->init_api();

                self::log('[INFO] process_payment(): getting currency details for "' . get_woocommerce_currency() . '"...');

                $response = Nettle_API_Handler::findCurrencyByCode(get_woocommerce_currency());

                if (is_null($response)) {
                    self::log('[ERROR] process_payment(): unable to find currency "' . get_woocommerce_currency() . '"');
                    throw new \Exception('Currency "' . get_woocommerce_currency() . '" not supported.');
                }

                self::log('[INFO] process_payment(): got currency response: ' . print_r($response, true));

                $requestParams = array(
                    "cancelUrl" => $this->get_cancel_url($order),
                    "payment" => array(
                        "atomicAmount" => $order->get_total() * pow(10, $response['decimal']),
                        "baseCurrencyCode" => $response['code'],
                    ),
                    "successUrl" => $this->get_return_url($order),
                    "wordPressOrder" => array(
                        "nonce" => (string) $nonce,
                        "webhookUrl" => get_bloginfo('url'),
                        "wpOrderId" => (string) $order_id,
                    ),
                );

                self::log('[INFO] process_payment(): attempting to generate payment for order id "' . $order_id . '"...');

                $response = Nettle_API_Handler::createPayment($requestParams);

                self::log('[INFO] process_payment(): got payment response: ' . print_r($response, true));

                if (empty($response['url'])) {
                    return array(
                        'result' => 'fail',
                    );
                }

                return array(
                    'result' => 'success',
                    'redirect' => $response['url'],
                );
            }
        }
    }
    else {
        global $wpdb;

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins_url = admin_url('plugins.php');

        $plugins = get_plugins();

        foreach ($plugins as $file => $plugin) {
            if ('Nettle Pay' === $plugin['Name'] && true === is_plugin_active($file)) {
                deactivate_plugins(plugin_basename(__FILE__));
                wp_die('WooCommerce needs to be installed and activated before Nettle Pay can be activated.<br><a href="' . $plugins_url . '">Return to plugins screen</a>');
            }
        }
    }
}

/**
 * Add the Nettle Pay gateway to the WooCommerce gateways
 */
add_filter('woocommerce_payment_gateways', 'add_nettle_pay_gateway');
function add_nettle_pay_gateway($gateways) {
    $gateways[] = 'WC_Nettle_Pay_Gateway';
    return $gateways;
}
