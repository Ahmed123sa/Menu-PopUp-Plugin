<?php
/**
 * Delivery Zone Model
 *
 * Handles CRUD operations for delivery zones.
 * Each zone belongs to a vendor/branch and can be either:
 *   - 'radius'  : circle defined by (center_lat, center_lng, radius_km)
 *   - 'polygon' : arbitrary shape defined by a JSON array of {lat, lng} points
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

    /** @var int DB schema version – bump when columns change */
    const SCHEMA_VERSION = '2.0.0';

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
     * Create or upgrade the delivery zones table.
     *
     * Adds the geo columns (zone_type, center_lat, center_lng, radius_km,
     * polygon_coords) that were missing from the v1 schema defined in
     * class-schema.php.  Safe to call on every activation thanks to dbDelta.
     */
    public static function maybe_run_migration(): void {
        global $wpdb;

        $table          = $wpdb->prefix . 'menu_popup_delivery_zones';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                       BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id                BIGINT(20) UNSIGNED DEFAULT NULL,
            zone_name                VARCHAR(100) NOT NULL,
            zone_name_ar             VARCHAR(100) DEFAULT NULL,
            zone_type                ENUM('radius','polygon') NOT NULL DEFAULT 'radius',
            center_lat               DECIMAL(10,7) DEFAULT NULL COMMENT 'Radius zone: centre latitude',
            center_lng               DECIMAL(10,7) DEFAULT NULL COMMENT 'Radius zone: centre longitude',
            radius_km                DECIMAL(8,4)  DEFAULT NULL COMMENT 'Radius zone: radius in kilometres',
            polygon_coords           LONGTEXT      DEFAULT NULL COMMENT 'Polygon zone: JSON [{\"lat\":…,\"lng\":…},…]',
            delivery_fee             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            minimum_order            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            estimated_delivery_minutes INT(11)     DEFAULT 30,
            is_active                TINYINT(1)   DEFAULT 1,
            created_at               DATETIME     DEFAULT CURRENT_TIMESTAMP,
            updated_at               DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY branch_id (branch_id),
            KEY is_active (is_active),
            KEY zone_type (zone_type)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'menu_popup_delivery_zone_schema_version', self::SCHEMA_VERSION );
    }

    // -----------------------------------------------------------------------
    // Write operations
    // -----------------------------------------------------------------------

    /**
     * Insert a new delivery zone.
     *
     * @param array $data {
     *   @type int    branch_id
     *   @type string zone_name
     *   @type string zone_name_ar
     *   @type string zone_type            'radius' | 'polygon'
     *   @type float  center_lat           (radius zones)
     *   @type float  center_lng           (radius zones)
     *   @type float  radius_km            (radius zones)
     *   @type array  polygon_coords       [[lat,lng],…] or [{lat,lng},…]  (polygon zones)
     *   @type float  delivery_fee
     *   @type float  minimum_order
     *   @type int    estimated_delivery_minutes
     *   @type int    is_active
     * }
     * @return int|false  New zone ID on success, false on failure.
     */
    public function create( array $data ): int|false {
        global $wpdb;

        $row    = $this->prepare_row( $data );
        $result = $wpdb->insert( $this->table, $row['values'], $row['formats'] );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing zone.
     *
     * @param int   $id
     * @param array $data  Same keys as create().
     * @return bool
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

        return $result !== false;
    }

    /**
     * Soft-delete: set is_active = 0.
     *
     * @param int $id
     * @return bool
     */
    public function delete( int $id ): bool {
        global $wpdb;

        return (bool) $wpdb->update(
            $this->table,
            [ 'is_active' => 0 ],
            [ 'id' => $id ],
            [ '%d' ],
            [ '%d' ]
        );
    }

    /**
     * Hard-delete a zone row.
     *
     * @param int $id
     * @return bool
     */
    public function hard_delete( int $id ): bool {
        global $wpdb;

        return (bool) $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
    }

    // -----------------------------------------------------------------------
    // Read operations
    // -----------------------------------------------------------------------

    /**
     * Fetch a single zone by ID.
     *
     * @param int $id
     * @return array|null  Associative array with decoded polygon_coords.
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
     * Return all active zones for a given branch/vendor.
     *
     * @param int  $branch_id
     * @param bool $active_only  When true (default) skip inactive zones.
     * @return array  List of zone rows.
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

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Normalise + sanitise input data into a DB row.
     *
     * @param array $data
     * @return array { values: array, formats: array }
     */
    private function prepare_row( array $data ): array {
        $zone_type = in_array( $data['zone_type'] ?? 'radius', [ 'radius', 'polygon' ], true )
            ? $data['zone_type']
            : 'radius';

        // Encode polygon to JSON when supplied as PHP array
        $polygon_json = null;
        if ( $zone_type === 'polygon' && ! empty( $data['polygon_coords'] ) ) {
            $polygon_json = is_string( $data['polygon_coords'] )
                ? $data['polygon_coords']
                : wp_json_encode( $data['polygon_coords'] );
        }

        $values = [
            'branch_id'                  => ! empty( $data['branch_id'] ) ? absint( $data['branch_id'] ) : null,
            'zone_name'                  => sanitize_text_field( $data['zone_name'] ?? '' ),
            'zone_name_ar'               => ! empty( $data['zone_name_ar'] ) ? sanitize_text_field( $data['zone_name_ar'] ) : null,
            'zone_type'                  => $zone_type,
            'center_lat'                 => isset( $data['center_lat'] ) ? (float) $data['center_lat'] : null,
            'center_lng'                 => isset( $data['center_lng'] ) ? (float) $data['center_lng'] : null,
            'radius_km'                  => isset( $data['radius_km'] )  ? (float) $data['radius_km']  : null,
            'polygon_coords'             => $polygon_json,
            'delivery_fee'               => isset( $data['delivery_fee'] )   ? (float) $data['delivery_fee']   : 0.00,
            'minimum_order'              => isset( $data['minimum_order'] )  ? (float) $data['minimum_order']  : 0.00,
            'estimated_delivery_minutes' => isset( $data['estimated_delivery_minutes'] ) ? absint( $data['estimated_delivery_minutes'] ) : 30,
            'is_active'                  => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
        ];

        $formats = [ '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%f', '%f', '%d', '%d' ];

        return compact( 'values', 'formats' );
    }

    /**
     * Decode the polygon_coords JSON column back to a PHP array.
     *
     * @param array $row
     * @return array
     */
    private function decode_row( array $row ): array {
        if ( ! empty( $row['polygon_coords'] ) && is_string( $row['polygon_coords'] ) ) {
            $decoded = json_decode( $row['polygon_coords'], true );
            $row['polygon_coords'] = is_array( $decoded ) ? $decoded : [];
        } else {
            $row['polygon_coords'] = [];
        }
        return $row;
    }
}
