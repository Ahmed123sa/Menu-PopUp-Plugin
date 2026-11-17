<?php
/**
 * Order Dashboard (admin): live board, payments to check, history.
 *
 * Replaces the old single-table page. Everything it shows comes from its own
 * AJAX endpoints below; the older endpoints (export, accounting, real-time)
 * are left as they were.
 *
 * @package MenuPopup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu_Popup_Orders_Board {

    const NONCE = 'mp_orders_board';

    const LIVE_STATUSES    = array( 'pending', 'confirmed', 'preparing', 'ready' );
    const PAYMENT_STATUSES = array( 'awaiting_verification', 'awaiting_payment' );
    const DONE_STATUSES    = array( 'delivered', 'cancelled', 'expired' );

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

        add_action( 'wp_ajax_mp_board_list', array( $this, 'ajax_list' ) );
        add_action( 'wp_ajax_mp_board_order', array( $this, 'ajax_order' ) );
        add_action( 'wp_ajax_mp_board_set_status', array( $this, 'ajax_set_status' ) );
        add_action( 'wp_ajax_mp_board_payment', array( $this, 'ajax_payment' ) );
        add_action( 'wp_ajax_mp_board_proof', array( $this, 'ajax_proof' ) );
        add_action( 'wp_ajax_mp_board_delete', array( $this, 'ajax_delete' ) );
    }

    // ---------------------------------------------------------------------
    // Assets and page
    // ---------------------------------------------------------------------

    public function enqueue( $hook ) {
        if ( strpos( (string) $hook, 'menu-popup-orders' ) === false ) {
            return;
        }

        wp_enqueue_style( 'mp-orders-board', MENU_POPUP_PLUGIN_URL . 'assets/css/admin-orders-board.css', array(), MENU_POPUP_VERSION );
        wp_enqueue_script( 'mp-orders-board', MENU_POPUP_PLUGIN_URL . 'assets/js/admin-orders-board.js', array( 'jquery' ), MENU_POPUP_VERSION, true );

        $interval = (int) get_option( 'menu_popup_order_dashboard_refresh_interval', 30 );

        wp_localize_script( 'mp-orders-board', 'mpBoard', array(
            'ajax'          => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( self::NONCE ),
            'exportNonce'   => wp_create_nonce( 'get_orders_nonce' ),
            'refresh'       => max( 10, min( 300, $interval ? $interval : 30 ) ),
            'currency'      => (string) get_option( 'menu_popup_currency_symbol', '$' ),
            'statusLabels'  => Menu_Popup_Order_Tracking::order_status_labels(),
            'paymentLabels' => Menu_Popup_Order_Tracking::payment_status_labels(),
            'i18n'          => array(
                'new'          => __( 'New', 'menu-popup' ),
                'confirmed'    => __( 'Confirmed', 'menu-popup' ),
                'preparing'    => __( 'Preparing', 'menu-popup' ),
                'ready'        => __( 'Ready', 'menu-popup' ),
                'empty'        => __( 'Nothing here', 'menu-popup' ),
                'next'         => array(
                    'pending'   => __( 'Confirm', 'menu-popup' ),
                    'confirmed' => __( 'Start preparing', 'menu-popup' ),
                    'preparing' => __( 'Mark ready', 'menu-popup' ),
                    'ready'     => __( 'Complete', 'menu-popup' ),
                ),
                'view'          => __( 'Details', 'menu-popup' ),
                'cancel'        => __( 'Cancel order', 'menu-popup' ),
                'confirmCancel' => __( 'Cancel this order?', 'menu-popup' ),
                'confirmDelete' => __( 'Delete this order for good? This cannot be undone.', 'menu-popup' ),
                'delete'        => __( 'Delete', 'menu-popup' ),
                'checkPayment'  => __( 'Receipts to check', 'menu-popup' ),
                'waitingCust'   => __( 'Waiting for the customer to pay', 'menu-popup' ),
                'confirmPay'    => __( 'Payment received', 'menu-popup' ),
                'rejectPay'     => __( 'Reject receipt', 'menu-popup' ),
                'rejectReason'  => __( 'Why is the receipt rejected? The customer will see this.', 'menu-popup' ),
                'defaultReason' => __( 'We could not find this transfer. Please check the amount and the transaction number.', 'menu-popup' ),
                'markPaidCash'  => __( 'Paid in cash', 'menu-popup' ),
                'markPaidCard'  => __( 'Paid by card (machine)', 'menu-popup' ),
                'duplicate'     => __( 'This transaction number was already used on order(s):', 'menu-popup' ),
                'reference'     => __( 'Transaction number', 'menu-popup' ),
                'sender'        => __( 'Sent from', 'menu-popup' ),
                'receipt'       => __( 'Receipt', 'menu-popup' ),
                'noReceipt'     => __( 'No screenshot sent', 'menu-popup' ),
                'openPdf'       => __( 'Open PDF receipt', 'menu-popup' ),
                'timeLeft'      => __( 'left', 'menu-popup' ),
                'items'         => __( 'Items', 'menu-popup' ),
                'customer'      => __( 'Customer', 'menu-popup' ),
                'delivery'      => __( 'Delivery', 'menu-popup' ),
                'pickup'        => __( 'Pickup', 'menu-popup' ),
                'payment'       => __( 'Payment', 'menu-popup' ),
                'history'       => __( 'History', 'menu-popup' ),
                'notes'         => __( 'Notes', 'menu-popup' ),
                'subtotal'      => __( 'Subtotal', 'menu-popup' ),
                'tax'           => __( 'Tax', 'menu-popup' ),
                'deliveryFee'   => __( 'Delivery fee', 'menu-popup' ),
                'total'         => __( 'Total', 'menu-popup' ),
                'map'           => __( 'Open in Google Maps', 'menu-popup' ),
                'whatsapp'      => __( 'WhatsApp the customer', 'menu-popup' ),
                'statusPage'    => __( 'Customer status page', 'menu-popup' ),
                'changeStatus'  => __( 'Change status', 'menu-popup' ),
                'loadMore'      => __( 'Load more', 'menu-popup' ),
                'error'         => __( 'Something went wrong. Please try again.', 'menu-popup' ),
                'soundOn'       => __( 'Sound on', 'menu-popup' ),
                'soundOff'      => __( 'Sound off', 'menu-popup' ),
                'waConfirm'     => __( 'Hello {name}, your payment for order #{number} is confirmed and your order is being prepared. Follow it here: {url}', 'menu-popup' ),
                'waReject'      => __( 'Hello {name}, we could not confirm the payment for order #{number}: {reason} Please send the receipt again here: {url}', 'menu-popup' ),
                'waGeneric'     => __( 'Hello {name}, about your order #{number}: {url}', 'menu-popup' ),
            ),
        ) );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $branches = get_posts( array(
            'post_type'      => 'menu_popup_branch',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        ?>
        <div class="wrap mp-board" id="mp-board">
            <div class="mp-board-top">
                <h1><?php esc_html_e( 'Order Dashboard', 'menu-popup' ); ?></h1>
                <div class="mp-board-stats" id="mp-board-stats">
                    <div><strong data-stat="today_orders">–</strong><span><?php esc_html_e( 'Orders today', 'menu-popup' ); ?></span></div>
                    <div><strong data-stat="today_sales">–</strong><span><?php esc_html_e( 'Sales today', 'menu-popup' ); ?></span></div>
                    <div><strong data-stat="live">–</strong><span><?php esc_html_e( 'In progress', 'menu-popup' ); ?></span></div>
                    <div class="mp-stat-warn"><strong data-stat="to_check">–</strong><span><?php esc_html_e( 'Receipts to check', 'menu-popup' ); ?></span></div>
                </div>
            </div>

            <nav class="nav-tab-wrapper mp-board-tabs">
                <a href="#live" class="nav-tab" data-view="live"><?php esc_html_e( 'Live orders', 'menu-popup' ); ?> <span class="mp-count" data-count="live"></span></a>
                <a href="#payments" class="nav-tab" data-view="payments"><?php esc_html_e( 'Payments', 'menu-popup' ); ?> <span class="mp-count mp-count-warn" data-count="to_check"></span></a>
                <a href="#history" class="nav-tab" data-view="history"><?php esc_html_e( 'History', 'menu-popup' ); ?></a>
            </nav>

            <div class="mp-board-filters">
                <input type="search" id="mp-f-search" placeholder="<?php esc_attr_e( 'Order number, name or phone', 'menu-popup' ); ?>">
                <?php if ( count( $branches ) > 1 ) : ?>
                <select id="mp-f-branch">
                    <option value="0"><?php esc_html_e( 'All branches', 'menu-popup' ); ?></option>
                    <?php foreach ( $branches as $b ) : ?>
                        <option value="<?php echo esc_attr( $b->ID ); ?>"><?php echo esc_html( $b->post_title ); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <select id="mp-f-type">
                    <option value=""><?php esc_html_e( 'Delivery and pickup', 'menu-popup' ); ?></option>
                    <option value="delivery"><?php esc_html_e( 'Delivery only', 'menu-popup' ); ?></option>
                    <option value="pickup"><?php esc_html_e( 'Pickup only', 'menu-popup' ); ?></option>
                </select>
                <span class="mp-history-only">
                    <input type="date" id="mp-f-from" aria-label="<?php esc_attr_e( 'From', 'menu-popup' ); ?>">
                    <input type="date" id="mp-f-to" aria-label="<?php esc_attr_e( 'To', 'menu-popup' ); ?>">
                    <select id="mp-f-status">
                        <option value=""><?php esc_html_e( 'All results', 'menu-popup' ); ?></option>
                        <option value="delivered"><?php esc_html_e( 'Completed', 'menu-popup' ); ?></option>
                        <option value="cancelled"><?php esc_html_e( 'Cancelled', 'menu-popup' ); ?></option>
                        <option value="expired"><?php esc_html_e( 'Expired', 'menu-popup' ); ?></option>
                    </select>
                    <button type="button" class="button" id="mp-export"><?php esc_html_e( 'Export CSV', 'menu-popup' ); ?></button>
                </span>
                <span class="mp-board-right">
                    <button type="button" class="button" id="mp-sound"></button>
                    <button type="button" class="button" id="mp-refresh" title="<?php esc_attr_e( 'Refresh', 'menu-popup' ); ?>">↻</button>
                </span>
            </div>

            <div class="mp-view" data-view="live">
                <div class="mp-columns" id="mp-live"></div>
            </div>

            <div class="mp-view" data-view="payments">
                <h2><?php esc_html_e( 'Receipts to check', 'menu-popup' ); ?></h2>
                <div class="mp-cards" id="mp-to-check"></div>
                <h2><?php esc_html_e( 'Waiting for the customer to pay', 'menu-popup' ); ?></h2>
                <div class="mp-cards" id="mp-waiting"></div>
            </div>

            <div class="mp-view" data-view="history">
                <table class="widefat striped mp-history">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Order', 'menu-popup' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'menu-popup' ); ?></th>
                            <th><?php esc_html_e( 'Customer', 'menu-popup' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'menu-popup' ); ?></th>
                            <th><?php esc_html_e( 'Total', 'menu-popup' ); ?></th>
                            <th><?php esc_html_e( 'Payment', 'menu-popup' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'menu-popup' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="mp-history-body"></tbody>
                </table>
                <p><button type="button" class="button" id="mp-more" style="display:none"></button></p>
            </div>

            <div class="mp-drawer" id="mp-drawer" aria-hidden="true">
                <div class="mp-drawer-bg"></div>
                <div class="mp-drawer-panel" role="dialog" aria-modal="true">
                    <button type="button" class="mp-drawer-close" aria-label="<?php esc_attr_e( 'Close', 'menu-popup' ); ?>">&times;</button>
                    <div id="mp-drawer-body"></div>
                </div>
            </div>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------
    // Data
    // ---------------------------------------------------------------------

    private function check() {
        if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload the page.', 'menu-popup' ) ), 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Not allowed.', 'menu-popup' ) ), 403 );
        }
    }

    /**
     * Normalise a phone number for wa.me (Egyptian local numbers get 20).
     */
    public static function wa_phone( $phone ) {
        $d = preg_replace( '/\D+/', '', (string) $phone );
        if ( $d === '' ) {
            return '';
        }
        if ( strpos( $d, '00' ) === 0 ) {
            $d = substr( $d, 2 );
        }
        if ( strlen( $d ) === 11 && strpos( $d, '01' ) === 0 ) {
            $d = '20' . substr( $d, 1 );
        }
        return $d;
    }

    private function row( $id, $full = false ) {
        $m      = function ( $k ) use ( $id ) {
            return get_post_meta( $id, $k, true );
        };
        $items  = json_decode( (string) $m( '_order_items' ), true );
        $items  = is_array( $items ) ? $items : array();
        $status = (string) $m( '_order_status' );
        $status = $status !== '' ? $status : 'pending';
        $branch = (int) $m( '_branch_id' );
        $type   = (string) $m( '_order_type' );
        if ( $type === '' ) {
            $type = $m( '_customer_address' ) ? 'delivery' : 'pickup';
        }
        $method   = (string) $m( '_payment_method' );
        $deadline = (int) $m( '_payment_deadline' );
        $count    = 0;
        foreach ( $items as $l ) {
            $count += (int) ( $l['quantity'] ?? 1 );
        }

        $row = array(
            'id'             => (int) $id,
            'number'         => (string) ( $m( '_order_number' ) ?: $id ),
            'time'           => (int) get_post_time( 'U', true, $id ),
            'date'           => get_the_date( 'Y-m-d H:i', $id ),
            'status'         => $status,
            'type'           => $type,
            'source'         => (string) $m( '_order_source' ),
            'branch'         => $branch ? get_the_title( $branch ) : '',
            'customer'       => (string) $m( '_customer_name' ),
            'phone'          => (string) $m( '_customer_phone' ),
            'total'          => (float) $m( '_order_total' ),
            'currency'       => (string) ( $m( '_currency_symbol' ) ?: get_option( 'menu_popup_currency_symbol', '$' ) ),
            'item_count'     => $count,
            'items_short'    => implode( ', ', array_slice( array_map( function ( $l ) {
                return ( (int) ( $l['quantity'] ?? 1 ) ) . '× ' . ( $l['name'] ?? '' );
            }, $items ), 0, 4 ) ) . ( count( $items ) > 4 ? '…' : '' ),
            'payment_method' => $method,
            'payment_label'  => Menu_Popup_Payment_Methods::label( $method ),
            'payment_status' => (string) $m( '_payment_status' ),
            'is_transfer'    => Menu_Popup_Payment_Methods::is_transfer( $method ),
            'seconds_left'   => ( $status === 'awaiting_payment' && $deadline ) ? max( 0, $deadline - time() ) : 0,
            'reference'      => (string) $m( '_payment_ref' ),
            'duplicates'     => array_values( (array) ( $m( '_payment_ref_duplicates' ) ?: array() ) ),
            'needs_review'   => $m( '_needs_review' ) === '1',
            'zone'           => (string) $m( '_delivery_zone_name' ),
        );

        if ( ! $full ) {
            return $row;
        }

        $lat  = (string) $m( '_delivery_lat' );
        $lng  = (string) $m( '_delivery_lng' );
        $proof_rel = (string) $m( '_payment_proof_file' );

        $row += array(
            'items'         => array_map( function ( $l ) {
                return array(
                    'name'       => (string) ( $l['name'] ?? '' ),
                    'size'       => (string) ( $l['size'] ?? '' ),
                    'quantity'   => (int) ( $l['quantity'] ?? 1 ),
                    'line_total' => (float) ( $l['line_total'] ?? 0 ),
                );
            }, $items ),
            'email'         => (string) $m( '_customer_email' ),
            'address'       => (string) $m( '_customer_address' ),
            'details'       => (string) $m( '_delivery_address_details' ),
            'notes'         => (string) $m( '_special_instructions' ),
            'subtotal'      => (float) $m( '_order_subtotal' ),
            'tax'           => (float) $m( '_order_tax' ),
            'delivery_fee'  => (float) $m( '_delivery_fee' ),
            'distance'      => (string) $m( '_delivery_distance_km' ),
            'eta'           => (int) $m( '_delivery_eta_minutes' ),
            'map_url'       => ( $lat !== '' && $lng !== '' ) ? 'https://www.google.com/maps?q=' . rawurlencode( $lat . ',' . $lng ) : '',
            'sender'        => (string) $m( '_payment_sender' ),
            'reject_reason' => (string) $m( '_payment_reject_reason' ),
            'paid_via'      => (string) $m( '_payment_paid_via' ),
            'proof_url'     => $proof_rel !== '' ? add_query_arg( array(
                'action'   => 'mp_board_proof',
                'order_id' => (int) $id,
                'nonce'    => wp_create_nonce( self::NONCE ),
            ), admin_url( 'admin-ajax.php' ) ) : '',
            'proof_is_pdf'  => substr( $proof_rel, -4 ) === '.pdf',
            'status_url'    => Menu_Popup_Order_Tracking::status_url( $id ),
            'wa_phone'      => self::wa_phone( $m( '_customer_phone' ) ),
            'history'       => array_map( function ( $h ) {
                return array(
                    'status' => (string) ( $h['status'] ?? '' ),
                    'date'   => isset( $h['time'] ) ? wp_date( 'Y-m-d H:i', (int) $h['time'] ) : '',
                    'by'     => (string) ( $h['by'] ?? '' ),
                    'note'   => (string) ( $h['note'] ?? '' ),
                );
            }, array_reverse( (array) ( $m( '_status_history' ) ?: array() ) ) ),
        );

        return $row;
    }

    private function query_ids( array $statuses, array $f, $limit = 200, $page = 1 ) {
        $mq = array(
            array( 'key' => '_order_status', 'value' => $statuses, 'compare' => 'IN' ),
        );
        if ( ! empty( $f['branch'] ) ) {
            $mq[] = array( 'key' => '_branch_id', 'value' => (int) $f['branch'] );
        }
        if ( ! empty( $f['type'] ) && in_array( $f['type'], array( 'delivery', 'pickup' ), true ) ) {
            $mq[] = array( 'key' => '_order_type', 'value' => $f['type'] );
        }

        $search = isset( $f['search'] ) ? trim( (string) $f['search'] ) : '';
        if ( $search !== '' ) {
            $mq[] = array(
                'relation' => 'OR',
                array( 'key' => '_order_number', 'value' => $search, 'compare' => 'LIKE' ),
                array( 'key' => '_customer_name', 'value' => $search, 'compare' => 'LIKE' ),
                array( 'key' => '_customer_phone', 'value' => $search, 'compare' => 'LIKE' ),
            );
        }

        $args = array(
            'post_type'      => 'menu_order',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => $limit,
            'paged'          => max( 1, (int) $page ),
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => $mq,
        );

        $date = array();
        if ( ! empty( $f['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['from'] ) ) {
            $date['after'] = $f['from'] . ' 00:00:00';
        }
        if ( ! empty( $f['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['to'] ) ) {
            $date['before'] = $f['to'] . ' 23:59:59';
        }
        if ( $date ) {
            $date['inclusive']  = true;
            $args['date_query'] = array( $date );
        }

        $q = new WP_Query( $args );
        return array( array_map( 'intval', $q->posts ), (int) $q->max_num_pages );
    }

    private function stats() {
        $today = current_time( 'Y-m-d' );
        $ids   = get_posts( array(
            'post_type'      => 'menu_order',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'date_query'     => array( array( 'after' => $today . ' 00:00:00', 'inclusive' => true ) ),
        ) );

        $orders = 0;
        $sales  = 0.0;
        foreach ( $ids as $id ) {
            $s = get_post_meta( $id, '_order_status', true );
            if ( in_array( $s, array( 'cancelled', 'expired', 'awaiting_payment' ), true ) ) {
                continue;
            }
            $orders++;
            $sales += (float) get_post_meta( $id, '_order_total', true );
        }

        $count = function ( $statuses ) {
            $q = new WP_Query( array(
                'post_type'      => 'menu_order',
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => 1,
                'meta_query'     => array( array( 'key' => '_order_status', 'value' => $statuses, 'compare' => 'IN' ) ),
            ) );
            return (int) $q->found_posts;
        };

        return array(
            'today_orders' => $orders,
            'today_sales'  => round( $sales, 2 ),
            'live'         => $count( self::LIVE_STATUSES ),
            'to_check'     => $count( array( 'awaiting_verification' ) ),
        );
    }

    // ---------------------------------------------------------------------
    // AJAX
    // ---------------------------------------------------------------------

    public function ajax_list() {
        $this->check();

        $view = sanitize_key( $_POST['view'] ?? 'live' );
        $f    = array(
            'search' => sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ),
            'branch' => absint( $_POST['branch'] ?? 0 ),
            'type'   => sanitize_key( $_POST['type'] ?? '' ),
            'from'   => sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) ),
            'to'     => sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ),
        );

        // Expire unpaid transfers before showing them
        if ( $view === 'payments' || $view === 'live' ) {
            Menu_Popup_Order_Tracking::get_instance()->expire_due_orders();
        }

        $out = array( 'stats' => $this->stats() );

        if ( $view === 'payments' ) {
            list( $check ) = $this->query_ids( array( 'awaiting_verification' ), $f );
            list( $wait )  = $this->query_ids( array( 'awaiting_payment' ), $f );
            $out['to_check'] = array_map( array( $this, 'row' ), $check );
            $out['waiting']  = array_map( array( $this, 'row' ), $wait );
        } elseif ( $view === 'history' ) {
            $status   = sanitize_key( $_POST['status'] ?? '' );
            $statuses = in_array( $status, self::DONE_STATUSES, true ) ? array( $status ) : self::DONE_STATUSES;
            $page     = max( 1, absint( $_POST['page'] ?? 1 ) );
            list( $ids, $pages ) = $this->query_ids( $statuses, $f, 30, $page );
            $out['orders'] = array_map( array( $this, 'row' ), $ids );
            $out['page']   = $page;
            $out['pages']  = $pages;
        } else {
            list( $ids ) = $this->query_ids( self::LIVE_STATUSES, $f );
            $out['orders'] = array_map( array( $this, 'row' ), $ids );
        }

        wp_send_json_success( $out );
    }

    public function ajax_order() {
        $this->check();
        $id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $id || get_post_type( $id ) !== 'menu_order' ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'menu-popup' ) ) );
        }
        Menu_Popup_Order_Tracking::expire_if_due( $id );
        wp_send_json_success( $this->row( $id, true ) );
    }

    public function ajax_set_status() {
        $this->check();
        $id     = absint( $_POST['order_id'] ?? 0 );
        $status = sanitize_key( $_POST['status'] ?? '' );
        if ( ! $id || get_post_type( $id ) !== 'menu_order' ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'menu-popup' ) ) );
        }

        $allowed = array( 'pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled' );
        if ( ! in_array( $status, $allowed, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown status.', 'menu-popup' ) ) );
        }

        $old = (string) get_post_meta( $id, '_order_status', true );
        // An unpaid transfer goes to the kitchen only through "Payment received"
        if ( in_array( $old, self::PAYMENT_STATUSES, true ) && $status !== 'cancelled' ) {
            wp_send_json_error( array( 'message' => __( 'Confirm the payment first.', 'menu-popup' ) ) );
        }

        if ( $old !== $status ) {
            update_post_meta( $id, '_order_status', $status );
            if ( $status === 'cancelled' && in_array( $old, self::PAYMENT_STATUSES, true ) ) {
                update_post_meta( $id, '_payment_status', 'rejected' );
            }
            Menu_Popup_Order_Tracking::add_history( $id, $status );
            do_action( 'menu_popup_order_status_changed', $id, $old, $status );
        }

        wp_send_json_success( $this->row( $id, true ) );
    }

    public function ajax_payment() {
        $this->check();
        $id       = absint( $_POST['order_id'] ?? 0 );
        $decision = sanitize_key( $_POST['decision'] ?? '' );
        if ( ! $id || get_post_type( $id ) !== 'menu_order' ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'menu-popup' ) ) );
        }

        if ( $decision === 'mark_paid' ) {
            $via = sanitize_key( $_POST['via'] ?? 'cash' );
            $via = in_array( $via, array( 'cash', 'card_pos' ), true ) ? $via : 'cash';
            update_post_meta( $id, '_payment_status', 'paid' );
            update_post_meta( $id, '_payment_paid_via', $via );
            update_post_meta( $id, '_payment_confirmed_at', time() );
            Menu_Popup_Order_Tracking::add_history( $id, 'paid', $via === 'card_pos' ? __( 'Card (machine)', 'menu-popup' ) : __( 'Cash', 'menu-popup' ) );
            wp_send_json_success( $this->row( $id, true ) );
        }

        $result = Menu_Popup_Order_Tracking::decide_payment(
            $id,
            $decision,
            wp_unslash( $_POST['reason'] ?? '' )
        );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( $this->row( $id, true ) );
    }

    /**
     * Stream a receipt to the admin. Receipts never get a public URL.
     */
    public function ajax_proof() {
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) ), self::NONCE ) ) {
            status_header( 403 );
            exit;
        }
        $id   = absint( $_GET['order_id'] ?? 0 );
        $path = $id ? Menu_Popup_Order_Tracking::proof_path( $id ) : '';
        if ( $path === '' ) {
            status_header( 404 );
            exit;
        }

        $types = array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf' );
        $ext   = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( ! isset( $types[ $ext ] ) ) {
            status_header( 404 );
            exit;
        }

        nocache_headers();
        header( 'Content-Type: ' . $types[ $ext ] );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Content-Disposition: inline; filename="receipt-' . $id . '.' . $ext . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $path );
        exit;
    }

    public function ajax_delete() {
        $this->check();
        $id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $id || get_post_type( $id ) !== 'menu_order' ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'menu-popup' ) ) );
        }
        $proof = Menu_Popup_Order_Tracking::proof_path( $id );
        if ( $proof !== '' ) {
            @unlink( $proof );
        }
        wp_delete_post( $id, true );
        wp_send_json_success();
    }
}
