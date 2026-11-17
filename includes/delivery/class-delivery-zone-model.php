<?php
/**
 * Delivery Zone Model
 *
 * Handles CRUD operations for delivery zones.
 * Each zone belongs to a branch and can be either:
 *   - 'radius'  : circle defined by (center_lat, center_lng, radius_km)
 *   - 'polygon' : arbitrary shape defined by a JSON array of {lat, lng} points
 *
 * Schema 3.0.0 adds pricing (flat or by distance), priority for overlapping
 * zones, a working-hours window, a pause switch and a bounding box that keeps
 * the lookup fast no matter how many zones exist.
 *
 * @package    MenuPopup
 * @subpackage Delivery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu_Popup_Delivery_Zone_Model {

    /** @var string Full (prefixed) table name */
    private string $table;

    /** @var string DB schema version – bump when columns change */
    const SCHEMA_VERSION = '3.1.0';

    /** Transient that holds the active zones for the resolver */
    const CACHE_KEY = 'mp_delivery_zones_active_v3';

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'menu_popup_delivery_zones';
    }

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    /**
     * Create or upgrade the delivery zones table. Only ever adds columns.
     */
    public static function maybe_run_migration(): void {
        global $wpdb;

        $table           = $wpdb->prefix . 'menu_popup_delivery_zones';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                       BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id                BIGINT(20) UNSIGNED DEFAULT NULL,
            zone_name                VARCHAR(100) NOT NULL,
            zone_name_ar             VARCHAR(100) DEFAULT NULL,
            zone_type                ENUM('radius','polygon') NOT NULL DEFAULT 'radius',
            center_lat               DECIMAL(10,7) DEFAULT NULL,
            center_lng               DECIMAL(10,7) DEFAULT NULL,
            radius_km                DECIMAL(8,4)  DEFAULT NULL,
            polygon_coords           LONGTEXT      DEFAULT NULL,
            delivery_fee             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            minimum_order            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            estimated_delivery_minutes INT(11)     DEFAULT 30,
            is_active                TINYINT(1)   DEFAULT 1,
            priority                 INT(11) NOT NULL DEFAULT 0,
            pricing_type             VARCHAR(10) NOT NULL DEFAULT 'flat',
            base_fee                 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            per_km_fee               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            max_fee                  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            free_delivery_over       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            schedule                 TEXT DEFAULT NULL,
            is_paused                TINYINT(1) NOT NULL DEFAULT 0,
            is_manual                TINYINT(1) NOT NULL DEFAULT 0,
            min_lat                  DECIMAL(10,7) DEFAULT NULL,
            max_lat                  DECIMAL(10,7) DEFAULT NULL,
            min_lng                  DECIMAL(10,7) DEFAULT NULL,
            max_lng                  DECIMAL(10,7) DEFAULT NULL,
            created_at               DATETIME     DEFAULT CURRENT_TIMESTAMP,
            updated_at               DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY branch_id (branch_id),
            KEY is_active (is_active),
            KEY zone_type (zone_type)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Bounding boxes for zones saved before this version
        $rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE min_lat IS NULL", ARRAY_A );
        if ( $rows ) {
            $model = new self();
            foreach ( $rows as $row ) {
                $row  = $model->decode_row( $row );
                $bbox = self::compute_bbox( $row );
                if ( $bbox ) {
                    $wpdb->update( $table, $bbox, [ 'id' => (int) $row['id'] ], [ '%f', '%f', '%f', '%f' ], [ '%d' ] );
                }
            }
        }

        self::clear_cache();
        update_option( 'menu_popup_delivery_zone_schema_version', self::SCHEMA_VERSION );
    }

    /**
     * Run the migration when the stored schema is older than this code.
     * Called on every request, costs one option read.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( 'menu_popup_delivery_zone_schema_version' ) !== self::SCHEMA_VERSION ) {
            self::maybe_run_migration();
        }
    }

    /**
     * How delivery is priced: 'manual' (zones picked from a list, fixed fee)
     * or 'map' (customer drops a pin, zones are drawn shapes). Never both.
     */
    public static function mode(): string {
        return get_option( 'menu_popup_delivery_mode', 'manual' ) === 'map' ? 'map' : 'manual';
    }

    /**
     * Active drawn-shape zones only (the ones the map resolver may use).
     */
    public function get_active_map_zones(): array {
        return array_values( array_filter(
            $this->get_all_active_cached(),
            static function ( $z ) { return empty( $z['is_manual'] ); }
        ) );
    }

    public static function clear_cache(): void {
        delete_transient( self::CACHE_KEY );
    }

    // -----------------------------------------------------------------------
    // Write operations
    // -----------------------------------------------------------------------

    /**
     * Insert a new delivery zone.
     *
     * @param array $data See prepare_row() for the accepted keys.
     * @return int|false  New zone ID on success, false on failure.
     */
    public function create( array $data ): int|false {
        global $wpdb;

        $row    = $this->prepare_row( $data );
        $result = $wpdb->insert( $this->table, $row['values'], $row['formats'] );
        self::clear_cache();

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing zone.
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;

        $row = $this->prepare_row( $data );

        $result = $wpdb->update(
            $this->table,
            $row['values'],
            [ 'id' => $id ],
            $row['formats'],
            [ '%d' ]
        );
        self::clear_cache();

        return $result !== false;
    }

    /**
     * Pause or resume a zone without touching anything else.
     */
    public function set_paused( int $id, bool $paused ): bool {
        global $wpdb;

        $result = $wpdb->update( $this->table, [ 'is_paused' => $paused ? 1 : 0 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        self::clear_cache();

        return $result !== false;
    }

    /**
     * Soft-delete: set is_active = 0.
     */
    public function delete( int $id ): bool {
        global $wpdb;

        $result = (bool) $wpdb->update(
            $this->table,
            [ 'is_active' => 0 ],
            [ 'id' => $id ],
            [ '%d' ],
            [ '%d' ]
        );
        self::clear_cache();

        return $result;
    }

    /**
     * Hard-delete a zone row.
     */
    public function hard_delete( int $id ): bool {
        global $wpdb;

        $result = (bool) $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
        self::clear_cache();

        return $result;
    }

    // -----------------------------------------------------------------------
    // Read operations
    // -----------------------------------------------------------------------

    /**
     * Fetch a single zone by ID.
     */
    public function get( int $id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
            ARRAY_A
        );

        return $row ? $this->decode_row( $row ) : null;
    }

    /**
     * Return zones for a branch (all branches when $branch_id is 0).
     */
    public function get_by_branch( int $branch_id, bool $active_only = true ): array {
        global $wpdb;

        if ( $branch_id > 0 ) {
            $sql = $active_only
                ? $wpdb->prepare(
                    "SELECT * FROM {$this->table} WHERE branch_id = %d AND is_active = 1 ORDER BY id ASC",
                    $branch_id
                  )
                : $wpdb->prepare(
                    "SELECT * FROM {$this->table} WHERE branch_id = %d ORDER BY id ASC",
                    $branch_id
                  );
        } else {
            $sql = $active_only
                ? "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY id ASC"
                : "SELECT * FROM {$this->table} ORDER BY id ASC";
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A );

        return array_map( [ $this, 'decode_row' ], $rows ?: [] );
    }

    /**
     * All active zones (paused ones included), cached. The resolver filters
     * paused zones and working hours itself.
     */
    public function get_all_active_cached(): array {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $zones = $this->get_by_branch( 0, true );
        set_transient( self::CACHE_KEY, $zones, HOUR_IN_SECONDS );

        return $zones;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Bounding box of a decoded zone row, or null if it has no usable shape.
     *
     * @return array|null [min_lat, max_lat, min_lng, max_lng]
     */
    public static function compute_bbox( array $zone ): ?array {
        if ( ( $zone['zone_type'] ?? '' ) === 'radius' ) {
            if ( ! isset( $zone['center_lat'], $zone['center_lng'], $zone['radius_km'] ) ) {
                return null;
            }
            $lat = (float) $zone['center_lat'];
            $lng = (float) $zone['center_lng'];
            $r   = (float) $zone['radius_km'];
            if ( $r <= 0 ) {
                return null;
            }
            $d_lat = $r / 110.574;
            $cos   = max( 0.01, cos( deg2rad( $lat ) ) );
            $d_lng = $r / ( 111.320 * $cos );

            return [
                'min_lat' => $lat - $d_lat,
                'max_lat' => $lat + $d_lat,
                'min_lng' => $lng - $d_lng,
                'max_lng' => $lng + $d_lng,
            ];
        }

        $coords = $zone['polygon_coords'] ?? [];
        if ( ! is_array( $coords ) || count( $coords ) < 3 ) {
            return null;
        }

        $lats = [];
        $lngs = [];
        foreach ( $coords as $p ) {
            if ( is_array( $p ) && isset( $p['lat'], $p['lng'] ) ) {
                $lats[] = (float) $p['lat'];
                $lngs[] = (float) $p['lng'];
            } elseif ( is_array( $p ) && count( $p ) >= 2 ) {
                $v      = array_values( $p );
                $lats[] = (float) $v[0];
                $lngs[] = (float) $v[1];
            }
        }
        if ( count( $lats ) < 3 ) {
            return null;
        }

        return [
            'min_lat' => min( $lats ),
            'max_lat' => max( $lats ),
            'min_lng' => min( $lngs ),
            'max_lng' => max( $lngs ),
        ];
    }

    /**
     * Normalise + sanitise input data into a DB row.
     *
     * @return array { values: array, formats: array }
     */
    private function prepare_row( array $data ): array {
        // A manual zone has no shape: just a name, a fixed fee and a minimum order
        $is_manual = ( $data['zone_type'] ?? '' ) === 'manual';
        $zone_type = in_array( $data['zone_type'] ?? 'radius', [ 'radius', 'polygon' ], true )
            ? $data['zone_type']
            : 'radius';

        // Polygon: accept a JSON string or a PHP array, store clean JSON
        $polygon_array = [];
        if ( $zone_type === 'polygon' && ! empty( $data['polygon_coords'] ) ) {
            $raw = is_string( $data['polygon_coords'] )
                ? json_decode( wp_unslash( $data['polygon_coords'] ), true )
                : $data['polygon_coords'];
            if ( is_array( $raw ) ) {
                foreach ( $raw as $p ) {
                    if ( is_array( $p ) && isset( $p['lat'], $p['lng'] ) ) {
                        $polygon_array[] = [ 'lat' => round( (float) $p['lat'], 7 ), 'lng' => round( (float) $p['lng'], 7 ) ];
                    }
                }
            }
        }
        $polygon_json = $polygon_array ? wp_json_encode( $polygon_array ) : null;

        $pricing = in_array( $data['pricing_type'] ?? 'flat', [ 'flat', 'distance' ], true ) ? ( $data['pricing_type'] ?? 'flat' ) : 'flat';
        if ( $is_manual ) {
            $pricing = 'flat';
        }

        // Working hours window: {"from":"HH:MM","to":"HH:MM"}, empty = always open
        $schedule = null;
        if ( ! empty( $data['schedule'] ) ) {
            $sch = is_string( $data['schedule'] ) ? json_decode( wp_unslash( $data['schedule'] ), true ) : $data['schedule'];
            if ( is_array( $sch ) && ! empty( $sch['from'] ) && ! empty( $sch['to'] )
                && preg_match( '/^\d{2}:\d{2}$/', $sch['from'] ) && preg_match( '/^\d{2}:\d{2}$/', $sch['to'] ) ) {
                $schedule = wp_json_encode( [ 'from' => $sch['from'], 'to' => $sch['to'] ] );
            }
        }

        if ( $is_manual ) {
            $data['center_lat'] = $data['center_lng'] = $data['radius_km'] = null;
            $polygon_array = [];
            $polygon_json  = null;
        }

        $center_lat = isset( $data['center_lat'] ) && $data['center_lat'] !== '' ? (float) $data['center_lat'] : null;
        $center_lng = isset( $data['center_lng'] ) && $data['center_lng'] !== '' ? (float) $data['center_lng'] : null;
        $radius_km  = isset( $data['radius_km'] )  && $data['radius_km']  !== '' ? (float) $data['radius_km']  : null;

        $bbox = self::compute_bbox( [
            'zone_type'      => $zone_type,
            'center_lat'     => $center_lat,
            'center_lng'     => $center_lng,
            'radius_km'      => $radius_km,
            'polygon_coords' => $polygon_array,
        ] );

        $values = [
            'branch_id'                  => ! empty( $data['branch_id'] ) ? absint( $data['branch_id'] ) : null,
            'zone_name'                  => sanitize_text_field( $data['zone_name'] ?? '' ),
            'zone_name_ar'               => ! empty( $data['zone_name_ar'] ) ? sanitize_text_field( $data['zone_name_ar'] ) : null,
            'zone_type'                  => $zone_type,
            'center_lat'                 => $zone_type === 'radius' ? $center_lat : null,
            'center_lng'                 => $zone_type === 'radius' ? $center_lng : null,
            'radius_km'                  => $zone_type === 'radius' ? $radius_km : null,
            'polygon_coords'             => $polygon_json,
            'delivery_fee'               => isset( $data['delivery_fee'] )   ? max( 0, (float) $data['delivery_fee'] )   : 0.00,
            'minimum_order'              => isset( $data['minimum_order'] )  ? max( 0, (float) $data['minimum_order'] )  : 0.00,
            'estimated_delivery_minutes' => isset( $data['estimated_delivery_minutes'] ) ? absint( $data['estimated_delivery_minutes'] ) : 30,
            'is_active'                  => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
            'priority'                   => isset( $data['priority'] ) ? (int) $data['priority'] : 0,
            'pricing_type'               => $pricing,
            'base_fee'                   => isset( $data['base_fee'] )   ? max( 0, (float) $data['base_fee'] )   : 0.00,
            'per_km_fee'                 => isset( $data['per_km_fee'] ) ? max( 0, (float) $data['per_km_fee'] ) : 0.00,
            'max_fee'                    => isset( $data['max_fee'] )    ? max( 0, (float) $data['max_fee'] )    : 0.00,
            'free_delivery_over'         => isset( $data['free_delivery_over'] ) ? max( 0, (float) $data['free_delivery_over'] ) : 0.00,
            'schedule'                   => $schedule,
            'is_paused'                  => isset( $data['is_paused'] ) ? (int) (bool) $data['is_paused'] : 0,
            'min_lat'                    => $bbox ? $bbox['min_lat'] : null,
            'max_lat'                    => $bbox ? $bbox['max_lat'] : null,
            'min_lng'                    => $bbox ? $bbox['min_lng'] : null,
            'max_lng'                    => $bbox ? $bbox['max_lng'] : null,
            'is_manual'                  => $is_manual ? 1 : 0,
        ];

        $formats = [
            '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%f', '%f', '%d', '%d',
            '%d', '%s', '%f', '%f', '%f', '%f', '%s', '%d', '%f', '%f', '%f', '%f', '%d',
        ];

        return compact( 'values', 'formats' );
    }

    /**
     * Decode the polygon_coords JSON column back to a PHP array.
     */
    public function decode_row( array $row ): array {
        if ( ! empty( $row['polygon_coords'] ) && is_string( $row['polygon_coords'] ) ) {
            $decoded = json_decode( $row['polygon_coords'], true );
            $row['polygon_coords'] = is_array( $decoded ) ? $decoded : [];
        } else {
            $row['polygon_coords'] = [];
        }

        if ( ! empty( $row['schedule'] ) && is_string( $row['schedule'] ) ) {
            $sch = json_decode( $row['schedule'], true );
            $row['schedule'] = is_array( $sch ) ? $sch : null;
        } else {
            $row['schedule'] = null;
        }

        return $row;
    }
}
