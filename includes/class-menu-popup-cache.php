<?php
/**
 * Cache Manager
 * 
 * Handles caching for frequently accessed data using WordPress transients
 * 
 * @package MenuPopup
 * @subpackage Core
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache Manager Class
 */
class Menu_Popup_Cache {
    
    /**
     * Cache expiration times (in seconds)
     */
    const CACHE_MENU_ITEMS = HOUR_IN_SECONDS;      // 1 hour
    const CACHE_CATEGORIES = HOUR_IN_SECONDS * 2; // 2 hours
    const CACHE_BRANCHES = HOUR_IN_SECONDS;       // 1 hour
    const CACHE_SETTINGS = HOUR_IN_SECONDS;       // 1 hour
    const CACHE_OPENING_HOURS = HOUR_IN_SECONDS;   // 1 hour
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - add cache clear hooks
     */
    private function __construct() {
        // Clear cache when menu items are updated
        add_action('save_post_menu_item', [$this, 'clear_menu_cache']);
        add_action('delete_post', [$this, 'clear_menu_cache']);
        
        // Clear cache when branches are updated
        add_action('save_post_menu_popup_branch', [$this, 'clear_branch_cache']);
        
        // Clear all cache on plugin settings change
        add_action('update_option', [$this, 'clear_settings_cache_on_change']);
    }
    
    /**
     * Get cached menu items
     */
    public function get_menu_items($args = []) {
        $cache_key = 'menu_items_' . md5(serialize($args));
        $items = get_transient($cache_key);
        
        if (false === $items) {
            $items = $this->fetch_menu_items($args);
            $this->set_menu_items($cache_key, $items);
        }
        
        return $items;
    }
    
    /**
     * Fetch menu items from database
     */
    private function fetch_menu_items($args = []) {
        $defaults = [
            'post_type' => 'menu_item',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'menu_order',
            'order' => 'ASC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        $query = new \WP_Query($args);
        
        return $query->posts;
    }
    
    /**
     * Set menu items cache
     */
    private function set_menu_items($key, $items) {
        set_transient($key, $items, self::CACHE_MENU_ITEMS);
    }
    
    /**
     * Clear menu items cache
     */
    public function clear_menu_cache() {
        global $wpdb;
        
        // Clear all menu item related transients
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_menu_items_') . '%'
            )
        );
        
        // Clear transient timeout versions
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_timeout_menu_items_') . '%'
            )
        );
    }
    
    /**
     * Get cached categories
     */
    public function get_categories() {
        $cache_key = 'menu_categories';
        $categories = get_transient($cache_key);
        
        if (false === $categories) {
            $categories = get_option('menu_popup_categories', []);
            set_transient($cache_key, $categories, self::CACHE_CATEGORIES);
        }
        
        return $categories;
    }
    
    /**
     * Clear categories cache
     */
    public function clear_categories_cache() {
        delete_transient('menu_categories');
    }
    
    /**
     * Get cached branches
     */
    public function get_branches($args = []) {
        $cache_key = 'menu_branches_' . md5(serialize($args));
        $branches = get_transient($cache_key);
        
        if (false === $branches) {
            $branches = $this->fetch_branches($args);
            set_transient($cache_key, $branches, self::CACHE_BRANCHES);
        }
        
        return $branches;
    }
    
    /**
     * Fetch branches from database
     */
    private function fetch_branches($args = []) {
        $defaults = [
            'post_type' => 'menu_popup_branch',
            'post_status' => 'publish',
            'posts_per_page' => -1
        ];
        
        $args = wp_parse_args($args, $defaults);
        $query = new \WP_Query($args);
        
        return $query->posts;
    }
    
    /**
     * Clear branches cache
     */
    public function clear_branch_cache() {
        global $wpdb;
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_menu_branches_') . '%'
            )
        );
    }
    
    /**
     * Get cached settings
     */
    public function get_settings($key, $default = false) {
        $cache_key = 'menu_settings_' . $key;
        $value = get_transient($cache_key);
        
        if (false === $value) {
            $value = get_option($key, $default);
            // Don't cache empty values
            if (!empty($value)) {
                set_transient($cache_key, $value, self::CACHE_SETTINGS);
            }
        }
        
        return $value;
    }
    
    /**
     * Clear settings cache on option update
     */
    public function clear_settings_cache_on_change($option_name) {
        if (strpos($option_name, 'menu_popup_') === 0) {
            delete_transient('menu_settings_' . $option_name);
        }
    }
    
    /**
     * Get cached opening hours
     */
    public function get_opening_hours($branch_id, $date = null) {
        $cache_key = 'opening_hours_' . $branch_id . '_' . ($date ?: date('Y-m-d'));
        $hours = get_transient($cache_key);
        
        if (false === $hours) {
            $hours = $this->fetch_opening_hours($branch_id, $date);
            set_transient($cache_key, $hours, self::CACHE_OPENING_HOURS);
        }
        
        return $hours;
    }
    
    /**
     * Fetch opening hours from database
     */
    private function fetch_opening_hours($branch_id, $date = null) {
        $day_of_week = strtolower(date('D', strtotime($date ?: current_time('Y-m-d'))));
        
        $hours = get_post_meta($branch_id, '_opening_hours_' . $day_of_week, true);
        
        return $hours ?: [];
    }
    
    /**
     * Clear all plugin caches
     */
    public function clear_all_caches() {
        global $wpdb;
        
        // Clear all menu popup transients
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_menu_') . '%',
                $wpdb->esc_like('_transient_timeout_menu_') . '%'
            )
        );
        
        return true;
    }
    
    /**
     * Get cache statistics
     */
    public function get_cache_stats() {
        global $wpdb;
        
        $transient_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_menu_') . '%'
            )
        );
        
        return [
            'transient_count' => (int) $transient_count,
            'cache_size_estimate' => $transient_count * 1000 // Rough estimate in bytes
        ];
    }
}

// Initialize cache manager
function menu_popup_cache() {
    return Menu_Popup_Cache::get_instance();
}
