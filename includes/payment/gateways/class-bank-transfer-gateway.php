<?php
/**
 * Bank Transfer Payment Gateway
 * 
 * @package MenuPopup
 * @subpackage Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bank Transfer Payment Gateway Class
 */
class BankTransferPaymentGateway extends PaymentGatewayBase {
    
    /**
     * Gateway ID
     * 
     * @var string
     */
    protected $gateway_id = 'bank_transfer';
    
    /**
     * Gateway name
     * 
     * @var string
     */
    protected $gateway_name = 'Bank Transfer';
    
    /**
     * Gateway description
     * 
     * @var string
     */
    protected $gateway_description = 'Pay via bank transfer (manual verification required)';
    
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
                'label' => __('Enable Bank Transfer', 'menu-popup'),
                'description' => __('Allow customers to pay via bank transfer', 'menu-popup'),
                'default' => '0'
            ],
            'bank_name' => [
                'type' => 'text',
                'label' => __('Bank Name', 'menu-popup'),
                'description' => __('Enter your bank name', 'menu-popup'),
                'default' => ''
            ],
            'account_number' => [
                'type' => 'text',
                'label' => __('Account Number', 'menu-popup'),
                'description' => __('Enter your bank account number', 'menu-popup'),
                'default' => ''
            ],
            'account_holder' => [
                'type' => 'text',
                'label' => __('Account Holder Name', 'menu-popup'),
                'description' => __('Enter the account holder name', 'menu-popup'),
                'default' => ''
            ],
            'branch' => [
                'type' => 'text',
                'label' => __('Branch Name', 'menu-popup'),
                'description' => __('Enter branch name (optional)', 'menu-popup'),
                'default' => ''
            ],
            'iban' => [
                'type' => 'text',
                'label' => __('IBAN', 'menu-popup'),
                'description' => __('Enter IBAN code (optional)', 'menu-popup'),
                'default' => ''
            ],
            'swift_code' => [
                'type' => 'text',
                'label' => __('SWIFT/BIC Code', 'menu-popup'),
                'description' => __('Enter SWIFT/BIC code (optional for international transfers)', 'menu-popup'),
                'default' => ''
            ],
            'instructions' => [
                'type' => 'textarea',
                'label' => __('Transfer Instructions', 'menu-popup'),
                'description' => __('Instructions shown to customers for making the transfer', 'menu-popup'),
                'default' => __('Please transfer the order total to the account above. Your order will be processed after payment verification.', 'menu-popup')
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
            if (empty($settings['bank_name'])) {
                return false;
            }
            if (empty($settings['account_number'])) {
                return false;
            }
            if (empty($settings['account_holder'])) {
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
        $this->log('info', 'Processing Bank Transfer payment', $order_data);
        
        // Validate order data
        if (!$this->validate_order_data($order_data)) {
            return [
                'success' => false,
                'message' => __('Invalid order data', 'menu-popup')
            ];
        }
        
        $total = $this->get_order_total($order_data);
        
        // Generate unique reference number for the transfer
        $reference = $this->generate_reference('BT');
        
        // Get bank details for display
        $bank_details = $this->get_bank_details();
        
        // Success - pending manual verification
        $this->log('info', 'Bank Transfer payment initiated', [
            'reference' => $reference,
            'amount' => $total,
            'bank' => $bank_details['bank_name']
        ]);
        
        return [
            'success' => true,
            'payment_method' => 'bank_transfer',
            'payment_status' => 'pending',
            'reference_number' => $reference,
            'message' => __('Bank transfer initiated. Please transfer the amount to complete your order.', 'menu-popup'),
            'bank_details' => $bank_details,
            'instructions' => $this->get_setting('instructions', '')
        ];
    }
    
    /**
     * Validate payment data
     * 
     * @param array $payment_data
     * @return bool
     */
    public function validate_payment(array $payment_data): bool {
        // Bank transfer doesn't require additional validation at this point
        return true;
    }
    
    /**
     * Get payment form HTML for frontend display
     * 
     * @param array $order_data
     * @return string
     */
    public function get_payment_form(array $order_data): string {
        $bank_details = $this->get_bank_details();
        $instructions = $this->get_setting('instructions', '');
        $reference = $this->generate_reference('BT');
        
        // If order data has reference, use it
        if (!empty($order_data['payment_reference'])) {
            $reference = $order_data['payment_reference'];
        }
        
        ob_start();
        ?>
        <div class="payment-form bank-transfer-form">
            <div class="bank-details-box">
                <h4><?php _e('Bank Transfer Details', 'menu-popup'); ?></h4>
                
                <div class="bank-detail">
                    <span class="label"><?php _e('Bank Name:', 'menu-popup'); ?></span>
                    <span class="value"><?php echo esc_html($bank_details['bank_name']); ?></span>
                </div>
                
                <div class="bank-detail">
                    <span class="label"><?php _e('Account Number:', 'menu-popup'); ?></span>
                    <span class="value"><?php echo esc_html($bank_details['account_number']); ?></span>
                </div>
                
                <div class="bank-detail">
                    <span class="label"><?php _e('Account Holder:', 'menu-popup'); ?></span>
                    <span class="value"><?php echo esc_html($bank_details['account_holder']); ?></span>
                </div>
                
                <?php if (!empty($bank_details['branch'])): ?>
                <div class="bank-detail">
                    <span class="label"><?php _e('Branch:', 'menu-popup'); ?></span>
                    <span class="value"><?php echo esc_html($bank_details['branch']); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($bank_details['iban'])): ?>
                <div class="bank-detail">
                    <span class="label"><?php _e('IBAN:', 'menu-popup'); ?></span>
                    <span class="value"><?php echo esc_html($bank_details['iban']); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($bank_details['swift_code'])): ?>
                <div class="bank-detail">
                    <span class="label"><?php _e('SWIFT Code:', 'menu-popup'); ?></span>
                    <span class="value"><?php echo esc_html($bank_details['swift_code']); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="bank-detail reference">
                    <span class="label"><?php _e('Your Reference:', 'menu-popup'); ?></span>
                    <span class="value"><?php echo esc_html($reference); ?></span>
                </div>
                
                <?php if (!empty($instructions)): ?>
                <div class="transfer-instructions">
                    <p><?php echo esc_html($instructions); ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="bank-transfer-agreement">
                <label>
                    <input type="checkbox" name="bank_transfer_agreement" value="1" required>
                    <span><?php _e('I have made the bank transfer payment', 'menu-popup'); ?></span>
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
        return $this->get_setting('instructions', __('Transfer the amount to our bank account', 'menu-popup'));
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
     * Get bank details
     * 
     * @return array
     */
    public function get_bank_details(): array {
        return [
            'bank_name' => $this->get_setting('bank_name', ''),
            'account_number' => $this->get_setting('account_number', ''),
            'account_holder' => $this->get_setting('account_holder', ''),
            'branch' => $this->get_setting('branch', ''),
            'iban' => $this->get_setting('iban', ''),
            'swift_code' => $this->get_setting('swift_code', '')
        ];
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
        
        // Check if bank details are configured
        $bank_details = $this->get_bank_details();
        if (empty($bank_details['bank_name']) || empty($bank_details['account_number'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Verify bank transfer (admin action)
     * 
     * @param int $order_id
     * @param string $notes
     * @return array
     */
    public function verify_payment(int $order_id, string $notes = ''): array {
        $payment_manager = PaymentManager::get_instance();
        
        $result = $payment_manager->update_payment_status($order_id, 'paid', $notes);
        
        if ($result['success']) {
            // Update order status
            $order = get_post($order_id);
            if ($order) {
                wp_update_post([
                    'ID' => $order_id,
                    'post_status' => 'menu_order_processing'
                ]);
            }
        }
        
        return $result;
    }
}
