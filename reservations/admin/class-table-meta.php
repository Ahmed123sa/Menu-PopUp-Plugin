<?php
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Table_Meta
{

    public function __construct()
    {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));
    }

    public function add_meta_boxes()
    {
        add_meta_box(
            'menu_popup_table_details',
            __('Table Details', 'menu-popup'),
            array($this, 'render_meta_box'),
            'restaurant_table',
            'normal',
            'high'
        );
    }

    public function render_meta_box($post)
    {
        wp_nonce_field('menu_popup_table_meta_nonce', 'menu_popup_table_meta_nonce');

        $branch_id = get_post_meta($post->ID, '_branch_id', true);
        $min_pax = get_post_meta($post->ID, '_min_pax', true);
        $max_pax = get_post_meta($post->ID, '_max_pax', true); // Replaces _table_capacity
        if (empty($max_pax))
            $max_pax = get_post_meta($post->ID, '_table_capacity', true); // Migration fallback

        $shape = get_post_meta($post->ID, '_shape', true);
        $zone = get_post_meta($post->ID, '_zone', true);

        // Get all branches
        $branches = get_posts(array('post_type' => 'menu_popup_branch', 'posts_per_page' => -1));
        ?>
        <div class="menu-popup-meta-wrapper">
            <p>
                <label for="branch_id"><strong><?php _e('Branch', 'menu-popup'); ?></strong></label><br>
                <select name="branch_id" id="branch_id" class="widefat">
                    <option value=""><?php _e('Select Branch', 'menu-popup'); ?></option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch->ID; ?>" <?php selected($branch_id, $branch->ID); ?>>
                            <?php echo esc_html($branch->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <div style="display: flex; gap: 20px;">
                <p style="flex: 1;">
                    <label for="min_pax"><strong><?php _e('Min Capacity', 'menu-popup'); ?></strong></label><br>
                    <input type="number" name="min_pax" id="min_pax" value="<?php echo esc_attr($min_pax); ?>" class="widefat"
                        min="1">
                </p>
                <p style="flex: 1;">
                    <label for="max_pax"><strong><?php _e('Max Capacity', 'menu-popup'); ?></strong></label><br>
                    <input type="number" name="max_pax" id="max_pax" value="<?php echo esc_attr($max_pax); ?>" class="widefat"
                        min="1">
                </p>
            </div>

            <div style="display: flex; gap: 20px;">
                <p style="flex: 1;">
                    <label for="shape"><strong><?php _e('Shape', 'menu-popup'); ?></strong></label><br>
                    <select name="shape" id="shape" class="widefat">
                        <option value="square" <?php selected($shape, 'square'); ?>><?php _e('Square', 'menu-popup'); ?>
                        </option>
                        <option value="round" <?php selected($shape, 'round'); ?>><?php _e('Round', 'menu-popup'); ?></option>
                        <option value="rectangle" <?php selected($shape, 'rectangle'); ?>>
                            <?php _e('Rectangle', 'menu-popup'); ?></option>
                    </select>
                </p>
                <p style="flex: 1;">
                    <label for="zone"><strong><?php _e('Zone', 'menu-popup'); ?></strong></label><br>
                    <select name="zone" id="zone" class="widefat">
                        <option value="indoor" <?php selected($zone, 'indoor'); ?>><?php _e('Indoor', 'menu-popup'); ?></option>
                        <option value="terrace" <?php selected($zone, 'terrace'); ?>><?php _e('Terrace', 'menu-popup'); ?>
                        </option>
                        <option value="vip" <?php selected($zone, 'vip'); ?>><?php _e('VIP Room', 'menu-popup'); ?></option>
                    </select>
                </p>
            </div>
        </div>
        <?php
    }

    public function save_meta_boxes($post_id)
    {
        if (!isset($_POST['menu_popup_table_meta_nonce']) || !wp_verify_nonce($_POST['menu_popup_table_meta_nonce'], 'menu_popup_table_meta_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = array('branch_id', 'min_pax', 'max_pax', 'shape', 'zone');
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }

        // Sync legacy field
        if (isset($_POST['max_pax'])) {
            update_post_meta($post_id, '_table_capacity', sanitize_text_field($_POST['max_pax']));
        }
    }
}
