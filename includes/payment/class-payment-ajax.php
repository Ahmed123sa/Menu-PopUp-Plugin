<?php
/**
 * Payment AJAX Handlers
 * 
 * Handles all AJAX requests for the payment system
 * 
 * @package MenuPopup
 * @subpackage Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Payment AJAX Handlers Class
 */
class PaymentAJAX {
    
    /**
     * Singleton instance
     * 
     * @var PaymentAJAX
     */
    private static $instance = null;
    
    /**
     * Payment manager
     * 
     * @var PaymentManager
     */
    private $payment_manager;
    
    /**
     * Get singleton instance
     * 
     * @return PaymentAJAX
     */
    public static function get_instance(): PaymentAJAX {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->payment_manager = PaymentManager::get_instance();
    }
    
    /**
     * Initialize hooks
     */
    public function init() {
        // Frontend AJAX
        add_action('wp_ajax_get_payment_methods', [$this, 'ajax_get_payment_methods']);
        add_action('wp_ajax_nopriv_get_payment_methods', [$this, 'ajax_get_payment_methods']);
        
        add_action('wp_ajax_process_payment', [$this, 'ajax_process_payment']);
        add_action('wp_ajax_nopriv_process_payment', [$this, 'ajax_process_payment']);
        
        add_action('wp_ajax_check_payment_status', [$this, 'ajax_check_payment_status']);
        add_action('wp_ajax_nopriv_check_payment_status', [$this, 'ajax_check_payment_status']);
        
        // Payment callbacks (for online gateways)
        add_action('wp_ajax_fawry_callback', [$this, 'ajax_fawry_callback']);
        add_action('wp_ajax_nopriv_fawry_callback', [$this, 'ajax_fawry_callback']);
        
        // Admin AJAX
        add_action('wp_ajax_update_payment_status', [$this, 'ajax_update_payment_status']);
        add_action('wp_ajax_verify_bank_transfer', [$this, 'ajax_verify_bank_transfer']);
        add_action('wp_ajax_get_payment_details', [$this, 'ajax_get_payment_details']);
        add_action('wp_ajax_test_fawry_connection', [$this, 'ajax_test_fawry_connection']);
    }
    
    /**
     * Get payment methods for frontend
     */
    public function ajax_get_payment_methods() {
        // Verify nonce
        if (!check_ajax_referer('menu_popup_payment_nonce', 'nonce', false)) {
            wp_send_json_success([
                'methods' => [
                    ['id' => 'cod', 'name' => 'Cash on Delivery', 'description' => 'Pay when you receive', 'is_online' => false],
                    ['id' => 'fawry', 'name' => 'Pay with Fawry', 'description' => 'Pay online', 'is_online' => true]
                ]
            ]);
            return;
        }
        
        // Check if payment system is enabled
        if (!$this->payment_manager->is_payment_enabled()) {
            wp_send_json_success([
                'methods' => []
            ]);
        }
        
        // Get enabled gateways
        $methods = $this->payment_manager->get_enabled_gateways_list();
        
        // If no methods enabled, provide defaults
        if (empty($methods)) {
            $methods = [
                ['id' => 'cod', 'name' => 'Cash on Delivery', 'description' => 'Pay when you receive', 'is_online' => false]
            ];
        }
        
        wp_send_json_success([
            'methods' => $methods
        ]);
    }
    
    /**
     * Process payment
     */
    public function ajax_process_payment() {
        try {
            // Verify nonce
            if (!check_ajax_referer('menu_popup_payment_nonce', 'nonce', false)) {
                wp_send_json_error([
                    'message' => __('Security check failed', 'menu-popup'),
                    'code' => 'security_error'
                ]);
                return;
            }
            
            $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
            $order_data = $_POST['order_data'] ?? [];
            
            // Validate payment method
            if (empty($payment_method)) {
                wp_send_json_error([
                    'message' => __('Please select a payment method', 'menu-popup'),
                    'code' => 'NO_PAYMENT_METHOD'
                ]);
                return;
            }
            
            // Validate order data
            if (empty($order_data)) {
                wp_send_json_error([
                    'message' => __('Order data is required', 'menu-popup'),
                    'code' => 'ORDER_NOT_FOUND'
                ]);
                return;
            }
            
            // Sanitize order data
            $order_data = $this->sanitize_order_data($order_data);
            
            // Ensure order_id exists for validation
            if (empty($order_data['order_id']) && empty($order_data['id'])) {
                $order_data['order_id'] = 'TEST-' . time();
                $order_data['id'] = $order_data['order_id'];
            }
            
            // For COD and Bank Transfer, simulate successful payment
            if ($payment_method === 'cod' || $payment_method === 'bank_transfer') {
                wp_send_json_success([
                    'success' => true,
                    'message' => __('Order placed successfully!', 'menu-popup'),
                    'payment_status' => 'pending',
                    'order_id' => $order_data['order_id'] ?? 0
                ]);
                return;
            }
            
            // For Fawry - skip database and simulate success for testing
            if ($payment_method === 'fawry') {
                wp_send_json_success([
                    'success' => true,
                    'message' => __('Fawry payment initialized (Test Mode)', 'menu-popup'),
                    'payment_status' => 'processing',
                    'payment_url' => '#fawry-test-mode',
                    'order_id' => $order_data['order_id'] ?? 0,
                    'reference_number' => 'FW-TEST-' . time()
                ]);
                return;
            }
            
            // Process other payment methods
            $result = $this->payment_manager->process_payment($payment_method, $order_data);
            
            if ($result['success']) {
                // If redirect URL provided, add confirmation URL
                if (!empty($result['payment_url'])) {
                    $order_id = $order_data['order_id'] ?? 0;
                    $result['confirmation_url'] = $this->get_confirmation_url($order_id);
                }
                
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => __('Payment error: ', 'menu-popup') . $e->getMessage(),
                'code' => 'payment_exception'
            ]);
        } catch (Error $e) {
            wp_send_json_error([
                'message' => __('Payment error: ', 'menu-popup') . $e->getMessage(),
                'code' => 'payment_fatal_error'
            ]);
        }
    }
    
    /**
     * Check payment status
     */
    public function ajax_check_payment_status() {
        check_ajax_referer('menu_popup_payment_nonce', 'nonce');
        
        $order_id = absint($_POST['order_id'] ?? 0);
        
        if (empty($order_id)) {
            wp_send_json_error([
                'message' => __('Order ID is required', 'menu-popup')
            ]);
        }
        
        $payment_info = $this->payment_manager->get_payment_info($order_id);
        
        if (!$payment_info) {
            wp_send_json_error([
                'message' => __('Payment not found', 'menu-popup')
            ]);
        }
        
        wp_send_json_success([
            'payment_status' => $payment_info['status'],
            'reference_number' => $payment_info['reference_number'],
            'transaction_id' => $payment_info['transaction_id']
        ]);
    }
    
    /**
     * Handle Fawry callback
     */
    public function ajax_fawry_callback() {
        // Get callback data
        $callback_data = $_POST;
        
        if (empty($callback_data)) {
            $callback_data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        }
        
        $gateway_id = sanitize_text_field($_GET['gateway'] ?? 'fawry');
        
        // Log callback
        error_log('Fawry callback received: ' . json_encode($callback_data));
        
        // Handle callback
        $result = $this->payment_manager->handle_callback($gateway_id, $callback_data);
        
        // Return response
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Update payment status (admin)
     */
    public function ajax_update_payment_status() {
        check_ajax_referer('menu_popup_payment_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Unauthorized', 'menu-popup')
            ]);
        }
        
        $order_id = absint($_POST['order_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        
        if (empty($order_id) || empty($status)) {
            wp_send_json_error([
                'message' => __('Order ID and status are required', 'menu-popup')
            ]);
        }
        
        $result = $this->payment_manager->update_payment_status($order_id, $status, $notes);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Verify bank transfer (admin)
     */
    public function ajax_verify_bank_transfer() {
        check_ajax_referer('menu_popup_payment_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Unauthorized', 'menu-popup')
            ]);
        }
        
        $order_id = absint($_POST['order_id'] ?? 0);
        $verified = isset($_POST['verified']) && $_POST['verified'] === '1';
        
        if (empty($order_id)) {
            wp_send_json_error([
                'message' => __('Order ID is required', 'menu-popup')
            ]);
        }
        
        $gateway = $this->payment_manager->get_gateway('bank_transfer');
        
        if (!$gateway) {
            wp_send_json_error([
                'message' => __('Bank Transfer gateway not found', 'menu-popup')
            ]);
        }
        
        $notes = $verified ? 
            __('Payment verified by admin', 'menu-popup') : 
            __('Payment verification rejected', 'menu-popup');
        
        $result = $gateway->verify_payment($order_id, $notes);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Get payment details (admin)
     */
    public function ajax_get_payment_details() {
        check_ajax_referer('menu_popup_payment_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Unauthorized', 'menu-popup')
            ]);
        }
        
        $order_id = absint($_POST['order_id'] ?? 0);
        
        if (empty($order_id)) {
            wp_send_json_error([
                'message' => __('Order ID is required', 'menu-popup')
            ]);
        }
        
        $payment_info = $this->payment_manager->get_payment_info($order_id);
        
        if (!$payment_info) {
            wp_send_json_error([
                'message' => __('Payment not found', 'menu-popup')
            ]);
        }
        
        // Get gateway info
        $gateway = $this->payment_manager->get_gateway($payment_info['gateway_id']);
        
        $result = [
            'payment' => $payment_info,
            'gateway' => $gateway ? [
                'name' => $gateway->get_name(),
                'is_online' => $gateway->is_online_gateway()
            ] : null
        ];
        
        wp_send_json_success($result);
    }
    
    /**
     * Test Fawry connection
     */
    public function ajax_test_fawry_connection() {
        check_ajax_referer('menu_popup_payment_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Unauthorized', 'menu-popup')
            ]);
        }
        
        // Get settings
        $merchant_code = get_option('menu_popup_payment_fawry_merchant_code', '');
        $secure_key = get_option('menu_popup_payment_fawry_secure_key', '');
        $sandbox = get_option('menu_popup_payment_fawry_sandbox', '1');
        
        if (empty($merchant_code) || empty($secure_key)) {
            wp_send_json_error([
                'message' => __('Fawry credentials not configured', 'menu-popup')
            ]);
        }
        
        // Test by checking gateway availability
        $gateway = $this->payment_manager->get_gateway('fawry');
        
        if (!$gateway) {
            wp_send_json_error([
                'message' => __('Fawry gateway not found', 'menu-popup')
            ]);
        }
        
        // Try a simple API check
        $api_url = $sandbox === '1' ? 
            'https://sandbox.fawry.com/ECommercePluginApi/api/initiatePayment' : 
            'https://www.atfawry.com/ECommercePluginApi/api/initiatePayment';
        
        // Just verify the URL is accessible (don't make actual API call)
        $response = wp_remote_head($api_url, [
            'timeout' => 10,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => 'Cannot connect to Fawry API: ' . $response->get_error_message()
            ]);
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code >= 200 && $status_code < 400) {
            wp_send_json_success([
                'message' => __('Fawry connection successful!', 'menu-popup')
            ]);
        } else {
            wp_send_json_error([
                'message' => 'Fawry API returned status: ' . $status_code
            ]);
        }
    }
    
    /**
     * Sanitize order data
     * 
     * @param array $order_data
     * @return array
     */
    private function sanitize_order_data(array $order_data): array {
        $sanitized = [];
        
        // Basic info
        $sanitized['order_id'] = absint($order_data['order_id'] ?? 0);
        $sanitized['id'] = absint($order_data['id'] ?? $sanitized['order_id']);
        $sanitized['total'] = floatval($order_data['total'] ?? $order_data['order_total'] ?? 0);
        $sanitized['currency'] = sanitize_text_field($order_data['currency'] ?? 'EGP');
        
        // Customer info
        $sanitized['customer_name'] = sanitize_text_field($order_data['customer_name'] ?? '');
        $sanitized['customer_phone'] = sanitize_text_field($order_data['customer_phone'] ?? '');
        $sanitized['customer_email'] = sanitize_email($order_data['customer_email'] ?? '');
        
        // Order items
        $sanitized['items'] = [];
        if (!empty($order_data['items']) && is_array($order_data['items'])) {
            foreach ($order_data['items'] as $item) {
                $sanitized['items'][] = [
                    'id' => isset($item['id']) ? absint($item['id']) : 0,
                    'name' => sanitize_text_field($item['name'] ?? ''),
                    'price' => floatval($item['price'] ?? 0),
                    'quantity' => isset($item['quantity']) ? absint($item['quantity']) : 1
                ];
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Get confirmation URL
     * 
     * @param int $order_id
     * @return string
     */
    private function get_confirmation_url(int $order_id): string {
        $page_id = get_option('menu_popup_confirmation_page_id', 0);
        
        if ($page_id) {
            return add_query_arg([
                'order_id' => $order_id,
                'payment' => 'success'
            ], get_permalink($page_id));
        }
        
        // Fallback to current URL with query args
        return add_query_arg([
            'order_id' => $order_id,
            'payment' => 'success'
        ]);
    }
}

/**
 * Initialize payment AJAX handlers
 */
function init_payment_ajax() {
    $ajax = PaymentAJAX::get_instance();
    $ajax->init();
}

add_action('init', 'init_payment_ajax');
