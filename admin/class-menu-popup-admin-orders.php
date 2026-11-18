<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Admin_Orders extends Menu_Popup_Abstract_Handler {

    private $version;
    private $utils;

    public function __construct($version, $utils) {
        $this->version = $version;
        $this->utils = $utils; // Inject Menu_Popup_Utils
        $this->init_hooks();
    }

    private function init_hooks() {
        // Order management system
        add_action('init', array($this, 'register_order_post_type'));
        add_action('wp_ajax_get_orders_active', array($this, 'get_orders_active'));
        add_action('wp_ajax_get_orders', array($this, 'ajax_get_orders'));
        add_action('wp_ajax_update_order_status', array($this, 'ajax_update_order_status'));
        add_action('wp_ajax_delete_order', array($this, 'ajax_delete_order'));
        add_action('wp_ajax_get_order_details', array($this, 'ajax_get_order_details'));
        add_action('wp_ajax_export_orders_data', array($this, 'ajax_export_orders_data'));

            // Remove default orders admin menu (runs at priority 999 to ensure it runs after WordPress adds the menu)
            add_action('admin_menu', array($this, 'remove_orders_admin_menu'), 999);

        // Order dashboard real-time updates
        add_action('wp_ajax_get_orders_realtime', array($this, 'ajax_get_orders_realtime'));

        // Accounting system hooks
        add_action('wp_ajax_open_business_day', array($this, 'ajax_open_business_day'));
        add_action('wp_ajax_close_business_day', array($this, 'ajax_close_business_day'));
        add_action('wp_ajax_get_accounting_reports', array($this, 'ajax_get_accounting_reports'));
    }

    /**
     * Register order post type for order management
     * Orders are managed through our custom Order Dashboard only
     */
    public function register_order_post_type() {
        $labels = array(
            'name' => __('Orders', 'menu-popup'),
            'singular_name' => __('Order', 'menu-popup'),
            'add_new' => __('Add New Order', 'menu-popup'),
            'add_new_item' => __('Add New Order', 'menu-popup'),
            'edit_item' => __('Edit Order', 'menu-popup'),
            'new_item' => __('New Order', 'menu-popup'),
            'view_item' => __('View Order', 'menu-popup'),
            'search_items' => __('Search Orders', 'menu-popup'),
            'not_found' => __('No orders found', 'menu-popup'),
            'not_found_in_trash' => __('No orders found in trash', 'menu-popup'),
            'all_items' => __('All Orders', 'menu-popup'),
            'menu_name' => __('Orders', 'menu-popup')
        );

        $args = array(
            'labels' => $labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => false, // Don't show as admin menu item
            'query_var' => false,
            'rewrite' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'supports' => array('title', 'custom-fields', 'page-attributes'),
            'show_in_rest' => false
        );

        register_post_type('menu_order', $args);
    }

    /**
     * Add order dashboard menu
     */
    public function add_order_dashboard_menu() {
        add_submenu_page(
            'menu-popup-dashboard',
            __('Order Dashboard', 'menu-popup'),
            __('Order Dashboard', 'menu-popup'),
            'manage_options',
            'menu-popup-orders',
            array($this, 'order_dashboard_page')
        );
    }

    /**
     * Calculate total revenue from delivered orders
     */
    private function calculate_total_revenue() {
        $args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_order_status',
                    'value' => 'delivered'
                )
            ),
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $orders = get_posts($args);
        $total_revenue = 0.0;

        foreach ($orders as $order_id) {
            $order_total = floatval(get_post_meta($order_id, '_order_total', true));
            $total_revenue += $order_total;
        }

        return $total_revenue;
    }

    /**
     * Order dashboard page
     */
    public function order_dashboard_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        // The page itself lives in Menu_Popup_Orders_Board (board, payments, history)
        Menu_Popup_Orders_Board::get_instance()->render_page();
    }

    /**
     * AJAX handler to get active and completed orders separately
     */
    public function get_orders_active() {
        check_ajax_referer('get_orders_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'menu-popup'));
        }

        $page = intval($_POST['page']) ?: 1;
        $filters = $_POST['filters'] ?: array();
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        // Get active orders (pending, confirmed, preparing, ready)
        $active_statuses = array('pending', 'confirmed', 'preparing', 'ready');
        $active_args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'meta_query' => array(
                array(
                    'key' => '_order_status',
                    'value' => $active_statuses,
                    'compare' => 'IN'
                )
            )
        );

        // Get completed orders (delivered, cancelled)
        $completed_statuses = array('delivered', 'cancelled');
        $completed_args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'meta_query' => array(
                array(
                    'key' => '_order_status',
                    'value' => $completed_statuses,
                    'compare' => 'IN'
                )
            )
        );

        // Apply filters to both queries if specified
        if (!empty($filters['search'])) {
            $active_args['s'] = sanitize_text_field($filters['search']);
            $completed_args['s'] = sanitize_text_field($filters['search']);
        }

        // Payment method filtering
        if (!empty($filters['payment_method']) && $filters['payment_method'] !== 'all') {
            $active_args['meta_query'][] = array(
                'key' => '_payment_method',
                'value' => $filters['payment_method']
            );
            $completed_args['meta_query'][] = array(
                'key' => '_payment_method',
                'value' => $filters['payment_method']
            );
        }

        // Date filtering
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $date_query = array();

            if (!empty($filters['date_from'])) {
                $date_query['after'] = sanitize_text_field($filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $date_query['before'] = sanitize_text_field($filters['date_to']);
            }

            $date_query['inclusive'] = true;
            $active_args['date_query'] = array($date_query);
            $completed_args['date_query'] = array($date_query);
        }

        // Execute queries
        $active_query = new WP_Query($active_args);
        $completed_query = new WP_Query($completed_args);

        $active_orders = array();
        $completed_orders = array();

        // Process active orders
        if ($active_query->have_posts()) {
            while ($active_query->have_posts()) {
                $active_query->the_post();
                $order_id = get_the_ID();

                $payment_method = get_post_meta($order_id, '_payment_method', true);
                $payment_status = get_post_meta($order_id, '_payment_status', true) ?: 'pending';
                
                // Format payment method display
                $payment_method_display = $this->format_payment_method_display($payment_method);
                
                // Create payment status badge HTML
                $payment_status_badge = $this->create_payment_status_badge($payment_status);

                $active_orders[] = array(
                    'id' => $order_id,
                    'order_number' => get_post_meta($order_id, '_order_number', true) ?: $order_id,
                    'customer_name' => get_post_meta($order_id, '_customer_name', true),
                    'customer_phone' => get_post_meta($order_id, '_customer_phone', true),
                    'customer_email' => get_post_meta($order_id, '_customer_email', true),
                    'customer_address' => get_post_meta($order_id, '_customer_address', true),
                    'items' => json_decode(get_post_meta($order_id, '_order_items', true), true) ?: array(),
                    'subtotal' => get_post_meta($order_id, '_order_subtotal', true),
                    'tax' => get_post_meta($order_id, '_order_tax', true),
                    'total' => get_post_meta($order_id, '_order_total', true),
                    'status' => get_post_meta($order_id, '_order_status', true) ?: 'pending',
                    'payment_method' => $payment_method,
                    'payment_method_display' => $payment_method_display,
                    'payment_status' => $payment_status,
                    'payment_status_badge' => $payment_status_badge,
                    'date' => get_the_date('Y-m-d H:i'),
                    'special_instructions' => get_post_meta($order_id, '_special_instructions', true),
                    'order_type' => get_post_meta($order_id, '_order_type', true),
                    'delivery_fee' => get_post_meta($order_id, '_delivery_fee', true),
                    'delivery_zone' => get_post_meta($order_id, '_delivery_zone_name', true),
                    'delivery_details' => get_post_meta($order_id, '_delivery_address_details', true),
                    'order_source' => get_post_meta($order_id, '_order_source', true),
                    'needs_review' => get_post_meta($order_id, '_needs_review', true),
                    'delivery_lat' => get_post_meta($order_id, '_delivery_lat', true),
                    'delivery_lng' => get_post_meta($order_id, '_delivery_lng', true),
                    'delivery_distance' => get_post_meta($order_id, '_delivery_distance_km', true),
                    'delivery_eta' => get_post_meta($order_id, '_delivery_eta_minutes', true),
                    'branch_name' => get_post_meta($order_id, '_branch_id', true) ? get_the_title((int) get_post_meta($order_id, '_branch_id', true)) : '',
                    'currency_symbol' => get_option('menu_popup_currency_symbol', '$')
                );
            }
        }

        // Process completed orders
        if ($completed_query->have_posts()) {
            while ($completed_query->have_posts()) {
                $completed_query->the_post();
                $order_id = get_the_ID();

                $completed_orders[] = array(
                    'id' => $order_id,
                    'order_number' => get_post_meta($order_id, '_order_number', true) ?: $order_id,
                    'customer_name' => get_post_meta($order_id, '_customer_name', true),
                    'customer_phone' => get_post_meta($order_id, '_customer_phone', true),
                    'customer_email' => get_post_meta($order_id, '_customer_email', true),
                    'customer_address' => get_post_meta($order_id, '_customer_address', true),
                    'items' => json_decode(get_post_meta($order_id, '_order_items', true), true) ?: array(),
                    'subtotal' => get_post_meta($order_id, '_order_subtotal', true),
                    'tax' => get_post_meta($order_id, '_order_tax', true),
                    'total' => get_post_meta($order_id, '_order_total', true),
                    'status' => get_post_meta($order_id, '_order_status', true) ?: 'delivered',
                    'date' => get_the_date('Y-m-d H:i'),
                    'special_instructions' => get_post_meta($order_id, '_special_instructions', true),
                    'order_type' => get_post_meta($order_id, '_order_type', true),
                    'delivery_fee' => get_post_meta($order_id, '_delivery_fee', true),
                    'delivery_zone' => get_post_meta($order_id, '_delivery_zone_name', true),
                    'delivery_details' => get_post_meta($order_id, '_delivery_address_details', true),
                    'order_source' => get_post_meta($order_id, '_order_source', true),
                    'needs_review' => get_post_meta($order_id, '_needs_review', true),
                    'delivery_lat' => get_post_meta($order_id, '_delivery_lat', true),
                    'delivery_lng' => get_post_meta($order_id, '_delivery_lng', true),
                    'delivery_distance' => get_post_meta($order_id, '_delivery_distance_km', true),
                    'delivery_eta' => get_post_meta($order_id, '_delivery_eta_minutes', true),
                    'branch_name' => get_post_meta($order_id, '_branch_id', true) ? get_the_title((int) get_post_meta($order_id, '_branch_id', true)) : '',
                    'currency_symbol' => get_option('menu_popup_currency_symbol', '$')
                );
            }
        }

        wp_reset_postdata();

        wp_send_json_success(array(
            'active_orders' => $active_orders,
            'completed_orders' => $completed_orders,
            'active_total' => $active_query->found_posts,
            'completed_total' => $completed_query->found_posts
        ));
    }

    /**
     * AJAX handler to get orders
     */
    public function ajax_get_orders() {
        check_ajax_referer('get_orders_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'menu-popup'));
        }

        $page = intval($_POST['page']) ?: 1;
        $filters = $_POST['filters'] ?: array();
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        // Build query args
        $args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'meta_query' => array()
        );

        // Apply filters
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $args['meta_query'][] = array(
                'key' => '_order_status',
                'value' => $filters['status']
            );
        }

        if (!empty($filters['search'])) {
            $args['s'] = sanitize_text_field($filters['search']);
        }

        // Date filtering
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $date_query = array();

            if (!empty($filters['date_from'])) {
                $date_query['after'] = sanitize_text_field($filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $date_query['before'] = sanitize_text_field($filters['date_to']);
            }

            $date_query['inclusive'] = true;
            $args['date_query'] = array($date_query);
        }

        $orders_query = new WP_Query($args);
        $orders = array();

        if ($orders_query->have_posts()) {
            while ($orders_query->have_posts()) {
                $orders_query->the_post();
                $order_id = get_the_ID();

                // Get order meta data
                $order_data = array(
                    'id' => $order_id,
                    'order_number' => get_post_meta($order_id, '_order_number', true) ?: $order_id,
                    'customer_name' => get_post_meta($order_id, '_customer_name', true),
                    'customer_phone' => get_post_meta($order_id, '_customer_phone', true),
                    'customer_email' => get_post_meta($order_id, '_customer_email', true),
                    'customer_address' => get_post_meta($order_id, '_customer_address', true),
                    'items' => json_decode(get_post_meta($order_id, '_order_items', true), true) ?: array(),
                    'subtotal' => get_post_meta($order_id, '_order_subtotal', true),
                    'tax' => get_post_meta($order_id, '_order_tax', true),
                    'total' => get_post_meta($order_id, '_order_total', true),
                    'status' => get_post_meta($order_id, '_order_status', true) ?: 'pending',
                    'date' => get_the_date('Y-m-d H:i'),
                    'special_instructions' => get_post_meta($order_id, '_special_instructions', true),
                    'order_type' => get_post_meta($order_id, '_order_type', true),
                    'delivery_fee' => get_post_meta($order_id, '_delivery_fee', true),
                    'delivery_zone' => get_post_meta($order_id, '_delivery_zone_name', true),
                    'delivery_details' => get_post_meta($order_id, '_delivery_address_details', true),
                    'order_source' => get_post_meta($order_id, '_order_source', true),
                    'needs_review' => get_post_meta($order_id, '_needs_review', true),
                    'delivery_lat' => get_post_meta($order_id, '_delivery_lat', true),
                    'delivery_lng' => get_post_meta($order_id, '_delivery_lng', true),
                    'delivery_distance' => get_post_meta($order_id, '_delivery_distance_km', true),
                    'delivery_eta' => get_post_meta($order_id, '_delivery_eta_minutes', true),
                    'branch_name' => get_post_meta($order_id, '_branch_id', true) ? get_the_title((int) get_post_meta($order_id, '_branch_id', true)) : '',
                    'currency_symbol' => get_option('menu_popup_currency_symbol', '$')
                );

                $orders[] = $order_data;
            }
        }

        wp_reset_postdata();

        wp_send_json_success(array(
            'orders' => $orders,
            'total' => $orders_query->found_posts,
            'pages' => ceil($orders_query->found_posts / $per_page)
        ));
    }

    /**
     * AJAX handler to update order status
     */
    public function ajax_update_order_status() {
        check_ajax_referer('update_order_status_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'menu-popup'));
        }

        $order_id = intval($_POST['order_id']);
        $status = sanitize_text_field($_POST['status']);

        if ($order_id && $status) {
            update_post_meta($order_id, '_order_status', $status);
            wp_send_json_success(__('Order status updated', 'menu-popup'));
        } else {
            wp_send_json_error(__('Invalid order or status', 'menu-popup'));
        }
    }

    /**
     * AJAX handler to delete order
     */
    public function ajax_delete_order() {
        check_ajax_referer('delete_order_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'menu-popup'));
        }

        $order_id = intval($_POST['order_id']);

        if ($order_id) {
            wp_delete_post($order_id, true);
            wp_send_json_success(__('Order deleted', 'menu-popup'));
        } else {
            wp_send_json_error(__('Invalid order ID', 'menu-popup'));
        }
    }

    /**
     * Count orders by status
     */
    private function count_orders_by_status($status) {
        $args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_order_status',
                    'value' => $status
                )
            ),
            'fields' => 'ids'
        );

        $query = new WP_Query($args);
        return $query->found_posts;
    }

    /**
     * AJAX handler to get order details
     */
    public function ajax_get_order_details() {
        check_ajax_referer('get_order_details_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'menu-popup'));
        }

        $order_id = intval($_POST['order_id']);

        if (!$order_id) {
            wp_send_json_error(__('Invalid order ID', 'menu-popup'));
        }

        // Get order data
        $order_data = array(
            'id' => $order_id,
            'order_number' => get_post_meta($order_id, '_order_number', true) ?: $order_id,
            'customer_name' => get_post_meta($order_id, '_customer_name', true),
            'customer_phone' => get_post_meta($order_id, '_customer_phone', true),
            'customer_email' => get_post_meta($order_id, '_customer_email', true),
            'customer_address' => get_post_meta($order_id, '_customer_address', true),
            'items' => json_decode(get_post_meta($order_id, '_order_items', true), true) ?: array(),
            'subtotal' => get_post_meta($order_id, '_order_subtotal', true),
            'tax' => get_post_meta($order_id, '_order_tax', true),
            'total' => get_post_meta($order_id, '_order_total', true),
            'status' => get_post_meta($order_id, '_order_status', true) ?: 'pending',
            'payment_method' => get_post_meta($order_id, '_payment_method', true),
            'payment_status' => get_post_meta($order_id, '_payment_status', true) ?: 'pending',
            'date' => get_the_date('Y-m-d H:i', $order_id),
            'special_instructions' => get_post_meta($order_id, '_special_instructions', true),
            'order_type' => get_post_meta($order_id, '_order_type', true),
            'delivery_fee' => get_post_meta($order_id, '_delivery_fee', true),
            'delivery_zone' => get_post_meta($order_id, '_delivery_zone_name', true),
            'delivery_details' => get_post_meta($order_id, '_delivery_address_details', true),
            'order_source' => get_post_meta($order_id, '_order_source', true),
            'needs_review' => get_post_meta($order_id, '_needs_review', true),
            'delivery_lat' => get_post_meta($order_id, '_delivery_lat', true),
            'delivery_lng' => get_post_meta($order_id, '_delivery_lng', true),
            'delivery_distance' => get_post_meta($order_id, '_delivery_distance_km', true),
            'delivery_eta' => get_post_meta($order_id, '_delivery_eta_minutes', true),
            'branch_name' => get_post_meta($order_id, '_branch_id', true) ? get_the_title((int) get_post_meta($order_id, '_branch_id', true)) : '',
            'currency_symbol' => get_post_meta($order_id, '_currency_symbol', true) ?: '$'
        );

        wp_send_json_success($order_data);
    }

    /**
     * AJAX handler to export orders data
     */
    public function ajax_export_orders_data() {
        check_ajax_referer('get_orders_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'menu-popup'));
        }

        $filters = $_POST['filters'] ?: array();

        // Build query args for export (get all matching orders)
        $args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => array()
        );

        // Apply filters
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $args['meta_query'][] = array(
                'key' => '_order_status',
                'value' => $filters['status']
            );
        }

        if (!empty($filters['search'])) {
            $args['s'] = sanitize_text_field($filters['search']);
        }

        // Date filtering
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $date_query = array();

            if (!empty($filters['date_from'])) {
                $date_query['after'] = sanitize_text_field($filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $date_query['before'] = sanitize_text_field($filters['date_to']);
            }

            $date_query['inclusive'] = true;
            $args['date_query'] = array($date_query);
        }

        $orders_query = new WP_Query($args);

        // Generate CSV data
        $csv_data = $this->generate_orders_csv($orders_query);

        wp_reset_postdata();

        wp_send_json_success(array(
            'csv_data' => $csv_data
        ));
    }

    /**
     * AJAX handler for day open/close
     */
    public function ajax_open_business_day() {
        check_ajax_referer('menu_popup_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'menu-popup'));
        }

        // Set current period to today
        $current_period = date('Y-m-d');
        update_option('menu_popup_accounting_business_open', '1');
        update_option('menu_popup_accounting_day_open_time', current_time('timestamp'));

        // Log day opening
        $this->log_accounting_entry('DAY_OPENED', array(
            'timestamp' => current_time('timestamp'),
            'user_id' => get_current_user_id(),
            'period' => $current_period
        ));

        wp_send_json_success(array('message' => __('Business day opened successfully', 'menu-popup')));
    }

    /**
     * AJAX handler for closing business day
     */
    public function ajax_close_business_day() {
        check_ajax_referer('menu_popup_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'menu-popup'));
        }

        // Close the business day
        update_option('menu_popup_accounting_business_open', '0');

        // Calculate end-of-day summary
        $day_summary = $this->calculate_daily_summary();

        // Log day closing
        $this->log_accounting_entry('DAY_CLOSED', array_merge($day_summary, array(
            'timestamp' => current_time('timestamp'),
            'user_id' => get_current_user_id(),
            'period' => date('Y-m-d')
        )));

        wp_send_json_success(array(
            'message' => __('Business day closed successfully', 'menu-popup'),
            'summary' => $day_summary
        ));
    }

    /**
     * AJAX handler for getting accounting reports
     */
    public function ajax_get_accounting_reports() {
        check_ajax_referer('menu_popup_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'menu-popup'));
        }

        $report_type = sanitize_text_field($_POST['report_type'] ?? 'daily');
        $start_date = sanitize_text_field($_POST['start_date'] ?? date('Y-m-d', strtotime('-7 days')));
        $end_date = sanitize_text_field($_POST['end_date'] ?? date('Y-m-d'));

        $reports = array();

        switch ($report_type) {
            case 'daily':
                $reports = $this->get_daily_reports($start_date, $end_date);
                break;
            case 'journal':
                $reports = $this->get_journal_entries($start_date, $end_date);
                break;
            case 'summary':
                $reports = $this->get_period_summary($start_date, $end_date);
                break;
        }

        wp_send_json_success($reports);
    }

    /**
     * Get daily reports for date range
     */
    private function get_daily_reports($start_date, $end_date) {
        $reports = array();

        $current_date = strtotime($start_date);
        $end_timestamp = strtotime($end_date);

        while ($current_date <= $end_timestamp) {
            $date = date('Y-m-d', $current_date);
            $day_entries = $this->get_entries_for_date($date);

            $reports[$date] = array(
                'orders_count' => count($day_entries['orders']),
                'revenue' => $this->calculate_date_revenue($date),
                'opening_time' => $this->get_date_open_time($date),
                'closing_time' => $this->get_date_close_time($date),
                'is_open' => $this->is_business_day_open($date)
            );

            $current_date = strtotime('+1 day', $current_date);
        }

        return $reports;
    }

    /**
     * Get journal entries for date range
     */
    private function get_journal_entries($start_date, $end_date) {
        // Get orders in date range
        $args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'date_query' => array(
                array(
                    'after' => $start_date . ' 00:00:00',
                    'before' => $end_date . ' 23:59:59',
                    'inclusive' => true
                )
            ),
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC'
        );

        $query = new WP_Query($args);
        $journal_entries = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $order_id = get_the_ID();

                $journal_entries[] = array(
                    'type' => 'ORDER',
                    'reference' => get_post_meta($order_id, '_order_number', true),
                    'amount' => floatval(get_post_meta($order_id, '_order_total', true)),
                    'description' => 'Order #' . get_post_meta($order_id, '_order_number', true),
                    'timestamp' => get_post_time('U')
                );
            }
        }

        wp_reset_postdata();

        return $journal_entries;
    }

    /**
     * Get period summary report
     */
    private function get_period_summary($start_date, $end_date) {
        $start_timestamp = strtotime($start_date . ' 00:00:00');
        $end_timestamp = strtotime($end_date . ' 23:59:59');

        $args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'date_query' => array(
                array(
                    'after' => $start_date . ' 00:00:00',
                    'before' => $end_date . ' 23:59:59',
                    'inclusive' => true
                )
            ),
            'posts_per_page' => -1
        );

        $query = new WP_Query($args);

        $summary = array(
            'total_orders' => $query->found_posts,
            'total_revenue' => 0,
            'delivered_orders' => 0,
            'pending_orders' => 0,
            'average_order_value' => 0
        );

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $order_id = get_the_ID();

                $total = floatval(get_post_meta($order_id, '_order_total', true));
                $status = get_post_meta($order_id, '_order_status', true);

                $summary['total_revenue'] += $total;

                if ($status === 'delivered') {
                    $summary['delivered_orders']++;
                } else {
                    $summary['pending_orders']++;
                }
            }

            if ($summary['total_orders'] > 0) {
                $summary['average_order_value'] = $summary['total_revenue'] / $summary['total_orders'];
            }
        }

        wp_reset_postdata();

        return $summary;
    }

    /**
     * Log accounting entry with journal entry in order post meta
     */
    private function log_accounting_entry($type, $data) {
        // Store entry in WordPress options for period tracking
        $entries = get_option('menu_popup_accounting_entries', array());

        if (!is_array($entries)) {
            $entries = array();
        }

        $entries[] = array(
            'type' => $type,
            'timestamp' => $data['timestamp'],
            'user_id' => get_current_user_id(),
            'data' => $data
        );

        update_option('menu_popup_accounting_entries', $entries);

        // For orders, we already store them in separate post type
        // This keeps the accounting system WordPress-native
    }

    /**
     * Calculate daily summary for business day
     */
    private function calculate_daily_summary() {
        $today = date('Y-m-d');
        $open_time = $this->get_date_open_time($today);

        if (!$open_time) {
            return array('error' => 'Business day was not opened properly');
        }

        // Get orders placed today
        $args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'date_query' => array(
                array(
                    'after' => $today . ' 00:00:00',
                    'before' => $today . ' 23:59:59',
                    'inclusive' => true
                )
            ),
            'posts_per_page' => -1
        );

        $query = new WP_Query($args);

        $summary = array(
            'orders_count' => 0,
            'total_revenue' => 0.0,
            'delivered_orders' => 0,
            'pending_orders' => 0,
            'open_time' => $open_time,
            'close_time' => current_time('timestamp'),
            'period' => $today
        );

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $order_id = get_the_ID();

                $summary['orders_count']++;
                $total = floatval(get_post_meta($order_id, '_order_total', true));
                $summary['total_revenue'] += $total;

                $status = get_post_meta($order_id, '_order_status', true);
                if ($status === 'delivered') {
                    $summary['delivered_orders']++;
                } else {
                    $summary['pending_orders']++;
                }
            }
        }

        wp_reset_postdata();

        return $summary;
    }

    /**
     * Get entries for specific date
     */
    private function get_entries_for_date($date) {
        // Orders for this date
        $args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'date_query' => array(
                array(
                    'after' => $date . ' 00:00:00',
                    'before' => $date . ' 23:59:59',
                    'inclusive' => true
                )
            ),
            'posts_per_page' => -1
        );

        $query = new WP_Query($args);
        $orders = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $orders[] = array(
                    'id' => get_the_ID(),
                    'order_number' => get_post_meta(get_the_ID(), '_order_number', true),
                    'total' => get_post_meta(get_the_ID(), '_order_total', true),
                    'status' => get_post_meta(get_the_ID(), '_order_status', true)
                );
            }
        }

        wp_reset_postdata();

        return array('orders' => $orders);
    }

    /**
     * Calculate revenue for specific date
     */
    private function calculate_date_revenue($date) {
        $args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_order_status',
                    'value' => 'delivered'
                )
            ),
            'date_query' => array(
                array(
                    'after' => $date . ' 00:00:00',
                    'before' => $date . ' 23:59:59',
                    'inclusive' => true
                )
            ),
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $query = new WP_Query($args);
        $revenue = 0.0;

        foreach ($query->posts as $order_id) {
            $revenue += floatval(get_post_meta($order_id, '_order_total', true));
        }

        return $revenue;
    }

    /**
     * Get open time for specific date
     */
    private function get_date_open_time($date) {
        $entries = get_option('menu_popup_accounting_entries', array());

        foreach ($entries as $entry) {
            if ($entry['type'] === 'DAY_OPENED' && $entry['data']['period'] === $date) {
                return $entry['data']['timestamp'];
            }
        }

        return null;
    }

    /**
     * Get close time for specific date
     */
    private function get_date_close_time($date) {
        $entries = get_option('menu_popup_accounting_entries', array());

        foreach ($entries as $entry) {
            if ($entry['type'] === 'DAY_CLOSED' && $entry['data']['period'] === $date) {
                return $entry['data']['timestamp'];
            }
        }

        return null;
    }

    /**
     * Check if business day is currently open
     */
    private function is_business_day_open($date = null) {
        if (!$date) {
            $date = date('Y-m-d');
        }

        // Check if today has an opening entry but no closing entry
        $has_opening = $this->get_date_open_time($date) !== null;
        $has_closing = $this->get_date_close_time($date) !== null;

        return $has_opening && !$has_closing;
    }

    /**
     * AJAX handler for real-time order updates
     */
    public function ajax_get_orders_realtime() {
        check_ajax_referer('menu_popup_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'menu-popup'));
        }

        $last_check_time = intval($_POST['last_check_time']) ?: 0;

        // Get orders updated since last check, or get all recent orders
        $args = array(
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        );

        // If there's a last check time, get orders modified after that
        if ($last_check_time > 0) {
            $args['date_query'] = array(
                array(
                    'column' => 'post_modified',
                    'after' => date('Y-m-d H:i:s', $last_check_time)
                )
            );
        } else {
            // If no last check time, get orders from the last 24 hours
            $args['date_query'] = array(
                array(
                    'column' => 'post_date',
                    'after' => '24 hours ago'
                )
            );
        }

        $query = new WP_Query($args);

        $orders = array();
        $order_statuses = $this->get_order_status_counts();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $order_id = get_the_ID();

                $orders[] = array(
                    'id' => $order_id,
                    'order_number' => get_post_meta($order_id, '_order_number', true) ?: $order_id,
                    'customer_name' => get_post_meta($order_id, '_customer_name', true),
                    'customer_phone' => get_post_meta($order_id, '_customer_phone', true),
                    'customer_email' => get_post_meta($order_id, '_customer_email', true),
                    'customer_address' => get_post_meta($order_id, '_customer_address', true),
                    'items' => json_decode(get_post_meta($order_id, '_order_items', true), true) ?: array(),
                    'subtotal' => get_post_meta($order_id, '_order_subtotal', true),
                    'tax' => get_post_meta($order_id, '_order_tax', true),
                    'total' => get_post_meta($order_id, '_order_total', true),
                    'status' => get_post_meta($order_id, '_order_status', true) ?: 'pending',
                    'date' => get_the_date('Y-m-d H:i'),
                    'special_instructions' => get_post_meta($order_id, '_special_instructions', true),
                    'order_type' => get_post_meta($order_id, '_order_type', true),
                    'delivery_fee' => get_post_meta($order_id, '_delivery_fee', true),
                    'delivery_zone' => get_post_meta($order_id, '_delivery_zone_name', true),
                    'delivery_details' => get_post_meta($order_id, '_delivery_address_details', true),
                    'order_source' => get_post_meta($order_id, '_order_source', true),
                    'needs_review' => get_post_meta($order_id, '_needs_review', true),
                    'delivery_lat' => get_post_meta($order_id, '_delivery_lat', true),
                    'delivery_lng' => get_post_meta($order_id, '_delivery_lng', true),
                    'delivery_distance' => get_post_meta($order_id, '_delivery_distance_km', true),
                    'delivery_eta' => get_post_meta($order_id, '_delivery_eta_minutes', true),
                    'branch_name' => get_post_meta($order_id, '_branch_id', true) ? get_the_title((int) get_post_meta($order_id, '_branch_id', true)) : '',
                    'currency_symbol' => get_post_meta($order_id, '_currency_symbol', true) ?: '$',
                    'modified' => get_the_modified_time('U')
                );
            }
        }

        wp_reset_postdata();

        wp_send_json_success(array(
            'orders' => $orders,
            'stats' => $order_statuses,
            'current_time' => current_time('timestamp')
        ));
    }

    /**
     * Get order status counts for real-time updates
     */
    private function get_order_status_counts() {
        $statuses = array('pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled');

        $counts = array();
        $total = 0;

        foreach ($statuses as $status) {
            $args = array(
                'post_type' => 'menu_order',
                'post_status' => 'publish',
                'meta_query' => array(
                    array(
                        'key' => '_order_status',
                        'value' => $status
                    )
                ),
                'fields' => 'ids'
            );

            $query = new WP_Query($args);
            $count = $query->found_posts;
            $counts[$status] = $count;
            $total += $count;
        }

        $counts['total'] = $total;
        return $counts;
    }

    /**
     * Generate CSV data for orders export
     */
    private function generate_orders_csv($query) {
        $csv_rows = array();

        // Add CSV headers
        $csv_rows[] = array(
            'Order Number',
            'Customer Name',
            'Phone',
            'Email',
            'Address',
            'Items',
            'Subtotal',
            'Tax',
            'Total',
            'Status',
            'Date',
            'Special Instructions',
            'Order Type',
            'Delivery Fee',
            'Delivery Zone',
            'Source',
            'Needs Review'
        );

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $order_id = get_the_ID();

                // Get order meta data
                $order_number = get_post_meta($order_id, '_order_number', true) ?: $order_id;
                $customer_name = get_post_meta($order_id, '_customer_name', true);
                $customer_phone = get_post_meta($order_id, '_customer_phone', true);
                $customer_email = get_post_meta($order_id, '_customer_email', true);
                $customer_address = get_post_meta($order_id, '_customer_address', true);
                $items = json_decode(get_post_meta($order_id, '_order_items', true), true) ?: array();
                $subtotal = get_post_meta($order_id, '_order_subtotal', true);
                $tax = get_post_meta($order_id, '_order_tax', true);
                $total = get_post_meta($order_id, '_order_total', true);
                $status = get_post_meta($order_id, '_order_status', true) ?: 'pending';
                $date = get_the_date('Y-m-d H:i', $order_id);
                $special_instructions = get_post_meta($order_id, '_special_instructions', true);
                $currency_symbol = get_post_meta($order_id, '_currency_symbol', true) ?: '$';
                $order_type = get_post_meta($order_id, '_order_type', true);
                $delivery_fee = get_post_meta($order_id, '_delivery_fee', true);
                $delivery_zone = get_post_meta($order_id, '_delivery_zone_name', true);
                $order_source = get_post_meta($order_id, '_order_source', true);
                $needs_review = get_post_meta($order_id, '_needs_review', true) === '1' ? 'yes' : '';

                // Format items for CSV
                $items_text = '';
                if (!empty($items)) {
                    $item_parts = array();
                    foreach ($items as $item) {
                        $item_parts[] = $item['name'] . ' (x' . $item['quantity'] . ')';
                    }
                    $items_text = implode('; ', $item_parts);
                }

                // Format special instructions for CSV (remove newlines)
                $special_instructions = str_replace(array("\r", "\n"), ' ', $special_instructions);

                // Add row to CSV
                $csv_rows[] = array(
                    $order_number,
                    $customer_name,
                    $customer_phone,
                    $customer_email,
                    $customer_address,
                    $items_text,
                    $currency_symbol . $subtotal,
                    $currency_symbol . $tax,
                    $currency_symbol . $total,
                    $status,
                    $date,
                    $special_instructions,
                    $order_type,
                    $delivery_fee === '' ? '' : $currency_symbol . $delivery_fee,
                    $delivery_zone,
                    $order_source,
                    $needs_review
                );
            }
        }

        // Convert to CSV string
        $output = fopen('php://temp', 'r+');

        foreach ($csv_rows as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);

        return $csv_content;
    }

    /**
     * Remove default orders admin menu from WordPress (we have Order Dashboard instead)
     * Hook runs at priority 999 to ensure it runs after WordPress adds the menu
     */
    public function remove_orders_admin_menu() {
        // Remove the default menu item for menu_order post type
        remove_menu_page('edit.php?post_type=menu_order');
    }

    /**
     * Remove orders from admin bar and menu (alternative approach)
     * This ensures orders are completely hidden from admin interface
     */
    public function remove_orders_from_admin() {
        // Remove if exists
        remove_menu_page('edit.php?post_type=menu_order');
    }

    /**
     * Format payment method for display
     */
    private function format_payment_method_display($payment_method) {
        if (empty($payment_method)) {
            return 'Not specified';
        }

        switch ($payment_method) {
            case 'cod':
                return 'Cash on Delivery';
            case 'bank_transfer':
                return 'Bank Transfer';
            case 'fawry':
                return 'Fawry';
            default:
                return ucfirst(str_replace('_', ' ', $payment_method));
        }
    }

    /**
     * Create payment status badge HTML
     */
    private function create_payment_status_badge($payment_status) {
        if (empty($payment_status)) {
            $payment_status = 'pending';
        }

        $status_class = 'payment-' . $payment_status;
        $status_text = ucfirst(str_replace('_', ' ', $payment_status));

        return '<span class="payment-status ' . $status_class . '">' . $status_text . '</span>';
    }
}
