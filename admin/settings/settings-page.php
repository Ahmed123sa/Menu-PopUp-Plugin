<?php
/**
 * Settings Page Template
 * 
 * Modular settings page with tabs
 * 
 * @package MenuPopup
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current tab
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
$tabs = [
    'general' => __('General', 'menu-popup'),
    'payment' => __('Payment', 'menu-popup'),
    'voice' => __('Voice & Search', 'menu-popup'),
    'branches' => __('Branches', 'menu-popup'),
    'accounting' => __('Accounting', 'menu-popup'),
    'appearance' => __('Appearance', 'menu-popup')
];
?>

<div class="wrap menu-popup-settings-wrap">
    <h1><?php _e('Menu Pop-up Settings', 'menu-popup'); ?></h1>
    
    <h2 class="nav-tab-wrapper">
        <?php foreach ($tabs as $tab_key => $tab_label): ?>
            <a href="?page=menu-popup-settings&tab=<?php echo esc_attr($tab_key); ?>" 
               class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($tab_label); ?>
            </a>
        <?php endforeach; ?>
    </h2>
    
    <form method="post" action="options.php" class="menu-popup-settings-form">
        <?php
        // Output nonce fields for the active tab
        settings_fields('menu_popup_' . $active_tab);
        
        // Render the active tab content
        switch ($active_tab) {
            case 'general':
                $general = new Menu_Popup_Settings_General();
                $general->render_general_settings();
                break;
                
            case 'payment':
                $payment = new Menu_Popup_Settings_Payment();
                $payment->render_payment_settings();
                break;
                
            case 'voice':
                $voice = new Menu_Popup_Settings_Voice();
                $voice->render_voice_settings();
                break;
                
            case 'branches':
                ?>
                <div class="menu-popup-settings-section" id="branches-settings">
                    <h2><?php _e('Branch Settings', 'menu-popup'); ?></h2>
                    <p><?php _e('Branch management settings are handled in the Branches admin page.', 'menu-popup'); ?></p>
                    <p><a href="<?php echo admin_url('admin.php?page=menu-popup-branches'); ?>" class="button"><?php _e('Manage Branches', 'menu-popup'); ?></a></p>
                </div>
                <?php
                break;
                
            case 'accounting':
                ?>
                <div class="menu-popup-settings-section" id="accounting-settings">
                    <h2><?php _e('Accounting Settings', 'menu-popup'); ?></h2>
                    <p><?php _e('Accounting settings are managed through the Order Dashboard.', 'menu-popup'); ?></p>
                    <p><a href="<?php echo admin_url('admin.php?page=menu-popup-orders'); ?>" class="button"><?php _e('View Orders', 'menu-popup'); ?></a></p>
                </div>
                <?php
                break;
                
            case 'appearance':
                ?>
                <div class="menu-popup-settings-section" id="appearance-settings">
                    <h2><?php _e('Appearance Settings', 'menu-popup'); ?></h2>
                    
                    <?php
                    $primary_color = get_option('menu_popup_primary_color', '#ff6b35');
                    $secondary_color = get_option('menu_popup_secondary_color', '#2d3436');
                    $accent_color = get_option('menu_popup_accent_color', '#00cec9');
                    ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="menu_popup_primary_color"><?php _e('Primary Color', 'menu-popup'); ?></label>
                            </th>
                            <td>
                                <input type="color" id="menu_popup_primary_color" name="menu_popup_primary_color" value="<?php echo esc_attr($primary_color); ?>">
                                <p class="description"><?php _e('Main brand color', 'menu-popup'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="menu_popup_secondary_color"><?php _e('Secondary Color', 'menu-popup'); ?></label>
                            </th>
                            <td>
                                <input type="color" id="menu_popup_secondary_color" name="menu_popup_secondary_color" value="<?php echo esc_attr($secondary_color); ?>">
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="menu_popup_accent_color"><?php _e('Accent Color', 'menu-popup'); ?></label>
                            </th>
                            <td>
                                <input type="color" id="menu_popup_accent_color" name="menu_popup_accent_color" value="<?php echo esc_attr($accent_color); ?>">
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(__('Save Appearance', 'menu-popup')); ?>
                </div>
                <?php
                break;
                
            default:
                ?>
                <div class="notice notice-warning">
                    <p><?php _e('Settings for this section are being updated.', 'menu-popup'); ?></p>
                </div>
                <?php
                break;
        }
        ?>
    </form>
</div>

<style>
.menu-popup-settings-wrap {
    max-width: 1200px;
}

.menu-popup-settings-section {
    background: #fff;
    padding: 20px;
    margin-top: 20px;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,0.04);
}

.menu-popup-settings-section h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.menu-popup-settings-section h3 {
    margin-top: 30px;
    margin-bottom: 15px;
    font-size: 1.1em;
    color: #23282d;
}

.nav-tab-wrapper {
    margin-bottom: 20px;
}
</style>
