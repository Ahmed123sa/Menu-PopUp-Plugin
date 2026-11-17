<?php
/**
 * Delivery Zone Admin Page
 *
 * "Delivery Zones" sub-menu: one map with every zone and branch, a form to
 * draw a circle or polygon and set its pricing, and a tester that runs a
 * point through the same rules a customer order uses.
 *
 * @package    MenuPopup
 * @subpackage Delivery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu_Popup_Admin_Delivery_Zones {

    /** @var self|null Singleton */
    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'register_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function register_submenu(): void {
        add_submenu_page(
            'menu-popup-dashboard',
            __( 'Delivery Zones', 'menu-popup' ),
            __( '📍 Delivery Zones', 'menu-popup' ),
            'manage_options',
            'mp-delivery-zones',
            [ $this, 'render_page' ]
        );
    }

    // -----------------------------------------------------------------------
    // Assets
    // -----------------------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'mp-delivery-zones' ) === false ) {
            return;
        }

        $mode         = Menu_Popup_Delivery_Zone_Model::mode();
        $maps_api_key = $mode === 'map' ? get_option( 'menu_popup_google_maps_api_key', '' ) : '';
        $deps         = [ 'jquery' ];

        if ( $maps_api_key ) {
            wp_enqueue_script(
                'google-maps-api',
                'https://maps.googleapis.com/maps/api/js?key=' . rawurlencode( $maps_api_key ) . '&libraries=geometry',
                [],
                null,
                true
            );
            $deps[] = 'google-maps-api';
        }

        wp_enqueue_script(
            'mp-delivery-zones-admin',
            MENU_POPUP_PLUGIN_URL . 'assets/js/delivery-zones-admin.js',
            $deps,
            MENU_POPUP_VERSION,
            true
        );

        // Every zone and branch pin, for the overview map
        $model     = new Menu_Popup_Delivery_Zone_Model();
        $all_zones = [];
        foreach ( $model->get_by_branch( 0, false ) as $z ) {
            $all_zones[] = [
                'id'             => (int) $z['id'],
                'name'           => $z['zone_name'],
                'branch_id'      => (int) $z['branch_id'],
                'type'           => $z['zone_type'],
                'center_lat'     => $z['center_lat'] !== null ? (float) $z['center_lat'] : null,
                'center_lng'     => $z['center_lng'] !== null ? (float) $z['center_lng'] : null,
                'radius_km'      => $z['radius_km'] !== null ? (float) $z['radius_km'] : null,
                'polygon_coords' => $z['polygon_coords'],
                'is_active'      => (int) $z['is_active'],
                'is_paused'      => (int) $z['is_paused'],
            ];
        }

        $branch_pins = [];
        foreach ( $this->get_branches() as $b ) {
            $lat = get_post_meta( $b->ID, '_branch_lat', true );
            $lng = get_post_meta( $b->ID, '_branch_lng', true );
            if ( $lat !== '' && $lng !== '' && is_numeric( $lat ) && is_numeric( $lng ) ) {
                $branch_pins[] = [ 'id' => $b->ID, 'name' => $b->post_title, 'lat' => (float) $lat, 'lng' => (float) $lng ];
            }
        }

        wp_localize_script( 'mp-delivery-zones-admin', 'mpDeliveryZones', [
            'ajaxurl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'mp_admin_zone_nonce' ),
            'hasMapsApi' => ! empty( $maps_api_key ),
            'mode'       => $mode,
            'center'     => [
                'lat' => (float) get_option( 'menu_popup_map_default_lat', '30.0444' ),
                'lng' => (float) get_option( 'menu_popup_map_default_lng', '31.2357' ),
            ],
            'zones'      => $all_zones,
            'branches'   => $branch_pins,
            'activeBranch' => absint( $_GET['branch_id'] ?? 0 ),
            'i18n'       => [
                'confirmDelete' => __( 'Delete this zone? This cannot be undone.', 'menu-popup' ),
                'savedOk'       => __( 'Zone saved successfully.', 'menu-popup' ),
                'drawRadius'    => __( 'Click the map to set the zone centre, then drag to resize the circle.', 'menu-popup' ),
                'drawPolygon'   => __( 'Click to add points. Click the first point to close the shape.', 'menu-popup' ),
                'noShape'       => __( 'Draw the zone on the map first.', 'menu-popup' ),
                'testOk'        => __( 'Delivery available', 'menu-popup' ),
                'circleSecond'  => __( 'Now move the mouse and click again to set the radius.', 'menu-popup' ),
                'pointsAdded'   => __( 'points added. Click the first point or press Finish shape to close it.', 'menu-popup' ),
                'needThree'     => __( 'A custom shape needs at least 3 points.', 'menu-popup' ),
                'geocodeDenied' => __( 'Search is blocked: enable the Geocoding API for your Google Maps key.', 'menu-popup' ),
                'noResults'     => __( 'No results for this search.', 'menu-popup' ),
                'authFailed'    => __( 'Google Maps rejected the API key. Check the key, billing and allowed websites.', 'menu-popup' ),
            ],
        ] );

        $css = '
            #mp-zones-wrap { max-width: 1200px; }
            #mp-zones-wrap .mp-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px; margin-bottom:20px; }
            #mp-zones-map  { width:100%; height:520px; border:1px solid #c3c4c7; border-radius:4px; margin-top:10px; }
            .mp-zone-row   { border-bottom:1px solid #f0f0f1; padding:10px 0; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
            .mp-zone-row:last-child { border-bottom:none; }
            .mp-badge       { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase; }
            .mp-badge-radius  { background:#e8f5e9; color:#2e7d32; }
            .mp-badge-polygon { background:#e3f2fd; color:#1565c0; }
            .mp-badge-inactive{ background:#fce4ec; color:#c62828; }
            .mp-badge-paused  { background:#fff3e0; color:#e65100; }
            #mp-zone-warnings .notice { margin:8px 0; }
            #mp-test-result { margin-top:10px; padding:10px 12px; border-radius:4px; display:none; }
            #mp-test-result.ok { background:#ecfdf5; border:1px solid #6ee7b7; }
            #mp-test-result.no { background:#fff1f2; border:1px solid #fda4af; }
            .mp-legend span { display:inline-block; margin-right:14px; font-size:12px; }
            .mp-legend i { display:inline-block; width:12px; height:12px; border-radius:2px; margin-right:4px; vertical-align:-2px; }
        ';
        wp_add_inline_style( 'wp-admin', $css );
    }

    private function get_branches(): array {
        return get_posts( [
            'post_type'      => 'menu_popup_branch',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );
    }

    // -----------------------------------------------------------------------
    // Page
    // -----------------------------------------------------------------------

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'menu-popup' ) );
        }

        $branches         = $this->get_branches();
        $active_branch_id = absint( $_GET['branch_id'] ?? ( $branches[0]->ID ?? 0 ) );
        $has_key          = (bool) get_option( 'menu_popup_google_maps_api_key' );

        $model     = new Menu_Popup_Delivery_Zone_Model();
        $mode      = Menu_Popup_Delivery_Zone_Model::mode();
        $is_manual = $mode === 'manual';
        $zones     = $active_branch_id ? $model->get_by_branch( $active_branch_id, false ) : [];
        // Each mode keeps its own zones
        $zones = array_values( array_filter( $zones, static function ( $z ) use ( $is_manual ) {
            return ! empty( $z['is_manual'] ) === $is_manual;
        } ) );
        $delivery_on = get_option( 'menu_popup_delivery_enabled', '0' ) === '1';

        $missing_location = [];
        foreach ( $branches as $b ) {
            if ( get_post_meta( $b->ID, '_branch_lat', true ) === '' ) {
                $missing_location[] = $b->post_title;
            }
        }
        ?>
        <div class="wrap" id="mp-zones-wrap">
            <h1><?php esc_html_e( 'Delivery Zones', 'menu-popup' ); ?></h1>

            <div class="notice notice-info inline"><p>
                <strong><?php esc_html_e( 'Zones mode:', 'menu-popup' ); ?></strong>
                <?php echo $is_manual ? esc_html__( 'Manual zones (fixed fee, customer picks from a list).', 'menu-popup' ) : esc_html__( 'By map (customer drops a pin).', 'menu-popup' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=menu-popup-settings' ) ); ?>"><?php esc_html_e( 'Change in settings', 'menu-popup' ); ?></a>
            </p></div>

            <?php if ( ! $delivery_on ) : ?>
                <div class="notice notice-warning inline"><p>
                    <?php esc_html_e( 'Delivery is turned off in settings, so customers cannot order delivery at all.', 'menu-popup' ); ?>
                </p></div>
            <?php endif; ?>

            <?php if ( ! $is_manual && ! $has_key ) : ?>
                <div class="notice notice-warning"><p>
                    <?php esc_html_e( 'Add your Google Maps API key to turn on the map. Without it customers cannot choose a delivery location.', 'menu-popup' ); ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=menu-popup-settings' ) ); ?>"><?php esc_html_e( 'Open settings', 'menu-popup' ); ?></a>
                </p></div>
            <?php endif; ?>

            <?php if ( ! $is_manual && $missing_location ) : ?>
                <div class="notice notice-warning"><p>
                    <?php
                    printf(
                        /* translators: %s: comma separated branch names */
                        esc_html__( 'These branches have no map location yet: %s. Distance pricing and the nearest-branch list need it. Set it in each branch page.', 'menu-popup' ),
                        '<strong>' . esc_html( implode( ', ', $missing_location ) ) . '</strong>'
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <div class="mp-card">
                <form method="get" action="">
                    <input type="hidden" name="page" value="mp-delivery-zones">
                    <label for="mp-branch-select"><strong><?php esc_html_e( 'Branch', 'menu-popup' ); ?></strong></label>&nbsp;
                    <select id="mp-branch-select" name="branch_id">
                        <?php foreach ( $branches as $branch ) : ?>
                            <option value="<?php echo esc_attr( $branch->ID ); ?>" <?php selected( $branch->ID, $active_branch_id ); ?>>
                                <?php echo esc_html( $branch->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button"><?php esc_html_e( 'Show zones', 'menu-popup' ); ?></button>
                </form>
            </div>

            <?php if ( empty( $branches ) ) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e( 'No branches found. Please create a branch first.', 'menu-popup' ); ?></p></div>
            <?php else : ?>

            <?php if ( ! $is_manual ) : ?>
            <div class="mp-card">
                <h2><?php esc_html_e( 'Map', 'menu-popup' ); ?></h2>
                <p class="mp-legend">
                    <span><i style="background:#2563eb"></i><?php esc_html_e( 'Selected branch zones', 'menu-popup' ); ?></span>
                    <span><i style="background:#9ca3af"></i><?php esc_html_e( 'Other branches', 'menu-popup' ); ?></span>
                    <span><i style="background:#f59e0b"></i><?php esc_html_e( 'Paused or inactive', 'menu-popup' ); ?></span>
                    <span>📍 <?php esc_html_e( 'Branch', 'menu-popup' ); ?></span>
                </p>
                <?php if ( $has_key ) : ?>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:8px; align-items:center;">
                        <input type="search" id="mp-map-search" class="regular-text" style="min-width:280px;" placeholder="<?php esc_attr_e( 'Search an area or address, or paste lat,lng', 'menu-popup' ); ?>">
                        <button type="button" class="button" id="mp-map-search-btn"><?php esc_html_e( 'Search', 'menu-popup' ); ?></button>
                        <button type="button" class="button" id="mp-draw-finish" style="display:none;"><?php esc_html_e( 'Finish shape', 'menu-popup' ); ?></button>
                        <button type="button" class="button" id="mp-draw-clear"><?php esc_html_e( 'Redraw shape', 'menu-popup' ); ?></button>
                        <span class="description" id="mp-search-msg"></span>
                    </div>
                    <div id="mp-zones-map"></div>
                    <p class="description" id="mp-map-hint" style="margin-top:8px;"></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="mp-card">
                <h2><?php esc_html_e( 'Zones of this branch', 'menu-popup' ); ?></h2>

                <div id="mp-zones-list">
                <?php if ( empty( $zones ) ) : ?>
                    <p class="description"><?php esc_html_e( 'No zones yet. Add one below.', 'menu-popup' ); ?></p>
                <?php else : ?>
                    <?php foreach ( $zones as $zone ) : ?>
                    <div class="mp-zone-row" data-zone-id="<?php echo esc_attr( $zone['id'] ); ?>">
                        <span class="mp-badge mp-badge-<?php echo esc_attr( $zone['zone_type'] ); ?>"><?php echo esc_html( $is_manual ? 'manual' : $zone['zone_type'] ); ?></span>
                        <?php if ( ! $zone['is_active'] ) : ?>
                            <span class="mp-badge mp-badge-inactive"><?php esc_html_e( 'inactive', 'menu-popup' ); ?></span>
                        <?php endif; ?>
                        <?php if ( $zone['is_paused'] ) : ?>
                            <span class="mp-badge mp-badge-paused"><?php esc_html_e( 'paused', 'menu-popup' ); ?></span>
                        <?php endif; ?>

                        <strong><?php echo esc_html( $zone['zone_name'] ); ?></strong>
                        <?php if ( $zone['zone_name_ar'] ) : ?>
                            <span style="direction:rtl"><?php echo esc_html( $zone['zone_name_ar'] ); ?></span>
                        <?php endif; ?>

                        &mdash;
                        <?php
                        if ( $zone['pricing_type'] === 'distance' ) {
                            printf(
                                esc_html__( 'Fee: %1$s + %2$s per km (max %3$s)', 'menu-popup' ),
                                esc_html( $zone['base_fee'] ),
                                esc_html( $zone['per_km_fee'] ),
                                (float) $zone['max_fee'] > 0 ? esc_html( $zone['max_fee'] ) : '∞'
                            );
                        } else {
                            printf( esc_html__( 'Fee: %s', 'menu-popup' ), esc_html( $zone['delivery_fee'] ) );
                        }
                        printf( ' | ' . esc_html__( 'Min order: %s', 'menu-popup' ), esc_html( $zone['minimum_order'] ) );
                        printf( ' | ' . esc_html__( 'Priority: %d', 'menu-popup' ), (int) $zone['priority'] );
                        ?>

                        <span style="margin-left:auto; display:flex; gap:6px;">
                            <button class="button button-small mp-pause-zone" data-id="<?php echo esc_attr( $zone['id'] ); ?>" data-paused="<?php echo $zone['is_paused'] ? '1' : '0'; ?>">
                                <?php echo $zone['is_paused'] ? esc_html__( 'Resume', 'menu-popup' ) : esc_html__( 'Pause', 'menu-popup' ); ?>
                            </button>
                            <button class="button button-small mp-edit-zone" data-zone='<?php echo esc_attr( wp_json_encode( $zone ) ); ?>'>
                                <?php esc_html_e( 'Edit', 'menu-popup' ); ?>
                            </button>
                            <button class="button button-small button-link-delete mp-delete-zone" data-id="<?php echo esc_attr( $zone['id'] ); ?>">
                                <?php esc_html_e( 'Delete', 'menu-popup' ); ?>
                            </button>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>

            <div class="mp-card">
                <h2 id="mp-zone-form-title"><?php esc_html_e( 'Add New Zone', 'menu-popup' ); ?></h2>

                <form id="mp-zone-form">
                    <input type="hidden" id="mp-zone-id" value="0">
                    <input type="hidden" id="mp-zone-branch-id" value="<?php echo esc_attr( $active_branch_id ); ?>">
                    <input type="hidden" id="mp-center-lat">
                    <input type="hidden" id="mp-center-lng">
                    <input type="hidden" id="mp-radius-km">
                    <input type="hidden" id="mp-polygon-coords">

                    <table class="form-table">
                        <tr>
                            <th><label for="mp-zone-name"><?php esc_html_e( 'Zone name (English)', 'menu-popup' ); ?></label></th>
                            <td><input type="text" id="mp-zone-name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="mp-zone-name-ar"><?php esc_html_e( 'Zone name (Arabic)', 'menu-popup' ); ?></label></th>
                            <td><input type="text" id="mp-zone-name-ar" class="regular-text" dir="rtl"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Shape', 'menu-popup' ); ?></th>
                            <td>
                                <label><input type="radio" name="mp-zone-type" value="radius" checked> <?php esc_html_e( 'Circle', 'menu-popup' ); ?></label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="mp-zone-type" value="polygon"> <?php esc_html_e( 'Custom shape', 'menu-popup' ); ?></label>
                                <p class="description" id="mp-shape-status"></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Delivery fee', 'menu-popup' ); ?></th>
                            <td>
                                <label><input type="radio" name="mp-pricing-type" value="flat" checked> <?php esc_html_e( 'Fixed', 'menu-popup' ); ?></label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="mp-pricing-type" value="distance"> <?php esc_html_e( 'By distance (straight line from the branch)', 'menu-popup' ); ?></label>
                            </td>
                        </tr>
                        <tbody id="mp-flat-fields">
                        <tr>
                            <th><label for="mp-delivery-fee"><?php esc_html_e( 'Fee', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-delivery-fee" step="0.01" min="0" class="small-text" value="0"></td>
                        </tr>
                        </tbody>
                        <tbody id="mp-distance-fields" style="display:none;">
                        <tr>
                            <th><label for="mp-base-fee"><?php esc_html_e( 'Starting fee', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-base-fee" step="0.01" min="0" class="small-text" value="0"></td>
                        </tr>
                        <tr>
                            <th><label for="mp-per-km-fee"><?php esc_html_e( 'Price per km', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-per-km-fee" step="0.01" min="0" class="small-text" value="0"></td>
                        </tr>
                        <tr>
                            <th><label for="mp-max-fee"><?php esc_html_e( 'Maximum fee', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-max-fee" step="0.01" min="0" class="small-text" value="0"> <span class="description"><?php esc_html_e( '0 = no limit', 'menu-popup' ); ?></span></td>
                        </tr>
                        </tbody>
                        <tr>
                            <th><label for="mp-free-over"><?php esc_html_e( 'Free delivery over', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-free-over" step="0.01" min="0" class="small-text" value="0"> <span class="description"><?php esc_html_e( '0 = never free', 'menu-popup' ); ?></span></td>
                        </tr>
                        <tr>
                            <th><label for="mp-minimum-order"><?php esc_html_e( 'Minimum order', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-minimum-order" step="0.01" min="0" class="small-text" value="0"></td>
                        </tr>
                        <tr>
                            <th><label for="mp-eta"><?php esc_html_e( 'Estimated delivery (minutes)', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-eta" min="1" class="small-text" value="30"></td>
                        </tr>
                        <tr>
                            <th><label for="mp-priority"><?php esc_html_e( 'Priority', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-priority" class="small-text" value="0"> <span class="description"><?php esc_html_e( 'When zones overlap, the higher number wins.', 'menu-popup' ); ?></span></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Working hours', 'menu-popup' ); ?></th>
                            <td>
                                <input type="time" id="mp-schedule-from"> &ndash; <input type="time" id="mp-schedule-to">
                                <span class="description"><?php esc_html_e( 'Leave empty for always open. A window like 18:00 - 02:00 is fine.', 'menu-popup' ); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Status', 'menu-popup' ); ?></th>
                            <td>
                                <label><input type="checkbox" id="mp-is-active" checked> <?php esc_html_e( 'Active', 'menu-popup' ); ?></label>
                                &nbsp;&nbsp;
                                <label><input type="checkbox" id="mp-is-paused"> <?php esc_html_e( 'Paused', 'menu-popup' ); ?></label>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary" id="mp-save-zone-btn"><?php esc_html_e( 'Save Zone', 'menu-popup' ); ?></button>
                        <button type="button" class="button" id="mp-cancel-edit" style="display:none;"><?php esc_html_e( 'Cancel Edit', 'menu-popup' ); ?></button>
                        <span class="spinner" style="float:none;vertical-align:middle;"></span>
                    </p>
                    <div id="mp-zone-message" style="display:none; margin-top:10px;"></div>
                    <div id="mp-zone-warnings"></div>
                </form>
            </div>

            <?php if ( ! $is_manual ) : ?>
            <div class="mp-card">
                <h2><?php esc_html_e( 'Test a location', 'menu-popup' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Runs the same rules a customer order runs. Tip: click the map to fill the coordinates.', 'menu-popup' ); ?></p>
                <p>
                    <input type="number" id="mp-test-lat" step="any" placeholder="<?php esc_attr_e( 'Latitude', 'menu-popup' ); ?>" class="regular-text" style="width:150px">
                    <input type="number" id="mp-test-lng" step="any" placeholder="<?php esc_attr_e( 'Longitude', 'menu-popup' ); ?>" class="regular-text" style="width:150px">
                    <input type="number" id="mp-test-value" step="0.01" min="0" placeholder="<?php esc_attr_e( 'Order value', 'menu-popup' ); ?>" class="regular-text" style="width:130px">
                    <button type="button" class="button" id="mp-test-btn"><?php esc_html_e( 'Test', 'menu-popup' ); ?></button>
                </p>
                <div id="mp-test-result"></div>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }
}
