<?php
/**
 * Settings page for Menu Pop-up plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Settings {

    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        
        add_action('wp_ajax_save_category_order', array($this, 'save_category_order'));
        add_action('wp_ajax_delete_category', array($this, 'delete_category'));
        add_action('wp_ajax_add_new_category', array($this, 'add_new_category'));
        add_action('wp_ajax_update_category', array($this, 'update_category'));

        // Add menu management AJAX handlers
        add_action('wp_ajax_save_menu_order', array($this, 'save_menu_order'));
        add_action('wp_ajax_delete_menu', array($this, 'delete_menu'));
        add_action('wp_ajax_add_new_menu', array($this, 'add_new_menu'));
        add_action('wp_ajax_update_menu', array($this, 'update_menu'));

        // AJAX for menu-to-branch assignment
        add_action('wp_ajax_menu_popup_get_branches_for_menu', array($this, 'get_branches_for_menu_ajax'));

        // AJAX for accounting reset
        add_action('wp_ajax_reset_accounting_day', array($this, 'reset_accounting_day'));
        add_action('wp_ajax_reset_all_accounting_data', array($this, 'reset_all_accounting_data'));
        add_action('wp_ajax_verify_admin_password', array($this, 'verify_admin_password'));

        // Note: Menu registration is now centralized in Menu_Popup_Admin_Dashboard
    }



    /**
     * Accounting page
     */
    public function accounting_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle business day open/close actions
        if (isset($_POST['action']) && check_admin_referer('menu_popup_accounting_nonce')) {
            if ($_POST['action'] === 'open_business_day') {
                update_option('menu_popup_accounting_business_open', '1');
                update_option('menu_popup_accounting_day_open_time', current_time('timestamp'));

                $this->log_accounting_entry('DAY_OPENED', array(
                    'timestamp' => current_time('timestamp'),
                    'user_id' => get_current_user_id(),
                    'period' => date('Y-m-d')
                ));

                echo '<div class="notice notice-success"><p>' . __('Business day opened successfully!', 'menu-popup') . '</p></div>';
            } elseif ($_POST['action'] === 'close_business_day') {
                update_option('menu_popup_accounting_business_open', '0');

                // Calculate end-of-day summary
                $day_summary = $this->calculate_daily_summary();

                $this->log_accounting_entry('DAY_CLOSED', array_merge($day_summary, array(
                    'timestamp' => current_time('timestamp'),
                    'user_id' => get_current_user_id(),
                    'period' => date('Y-m-d')
                )));

                echo '<div class="notice notice-success"><p>' . __('Business day closed successfully! Orders processed: ' . $day_summary['orders_count'] . ', Revenue: $' . number_format($day_summary['total_revenue'], 2) . '', 'menu-popup') . '</p></div>';
            }
        }

        $business_open = get_option('menu_popup_accounting_business_open', '1') === '1';

        // Get today's summary
        $today_date = date('Y-m-d');
        $today_entries_for_date = $this->get_entries_for_date($today_date);
        $today_summary = $this->calculate_daily_summary();
        // Override today's summary with actual today's data if empty
        if (empty($today_summary['orders_count'])) {
            $today_summary = array(
                'orders_count' => count($today_entries_for_date['orders']),
                'total_revenue' => $this->calculate_date_revenue($today_date),
                'period' => $today_date
            );
        }

        // Get all unique dates that have accounting entries
        $entries = get_option('menu_popup_accounting_entries', array());
        $accounting_dates = array();
        
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (isset($entry['data']['period'])) {
                    $accounting_dates[$entry['data']['period']] = true;
                }
            }
        }
        
        // Sort dates in descending order (most recent first)
        krsort($accounting_dates);
        
        // Get up to 7 days that have accounting entries
        $week_data = array();
        $days_shown = 0;
        foreach ($accounting_dates as $date => $has_entry) {
            if ($days_shown >= 7) break;
            
            $entries_for_date = $this->get_entries_for_date($date);
            $revenue = $this->calculate_date_revenue($date);
            $week_data[] = array(
                'date' => $date,
                'orders_count' => count($entries_for_date['orders']),
                'revenue' => $revenue,
                'is_open' => $this->is_business_day_open($date)
            );
            $days_shown++;
        }

        // Initialize calculation methods for this instance
        $total_orders = wp_count_posts('menu_order')->publish;
        $delivered_orders = $this->count_orders_by_status('delivered');
        $total_revenue = $this->calculate_total_revenue();
        $average_order_value = $total_orders > 0 ? $total_revenue / $total_orders : 0;
        ?>
        <div class="wrap">
            <h1><?php _e('Accounting & Reports', 'menu-popup'); ?></h1>

            <!-- Business Day Management -->
            <div class="business-day-management">
                <h2><?php _e('Business Day Management', 'menu-popup'); ?></h2>
                <div class="day-status">
                    <div class="status-indicator <?php echo $business_open ? 'open' : 'closed'; ?>">
                        <span class="status-icon"><?php echo $business_open ? '🟢' : '🔴'; ?></span>
                        <span class="status-text"><?php echo $business_open ? __('Business Day: OPEN', 'menu-popup') : __('Business Day: CLOSED', 'menu-popup'); ?></span>
                    </div>
                </div>

                <div class="day-actions">
                    <form method="post" action="">
                        <?php wp_nonce_field('menu_popup_accounting_nonce'); ?>
                        <?php if (!$business_open): ?>
                            <button type="submit" name="action" value="open_business_day" class="button button-primary">
                                <?php _e('Open Business Day', 'menu-popup'); ?>
                            </button>
                            <p class="description"><?php _e('Open the business day to start accepting orders.', 'menu-popup'); ?></p>
                        <?php else: ?>
                            <button type="submit" name="action" value="close_business_day" class="button button-primary">
                                <?php _e('Close Business Day', 'menu-popup'); ?>
                            </button>
                            <p class="description"><?php _e('Close the business day and finalize today\'s transactions.', 'menu-popup'); ?></p>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="accounting-stats">
                <div class="stat-card">
                    <h3>$<?php echo number_format($total_revenue, 2); ?></h3>
                    <p><?php _e('Total Sales', 'menu-popup'); ?></p>
                </div>
                <div class="stat-card">
                    <h3><?php echo $total_orders; ?></h3>
                    <p><?php _e('Total Orders', 'menu-popup'); ?></p>
                </div>
                <div class="stat-card">
                    <h3><?php echo $delivered_orders; ?></h3>
                    <p><?php _e('Delivered Orders', 'menu-popup'); ?></p>
                </div>
                <div class="stat-card">
                    <h3>$<?php echo number_format($average_order_value, 2); ?></h3>
                    <p><?php _e('Average Order Value', 'menu-popup'); ?></p>
                </div>
            </div>

            <!-- Today's Summary -->
            <div class="today-summary">
                <h2><?php _e('Today\'s Summary', 'menu-popup'); ?> (<?php echo date('F j, Y'); ?>)</h2>
                <div class="summary-grid">
                    <div class="summary-item">
                        <span class="summary-label"><?php _e('Orders Placed:', 'menu-popup'); ?></span>
                        <span class="summary-value"><?php echo isset($today_summary['orders']) && is_array($today_summary['orders']) ? count($today_summary['orders']) : '0'; ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label"><?php _e('Revenue:', 'menu-popup'); ?></span>
                        <span class="summary-value">$<?php echo number_format($this->calculate_date_revenue($today_date), 2); ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label"><?php _e('Day Started:', 'menu-popup'); ?></span>
                        <span class="summary-value"><?php echo $business_open ? date('g:i A', get_option('menu_popup_accounting_day_open_time', current_time('timestamp'))) : __('Not opened', 'menu-popup'); ?></span>
                    </div>
                </div>
            </div>

            <!-- 7-Day Overview -->
            <div class="week-overview">
                <h2><?php _e('Last 7 Days Overview', 'menu-popup'); ?></h2>
                <div class="week-chart">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Date', 'menu-popup'); ?></th>
                                <th><?php _e('Orders', 'menu-popup'); ?></th>
                                <th><?php _e('Revenue', 'menu-popup'); ?></th>
                                <th><?php _e('Status', 'menu-popup'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($week_data as $day): ?>
                                <tr>
                                    <td><?php echo date('M j', strtotime($day['date'])); ?></td>
                                    <td><?php echo $day['orders_count']; ?></td>
                                    <td>$<?php echo number_format($day['revenue'], 2); ?></td>
                                    <td>
                                        <span class="day-status <?php echo $day['is_open'] ? 'open' : 'closed'; ?>">
                                            <?php echo $day['is_open'] ? __('Opened', 'menu-popup') : __('Closed', 'menu-popup'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Export Options -->
            <div class="export-options">
                <h2><?php _e('Reports & Export', 'menu-popup'); ?></h2>
                <div class="export-buttons">
                    <button id="export-daily-report" class="button"><?php _e('Export Daily Report', 'menu-popup'); ?></button>
                    <button id="export-monthly-report" class="button"><?php _e('Export Monthly Report', 'menu-popup'); ?></button>
                </div>
            </div>

            <!-- Reset Accounting Data -->
            <div class="reset-accounting-section" style="background: #fff; padding: 2rem; margin: 2rem 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #dc2626;">
                <h2 style="color: #dc2626;"><?php _e('Reset Accounting Data', 'menu-popup'); ?></h2>
                <p class="description"><?php _e('Warning: These actions cannot be undone. Please export your data before resetting.', 'menu-popup'); ?></p>
                
                <div class="reset-options" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem;">
                    <div class="reset-option" style="flex: 1; min-width: 250px; padding: 1rem; border: 1px solid #ddd; border-radius: 8px;">
                        <h3><?php _e('Reset Specific Day', 'menu-popup'); ?></h3>
                        <p><?php _e('Remove accounting data for a specific date.', 'menu-popup'); ?></p>
                        <div style="margin: 1rem 0;">
                            <input type="date" id="reset-day-date" max="<?php echo date('Y-m-d'); ?>" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />
                        </div>
                        <button type="button" id="reset-day-btn" class="button" style="background: #dc2626; color: #fff; border-color: #dc2626;">
                            <?php _e('Reset This Day', 'menu-popup'); ?>
                        </button>
                    </div>

                    <div class="reset-option" style="flex: 1; min-width: 250px; padding: 1rem; border: 1px solid #dc2626; border-radius: 8px; background: #fef2f2;">
                        <h3 style="color: #dc2626;"><?php _e('Reset All Data', 'menu-popup'); ?></h3>
                        <p><?php _e('Clear ALL accounting entries and reset the system. This cannot be undone!', 'menu-popup'); ?></p>
                        <button type="button" id="reset-all-btn" class="button" style="background: #dc2626; color: #fff; border-color: #dc2626; margin-top: 1rem;">
                            <?php _e('Reset All Accounting Data', 'menu-popup'); ?>
                        </button>
                    </div>
                </div>
                
                <div id="reset-message" style="margin-top: 1rem;"></div>
            </div>

            <!-- Password Confirmation Modal -->
            <div id="password-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999;">
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; padding: 2rem; border-radius: 8px; max-width: 400px; width: 90%;">
                    <h3 id="password-modal-title"><?php _e('Confirm Action', 'menu-popup'); ?></h3>
                    <p><?php _e('Enter your WordPress admin password to confirm this action:', 'menu-popup'); ?></p>
                    <input type="password" id="confirm-password" style="width: 100%; padding: 8px; margin: 1rem 0; border: 1px solid #ddd; border-radius: 4px;" />
                    <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                        <button type="button" id="cancel-reset" class="button"><?php _e('Cancel', 'menu-popup'); ?></button>
                        <button type="button" id="confirm-reset" class="button button-primary" style="background: #dc2626; border-color: #dc2626;"><?php _e('Confirm', 'menu-popup'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <style>
        .accounting-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 2rem 0;
        }
        .accounting-stats .stat-card {
            background: #fff;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        .accounting-stats .stat-card h3 {
            margin: 0 0 0.5rem 0;
            color: #0073aa;
        }
        .business-day-management {
            background: #fff;
            padding: 2rem;
            margin: 2rem 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .day-status {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        .status-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-weight: 500;
        }
        .status-indicator.open {
            background: #dcfce7;
            color: #166534;
        }
        .status-indicator.closed {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-icon {
            font-size: 1.2rem;
        }
        .today-summary, .week-overview, .export-options {
            background: #fff;
            padding: 2rem;
            margin: 2rem 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 6px;
        }
        .summary-label {
            font-weight: 500;
        }
        .summary-value {
            font-weight: 600;
            color: #0073aa;
        }
        .day-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .day-status.open {
            background: #dcfce7;
            color: #166534;
        }
        .day-status.closed {
            background: #f3f4f6;
            color: #6b7280;
        }
        .week-chart {
            overflow-x: auto;
        }
        .export-buttons {
            display: flex;
            gap: 1rem;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Export functionality for reports
            $('#export-daily-report').on('click', function() {
                // Get last 7 days data
                const reportData = <?php echo json_encode($week_data); ?>;

                // Create CSV content
                let csvContent = 'Date,Orders,Revenue,Status\n';
                reportData.forEach(day => {
                    csvContent += `${day.date},${day.orders_count},$${day.revenue.toFixed(2)},${day.is_open ? 'Opened' : 'Closed'}\n`;
                });

                // Download CSV
                downloadCSV('daily_report_' + new Date().toISOString().split('T')[0] + '.csv', csvContent);
            });

            $('#export-monthly-report').on('click', function() {
                // Get current month data (simplified for now - extend as needed)
                const orders = <?php echo $total_orders; ?>;
                const revenue = <?php echo $total_revenue; ?>;
                const averageOrder = <?php echo $average_order_value; ?>;

                let csvContent = 'Metric,Value\n';
                csvContent += `Total Orders,${orders}\n`;
                csvContent += `Total Revenue,$${revenue.toFixed(2)}\n`;
                csvContent += `Average Order Value,$${averageOrder.toFixed(2)}\n`;
                csvContent += `Period,${new Date().toLocaleString('default', { month: 'long', year: 'numeric' })}\n`;

                downloadCSV('monthly_report_' + new Date().toISOString().slice(0,7) + '.csv', csvContent);
            });

            function downloadCSV(filename, content) {
                const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            }

            // Reset functionality
            let currentResetAction = '';
            
            $('#reset-day-btn').on('click', function() {
                const date = $('#reset-day-date').val();
                if (!date) {
                    alert('<?php _e('Please select a date to reset.', 'menu-popup'); ?>');
                    return;
                }
                currentResetAction = 'reset_day';
                $('#password-modal-title').text('<?php _e('Confirm Reset Day', 'menu-popup'); ?>');
                $('#password-modal').show();
            });

            $('#reset-all-btn').on('click', function() {
                if (!confirm('<?php _e('Are you sure you want to reset ALL accounting data? This cannot be undone!', 'menu-popup'); ?>')) {
                    return;
                }
                currentResetAction = 'reset_all';
                $('#password-modal-title').text('<?php _e('Confirm Reset All', 'menu-popup'); ?>');
                $('#password-modal').show();
            });

            $('#cancel-reset').on('click', function() {
                $('#password-modal').hide();
                $('#confirm-password').val('');
                currentResetAction = '';
            });

            $('#confirm-reset').on('click', function() {
                const password = $('#confirm-password').val();
                if (!password) {
                    alert('<?php _e('Please enter your password.', 'menu-popup'); ?>');
                    return;
                }

                // Verify password first
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'verify_admin_password',
                        password: password,
                        nonce: '<?php echo wp_create_nonce('menu_popup_reset_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Password verified, proceed with reset
                            if (currentResetAction === 'reset_day') {
                                const date = $('#reset-day-date').val();
                                $.ajax({
                                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                    type: 'POST',
                                    data: {
                                        action: 'reset_accounting_day',
                                        date: date,
                                        nonce: '<?php echo wp_create_nonce('menu_popup_reset_nonce'); ?>'
                                    },
                                    success: function(res) {
                                        if (res.success) {
                                            $('#reset-message').html('<div class="notice notice-success"><p>' + res.data.message + '</p></div>');
                                            setTimeout(function() { location.reload(); }, 1500);
                                        } else {
                                            $('#reset-message').html('<div class="notice notice-error"><p>' + res.data.message + '</p></div>');
                                        }
                                    }
                                });
                            } else if (currentResetAction === 'reset_all') {
                                $.ajax({
                                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                    type: 'POST',
                                    data: {
                                        action: 'reset_all_accounting_data',
                                        nonce: '<?php echo wp_create_nonce('menu_popup_reset_nonce'); ?>'
                                    },
                                    success: function(res) {
                                        if (res.success) {
                                            $('#reset-message').html('<div class="notice notice-success"><p>' + res.data.message + '</p></div>');
                                            setTimeout(function() { location.reload(); }, 1500);
                                        } else {
                                            $('#reset-message').html('<div class="notice notice-error"><p>' + res.data.message + '</p></div>');
                                        }
                                    }
                                });
                            }
                            $('#password-modal').hide();
                            $('#confirm-password').val('');
                        } else {
                            alert('<?php _e('Incorrect password. Please try again.', 'menu-popup'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php _e('An error occurred. Please try again.', 'menu-popup'); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    // Helper methods for accounting (move these from main plugin class if needed)
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

    private function is_business_day_open($date = null) {
        if (!$date) {
            $date = date('Y-m-d');
        }

        // Check if today has an opening entry but no closing entry
        $has_opening = $this->get_date_open_time($date) !== null;
        $has_closing = $this->get_date_close_time($date) !== null;

        return $has_opening && !$has_closing;
    }

    private function get_date_open_time($date) {
        $entries = get_option('menu_popup_accounting_entries', array());

        foreach ($entries as $entry) {
            if ($entry['type'] === 'DAY_OPENED' && $entry['data']['period'] === $date) {
                return $entry['data']['timestamp'];
            }
        }

        return null;
    }

    private function get_date_close_time($date) {
        $entries = get_option('menu_popup_accounting_entries', array());

        foreach ($entries as $entry) {
            if ($entry['type'] === 'DAY_CLOSED' && $entry['data']['period'] === $date) {
                return $entry['data']['timestamp'];
            }
        }

        return null;
    }

    private function log_accounting_entry($type, $data) {
        // Store entry in WordPress options for period tracking
        $entries = get_option('menu_popup_accounting_entries', array());

        if (!is_array($entries)) {
            $entries = array();
        }

        $entries[] = array(
            'type' => $type,
            'timestamp' => $data['timestamp'],
            'user_id' => $data['user_id'],
            'data' => $data
        );

        update_option('menu_popup_accounting_entries', $entries);
    }

    private function calculate_daily_summary() {
        $today = date('Y-m-d');
        $open_time = $this->get_date_open_time($today);

        // Always return a valid array with default values, even if day wasn't opened
        $summary = array(
            'orders_count' => 0,
            'total_revenue' => 0.0,
            'delivered_orders' => 0,
            'pending_orders' => 0,
            'open_time' => $open_time,
            'close_time' => current_time('timestamp'),
            'period' => $today,
            'orders' => array() // Empty orders array for compatibility
        );

        if (!$open_time) {
            return $summary;
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

        $orders_list = array();

        $summary = array(
            'orders_count' => 0,
            'total_revenue' => 0.0,
            'delivered_orders' => 0,
            'pending_orders' => 0,
            'open_time' => $open_time,
            'close_time' => current_time('timestamp'),
            'period' => $today,
            'orders' => array()
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
     * Migration page HTML
     */
    public function migration_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['migrate_menu_items']) && check_admin_referer('menu_popup_migration')) {
            $this->migrate_post_meta_to_cpt();
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

    public function add_plugin_menu() {
        // Submenu pages
        add_submenu_page(
            'menu-popup-dashboard',
            __('Categories', 'menu-popup'),
            __('Categories', 'menu-popup'),
            'manage_options',
            'menu-popup-categories',
            array($this, 'categories_page_html')
        );

        add_submenu_page(
            'menu-popup-dashboard',
            __('Menus', 'menu-popup'),
            __('Menus', 'menu-popup'),
            'manage_options',
            'menu-popup-menus',
            array($this, 'menus_page_html')
        );

        add_submenu_page(
            'edit.php?post_type=menu_popup_branch',
            __('Cities/Areas', 'menu-popup'),
            __('Cities/Areas', 'menu-popup'),
            'manage_categories', // Capability for managing taxonomies
            'edit-tags.php?taxonomy=menu_popup_branch_city&post_type=menu_popup_branch',
            null
        );

        add_submenu_page(
            'menu-popup-dashboard',
            __('Settings', 'menu-popup'),
            __('Settings', 'menu-popup'),
            'manage_options',
            'menu-popup-settings',
            array($this, 'settings_page_html')
        );
    }

    public function register_settings() {
        register_setting(
            'menu_popup_settings_group',
            'menu_popup_layout_type',
            array(
                'type' => 'string',
                'default' => 'grid',
                'sanitize_callback' => array($this, 'sanitize_layout_type')
            )
        );

        // E-Commerce Settings
        register_setting(
            'menu_popup_settings_group',
            'menu_popup_ordering_enabled',
            array(
                'type' => 'boolean',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_whatsapp_enabled',
            array(
                'type' => 'boolean',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        // Shopping cart is now automatically enabled when ordering or WhatsApp is enabled
        // Removed: menu_popup_cart_enabled setting

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_delivery_enabled',
            array(
                'type' => 'boolean',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_delivery_mode',
            array(
                'type' => 'string',
                'default' => 'manual',
                'sanitize_callback' => array($this, 'sanitize_delivery_mode')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_map_default_lat',
            array(
                'type' => 'string',
                'default' => '30.0444',
                'sanitize_callback' => array($this, 'sanitize_map_coordinate_lat')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_map_default_lng',
            array(
                'type' => 'string',
                'default' => '31.2357',
                'sanitize_callback' => array($this, 'sanitize_map_coordinate_lng')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_google_maps_api_key',
            array(
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field'
            )
        );

        // Customer Fields Settings
        register_setting(
            'menu_popup_settings_group',
            'menu_popup_customer_name_required',
            array(
                'type' => 'boolean',
                'default' => '1',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_customer_phone_required',
            array(
                'type' => 'boolean',
                'default' => '1',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_customer_email_enabled',
            array(
                'type' => 'boolean',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_customer_address_enabled',
            array(
                'type' => 'boolean',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_special_instructions_enabled',
            array(
                'type' => 'boolean',
                'default' => '1',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        // Business Settings
        register_setting(
            'menu_popup_settings_group',
            'menu_popup_restaurant_whatsapp',
            array(
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => array($this, 'sanitize_whatsapp_number')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_currency_symbol',
            array(
                'type' => 'string',
                'default' => '$',
                'sanitize_callback' => array($this, 'sanitize_currency_symbol')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_tax_rate',
            array(
                'type' => 'string',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_tax_rate')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_minimum_order',
            array(
                'type' => 'string',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_minimum_order')
            )
        );



        register_setting(
            'menu_popup_settings_group',
            'menu_popup_animation_speed',
            array(
                'type' => 'string',
                'default' => '300',
                'sanitize_callback' => array($this, 'sanitize_animation_speed')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_show_price_card',
            array(
                'type' => 'boolean',
                'default' => '1',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_show_price_modal',
            array(
                'type' => 'boolean',
                'default' => '1',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        // Order Button Display Settings in Modal
        register_setting(
            'menu_popup_settings_group',
            'menu_popup_show_order_online_modal',
            array(
                'type' => 'boolean',
                'default' => '1',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_show_whatsapp_modal',
            array(
                'type' => 'boolean',
                'default' => '1',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_enable_video_autoplay',
            array(
                'type' => 'boolean',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        // Color Settings
        register_setting(
            'menu_popup_colors_group',
            'menu_popup_primary_color',
            array(
                'type' => 'string',
                'default' => '#A855F7', // Purple primary
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_secondary_color',
            array(
                'type' => 'string',
                'default' => '#1F2937', // Dark gray secondary
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_accent_color',
            array(
                'type' => 'string',
                'default' => '#F59E0B', // Amber accent
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_background_color',
            array(
                'type' => 'string',
                'default' => '#0F0F23', // Very dark background
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_text_color',
            array(
                'type' => 'string',
                'default' => '#F9FAFB', // Very light text
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        // Cart Customization Settings - Add them to the existing colors group for better organization
        register_setting(
            'menu_popup_colors_group',
            'menu_popup_cart_background_color',
            array(
                'type' => 'string',
                'default' => '#161235', // Same as secondary color
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_cart_border_color',
            array(
                'type' => 'string',
                'default' => '#5755f7', // Same as primary color
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_cart_text_color',
            array(
                'type' => 'string',
                'default' => '#F9FAFB', // Same as text color
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_cart_price_color',
            array(
                'type' => 'string',
                'default' => '#F59E0B', // Same as accent color
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_cart_total_color',
            array(
                'type' => 'string',
                'default' => '#A855F7', // Same as primary color
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_cart_icon_color',
            array(
                'type' => 'string',
                'default' => '#F9FAFB', // Light color for cart icons
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_cart_icon_size',
            array(
                'type' => 'string',
                'default' => '20',
                'sanitize_callback' => array($this, 'sanitize_cart_icon_size')
            )
        );

        // Order Confirmation Modal Colors
        register_setting(
            'menu_popup_colors_group',
            'menu_popup_order_modal_bg_color',
            array(
                'type' => 'string',
                'default' => '#1F2937', // Same as secondary color
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_order_modal_border_color',
            array(
                'type' => 'string',
                'default' => '#A855F7', // Same as primary color
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_order_modal_text_color',
            array(
                'type' => 'string',
                'default' => '#F9FAFB', // Same as text color
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_order_modal_accent_color',
            array(
                'type' => 'string',
                'default' => '#F59E0B', // Same as accent color
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_order_modal_overlay_color',
            array(
                'type' => 'string',
                'default' => 'rgba(0, 0, 0, 0.7)', // Semi-transparent black
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_cart_toggle_icon_size',
            array(
                'type' => 'string',
                'default' => '20',
                'sanitize_callback' => array($this, 'sanitize_cart_icon_size')
            )
        );

        // Hero Section Settings
        register_setting(
            'menu_popup_colors_group',
            'menu_popup_hero_title',
            array(
                'type' => 'string',
                'default' => 'Culinary<br>Excellence',
                'sanitize_callback' => 'wp_kses_post'
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_hero_subtitle',
            array(
                'type' => 'string',
                'default' => 'Click any dish to watch our chefs prepare it with passion and precision',
                'sanitize_callback' => 'wp_kses_post'
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_hero_bg_color',
            array(
                'type' => 'string',
                'default' => '#0F0F23', // Same as background color
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_hero_title_color',
            array(
                'type' => 'string',
                'default' => '#F9FAFB', // Same as text color
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_hero_subtitle_color',
            array(
                'type' => 'string',
                'default' => '#F9FAFB', // Same as text color, slightly transparent
                'sanitize_callback' => array($this, 'sanitize_color_value')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_hero_title_font_size',
            array(
                'type' => 'string',
                'default' => '6rem', // Same as default CSS
                'sanitize_callback' => array($this, 'sanitize_font_size')
            )
        );

        register_setting(
            'menu_popup_colors_group',
            'menu_popup_hero_subtitle_font_size',
            array(
                'type' => 'string',
                'default' => '1.125rem', // Same as default CSS
                'sanitize_callback' => array($this, 'sanitize_font_size')
            )
        );

        add_settings_section(
            'menu_popup_cart_settings',
            __('Cart Customization', 'menu-popup'),
            array($this, 'cart_settings_section_callback'),
            'menu_popup_colors_group'
        );

        add_settings_field(
            'menu_popup_cart_background_color',
            __('Cart Background Color', 'menu-popup'),
            array($this, 'cart_background_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_cart_settings'
        );

        add_settings_field(
            'menu_popup_cart_border_color',
            __('Cart Border Color', 'menu-popup'),
            array($this, 'cart_border_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_cart_settings'
        );

        add_settings_field(
            'menu_popup_cart_text_color',
            __('Cart Text Color', 'menu-popup'),
            array($this, 'cart_text_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_cart_settings'
        );

        add_settings_field(
            'menu_popup_cart_price_color',
            __('Cart Price Color', 'menu-popup'),
            array($this, 'cart_price_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_cart_settings'
        );

        add_settings_field(
            'menu_popup_cart_total_color',
            __('Cart Total Color', 'menu-popup'),
            array($this, 'cart_total_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_cart_settings'
        );

        add_settings_field(
            'menu_popup_cart_icon_color',
            __('Cart Icon Color', 'menu-popup'),
            array($this, 'cart_icon_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_cart_settings'
        );

        add_settings_field(
            'menu_popup_cart_icon_size',
            __('Cart Icon Size', 'menu-popup'),
            array($this, 'cart_icon_size_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_cart_settings'
        );

        add_settings_field(
            'menu_popup_cart_toggle_icon_size',
            __('Cart Toggle Icon Size', 'menu-popup'),
            array($this, 'cart_toggle_icon_size_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_cart_settings'
        );

        // Order Confirmation Modal Colors Section
        add_settings_section(
            'menu_popup_order_modal_settings',
            __('Order Confirmation Modal', 'menu-popup'),
            array($this, 'order_modal_settings_section_callback'),
            'menu_popup_colors_group'
        );

        add_settings_field(
            'menu_popup_order_modal_bg_color',
            __('Modal Background Color', 'menu-popup'),
            array($this, 'order_modal_bg_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_order_modal_settings'
        );

        add_settings_field(
            'menu_popup_order_modal_border_color',
            __('Modal Border Color', 'menu-popup'),
            array($this, 'order_modal_border_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_order_modal_settings'
        );

        add_settings_field(
            'menu_popup_order_modal_text_color',
            __('Modal Text Color', 'menu-popup'),
            array($this, 'order_modal_text_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_order_modal_settings'
        );

        add_settings_field(
            'menu_popup_order_modal_accent_color',
            __('Modal Accent Color', 'menu-popup'),
            array($this, 'order_modal_accent_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_order_modal_settings'
        );

        add_settings_field(
            'menu_popup_order_modal_overlay_color',
            __('Modal Overlay Color', 'menu-popup'),
            array($this, 'order_modal_overlay_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_order_modal_settings'
        );

        add_settings_section(
            'menu_popup_general_settings',
            __('Menu Display', 'menu-popup'),
            array($this, 'general_settings_section_callback'),
            'menu_popup_settings_group'
        );

        add_settings_field(
            'menu_popup_enable_videos',
            __('Enable Video Popups', 'menu-popup'),
            array($this, 'enable_videos_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_general_settings'
        );

        add_settings_field(
            'menu_popup_enable_video_autoplay',
            __('Enable Video Autoplay', 'menu-popup'),
            array($this, 'enable_video_autoplay_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_general_settings'
        );

        add_settings_field(
            'menu_popup_animation_speed',
            __('Animation Speed (ms)', 'menu-popup'),
            array($this, 'animation_speed_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_general_settings'
        );

        add_settings_field(
            'menu_popup_show_price_card',
            __('Show Price in Menu Cards', 'menu-popup'),
            array($this, 'show_price_card_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_general_settings'
        );

        add_settings_field(
            'menu_popup_show_price_modal',
            __('Show Price in Video Modal', 'menu-popup'),
            array($this, 'show_price_modal_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_general_settings'
        );

        add_settings_field(
            'menu_popup_show_order_online_modal',
            __('Show Order Online Button in Video Modal', 'menu-popup'),
            array($this, 'show_order_online_modal_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_general_settings'
        );

        add_settings_field(
            'menu_popup_show_whatsapp_modal',
            __('Show WhatsApp Order Button in Video Modal', 'menu-popup'),
            array($this, 'show_whatsapp_modal_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_general_settings'
        );

        add_settings_field(
            'menu_popup_layout_type',
            __('Display Layout', 'menu-popup'),
            array($this, 'layout_type_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_general_settings'
        );

        add_settings_field(
            'menu_popup_ordering_enabled',
            __('Enable Online Ordering', 'menu-popup'),
            array($this, 'ordering_enabled_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_ordering_settings'
        );

        add_settings_field(
            'menu_popup_whatsapp_enabled',
            __('Enable WhatsApp Ordering', 'menu-popup'),
            array($this, 'whatsapp_enabled_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_ordering_settings'
        );

        // Shopping cart is automatically enabled when WhatsApp or online ordering is enabled
        // Removed: menu_popup_cart_enabled setting field

        add_settings_field(
            'menu_popup_delivery_enabled',
            __('Enable Delivery Options', 'menu-popup'),
            array($this, 'delivery_enabled_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_delivery_settings'
        );

        add_settings_field(
            'menu_popup_google_maps_api_key',
            __('Google Maps API Key', 'menu-popup'),
            array($this, 'google_maps_api_key_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_delivery_settings'
        );

        add_settings_field(
            'menu_popup_delivery_mode',
            __('Delivery Zones Mode', 'menu-popup'),
            array($this, 'geo_zone_check_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_delivery_settings'
        );

        add_settings_field(
            'menu_popup_map_default_center',
            __('Default Map Center', 'menu-popup'),
            array($this, 'map_default_center_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_delivery_settings'
        );

        add_settings_section(
            'menu_popup_ordering_settings',
            __('Ordering Channels', 'menu-popup'),
            array($this, 'ordering_settings_section_callback'),
            'menu_popup_settings_group'
        );

        add_settings_section(
            'menu_popup_delivery_settings',
            __('Delivery', 'menu-popup'),
            array($this, 'delivery_settings_section_callback'),
            'menu_popup_settings_group'
        );

        // Customer Fields Settings Section
        add_settings_section(
            'menu_popup_customer_settings',
            __('Customer Information Fields', 'menu-popup'),
            array($this, 'customer_settings_section_callback'),
            'menu_popup_settings_group'
        );

        add_settings_field(
            'menu_popup_customer_name_required',
            __('Customer Name', 'menu-popup'),
            array($this, 'customer_name_required_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_customer_settings'
        );

        add_settings_field(
            'menu_popup_customer_phone_required',
            __('Phone Number', 'menu-popup'),
            array($this, 'customer_phone_required_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_customer_settings'
        );

        add_settings_field(
            'menu_popup_customer_email_enabled',
            __('Email Address', 'menu-popup'),
            array($this, 'customer_email_enabled_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_customer_settings'
        );

        add_settings_field(
            'menu_popup_customer_address_enabled',
            __('Delivery Address', 'menu-popup'),
            array($this, 'customer_address_enabled_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_customer_settings'
        );

        add_settings_field(
            'menu_popup_special_instructions_enabled',
            __('Special Instructions', 'menu-popup'),
            array($this, 'special_instructions_enabled_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_customer_settings'
        );

        // Business Settings Section
        add_settings_section(
            'menu_popup_business_settings',
            __('Business & Pricing Settings', 'menu-popup'),
            array($this, 'business_settings_section_callback'),
            'menu_popup_settings_group'
        );

        // Branch Selection Settings
        register_setting(
            'menu_popup_settings_group',
            'menu_popup_force_branch_selection',
            array(
                'type' => 'boolean',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_enable_cities',
            array(
                'type' => 'boolean',
                'default' => '1',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        add_settings_field(
            'menu_popup_restaurant_whatsapp',
            __('WhatsApp Number', 'menu-popup'),
            array($this, 'restaurant_whatsapp_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_business_settings'
        );

        add_settings_field(
            'menu_popup_currency_symbol',
            __('Currency Symbol', 'menu-popup'),
            array($this, 'currency_symbol_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_business_settings'
        );

        add_settings_field(
            'menu_popup_tax_rate',
            __('Tax Rate (%)', 'menu-popup'),
            array($this, 'tax_rate_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_business_settings'
        );

        add_settings_field(
            'menu_popup_minimum_order',
            __('Minimum Order Amount', 'menu-popup'),
            array($this, 'minimum_order_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_business_settings'
        );

        add_settings_section(
            'menu_popup_colors_settings',
            __('Color Customization', 'menu-popup'),
            array($this, 'colors_settings_section_callback'),
            'menu_popup_colors_group'
        );

        add_settings_field(
            'menu_popup_primary_color',
            __('Primary Color', 'menu-popup'),
            array($this, 'primary_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_colors_settings'
        );

        add_settings_field(
            'menu_popup_secondary_color',
            __('Secondary Color', 'menu-popup'),
            array($this, 'secondary_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_colors_settings'
        );

        add_settings_field(
            'menu_popup_accent_color',
            __('Accent Color', 'menu-popup'),
            array($this, 'accent_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_colors_settings'
        );

        add_settings_field(
            'menu_popup_background_color',
            __('Background Color', 'menu-popup'),
            array($this, 'background_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_colors_settings'
        );

        add_settings_field(
            'menu_popup_text_color',
            __('Text Color', 'menu-popup'),
            array($this, 'text_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_colors_settings'
        );


        // Hero Section Settings
        // Branch Selection Settings Section
        add_settings_section(
            'menu_popup_branch_selection_settings',
            __('Branch Selection Settings', 'menu-popup'),
            array($this, 'branch_selection_settings_section_callback'),
            'menu_popup_settings_group'
        );

        add_settings_field(
            'menu_popup_enable_cities',
            __('Enable City/Area Selection', 'menu-popup'),
            array($this, 'enable_cities_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_branch_selection_settings'
        );

        add_settings_field(
            'menu_popup_force_branch_selection',
            __('Force Branch Selection', 'menu-popup'),
            array($this, 'force_branch_selection_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_branch_selection_settings'
        );

        add_settings_section(
            'menu_popup_hero_settings',
            __('Hero Section Customization', 'menu-popup'),
            array($this, 'hero_settings_section_callback'),
            'menu_popup_colors_group'
        );



        add_settings_field(
            'menu_popup_hero_title',
            __('Hero Title', 'menu-popup'),
            array($this, 'hero_title_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_hero_settings'
        );

        add_settings_field(
            'menu_popup_hero_subtitle',
            __('Hero Subtitle', 'menu-popup'),
            array($this, 'hero_subtitle_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_hero_settings'
        );

        add_settings_field(
            'menu_popup_hero_bg_color',
            __('Hero Background Color', 'menu-popup'),
            array($this, 'hero_bg_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_hero_settings'
        );

        add_settings_field(
            'menu_popup_hero_title_color',
            __('Hero Title Color', 'menu-popup'),
            array($this, 'hero_title_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_hero_settings'
        );

        add_settings_field(
            'menu_popup_hero_subtitle_color',
            __('Hero Subtitle Color', 'menu-popup'),
            array($this, 'hero_subtitle_color_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_hero_settings'
        );

        add_settings_field(
            'menu_popup_hero_title_font_size',
            __('Hero Title Font Size', 'menu-popup'),
            array($this, 'hero_title_font_size_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_hero_settings'
        );

        add_settings_field(
            'menu_popup_hero_subtitle_font_size',
            __('Hero Subtitle Font Size', 'menu-popup'),
            array($this, 'hero_subtitle_font_size_field_callback'),
            'menu_popup_colors_group',
            'menu_popup_hero_settings'
        );

        // Voice Features Settings
        register_setting(
            'menu_popup_settings_group',
            'menu_popup_voice_search_enabled',
            array(
                'type' => 'boolean',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        // External TTS Service Settings
        register_setting(
            'menu_popup_settings_group',
            'menu_popup_external_tts_enabled',
            array(
                'type' => 'boolean',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_google_tts_api_key',
            array(
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field'
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_google_stt_api_key',
            array(
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field'
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_tts_cache_enabled',
            array(
                'type' => 'boolean',
                'default' => '1',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_pronounce_on_hover_enabled',
            array(
                'type' => 'boolean',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_voice_hover_enabled_mobile',
            array(
                'type' => 'boolean',
                'default' => '0',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_voice_confirmation_enabled',
            array(
                'type' => 'boolean',
                'default' => '1',
                'sanitize_callback' => array($this, 'sanitize_boolean')
            )
        );

        // Voice Agent Selection Settings
        register_setting(
            'menu_popup_settings_group',
            'menu_popup_arabic_voice',
            array(
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field'
            )
        );

        register_setting(
            'menu_popup_settings_group',
            'menu_popup_english_voice',
            array(
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field'
            )
        );

        // Order Dashboard Real-Time Settings
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

        // Add Order Dashboard Real-Time Settings Section
        // Voice Features Settings Section
        add_settings_section(
            'menu_popup_voice_settings',
            __('Voice Features Settings', 'menu-popup'),
            array($this, 'voice_settings_section_callback'),
            'menu_popup_settings_group'
        );

        add_settings_field(
            'menu_popup_voice_search_enabled',
            __('Enable Voice Search', 'menu-popup'),
            array($this, 'voice_search_enabled_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_voice_settings'
        );

        add_settings_field(
            'menu_popup_pronounce_on_hover_enabled',
            __('Enable Pronunciation on Hover', 'menu-popup'),
            array($this, 'pronounce_on_hover_enabled_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_voice_settings'
        );

        add_settings_field(
            'menu_popup_voice_hover_enabled_mobile',
            __('Enable Hover Pronunciation on Mobile', 'menu-popup'),
            array($this, 'voice_hover_enabled_mobile_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_voice_settings'
        );

        add_settings_field(
            'menu_popup_voice_confirmation_enabled',
            __('Enable Confirmation Dialogs for Clear Voice Catches', 'menu-popup'),
            array($this, 'voice_confirmation_enabled_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_voice_settings'
        );

        add_settings_field(
            'menu_popup_external_tts_enabled',
            __('Enable External TTS Service', 'menu-popup'),
            array($this, 'external_tts_enabled_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_voice_settings'
        );

        add_settings_field(
            'menu_popup_google_tts_api_key',
            __('Google Cloud TTS API Key', 'menu-popup'),
            array($this, 'google_tts_api_key_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_voice_settings'
        );

        add_settings_field(
            'menu_popup_google_stt_api_key',
            __('Google Cloud Speech-to-Text API Key', 'menu-popup'),
            array($this, 'google_stt_api_key_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_voice_settings'
        );

        add_settings_field(
            'menu_popup_tts_cache_enabled',
            __('Enable Audio Caching', 'menu-popup'),
            array($this, 'tts_cache_enabled_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_voice_settings'
        );

        add_settings_field(
            'menu_popup_arabic_voice',
            __('Arabic Voice Agent', 'menu-popup'),
            array($this, 'arabic_voice_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_voice_settings'
        );

        add_settings_field(
            'menu_popup_english_voice',
            __('English Voice Agent', 'menu-popup'),
            array($this, 'english_voice_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_voice_settings'
        );

        add_settings_section(
            'menu_popup_order_dashboard_realtime_settings',
            __('Order Dashboard Real-Time Updates', 'menu-popup'),
            array($this, 'order_dashboard_realtime_settings_section_callback'),
            'menu_popup_settings_group'
        );

        add_settings_field(
            'menu_popup_order_dashboard_realtime_enabled',
            __('Enable Auto-Refresh', 'menu-popup'),
            array($this, 'order_dashboard_realtime_enabled_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_order_dashboard_realtime_settings'
        );

        add_settings_field(
            'menu_popup_order_dashboard_refresh_interval',
            __('Refresh Interval (seconds)', 'menu-popup'),
            array($this, 'order_dashboard_refresh_interval_field_callback'),
            'menu_popup_settings_group',
            'menu_popup_order_dashboard_realtime_settings'
        );

        // Migration submenu already added in add_migration_submenu method
    }

    public function sanitize_boolean($value) {
        return $value === '1' ? '1' : '0';
    }

    public function sanitize_layout_type($value) {
        $allowed_types = array('grid', 'list', 'masonry');
        return in_array($value, $allowed_types) ? $value : 'grid';
    }

    public function sanitize_whatsapp_number($value) {
        // Allow empty or phone number formats with +, spaces, dashes, parentheses
        if (empty($value)) {
            return '';
        }
        // Basic validation: allow international format +countrycode...
        if (preg_match('/^\+[1-9][0-9\s\-\(\)]+$/', trim($value))) {
            return sanitize_text_field($value);
        }
        return '';
    }

    public function sanitize_currency_symbol($value) {
        // Allow common currency symbols and short text
        $allowed_symbols = array('$', '€', '£', '¥', '₪', '₩', '₦', '₱', '₨', '₹', '₽', '₴', '₵', '₡', '₲', '₭', '₯', '₰', '₳', '₶', '₷', '₹', '₻', '₽', '₾', '₿', '₰', '₳', '₶', '₷');
        $value = trim($value);
        if (in_array($value, $allowed_symbols) || strlen($value) <= 5) {
            return sanitize_text_field($value);
        }
        return '$'; // default
    }

    public function sanitize_tax_rate($value) {
        $rate = floatval($value);
        return $rate >= 0 && $rate <= 50 ? $rate : 0;
    }

    public function sanitize_minimum_order($value) {
        $amount = floatval($value);
        return $amount >= 0 ? $amount : 0;
    }

    public function sanitize_animation_speed($value) {
        $value = intval($value);
        return $value >= 100 && $value <= 1000 ? $value : 300;
    }

    public function sanitize_color_value($value) {
        // Allow empty values (they will fall back to defaults)
        $value = trim($value);
        if (empty($value)) {
            return '';
        }

        // Allow 'transparent' keyword
        if (strtolower($value) === 'transparent') {
            return 'transparent';
        }

        // Validate hex color format (# followed by 3 or 6 hex digits - HTML color input compatible)
        if (preg_match('/^#[a-fA-F0-9]{3}$/', $value) || preg_match('/^#[a-fA-F0-9]{6}$/', $value)) {
            return $value;
        }

        // Validate rgba() format
        if (preg_match('/^rgba?\([^)]+\)$/', $value)) {
            return $value;
        }

        // Validate hsl() format
        if (preg_match('/^hsl\([^)]+\)$/', $value)) {
            return $value;
        }

        // Validate hsla() format
        if (preg_match('/^hsla\([^)]+\)$/', $value)) {
            return $value;
        }

        // If invalid format, return empty string (will use default)
        return '';
    }

    public function sanitize_font_size($value) {
        $value = trim($value);

        // Allow empty values (will use defaults)
        if (empty($value)) {
            return '';
        }

        // Allow percentage values (e.g., 120%)
        if (preg_match('/^\d+(\.\d+)?%$/', $value)) {
            $percentage = floatval(trim($value, '%'));
            // Reasonable percentage range
            if ($percentage >= 50 && $percentage <= 500) {
                return $value;
            }
        }

        // Allow px values (e.g., 32px)
        if (preg_match('/^\d+(\.\d+)?px$/', $value)) {
            $px = floatval(trim($value, 'px'));
            // Reasonable px range for text
            if ($px >= 10 && $px <= 200) {
                return $value;
            }
        }

        // Allow rem values (e.g., 3rem, 1.5rem)
        if (preg_match('/^\d+(\.\d+)?rem?$/', $value)) {
            return $value; // REM values are typically safe and in user control
        }

        // Allow em values (e.g., 2.5em)
        if (preg_match('/^\d+(\.\d+)?em?$/', $value)) {
            return $value; // EM values are also safe
        }

        // Allow vh values (e.g., 10vh)
        if (preg_match('/^\d+(\.\d+)?vh$/', $value)) {
            return $value; // VH for responsive text
        }

        // If invalid format, return empty string (will use default)
        return '';
    }

    public function sanitize_cart_icon_size($value) {
        $size = intval($value);
        return $size >= 12 && $size <= 100 ? $size : 20; // Default to 20px
    }

    public function sanitize_refresh_interval($value) {
        $interval = intval($value);
        return $interval >= 30 && $interval <= 600 ? $interval : 60; // Default to 60 seconds, range 30-600
    }

    // Order Dashboard Real-Time Settings Section Callback
    public function order_dashboard_realtime_settings_section_callback() {
        echo '<p>' . __('Configure real-time update settings for the order dashboard to automatically refresh with new orders.', 'menu-popup') . '</p>';
        echo '<p class="description">' . __('These settings allow restaurant owners to see new orders instantly without manual page refresh.', 'menu-popup') . '</p>';
    }

    // Order Dashboard Real-Time Enabled Field Callback
    public function order_dashboard_realtime_enabled_field_callback() {
        $value = get_option('menu_popup_order_dashboard_realtime_enabled', '0');
        ?>
        <label for="menu_popup_order_dashboard_realtime_enabled">
            <input type="checkbox" id="menu_popup_order_dashboard_realtime_enabled" name="menu_popup_order_dashboard_realtime_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable automatic refresh of the order dashboard', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, the order dashboard will automatically refresh to show new orders and status changes.', 'menu-popup'); ?>
        </p>
        <?php
    }

    // Order Dashboard Refresh Interval Field Callback
    public function order_dashboard_refresh_interval_field_callback() {
        $value = get_option('menu_popup_order_dashboard_refresh_interval', '60');
        ?>
        <input type="number" id="menu_popup_order_dashboard_refresh_interval" name="menu_popup_order_dashboard_refresh_interval"
               value="<?php echo esc_attr($value); ?>" min="30" max="600" step="10" />
        <span><?php _e('seconds', 'menu-popup'); ?></span>
        <p class="description">
            <?php _e('How often to check for new orders and updates (30-600 seconds).', 'menu-popup'); ?>
        </p>
        <?php
    }





    public function colors_settings_section_callback() {
        echo '<p>' . __('Customize the colors of your menu display to match your brand.', 'menu-popup') . '</p>';
        echo '<p class="description">' . __('Changes will apply immediately when you save and refresh the frontend page.', 'menu-popup') . '</p>';
    }

    public function primary_color_field_callback() {
        $value = get_option('menu_popup_primary_color', '#A855F7');
        ?>
        <input type="color" id="menu_popup_primary_color" name="menu_popup_primary_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_primary_color_text').value = this.value;" />
        <input type="text" id="menu_popup_primary_color_text" name="menu_popup_primary_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_primary_color').value = this.value;" />
        <p class="description">
            <?php _e('Primary color used for buttons, accents, and interactive elements.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function secondary_color_field_callback() {
        $value = get_option('menu_popup_secondary_color', '#1F2937');
        ?>
        <input type="color" id="menu_popup_secondary_color" name="menu_popup_secondary_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_secondary_color_text').value = this.value;" />
        <input type="text" id="menu_popup_secondary_color_text" name="menu_popup_secondary_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_secondary_color').value = this.value;" />
        <p class="description">
            <?php _e('Secondary color used for backgrounds and secondary elements. Supports hex (#1F2937), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function accent_color_field_callback() {
        $value = get_option('menu_popup_accent_color', '#F59E0B');
        ?>
        <input type="color" id="menu_popup_accent_color" name="menu_popup_accent_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_accent_color_text').value = this.value;" />
        <input type="text" id="menu_popup_accent_color_text" name="menu_popup_accent_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_accent_color').value = this.value;" />
        <p class="description">
            <?php _e('Accent color used for highlights and special elements. Supports hex (#F59E0B), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function background_color_field_callback() {
        $value = get_option('menu_popup_background_color', '#0F0F23');
        ?>
        <input type="color" id="menu_popup_background_color" name="menu_popup_background_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_background_color_text').value = this.value;" />
        <input type="text" id="menu_popup_background_color_text" name="menu_popup_background_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_background_color').value = this.value;" />
        <p class="description">
            <?php _e('Main background color of the menu page. Supports hex (#0F0F23), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function text_color_field_callback() {
        $value = get_option('menu_popup_text_color', '#F9FAFB');
        ?>
        <input type="color" id="menu_popup_text_color" name="menu_popup_text_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_text_color_text').value = this.value;" />
        <input type="text" id="menu_popup_text_color_text" name="menu_popup_text_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_text_color').value = this.value;" />
        <p class="description">
            <?php _e('Primary text color for menu content. Supports hex (#F9FAFB), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function ordering_settings_section_callback() {
        echo '<p>' . esc_html__( 'Choose how customers can send orders.', 'menu-popup' ) . '</p>';
    }

    public function delivery_settings_section_callback() {
        echo '<p>' . esc_html__( 'Turn delivery on or off and choose how delivery zones work. When delivery is off, customers can only pick up.', 'menu-popup' ) . '</p>';
    }

    /**
     * Which settings tab each settings section belongs to.
     */
    private function settings_section_tabs() {
        return array(
            'menu_popup_business_settings'                => 'general',
            'menu_popup_branch_selection_settings'        => 'general',
            'menu_popup_general_settings'                 => 'display',
            'menu_popup_ordering_settings'                => 'ordering',
            'menu_popup_customer_settings'                => 'ordering',
            'menu_popup_order_dashboard_realtime_settings' => 'ordering',
            'menu_popup_delivery_settings'                => 'delivery',
            'menu_popup_voice_settings'                   => 'voice',
            'menu_popup_colors_settings'                  => 'appearance',
            'menu_popup_hero_settings'                    => 'appearance',
            'menu_popup_cart_settings'                    => 'appearance',
            'menu_popup_order_modal_settings'             => 'appearance',
        );
    }

    /**
     * Same output as do_settings_sections(), with every section wrapped in a
     * tab panel. All fields stay inside the form, so saving one tab never
     * clears the options of another.
     */
    private function render_tabbed_sections( $page, $default_tab ) {
        global $wp_settings_sections, $wp_settings_fields;

        if ( empty( $wp_settings_sections[ $page ] ) ) {
            return;
        }

        $tabs = $this->settings_section_tabs();

        foreach ( (array) $wp_settings_sections[ $page ] as $section ) {
            $tab = isset( $tabs[ $section['id'] ] ) ? $tabs[ $section['id'] ] : $default_tab;

            echo '<div class="mp-settings-panel" data-tab="' . esc_attr( $tab ) . '" id="mp-section-' . esc_attr( $section['id'] ) . '">';

            if ( $section['title'] ) {
                echo '<h2>' . esc_html( $section['title'] ) . '</h2>';
            }
            if ( $section['callback'] ) {
                call_user_func( $section['callback'], $section );
            }
            if ( isset( $wp_settings_fields[ $page ][ $section['id'] ] ) ) {
                echo '<table class="form-table" role="presentation">';
                do_settings_fields( $page, $section['id'] );
                echo '</table>';
            }

            echo '</div>';
        }
    }

    public function general_settings_section_callback() {
        echo '<p>' . __('Configure the general settings for your menu display.', 'menu-popup') . '</p>';
    }

    public function enable_videos_field_callback() {
        $value = get_option('menu_popup_enable_videos', '1');
        ?>
        <label for="menu_popup_enable_videos">
            <input type="checkbox" id="menu_popup_enable_videos" name="menu_popup_enable_videos" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable video popup functionality when users click on menu items', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When disabled, menu items will not show video popups even if video URLs are provided.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function enable_video_autoplay_field_callback() {
        $value = get_option('menu_popup_enable_video_autoplay', '0');
        ?>
        <label for="menu_popup_enable_video_autoplay">
            <input type="checkbox" id="menu_popup_enable_video_autoplay" name="menu_popup_enable_video_autoplay" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable automatic video playback when videos open', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, videos will automatically start playing when the popup modal opens. Note: Autoplay may be blocked by browsers depending on user interaction.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function animation_speed_field_callback() {
        $value = get_option('menu_popup_animation_speed', '300');
        ?>
        <input type="number" id="menu_popup_animation_speed" name="menu_popup_animation_speed"
               value="<?php echo esc_attr($value); ?>" min="100" max="1000" step="50" />
        <p class="description">
            <?php _e('Animation speed in milliseconds for hover effects and transitions (100-1000ms).', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function show_price_card_field_callback() {
        $value = get_option('menu_popup_show_price_card', '1');
        ?>
        <label for="menu_popup_show_price_card">
            <input type="checkbox" id="menu_popup_show_price_card" name="menu_popup_show_price_card" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Display price information (size variations or single price) in menu item cards', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When unchecked, menu item cards will not show any pricing information.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function show_price_modal_field_callback() {
        $value = get_option('menu_popup_show_price_modal', '1');
        ?>
        <label for="menu_popup_show_price_modal">
            <input type="checkbox" id="menu_popup_show_price_modal" name="menu_popup_show_price_modal" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Display price information in video popup modal', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When unchecked, the video popup will not show pricing details.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function show_order_online_modal_field_callback() {
        $value = get_option('menu_popup_show_order_online_modal', '1');
        ?>
        <label for="menu_popup_show_order_online_modal">
            <input type="checkbox" id="menu_popup_show_order_online_modal" name="menu_popup_show_order_online_modal" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Display Order Online button in video popup modal', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When unchecked, the Order Online button will not appear in the video popup modal.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function show_whatsapp_modal_field_callback() {
        $value = get_option('menu_popup_show_whatsapp_modal', '1');
        ?>
        <label for="menu_popup_show_whatsapp_modal">
            <input type="checkbox" id="menu_popup_show_whatsapp_modal" name="menu_popup_show_whatsapp_modal" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Display WhatsApp Order button in video popup modal', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When unchecked, the WhatsApp Order button will not appear in the video popup modal.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function layout_type_field_callback() {
        $value = get_option('menu_popup_layout_type', 'grid');
        ?>
        <select id="menu_popup_layout_type" name="menu_popup_layout_type">
            <option value="grid" <?php selected($value, 'grid'); ?>><?php _e('Grid (Cards)', 'menu-popup'); ?></option>
            <option value="list" <?php selected($value, 'list'); ?>><?php _e('List (Horizontal)', 'menu-popup'); ?></option>
            <option value="masonry" <?php selected($value, 'masonry'); ?>><?php _e('Masonry (Cards)', 'menu-popup'); ?></option>
        </select>
        <p class="description">
            <?php _e('Choose the display layout for menu items: Grid shows items in a card-based grid, List displays items horizontally as a list, Masonry uses Pinterest-style card layout.', 'menu-popup'); ?>
        </p>
        <?php
    }

    // E-Commerce Callbacks
    public function ecommerce_settings_section_callback() {
        echo '<p>' . __('Configure e-commerce and ordering features for your menu.', 'menu-popup') . '</p>';
        echo '<p class="description">' . __('Enable shopping features to allow customers to place orders directly from your menu.', 'menu-popup') . '</p>';
    }

    public function ordering_enabled_field_callback() {
        $value = get_option('menu_popup_ordering_enabled', '0');
        ?>
        <label for="menu_popup_ordering_enabled">
            <input type="checkbox" id="menu_popup_ordering_enabled" name="menu_popup_ordering_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable online ordering system', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, customers can add items to cart and place orders through the menu.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function whatsapp_enabled_field_callback() {
        $value = get_option('menu_popup_whatsapp_enabled', '0');
        ?>
        <label for="menu_popup_whatsapp_enabled">
            <input type="checkbox" id="menu_popup_whatsapp_enabled" name="menu_popup_whatsapp_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable WhatsApp ordering', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, order buttons will redirect to WhatsApp with order details.', 'menu-popup'); ?>
        </p>
        <?php
    }

    // Shopping cart callback removed - cart is now automatically enabled based on ordering settings

    public function delivery_enabled_field_callback() {
        $value = get_option('menu_popup_delivery_enabled', '0');
        ?>
        <label for="menu_popup_delivery_enabled">
            <input type="checkbox" id="menu_popup_delivery_enabled" name="menu_popup_delivery_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable delivery/pickup options', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, customers can choose between delivery and pickup options.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function google_maps_api_key_field_callback() {
        $value = get_option('menu_popup_google_maps_api_key', '');
        ?>
        <input type="text" id="menu_popup_google_maps_api_key" name="menu_popup_google_maps_api_key" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description">
            <?php _e('Used by the Delivery Zones editor and by the map customers see when ordering delivery. Enable Maps JavaScript API and Geocoding API (and Places API for address suggestions), and restrict the key to your website address.', 'menu-popup'); ?>
            <a href="https://console.cloud.google.com/google/maps-apis/credentials" target="_blank"><?php _e('Get an API key here', 'menu-popup'); ?></a>. 
            <?php _e('(Ensure the Maps JavaScript API is enabled)', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function geo_zone_check_field_callback() {
        $value = get_option('menu_popup_delivery_mode', 'manual') === 'map' ? 'map' : 'manual';
        ?>
        <label style="display:block;margin-bottom:6px;">
            <input type="radio" name="menu_popup_delivery_mode" value="manual" <?php checked($value, 'manual'); ?> />
            <strong><?php _e('Manual zones', 'menu-popup'); ?></strong>
            &mdash; <?php _e('You add zones by name with a fixed fee, and the customer picks one from a list. No map needed.', 'menu-popup'); ?>
        </label>
        <label style="display:block;">
            <input type="radio" name="menu_popup_delivery_mode" value="map" <?php checked($value, 'map'); ?> />
            <strong><?php _e('By map', 'menu-popup'); ?></strong>
            &mdash; <?php _e('The customer drops a pin on a map. The branch, fee and minimum order come from the zones you drew. Needs a Google Maps key.', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('Only one mode works at a time. Each mode keeps its own zones. If "Enable Delivery Options" above is off, there is no delivery at all.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function sanitize_delivery_mode($value) {
        return $value === 'map' ? 'map' : 'manual';
    }

    public function map_default_center_field_callback() {
        $lat = get_option('menu_popup_map_default_lat', '30.0444');
        $lng = get_option('menu_popup_map_default_lng', '31.2357');
        ?>
        <input type="number" step="any" name="menu_popup_map_default_lat" value="<?php echo esc_attr($lat); ?>" class="small-text" style="width:120px" placeholder="30.0444" />
        <input type="number" step="any" name="menu_popup_map_default_lng" value="<?php echo esc_attr($lng); ?>" class="small-text" style="width:120px" placeholder="31.2357" />
        <p class="description">
            <?php _e('Where the map opens for a customer who has not chosen a location before (latitude, longitude). Default is Cairo.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function sanitize_map_coordinate_lat($value) {
        return is_numeric($value) && $value >= -90 && $value <= 90 ? (string) (float) $value : '30.0444';
    }

    public function sanitize_map_coordinate_lng($value) {
        return is_numeric($value) && $value >= -180 && $value <= 180 ? (string) (float) $value : '31.2357';
    }

    // Customer Fields Callbacks
    public function customer_settings_section_callback() {
        echo '<p>' . __('Configure which customer information fields to collect during ordering.', 'menu-popup') . '</p>';
        echo '<p class="description">' . __('Choose which fields are required or optional for customer information collection.', 'menu-popup') . '</p>';
    }

    public function customer_name_required_field_callback() {
        $value = get_option('menu_popup_customer_name_required', '1');
        ?>
        <label for="menu_popup_customer_name_required">
            <input type="checkbox" id="menu_popup_customer_name_required" name="menu_popup_customer_name_required" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Make customer name a required field', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When checked, customers must provide their name to place an order.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function customer_phone_required_field_callback() {
        $value = get_option('menu_popup_customer_phone_required', '1');
        ?>
        <label for="menu_popup_customer_phone_required">
            <input type="checkbox" id="menu_popup_customer_phone_required" name="menu_popup_customer_phone_required" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Make phone number a required field', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When checked, customers must provide their phone number to place an order.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function customer_email_enabled_field_callback() {
        $value = get_option('menu_popup_customer_email_enabled', '0');
        ?>
        <label for="menu_popup_customer_email_enabled">
            <input type="checkbox" id="menu_popup_customer_email_enabled" name="menu_popup_customer_email_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable email address field', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, customers can optionally provide their email address.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function customer_address_enabled_field_callback() {
        $value = get_option('menu_popup_customer_address_enabled', '0');
        ?>
        <label for="menu_popup_customer_address_enabled">
            <input type="checkbox" id="menu_popup_customer_address_enabled" name="menu_popup_customer_address_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable delivery address field', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, customers can provide their delivery address for orders.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function special_instructions_enabled_field_callback() {
        $value = get_option('menu_popup_special_instructions_enabled', '1');
        ?>
        <label for="menu_popup_special_instructions_enabled">
            <input type="checkbox" id="menu_popup_special_instructions_enabled" name="menu_popup_special_instructions_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable special instructions field', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, customers can add special instructions for their orders.', 'menu-popup'); ?>
        </p>
        <?php
    }

    // Cart Customization Settings Callbacks
    public function cart_settings_section_callback() {
        echo '<p>' . __('Customize the appearance of the shopping cart (flyout and icon).', 'menu-popup') . '</p>';
        echo '<p class="description">' . __('These settings affect the cart background, borders, text, and icon colors and sizes.', 'menu-popup') . '</p>';
    }

    public function cart_background_color_field_callback() {
        $value = get_option('menu_popup_cart_background_color', '#161235');
        ?>
        <input type="color" id="menu_popup_cart_background_color" name="menu_popup_cart_background_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_cart_background_color_text').value = this.value;" />
        <input type="text" id="menu_popup_cart_background_color_text" name="menu_popup_cart_background_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_cart_background_color').value = this.value;" />
        <p class="description">
            <?php _e('Background color of the cart flyout popup. Supports hex (#161235), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function cart_border_color_field_callback() {
        $value = get_option('menu_popup_cart_border_color', '#5755f7');
        ?>
        <input type="color" id="menu_popup_cart_border_color" name="menu_popup_cart_border_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_cart_border_color_text').value = this.value;" />
        <input type="text" id="menu_popup_cart_border_color_text" name="menu_popup_cart_border_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_cart_border_color').value = this.value;" />
        <p class="description">
            <?php _e('Border color of the cart flyout popup. Supports hex (#5755f7), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function cart_text_color_field_callback() {
        $value = get_option('menu_popup_cart_text_color', '#F9FAFB');
        ?>
        <input type="color" id="menu_popup_cart_text_color" name="menu_popup_cart_text_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_cart_text_color_text').value = this.value;" />
        <input type="text" id="menu_popup_cart_text_color_text" name="menu_popup_cart_text_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_cart_text_color').value = this.value;" />
        <p class="description">
            <?php _e('Text color inside the cart flyout popup. Supports hex (#F9FAFB), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function cart_price_color_field_callback() {
        $value = get_option('menu_popup_cart_price_color', '#F59E0B');
        ?>
        <input type="color" id="menu_popup_cart_price_color" name="menu_popup_cart_price_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_cart_price_color_text').value = this.value;" />
        <input type="text" id="menu_popup_cart_price_color_text" name="menu_popup_cart_price_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_cart_price_color').value = this.value;" />
        <p class="description">
            <?php _e('Color of prices in the cart flyout popup. Supports hex (#F59E0B), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function cart_total_color_field_callback() {
        $value = get_option('menu_popup_cart_total_color', '#A855F7');
        ?>
        <input type="color" id="menu_popup_cart_total_color" name="menu_popup_cart_total_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_cart_total_color_text').value = this.value;" />
        <input type="text" id="menu_popup_cart_total_color_text" name="menu_popup_cart_total_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_cart_total_color').value = this.value;" />
        <p class="description">
            <?php _e('Color of the total amount in the cart flyout popup. Supports hex (#A855F7), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function cart_icon_color_field_callback() {
        $value = get_option('menu_popup_cart_icon_color', '#F9FAFB');
        ?>
        <input type="color" id="menu_popup_cart_icon_color" name="menu_popup_cart_icon_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_cart_icon_color_text').value = this.value;" />
        <input type="text" id="menu_popup_cart_icon_color_text" name="menu_popup_cart_icon_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_cart_icon_color').value = this.value;" />
        <p class="description">
            <?php _e('Color of cart icons (shopping cart, plus, minus symbols). Supports hex (#F9FAFB), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function cart_icon_size_field_callback() {
        $value = get_option('menu_popup_cart_icon_size', '20');
        ?>
        <input type="number" id="menu_popup_cart_icon_size" name="menu_popup_cart_icon_size"
               value="<?php echo esc_attr($value); ?>" min="12" max="100" step="1" />
        <span>px</span>
        <p class="description">
            <?php _e('Size of cart icons in pixels (12-100px).', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function cart_toggle_icon_size_field_callback() {
        $value = get_option('menu_popup_cart_toggle_icon_size', '20');
        ?>
        <input type="number" id="menu_popup_cart_toggle_icon_size" name="menu_popup_cart_toggle_icon_size"
               value="<?php echo esc_attr($value); ?>" min="12" max="100" step="1" />
        <span>px</span>
        <p class="description">
            <?php _e('Size of the cart toggle button icon in pixels (12-100px).', 'menu-popup'); ?>
        </p>
        <?php
    }

    // Order Confirmation Modal Color Settings Callbacks
    public function order_modal_settings_section_callback() {
        echo '<p>' . __('Customize the appearance of the order confirmation modal popup.', 'menu-popup') . '</p>';
        echo '<p class="description">' . __('These settings affect the modal background, borders, text colors, and overlay.', 'menu-popup') . '</p>';
    }

    public function order_modal_bg_color_field_callback() {
        $value = get_option('menu_popup_order_modal_bg_color', '#1F2937');
        ?>
        <input type="color" id="menu_popup_order_modal_bg_color" name="menu_popup_order_modal_bg_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_order_modal_bg_color_text').value = this.value;" />
        <input type="text" id="menu_popup_order_modal_bg_color_text" name="menu_popup_order_modal_bg_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_order_modal_bg_color').value = this.value;" />
        <p class="description">
            <?php _e('Background color of the order confirmation modal. Supports hex (#1F2937), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function order_modal_border_color_field_callback() {
        $value = get_option('menu_popup_order_modal_border_color', '#A855F7');
        ?>
        <input type="color" id="menu_popup_order_modal_border_color" name="menu_popup_order_modal_border_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_order_modal_border_color_text').value = this.value;" />
        <input type="text" id="menu_popup_order_modal_border_color_text" name="menu_popup_order_modal_border_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_order_modal_border_color').value = this.value;" />
        <p class="description">
            <?php _e('Border color of the order confirmation modal. Supports hex (#A855F7), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function order_modal_text_color_field_callback() {
        $value = get_option('menu_popup_order_modal_text_color', '#F9FAFB');
        ?>
        <input type="color" id="menu_popup_order_modal_text_color" name="menu_popup_order_modal_text_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_order_modal_text_color_text').value = this.value;" />
        <input type="text" id="menu_popup_order_modal_text_color_text" name="menu_popup_order_modal_text_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_order_modal_text_color').value = this.value;" />
        <p class="description">
            <?php _e('Primary text color inside the order confirmation modal. Supports hex (#F9FAFB), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function order_modal_accent_color_field_callback() {
        $value = get_option('menu_popup_order_modal_accent_color', '#F59E0B');
        ?>
        <input type="color" id="menu_popup_order_modal_accent_color" name="menu_popup_order_modal_accent_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_order_modal_accent_color_text').value = this.value;" />
        <input type="text" id="menu_popup_order_modal_accent_color_text" name="menu_popup_order_modal_accent_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_order_modal_accent_color').value = this.value;" />
        <p class="description">
            <?php _e('Accent color for highlights and important elements in the modal. Supports hex (#F59E0B), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function order_modal_overlay_color_field_callback() {
        $value = get_option('menu_popup_order_modal_overlay_color', 'rgba(0, 0, 0, 0.7)');
        ?>
        <input type="color" id="menu_popup_order_modal_overlay_color" name="menu_popup_order_modal_overlay_color" value="#000000" onchange="
            let opacity = <?php echo floatval(substr($value, strpos($value, ',') + 1, -1)) ?: 0.7; ?>;
            this.nextElementSibling.value = 'rgba(' + parseInt(this.value.slice(1, 3), 16) + ',' + parseInt(this.value.slice(3, 5), 16) + ',' + parseInt(this.value.slice(5, 7), 16) + ',' + '<?php echo substr($value, strpos($value, ',') + 1, -1); ?>';
        " />
        <input type="text" id="menu_popup_order_modal_overlay_color_text" name="menu_popup_order_modal_overlay_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 120px; border: 1px solid #ddd;" placeholder="rgba(0,0,0,0.7)" />
        <p class="description">
            <?php _e('Backdrop overlay color behind the modal (supports rgba for transparency, e.g., rgba(0,0,0,0.7)).', 'menu-popup'); ?>
        </p>
        <?php
    }

    // Business Settings Callbacks
    public function business_settings_section_callback() {
        echo '<p>' . __('Configure business settings including pricing, WhatsApp, and tax rates.', 'menu-popup') . '</p>';
        echo '<p class="description">' . __('These settings will be used for order calculations and customer communication.', 'menu-popup') . '</p>';
    }

    public function restaurant_whatsapp_field_callback() {
        $value = get_option('menu_popup_restaurant_whatsapp', '');
        ?>
        <input type="text" id="menu_popup_restaurant_whatsapp" name="menu_popup_restaurant_whatsapp"
               value="<?php echo esc_attr($value); ?>" placeholder="+1234567890" />
        <p class="description">
            <?php _e('Enter your restaurant WhatsApp number (with country code, e.g., +1234567890)', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function currency_symbol_field_callback() {
        $value = get_option('menu_popup_currency_symbol', '$');
        ?>
        <input type="text" id="menu_popup_currency_symbol" name="menu_popup_currency_symbol"
               value="<?php echo esc_attr($value); ?>" placeholder="$" maxlength="5" />
        <p class="description">
            <?php _e('Symbol to display for currency (e.g., $, €, £, ₪)', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function tax_rate_field_callback() {
        $value = get_option('menu_popup_tax_rate', '0');
        ?>
        <input type="number" id="menu_popup_tax_rate" name="menu_popup_tax_rate"
               value="<?php echo esc_attr($value); ?>" min="0" max="50" step="0.01" />
        <p class="description">
            <?php _e('Tax rate as percentage (0-50%, e.g., 8.25 for 8.25%)', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function minimum_order_field_callback() {
        $value = get_option('menu_popup_minimum_order', '0');
        ?>
        <input type="number" id="menu_popup_minimum_order" name="menu_popup_minimum_order"
               value="<?php echo esc_attr($value); ?>" min="0" step="0.01" />
        <p class="description">
            <?php _e('Minimum order amount before tax (0 for no minimum)', 'menu-popup'); ?>
        </p>
        <?php
    }

    // Hero Section Settings Callbacks
    public function hero_settings_section_callback() {
        echo '<p>' . __('Customize the hero section that appears at the top of your menu display.', 'menu-popup') . '</p>';
        echo '<p class="description">' . __('These settings control the title, subtitle, and colors of the hero section.', 'menu-popup') . '</p>';
    }

    public function hero_title_field_callback() {
        $value = get_option('menu_popup_hero_title', 'Culinary<br>Excellence');
        ?>
        <textarea id="menu_popup_hero_title" name="menu_popup_hero_title" rows="3" cols="50"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php _e('Hero section main title. HTML is allowed, including <br> tags for line breaks.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function hero_subtitle_field_callback() {
        $value = get_option('menu_popup_hero_subtitle', 'Click any dish to watch our chefs prepare it with passion and precision');
        ?>
        <textarea id="menu_popup_hero_subtitle" name="menu_popup_hero_subtitle" rows="3" cols="50"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php _e('Hero section subtitle text describing the interactive nature of your menu.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function hero_bg_color_field_callback() {
        $value = get_option('menu_popup_hero_bg_color', '#0F0F23');
        ?>
        <input type="color" id="menu_popup_hero_bg_color" name="menu_popup_hero_bg_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_hero_bg_color_text').value = this.value;" />
        <input type="text" id="menu_popup_hero_bg_color_text" name="menu_popup_hero_bg_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_hero_bg_color').value = this.value;" />
        <p class="description">
            <?php _e('Background color of the hero section. Supports hex (#0F0F23), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function hero_title_color_field_callback() {
        $value = get_option('menu_popup_hero_title_color', '#F9FAFB');
        ?>
        <input type="color" id="menu_popup_hero_title_color" name="menu_popup_hero_title_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_hero_title_color_text').value = this.value;" />
        <input type="text" id="menu_popup_hero_title_color_text" name="menu_popup_hero_title_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_hero_title_color').value = this.value;" />
        <p class="description">
            <?php _e('Color of the main title text in the hero section. Supports hex (#F9FAFB), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function hero_subtitle_color_field_callback() {
        $value = get_option('menu_popup_hero_subtitle_color', '#F9FAFB');
        ?>
        <input type="color" id="menu_popup_hero_subtitle_color" name="menu_popup_hero_subtitle_color" value="<?php echo esc_attr($value); ?>" oninput="document.getElementById('menu_popup_hero_subtitle_color_text').value = this.value;" />
        <input type="text" id="menu_popup_hero_subtitle_color_text" name="menu_popup_hero_subtitle_color" value="<?php echo esc_attr($value); ?>" style="margin-left: 10px; background: #fff; width: 100px; border: 1px solid #ddd;" oninput="document.getElementById('menu_popup_hero_subtitle_color').value = this.value;" />
        <p class="description">
            <?php _e('Color of the subtitle text in the hero section. Supports hex (#F9FAFB), rgba(), hsl(), or transparent.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function hero_title_font_size_field_callback() {
        $value = get_option('menu_popup_hero_title_font_size', '6rem');
        ?>
        <input type="text" id="menu_popup_hero_title_font_size" name="menu_popup_hero_title_font_size" value="<?php echo esc_attr($value); ?>" placeholder="6rem" style="background: #fff; width: 80px;" />
        <p class="description">
            <?php _e('Font size for the hero title. Examples: 6rem, 96px, 3.8rem, etc.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function hero_subtitle_font_size_field_callback() {
        $value = get_option('menu_popup_hero_subtitle_font_size', '1.125rem');
        ?>
        <input type="text" id="menu_popup_hero_subtitle_font_size" name="menu_popup_hero_subtitle_font_size" value="<?php echo esc_attr($value); ?>" placeholder="1.125rem" style="background: #fff; width: 80px;" />
        <p class="description">
            <?php _e('Font size for the hero subtitle. Examples: 1.125rem, 18px, 1.2rem, etc.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function dashboard_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get plugin statistics
        $total_menu_items = $this->get_total_menu_items();
        $total_categories = $this->get_total_categories();
        $recent_items = $this->get_recent_menu_items();
        ?>
        <div class="wrap">
            <h1><?php _e('Menu Pop-up Dashboard', 'menu-popup'); ?></h1>

            <div class="menu-popup-dashboard">
                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">🍽️</div>
                        <div class="stat-content">
                            <h3><?php echo $total_menu_items; ?></h3>
                            <p><?php _e('Total Menu Items', 'menu-popup'); ?></p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">🏷️</div>
                        <div class="stat-content">
                            <h3><?php echo $total_categories; ?></h3>
                            <p><?php _e('Categories', 'menu-popup'); ?></p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">▶️</div>
                        <div class="stat-content">
                            <h3><?php echo get_option('menu_popup_enable_videos', '1') == '1' ? __('Enabled', 'menu-popup') : __('Disabled', 'menu-popup'); ?></h3>
                            <p><?php _e('Video Popups', 'menu-popup'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <h2><?php _e('Quick Actions', 'menu-popup'); ?></h2>
                    <div class="action-buttons">
                        <a href="<?php echo admin_url('post-new.php?post_type=menu_item'); ?>" class="button button-primary">
                            <?php _e('Add New Menu Item', 'menu-popup'); ?>
                        </a>
                        <a href="<?php echo admin_url('edit.php?post_type=menu_item'); ?>" class="button">
                            <?php _e('All Menu Items', 'menu-popup'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=menu-popup-categories'); ?>" class="button">
                            <?php _e('Manage Categories', 'menu-popup'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=menu-popup-settings'); ?>" class="button">
                            <?php _e('Plugin Settings', 'menu-popup'); ?>
                        </a>
                    </div>
                </div>

                <!-- Recent Menu Items -->
                <div class="recent-items">
                    <h2><?php _e('Recent Menu Items', 'menu-popup'); ?></h2>
                    <?php if (!empty($recent_items)): ?>
                        <div class="recent-items-list">
                            <?php foreach ($recent_items as $item): ?>
                                <div class="recent-item">
                                    <div class="item-info">
                                        <h4><?php echo esc_html(get_post_meta($item->ID, '_menu_item_name', true)); ?></h4>
                                        <p><?php echo esc_html(get_post_meta($item->ID, '_menu_item_category', true)); ?></p>
                                    </div>
                                    <div class="item-actions">
                                        <a href="<?php echo get_edit_post_link($item->ID); ?>" class="button button-small">
                                            <?php _e('Edit', 'menu-popup'); ?>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p><?php _e('No menu items found. Create your first menu item to get started!', 'menu-popup'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Getting Started Guide -->
                <div class="getting-started">
                    <h2><?php _e('Getting Started', 'menu-popup'); ?></h2>
                    <ol>
                        <li><?php _e('Create menu items by editing posts and filling the "Menu Item Details" meta box', 'menu-popup'); ?></li>
                        <li><?php _e('Organize your menu items using categories', 'menu-popup'); ?></li>
                        <li><?php _e('Use the shortcode', 'menu-popup'); ?> <code>[menu-popup]</code> <?php _e('to display your menu', 'menu-popup'); ?></li>
                        <li><?php _e('Customize the settings to match your needs', 'menu-popup'); ?></li>
                    </ol>
                </div>
            </div>
        </div>
        <?php
    }

    public function categories_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Admin scripts are now enqueued globally in the main plugin file

        // Handle form submission (fallback for non-AJAX)
        if (isset($_POST['category_name']) && isset($_POST['category_slug'])) {
            $this->handle_category_form_submission();
        }

        $categories = $this->get_menu_categories();
        ?>
        <div class="wrap">
            <h1><?php _e('Menu Categories', 'menu-popup'); ?></h1>

            <div class="menu-categories-page">
                <!-- Add New Category Form -->
                <div class="add-category-form">
                    <h2><?php _e('Add New Category', 'menu-popup'); ?></h2>
                    <form id="add-category-form" method="post" action="<?php echo admin_url('admin.php?page=menu-popup-categories'); ?>">
                        <div id="category-form-messages"></div>
                        <table class="form-table">
                            <tr>
                                <th><label for="category_name"><?php _e('Name', 'menu-popup'); ?> <span class="required">*</span></label></th>
                                <td>
                                    <input type="text" id="category_name" name="category_name" required />
                                    <p class="description"><?php _e('The name is how it appears on your site.', 'menu-popup'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="category_slug"><?php _e('Slug', 'menu-popup'); ?></label></th>
                                <td>
                                    <input type="text" id="category_slug" name="category_slug" />
                                    <p class="description"><?php _e('The "slug" is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.', 'menu-popup'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="category_description"><?php _e('Description', 'menu-popup'); ?></label></th>
                                <td>
                                    <textarea id="category_description" name="category_description" rows="3" cols="50"></textarea>
                                    <p class="description"><?php _e('The description is not prominent by default; however, some themes may show it.', 'menu-popup'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="category_image"><?php _e('Category Image', 'menu-popup'); ?></label></th>
                                <td>
                                    <div class="category-image-upload">
                                        <input type="text" id="category_image" name="category_image" placeholder="https://example.com/image.jpg" />
                                        <button type="button" class="button" id="upload_category_image"><?php _e('Upload Image', 'menu-popup'); ?></button>
                                        <div id="category_image_preview"></div>
                                        <p class="description"><?php _e('Optional category image for visual identification.', 'menu-popup'); ?></p>
                                    </div>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" class="button button-primary" id="add-category-btn"><?php _e('Add New Category', 'menu-popup'); ?></button>
                            <button type="button" class="button" id="cancel-category-btn" style="display:none;"><?php _e('Cancel', 'menu-popup'); ?></button>
                        </p>
                    </form>
                </div>

                <!-- Categories List -->
                <div class="categories-list">
                    <h2><?php _e('Manage Categories', 'menu-popup'); ?></h2>
                    <?php if (!empty($categories)): ?>
                        <div class="categories-container" id="sortable-categories">
                            <?php foreach ($categories as $index => $category): ?>
                                <div class="category-item" data-id="<?php echo esc_attr($category['id']); ?>">
                                    <div class="category-header">
                                        <span class="category-name"><?php echo esc_html($category['name']); ?></span>
                                        <div class="category-actions">
                                            <button class="edit-category button button-small" data-id="<?php echo esc_attr($category['id']); ?>">
                                                <?php _e('Edit', 'menu-popup'); ?>
                                            </button>
                                            <button class="delete-category button button-small button-link-delete" data-id="<?php echo esc_attr($category['id']); ?>">
                                                <?php _e('Delete', 'menu-popup'); ?>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="category-meta">
                                        <span class="category-slug"><?php echo esc_html($category['slug']); ?></span>
                                        <?php if (!empty($category['description'])): ?>
                                            <span class="category-description"><?php echo esc_html($category['description']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">
                            <?php _e('Drag and drop categories to reorder them. The order will be reflected in the frontend menu.', 'menu-popup'); ?>
                        </p>
                    <?php else: ?>
                        <p><?php _e('No categories found. Add your first category to get started!', 'menu-popup'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php
    }

    public function menus_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle form submission (fallback for non-AJAX)
        if (isset($_POST['menu_name']) && isset($_POST['menu_slug'])) {
            $this->handle_menu_form_submission();
        }

        $menus = $this->get_menu_menus();
        $categories = $this->get_menu_categories();
        $branches = $this->get_all_branches(); // Get all branches

        // Enqueue jQuery UI sortable
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style('wp-jquery-ui-dialog');

        // Localize categories and menu data for menu editing
        wp_localize_script('menu-popup-admin', 'menuPopupCategories', $categories);
        wp_localize_script('menu-popup-admin', 'menuPopupMenus', $menus);
        
        // Get branches for JavaScript
        $branches_list = get_posts(array(
            'post_type' => 'menu_popup_branch',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ));
        $branches_data = array();
        foreach ($branches_list as $branch) {
            $branches_data[] = array(
                'id' => $branch->ID,
                'name' => $branch->post_title
            );
        }
        wp_localize_script('menu-popup-admin', 'menuPopupBranches', $branches_data);
        ?>
        <div class="wrap">
            <h1><?php _e('Menu Menus', 'menu-popup'); ?></h1>

            <div class="menu-menus-page">
                <!-- Add New Menu Form -->
                <div class="add-menu-form">
                    <h2><?php _e('Add New Menu', 'menu-popup'); ?></h2>
                    <form id="add-menu-form" method="post" action="<?php echo admin_url('admin.php?page=menu-popup-menus'); ?>">
                        <div id="menu-form-messages"></div>
                        <table class="form-table">
                            <tr>
                                <th><label for="menu_name"><?php _e('Name', 'menu-popup'); ?> <span class="required">*</span></label></th>
                                <td>
                                    <input type="text" id="menu_name" name="menu_name" required />
                                    <p class="description"><?php _e('The name is how it appears on your site.', 'menu-popup'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="menu_slug"><?php _e('Slug', 'menu-popup'); ?></label></th>
                                <td>
                                    <input type="text" id="menu_slug" name="menu_slug" />
                                    <p class="description"><?php _e('The "slug" is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.', 'menu-popup'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="menu_description"><?php _e('Description', 'menu-popup'); ?></label></th>
                                <td>
                                    <textarea id="menu_description" name="menu_description" rows="3" cols="50"></textarea>
                                    <p class="description"><?php _e('The description is not prominent by default.', 'menu-popup'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="menu_categories"><?php _e('Categories', 'menu-popup'); ?></label></th>
                                <td>
                                    <div class="menu-categories-selector">
                                        <?php if (!empty($categories)): ?>
                                            <?php foreach ($categories as $category): ?>
                                                <label class="category-checkbox">
                                                    <input type="checkbox" name="menu_categories[]" value="<?php echo esc_attr($category['slug']); ?>" />
                                                    <?php echo esc_html($category['name']); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="description"><?php _e('No categories available. Create categories first.', 'menu-popup'); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <p class="description"><?php _e('Select categories to include in this menu.', 'menu-popup'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="menu_branches"><?php _e('Branches', 'menu-popup'); ?></label></th>
                                <td>
                                    <div class="menu-branches-selector">
                                        <?php if (!empty($branches)): ?>
                                            <?php foreach ($branches as $branch): ?>
                                                <?php $current_branches = isset($menu) && !empty($menu['branches']) ? $menu['branches'] : array(); ?>
                                                <label class="branch-checkbox">
                                                    <input type="checkbox" name="menu_branches[]" value="<?php echo esc_attr($branch->ID); ?>" <?php checked(in_array($branch->ID, $current_branches)); ?> />
                                                    <?php echo esc_html($branch->post_title); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="description"><?php _e('No branches available. Create branches first.', 'menu-popup'); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <p class="description"><?php _e('Select branches to assign this menu to.', 'menu-popup'); ?></p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" class="button button-primary" id="add-menu-btn"><?php _e('Add New Menu', 'menu-popup'); ?></button>
                            <button type="button" class="button" id="cancel-menu-btn" style="display:none;"><?php _e('Cancel', 'menu-popup'); ?></button>
                        </p>
                    </form>
                </div>

                <!-- Menus List -->
                <div class="menus-list">
                    <h2><?php _e('Manage Menus', 'menu-popup'); ?></h2>
                    <?php if (!empty($menus)): ?>
                        <div class="menus-container" id="sortable-menus">
                            <?php foreach ($menus as $index => $menu): ?>
                                <div class="menu-item" data-id="<?php echo esc_attr($menu['id']); ?>">
                                    <div class="menu-header">
                                        <span class="menu-name"><?php echo esc_html($menu['name']); ?></span>
                                        <div class="menu-actions">
                                            <button class="edit-menu button button-small" data-id="<?php echo esc_attr($menu['id']); ?>">
                                                <?php _e('Edit', 'menu-popup'); ?>
                                            </button>
                                            <button class="delete-menu button button-small button-link-delete" data-id="<?php echo esc_attr($menu['id']); ?>">
                                                <?php _e('Delete', 'menu-popup'); ?>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="menu-meta">
                                        <span class="menu-slug"><?php echo esc_html($menu['slug']); ?></span>
                                        <?php if (!empty($menu['description'])): ?>
                                            <span class="menu-description"><?php echo esc_html($menu['description']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($menu['categories'])): ?>
                                            <div class="menu-categories">
                                                <strong><?php _e('Categories:', 'menu-popup'); ?> </strong>
                                                <?php foreach ($menu['categories'] as $cat_slug): ?>
                                                    <?php $cat_name = $this->get_category_name_by_slug($cat_slug); ?>
                                                    <span class="category-tag"><?php echo esc_html($cat_name); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($menu['branches'])): ?>
                                            <div class="menu-branches">
                                                <strong><?php _e('Branches:', 'menu-popup'); ?> </strong>
                                                <?php foreach ($menu['branches'] as $branch_id): ?>
                                                    <?php $branch_name = get_the_title($branch_id); ?>
                                                    <span class="branch-tag"><?php echo esc_html($branch_name); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">
                            <?php _e('Drag and drop menus to reorder them.', 'menu-popup'); ?>
                        </p>
                    <?php else: ?>
                        <p><?php _e('No menus found. Add your first menu to get started!', 'menu-popup'); ?></p>
                    <?php endif; ?>
                </div>


                <!-- How to Use Menus -->
                <div class="menu-usage-instructions" style="background: #fff; padding: 20px; margin-top: 20px; border: 1px solid #ddd; border-radius: 4px;">
                    <h3><?php _e('How to Use Menu Shortcodes', 'menu-popup'); ?></h3>
                    <p><?php _e('Once you\'ve created menus and assigned categories to them, you can display them on any page, post, or widget using these shortcodes:', 'menu-popup'); ?></p>

                    <div class="shortcode-examples" style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin: 15px 0;">
                        <h4><?php _e('Basic Usage', 'menu-popup'); ?></h4>
                        <p><code>[menu-popup menu="your-menu-slug"]</code></p>
                        <p class="description"><?php _e('Replace "your-menu-slug" with the slug of your menu. This will display only items from categories assigned to that specific menu.', 'menu-popup'); ?></p>
                    </div>

                    <div class="shortcode-examples" style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin: 15px 0;">
                        <h4><?php _e('Advanced Usage', 'menu-popup'); ?></h4>
                        <p><code>[menu-popup menu="food-menu" category="starters"]</code></p>
                        <p class="description"><?php _e('Show only "starters" from the "food-menu".', 'menu-popup'); ?></p>

                        <p><code>[menu-popup menu="beverages"]</code></p>
                        <p class="description"><?php _e('Show all categories assigned to the "beverages" menu.', 'menu-popup'); ?></p>
                    </div>

                    <div class="shortcode-examples" style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin: 15px 0;">
                        <h4><?php _e('Available Parameters', 'menu-popup'); ?></h4>
                        <ul style="margin: 10px 0; padding-left: 20px;">
                            <li><strong>menu</strong> - <?php _e('The slug of your menu (required)', 'menu-popup'); ?></li>
                            <li><strong>branch</strong> - <?php _e('Filter by specific branch slug (optional, if forced branch selection is enabled, this will be dynamically set by the user choice)', 'menu-popup'); ?></li>
                            <li><strong>category</strong> - <?php _e('Filter by specific category slug (optional)', 'menu-popup'); ?></li>
                            <li><strong>layout</strong> - <?php _e('Display layout: grid, list, or masonry (optional)', 'menu-popup'); ?></li>
                        </ul>
                    </div>

                    <div class="menu-examples" style="background: #e8f5e8; padding: 15px; border-radius: 4px; border-left: 4px solid #4CAF50; margin: 15px 0;">
                        <h4><?php _e('Examples with your menus:', 'menu-popup'); ?></h4>
                        <?php
                        $menus = $this->get_menu_menus();
                        if (!empty($menus)) {
                            foreach ($menus as $menu) {
                                echo '<p><code>[menu-popup menu="' . esc_attr($menu['slug']) . '"]</code> - ' . esc_html($menu['name']) . '</p>';
                            }
                        } else {
                            echo '<p>' . __('No menus created yet. Create a menu above to see examples.', 'menu-popup') . '</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function settings_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // media picker for the transfer QR images
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error('menu_popup_messages', 'menu_popup_message', __('Settings Saved', 'menu-popup'), 'updated');
        }

        settings_errors('menu_popup_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <p class="description">
                <?php _e('Configure your Menu Pop-up plugin settings. Use the shortcode', 'menu-popup'); ?>
                <code>[menu-popup]</code>
                <?php _e('to display the menu on any page or post.', 'menu-popup'); ?>
            </p>

            <?php
            $mp_tabs = array(
                'general'    => __( 'General', 'menu-popup' ),
                'display'    => __( 'Menu Display', 'menu-popup' ),
                'ordering'   => __( 'Ordering', 'menu-popup' ),
                'delivery'   => __( 'Delivery', 'menu-popup' ),
                'payment'    => __( 'Payment', 'menu-popup' ),
                'appearance' => __( 'Colors & Look', 'menu-popup' ),
                'voice'      => __( 'Voice', 'menu-popup' ),
            );
            ?>
            <nav class="nav-tab-wrapper mp-settings-tabs">
                <?php foreach ( $mp_tabs as $mp_key => $mp_label ) : ?>
                    <a href="#<?php echo esc_attr( $mp_key ); ?>" class="nav-tab" data-tab="<?php echo esc_attr( $mp_key ); ?>"><?php echo esc_html( $mp_label ); ?></a>
                <?php endforeach; ?>
            </nav>

            <style>
                .mp-settings-js .mp-settings-panel { display: none; }
                .mp-settings-js .mp-settings-panel.is-active { display: block; }
                .mp-settings-js .mp-settings-form.is-empty { display: none; }
                .mp-settings-panel > h2 { margin-top: 28px; padding-bottom: 6px; border-bottom: 1px solid #dcdcde; }
                .mp-settings-panel:first-child > h2 { margin-top: 18px; }
                .mp-dep-note { background: #f6f7f7; border-left: 4px solid #72aee6; padding: 8px 12px; margin: 10px 0; }
            </style>

            <!-- Settings (general, display, ordering, delivery, voice) -->
            <form action="options.php" method="post" class="mp-settings-form">
                <?php
                settings_fields('menu_popup_settings_group');
                $this->render_tabbed_sections('menu_popup_settings_group', 'general');
                submit_button(__('Save Changes', 'menu-popup'));
                ?>
            </form>

            <!-- Colors & look -->
            <form action="options.php" method="post" class="mp-settings-form">
                <?php
                settings_fields('menu_popup_colors_group');
                $this->render_tabbed_sections('menu_popup_colors_group', 'appearance');
                submit_button(__('Save Changes', 'menu-popup'));
                ?>
            </form>



            <!-- Payment Methods Settings (AJAX) -->
            <?php
            $payment_nonce = wp_create_nonce('menu_popup_save_payment_settings');
            ?>
            <div class="mp-settings-form">
            <div class="mp-settings-panel" data-tab="payment">
            <form id="payment-settings-form-ajax" method="post">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($payment_nonce); ?>">
                <h2><?php _e('💳 Payment Methods', 'menu-popup'); ?></h2>
                <p class="description"><?php _e('Configure payment methods for online orders', 'menu-popup'); ?></p>
                
                <h3><?php _e('General Settings', 'menu-popup'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_enabled"><?php _e('Enable Payment System', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="menu_popup_payment_enabled" name="menu_popup_payment_enabled" value="1" <?php checked(get_option('menu_popup_payment_enabled', '0'), '1'); ?>>
                            <p class="description"><?php _e('Enable payment functionality for online orders', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_currency"><?php _e('Default Currency', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <select id="menu_popup_payment_currency" name="menu_popup_payment_currency">
                                <option value="EGP" <?php selected(get_option('menu_popup_payment_currency', 'EGP'), 'EGP'); ?>><?php _e('Egyptian Pound (EGP)', 'menu-popup'); ?></option>
                                <option value="USD" <?php selected(get_option('menu_popup_payment_currency', 'EGP'), 'USD'); ?>><?php _e('US Dollar (USD)', 'menu-popup'); ?></option>
                                <option value="SAR" <?php selected(get_option('menu_popup_payment_currency', 'EGP'), 'SAR'); ?>><?php _e('Saudi Riyal (SAR)', 'menu-popup'); ?></option>
                                <option value="AED" <?php selected(get_option('menu_popup_payment_currency', 'EGP'), 'AED'); ?>><?php _e('UAE Dirham (AED)', 'menu-popup'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <h3><?php _e('Cash on Delivery (COD)', 'menu-popup'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_cod_enabled"><?php _e('Enable COD', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="menu_popup_payment_cod_enabled" name="menu_popup_payment_cod_enabled" value="1" <?php checked(get_option('menu_popup_payment_cod_enabled', '0'), '1'); ?>>
                            <p class="description"><?php _e('Allow customers to pay in cash on delivery', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_cod_max_amount"><?php _e('Maximum Amount', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="menu_popup_payment_cod_max_amount" name="menu_popup_payment_cod_max_amount" value="<?php echo esc_attr(get_option('menu_popup_payment_cod_max_amount', '')); ?>" class="small-text" step="0.01">
                            <p class="description"><?php _e('Maximum order amount for COD (leave empty for unlimited)', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php
                if (class_exists('Menu_Popup_Payment_Methods')) {
                    Menu_Popup_Payment_Methods::render_settings();
                }
                ?>

                <h3><?php _e('Bank Transfer', 'menu-popup'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_bank_transfer_enabled"><?php _e('Enable Bank Transfer', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="menu_popup_payment_bank_transfer_enabled" name="menu_popup_payment_bank_transfer_enabled" value="1" <?php checked(get_option('menu_popup_payment_bank_transfer_enabled', '0'), '1'); ?>>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_bank_transfer_bank_name"><?php _e('Bank Name', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="menu_popup_payment_bank_transfer_bank_name" name="menu_popup_payment_bank_transfer_bank_name" value="<?php echo esc_attr(get_option('menu_popup_payment_bank_transfer_bank_name', '')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_bank_transfer_account_number"><?php _e('Account Number', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="menu_popup_payment_bank_transfer_account_number" name="menu_popup_payment_bank_transfer_account_number" value="<?php echo esc_attr(get_option('menu_popup_payment_bank_transfer_account_number', '')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_bank_transfer_iban"><?php _e('IBAN', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="menu_popup_payment_bank_transfer_iban" name="menu_popup_payment_bank_transfer_iban" value="<?php echo esc_attr(get_option('menu_popup_payment_bank_transfer_iban', '')); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                
                <h3><?php _e('Fawry Gateway', 'menu-popup'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_fawry_enabled"><?php _e('Enable Fawry', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="menu_popup_payment_fawry_enabled" name="menu_popup_payment_fawry_enabled" value="1" <?php checked(get_option('menu_popup_payment_fawry_enabled', '0'), '1'); ?>>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_fawry_merchant_code"><?php _e('Merchant Code', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="menu_popup_payment_fawry_merchant_code" name="menu_popup_payment_fawry_merchant_code" value="<?php echo esc_attr(get_option('menu_popup_payment_fawry_merchant_code', '')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_fawry_secure_key"><?php _e('Secure Key', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="menu_popup_payment_fawry_secure_key" name="menu_popup_payment_fawry_secure_key" value="<?php echo esc_attr(get_option('menu_popup_payment_fawry_secure_key', '')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="menu_popup_payment_fawry_sandbox"><?php _e('Sandbox Mode', 'menu-popup'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="menu_popup_payment_fawry_sandbox" name="menu_popup_payment_fawry_sandbox" value="1" <?php checked(get_option('menu_popup_payment_fawry_sandbox', '1'), '1'); ?>>
                            <p class="description"><?php _e('Use Fawry sandbox for testing', 'menu-popup'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Save Payment Settings', 'menu-popup'), 'primary', 'save_payment_settings', true, ['id' => 'save-payment-settings-btn']); ?>
                <div id="payment-settings-message"></div>
            </form>
            </div>
            </div>

            <script>
            jQuery(function ($) {
                var $wrap = $('.mp-settings-tabs').closest('.wrap').addClass('mp-settings-js');
                var valid = $('.mp-settings-tabs .nav-tab').map(function () { return $(this).data('tab'); }).get();

                function show(tab) {
                    if (valid.indexOf(tab) === -1) { tab = 'general'; }
                    $('.mp-settings-tabs .nav-tab').removeClass('nav-tab-active')
                        .filter('[data-tab="' + tab + '"]').addClass('nav-tab-active');
                    $wrap.find('.mp-settings-panel').removeClass('is-active')
                        .filter('[data-tab="' + tab + '"]').addClass('is-active');
                    $wrap.find('.mp-settings-form').each(function () {
                        $(this).toggleClass('is-empty', $(this).find('.mp-settings-panel.is-active').length === 0);
                    });
                    // come back to the same tab after saving
                    $wrap.find('input[name="_wp_http_referer"]').each(function () {
                        var v = $(this).val().replace(/([?&])mp_tab=[^&#]*/g, '$1').replace(/[?&]$/, '').replace(/&&+/g, '&');
                        $(this).val(v + (v.indexOf('?') === -1 ? '?' : '&') + 'mp_tab=' + tab);
                    });
                    try { localStorage.setItem('mpSettingsTab', tab); } catch (e) {}
                }

                $('.mp-settings-tabs').on('click', '.nav-tab', function (e) {
                    e.preventDefault();
                    var tab = $(this).data('tab');
                    if (history.replaceState) { history.replaceState(null, '', '#' + tab); }
                    show(tab);
                });

                var start = (location.hash || '').replace('#', '');
                if (!start) {
                    var m = location.search.match(/[?&]mp_tab=([a-z]+)/);
                    if (m) { start = m[1]; }
                }
                if (!start) { try { start = localStorage.getItem('mpSettingsTab') || ''; } catch (e) {} }
                show(start || 'general');

                // Settings that only matter when another one is on.
                // Hidden rows are still saved, so nothing is lost.
                function row(name) { return $wrap.find('[name="' + name + '"]').first().closest('tr'); }

                function applyDeps() {
                    var deliveryOn = $wrap.find('[name="menu_popup_delivery_enabled"]').is(':checked');
                    var mapMode = $wrap.find('[name="menu_popup_delivery_mode"]:checked').val() === 'map';
                    row('menu_popup_delivery_mode').toggle(deliveryOn);
                    row('menu_popup_google_maps_api_key').toggle(deliveryOn && mapMode);
                    row('menu_popup_map_default_lat').toggle(deliveryOn && mapMode);
                    $('#mp-delivery-off-note').toggle(!deliveryOn);

                    var ttsOn = $wrap.find('[name="menu_popup_external_tts_enabled"]').is(':checked');
                    row('menu_popup_google_tts_api_key').toggle(ttsOn);
                    row('menu_popup_tts_cache_enabled').toggle(ttsOn);

                    var autoRefresh = $wrap.find('[name="menu_popup_order_dashboard_realtime_enabled"]').is(':checked');
                    row('menu_popup_order_dashboard_refresh_interval').toggle(autoRefresh);
                }

                $('#mp-section-menu_popup_delivery_settings > table').before(
                    $('<p class="mp-dep-note" id="mp-delivery-off-note"></p>').text('<?php echo esc_js( __( 'Delivery is off: customers can only pick up. Turn it on to set up delivery zones.', 'menu-popup' ) ); ?>')
                );
                $wrap.on('change', 'input', applyDeps);
                applyDeps();
            });
            </script>
            <script>
            jQuery(document).ready(function($) {
                $('#payment-settings-form-ajax').on('submit', function(e) {
                    e.preventDefault();
                    
                    var formData = {};
                    $(this).find('input, select, textarea').each(function() {
                        var name = $(this).attr('name');
                        var type = $(this).attr('type');
                        
                        if (type === 'checkbox') {
                            formData[name] = $(this).is(':checked') ? '1' : '0';
                        } else if (name) {
                            formData[name] = $(this).val();
                        }
                    });
                    
                    formData.action = 'save_payment_settings';
                    formData.nonce = '<?php echo esc_js($payment_nonce); ?>';
                    
                    $('#save-payment-settings-btn').prop('disabled', true).val('Saving...');
                    
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: formData,
                        success: function(response) {
                            if (response.success) {
                                $('#payment-settings-message').html('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
                            } else {
                                $('#payment-settings-message').html('<div class="notice notice-error is-dismissible"><p>' + response.data.message + '</p></div>');
                            }
                            $('#save-payment-settings-btn').prop('disabled', false).val('Save Payment Settings');
                        },
                        error: function() {
                            $('#payment-settings-message').html('<div class="notice notice-error is-dismissible"><p>Error saving settings. Please try again.</p></div>');
                            $('#save-payment-settings-btn').prop('disabled', false).val('Save Payment Settings');
                        }
                    });
                });
            });
            </script>

            <script>
            jQuery(document).ready(function($) {
                let voices = [];
                let arabicVoices = [];
                let englishVoices = [];

                // External TTS settings - make available globally for external-tts.js
                window.menuPopupSettings = {
                    externalTtsEnabled: '<?php echo get_option('menu_popup_external_tts_enabled', '0'); ?>' === '1',
                    googleTtsApiKey: '<?php echo esc_js(get_option('menu_popup_google_tts_api_key', '')); ?>',
                    ttsCacheEnabled: '<?php echo get_option('menu_popup_tts_cache_enabled', '1'); ?>' === '1'
                };

                // External TTS settings
                const externalTtsEnabled = window.menuPopupSettings.externalTtsEnabled;

                // Load voices when speech synthesis is ready
                if ('speechSynthesis' in window) {
                    speechSynthesis.onvoiceschanged = function() {
                        voices = speechSynthesis.getVoices();
                        populateVoiceDropdowns();
                    };

                    // Also try to get voices immediately
                    voices = speechSynthesis.getVoices();
                    if (voices.length > 0) {
                        populateVoiceDropdowns();
                    }
                }

                function populateVoiceDropdowns() {
                    arabicVoices = voices.filter(voice =>
                        voice.lang.startsWith('ar') ||
                        voice.name.toLowerCase().includes('arabic') ||
                        voice.name.toLowerCase().includes('عربي')
                    );

                    englishVoices = voices.filter(voice =>
                        voice.lang.startsWith('en')
                    );

                    // Populate Arabic voice dropdown
                    const arabicSelect = $('#menu_popup_arabic_voice');
                    arabicSelect.find('option:not(:first)').remove(); // Keep the "Auto-select" option

                    // Add browser voices
                    arabicVoices.forEach(voice => {
                        const option = $('<option>')
                            .val(voice.name)
                            .text(voice.name + ' (' + voice.lang + ') - Browser')
                            .prop('selected', voice.name === arabicSelect.data('current'));
                        arabicSelect.append(option);
                    });

                    // Add Google TTS voices if external TTS is enabled
                    if (externalTtsEnabled) {
                        const googleArabicVoices = [
                            // Arabic - General (XA)
                            { name: 'ar-XA-Wavenet-A', gender: 'Female', quality: 'High', region: 'General' },
                            { name: 'ar-XA-Wavenet-B', gender: 'Male', quality: 'High', region: 'General' },
                            { name: 'ar-XA-Wavenet-C', gender: 'Male', quality: 'High', region: 'General' },
                            { name: 'ar-XA-Wavenet-D', gender: 'Female', quality: 'High', region: 'General' },
                            { name: 'ar-XA-Standard-A', gender: 'Female', quality: 'Standard', region: 'General' },
                            { name: 'ar-XA-Standard-B', gender: 'Male', quality: 'Standard', region: 'General' },
                            { name: 'ar-XA-Standard-C', gender: 'Male', quality: 'Standard', region: 'General' },
                            { name: 'ar-XA-Standard-D', gender: 'Female', quality: 'Standard', region: 'General' },

                            // Arabic - Saudi Arabia (SA)
                            { name: 'ar-SA-Wavenet-A', gender: 'Female', quality: 'High', region: 'Saudi Arabia' },
                            { name: 'ar-SA-Wavenet-B', gender: 'Male', quality: 'High', region: 'Saudi Arabia' },
                            { name: 'ar-SA-Standard-A', gender: 'Female', quality: 'Standard', region: 'Saudi Arabia' },
                            { name: 'ar-SA-Standard-B', gender: 'Male', quality: 'Standard', region: 'Saudi Arabia' },

                            // Arabic - Egypt (EG)
                            { name: 'ar-EG-Wavenet-A', gender: 'Female', quality: 'High', region: 'Egypt' },
                            { name: 'ar-EG-Wavenet-B', gender: 'Male', quality: 'High', region: 'Egypt' },
                            { name: 'ar-EG-Standard-A', gender: 'Female', quality: 'Standard', region: 'Egypt' },
                            { name: 'ar-EG-Standard-B', gender: 'Male', quality: 'Standard', region: 'Egypt' },

                            // Arabic - Iraq (IQ)
                            { name: 'ar-IQ-Wavenet-A', gender: 'Female', quality: 'High', region: 'Iraq' },
                            { name: 'ar-IQ-Wavenet-B', gender: 'Male', quality: 'High', region: 'Iraq' },
                            { name: 'ar-IQ-Standard-A', gender: 'Female', quality: 'Standard', region: 'Iraq' },
                            { name: 'ar-IQ-Standard-B', gender: 'Male', quality: 'Standard', region: 'Iraq' },

                            // Arabic - Jordan (JO)
                            { name: 'ar-JO-Wavenet-A', gender: 'Female', quality: 'High', region: 'Jordan' },
                            { name: 'ar-JO-Wavenet-B', gender: 'Male', quality: 'High', region: 'Jordan' },
                            { name: 'ar-JO-Standard-A', gender: 'Female', quality: 'Standard', region: 'Jordan' },
                            { name: 'ar-JO-Standard-B', gender: 'Male', quality: 'Standard', region: 'Jordan' },

                            // Arabic - Kuwait (KW)
                            { name: 'ar-KW-Wavenet-A', gender: 'Female', quality: 'High', region: 'Kuwait' },
                            { name: 'ar-KW-Wavenet-B', gender: 'Male', quality: 'High', region: 'Kuwait' },
                            { name: 'ar-KW-Standard-A', gender: 'Female', quality: 'Standard', region: 'Kuwait' },
                            { name: 'ar-KW-Standard-B', gender: 'Male', quality: 'Standard', region: 'Kuwait' },

                            // Arabic - Lebanon (LB)
                            { name: 'ar-LB-Wavenet-A', gender: 'Female', quality: 'High', region: 'Lebanon' },
                            { name: 'ar-LB-Wavenet-B', gender: 'Male', quality: 'High', region: 'Lebanon' },
                            { name: 'ar-LB-Standard-A', gender: 'Female', quality: 'Standard', region: 'Lebanon' },
                            { name: 'ar-LB-Standard-B', gender: 'Male', quality: 'Standard', region: 'Lebanon' },

                            // Arabic - Morocco (MA)
                            { name: 'ar-MA-Wavenet-A', gender: 'Female', quality: 'High', region: 'Morocco' },
                            { name: 'ar-MA-Wavenet-B', gender: 'Male', quality: 'High', region: 'Morocco' },
                            { name: 'ar-MA-Standard-A', gender: 'Female', quality: 'Standard', region: 'Morocco' },
                            { name: 'ar-MA-Standard-B', gender: 'Male', quality: 'Standard', region: 'Morocco' },

                            // Arabic - Qatar (QA)
                            { name: 'ar-QA-Wavenet-A', gender: 'Female', quality: 'High', region: 'Qatar' },
                            { name: 'ar-QA-Wavenet-B', gender: 'Male', quality: 'High', region: 'Qatar' },
                            { name: 'ar-QA-Standard-A', gender: 'Female', quality: 'Standard', region: 'Qatar' },
                            { name: 'ar-QA-Standard-B', gender: 'Male', quality: 'Standard', region: 'Qatar' },

                            // Arabic - Syria (SY)
                            { name: 'ar-SY-Wavenet-A', gender: 'Female', quality: 'High', region: 'Syria' },
                            { name: 'ar-SY-Wavenet-B', gender: 'Male', quality: 'High', region: 'Syria' },
                            { name: 'ar-SY-Standard-A', gender: 'Female', quality: 'Standard', region: 'Syria' },
                            { name: 'ar-SY-Standard-B', gender: 'Male', quality: 'Standard', region: 'Syria' },

                            // Arabic - Tunisia (TN)
                            { name: 'ar-TN-Wavenet-A', gender: 'Female', quality: 'High', region: 'Tunisia' },
                            { name: 'ar-TN-Wavenet-B', gender: 'Male', quality: 'High', region: 'Tunisia' },
                            { name: 'ar-TN-Standard-A', gender: 'Female', quality: 'Standard', region: 'Tunisia' },
                            { name: 'ar-TN-Standard-B', gender: 'Male', quality: 'Standard', region: 'Tunisia' },

                            // Arabic - Yemen (YE)
                            { name: 'ar-YE-Wavenet-A', gender: 'Female', quality: 'High', region: 'Yemen' },
                            { name: 'ar-YE-Wavenet-B', gender: 'Male', quality: 'High', region: 'Yemen' },
                            { name: 'ar-YE-Standard-A', gender: 'Female', quality: 'Standard', region: 'Yemen' },
                            { name: 'ar-YE-Standard-B', gender: 'Male', quality: 'Standard', region: 'Yemen' }
                        ];

                        googleArabicVoices.forEach(voice => {
                            const option = $('<option>')
                                .val(voice.name)
                                .text(voice.name + ' (' + voice.gender + ', ' + voice.quality + ') - Google TTS')
                                .prop('selected', voice.name === arabicSelect.data('current'));
                            arabicSelect.append(option);
                        });
                    }

                    // Populate English voice dropdown
                    const englishSelect = $('#menu_popup_english_voice');
                    englishSelect.find('option:not(:first)').remove(); // Keep the "Auto-select" option

                    // Add browser voices
                    englishVoices.forEach(voice => {
                        const option = $('<option>')
                            .val(voice.name)
                            .text(voice.name + ' (' + voice.lang + ') - Browser')
                            .prop('selected', voice.name === englishSelect.data('current'));
                        englishSelect.append(option);
                    });

                    // Add Google TTS voices for English if external TTS is enabled
                    if (externalTtsEnabled) {
                        const googleEnglishVoices = [
                            { name: 'en-US-Wavenet-A', gender: 'Female', quality: 'High' },
                            { name: 'en-US-Wavenet-B', gender: 'Male', quality: 'High' },
                            { name: 'en-US-Wavenet-C', gender: 'Female', quality: 'High' },
                            { name: 'en-US-Wavenet-D', gender: 'Male', quality: 'High' },
                            { name: 'en-US-Standard-A', gender: 'Female', quality: 'Standard' },
                            { name: 'en-US-Standard-B', gender: 'Male', quality: 'Standard' },
                            { name: 'en-US-Standard-C', gender: 'Female', quality: 'Standard' },
                            { name: 'en-US-Standard-D', gender: 'Male', quality: 'Standard' }
                        ];

                        googleEnglishVoices.forEach(voice => {
                            const option = $('<option>')
                                .val(voice.name)
                                .text(voice.name + ' (' + voice.gender + ', ' + voice.quality + ') - Google TTS')
                                .prop('selected', voice.name === englishSelect.data('current'));
                            englishSelect.append(option);
                        });
                    }
                }

                // Test voice buttons
                $('#test_arabic_voice').on('click', function() {
                    const selectedVoice = $('#menu_popup_arabic_voice').val();
                    testVoice(selectedVoice, 'مرحباً! هذا اختبار للنطق باللغة العربية', 'ar-SA');
                });

                $('#test_english_voice').on('click', function() {
                    const selectedVoice = $('#menu_popup_english_voice').val();
                    testVoice(selectedVoice, 'Hello! This is a test of English pronunciation.', 'en-US');
                });

                function testVoice(voiceName, testText, fallbackLang) {
                    if (!('speechSynthesis' in window)) {
                        alert('Speech synthesis is not supported in your browser.');
                        return;
                    }

                    // Cancel any ongoing speech
                    speechSynthesis.cancel();

                    const utterance = new SpeechSynthesisUtterance(testText);

                    // Set voice if specified
                    if (voiceName) {
                        const voice = voices.find(v => v.name === voiceName);
                        if (voice) {
                            utterance.voice = voice;
                        }
                    }

                    // Set language
                    utterance.lang = fallbackLang;
                    utterance.rate = 0.8;
                    utterance.pitch = 1.0;
                    utterance.volume = 0.8;

                    speechSynthesis.speak(utterance);
                }
            });
            </script>



            <!-- Colors are already included above -->

            <div class="menu-popup-instructions">
                <h3><?php _e('How to Use', 'menu-popup'); ?></h3>
                <ol>
                    <li><?php _e('Create a new post or edit an existing one.', 'menu-popup'); ?></li>
                    <li><?php _e('Fill in the "Menu Item Details" meta box with your menu information.', 'menu-popup'); ?></li>
                    <li><?php _e('Add the shortcode', 'menu-popup'); ?> <code>[menu-popup]</code> <?php _e('to any page or post where you want to display the menu.', 'menu-popup'); ?></li>
                    <li><?php _e('Use', 'menu-popup'); ?> <code>[menu-popup category="starters"]</code> <?php _e('to show only items from a specific category.', 'menu-popup'); ?></li>
                </ol>

                <h3><?php _e('Video Requirements', 'menu-popup'); ?></h3>
                <p><?php _e('For video popups, use YouTube or Vimeo embed URLs. Example:', 'menu-popup'); ?></p>
                <code>https://www.youtube.com/embed/VIDEO_ID</code>
                <p><?php _e('or', 'menu-popup'); ?></p>
                <code>https://player.vimeo.com/video/VIDEO_ID</code>
            </div>
        </div>
        <?php
    }

    // Branch Selection Callbacks
    public function branch_selection_settings_section_callback() {
        echo '<p>' . __('Configure how customers select branches and view menus.', 'menu-popup') . '</p>';
        echo '<p class="description">' . __('These settings control the branch selection flow and whether city selection is enabled.', 'menu-popup') . '</p>';
    }

    public function enable_cities_field_callback() {
        $value = get_option('menu_popup_enable_cities', '1');
        ?>
        <label for="menu_popup_enable_cities">
            <input type="checkbox" id="menu_popup_enable_cities" name="menu_popup_enable_cities" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable City/Area selection before branch selection', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When disabled, users will go directly to branch selection.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function force_branch_selection_field_callback() {
        $value = get_option('menu_popup_force_branch_selection', '0');
        ?>
        <label for="menu_popup_force_branch_selection">
            <input type="checkbox" id="menu_popup_force_branch_selection" name="menu_popup_force_branch_selection" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Force customer to select a branch before viewing the menu', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('If enabled, the menu will not display until a branch is selected. Menu items will be filtered by the selected branch.', 'menu-popup'); ?>
        </p>
        <?php
    }

    // Helper methods
    private function get_total_menu_items() {
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_menu_item_name',
                    'compare' => 'EXISTS'
                )
            ),
            'fields' => 'ids'
        );

        return count(get_posts($args));
    }

    private function get_total_categories() {
        $categories = get_option('menu_popup_categories', array());
        return count($categories);
    }

    private function get_recent_menu_items() {
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_menu_item_name',
                    'compare' => 'EXISTS'
                )
            ),
            'posts_per_page' => 5,
            'orderby' => 'modified',
            'order' => 'DESC'
        );

        return get_posts($args);
    }

    private function get_menu_categories() {
        return get_option('menu_popup_categories', array());
    }

    private function get_menu_menus() {
        return get_option('menu_popup_menus', array());
    }

    private function get_all_branches() {
        $args = array(
            'post_type' => 'menu_popup_branch',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filter' => true, // Ensure all posts are returned
        );
        return get_posts($args);
    }

    private function get_category_name_by_slug($slug) {
        $categories = $this->get_menu_categories();
        foreach ($categories as $category) {
            if (isset($category['slug']) && $category['slug'] === $slug) {
                return isset($category['name']) ? $category['name'] : $slug;
            }
        }
        return $slug;
    }

    // Fallback form handler for non-AJAX submissions
    private function handle_menu_form_submission() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'menu-popup-menus')) {
            echo '<div class="notice notice-error"><p>' . __('Security check failed', 'menu-popup') . '</p></div>';
            return;
        }

        $name = sanitize_text_field($_POST['menu_name']);
        $slug = sanitize_text_field($_POST['menu_slug']);
        $description = sanitize_textarea_field($_POST['menu_description']);
        $categories = isset($_POST['menu_categories']) ? array_map('sanitize_text_field', $_POST['menu_categories']) : array();
        $branches = isset($_POST['menu_branches']) ? array_map('intval', $_POST['menu_branches']) : array(); // New: Branches

        if (empty($name) || empty($slug)) {
            echo '<div class="notice notice-error"><p>' . __('Menu name and slug are required', 'menu-popup') . '</p></div>';
            return;
        }

        // Validate slug format
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            echo '<div class="notice notice-error"><p>' . __('Menu slug can only contain lowercase letters, numbers, and hyphens', 'menu-popup') . '</p></div>';
            return;
        }

        $menus = get_option('menu_popup_menus', array());

        // Check if slug already exists
        foreach ($menus as $menu) {
            if (isset($menu['slug']) && $menu['slug'] === $slug) {
                echo '<div class="notice notice-error"><p>' . __('Menu slug already exists', 'menu-popup') . '</p></div>';
                return;
            }
        }

        $new_menu = array(
            'id' => uniqid('menu_'),
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'categories' => $categories,
            'branches' => $branches // New: Save branches
        );

        $menus[] = $new_menu;
        $update_result = update_option('menu_popup_menus', $menus);

        if ($update_result) {
            echo '<div class="notice notice-success"><p>' . __('Menu added successfully', 'menu-popup') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('Failed to save menu', 'menu-popup') . '</p></div>';
        }
    }

    // Fallback form handler for non-AJAX submissions
    private function handle_category_form_submission() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'menu-popup-categories')) {
            echo '<div class="notice notice-error"><p>' . __('Security check failed', 'menu-popup') . '</p></div>';
            return;
        }

        $name = sanitize_text_field($_POST['category_name']);
        $slug = sanitize_text_field($_POST['category_slug']);
        $description = sanitize_textarea_field($_POST['category_description']);
        $image = isset($_POST['category_image']) ? esc_url_raw($_POST['category_image']) : '';

        if (empty($name) || empty($slug)) {
            echo '<div class="notice notice-error"><p>' . __('Category name and slug are required', 'menu-popup') . '</p></div>';
            return;
        }

        $categories = get_option('menu_popup_categories', array());

        // Check if slug already exists
        foreach ($categories as $category) {
            if (isset($category['slug']) && $category['slug'] === $slug) {
                echo '<div class="notice notice-error"><p>' . __('Category slug already exists', 'menu-popup') . '</p></div>';
                return;
            }
        }

        $new_category = array(
            'id' => uniqid('cat_'),
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'image' => $image
        );

        $categories[] = $new_category;
        $update_result = update_option('menu_popup_categories', $categories);

        if ($update_result) {
            echo '<div class="notice notice-success"><p>' . __('Category added successfully', 'menu-popup') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('Failed to save category', 'menu-popup') . '</p></div>';
        }
    }

    // AJAX handlers
    public function save_category_order() {
        $nonce_verified = false;
        if (isset($_POST['nonce'])) {
            $nonce_verified = wp_verify_nonce($_POST['nonce'], 'menu_popup_admin_nonce');
        }

        if (isset($_POST['nonce']) && !$nonce_verified) {
            wp_die(__('Security check failed', 'menu-popup'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'menu-popup'));
        }

        $category_order = $_POST['category_order'];
        $categories = get_option('menu_popup_categories', array());

        // Reorder categories based on the new order
        $reordered_categories = array();
        foreach ($category_order as $index => $category_id) {
            foreach ($categories as $category) {
                if (isset($category['id']) && $category['id'] == $category_id) {
                    $reordered_categories[] = $category;
                    break;
                }
            }
        }

        update_option('menu_popup_categories', $reordered_categories);
        wp_send_json_success(__('Category order saved successfully', 'menu-popup'));
    }

    public function delete_category() {
        $nonce_verified = false;
        if (isset($_POST['nonce'])) {
            $nonce_verified = wp_verify_nonce($_POST['nonce'], 'menu_popup_admin_nonce');
        }

        if (isset($_POST['nonce']) && !$nonce_verified) {
            wp_die(__('Security check failed', 'menu-popup'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'menu-popup'));
        }

        $category_id = sanitize_text_field($_POST['category_id']);
        $categories = get_option('menu_popup_categories', array());

        // Remove the category
        $categories = array_filter($categories, function($category) use ($category_id) {
            return isset($category['id']) && $category['id'] != $category_id;
        });

        update_option('menu_popup_categories', array_values($categories));
        wp_send_json_success(__('Category deleted successfully', 'menu-popup'));
    }

    public function add_new_category() {
        $nonce_verified = false;
        if (isset($_POST['nonce'])) {
            $nonce_verified = wp_verify_nonce($_POST['nonce'], 'menu_popup_admin_nonce');
        }

        if (isset($_POST['nonce']) && !$nonce_verified) {
            wp_send_json_error(__('Security check failed', 'menu-popup'));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'menu-popup'));
        }

        $name = sanitize_text_field($_POST['name']);
        $slug = sanitize_text_field($_POST['slug']);
        $description = sanitize_textarea_field($_POST['description']);
        $image = isset($_POST['image']) ? esc_url_raw($_POST['image']) : '';

        if (empty($name) || empty($slug)) {
            wp_send_json_error(__('Category name and slug are required', 'menu-popup'));
        }

        $categories = get_option('menu_popup_categories', array());

        // Check if slug already exists
        foreach ($categories as $category) {
            if (isset($category['slug']) && $category['slug'] === $slug) {
                wp_send_json_error(__('Category slug already exists', 'menu-popup'));
            }
        }

        $new_category = array(
            'id' => uniqid('cat_'),
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'image' => $image
        );

        $categories[] = $new_category;

        // Try to update option with error handling
        $update_result = update_option('menu_popup_categories', $categories);

        if ($update_result === false) {
            // If update failed, try to add option (in case it doesn't exist)
            $update_result = add_option('menu_popup_categories', $categories, '', 'no');
        }

        if ($update_result) {
            wp_send_json_success(array(
                'message' => __('Category added successfully', 'menu-popup'),
                'category' => $new_category
            ));
        } else {
            wp_send_json_error(__('Failed to save category', 'menu-popup'));
        }
    }

    public function update_category() {
        // More flexible nonce check - try multiple sources
        $nonce_verified = false;
        if (isset($_POST['nonce'])) {
            $nonce_verified = wp_verify_nonce($_POST['nonce'], 'menu_popup_admin_nonce');
        }

        if (isset($_POST['nonce']) && !$nonce_verified) {
            wp_send_json_error(__('Security check failed', 'menu-popup'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'menu-popup'));
        }

        $category_id = sanitize_text_field($_POST['category_id']);
        $name = sanitize_text_field($_POST['name']);
        $slug = sanitize_text_field($_POST['slug']);
        $description = sanitize_textarea_field($_POST['description']);
        $image = isset($_POST['image']) ? esc_url_raw($_POST['image']) : '';

        if (empty($name) || empty($slug)) {
            wp_send_json_error(__('Category name and slug are required', 'menu-popup'));
        }

        // Validate slug format
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            wp_send_json_error(__('Category slug can only contain lowercase letters, numbers, and hyphens', 'menu-popup'));
        }

        $categories = get_option('menu_popup_categories', array());

        // Find and update the category
        $category_found = false;
        foreach ($categories as $index => $category) {
            if (isset($category['id']) && $category['id'] === $category_id) {
                // Check if slug is being changed and if it's already taken by another category
                if ($category['slug'] !== $slug) {
                    foreach ($categories as $check_category) {
                        if (isset($check_category['slug']) && $check_category['slug'] === $slug && $check_category['id'] !== $category_id) {
                            wp_send_json_error(__('Category slug already exists', 'menu-popup'));
                        }
                    }
                }

                $categories[$index] = array(
                    'id' => $category_id,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                    'image' => $image
                );
                $category_found = true;
                break;
            }
        }

        if (!$category_found) {
            wp_send_json_error(__('Category not found', 'menu-popup'));
        }

        $update_result = update_option('menu_popup_categories', $categories);

        if ($update_result) {
            wp_send_json_success(array(
                'message' => __('Category updated successfully', 'menu-popup'),
                'category' => $categories[$index]
            ));
        } else {
            wp_send_json_error(__('Failed to update category', 'menu-popup'));
        }
    }


    // Voice Features Settings Callbacks
    public function voice_settings_section_callback() {
        echo '<p>' . __('Configure voice features for enhanced accessibility and interaction.', 'menu-popup') . '</p>';
        echo '<p class="description">' . __('These settings add Persian/Arabic/English language support for speech recognition and synthesis.', 'menu-popup') . '</p>';
    }

    public function voice_search_enabled_field_callback() {
        $value = get_option('menu_popup_voice_search_enabled', '0');
        ?>
        <label for="menu_popup_voice_search_enabled">
            <input type="checkbox" id="menu_popup_voice_search_enabled" name="menu_popup_voice_search_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable voice search functionality', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, a microphone button will appear beside filters for voice-activated search.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function pronounce_on_hover_enabled_field_callback() {
        $value = get_option('menu_popup_pronounce_on_hover_enabled', '0');
        ?>
        <label for="menu_popup_pronounce_on_hover_enabled">
            <input type="checkbox" id="menu_popup_pronounce_on_hover_enabled" name="menu_popup_pronounce_on_hover_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable item name pronunciation on hover', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, item names will be spoken aloud when users hover over them.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function voice_hover_enabled_mobile_field_callback() {
        $value = get_option('menu_popup_voice_hover_enabled_mobile', '0');
        ?>
        <label for="menu_popup_voice_hover_enabled_mobile">
            <input type="checkbox" id="menu_popup_voice_hover_enabled_mobile" name="menu_popup_voice_hover_enabled_mobile" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable pronunciation on hover for mobile devices', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, hover pronunciation works on mobile devices (may affect battery life).', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function voice_confirmation_enabled_field_callback() {
        $value = get_option('menu_popup_voice_confirmation_enabled', '1');
        ?>
        <label for="menu_popup_voice_confirmation_enabled">
            <input type="hidden" name="menu_popup_voice_confirmation_enabled" value="0" />
            <input type="checkbox" id="menu_popup_voice_confirmation_enabled" name="menu_popup_voice_confirmation_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable confirmation dialogs for clear voice catches', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When disabled, clear voice matches are processed directly without showing confirmation dialogs. Confirmation dialogs are still shown for unclear input.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function arabic_voice_field_callback() {
        $value = get_option('menu_popup_arabic_voice', '');
        $external_tts_enabled = get_option('menu_popup_external_tts_enabled', '0') === '1';
        ?>
        <select id="menu_popup_arabic_voice" name="menu_popup_arabic_voice" data-current="<?php echo esc_attr($value); ?>">
            <option value="" <?php selected($value, ''); ?>><?php _e('Auto-select (recommended)', 'menu-popup'); ?></option>
            <!-- Voice options will be populated by JavaScript -->
        </select>
        <button type="button" id="test_arabic_voice" class="button" style="margin-left: 10px;"><?php _e('Test Voice', 'menu-popup'); ?></button>
        <p class="description">
            <?php _e('Choose a specific Arabic voice for pronunciation. Leave as "Auto-select" to automatically choose the best available voice.', 'menu-popup'); ?>
            <?php if ($external_tts_enabled): ?>
                <br><strong><?php _e('Note:', 'menu-popup'); ?></strong> <?php _e('External TTS voices (Google) provide higher quality Arabic pronunciation.', 'menu-popup'); ?>
            <?php endif; ?>
        </p>
        <?php
    }

    public function external_tts_enabled_field_callback() {
        $value = get_option('menu_popup_external_tts_enabled', '0');
        ?>
        <label for="menu_popup_external_tts_enabled">
            <input type="checkbox" id="menu_popup_external_tts_enabled" name="menu_popup_external_tts_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Enable Google Cloud Text-to-Speech for additional Arabic voices', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, Google Cloud TTS will provide additional high-quality Arabic voices. Requires API key configuration below.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function google_tts_api_key_field_callback() {
        $value = get_option('menu_popup_google_tts_api_key', '');
        ?>
        <input type="password" id="menu_popup_google_tts_api_key" name="menu_popup_google_tts_api_key"
               value="<?php echo esc_attr($value); ?>" size="50" placeholder="Your Google Cloud API Key" />
        <p class="description">
            <?php _e('Enter your Google Cloud Text-to-Speech API key. Get one from the', 'menu-popup'); ?>
            <a href="https://console.cloud.google.com/apis/credentials" target="_blank"><?php _e('Google Cloud Console', 'menu-popup'); ?></a>.
            <?php _e('First 1 million characters are free per month.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function google_stt_api_key_field_callback() {
        $value = get_option('menu_popup_google_stt_api_key', '');
        ?>
        <input type="password" id="menu_popup_google_stt_api_key" name="menu_popup_google_stt_api_key"
               value="<?php echo esc_attr($value); ?>" size="50" placeholder="Your Google Cloud Speech-to-Text API Key" />
        <p class="description">
            <?php _e('Enter your Google Cloud Speech-to-Text API key for voice search. Get one from the', 'menu-popup'); ?>
            <a href="https://console.cloud.google.com/apis/credentials" target="_blank"><?php _e('Google Cloud Console', 'menu-popup'); ?></a>.
            <?php _e('First 60 minutes are free per month.', 'menu-popup'); ?>
        </p>
        <p class="description" style="color: #d63638;">
            <strong><?php _e('Note:', 'menu-popup'); ?></strong> <?php _e('This enables voice search on browsers without native SpeechRecognition support (Firefox, iOS Safari).', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function tts_cache_enabled_field_callback() {
        $value = get_option('menu_popup_tts_cache_enabled', '1');
        ?>
        <label for="menu_popup_tts_cache_enabled">
            <input type="checkbox" id="menu_popup_tts_cache_enabled" name="menu_popup_tts_cache_enabled" value="1" <?php checked($value, '1'); ?> />
            <?php _e('Cache generated audio files', 'menu-popup'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, generated voice audio files are cached locally to improve performance and reduce API calls.', 'menu-popup'); ?>
        </p>
        <?php
    }

    public function english_voice_field_callback() {
        $value = get_option('menu_popup_english_voice', '');
        $external_tts_enabled = get_option('menu_popup_external_tts_enabled', '0') === '1';
        ?>
        <select id="menu_popup_english_voice" name="menu_popup_english_voice" data-current="<?php echo esc_attr($value); ?>">
            <option value="" <?php selected($value, ''); ?>><?php _e('Auto-select (recommended)', 'menu-popup'); ?></option>
            <!-- Voice options will be populated by JavaScript -->
        </select>
        <button type="button" id="test_english_voice" class="button" style="margin-left: 10px;"><?php _e('Test Voice', 'menu-popup'); ?></button>
        <p class="description">
            <?php _e('Choose a specific English voice for pronunciation. Leave as "Auto-select" to automatically choose the best available voice.', 'menu-popup'); ?>
            <?php if ($external_tts_enabled): ?>
                <br><strong><?php _e('Note:', 'menu-popup'); ?></strong> <?php _e('External TTS voices (Google) provide higher quality English pronunciation.', 'menu-popup'); ?>
            <?php endif; ?>
        </p>
        <?php
    }


    // Menu AJAX handlers
    public function save_menu_order() {
        $nonce_verified = false;
        if (isset($_POST['nonce'])) {
            $nonce_verified = wp_verify_nonce($_POST['nonce'], 'menu_popup_admin_nonce');
        }

        if (isset($_POST['nonce']) && !$nonce_verified) {
            wp_die(__('Security check failed', 'menu-popup'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'menu-popup'));
        }

        $menu_order = $_POST['menu_order'];
        $menus = get_option('menu_popup_menus', array());

        // Reorder menus based on the new order
        $reordered_menus = array();
        foreach ($menu_order as $index => $menu_id) {
            foreach ($menus as $menu) {
                if (isset($menu['id']) && $menu['id'] == $menu_id) {
                    $reordered_menus[] = $menu;
                    break;
                }
            }
        }

        update_option('menu_popup_menus', $reordered_menus);
        wp_send_json_success(__('Menu order saved successfully', 'menu-popup'));
    }

    public function delete_menu() {
        $nonce_verified = false;
        if (isset($_POST['nonce'])) {
            $nonce_verified = wp_verify_nonce($_POST['nonce'], 'menu_popup_admin_nonce');
        }

        if (isset($_POST['nonce']) && !$nonce_verified) {
            wp_die(__('Security check failed', 'menu-popup'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'menu-popup'));
        }

        $menu_id = sanitize_text_field($_POST['menu_id']);
        $menus = get_option('menu_popup_menus', array());

        // Remove the menu
        $menus = array_filter($menus, function($menu) use ($menu_id) {
            return isset($menu['id']) && $menu['id'] != $menu_id;
        });

        update_option('menu_popup_menus', array_values($menus));
        wp_send_json_success(__('Menu deleted successfully', 'menu-popup'));
    }

    public function add_new_menu() {
        $nonce_verified = false;
        if (isset($_POST['nonce'])) {
            $nonce_verified = wp_verify_nonce($_POST['nonce'], 'menu_popup_admin_nonce');
        }

        if (isset($_POST['nonce']) && !$nonce_verified) {
            wp_send_json_error(__('Security check failed', 'menu-popup'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'menu-popup'));
        }

        $name = sanitize_text_field($_POST['name']);
        $slug = sanitize_text_field($_POST['slug']);
        $description = sanitize_textarea_field($_POST['description']);
        $categories = isset($_POST['categories']) ? array_map('sanitize_text_field', $_POST['categories']) : array();
        $branches = isset($_POST['branches']) ? array_map('intval', $_POST['branches']) : array(); // New: Branches

        if (empty($name) || empty($slug)) {
            wp_send_json_error(__('Menu name and slug are required', 'menu-popup'));
        }

        // Validate slug format
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            wp_send_json_error(__('Menu slug can only contain lowercase letters, numbers, and hyphens', 'menu-popup'));
        }

        $menus = get_option('menu_popup_menus', array());

        // Check if slug already exists
        foreach ($menus as $menu) {
            if (isset($menu['slug']) && $menu['slug'] === $slug) {
                wp_send_json_error(__('Menu slug already exists', 'menu-popup'));
            }
        }

        $new_menu = array(
            'id' => uniqid('menu_'),
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'categories' => $categories,
            'branches' => $branches // New: Save branches
        );

        $menus[] = $new_menu;

        // Try to update option with error handling
        $update_result = update_option('menu_popup_menus', $menus);

        if ($update_result === false) {
            // If update failed, try to add option (in case it doesn't exist)
            $update_result = add_option('menu_popup_menus', $menus, '', 'no');
        }

        if ($update_result) {
            wp_send_json_success(array(
                'message' => __('Menu added successfully', 'menu-popup'),
                'menu' => $new_menu
            ));
        } else {
            wp_send_json_error(__('Failed to save menu', 'menu-popup'));
        }
    }

    public function update_menu() {
        $nonce_verified = false;
        if (isset($_POST['nonce'])) {
            $nonce_verified = wp_verify_nonce($_POST['nonce'], 'menu_popup_admin_nonce');
        }

        if (isset($_POST['nonce']) && !$nonce_verified) {
            wp_send_json_error(__('Security check failed', 'menu-popup'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'menu-popup'));
        }

        $menu_id = sanitize_text_field($_POST['menu_id']);
        $name = sanitize_text_field($_POST['name']);
        $slug = sanitize_text_field($_POST['slug']);
        $description = sanitize_textarea_field($_POST['description']);
        $categories = isset($_POST['categories']) ? array_map('sanitize_text_field', $_POST['categories']) : array();
        $branches = isset($_POST['branches']) ? array_map('intval', $_POST['branches']) : array(); // New: Branches

        if (empty($name) || empty($slug)) {
            wp_send_json_error(__('Menu name and slug are required', 'menu-popup'));
        }

        // Validate slug format
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            wp_send_json_error(__('Menu slug can only contain lowercase letters, numbers, and hyphens', 'menu-popup'));
        }

        $menus = get_option('menu_popup_menus', array());

        // Find and update the menu
        $menu_found = false;
        foreach ($menus as $index => $menu) {
            if (isset($menu['id']) && $menu['id'] === $menu_id) {
                // Check if slug is being changed and if it's already taken by another menu
                if ($menu['slug'] !== $slug) {
                    foreach ($menus as $check_menu) {
                        if (isset($check_menu['slug']) && $check_menu['slug'] === $slug && $check_menu['id'] !== $menu_id) {
                            wp_send_json_error(__('Menu slug already exists', 'menu-popup'));
                        }
                    }
                }

                $menus[$index] = array(
                    'id' => $menu_id,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                    'categories' => $categories,
                    'branches' => $branches // New: Save branches
                );
                $menu_found = true;
                break;
            }
        }

        if (!$menu_found) {
            wp_send_json_error(__('Menu not found', 'menu-popup'));
        }

        // Validate the menus array structure
        if (!is_array($menus)) {
            wp_send_json_error(__('Invalid menu data structure', 'menu-popup'));
        }

        // Check each menu has required fields
        foreach ($menus as $menu) {
            if (!is_array($menu) || !isset($menu['id']) || !isset($menu['name']) || !isset($menu['slug'])) {
                wp_send_json_error(__('Menu data is malformed', 'menu-popup'));
            }
        }

        // Get existing menus to compare
        $existing_menus = get_option('menu_popup_menus', array());

        // Try to update the option
        $update_result = update_option('menu_popup_menus', $menus);

        if ($update_result === false) {
            // Check if the data is identical (WordPress returns false when no change is needed)
            if ($existing_menus === $menus) {
                // Data is identical - treat as success (no change needed)
                wp_send_json_success(array(
                    'message' => __('Menu updated successfully (no changes made)', 'menu-popup'),
                    'menu' => $menus[$index]
                ));
                return;
            }

            // If update_option fails, try add_option (in case option doesn't exist)
            $update_result = add_option('menu_popup_menus', $menus, '', 'no');
        }

        if ($update_result) {
            wp_send_json_success(array(
                'message' => __('Menu updated successfully', 'menu-popup'),
                'menu' => $menus[$index]
            ));
        } else {
            // Debug: create detailed error info for immediate display
            $existing_menus = get_option('menu_popup_menus', array());
            $debug_info = array(
                'existing_menus_count' => count($existing_menus),
                'new_menus_count' => count($menus),
                'existing_data_size' => strlen(serialize($existing_menus)),
                'new_data_size' => strlen(serialize($menus)),
                'existing_menus_sample' => array_slice($existing_menus, 0, 2), // First 2 menus
                'new_menus_sample' => array_slice($menus, 0, 2), // First 2 menus
                'update_result' => $update_result,
                'last_error' => error_get_last()
            );

            // Try a simple test update to see if options work at all
            $test_result = update_option('menu_popup_test_' . time(), 'test_value_' . time());
            $debug_info['test_option_update'] = $test_result ? 'success' : 'failed';

            // Log to error log as backup
            error_log('Menu Popup Debug - Update failed. Debug info: ' . json_encode($debug_info));

            wp_send_json_error(__('Failed to update menu - database error.', 'menu-popup') . ' Debug: ' . json_encode($debug_info));
        }
    }

    public function get_branches_for_menu_ajax() {
        check_ajax_referer('menu_popup_admin_nonce', 'nonce');

        $menu_id = sanitize_text_field($_POST['menu_id']);
        $menus = get_option('menu_popup_menus', array());

        $selected_branches = array();
        foreach ($menus as $menu) {
            if ($menu['id'] === $menu_id && isset($menu['branches'])) {
                $selected_branches = $menu['branches'];
                break;
            }
        }

        $all_branches = $this->get_all_branches();
        $branch_options_html = '';
        if (!empty($all_branches)) {
            foreach ($all_branches as $branch) {
                $checked = in_array($branch->ID, $selected_branches) ? 'checked' : '';
                $branch_options_html .= '<label class="branch-checkbox">';
                $branch_options_html .= '<input type="checkbox" name="menu_branches[]" value="' . esc_attr($branch->ID) . '" ' . $checked . ' />';
                $branch_options_html .= esc_html($branch->post_title);
                $branch_options_html .= '</label>';
            }
        } else {
            $branch_options_html = '<p class="description">' . __('No branches available. Create branches first.', 'menu-popup') . '</p>';
        }

        wp_send_json_success(array('html' => $branch_options_html));
    }

    // AJAX handler for verifying admin password
    public function verify_admin_password() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'menu_popup_reset_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'menu-popup')));
        }

        // Check capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'menu-popup')));
        }

        $password = isset($_POST['password']) ? $_POST['password'] : '';

        if (empty($password)) {
            wp_send_json_error(array('message' => __('Password is required', 'menu-popup')));
        }

        // Get current user
        $current_user = wp_get_current_user();
        
        // Verify password using WordPress
        if (wp_check_password($password, $current_user->user_pass, $current_user->ID)) {
            wp_send_json_success(array('message' => __('Password verified', 'menu-popup')));
        } else {
            wp_send_json_error(array('message' => __('Incorrect password', 'menu-popup')));
        }
    }

    // AJAX handler for resetting a specific day
    public function reset_accounting_day() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'menu_popup_reset_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'menu-popup')));
        }

        // Check capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'menu-popup')));
        }

        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';

        if (empty($date)) {
            wp_send_json_error(array('message' => __('Date is required', 'menu-popup')));
        }

        // Get current entries
        $entries = get_option('menu_popup_accounting_entries', array());
        
        if (!is_array($entries)) {
            $entries = array();
        }

        // Filter out entries for the specified date
        $new_entries = array();
        $removed_count = 0;
        
        foreach ($entries as $entry) {
            $entry_date = '';
            if (isset($entry['data']['period'])) {
                $entry_date = $entry['data']['period'];
            } elseif (isset($entry['timestamp'])) {
                $entry_date = date('Y-m-d', $entry['timestamp']);
            }
            
            if ($entry_date !== $date) {
                $new_entries[] = $entry;
            } else {
                $removed_count++;
            }
        }

        // Update the option
        update_option('menu_popup_accounting_entries', $new_entries);

        // Log the reset action
        $this->log_accounting_entry('DAY_RESET', array(
            'timestamp' => current_time('timestamp'),
            'user_id' => get_current_user_id(),
            'period' => $date,
            'removed_entries' => $removed_count,
            'action' => 'RESET_DAY'
        ));

        wp_send_json_success(array(
            'message' => sprintf(__('Accounting data for %s has been reset. %d entries removed.', 'menu-popup'), $date, $removed_count)
        ));
    }

    // AJAX handler for resetting all accounting data
    public function reset_all_accounting_data() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'menu_popup_reset_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'menu-popup')));
        }

        // Check capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'menu-popup')));
        }

        // Get count of entries before clearing
        $entries = get_option('menu_popup_accounting_entries', array());
        $entries_count = is_array($entries) ? count($entries) : 0;

        // Clear all accounting entries
        update_option('menu_popup_accounting_entries', array());

        // Reset business day status
        update_option('menu_popup_accounting_business_open', '0');
        delete_option('menu_popup_accounting_day_open_time');

        // Log the reset action
        $this->log_accounting_entry('ALL_DATA_RESET', array(
            'timestamp' => current_time('timestamp'),
            'user_id' => get_current_user_id(),
            'period' => date('Y-m-d'),
            'removed_entries' => $entries_count,
            'action' => 'RESET_ALL'
        ));

        wp_send_json_success(array(
            'message' => sprintf(__('All accounting data has been reset. %d entries removed.', 'menu-popup'), $entries_count)
        ));
    }
}

// Initialize the settings page
new Menu_Popup_Settings();
?>
