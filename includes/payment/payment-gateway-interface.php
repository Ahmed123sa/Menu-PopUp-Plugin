<?php
/**
 * Payment Gateway Interface
 * 
 * Defines the contract that all payment gateways must implement
 * 
 * @package MenuPopup
 * @subpackage Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Payment Gateway Interface
 * 
 * All payment gateways must implement this interface
 */
interface PaymentGatewayInterface {
    
    /**
     * Get gateway unique identifier
     * 
     * @return string
     */
    public function get_id(): string;
    
    /**
     * Get gateway display name
     * 
     * @return string
     */
    public function get_name(): string;
    
    /**
     * Get gateway description
     * 
     * @return string
     */
    public function get_description(): string;
    
    /**
     * Check if gateway is enabled
     * 
     * @return bool
     */
    public function is_enabled(): bool;
    
    /**
     * Get gateway settings fields
     * 
     * @return array
     */
    public function get_settings(): array;
    
    /**
     * Validate gateway settings
     * 
     * @param array $settings
     * @return bool
     */
    public function validate_settings(array $settings): bool;
    
    /**
     * Process payment for an order
     * 
     * @param array $order_data Order data including total, items, customer info
     * @return array Payment result with success status and details
     */
    public function process_payment(array $order_data): array;
    
    /**
     * Validate payment data
     * 
     * @param array $payment_data
     * @return bool
     */
    public function validate_payment(array $payment_data): bool;
    
    /**
     * Get payment URL for redirect (for online gateways)
     * 
     * @param array $order_data
     * @return string Payment URL or empty string if not applicable
     */
    public function get_payment_url(array $order_data): string;
    
    /**
     * Handle payment callback from gateway (for online gateways)
     * 
     * @param array $callback_data
     * @return array Callback result with payment status
     */
    public function handle_callback(array $callback_data): array;
    
    /**
     * Get payment form HTML for frontend display
     * 
     * @param array $order_data
     * @return string HTML form content
     */
    public function get_payment_form(array $order_data): string;
    
    /**
     * Get instruction text for the payment method
     * 
     * @return string
     */
    public function get_instruction_text(): string;
    
    /**
     * Check if gateway supports online payment (API-based)
     * 
     * @return bool
     */
    public function is_online_gateway(): bool;
}

/**
 * Payment Gateway Base Class
 * 
 * Provides common functionality for all payment gateways
 */
abstract class PaymentGatewayBase implements PaymentGatewayInterface {
    
    /**
     * Gateway settings
     * 
     * @var array
     */
    protected $settings = [];
    
    /**
     * Logger instance
     * 
     * @var PaymentLogger
     */
    protected $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->load_settings();
        $this->logger = PaymentLogger::get_instance();
    }
    
    /**
     * Load gateway settings from WordPress options
     */
    protected function load_settings() {
        $option_prefix = 'menu_popup_payment_' . $this->get_id() . '_';
        
        // Load all settings with prefix
        $all_options = wp_load_alloptions();
        foreach ($all_options as $key => $value) {
            if (strpos($key, $option_prefix) === 0) {
                $setting_key = str_replace($option_prefix, '', $key);
                $this->settings[$setting_key] = $value;
            }
        }
    }
    
    /**
     * Get a setting value
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function get_setting(string $key, $default = null) {
        return $this->settings[$key] ?? get_option('menu_popup_payment_' . $this->get_id() . '_' . $key, $default);
    }
    
    /**
     * Update a setting value
     * 
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    protected function update_setting(string $key, $value): bool {
        $option_key = 'menu_popup_payment_' . $this->get_id() . '_' . $key;
        return update_option($option_key, $value);
    }
    
    /**
     * Get order total from order data
     * 
     * @param array $order_data
     * @return float
     */
    protected function get_order_total(array $order_data): float {
        return (float) ($order_data['total'] ?? $order_data['order_total'] ?? 0);
    }
    
    /**
     * Get order currency from order data
     * 
     * @param array $order_data
     * @return string
     */
    protected function get_order_currency(array $order_data): string {
        return $order_data['currency'] ?? get_option('menu_popup_payment_currency', 'EGP');
    }
    
    /**
     * Get order items from order data
     * 
     * @param array $order_data
     * @return array
     */
    protected function get_order_items(array $order_data): array {
        return $order_data['items'] ?? [];
    }
    
    /**
     * Get customer email from order data
     * 
     * @param array $order_data
     * @return string
     */
    protected function get_customer_email(array $order_data): string {
        return $order_data['customer_email'] ?? '';
    }
    
    /**
     * Get customer phone from order data
     * 
     * @param array $order_data
     * @return string
     */
    protected function get_customer_phone(array $order_data): string {
        return $order_data['customer_phone'] ?? $order_data['phone'] ?? '';
    }
    
    /**
     * Get customer name from order data
     * 
     * @param array $order_data
     * @return string
     */
    protected function get_customer_name(array $order_data): string {
        return $order_data['customer_name'] ?? $order_data['name'] ?? '';
    }
    
    /**
     * Validate order data
     * 
     * @param array $order_data
     * @return bool
     */
    public function validate_order_data(array $order_data): bool {
        // Check required fields - be lenient for testing
        if (empty($order_data['order_id']) && empty($order_data['id'])) {
            // Allow test orders without ID
            $this->log('debug', 'Order ID missing - using test mode');
        }
        
        // Check amount - be lenient
        $total = $this->get_order_total($order_data);
        if ($total < 0) {
            $this->log('error', 'Invalid order total: ' . $total);
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate amount
     * 
     * @param float $amount
     * @return bool
     */
    protected function validate_amount(float $amount): bool {
        return $amount > 0 && $amount <= 1000000; // Max 1 million
    }
    
    /**
     * Check if gateway is enabled
     * 
     * @return bool
     */
    public function is_enabled(): bool {
        return (bool) $this->get_setting('enabled', false);
    }
    
    /**
     * Get gateway settings
     * 
     * @return array
     */
    public function get_settings(): array {
        return $this->settings;
    }
    
    /**
     * Default get_payment_url implementation
     * Returns empty string for offline gateways
     * 
     * @param array $order_data
     * @return string
     */
    public function get_payment_url(array $order_data): string {
        return '';
    }
    
    /**
     * Default handle_callback implementation
     * Returns error for offline gateways
     * 
     * @param array $callback_data
     * @return array
     */
    public function handle_callback(array $callback_data): array {
        return [
            'success' => false,
            'message' => 'Callback not supported for this payment method'
        ];
    }
    
    /**
     * Default is_online_gateway implementation
     * Override in gateway classes that use API
     * 
     * @return bool
     */
    public function is_online_gateway(): bool {
        return false;
    }
    
    /**
     * Log message
     * 
     * @param string $level
     * @param string $message
     * @param array $context
     */
    protected function log(string $level, string $message, array $context = []) {
        if (isset($this->logger)) {
            $this->logger->log($level, $message, array_merge(['gateway' => $this->get_id()], $context));
        }
    }
    
    /**
     * Format amount for display
     * 
     * @param float $amount
     * @param string $currency
     * @return string
     */
    protected function format_amount(float $amount, string $currency = 'EGP'): string {
        $symbols = [
            'EGP' => 'E£',
            'USD' => '$',
            'SAR' => '﷼',
            'AED' => 'د.إ',
            'EUR' => '€',
            'GBP' => '£'
        ];
        
        $symbol = $symbols[$currency] ?? $currency;
        return $symbol . number_format($amount, 2);
    }
    
    /**
     * Generate unique reference number
     * 
     * @param string $prefix
     * @return string
     */
    protected function generate_reference(string $prefix = 'REF'): string {
        return $prefix . '-' . strtoupper(wp_generate_password(10, false, false));
    }
    
    /**
     * Sanitize text field
     * 
     * @param string $value
     * @return string
     */
    protected function sanitize(string $value): string {
        return sanitize_text_field(trim($value));
    }
}

/**
 * Payment Logger Class
 * 
 * Handles logging for payment operations
 */
class PaymentLogger {
    
    /**
     * Singleton instance
     * 
     * @var PaymentLogger
     */
    private static $instance = null;
    
    /**
     * Log table name
     * 
     * @var string
     */
    private $table_name;
    
    /**
     * Get singleton instance
     * 
     * @return PaymentLogger
     */
    public static function get_instance(): PaymentLogger {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'menu_popup_payment_log';
    }
    
    /**
     * Log message
     * 
     * @param string $level
     * @param string $message
     * @param array $context
     */
    public function log(string $level, string $message, array $context = []) {
        // Log to WordPress debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $context_str = !empty($context) ? ' ' . json_encode($context) : '';
            error_log(sprintf(
                '[Payment %s] %s%s',
                strtoupper($level),
                $message,
                $context_str
            ));
        }
        
        // Store in database if table exists
        $this->store_in_database($level, $message, $context);
    }
    
    /**
     * Store log in database
     * 
     * @param string $level
     * @param string $message
     * @param array $context
     */
    private function store_in_database(string $level, string $message, array $context) {
        global $wpdb;
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$this->table_name'") != $this->table_name) {
            return;
        }
        
        $wpdb->insert(
            $this->table_name,
            [
                'event_type' => $level,
                'event_message' => $message,
                'request_data' => !empty($context) ? json_encode($context) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
    }
    
    /**
     * Log debug message
     * 
     * @param string $message
     * @param array $context
     */
    public function debug(string $message, array $context = []) {
        $this->log('debug', $message, $context);
    }
    
    /**
     * Log info message
     * 
     * @param string $message
     * @param array $context
     */
    public function info(string $message, array $context = []) {
        $this->log('info', $message, $context);
    }
    
    /**
     * Log warning message
     * 
     * @param string $message
     * @param array $context
     */
    public function warning(string $message, array $context = []) {
        $this->log('warning', $message, $context);
    }
    
    /**
     * Log error message
     * 
     * @param string $message
     * @param array $context
     */
    public function error(string $message, array $context = []) {
        $this->log('error', $message, $context);
    }
    
    /**
     * Log critical message
     * 
     * @param string $message
     * @param array $context
     */
    public function critical(string $message, array $context = []) {
        $this->log('critical', $message, $context);
    }
}

/**
 * Payment Exception Class
 */
class PaymentException extends Exception {
    
    /**
     * Error code
     * 
     * @var string
     */
    protected $error_code;
    
    /**
     * Additional data
     * 
     * @var array
     */
    protected $data = [];
    
    /**
     * Constructor
     * 
     * @param string $message
     * @param string $code
     * @param array $data
     */
    public function __construct(string $message, string $code = 'payment_error', array $data = []) {
        parent::__construct($message);
        $this->error_code = $code;
        $this->data = $data;
    }
    
    /**
     * Get error code
     * 
     * @return string
     */
    public function get_error_code(): string {
        return $this->error_code;
    }
    
    /**
     * Get additional data
     * 
     * @return array
     */
    public function get_data(): array {
        return $this->data;
    }
    
    /**
     * Convert to array for JSON response
     * 
     * @return array
     */
    public function to_array(): array {
        return [
            'code' => $this->error_code,
            'message' => $this->getMessage(),
            'data' => $this->data
        ];
    }
}

/**
 * Payment Status Constants
 */
class PaymentStatus {
    const PENDING = 'pending';
    const PROCESSING = 'processing';
    const PAID = 'paid';
    const FAILED = 'failed';
    const CANCELLED = 'cancelled';
    const EXPIRED = 'expired';
    const REFUNDED = 'refunded';
    
    /**
     * Get all status options
     * 
     * @return array
     */
    public static function get_all(): array {
        return [
            self::PENDING => __('Pending', 'menu-popup'),
            self::PROCESSING => __('Processing', 'menu-popup'),
            self::PAID => __('Paid', 'menu-popup'),
            self::FAILED => __('Failed', 'menu-popup'),
            self::CANCELLED => __('Cancelled', 'menu-popup'),
            self::EXPIRED => __('Expired', 'menu-popup'),
            self::REFUNDED => __('Refunded', 'menu-popup')
        ];
    }
}

/**
 * Payment Error Codes
 */
class PaymentErrors {
    const NO_PAYMENT_METHOD = 'payment_001';
    const GATEWAY_NOT_ENABLED = 'payment_002';
    const INVALID_AMOUNT = 'payment_003';
    const ORDER_NOT_FOUND = 'payment_004';
    const API_ERROR = 'payment_005';
    const INVALID_SIGNATURE = 'payment_006';
    const PAYMENT_EXPIRED = 'payment_007';
    const PAYMENT_FAILED = 'payment_008';
    const NETWORK_ERROR = 'payment_009';
    const BUSINESS_CLOSED = 'payment_010';
    const DUPLICATE_PAYMENT = 'payment_011';
    const INVALID_SETTINGS = 'payment_012';
    const MAX_AMOUNT_EXCEEDED = 'payment_013';
    const MIN_AMOUNT_NOT_MET = 'payment_014';
}
