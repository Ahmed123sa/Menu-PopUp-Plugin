<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Admin_Branches extends Menu_Popup_Abstract_Handler {

    private $version;
    private $utils;

    public function __construct($version, $utils) {
        $this->version = $version;
        $this->utils = $utils;
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('add_meta_boxes', array($this, 'add_branch_meta_boxes'));
        add_action('save_post_menu_popup_branch', array($this, 'save_branch_meta'));
    }

    public function add_branch_meta_boxes() {
        add_meta_box(
            'menu_popup_branch_details',
            __('Branch Details', 'menu-popup'),
            array($this, 'render_branch_meta_box'),
            'menu_popup_branch',
            'normal',
            'high'
        );
    }

    public function render_branch_meta_box($post) {
        wp_nonce_field('menu_popup_branch_meta_box', 'menu_popup_branch_meta_box_nonce');

        $whatsapp = get_post_meta($post->ID, '_menu_popup_branch_whatsapp', true);
        $address = get_post_meta($post->ID, '_menu_popup_branch_address', true);
        $hours = get_post_meta($post->ID, '_menu_popup_branch_hours', true);
        ?>
        <table class="form-table">
            <tr>
                <th><label for="menu_popup_branch_whatsapp"><?php _e('WhatsApp Number', 'menu-popup'); ?></label></th>
                <td><input type="text" id="menu_popup_branch_whatsapp" name="menu_popup_branch_whatsapp" value="<?php echo esc_attr($whatsapp); ?>" size="30" placeholder="+1234567890" /></td>
            </tr>
            <tr>
                <th><label for="menu_popup_branch_address"><?php _e('Address', 'menu-popup'); ?></label></th>
                <td><textarea id="menu_popup_branch_address" name="menu_popup_branch_address" rows="3" cols="50"><?php echo esc_textarea($address); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="menu_popup_branch_hours"><?php _e('Business Hours', 'menu-popup'); ?></label></th>
                <td><textarea id="menu_popup_branch_hours" name="menu_popup_branch_hours" rows="3" cols="50" placeholder="Mon-Fri: 9AM-10PM&#10;Sat-Sun: 10AM-11PM"><?php echo esc_textarea($hours); ?></textarea></td>
            </tr>
        </table>
        <?php
    }

    public function save_branch_meta($post_id) {
        if (!isset($_POST['menu_popup_branch_meta_box_nonce']) || !wp_verify_nonce($_POST['menu_popup_branch_meta_box_nonce'], 'menu_popup_branch_meta_box')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (isset($_POST['menu_popup_branch_whatsapp'])) {
            update_post_meta($post_id, '_menu_popup_branch_whatsapp', sanitize_text_field($_POST['menu_popup_branch_whatsapp']));
        }
        if (isset($_POST['menu_popup_branch_address'])) {
            update_post_meta($post_id, '_menu_popup_branch_address', sanitize_textarea_field($_POST['menu_popup_branch_address']));
        }
        if (isset($_POST['menu_popup_branch_hours'])) {
            update_post_meta($post_id, '_menu_popup_branch_hours', sanitize_textarea_field($_POST['menu_popup_branch_hours']));
        }
    }
}
