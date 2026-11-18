<?php
/**
 * Admin page "Tables": the live view of open tables (bills, waiter calls)
 * and the QR codes to print for each table.
 *
 * Shown in the menu only when "Order from the table" is switched on.
 *
 * @package MenuPopup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu_Popup_Tables_Admin {

    const NONCE = 'mp_tables_admin';
    const SLUG  = 'menu-popup-tables';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 25 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

        add_action( 'wp_ajax_mp_tables_data', array( $this, 'ajax_data' ) );
        add_action( 'wp_ajax_mp_tables_settle', array( $this, 'ajax_settle' ) );
        add_action( 'wp_ajax_mp_tables_close', array( $this, 'ajax_close' ) );
        add_action( 'wp_ajax_mp_tables_clear_call', array( $this, 'ajax_clear_call' ) );
        add_action( 'wp_ajax_mp_tables_regen', array( $this, 'ajax_regen' ) );
    }

    public function add_menu() {
        if ( ! Menu_Popup_Table_Ordering::enabled() ) {
            return;
        }
        add_submenu_page(
            'menu-popup-dashboard',
            __( 'Tables', 'menu-popup' ),
            __( 'Tables & QR', 'menu-popup' ),
            'manage_options',
            self::SLUG,
            array( $this, 'render_page' )
        );
    }

    // ---------------------------------------------------------------------
    // Assets
    // ---------------------------------------------------------------------

    private function logo_url() {
        $id = (int) get_theme_mod( 'custom_logo' );
        if ( $id ) {
            $url = wp_get_attachment_image_url( $id, 'medium' );
            if ( $url ) {
                return $url;
            }
        }
        $icon = get_site_icon_url( 256 );
        return $icon ? $icon : '';
    }

    public function enqueue( $hook ) {
        if ( strpos( (string) $hook, self::SLUG ) === false ) {
            return;
        }

        wp_enqueue_style( 'mp-tables-admin', MENU_POPUP_PLUGIN_URL . 'assets/css/admin-tables.css', array(), MENU_POPUP_VERSION );
        wp_enqueue_script( 'mp-qrcode', MENU_POPUP_PLUGIN_URL . 'assets/js/qrcode.js', array(), '2.0.4', true );
        wp_enqueue_script( 'mp-tables-admin', MENU_POPUP_PLUGIN_URL . 'assets/js/admin-tables.js', array( 'jquery', 'mp-qrcode' ), MENU_POPUP_VERSION, true );

        $qr = array();
        foreach ( Menu_Popup_Table_Ordering::all_tables() as $t ) {
            $qr[] = array(
                'id'     => $t['id'],
                'name'   => $t['name'],
                'branch' => $t['branch_name'],
                'url'    => Menu_Popup_Table_Ordering::table_url( $t['id'] ),
                'edit'   => get_edit_post_link( $t['id'], 'raw' ),
            );
        }

        wp_localize_script( 'mp-tables-admin', 'mpTables', array(
            'ajax'         => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( self::NONCE ),
            'refresh'      => 10,
            'mode'         => Menu_Popup_Table_Ordering::payment_mode(),
            'tables'       => $qr,
            'logo'         => $this->logo_url(),
            'siteName'     => get_bloginfo( 'name' ),
            'addUrl'       => admin_url( 'post-new.php?post_type=' . Menu_Popup_Table_Ordering::CPT ),
            'statusLabels' => Menu_Popup_Order_Tracking::order_status_labels(),
            'i18n'         => array(
                'openTab'      => __( 'Open tables', 'menu-popup' ),
                'qrTab'        => __( 'Tables & QR codes', 'menu-popup' ),
                'free'         => __( 'Free tables', 'menu-popup' ),
                'noTables'     => __( 'No tables yet. Add your tables first, then print their QR codes.', 'menu-popup' ),
                'noOrders'     => __( 'No orders yet', 'menu-popup' ),
                'waiterCall'   => __( 'Waiter called', 'menu-popup' ),
                'billCall'     => __( 'Bill requested', 'menu-popup' ),
                'done'         => __( 'Done', 'menu-popup' ),
                'ago'          => __( 'min ago', 'menu-popup' ),
                'now'          => __( 'just now', 'menu-popup' ),
                'due'          => __( 'To pay', 'menu-popup' ),
                'paidTotal'    => __( 'Paid', 'menu-popup' ),
                'paid'         => __( 'Paid', 'menu-popup' ),
                'unpaid'       => __( 'Not paid', 'menu-popup' ),
                'paidCash'     => __( 'Paid in cash', 'menu-popup' ),
                'paidCard'     => __( 'Paid by card (machine)', 'menu-popup' ),
                'pickOrders'   => __( 'Tick the orders you are settling.', 'menu-popup' ),
                'closeTable'   => __( 'Close table', 'menu-popup' ),
                'closeAsk'     => __( 'Close this table? Its next order will start a new bill.', 'menu-popup' ),
                'closeUnpaid'  => __( 'This table still has unpaid orders. Close it anyway?', 'menu-popup' ),
                'viewOrders'   => __( 'Open orders page', 'menu-popup' ),
                'outsideNote'  => __( 'Payment is handled on your own POS. Close the table when the customers leave.', 'menu-popup' ),
                'soundOn'      => __( 'Sound on', 'menu-popup' ),
                'soundOff'     => __( 'Sound off', 'menu-popup' ),
                'print'        => __( 'Print', 'menu-popup' ),
                'printAll'     => __( 'Print all', 'menu-popup' ),
                'png'          => __( 'Download PNG', 'menu-popup' ),
                'copyLink'     => __( 'Copy link', 'menu-popup' ),
                'copied'       => __( 'Copied', 'menu-popup' ),
                'edit'         => __( 'Edit table', 'menu-popup' ),
                'regen'        => __( 'New code', 'menu-popup' ),
                'regenAsk'     => __( 'Make a new code for this table? The printed code stops working and you must print the new one.', 'menu-popup' ),
                'addTable'     => __( 'Add a table', 'menu-popup' ),
                'popup'        => __( 'Allow pop-ups for this page, then press Print again.', 'menu-popup' ),
                'error'        => __( 'Something went wrong. Please try again.', 'menu-popup' ),
                'items'        => __( 'Items', 'menu-popup' ),
            ),
        ) );
    }

    // ---------------------------------------------------------------------
    // Page
    // ---------------------------------------------------------------------

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap mp-tables" id="mp-tables-app">
            <h1><?php esc_html_e( 'Tables', 'menu-popup' ); ?></h1>

            <h2 class="nav-tab-wrapper mp-tables-tabs">
                <a href="#open" class="nav-tab nav-tab-active" data-tab="open"><?php esc_html_e( 'Open tables', 'menu-popup' ); ?></a>
                <a href="#qr" class="nav-tab" data-tab="qr"><?php esc_html_e( 'Tables & QR codes', 'menu-popup' ); ?></a>
            </h2>

            <div class="mp-tables-panel" data-panel="open">
                <div class="mp-tables-toolbar">
                    <button type="button" class="button" id="mp-tables-sound"></button>
                    <span class="mp-tables-updated" id="mp-tables-updated"></span>
                </div>
                <div id="mp-open-grid" class="mp-open-grid"></div>
                <div id="mp-free-list" class="mp-free-list"></div>
            </div>

            <div class="mp-tables-panel" data-panel="qr" style="display:none">
                <div class="mp-tables-toolbar">
                    <button type="button" class="button button-primary" id="mp-print-all"></button>
                    <a class="button" id="mp-add-table" href="#"></a>
                </div>
                <div id="mp-qr-grid" class="mp-qr-grid"></div>
            </div>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------
    // AJAX
    // ---------------------------------------------------------------------

    private function check() {
        if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload the page.', 'menu-popup' ) ), 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Not allowed.', 'menu-popup' ) ), 403 );
        }
    }

    public function ajax_data() {
        $this->check();
        $data         = Menu_Popup_Table_Ordering::open_tables();
        $data['time'] = time();
        wp_send_json_success( $data );
    }

    public function ajax_settle() {
        $this->check();
        $table_id = absint( $_POST['table_id'] ?? 0 );
        $via      = sanitize_key( $_POST['via'] ?? 'cash' );
        $ids      = isset( $_POST['order_ids'] ) && is_array( $_POST['order_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['order_ids'] ) ) : array();

        if ( ! $table_id || empty( $ids ) ) {
            wp_send_json_error( array( 'message' => __( 'Tick the orders you are settling.', 'menu-popup' ) ) );
        }
        $n = Menu_Popup_Table_Ordering::settle( $table_id, $ids, $via );
        if ( is_wp_error( $n ) ) {
            wp_send_json_error( array( 'message' => $n->get_error_message() ) );
        }
        wp_send_json_success( array( 'settled' => $n ) );
    }

    public function ajax_close() {
        $this->check();
        $table_id = absint( $_POST['table_id'] ?? 0 );
        $force    = ! empty( $_POST['force'] );
        $r        = Menu_Popup_Table_Ordering::close_table( $table_id, $force );
        if ( is_wp_error( $r ) ) {
            wp_send_json_error( array( 'code' => $r->get_error_code(), 'message' => $r->get_error_message() ) );
        }
        wp_send_json_success();
    }

    public function ajax_clear_call() {
        $this->check();
        $table_id = absint( $_POST['table_id'] ?? 0 );
        $type     = sanitize_key( $_POST['type'] ?? 'all' );
        if ( ! in_array( $type, array( 'all', 'waiter', 'bill' ), true ) ) {
            $type = 'all';
        }
        if ( ! Menu_Popup_Table_Ordering::clear_call( $table_id, $type ) ) {
            wp_send_json_error( array( 'message' => __( 'Table not found.', 'menu-popup' ) ) );
        }
        wp_send_json_success();
    }

    public function ajax_regen() {
        $this->check();
        $table_id = absint( $_POST['table_id'] ?? 0 );
        if ( ! Menu_Popup_Table_Ordering::get_table( $table_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Table not found.', 'menu-popup' ) ) );
        }
        Menu_Popup_Table_Ordering::regenerate( $table_id );
        wp_send_json_success( array( 'url' => Menu_Popup_Table_Ordering::table_url( $table_id ) ) );
    }
}
