<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class MMG_Checkout_Payment {
    private $client_id;
    private $merchant_id;
    private $secret_key;
    private $rsa_public_key;
    private $mode;
    private $live_checkout_url = 'https://gtt-checkout.qpass.com:8743/checkout-endpoint/home';
    private $demo_checkout_url = 'https://gtt-uat-checkout.qpass.com:8743/checkout-endpoint/home';
    private $callback_url;

    public function __construct() {
        // Initialize plugin
        $this->mode = get_option('mmg_mode', 'demo'); // Default mode set to 'demo'
        
        // Generate or retrieve unique callback URL
        $this->callback_url = $this->generate_unique_callback_url();
        
        // Load settings
        require_once dirname(__FILE__) . '/class-mmg-settings.php';
        new MMG_Checkout_Settings();

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_generate_checkout_url', array($this, 'generate_checkout_url'));
        add_action('wp_ajax_nopriv_generate_checkout_url', array($this, 'generate_checkout_url'));
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
        add_action('plugins_loaded', array($this, 'init_gateway_class'), 11);
        add_action('parse_request', array($this, 'parse_api_request'));
    }

    private function generate_unique_callback_url() {
        $callback_key = get_option('mmg_callback_key');
        if (!$callback_key) {
            $callback_key = wp_generate_password(32, false);
            update_option('mmg_callback_key', $callback_key);
        }
        return home_url("wc-api/mmg-checkout/{$callback_key}");
    }

    public function enqueue_scripts() {
        if (is_checkout_pay_page()) {
            wp_enqueue_script('mmg-checkout', plugin_dir_url(dirname(__FILE__)) . 'js/mmg-checkout.js', array('jquery'), '1.0', true);
            wp_localize_script('mmg-checkout', 'mmg_checkout_params', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mmg_checkout_nonce'),
            ));
            error_log('MMG Checkout: Script enqueued on checkout pay page');
        } else {
            error_log('MMG Checkout: Not on checkout pay page, script not enqueued');
        }
    
        // For blocks support
        $gateway_settings = get_option('woocommerce_mmg_checkout_settings', array());
        wp_localize_script('wc-mmg-payments-blocks', 'mmgCheckoutData', array(
            'title' => isset($gateway_settings['title']) ? $gateway_settings['title'] : 'MMG Checkout',
            'description' => isset($gateway_settings['description']) ? $gateway_settings['description'] : 'Pay with MMG Checkout',
            'supports' => array('products', 'refunds'),
            'isEnabled' => isset($gateway_settings['enabled']) ? $gateway_settings['enabled'] : 'no',
        ));
    }

    public function generate_checkout_url() {
        try {
            if (!$this->validate_public_key()) {
                throw new Exception('Invalid RSA public key');
            }

            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            $order = wc_get_order($order_id);

            if (!$order) {
                error_log('MMG Checkout Error: Invalid order in generate_checkout_url. Order ID: ' . $order_id);
                wp_send_json_error('Invalid order');
            }

            error_log('MMG Checkout: Generating checkout URL for Order ID: ' . $order->get_id());

            $amount = $order->get_total();
            $description = 'Order #' . $order->get_order_number();

            $token_data = array(
                'secretKey' => get_option('mmg_secret_key'),
                'amount' => $amount,
                'merchantId' => get_option('mmg_merchant_id'),
                'merchantTransactionId' => $order->get_id(), // Use order ID instead of order number
                'productDescription' => $description,
                'requestInitiationTime' => (string) round(microtime(true) * 1000),
                'merchantName' => get_option('mmg_merchant_name', get_bloginfo('name')),
            );

            error_log('MMG Checkout: Token data merchantTransactionId: ' . $token_data['merchantTransactionId']);

            $encrypted = $this->encrypt($token_data);
            $encoded = $this->url_safe_base64_encode($encrypted);
            $checkout_url = add_query_arg(array(
                'token' => $encoded,
                'merchantId' => get_option('mmg_merchant_id'),
                'X-Client-ID' => get_option('mmg_client_id'),
            ), $this->get_checkout_url());

            $order->update_meta_data('_mmg_transaction_id', $token_data['merchantTransactionId']);
            $order->save();

            wp_send_json_success(array('checkout_url' => $checkout_url));
        } catch (Exception $e) {
            wp_send_json_error('Error generating checkout URL: ' . esc_html($e->getMessage()));
        }
    }

    private function get_checkout_url() {
        return $this->mode === 'live' ? $this->live_checkout_url : $this->demo_checkout_url;
    }

    private function encrypt($checkout_object) {
        $json_object = wp_json_encode($checkout_object, JSON_PRETTY_PRINT);
        error_log("Checkout Object:\n $json_object\n");

        // message to bytes
        if (function_exists('mb_convert_encoding')) {
            $json_bytes = mb_convert_encoding($json_object, 'ISO-8859-1', 'UTF-8');
        } else {
            // Fallback method
            $json_bytes = utf8_decode($json_object);
        }
        
        // Load the public key
        try {
            $public_key = \phpseclib3\Crypt\PublicKeyLoader::load(get_option('mmg_rsa_public_key'));
        } catch (Exception $e) {
            error_log('Error loading public key: ' . $e->getMessage());
            throw new Exception('Failed to load RSA public key');
        }
        
        // Configure RSA encryption
        $rsa = $public_key->withPadding(\phpseclib3\Crypt\RSA::ENCRYPTION_OAEP)
                          ->withHash('sha256')
                          ->withMGFHash('sha256');
        
        // Encrypt the data
        try {
            $ciphertext = $rsa->encrypt($json_bytes);
        } catch (Exception $e) {
            error_log('Error during encryption: ' . $e->getMessage());
            throw new Exception('Failed to encrypt data');
        }
        
        return $ciphertext;
    }

    private function url_safe_base64_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function validate_public_key() {
        $public_key = get_option('mmg_rsa_public_key');
        if (!$public_key) {
            error_log('MMG Checkout Error: RSA public key is missing');
            return false;
        }
        
        $key_resource = openssl_pkey_get_public($public_key);
        if (!$key_resource) {
            error_log('MMG Checkout Error: Invalid RSA public key');
            return false;
        }
        
        return true;
    }

    public function add_gateway_class($gateways) {
        $gateways[] = 'WC_MMG_Gateway';
        return $gateways;
    }

    public function init_gateway_class() {
        if (class_exists('WC_Payment_Gateway')) {
            require_once dirname(__FILE__) . '/class-wc-mmg-gateway.php';
        }
    }

    private function decrypt($encrypted_data) {
        // Load the private key
        try {
            $private_key = \phpseclib3\Crypt\PublicKeyLoader::load(get_option('mmg_rsa_private_key'));
        } catch (Exception $e) {
            error_log('Error loading private key: ' . $e->getMessage());
            throw new Exception('Failed to load RSA private key');
        }
        
        // Configure RSA decryption
        $rsa = $private_key->withPadding(\phpseclib3\Crypt\RSA::ENCRYPTION_OAEP)
                           ->withHash('sha256')
                           ->withMGFHash('sha256');
        
        // Decrypt the data
        try {
            $decrypted = $rsa->decrypt($encrypted_data);
        } catch (Exception $e) {
            error_log('Error during decryption: ' . $e->getMessage());
            throw new Exception('Failed to decrypt data');
        }
        
        $decoded = json_decode($decrypted, true);

        if (!is_array($decoded)) {
            error_log('MMG Checkout Error: Decrypted data is not a valid JSON array');
            throw new Exception('Decrypted data is not a valid JSON array');
        }

        return $decoded;
    }

    private function url_safe_base64_decode($data) {
        // Validate input
        if (!is_string($data)) {
            throw new InvalidArgumentException('Input must be a string');
        }

        // Replace URL-safe characters
        $base64 = strtr($data, '-_', '+/');

        // Add padding if necessary
        $base64 = str_pad($base64, strlen($base64) % 4, '=', STR_PAD_RIGHT);

        // Decode with strict mode
        $decoded = base64_decode($base64, true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid base64 encoding');
        }

        return $decoded;
    }

    public function parse_api_request() {
        global $wp;
        if (isset($wp->query_vars['mmg-checkout'])) {
            $this->handle_payment_confirmation();
            exit;
        }
    }

    public function handle_payment_confirmation() {
        // Get the callback key from the URL
        $parsed_url = wp_parse_url($_SERVER['REQUEST_URI']);
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $uri_parts = explode('/', trim($path, '/'));

        // Find the index of 'mmg-checkout' and get the next part as the callback key
        $mmg_checkout_index = array_search('mmg-checkout', $uri_parts);
        $callback_key = '';

        if ($mmg_checkout_index !== false && isset($uri_parts[$mmg_checkout_index + 1])) {
            // Sanitize the callback key
            $raw_callback_key = $uri_parts[$mmg_checkout_index + 1];
            
            // Only allow alphanumeric characters and dashes
            $callback_key = preg_replace('/[^a-zA-Z0-9-]/', '', $raw_callback_key);
            
            // Ensure the callback key is not empty and has a reasonable length
            if (empty($callback_key) || strlen($callback_key) > 64) {
                wp_die('Invalid callback key', 'MMG Checkout Error', array('response' => 400));
            }
        }

        $stored_callback_key = get_option('mmg_callback_key');
        
        if (empty($callback_key)) {
            wp_die('Missing callback key', 'MMG Checkout Error', array('response' => 400));
        }

        if ($callback_key !== $stored_callback_key) {
            wp_die('Invalid callback key', 'MMG Checkout Error', array('response' => 403));
        }

        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

        if (empty($token)) {
            wp_die('Invalid token', 'MMG Checkout Error', array('response' => 400));
        }

        try {
            $decoded_token = $this->url_safe_base64_decode($token);
            $payment_data = $this->decrypt($decoded_token);
            error_log('MMG Checkout: Decoded token: ' . print_r($payment_data, true));
        } catch (Exception $e) {
            wp_die('Error decrypting token: ' . esc_html($e->getMessage()), 'MMG Checkout Error', array('response' => 400));
        }

        $order_id = isset($payment_data['merchantTransactionId']) ? intval($payment_data['merchantTransactionId']) : 0;
        $result_code = isset($payment_data['resultCode']) ? intval($payment_data['resultCode']) : null;
        $result_message = isset($payment_data['resultMessage']) ? sanitize_text_field($payment_data['resultMessage']) : '';

        $order = wc_get_order($order_id);

        if (!$order) {
            wp_die('Invalid order', 'MMG Checkout Error', array('response' => 400));
        }

        $payment_verified = ($result_code === 0); // 0 indicates a successful transaction

        if ($payment_verified) {
            $order->payment_complete();
            $order->add_order_note("Payment completed via MMG Checkout. Transaction ID: {$payment_data['transactionId']}");
            wp_redirect($order->get_checkout_order_received_url());
        } else {
            $status_messages = array(
                1 => array('status' => 'failed', 'message' => 'Agent Not Registered.'),
                2 => array('status' => 'failed', 'message' => 'Payment Failed.'),
                3 => array('status' => 'failed', 'message' => 'Invalid Secret Key.'),
                4 => array('status' => 'failed', 'message' => 'Merchant ID Mismatch.'),
                5 => array('status' => 'failed', 'message' => 'Token Decryption Failed.'),
                6 => array('status' => 'cancelled', 'message' => 'Payment cancelled by user.'),
                7 => array('status' => 'failed', 'message' => 'Request Timed Out.'),
            );

            if (isset($status_messages[$result_code])) {
                $order->update_status($status_messages[$result_code]['status'], "Payment failed. Reason: {$status_messages[$result_code]['message']}");
            } else {
                $order->update_status('failed', "Payment failed. Result Code: {$result_code}, Message: {$result_message}");
            }

            wp_redirect($order->get_checkout_payment_url());
        }
        exit;
    }
}
?>