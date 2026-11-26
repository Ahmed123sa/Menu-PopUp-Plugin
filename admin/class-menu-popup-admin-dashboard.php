<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Admin_Dashboard extends Menu_Popup_Abstract_Handler {

    private $version;
    private $admin_menu_items;
    private $admin_orders;
    private $settings;

    public function __construct($version, $admin_menu_items, $admin_orders, $settings) {
        $this->version = $version;
        $this->admin_menu_items = $admin_menu_items;
        $this->admin_orders = $admin_orders;
        $this->settings = $settings;
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_menu_dashboard_pages'));
        add_action('admin_init', array($this, 'register_realtime_settings'));
        add_action('admin_init', array($this, 'register_accounting_settings'));
    }

    public function add_menu_dashboard_pages() {
        add_menu_page(
            __('Menu Pop-up Dashboard', 'menu-popup'),
            __('Menu Pop-up', 'menu-popup'),
            'manage_options',
            'menu-popup-dashboard',
            array($this, 'dashboard_page_html'),
            'dashicons-food', // Changed icon for main menu
            30
        );

        // Add 'Dashboard' submenu - points to the same dashboard page HTML
        add_submenu_page(
            'menu-popup-dashboard',
            __('Dashboard', 'menu-popup'),
            __('Dashboard', 'menu-popup'),
            'manage_options',
            'menu-popup-dashboard',
            array($this, 'dashboard_page_html')
        );

        // Submenu for Menu Items (linked to the CPT list table)
        add_submenu_page(
            'menu-popup-dashboard',
            __('Menu Items', 'menu-popup'),
            __('All Items', 'menu-popup'),
            'manage_options',
            'edit.php?post_type=menu_item', // Link directly to CPT list table
            false
        );

        // Submenu for Add New Menu Item (linked to the CPT add new page)
        add_submenu_page(
            'menu-popup-dashboard',
            __('Add New Menu Item', 'menu-popup'),
            __('Add New Item', 'menu-popup'),
            'manage_options',
            'post-new.php?post_type=menu_item', // Link directly to CPT add new page
            false
        );
        // Submenu for Branches (linked to the CPT list table)
add_submenu_page(
    'menu-popup-dashboard',
    __('Branches', 'menu-popup'),
    __('Branches', 'menu-popup'),
    'manage_options',
    'edit.php?post_type=menu_popup_branch', // Link directly to CPT list table
    false
);

// Submenu for Cities (linked to the taxonomy page)
add_submenu_page(
    'menu-popup-dashboard',
    __('Cities/Areas', 'menu-popup'),
    __('Cities/Areas', 'menu-popup'),
    'manage_options',
    'edit-tags.php?taxonomy=menu_popup_branch_city&post_type=menu_popup_branch', // Link to taxonomy page
    false
);

        // Submenu for Categories (will render the categories page from settings-page.php)
        add_submenu_page(
            'menu-popup-dashboard',
            __('Categories', 'menu-popup'),
            __('Categories', 'menu-popup'),
            'manage_options',
            'menu-popup-categories',
            array($this->settings, 'categories_page_html') // Access via injected settings instance
        );

        // Submenu for Menus (will render the menus page from settings-page.php)
        add_submenu_page(
            'menu-popup-dashboard',
            __('Menus', 'menu-popup'),
            __('Menus', 'menu-popup'),
            'manage_options',
            'menu-popup-menus',
            array($this->settings, 'menus_page_html') // Access via injected settings instance
        );

        // Submenu for Orders (will render the orders page from class-menu-popup-admin-orders.php)
        add_submenu_page(
            'menu-popup-dashboard',
            __('Orders', 'menu-popup'),
            __('Orders', 'menu-popup'),
            'manage_options',
            'menu-popup-orders',
            array($this->admin_orders, 'order_dashboard_page') // Access via injected orders instance
        );

        // Submenu for Accounting (will render the accounting page from settings-page.php)
        add_submenu_page(
            'menu-popup-dashboard',
            __('Accounting & Reports', 'menu-popup'),
            __('Accounting', 'menu-popup'),
            'manage_options',
            'menu-popup-accounting',
            array($this->settings, 'accounting_page') // Access via injected settings instance
        );

        // Submenu for Import (from this dashboard class)
        add_submenu_page(
            'menu-popup-dashboard',
            __('Import Menu Items', 'menu-popup'),
            __('Import Items', 'menu-popup'),
            'manage_options',
            'menu-popup-migration',
            array($this, 'migration_page_html')
        );

        // Submenu for Settings (will render the settings page from settings-page.php)
        add_submenu_page(
            'menu-popup-dashboard',
            __('Settings', 'menu-popup'),
            __('Settings', 'menu-popup'),
            'manage_options',
            'menu-popup-settings',
            array($this->settings, 'settings_page_html') // Access via injected settings instance
        );
    }

    public function dashboard_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get statistics
        $total_items = $this->count_menu_items();
        $total_categories = $this->count_categories();
        $total_orders = $this->count_orders();
        $recent_items = $this->get_recent_menu_items();
        $has_items = $total_items > 0;
        $has_categories = $total_categories > 0;
        $has_orders = $total_orders > 0;

        ?>
        <div class="wrap">
            <h1><?php _e('Menu Pop-up Dashboard', 'menu-popup'); ?></h1>
            <p><?php _e('Welcome to the Menu Pop-up Plugin Dashboard. Use the menu items on the left to manage your menu items, orders, and settings.', 'menu-popup'); ?></p>

            <!-- Statistics Panel -->
            <div class="dashboard-stats">
                <div class="stat-card">
                    <div class="stat-icon">🍽️</div>
                    <div class="stat-number"><?php echo esc_html($total_items); ?></div>
                    <div class="stat-label"><?php _e('Menu Items', 'menu-popup'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🏷️</div>
                    <div class="stat-number"><?php echo esc_html($total_categories); ?></div>
                    <div class="stat-label"><?php _e('Categories', 'menu-popup'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🛒</div>
                    <div class="stat-number"><?php echo esc_html($total_orders); ?></div>
                    <div class="stat-label"><?php _e('Total Orders', 'menu-popup'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-number"><?php echo $has_items ? '📊' : '🎯'; ?></div>
                    <div class="stat-label"><?php echo $has_items ? __('Active Menu', 'menu-popup') : __('Get Started', 'menu-popup'); ?></div>
                </div>
            </div>

            <?php if (!$has_items): ?>
                <!-- First-time user welcome panel -->
                <div class="welcome-panel">
                    <div class="welcome-panel-content">
                        <h2><?php _e('Welcome! Let\'s Set Up Your Interactive Menu', 'menu-popup'); ?></h2>
                        <p class="about-description"><?php _e('Create engaging menu items with videos, prices, and multiple languages. Turn your static menu into an interactive experience!', 'menu-popup'); ?></p>
                        <div class="welcome-panel-column-container">
                            <div class="welcome-panel-column">
                                <h3><?php _e('Quick Start', 'menu-popup'); ?></h3>
                                <ul>
                                    <li><a href="<?php echo admin_url('post-new.php?post_type=menu_item'); ?>" class="welcome-icon dashicons-plus"><?php _e('Add your first menu item', 'menu-popup'); ?></a></li>
                                    <li><a href="<?php echo admin_url('admin.php?page=menu-popup-categories'); ?>" class="welcome-icon dashicons-admin-page"><?php _e('Create menu categories', 'menu-popup'); ?></a></li>
                                    <li><a href="<?php echo admin_url('admin.php?page=menu-popup-settings'); ?>" class="welcome-icon dashicons-admin-settings"><?php _e('Configure your settings', 'menu-popup'); ?></a></li>
                                </ul>
                            </div>
                            <div class="welcome-panel-column">
                                <h3><?php _e('Menu Features', 'menu-popup'); ?></h3>
                                <ul>
                                    <li class="welcome-icon dashicons-video-alt3"><?php _e('Video integration for each dish', 'menu-popup'); ?></li>
                                    <li class="welcome-icon dashicons-translation"><?php _e('Support for English & Arabic', 'menu-popup'); ?></li>
                                    <li class="welcome-icon dashicons-cart"><?php _e('E-commerce & delivery options', 'menu-popup'); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Existing user dashboard panels -->
                <div class="dashboard-grid">
                    <!-- Recent Menu Items -->
                    <div class="dashboard-panel recent-items">
                        <h3><?php _e('Recent Menu Items', 'menu-popup'); ?></h3>
                        <?php if (!empty($recent_items)): ?>
                            <div class="recent-items-list">
                                <?php foreach ($recent_items as $item): ?>
                                    <div class="recent-item">
                                        <div class="item-thumbnail">
                                            <?php
                                            $thumbnail = get_post_meta($item->ID, '_menu_item_image', true);
                                            if ($thumbnail):
                                            ?>
                                                <img src="<?php echo esc_url($thumbnail); ?>" alt="" width="40" height="40">
                                            <?php else: ?>
                                                <div class="no-thumbnail">🍽️</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="item-info">
                                            <h4><a href="<?php echo get_edit_post_link($item->ID); ?>"><?php echo esc_html(get_post_meta($item->ID, '_menu_item_name', true) ?: $item->post_title); ?></a></h4>
                                            <p><?php echo esc_html(get_post_meta($item->ID, '_menu_item_category', true)); ?></p>
                                        </div>
                                        <div class="item-status">
                                            <?php if (get_post_meta($item->ID, '_menu_item_video', true)): ?>
                                                <span class="has-video" title="Has video">📹</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="panel-footer">
                                <a href="<?php echo admin_url('edit.php?post_type=menu_item'); ?>" class="button"><?php _e('View All Items', 'menu-popup'); ?></a>
                                <a href="<?php echo admin_url('post-new.php?post_type=menu_item'); ?>" class="button button-primary"><?php _e('Add New Item', 'menu-popup'); ?></a>
                            </div>
                        <?php else: ?>
                            <p><?php _e('No menu items found.', 'menu-popup'); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Actions -->
                    <div class="dashboard-panel quick-actions">
                        <h3><?php _e('Quick Actions', 'menu-popup'); ?></h3>
                        <div class="action-buttons">
                            <a href="<?php echo admin_url('post-new.php?post_type=menu_item'); ?>" class="button button-primary">
                                <span class="dashicons dashicons-plus"></span>
                                <?php _e('Add Menu Item', 'menu-popup'); ?>
                            </a>
                            <a href="<?php echo admin_url('edit.php?post_type=menu_item'); ?>" class="button">
                                <span class="dashicons dashicons-menu"></span>
                                <?php _e('Manage Items', 'menu-popup'); ?>
                            </a>
                            <?php if (!$has_categories): ?>
                                <a href="<?php echo admin_url('admin.php?page=menu-popup-categories'); ?>" class="button">
                                    <span class="dashicons dashicons-category"></span>
                                    <?php _e('Create Categories', 'menu-popup'); ?>
                                </a>
                            <?php endif; ?>
                            <?php if ($has_orders): ?>
                                <a href="<?php echo admin_url('admin.php?page=menu-popup-orders'); ?>" class="button">
                                    <span class="dashicons dashicons-cart"></span>
                                    <?php _e('View Orders', 'menu-popup'); ?>
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo admin_url('admin.php?page=menu-popup-settings'); ?>" class="button">
                                <span class="dashicons dashicons-admin-settings"></span>
                                <?php _e('Settings', 'menu-popup'); ?>
                            </a>
                        </div>

                        <?php if (!$has_categories): ?>
                            <div class="notice notice-info inline">
                                <p><?php _e('You don\'t have any categories yet. Categories help organize your menu items and improve navigation.', 'menu-popup'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- System Status -->
                    <div class="dashboard-panel system-status">
                        <h3><?php _e('System Status', 'menu-popup'); ?></h3>
                        <div class="status-items">
                            <div class="status-item">
                                <span class="status-label"><?php _e('Video Support:', 'menu-popup'); ?></span>
                                <span class="status-value <?php echo get_option('menu_popup_enable_videos', '1') == '1' ? 'enabled' : 'disabled'; ?>">
                                    <?php echo get_option('menu_popup_enable_videos', '1') == '1' ? __('Enabled', 'menu-popup') : __('Disabled', 'menu-popup'); ?>
                                </span>
                            </div>
                            <div class="status-item">
                                <span class="status-label"><?php _e('Ordering:', 'menu-popup'); ?></span>
                                <span class="status-value <?php echo get_option('menu_popup_ordering_enabled', '0') == '1' ? 'enabled' : 'disabled'; ?>">
                                    <?php echo get_option('menu_popup_ordering_enabled', '0') == '1' ? __('Enabled', 'menu-popup') : __('Disabled', 'menu-popup'); ?>
                                </span>
                            </div>
                            <div class="status-item">
                                <span class="status-label"><?php _e('Shortcode:', 'menu-popup'); ?></span>
                                <span class="status-value shortcode">[menu-popup]</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <style>
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 2rem 0;
        }
        .stat-card {
            background: #fff;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
            border-left: 4px solid #2271b1;
        }
        .stat-card .stat-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .stat-card .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #2271b1;
            margin: 0.5rem 0;
        }
        .stat-card .stat-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }

        .dashboard-panel {
            background: #fff;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .dashboard-panel h3 {
            margin-top: 0;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }

        .recent-items-list {
            margin-bottom: 1rem;
        }

        .recent-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .recent-item:last-child {
            border-bottom: none;
        }

        .item-thumbnail img,
        .item-thumbnail .no-thumbnail {
            width: 40px;
            height: 40px;
            border-radius: 4px;
            object-fit: cover;
        }

        .item-thumbnail .no-thumbnail {
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f0f0f0;
            font-size: 1.2rem;
        }

        .item-info h4 {
            margin: 0;
            font-size: 1rem;
        }

        .item-info h4 a {
            text-decoration: none;
            color: #2271b1;
        }

        .item-info p {
            margin: 0.2rem 0 0;
            font-size: 0.85rem;
            color: #666;
        }

        .item-status {
            margin-left: auto;
        }

        .panel-footer {
            padding-top: 1rem;
            border-top: 1px solid #f0f0f0;
            display: flex;
            gap: 0.5rem;
        }

        .quick-actions .action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .quick-actions .button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            justify-content: flex-start;
            padding: 0.75rem;
        }

        .system-status .status-items {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .status-value.enabled {
            color: #46b450;
            font-weight: bold;
        }

        .status-value.disabled {
            color: #a00;
        }

        .status-value.shortcode {
            font-family: monospace;
            background: #f0f0f0;
            padding: 0.2rem 0.5rem;
            border-radius: 3px;
        }

        .notice.inline {
            margin: 1rem 0;
        }

        @media (max-width: 782px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions .action-buttons {
                grid-template-columns: 1fr;
            }
        }
        </style>
        <?php
    }

    /**
     * Migration page HTML
     */
    public function migration_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Call the migrate_video_urls from the Menu_Popup_Admin_Menu_Items instance
        if (isset($_POST['migrate_menu_items']) && check_admin_referer('menu_popup_migration')) {
            $this->migrate_post_meta_to_cpt(); // This handles general item migration
            $this->admin_menu_items->migrate_video_urls(); // This handles video URL format migration
            echo '<div class="notice notice-success"><p>' . __('Migration completed successfully!', 'menu-popup') . '</p></div>';
        }

        $post_meta_count = $this->count_post_meta_menu_items();
        $cpt_count = $this->count_cpt_menu_items();

        ?>
        <div class="wrap">
            <h1><?php _e('Import Menu Items from Posts', 'menu-popup'); ?></h1>

            <p><?php _e('This tool allows you to convert existing menu items that are stored as metadata on regular posts into standalone menu items.', 'menu-popup'); ?></p>

            <div class="menu-migration-stats">
                <div class="stat-box">
                    <h3><?php echo esc_html($post_meta_count); ?></h3>
                    <p><?php _e('Menu items in posts', 'menu-popup'); ?></p>
                </div>
                <div class="stat-box">
                    <h3><?php echo esc_html($cpt_count); ?></h3>
                    <p><?php _e('Standalone menu items', 'menu-popup'); ?></p>
                </div>
            </div>

            <?php if ($post_meta_count > 0): ?>
                <div class="migration-notice">
                    <p><?php _e('Found menu items that can be migrated. Click the button below to convert them into standalone menu items.', 'menu-popup'); ?></p>
                    <form method="post" action="">
                        <?php wp_nonce_field('menu_popup_migration'); ?>
                        <p class="submit">
                            <input type="submit" name="migrate_menu_items" class="button button-primary" value="<?php _e('Import Menu Items', 'menu-popup'); ?>" />
                        </p>
                    </form>
                </div>
            <?php else: ?>
                <div class="migration-complete">
                    <p><?php _e('All menu items have been migrated to standalone items or none were found.', 'menu-popup'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Count menu items in post meta
     */
    private function count_post_meta_menu_items() {
        $args = array(
            'post_type' => 'post',
            'post_status' => 'any',
            'meta_query' => array(
                array(
                    'key' => '_menu_item_name',
                    'compare' => 'EXISTS'
                )
            ),
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $posts = get_posts($args);
        return count($posts);
    }

    /**
     * Count standalone menu items
     */
    private function count_cpt_menu_items() {
        $args = array(
            'post_type' => 'menu_item',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $posts = get_posts($args);
        return count($posts);
    }

    /**
     * Migrate post meta menu items to CPT
     */
    private function migrate_post_meta_to_cpt() {
        $args = array(
            'post_type' => 'post',
            'post_status' => 'any',
            'meta_query' => array(
                array(
                    'key' => '_menu_item_name',
                    'compare' => 'EXISTS'
                )
            ),
            'posts_per_page' => -1
        );

        $posts = get_posts($args);

        foreach ($posts as $post) {
            $menu_item_name = get_post_meta($post->ID, '_menu_item_name', true);

            // Create new menu item post
            $cpt_id = wp_insert_post(array(
                'post_title' => $menu_item_name,
                'post_type' => 'menu_item',
                'post_status' => 'publish',
                'post_author' => $post->post_author
            ));

            if ($cpt_id) {
                // Copy all menu item meta data
                $meta_keys = array(
                    '_menu_item_name',
                    '_menu_item_name_arabic',
                    '_menu_item_description',
                    '_menu_item_description_arabic',
                    '_menu_item_price',
                    '_menu_item_category',
                    '_menu_item_image',
                    '_menu_item_video',
                    '_menu_item_video_arabic',
                    '_menu_item_add_to_cart_arabic',
                    '_menu_item_order_now_arabic',
                    '_menu_item_language',
                    '_menu_item_sizes',
                    '_menu_item_gallery'
                );

                foreach ($meta_keys as $key) {
                    $value = get_post_meta($post->ID, $key, true);
                    if (!empty($value)) {
                        update_post_meta($cpt_id, $key, $value);
                    }
                }
            }
        }
    }

    /**
     * Sanitize boolean values for settings
     */
    public function sanitize_boolean($value) {
        return (string) intval((bool) $value);
    }

    /**
     * Sanitize refresh interval values
     */
    public function sanitize_refresh_interval($value) {
        $value = intval($value);
        return max(10, min(300, $value)); // Between 10 and 300 seconds
    }

    /**
     * Register real-time settings for order dashboard
     */
    public function register_realtime_settings() {
        register_setting(
            'menu_popup_settings_group',
            'menu_popup_order_dashboard_realtime_enabled',
            array(
                'type' => 'boolean',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_order_dashboard_refresh_interval',
            array(
                'type' => 'integer',
                'default' => '60',
                'sanitize_callback' => array($this, 'sanitize_refresh_interval')
            )
        );
    }

    /**
     * Register accounting system settings
     */
    public function register_accounting_settings() {
        // Accounting settings - use WordPress options for period management
        register_setting(
            'menu_popup_accounting_group',
            'menu_popup_accounting_current_period',
            array(
                'type' => 'string',
                'default' => date('Y-m'),
                'sanitize_callback' => array($this, 'sanitize_accounting_period')
            )
        );

        register_setting(
            'menu_popup_accounting_group',
            'menu_popup_accounting_business_open',
            array(
                'type' => 'boolean',
                'default' => '1',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        // Journal entry reference numbers for period tracking
        register_setting(
            'menu_popup_accounting_group',
            'menu_popup_accounting_next_journal_number',
            array(
                'type' => 'integer',
                'default' => '1',
                'sanitize_callback' => array($this, 'sanitize_journal_number')
            )
        );
    }

    /**
     * Sanitize accounting period (format: YYYY-MM)
     */
    public function sanitize_accounting_period($value) {
        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            return $value;
        }
        return date('Y-m');
    }

    /**
     * Sanitize journal reference number
     */
    public function sanitize_journal_number($value) {
        $num = intval($value);
        return max(1, $num); // Ensure positive number starting from 1
    }

    /**
     * Count total menu items
     */
    private function count_menu_items() {
        $args = array(
            'post_type' => 'menu_item',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $posts = get_posts($args);
        return count($posts);
    }

    /**
     * Count total categories
     */
    private function count_categories() {
        $categories = get_option('menu_popup_categories', array());
        return count($categories);
    }

    /**
     * Count total orders
     */
    private function count_orders() {
        $args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $posts = get_posts($args);
        return count($posts);
    }

    /**
     * Get recent menu items
     */
    private function get_recent_menu_items() {
        $args = array(
            'post_type' => 'menu_item',
            'post_status' => 'publish',
            'posts_per_page' => 5,
            'orderby' => 'modified',
            'order' => 'DESC'
        );

        return get_posts($args);
    }
}
