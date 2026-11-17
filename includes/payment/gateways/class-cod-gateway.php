<?php
/**
 * Cash on Delivery (COD) Payment Gateway
 * 
 * @package MenuPopup
 * @subpackage Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * COD Payment Gateway Class
 */
class CODPaymentGateway extends PaymentGatewayBase {
    
    /**
     * Gateway ID
     * 
     * @var string
     */
    protected $gateway_id = 'cod';
    
    /**
     * Gateway name
     * 
     * @var string
     */
    protected $gateway_name = 'Cash on Delivery';
    
    /**
     * Gateway description
     * 
     * @var string
     */
    protected $gateway_description = 'Pay cash when your order is delivered';
    
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
                'label' => __('Enable Cash on Delivery', 'menu-popup'),
                'description' => __('Allow customers to pay in cash when their order is delivered', 'menu-popup'),
                'default' => '0'
            ],
            'max_amount' => [
                'type' => 'number',
                'label' => __('Maximum Order Amount', 'menu-popup'),
                'description' => __('Maximum order amount for COD (leave empty for unlimited)', 'menu-popup'),
                'default' => '',
                'attributes' => [
                    'min' => 0,
                    'step' => 0.01
                ]
            ],
            'min_amount' => [
                'type' => 'number',
                'label' => __('Minimum Order Amount', 'menu-popup'),
                'description' => __('Minimum order amount for COD (leave empty for no minimum)', 'menu-popup'),
                'default' => '',
                'attributes' => [
                    'min' => 0,
                    'step' => 0.01
                ]
            ],
            'instructions' => [
                'type' => 'textarea',
                'label' => __('Delivery Instructions', 'menu-popup'),
                'description' => __('Instructions shown to customers about COD payment', 'menu-popup'),
                'default' => __('Pay in cash to the delivery person when receiving your order.', 'menu-popup')
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
        // Validate max amount
        if (!empty($settings['max_amount']) && $settings['max_amount'] < 0) {
            return false;
        }
        
        // Validate min amount
        if (!empty($settings['min_amount']) && $settings['min_amount'] < 0) {
            return false;
        }
        
        // Min must be less than max if both set
        if (!empty($settings['min_amount']) && !empty($settings['max_amount'])) {
            if ($settings['min_amount'] > $settings['max_amount']) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Process payment for an order
     * 
     * @param array $order_data
     * @return array
     */
    public function process_payment(array $order_data): array {
        $this->log('info', 'Processing COD payment', $order_data);
        
        // Validate order data
        if (!$this->validate_order_data($order_data)) {
            return [
                'success' => false,
                'message' => __('Invalid order data', 'menu-popup')
            ];
        }
        
        $total = $this->get_order_total($order_data);
        
        // Check maximum amount
        $max_amount = $this->get_setting('max_amount');
        if (!empty($max_amount) && $total > (float) $max_amount) {
            $this->log('warning', 'Order amount exceeds COD maximum', [
                'order_total' => $total,
                'max_allowed' => $max_amount
            ]);
            
            return [
                'success' => false,
                'message' => sprintf(
                    __('Cash on Delivery is not available for orders over %s', 'menu-popup'),
                    $this->format_amount($max_amount)
                ),
                'code' => PaymentErrors::MAX_AMOUNT_EXCEEDED
            ];
        }
        
        // Check minimum amount
        $min_amount = $this->get_setting('min_amount');
        if (!empty($min_amount) && $total < (float) $min_amount) {
            $this->log('warning', 'Order amount below COD minimum', [
                'order_total' => $total,
                'min_required' => $min_amount
            ]);
            
            return [
                'success' => false,
                'message' => sprintf(
                    __('Minimum order amount for COD is %s', 'menu-popup'),
                    $this->format_amount($min_amount)
                ),
                'code' => PaymentErrors::MIN_AMOUNT_NOT_MET
            ];
        }
        
        // Generate reference number
        $reference = $this->generate_reference('COD');
        
        // Success - COD payment is pending until delivery
        $this->log('info', 'COD payment initiated', [
            'reference' => $reference,
            'amount' => $total
        ]);
        
        return [
            'success' => true,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'reference_number' => $reference,
            'message' => __('Cash on Delivery payment pending. Pay when your order is delivered.', 'menu-popup')
        ];
    }
    
    /**
     * Validate payment data
     * 
     * @param array $payment_data
     * @return bool
     */
    public function validate_payment(array $payment_data): bool {
        // COD doesn't require additional validation
        return true;
    }
    
    /**
     * Get payment form HTML for frontend display
     * 
     * @param array $order_data
     * @return string
     */
    public function get_payment_form(array $order_data): string {
        $instructions = $this->get_setting('instructions', __('Pay in cash to the delivery person when receiving your order.', 'menu-popup'));
        
        ob_start();
        ?>
        <div class="payment-form cod-payment-form">
            <p class="payment-instructions"><?php echo esc_html($instructions); ?></p>
            <div class="cod-agreement">
                <label>
                    <input type="checkbox" name="cod_agreement" value="1" required>
                    <span><?php _e('I agree to pay in cash upon delivery', 'menu-popup'); ?></span>
                </label>
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
        return $this->get_setting('instructions', __('Pay in cash when your order is delivered', 'menu-popup'));
    }
    
    /**
     * Check if gateway is online (API-based)
     * 
     * @return bool
     */
    public function is_online_gateway(): bool {
        return false;
    }
    
    /**
     * Validate order data
     * 
     * @param array $order_data
     * @return bool
     */
    public function validate_order_data(array $order_data): bool {
        // Check order ID
        if (empty($order_data['order_id']) && empty($order_data['id'])) {
            return false;
        }
        
        // Check amount
        $total = $this->get_order_total($order_data);
        if (!$this->validate_amount($total)) {
            return false;
        }
        
        return true;
    }
}
