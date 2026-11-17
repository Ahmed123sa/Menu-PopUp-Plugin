<?php
/**
 * Delivery Zone Admin Page
 *
 * Adds a "Delivery Zones" sub-menu under the plugin's admin menu and renders
 * a full CRUD interface backed by Google Maps for drawing zones graphically.
 *
 * Requires: class-delivery-zone-model.php, class-delivery-zone-checker.php
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
        add_action( 'admin_menu',            [ $this, 'register_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // -----------------------------------------------------------------------
    // Menu registration
    // -----------------------------------------------------------------------

    public function register_submenu(): void {
        add_submenu_page(
            'menu-popup-dashboard',                   // ← correct parent slug for this plugin
            __( 'Delivery Zones', 'menu-popup' ),
            __( '📍 Delivery Zones', 'menu-popup' ),
            'manage_options',
            'mp-delivery-zones',
            [ $this, 'render_page' ]
        );
    }

    // -----------------------------------------------------------------------
    // Asset enqueueing
    // -----------------------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {
        // Only load on our page
        if ( strpos( $hook, 'mp-delivery-zones' ) === false ) {
            return;
        }

        // Google Maps JS API – replace YOUR_API_KEY or store it in plugin settings
        $maps_api_key = get_option( 'menu_popup_google_maps_api_key', '' );
        if ( $maps_api_key ) {
            wp_enqueue_script(
                'google-maps-api',
                'https://maps.googleapis.com/maps/api/js?key=' . urlencode( $maps_api_key ) . '&libraries=drawing,geometry',
                [],
                null,
                true
            );
        }

        // Inline admin page JS (zone CRUD + map drawing)
        wp_enqueue_script(
            'mp-delivery-zones-admin',
            MENU_POPUP_PLUGIN_URL . 'assets/js/delivery-zones-admin.js',
            [ 'jquery' ],
            MENU_POPUP_VERSION,
            true
        );

        wp_localize_script( 'mp-delivery-zones-admin', 'mpDeliveryZones', [
            'ajaxurl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'mp_admin_zone_nonce' ),
            'publicNonce'    => wp_create_nonce( 'mp_delivery_zone_nonce' ),
            'hasMapsApi'     => ! empty( $maps_api_key ),
            'mapsApiKey'     => $maps_api_key,
            'i18n'           => [
                'confirmDelete'   => __( 'Delete this zone? This cannot be undone.', 'menu-popup' ),
                'savedOk'         => __( 'Zone saved successfully.', 'menu-popup' ),
                'drawRadius'      => __( 'Click the map to set zone centre, then adjust the radius.', 'menu-popup' ),
                'drawPolygon'     => __( 'Click to add polygon vertices. Double-click to close.', 'menu-popup' ),
            ],
        ] );

        // Minimal admin styles (inline to avoid an extra HTTP request)
        $css = '
            #mp-zones-wrap { max-width: 1200px; }
            #mp-zones-wrap .mp-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px; margin-bottom:20px; }
            #mp-zones-map  { width:100%; height:480px; border:1px solid #c3c4c7; border-radius:4px; margin-top:10px; }
            .mp-zone-row   { border-bottom:1px solid #f0f0f1; padding:10px 0; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
            .mp-zone-row:last-child { border-bottom:none; }
            .mp-badge       { display:inline-block; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase; }
            .mp-badge-radius  { background:#e8f5e9; color:#2e7d32; }
            .mp-badge-polygon { background:#e3f2fd; color:#1565c0; }
            .mp-badge-inactive{ background:#fce4ec; color:#c62828; }
        ';
        wp_add_inline_style( 'wp-admin', $css );
    }

    // -----------------------------------------------------------------------
    // Page render
    // -----------------------------------------------------------------------

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'menu-popup' ) );
        }

        // Fetch all branches for the selector
        $branches = get_posts( [
            'post_type'      => 'menu_popup_branch',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        // Active branch from query string
        $active_branch_id = absint( $_GET['branch_id'] ?? ( $branches[0]->ID ?? 0 ) );

        $model = new Menu_Popup_Delivery_Zone_Model();
        $zones = $active_branch_id ? $model->get_by_branch( $active_branch_id, false ) : [];
        ?>
        <div class="wrap" id="mp-zones-wrap">
            <h1><?php esc_html_e( 'Delivery Zones', 'menu-popup' ); ?></h1>

            <!-- ── Branch selector ────────────────────────────────────────── -->
            <div class="mp-card">
                <form method="get" action="">
                    <input type="hidden" name="page" value="mp-delivery-zones">
                    <label for="mp-branch-select"><strong><?php esc_html_e( 'Select Branch / Vendor', 'menu-popup' ); ?></strong></label>&nbsp;
                    <select id="mp-branch-select" name="branch_id">
                        <?php foreach ( $branches as $branch ) : ?>
                            <option value="<?php echo esc_attr( $branch->ID ); ?>"
                                <?php selected( $branch->ID, $active_branch_id ); ?>>
                                <?php echo esc_html( $branch->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button"><?php esc_html_e( 'Load Zones', 'menu-popup' ); ?></button>
                </form>
            </div>

            <?php if ( empty( $branches ) ) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e( 'No branches found. Please create a branch first.', 'menu-popup' ); ?></p></div>
            <?php else : ?>

            <!-- ── Existing zones list ────────────────────────────────────── -->
            <div class="mp-card">
                <h2><?php esc_html_e( 'Configured Zones', 'menu-popup' ); ?></h2>

                <div id="mp-zones-list">
                <?php if ( empty( $zones ) ) : ?>
                    <p class="description"><?php esc_html_e( 'No zones configured yet. Add one below.', 'menu-popup' ); ?></p>
                <?php else : ?>
                    <?php foreach ( $zones as $zone ) : ?>
                    <div class="mp-zone-row" data-zone-id="<?php echo esc_attr( $zone['id'] ); ?>">
                        <span class="mp-badge mp-badge-<?php echo esc_attr( $zone['zone_type'] ); ?>">
                            <?php echo esc_html( $zone['zone_type'] ); ?>
                        </span>
                        <?php if ( ! $zone['is_active'] ) : ?>
                            <span class="mp-badge mp-badge-inactive"><?php esc_html_e( 'inactive', 'menu-popup' ); ?></span>
                        <?php endif; ?>

                        <strong><?php echo esc_html( $zone['zone_name'] ); ?></strong>
                        <?php if ( $zone['zone_name_ar'] ) : ?>
                            <span style="direction:rtl"><?php echo esc_html( $zone['zone_name_ar'] ); ?></span>
                        <?php endif; ?>

                        <?php if ( $zone['zone_type'] === 'radius' ) : ?>
                            &mdash; <?php printf( esc_html__( 'Centre: %.5f, %.5f &nbsp;|&nbsp; Radius: %s km', 'menu-popup' ),
                                (float) $zone['center_lat'], (float) $zone['center_lng'], esc_html( $zone['radius_km'] ) ); ?>
                        <?php else : ?>
                            &mdash; <?php printf( esc_html__( '%d vertices', 'menu-popup' ), count( $zone['polygon_coords'] ) ); ?>
                        <?php endif; ?>

                        &mdash; <?php printf( esc_html__( 'Fee: %s &nbsp;|&nbsp; Min order: %s', 'menu-popup' ),
                            esc_html( $zone['delivery_fee'] ), esc_html( $zone['minimum_order'] ) ); ?>

                        <span style="margin-left:auto; display:flex; gap:6px;">
                            <button class="button button-small mp-edit-zone"
                                    data-zone='<?php echo esc_attr( wp_json_encode( $zone ) ); ?>'>
                                <?php esc_html_e( 'Edit', 'menu-popup' ); ?>
                            </button>
                            <button class="button button-small button-link-delete mp-delete-zone"
                                    data-id="<?php echo esc_attr( $zone['id'] ); ?>">
                                <?php esc_html_e( 'Delete', 'menu-popup' ); ?>
                            </button>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div><!-- /#mp-zones-list -->
            </div>

            <!-- ── Add / Edit zone form ───────────────────────────────────── -->
            <div class="mp-card">
                <h2 id="mp-zone-form-title"><?php esc_html_e( 'Add New Zone', 'menu-popup' ); ?></h2>

                <form id="mp-zone-form">
                    <input type="hidden" id="mp-zone-id"        value="0">
                    <input type="hidden" id="mp-zone-branch-id" value="<?php echo esc_attr( $active_branch_id ); ?>">

                    <table class="form-table">
                        <tr>
                            <th><label for="mp-zone-name"><?php esc_html_e( 'Zone Name (EN)', 'menu-popup' ); ?></label></th>
                            <td><input type="text" id="mp-zone-name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="mp-zone-name-ar"><?php esc_html_e( 'Zone Name (AR)', 'menu-popup' ); ?></label></th>
                            <td><input type="text" id="mp-zone-name-ar" class="regular-text" dir="rtl"></td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Zone Type', 'menu-popup' ); ?></label></th>
                            <td>
                                <label>
                                    <input type="radio" name="mp-zone-type" value="radius" checked>
                                    <?php esc_html_e( 'Radius (circle)', 'menu-popup' ); ?>
                                </label>
                                &nbsp;&nbsp;
                                <label>
                                    <input type="radio" name="mp-zone-type" value="polygon">
                                    <?php esc_html_e( 'Polygon (custom shape)', 'menu-popup' ); ?>
                                </label>
                            </td>
                        </tr>

                        <!-- Radius fields -->
                        <tbody id="mp-radius-fields">
                        <tr>
                            <th><label for="mp-center-lat"><?php esc_html_e( 'Centre Latitude', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-center-lat" step="any" class="small-text"
                                       placeholder="e.g. 24.7136"></td>
                        </tr>
                        <tr>
                            <th><label for="mp-center-lng"><?php esc_html_e( 'Centre Longitude', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-center-lng" step="any" class="small-text"
                                       placeholder="e.g. 46.6753"></td>
                        </tr>
                        <tr>
                            <th><label for="mp-radius-km"><?php esc_html_e( 'Radius (km)', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-radius-km" step="0.1" min="0.1" class="small-text"
                                       placeholder="e.g. 5"></td>
                        </tr>
                        </tbody>

                        <!-- Polygon fields -->
                        <tbody id="mp-polygon-fields" style="display:none;">
                        <tr>
                            <th><?php esc_html_e( 'Polygon Coordinates', 'menu-popup' ); ?></th>
                            <td>
                                <textarea id="mp-polygon-coords" rows="6" class="large-text"
                                          placeholder='[{"lat":24.71,"lng":46.67},{"lat":24.73,"lng":46.69},{"lat":24.70,"lng":46.70}]'></textarea>
                                <p class="description">
                                    <?php esc_html_e( 'JSON array of {lat, lng} objects. Draw on the map to fill automatically.', 'menu-popup' ); ?>
                                </p>
                            </td>
                        </tr>
                        </tbody>

                        <tr>
                            <th><label for="mp-delivery-fee"><?php esc_html_e( 'Delivery Fee', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-delivery-fee" step="0.01" min="0" class="small-text" value="0"></td>
                        </tr>
                        <tr>
                            <th><label for="mp-minimum-order"><?php esc_html_e( 'Minimum Order', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-minimum-order" step="0.01" min="0" class="small-text" value="0"></td>
                        </tr>
                        <tr>
                            <th><label for="mp-eta"><?php esc_html_e( 'Estimated Delivery (min)', 'menu-popup' ); ?></label></th>
                            <td><input type="number" id="mp-eta" min="1" class="small-text" value="30"></td>
                        </tr>
                        <tr>
                            <th><label for="mp-is-active"><?php esc_html_e( 'Active', 'menu-popup' ); ?></label></th>
                            <td><input type="checkbox" id="mp-is-active" checked></td>
                        </tr>
                    </table>

                    <!-- Map (only shown when Google Maps API key is set) -->
                    <?php if ( get_option( 'menu_popup_google_maps_api_key' ) ) : ?>
                    <div id="mp-zones-map"></div>
                    <p class="description" id="mp-map-hint" style="margin-top:8px;">
                        <?php esc_html_e( 'Use the drawing tools on the map to define zone geometry.', 'menu-popup' ); ?>
                    </p>
                    <?php else : ?>
                    <div class="notice notice-info inline" style="margin:10px 0;">
                        <p>
                            <?php esc_html_e( 'To enable the visual map editor, add your Google Maps API key in', 'menu-popup' ); ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=menu-popup-settings' ) ); ?>">
                                <?php esc_html_e( 'Plugin Settings', 'menu-popup' ); ?>
                            </a>.
                        </p>
                    </div>
                    <?php endif; ?>

                    <p class="submit">
                        <button type="submit" class="button button-primary" id="mp-save-zone-btn">
                            <?php esc_html_e( 'Save Zone', 'menu-popup' ); ?>
                        </button>
                        <button type="button" class="button" id="mp-cancel-edit" style="display:none;">
                            <?php esc_html_e( 'Cancel Edit', 'menu-popup' ); ?>
                        </button>
                        <span class="spinner" style="float:none;vertical-align:middle;"></span>
                    </p>
                    <div id="mp-zone-message" style="display:none; margin-top:10px;"></div>
                </form>
            </div>

            <?php endif; // branches exist ?>
        </div><!-- /#mp-zones-wrap -->
        <?php
    }
}
