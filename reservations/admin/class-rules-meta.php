<?php
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Rules_Meta
{

    public function __construct()
    {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));
    }

    public function add_meta_boxes()
    {
        add_meta_box(
            'menu_popup_rule_details',
            __('Rule Configuration', 'menu-popup'),
            array($this, 'render_meta_box'),
            'reservation_rule',
            'normal',
            'high'
        );
    }

    public function render_meta_box($post)
    {
        wp_nonce_field('menu_popup_rule_meta_nonce', 'menu_popup_rule_meta_nonce');

        $rule_type = get_post_meta($post->ID, '_rule_type', true);
        $rule_date = get_post_meta($post->ID, '_rule_date', true);
        $branch_id = get_post_meta($post->ID, '_branch_id', true);
        $blocked_zone = get_post_meta($post->ID, '_blocked_zone', true);

        // Get all branches
        $branches = get_posts(array('post_type' => 'menu_popup_branch', 'posts_per_page' => -1));
        ?>
        <div class="menu-popup-meta-wrapper">
            <p>
                <label for="rule_type"><strong><?php _e('Rule Type', 'menu-popup'); ?></strong></label><br>
                <select name="rule_type" id="rule_type" class="widefat">
                    <option value="blocked_date" <?php selected($rule_type, 'blocked_date'); ?>>
                        <?php _e('Block Entire Date (Holiday)', 'menu-popup'); ?></option>
                    <option value="blocked_zone" <?php selected($rule_type, 'blocked_zone'); ?>>
                        <?php _e('Block Specific Zone', 'menu-popup'); ?></option>
                    <!-- Future: custom_hours -->
                </select>
            </p>

            <p>
                <label for="rule_date"><strong><?php _e('Date', 'menu-popup'); ?></strong></label><br>
                <input type="date" name="rule_date" id="rule_date" value="<?php echo esc_attr($rule_date); ?>" class="widefat">
            </p>

            <p>
                <label for="branch_id"><strong><?php _e('Apply to Branch', 'menu-popup'); ?></strong></label><br>
                <select name="branch_id" id="branch_id" class="widefat">
                    <option value=""><?php _e('All Branches', 'menu-popup'); ?></option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch->ID; ?>" <?php selected($branch_id, $branch->ID); ?>>
                            <?php echo esc_html($branch->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p id="zone_field" style="<?php echo $rule_type === 'blocked_zone' ? '' : 'display:none;'; ?>">
                <label for="blocked_zone"><strong><?php _e('Zone to Block', 'menu-popup'); ?></strong></label><br>
                <select name="blocked_zone" id="blocked_zone" class="widefat">
                    <option value="terrace" <?php selected($blocked_zone, 'terrace'); ?>><?php _e('Terrace', 'menu-popup'); ?>
                    </option>
                    <option value="indoor" <?php selected($blocked_zone, 'indoor'); ?>><?php _e('Indoor', 'menu-popup'); ?>
                    </option>
                    <option value="vip" <?php selected($blocked_zone, 'vip'); ?>><?php _e('VIP Room', 'menu-popup'); ?></option>
                </select>
            </p>

            <script>
                jQuery(document).ready(function ($) {
                    $('#rule_type').change(function () {
                        if ($(this).val() === 'blocked_zone') {
                            $('#zone_field').show();
                        } else {
                            $('#zone_field').hide();
                        }
                    });
                });
            </script>
        </div>
        <?php
    }

    public function save_meta_boxes($post_id)
    {
        if (!isset($_POST['menu_popup_rule_meta_nonce']) || !wp_verify_nonce($_POST['menu_popup_rule_meta_nonce'], 'menu_popup_rule_meta_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = array('rule_type', 'rule_date', 'branch_id', 'blocked_zone');
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }
    }
}
