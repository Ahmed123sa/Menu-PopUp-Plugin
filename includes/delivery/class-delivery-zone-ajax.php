<?php
/**
 * Delivery Zone AJAX Handler
 *
 * Actions registered
 * ------------------
 *  mp_resolve_delivery        (public)  Quote for a pinned location: branch, zone, fee, minimum, ETA
 *  mp_check_delivery_zone     (public)  Older "is the point inside a zone" check, kept for compatibility
 *  mp_get_vendor_zones        (public)  Older zone listing, kept for compatibility
 *  mp_save_delivery_zone      (admin)
 *  mp_delete_delivery_zone    (admin)
 *  mp_toggle_zone_pause       (admin)
 *  mp_test_delivery_resolve   (admin)   Try a point and order value against the live rules
 *
 * @package    MenuPopup
 * @subpackage Delivery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu_Popup_Delivery_Zone_Ajax {

    /** @var self|null Singleton */
    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        add_action( 'wp_ajax_nopriv_mp_resolve_delivery', [ $this, 'handle_resolve' ] );
        add_action( 'wp_ajax_mp_resolve_delivery',        [ $this, 'handle_resolve' ] );

        add_action( 'wp_ajax_nopriv_mp_check_delivery_zone', [ $this, 'handle_check_zone' ] );
        add_action( 'wp_ajax_mp_check_delivery_zone',        [ $this, 'handle_check_zone' ] );

        add_action( 'wp_ajax_nopriv_mp_get_vendor_zones', [ $this, 'handle_get_zones' ] );
        add_action( 'wp_ajax_mp_get_vendor_zones',        [ $this, 'handle_get_zones' ] );

        add_action( 'wp_ajax_mp_save_delivery_zone',    [ $this, 'handle_save_zone' ] );
        add_action( 'wp_ajax_mp_delete_delivery_zone',  [ $this, 'handle_delete_zone' ] );
        add_action( 'wp_ajax_mp_toggle_zone_pause',     [ $this, 'handle_toggle_pause' ] );
        add_action( 'wp_ajax_mp_test_delivery_resolve', [ $this, 'handle_test_resolve' ] );
    }

    // -----------------------------------------------------------------------
    // Public: quote for a pinned location
    // -----------------------------------------------------------------------

    /**
     * POST: nonce, lat, lng, order_value (optional), branch_id (optional)
     */
    public function handle_resolve(): void {
        if ( ! check_ajax_referer( 'mp_delivery_zone_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Your session has expired. Please refresh the page.', 'menu-popup' ) ], 403 );
        }

        if ( ! $this->allow_request() ) {
            wp_send_json_error( [ 'message' => __( 'Too many requests. Please wait a moment.', 'menu-popup' ) ], 429 );
        }

        if ( ! isset( $_POST['lat'], $_POST['lng'] ) || ! is_numeric( $_POST['lat'] ) || ! is_numeric( $_POST['lng'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Missing location.', 'menu-popup' ) ], 422 );
        }

        $order_value = isset( $_POST['order_value'] ) && is_numeric( $_POST['order_value'] ) ? max( 0, (float) $_POST['order_value'] ) : 0.0;

        $result = Menu_Popup_Delivery_Resolver::resolve(
            (float) $_POST['lat'],
            (float) $_POST['lng'],
            $order_value,
            absint( $_POST['branch_id'] ?? 0 )
        );

        wp_send_json_success( $result );
    }

    /**
     * At most 90 quotes a minute per visitor. Plenty for moving a pin around,
     * stops anyone from hammering the endpoint.
     */
    private function allow_request(): bool {
        $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $key = 'mp_rl_' . md5( $ip );
        $n   = (int) get_transient( $key );

        if ( $n >= 90 ) {
            return false;
        }

        set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
        return true;
    }

    // -----------------------------------------------------------------------
    // Public (compat): inside any zone of a branch?
    // -----------------------------------------------------------------------

    public function handle_check_zone(): void {
        if ( ! check_ajax_referer( 'mp_delivery_zone_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }

        $branch_id = absint( $_POST['branch_id'] ?? 0 );
        $lat       = isset( $_POST['lat'] ) ? (float) $_POST['lat'] : null;
        $lng       = isset( $_POST['lng'] ) ? (float) $_POST['lng'] : null;

        if ( $lat === null || $lng === null ) {
            wp_send_json_error( [ 'message' => 'Missing required parameters: lat, lng.' ], 422 );
        }

        $result = Menu_Popup_Zone_Checker::is_user_in_zone( $branch_id, $lat, $lng );

        if ( ! empty( $result['zone']['polygon_coords'] ) ) {
            unset( $result['zone']['polygon_coords'] );
        }

        wp_send_json_success( $result );
    }

    public function handle_get_zones(): void {
        if ( ! check_ajax_referer( 'mp_delivery_zone_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }

        $branch_id = absint( $_POST['branch_id'] ?? 0 );
        $zones     = Menu_Popup_Zone_Checker::get_vendor_zones( $branch_id );

        wp_send_json_success( [ 'zones' => $zones ] );
    }

    // -----------------------------------------------------------------------
    // Admin: save (create / update)
    // -----------------------------------------------------------------------

    public function handle_save_zone(): void {
        $this->require_admin();

        $zone_id   = absint( $_POST['zone_id'] ?? 0 );
        $zone_type = sanitize_text_field( $_POST['zone_type'] ?? 'radius' );

        if ( $zone_type === 'manual' ) {
            // no shape to check
        } elseif ( $zone_type === 'radius' ) {
            foreach ( [ 'center_lat', 'center_lng', 'radius_km' ] as $k ) {
                if ( ! isset( $_POST[ $k ] ) || ! is_numeric( $_POST[ $k ] ) ) {
                    wp_send_json_error( [ 'message' => __( 'Draw the circle on the map (or fill the centre and radius).', 'menu-popup' ) ], 422 );
                }
            }
            if ( (float) $_POST['radius_km'] <= 0 ) {
                wp_send_json_error( [ 'message' => __( 'Radius must be greater than zero.', 'menu-popup' ) ], 422 );
            }
        } elseif ( $zone_type === 'polygon' ) {
            $coords = json_decode( wp_unslash( $_POST['polygon_coords'] ?? '' ), true );
            if ( ! is_array( $coords ) || count( $coords ) < 3 ) {
                wp_send_json_error( [ 'message' => __( 'Draw the shape on the map: a polygon needs at least 3 points.', 'menu-popup' ) ], 422 );
            }
        } else {
            wp_send_json_error( [ 'message' => 'Invalid zone type.' ], 422 );
        }

        $schedule = '';
        $from     = sanitize_text_field( $_POST['schedule_from'] ?? '' );
        $to       = sanitize_text_field( $_POST['schedule_to'] ?? '' );
        if ( $from !== '' && $to !== '' ) {
            $schedule = wp_json_encode( [ 'from' => $from, 'to' => $to ] );
        }

        $data = [
            'branch_id'                  => absint( $_POST['branch_id'] ?? 0 ),
            'zone_name'                  => sanitize_text_field( wp_unslash( $_POST['zone_name'] ?? '' ) ),
            'zone_name_ar'               => sanitize_text_field( wp_unslash( $_POST['zone_name_ar'] ?? '' ) ),
            'zone_type'                  => $zone_type,
            'center_lat'                 => $_POST['center_lat'] ?? null,
            'center_lng'                 => $_POST['center_lng'] ?? null,
            'radius_km'                  => $_POST['radius_km'] ?? null,
            'polygon_coords'             => $_POST['polygon_coords'] ?? '',
            'delivery_fee'               => $_POST['delivery_fee'] ?? 0,
            'minimum_order'              => $_POST['minimum_order'] ?? 0,
            'estimated_delivery_minutes' => $_POST['estimated_delivery_minutes'] ?? 30,
            'is_active'                  => isset( $_POST['is_active'] ) ? (int) (bool) $_POST['is_active'] : 1,
            'priority'                   => $_POST['priority'] ?? 0,
            'pricing_type'               => sanitize_text_field( $_POST['pricing_type'] ?? 'flat' ),
            'base_fee'                   => $_POST['base_fee'] ?? 0,
            'per_km_fee'                 => $_POST['per_km_fee'] ?? 0,
            'max_fee'                    => $_POST['max_fee'] ?? 0,
            'free_delivery_over'         => $_POST['free_delivery_over'] ?? 0,
            'schedule'                   => $schedule,
            'is_paused'                  => isset( $_POST['is_paused'] ) ? (int) (bool) $_POST['is_paused'] : 0,
        ];

        if ( $data['zone_name'] === '' ) {
            wp_send_json_error( [ 'message' => __( 'Zone name is required.', 'menu-popup' ) ], 422 );
        }
        if ( $data['branch_id'] < 1 ) {
            wp_send_json_error( [ 'message' => __( 'Choose the branch this zone belongs to.', 'menu-popup' ) ], 422 );
        }

        $model = new Menu_Popup_Delivery_Zone_Model();

        if ( $zone_id > 0 ) {
            if ( ! $model->update( $zone_id, $data ) ) {
                wp_send_json_error( [ 'message' => __( 'Update failed.', 'menu-popup' ) ] );
            }
            $saved_id = $zone_id;
            $msg      = __( 'Zone updated.', 'menu-popup' );
        } else {
            $saved_id = $model->create( $data );
            if ( ! $saved_id ) {
                wp_send_json_error( [ 'message' => __( 'Creation failed.', 'menu-popup' ) ] );
            }
            $msg = __( 'Zone created.', 'menu-popup' );
        }

        wp_send_json_success( [
            'message'  => $msg,
            'zone_id'  => $saved_id,
            'warnings' => $this->overlap_warnings( $model, $saved_id ),
        ] );
    }

    /**
     * Compare the saved zone with the other active zones on a grid of sample
     * points and report the ones that share ground with it, along with which
     * zone wins there.
     */
    private function overlap_warnings( Menu_Popup_Delivery_Zone_Model $model, int $zone_id ): array {
        $zone = $model->get( $zone_id );
        if ( ! $zone || empty( $zone['is_active'] ) || $zone['min_lat'] === null ) {
            return [];
        }

        $warnings = [];

        foreach ( $model->get_by_branch( 0, true ) as $other ) {
            if ( (int) $other['id'] === $zone_id || $other['min_lat'] === null ) {
                continue;
            }

            // Boxes that do not touch cannot overlap
            if ( (float) $other['min_lat'] > (float) $zone['max_lat'] || (float) $other['max_lat'] < (float) $zone['min_lat']
                || (float) $other['min_lng'] > (float) $zone['max_lng'] || (float) $other['max_lng'] < (float) $zone['min_lng'] ) {
                continue;
            }

            $steps = 14;
            $hit   = false;
            for ( $i = 0; $i <= $steps && ! $hit; $i++ ) {
                $lat = (float) $zone['min_lat'] + ( (float) $zone['max_lat'] - (float) $zone['min_lat'] ) * $i / $steps;
                for ( $j = 0; $j <= $steps; $j++ ) {
                    $lng = (float) $zone['min_lng'] + ( (float) $zone['max_lng'] - (float) $zone['min_lng'] ) * $j / $steps;
                    if ( Menu_Popup_Delivery_Resolver::point_in_zone( $zone, $lat, $lng )
                        && Menu_Popup_Delivery_Resolver::point_in_zone( $other, $lat, $lng ) ) {
                        $hit = true;
                        break;
                    }
                }
            }

            if ( $hit ) {
                $winner = (int) $zone['priority'] >= (int) $other['priority'] ? $zone : $other;
                $warnings[] = sprintf(
                    /* translators: 1: other zone, 2: its priority, 3: this zone's priority, 4: winning zone */
                    __( 'Overlaps with "%1$s" (priority %2$d vs %3$d). Where they overlap, "%4$s" is used; with equal priority the closer branch wins.', 'menu-popup' ),
                    $other['zone_name'],
                    (int) $other['priority'],
                    (int) $zone['priority'],
                    $winner['zone_name']
                );
            }
        }

        return $warnings;
    }

    // -----------------------------------------------------------------------
    // Admin: delete / pause / test
    // -----------------------------------------------------------------------

    public function handle_delete_zone(): void {
        $this->require_admin();

        $zone_id = absint( $_POST['zone_id'] ?? 0 );
        if ( ! $zone_id ) {
            wp_send_json_error( [ 'message' => 'Invalid zone_id.' ], 422 );
        }

        $model = new Menu_Popup_Delivery_Zone_Model();
        $ok    = $model->hard_delete( $zone_id );

        $ok ? wp_send_json_success( [ 'message' => __( 'Zone deleted.', 'menu-popup' ) ] )
            : wp_send_json_error( [ 'message' => __( 'Delete failed.', 'menu-popup' ) ] );
    }

    public function handle_toggle_pause(): void {
        $this->require_admin();

        $zone_id = absint( $_POST['zone_id'] ?? 0 );
        $paused  = ! empty( $_POST['paused'] );
        if ( ! $zone_id ) {
            wp_send_json_error( [ 'message' => 'Invalid zone_id.' ], 422 );
        }

        $model = new Menu_Popup_Delivery_Zone_Model();
        $model->set_paused( $zone_id, $paused )
            ? wp_send_json_success( [ 'paused' => $paused ] )
            : wp_send_json_error( [ 'message' => __( 'Update failed.', 'menu-popup' ) ] );
    }

    /**
     * Admin test tool: runs exactly what a customer order would run.
     */
    public function handle_test_resolve(): void {
        $this->require_admin();

        if ( ! isset( $_POST['lat'], $_POST['lng'] ) || ! is_numeric( $_POST['lat'] ) || ! is_numeric( $_POST['lng'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Enter a latitude and longitude.', 'menu-popup' ) ], 422 );
        }

        $value = isset( $_POST['order_value'] ) && is_numeric( $_POST['order_value'] ) ? (float) $_POST['order_value'] : 0.0;

        wp_send_json_success( Menu_Popup_Delivery_Resolver::resolve( (float) $_POST['lat'], (float) $_POST['lng'], $value ) );
    }

    private function require_admin(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'menu-popup' ) ], 403 );
        }
        if ( ! check_ajax_referer( 'mp_admin_zone_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'menu-popup' ) ], 403 );
        }
    }
}
