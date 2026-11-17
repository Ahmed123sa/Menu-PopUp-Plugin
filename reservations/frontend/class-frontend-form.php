<?php
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Reservations_Frontend_Form
{

    public function __construct()
    {
        add_shortcode('menu_popup_reservation', array($this, 'render_form'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // AJAX Endpoints
        add_action('wp_ajax_menu_popup_submit_reservation', array($this, 'handle_submission'));
        add_action('wp_ajax_nopriv_menu_popup_submit_reservation', array($this, 'handle_submission'));
    }

    public function enqueue_assets()
    {
        // Register main styles
        wp_register_style(
            'menu-popup-reservation-frontend',
            MENU_POPUP_RESERVATIONS_URL . 'assets/css/reservation-style.css',
            array(),
            '1.0.0'
        );

        // Register social and PWA features (enqueue separately, don't load as dependencies)
        wp_register_script(
            'menu-popup-social-pwa',
            MENU_POPUP_RESERVATIONS_URL . 'assets/js/social-pwa.js',
            array('jquery'),
            '1.0.0',
            true
        );

        // Main reservation wizard - CLEAN dependencies (only jquery and no PWA/social conflicts)
        wp_register_script(
            'menu-popup-reservation-wizard',
            MENU_POPUP_RESERVATIONS_URL . 'assets/js/reservation-wizard.js',
            array('jquery'),  // ONLY jQuery - no conflicting dependencies!
            '1.0.0',
            true
        );

        // Debug utilities - enqueue conditionally and separately
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_register_script(
                'menu-popup-debug-utils',
                MENU_POPUP_RESERVATIONS_URL . 'assets/js/debug-utils-fixed.js',
                array('jquery'),
                '1.0.0',
                true
            );
        }

        wp_localize_script('menu-popup-reservation-wizard', 'menuPopupReservation', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('menu_popup_reservation_frontend_nonce'),
            'current_url' => home_url(add_query_arg(null, null)),
            'debug_enabled' => defined('WP_DEBUG') && WP_DEBUG
        ));

        // Add PWA meta tags
        add_action('wp_head', array($this, 'add_pwa_meta_tags'));
    }

    public function add_pwa_meta_tags()
    {
        // Add theme color
        echo '<meta name="theme-color" content="#3182ce">';

        // Add manifest link
        echo '<link rel="manifest" href="' . MENU_POPUP_RESERVATIONS_URL . 'assets/pwa/pwa-manifest.json">';

        // Register service worker if supported
        echo '<script>
            if ("serviceWorker" in navigator) {
                window.addEventListener("load", function() {
                    navigator.serviceWorker.register("' . MENU_POPUP_RESERVATIONS_URL . 'assets/pwa/sw-fixed.js")
                        .then(function(registration) {
                            console.log("SW registered");
                        })
                        .catch(function(error) {
                            console.log("SW registration failed");
                        });
                });
            }
        </script>';
    }

    public function render_form($atts)
    {
        wp_enqueue_style('menu-popup-reservation-frontend');
        wp_enqueue_script('menu-popup-reservation-wizard');

        ob_start();
        ?>
        <div id="reservation-wizard" class="reservation-wizard-wrapper">
            <!-- Step 1: Branch & Party -->
            <div class="wizard-step active" data-step="1">
                <h3><?php _e('Start Your Reservation', 'menu-popup'); ?></h3>
                <div class="form-group">
                    <label><?php _e('Select Branch', 'menu-popup'); ?></label>
                    <select name="branch_id" class="form-control">
                        <option value=""><?php _e('Loading branches...', 'menu-popup'); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label><?php _e('Party Size', 'menu-popup'); ?></label>
                    <input type="number" name="party_size" value="2" min="1" max="20" class="form-control">
                </div>
                <button class="btn btn-primary next-step"><?php _e('Next: Select Time', 'menu-popup'); ?></button>
            </div>

            <!-- Step 2: Date & Time -->
            <div class="wizard-step" data-step="2">
                <h3><?php _e('Choose Date & Time', 'menu-popup'); ?></h3>
                <div class="reservation-summary-bar">
                    <div class="summary-item">
                        <span class="label"><?php _e('Branch:', 'menu-popup'); ?></span>
                        <span class="value" id="selected-branch-name"><?php _e('Select branch first', 'menu-popup'); ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="label"><?php _e('Guests:', 'menu-popup'); ?></span>
                        <span class="value" id="selected-party-size">2</span>
                    </div>
                </div>
                <div class="form-group">
                    <label><?php _e('Date', 'menu-popup'); ?></label>
                    <input type="date" name="reservation_date" min="<?php echo date('Y-m-d'); ?>" class="form-control">
                </div>
                <div class="time-slots-container">
                    <label><?php _e('Available Times', 'menu-popup'); ?></label>
                    <div class="time-slots-grid">
                        <p class="text-muted"><?php _e('Select a date to see available times.', 'menu-popup'); ?></p>
                    </div>
                </div>
                <div class="wizard-actions">
                    <button class="btn btn-secondary prev-step"><?php _e('Back', 'menu-popup'); ?></button>
                    <button class="btn btn-primary next-step"><?php _e('Next: Select Table', 'menu-popup'); ?></button>
                </div>
            </div>

            <!-- Step 3: Table Selection -->
            <div class="wizard-step" data-step="3">
                <h3><?php _e('Choose Your Table', 'menu-popup'); ?></h3>
                <div class="table-selection-instructions">
                    <p><?php _e('Click on an available table to select it. Green tables are available for your party size.', 'menu-popup'); ?></p>
                </div>
                <div class="customer-floor-plan-container">
                    <div class="customer-floor-plan" id="customer-floor-plan">
                        <p class="text-muted"><?php _e('Select a time to see available tables on our floor plan.', 'menu-popup'); ?></p>
                    </div>
                </div>
                <div class="table-selection-legend">
                    <span class="legend-item available"><?php _e('Available', 'menu-popup'); ?></span>
                    <span class="legend-item unavailable"><?php _e('Unavailable', 'menu-popup'); ?></span>
                </div>
                <div class="selected-table-display" id="selected-table-display">
                    <div class="selected-table-info">
                        <span class="table-number"><?php _e('No table selected', 'menu-popup'); ?></span>
                        <span class="table-details"></span>
                    </div>
                </div>
                <div class="wizard-actions">
                    <button class="btn btn-secondary prev-step"><?php _e('Back', 'menu-popup'); ?></button>
                    <button class="btn btn-primary next-step" id="table-selection-next"><?php _e('Next: Details', 'menu-popup'); ?></button>
                </div>
            </div>

            <!-- Step 4: Customer Details -->
            <div class="wizard-step" data-step="4">
                <h3><?php _e('Your Details', 'menu-popup'); ?></h3>
                <div class="form-group">
                    <label><?php _e('Full Name', 'menu-popup'); ?></label>
                    <input type="text" name="customer_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label><?php _e('Phone Number', 'menu-popup'); ?></label>
                    <input type="tel" name="customer_phone" class="form-control" required>
                </div>
                <div class="form-group">
                    <label><?php _e('Special Requests', 'menu-popup'); ?></label>
                    <textarea name="special_requests" class="form-control"></textarea>
                </div>
                <div class="wizard-actions">
                    <button class="btn btn-secondary prev-step"><?php _e('Back', 'menu-popup'); ?></button>
                    <button
                        class="btn btn-success submit-reservation"><?php _e('Confirm Reservation', 'menu-popup'); ?></button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_submission()
    {
        // Add comprehensive debug logging
        Reservation_Debug_Logger::log_step('RESERVATION: Submit Request Received', $_POST, get_current_user_id());
        Reservation_Debug_Logger::log_api('menu_popup_submit_reservation', $_POST);

        Reservation_Debug_Logger::log_backend('RESERVATION: Processing submission', array(
            'customer_name' => $_POST['customer_name'] ?? 'NOT PROVIDED',
            'customer_phone' => $_POST['customer_phone'] ?? 'NOT PROVIDED',
            'reservation_date' => $_POST['reservation_date'] ?? 'NOT PROVIDED',
            'time' => $_POST['time'] ?? 'NOT PROVIDED',
            'party_size' => $_POST['party_size'] ?? 'NOT PROVIDED',
            'branch_id' => $_POST['branch_id'] ?? 'NOT PROVIDED',
            'table_id' => $_POST['table_id'] ?? 'NOT PROVIDED'
        ));

        check_ajax_referer('menu_popup_reservation_frontend_nonce', 'nonce');
        Reservation_Debug_Logger::log_backend('RESERVATION: Nonce verification passed');
        // ... (rest of the code remains similar, can be optimized later)

        // Re-implementing basic save for completeness of this file update
        $name = sanitize_text_field($_POST['customer_name']);
        $phone = sanitize_text_field($_POST['customer_phone']);
        $date = sanitize_text_field($_POST['reservation_date']);
        $time = sanitize_text_field($_POST['time']); // Note: JS sends 'time', previous form sent 'reservation_time'
        if (empty($time))
            $time = sanitize_text_field($_POST['reservation_time']); // Fallback

        $party_size = intval($_POST['party_size']);
        $branch_id = intval($_POST['branch_id']);
        $table_id = intval($_POST['table_id']);

        if (empty($name) || empty($phone) || empty($date) || empty($time) || empty($table_id)) {
            wp_send_json_error(__('All fields are required, including table selection.', 'menu-popup'));
        }

        $reservation_id = wp_insert_post(array(
            'post_type' => 'menu_reservation',
            'post_status' => 'publish',
            'post_title' => sprintf(__('Reservation for %s', 'menu-popup'), $name),
        ));

        if ($reservation_id) {
            update_post_meta($reservation_id, '_customer_name', $name);
            update_post_meta($reservation_id, '_customer_phone', $phone);
            update_post_meta($reservation_id, '_reservation_date', $date);
            update_post_meta($reservation_id, '_reservation_time', $time);
            update_post_meta($reservation_id, '_party_size', $party_size);
            update_post_meta($reservation_id, '_branch_id', $branch_id);
            update_post_meta($reservation_id, '_assigned_table', $table_id);
            update_post_meta($reservation_id, '_status', 'pending');
            update_post_meta($reservation_id, '_special_requests', sanitize_textarea_field($_POST['special_requests']));

            wp_send_json_success(__('Reservation submitted successfully! We look forward to seeing you.', 'menu-popup'));
        } else {
            wp_send_json_error(__('Failed to create reservation.', 'menu-popup'));
        }
    }
}
