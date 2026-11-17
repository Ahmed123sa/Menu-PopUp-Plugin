<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Include the debug logger for comprehensive logging
 */
require_once plugin_dir_path(__FILE__) . 'class-debug-logger.php';

class Menu_Popup_Reservations_API
{

    private $availability_engine;

    public function __construct()
    {
        Reservation_Debug_Logger::log_step('API Class Initialized', array('class' => __CLASS__), get_current_user_id());
        $this->availability_engine = new Menu_Popup_Availability_Engine();

        add_action('wp_ajax_menu_popup_get_time_slots', array($this, 'get_time_slots'));
        add_action('wp_ajax_nopriv_menu_popup_get_time_slots', array($this, 'get_time_slots'));

        add_action('wp_ajax_menu_popup_get_branches', array($this, 'get_branches'));
        add_action('wp_ajax_nopriv_menu_popup_get_branches', array($this, 'get_branches'));

        add_action('wp_ajax_menu_popup_get_available_tables', array($this, 'get_available_tables'));
        add_action('wp_ajax_nopriv_menu_popup_get_available_tables', array($this, 'get_available_tables'));

        add_action('wp_ajax_menu_popup_get_table_reservations', array($this, 'get_table_reservations'));
    }

    public function get_branches()
    {
        Reservation_Debug_Logger::log_step('BRANCH: Get Branches Request Started', $_REQUEST, get_current_user_id());
        Reservation_Debug_Logger::log_api('get_branches', $_REQUEST);

        $args = array(
            'post_type' => 'menu_popup_branch',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        );

        Reservation_Debug_Logger::log_backend('BRANCH: Querying branches with args', $args);
        $branches = get_posts($args);
        Reservation_Debug_Logger::log_database('SELECT', 'menu_popup_branch', $args, array('count' => count($branches)));

        $data = array();

        if (empty($branches)) {
            Reservation_Debug_Logger::log_backend('BRANCH: No branches found, using fallback', $args);
            // If no branches exist, return a fallback for testing
            $data[] = array(
                'id' => 0,
                'name' => 'Main Restaurant',
                'city' => 'Default'
            );
        } else {
            Reservation_Debug_Logger::log_backend('BRANCH: Found branches', array('branch_count' => count($branches)));
            foreach ($branches as $branch) {
                Reservation_Debug_Logger::log_backend('BRANCH: Processing branch', array('id' => $branch->ID, 'title' => $branch->post_title));

                // Get city from taxonomy (Menu Popup Cities)
                $cities = wp_get_post_terms($branch->ID, 'menu_popup_branch_city', array('fields' => 'names'));
                $city = !empty($cities) ? $cities[0] : '';

                $data[] = array(
                    'id' => $branch->ID,
                    'name' => $branch->post_title,
                    'city' => $city
                );
            }
        }

        Reservation_Debug_Logger::log_api('get_branches', null, $data);
        wp_send_json_success($data);
    }

    public function get_time_slots()
    {
        Reservation_Debug_Logger::log_step('TIMESLOTS: Get Time Slots Request Started', $_POST, get_current_user_id());
        Reservation_Debug_Logger::log_api('get_time_slots', $_POST);

        $date = sanitize_text_field($_POST['date']);
        $branch_id = intval($_POST['branch_id']);
        $party_size = intval($_POST['party_size']);

        Reservation_Debug_Logger::log_backend('TIMESLOTS: Input parameters', array(
            'date' => $date,
            'branch_id' => $branch_id,
            'party_size' => $party_size
        ));

        if (empty($date) || empty($branch_id)) {
            Reservation_Debug_Logger::log_error('TIMESLOTS: Missing required parameters', array('date' => $date, 'branch_id' => $branch_id));
            wp_send_json_error('Missing parameters');
            return;
        }

        Reservation_Debug_Logger::log_backend('TIMESLOTS: Getting base time slots from availability engine');
        $slots = $this->availability_engine->get_time_slots($date, $branch_id);
        Reservation_Debug_Logger::log_backend('TIMESLOTS: Base slots from engine', array('count' => count($slots), 'slots' => $slots));

        // Filter slots by table availability
        $available_slots = array();
        $checked_count = 0;

        foreach ($slots as $slot) {
            $checked_count++;
            Reservation_Debug_Logger::log_backend('TIMESLOTS: Checking slot availability', array(
                'slot' => $slot,
                'count_so_far' => $checked_count,
                'total' => count($slots)
            ));

            // Check if ANY table is available for this slot and party size
            $available = $this->availability_engine->find_best_table($party_size, $date, $slot, $branch_id);

            if ($available) {
                Reservation_Debug_Logger::log_backend('TIMESLOTS: Slot IS available', array('slot' => $slot, 'available' => true));
                $available_slots[] = $slot;
            } else {
                Reservation_Debug_Logger::log_backend('TIMESLOTS: Slot NOT available', array('slot' => $slot, 'available' => false));
            }
        }

        Reservation_Debug_Logger::log_step('TIMESLOTS: Filtered available slots', array(
            'total_base_slots' => count($slots),
            'available_slots_count' => count($available_slots),
            'available_slots' => $available_slots
        ));

        Reservation_Debug_Logger::log_api('get_time_slots', null, $available_slots);
        wp_send_json_success($available_slots);
    }

    public function get_available_tables()
    {
        Reservation_Debug_Logger::log_step('TABLES: Get Available Tables Request Started', $_POST, get_current_user_id());
        Reservation_Debug_Logger::log_api('get_available_tables', $_POST);

        $date = sanitize_text_field($_POST['date']);
        $time = sanitize_text_field($_POST['time']);
        $party_size = intval($_POST['party_size']);
        $branch_id = intval($_POST['branch_id']);

        Reservation_Debug_Logger::log_backend('TABLES: Input parameters', array(
            'date' => $date,
            'time' => $time,
            'party_size' => $party_size,
            'branch_id' => $branch_id
        ));

        if (empty($date) || empty($time) || empty($party_size)) {
            Reservation_Debug_Logger::log_error('TABLES: Missing required parameters', array(
                'date' => $date,
                'time' => $time,
                'party_size' => $party_size
            ));
            wp_send_json_error('Missing parameters: date, time, party_size required');
            return;
        }

        // Get all tables for this branch
        $args = array(
            'post_type' => 'restaurant_table',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_max_pax',
                    'value' => $party_size,
                    'compare' => '>='
                ),
                array(
                    'key' => '_min_pax',
                    'value' => $party_size,
                    'compare' => '<=',
                    'type' => 'NUMERIC'
                )
            )
        );

        if ($branch_id > 0) {
            $args['meta_query'][] = array(
                'key' => '_branch_id',
                'value' => $branch_id,
                'compare' => '='
            );
        }

        Reservation_Debug_Logger::log_backend('TABLES: Querying tables with args', $args);
        $tables = get_posts($args);
        Reservation_Debug_Logger::log_database('SELECT', 'restaurant_table', $args, array('count' => count($tables)));

        $available_tables = array();
        $checked_tables = 0;

        foreach ($tables as $table) {
            $checked_tables++;
            Reservation_Debug_Logger::log_backend('TABLES: Checking table availability', array(
                'table_id' => $table->ID,
                'table_name' => $table->post_title,
                'count_so_far' => $checked_tables,
                'total' => count($tables)
            ));

            if ($this->availability_engine->is_table_available($table->ID, $date, $time)) {
                Reservation_Debug_Logger::log_backend('TABLES: Table IS available', array('table_id' => $table->ID));

                $capacity = get_post_meta($table->ID, '_max_pax', true);
                $zone = get_post_meta($table->ID, '_zone', true) ?: 'Main';
                $shape = get_post_meta($table->ID, '_shape', true) ?: 'round';
                $coordinates = get_post_meta($table->ID, '_coordinates', true) ?: array('x' => 0, 'y' => 0);

                Reservation_Debug_Logger::log_backend('TABLES: Table metadata loaded', array(
                    'table_id' => $table->ID,
                    'capacity' => $capacity,
                    'zone' => $zone,
                    'coordinates' => $coordinates
                ));

                $available_tables[] = array(
                    'id' => $table->ID,
                    'name' => $table->post_title,
                    'capacity' => intval($capacity),
                    'zone' => $zone,
                    'shape' => $shape,
                    'coordinates' => $coordinates,
                    'available' => true
                );
            } else {
                Reservation_Debug_Logger::log_backend('TABLES: Table NOT available', array(
                    'table_id' => $table->ID,
                    'reason' => 'already booked'
                ));
            }
        }

        Reservation_Debug_Logger::log_step('TABLES: Final available tables', array(
            'total_tables_checked' => count($tables),
            'available_tables_count' => count($available_tables),
            'available_tables' => array_map(function($t) { return array('id' => $t['id'], 'name' => $t['name']); }, $available_tables)
        ));

        Reservation_Debug_Logger::log_api('get_available_tables', null, $available_tables);
        wp_send_json_success($available_tables);
    }

    public function get_table_reservations()
    {
        Reservation_Debug_Logger::log_step('RESERVATIONS: Get Table Reservations Request Started', $_POST, get_current_user_id());
        Reservation_Debug_Logger::log_api('get_table_reservations', $_POST);

        $table_id = intval($_POST['table_id']);
        $date = sanitize_text_field($_POST['date']);

        Reservation_Debug_Logger::log_backend('RESERVATIONS: Input parameters', array(
            'table_id' => $table_id,
            'date' => $date
        ));

        if (!$table_id || !$date) {
            Reservation_Debug_Logger::log_error('RESERVATIONS: Missing table_id or date', array(
                'table_id' => $table_id,
                'date' => $date
            ));
            wp_send_json_error('Missing table_id or date');
            return;
        }

        $args = array(
            'post_type' => 'menu_reservation',
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => '_assigned_table', 'value' => $table_id, 'compare' => '='),
                array('key' => '_reservation_date', 'value' => $date, 'compare' => '='),
                array('key' => '_status', 'value' => array('confirmed', 'pending'), 'compare' => 'IN')
            ),
            'orderby' => 'meta_value',
            'meta_key' => '_reservation_time',
            'order' => 'ASC'
        );

        Reservation_Debug_Logger::log_backend('RESERVATIONS: Querying reservations with args', $args);
        $reservations = new WP_Query($args);
        Reservation_Debug_Logger::log_database('SELECT', 'menu_reservation', $args, array('count' => $reservations->found_posts));

        $reservation_data = array();
        if ($reservations->have_posts()) {
            Reservation_Debug_Logger::log_backend('RESERVATIONS: Found reservations', array('count' => $reservations->found_posts));

            while ($reservations->have_posts()) {
                $reservations->the_post();
                $id = get_the_ID();

                $reservation_data[] = array(
                    'id' => $id,
                    'time' => get_post_meta($id, '_reservation_time', true),
                    'customer_name' => get_post_meta($id, '_customer_name', true),
                    'party_size' => intval(get_post_meta($id, '_party_size', true)),
                    'phone' => get_post_meta($id, '_customer_phone', true),
                    'status' => get_post_meta($id, '_status', true)
                );

                Reservation_Debug_Logger::log_backend('RESERVATIONS: Processed reservation', array(
                    'reservation_id' => $id,
                    'customer_name' => get_post_meta($id, '_customer_name', true),
                    'time' => get_post_meta($id, '_reservation_time', true),
                    'status' => get_post_meta($id, '_status', true)
                ));
            }
            wp_reset_postdata();
        } else {
            Reservation_Debug_Logger::log_backend('RESERVATIONS: No reservations found', $args);
        }

        Reservation_Debug_Logger::log_step('RESERVATIONS: Final reservation data', array(
            'table_id' => $table_id,
            'date' => $date,
            'reservation_count' => count($reservation_data),
            'reservations' => $reservation_data
        ));

        Reservation_Debug_Logger::log_api('get_table_reservations', null, $reservation_data);
        wp_send_json_success($reservation_data);
    }
}
