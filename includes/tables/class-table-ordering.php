<?php
/**
 * Table ordering (dine-in via QR code).
 *
 * Each restaurant table (the same "restaurant_table" posts the reservations
 * use) gets a printed QR code. The code holds a signed link; scanning it
 * remembers the table in a cookie and opens the menu. Orders made that way
 * are normal orders of type "dine_in" that carry the table.
 *
 * Nothing here runs unless "Order from the table" is switched on in the
 * settings.
 *
 * @package MenuPopup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu_Popup_Table_Ordering {

    const CPT         = 'restaurant_table';
    const COOKIE      = 'mp_table';
    const COOKIE_TTL  = 21600; // 6 hours
    const OPT_ENABLED = 'menu_popup_table_ordering_enabled';
    const OPT_MODE    = 'menu_popup_table_payment_mode';
    const OPT_COOLDOWN = 'menu_popup_table_call_cooldown';
    const OPT_PAGE    = 'menu_popup_table_menu_page';
    const OPT_SECRET  = 'menu_popup_table_secret';

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action( 'template_redirect', array( $this, 'handle_landing' ), 0 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ), 30 );

        add_action( 'wp_ajax_mp_table_context', array( $this, 'ajax_context' ) );
        add_action( 'wp_ajax_nopriv_mp_table_context', array( $this, 'ajax_context' ) );
        add_action( 'wp_ajax_mp_table_call', array( $this, 'ajax_call' ) );
        add_action( 'wp_ajax_nopriv_mp_table_call', array( $this, 'ajax_call' ) );
    }

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------

    public static function enabled() {
        return get_option( self::OPT_ENABLED, '0' ) === '1';
    }

    /**
     * "inside": the waiter settles bills in the plugin (cash / card machine).
     * "outside": the restaurant has its own POS; the plugin only takes orders.
     */
    public static function payment_mode() {
        return get_option( self::OPT_MODE, 'inside' ) === 'outside' ? 'outside' : 'inside';
    }

    public static function cooldown() {
        $v = (int) get_option( self::OPT_COOLDOWN, 120 );
        return max( 30, min( 1800, $v ? $v : 120 ) );
    }

    // ---------------------------------------------------------------------
    // Signed links
    // ---------------------------------------------------------------------

    private static function secret() {
        $s = (string) get_option( self::OPT_SECRET, '' );
        if ( strlen( $s ) < 32 ) {
            $s = wp_generate_password( 64, true, true );
            update_option( self::OPT_SECRET, $s, false );
        }
        return $s;
    }

    public static function sign( $table_id ) {
        $table_id = (int) $table_id;
        $ver      = (int) get_post_meta( $table_id, '_mp_qr_ver', true );
        return substr( hash_hmac( 'sha256', $table_id . '|' . $ver, self::secret() ), 0, 16 );
    }

    public static function verify( $table_id, $sig ) {
        $sig = (string) $sig;
        return $table_id > 0 && strlen( $sig ) === 16 && hash_equals( self::sign( $table_id ), $sig );
    }

    /** The link printed inside a table's QR code. */
    public static function table_url( $table_id ) {
        return add_query_arg(
            array(
                'mp_table' => (int) $table_id,
                's'        => self::sign( $table_id ),
            ),
            home_url( '/' )
        );
    }

    /** Invalidate the printed QR code of one table (a new code must be printed). */
    public static function regenerate( $table_id ) {
        $ver = (int) get_post_meta( $table_id, '_mp_qr_ver', true );
        update_post_meta( $table_id, '_mp_qr_ver', $ver + 1 );
    }

    // ---------------------------------------------------------------------
    // Tables
    // ---------------------------------------------------------------------

    /**
     * @return array|null id, name, branch_id, branch_name
     */
    public static function get_table( $id ) {
        $id = (int) $id;
        if ( $id < 1 || get_post_type( $id ) !== self::CPT || get_post_status( $id ) !== 'publish' ) {
            return null;
        }
        $branch = (int) get_post_meta( $id, '_branch_id', true );
        if ( $branch && ( get_post_type( $branch ) !== 'menu_popup_branch' || get_post_status( $branch ) !== 'publish' ) ) {
            $branch = 0;
        }
        return array(
            'id'          => $id,
            'name'        => get_the_title( $id ),
            'branch_id'   => $branch,
            'branch_name' => $branch ? get_the_title( $branch ) : '',
        );
    }

    /**
     * Table for an order or context request. Checks the feature switch and the signature.
     *
     * @return array|WP_Error
     */
    public static function resolve( $id, $sig ) {
        if ( ! self::enabled() ) {
            return new WP_Error( 'table_disabled', __( 'Ordering from the table is not available.', 'menu-popup' ) );
        }
        $table = self::get_table( $id );
        if ( ! $table || ! self::verify( (int) $id, $sig ) ) {
            return new WP_Error( 'table_invalid', __( 'This table code is not valid. Please scan the code on your table again.', 'menu-popup' ) );
        }
        return $table;
    }

    /** All published tables, ordered by name (natural order: 2 before 10). */
    public static function all_tables() {
        $posts = get_posts( array(
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ) );
        $out = array();
        foreach ( $posts as $p ) {
            $t = self::get_table( $p->ID );
            if ( $t ) {
                $out[] = $t;
            }
        }
        usort( $out, function ( $a, $b ) {
            return strnatcasecmp( $a['name'], $b['name'] );
        } );
        return $out;
    }

    // ---------------------------------------------------------------------
    // Payment choices at the table
    // ---------------------------------------------------------------------

    /**
     * What a customer sitting at a table can pick. Transfers (InstaPay,
     * wallets) are included only when the payment system offers them.
     */
    public static function payment_methods() {
        $list = array();

        if ( self::payment_mode() === 'inside' ) {
            $list[] = array(
                'id'          => 'cod',
                'label'       => __( 'Cash', 'menu-popup' ),
                'label_ar'    => 'كاش',
                'description' => __( 'Pay the waiter at your table', 'menu-popup' ),
                'kind'        => 'cash',
                'icon'        => '💵',
            );
            $list[] = array(
                'id'          => 'card_pos',
                'label'       => __( 'Card', 'menu-popup' ),
                'label_ar'    => 'فيزا',
                'description' => __( 'Pay by card with the restaurant machine', 'menu-popup' ),
                'kind'        => 'cash',
                'icon'        => '💳',
            );
        } else {
            $list[] = array(
                'id'          => 'table',
                'label'       => __( 'Pay at the table', 'menu-popup' ),
                'label_ar'    => 'الدفع على الترابيزة',
                'description' => __( 'Pay when you finish', 'menu-popup' ),
                'kind'        => 'cash',
                'icon'        => '🍽️',
            );
        }

        if ( class_exists( 'Menu_Popup_Payment_Methods' ) ) {
            foreach ( Menu_Popup_Payment_Methods::available() as $m ) {
                if ( $m['kind'] === 'manual' ) {
                    $list[] = $m;
                }
            }
        }

        return $list;
    }

    public static function find_method( $id ) {
        foreach ( self::payment_methods() as $m ) {
            if ( $m['id'] === $id ) {
                return $m;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Landing: the QR link
    // ---------------------------------------------------------------------

    /** Page that shows the menu (the one with the shortcode). */
    public static function menu_url( $branch_id = 0 ) {
        $url     = '';
        $page_id = (int) get_option( self::OPT_PAGE, 0 );

        if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
            $url = get_permalink( $page_id );
        }

        if ( $url === '' ) {
            $found = get_transient( 'mp_table_menu_page' );
            if ( $found === false ) {
                global $wpdb;
                $found = (int) $wpdb->get_var(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_status = 'publish' AND post_type IN ('page','post')
                       AND (post_content LIKE '%[menu-popup]%' OR post_content LIKE '%[menu-popup %')
                     ORDER BY (post_type = 'page') DESC, ID ASC LIMIT 1"
                );
                set_transient( 'mp_table_menu_page', $found, 6 * HOUR_IN_SECONDS );
            }
            if ( $found ) {
                $url = get_permalink( (int) $found );
            }
        }

        if ( ! $url ) {
            $url = home_url( '/' );
        }
        if ( $branch_id > 0 ) {
            $url = add_query_arg( 'branch', (int) $branch_id, $url );
        }
        return $url;
    }

    public function handle_landing() {
        if ( ! isset( $_GET['mp_table'] ) || is_admin() ) {
            return;
        }

        $id    = absint( $_GET['mp_table'] );
        $sig   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $table = self::resolve( $id, $sig );

        if ( is_wp_error( $table ) ) {
            self::set_cookie( '', time() - DAY_IN_SECONDS );
            wp_die(
                esc_html( $table->get_error_message() ),
                esc_html__( 'Table code', 'menu-popup' ),
                array(
                    'response'  => 410,
                    'link_url'  => esc_url( home_url( '/' ) ),
                    'link_text' => esc_html__( 'Open the menu', 'menu-popup' ),
                )
            );
        }

        self::set_cookie( $table['id'] . '.' . $sig, time() + self::COOKIE_TTL );

        nocache_headers();
        wp_safe_redirect( self::menu_url( $table['branch_id'] ) );
        exit;
    }

    private static function set_cookie( $value, $expires ) {
        if ( headers_sent() ) {
            return;
        }
        $path = '/';
        if ( PHP_VERSION_ID >= 70300 ) {
            setcookie( self::COOKIE, $value, array(
                'expires'  => $expires,
                'path'     => $path,
                'secure'   => is_ssl(),
                'httponly' => false, // the menu script reads it
                'samesite' => 'Lax',
            ) );
        } else {
            setcookie( self::COOKIE, $value, $expires, $path . '; samesite=Lax', '', is_ssl(), false );
        }
    }

    // ---------------------------------------------------------------------
    // Customer side: assets, context and calls
    // ---------------------------------------------------------------------

    public function enqueue_frontend() {
        if ( ! self::enabled() ) {
            return;
        }
        wp_enqueue_script(
            'menu-popup-table-ordering',
            MENU_POPUP_PLUGIN_URL . 'assets/js/table-ordering.js',
            array( 'jquery', 'menu-popup-frontend' ),
            MENU_POPUP_VERSION,
            true
        );
        wp_enqueue_style(
            'menu-popup-table-ordering',
            MENU_POPUP_PLUGIN_URL . 'assets/css/table-ordering.css',
            array(),
            MENU_POPUP_VERSION
        );
        wp_localize_script( 'menu-popup-table-ordering', 'mpTableCfg', array(
            'ajax'   => admin_url( 'admin-ajax.php' ),
            'cookie' => self::COOKIE,
            'i18n'   => array(
                'table'      => __( 'Table', 'menu-popup' ),
                'callWaiter' => __( 'Call waiter', 'menu-popup' ),
                'askBill'    => __( 'Ask for the bill', 'menu-popup' ),
                'waiterSent' => __( 'The waiter has been notified.', 'menu-popup' ),
                'billSent'   => __( 'The bill has been requested.', 'menu-popup' ),
                'waitMore'   => __( 'Already sent. Please wait a moment.', 'menu-popup' ),
                'loading'    => __( 'Please wait a moment, your table is loading.', 'menu-popup' ),
                'error'      => __( 'Connection problem. Please try again.', 'menu-popup' ),
            ),
        ) );
    }

    /** Read "ID.SIG" and check it. */
    private function table_from_ref( $ref ) {
        $ref = (string) $ref;
        if ( ! preg_match( '/^(\d{1,9})\.([a-f0-9]{16})$/', $ref, $m ) ) {
            return new WP_Error( 'table_invalid', __( 'This table code is not valid. Please scan the code on your table again.', 'menu-popup' ) );
        }
        return self::resolve( (int) $m[1], $m[2] );
    }

    public function ajax_context() {
        $ref   = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';
        $table = $this->table_from_ref( $ref );
        if ( is_wp_error( $table ) ) {
            wp_send_json_error( array( 'code' => $table->get_error_code(), 'message' => $table->get_error_message() ) );
        }

        wp_send_json_success( array(
            'id'        => $table['id'],
            'sig'       => substr( $ref, strpos( $ref, '.' ) + 1 ),
            'name'      => $table['name'],
            'branch_id' => $table['branch_id'],
            'mode'      => self::payment_mode(),
            'methods'   => self::payment_methods(),
            'cooldown'  => self::cooldown(),
        ) );
    }

    /** Customer presses "Call waiter" or "Ask for the bill". */
    public function ajax_call() {
        $ref   = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';
        $type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
        $table = $this->table_from_ref( $ref );
        if ( is_wp_error( $table ) ) {
            wp_send_json_error( array( 'code' => $table->get_error_code(), 'message' => $table->get_error_message() ) );
        }
        if ( ! in_array( $type, array( 'waiter', 'bill' ), true ) ) {
            wp_send_json_error( array( 'code' => 'bad_type', 'message' => __( 'Unknown request.', 'menu-popup' ) ) );
        }

        $key  = '_mp_call_' . $type;
        $last = (int) get_post_meta( $table['id'], $key, true );
        if ( $last > 0 && ( time() - $last ) < self::cooldown() ) {
            wp_send_json_error( array( 'code' => 'wait', 'message' => __( 'Already sent. Please wait a moment.', 'menu-popup' ) ) );
        }

        update_post_meta( $table['id'], $key, time() );
        wp_send_json_success( array( 'type' => $type ) );
    }

    // ---------------------------------------------------------------------
    // Staff side: what is open right now
    // ---------------------------------------------------------------------

    /** Orders with an id up to this one belong to bills that were already closed. */
    public static function session_after( $table_id ) {
        return (int) get_post_meta( $table_id, '_mp_session_after', true );
    }

    private static function last_order_id() {
        $ids = get_posts( array(
            'post_type'      => 'menu_order',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );
        return $ids ? (int) $ids[0] : 0;
    }

    /**
     * Orders of every table since it was last closed, grouped by table id.
     *
     * @return array table_id => int[] order ids
     */
    private static function open_order_ids() {
        $ids = get_posts( array(
            'post_type'      => 'menu_order',
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => '_order_type', 'value' => 'dine_in' ),
                array( 'key' => '_created_ts', 'value' => time() - 3 * DAY_IN_SECONDS, 'compare' => '>=', 'type' => 'NUMERIC' ),
            ),
        ) );

        $by_table = array();
        foreach ( array_reverse( $ids ) as $oid ) {
            $status = (string) get_post_meta( $oid, '_order_status', true );
            if ( in_array( $status, array( 'cancelled', 'expired' ), true ) ) {
                continue;
            }
            $tid = (int) get_post_meta( $oid, '_table_id', true );
            if ( $tid < 1 ) {
                continue;
            }
            if ( (int) $oid <= self::session_after( $tid ) ) {
                continue;
            }
            $by_table[ $tid ][] = (int) $oid;
        }
        return $by_table;
    }

    private static function order_row( $oid ) {
        $m      = function ( $k ) use ( $oid ) {
            return get_post_meta( $oid, $k, true );
        };
        $items  = json_decode( (string) $m( '_order_items' ), true );
        $items  = is_array( $items ) ? $items : array();
        $method = (string) $m( '_payment_method' );
        $paid   = (string) $m( '_payment_status' ) === 'paid';
        $status = (string) $m( '_order_status' );

        $lines = array();
        foreach ( $items as $l ) {
            $lines[] = ( (int) ( $l['quantity'] ?? 1 ) ) . '× ' . ( $l['name'] ?? '' );
        }

        return array(
            'id'        => (int) $oid,
            'number'    => (string) ( $m( '_order_number' ) ?: $oid ),
            'time'      => (int) $m( '_created_ts' ),
            'status'    => $status !== '' ? $status : 'pending',
            'total'     => (float) $m( '_order_total' ),
            'items'     => implode( ', ', $lines ),
            'customer'  => (string) $m( '_customer_name' ),
            'notes'     => (string) $m( '_special_instructions' ),
            'method'    => $method,
            'method_label' => Menu_Popup_Payment_Methods::label( $method ),
            'paid'      => $paid,
            'paid_via'  => (string) $m( '_payment_paid_via' ),
            // Only cash-type orders are settled by the waiter; transfers are checked on the orders page
            'settleable' => ! $paid
                && self::payment_mode() === 'inside'
                && ! Menu_Popup_Payment_Methods::is_transfer( $method )
                && ! in_array( $status, array( 'awaiting_payment', 'awaiting_verification' ), true ),
        );
    }

    /**
     * Everything the "Open tables" screen shows.
     *
     * @return array
     */
    public static function open_tables() {
        $grouped = self::open_order_ids();
        $cur     = (string) get_option( 'menu_popup_currency_symbol', '$' );
        $cd      = self::cooldown();
        $rows    = array();

        foreach ( self::all_tables() as $t ) {
            $orders = array();
            $due    = 0.0;
            $paid   = 0.0;
            foreach ( isset( $grouped[ $t['id'] ] ) ? $grouped[ $t['id'] ] : array() as $oid ) {
                $r        = self::order_row( $oid );
                $orders[] = $r;
                if ( $r['paid'] ) {
                    $paid += $r['total'];
                } else {
                    $due += $r['total'];
                }
            }

            $waiter = (int) get_post_meta( $t['id'], '_mp_call_waiter', true );
            $bill   = (int) get_post_meta( $t['id'], '_mp_call_bill', true );

            $rows[] = array(
                'id'      => $t['id'],
                'name'    => $t['name'],
                'branch'  => $t['branch_name'],
                'orders'  => $orders,
                'due'     => round( $due, 2 ),
                'paid'    => round( $paid, 2 ),
                'waiter'  => $waiter,
                'bill'    => $bill,
                'cooldown' => $cd,
            );
        }

        return array(
            'currency' => $cur,
            'mode'     => self::payment_mode(),
            'tables'   => $rows,
        );
    }

    /**
     * Mark some orders of a table as paid (waiter took cash / used the card machine).
     *
     * @param int   $table_id
     * @param int[] $order_ids
     * @param string $via 'cash' | 'card_pos'
     * @return int|WP_Error number of orders settled
     */
    public static function settle( $table_id, array $order_ids, $via ) {
        if ( self::payment_mode() !== 'inside' ) {
            return new WP_Error( 'outside', __( 'Payments are handled outside the plugin.', 'menu-popup' ) );
        }
        $via   = $via === 'card_pos' ? 'card_pos' : 'cash';
        $grouped = self::open_order_ids();
        $open  = isset( $grouped[ $table_id ] ) ? $grouped[ $table_id ] : array();
        $n     = 0;

        foreach ( array_unique( array_map( 'absint', $order_ids ) ) as $oid ) {
            if ( ! in_array( $oid, $open, true ) ) {
                continue;
            }
            $row = self::order_row( $oid );
            if ( ! $row['settleable'] ) {
                continue;
            }
            update_post_meta( $oid, '_payment_status', 'paid' );
            update_post_meta( $oid, '_payment_paid_via', $via );
            update_post_meta( $oid, '_payment_confirmed_at', time() );
            if ( $row['method'] === '' || in_array( $row['method'], array( 'cod', 'card_pos', 'table' ), true ) ) {
                update_post_meta( $oid, '_payment_method', $via === 'card_pos' ? 'card_pos' : 'cod' );
            }
            if ( class_exists( 'Menu_Popup_Order_Tracking' ) ) {
                $user = wp_get_current_user();
                Menu_Popup_Order_Tracking::add_history(
                    $oid,
                    'paid',
                    ( $via === 'card_pos' ? __( 'Card (machine)', 'menu-popup' ) : __( 'Cash', 'menu-popup' ) )
                        . ' · ' . sprintf( __( 'Table %s', 'menu-popup' ), (string) get_post_meta( $oid, '_table_name', true ) )
                );
            }
            $n++;
        }

        return $n;
    }

    /**
     * Close a table: its next order starts a fresh bill.
     *
     * @return true|WP_Error
     */
    public static function close_table( $table_id, $force = false ) {
        if ( ! self::get_table( $table_id ) ) {
            return new WP_Error( 'no_table', __( 'Table not found.', 'menu-popup' ) );
        }

        if ( ! $force && self::payment_mode() === 'inside' ) {
            $grouped = self::open_order_ids();
            foreach ( isset( $grouped[ $table_id ] ) ? $grouped[ $table_id ] : array() as $oid ) {
                $row = self::order_row( $oid );
                if ( ! $row['paid'] ) {
                    return new WP_Error( 'unpaid', __( 'This table still has unpaid orders.', 'menu-popup' ) );
                }
            }
        }

        update_post_meta( $table_id, '_mp_session_after', self::last_order_id() );
        update_post_meta( $table_id, '_mp_call_waiter', 0 );
        update_post_meta( $table_id, '_mp_call_bill', 0 );
        return true;
    }

    public static function clear_call( $table_id, $type ) {
        if ( ! self::get_table( $table_id ) ) {
            return false;
        }
        if ( $type === 'all' || $type === 'waiter' ) {
            update_post_meta( $table_id, '_mp_call_waiter', 0 );
        }
        if ( $type === 'all' || $type === 'bill' ) {
            update_post_meta( $table_id, '_mp_call_bill', 0 );
        }
        return true;
    }
}
