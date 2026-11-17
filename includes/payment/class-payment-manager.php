<?php
/**
 * Payment Manager
 * 
 * Manages all payment gateways and processes payments
 * 
 * @package MenuPopup
 * @subpackage Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Payment Manager Class
 */
class PaymentManager {
    
    /**
     * Singleton instance
     * 
     * @var PaymentManager
     */
    private static $instance = null;
    
    /**
     * Registered gateways
     * 
     * @var array
     */
    private $gateways = [];
    
    /**
     * Database handler
     * 
     * @var PaymentDatabaseHandler
     */
    private $database;
    
    /**
     * Logger
     * 
     * @var PaymentLogger
     */
    private $logger;
    
    /**
     * Get singleton instance
     * 
     * @return PaymentManager
     */
    public static function get_instance(): PaymentManager {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->database = PaymentDatabaseHandler::get_instance();
        $this->logger = PaymentLogger::get_instance();
        
        // Initialize
        $this->init();
    }
    
    /**
     * Initialize payment system
     */
    public function init() {
        // Load built-in gateways
        $this->load_builtin_gateways();
        
        // Load external gateways via hook
        do_action('menu_popup_payment_gateways_loaded', $this);
        
        $this->logger->info('Payment manager initialized', ['gateways' => array_keys($this->gateways)]);
    }
    
    /**
     * Load built-in payment gateways
     */
    private function load_builtin_gateways() {
        // Load COD gateway
        require_once MENU_POPUP_PLUGIN_DIR . 'includes/payment/gateways/class-cod-gateway.php';
        
        // Load Bank Transfer gateway
        require_once MENU_POPUP_PLUGIN_DIR . 'includes/payment/gateways/class-bank-transfer-gateway.php';
        
        // Load Fawry gateway
        require_once MENU_POPUP_PLUGIN_DIR . 'includes/payment/gateways/class-fawry-gateway.php';
        
        // Register gateways
        $this->register_gateway(new CODPaymentGateway());
        $this->register_gateway(new BankTransferPaymentGateway());
        $this->register_gateway(new FawryPaymentGateway());
    }
    
    /**
     * Register a payment gateway
     * 
     * @param PaymentGatewayInterface $gateway
     */
    public function register_gateway(PaymentGatewayInterface $gateway) {
        $id = $gateway->get_id();
        $this->gateways[$id] = $gateway;
        
        $this->logger->debug('Gateway registered', ['id' => $id, 'name' => $gateway->get_name()]);
    }
    
    /**
     * Get gateway by ID
     * 
     * @param string $gateway_id
     * @return PaymentGatewayInterface|null
     */
    public function get_gateway(string $gateway_id): ?PaymentGatewayInterface {
        return $this->gateways[$gateway_id] ?? null;
    }
    
    /**
     * Get all registered gateways
     * 
     * @return array
     */
    public function get_all_gateways(): array {
        return $this->gateways;
    }
    
    /**
     * Get enabled gateways
     * 
     * @return array
     */
    public function get_enabled_gateways(): array {
        $enabled = [];
        
        foreach ($this->gateways as $id => $gateway) {
            if ($gateway->is_enabled()) {
                $enabled[$id] = $gateway;
            }
        }
        
        return $enabled;
    }
    
    /**
     * Get enabled gateways for frontend
     * 
     * @return array
     */
    public function get_enabled_gateways_list(): array {
        $gateways = [];
        
        foreach ($this->get_enabled_gateways() as $id => $gateway) {
            $gateways[] = [
                'id' => $id,
                'name' => $gateway->get_name(),
                'description' => $gateway->get_description(),
                'is_online' => $gateway->is_online_gateway(),
                'instruction_text' => $gateway->get_instruction_text()
            ];
        }
        
        return $gateways;
    }
    
    /**
     * Check if any payment method is enabled
     * 
     * @return bool
     */
    public function is_payment_enabled(): bool {
        return get_option('menu_popup_payment_enabled', '0') === '1' && !empty($this->get_enabled_gateways());
    }
    
    /**
     * Process payment for an order
     * 
     * @param string $gateway_id
     * @param array $order_data
     * @return array
     */
    public function process_payment(string $gateway_id, array $order_data): array {
        $this->logger->info('Processing payment', [
            'gateway' => $gateway_id,
            'order_id' => $order_data['order_id'] ?? $order_data['id'] ?? 0
        ]);
        
        // Get gateway
        $gateway = $this->get_gateway($gateway_id);
        
        if (!$gateway) {
            $this->logger->error('Gateway not found', ['gateway' => $gateway_id]);
            return [
                'success' => false,
                'message' => __('Payment gateway not found', 'menu-popup'),
                'code' => PaymentErrors::GATEWAY_NOT_ENABLED
            ];
        }
        
        // Check if gateway is enabled
        if (!$gateway->is_enabled()) {
            $this->logger->error('Gateway not enabled', ['gateway' => $gateway_id]);
            return [
                'success' => false,
                'message' => __('This payment method is not available', 'menu-popup'),
                'code' => PaymentErrors::GATEWAY_NOT_ENABLED
            ];
        }
        
        // Check business day
        if (!$this->is_business_day_open()) {
            $this->logger->warning('Business day is closed');
            return [
                'success' => false,
                'message' => __('Business is currently closed. Cannot process payments.', 'menu-popup'),
                'code' => PaymentErrors::BUSINESS_CLOSED
            ];
        }
        
        // Validate order data
        if (!$gateway->validate_order_data($order_data)) {
            $this->logger->error('Invalid order data', ['gateway' => $gateway_id]);
            return [
                'success' => false,
                'message' => __('Invalid order data', 'menu-popup'),
                'code' => PaymentErrors::ORDER_NOT_FOUND
            ];
        }
        
        try {
            // Create payment record in database
            $payment_data = [
                'order_id' => $order_data['order_id'] ?? $order_data['id'],
                'gateway_id' => $gateway_id,
                'amount' => $this->get_order_total($order_data),
                'currency' => $this->get_order_currency($order_data),
                'status' => 'pending',
                'customer_email' => $this->get_customer_email($order_data),
                'customer_phone' => $this->get_customer_phone($order_data)
            ];
            
            $payment_id = $this->database->create_payment($payment_data);
            
            if (!$payment_id) {
                throw new PaymentException('Failed to create payment record', PaymentErrors::API_ERROR);
            }
            
            // Add payment_id to order_data
            $order_data['payment_id'] = $payment_id;
            
            // Process payment via gateway
            $result = $gateway->process_payment($order_data);
            
            // Update payment status based on result
            if ($result['success']) {
                $this->database->update_payment_status($payment_id, 'processing', [
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'reference_number' => $result['reference_number'] ?? null
                ]);
                
                // Update order meta
                $this->update_order_payment_meta($order_data['order_id'] ?? $order_data['id'], [
                    'payment_method' => $gateway_id,
                    'payment_status' => 'processing',
                    'payment_reference' => $result['reference_number'] ?? null,
                    'transaction_id' => $result['transaction_id'] ?? null
                ]);
                
                // Log success
                $this->logger->info('Payment initiated successfully', [
                    'gateway' => $gateway_id,
                    'payment_id' => $payment_id,
                    'order_id' => $order_data['order_id'] ?? $order_data['id']
                ]);
            } else {
                $this->database->update_payment_status($payment_id, 'failed', [
                    'notes' => $result['message'] ?? 'Payment failed'
                ]);
                
                $this->logger->error('Payment failed', [
                    'gateway' => $gateway_id,
                    'payment_id' => $payment_id,
                    'error' => $result['message'] ?? 'Unknown error'
                ]);
            }
            
            return $result;
            
        } catch (PaymentException $e) {
            $this->logger->error('Payment exception', [
                'gateway' => $gateway_id,
                'error' => $e->getMessage(),
                'code' => $e->get_error_code()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->get_error_code()
            ];
            
        } catch (Exception $e) {
            $this->logger->critical('Payment error', [
                'gateway' => $gateway_id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => __('Payment processing failed', 'menu-popup'),
                'code' => PaymentErrors::API_ERROR
            ];
        }
    }
    
    /**
     * Handle payment callback (for online gateways)
     * 
     * @param string $gateway_id
     * @param array $callback_data
     * @return array
     */
    public function handle_callback(string $gateway_id, array $callback_data): array {
        $this->logger->info('Handling payment callback', [
            'gateway' => $gateway_id,
            'data' => $callback_data
        ]);
        
        $gateway = $this->get_gateway($gateway_id);
        
        if (!$gateway) {
            return [
                'success' => false,
                'message' => 'Gateway not found'
            ];
        }
        
        try {
            $result = $gateway->handle_callback($callback_data);
            
            // Find payment by reference or order ID
            $order_id = $callback_data['order_id'] ?? $callback_data['merchantRefNumber'] ?? 0;
            
            if (!empty($callback_data['reference_number'])) {
                $payment = $this->database->get_payment_by_reference($callback_data['reference_number']);
            } elseif (!empty($callback_data['merchantRefNumber'])) {
                $payment = $this->database->get_payment_by_reference($callback_data['merchantRefNumber']);
            } else {
                $payment = $this->database->get_payment_by_order_id($order_id);
            }
            
            if ($payment) {
                // Update payment status
                $status = $result['status'] ?? 'failed';
                $this->database->update_payment_status($payment['id'], $status, [
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'gateway_response' => $callback_data
                ]);
                
                // Update order meta
                $this->update_order_payment_meta($payment['order_id'], [
                    'payment_status' => $status,
                    'transaction_id' => $result['transaction_id'] ?? null
                ]);
                
                // Log event
                $this->database->log_event([
                    'payment_id' => $payment['id'],
                    'order_id' => $payment['order_id'],
                    'gateway_id' => $gateway_id,
                    'event_type' => 'callback',
                    'message' => 'Payment callback processed: ' . ($result['message'] ?? ''),
                    'response' => $result
                ]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->logger->critical('Callback error', [
                'gateway' => $gateway_id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update payment status manually (admin action)
     * 
     * @param int $order_id
     * @param string $status
     * @param string $notes
     * @return array
     */
    public function update_payment_status(int $order_id, string $status, string $notes = ''): array {
        $result = $this->database->update_payment_by_order($order_id, $status, [
            'notes' => $notes
        ]);
        
        if ($result) {
            // Update order meta
            $this->update_order_payment_meta($order_id, [
                'payment_status' => $status
            ]);
            
            return [
                'success' => true,
                'message' => __('Payment status updated', 'menu-popup')
            ];
        }
        
        return [
            'success' => false,
            'message' => __('Failed to update payment status', 'menu-popup')
        ];
    }
    
    /**
     * Get payment info for an order
     * 
     * @param int $order_id
     * @return array|null
     */
    public function get_payment_info(int $order_id): ?array {
        return $this->database->get_payment_by_order_id($order_id);
    }
    
    /**
     * Get payment statistics for dashboard
     * 
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public function get_statistics(string $start_date, string $end_date): array {
        return $this->database->get_statistics($start_date, $end_date);
    }
    
    /**
     * Check if business day is open
     * 
     * @return bool
     */
    private function is_business_day_open(): bool {
        // Check if business hours check is disabled for testing
        $disable_hours_check = get_option('menu_popup_payment_disable_hours_check', '0');
        if ($disable_hours_check === '1') {
            return true;
        }
        
        // Check main accounting setting
        $business_open = get_option('menu_popup_accounting_business_open', '0');
        
        // Check payment-specific setting
        $payment_business_open = get_option('menu_popup_payment_business_hours', '1');
        
        return ($business_open === '1' || $business_open === '0' && $payment_business_open === '1');
    }
    
    /**
     * Get order total from order data
     * 
     * @param array $order_data
     * @return float
     */
    private function get_order_total(array $order_data): float {
        return (float) ($order_data['total'] ?? $order_data['order_total'] ?? 0);
    }
    
    /**
     * Get order currency from order data
     * 
     * @param array $order_data
     * @return string
     */
    private function get_order_currency(array $order_data): string {
        return $order_data['currency'] ?? get_option('menu_popup_payment_currency', 'EGP');
    }
    
    /**
     * Get customer email from order data
     * 
     * @param array $order_data
     * @return string
     */
    private function get_customer_email(array $order_data): string {
        return $order_data['customer_email'] ?? '';
    }
    
    /**
     * Get customer phone from order data
     * 
     * @param array $order_data
     * @return string
     */
    private function get_customer_phone(array $order_data): string {
        return $order_data['customer_phone'] ?? $order_data['phone'] ?? '';
    }
    
    /**
     * Update order payment meta
     * 
     * @param int $order_id
     * @param array $meta
     */
    private function update_order_payment_meta(int $order_id, array $meta) {
        foreach ($meta as $key => $value) {
            if ($value !== null) {
                update_post_meta($order_id, '_' . $key, $value);
            }
        }
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        $this->database->create_tables();
    }
}
