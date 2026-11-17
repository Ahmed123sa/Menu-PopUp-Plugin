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

    /**
     * Check if a table is available for a given time slot
     */
    public function is_table_available($table_id, $date, $time, $duration = 90)
    {
        $start_time = strtotime("$date $time");
        $end_time = $start_time + ($duration * 60);

        $args = array(
            'post_type' => 'menu_reservation',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_assigned_table',
                    'value' => $table_id,
                    'compare' => '='
                ),
                array(
                    'key' => '_status',
                    'value' => array('confirmed', 'pending', 'seated'),
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
            $res_time = get_post_meta($reservation->ID, '_reservation_time', true);
            $res_duration = get_post_meta($reservation->ID, '_duration', true) ?: 90;

            $res_start = strtotime("$date $res_time");
            $res_end = $res_start + ($res_duration * 60);

            if ($start_time < $res_end && $end_time > $res_start) {
                return false;
            }
        }

        return true;
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
