<?php
/**
 * Reservation System Loader
 * 
 * This file initializes the reservation system module.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define module constants
define('MENU_POPUP_RESERVATIONS_PATH', plugin_dir_path(__FILE__));
define('MENU_POPUP_RESERVATIONS_URL', plugin_dir_url(__FILE__));

/**
 * Main Reservation System Class
 */
class Menu_Popup_Reservations
{

    private static $instance = null;

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->includes();
        $this->init_hooks();
    }

    private function includes()
    {
        require_once MENU_POPUP_RESERVATIONS_PATH . 'includes/class-post-types.php';
        require_once MENU_POPUP_RESERVATIONS_PATH . 'includes/class-availability-engine.php';
        require_once MENU_POPUP_RESERVATIONS_PATH . 'admin/class-admin-dashboard.php';
        require_once MENU_POPUP_RESERVATIONS_PATH . 'admin/class-table-meta.php';
        require_once MENU_POPUP_RESERVATIONS_PATH . 'admin/class-rules-meta.php';
        require_once MENU_POPUP_RESERVATIONS_PATH . 'frontend/class-frontend-form.php';
        require_once MENU_POPUP_RESERVATIONS_PATH . 'includes/class-api-endpoints.php';
    }

    private function init_hooks()
    {
        // Initialize components
        add_action('plugins_loaded', array($this, 'init_components'));
    }

    public function init_components()
    {
        new Menu_Popup_Reservations_Post_Types();
        new Menu_Popup_Reservations_Admin_Dashboard();
        new Menu_Popup_Table_Meta();
        new Menu_Popup_Rules_Meta();
        new Menu_Popup_Reservations_Frontend_Form();
        new Menu_Popup_Reservations_API();
    }
}
Menu_Popup_Reservations::instance();
