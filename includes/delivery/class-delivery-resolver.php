<?php
/**
 * Delivery Resolver
 *
 * Single place that answers: "a customer pinned this spot on the map, can we
 * deliver there, from which branch, and for how much?"
 *
 * The same function backs the live preview the customer sees on the map
 * (mp_resolve_delivery) and the check that runs when the order is saved, so
 * the quote and the final charge can never disagree.
 *
 * @package    MenuPopup
 * @subpackage Delivery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu_Popup_Delivery_Resolver {

    /**
     * @param float $lat              Pin latitude.
     * @param float $lng              Pin longitude.
     * @param float $order_value      Order total before delivery (used for free-delivery thresholds).
     * @param int   $preferred_branch Branch the customer opened the menu from. Only breaks ties.
     * @return array {
     *   ok: bool
     *   reason: 'invalid'|'no_zones'|'outside'|'closed'   (when ok is false)
     *   message: string                                    (when ok is false)
     *   nearest_branches: array                            (when ok is false)
     *   zone_id, zone_name, zone_name_ar, branch_id, branch_name,
     *   fee, min_order, eta, distance_km, free_delivery_applied, free_delivery_over  (when ok is true)
     * }
     */
    public static function resolve( float $lat, float $lng, float $order_value = 0.0, int $preferred_branch = 0 ): array {
        if ( ! Menu_Popup_Zone_Checker::is_valid_lat( $lat ) || ! Menu_Popup_Zone_Checker::is_valid_lng( $lng ) ) {
            return self::fail( 'invalid', __( 'The selected location is not valid.', 'menu-popup' ), $lat, $lng );
        }

        $model = new Menu_Popup_Delivery_Zone_Model();
        $zones = $model->get_active_map_zones();

        if ( empty( $zones ) ) {
            return self::fail( 'no_zones', __( 'Delivery is not set up yet.', 'menu-popup' ), $lat, $lng );
        }

        $serving = array();
        $closed  = 0;

        foreach ( $zones as $zone ) {
            // Cheap box test first, exact shape only for the few that pass
            if ( $zone['min_lat'] !== null && $zone['max_lat'] !== null ) {
                if ( $lat < (float) $zone['min_lat'] || $lat > (float) $zone['max_lat']
                    || $lng < (float) $zone['min_lng'] || $lng > (float) $zone['max_lng'] ) {
                    continue;
                }
            }

            if ( ! self::point_in_zone( $zone, $lat, $lng ) ) {
                continue;
            }

            if ( ! empty( $zone['is_paused'] ) || ! self::is_open_now( $zone ) || ! self::branch_delivers( (int) $zone['branch_id'] ) ) {
                $closed++;
                continue;
            }

            $branch_point = self::branch_point( (int) $zone['branch_id'], $zone );
            $zone['_distance_km'] = Menu_Popup_Zone_Checker::haversine_distance( $branch_point[0], $branch_point[1], $lat, $lng );
            $serving[] = $zone;
        }

        if ( empty( $serving ) ) {
            if ( $closed > 0 ) {
                return self::fail( 'closed', __( 'Delivery to your area is not available right now. Please try again later or choose pickup.', 'menu-popup' ), $lat, $lng );
            }
            return self::fail( 'outside', __( 'Sorry, we do not deliver to this location.', 'menu-popup' ), $lat, $lng );
        }

        usort( $serving, function ( $a, $b ) use ( $preferred_branch ) {
            if ( (int) $a['priority'] !== (int) $b['priority'] ) {
                return (int) $b['priority'] <=> (int) $a['priority'];
            }
            if ( $a['_distance_km'] !== $b['_distance_km'] ) {
                return $a['_distance_km'] <=> $b['_distance_km'];
            }
            $a_pref = (int) $a['branch_id'] === $preferred_branch ? 0 : 1;
            $b_pref = (int) $b['branch_id'] === $preferred_branch ? 0 : 1;
            if ( $a_pref !== $b_pref ) {
                return $a_pref <=> $b_pref;
            }
            return (int) $a['id'] <=> (int) $b['id'];
        } );

        $zone     = $serving[0];
        $distance = round( (float) $zone['_distance_km'], 2 );

        if ( $zone['pricing_type'] === 'distance' ) {
            $fee = (float) $zone['base_fee'] + $distance * (float) $zone['per_km_fee'];
            if ( (float) $zone['max_fee'] > 0 ) {
                $fee = min( $fee, (float) $zone['max_fee'] );
            }
        } else {
            $fee = (float) $zone['delivery_fee'];
        }
        $fee = round( max( 0, $fee ), 2 );

        $free_over = (float) $zone['free_delivery_over'];
        $free      = false;
        if ( $free_over > 0 && $order_value >= $free_over && $fee > 0 ) {
            $fee  = 0.0;
            $free = true;
        }

        $branch_id = (int) $zone['branch_id'];

        return array(
            'ok'                    => true,
            'zone_id'               => (int) $zone['id'],
            'zone_name'             => (string) $zone['zone_name'],
            'zone_name_ar'          => (string) ( $zone['zone_name_ar'] ?? '' ),
            'branch_id'             => $branch_id,
            'branch_name'           => $branch_id > 0 ? (string) get_the_title( $branch_id ) : '',
            'fee'                   => $fee,
            'min_order'             => round( (float) $zone['minimum_order'], 2 ),
            'eta'                   => (int) $zone['estimated_delivery_minutes'],
            'distance_km'           => $distance,
            'free_delivery_applied' => $free,
            'free_delivery_over'    => $free_over,
        );
    }

    // -----------------------------------------------------------------------
    // Zone tests
    // -----------------------------------------------------------------------

    public static function point_in_zone( array $zone, float $lat, float $lng ): bool {
        if ( $zone['zone_type'] === 'radius' ) {
            return Menu_Popup_Zone_Checker::point_in_radius(
                $lat, $lng,
                (float) $zone['center_lat'],
                (float) $zone['center_lng'],
                (float) $zone['radius_km']
            );
        }

        return Menu_Popup_Zone_Checker::point_in_polygon( $lat, $lng, $zone['polygon_coords'] );
    }

    /**
     * Working hours window in the site's timezone. No window = always open.
     */
    private static function is_open_now( array $zone ): bool {
        $sch = $zone['schedule'] ?? null;
        if ( ! is_array( $sch ) || empty( $sch['from'] ) || empty( $sch['to'] ) ) {
            return true;
        }

        $now  = current_time( 'H:i' );
        $from = $sch['from'];
        $to   = $sch['to'];

        if ( $from === $to ) {
            return true;
        }
        if ( $from < $to ) {
            return $now >= $from && $now <= $to;
        }

        // Window that crosses midnight, e.g. 18:00 - 02:00
        return $now >= $from || $now <= $to;
    }

    /**
     * A branch delivers unless it is missing/unpublished or switched off.
     * Zones that are not tied to a branch are always allowed.
     */
    private static function branch_delivers( int $branch_id ): bool {
        if ( $branch_id <= 0 ) {
            return true;
        }
        if ( get_post_type( $branch_id ) !== 'menu_popup_branch' || get_post_status( $branch_id ) !== 'publish' ) {
            return false;
        }

        return get_post_meta( $branch_id, '_branch_delivery_enabled', true ) !== '0';
    }

    /**
     * Where the food leaves from: the branch pin, or the zone centre when the
     * branch has no location yet.
     *
     * @return array [lat, lng]
     */
    private static function branch_point( int $branch_id, array $zone ): array {
        if ( $branch_id > 0 ) {
            $lat = get_post_meta( $branch_id, '_branch_lat', true );
            $lng = get_post_meta( $branch_id, '_branch_lng', true );
            if ( $lat !== '' && $lng !== '' && is_numeric( $lat ) && is_numeric( $lng ) ) {
                return array( (float) $lat, (float) $lng );
            }
        }

        if ( $zone['zone_type'] === 'radius' ) {
            return array( (float) $zone['center_lat'], (float) $zone['center_lng'] );
        }

        return array(
            ( (float) $zone['min_lat'] + (float) $zone['max_lat'] ) / 2,
            ( (float) $zone['min_lng'] + (float) $zone['max_lng'] ) / 2,
        );
    }

    // -----------------------------------------------------------------------
    // Failure payload with the closest branches for pickup
    // -----------------------------------------------------------------------

    private static function fail( string $reason, string $message, float $lat, float $lng ): array {
        return array(
            'ok'               => false,
            'reason'           => $reason,
            'message'          => $message,
            'nearest_branches' => self::nearest_branches( $lat, $lng, 3 ),
        );
    }

    public static function nearest_branches( float $lat, float $lng, int $limit = 3 ): array {
        if ( ! Menu_Popup_Zone_Checker::is_valid_lat( $lat ) || ! Menu_Popup_Zone_Checker::is_valid_lng( $lng ) ) {
            return array();
        }

        $branches = get_posts( array(
            'post_type'      => 'menu_popup_branch',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'no_found_rows'  => true,
        ) );

        $list = array();
        foreach ( $branches as $branch ) {
            $b_lat = get_post_meta( $branch->ID, '_branch_lat', true );
            $b_lng = get_post_meta( $branch->ID, '_branch_lng', true );
            if ( $b_lat === '' || $b_lng === '' || ! is_numeric( $b_lat ) || ! is_numeric( $b_lng ) ) {
                continue;
            }
            $list[] = array(
                'id'          => (int) $branch->ID,
                'name'        => $branch->post_title,
                'address'     => (string) get_post_meta( $branch->ID, '_menu_popup_branch_address', true ),
                'distance_km' => round( Menu_Popup_Zone_Checker::haversine_distance( $lat, $lng, (float) $b_lat, (float) $b_lng ), 1 ),
            );
        }

        usort( $list, function ( $a, $b ) {
            return $a['distance_km'] <=> $b['distance_km'];
        } );

        return array_slice( $list, 0, $limit );
    }
}
