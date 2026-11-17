<?php
/**
 * Delivery Zone AJAX Handler
 *
 * Registers WordPress AJAX actions that the frontend JavaScript
 * can call to check whether a user is in a delivery zone and to
 * fetch zone data for rendering on a map.
 *
 * Actions registered
 * ------------------
 *  wp_ajax_nopriv_mp_check_delivery_zone   (guests)
 *  wp_ajax_mp_check_delivery_zone          (logged-in users)
 *  wp_ajax_nopriv_mp_get_vendor_zones      (guests)
 *  wp_ajax_mp_get_vendor_zones             (logged-in users)
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

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        // Zone check: is user lat/lng inside a vendor's zone?
        add_action( 'wp_ajax_nopriv_mp_check_delivery_zone', [ $this, 'handle_check_zone' ] );
        add_action( 'wp_ajax_mp_check_delivery_zone',        [ $this, 'handle_check_zone' ] );

        // Fetch raw zone data (for JS map drawing, admin preview, etc.)
        add_action( 'wp_ajax_nopriv_mp_get_vendor_zones', [ $this, 'handle_get_zones' ] );
        add_action( 'wp_ajax_mp_get_vendor_zones',        [ $this, 'handle_get_zones' ] );

        // Admin-only CRUD actions
        add_action( 'wp_ajax_mp_save_delivery_zone',   [ $this, 'handle_save_zone' ] );
        add_action( 'wp_ajax_mp_delete_delivery_zone', [ $this, 'handle_delete_zone' ] );
    }

    // -----------------------------------------------------------------------
    // Public: Check if user is in zone
    // -----------------------------------------------------------------------

    /**
     * POST params:
     *   nonce      – wp_create_nonce('mp_delivery_zone_nonce')
     *   branch_id  – int
     *   lat        – float
     *   lng        – float
     *
     * JSON response:
     *   { success: true, data: { in_zone, zone, reason } }
     */
    public function handle_check_zone(): void {
        // Verify nonce (public nonce; value is printed via wp_localize_script)
        if ( ! check_ajax_referer( 'mp_delivery_zone_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }

        $branch_id = absint( $_POST['branch_id'] ?? 0 );
        $lat       = isset( $_POST['lat'] ) ? (float) $_POST['lat'] : null;
        $lng       = isset( $_POST['lng'] ) ? (float) $_POST['lng'] : null;

        // Basic presence check
        if ( $lat === null || $lng === null ) {
            wp_send_json_error( [ 'message' => 'Missing required parameters: lat, lng.' ], 422 );
        }

        // Delegate entirely to the pure-logic checker
        $result = Menu_Popup_Zone_Checker::is_user_in_zone( $branch_id, $lat, $lng );

        // Strip polygon_coords from the zone payload to keep the response lean
        if ( ! empty( $result['zone']['polygon_coords'] ) ) {
            unset( $result['zone']['polygon_coords'] );
        }

        wp_send_json_success( $result );
    }

    // -----------------------------------------------------------------------
    // Public: Get vendor zones (for map rendering / JS)
    // -----------------------------------------------------------------------

    /**
     * POST params:
     *   nonce      – same nonce
     *   branch_id  – int
     *
     * Returns all active zones with full coordinate data.
     */
    public function handle_get_zones(): void {
        if ( ! check_ajax_referer( 'mp_delivery_zone_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }

        $branch_id = absint( $_POST['branch_id'] ?? 0 );



        $zones = Menu_Popup_Zone_Checker::get_vendor_zones( $branch_id );

        wp_send_json_success( [ 'zones' => $zones ] );
    }

    // -----------------------------------------------------------------------
    // Admin: Save (create / update) a delivery zone
    // -----------------------------------------------------------------------

    /**
     * POST params (all admin-only):
     *   nonce
     *   zone_id        – int (0 = create new)
     *   branch_id
     *   zone_name
     *   zone_name_ar
     *   zone_type      – 'radius' | 'polygon'
     *   center_lat     – (radius)
     *   center_lng     – (radius)
     *   radius_km      – (radius)
     *   polygon_coords – JSON string  (polygon)
     *   delivery_fee
     *   minimum_order
     *   estimated_delivery_minutes
     *   is_active
     */
    public function handle_save_zone(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        if ( ! check_ajax_referer( 'mp_admin_zone_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }

        $zone_id   = absint( $_POST['zone_id'] ?? 0 );
        $zone_type = sanitize_text_field( $_POST['zone_type'] ?? 'radius' );

        // Validate type-specific required fields
        if ( $zone_type === 'radius' ) {
            $missing = array_filter( [ 'center_lat', 'center_lng', 'radius_km' ], fn( $k ) => ! isset( $_POST[ $k ] ) );
            if ( $missing ) {
                wp_send_json_error( [ 'message' => 'Radius zones require center_lat, center_lng, and radius_km.' ], 422 );
            }
        } elseif ( $zone_type === 'polygon' ) {
            if ( empty( $_POST['polygon_coords'] ) ) {
                wp_send_json_error( [ 'message' => 'Polygon zones require polygon_coords.' ], 422 );
            }
        }

        $data = [
            'branch_id'                  => absint( $_POST['branch_id'] ?? 0 ),
            'zone_name'                  => sanitize_text_field( $_POST['zone_name'] ?? '' ),
            'zone_name_ar'               => sanitize_text_field( $_POST['zone_name_ar'] ?? '' ),
            'zone_type'                  => $zone_type,
            'center_lat'                 => isset( $_POST['center_lat'] )  ? (float) $_POST['center_lat']  : null,
            'center_lng'                 => isset( $_POST['center_lng'] )  ? (float) $_POST['center_lng']  : null,
            'radius_km'                  => isset( $_POST['radius_km'] )   ? (float) $_POST['radius_km']   : null,
            'polygon_coords'             => sanitize_textarea_field( $_POST['polygon_coords'] ?? '' ),
            'delivery_fee'               => isset( $_POST['delivery_fee'] )  ? (float) $_POST['delivery_fee']  : 0,
            'minimum_order'              => isset( $_POST['minimum_order'] ) ? (float) $_POST['minimum_order'] : 0,
            'estimated_delivery_minutes' => absint( $_POST['estimated_delivery_minutes'] ?? 30 ),
            'is_active'                  => isset( $_POST['is_active'] ) ? (int) (bool) $_POST['is_active'] : 1,
        ];

        if ( empty( $data['zone_name'] ) ) {
            wp_send_json_error( [ 'message' => 'Zone name is required.' ], 422 );
        }

        $model = new Menu_Popup_Delivery_Zone_Model();

        if ( $zone_id > 0 ) {
            $ok  = $model->update( $zone_id, $data );
            $msg = $ok ? 'Zone updated.' : 'Update failed.';
            $ok ? wp_send_json_success( [ 'message' => $msg, 'zone_id' => $zone_id ] )
                : wp_send_json_error( [ 'message' => $msg ] );
        } else {
            $new_id = $model->create( $data );
            $new_id ? wp_send_json_success( [ 'message' => 'Zone created.', 'zone_id' => $new_id ] )
                    : wp_send_json_error( [ 'message' => 'Creation failed.' ] );
        }
    }

    // -----------------------------------------------------------------------
    // Admin: Delete a delivery zone
    // -----------------------------------------------------------------------

    /**
     * POST params:
     *   nonce
     *   zone_id  – int
     */
    public function handle_delete_zone(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        if ( ! check_ajax_referer( 'mp_admin_zone_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }

        $zone_id = absint( $_POST['zone_id'] ?? 0 );
        if ( ! $zone_id ) {
            wp_send_json_error( [ 'message' => 'Invalid zone_id.' ], 422 );
        }

        $model = new Menu_Popup_Delivery_Zone_Model();
        $ok    = $model->hard_delete( $zone_id );

        $ok ? wp_send_json_success( [ 'message' => 'Zone deleted.' ] )
            : wp_send_json_error( [ 'message' => 'Delete failed.' ] );
    }
}
