<?php
if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Availability_Engine
{

    /**
     * Get available time slots for a specific date and branch
     */
    public function get_time_slots($date, $branch_id)
    {
        $day_of_week = strtolower(date('D', strtotime($date))); // mon, tue, wed...

        // 1. Check Global/Branch Rules (Exceptions)
        if ($this->is_date_blocked($date, $branch_id)) {
            return array(); // Closed
        }

        // 2. Get Opening Hours
        $opening_hours = $this->get_opening_hours($branch_id, $day_of_week);
        if (empty($opening_hours)) {
            return array(); // Closed today
        }

        // 3. Generate Slots
        $slots = array();
        foreach ($opening_hours as $range) {
            $slots = array_merge($slots, $this->generate_slots_from_range($range));
        }

        return $slots;
    }

    /**
     * Check if a date is blocked by a rule
     */
    private function is_date_blocked($date, $branch_id)
    {
        $args = array(
            'post_type' => 'reservation_rule',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_rule_type',
                    'value' => 'blocked_date',
                    'compare' => '='
                ),
                array(
                    'key' => '_rule_date',
                    'value' => $date,
                    'compare' => '='
                )
            )
        );

        // Filter by branch if needed (simplified for now)
        $rules = get_posts($args);
        return !empty($rules);
    }

    /**
     * Check if a zone is blocked for a specific date
     */
    private function is_zone_blocked($zone, $date, $branch_id)
    {
        $args = array(
            'post_type' => 'reservation_rule',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_rule_type',
                    'value' => 'blocked_zone',
                    'compare' => '='
                ),
                array(
                    'key' => '_rule_date',
                    'value' => $date,
                    'compare' => '='
                ),
                array(
                    'key' => '_blocked_zone',
                    'value' => $zone,
                    'compare' => '='
                )
            )
        );

        $rules = get_posts($args);
        return !empty($rules);
    }

    /**
     * Get opening hours for a branch on a specific day
     */
    private function get_opening_hours($branch_id, $day)
    {
        $hours_meta = get_post_meta($branch_id, '_opening_hours', true);
        if (empty($hours_meta)) {
            return array("11:00-23:00");
        }

        $hours = is_string($hours_meta) ? json_decode($hours_meta, true) : $hours_meta;
        return isset($hours[$day]) ? $hours[$day] : array();
    }

    /**
     * Generate 30-min slots from a range
     */
    private function generate_slots_from_range($range)
    {
        list($start, $end) = explode('-', $range);
        $start_ts = strtotime($start);
        $end_ts = strtotime($end);
        $slots = array();

        $last_seating = $end_ts - (60 * 60);

        while ($start_ts <= $last_seating) {
            $slots[] = date('H:i', $start_ts);
            $start_ts += 30 * 60;
        }

        return $slots;
    }

    /** Statuses that keep a table taken */
    const ACTIVE_STATUSES = array('confirmed', 'pending', 'seated');

    /**
     * How long a table is held for one reservation, in minutes.
     */
    public static function default_duration()
    {
        $d = (int) apply_filters('menu_popup_reservation_duration', 90);
        return max(30, min(480, $d ? $d : 90));
    }

    /**
     * Check if a table is available for a given time slot
     *
     * @param int $table_id
     * @param string $date Y-m-d
     * @param string $time H:i
     * @param int|null $duration minutes (default: the standard reservation length)
     * @param int $exclude_id a reservation to ignore (when re-checking an existing one)
     */
    public function is_table_available($table_id, $date, $time, $duration = null, $exclude_id = 0)
    {
        $duration = $duration ? (int) $duration : self::default_duration();
        $start_time = strtotime("$date $time");
        $end_time = $start_time + ($duration * 60);

        $args = array(
            'post_type' => 'menu_reservation',
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_assigned_table',
                    'value' => $table_id,
                    'compare' => '='
                ),
                array(
                    'key' => '_status',
                    'value' => self::ACTIVE_STATUSES,
                    'compare' => 'IN'
                ),
                array(
                    'key' => '_reservation_date',
                    'value' => $date,
                    'compare' => '='
                )
            ),
            'posts_per_page' => -1
        );

        $reservations = get_posts($args);

        foreach ($reservations as $reservation) {
            if ($exclude_id && (int) $reservation->ID === (int) $exclude_id) {
                continue;
            }

            $res_time = get_post_meta($reservation->ID, '_reservation_time', true);
            $res_duration = (int) get_post_meta($reservation->ID, '_duration', true);
            $res_duration = $res_duration > 0 ? $res_duration : 90;

            $res_start = strtotime("$date $res_time");
            $res_end = $res_start + ($res_duration * 60);

            if ($start_time < $res_end && $end_time > $res_start) {
                return false;
            }
        }

        return true;
    }

    // ---------------------------------------------------------------------
    // Booking (the one place a reservation is created)
    // ---------------------------------------------------------------------

    /**
     * Validate a booking request and, if the table is still free, save it.
     *
     * The availability check and the save happen inside a lock on that table
     * and day, so two customers sending the same table at the same moment can
     * not both get it: the second one is told the table was just taken.
     *
     * @param array $d name, phone, date, time, party_size, branch_id, table_id, special_requests
     * @return array|WP_Error array(id, duplicate) on success
     */
    public function book(array $d)
    {
        $name = trim((string) ($d['name'] ?? ''));
        $phone = trim((string) ($d['phone'] ?? ''));
        $date = (string) ($d['date'] ?? '');
        $time = (string) ($d['time'] ?? '');
        $party = (int) ($d['party_size'] ?? 0);
        $branch_id = (int) ($d['branch_id'] ?? 0);
        $table_id = (int) ($d['table_id'] ?? 0);

        // --- who and when --------------------------------------------------
        if ($name === '' || $phone === '' || $table_id < 1) {
            return new WP_Error('missing', __('All fields are required, including table selection.', 'menu-popup'));
        }
        if (!preg_match('/^[\p{Nd}+\-\s()]{6,20}$/u', $phone)) {
            return new WP_Error('bad_phone', __('Please enter a valid phone number.', 'menu-popup'));
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return new WP_Error('bad_date', __('Please choose a valid date.', 'menu-popup'));
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return new WP_Error('bad_time', __('Please choose a valid time.', 'menu-popup'));
        }
        if ($date . ' ' . $time <= wp_date('Y-m-d H:i')) {
            return new WP_Error('past', __('This time has already passed. Please choose a later time.', 'menu-popup'), array('step' => 2));
        }

        // --- the table -----------------------------------------------------
        if (get_post_type($table_id) !== 'restaurant_table' || get_post_status($table_id) !== 'publish') {
            return new WP_Error('bad_table', __('This table is not available. Please choose another one.', 'menu-popup'), array('step' => 3));
        }
        $table_branch = (int) get_post_meta($table_id, '_branch_id', true);
        if ($branch_id > 0 && $table_branch > 0 && $table_branch !== $branch_id) {
            return new WP_Error('bad_table', __('This table is not available. Please choose another one.', 'menu-popup'), array('step' => 3));
        }
        if ($branch_id < 1) {
            $branch_id = $table_branch;
        }

        $max_pax = (int) get_post_meta($table_id, '_max_pax', true);
        $min_pax = (int) get_post_meta($table_id, '_min_pax', true);
        if ($party < 1 || ($max_pax > 0 && $party > $max_pax) || ($min_pax > 0 && $party < $min_pax)) {
            return new WP_Error('bad_party', __('This table does not fit your party size. Please choose another one.', 'menu-popup'), array('step' => 3));
        }

        // --- opening hours, closed days, blocked zone ------------------------
        if (!in_array($time, $this->get_time_slots($date, $branch_id), true)) {
            return new WP_Error('slot_unavailable', __('Sorry, we cannot take reservations at this time. Please choose another time.', 'menu-popup'), array('step' => 2));
        }
        $zone = get_post_meta($table_id, '_zone', true);
        if ($zone && $this->is_zone_blocked($zone, $date, $branch_id)) {
            return new WP_Error('table_taken', __('This area is closed on that day. Please choose another table.', 'menu-popup'), array('step' => 3));
        }

        // --- the check and the save, together --------------------------------
        $lock = $this->acquire_lock('table_' . $table_id . '_' . $date);
        if ($lock === false) {
            return new WP_Error('busy', __('The system is busy right now. Please try again in a moment.', 'menu-popup'));
        }

        try {
            // The same customer pressing twice gets the reservation they already made
            $same = get_posts(array(
                'post_type' => 'menu_reservation',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_query' => array(
                    'relation' => 'AND',
                    array('key' => '_assigned_table', 'value' => $table_id),
                    array('key' => '_reservation_date', 'value' => $date),
                    array('key' => '_reservation_time', 'value' => $time),
                    array('key' => '_customer_phone', 'value' => $phone),
                    array('key' => '_status', 'value' => self::ACTIVE_STATUSES, 'compare' => 'IN'),
                ),
            ));
            if (!empty($same)) {
                return array('id' => (int) $same[0], 'duplicate' => true);
            }

            if (!$this->is_table_available($table_id, $date, $time)) {
                return new WP_Error('table_taken', __('Sorry, this table was just booked by someone else. Please choose another table or time.', 'menu-popup'), array('step' => 3));
            }

            $reservation_id = wp_insert_post(array(
                'post_type' => 'menu_reservation',
                'post_status' => 'publish',
                'post_title' => sprintf(__('Reservation for %s', 'menu-popup'), $name),
            ));
            if (!$reservation_id || is_wp_error($reservation_id)) {
                return new WP_Error('save_failed', __('Failed to create reservation.', 'menu-popup'));
            }

            update_post_meta($reservation_id, '_customer_name', $name);
            update_post_meta($reservation_id, '_customer_phone', $phone);
            update_post_meta($reservation_id, '_reservation_date', $date);
            update_post_meta($reservation_id, '_reservation_time', $time);
            update_post_meta($reservation_id, '_duration', self::default_duration());
            update_post_meta($reservation_id, '_party_size', $party);
            update_post_meta($reservation_id, '_branch_id', $branch_id);
            update_post_meta($reservation_id, '_assigned_table', $table_id);
            update_post_meta($reservation_id, '_status', 'pending');
            update_post_meta($reservation_id, '_special_requests', sanitize_textarea_field((string) ($d['special_requests'] ?? '')));

            return array('id' => (int) $reservation_id, 'duplicate' => false);
        } finally {
            $this->release_lock($lock);
        }
    }

    /**
     * Can a reservation that was cancelled (or finished) be made active again?
     * Used by the admin screen so a restored reservation can not collide with a newer one.
     */
    public function can_reactivate($reservation_id)
    {
        $table_id = (int) get_post_meta($reservation_id, '_assigned_table', true);
        $date = (string) get_post_meta($reservation_id, '_reservation_date', true);
        $time = (string) get_post_meta($reservation_id, '_reservation_time', true);
        if (!$table_id || $date === '' || $time === '') {
            return true; // nothing to collide with
        }
        $duration = (int) get_post_meta($reservation_id, '_duration', true);
        return $this->is_table_available($table_id, $date, $time, $duration > 0 ? $duration : 90, $reservation_id);
    }

    // ---------------------------------------------------------------------
    // Locking
    // ---------------------------------------------------------------------

    /**
     * Wait for the lock on a key. Uses the database's own lock; if the
     * database can not do that, falls back to a row in the options table
     * (inserted with INSERT IGNORE, so only one request can create it).
     *
     * @return array|false lock handle, or false when it could not be taken
     */
    protected function acquire_lock($key)
    {
        global $wpdb;
        $name = 'mp_' . md5($key);

        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 8)', $name));
        if ((string) $got === '1') {
            return array('type' => 'db', 'name' => $name);
        }
        if ($got !== null) {
            return false; // the lock exists but did not free up in time
        }

        // GET_LOCK not available here
        $opt = 'mp_lock_' . $name;
        for ($i = 0; $i < 40; $i++) {
            $made = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $opt,
                (string) time()
            ));
            if ($made) {
                return array('type' => 'opt', 'name' => $opt);
            }
            $since = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt));
            if ($since && (time() - $since) > 15) {
                $wpdb->delete($wpdb->options, array('option_name' => $opt));
                continue;
            }
            usleep(200000);
        }
        return false;
    }

    protected function release_lock($lock)
    {
        global $wpdb;
        if (!is_array($lock)) {
            return;
        }
        if ($lock['type'] === 'db') {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock['name']));
        } else {
            $wpdb->delete($wpdb->options, array('option_name' => $lock['name']));
        }
    }

    /**
     * Find best available table for party size
     */
    public function find_best_table($party_size, $date, $time, $branch_id = null)
    {
        if ($branch_id) {
            $slots = $this->get_time_slots($date, $branch_id);
            if (!in_array($time, $slots)) {
                return false;
            }
        }

        $args = array(
            'post_type' => 'restaurant_table',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_max_pax',
                    'value' => $party_size,
                    'compare' => '>='
                )
            )
        );

        if ($branch_id) {
            $args['meta_query'][] = array(
                'key' => '_branch_id',
                'value' => $branch_id,
                'compare' => '='
            );
        }

        $tables = get_posts($args);

        // Filter by Min Capacity & Zone Blocking
        $tables = array_filter($tables, function ($table) use ($party_size, $date, $branch_id) {
            // Min Pax Check
            $min_pax = get_post_meta($table->ID, '_min_pax', true);
            if ($min_pax && $party_size < $min_pax) {
                return false;
            }

            // Zone Blocking Check
            $zone = get_post_meta($table->ID, '_zone', true);
            if ($zone && $this->is_zone_blocked($zone, $date, $branch_id)) {
                return false; // Zone is blocked today
            }

            return true;
        });

        // Sort by Capacity (Ascending) -> Best Fit
        usort($tables, function ($a, $b) {
            $cap_a = get_post_meta($a->ID, '_max_pax', true);
            $cap_b = get_post_meta($b->ID, '_max_pax', true);
            return $cap_a - $cap_b;
        });

        foreach ($tables as $table) {
            if ($this->is_table_available($table->ID, $date, $time)) {
                return $table->ID;
            }
        }

        return false;
    }
}
