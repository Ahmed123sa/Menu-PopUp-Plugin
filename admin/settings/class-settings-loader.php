<?php
/**
 * Settings Loader
 * 
 * Modular settings router - includes separate settings modules
 * 
 * @package MenuPopup
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings Loader Class
 * 
 * Routes to appropriate settings module based on current tab
 */
class Menu_Popup_Settings_Loader {
    
    /**
     * Settings modules
     */
    private $modules = [];
    
    /**
     * Current active tab
     */
    private $active_tab;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_modules();
        $this->init_hooks();
    }
    
    /**
     * Initialize settings modules
     */
    private function init_modules() {
        $this->modules = [
            'general' => [
                'title' => __('General', 'menu-popup'),
                'file' => 'class-settings-general.php',
                'callback' => 'render_general_settings'
            ],
            'payment' => [
                'title' => __('Payment', 'menu-popup'),
                'file' => 'class-settings-payment.php',
                'callback' => 'render_payment_settings'
            ],
            'voice' => [
                'title' => __('Voice & Search', 'menu-popup'),
                'file' => 'class-settings-voice.php',
                'callback' => 'render_voice_settings'
            ],
            'branches' => [
                'title' => __('Branches', 'menu-popup'),
                'file' => 'class-settings-branches.php',
                'callback' => 'render_branches_settings'
            ],
            'accounting' => [
                'title' => __('Accounting', 'menu-popup'),
                'file' => 'class-settings-accounting.php',
                'callback' => 'render_accounting_settings'
            ],
            'appearance' => [
                'title' => __('Appearance', 'menu-popup'),
                'file' => 'class-settings-appearance.php',
                'callback' => 'render_appearance_settings'
            ]
        ];
        
        // Load module files
        foreach ($this->modules as $key => $module) {
            $file_path = plugin_dir_path(__FILE__) . 'settings/' . $module['file'];
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'menu-popup',
            __('Settings', 'menu-popup'),
            __('Settings', 'menu-popup'),
            'manage_options',
            'menu-popup-settings',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Register general settings
        register_setting('menu_popup_general', 'menu_popup_enable_videos');
        register_setting('menu_popup_general', 'menu_popup_animation_speed');
        register_setting('menu_popup_general', 'menu_popup_show_price_card');
        register_setting('menu_popup_general', 'menu_popup_show_price_modal');
        
        // Register payment settings
        register_setting('menu_popup_payment', 'menu_popup_payment_enabled');
        register_setting('menu_popup_payment', 'menu_popup_payment_currency');
        
        // Register voice settings
        register_setting('menu_popup_voice', 'menu_popup_voice_search_enabled');
        register_setting('menu_popup_voice', 'menu_popup_pronounce_on_hover_enabled');
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'menu-popup-settings') === false) {
            return;
        }
        
        wp_enqueue_style('menu-popup-admin-settings', MENU_POPUP_PLUGIN_URL . 'assets/css/menu-popup-admin.css', [], '1.0.0');
        wp_enqueue_script('menu-popup-admin-settings', MENU_POPUP_PLUGIN_URL . 'assets/js/menu-popup-admin.js', ['jquery'], '1.0.0', true);
        
        wp_localize_script('menu-popup-admin-settings', 'menuPopupSettings', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('menu_popup_settings_nonce'),
            'tabs' => array_keys($this->modules)
        ]);
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        $this->active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        
        // Include the main settings page template
        include_once plugin_dir_path(__FILE__) . 'settings-page.php';
    }
    
    /**
     * Get active tab
     */
    public function get_active_tab() {
        return $this->active_tab;
    }
    
    /**
     * Get all modules
     */
    public function get_modules() {
        return $this->modules;
    }
    
    /**
     * Get module by key
     */
    public function get_module($key) {
        return isset($this->modules[$key]) ? $this->modules[$key] : null;
    }
}

// Initialize settings loader
new Menu_Popup_Settings_Loader();
