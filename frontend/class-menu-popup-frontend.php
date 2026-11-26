<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Frontend extends Menu_Popup_Abstract_Handler {

    private $version;
    private $utils;

    public function __construct($version, $utils) {
        $this->version = $version;
        $this->utils = $utils;
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'));
        add_action('init', array($this, 'init_shortcode'));
        add_action('wp_ajax_filter_menu_items', array($this, 'ajax_filter_menu_items'));
        add_action('wp_ajax_nopriv_filter_menu_items', array($this, 'ajax_filter_menu_items'));
        add_action('wp_ajax_get_branches_by_city', array($this, 'ajax_get_branches_by_city'));
        add_action('wp_ajax_nopriv_get_branches_by_city', array($this, 'ajax_get_branches_by_city'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_swiper_assets'));
    }

    public function init_shortcode() {
        // Only register shortcode on frontend to prevent admin-side processing issues
        if (!is_admin()) {
            add_shortcode('menu-popup', array($this, 'menu_popup_shortcode'));
            add_shortcode('menu-popup-featured', array($this, 'menu_popup_featured_shortcode'));
        }
    }

    private static function mp_hex_rgb($c) {
        $c = trim((string) $c);
        if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c, $m)) {
            return null;
        }
        $h = $m[1];
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        return array(hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)));
    }

    private static function mp_luminance($rgb) {
        $out = array();
        foreach ($rgb as $v) {
            $v = $v / 255;
            $out[] = ($v <= 0.03928) ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $out[0] + 0.7152 * $out[1] + 0.0722 * $out[2];
    }

    private static function mp_ratio($a, $b) {
        $la = self::mp_luminance($a);
        $lb = self::mp_luminance($b);
        if ($la < $lb) {
            $t = $la; $la = $lb; $lb = $t;
        }
        return ($la + 0.05) / ($lb + 0.05);
    }

    /**
     * Pick the readable text colour (dark or white) for one or more background colours.
     * Returns an empty string when a colour is not a plain hex value.
     */
    private static function mp_contrast_text($backgrounds) {
        $rgbs = array();
        foreach ((array) $backgrounds as $bg) {
            $rgb = self::mp_hex_rgb($bg);
            if ($rgb === null) {
                return '';
            }
            $rgbs[] = $rgb;
        }
        $best = '';
        $best_score = -1;
        foreach (array('#111111', '#ffffff') as $candidate) {
            $c = self::mp_hex_rgb($candidate);
            $min = 21;
            foreach ($rgbs as $rgb) {
                $min = min($min, self::mp_ratio($c, $rgb));
            }
            if ($min > $best_score) {
                $best_score = $min;
                $best = $candidate;
            }
        }
        return $best;
    }

    /**
     * CSS custom properties holding readable text colours for buttons that sit on the
     * primary / accent colours, so button labels never disappear into their background.
     */
    public static function contrast_vars() {
        $primary = get_option('menu_popup_primary_color', '#A855F7');
        $accent = get_option('menu_popup_accent_color', '#F59E0B');
        $out = '';
        $on_primary = self::mp_contrast_text(array($primary));
        $on_accent = self::mp_contrast_text(array($accent));
        $on_gradient = self::mp_contrast_text(array($primary, $accent));
        if ($on_primary !== '') {
            $out .= '--menu-on-primary: ' . $on_primary . '; ';
        }
        if ($on_accent !== '') {
            $out .= '--menu-on-accent: ' . $on_accent . '; ';
        }
        if ($on_gradient !== '') {
            $out .= '--menu-on-gradient: ' . $on_gradient . '; ';
        }
        return $out;
    }

    public function frontend_enqueue_scripts() {
        wp_enqueue_style('menu-popup-frontend', MENU_POPUP_PLUGIN_URL . 'assets/css/menu-popup-frontend.css', array(), $this->version);
        wp_enqueue_script('menu-popup-frontend', MENU_POPUP_PLUGIN_URL . 'assets/js/menu-popup-frontend.js', array('jquery'), $this->version, true);
        wp_enqueue_script('menu-popup-a11y', MENU_POPUP_PLUGIN_URL . 'assets/js/menu-popup-a11y.js', array('jquery'), $this->version, true);
        
        // Localize script with payment settings for menu-popup-frontend.js
        // Note: Same settings are also localized to payment-frontend.js
        if (get_option('menu_popup_payment_enabled', '0') === '1') {
            wp_localize_script('menu-popup-frontend', 'menuPopupPayment', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('menu_popup_payment_nonce'),
                'currency' => get_option('menu_popup_payment_currency', 'EGP'),
                'currency_symbol' => '£',
                'labels' => array(
                    'cod' => 'Cash on Delivery',
                    'bank_transfer' => 'Bank Transfer',
                    'fawry' => 'Pay with Fawry',
                ),
                'descriptions' => array(
                    'cod' => 'Pay in cash when your order is delivered',
                    'bank_transfer' => 'Transfer via bank account',
                    'fawry' => 'Pay securely online',
                ),
            ));
        }
        
        // The checkout builds its payment choices from menuPopupAjax.paymentMethods,
        // so the older payment-frontend.js is no longer loaded.

        
        // Enqueue voice features scripts if enabled
        if (get_option('menu_popup_voice_search_enabled', '0') === '1') {
            wp_enqueue_script('menu-popup-voice-search', MENU_POPUP_PLUGIN_URL . 'assets/js/voice-search.js', array('jquery'), $this->version, true);
        }

        if (get_option('menu_popup_pronounce_on_hover_enabled', '0') === '1') {
            wp_enqueue_script('menu-popup-speech-synthesis', MENU_POPUP_PLUGIN_URL . 'assets/js/speech-synthesis.js', array('jquery'), $this->version, true);
        }

        // Enqueue external TTS script if enabled
        if (get_option('menu_popup_external_tts_enabled', '0') === '1') {
            wp_enqueue_script('menu-popup-external-tts', MENU_POPUP_PLUGIN_URL . 'assets/js/external-tts.js', array('jquery'), $this->version, true);

            // Localize settings for external TTS script
            wp_localize_script('menu-popup-external-tts', 'menuPopupSettings', array(
                'externalTtsEnabled' => '1',
                'googleTtsApiKey' => get_option('menu_popup_google_tts_api_key', ''),
                'ttsCacheEnabled' => get_option('menu_popup_tts_cache_enabled', '1')
            ));
        }

        // Fetch active branch ID (used to filter zones)
        $active_branch_id = $this->requested_branch();

        // Fetch delivery zones exclusively from the new database structure
        $delivery_zones = array();
        if (class_exists('Menu_Popup_Zone_Checker')) {
            $db_zones = Menu_Popup_Zone_Checker::get_vendor_zones($active_branch_id);
            foreach ($db_zones as $z) {
                // Format correctly for existing JS expectations
                $dz = array(
                    'id'        => $z['id'],
                    'name'      => $z['zone_name'],
                    'fee'       => (float) $z['delivery_fee'],
                    'min_order' => (float) $z['minimum_order'],
                    'geo'       => array(
                        'zone_type'      => $z['zone_type'],
                        'center_lat'     => $z['center_lat'],
                        'center_lng'     => $z['center_lng'],
                        'radius_km'      => $z['radius_km'],
                        'polygon_coords' => !empty($z['polygon_coords']) && is_string($z['polygon_coords']) 
                                            ? json_decode($z['polygon_coords'], true) 
                                            : (is_array($z['polygon_coords']) ? $z['polygon_coords'] : array())
                    )
                );
                $delivery_zones[] = $dz;
            }
        }


        // Are any delivery zones set up at all? (decides map delivery vs the older zone list)
        $delivery_zones_exist = false;
        if (class_exists('Menu_Popup_Delivery_Zone_Model')) {
            $zone_model = new Menu_Popup_Delivery_Zone_Model();
            $delivery_zones_exist = !empty($zone_model->get_active_map_zones());
        }

        // Localize script for AJAX
        wp_localize_script('menu-popup-frontend', 'menuPopupAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('menu_popup_nonce'),
            // Voice features settings
            'voiceSearchEnabled' => get_option('menu_popup_voice_search_enabled', '0'),
            'pronounceOnHoverEnabled' => get_option('menu_popup_pronounce_on_hover_enabled', '0'),
            'voiceHoverEnabledMobile' => get_option('menu_popup_voice_hover_enabled_mobile', '0'),
            'voiceConfirmCorrectCatchesEnabled' => get_option('menu_popup_voice_confirmation_enabled', '1'),
            // Voice agent selections
            'arabicVoice' => get_option('menu_popup_arabic_voice', ''),
            'englishVoice' => get_option('menu_popup_english_voice', ''),
            // External TTS settings
            'externalTtsEnabled' => get_option('menu_popup_external_tts_enabled', '0'),
            'googleTtsApiKey' => get_option('menu_popup_google_tts_api_key', ''),
            'ttsCacheEnabled' => get_option('menu_popup_tts_cache_enabled', '1'),
            // Cloud Speech-to-Text for voice search (fallback for unsupported browsers)
            'googleSttApiKey' => get_option('menu_popup_google_stt_api_key', ''),
            // Legacy settings
            'enableVideos' => get_option('menu_popup_enable_videos', '1'),
            'orderingEnabled' => get_option('menu_popup_ordering_enabled', '0'),
            'whatsappEnabled' => get_option('menu_popup_whatsapp_enabled', '0'),
            'deliveryEnabled' => get_option('menu_popup_delivery_enabled', '0'),
            'customerNameRequired' => get_option('menu_popup_customer_name_required', '1'),
            'customerPhoneRequired' => get_option('menu_popup_customer_phone_required', '1'),
            'customerEmailEnabled' => get_option('menu_popup_customer_email_enabled', '0'),
            'customerAddressEnabled' => get_option('menu_popup_customer_address_enabled', '0'),
            'deliveryZones' => $delivery_zones,
            'specialInstructionsEnabled' => get_option('menu_popup_special_instructions_enabled', '1'),
            'restaurantWhatsapp' => get_option('menu_popup_restaurant_whatsapp', ''),
            'currencySymbol' => get_option('menu_popup_currency_symbol', '$'),
            'taxRate' => get_option('menu_popup_tax_rate', '0'),
            'minimumOrder' => get_option('menu_popup_minimum_order', '0'),
            // Modal order button display settings
            'showOrderOnlineModal' => get_option('menu_popup_show_order_online_modal', '1'),
            'showWhatsappModal' => get_option('menu_popup_show_whatsapp_modal', '1'),
            // Video autoplay setting
            'enableVideoAutoplay' => get_option('menu_popup_enable_video_autoplay', '0'),
            // Geo zone detection
            'geoCheckEnabled'  => (class_exists('Menu_Popup_Delivery_Zone_Model') && Menu_Popup_Delivery_Zone_Model::mode() === 'map') ? '1' : '0',
            'deliveryMode'     => class_exists('Menu_Popup_Delivery_Zone_Model') ? Menu_Popup_Delivery_Zone_Model::mode() : 'manual',
            'geoZoneNonce'     => wp_create_nonce('mp_delivery_zone_nonce'),
            'activeBranchId'   => $active_branch_id,
            // Delivery by map
            'mapsApiKey'       => get_option('menu_popup_google_maps_api_key', ''),
            'mapCenter'        => array(
                'lat' => (float) get_option('menu_popup_map_default_lat', '30.0444'),
                'lng' => (float) get_option('menu_popup_map_default_lng', '31.2357'),
            ),
            'zonesConfigured'  => !empty($delivery_zones_exist) ? '1' : '0',
            // Payment choices offered right now (empty = no payment step)
            'paymentMethods'   => class_exists('Menu_Popup_Payment_Methods') ? Menu_Popup_Payment_Methods::available() : array(),
        ));
    }

    public function menu_popup_shortcode($atts) {
        // Prevent shortcode processing during AJAX, REST API, or POST operations to avoid corrupting JSON responses
        if ((defined('DOING_AJAX') && DOING_AJAX) ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST')) {
            return ''; // Return empty string during AJAX/REST/POST to avoid interfering with JSON responses
        }

        $atts = shortcode_atts(array(
            'category' => 'all',
            'menu' => 'all',
            'limit' => -1,
            'initial_limit' => -1,
            'show_more' => 'false',
            'show_search' => 'false',
            'all_filter' => 'true'
        ), $atts);

        // Suppress any potential warnings/errors during shortcode processing
        ob_start();
        try {
            if (get_option('menu_popup_force_branch_selection', '0') === '1') {
                $this->render_branch_selection_flow($atts);
            } else {
                $this->render_menu($atts);
            }
        } catch (Exception $e) {
            // Log error but don't output it to avoid breaking JSON responses
            error_log('Menu Popup Shortcode Error: ' . $e->getMessage());
        }
        return ob_get_clean();
    }

    public function menu_popup_featured_shortcode($atts) {
        // Prevent shortcode processing during AJAX, REST API, or POST operations to avoid corrupting JSON responses
        if ((defined('DOING_AJAX') && DOING_AJAX) ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST')) {
            return ''; // Return empty string during AJAX/REST/POST to avoid interfering with JSON responses
        }

        $atts = shortcode_atts(array(
            'limit' => 6,
            'autoplay' => 'true',
            'show_dots' => 'true',
            'show_arrows' => 'true'
        ), $atts);

        // Suppress any potential warnings/errors during shortcode processing
        ob_start();
        try {
            $this->render_featured_slideshow($atts);
        } catch (Exception $e) {
            // Log error but don't output it to avoid breaking JSON responses
            error_log('Menu Popup Featured Shortcode Error: ' . $e->getMessage());
        }
        return ob_get_clean();
    }

    private function render_menu($atts) {
        // Get menu items from both post-meta and CPT sources
        $menu_items = array();

        // Get menu and category data
        $menus = get_option('menu_popup_menus', array());
        $categories = get_option('menu_popup_categories', array());

        // Determine which categories to show based on menu filter
        $allowed_categories = array();
        $menu_found = false;
        $category_blocked = false;
        $menu_branches = array();

        // Get branch filter if set
        $branch_filter = $this->requested_branch() > 0 ? (string) $this->requested_branch() : '';

        if ($atts['menu'] !== 'all') {
            // Find the menu and get its associated categories
            foreach ($menus as $menu) {
                if (isset($menu['slug']) && $menu['slug'] === $atts['menu']) {
                    $menu_found = true;
                    if (!empty($menu['categories']) && is_array($menu['categories'])) {
                        $allowed_categories = $menu['categories'];
                    }
                    // Store the branches assigned to this menu
                    if (!empty($menu['branches']) && is_array($menu['branches'])) {
                        $menu_branches = $menu['branches'];
                    }
                    break;
                }
            }

            // Check if menu is connected to the selected branch
            if ($menu_found && !empty($branch_filter) && !empty($menu_branches)) {
                // Convert branch filter to integer for comparison (it's passed as string from URL)
                $branch_id = intval($branch_filter);
                
                // If the selected branch is not in the menu's branch list, show error
                if (!in_array($branch_id, $menu_branches)) {
                    echo '<div class="menu-popup-container">';
                    echo '<div class="menu-no-menu-msg">';
                    echo '<h2>' . __('No Menu for This Branch', 'menu-popup') . '</h2>';
                    echo '<p>' . sprintf(__('The selected branch does not have a menu assigned to it. Please select a different branch or contact the administrator.', 'menu-popup'), esc_html($atts['menu'])) . '</p>';
                    echo '<p><a href="javascript:void(0)" class="button button-primary" id="selectDifferentBranch">' . __('Select Different Branch', 'menu-popup') . '</a></p>';
                    echo '</div>';
                    echo '</div>';
                    return;
                }
            }

            // If no valid menu found, show message and return empty
            if (!$menu_found) {
                echo '<div class="menu-popup-container">';
                echo '<div class="menu-no-menu-msg">';
                echo '<h2>' . __('Menu Not Found', 'menu-popup') . '</h2>';
                echo '<p>' . sprintf(__('The menu "%s" does not exist. Please check your shortcode and ensure the menu slug is correct.', 'menu-popup'), esc_html($atts['menu'])) . '</p>';
                echo '<p><a href="' . admin_url('admin.php?page=menu-popup-menus') . '" class="button button-primary">' . __('View Available Menus', 'menu-popup') . '</a></p>';
                echo '</div>';
                echo '</div>';
                return;
            }

            // If menu found but has no categories, show message
            if (empty($allowed_categories)) {
                echo '<div class="menu-popup-container">';
                echo '<div class="menu-no-categories-msg">';
                echo '<h2>' . __('No Categories in Selected Menu', 'menu-popup') . '</h2>';
                echo '<p>' . sprintf(__('The menu "%s" does not have any categories assigned to it yet.', 'menu-popup'), esc_html($atts['menu'])) . '</p>';
                echo '<p><a href="' . admin_url('admin.php?page=menu-popup-menus') . '" class="button button-primary">' . __('Edit Menu Categories', 'menu-popup') . '</a></p>';
                echo '</div>';
                echo '</div>';
                return;
            }
        }

        // Apply category filter (if specified in shortcode parameters)
        if ($atts['category'] !== 'all') {
            if (!empty($allowed_categories)) {
                // If we already have menu-based categories, only show intersection
                $allowed_categories = array_intersect($allowed_categories, array($atts['category']));
                $category_blocked = empty($allowed_categories);
            } else {
                // Otherwise, use the specific category
                $allowed_categories = array($atts['category']);
            }
        }

        // Get items from posts (legacy support)
        $post_args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_menu_item_name',
                    'compare' => 'EXISTS'
                )
            ),
            'posts_per_page' => -1,
            'orderby' => 'menu_order',
            'order' => 'ASC'
        );

        // Apply category filters to posts
        if (!empty($allowed_categories)) {
            $post_args['meta_query'][] = array(
                'key' => '_menu_item_category',
                'value' => $allowed_categories,
                'compare' => 'IN'
            );
        }

        // Apply branch filter to posts if set
        // Include items that either match the branch OR have no branch (global items)
        if (!empty($branch_filter)) {
            $post_args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key' => '_menu_item_branch',
                    'value' => $branch_filter,
                    'compare' => '='
                ),
                array(
                    'key' => '_menu_item_branch',
                    'value' => '',
                    'compare' => '=',
                    'type' => 'CHAR'
                ),
                array(
                    'key' => '_menu_item_branch',
                    'compare' => 'NOT EXISTS'
                )
            );
        }

        if ($category_blocked) {
            $post_args['post__in'] = array(0);
        }
        $posts_with_menu_items = get_posts($post_args);

        // Get items from menu_item CPT
        $cpt_args = array(
            'post_type' => 'menu_item',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => array(),
            'orderby' => 'menu_order',
            'order' => 'ASC'
        );

        // Apply category filters to CPT items
        if (!empty($allowed_categories)) {
            $cpt_args['meta_query'][] = array(
                'key' => '_menu_item_category',
                'value' => $allowed_categories,
                'compare' => 'IN'
            );
        }

        // Apply branch filter to CPT items if set
        // Include items that either match the branch OR have no branch (global items)
        if (!empty($branch_filter)) {
            $cpt_args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key' => '_menu_item_branch',
                    'value' => $branch_filter,
                    'compare' => '='
                ),
                array(
                    'key' => '_menu_item_branch',
                    'value' => '',
                    'compare' => '=',
                    'type' => 'CHAR'
                ),
                array(
                    'key' => '_menu_item_branch',
                    'compare' => 'NOT EXISTS'
                )
            );
        }

        if ($category_blocked) {
            $cpt_args['post__in'] = array(0);
        }
        $cpt_menu_items = get_posts($cpt_args);

        // Combine both arrays
        $menu_items = array_merge($posts_with_menu_items, $cpt_menu_items);

        // Determine effective limit for display
        $effective_limit = $atts['limit'];
        $has_show_more = ($atts['show_more'] === 'true' && $atts['initial_limit'] > 0);
        $total_items_count = count($menu_items);

        if ($has_show_more) {
            // Use initial_limit for first display, but track full limit
            $effective_limit = intval($atts['initial_limit']);
        } elseif ($atts['limit'] != -1) {
            $effective_limit = intval($atts['limit']);
        }

        // Apply limit for display
        if ($effective_limit > 0 && $effective_limit < $total_items_count) {
            $menu_items = array_slice($menu_items, 0, $effective_limit);
        }

        // Check if there are more items available
        $has_more_items = ($has_show_more && $total_items_count > $effective_limit) ||
                         (!$has_show_more && $atts['limit'] != -1 && $total_items_count > $atts['limit']);

        // Get all custom categories - filter out empty arrays and invalid entries
        $all_custom_categories = array_filter(get_option('menu_popup_categories', array()), function($cat) {
            return !empty($cat['slug']) && !empty($cat['name']);
        });

        // Filter categories based on menu selection
        $custom_categories = $all_custom_categories;

        // If a specific menu is requested, only show categories assigned to that menu
        if (!empty($allowed_categories) && $atts['menu'] !== 'all') {
            $custom_categories = array_filter($all_custom_categories, function($cat) use ($allowed_categories) {
                return in_array($cat['slug'], $allowed_categories);
            });
        }

        // Ensure we have at least some categories to show after filtering
        if (!empty($custom_categories)) {
            // Sort categories by their order (if available) or alphabetically by name
            usort($custom_categories, function($a, $b) {
                $a_order = isset($a['order']) ? $a['order'] : PHP_INT_MAX;
                $b_order = isset($b['order']) ? $b['order'] : PHP_INT_MAX;
                if ($a_order === $b_order) {
                    return strcmp($a['name'], $b['name']);
                }
                return $a_order - $b_order;
            });

            $categories = array_column($custom_categories, 'slug');
        } elseif (empty($custom_categories)) {
            // No categories found for this menu or no categories exist at all
            echo '<div class="menu-popup-container">';
            echo '<div class="menu-no-categories-msg">';
            if (!empty($allowed_categories) && $atts['menu'] !== 'all') {
                echo '<h2>' . __('No Categories Assigned to Menu', 'menu-popup') . '</h2>';
                echo '<p>' . __('The selected menu doesn\'t have any categories assigned to it yet.', 'menu-popup') . '</p>';
                echo '<p><a href="' . admin_url('admin.php?page=menu-popup-menus') . '" class="button button-primary">' . __('Edit Menu Categories', 'menu-popup') . '</a></p>';
            } else {
                echo '<h2>' . __('No Menu Categories Found', 'menu-popup') . '</h2>';
                echo '<p>' . __('You haven\'t added any menu categories yet. Please create categories for your menu items first.', 'menu-popup') . '</p>';
                echo '<p><a href="' . admin_url('admin.php?page=menu-popup-categories') . '" class="button button-primary">' . __('Add Menu Categories', 'menu-popup') . '</a></p>';
            }
            echo '</div>';
            echo '</div>';
            return;
        }

        $categories = array_column($custom_categories, 'slug');

        // Get current display language for RTL and content selection
        $current_lang = $this->get_current_display_language();
        $direction = ($current_lang === 'ar') ? 'rtl' : 'ltr';

        // Get branch filter if set
        $branch_filter = $this->requested_branch() > 0 ? (string) $this->requested_branch() : '';

        // Get layout type from settings
        $layout_type = get_option('menu_popup_layout_type', 'grid');

        // Static flag to ensure video modal is only rendered once per page
        static $video_modal_rendered = false;

        $css_class = ($current_lang === 'ar') ? 'menu-rtl menu-rtl' : 'menu-ltr';
        // Get custom colors for inline CSS
        $primary_color = esc_attr(get_option('menu_popup_primary_color', '#A855F7'));
        $secondary_color = esc_attr(get_option('menu_popup_secondary_color', '#1F2937'));
        $accent_color = esc_attr(get_option('menu_popup_accent_color', '#F59E0B'));
        $background_color = esc_attr(get_option('menu_popup_background_color', '#0F0F23'));
        $text_color = esc_attr(get_option('menu_popup_text_color', '#F9FAFB'));

        // Cart customization colors
        $cart_background_color = esc_attr(get_option('menu_popup_cart_background_color', '#161235'));
        $cart_border_color = esc_attr(get_option('menu_popup_cart_border_color', '#5755f7'));
        $cart_text_color = esc_attr(get_option('menu_popup_cart_text_color', '#F9FAFB'));
        $cart_price_color = esc_attr(get_option('menu_popup_cart_price_color', '#F59E0B'));
        $cart_total_color = esc_attr(get_option('menu_popup_cart_total_color', '#A855F7'));
        $cart_icon_color = esc_attr(get_option('menu_popup_cart_icon_color', '#F9FAFB'));
        $cart_icon_size = esc_attr(get_option('menu_popup_cart_icon_size', '20'));
        $cart_toggle_icon_size = esc_attr(get_option('menu_popup_cart_toggle_icon_size', '20'));

        // Order confirmation modal colors
        $order_modal_bg_color = esc_attr(get_option('menu_popup_order_modal_bg_color', '#1F2937'));
        $order_modal_border_color = esc_attr(get_option('menu_popup_order_modal_border_color', '#A855F7'));
        $order_modal_text_color = esc_attr(get_option('menu_popup_order_modal_text_color', '#F9FAFB'));
        $order_modal_accent_color = esc_attr(get_option('menu_popup_order_modal_accent_color', '#F59E0B'));
        $order_modal_overlay_color = esc_attr(get_option('menu_popup_order_modal_overlay_color', 'rgba(0, 0, 0, 0.7)'));

        // Hero section colors and fonts
        $hero_bg_color = esc_attr(get_option('menu_popup_hero_bg_color', '#0F0F23'));
        $hero_title_color = esc_attr(get_option('menu_popup_hero_title_color', '#F9FAFB'));
        $hero_subtitle_color = esc_attr(get_option('menu_popup_hero_subtitle_color', '#F9FAFB'));
        $hero_title_font_size = esc_attr(get_option('menu_popup_hero_title_font_size', '6rem'));
        $hero_subtitle_font_size = esc_attr(get_option('menu_popup_hero_subtitle_font_size', '1.125rem'));

        ?>
        <style>
            .menu-popup-container {
                --menu-bg: <?php echo $background_color; ?>;
                --menu-text: <?php echo $text_color; ?>;
                --menu-primary: <?php echo $primary_color; ?>;
                <?php echo esc_html(self::contrast_vars()); ?>
                --menu-secondary: <?php echo $secondary_color; ?>;
                --menu-accent: <?php echo $accent_color; ?>;

                /* Cart customization variables */
                --menu-cart-bg: <?php echo $cart_background_color; ?>;
                --menu-cart-border: <?php echo $cart_border_color; ?>;
                --menu-cart-text: <?php echo $cart_text_color; ?>;
                --menu-cart-price: <?php echo $cart_price_color; ?>;
                --menu-cart-total: <?php echo $cart_total_color; ?>;
                --menu-cart-icon-color: <?php echo $cart_icon_color; ?>;
                --menu-cart-icon-size: <?php echo $cart_icon_size; ?>px;
                --menu-cart-toggle-icon-size: <?php echo $cart_toggle_icon_size; ?>px;

            /* Order confirmation modal variables */
            --menu-order-modal-bg: <?php echo $order_modal_bg_color; ?>;
            --menu-order-modal-border: <?php echo $order_modal_border_color; ?>;
            --menu-order-modal-text: <?php echo $order_modal_text_color; ?>;
            --menu-order-modal-accent: <?php echo $order_modal_accent_color; ?>;
            --menu-order-modal-overlay: <?php echo $order_modal_overlay_color; ?>;

            /* Hero section variables */
            --menu-hero-bg: <?php echo $hero_bg_color; ?>;
            --menu-hero-title-color: <?php echo $hero_title_color; ?>;
            --menu-hero-subtitle-color: <?php echo $hero_subtitle_color; ?>;
            --menu-hero-title-font-size: <?php echo $hero_title_font_size; ?>;
            --menu-hero-subtitle-font-size: <?php echo $hero_subtitle_font_size; ?>;
            }

            /* Make cart and order modal variables globally available */
            :root {
                --menu-cart-bg: <?php echo $cart_background_color; ?>;
                --menu-cart-border: <?php echo $cart_border_color; ?>;
                --menu-cart-text: <?php echo $cart_text_color; ?>;
                --menu-cart-price: <?php echo $cart_price_color; ?>;
                --menu-cart-total: <?php echo $cart_total_color; ?>;
                --menu-cart-icon-color: <?php echo $cart_icon_color; ?>;
                --menu-cart-icon-size: <?php echo $cart_icon_size; ?>px;
                --menu-cart-toggle-icon-size: <?php echo $cart_toggle_icon_size; ?>px;

                /* Order confirmation modal variables */
                --menu-order-modal-bg: <?php echo $order_modal_bg_color; ?>;
                --menu-order-modal-border: <?php echo $order_modal_border_color; ?>;
                --menu-order-modal-text: <?php echo $order_modal_text_color; ?>;
                --menu-order-modal-accent: <?php echo $order_modal_accent_color; ?>;
                --menu-order-modal-overlay: <?php echo $order_modal_overlay_color; ?>;
            }
        </style>
        <div class="menu-popup-container <?php echo $css_class; ?> layout-<?php echo esc_attr($layout_type); ?> <?php echo get_option('menu_popup_enable_videos') == '1' ? 'videos-enabled' : 'videos-disabled'; ?>" dir="<?php echo $direction; ?>" data-layout="<?php echo esc_attr($layout_type); ?>" data-menu="<?php echo esc_attr($atts['menu']); ?>">
            <!-- Language Switcher -->
            <div class="language-switcher" role="group" aria-label="<?php esc_attr_e('Language', 'menu-popup'); ?>">
                <a href="<?php echo esc_url(add_query_arg('lang', 'en')); ?>" class="language-btn <?php echo ($current_lang ?? 'en') === 'en' ? 'active' : ''; ?>" lang="en" hreflang="en"<?php echo ($current_lang ?? 'en') === 'en' ? ' aria-current="true"' : ''; ?>>EN</a>
                <a href="<?php echo esc_url(add_query_arg('lang', 'ar')); ?>" class="language-btn <?php echo ($current_lang ?? 'en') === 'ar' ? 'active' : ''; ?>" lang="ar" hreflang="ar"<?php echo ($current_lang ?? 'en') === 'ar' ? ' aria-current="true"' : ''; ?>>العربية</a>
            </div>
            <!-- Branch Selector -->
            <?php $current_branch_id = $this->requested_branch(); ?>
            <?php if ($current_branch_id > 0 || count($this->published_branches()) > 1): ?>
            <div class="branch-switcher">
                <button id="changeBranch">
                    <?php echo esc_html($current_branch_id > 0 ? get_the_title($current_branch_id) : __('Choose your branch', 'menu-popup')); ?>
                    <span class="change-icon">↓</span>
                </button>
            </div>
            <?php endif; ?>
            <!-- Hero Section -->
            <section class="menu-hero">
                <h1 class="menu-hero-title">
                    <span><?php echo wp_kses(get_option('menu_popup_hero_title', 'Culinary<br>Excellence'), array('br' => array())); ?></span>
                </h1>
                <p class="menu-hero-subtitle"><?php echo wp_kses(get_option('menu_popup_hero_subtitle', 'Click any dish to watch our chefs prepare it with passion and precision'), array('br' => array())); ?></p>
            </section>

            <!-- Search Bar -->
            <?php if ($atts['show_search'] === 'true'): ?>
            <section class="menu-search">
                <div class="search-container">
                    <input type="text" id="menu-search-input" placeholder="<?php esc_attr_e('Search menu items...', 'menu-popup'); ?>" aria-label="<?php esc_attr_e('Search menu items', 'menu-popup'); ?>" class="search-input">
                    <button type="button" class="search-clear" id="search-clear" aria-label="<?php esc_attr_e('Clear search', 'menu-popup'); ?>" style="display: none;">×</button>
                    <!-- Voice Search Button Placeholder -->
                    <div class="search-buttons">
                        <!-- Voice search and speech toggle buttons will be inserted here -->
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- Category Filter -->
            <section class="menu-categories" data-all-filter="<?php echo esc_attr($atts['all_filter']); ?>">
                <?php if ($atts['all_filter'] !== 'false'): ?>
                <button class="category-btn active" data-category="all">
                    <?php _e('All', 'menu-popup'); ?>
                </button>
                <?php endif; ?>
                <?php foreach ($categories as $category): ?>
                    <button class="category-btn <?php echo ($atts['all_filter'] === 'false' && $category === $categories[0]) ? 'active' : ''; ?>" data-category="<?php echo esc_attr($category); ?>">
                        <?php
                        // Get category name from custom categories
                        foreach ($custom_categories as $cat) {
                            if ($cat['slug'] === $category) {
                                echo esc_html($cat['name']);
                                break;
                            }
                        }
                        ?>
                    </button>
                <?php endforeach; ?>
            </section>

            <!-- Masonry Grid Menu -->
            <section class="menu-grid <?php echo ($layout_type === 'masonry') ? 'layout-masonry' : ''; ?>">
                <?php if ($layout_type === 'masonry'): ?>
                    <?php
                    // Distribute items into columns for masonry layout
                    $columns = array();
                    $column_count = 3; // Default for desktop

                    // Adjust column count based on screen size (we'll handle responsive in CSS)
                    if (isset($_GET['mobile']) && $_GET['mobile'] === '1') {
                        $column_count = 1;
                    } elseif (isset($_GET['tablet']) && $_GET['tablet'] === '1') {
                        $column_count = 2;
                    }

                    // Initialize columns
                    for ($i = 0; $i < $column_count; $i++) {
                        $columns[$i] = array();
                    }

                    // Distribute items evenly across columns
                    foreach ($menu_items as $index => $item) {
                        $column_index = $index % $column_count;
                        $columns[$column_index][] = $item;
                    }
                    ?>

                    <?php foreach ($columns as $column_index => $column_items): ?>
                        <div class="menu-column">
                            <?php foreach ($column_items as $index => $item): ?>
                                <?php
                                $name = get_post_meta($item->ID, '_menu_item_name', true);
                                $name_arabic = get_post_meta($item->ID, '_menu_item_name_arabic', true);
                                $description = get_post_meta($item->ID, '_menu_item_description', true);
                                $description_arabic = get_post_meta($item->ID, '_menu_item_description_arabic', true);
                                $price = get_post_meta($item->ID, '_menu_item_price', true);
                                $category = get_post_meta($item->ID, '_menu_item_category', true);
                                $image = get_post_meta($item->ID, '_menu_item_image', true);
                                $video = get_post_meta($item->ID, '_menu_item_video', true);
                                $video_arabic = get_post_meta($item->ID, '_menu_item_video_arabic', true);
                                $add_to_cart_arabic = get_post_meta($item->ID, '_menu_item_add_to_cart_arabic', true);
                                $order_now_arabic = get_post_meta($item->ID, '_menu_item_order_now_arabic', true);
                                $item_language = get_post_meta($item->ID, '_menu_item_language', true);

                                // Select appropriate video based on current display language
                                $selected_video = ($current_lang === 'ar' && !empty($video_arabic)) ? $video_arabic : $video;

                                // Smart content selection based on current display language
                                if ($current_lang === 'ar') {
                                    $selected_name = (!empty($name_arabic)) ? $name_arabic : $name;
                                    $selected_description = (!empty($description_arabic)) ? $description_arabic : $description;
                                    $selected_add_to_cart = (!empty($add_to_cart_arabic)) ? $add_to_cart_arabic : __('Add to Cart', 'menu-popup');
                                    $selected_order_now = (!empty($order_now_arabic)) ? $order_now_arabic : __('Order Now', 'menu-popup');
                                } else {
                                    $selected_name = $name;
                                    $selected_description = $description;
                                    $selected_add_to_cart = __('Add to Cart', 'menu-popup');
                                    $selected_order_now = __('Order Now', 'menu-popup');
                                }

                                // Get category name for display
                                $category_name = '';
                                foreach ($custom_categories as $cat) {
                                    if ($cat['slug'] === $category) {
                                        $category_name = $cat['name'];
                                        break;
                                    }
                                }

                                $gallery_images = get_post_meta($item->ID, '_menu_item_gallery', true);
                                if (!is_array($gallery_images)) {
                                    $gallery_images = !empty($gallery_images) ? array($gallery_images) : array();
                                }
                                $filtered_gallery = array_filter($gallery_images, function($url) { return !empty(trim($url)); });

                                $sizes_variations_json = '';
                                $sizes_variations_data = get_post_meta($item->ID, '_menu_item_sizes', true);
                                $first_price = isset($sizes_variations_data[0]['price']) ? $sizes_variations_data[0]['price'] : $price;
                                if (!empty($sizes_variations_data) && is_array($sizes_variations_data)) {
                                    $sizes_variations_json = json_encode($sizes_variations_data);
                                } else {
                                    $sizes_variations_json = '';
                                }
                                ?>
                                <div class="menu-item" data-category="<?php echo esc_attr($category); ?>" <?php if ($selected_video && get_option('menu_popup_enable_videos') == '1'): ?>data-video="<?php echo esc_url($selected_video); ?>" data-title="<?php echo esc_attr($selected_name); ?>" data-description="<?php echo esc_attr($selected_description); ?>"<?php endif; ?><?php if (!empty($filtered_gallery)): ?> data-gallery="<?php echo esc_attr(json_encode($filtered_gallery)); ?>"<?php endif; ?><?php if (get_option('menu_popup_show_price_modal', '1') == '1'): ?> data-price="<?php echo esc_attr($first_price ?? $price); ?>" data-sizes="<?php echo esc_attr($sizes_variations_json); ?>"<?php endif; ?>>
                                    <div class="menu-item-image">
                                        <?php if ($image): ?>
                                            <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($name); ?>" />
                                        <?php endif; ?>
                                        <?php if ($selected_video && get_option('menu_popup_enable_videos') == '1'): ?>
                                            <div class="play-button">
                                                <span class="play-icon">▶</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="menu-item-category-tag">
                                        <?php echo esc_html($category_name); ?>
                                    </div>

                                    <div class="menu-item-info">
                                        <div class="menu-item-header">
                                            <h3 class="menu-item-name"><?php echo esc_html($selected_name); ?></h3>
                                            <?php if (get_option('menu_popup_show_price_card', '1') == '1' && !empty($price)) : ?>
                                                <span class="menu-item-price"><?php echo esc_html($price); ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <?php
                                        if (get_option('menu_popup_show_price_card', '1') == '1') {
                                            $sizes_variations = get_post_meta($item->ID, '_menu_item_sizes', true);

                                            // Show size selection if variations exist
                                            if (!empty($sizes_variations) && is_array($sizes_variations)) {
                                                echo '<div class="menu-item-size-selection">';
                                                echo '<div class="size-buttons">';
                                                foreach ($sizes_variations as $index => $variation) {
                                                    $size_name = isset($variation['size']) ? $variation['size'] : '';
                                                    $size_price = isset($variation['price']) ? $variation['price'] : '';
                                                    if ($size_name && $size_price) {
                                                        $active_class = $index === 0 ? 'active' : '';
                                                        echo '<button class="size-btn ' . $active_class . '" data-size="' . esc_attr($size_name) . '" data-price="' . esc_attr($size_price) . '">';
                                                        echo esc_html($size_name) . ' • ' . esc_html($size_price);
                                                        echo '</button>';
                                                    }
                                                }
                                                echo '</div></div>';
                                            }
                                        }
                                        ?>

                                        <p class="menu-item-description"><?php echo esc_html($selected_description); ?></p>

                    <!-- Order/Cart Actions -->
                    <?php if (get_option('menu_popup_ordering_enabled', '0') == '1' || get_option('menu_popup_whatsapp_enabled', '0') == '1'): ?>
                        <div class="menu-item-actions <?php echo (!empty($sizes_variations) && is_array($sizes_variations) && count($sizes_variations) > 1) ? 'has-variations' : ''; ?>">
                            <?php if (get_option('menu_popup_ordering_enabled', '0') == '1'): ?>
                                <button class="action-btn add-to-cart-btn <?php echo (!empty($sizes_variations) && is_array($sizes_variations) && count($sizes_variations) > 1) ? 'requires-size-selection' : ''; ?>" data-item-id="<?php echo esc_attr($item->ID); ?>" data-name="<?php echo esc_attr($selected_name_ajax); ?>" data-price="<?php echo esc_attr($first_price_ajax); ?>" data-selected-size="<?php echo esc_attr($sizes_variations[0]['size'] ?? ''); ?>" data-selected-price="<?php echo esc_attr($sizes_variations[0]['price'] ?? $price); ?>" <?php echo (!empty($sizes_variations) && is_array($sizes_variations) && count($sizes_variations) > 1) ? 'style="display: none;"' : ''; ?>>
                                    <span class="cart-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.4a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L20 7H6"/></svg></span> Add to Cart
                                </button>
                            <?php endif; ?>

                            <?php if (get_option('menu_popup_whatsapp_enabled', '0') == '1'): ?>
                                <button class="action-btn order-whatsapp-btn <?php echo (!empty($sizes_variations) && is_array($sizes_variations) && count($sizes_variations) > 1) ? 'requires-size-selection' : ''; ?>" data-name="<?php echo esc_attr($selected_name_ajax); ?>" data-price="<?php echo esc_attr($first_price_ajax); ?>" data-selected-size="<?php echo esc_attr($sizes_variations[0]['size'] ?? ''); ?>" data-selected-price="<?php echo esc_attr($sizes_variations[0]['price'] ?? $price); ?>" <?php echo (!empty($sizes_variations) && is_array($sizes_variations) && count($sizes_variations) > 1) ? 'style="display: none;"' : ''; ?>>
                                    <span class="whatsapp-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg></span> Order Now
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Regular Grid/List Layout -->
                    <?php foreach ($menu_items as $index => $item): ?>
                        <?php
                        $name = get_post_meta($item->ID, '_menu_item_name', true);
                        $name_arabic = get_post_meta($item->ID, '_menu_item_name_arabic', true);
                        $description = get_post_meta($item->ID, '_menu_item_description', true);
                        $description_arabic = get_post_meta($item->ID, '_menu_item_description_arabic', true);
                        $price = get_post_meta($item->ID, '_menu_item_price', true);
                        $category = get_post_meta($item->ID, '_menu_item_category', true);
                        $image = get_post_meta($item->ID, '_menu_item_image', true);
                        $video = get_post_meta($item->ID, '_menu_item_video', true);
                        $video_arabic = get_post_meta($item->ID, '_menu_item_video_arabic', true);
                        $add_to_cart_arabic = get_post_meta($item->ID, '_menu_item_add_to_cart_arabic', true);
                        $order_now_arabic = get_post_meta($item->ID, '_menu_item_order_now_arabic', true);
                        $item_language = get_post_meta($item->ID, '_menu_item_language', true);

                        // Select appropriate video based on current display language
                        $selected_video = ($current_lang === 'ar' && !empty($video_arabic)) ? $video_arabic : $video;

                        // Smart content selection based on current display language
                        if ($current_lang === 'ar') {
                            $selected_name = (!empty($name_arabic)) ? $name_arabic : $name;
                            $selected_description = (!empty($description_arabic)) ? $description_arabic : $description;
                            $selected_add_to_cart = (!empty($add_to_cart_arabic)) ? $add_to_cart_arabic : __('Add to Cart', 'menu-popup');
                            $selected_order_now = (!empty($order_now_arabic)) ? $order_now_arabic : __('Order Now', 'menu-popup');
                        } else {
                            $selected_name = $name;
                            $selected_description = $description;
                            $selected_add_to_cart = __('Add to Cart', 'menu-popup');
                            $selected_order_now = __('Order Now', 'menu-popup');
                        }

                        // Get category name for display
                        $category_name = '';
                        foreach ($custom_categories as $cat) {
                            if ($cat['slug'] === $category) {
                                $category_name = $cat['name'];
                                break;
                            }
                        }

                        $gallery_images = get_post_meta($item->ID, '_menu_item_gallery', true);
                        if (!is_array($gallery_images)) {
                            $gallery_images = !empty($gallery_images) ? array($gallery_images) : array();
                        }
                        $filtered_gallery = array_filter($gallery_images, function($url) { return !empty(trim($url)); });

                        $sizes_variations_json = '';
                        $sizes_variations_data = get_post_meta($item->ID, '_menu_item_sizes', true);
                        if (!empty($sizes_variations_data) && is_array($sizes_variations_data)) {
                            $sizes_variations_json = json_encode($sizes_variations_data);
                        }
                        ?>
                        <div class="menu-item" data-category="<?php echo esc_attr($category); ?>" <?php if ($selected_video && get_option('menu_popup_enable_videos') == '1'): ?>data-video="<?php echo esc_url($selected_video); ?>" data-title="<?php echo esc_attr($selected_name); ?>" data-description="<?php echo esc_attr($selected_description); ?>"<?php endif; ?><?php if (!empty($filtered_gallery)): ?> data-gallery="<?php echo esc_attr(json_encode($filtered_gallery)); ?>"<?php endif; ?><?php if (get_option('menu_popup_show_price_modal', '1') == '1'): ?> data-price="<?php echo esc_attr($first_price ?? $price); ?>" data-sizes="<?php echo esc_attr($sizes_variations_json); ?>"<?php endif; ?>>
                            <div class="menu-item-image">
                                <?php if ($image): ?>
                                    <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($name); ?>" />
                                <?php endif; ?>
                                <?php if ($selected_video && get_option('menu_popup_enable_videos') == '1'): ?>
                                    <div class="play-button">
                                        <span class="play-icon">▶</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="menu-item-category-tag">
                                <?php echo esc_html($category_name); ?>
                            </div>

                            <div class="menu-item-info">
                                <div class="menu-item-header">
                                    <h3 class="menu-item-name"><?php echo esc_html($selected_name); ?></h3>
                                    <?php if (get_option('menu_popup_show_price_card', '1') == '1' && !empty($price)) : ?>
                                        <span class="menu-item-price"><?php echo esc_html($price); ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php
                                if (get_option('menu_popup_show_price_card', '1') == '1') {
                                    $sizes_variations = get_post_meta($item->ID, '_menu_item_sizes', true);

                                    // Show size selection if variations exist
                                    if (!empty($sizes_variations) && is_array($sizes_variations)) {
                                        echo '<div class="menu-item-size-selection">';
                                        echo '<div class="size-buttons">';
                                        foreach ($sizes_variations as $index => $variation) {
                                            $size_name = isset($variation['size']) ? $variation['size'] : '';
                                            $size_price = isset($variation['price']) ? $variation['price'] : '';
                                            if ($size_name && $size_price) {
                                                $active_class = $index === 0 ? 'active' : '';
                                                echo '<button class="size-btn ' . $active_class . '" data-size="' . esc_attr($size_name) . '" data-price="' . esc_attr($size_price) . '">';
                                                echo esc_html($size_name) . ' • ' . esc_html($size_price);
                                                echo '</button>';
                                            }
                                        }
                                        echo '</div></div>';
                                    }
                                }
                                ?>

                                <p class="menu-item-description"><?php echo esc_html($selected_description); ?></p>

                                <!-- Order/Cart Actions -->
                                <?php if (get_option('menu_popup_ordering_enabled', '0') == '1' || get_option('menu_popup_whatsapp_enabled', '0') == '1'): ?>
                                    <div class="menu-item-actions">
                                        <?php if (get_option('menu_popup_ordering_enabled', '0') == '1'): ?>
                                            <button class="action-btn add-to-cart-btn" data-item-id="<?php echo esc_attr($item->ID); ?>" data-name="<?php echo esc_attr($selected_name); ?>" data-price="<?php echo esc_attr($first_price ?? $price); ?>" data-selected-size="<?php echo esc_attr($sizes_variations[0]['size'] ?? ''); ?>" data-selected-price="<?php echo esc_attr($sizes_variations[0]['price'] ?? $price); ?>">
                                                <span class="cart-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.4a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L20 7H6"/></svg></span> <?php echo esc_html($selected_add_to_cart); ?>
                                            </button>
                                        <?php endif; ?>

                                        <?php if (get_option('menu_popup_whatsapp_enabled', '0') == '1'): ?>
                                            <button class="action-btn order-whatsapp-btn" data-name="<?php echo esc_attr($selected_name); ?>" data-price="<?php echo esc_attr($first_price ?? $price); ?>" data-selected-size="<?php echo esc_attr($sizes_variations[0]['size'] ?? ''); ?>" data-selected-price="<?php echo esc_attr($sizes_variations[0]['price'] ?? $price); ?>">
                                                <span class="whatsapp-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg></span> <?php echo esc_html($selected_order_now); ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($has_show_more && $has_more_items): ?>
                <div class="show-more-container" style="text-align: center; margin: 2rem 0;">
                    <button class="show-more-btn" data-initial-limit="<?php echo esc_attr($effective_limit); ?>" data-full-limit="<?php echo esc_attr($atts['limit'] > 0 ? $atts['limit'] : $total_items_count); ?>" data-menu="<?php echo esc_attr($atts['menu']); ?>" data-category="<?php echo esc_attr($atts['category']); ?>" data-all-filter="<?php echo esc_attr($atts['all_filter']); ?>">
                        <?php _e('Show More Items', 'menu-popup'); ?>
                    </button>
                </div>
                <?php endif; ?>
            </section>

            <!-- Video Modal -->
            <?php if (!$video_modal_rendered): ?>
                <?php $video_modal_rendered = true; ?>
                <div class="video-modal-overlay" id="globalVideoModal" role="dialog" aria-modal="true" aria-labelledby="mpVideoModalTitle" style="display: none;">
                <div class="video-modal-container">
                    <div class="video-modal-content">
                        <iframe allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" title=""></iframe>
                    </div>
                    <div class="video-modal-gallery-section">
                        <div class="video-modal-header">
                            <h3 class="video-modal-title" id="mpVideoModalTitle"></h3>
                            <p class="video-modal-description"></p>
                            <?php if (get_option('menu_popup_show_price_modal', '1') == '1'): ?>
                                <div class="video-modal-variations" style="display: none;"></div>
                                <div class="video-modal-price" style="display: none;"></div>
                            <?php endif; ?>

                            <!-- Order/Cart Actions in Modal -->
                            <div class="video-modal-actions" style="display: none;">
                                <button class="action-btn video-add-to-cart-btn" style="display: none;">
                                    <span class="cart-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.4a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L20 7H6"/></svg></span> <?php _e('Add to Cart', 'menu-popup'); ?>
                                </button>
                                <button class="action-btn video-order-whatsapp-btn" style="display: none;">
                                    <span class="whatsapp-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg></span> <?php _e('Order Now', 'menu-popup'); ?>
                                </button>
                            </div>
                        </div>
                        <div class="video-modal-gallery"></div>
                    </div>
                    <button type="button" class="video-modal-close" title="Close Video" aria-label="Close video">×</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Branch Change Modal -->
            <div class="branch-change-modal-overlay" id="branchChangeModal" role="dialog" aria-modal="true" aria-labelledby="mpBranchModalTitle" style="display: none;">
                <div class="branch-change-modal">
                    <div class="branch-change-modal-header">
                        <h3 class="branch-change-modal-title" id="mpBranchModalTitle"><?php _e('Change Your Location', 'menu-popup'); ?></h3>
                        <button type="button" class="branch-change-modal-close" title="Close" aria-label="Close">&times;</button>
                    </div>
                    <div class="branch-change-modal-body" id="branchChangeGrid">
                        <!-- Branches loaded via AJAX -->
                    </div>
                    <div class="branch-change-modal-footer">
                        <p class="branch-change-help-text"><?php _e('Select a branch to see available menu items', 'menu-popup'); ?></p>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    public function ajax_filter_menu_items() {
        check_ajax_referer('menu_popup_nonce', 'nonce');

        $category = sanitize_text_field($_POST['category']);
        $menu = sanitize_text_field($_POST['menu'] ?? 'all');
        $voice_search_type = sanitize_text_field($_POST['voice_search_type'] ?? '');
        $voice_search_query = sanitize_text_field($_POST['voice_search_query'] ?? '');
        $branch_filter = sanitize_text_field($_POST['branch'] ?? '');
        $branch_filter = $this->valid_branch_id($branch_filter) > 0 ? (string) $this->valid_branch_id($branch_filter) : '';
        $search_query = sanitize_text_field($_POST['search'] ?? '');
        $offset = intval($_POST['offset'] ?? 0);
        $limit = intval($_POST['limit'] ?? -1);

        // Get menu data for filtering
        $menus = get_option('menu_popup_menus', array());

        // Determine which categories to show based on menu filter
        $allowed_categories = array();
        $menu_found = ($menu === 'all');
        $menu_branches = array();
        if ($menu !== 'all') {
            // Find the menu and get its associated categories
            foreach ($menus as $menu_data) {
                if (isset($menu_data['slug']) && $menu_data['slug'] === $menu) {
                    $menu_found = true;
                    if (!empty($menu_data['branches']) && is_array($menu_data['branches'])) {
                        $menu_branches = array_map('intval', $menu_data['branches']);
                    }
                    if (!empty($menu_data['categories']) && is_array($menu_data['categories'])) {
                        $allowed_categories = $menu_data['categories'];
                    }
                    break;
                }
            }
        }

        // Same rules as the page itself: unknown menu, empty menu, a menu that is not offered
        // at this branch, or a category outside the menu all give an empty list
        $no_results = !$menu_found
            || ($menu !== 'all' && empty($allowed_categories))
            || ($menu !== 'all' && $branch_filter !== '' && !empty($menu_branches) && !in_array((int) $branch_filter, $menu_branches, true));

        // Apply category filter
        if ($category !== 'all') {
            if (!empty($allowed_categories)) {
                $allowed_categories = array_intersect($allowed_categories, array($category));
                if (empty($allowed_categories)) {
                    $no_results = true;
                }
            } else {
                $allowed_categories = array($category);
            }
        }

        // Query both post-meta items AND menu_item CPT
        $post_args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_menu_item_name',
                    'compare' => 'EXISTS'
                )
            ),
            'posts_per_page' => -1
        );

        $cpt_args = array(
            'post_type' => 'menu_item',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => array()
        );

        // Apply category filters to posts
        if (!empty($allowed_categories)) {
            $post_args['meta_query'][] = array(
                'key' => '_menu_item_category',
                'value' => $allowed_categories,
                'compare' => 'IN'
            );
        }

        // Apply branch filter to posts if set
        // Include items that either match the branch OR have no branch (global items)
        if (!empty($branch_filter)) {
            $post_args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key' => '_menu_item_branch',
                    'value' => $branch_filter,
                    'compare' => '='
                ),
                array(
                    'key' => '_menu_item_branch',
                    'value' => '',
                    'compare' => '=',
                    'type' => 'CHAR'
                ),
                array(
                    'key' => '_menu_item_branch',
                    'compare' => 'NOT EXISTS'
                )
            );
        }

        // Apply category filters to CPT items
        if (!empty($allowed_categories)) {
            $cpt_args['meta_query'][] = array(
                'key' => '_menu_item_category',
                'value' => $allowed_categories,
                'compare' => 'IN'
            );
        }

        // Apply branch filter to CPT items if set
        // Include items that either match the branch OR have no branch (global items)
        if (!empty($branch_filter)) {
            $cpt_args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key' => '_menu_item_branch',
                    'value' => $branch_filter,
                    'compare' => '='
                ),
                array(
                    'key' => '_menu_item_branch',
                    'value' => '',
                    'compare' => '=',
                    'type' => 'CHAR'
                ),
                array(
                    'key' => '_menu_item_branch',
                    'compare' => 'NOT EXISTS'
                )
            );
        }

        // Handle search query
        if (!empty($search_query)) {
            // Add search meta query for both posts and CPT
            $search_meta_query = array(
                'relation' => 'OR',
                array(
                    'key' => '_menu_item_name',
                    'value' => $search_query,
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => '_menu_item_description',
                    'value' => $search_query,
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => '_menu_item_name_arabic',
                    'value' => $search_query,
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => '_menu_item_description_arabic',
                    'value' => $search_query,
                    'compare' => 'LIKE'
                )
            );

            $post_args['meta_query'][] = $search_meta_query;
            $cpt_args['meta_query'][] = $search_meta_query;
        }

        // Handle voice search
        if ($voice_search_type === 'item' && !empty($voice_search_query)) {
            $arabic_search_results = $this->utils->search_arabic_items($voice_search_query, $allowed_categories);

            if (!empty($arabic_search_results)) {
                $matched_post_ids = array_map(function($result) {
                    return $result['post']->ID;
                }, $arabic_search_results);

                $post_args['post__in'] = $matched_post_ids;
                $post_args['orderby'] = 'post__in';

                $cpt_args['post__in'] = $matched_post_ids;
                $cpt_args['orderby'] = 'post__in';
            } else {
                $post_args['post__in'] = array(0);
                $cpt_args['post__in'] = array(0);
            }
        }

        // Same order as the page: the order the owner set, not newest first
        if (empty($post_args['orderby'])) {
            $post_args['orderby'] = 'menu_order';
            $post_args['order'] = 'ASC';
        }
        if (empty($cpt_args['orderby'])) {
            $cpt_args['orderby'] = 'menu_order';
            $cpt_args['order'] = 'ASC';
        }
        if ($no_results) {
            $post_args['post__in'] = array(0);
            $cpt_args['post__in'] = array(0);
        }
        $posts_with_menu_items = get_posts($post_args);
        $cpt_menu_items = get_posts($cpt_args);
        $menu_items = array_merge($posts_with_menu_items, $cpt_menu_items);

        // Apply offset and limit for show more functionality
        if ($offset > 0 || $limit > 0) {
            $menu_items = array_slice($menu_items, $offset, $limit > 0 ? $limit : null);
        }

        // Get custom categories for AJAX render
        $custom_categories_ajax = array_filter(get_option('menu_popup_categories', array()), function($cat) {
            return !empty($cat['slug']) && !empty($cat['name']);
        });

        $current_lang_ajax = $this->get_current_display_language();

        // Get all_filter setting from POST data or default to true
        $all_filter_enabled = isset($_POST['all_filter']) ? sanitize_text_field($_POST['all_filter']) : 'true';

        ob_start();
        foreach ($menu_items as $item):
            $name = get_post_meta($item->ID, '_menu_item_name', true);
            $name_arabic = get_post_meta($item->ID, '_menu_item_name_arabic', true);
            $description = get_post_meta($item->ID, '_menu_item_description', true);
            $description_arabic = get_post_meta($item->ID, '_menu_item_description_arabic', true);
            $price = get_post_meta($item->ID, '_menu_item_price', true);
            $category = get_post_meta($item->ID, '_menu_item_category', true);
            $image = get_post_meta($item->ID, '_menu_item_image', true);
            $video = get_post_meta($item->ID, '_menu_item_video', true);
            $video_arabic = get_post_meta($item->ID, '_menu_item_video_arabic', true);
            $add_to_cart_arabic = get_post_meta($item->ID, '_menu_item_add_to_cart_arabic', true);
            $order_now_arabic = get_post_meta($item->ID, '_menu_item_order_now_arabic', true);
            $item_language = get_post_meta($item->ID, '_menu_item_language', true);

            $selected_video_ajax = ($current_lang_ajax === 'ar' && !empty($video_arabic)) ? $video_arabic : $video;

            if ($current_lang_ajax === 'ar') {
                $selected_name_ajax = (!empty($name_arabic)) ? $name_arabic : $name;
                $selected_description_ajax = (!empty($description_arabic)) ? $description_arabic : $description;
                $selected_add_to_cart_ajax = (!empty($add_to_cart_arabic)) ? $add_to_cart_arabic : 'Add to Cart';
                $selected_order_now_ajax = (!empty($order_now_arabic)) ? $order_now_arabic : 'Order Now';
            } else {
                $selected_name_ajax = $name;
                $selected_description_ajax = $description;
                $selected_add_to_cart_ajax = 'Add to Cart';
                $selected_order_now_ajax = 'Order Now';
            }

            $sizes_variations = get_post_meta($item->ID, '_menu_item_sizes', true);
            $gallery_images = get_post_meta($item->ID, '_menu_item_gallery', true);

            if (!is_array($gallery_images)) {
                $gallery_images = !empty($gallery_images) ? array($gallery_images) : array();
            }

            $filtered_gallery = array_filter($gallery_images, function($url) { return !empty(trim($url)); });

            $sizes_variations_json_ajax = '';
            $first_price_ajax = isset($sizes_variations[0]['price']) ? $sizes_variations[0]['price'] : $price;

            if (!empty($sizes_variations) && is_array($sizes_variations)) {
                $sizes_variations_json_ajax = json_encode($sizes_variations);
            }
            ?>
            <div class="menu-item" data-category="<?php echo esc_attr($category); ?>" <?php if ($selected_video_ajax && get_option('menu_popup_enable_videos') == '1'): ?>data-video="<?php echo esc_url($selected_video_ajax); ?>" data-title="<?php echo esc_attr($selected_name_ajax); ?>" data-description="<?php echo esc_attr($selected_description_ajax); ?>"<?php endif; ?><?php if (!empty($filtered_gallery)): ?> data-gallery="<?php echo esc_attr(json_encode($filtered_gallery)); ?>"<?php endif; ?><?php if (get_option('menu_popup_show_price_modal', '1') == '1'): ?> data-price="<?php echo esc_attr($first_price_ajax); ?>" data-sizes="<?php echo esc_attr($sizes_variations_json_ajax); ?>"<?php endif; ?>>
                <div class="menu-item-image">
                    <?php if ($image): ?>
                        <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($name); ?>" />
                    <?php endif; ?>
                    <?php if ($selected_video_ajax && get_option('menu_popup_enable_videos') == '1'): ?>
                        <div class="play-button">
                            <span class="play-icon">▶</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="menu-item-category-tag">
                    <?php
                    $category_name_ajax = '';
                    foreach ($custom_categories_ajax as $cat) {
                        if ($cat['slug'] === $category) {
                            $category_name_ajax = $cat['name'];
                            break;
                        }
                    }
                    echo esc_html($category_name_ajax);
                    ?>
                </div>

                <div class="menu-item-info">
                    <div class="menu-item-header">
                        <h3 class="menu-item-name"><?php echo esc_html($selected_name_ajax); ?></h3>
                        <?php if (get_option('menu_popup_show_price_card', '1') == '1' && !empty($price)) : ?>
                            <span class="menu-item-price"><?php echo esc_html($price); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php
                    if (get_option('menu_popup_show_price_card', '1') == '1') {
                        $sizes_variations = get_post_meta($item->ID, '_menu_item_sizes', true);

                        if (!empty($sizes_variations) && is_array($sizes_variations)) {
                            echo '<div class="menu-item-size-selection">';
                            echo '<div class="size-buttons">';
                            foreach ($sizes_variations as $index => $variation) {
                                $size_name = isset($variation['size']) ? $variation['size'] : '';
                                $size_price = isset($variation['price']) ? $variation['price'] : '';
                                if ($size_name && $size_price) {
                                    $active_class = $index === 0 ? 'active' : '';
                                    echo '<button class="size-btn ' . $active_class . '" data-size="' . esc_attr($size_name) . '" data-price="' . esc_attr($size_price) . '">';
                                    echo esc_html($size_name) . ' • ' . esc_html($size_price);
                                    echo '</button>';
                                }
                            }
                            echo '</div></div>';
                        }
                    }
                    ?>

                    <p class="menu-item-description"><?php echo esc_html($selected_description_ajax); ?></p>

                    <!-- Order/Cart Actions -->
                    <?php if (get_option('menu_popup_ordering_enabled', '0') == '1' || get_option('menu_popup_whatsapp_enabled', '0') == '1'): ?>
                        <div class="menu-item-actions <?php echo (!empty($sizes_variations) && is_array($sizes_variations) && count($sizes_variations) > 1) ? 'has-variations' : ''; ?>">
                            <?php if (get_option('menu_popup_ordering_enabled', '0') == '1'): ?>
                                <button class="action-btn add-to-cart-btn <?php echo (!empty($sizes_variations) && is_array($sizes_variations) && count($sizes_variations) > 1) ? 'requires-size-selection' : ''; ?>" data-item-id="<?php echo esc_attr($item->ID); ?>" data-name="<?php echo esc_attr($selected_name_ajax); ?>" data-price="<?php echo esc_attr($first_price_ajax); ?>" data-selected-size="<?php echo esc_attr($sizes_variations[0]['size'] ?? ''); ?>" data-selected-price="<?php echo esc_attr($sizes_variations[0]['price'] ?? $price); ?>" <?php echo (!empty($sizes_variations) && is_array($sizes_variations) && count($sizes_variations) > 1) ? 'style="display: none;"' : ''; ?>>
                                    <span class="cart-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.4a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L20 7H6"/></svg></span> <?php echo esc_html($selected_add_to_cart_ajax); ?>
                                </button>
                            <?php endif; ?>

                            <?php if (get_option('menu_popup_whatsapp_enabled', '0') == '1'): ?>
                                <button class="action-btn order-whatsapp-btn <?php echo (!empty($sizes_variations) && is_array($sizes_variations) && count($sizes_variations) > 1) ? 'requires-size-selection' : ''; ?>" data-name="<?php echo esc_attr($selected_name_ajax); ?>" data-price="<?php echo esc_attr($first_price_ajax); ?>" data-selected-size="<?php echo esc_attr($sizes_variations[0]['size'] ?? ''); ?>" data-selected-price="<?php echo esc_attr($sizes_variations[0]['price'] ?? $price); ?>" <?php echo (!empty($sizes_variations) && is_array($sizes_variations) && count($sizes_variations) > 1) ? 'style="display: none;"' : ''; ?>>
                                    <span class="whatsapp-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg></span> <?php echo esc_html($selected_order_now_ajax); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php
        $output = ob_get_clean();
        wp_send_json_success($output);
    }

    public function ajax_get_branches_by_city() {
        // Branch names and addresses are public, so no nonce: a cached page must not break this
        $city_slug = sanitize_text_field($_POST['city_slug']);

        $args = array(
            'post_type' => 'menu_popup_branch',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'tax_query' => array(
                array(
                    'taxonomy' => 'menu_popup_branch_city',
                    'field'    => 'slug',
                    'terms'    => $city_slug,
                ),
            ),
        );

        if (empty($city_slug)) {
            unset($args['tax_query']);
        }

        $branches = get_posts($args);

        ob_start();
        if (!empty($branches)):
            foreach ($branches as $branch):
                $city_terms = get_terms(array(
                    'taxonomy' => 'menu_popup_branch_city',
                    'object_ids' => $branch->ID
                ));
                $city_name = !empty($city_terms) ? $city_terms[0]->name : '';

                $whatsapp = get_post_meta($branch->ID, '_menu_popup_branch_whatsapp', true);
                $address = get_post_meta($branch->ID, '_menu_popup_branch_address', true);
                $hours = get_post_meta($branch->ID, '_menu_popup_branch_hours', true);
                $branch_slug = $branch->post_name;
    ?>
    <div class="location-card branch-card" role="button" tabindex="0"
         data-branch-id="<?php echo esc_attr($branch->ID); ?>"
         data-branch-slug="<?php echo esc_attr($branch_slug); ?>"
         data-city-slug="<?php echo esc_attr($city_slug); ?>">
        <h3><?php echo esc_html($branch->post_title); ?></h3>
        <?php if (!empty($city_name)): ?>
            <p><?php echo esc_html($city_name); ?></p>
        <?php endif; ?>
        <?php if (!empty($address)): ?>
            <p><?php echo esc_html($address); ?></p>
        <?php endif; ?>
        <?php if (!empty($hours)): ?>
            <p><?php echo esc_html($hours); ?></p>
        <?php endif; ?>
    </div>
    <?php
            endforeach;
        else:
    ?>
    <div class="location-card">
        <h3><?php _e('No Branches Available', 'menu-popup'); ?></h3>
        <p><?php _e('No branches found for the selected city.', 'menu-popup'); ?></p>
    </div>
    <?php
        endif;

        $output = ob_get_clean();
        wp_send_json_success($output);
    }

    /**
     * Branch id from the address bar, only if it is a real, published branch.
     */
    private function requested_branch() {
        return $this->valid_branch_id(isset($_GET['branch']) ? $_GET['branch'] : 0);
    }

    private function valid_branch_id($id) {
        $id = absint($id);
        if ($id < 1) {
            return 0;
        }
        $post = get_post($id);
        return ($post && $post->post_type === 'menu_popup_branch' && $post->post_status === 'publish') ? $id : 0;
    }

    private function published_branches() {
        return get_posts(array(
            'post_type' => 'menu_popup_branch',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ));
    }

    /**
     * Cities that really have a published branch, counting branches of their sub-areas.
     */
    private function cities_with_branches() {
        $terms = get_terms(array(
            'taxonomy' => 'menu_popup_branch_city',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));
        if (is_wp_error($terms) || empty($terms)) {
            return array();
        }
        $keep = array();
        foreach ($terms as $term) {
            $ids = get_posts(array(
                'post_type' => 'menu_popup_branch',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'tax_query' => array(array(
                    'taxonomy' => 'menu_popup_branch_city',
                    'field' => 'term_id',
                    'terms' => $term->term_id,
                )),
            ));
            if (!empty($ids)) {
                $keep[] = $term;
            }
        }
        return $keep;
    }

    private function has_branch_without_city($branches) {
        foreach ($branches as $branch) {
            $terms = wp_get_post_terms($branch->ID, 'menu_popup_branch_city');
            if (is_wp_error($terms) || empty($terms)) {
                return true;
            }
        }
        return false;
    }

    private function render_branch_selection_flow($atts) {
        $force_branch_selection = get_option('menu_popup_force_branch_selection', '0') === '1';
        $enable_cities = get_option('menu_popup_enable_cities', '1') === '1';

        if ($this->requested_branch() > 0) {
            $this->render_menu($atts);
            return;
        }

        $branches = $this->published_branches();

        $cities = $this->cities_with_branches();
        // A branch without a city would be unreachable behind the city step: show all branches instead
        if ($this->has_branch_without_city($branches)) {
            $cities = array();
        }

        if (empty($branches)) {
            $this->render_menu($atts);
            return;
        }

        $current_lang = $this->get_current_display_language();
        $direction = ($current_lang === 'ar') ? 'rtl' : 'ltr';
        $css_class = ($current_lang === 'ar') ? 'menu-rtl menu-rtl' : 'menu-ltr';

        $primary_color = esc_attr(get_option('menu_popup_primary_color', '#A855F7'));
        $secondary_color = esc_attr(get_option('menu_popup_secondary_color', '#1F2937'));
        $background_color = esc_attr(get_option('menu_popup_background_color', '#0F0F23'));
        $text_color = esc_attr(get_option('menu_popup_text_color', '#F9FAFB'));

        ?>
        <style>
            .menu-popup-container {
                --menu-bg: <?php echo $background_color; ?>;
                --menu-text: <?php echo $text_color; ?>;
                --menu-primary: <?php echo $primary_color; ?>;
                <?php echo esc_html(self::contrast_vars()); ?>
                --menu-secondary: <?php echo $secondary_color; ?>;
            }

            .branch-selection-container {
                background: var(--menu-bg);
                color: var(--menu-text);
                padding: 2rem;
                min-height: 100vh;
            }

            .branch-selection-flow {
                max-width: 1200px;
                margin: 0 auto;
            }

            .city-selection-step, .branch-selection-step {
                display: none;
            }

            .city-selection-step.active, .branch-selection-step.active {
                display: block;
            }

            .location-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 1.5rem;
                margin: 2rem 0;
            }

            .location-card {
                background: var(--menu-secondary);
                padding: 1.5rem;
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.3s ease;
                border: 2px solid transparent;
                text-align: center;
            }

            .location-card:hover, .location-card.selected {
                border-color: var(--menu-primary);
                transform: translateY(-2px);
            }

            .location-card h3 {
                margin: 0 0 0.5rem 0;
                color: var(--menu-text);
            }

            .location-card p {
                margin: 0.25rem 0;
                color: var(--menu-text);
                opacity: 0.8;
                font-size: 0.9rem;
            }

            .step-navigation {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin: 2rem 0;
            }

            .back-button {
                background: transparent;
                border: 1px solid var(--menu-primary);
                color: var(--menu-primary);
                padding: 0.75rem 1.5rem;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .back-button:hover {
                background: var(--menu-primary);
                color: white;
            }

            .continue-button {
                background: var(--menu-primary);
                color: white;
                border: none;
                padding: 0.75rem 1.5rem;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .continue-button:hover {
                opacity: 0.9;
            }

            .welcome-message {
                text-align: center;
                margin-bottom: 3rem;
            }

            .welcome-message h2 {
                color: var(--menu-text);
                margin-bottom: 1rem;
            }

            .welcome-message p {
                color: var(--menu-text);
                opacity: 0.8;
                max-width: 600px;
                margin: 0 auto;
            }
        </style>

        <div class="menu-popup-container <?php echo $css_class; ?>" dir="<?php echo $direction; ?>">
            <div class="branch-selection-container">
                <div class="branch-selection-flow">
                    <div class="welcome-message">
                        <h2><?php _e('Welcome to Our Menu', 'menu-popup'); ?></h2>
                        <p><?php _e('Please select your preferred location to see available menu items and place your order.', 'menu-popup'); ?></p>
                    </div>

                    <?php if ($enable_cities && !empty($cities)): ?>
                        <div class="city-selection-step active" id="cityStep">
                            <h3 style="text-align: center; color: var(--menu-text); margin-bottom: 2rem;">
                                <?php _e('Choose Your City', 'menu-popup'); ?>
                            </h3>
                            <div class="location-grid" id="cityGrid">
                                <?php foreach ($cities as $city): ?>
                                    <div class="location-card city-card" data-city-slug="<?php echo esc_attr($city->slug); ?>">
                                        <h3><?php echo esc_html($city->name); ?></h3>
                                        <p><?php echo esc_html($city->description ?: __('Click to select this city', 'menu-popup')); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="branch-selection-step <?php echo (!$enable_cities || empty($cities)) ? 'active' : ''; ?>" id="branchStep">
                        <div class="step-navigation">
                            <?php if ($enable_cities && !empty($cities)): ?>
                                <button class="back-button" id="backToCity">
                                    <?php _e('← Back to Cities', 'menu-popup'); ?>
                                </button>
                            <?php else: ?>
                                <div></div>
                            <?php endif; ?>
                            <h3 style="color: var(--menu-text); margin: 0;">
                                <?php _e('Choose Your Branch', 'menu-popup'); ?>
                            </h3>
                        </div>

                        <div class="location-grid" id="branchGrid">
                            <?php if (!empty($branches)): ?>
                                <?php foreach ($branches as $branch): ?>
                                    <?php
                                    $city_terms = wp_get_post_terms($branch->ID, 'menu_popup_branch_city');
                                    $city_slug = !empty($city_terms) ? $city_terms[0]->slug : '';
                                    $city_name = !empty($city_terms) ? $city_terms[0]->name : '';

                                    $whatsapp = get_post_meta($branch->ID, '_menu_popup_branch_whatsapp', true);
                                    $address = get_post_meta($branch->ID, '_menu_popup_branch_address', true);
                                    $hours = get_post_meta($branch->ID, '_menu_popup_branch_hours', true);
                                    $branch_slug = $branch->post_name;
                                    ?>
                                    <div class="location-card branch-card" role="button" tabindex="0"
                                         data-branch-id="<?php echo esc_attr($branch->ID); ?>"
                                         data-branch-slug="<?php echo esc_attr($branch_slug); ?>"
                                         data-city-slug="<?php echo esc_attr($city_slug); ?>">
                                        <h3><?php echo esc_html($branch->post_title); ?></h3>
                                        <?php if (!empty($city_name)): ?>
                                            <p><?php echo esc_html($city_name); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($address)): ?>
                                            <p><?php echo esc_html($address); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($hours)): ?>
                                            <p><?php echo esc_html($hours); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="location-card">
                                    <h3><?php _e('No Branches Available', 'menu-popup'); ?></h3>
                                    <p><?php _e('No branches have been configured yet.', 'menu-popup'); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            let selectedBranch = '';

            $('.city-card').on('click', function() {
                const citySlug = $(this).data('city-slug');

                $('.city-card').removeClass('selected');
                $(this).addClass('selected');

                loadBranchesForCity(citySlug);

                if ($('#cityStep').length > 0) {
                    $('#cityStep').removeClass('active');
                    $('#branchStep').addClass('active');
                }
            });

            $('#backToCity').on('click', function() {
                $('#branchStep').removeClass('active');
                $('#cityStep').addClass('active');
                $('.city-card').removeClass('selected');
                selectedBranch = '';
            });

            $(document).on('click', '.branch-card', function() {
                $('.branch-card').removeClass('selected');
                $(this).addClass('selected');
                const branchId = $(this).data('branch-id');

                if (branchId) {
                    // Store branch ID in localStorage for faster subsequent visits
                    localStorage.setItem('menuPopupBranch', branchId);
                    
                    // Update URL without reload first (for smooth UX)
                    const url = new URL(window.location.href);
                    url.searchParams.set('branch', branchId);
                    window.history.pushState({}, '', url.toString());
                    
                    // Then reload - it will be fast since branch is now in URL
                    // This is needed because PHP renders the menu server-side
                    window.location.reload();
                }
            });

            function loadBranchesForCity(citySlug) {
                $.ajax({
                    url: menuPopupAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_branches_by_city',
                        city_slug: citySlug,
                        nonce: menuPopupAjax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#branchGrid').html(response.data);
                        }
                    },
                    error: function() {
                        $('#branchGrid').html('<div class="location-card"><h3>Error</h3><p>Failed to load branches. Please try again.</p></div>');
                    }
                });
            }

            // Go straight to the branch chosen on the last visit, if it still exists
            try {
                const saved = parseInt(localStorage.getItem('menuPopupBranch'), 10);
                if (saved > 0) {
                    if ($('.branch-card[data-branch-id="' + saved + '"]').length && sessionStorage.getItem('mpAutoBranch') !== String(saved)) {
                        sessionStorage.setItem('mpAutoBranch', String(saved));
                        const url = new URL(window.location.href);
                        url.searchParams.set('branch', saved);
                        window.location.replace(url.toString());
                    } else if (!$('.branch-card[data-branch-id="' + saved + '"]').length) {
                        localStorage.removeItem('menuPopupBranch');
                    }
                }
            } catch (e) {}
        });
        </script>
        <?php
    }

    private function get_current_display_language() {
        if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'ar'])) {
            return $_GET['lang'];
        }

        if (isset($_COOKIE['menu_popup_lang']) && in_array($_COOKIE['menu_popup_lang'], ['en', 'ar'])) {
            return $_COOKIE['menu_popup_lang'];
        }

        if (function_exists('icl_get_current_language')) {
            $lang = icl_get_current_language();
            return ($lang === 'ar') ? 'ar' : 'en';
        }

        if (function_exists('pll_current_language')) {
            $lang = pll_current_language();
            return ($lang === 'ar') ? 'ar' : 'en';
        }

        if (isset($GLOBALS['qtranslate_mode']) && $GLOBALS['qtranslate_mode'] === 'url') {
            $current_url = $_SERVER['REQUEST_URI'];
            if (strpos($current_url, '/ar/') !== false || strpos($current_url, '?lang=ar') !== false) {
                return 'ar';
            }
        }

        $locale = get_locale();
        return (strpos($locale, 'ar') === 0 || $locale === 'ar') ? 'ar' : 'en';
    }

    public function enqueue_swiper_assets() {
        if (!is_admin() && (is_singular() || is_page() || is_front_page())) {
            wp_enqueue_style('swiper-css', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css', array(), '11.0.0');
            wp_enqueue_script('swiper-js', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js', array(), '11.0.0', true);
        }
    }

    private function render_featured_slideshow($atts) {
        // Static flag to ensure video modal is only rendered once per page
        static $video_modal_rendered = false;

        $post_args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_menu_item_featured',
                    'value' => '1',
                    'compare' => '='
                )
            ),
            'posts_per_page' => intval($atts['limit']),
            'orderby' => 'menu_order',
            'order' => 'ASC'
        );

        $cpt_args = array(
            'post_type' => 'menu_item',
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_menu_item_featured',
                    'value' => '1',
                    'compare' => '='
                )
            ),
            'posts_per_page' => intval($atts['limit']),
            'orderby' => 'menu_order',
            'order' => 'ASC'
        );

        $posts_with_featured_items = get_posts($post_args);
        $cpt_featured_items = get_posts($cpt_args);
        $featured_items = array_slice(array_merge($posts_with_featured_items, $cpt_featured_items), 0, intval($atts['limit']));

        if (empty($featured_items)) {
            echo '<div class="menu-popup-container">';
            echo '<div class="menu-no-items-msg">';
            echo '<h2>' . __('No Featured Items', 'menu-popup') . '</h2>';
            echo '<p>' . __('No featured menu items have been selected yet. Please mark some items as featured in the admin.', 'menu-popup') . '</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        $current_lang = $this->get_current_display_language();
        $direction = ($current_lang === 'ar') ? 'rtl' : 'ltr';
        $css_class = ($current_lang === 'ar') ? 'menu-rtl menu-rtl' : 'menu-ltr';

        $primary_color = esc_attr(get_option('menu_popup_primary_color', '#A855F7'));
        $secondary_color = esc_attr(get_option('menu_popup_secondary_color', '#1F2937'));
        $accent_color = esc_attr(get_option('menu_popup_accent_color', '#F59E0B'));
        $background_color = esc_attr(get_option('menu_popup_background_color', '#0F0F23'));
        $text_color = esc_attr(get_option('menu_popup_text_color', '#F9FAFB'));

        $cart_background_color = esc_attr(get_option('menu_popup_cart_background_color', '#161235'));
        $cart_border_color = esc_attr(get_option('menu_popup_cart_border_color', '#5755f7'));
        $cart_text_color = esc_attr(get_option('menu_popup_cart_text_color', '#F9FAFB'));
        $cart_price_color = esc_attr(get_option('menu_popup_cart_price_color', '#F59E0B'));
        $cart_total_color = esc_attr(get_option('menu_popup_cart_total_color', '#A855F7'));
        $cart_icon_color = esc_attr(get_option('menu_popup_cart_icon_color', '#F9FAFB'));
        $cart_icon_size = esc_attr(get_option('menu_popup_cart_icon_size', '20'));
        $cart_toggle_icon_size = esc_attr(get_option('menu_popup_cart_toggle_icon_size', '20'));

        $order_modal_bg_color = esc_attr(get_option('menu_popup_order_modal_bg_color', '#1F2937'));
        $order_modal_border_color = esc_attr(get_option('menu_popup_order_modal_border_color', '#A855F7'));
        $order_modal_text_color = esc_attr(get_option('menu_popup_order_modal_text_color', '#F9FAFB'));
        $order_modal_accent_color = esc_attr(get_option('menu_popup_order_modal_accent_color', '#F59E0B'));
        $order_modal_overlay_color = esc_attr(get_option('menu_popup_order_modal_overlay_color', 'rgba(0, 0, 0, 0.7)'));

        ?>
        <style>
            .menu-popup-container {
                --menu-bg: <?php echo $background_color; ?>;
                --menu-text: <?php echo $text_color; ?>;
                --menu-primary: <?php echo $primary_color; ?>;
                <?php echo esc_html(self::contrast_vars()); ?>
                --menu-secondary: <?php echo $secondary_color; ?>;
                --menu-accent: <?php echo $accent_color; ?>;
                --menu-cart-bg: <?php echo $cart_background_color; ?>;
                --menu-cart-border: <?php echo $cart_border_color; ?>;
                --menu-cart-text: <?php echo $cart_text_color; ?>;
                --menu-cart-price: <?php echo $cart_price_color; ?>;
                --menu-cart-total: <?php echo $cart_total_color; ?>;
                --menu-cart-icon-color: <?php echo $cart_icon_color; ?>;
                --menu-cart-icon-size: <?php echo $cart_icon_size; ?>px;
                --menu-cart-toggle-icon-size: <?php echo $cart_toggle_icon_size; ?>px;
                --menu-order-modal-bg: <?php echo $order_modal_bg_color; ?>;
                --menu-order-modal-border: <?php echo $order_modal_border_color; ?>;
                --menu-order-modal-text: <?php echo $order_modal_text_color; ?>;
                --menu-order-modal-accent: <?php echo $order_modal_accent_color; ?>;
                --menu-order-modal-overlay: <?php echo $order_modal_overlay_color; ?>;
            }

            .featured-slideshow-container .video-modal-overlay {
                z-index: 999999 !important;
                position: fixed !important;
            }

            .featured-slideshow-container .video-modal-overlay.active {
                z-index: 999999 !important;
            }

            .featured-slideshow-container {
                max-width: 1400px;
                margin: 0 auto;
                padding: 2rem 1rem;
                background: var(--menu-bg);
                color: var(--menu-text);
                min-height: 600px;
                position: relative;
            }

            .featured-slideshow-wrapper {
                position: relative;
                overflow: hidden;
            }

            .featured-slideshow {
                width: 100%;
                height: 100%;
            }

            .featured-slide {
                display: flex;
                align-items: stretch;
                justify-content: center;
                min-height: 500px;
                padding: 2rem 0;
                gap: 1rem;
            }

            .featured-slide-item {
                max-width: 380px;
                width: 100%;
                flex: 1;
                min-width: 0;
            }

            .featured-navigation {
                position: absolute;
                top: 50%;
                transform: translateY(-50%);
                z-index: 10;
                background: var(--menu-primary);
                border: 2px solid rgba(255, 255, 255, 0.2);
                color: white;
                width: 60px;
                height: 60px;
                border-radius: 50%;
                cursor: pointer;
                font-size: 24px;
                font-weight: bold;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: 0 4px 20px rgba(168, 85, 247, 0.3);
                backdrop-filter: blur(10px);
            }

            .featured-navigation:hover {
                background: var(--menu-accent);
                transform: translateY(-50%) scale(1.1);
                box-shadow: 0 8px 30px rgba(245, 158, 11, 0.4);
                border-color: rgba(255, 255, 255, 0.3);
            }

            .featured-navigation:active {
                transform: translateY(-50%) scale(0.95);
            }

            .featured-prev {
                left: 20px;
            }

            .featured-next {
                right: 20px;
            }

            .featured-pagination {
                position: absolute;
                bottom: 30px;
                left: 50%;
                transform: translateX(-50%);
                z-index: 10;
            }

            .swiper-pagination-bullet {
                background: rgba(255, 255, 255, 0.4);
                width: 14px;
                height: 14px;
                margin: 0 6px;
                border-radius: 50%;
                cursor: pointer;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                border: 2px solid transparent;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            }

            .swiper-pagination-bullet:hover {
                background: rgba(255, 255, 255, 0.7);
                transform: scale(1.1);
            }

            .swiper-pagination-bullet-active {
                background: var(--menu-primary);
                transform: scale(1.3);
                box-shadow: 0 4px 12px rgba(168, 85, 247, 0.5);
                border-color: rgba(255, 255, 255, 0.3);
            }

            @media (max-width: 768px) {
                .featured-slideshow-container {
                    padding: 1rem 0.5rem;
                }

                .featured-slide-item {
                    max-width: 300px;
                    margin: 0 0.5rem;
                }

                .featured-navigation {
                    width: 40px;
                    height: 40px;
                    font-size: 16px;
                }

                .featured-prev {
                    left: 10px;
                }

                .featured-next {
                    right: 10px;
                }
            }

            @media (max-width: 480px) {
                .featured-slide-item {
                    max-width: 280px;
                }
            }
        </style>

        <div class="menu-popup-container <?php echo $css_class; ?> featured-slideshow-container" dir="<?php echo $direction; ?>">
            <div class="featured-slideshow-wrapper">
                <div class="swiper featured-slideshow">
                    <div class="swiper-wrapper">
                        <?php foreach ($featured_items as $item): ?>
                            <?php
                            $name = get_post_meta($item->ID, '_menu_item_name', true);
                            $name_arabic = get_post_meta($item->ID, '_menu_item_name_arabic', true);
                            $description = get_post_meta($item->ID, '_menu_item_description', true);
                            $description_arabic = get_post_meta($item->ID, '_menu_item_description_arabic', true);
                            $price = get_post_meta($item->ID, '_menu_item_price', true);
                            $image = get_post_meta($item->ID, '_menu_item_image', true);
                            $video = get_post_meta($item->ID, '_menu_item_video', true);
                            $video_arabic = get_post_meta($item->ID, '_menu_item_video_arabic', true);
                            $add_to_cart_arabic = get_post_meta($item->ID, '_menu_item_add_to_cart_arabic', true);
                            $order_now_arabic = get_post_meta($item->ID, '_menu_item_order_now_arabic', true);

                            $selected_video = ($current_lang === 'ar' && !empty($video_arabic)) ? $video_arabic : $video;

                            if ($current_lang === 'ar') {
                                $selected_name = (!empty($name_arabic)) ? $name_arabic : $name;
                                $selected_description = (!empty($description_arabic)) ? $description_arabic : $description;
                                $selected_add_to_cart = (!empty($add_to_cart_arabic)) ? $add_to_cart_arabic : __('Add to Cart', 'menu-popup');
                                $selected_order_now = (!empty($order_now_arabic)) ? $order_now_arabic : __('Order Now', 'menu-popup');
                            } else {
                                $selected_name = $name;
                                $selected_description = $description;
                                $selected_add_to_cart = __('Add to Cart', 'menu-popup');
                                $selected_order_now = __('Order Now', 'menu-popup');
                            }

                            $gallery_images = get_post_meta($item->ID, '_menu_item_gallery', true);
                            if (!is_array($gallery_images)) {
                                $gallery_images = !empty($gallery_images) ? array($gallery_images) : array();
                            }
                            $filtered_gallery = array_filter($gallery_images, function($url) { return !empty(trim($url)); });

                            $sizes_variations_json = '';
                            $sizes_variations_data = get_post_meta($item->ID, '_menu_item_sizes', true);
                            $first_price = isset($sizes_variations_data[0]['price']) ? $sizes_variations_data[0]['price'] : $price;

                            if (!empty($sizes_variations_data) && is_array($sizes_variations_data)) {
                                $sizes_variations_json = json_encode($sizes_variations_data);
                            }
                            ?>
                            <div class="swiper-slide featured-slide">
                                <div class="featured-slide-item">
                                    <?php echo $this->render_menu_item_card($item, $selected_name, $selected_description, $price, $image, $selected_video, $filtered_gallery, $first_price, $sizes_variations_json, $selected_add_to_cart, $selected_order_now, $current_lang); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($atts['show_arrows'] === 'true'): ?>
                        <div class="swiper-button-prev featured-navigation featured-prev"></div>
                        <div class="swiper-button-next featured-navigation featured-next"></div>
                    <?php endif; ?>

                    <?php if ($atts['show_dots'] === 'true'): ?>
                        <div class="swiper-pagination featured-pagination"></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$video_modal_rendered): ?>
                <?php $video_modal_rendered = true; ?>
                <div class="video-modal-overlay" id="globalVideoModal" role="dialog" aria-modal="true" aria-labelledby="mpVideoModalTitle" style="display: none;">
                    <div class="video-modal-container">
                        <div class="video-modal-content">
                            <iframe allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" title=""></iframe>
                        </div>
                        <div class="video-modal-gallery-section">
                            <div class="video-modal-header">
                                <h3 class="video-modal-title" id="mpVideoModalTitle"></h3>
                                <p class="video-modal-description"></p>
                                <?php if (get_option('menu_popup_show_price_modal', '1') == '1'): ?>
                                    <div class="video-modal-variations" style="display: none;"></div>
                                    <div class="video-modal-price" style="display: none;"></div>
                                <?php endif; ?>

                                <div class="video-modal-actions" style="display: none;">
                                    <button class="action-btn video-add-to-cart-btn" style="display: none;">
                                        <span class="cart-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.4a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L20 7H6"/></svg></span> <?php _e('Add to Cart', 'menu-popup'); ?>
                                    </button>
                                    <button class="action-btn video-order-whatsapp-btn" style="display: none;">
                                        <span class="whatsapp-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg></span> <?php _e('Order Now', 'menu-popup'); ?>
                                    </button>
                                </div>
                            </div>
                            <div class="video-modal-gallery"></div>
                        </div>
                        <button type="button" class="video-modal-close" title="Close Video" aria-label="Close video">×</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Check if Swiper is available
            if (typeof Swiper === 'undefined') {
                console.warn('Swiper library not loaded');
                return;
            }

            const swiper = new Swiper('.featured-slideshow', {
                slidesPerView: 3,
                spaceBetween: 30,
                centeredSlides: false,
                <?php if ($atts['autoplay'] === 'true'): ?>
                autoplay: {
                    delay: 5000,
                    disableOnInteraction: false
                },
                <?php endif; ?>
                loop: true,
                navigation: {
                    nextEl: '.swiper-button-next',
                    prevEl: '.swiper-button-prev',
                },
                pagination: {
                    el: '.swiper-pagination',
                    clickable: true,
                },
                breakpoints: {
                    320: {
                        slidesPerView: 1,
                        spaceBetween: 10,
                    },
                    480: {
                        slidesPerView: 1,
                        spaceBetween: 15,
                    },
                    768: {
                        slidesPerView: 2,
                        spaceBetween: 20,
                    },
                    1024: {
                        slidesPerView: 3,
                        spaceBetween: 30,
                    }
                }
            });

            const slideshowContainer = document.querySelector('.featured-slideshow-wrapper');
            if (slideshowContainer) {
                slideshowContainer.addEventListener('mouseenter', function() {
                    <?php if ($atts['autoplay'] === 'true'): ?>
                    swiper.autoplay.stop();
                    <?php endif; ?>
                });
                slideshowContainer.addEventListener('mouseleave', function() {
                    <?php if ($atts['autoplay'] === 'true'): ?>
                    swiper.autoplay.start();
                    <?php endif; ?>
                });
            }
        });
        </script>
        <?php
    }

    private function render_menu_item_card($item, $selected_name, $selected_description, $price, $image, $selected_video, $filtered_gallery, $first_price, $sizes_variations_json, $selected_add_to_cart, $selected_order_now, $current_lang) {
        ob_start();
        ?>
        <div class="menu-item" data-category="featured" <?php if ($selected_video && get_option('menu_popup_enable_videos') == '1'): ?>data-video="<?php echo esc_url($selected_video); ?>" data-title="<?php echo esc_attr($selected_name); ?>" data-description="<?php echo esc_attr($selected_description); ?>"<?php endif; ?><?php if (!empty($filtered_gallery)): ?> data-gallery="<?php echo esc_attr(json_encode($filtered_gallery)); ?>"<?php endif; ?><?php if (get_option('menu_popup_show_price_modal', '1') == '1'): ?> data-price="<?php echo esc_attr($first_price ?? $price); ?>" data-sizes="<?php echo esc_attr($sizes_variations_json); ?>"<?php endif; ?>>
            <div class="menu-item-image">
                <?php if ($image): ?>
                    <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($selected_name); ?>" />
                <?php endif; ?>
                <?php if ($selected_video && get_option('menu_popup_enable_videos') == '1'): ?>
                    <div class="play-button">
                        <span class="play-icon">▶</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="menu-item-info">
                <div class="menu-item-header">
                    <h3 class="menu-item-name"><?php echo esc_html($selected_name); ?></h3>
                    <?php if (get_option('menu_popup_show_price_card', '1') == '1' && !empty($price)) : ?>
                        <span class="menu-item-price"><?php echo esc_html($price); ?></span>
                    <?php endif; ?>
                </div>

                <?php
                if (get_option('menu_popup_show_price_card', '1') == '1') {
                    $sizes_variations = get_post_meta($item->ID, '_menu_item_sizes', true);
                    if (!empty($sizes_variations) && is_array($sizes_variations)) {
                        echo '<div class="menu-item-size-selection">';
                        echo '<div class="size-buttons">';
                        foreach ($sizes_variations as $index => $variation) {
                            $size_name = isset($variation['size']) ? $variation['size'] : '';
                            $size_price = isset($variation['price']) ? $variation['price'] : '';
                            if ($size_name && $size_price) {
                                $active_class = $index === 0 ? 'active' : '';
                                echo '<button class="size-btn ' . $active_class . '" data-size="' . esc_attr($size_name) . '" data-price="' . esc_attr($size_price) . '">';
                                echo esc_html($size_name) . ' • ' . esc_html($size_price);
                                echo '</button>';
                            }
                        }
                        echo '</div></div>';
                    }
                }
                ?>

                <p class="menu-item-description"><?php echo esc_html($selected_description); ?></p>

                <?php if (get_option('menu_popup_ordering_enabled', '0') == '1' || get_option('menu_popup_whatsapp_enabled', '0') == '1'): ?>
                    <div class="menu-item-actions">
                        <?php if (get_option('menu_popup_ordering_enabled', '0') == '1'): ?>
                            <button class="action-btn add-to-cart-btn" data-item-id="<?php echo esc_attr($item->ID); ?>" data-name="<?php echo esc_attr($selected_name); ?>" data-price="<?php echo esc_attr($first_price ?? $price); ?>" data-selected-size="<?php echo esc_attr($sizes_variations[0]['size'] ?? ''); ?>" data-selected-price="<?php echo esc_attr($sizes_variations[0]['price'] ?? $price); ?>">
                                <span class="cart-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.4a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.8L20 7H6"/></svg></span> <?php echo esc_html($selected_add_to_cart); ?>
                            </button>
                        <?php endif; ?>

                        <?php if (get_option('menu_popup_whatsapp_enabled', '0') == '1'): ?>
                            <button class="action-btn order-whatsapp-btn" data-name="<?php echo esc_attr($selected_name); ?>" data-price="<?php echo esc_attr($first_price ?? $price); ?>" data-selected-size="<?php echo esc_attr($sizes_variations[0]['size'] ?? ''); ?>" data-selected-price="<?php echo esc_attr($sizes_variations[0]['price'] ?? $price); ?>">
                                <span class="whatsapp-icon" aria-hidden="true"><svg class="mp-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg></span> <?php echo esc_html($selected_order_now); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
