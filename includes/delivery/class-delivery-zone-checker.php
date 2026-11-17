<?php
/**
 * Delivery Zone Checker
 *
 * Pure, stateless geographic logic for delivery zone validation.
 *
 * Two exported static helpers:
 *   Menu_Popup_Zone_Checker::get_vendor_zones( $branch_id )
 *   Menu_Popup_Zone_Checker::is_user_in_zone( $branch_id, $lat, $lng )
 *
 * Both rely on Menu_Popup_Delivery_Zone_Model for data access.
 *
 * @package    MenuPopup
 * @subpackage Delivery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu_Popup_Zone_Checker {

    // -----------------------------------------------------------------------
    // Earth radius used in Haversine calculations
    // -----------------------------------------------------------------------
    const EARTH_RADIUS_KM = 6371.0;

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Fetch all active delivery zones for a given vendor/branch.
     *
     * Returns an array of zone rows (associative arrays) as returned by
     * Menu_Popup_Delivery_Zone_Model::get_by_branch().
     *
     * @param int  $branch_id  WordPress post ID of the branch / vendor.
     * @return array  List of zone rows; empty array when none defined.
     */
    public static function get_vendor_zones( int $branch_id ): array {


        $model = new Menu_Popup_Delivery_Zone_Model();

        return $model->get_by_branch( $branch_id, true );
    }

    /**
     * Check whether a user's location falls inside ANY active zone of a vendor.
     *
     * Handles both radius-based and polygon-based zones.
     * Returns true on the first matching zone (zones are OR-ed).
     *
     * @param int   $branch_id  Vendor/branch ID.
     * @param float $lat        User latitude  (decimal degrees, −90 … 90).
     * @param float $lng        User longitude (decimal degrees, −180 … 180).
     * @return array {
     *   @type bool       in_zone   True when the user is inside at least one zone.
     *   @type array|null zone      The first matching zone row, or null.
     *   @type string     reason    Human-readable explanation (useful for debugging).
     * }
     */
    public static function is_user_in_zone( int $branch_id, float $lat, float $lng ): array {
        // --- validate coordinates -------------------------------------------
        if ( ! self::is_valid_lat( $lat ) || ! self::is_valid_lng( $lng ) ) {
            return [
                'in_zone' => false,
                'zone'    => null,
                'reason'  => 'Invalid coordinates supplied.',
            ];
        }

        // --- fetch zones ----------------------------------------------------
        $zones = self::get_vendor_zones( $branch_id );

        if ( empty( $zones ) ) {
            return [
                'in_zone' => false,
                'zone'    => null,
                'reason'  => 'No delivery zones configured for this vendor.',
            ];
        }

        // --- test each zone -------------------------------------------------
        foreach ( $zones as $zone ) {
            $hit = false;

            if ( $zone['zone_type'] === 'radius' ) {
                $hit = self::point_in_radius(
                    $lat, $lng,
                    (float) $zone['center_lat'],
                    (float) $zone['center_lng'],
                    (float) $zone['radius_km']
                );
            } elseif ( $zone['zone_type'] === 'polygon' ) {
                $hit = self::point_in_polygon(
                    $lat, $lng,
                    $zone['polygon_coords']          // already decoded to PHP array
                );
            }

            if ( $hit ) {
                return [
                    'in_zone' => true,
                    'zone'    => $zone,
                    'reason'  => sprintf(
                        'User is inside zone "%s" (type: %s, id: %d).',
                        $zone['zone_name'],
                        $zone['zone_type'],
                        $zone['id']
                    ),
                ];
            }
        }

        return [
            'in_zone' => false,
            'zone'    => null,
            'reason'  => sprintf(
                'User location (%.6f, %.6f) is outside all %d configured zone(s).',
                $lat, $lng, count( $zones )
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // Geometry: Radius (Haversine)
    // -----------------------------------------------------------------------

    /**
     * Return the great-circle distance in kilometres between two coordinates.
     *
     * Uses the Haversine formula which gives accurate results for small and
     * medium distances (error < 0.3 % across typical delivery radii).
     *
     * @param float $lat1
     * @param float $lng1
     * @param float $lat2
     * @param float $lng2
     * @return float  Distance in kilometres.
     */
    public static function haversine_distance(
        float $lat1, float $lng1,
        float $lat2, float $lng2
    ): float {
        $d_lat = deg2rad( $lat2 - $lat1 );
        $d_lng = deg2rad( $lng2 - $lng1 );

        $a = sin( $d_lat / 2 ) ** 2
           + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) )
           * sin( $d_lng / 2 ) ** 2;

        $c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

        return self::EARTH_RADIUS_KM * $c;
    }

    /**
     * Return true when ($point_lat, $point_lng) is within $radius_km of the centre.
     *
     * @param float $point_lat
     * @param float $point_lng
     * @param float $center_lat
     * @param float $center_lng
     * @param float $radius_km
     * @return bool
     */
    public static function point_in_radius(
        float $point_lat,  float $point_lng,
        float $center_lat, float $center_lng,
        float $radius_km
    ): bool {
        if ( $radius_km <= 0 ) {
            return false;
        }

        $distance = self::haversine_distance( $point_lat, $point_lng, $center_lat, $center_lng );

        return $distance <= $radius_km;
    }

    // -----------------------------------------------------------------------
    // Geometry: Point-in-Polygon (Ray Casting)
    // -----------------------------------------------------------------------

    /**
     * Return true when the point is inside a polygon.
     *
     * Uses the ray-casting algorithm (Jordan curve theorem).  Works correctly
     * for concave polygons and correctly handles edge-crossing ambiguities.
     *
     * @param float $point_lat
     * @param float $point_lng
     * @param array $vertices  List of vertices.  Each element may be:
     *                         - associative: ['lat' => …, 'lng' => …]
     *                         - indexed:     [lat, lng]
     * @return bool
     */
    public static function point_in_polygon(
        float $point_lat,
        float $point_lng,
        array $vertices
    ): bool {
        $n = count( $vertices );

        // A polygon needs at least 3 vertices.
        if ( $n < 3 ) {
            return false;
        }

        // Normalise vertices to [lat, lng] pairs.
        $verts = self::normalise_polygon_vertices( $vertices );
        if ( $verts === null ) {
            return false;
        }

        $inside = false;

        // Ray-casting: shoot a horizontal ray eastward from the test point
        // and count how many polygon edges it crosses.
        $j = $n - 1;
        for ( $i = 0; $i < $n; $i++ ) {
            [ $xi, $yi ] = $verts[ $i ];
            [ $xj, $yj ] = $verts[ $j ];

            // Swap xi/xj here: $xi = lng, $yi = lat (latitude is the y-axis).
            $intersect = ( ( $yi > $point_lat ) !== ( $yj > $point_lat ) )
                && ( $point_lng < ( $xj - $xi ) * ( $point_lat - $yi ) / ( $yj - $yi ) + $xi );

            if ( $intersect ) {
                $inside = ! $inside;
            }

            $j = $i;
        }

        return $inside;
    }

    // -----------------------------------------------------------------------
    // Coordinate validation
    // -----------------------------------------------------------------------

    /**
     * Return true when $lat is a valid latitude (−90 … 90, not NaN).
     *
     * @param float $lat
     * @return bool
     */
    public static function is_valid_lat( float $lat ): bool {
        return ! is_nan( $lat ) && $lat >= -90.0 && $lat <= 90.0;
    }

    /**
     * Return true when $lng is a valid longitude (−180 … 180, not NaN).
     *
     * @param float $lng
     * @return bool
     */
    public static function is_valid_lng( float $lng ): bool {
        return ! is_nan( $lng ) && $lng >= -180.0 && $lng <= 180.0;
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Normalise polygon vertices to a uniform [[lng, lat], …] array.
     *
     * Note: We use [lng, lat] ordering internally (matching Cartesian x, y)
     *       so the ray-casting math is readable.
     *
     * Accepted input formats:
     *   - [['lat' => …, 'lng' => …], …]
     *   - [[lat, lng], …]  (indexed pair)
     *
     * @param array $vertices
     * @return array|null  [[lng, lat], …] or null on error.
     */
    private static function normalise_polygon_vertices( array $vertices ): ?array {
        $out = [];

        foreach ( $vertices as $v ) {
            if ( is_array( $v ) && isset( $v['lat'], $v['lng'] ) ) {
                $lat = (float) $v['lat'];
                $lng = (float) $v['lng'];
            } elseif ( is_array( $v ) && count( $v ) >= 2 ) {
                // Assume [lat, lng] order
                $lat = (float) array_values( $v )[0];
                $lng = (float) array_values( $v )[1];
            } else {
                // Unrecognised vertex format – bail out.
                return null;
            }

            if ( ! self::is_valid_lat( $lat ) || ! self::is_valid_lng( $lng ) ) {
                return null;
            }

            $out[] = [ $lng, $lat ]; // [x, y] for ray-casting
        }

        return $out;
    }
}
