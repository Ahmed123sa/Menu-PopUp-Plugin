<?php
/**
 * Payment Settings Admin Page
 * 
 * Handles the Payment Methods settings tab in the plugin admin
 * 
 * @package MenuPopup
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Payment Settings Class
 */
class PaymentSettings {
    
    /**
     * Singleton instance
     * 
     * @var PaymentSettings
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
     * @return PaymentSettings
     */
    public static function get_instance(): PaymentSettings {
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
        // Add settings tab
        add_filter('menu_popup_settings_tabs', [$this, 'add_settings_tab']);
        
        // Add settings section
        add_action('menu_popup_settings_content_payment', [$this, 'render_settings_content']);
        
        // Register settings
        add_action('admin_init', [$this, 'register_settings']);
        
        // AJAX for saving payment settings
        add_action('wp_ajax_save_payment_settings', [$this, 'ajax_save_payment_settings']);
    }
    
    /**
     * Add payment settings tab
     * 
     * @param array $tabs
     * @return array
     */
    public function add_settings_tab(array $tabs): array {
        $tabs['payment'] = [
            'label' => __('Payment Methods', 'menu-popup'),
            'icon' => '💳',
            'priority' => 15
        ];
        
        return $tabs;
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // General payment settings
        register_setting(
            'menu_popup_payment_general',
            'menu_popup_payment_enabled',
            [
                'type' => 'boolean',
                'sanitize_callback' => [$this, 'sanitize_checkbox']
            ]
        );
        
        register_setting(
            'menu_popup_payment_general',
            'menu_popup_payment_currency',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );
        
        // COD settings
        register_setting(
            'menu_popup_payment_cod',
            'menu_popup_payment_cod_enabled',
            [
                'type' => 'boolean',
                'sanitize_callback' => [$this, 'sanitize_checkbox']
            ]
        );
        
        register_setting(
            'menu_popup_payment_cod',
            'menu_popup_payment_cod_max_amount',
            [
                'type' => 'number',
                'sanitize_callback' => [$this, 'sanitize_amount']
            ]
        );
        
        register_setting(
            'menu_popup_payment_cod',
            'menu_popup_payment_cod_min_amount',
            [
                'type' => 'number',
                'sanitize_callback' => [$this, 'sanitize_amount']
            ]
        );
        
        register_setting(
            'menu_popup_payment_cod',
            'menu_popup_payment_cod_instructions',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field'
            ]
        );
        
        // Bank Transfer settings
        register_setting(
            'menu_popup_payment_bank_transfer',
            'menu_popup_payment_bank_transfer_enabled',
            [
                'type' => 'boolean',
                'sanitize_callback' => [$this, 'sanitize_checkbox']
            ]
        );
        
        register_setting(
            'menu_popup_payment_bank_transfer',
            'menu_popup_payment_bank_transfer_bank_name',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );
        
        register_setting(
            'menu_popup_payment_bank_transfer',
            'menu_popup_payment_bank_transfer_account_number',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );
        
        register_setting(
            'menu_popup_payment_bank_transfer',
            'menu_popup_payment_bank_transfer_account_holder',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );
        
        register_setting(
            'menu_popup_payment_bank_transfer',
            'menu_popup_payment_bank_transfer_branch',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );
        
        register_setting(
            'menu_popup_payment_bank_transfer',
            'menu_popup_payment_bank_transfer_iban',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );
        
        register_setting(
            'menu_popup_payment_bank_transfer',
            'menu_popup_payment_bank_transfer_swift_code',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );
        
        register_setting(
            'menu_popup_payment_bank_transfer',
            'menu_popup_payment_bank_transfer_instructions',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field'
            ]
        );
        
        // Fawry settings
        register_setting(
            'menu_popup_payment_fawry',
            'menu_popup_payment_fawry_enabled',
            [
                'type' => 'boolean',
                'sanitize_callback' => [$this, 'sanitize_checkbox']
            ]
        );
        
        register_setting(
            'menu_popup_payment_fawry',
            'menu_popup_payment_fawry_merchant_code',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );
        
        register_setting(
            'menu_popup_payment_fawry',
            'menu_popup_payment_fawry_secure_key',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ]
        );
        
        register_setting(
            'menu_popup_payment_fawry',
            'menu_popup_payment_fawry_sandbox',
            [
                'type' => 'boolean',
                'sanitize_callback' => [$this, 'sanitize_checkbox']
            ]
        );
        
        register_setting(
            'menu_popup_payment_fawry',
            'menu_popup_payment_fawry_expiry_hours',
            [
                'type' => 'number',
                'sanitize_callback' => 'absint'
            ]
        );
    }
    
    /**
     * Render settings content
     */
    public function render_settings_content() {
        // Create database tables if needed
        $this->payment_manager->create_tables();
        
        $nonce = wp_create_nonce('menu_popup_save_payment_settings');
        
        ?>
        <form method="post" id="payment-settings-form" class="payment-settings-form" style="margin-top: 40px;">
            <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
            <h2><?php _e('💳 Payment Methods', 'menu-popup'); ?></h2>
            <p class="description"><?php _e('Configure payment methods for online orders', 'menu-popup'); ?></p>
            
            <?php
            // Register settings first
            $this->register_settings();
            
            // General Settings Section
            ?>
            <div class="settings-section" id="payment-general">
                <h3><?php _e('General Settings', 'menu-popup'); ?></h3>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_enabled"><?php _e('Enable Payment System', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" 
                                   id="menu_popup_payment_enabled" 
                                   name="menu_popup_payment_enabled" 
                                   value="1" 
                                   <?php checked(get_option('menu_popup_payment_enabled', '0'), '1'); ?>>
                            <p class="description"><?php _e('Enable payment functionality for online orders', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_currency"><?php _e('Default Currency', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <select id="menu_popup_payment_currency" name="menu_popup_payment_currency">
                                <?php
                                $currencies = [
                                    'EGP' => __('Egyptian Pound (EGP)', 'menu-popup'),
                                    'USD' => __('US Dollar (USD)', 'menu-popup'),
                                    'SAR' => __('Saudi Riyal (SAR)', 'menu-popup'),
                                    'AED' => __('UAE Dirham (AED)', 'menu-popup'),
                                    'EUR' => __('Euro (EUR)', 'menu-popup'),
                                    'GBP' => __('British Pound (GBP)', 'menu-popup')
                                ];
                                $current = get_option('menu_popup_payment_currency', 'EGP');
                                
                                foreach ($currencies as $code => $name) {
                                    echo '<option value="' . esc_attr($code) . '" ' . selected($current, $code, false) . '>' . esc_html($name) . '</option>';
                                }
                                ?>
                            </select>
                            <p class="description"><?php _e('Default currency for payments', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <?php
            // COD Section
            $this->render_cod_settings();
            
            // Bank Transfer Section
            $this->render_bank_transfer_settings();
            
            // Fawry Section
            $this->render_fawry_settings();
            
            submit_button(__('Save Payment Settings', 'menu-popup'), 'primary', 'save_payment_settings', true, ['id' => 'save-payment-settings-btn']);
            ?>
            <div id="payment-settings-message"></div>
        </form>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#payment-settings-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = {};
                $(this).find('input, select, textarea').each(function() {
                    var name = $(this).attr('name');
                    var type = $(this).attr('type');
                    
                    if (type === 'checkbox') {
                        formData[name] = $(this).is(':checked') ? '1' : '0';
                    } else if (name) {
                        formData[name] = $(this).val();
                    }
                });
                
                formData.action = 'save_payment_settings';
                formData.nonce = '<?php echo esc_js($nonce); ?>';
                
                $('#save-payment-settings-btn').prop('disabled', true).val('Saving...');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        if (response.success) {
                            $('#payment-settings-message').html('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
                        } else {
                            $('#payment-settings-message').html('<div class="notice notice-error is-dismissible"><p>' + response.data.message + '</p></div>');
                        }
                        $('#save-payment-settings-btn').prop('disabled', false).val('Save Payment Settings');
                    },
                    error: function() {
                        $('#payment-settings-message').html('<div class="notice notice-error is-dismissible"><p>Error saving settings. Please try again.</p></div>');
                        $('#save-payment-settings-btn').prop('disabled', false).val('Save Payment Settings');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render general settings
     */
    private function render_general_settings() {
        ?>
        <div class="settings-section" id="payment-general">
            <h2><?php _e('General Settings', 'menu-popup'); ?></h2>
            <p class="description"><?php _e('Configure general payment system settings', 'menu-popup'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="menu_popup_payment_enabled"><?php _e('Enable Payment System', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="menu_popup_payment_enabled" 
                               name="menu_popup_payment_enabled" 
                               value="1" 
                               <?php checked(get_option('menu_popup_payment_enabled', '0'), '1'); ?>>
                        <p class="description"><?php _e('Enable payment functionality for online orders', 'menu-popup'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="menu_popup_payment_currency"><?php _e('Default Currency', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <select id="menu_popup_payment_currency" name="menu_popup_payment_currency">
                            <?php
                            $currencies = [
                                'EGP' => __('Egyptian Pound (EGP)', 'menu-popup'),
                                'USD' => __('US Dollar (USD)', 'menu-popup'),
                                'SAR' => __('Saudi Riyal (SAR)', 'menu-popup'),
                                'AED' => __('UAE Dirham (AED)', 'menu-popup'),
                                'EUR' => __('Euro (EUR)', 'menu-popup'),
                                'GBP' => __('British Pound (GBP)', 'menu-popup')
                            ];
                            $current = get_option('menu_popup_payment_currency', 'EGP');
                            
                            foreach ($currencies as $code => $name) {
                                echo '<option value="' . esc_attr($code) . '" ' . selected($current, $code, false) . '>' . esc_html($name) . '</option>';
                            }
                            ?>
                        </select>
                        <p class="description"><?php _e('Default currency for payments', 'menu-popup'); ?></p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for saving payment settings
     */
    public function ajax_save_payment_settings() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'menu_popup_save_payment_settings')) {
            wp_send_json_error(['message' => 'Security check failed']);
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }
        
        $payment_options = [
            'menu_popup_payment_enabled',
            'menu_popup_payment_currency',
            'menu_popup_payment_cod_enabled',
            'menu_popup_payment_cod_max_amount',
            'menu_popup_payment_cod_min_amount',
            'menu_popup_payment_cod_instructions',
            'menu_popup_payment_bank_transfer_enabled',
            'menu_popup_payment_bank_transfer_bank_name',
            'menu_popup_payment_bank_transfer_account_number',
            'menu_popup_payment_bank_transfer_account_holder',
            'menu_popup_payment_bank_transfer_branch',
            'menu_popup_payment_bank_transfer_iban',
            'menu_popup_payment_bank_transfer_swift_code',
            'menu_popup_payment_bank_transfer_instructions',
            'menu_popup_payment_fawry_enabled',
            'menu_popup_payment_fawry_merchant_code',
            'menu_popup_payment_fawry_secure_key',
            'menu_popup_payment_fawry_sandbox'
        ];
        
        foreach ($payment_options as $option) {
            if (isset($_POST[$option])) {
                update_option($option, sanitize_text_field($_POST[$option]));
            } else {
                // For checkboxes - if not present, it's unchecked
                if (strpos($option, '_enabled') !== false) {
                    update_option($option, '0');
                }
            }
        }
        
        wp_send_json_success(['message' => 'Payment settings saved successfully']);
    }
    
    /**
     * Render COD settings
     */
    private function render_cod_settings() {
        $enabled = get_option('menu_popup_payment_cod_enabled', '0');
        ?>
        <div class="settings-section gateway-section" id="payment-cod">
            <div class="gateway-header">
                <h2><?php _e('Cash on Delivery (COD)', 'menu-popup'); ?></h2>
                <label class="gateway-toggle">
                    <input type="checkbox" 
                           name="menu_popup_payment_cod_enabled" 
                           value="1" 
                           <?php checked($enabled, '1'); ?>
                           onchange="document.getElementById('cod-settings').style.display = this.checked ? 'block' : 'none'">
                    <span class="toggle-label"><?php _e('Enable', 'menu-popup'); ?></span>
                </label>
            </div>
            <p class="description"><?php _e('Allow customers to pay in cash when their order is delivered', 'menu-popup'); ?></p>
            
            <div id="cod-settings" style="display: <?php echo $enabled ? 'block' : 'none'; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_cod_max_amount"><?php _e('Maximum Order Amount', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="menu_popup_payment_cod_max_amount" 
                                   name="menu_popup_payment_cod_max_amount" 
                                   value="<?php echo esc_attr(get_option('menu_popup_payment_cod_max_amount', '')); ?>"
                                   class="regular-text"
                                   step="0.01"
                                   min="0">
                            <p class="description"><?php _e('Maximum order amount for COD (leave empty for unlimited)', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_cod_min_amount"><?php _e('Minimum Order Amount', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="menu_popup_payment_cod_min_amount" 
                                   name="menu_popup_payment_cod_min_amount" 
                                   value="<?php echo esc_attr(get_option('menu_popup_payment_cod_min_amount', '')); ?>"
                                   class="regular-text"
                                   step="0.01"
                                   min="0">
                            <p class="description"><?php _e('Minimum order amount for COD (leave empty for no minimum)', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_cod_instructions"><?php _e('Delivery Instructions', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <textarea 
                                id="menu_popup_payment_cod_instructions" 
                                name="menu_popup_payment_cod_instructions" 
                                class="large-text"
                                rows="3"><?php echo esc_textarea(get_option('menu_popup_payment_cod_instructions', __('Pay in cash to the delivery person when receiving your order.', 'menu-popup'))); ?></textarea>
                            <p class="description"><?php _e('Instructions shown to customers about COD payment', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Bank Transfer settings
     */
    private function render_bank_transfer_settings() {
        $enabled = get_option('menu_popup_payment_bank_transfer_enabled', '0');
        ?>
        <div class="settings-section gateway-section" id="payment-bank-transfer">
            <div class="gateway-header">
                <h2><?php _e('Bank Transfer', 'menu-popup'); ?></h2>
                <label class="gateway-toggle">
                    <input type="checkbox" 
                           name="menu_popup_payment_bank_transfer_enabled" 
                           value="1" 
                           <?php checked($enabled, '1'); ?>
                           onchange="document.getElementById('bank-transfer-settings').style.display = this.checked ? 'block' : 'none'">
                    <span class="toggle-label"><?php _e('Enable', 'menu-popup'); ?></span>
                </label>
            </div>
            <p class="description"><?php _e('Allow customers to pay via bank transfer (manual verification required)', 'menu-popup'); ?></p>
            
            <div id="bank-transfer-settings" style="display: <?php echo $enabled ? 'block' : 'none'; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_bank_transfer_bank_name"><?php _e('Bank Name', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="menu_popup_payment_bank_transfer_bank_name" 
                                   name="menu_popup_payment_bank_transfer_bank_name" 
                                   value="<?php echo esc_attr(get_option('menu_popup_payment_bank_transfer_bank_name', '')); ?>"
                                   class="regular-text">
                            <p class="description"><?php _e('Your bank name', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_bank_transfer_account_number"><?php _e('Account Number', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="menu_popup_payment_bank_transfer_account_number" 
                                   name="menu_popup_payment_bank_transfer_account_number" 
                                   value="<?php echo esc_attr(get_option('menu_popup_payment_bank_transfer_account_number', '')); ?>"
                                   class="regular-text">
                            <p class="description"><?php _e('Your bank account number', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_bank_transfer_account_holder"><?php _e('Account Holder Name', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="menu_popup_payment_bank_transfer_account_holder" 
                                   name="menu_popup_payment_bank_transfer_account_holder" 
                                   value="<?php echo esc_attr(get_option('menu_popup_payment_bank_transfer_account_holder', '')); ?>"
                                   class="regular-text">
                            <p class="description"><?php _e('The account holder name', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_bank_transfer_branch"><?php _e('Branch Name', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="menu_popup_payment_bank_transfer_branch" 
                                   name="menu_popup_payment_bank_transfer_branch" 
                                   value="<?php echo esc_attr(get_option('menu_popup_payment_bank_transfer_branch', '')); ?>"
                                   class="regular-text">
                            <p class="description"><?php _e('Branch name (optional)', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_bank_transfer_iban"><?php _e('IBAN', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="menu_popup_payment_bank_transfer_iban" 
                                   name="menu_popup_payment_bank_transfer_iban" 
                                   value="<?php echo esc_attr(get_option('menu_popup_payment_bank_transfer_iban', '')); ?>"
                                   class="regular-text">
                            <p class="description"><?php _e('IBAN code (optional)', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_bank_transfer_swift_code"><?php _e('SWIFT/BIC Code', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="menu_popup_payment_bank_transfer_swift_code" 
                                   name="menu_popup_payment_bank_transfer_swift_code" 
                                   value="<?php echo esc_attr(get_option('menu_popup_payment_bank_transfer_swift_code', '')); ?>"
                                   class="regular-text">
                            <p class="description"><?php _e('SWIFT/BIC code (optional for international transfers)', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_bank_transfer_instructions"><?php _e('Transfer Instructions', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <textarea 
                                id="menu_popup_payment_bank_transfer_instructions" 
                                name="menu_popup_payment_bank_transfer_instructions" 
                                class="large-text"
                                rows="3"><?php echo esc_textarea(get_option('menu_popup_payment_bank_transfer_instructions', __('Please transfer the order total to the account above. Your order will be processed after payment verification.', 'menu-popup'))); ?></textarea>
                            <p class="description"><?php _e('Instructions shown to customers for making the transfer', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Fawry settings
     */
    private function render_fawry_settings() {
        $enabled = get_option('menu_popup_payment_fawry_enabled', '0');
        $sandbox = get_option('menu_popup_payment_fawry_sandbox', '1');
        ?>
        <div class="settings-section gateway-section" id="payment-fawry">
            <div class="gateway-header">
                <h2><?php _e('Fawry Gateway', 'menu-popup'); ?></h2>
                <label class="gateway-toggle">
                    <input type="checkbox" 
                           name="menu_popup_payment_fawry_enabled" 
                           value="1" 
                           <?php checked($enabled, '1'); ?>
                           onchange="document.getElementById('fawry-settings').style.display = this.checked ? 'block' : 'none'">
                    <span class="toggle-label"><?php _e('Enable', 'menu-popup'); ?></span>
                </label>
            </div>
            <p class="description"><?php _e('Allow customers to pay via Fawry online payment system', 'menu-popup'); ?></p>
            
            <div id="fawry-settings" style="display: <?php echo $enabled ? 'block' : 'none'; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_fawry_merchant_code"><?php _e('Merchant Code', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="menu_popup_payment_fawry_merchant_code" 
                                   name="menu_popup_payment_fawry_merchant_code" 
                                   value="<?php echo esc_attr(get_option('menu_popup_payment_fawry_merchant_code', '')); ?>"
                                   class="regular-text">
                            <p class="description"><?php _e('Your Fawry Merchant Code', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_fawry_secure_key"><?php _e('Secure Key', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="password" 
                                   id="menu_popup_payment_fawry_secure_key" 
                                   name="menu_popup_payment_fawry_secure_key" 
                                   value="<?php echo esc_attr(get_option('menu_popup_payment_fawry_secure_key', '')); ?>"
                                   class="regular-text">
                            <p class="description"><?php _e('Your Fawry Secure Key', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_fawry_sandbox"><?php _e('Sandbox Mode', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" 
                                   id="menu_popup_payment_fawry_sandbox" 
                                   name="menu_popup_payment_fawry_sandbox" 
                                   value="1" 
                                   <?php checked($sandbox, '1'); ?>>
                            <p class="description"><?php _e('Use Fawry sandbox environment for testing. Disable for production.', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_fawry_expiry_hours"><?php _e('Payment Expiry (Hours)', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="menu_popup_payment_fawry_expiry_hours" 
                                   name="menu_popup_payment_fawry_expiry_hours" 
                                   value="<?php echo esc_attr(get_option('menu_popup_payment_fawry_expiry_hours', '24')); ?>"
                                   class="small-text"
                                   min="1"
                                   max="72">
                            <p class="description"><?php _e('Number of hours before payment expires (1-72 hours)', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <div class="fawry-test-connection">
                    <button type="button" class="button" id="test-fawry-connection">
                        <?php _e('Test Fawry Connection', 'menu-popup'); ?>
                    </button>
                    <span class="test-result" id="fawry-test-result"></span>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Sanitize checkbox
     * 
     * @param mixed $value
     * @return string
     */
    public function sanitize_checkbox($value) {
        return !empty($value) ? '1' : '0';
    }
    
    /**
     * Sanitize amount
     * 
     * @param mixed $value
     * @return string
     */
    public function sanitize_amount($value) {
        if (empty($value)) {
            return '';
        }
        
        $value = floatval($value);
        
        return $value >= 0 ? strval($value) : '';
    }
}
