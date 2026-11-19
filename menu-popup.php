<?php
/**
 * Plugin Name: Menu Pop-up
 * Plugin URI: https://github.com/Ahmed123sa/Menu-PopUp-Plugin
 * Description: Interactive menu display with video popup functionality and category filtering
 * Version: 7.6.2
 * Author: Ahmed Ali
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: menu-popup
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants for easier access to paths and URLs
define('MENU_POPUP_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('MENU_POPUP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MENU_POPUP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MENU_POPUP_VERSION', '7.6.2');

/**
 * Main Plugin Class
 * This class bootstraps the plugin and initializes all components
 */
class Menu_Popup_Plugin
{

    private $version = MENU_POPUP_VERSION;
    private $utils;
    private $admin_dashboard;
    private $admin_menu_items;
    private $admin_orders;
    private $admin_branches; // New property for branch admin
    private $frontend;
    private $post_types; // New property for custom post types

    public function __construct()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/class-menu-popup-license.php';

        if (!Menu_Popup_License::is_active()) {
            // Not licensed for this domain: load nothing else, keep all data
            Menu_Popup_License::init(false);
            register_activation_hook(__FILE__, function () {
                update_option('menu_popup_pending_activation', '1');
            });
            return;
        }

        Menu_Popup_License::init(true);
        $this->includes();
        $this->init_hooks();
        $this->initialize_classes();
    }

    /**
     * Include required files
     */
    private function includes()
    {
        // Include abstract handler for common methods
        require_once plugin_dir_path(__FILE__) . 'includes/class-menu-popup-abstract-handler.php';
        // Include utility functions
        require_once plugin_dir_path(__FILE__) . 'includes/class-menu-popup-utils.php';
        // Include custom post types and taxonomies
        require_once plugin_dir_path(__FILE__) . 'includes/class-menu-popup-post-types.php';
        
        // Include Payment System
        require_once plugin_dir_path(__FILE__) . 'includes/payment/payment-gateway-interface.php';
        require_once plugin_dir_path(__FILE__) . 'includes/payment/class-payment-database.php';
        require_once plugin_dir_path(__FILE__) . 'includes/payment/class-payment-manager.php';
        require_once plugin_dir_path(__FILE__) . 'includes/payment/class-payment-ajax.php';
        require_once plugin_dir_path(__FILE__) . 'includes/payment/class-payment-methods.php';
        
        // Include admin classes
        require_once plugin_dir_path(__FILE__) . 'admin/class-menu-popup-admin-dashboard.php';
        require_once plugin_dir_path(__FILE__) . 'admin/class-menu-popup-admin-menu-items.php';
        require_once plugin_dir_path(__FILE__) . 'admin/class-menu-popup-admin-orders.php';
        require_once plugin_dir_path(__FILE__) . 'admin/class-menu-popup-orders-board.php';
        require_once plugin_dir_path(__FILE__) . 'admin/class-menu-popup-admin-branches.php'; // Include branch admin class
        require_once plugin_dir_path(__FILE__) . 'admin/class-payment-settings.php'; // Payment settings
        require_once plugin_dir_path(__FILE__) . 'admin/settings-page.php'; // Moved inclusion here
        // Include frontend class
        require_once plugin_dir_path(__FILE__) . 'frontend/class-menu-popup-frontend.php';

        // Include Reservation System
        if (file_exists(plugin_dir_path(__FILE__) . 'reservations/loader.php')) {
            require_once plugin_dir_path(__FILE__) . 'reservations/loader.php';
        }

        // Include Delivery Zone System
        require_once plugin_dir_path(__FILE__) . 'includes/delivery/class-delivery-zone-model.php';
        require_once plugin_dir_path(__FILE__) . 'includes/delivery/class-delivery-zone-checker.php';
        require_once plugin_dir_path(__FILE__) . 'includes/delivery/class-delivery-resolver.php';
        require_once plugin_dir_path(__FILE__) . 'includes/delivery/class-delivery-zone-ajax.php';
        require_once plugin_dir_path(__FILE__) . 'includes/delivery/class-delivery-zone-admin.php';

        // Include Order Service (server-side pricing and order creation)
        require_once plugin_dir_path(__FILE__) . 'includes/orders/class-order-service.php';
        require_once plugin_dir_path(__FILE__) . 'includes/orders/class-order-tracking.php';

        // Ordering from the table (QR codes)
        require_once plugin_dir_path(__FILE__) . 'includes/tables/class-table-ordering.php';
        require_once plugin_dir_path(__FILE__) . 'admin/class-menu-popup-tables-admin.php';
    }

    /**
     * Initialize core hooks
     */
    private function init_hooks()
    {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('init', array($this, 'init'));
        add_action('init', array($this, 'maybe_finish_activation'), 99);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Initialize component classes
     */
    private function initialize_classes()
    {
        // Initialize utils first (dependency for other classes)
        $this->utils = new Menu_Popup_Utils();

        // Initialize admin components with dependencies
        $this->admin_menu_items = new Menu_Popup_Admin_Menu_Items($this->version, $this->utils);
        $this->admin_orders = new Menu_Popup_Admin_Orders($this->version, $this->utils);
        $this->admin_branches = new Menu_Popup_Admin_Branches($this->version, $this->utils); // Initialize branch admin
        $this->settings = new Menu_Popup_Settings(); // Instantiate settings
        $this->admin_dashboard = new Menu_Popup_Admin_Dashboard($this->version, $this->admin_menu_items, $this->admin_orders, $this->settings);
        
        // Initialize Payment Settings
        $payment_settings = PaymentSettings::get_instance();
        $payment_settings->init();
        
        // Initialize Payment Manager (this loads all gateways)
        $payment_manager = PaymentManager::get_instance();
        
        // Initialize Payment AJAX (for frontend and admin AJAX handlers)
        $payment_ajax = PaymentAJAX::get_instance();
        $payment_ajax->init();
        
        // Create database tables on activation
        register_activation_hook(__FILE__, function() use ($payment_manager) {
            $payment_manager->create_tables();
        });

        // Initialize custom post types
        $this->post_types = new Menu_Popup_Post_Types();

        // Initialize frontend component
        $this->frontend = new Menu_Popup_Frontend($this->version, $this->utils);

        // Initialize Delivery Zone System
        $delivery_zone_ajax  = Menu_Popup_Delivery_Zone_Ajax::get_instance();
        $delivery_zone_ajax->init();

        $delivery_zone_admin = Menu_Popup_Admin_Delivery_Zones::get_instance();
        $delivery_zone_admin->init();

        // Initialize Order Service
        Menu_Popup_Order_Service::get_instance()->init();

        // Customer status page, transfer receipts, expiry of unpaid transfers
        Menu_Popup_Order_Tracking::get_instance()->init();

        // Order Dashboard
        Menu_Popup_Orders_Board::get_instance()->init();

        // Ordering from the table (does nothing until it is switched on in the settings)
        Menu_Popup_Table_Ordering::get_instance()->init();
        Menu_Popup_Tables_Admin::get_instance()->init();

        // Enqueue frontend zone checker script
        add_action('wp_enqueue_scripts', array($this, 'enqueue_delivery_zone_scripts'));
    }

    public function init()
    {
        // Load text domain for internationalization
        load_plugin_textdomain('menu-popup', false, dirname(plugin_basename(__FILE__)) . '/languages/');

        // Bring the delivery zones table up to date when the plugin files were replaced
        Menu_Popup_Delivery_Zone_Model::maybe_upgrade();
    }

    public function menu_popup_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'category' => 'all',
            'menu' => 'all',
            'limit' => -1,
            'show_search' => 'false'
        ), $atts);

        ob_start();
        // Delegate to frontend component
        if (method_exists($this->frontend, 'menu_popup_shortcode')) {
            $this->frontend->menu_popup_shortcode($atts);
        } else {
            echo '<p>Menu display functionality not available.</p>';
        }
        return ob_get_clean();
    }

    /**
     * Run the activation steps once if the plugin was activated while it had no license.
     */
    public function maybe_finish_activation()
    {
        if (get_option('menu_popup_pending_activation') !== '1') {
            return;
        }
        delete_option('menu_popup_pending_activation');
        $this->activate();
        PaymentManager::get_instance()->create_tables();
    }

    public function activate()
    {
        // Set default options
        add_option('menu_popup_enable_videos', '1');
        add_option('menu_popup_animation_speed', '300');
        add_option('menu_popup_show_price_card', '1');
        add_option('menu_popup_show_price_modal', '1');

        // Force migration of existing video URLs to embed format
        delete_option('menu_popup_video_migration_complete'); // Reset migration flag

        // Migrate existing video URLs (delegate to utils)
        if (method_exists($this->utils, 'migrate_video_urls')) {
            $this->utils->migrate_video_urls();
        }

        // Create / upgrade delivery zone table (adds geo columns)
        Menu_Popup_Delivery_Zone_Model::maybe_run_migration();

        // Create database tables if needed (for now using post meta)
        flush_rewrite_rules();

        // Add admin notice about migration
        if (!get_option('menu_popup_migration_notice_shown', false)) {
            add_option('menu_popup_migration_notice_shown', '1');
        }
    }

    public function deactivate()
    {
        // Clean up if needed
        wp_clear_scheduled_hook('mp_expire_unpaid_orders');
        flush_rewrite_rules();
    }

    /**
     * Enqueue admin scripts globally
     */
    public function enqueue_admin_scripts($hook)
    {
        // Enqueue admin script on all admin pages
        wp_enqueue_script('menu-popup-admin', MENU_POPUP_PLUGIN_URL . 'assets/js/menu-popup-admin.js', array('jquery', 'jquery-ui-sortable'), MENU_POPUP_VERSION, true);

        // Localize the script with nonce and AJAX URL
        wp_localize_script('menu-popup-admin', 'menuPopupAdminAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('menu_popup_admin_nonce')
        ));
    }

    /**
     * Enqueue delivery zone geo-checker on frontend pages.
     * Depends on 'menu-popup-frontend' so it loads AFTER menuPopupAjax is defined.
     * The checker reads geoCheckEnabled, geoZoneNonce, activeBranchId, and
     * deliveryZones directly from the menuPopupAjax object set by the frontend class.
     */
    public function enqueue_delivery_zone_scripts()
    {
        // Only load when delivery is actually enabled
        if (get_option('menu_popup_delivery_enabled', '0') !== '1') {
            return;
        }

        wp_enqueue_script(
            'mp-delivery-map',
            MENU_POPUP_PLUGIN_URL . 'assets/js/delivery-map.js',
            array('jquery', 'menu-popup-frontend'),   // runs after menuPopupAjax is localised
            MENU_POPUP_VERSION,
            true
        );
    }

}

// Initialize the plugin
new Menu_Popup_Plugin();
