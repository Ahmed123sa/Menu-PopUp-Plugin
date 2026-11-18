<?php
/**
 * Fawry Payment Gateway
 * 
 * @package MenuPopup
 * @subpackage Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fawry Payment Gateway Class
 */
class FawryPaymentGateway extends PaymentGatewayBase {
    
    /**
     * Gateway ID
     * 
     * @var string
     */
    protected $gateway_id = 'fawry';
    
    /**
     * Gateway name
     * 
     * @var string
     */
    protected $gateway_name = 'Fawry';
    
    /**
     * Gateway description
     * 
     * @var string
     */
    protected $gateway_description = 'Pay via Fawry payment system';
    
    /**
     * Fawry API base URLs
     */
    const FAWRY_SANDBOX = 'https://sandbox.fawry.com';
    const FAWRY_PRODUCTION = 'https://www.atfawry.com';
    
    /**
     * API Endpoints
     */
    const ENDPOINT_INITIATE = '/ECommercePluginApi/api/initiatePayment';
    const ENDPOINT_STATUS = '/ECommercePluginApi/api/getPaymentStatus';
    const ENDPOINT_CANCEL = '/ECommercePluginApi/api/cancelMerchantRefNumber';
    const ENDPOINT_REFUND = '/ECommercePluginApi/api/refund';
    
    /**
     * Payment methods supported by Fawry
     */
    const METHOD_PAY_AT_FAWRY = 'PAYATFAWRY';
    const METHOD_CREDIT_CARD = 'CREDITCARD';
    const METHOD_DEBIT_CARD = 'DEBITCARD';
    const METHOD_MASTERCARD = 'MASTERCARD';
    const METHOD_VISA = 'VISA';
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Get gateway ID
     * 
     * @return string
     */
    public function get_id(): string {
        return $this->gateway_id;
    }
    
    /**
     * Get gateway name
     * 
     * @return string
     */
    public function get_name(): string {
        return $this->gateway_name;
    }
    
    /**
     * Get gateway description
     * 
     * @return string
     */
    public function get_description(): string {
        return $this->gateway_description;
    }
    
    /**
     * Get gateway settings fields
     * 
     * @return array
     */
    public function get_settings(): array {
        return [
            'enabled' => [
                'type' => 'checkbox',
                'label' => __('Enable Fawry', 'menu-popup'),
                'description' => __('Allow customers to pay via Fawry online payment', 'menu-popup'),
                'default' => '0'
            ],
            'merchant_code' => [
                'type' => 'text',
                'label' => __('Merchant Code', 'menu-popup'),
                'description' => __('Your Fawry Merchant Code', 'menu-popup'),
                'default' => ''
            ],
            'secure_key' => [
                'type' => 'password',
                'label' => __('Secure Key', 'menu-popup'),
                'description' => __('Your Fawry Secure Key', 'menu-popup'),
                'default' => ''
            ],
            'sandbox' => [
                'type' => 'checkbox',
                'label' => __('Sandbox Mode', 'menu-popup'),
                'description' => __('Use Fawry sandbox environment for testing', 'menu-popup'),
                'default' => '1'
            ],
            'expiry_hours' => [
                'type' => 'number',
                'label' => __('Payment Expiry (Hours)', 'menu-popup'),
                'description' => __('Number of hours before payment expires', 'menu-popup'),
                'default' => '24',
                'attributes' => [
                    'min' => 1,
                    'max' => 72
                ]
            ],
            'payment_methods' => [
                'type' => 'multicheck',
                'label' => __('Allowed Payment Methods', 'menu-popup'),
                'description' => __('Select which payment methods to allow', 'menu-popup'),
                'options' => [
                    'PAYATFAWRY' => __('Pay at Fawry', 'menu-popup'),
                    'CREDITCARD' => __('Credit Card', 'menu-popup'),
                    'DEBITCARD' => __('Debit Card', 'menu-popup')
                ],
                'default' => ['PAYATFAWRY']
            ]
        ];
    }
    
    /**
     * Validate gateway settings
     * 
     * @param array $settings
     * @return bool
     */
    public function validate_settings(array $settings): bool {
        // Required fields validation when enabled
        if (!empty($settings['enabled'])) {
            if (empty($settings['merchant_code'])) {
                return false;
            }
            if (empty($settings['secure_key'])) {
                return false;
            }
        }
        
        // Validate expiry hours
        if (!empty($settings['expiry_hours'])) {
            $hours = (int) $settings['expiry_hours'];
            if ($hours < 1 || $hours > 72) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get API base URL
     * 
     * @return string
     */
    protected function get_api_url(): string {
        $sandbox = $this->get_setting('sandbox', '1');
        $is_sandbox = ($sandbox === '1' || $sandbox === true || $sandbox === 'on' || $sandbox === 1);
        return $is_sandbox ? self::FAWRY_SANDBOX : self::FAWRY_PRODUCTION;
    }
    
    /**
     * Get merchant code
     * 
     * @return string
     */
    protected function get_merchant_code(): string {
        return $this->get_setting('merchant_code', '');
    }
    
    /**
     * Get secure key
     * 
     * @return string
     */
    protected function get_secure_key(): string {
        return $this->get_setting('secure_key', '');
    }
    
    /**
     * Generate signature for Fawry API
     * 
     * @param array $data
     * @return string
     */
    protected function generate_signature(array $data): string {
        $secure_key = $this->get_secure_key();
        
        $signature_string = 
            $data['merchantCode'] . 
            $data['merchantRefNumber'] . 
            $data['amount'] . 
            $data['currencyCode'] . 
            $secure_key;
        
        return hash('sha256', $signature_string);
    }
    
    /**
     * Process payment for an order
     * 
     * @param array $order_data
     * @return array
     */
    public function process_payment(array $order_data): array {
        $this->log('info', 'Processing Fawry payment', $order_data);
        
        // Validate order data
        if (!$this->validate_order_data($order_data)) {
            return [
                'success' => false,
                'message' => __('Invalid order data', 'menu-popup')
            ];
        }
        
        // Check API credentials - allow test mode if sandbox is enabled
        $sandbox = $this->get_setting('sandbox', '1');
        
        // Check if sandbox is enabled (be lenient with checking)
        $is_sandbox = ($sandbox === '1' || $sandbox === true || $sandbox === 'on' || $sandbox === 1);
        
        // For testing purposes, allow sandbox mode without credentials
        if ($is_sandbox) {
            // In sandbox mode, we can test without real credentials
            $this->log('info', 'Fawry running in sandbox mode - credentials not required');
        } else if (empty($this->get_merchant_code()) || empty($this->get_secure_key())) {
            $this->log('error', 'Fawry API credentials not configured');
            return [
                'success' => false,
                'message' => __('Fawry payment is not properly configured. Please contact the administrator.', 'menu-popup'),
                'code' => PaymentErrors::INVALID_SETTINGS
            ];
        }
        
        $total = $this->get_order_total($order_data);
        $currency = $this->get_order_currency($order_data);
        
        try {
            // Check if we're in sandbox mode without credentials
            $sandbox = $this->get_setting('sandbox', '1');
            
            // Prepare payment request
            $reference = $this->generate_reference('FW');
            $order_id = $order_data['order_id'] ?? $order_data['id'] ?? 0;
            
            // In sandbox mode without credentials, simulate a successful response
            $sandbox = $this->get_setting('sandbox', '1');
            $is_sandbox = ($sandbox === '1' || $sandbox === true || $sandbox === 'on' || $sandbox === 1);
            
            if (empty($this->get_merchant_code()) || empty($this->get_secure_key())) {
                // Without credentials nothing real can happen. Only a debug
                // site may pretend; a live site must refuse.
                if (!(defined('WP_DEBUG') && WP_DEBUG)) {
                    return [
                        'success' => false,
                        'message' => __('Fawry is not configured yet', 'menu-popup')
                    ];
                }
                $this->log('info', 'Fawry sandbox mode - simulating payment', ['reference' => $reference]);
                
                return [
                    'success' => true,
                    'payment_method' => 'fawry',
                    'payment_status' => 'processing',
                    'reference_number' => $reference,
                    'transaction_id' => $reference,
                    'payment_url' => '#sandbox-test-mode',
                    'message' => __('Test mode: Payment would be processed via Fawry sandbox', 'menu-popup')
                ];
            }
            
            $request_data = [
                'merchantCode' => $this->get_merchant_code(),
                'merchantRefNumber' => $reference,
                'customerMobile' => $this->get_customer_phone($order_data),
                'customerEmail' => $this->get_customer_email($order_data),
                'customerProfileId' => $order_data['customer_id'] ?? '',
                'paymentMethod' => implode(',', $this->get_setting('payment_methods', ['PAYATFAWRY'])),
                'language' => 'ar-en',
                'currencyCode' => $currency,
                'amount' => $total,
                'paymentExpiry' => $this->get_expiry_timestamp(),
                'chargeItems' => $this->build_charge_items($order_data),
                'returnUrl' => $this->get_return_url($order_id),
                'customerName' => $this->get_customer_name($order_data)
            ];
            
            // Generate signature
            $request_data['signature'] = $this->generate_signature($request_data);
            
            $this->log('debug', 'Fawry payment request', $request_data);
            
            // Send request to Fawry
            $response = $this->send_api_request(self::ENDPOINT_INITIATE, $request_data);
            
            $this->log('debug', 'Fawry payment response', $response);
            
            // Check response status
            if (isset($response['type']) && $response['type'] === 'errorResponse') {
                $error_message = $response['errorDescription'] ?? __('Payment initiation failed', 'menu-popup');
                $this->log('error', 'Fawry error', $response);
                
                return [
                    'success' => false,
                    'message' => $error_message,
                    'code' => PaymentErrors::API_ERROR
                ];
            }
            
            // Get payment URL from response
            $payment_url = $response['paymentUrl'] ?? '';
            
            if (empty($payment_url)) {
                $this->log('error', 'No payment URL in Fawry response', $response);
                return [
                    'success' => false,
                    'message' => __('Failed to get payment URL', 'menu-popup'),
                    'code' => PaymentErrors::API_ERROR
                ];
            }
            
            // Success
            $this->log('info', 'Fawry payment initiated', [
                'reference' => $reference,
                'payment_url' => $payment_url
            ]);
            
            return [
                'success' => true,
                'payment_method' => 'fawry',
                'payment_status' => 'processing',
                'reference_number' => $reference,
                'transaction_id' => $response['merchantRefNumber'] ?? $reference,
                'payment_url' => $payment_url,
                'message' => __('Redirecting to payment page...', 'menu-popup')
            ];
            
        } catch (Exception $e) {
            $this->log('critical', 'Fawry payment exception', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => __('Payment processing failed: ', 'menu-popup') . $e->getMessage(),
                'code' => PaymentErrors::NETWORK_ERROR
            ];
        }
    }
    
    /**
     * Handle payment callback from Fawry
     * 
     * @param array $callback_data
     * @return array
     */
    public function handle_callback(array $callback_data): array {
        $this->log('info', 'Fawry callback received', $callback_data);
        
        try {
            // Verify signature
            $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
            
            if (!$this->verify_callback_signature($callback_data, $signature)) {
                $this->log('error', 'Invalid Fawry callback signature');
                return [
                    'success' => false,
                    'status' => 'failed',
                    'message' => 'Invalid signature'
                ];
            }
            
            // Parse callback data
            $merchant_ref = $callback_data['merchantRefNumber'] ?? '';
            $fawry_ref = $callback_data['fawryRefNo'] ?? '';
            $payment_status = $callback_data['paymentStatus'] ?? '';
            $amount = $callback_data['amount'] ?? 0;
            
            // Map Fawry status to our status
            $status_map = [
                'PAID' => 'paid',
                'PAYMENT_REJECTED' => 'failed',
                'CANCELLED' => 'cancelled',
                'EXPIRED' => 'expired',
                'REFUNDED' => 'refunded'
            ];
            
            $status = $status_map[$payment_status] ?? 'failed';
            
            // Determine if successful
            $success = ($status === 'paid');
            
            $result = [
                'success' => $success,
                'status' => $status,
                'transaction_id' => $fawry_ref,
                'reference_number' => $merchant_ref,
                'amount' => $amount,
                'message' => $this->get_status_message($payment_status)
            ];
            
            $this->log('info', 'Fawry callback processed', $result);
            
            return $result;
            
        } catch (Exception $e) {
            $this->log('critical', 'Fawry callback error', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Verify callback signature
     * 
     * @param array $data
     * @param string $signature
     * @return bool
     */
    protected function verify_callback_signature(array $data, string $signature): bool {
        $key = $this->get_secure_key();
        // Fail closed: no key or no signature means the call is not trusted
        if ($key === '' || $signature === '') {
            return false;
        }

        $signature_data =
            ($data['merchantCode'] ?? '') .
            ($data['merchantRefNumber'] ?? '') .
            ($data['fawryRefNo'] ?? '') .
            ($data['amount'] ?? '') .
            $key;

        $expected_signature = hash('sha256', $signature_data);

        return hash_equals($expected_signature, $signature);
    }

    /**
     * Send API request to Fawry
     * 
     * @param string $endpoint
     * @param array $data
     * @return array
     */
    protected function send_api_request(string $endpoint, array $data): array {
        $url = $this->get_api_url() . $endpoint;
        
        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'body' => json_encode($data),
            'timeout' => 30,
            'sslverify' => true
        ];
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code < 200 || $response_code >= 300) {
            throw new Exception('API error: ' . $response_code . ' - ' . $body);
        }
        
        return json_decode($body, true) ?: [];
    }
    
    /**
     * Get payment URL for redirect
     * 
     * @param array $order_data
     * @return string
     */
    public function get_payment_url(array $order_data): string {
        $result = $this->process_payment($order_data);
        
        if ($result['success'] && !empty($result['payment_url'])) {
            return $result['payment_url'];
        }
        
        return '';
    }
    
    /**
     * Get payment form HTML for frontend display
     * 
     * @param array $order_data
     * @return string
     */
    public function get_payment_form(array $order_data): string {
        ob_start();
        ?>
        <div class="payment-form fawry-payment-form">
            <p class="payment-instructions">
                <?php _e('You will be redirected to Fawry to complete your payment securely.', 'menu-popup'); ?>
            </p>
            <div class="fawry-logo">
                <img src="<?php echo MENU_POPUP_PLUGIN_URL; ?>assets/images/fawry-logo.png" alt="Fawry">
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get instruction text
     * 
     * @return string
     */
    public function get_instruction_text(): string {
        return __('Pay securely online via Fawry', 'menu-popup');
    }
    
    /**
     * Validate payment data
     * 
     * @param array $payment_data
     * @return bool
     */
    public function validate_payment(array $payment_data): bool {
        // Fawry payments are validated via API
        return !empty($payment_data['order_id']) && !empty($payment_data['total']);
    }
    
    /**
     * Check if gateway is online (API-based)
     * 
     * @return bool
     */
    public function is_online_gateway(): bool {
        return true;
    }
    
    /**
     * Build charge items for Fawry request
     * 
     * @param array $order_data
     * @return array
     */
    protected function build_charge_items(array $order_data): array {
        $items = $this->get_order_items($order_data);
        
        if (empty($items)) {
            // If no items, create a single charge item for the order
            return [
                [
                    'itemId' => 'order_' . ($order_data['order_id'] ?? $order_data['id'] ?? 0),
                    'description' => 'Order Payment',
                    'price' => $this->get_order_total($order_data),
                    'quantity' => 1
                ]
            ];
        }
        
        $charge_items = [];
        foreach ($items as $item) {
            $charge_items[] = [
                'itemId' => $item['id'] ?? 'item_' . sanitize_title($item['name']),
                'description' => $item['name'] ?? 'Item',
                'price' => $item['price'] ?? 0,
                'quantity' => $item['quantity'] ?? 1
            ];
        }
        
        return $charge_items;
    }
    
    /**
     * Get expiry timestamp
     * 
     * @return string
     */
    protected function get_expiry_timestamp(): string {
        $hours = (int) $this->get_setting('expiry_hours', 24);
        $expiry = time() + ($hours * 3600);
        return date('Y-m-d', $expiry) . 'T' . date('H:i:s', $expiry) . 'Z';
    }
    
    /**
     * Get return URL
     * 
     * @param int $order_id
     * @return string
     */
    protected function get_return_url(int $order_id): string {
        $base_url = get_permalink(get_option('menu_popup_checkout_page_id'));
        
        return add_query_arg([
            'order_id' => $order_id,
            'payment' => 'fawry',
            'action' => 'return'
        ], $base_url);
    }
    
    /**
     * Get callback URL
     * 
     * @return string
     */
    public function get_callback_url(): string {
        return add_query_arg([
            'action' => 'fawry_callback',
            'gateway' => 'fawry'
        ], admin_url('admin-ajax.php'));
    }
    
    /**
     * Get status message
     * 
     * @param string $fawry_status
     * @return string
     */
    protected function get_status_message(string $fawry_status): string {
        $messages = [
            'PAID' => __('Payment successful', 'menu-popup'),
            'PAYMENT_REJECTED' => __('Payment was rejected', 'menu-popup'),
            'CANCELLED' => __('Payment was cancelled', 'menu-popup'),
            'EXPIRED' => __('Payment has expired', 'menu-popup'),
            'REFUNDED' => __('Payment was refunded', 'menu-popup')
        ];
        
        return $messages[$fawry_status] ?? __('Unknown payment status', 'menu-popup');
    }
    
    /**
     * Check payment status with Fawry
     * 
     * @param string $reference
     * @return array
     */
    public function check_payment_status(string $reference): array {
        $request_data = [
            'merchantCode' => $this->get_merchant_code(),
            'merchantRefNumber' => $reference,
            'signature' => ''
        ];
        
        // Generate signature
        $signature_string = 
            $request_data['merchantCode'] . 
            $request_data['merchantRefNumber'] . 
            $this->get_secure_key();
        
        $request_data['signature'] = hash('sha256', $signature_string);
        
        try {
            $response = $this->send_api_request(self::ENDPOINT_STATUS, $request_data);
            
            return [
                'success' => true,
                'status' => $response['paymentStatus'] ?? 'UNKNOWN',
                'fawry_ref' => $response['fawryRefNo'] ?? '',
                'amount' => $response['amount'] ?? 0
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
