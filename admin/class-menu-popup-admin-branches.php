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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_branch_map_assets'));
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
        $lat = get_post_meta($post->ID, '_branch_lat', true);
        $lng = get_post_meta($post->ID, '_branch_lng', true);
        $delivery_on = get_post_meta($post->ID, '_branch_delivery_enabled', true) !== '0';
        $has_key = (bool) get_option('menu_popup_google_maps_api_key', '');
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
            <tr>
                <th><label><?php _e('Location on Map', 'menu-popup'); ?></label></th>
                <td>
                    <input type="number" step="any" id="menu_popup_branch_lat" name="menu_popup_branch_lat" value="<?php echo esc_attr($lat); ?>" placeholder="<?php esc_attr_e('Latitude', 'menu-popup'); ?>" style="width:140px" />
                    <input type="number" step="any" id="menu_popup_branch_lng" name="menu_popup_branch_lng" value="<?php echo esc_attr($lng); ?>" placeholder="<?php esc_attr_e('Longitude', 'menu-popup'); ?>" style="width:140px" />
                    <p class="description"><?php _e('Used for distance pricing and to suggest the nearest branch. Click the map to set it.', 'menu-popup'); ?></p>
                    <?php if ($has_key) : ?>
                        <div id="mp-branch-map" style="width:100%;max-width:640px;height:260px;margin-top:8px;border:1px solid #c3c4c7;border-radius:4px;"></div>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="menu_popup_branch_delivery_enabled"><?php _e('Delivery', 'menu-popup'); ?></label></th>
                <td>
                    <input type="hidden" name="menu_popup_branch_delivery_present" value="1" />
                    <label><input type="checkbox" id="menu_popup_branch_delivery_enabled" name="menu_popup_branch_delivery_enabled" value="1" <?php checked($delivery_on); ?> /> <?php _e('This branch delivers', 'menu-popup'); ?></label>
                </td>
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

        // Map location: both values or none
        if (isset($_POST['menu_popup_branch_lat'], $_POST['menu_popup_branch_lng'])) {
            $lat = $_POST['menu_popup_branch_lat'];
            $lng = $_POST['menu_popup_branch_lng'];
            if (is_numeric($lat) && is_numeric($lng) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                update_post_meta($post_id, '_branch_lat', (string) round((float) $lat, 7));
                update_post_meta($post_id, '_branch_lng', (string) round((float) $lng, 7));
            } elseif ($lat === '' && $lng === '') {
                delete_post_meta($post_id, '_branch_lat');
                delete_post_meta($post_id, '_branch_lng');
            }
        }

        if (isset($_POST['menu_popup_branch_delivery_present'])) {
            update_post_meta($post_id, '_branch_delivery_enabled', isset($_POST['menu_popup_branch_delivery_enabled']) ? '1' : '0');
        }
    }

    /**
     * Map picker on the branch edit screen
     */
    public function enqueue_branch_map_assets($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'menu_popup_branch') {
            return;
        }
        $key = get_option('menu_popup_google_maps_api_key', '');
        if (!$key) {
            return;
        }

        wp_enqueue_script(
            'google-maps-api',
            'https://maps.googleapis.com/maps/api/js?key=' . rawurlencode($key),
            array(),
            null,
            true
        );

        $center = array(
            'lat' => (float) get_option('menu_popup_map_default_lat', '30.0444'),
            'lng' => (float) get_option('menu_popup_map_default_lng', '31.2357'),
        );

        wp_register_script('mp-branch-map', false, array('google-maps-api'), null, true);
        wp_enqueue_script('mp-branch-map');
        wp_add_inline_script('mp-branch-map', '(function(){
            var el=document.getElementById("mp-branch-map"); if(!el||!window.google){return;}
            var latIn=document.getElementById("menu_popup_branch_lat"), lngIn=document.getElementById("menu_popup_branch_lng");
            var def=' . wp_json_encode($center) . ';
            var has=latIn.value!==""&&lngIn.value!=="";
            var start=has?{lat:parseFloat(latIn.value),lng:parseFloat(lngIn.value)}:def;
            var map=new google.maps.Map(el,{center:start,zoom:has?16:11,streetViewControl:false,mapTypeControl:false});
            var marker=new google.maps.Marker({map:map,position:start,draggable:true,visible:has});
            function set(p){marker.setPosition(p);marker.setVisible(true);latIn.value=p.lat().toFixed(7);lngIn.value=p.lng().toFixed(7);}
            map.addListener("click",function(e){set(e.latLng);});
            marker.addListener("dragend",function(){set(marker.getPosition());});
        })();');
    }
}
