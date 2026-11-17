<?php
/**
 * General Settings Module
 * 
 * @package MenuPopup
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * General Settings Class
 */
class Menu_Popup_Settings_General {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Settings are registered in the loader
    }
    
    /**
     * Render general settings
     */
    public function render_general_settings() {
        $enable_videos = get_option('menu_popup_enable_videos', '1');
        $animation_speed = get_option('menu_popup_animation_speed', '300');
        $show_price_card = get_option('menu_popup_show_price_card', '1');
        $show_price_modal = get_option('menu_popup_show_price_modal', '1');
        $default_layout = get_option('menu_popup_default_layout', 'grid');
        $items_per_page = get_option('menu_popup_items_per_page', '12');
        ?>
        <div class="menu-popup-settings-section" id="general-settings">
            <h2><?php _e('General Settings', 'menu-popup'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="menu_popup_enable_videos"><?php _e('Enable Video Popups', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="menu_popup_enable_videos" name="menu_popup_enable_videos" value="1" <?php checked($enable_videos, '1'); ?>>
                        <p class="description"><?php _e('Show video popup when clicking on menu items with video URLs', 'menu-popup'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="menu_popup_animation_speed"><?php _e('Animation Speed', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="menu_popup_animation_speed" name="menu_popup_animation_speed" value="<?php echo esc_attr($animation_speed); ?>" min="0" max="2000" step="50">
                        <p class="description"><?php _e('Animation speed in milliseconds (0-2000)', 'menu-popup'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="menu_popup_default_layout"><?php _e('Default Layout', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <select id="menu_popup_default_layout" name="menu_popup_default_layout">
                            <option value="grid" <?php selected($default_layout, 'grid'); ?>><?php _e('Grid', 'menu-popup'); ?></option>
                            <option value="list" <?php selected($default_layout, 'list'); ?>><?php _e('List', 'menu-popup'); ?></option>
                            <option value="masonry" <?php selected($default_layout, 'masonry'); ?>><?php _e('Masonry', 'menu-popup'); ?></option>
                        </select>
                        <p class="description"><?php _e('Default menu layout', 'menu-popup'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="menu_popup_items_per_page"><?php _e('Items Per Page', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="menu_popup_items_per_page" name="menu_popup_items_per_page" value="<?php echo esc_attr($items_per_page); ?>" min="1" max="100">
                        <p class="description"><?php _e('Number of menu items to display per page', 'menu-popup'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="menu_popup_show_price_card"><?php _e('Show Price on Card', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="menu_popup_show_price_card" name="menu_popup_show_price_card" value="1" <?php checked($show_price_card, '1'); ?>>
                        <p class="description"><?php _e('Display price on menu item cards', 'menu-popup'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="menu_popup_show_price_modal"><?php _e('Show Price in Modal', 'menu-popup'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" id="menu_popup_show_price_modal" name="menu_popup_show_price_modal" value="1" <?php checked($show_price_modal, '1'); ?>>
                        <p class="description"><?php _e('Display price in item detail modal', 'menu-popup'); ?></p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('Save General Settings', 'menu-popup')); ?>
        </div>
        <?php
    }
    
    /**
     * Sanitize general settings
     */
    public function sanitize_settings($input) {
        $output = [];
        
        $output['menu_popup_enable_videos'] = isset($input['menu_popup_enable_videos']) ? '1' : '0';
        $output['menu_popup_animation_speed'] = isset($input['menu_popup_animation_speed']) ? absint($input['menu_popup_animation_speed']) : '300';
        $output['menu_popup_show_price_card'] = isset($input['menu_popup_show_price_card']) ? '1' : '0';
        $output['menu_popup_show_price_modal'] = isset($input['menu_popup_show_price_modal']) ? '1' : '0';
        $output['menu_popup_default_layout'] = isset($input['menu_popup_default_layout']) ? sanitize_text_field($input['menu_popup_default_layout']) : 'grid';
        $output['menu_popup_items_per_page'] = isset($input['menu_popup_items_per_page']) ? absint($input['menu_popup_items_per_page']) : '12';
        
        return $output;
    }
}

// Initialize
new Menu_Popup_Settings_General();
