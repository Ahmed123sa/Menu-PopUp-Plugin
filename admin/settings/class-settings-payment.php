<?php
/**
 * Payment Settings Module
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
class Menu_Popup_Settings_Payment {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Settings are registered in the loader
    }
    
    /**
     * Render payment settings
     */
    public function render_payment_settings() {
        $payment_enabled = get_option('menu_popup_payment_enabled', '0');
        $currency = get_option('menu_popup_payment_currency', 'EGP');
        $currency_symbol = get_option('menu_popup_payment_currency_symbol', '£');
        $tax_enabled = get_option('menu_popup_tax_enabled', '0');
        $tax_rate = get_option('menu_popup_tax_rate', '14');
        $minimum_order = get_option('menu_popup_minimum_order', '0');
        
        // Gateway settings
        $cod_enabled = get_option('menu_popup_payment_cod_enabled', '1');
        $bank_transfer_enabled = get_option('menu_popup_payment_bank_transfer_enabled', '1');
        $fawry_enabled = get_option('menu_popup_payment_fawry_enabled', '0');
        
        // Fawry API settings
        $fawry_merchant_code = get_option('menu_popup_payment_fawry_merchant_code', '');
        $fawry_secret_key = get_option('menu_popup_payment_fawry_secret_key', '');
        $fawry_test_mode = get_option('menu_popup_payment_fawry_test_mode', '1');
        
        ?>
        <div class="menu-popup-settings-section" id="payment-settings">
            <h2><?php _e('Payment Settings', 'menu-popup'); ?></h2>
            
            <h3><?php _e('General Payment', 'menu-popup'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="menu_popup_payment_enabled"><?php _e('Enable Payments', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="menu_popup_payment_enabled" name="menu_popup_payment_enabled" value="1" <?php checked($payment_enabled, '1'); ?>>
                        <p class="description"><?php _e('Enable online payment functionality', 'menu-popup'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="menu_popup_payment_currency"><?php _e('Currency', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <select id="menu_popup_payment_currency" name="menu_popup_payment_currency">
                            <option value="EGP" <?php selected($currency, 'EGP'); ?>><?php _e('Egyptian Pound (EGP)', 'menu-popup'); ?></option>
                            <option value="USD" <?php selected($currency, 'USD'); ?>><?php _e('US Dollar (USD)', 'menu-popup'); ?></option>
                            <option value="SAR" <?php selected($currency, 'SAR'); ?>><?php _e('Saudi Riyal (SAR)', 'menu-popup'); ?></option>
                            <option value="AED" <?php selected($currency, 'AED'); ?>><?php _e('UAE Dirham (AED)', 'menu-popup'); ?></option>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="menu_popup_payment_currency_symbol"><?php _e('Currency Symbol', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="menu_popup_payment_currency_symbol" name="menu_popup_payment_currency_symbol" value="<?php echo esc_attr($currency_symbol); ?>" class="small-text">
                    </td>
                </tr>
            </table>
            
            <h3><?php _e('Tax Settings', 'menu-popup'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="menu_popup_tax_enabled"><?php _e('Enable Tax', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="menu_popup_tax_enabled" name="menu_popup_tax_enabled" value="1" <?php checked($tax_enabled, '1'); ?>>
                        <p class="description"><?php _e('Add tax to orders automatically', 'menu-popup'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="menu_popup_tax_rate"><?php _e('Tax Rate (%)', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="menu_popup_tax_rate" name="menu_popup_tax_rate" value="<?php echo esc_attr($tax_rate); ?>" min="0" max="100" step="0.1">
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="menu_popup_minimum_order"><?php _e('Minimum Order Amount', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="menu_popup_minimum_order" name="menu_popup_minimum_order" value="<?php echo esc_attr($minimum_order); ?>" min="0" step="0.01">
                        <p class="description"><?php _e('Minimum order value for checkout', 'menu-popup'); ?></p>
                    </td>
                </tr>
            </table>
            
            <h3><?php _e('Payment Gateways', 'menu-popup'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Cash on Delivery', 'menu-popup'); ?></th>
                    <td>
                        <input type="checkbox" id="menu_popup_payment_cod_enabled" name="menu_popup_payment_cod_enabled" value="1" <?php checked($cod_enabled, '1'); ?>>
                        <label for="menu_popup_payment_cod_enabled"><?php _e('Enable Cash on Delivery', 'menu-popup'); ?></label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Bank Transfer', 'menu-popup'); ?></th>
                    <td>
                        <input type="checkbox" id="menu_popup_payment_bank_transfer_enabled" name="menu_popup_payment_bank_transfer_enabled" value="1" <?php checked($bank_transfer_enabled, '1'); ?>>
                        <label for="menu_popup_payment_bank_transfer_enabled"><?php _e('Enable Bank Transfer', 'menu-popup'); ?></label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Fawry', 'menu-popup'); ?></th>
                    <td>
                        <input type="checkbox" id="menu_popup_payment_fawry_enabled" name="menu_popup_payment_fawry_enabled" value="1" <?php checked($fawry_enabled, '1'); ?>>
                        <label for="menu_popup_payment_fawry_enabled"><?php _e('Enable Fawry Payment', 'menu-popup'); ?></label>
                    </td>
                </tr>
            </table>
            
            <div id="fawry-settings" style="<?php echo $fawry_enabled ? '' : 'display:none;'; ?>">
                <h3><?php _e('Fawry API Configuration', 'menu-popup'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_fawry_merchant_code"><?php _e('Merchant Code', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="menu_popup_payment_fawry_merchant_code" name="menu_popup_payment_fawry_merchant_code" value="<?php echo esc_attr($fawry_merchant_code); ?>" class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_fawry_secret_key"><?php _e('Secret Key', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="menu_popup_payment_fawry_secret_key" name="menu_popup_payment_fawry_secret_key" value="<?php echo esc_attr($fawry_secret_key); ?>" class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_fawry_test_mode"><?php _e('Test Mode', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="menu_popup_payment_fawry_test_mode" name="menu_popup_payment_fawry_test_mode" value="1" <?php checked($fawry_test_mode, '1'); ?>>
                            <label for="menu_popup_payment_fawry_test_mode"><?php _e('Use Fawry Sandbox', 'menu-popup'); ?></label>
                        </td>
                    </tr>
                </table>
            </div>
            
            <?php submit_button(__('Save Payment Settings', 'menu-popup')); ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#menu_popup_payment_fawry_enabled').on('change', function() {
                $('#fawry-settings').toggle(this.checked);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Sanitize payment settings
     */
    public function sanitize_settings($input) {
        $output = [];
        
        $output['menu_popup_payment_enabled'] = isset($input['menu_popup_payment_enabled']) ? '1' : '0';
        $output['menu_popup_payment_currency'] = isset($input['menu_popup_payment_currency']) ? sanitize_text_field($input['menu_popup_payment_currency']) : 'EGP';
        $output['menu_popup_payment_currency_symbol'] = isset($input['menu_popup_payment_currency_symbol']) ? sanitize_text_field($input['menu_popup_payment_currency_symbol']) : '£';
        $output['menu_popup_tax_enabled'] = isset($input['menu_popup_tax_enabled']) ? '1' : '0';
        $output['menu_popup_tax_rate'] = isset($input['menu_popup_tax_rate']) ? floatval($input['menu_popup_tax_rate']) : '14';
        $output['menu_popup_minimum_order'] = isset($input['menu_popup_minimum_order']) ? floatval($input['menu_popup_minimum_order']) : '0';
        $output['menu_popup_payment_cod_enabled'] = isset($input['menu_popup_payment_cod_enabled']) ? '1' : '0';
        $output['menu_popup_payment_bank_transfer_enabled'] = isset($input['menu_popup_payment_bank_transfer_enabled']) ? '1' : '0';
        $output['menu_popup_payment_fawry_enabled'] = isset($input['menu_popup_payment_fawry_enabled']) ? '1' : '0';
        $output['menu_popup_payment_fawry_merchant_code'] = isset($input['menu_popup_payment_fawry_merchant_code']) ? sanitize_text_field($input['menu_popup_payment_fawry_merchant_code']) : '';
        $output['menu_popup_payment_fawry_secret_key'] = isset($input['menu_popup_payment_fawry_secret_key']) ? sanitize_text_field($input['menu_popup_payment_fawry_secret_key']) : '';
        $output['menu_popup_payment_fawry_test_mode'] = isset($input['menu_popup_payment_fawry_test_mode']) ? '1' : '0';
        
        return $output;
    }
}

// Initialize
new Menu_Popup_Settings_Payment();
