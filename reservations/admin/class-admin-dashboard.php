<?php
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Reservations_Admin_Dashboard
{

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX Endpoints
        add_action('wp_ajax_menu_popup_update_reservation_status', array($this, 'handle_status_update'));
        add_action('wp_ajax_menu_popup_delete_reservation', array($this, 'handle_delete_reservation'));
        add_action('wp_ajax_menu_popup_bulk_delete_reservations', array($this, 'handle_bulk_delete_reservations'));
        add_action('wp_ajax_menu_popup_get_table_reservations', array($this, 'get_table_reservations'));
        add_action('wp_ajax_menu_popup_get_live_status', array($this, 'get_live_status'));
    }

    public function add_admin_menu()
    {
        add_submenu_page(
            'menu-popup-dashboard',
            __('Reservations', 'menu-popup'),
            __('Reservations', 'menu-popup'),
            'manage_options',
            'menu-popup-reservations',
            array($this, 'render_dashboard')
        );
    }

    public function enqueue_admin_assets($hook)
    {
        // Load assets for reservation admin pages always for reservation pages
        $load_assets = false;

        if (strpos($hook, 'menu-popup-reservations') !== false ||
            $hook === 'menu_popup_dashboard_page_menu-popup-reservations' ||
            $hook === 'menu-pop-up_page_menu-popup-reservations' ||
            strpos($_GET['page'] ?? '', 'menu-popup-reservations') !== false) {
            $load_assets = true;
        }

        if ($load_assets) {
            wp_enqueue_style(
                'menu-popup-reservation-admin',
                MENU_POPUP_RESERVATIONS_URL . 'assets/css/reservation-style.css',
                array(),
                '1.0.0'
            );

            wp_enqueue_script(
                'menu-popup-reservation-admin',
                MENU_POPUP_RESERVATIONS_URL . 'assets/js/reservation-admin.js',
                array('jquery', 'jquery-ui-draggable', 'jquery-ui-droppable'),
                '1.0.0',
                true
            );

            wp_localize_script('menu-popup-reservation-admin', 'menuPopupReservation', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('menu_popup_reservation_admin_nonce')
            ));
        }
    }

    public function render_dashboard()
    {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'cards';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Reservations Mission Control', 'menu-popup'); ?></h1>
            <p class="description"><?php _e('Professional reservation management system.', 'menu-popup'); ?></p>

            <nav class="nav-tab-wrapper">
                <a href="?page=menu-popup-reservations&tab=cards" class="nav-tab <?php echo $active_tab == 'cards' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Professional Cards', 'menu-popup'); ?>
                </a>
                <a href="?page=menu-popup-reservations&tab=map" class="nav-tab <?php echo $active_tab == 'map' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Live Floor Plan', 'menu-popup'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php if ($active_tab == 'map'): ?>
                    <?php $this->render_map_view(); ?>
                <?php else: ?>
                    <?php $this->render_list_view(); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_map_view()
    {
        // Get all tables for floor plan
        $args = array(
            'post_type' => 'restaurant_table',
            'posts_per_page' => -1,
            'orderby' => 'menu_order',
            'order' => 'ASC'
        );

        $tables = get_posts($args);

        // Get current live status for today
        $today = date('Y-m-d');

        // Get all existing reservations for today
        $reservations_today = new WP_Query(array(
            'post_type' => 'menu_reservation',
            'meta_query' => array(
                array('key' => '_reservation_date', 'value' => $today, 'compare' => '='),
                array('key' => '_status', 'value' => array('confirmed', 'pending'), 'compare' => 'IN')
            ),
            'posts_per_page' => -1
        ));

        // Track table reservations and status
        $table_status = array();
        $table_reservations = array();

        // Initialize all tables as free
        foreach ($tables as $table) {
            $table_status[$table->ID] = 'free';
            $table_reservations[$table->ID] = array();
        }

        // Parse today's reservations
        if ($reservations_today->have_posts()) {
            while ($reservations_today->have_posts()) {
                $reservations_today->the_post();
                $reservation_id = get_the_ID();
                $assigned_table = get_post_meta($reservation_id, '_assigned_table', true);
                $reservation_time = get_post_meta($reservation_id, '_reservation_time', true);
                $party_size = intval(get_post_meta($reservation_id, '_party_size', true));
                $status = get_post_meta($reservation_id, '_status', true);
                $customer_name = get_post_meta($reservation_id, '_customer_name', true);

                if ($assigned_table && isset($table_reservations[$assigned_table])) {
                    $table_reservations[$assigned_table][] = array(
                        'time' => $reservation_time,
                        'party_size' => $party_size,
                        'status' => $status,
                        'customer' => $customer_name,
                        'id' => $reservation_id
                    );
                }
            }
            wp_reset_postdata();
        }

        // Determine current table status based on time
        $current_time = date('H:i');
        $current_hour = (int)substr($current_time, 0, 2);
        $current_minute = (int)substr($current_time, 3, 2);
        $current_minutes = $current_hour * 60 + $current_minute;

        foreach ($tables as $table) {
            $table_id = $table->ID;

            if (!empty($table_reservations[$table_id])) {
                foreach ($table_reservations[$table_id] as $reservation) {
                    if ($reservation['status'] === 'confirmed') {
                        $res_time = $reservation['time'];
                        $res_hour = (int)substr($res_time, 0, 2);
                        $res_minute = (int)substr($res_time, 3, 2);
                        $res_minutes = $res_hour * 60 + $res_minute;

                        // Assume 2-hour reservation duration
                        $reservation_end = $res_minutes + 120;

                        if ($current_minutes >= $res_minutes && $current_minutes <= $reservation_end) {
                            $table_status[$table_id] = 'occupied';
                            break;
                        } elseif ($current_minutes < $res_minutes) {
                            $table_status[$table_id] = 'reserved';
                        }
                    }
                }
            }
        }

        // Group tables by zone
        $zone_tables = array();
        foreach ($tables as $table) {
            $zone = get_post_meta($table->ID, '_zone', true) ?: 'indoor';
            $zone_tables[$zone][] = $table;
        }
        ?>
        <div class="live-map-container">
            <!-- Professional Live Status Toolbar -->
            <div class="map-toolbar premium-toolbar">
                <div class="status-legend">
                    <span class="status-indicator free">
                        <span class="indicator-dot"></span>
                        <span class="indicator-text">Available</span>
                    </span>
                    <span class="status-indicator reserved">
                        <span class="indicator-dot"></span>
                        <span class="indicator-text">Reserved</span>
                    </span>
                    <span class="status-indicator occupied">
                        <span class="indicator-dot"></span>
                        <span class="indicator-text">Occupied</span>
                    </span>
                </div>

                <div class="toolbar-actions">
                    <button id="refresh-live-status" class="button premium-button">
                        <span class="btn-icon">🔄</span>
                        Refresh Live Status
                    </button>
                    <button id="toggle-arrangement-mode" class="button premium-button">
                        <span class="btn-icon">🏗️</span>
                        Floor Plan Mode
                    </button>
                    <div class="live-indicator">
                        <span class="live-dot pulsing"></span>
                        <span class="live-text">Live View Active</span>
                        <span class="current-time-display"><?php echo date('H:i'); ?></span>
                    </div>
                </div>

                <div class="quick-stats">
                    <span class="stat-item">
                        <span class="stat-number"><?php echo count($tables); ?></span>
                        <span class="stat-label">Tables</span>
                    </span>
                    <span class="stat-item">
                        <span class="stat-number"><?php echo $reservations_today->found_posts; ?></span>
                        <span class="stat-label">Today's Bookings</span>
                    </span>
                    <span class="stat-item">
                        <span class="stat-number"><?php echo array_count_values($table_status)['free'] ?? 0; ?></span>
                        <span class="stat-label">Available Now</span>
                    </span>
                </div>
            </div>

            <!-- Restaurant Floor Plan Canvas -->
            <div class="restaurant-floor-plan live-floor-plan" id="live-floor-plan">
                <div class="floor-plan-header">
                    <h3 class="floor-plan-title">🍽️ Restaurant Floor Plan - Live Status</h3>
                    <p class="floor-plan-subtitle">
                        <?php echo count($tables); ?> tables • <?php echo date('l, F j, Y'); ?> • Live updates every 30 seconds
                    </p>
                </div>

                <?php
                // Render tables by zone
                foreach ($zone_tables as $zone => $zone_tables_list):
                    if (empty($zone_tables_list)) continue;

                    $zone_title = ucfirst($zone) . ' Area';
                    $zone_icon = ($zone === 'vip') ? '👑' : (($zone === 'terrace') ? '🌳' : '🏠');
                    $total_tables_in_zone = count($zone_tables_list);
                    ?>
                    <div class="floor-plan-zone zone-<?php echo $zone; ?>">
                        <div class="zone-header">
                            <span class="zone-icon"><?php echo $zone_icon; ?></span>
                            <span class="zone-title"><?php echo $zone_title; ?></span>
                            <span class="zone-count"><?php echo $total_tables_in_zone; ?> tables</span>
                        </div>

                        <div class="zone-floor-plan">
                            <?php foreach ($zone_tables_list as $table):
                                $table_id = $table->ID;
                                $table_name = $table->post_title;
                                $capacity = get_post_meta($table_id, '_max_pax', true) ?: 4;
                                $shape = get_post_meta($table_id, '_shape', true) ?: 'round';
                                $status = $table_status[$table_id];
                                $position_x = get_post_meta($table_id, '_table_position_x', true) ?: (50 + ($table_id % 8) * 100);
                                $position_y = get_post_meta($table_id, '_table_position_y', true) ?: (50 + (floor($table_id / 8) % 4) * 80);

                                // Size based on capacity
                                $size_multiplier = ($capacity <= 2) ? 0.8 : (($capacity <= 4) ? 1 : 1.2);
                                $base_width = 80 * $size_multiplier;
                                $base_height = 80 * $size_multiplier;

                                // Status colors and information
                                $status_classes = "table-node status-$status shape-$shape zone-$zone capacity-$capacity";
                                $status_text = ($status === 'free') ? 'Available' : (($status === 'reserved') ? 'Reserved' : 'Occupied');

                                // Get current reservation details
                                $current_reservation = null;
                                if (!empty($table_reservations[$table_id])) {
                                    foreach ($table_reservations[$table_id] as $reservation) {
                                        if ($reservation['status'] === 'confirmed') {
                                            $current_reservation = $reservation;
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <div class="table-node <?php echo $status_classes; ?>"
                                     data-id="<?php echo $table_id; ?>"
                                     data-shape="<?php echo $shape; ?>"
                                     data-zone="<?php echo $zone; ?>"
                                     data-capacity="<?php echo $capacity; ?>"
                                     id="table-<?php echo $table_id; ?>"
                                     style="left: <?php echo $position_x; ?>px; top: <?php echo $position_y; ?>px; width: <?php echo $base_width; ?>px; height: <?php echo $base_height; ?>px;">

                                    <div class="table-visual">
                                        <div class="table-shape shape-<?php echo $shape; ?>" style="font-size: <?php echo 12 * $size_multiplier; ?>px;">
                                            <?php echo esc_html($table_name); ?>
                                        </div>
                                        <div class="table-status-indicator <?php echo $status; ?>">
                                            <span class="status-dot"></span>
                                        </div>
                                    </div>

                                    <div class="table-info">
                                        <div class="table-status"><?php echo $status_text; ?></div>
                                        <div class="table-capacity"><?php echo $capacity; ?> seats</div>
                                        <?php if ($current_reservation): ?>
                                            <div class="table-reservation-info">
                                                <div class="customer-name">👤 <?php echo esc_html($current_reservation['customer']); ?></div>
                                                <div class="reservation-time">⏰ <?php echo $current_reservation['time']; ?> (<?php echo $current_reservation['party_size']; ?> guests)</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <script>
            jQuery(document).ready(function($) {
                // Define AJAX variables with fallback if not localized
                if (typeof menuPopupReservation === 'undefined') {
                    window.menuPopupReservation = {
                        ajax_url: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                        nonce: '<?php echo wp_create_nonce('menu_popup_reservation_admin_nonce'); ?>'
                    };
                }

                // Debug: Check if AJAX variables are loaded
                console.log('MenuPopupReservation AJAX Debug:', menuPopupReservation);
                console.log('AJAX URL:', menuPopupReservation.ajax_url);
                console.log('AJAX Nonce:', menuPopupReservation.nonce);

                let arrangementMode = false;
                let statusRefreshInterval;

                // Auto-refresh live status every 30 seconds
                function startAutoRefresh() {
                    statusRefreshInterval = setInterval(function() {
                        refreshTableStatus();
                        updateCurrentTime();
                    }, 30000);
                }

                function stopAutoRefresh() {
                    if (statusRefreshInterval) {
                        clearInterval(statusRefreshInterval);
                        statusRefreshInterval = null;
                    }
                }

                function updateCurrentTime() {
                    const now = new Date();
                    const timeString = now.toTimeString().substring(0, 5);
                    $('.current-time-display').text(timeString);
                }

                function refreshTableStatus() {
                    $.ajax({
                        url: menuPopupReservation.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'menu_popup_get_live_status',
                            nonce: menuPopupReservation.nonce
                        },
                        success: function(response) {
                            if (response.success && response.data) {
                                // Update table statuses
                                $.each(response.data, function(tableId, status) {
                                    const tableElement = $('#table-' + tableId);
                                    if (tableElement.length) {
                                        // Remove old status classes
                                        tableElement.removeClass('status-free status-reserved status-occupied');
                                        // Add new status class
                                        tableElement.addClass('status-' + status);

                                        // Update status text
                                        const statusText = tableElement.find('.table-status');
                                        const statusTexts = {
                                            'free': 'Available',
                                            'reserved': 'Reserved',
                                            'occupied': 'Occupied'
                                        };
                                        statusText.text(statusTexts[status] || 'Unknown');
                                    }
                                });
                            }
                        }
                    });
                }

                // Manual refresh button
                $('#refresh-live-status').on('click', function() {
                    $(this).prop('disabled', true).text('Refreshing...');
                    refreshTableStatus();
                    setTimeout(function() {
                        $('#refresh-live-status').prop('disabled', false).html('<span class="btn-icon">🔄</span> Refresh Live Status');
                    }, 1000);
                });

                // Toggle arrangement mode
                $('#toggle-arrangement-mode').on('click', function() {
                    arrangementMode = !arrangementMode;

                    if (arrangementMode) {
                        stopAutoRefresh();
                        $('.table-node').addClass('arrangement-mode').draggable({
                            containment: ".restaurant-floor-plan",
                            stop: function(event, ui) {
                                const tableId = $(this).data('id');
                                const x = ui.position.left;
                                const y = ui.position.top;

                                // Save position via AJAX
                                $.ajax({
                                    url: menuPopupReservation.ajax_url,
                                    type: 'POST',
                                    data: {
                                        action: 'menu_popup_save_table_position',
                                        table_id: tableId,
                                        x: x,
                                        y: y,
                                        nonce: menuPopupReservation.nonce
                                    },
                                    success: function(response) {
                                        console.log('Position saved for table ' + tableId);
                                    }
                                });
                            }
                        });
                        $(this).html('<span class="btn-icon">✏️</span> Exit Floor Plan Mode');
                        $('.live-indicator').hide();
                    } else {
                        startAutoRefresh();
                        $('.table-node').removeClass('arrangement-mode').draggable('destroy');
                        $(this).html('<span class="btn-icon">🏗️</span> Floor Plan Mode');
                        $('.live-indicator').show();
                    }
                });

                // TABLE CLICK FOR INFORMATION DISPLAY - SHOW ALL RESERVATIONS
                $('.table-node').on('click', function(e) {
                    // Don't trigger if in arrangement mode
                    if (arrangementMode) return;

                    e.stopPropagation();
                    const $table = $(this);
                    const tableId = $table.data('id');
                    const tableName = $table.find('.table-name').text();
                    const tableStatus = $table.find('.table-status').text();
                    const tableCapacity = $table.find('.table-capacity').text();

                    // Remove active from all tables
                    $('.table-node').removeClass('active');
                    // Add active to clicked table
                    $table.addClass('active');

                    // Get ALL reservations for this table today
                    getAllTableReservations(tableId, tableName, tableStatus, tableCapacity);
                });

                function getAllTableReservations(tableId, tableName, tableStatus, tableCapacity) {
                    $.ajax({
                        url: menuPopupReservation.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'menu_popup_get_table_reservations',
                            table_id: tableId,
                            nonce: menuPopupReservation.nonce
                        },
                        success: function(response) {
                            let infoText = 'Table: ' + tableName + '\nStatus: ' + tableStatus + '\nCapacity: ' + tableCapacity;

                            if (response.success && response.data && response.data.length > 0) {
                                infoText += '\n\n📅 TODAY\'S RESERVATIONS (' + response.data.length + '):';

                                response.data.forEach(function(reservation, index) {
                                    const time = reservation.time || 'N/A';
                                    const customer = reservation.customer || 'Unknown';
                                    const partySize = reservation.party_size || 'N/A';
                                    const status = reservation.status || 'unknown';

                                    const statusIcon = (status === 'confirmed') ? '✅' : (status === 'pending') ? '⏳' : '❓';

                                    infoText += '\n\n' + (index + 1) + '. [' + statusIcon + '] ' + status.toUpperCase();
                                    infoText += '\n   👤 Customer: ' + customer;
                                    infoText += '\n   ⏰ Time: ' + time;
                                    infoText += '\n   👥 Party Size: ' + partySize + ' guests';
                                });
                            } else {
                                infoText += '\n\n📅 No reservations for today';
                            }

                            alert(infoText);
                        },
                        error: function() {
                            const infoText = 'Table: ' + tableName + '\nStatus: ' + tableStatus + '\nCapacity: ' + tableCapacity + '\n\n❌ Could not load reservation details';
                            alert(infoText);
                        }
                    });
                }

                // Start auto-refresh when page loads
                startAutoRefresh();

                // Initial time update
                updateCurrentTime();

                // Clean up on page unload
                $(window).on('beforeunload', function() {
                    stopAutoRefresh();
                });
            });
            </script>

            <div class="map-instructions">
                <p><strong>Live Floor Plan Instructions:</strong></p>
                <ul>
                    <li><strong>Real-time Status:</strong> Tables update automatically every 30 seconds</li>
                    <li><strong>Color Coding:</strong> Green = Available, Yellow = Reserved, Red = Occupied</li>
                    <li><strong>Floor Plan Mode:</strong> Click to drag and rearrange table positions</li>
                    <li><strong>Table Details:</strong> Hover over tables to see reservation information</li>
                </ul>
            </div>
        </div>
        <?php
    }

    private function render_list_view()
    {
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';

        $args = array(
            'post_type' => 'menu_reservation',
            'posts_per_page' => 20,
            'paged' => isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1,
            'orderby' => 'meta_value',
            'meta_key' => '_reservation_date',
            'order' => 'DESC'
        );

        if ($status_filter !== 'all') {
            $args['meta_query'] = array(
                array(
                    'key' => '_status',
                    'value' => $status_filter,
                    'compare' => '='
                )
            );
        }

        $reservations = new WP_Query($args);

        // Professional filters with bulk actions
        ?>
        <div class="dashboard-filters premium-filters" style="background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%); border-radius: 12px; padding: 20px; margin-bottom: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
            <div class="filters-section">
                <label>Status Filter:</label>
                <select id="status-filter" style="padding: 8px 12px; border: 2px solid #e2e8f0; border-radius: 8px; margin-left: 10px;">
                    <option value="all" <?php selected($status_filter, 'all'); ?>>All Reservations</option>
                    <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
                    <option value="confirmed" <?php selected($status_filter, 'confirmed'); ?>>Confirmed</option>
                    <option value="completed" <?php selected($status_filter, 'completed'); ?>>Completed</option>
                    <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>>Cancelled</option>
                </select>
                <button id="apply-filters" class="button button-primary" style="margin-left: 15px;">Apply Filters</button>
            </div>

            <div class="bulk-actions-section" id="bulk-actions-container" style="display: none;">
                <div style="display: flex; align-items: center; gap: 10px; margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(226, 232, 240, 0.5);">
                    <span class="bulk-actions-count" id="bulk-count">0 selected</span>
                    <button id="bulk-delete-btn" class="button button-danger" style="background: #dc3545; border-color: #dc3545; color: white;">🗑️ Delete Selected</button>
                    <button id="select-all-btn" class="button button-secondary">📋 Select All</button>
                    <button id="clear-selection-btn" class="button button-secondary">❌ Clear</button>
                </div>
            </div>
        </div>

        <div class="reservation-cards-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px;">
            <?php if ($reservations->have_posts()): ?>
                <?php while ($reservations->have_posts()):
                    $reservations->the_post();
                    $id = get_the_ID();
                    $status = get_post_meta($id, '_status', true);
                    $customer_name = get_post_meta($id, '_customer_name', true);
                    $party_size = intval(get_post_meta($id, '_party_size', true));
                    $reservation_date = get_post_meta($id, '_reservation_date', true);
                    $reservation_time = get_post_meta($id, '_reservation_time', true);
                    $assigned_table = get_post_meta($id, '_assigned_table', true);
                    $table_name = $assigned_table ? get_the_title($assigned_table) : 'Not Assigned';

                    $status_colors = array(
                        'pending' => '#f6ad55',
                        'confirmed' => '#48bb78',
                        'completed' => '#9f7aea',
                        'cancelled' => '#fc8181'
                    );

                    $status_icons = array(
                        'pending' => '⏳',
                        'confirmed' => '✅',
                        'completed' => '🎉',
                        'cancelled' => '❌'
                    );
                    ?>
                    <div class="reservation-card" style="background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 24px; transition: transform 0.3s ease;">
                        <div class="card-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 16px; margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div class="reservation-id" style="font-size: 18px; font-weight: 700; color: #2d3748;">Reservation #<?php echo $id; ?></div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="checkbox" class="reservation-checkbox" value="<?php echo $id; ?>" style="width: 18px; height: 18px;">
                                    <button class="action-btn delete-btn" data-id="<?php echo $id; ?>" style="padding: 6px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;" title="Delete Reservation">🗑️</button>
                                </div>
                            </div>
                            <div class="status-badge" style="padding: 6px 12px; border-radius: 20px; color: white; font-weight: 600; background-color: <?php echo $status_colors[$status]; ?>; margin-top: 10px;">
                                <span style="margin-right: 6px;"><?php echo $status_icons[$status]; ?></span>
                                <?php echo ucfirst($status); ?>
                            </div>
                        </div>

                        <div class="customer-info" style="margin-bottom: 20px;">
                            <h4 style="margin: 0 0 12px 0; font-size: 16px; color: #2d3748;"><?php echo esc_html($customer_name); ?></h4>
                            <div style="display: grid; gap: 8px;">
                                <div>👥 Party: <?php echo $party_size; ?> guests</div>
                                <div>📅 Date: <?php echo date('M j, Y', strtotime($reservation_date)); ?> @ <?php echo $reservation_time; ?></div>
                                <div>🪑 Table: <?php echo esc_html($table_name); ?></div>
                            </div>
                        </div>

                        <div class="card-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <?php if ($status === 'pending'): ?>
                                <button class="action-btn confirm-btn" data-id="<?php echo $id; ?>" style="padding: 8px 16px; background: #48bb78; color: white; border: none; border-radius: 8px; cursor: pointer;">✅ Confirm</button>
                                <button class="action-btn cancel-btn" data-id="<?php echo $id; ?>" style="padding: 8px 16px; background: #e53e3e; color: white; border: none; border-radius: 8px; cursor: pointer;">❌ Cancel</button>
                            <?php elseif ($status === 'confirmed'): ?>
                                <button class="action-btn complete-btn" data-id="<?php echo $id; ?>" style="padding: 8px 16px; background: #9f7aea; color: white; border: none; border-radius: 8px; cursor: pointer;">🎉 Complete</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-reservations" style="grid-column: 1 / -1; text-align: center; padding: 60px 20px; background: #fff; border-radius: 16px; border: 2px dashed #e2e8f0;">
                    <div style="font-size: 60px; margin-bottom: 20px;">📅</div>
                    <h3 style="color: #4a5568; margin-bottom: 10px;">No reservations found</h3>
                    <p style="color: #718096;">No reservations match your current filters.</p>
                </div>
            <?php endif; wp_reset_postdata(); ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#apply-filters').on('click', function() {
                const status = $('#status-filter').val();
                const url = new URL(window.location);
                url.searchParams.set('status', status);
                window.location.href = url.toString();
            });

            $('.action-btn').on('click', function() {
                const btn = $(this);
                const action = btn.hasClass('confirm-btn') ? 'confirmed' :
                              btn.hasClass('cancel-btn') ? 'cancelled' :
                              btn.hasClass('complete-btn') ? 'completed' : '';

                if (action) {
                    const reservationId = btn.data('id');
                    $.ajax({
                        url: menuPopupReservation.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'menu_popup_update_reservation_status',
                            reservation_id: reservationId,
                            status: action,
                            nonce: menuPopupReservation.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert('Error updating reservation: ' + response.data);
                            }
                        }
                    });
                }
            });

            // INDIVIDUAL DELETE BUTTON
            $('.delete-btn').on('click', function() {
                const reservationId = $(this).data('id');
                if (confirm('Are you sure you want to delete this reservation? This action cannot be undone.')) {
                    $.ajax({
                        url: menuPopupReservation.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'menu_popup_delete_reservation',
                            reservation_id: reservationId,
                            nonce: menuPopupReservation.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert('Error deleting reservation: ' + response.data);
                            }
                        }
                    });
                }
            });

            // BULK DELETE FUNCTIONALITY
            let selectedReservations = [];

            $('.reservation-checkbox').on('change', function() {
                const reservationId = $(this).val();

                if (this.checked) {
                    if (!selectedReservations.includes(reservationId)) {
                        selectedReservations.push(reservationId);
                    }
                } else {
                    const index = selectedReservations.indexOf(reservationId);
                    if (index > -1) {
                        selectedReservations.splice(index, 1);
                    }
                }

                // Update bulk actions UI
                const $bulkContainer = $('#bulk-actions-container');
                const $count = $('#bulk-count');

                if (selectedReservations.length > 0) {
                    $bulkContainer.show();
                    $count.text(selectedReservations.length + ' selected');
                } else {
                    $bulkContainer.hide();
                    $count.text('0 selected');
                }
            });

            $('#select-all-btn').on('click', function() {
                $('.reservation-checkbox').prop('checked', true).trigger('change');
            });

            $('#clear-selection-btn').on('click', function() {
                $('.reservation-checkbox').prop('checked', false).trigger('change');
            });

            $('#bulk-delete-btn').on('click', function() {
                if (selectedReservations.length === 0) {
                    alert('No reservations selected');
                    return;
                }

                if (confirm('Are you sure you want to delete ' + selectedReservations.length + ' reservation(s)? This action cannot be undone.')) {
                    $.ajax({
                        url: menuPopupReservation.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'menu_popup_bulk_delete_reservations',
                            reservation_ids: selectedReservations,
                            nonce: menuPopupReservation.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                alert(response.data);
                                location.reload();
                            } else {
                                alert('Error deleting reservations: ' + response.data);
                            }
                        }
                    });
                }
            });
        });
        </script>
        <?php
    }

    public function handle_status_update()
    {
        check_ajax_referer('menu_popup_reservation_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Not allowed');
            return;
        }
        $reservation_id = intval($_POST['reservation_id']);
        $new_status = sanitize_text_field($_POST['status']);

        if (get_post_type($reservation_id) !== 'menu_reservation'
            || !in_array($new_status, array('pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'), true)) {
            wp_send_json_error('Unknown reservation or status');
            return;
        }

        // Bringing a cancelled / finished reservation back must not collide with a newer booking
        $old_status = (string) get_post_meta($reservation_id, '_status', true);
        $active = Menu_Popup_Availability_Engine::ACTIVE_STATUSES;
        if (in_array($new_status, $active, true) && !in_array($old_status, $active, true)) {
            $engine = new Menu_Popup_Availability_Engine();
            if (!$engine->can_reactivate($reservation_id)) {
                wp_send_json_error('This table is already booked at that time by another reservation, so this one can not be restored.');
                return;
            }
        }

        // update_post_meta returns meta_id on success or false on failure
        $result = update_post_meta($reservation_id, '_status', $new_status);

        if ($result !== false) {
            wp_send_json_success('Reservation status updated successfully');
        } else {
            wp_send_json_error('Failed to update reservation status');
        }
    }

    public function handle_delete_reservation()
    {
        check_ajax_referer('menu_popup_reservation_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        $reservation_id = intval($_POST['reservation_id']);

        if (wp_delete_post($reservation_id, true)) {
            wp_send_json_success('Reservation deleted successfully');
        } else {
            wp_send_json_error('Failed to delete reservation');
        }
    }

    public function handle_bulk_delete_reservations()
    {
        check_ajax_referer('menu_popup_reservation_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        $reservation_ids = array_map('intval', $_POST['reservation_ids'] ?? array());

        if (empty($reservation_ids)) {
            wp_send_json_error('No reservations selected for deletion');
            return;
        }

        $deleted_count = 0;
        foreach ($reservation_ids as $id) {
            if (wp_delete_post($id, true)) {
                $deleted_count++;
            }
        }

        if ($deleted_count > 0) {
            wp_send_json_success("Successfully deleted {$deleted_count} reservation(s)");
        } else {
            wp_send_json_error('Failed to delete any reservations');
        }
    }

    public function get_live_status()
    {
        check_ajax_referer('menu_popup_reservation_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        // Return live status of all tables for today
        $today = date('Y-m-d');
        $availability_engine = new Menu_Popup_Availability_Engine();

        $tables = get_posts(array('post_type' => 'restaurant_table', 'posts_per_page' => -1, 'fields' => 'ids'));
        $status_map = array();

        foreach ($tables as $table_id) {
            // Check if occupied NOW
            $now = date('H:i');
            if (!$availability_engine->is_table_available($table_id, $today, $now, 30)) {
                $status_map[$table_id] = 'occupied';
            } else {
                // Check if reserved today
                $reservations = new WP_Query(array(
                    'post_type' => 'menu_reservation',
                    'meta_query' => array(
                        'relation' => 'AND',
                        array('key' => '_assigned_table', 'value' => $table_id, 'compare' => '='),
                        array('key' => '_reservation_date', 'value' => $today, 'compare' => '='),
                        array('key' => '_status', 'value' => array('confirmed', 'pending'), 'compare' => 'IN')
                    ),
                    'posts_per_page' => 1
                ));

                if ($reservations->have_posts()) {
                    $status_map[$table_id] = 'reserved';
                } else {
                    $status_map[$table_id] = 'free';
                }
                wp_reset_postdata();
            }
        }

        wp_send_json_success($status_map);
    }

    public function get_table_reservations()
    {
        check_ajax_referer('menu_popup_reservation_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $table_id = intval($_POST['table_id']);
        $today = date('Y-m-d');

        if (!$table_id) {
            wp_send_json_error('Invalid table ID');
            return;
        }

        // Get all reservations for this table today
        $reservations_query = new WP_Query(array(
            'post_type' => 'menu_reservation',
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => '_assigned_table', 'value' => $table_id, 'compare' => '='),
                array('key' => '_reservation_date', 'value' => $today, 'compare' => '=')
            ),
            'posts_per_page' => -1,
            'orderby' => 'meta_value',
            'meta_key' => '_reservation_time',
            'order' => 'ASC'
        ));

        $reservations_data = array();

        if ($reservations_query->have_posts()) {
            while ($reservations_query->have_posts()) {
                $reservations_query->the_post();
                $reservation_id = get_the_ID();
                $reservation_time = get_post_meta($reservation_id, '_reservation_time', true);
                $party_size = intval(get_post_meta($reservation_id, '_party_size', true));
                $status = get_post_meta($reservation_id, '_status', true);
                $customer_name = get_post_meta($reservation_id, '_customer_name', true);

                $reservations_data[] = array(
                    'id' => $reservation_id,
                    'time' => $reservation_time,
                    'party_size' => $party_size,
                    'status' => $status,
                    'customer' => $customer_name
                );
            }
            wp_reset_postdata();
        }

        wp_send_json_success($reservations_data);
    }

    public function save_table_position()
    {
        check_ajax_referer('menu_popup_reservation_admin_nonce', 'nonce');

        $table_id = intval($_POST['table_id']);
        $x = floatval($_POST['x']);
        $y = floatval($_POST['y']);

        if (!$table_id) {
            wp_send_json_error('Invalid table ID');
        }

        update_post_meta($table_id, '_table_position_x', $x);
        update_post_meta($table_id, '_table_position_y', $y);

        wp_send_json_success();
    }
}

// End of file
