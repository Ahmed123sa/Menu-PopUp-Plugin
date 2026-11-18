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
        add_action('wp_ajax_menu_popup_live_snapshot', array($this, 'ajax_live_snapshot'));
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

            if (isset($_GET['tab']) && $_GET['tab'] === 'map') {
                wp_enqueue_style('menu-popup-live-floor', MENU_POPUP_RESERVATIONS_URL . 'assets/css/live-floor.css', array(), MENU_POPUP_VERSION);
                wp_enqueue_script('menu-popup-live-floor', MENU_POPUP_RESERVATIONS_URL . 'assets/js/live-floor.js', array('jquery'), MENU_POPUP_VERSION, true);
                wp_localize_script('menu-popup-live-floor', 'mpLiveFloor', array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('menu_popup_reservation_admin_nonce'),
                    'interval' => 20,
                    'snapshot' => $this->build_live_snapshot(),
                    'tablesUrl' => (class_exists('Menu_Popup_Table_Ordering') && Menu_Popup_Table_Ordering::enabled()) ? admin_url('admin.php?page=menu-popup-tables') : '',
                    'i18n' => array(
                        'available' => __('Available', 'menu-popup'), 'reserved' => __('Reserved', 'menu-popup'),
                        'occupied' => __('Occupied', 'menu-popup'), 'waiter' => __('Waiter called', 'menu-popup'),
                        'bill' => __('Bill requested', 'menu-popup'), 'overstay' => __('Over time', 'menu-popup'),
                        'unconfirmed' => __('Not confirmed', 'menu-popup'), 'guests' => __('guests', 'menu-popup'),
                        'since' => __('from', 'menu-popup'), 'until' => __('until', 'menu-popup'),
                        'openOrders' => __('open order(s)', 'menu-popup'), 'due' => __('due', 'menu-popup'),
                        'freeAllDay' => __('Free for the rest of the day', 'menu-popup'), 'next' => __('Next', 'menu-popup'),
                        'late' => __('late', 'menu-popup'), 'min' => __('min', 'menu-popup'), 'seats' => __('seats', 'menu-popup'),
                        'none' => __('No tables match this filter.', 'menu-popup'), 'close' => __('Close', 'menu-popup'),
                        'openTables' => __('Open Tables & QR', 'menu-popup'), 'today' => __("Today's reservations", 'menu-popup'),
                        'noRes' => __('No reservations today.', 'menu-popup'), 'confirm' => __('Confirm', 'menu-popup'),
                        'seat' => __('Seat guests', 'menu-popup'), 'noShow' => __('No-show', 'menu-popup'),
                        'cancel' => __('Cancel', 'menu-popup'), 'complete' => __('Complete', 'menu-popup'),
                        'updated' => __('Updated', 'menu-popup'), 'sure' => __('Are you sure?', 'menu-popup'),
                        'failed' => __('Could not update. Please try again.', 'menu-popup'),
                        'st' => array(
                            'pending' => __('Pending', 'menu-popup'), 'confirmed' => __('Confirmed', 'menu-popup'),
                            'seated' => __('Seated', 'menu-popup'), 'completed' => __('Completed', 'menu-popup'),
                            'cancelled' => __('Cancelled', 'menu-popup'), 'no_show' => __('No-show', 'menu-popup'),
                        ),
                    ),
                ));
            }

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

    /**
     * One place that decides what every table is doing right now.
     * Used for the first paint and for the auto refresh, so they can never disagree.
     */
    public function build_live_snapshot()
    {
        $today = wp_date('Y-m-d');
        $now_min = (int) wp_date('G') * 60 + (int) wp_date('i');
        $soon = (int) apply_filters('menu_popup_live_reserved_window', 120);
        $active = Menu_Popup_Availability_Engine::ACTIVE_STATUSES;

        $to_min = function ($time) {
            return preg_match('/(\d{1,2}):(\d{2})/', (string) $time, $m) ? ((int) $m[1] * 60 + (int) $m[2]) : -1;
        };
        $fmt = function ($min) {
            $min = ((int) $min) % 1440;
            return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
        };

        $tables = get_posts(array(
            'post_type' => 'restaurant_table',
            'posts_per_page' => -1,
            'orderby' => array('menu_order' => 'ASC', 'title' => 'ASC'),
            'no_found_rows' => true,
        ));

        // Today's reservations, grouped by table
        $by_table = array();
        $bookings = 0;
        $guests = 0;
        $q = new WP_Query(array(
            'post_type' => 'menu_reservation',
            'posts_per_page' => 500,
            'no_found_rows' => true,
            'meta_query' => array(array('key' => '_reservation_date', 'value' => $today)),
        ));
        foreach ($q->posts as $p) {
            $tid = (int) get_post_meta($p->ID, '_assigned_table', true);
            $status = (string) get_post_meta($p->ID, '_status', true);
            $start = $to_min(get_post_meta($p->ID, '_reservation_time', true));
            if (!$tid || $start < 0) {
                continue;
            }
            $dur = (int) get_post_meta($p->ID, '_duration', true);
            $dur = $dur > 0 ? $dur : Menu_Popup_Availability_Engine::default_duration();
            $party = (int) get_post_meta($p->ID, '_party_size', true);
            $row = array(
                'id' => $p->ID,
                'status' => $status,
                'time' => $fmt($start),
                'end' => $fmt($start + $dur),
                'start_min' => $start,
                'end_min' => $start + $dur,
                'party' => $party,
                'customer' => (string) get_post_meta($p->ID, '_customer_name', true),
                'phone' => (string) get_post_meta($p->ID, '_customer_phone', true),
                'notes' => (string) get_post_meta($p->ID, '_special_requests', true),
            );
            $by_table[$tid][] = $row;
            if (in_array($status, $active, true) || $status === 'completed') {
                $bookings++;
                $guests += $party;
            }
        }

        // Dine-in orders and waiter calls from the QR ordering feature
        $dine = array();
        if (class_exists('Menu_Popup_Table_Ordering') && Menu_Popup_Table_Ordering::enabled()) {
            $open = Menu_Popup_Table_Ordering::open_tables();
            $currency = $open['currency'];
            foreach ($open['tables'] as $row) {
                $dine[(int) $row['id']] = $row;
            }
        } else {
            $currency = (string) get_option('menu_popup_currency_symbol', '$');
        }

        $out = array();
        $sum = array('free' => 0, 'reserved' => 0, 'occupied' => 0, 'attention' => 0);

        foreach ($tables as $tbl) {
            $tid = $tbl->ID;
            $list = isset($by_table[$tid]) ? $by_table[$tid] : array();
            usort($list, function ($x, $y) {
                return $x['start_min'] - $y['start_min'];
            });

            $seated = null;
            $next = null;
            foreach ($list as $r) {
                if (!in_array($r['status'], $active, true)) {
                    continue;
                }
                if ($r['status'] === 'seated') {
                    if (!$seated) {
                        $seated = $r;
                    }
                } elseif ($r['end_min'] > $now_min && !$next) {
                    $next = $r;
                }
            }

            $dr = isset($dine[$tid]) ? $dine[$tid] : null;
            $n_orders = $dr ? count($dr['orders']) : 0;
            $flags = array();

            if ($seated || $n_orders) {
                $status = 'occupied';
            } elseif ($next && ($next['start_min'] - $now_min) <= $soon) {
                $status = 'reserved';
            } else {
                $status = 'free';
            }

            if ($next) {
                $next['late'] = max(0, $now_min - $next['start_min']);
                if ($next['late'] >= 15) {
                    $flags[] = 'late';
                }
                if ($next['status'] === 'pending' && ($next['start_min'] - $now_min) <= 60) {
                    $flags[] = 'unconfirmed';
                }
            }
            if ($seated && $now_min > $seated['end_min']) {
                $flags[] = 'overstay';
            }
            if ($dr && $dr['waiter']) {
                $flags[] = 'waiter';
            }
            if ($dr && $dr['bill']) {
                $flags[] = 'bill';
            }

            $sum[$status]++;
            if ($flags) {
                $sum['attention']++;
            }

            $branch_id = get_post_meta($tid, '_branch_id', true);
            $out[] = array(
                'id' => $tid,
                'name' => $tbl->post_title,
                'zone' => (string) (get_post_meta($tid, '_zone', true) ?: 'indoor'),
                'capacity' => (int) (get_post_meta($tid, '_max_pax', true) ?: 4),
                'branch' => $branch_id ? get_the_title($branch_id) : '',
                'status' => $status,
                'current' => $seated,
                'next' => $next,
                'flags' => $flags,
                'orders' => $dr ? array('count' => $n_orders, 'due' => $dr['due']) : null,
                'waiter' => $dr ? (int) $dr['waiter'] : 0,
                'bill' => $dr ? (int) $dr['bill'] : 0,
                'today' => $list,
            );
        }

        $sum['bookings'] = $bookings;
        $sum['guests'] = $guests;

        return array('currency' => $currency, 'summary' => $sum, 'tables' => $out);
    }

    public function ajax_live_snapshot()
    {
        check_ajax_referer('menu_popup_reservation_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        wp_send_json_success($this->build_live_snapshot());
    }

    private function render_map_view()
    {
        $snap = $this->build_live_snapshot();
        $zones = array();
        foreach ($snap['tables'] as $t) {
            $zones[$t['zone']] = true;
        }
        ?>
        <div id="mpl-root">
            <div class="mpl-top">
                <div class="mpl-tile free"><b id="mpl-sum-free">0</b><span><?php esc_html_e('Available', 'menu-popup'); ?></span></div>
                <div class="mpl-tile reserved"><b id="mpl-sum-reserved">0</b><span><?php esc_html_e('Reserved soon', 'menu-popup'); ?></span></div>
                <div class="mpl-tile occupied"><b id="mpl-sum-occupied">0</b><span><?php esc_html_e('Occupied', 'menu-popup'); ?></span></div>
                <div class="mpl-tile attention"><b id="mpl-sum-attention">0</b><span><?php esc_html_e('Need attention', 'menu-popup'); ?></span></div>
                <div class="mpl-tile"><b id="mpl-sum-bookings">0</b><span><?php esc_html_e("Today's bookings", 'menu-popup'); ?></span></div>
                <div class="mpl-tile"><b id="mpl-sum-guests">0</b><span><?php esc_html_e('Guests expected', 'menu-popup'); ?></span></div>
            </div>

            <div class="mpl-bar">
                <button type="button" class="mpl-chip is-on" data-filter="all"><?php esc_html_e('All', 'menu-popup'); ?><b id="mpl-chip-all">0</b></button>
                <button type="button" class="mpl-chip" data-filter="free"><?php esc_html_e('Available', 'menu-popup'); ?><b id="mpl-chip-free">0</b></button>
                <button type="button" class="mpl-chip" data-filter="reserved"><?php esc_html_e('Reserved', 'menu-popup'); ?><b id="mpl-chip-reserved">0</b></button>
                <button type="button" class="mpl-chip" data-filter="occupied"><?php esc_html_e('Occupied', 'menu-popup'); ?><b id="mpl-chip-occupied">0</b></button>
                <button type="button" class="mpl-chip" data-filter="attention"><?php esc_html_e('Need attention', 'menu-popup'); ?><b id="mpl-chip-attention">0</b></button>
                <?php if (count($zones) > 1): ?>
                    <select id="mpl-zone">
                        <option value="all"><?php esc_html_e('All areas', 'menu-popup'); ?></option>
                        <?php foreach (array_keys($zones) as $z): ?>
                            <option value="<?php echo esc_attr($z); ?>"><?php echo esc_html(ucfirst($z)); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <span class="mpl-spacer"></span>
                <span id="mpl-live" class="mpl-live"><span id="mpl-updated"></span></span>
                <button type="button" id="mpl-refresh" class="button"><?php esc_html_e('Refresh', 'menu-popup'); ?></button>
            </div>

            <div id="mpl-board"></div>
        </div>
        <div id="mpl-shade"></div>
        <aside id="mpl-panel" aria-live="polite"></aside>
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
}

// End of file
