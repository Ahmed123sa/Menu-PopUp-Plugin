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
        add_action('wp_ajax_save_order', array($this, 'ajax_save_order'));
        add_action('wp_ajax_nopriv_save_order', array($this, 'ajax_save_order'));
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

        // Handle order status update
        if (isset($_POST['update_order_status']) && check_admin_referer('update_order_status_nonce')) {
            $order_id = intval($_POST['order_id']);
            $new_status = sanitize_text_field($_POST['order_status']);

            if ($order_id && $new_status) {
                update_post_meta($order_id, '_order_status', $new_status);
                echo '<div class="notice notice-success"><p>' . __('Order status updated successfully!', 'menu-popup') . '</p></div>';
            }
        }

        // Handle order deletion
        if (isset($_POST['delete_order']) && check_admin_referer('delete_order_nonce')) {
            $order_id = intval($_POST['order_id']);

            if ($order_id) {
                wp_delete_post($order_id, true);
                echo '<div class="notice notice-success"><p>' . __('Order deleted successfully!', 'menu-popup') . '</p></div>';
            }
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Order Dashboard', 'menu-popup'); ?></h1>

            <!-- Order Statistics -->
            <div class="order-stats">
                <?php
                $total_orders = wp_count_posts('menu_order')->publish;
                $pending_orders = $this->count_orders_by_status('pending');
                $confirmed_orders = $this->count_orders_by_status('confirmed');
                $completed_orders = $this->count_orders_by_status('delivered');
                $total_revenue = $this->calculate_total_revenue();
                ?>
                <div class="stat-card">
                    <h3><?php echo esc_html($total_orders); ?></h3>
                    <p><?php _e('Total Orders', 'menu-popup'); ?></p>
                </div>
                <div class="stat-card pending">
                    <h3><?php echo esc_html($pending_orders); ?></h3>
                    <p><?php _e('Pending', 'menu-popup'); ?></p>
                </div>
                <div class="stat-card confirmed">
                    <h3><?php echo esc_html($confirmed_orders); ?></h3>
                    <p><?php _e('Confirmed', 'menu-popup'); ?></p>
                </div>
                <div class="stat-card completed">
                    <h3><?php echo esc_html($completed_orders); ?></h3>
                    <p><?php _e('Completed', 'menu-popup'); ?></p>
                </div>
                <div class="stat-card revenue">
                    <h3>$<?php echo number_format($total_revenue, 2); ?></h3>
                    <p><?php _e('Total Sales', 'menu-popup'); ?></p>
                </div>
            </div>

            <!-- Filters and Search -->
            <div class="order-filters">
                <div class="filter-row">
                    <div class="search-box">
                        <input type="text" id="order-search" placeholder="<?php _e('Search orders...', 'menu-popup'); ?>">
                        <button id="search-btn" class="button">🔍</button>
                    </div>
                    <div class="status-filter">
                        <select id="status-filter">
                            <option value="all"><?php _e('All Statuses', 'menu-popup'); ?></option>
                            <option value="pending"><?php _e('Pending', 'menu-popup'); ?></option>
                            <option value="confirmed"><?php _e('Confirmed', 'menu-popup'); ?></option>
                            <option value="preparing"><?php _e('Preparing', 'menu-popup'); ?></option>
                            <option value="ready"><?php _e('Ready', 'menu-popup'); ?></option>
                            <option value="delivered"><?php _e('Delivered', 'menu-popup'); ?></option>
                            <option value="cancelled"><?php _e('Cancelled', 'menu-popup'); ?></option>
                        </select>
                    </div>
                    <div class="payment-filter">
                        <select id="payment-filter">
                            <option value="all"><?php _e('All Payment Methods', 'menu-popup'); ?></option>
                            <option value="cod"><?php _e('Cash on Delivery', 'menu-popup'); ?></option>
                            <option value="bank_transfer"><?php _e('Bank Transfer', 'menu-popup'); ?></option>
                            <option value="fawry"><?php _e('Fawry', 'menu-popup'); ?></option>
                        </select>
                    </div>
                    <div class="order-type-filter">
                        <button id="show-all-orders" class="button order-type-btn active"><?php _e('All Orders', 'menu-popup'); ?></button>
                        <button id="show-delivery-orders" class="button order-type-btn"><?php _e('Delivery Orders', 'menu-popup'); ?> 🚚</button>
                        <button id="show-pickup-orders" class="button order-type-btn"><?php _e('Pickup Orders', 'menu-popup'); ?> 🛍️</button>
                    </div>
                    <div class="date-filter">
                        <input type="date" id="date-from" placeholder="From Date">
                        <input type="date" id="date-to" placeholder="To Date">
                        <button id="filter-btn" class="button"><?php _e('Filter', 'menu-popup'); ?></button>
                        <button id="export-btn" class="button button-secondary"><?php _e('Export', 'menu-popup'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Active Orders Table -->
            <div class="orders-section active-orders-section">
                <h3><?php _e('Active Orders', 'menu-popup'); ?></h3>
                <table class="wp-list-table widefat fixed striped" id="active-orders-table">
                    <thead>
                        <tr>
                            <th><?php _e('Order #', 'menu-popup'); ?></th>
                            <th><?php _e('Customer', 'menu-popup'); ?></th>
                            <th><?php _e('Items', 'menu-popup'); ?></th>
                            <th><?php _e('Total', 'menu-popup'); ?></th>
                            <th><?php _e('Order Status', 'menu-popup'); ?></th>
                            <th><?php _e('Payment Method', 'menu-popup'); ?></th>
                            <th><?php _e('Payment Status', 'menu-popup'); ?></th>
                            <th><?php _e('Date', 'menu-popup'); ?></th>
                            <th><?php _e('Actions', 'menu-popup'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="active-orders-tbody">
                        <!-- Active orders will be loaded here -->
                    </tbody>
                </table>
            </div>

            <!-- Completed Orders Table -->
            <div class="orders-section completed-orders-section">
                <h3><?php _e('Completed Orders', 'menu-popup'); ?> <span class="expand-icon">▼</span></h3>
                <div class="completed-orders-container collapsed">
                    <table class="wp-list-table widefat fixed striped completed-orders-table" id="completed-orders-table">
                        <thead>
                            <tr>
                                <th><?php _e('Order #', 'menu-popup'); ?></th>
                                <th><?php _e('Customer', 'menu-popup'); ?></th>
                                <th><?php _e('Items', 'menu-popup'); ?></th>
                                <th><?php _e('Total', 'menu-popup'); ?></th>
                                <th><?php _e('Status', 'menu-popup'); ?></th>
                            <th><?php _e('Date', 'menu-popup'); ?></th>
                            <th><?php _e('Actions', 'menu-popup'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="completed-orders-tbody">
                        <!-- Completed orders will be loaded here -->
                    </tbody>
                </table>
            </div>
        </div>

            <!-- Order Details Modal -->
            <div id="order-modal" class="order-modal" style="display: none;">
                <div class="order-modal-overlay"></div>
                <div class="order-modal-content">
                    <div class="order-modal-header">
                        <h3><?php _e('Order Details', 'menu-popup'); ?></h3>
                        <button class="order-modal-close">&times;</button>
                    </div>
                    <div class="order-modal-body">
                        <!-- Order details will be loaded here -->
                    </div>
                </div>
            </div>
        </div>

        <style>
        .order-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 2rem 0;
        }
        .stat-card {
            background: #fff;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-card h3 {
            margin: 0;
            font-size: 2rem;
            color: #0073aa;
        }
        .stat-card.pending h3 { color: #f59e0b; }
        .stat-card.confirmed h3 { color: #3b82f6; }
        .stat-card.completed h3 { color: #10b981; }
        .stat-card p {
            margin: 0.5rem 0 0;
            color: #666;
            font-weight: 500;
        }
        .order-filters {
            background: #fff;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .filter-row {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .search-box {
            display: flex;
            gap: 0.5rem;
        }
        .search-box input {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .date-filter {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .orders-container {
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        #orders-table th {
            background: #f8f9fa;
            padding: 1rem;
            font-weight: 600;
        }
        #orders-table td {
            padding: 1rem;
            vertical-align: middle;
        }
        .order-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .status-pending { background: #fef3c7; color: #f59e0b; }
        .status-confirmed { background: #dbeafe; color: #3b82f6; }
        .status-preparing { background: #e0e7ff; color: #6366f1; }
        .status-ready { background: #d1fae5; color: #059669; }
        .status-delivered { background: #d1fae5; color: #10b981; }
        .status-cancelled { background: #fee2e2; color: #ef4444; }
        
        /* Payment status styles */
        .payment-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-block;
        }
        .payment-pending { background: #fef3c7; color: #f59e0b; }
        .payment-processing { background: #e0e7ff; color: #6366f1; }
        .payment-paid { background: #d1fae5; color: #10b981; }
        .payment-failed { background: #fee2e2; color: #ef4444; }
        .payment-cancelled { background: #fee2e2; color: #ef4444; }
        .payment-expired { background: #f3f4f6; color: #6b7280; }
        .payment-refunded { background: #e5e7eb; color: #374151; }
        
        /* Payment method styles */
        .payment-method {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-block;
            background: #f3f4f6;
            color: #374151;
        }
        .payment-cod { background: #dbeafe; color: #1e40af; }
        .payment-bank-transfer { background: #e0e7ff; color: #3730a3; }
        .payment-fawry { background: #d1fae5; color: #065f46; }
        .order-actions {
            display: flex;
            gap: 0.5rem;
        }
        .btn-small {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border-radius: 4px;
            border: none;
            cursor: pointer;
        }
        .btn-view { background: #0073aa; color: white; }
        .btn-edit { background: #f59e0b; color: white; }
        .btn-delete { background: #ef4444; color: white; }
        .order-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .order-modal-content {
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow: auto;
        }
        .order-modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .order-modal-body {
            padding: 1.5rem;
        }
        .order-modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }
        .order-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .order-summary {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            let currentPage = 1;
            let currentFilters = {
                search: '',
                status: 'all',
                date_from: '',
                date_to: ''
            };
            let realtimeEnabled = '<?php echo get_option('menu_popup_order_dashboard_realtime_enabled', '0'); ?>' === '1';
            let updateInterval = parseInt('<?php echo get_option('menu_popup_order_dashboard_refresh_interval', '60'); ?>') * 1000; // Convert to milliseconds
            let updateTimer = null;
            let lastCheckTime = 0;

            // Load orders on page load
            loadOrders();

            // Start real-time updates if enabled
            if (realtimeEnabled) {
                startRealtimeUpdates();
            }

            // Search functionality
            $('#order-search').on('input', debounce(function() {
                currentFilters.search = this.value;
                currentPage = 1;
                loadOrders();
            }, 500));

            // Status filter
            $('#status-filter').on('change', function() {
                currentFilters.status = this.value;
                currentPage = 1;
                loadOrders();
            });

            // Payment method filter
            $('#payment-filter').on('change', function() {
                currentFilters.payment_method = this.value;
                currentPage = 1;
                loadOrders();
            });

            // Date filters
            $('#date-from, #date-to').on('change', function() {
                currentFilters.date_from = $('#date-from').val();
                currentFilters.date_to = $('#date-to').val();
                currentPage = 1;
                loadOrders();
            });

            // Filter button
            $('#filter-btn').on('click', function() {
                loadOrders();
            });

            // Order type filter buttons
            $('.order-type-btn').on('click', function() {
                const orderType = $(this).attr('id');

                // Remove active class from all buttons
                $('.order-type-btn').removeClass('active');
                // Add active class to clicked button
                $(this).addClass('active');

                // Store filter type
                currentFilters.order_type = orderType;

                // Apply client-side filtering if orders are already loaded
                filterOrdersByType(orderType);
            });

            // Export button
            $('#export-btn').on('click', function() {
                exportOrders();
            });

            function renderActiveOrders(orders) {
                const tbody = $('#active-orders-tbody');

                if (orders.length === 0) {
                    tbody.html('<tr><td colspan="7" style="text-align: center;">No active orders found</td></tr>');
                    return;
                }

                let html = '';
                orders.forEach(function(order) {
                    const statusClass = 'status-' + order.status;
                    const itemsPreview = order.items.slice(0, 3).map(item => item.name).join(', ');
                    const itemsText = order.items.length > 3 ? itemsPreview + '...' : itemsPreview;

                    const deliveryMark = order.customer_address ? ' 🚚' : ' 🛍️';
                    html += `
                        <tr data-order-type="${order.customer_address ? 'delivery' : 'pickup'}">
                            <td>#${order.order_number}${deliveryMark}</td>
                            <td>
                                <strong>${order.customer_name}</strong><br>
                                <small>${order.customer_phone}</small>
                            </td>
                            <td>${itemsText}</td>
                            <td>${order.currency_symbol}${order.total}</td>
                            <td><span class="order-status ${statusClass}">${order.status}</span></td>
                            <td>${order.payment_method_display || 'Not specified'}</td>
                            <td>${order.payment_status_badge || ''}</td>
                            <td>${order.date}</td>
                            <td>
                                <div class="order-actions">
                                    <button class="btn-small btn-view" onclick="viewOrder(${order.id})">View</button>
                                    <select class="btn-small" onchange="updateOrderStatus(${order.id}, this.value)" style="background: #f8f9fa; color: #333;">
                                        <option value="">Update Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="confirmed">Confirmed</option>
                                        <option value="preparing">Preparing</option>
                                        <option value="ready">Ready</option>
                                        <option value="delivered">Delivered</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                    <button class="btn-small btn-delete" onclick="deleteOrder(${order.id})">Delete</button>
                                </div>
                            </td>
                        </tr>
                    `;
                });

                tbody.html(html);
            }

            function renderCompletedOrders(orders) {
                const tbody = $('#completed-orders-tbody');

                if (orders.length === 0) {
                    tbody.html('<tr><td colspan="7" style="text-align: center;">No completed orders found</td></tr>');
                    return;
                }

                let html = '';
                orders.forEach(function(order) {
                    const statusClass = 'status-' + order.status;
                    const itemsPreview = order.items.slice(0, 3).map(item => item.name).join(', ');
                    const itemsText = order.items.length > 3 ? itemsPreview + '...' : itemsPreview;

                    html += `
                        <tr>
                            <td>#${order.order_number}</td>
                            <td>
                                <strong>${order.customer_name}</strong><br>
                                <small>${order.customer_phone}</small>
                            </td>
                            <td>${itemsText}</td>
                            <td>${order.currency_symbol}${order.total}</td>
                            <td><span class="order-status ${statusClass}">${order.status}</span></td>
                            <td>${order.date}</td>
                            <td>
                                <div class="order-actions">
                                    <button class="btn-small btn-view" onclick="viewOrder(${order.id})">View</button>
                                    <select class="btn-small" onchange="updateOrderStatus(${order.id}, this.value)" style="background: #f8f9fa; color: #333;">
                                        <option value="">Update Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="confirmed">Confirmed</option>
                                        <option value="preparing">Preparing</option>
                                        <option value="ready">Ready</option>
                                        <option value="delivered">Delivered</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                    <button class="btn-small btn-delete" onclick="deleteOrder(${order.id})">Delete</button>
                                </div>
                            </td>
                        </tr>
                    `;
                });

                tbody.html(html);
            }

            function loadOrders() {
                // Show loading for both active and completed orders
                $('#active-orders-tbody').html('<tr><td colspan="7" style="text-align: center;">Loading active orders...</td></tr>');
                $('#completed-orders-tbody').html('<tr><td colspan="7" style="text-align: center;">Loading completed orders...</td></tr>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_orders_active',
                        page: currentPage,
                        filters: currentFilters,
                        nonce: '<?php echo wp_create_nonce("get_orders_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            renderActiveOrders(response.data.active_orders || []);
                            renderCompletedOrders(response.data.completed_orders || []);
                        } else {
                            $('#active-orders-tbody').html('<tr><td colspan="7" style="text-align: center;">Error loading orders</td></tr>');
                            $('#completed-orders-tbody').html('<tr><td colspan="7" style="text-align: center;">Error loading orders</td></tr>');
                        }
                    }
                });
            }

            function renderOrders(orders) {
                const tbody = $('#orders-tbody');

                if (orders.length === 0) {
                    tbody.html('<tr><td colspan="7" style="text-align: center;">No orders found</td></tr>');
                    return;
                }

                let html = '';
                orders.forEach(function(order) {
                    const statusClass = 'status-' + order.status;
                    const itemsPreview = order.items.slice(0, 3).map(item => item.name).join(', ');
                    const itemsText = order.items.length > 3 ? itemsPreview + '...' : itemsPreview;

                    html += `
                        <tr>
                            <td>#${order.order_number}</td>
                            <td>
                                <strong>${order.customer_name}</strong><br>
                                <small>${order.customer_phone}</small>
                            </td>
                            <td>${itemsText}</td>
                            <td>${order.currency_symbol}${order.total}</td>
                            <td><span class="order-status ${statusClass}">${order.status}</span></td>
                            <td>${order.date}</td>
                            <td>
                                <div class="order-actions">
                                    <button class="btn-small btn-view" onclick="viewOrder(${order.id})">View</button>
                                    <select class="btn-small" onchange="updateOrderStatus(${order.id}, this.value)" style="background: #f8f9fa; color: #333;">
                                        <option value="">Update Status</option>
                                        <option value="pending">Pending</option>
                                        <option value="confirmed">Confirmed</option>
                                        <option value="preparing">Preparing</option>
                                        <option value="ready">Ready</option>
                                        <option value="delivered">Delivered</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                    <button class="btn-small btn-delete" onclick="deleteOrder(${order.id})">Delete</button>
                                </div>
                            </td>
                        </tr>
                    `;
                });

                tbody.html(html);
            }

            // Global functions for order actions
            window.viewOrder = function(orderId) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_order_details',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce("get_order_details_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            showOrderModal(response.data);
                        }
                    }
                });
            };

            window.updateOrderStatus = function(orderId, status) {
                if (!status) return;

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'update_order_status',
                        order_id: orderId,
                        status: status,
                        nonce: '<?php echo wp_create_nonce("update_order_status_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            loadOrders(); // Reload orders
                        }
                    }
                });
            };

            window.deleteOrder = function(orderId) {
                if (confirm('Are you sure you want to delete this order?')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'delete_order',
                            order_id: orderId,
                            nonce: '<?php echo wp_create_nonce("delete_order_nonce"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                loadOrders(); // Reload orders
                            }
                        }
                    });
                }
            };

            function showOrderModal(order) {
                const modal = $('#order-modal');
                const modalBody = modal.find('.order-modal-body');

                let itemsHtml = '';
                order.items.forEach(item => {
                    itemsHtml += `
                        <div class="order-item">
                            <span>${item.name} x${item.quantity}</span>
                            <span>${order.currency_symbol}${(item.price * item.quantity).toFixed(2)}</span>
                        </div>
                    `;
                });

                const modalContent = `
                    <div class="order-details">
                        <h4>Order #${order.order_number}</h4>
                        <div class="order-info">
                            <p><strong>Customer:</strong> ${order.customer_name}</p>
                            <p><strong>Phone:</strong> ${order.customer_phone}</p>
                            ${order.customer_email ? `<p><strong>Email:</strong> ${order.customer_email}</p>` : ''}
                            ${order.customer_address ? `<p><strong>Address:</strong> ${order.customer_address}</p>` : ''}
                            <p><strong>Date:</strong> ${order.date}</p>
                            <p><strong>Status:</strong> <span class="order-status status-${order.status}">${order.status}</span></p>
                        </div>

                        <h5>Order Items</h5>
                        <div class="order-items">
                            ${itemsHtml}
                        </div>

                        <div class="order-summary">
                            <div class="order-summary-row">
                                <span>Subtotal:</span>
                                <span>${order.currency_symbol}${order.subtotal}</span>
                            </div>
                            <div class="order-summary-row">
                                <span>Tax:</span>
                                <span>${order.currency_symbol}${order.tax}</span>
                            </div>
                            <div class="order-summary-row">
                                <strong>Total:</strong>
                                <strong>${order.currency_symbol}${order.total}</strong>
                            </div>
                        </div>

                        ${order.special_instructions ? `<div class="special-instructions"><strong>Special Instructions:</strong><br>${order.special_instructions}</div>` : ''}
                    </div>
                `;

                modalBody.html(modalContent);
                modal.show();
            }

            // Close modal
            $('.order-modal-close, .order-modal-overlay').on('click', function() {
                $('#order-modal').hide();
            });

            function exportOrders() {
                // Show loading state
                const exportBtn = $('#export-btn');
                const originalText = exportBtn.text();
                exportBtn.text('Exporting...').prop('disabled', true);

                // Get current filters
                const exportFilters = {
                    search: $('#order-search').val(),
                    status: $('#status-filter').val(),
                    date_from: $('#date-from').val(),
                    date_to: $('#date-to').val()
                };

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'export_orders_data',
                        filters: exportFilters,
                        nonce: '<?php echo wp_create_nonce("get_orders_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Create and download CSV file
                            const csvData = response.data.csv_data;
                            const blob = new Blob([csvData], { type: 'text/csv;charset=utf-8;' });
                            const url = window.URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = 'orders_export_' + new Date().toISOString().split('T')[0] + '.csv';
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            window.URL.revokeObjectURL(url);

                            // Show success message
                            showExportSuccess();
                        } else {
                            alert('Error exporting orders: ' + (response.data || 'Unknown error'));
                        }
                    },
                    error: function() {
                        alert('Error connecting to server. Please try again.');
                    },
                    complete: function() {
                        // Restore button state
                        exportBtn.text(originalText).prop('disabled', false);
                    }
                });
            }

            function showExportSuccess() {
                // Create temporary success notification
                const notification = $(`
                    <div id="export-notification" class="export-notification" style="
                        position: fixed;
                        top: 20px;
                        right: 20px;
                        background: #10b981;
                        color: white;
                        padding: 1rem 1.5rem;
                        border-radius: 8px;
                        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                        z-index: 9999;
                        display: flex;
                        align-items: center;
                        gap: 0.5rem;
                    ">
                        <span>✅</span>
                        <span>Orders exported successfully!</span>
                    </div>
                `);

                $('body').append(notification);

                // Remove after 3 seconds
                setTimeout(() => {
                    notification.fadeOut(() => {
                        notification.remove();
                    });
                }, 3000);
            }

            function startRealtimeUpdates() {
                // First load to establish baseline
                checkForUpdates();

                // Then set up interval for continuous updates
                updateTimer = setInterval(checkForUpdates, updateInterval);
            }

            function stopRealtimeUpdates() {
                if (updateTimer) {
                    clearInterval(updateTimer);
                    updateTimer = null;
                }
            }

            function checkForUpdates() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_orders_realtime',
                        last_check_time: lastCheckTime,
                        nonce: '<?php echo wp_create_nonce("menu_popup_admin_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update statistics if they changed
                            if (response.data.stats) {
                                updateStatistics(response.data.stats);
                            }

                            // Update orders table if there are new/modified orders
                            if (response.data.orders && response.data.orders.length > 0) {
                                // Here we could show a notification about new orders
                                console.log('New orders received:', response.data.orders);

                                // Refresh the orders table to show updates
                                loadOrders();
                            }

                            // Update last check time
                            lastCheckTime = response.data.current_time;
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.warn('Real-time update failed:', textStatus);
                        // Continue polling - don't show user error for background updates
                    }
                });
            }

            function updateStatistics(stats) {
                $('.stat-card h3').each(function() {
                    const statType = $(this).siblings('p').text().toLowerCase();
                    let newValue = 0;

                    switch(statType) {
                        case 'pending':
                            newValue = stats.pending || 0;
                            break;
                        case 'confirmed':
                            newValue = stats.confirmed || 0;
                            break;
                        case 'completed':
                            newValue = stats.delivered || 0; // delivered = completed
                            break;
                        case 'total orders':
                            newValue = stats.total || 0;
                            break;
                    }

                    const currentValue = parseInt($(this).text()) || 0;
                    if (newValue !== currentValue) {
                        // Animate the change
                        animateNumber($(this), currentValue, newValue);

                        // Add visual indicator for change
                        if (newValue > currentValue) {
                            showStatChangeIndicator($(this), 'up');
                        }
                    }
                });
            }

            function animateNumber(element, start, end) {
                const duration = 500;
                const startTime = performance.now();

                function animate(currentTime) {
                    const elapsed = currentTime - startTime;
                    const progress = Math.min(elapsed / duration, 1);

                    const current = Math.floor(start + (end - start) * progress);
                    element.text(current);

                    if (progress < 1) {
                        requestAnimationFrame(animate);
                    }
                }

                requestAnimationFrame(animate);
            }

            function showStatChangeIndicator(element, direction) {
                const indicator = direction === 'up' ? '📈' : '📉';
                const color = direction === 'up' ? '#10b981' : '#ef4444';

                // Create temporary indicator
                const changeIndicator = $('<span>')
                    .text(indicator)
                    .css({
                        position: 'absolute',
                        top: '-10px',
                        right: '-10px',
                        fontSize: '16px',
                        color: color,
                        animation: 'bounce 0.5s ease-out'
                    });

                element.parent().css('position', 'relative').append(changeIndicator);

                // Remove after animation
                setTimeout(() => {
                    changeIndicator.fadeOut(() => changeIndicator.remove());
                }, 500);
            }

            // Stop real-time updates when page is not visible
            $(window).on('blur', function() {
                if (updateTimer) {
                    stopRealtimeUpdates();
                }
            });

            $(window).on('focus', function() {
                if (realtimeEnabled && !updateTimer) {
                    startRealtimeUpdates();
                }
            });

            function filterOrdersByType(orderType) {
                const activeTable = $('#active-orders-tbody');
                const completedTable = $('#completed-orders-tbody');

                if (orderType === 'show-all-orders') {
                    // Show all orders
                    activeTable.find('tr[data-order-type]').show();
                    completedTable.find('tr').show();
                } else if (orderType === 'show-delivery-orders') {
                    // Show only delivery orders
                    activeTable.find('tr[data-order-type="pickup"]').hide();
                    activeTable.find('tr[data-order-type="delivery"]').show();
                    completedTable.find('tr').show(); // Completed table doesn't have the attribute, show all
                } else if (orderType === 'show-pickup-orders') {
                    // Show only pickup orders
                    activeTable.find('tr[data-order-type="delivery"]').hide();
                    activeTable.find('tr[data-order-type="pickup"]').show();
                    completedTable.find('tr').show(); // Completed table doesn't have the attribute, show all
                }
            }

            function debounce(func, wait) {
                let timeout;
                return function executedFunction(...args) {
                    const later = () => {
                        clearTimeout(timeout);
                        func(...args);
                    };
                    clearTimeout(timeout);
                    timeout = setTimeout(later, wait);
                };
            }
        });
        </script>
        <?php
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
     * AJAX handler to save order to database
     */
    public function ajax_save_order() {
        check_ajax_referer('menu_popup_nonce', 'nonce');

        $order_data = $_POST['order_data'];

        if (!$order_data) {
            wp_send_json_error(__('No order data provided', 'menu-popup'));
        }

        // Generate unique order number
        $order_number = 'ORD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // Create order post
        $order_id = wp_insert_post(array(
            'post_title' => 'Order #' . $order_number,
            'post_type' => 'menu_order',
            'post_status' => 'publish',
            'post_date' => current_time('mysql')
        ));

        if ($order_id) {
            // Save order meta data
            update_post_meta($order_id, '_order_number', $order_number);
            update_post_meta($order_id, '_customer_name', sanitize_text_field($order_data['customer_name']));
            update_post_meta($order_id, '_customer_phone', sanitize_text_field($order_data['customer_phone']));
            update_post_meta($order_id, '_customer_email', sanitize_email($order_data['customer_email']));
            update_post_meta($order_id, '_customer_address', sanitize_textarea_field($order_data['customer_address']));
            update_post_meta($order_id, '_special_instructions', sanitize_textarea_field($order_data['special_instructions']));
            update_post_meta($order_id, '_order_items', $order_data['order_items']);
            update_post_meta($order_id, '_order_subtotal', floatval($order_data['subtotal']));
            update_post_meta($order_id, '_order_tax', floatval($order_data['tax']));
            update_post_meta($order_id, '_order_total', floatval($order_data['total']));
            update_post_meta($order_id, '_order_status', 'pending');
            update_post_meta($order_id, '_currency_symbol', sanitize_text_field($order_data['currency_symbol']));
            
            // Save payment method
            if (!empty($order_data['payment_method'])) {
                update_post_meta($order_id, '_payment_method', sanitize_text_field($order_data['payment_method']));
                update_post_meta($order_id, '_payment_status', 'pending');
            }

            wp_send_json_success(__('Order saved successfully', 'menu-popup'));
        } else {
            wp_send_json_error(__('Failed to save order', 'menu-popup'));
        }
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
            'Special Instructions'
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
                    $special_instructions
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
