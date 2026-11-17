<?php
/**
 * Order status page for customers, transfer receipts and payment expiry.
 *
 * Every order gets a secret key. The link ?mp_order=<id>&k=<key> opens a
 * small page where the customer follows the order and, for InstaPay /
 * wallet / bank transfers, sends the transaction number and a screenshot.
 *
 * Order status (_order_status) used here:
 *   awaiting_payment       transfer chosen, no receipt yet
 *   awaiting_verification  receipt sent, admin has to check it
 *   pending … delivered    the normal kitchen flow (unchanged)
 *   cancelled, expired
 *
 * Payment status (_payment_status):
 *   pending, awaiting_proof, awaiting_verification, paid, rejected, expired
 *
 * @package MenuPopup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu_Popup_Order_Tracking {

    const CRON_HOOK   = 'mp_expire_unpaid_orders';
    const MAX_UPLOAD  = 5242880; // 5 MB

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action( 'template_redirect', array( $this, 'maybe_render_status_page' ), 1 );

        add_action( 'wp_ajax_mp_order_status', array( $this, 'ajax_status' ) );
        add_action( 'wp_ajax_nopriv_mp_order_status', array( $this, 'ajax_status' ) );
        add_action( 'wp_ajax_mp_submit_payment_proof', array( $this, 'ajax_submit_proof' ) );
        add_action( 'wp_ajax_nopriv_mp_submit_payment_proof', array( $this, 'ajax_submit_proof' ) );

        add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
        add_action( self::CRON_HOOK, array( $this, 'expire_due_orders' ) );
        add_action( 'init', array( $this, 'schedule_cron' ) );
    }

    // ---------------------------------------------------------------------
    // Keys and links
    // ---------------------------------------------------------------------

    public static function ensure_key( $order_id ) {
        $key = (string) get_post_meta( $order_id, '_order_access_key', true );
        if ( $key === '' ) {
            $key = wp_generate_password( 24, false, false );
            update_post_meta( $order_id, '_order_access_key', $key );
        }
        return $key;
    }

    public static function status_url( $order_id ) {
        return add_query_arg(
            array(
                'mp_order' => (int) $order_id,
                'k'        => self::ensure_key( $order_id ),
            ),
            home_url( '/' )
        );
    }

    /**
     * The order behind an id + key pair, or 0.
     */
    private static function order_from_request( $id, $key ) {
        $id  = absint( $id );
        $key = is_string( $key ) ? $key : '';
        if ( ! $id || $key === '' || get_post_type( $id ) !== 'menu_order' ) {
            return 0;
        }
        $stored = (string) get_post_meta( $id, '_order_access_key', true );
        if ( $stored === '' || ! hash_equals( $stored, $key ) ) {
            return 0;
        }
        return $id;
    }

    // ---------------------------------------------------------------------
    // Labels
    // ---------------------------------------------------------------------

    public static function order_status_labels() {
        return array(
            'awaiting_payment'      => __( 'Waiting for payment', 'menu-popup' ),
            'awaiting_verification' => __( 'Checking payment', 'menu-popup' ),
            'pending'               => __( 'New', 'menu-popup' ),
            'confirmed'             => __( 'Confirmed', 'menu-popup' ),
            'preparing'             => __( 'Preparing', 'menu-popup' ),
            'ready'                 => __( 'Ready', 'menu-popup' ),
            'delivered'             => __( 'Completed', 'menu-popup' ),
            'cancelled'             => __( 'Cancelled', 'menu-popup' ),
            'expired'               => __( 'Expired', 'menu-popup' ),
        );
    }

    public static function payment_status_labels() {
        return array(
            'pending'               => __( 'Unpaid', 'menu-popup' ),
            'awaiting_proof'        => __( 'Waiting for receipt', 'menu-popup' ),
            'awaiting_verification' => __( 'Receipt to check', 'menu-popup' ),
            'paid'                  => __( 'Paid', 'menu-popup' ),
            'rejected'              => __( 'Rejected', 'menu-popup' ),
            'expired'               => __( 'Expired', 'menu-popup' ),
            'failed'                => __( 'Failed', 'menu-popup' ),
            'refunded'              => __( 'Refunded', 'menu-popup' ),
        );
    }

    // ---------------------------------------------------------------------
    // Expiry
    // ---------------------------------------------------------------------

    public function cron_schedules( $schedules ) {
        if ( ! isset( $schedules['mp_five_minutes'] ) ) {
            $schedules['mp_five_minutes'] = array(
                'interval' => 300,
                'display'  => 'Every 5 minutes (Menu Pop-up)',
            );
        }
        return $schedules;
    }

    public function schedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'mp_five_minutes', self::CRON_HOOK );
        }
    }

    /**
     * Cancel an unpaid transfer order whose time ran out.
     *
     * @return bool true when the order was expired now
     */
    public static function expire_if_due( $order_id ) {
        if ( get_post_meta( $order_id, '_order_status', true ) !== 'awaiting_payment' ) {
            return false;
        }
        $deadline = (int) get_post_meta( $order_id, '_payment_deadline', true );
        if ( ! $deadline || $deadline > time() ) {
            return false;
        }
        update_post_meta( $order_id, '_order_status', 'expired' );
        update_post_meta( $order_id, '_payment_status', 'expired' );
        self::add_history( $order_id, 'expired', __( 'No receipt in time', 'menu-popup' ) );
        do_action( 'menu_popup_order_status_changed', $order_id, 'awaiting_payment', 'expired' );
        return true;
    }

    public function expire_due_orders() {
        $ids = get_posts( array(
            'post_type'      => 'menu_order',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 200,
            'no_found_rows'  => true,
            'meta_query'     => array(
                array( 'key' => '_order_status', 'value' => 'awaiting_payment' ),
                array( 'key' => '_payment_deadline', 'value' => time(), 'compare' => '<', 'type' => 'NUMERIC' ),
            ),
        ) );
        foreach ( $ids as $id ) {
            self::expire_if_due( (int) $id );
        }
    }

    public static function add_history( $order_id, $status, $note = '' ) {
        $hist = get_post_meta( $order_id, '_status_history', true );
        if ( ! is_array( $hist ) ) {
            $hist = array();
        }
        $user   = wp_get_current_user();
        $hist[] = array(
            'status' => $status,
            'time'   => time(),
            'by'     => ( $user && $user->exists() ) ? $user->display_name : '',
            'note'   => $note,
        );
        if ( count( $hist ) > 50 ) {
            $hist = array_slice( $hist, -50 );
        }
        update_post_meta( $order_id, '_status_history', $hist );
    }

    // ---------------------------------------------------------------------
    // Admin decision on a receipt
    // ---------------------------------------------------------------------

    /**
     * @param string $decision confirm | reject
     * @return true|WP_Error
     */
    public static function decide_payment( $order_id, $decision, $reason = '' ) {
        $status = get_post_meta( $order_id, '_order_status', true );
        if ( ! in_array( $status, array( 'awaiting_verification', 'awaiting_payment' ), true ) ) {
            return new WP_Error( 'state', __( 'This order is not waiting for a payment check.', 'menu-popup' ) );
        }

        if ( $decision === 'confirm' ) {
            update_post_meta( $order_id, '_payment_status', 'paid' );
            update_post_meta( $order_id, '_payment_confirmed_at', time() );
            update_post_meta( $order_id, '_order_status', 'pending' );
            delete_post_meta( $order_id, '_payment_reject_reason' );
            self::add_history( $order_id, 'paid', __( 'Payment confirmed', 'menu-popup' ) );
            do_action( 'menu_popup_order_status_changed', $order_id, $status, 'pending' );
            return true;
        }

        if ( $decision === 'reject' ) {
            $reason = sanitize_text_field( $reason );
            update_post_meta( $order_id, '_payment_status', 'rejected' );
            update_post_meta( $order_id, '_payment_reject_reason', $reason );
            update_post_meta( $order_id, '_order_status', 'awaiting_payment' );
            // a fresh window to send a correct receipt
            update_post_meta( $order_id, '_payment_deadline', time() + Menu_Popup_Payment_Methods::deadline_minutes() * MINUTE_IN_SECONDS );
            self::add_history( $order_id, 'rejected', $reason );
            do_action( 'menu_popup_order_status_changed', $order_id, $status, 'awaiting_payment' );
            return true;
        }

        return new WP_Error( 'decision', 'Unknown decision.' );
    }

    // ---------------------------------------------------------------------
    // Receipt files
    // ---------------------------------------------------------------------

    public static function proof_base_dir() {
        $up  = wp_upload_dir( null, false );
        $dir = trailingslashit( $up['basedir'] ) . 'menu-popup-private/proofs';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $root = dirname( $dir );
        if ( ! file_exists( $root . '/.htaccess' ) ) {
            @file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" );
        }
        foreach ( array( $root, $dir ) as $d ) {
            if ( ! file_exists( $d . '/index.php' ) ) {
                @file_put_contents( $d . '/index.php', "<?php // Silence is golden.\n" );
            }
        }
        return $dir;
    }

    /**
     * Absolute path of the current receipt of an order, or ''.
     */
    public static function proof_path( $order_id ) {
        $rel = (string) get_post_meta( $order_id, '_payment_proof_file', true );
        if ( $rel === '' || strpos( $rel, '..' ) !== false ) {
            return '';
        }
        $path = trailingslashit( self::proof_base_dir() ) . ltrim( $rel, '/' );
        return file_exists( $path ) ? $path : '';
    }

    private static function detect_mime( $tmp ) {
        $mime = '';
        if ( function_exists( 'finfo_open' ) ) {
            $fi = finfo_open( FILEINFO_MIME_TYPE );
            if ( $fi ) {
                $mime = (string) finfo_file( $fi, $tmp );
                finfo_close( $fi );
            }
        }
        if ( $mime === '' ) {
            $info = @getimagesize( $tmp );
            if ( $info && ! empty( $info['mime'] ) ) {
                $mime = $info['mime'];
            } else {
                $head = (string) @file_get_contents( $tmp, false, null, 0, 5 );
                if ( $head === '%PDF-' ) {
                    $mime = 'application/pdf';
                }
            }
        }
        return $mime;
    }

    // ---------------------------------------------------------------------
    // Public data
    // ---------------------------------------------------------------------

    /**
     * What the status page and its refresh show.
     */
    public static function public_status( $order_id ) {
        self::expire_if_due( $order_id );

        $status   = (string) get_post_meta( $order_id, '_order_status', true );
        $status   = $status !== '' ? $status : 'pending';
        $pstatus  = (string) get_post_meta( $order_id, '_payment_status', true );
        $method   = (string) get_post_meta( $order_id, '_payment_method', true );
        $deadline = (int) get_post_meta( $order_id, '_payment_deadline', true );
        $labels   = self::order_status_labels();

        return array(
            'status'         => $status,
            'status_label'   => isset( $labels[ $status ] ) ? $labels[ $status ] : $status,
            'payment_status' => $pstatus,
            'payment_method' => $method,
            'is_transfer'    => Menu_Popup_Payment_Methods::is_transfer( $method ),
            'seconds_left'   => ( $status === 'awaiting_payment' && $deadline ) ? max( 0, $deadline - time() ) : 0,
            'reject_reason'  => (string) get_post_meta( $order_id, '_payment_reject_reason', true ),
            'can_upload'     => $status === 'awaiting_payment',
        );
    }

    // ---------------------------------------------------------------------
    // AJAX
    // ---------------------------------------------------------------------

    public function ajax_status() {
        $id = self::order_from_request( $_POST['order_id'] ?? 0, wp_unslash( $_POST['k'] ?? '' ) );
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'menu-popup' ) ), 404 );
        }
        nocache_headers();
        wp_send_json_success( self::public_status( $id ) );
    }

    public function ajax_submit_proof() {
        $id = self::order_from_request( $_POST['order_id'] ?? 0, wp_unslash( $_POST['k'] ?? '' ) );
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'menu-popup' ) ), 404 );
        }

        // Limits: per order and per visitor
        $ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $k_order = 'mp_proof_o_' . $id;
        $k_ip    = 'mp_proof_ip_' . md5( $ip );
        if ( (int) get_transient( $k_order ) >= 6 || (int) get_transient( $k_ip ) >= 20 ) {
            wp_send_json_error( array( 'message' => __( 'Too many attempts. Please wait a while and try again.', 'menu-popup' ) ) );
        }
        set_transient( $k_order, (int) get_transient( $k_order ) + 1, HOUR_IN_SECONDS );
        set_transient( $k_ip, (int) get_transient( $k_ip ) + 1, HOUR_IN_SECONDS );

        self::expire_if_due( $id );
        if ( get_post_meta( $id, '_order_status', true ) !== 'awaiting_payment' ) {
            wp_send_json_error( array( 'message' => __( 'This order is no longer waiting for a payment.', 'menu-popup' ) ) );
        }
        $method = (string) get_post_meta( $id, '_payment_method', true );
        if ( ! Menu_Popup_Payment_Methods::is_transfer( $method ) ) {
            wp_send_json_error( array( 'message' => __( 'This order does not need a receipt.', 'menu-popup' ) ) );
        }

        $ref    = substr( preg_replace( '/[^\p{L}\p{N}\-_. #\/]/u', '', sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) ) ), 0, 64 );
        $sender = substr( sanitize_text_field( wp_unslash( $_POST['sender'] ?? '' ) ), 0, 64 );
        if ( mb_strlen( trim( $ref ) ) < 3 ) {
            wp_send_json_error( array( 'message' => __( 'Please enter the transaction number from your receipt.', 'menu-popup' ) ) );
        }
        $ref = trim( $ref );

        $has_file = ! empty( $_FILES['proof'] ) && isset( $_FILES['proof']['error'] ) && $_FILES['proof']['error'] !== UPLOAD_ERR_NO_FILE;
        if ( ! $has_file && Menu_Popup_Payment_Methods::require_image() ) {
            wp_send_json_error( array( 'message' => __( 'Please attach a screenshot of the transfer.', 'menu-popup' ) ) );
        }

        $rel = '';
        if ( $has_file ) {
            $f = $_FILES['proof'];
            if ( $f['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file( $f['tmp_name'] ) ) {
                wp_send_json_error( array( 'message' => __( 'The file could not be uploaded. Please try again.', 'menu-popup' ) ) );
            }
            if ( (int) $f['size'] > self::MAX_UPLOAD ) {
                wp_send_json_error( array( 'message' => __( 'The file is larger than 5 MB.', 'menu-popup' ) ) );
            }
            $allowed = array(
                'image/jpeg'      => 'jpg',
                'image/png'       => 'png',
                'image/webp'      => 'webp',
                'application/pdf' => 'pdf',
            );
            $mime = self::detect_mime( $f['tmp_name'] );
            if ( ! isset( $allowed[ $mime ] ) ) {
                wp_send_json_error( array( 'message' => __( 'Only JPG, PNG, WebP or PDF files are accepted.', 'menu-popup' ) ) );
            }

            $sub = gmdate( 'Y/m' );
            $dir = trailingslashit( self::proof_base_dir() ) . $sub;
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
                @file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
            }
            $name = $id . '-' . wp_generate_password( 20, false, false ) . '.' . $allowed[ $mime ];
            if ( ! @move_uploaded_file( $f['tmp_name'], $dir . '/' . $name ) ) {
                wp_send_json_error( array( 'message' => __( 'The file could not be saved. Please try again.', 'menu-popup' ) ) );
            }
            @chmod( $dir . '/' . $name, 0640 );
            $rel = $sub . '/' . $name;
        }

        // Keep earlier receipts of this order for the record
        $old = (string) get_post_meta( $id, '_payment_proof_file', true );
        if ( $old !== '' ) {
            $prev   = get_post_meta( $id, '_payment_proof_history', true );
            $prev   = is_array( $prev ) ? $prev : array();
            $prev[] = array(
                'file' => $old,
                'ref'  => (string) get_post_meta( $id, '_payment_ref', true ),
                'time' => (int) get_post_meta( $id, '_payment_submitted_at', true ),
            );
            update_post_meta( $id, '_payment_proof_history', array_slice( $prev, -10 ) );
        }

        update_post_meta( $id, '_payment_ref', $ref );
        update_post_meta( $id, '_payment_sender', $sender );
        update_post_meta( $id, '_payment_proof_file', $rel );
        update_post_meta( $id, '_payment_submitted_at', time() );
        update_post_meta( $id, '_payment_status', 'awaiting_verification' );
        update_post_meta( $id, '_order_status', 'awaiting_verification' );

        // Same transaction number on another order?
        $dupes = get_posts( array(
            'post_type'      => 'menu_order',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 5,
            'no_found_rows'  => true,
            'post__not_in'   => array( $id ),
            'meta_query'     => array( array( 'key' => '_payment_ref', 'value' => $ref ) ),
        ) );
        $numbers = array();
        foreach ( $dupes as $d ) {
            $numbers[] = (string) ( get_post_meta( $d, '_order_number', true ) ?: $d );
        }
        if ( $numbers ) {
            update_post_meta( $id, '_payment_ref_duplicates', $numbers );
        } else {
            delete_post_meta( $id, '_payment_ref_duplicates' );
        }

        self::add_history( $id, 'awaiting_verification', __( 'Receipt sent', 'menu-popup' ) );
        do_action( 'menu_popup_order_status_changed', $id, 'awaiting_payment', 'awaiting_verification' );
        do_action( 'menu_popup_payment_proof_submitted', $id );

        wp_send_json_success( self::public_status( $id ) );
    }

    // ---------------------------------------------------------------------
    // Status page
    // ---------------------------------------------------------------------

    public function maybe_render_status_page() {
        if ( empty( $_GET['mp_order'] ) ) {
            return;
        }

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        do_action( 'litespeed_control_set_nocache', 'menu-popup order status' );
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow' );

        $id = self::order_from_request( wp_unslash( $_GET['mp_order'] ), wp_unslash( $_GET['k'] ?? '' ) );
        if ( ! $id ) {
            status_header( 404 );
            $this->page_shell( __( 'Order not found', 'menu-popup' ), '<div class="mp-card"><p>' . esc_html__( 'This order link is not valid.', 'menu-popup' ) . '</p></div>', '' );
            exit;
        }

        $this->render_status_page( $id );
        exit;
    }

    private function render_status_page( $id ) {
        $st       = self::public_status( $id );
        $number   = (string) ( get_post_meta( $id, '_order_number', true ) ?: $id );
        $cur      = (string) ( get_post_meta( $id, '_currency_symbol', true ) ?: get_option( 'menu_popup_currency_symbol', '$' ) );
        $items    = json_decode( (string) get_post_meta( $id, '_order_items', true ), true );
        $items    = is_array( $items ) ? $items : array();
        $total    = (float) get_post_meta( $id, '_order_total', true );
        $subtotal = (float) get_post_meta( $id, '_order_subtotal', true );
        $tax      = (float) get_post_meta( $id, '_order_tax', true );
        $fee      = (float) get_post_meta( $id, '_delivery_fee', true );
        $type     = (string) get_post_meta( $id, '_order_type', true );
        $money    = function ( $v ) use ( $cur ) {
            return $cur . number_format( (float) $v, 2 );
        };

        ob_start();
        ?>
        <div class="mp-card mp-head">
            <div class="mp-number"><?php echo esc_html( sprintf( __( 'Order #%s', 'menu-popup' ), $number ) ); ?></div>
            <div class="mp-badge mp-s-<?php echo esc_attr( $st['status'] ); ?>"><?php echo esc_html( $st['status_label'] ); ?></div>
            <?php echo $this->steps_html( $st ); // phpcs:ignore -- escaped inside ?>
        </div>

        <?php if ( $st['status'] === 'awaiting_payment' ) :
            $cfg = Menu_Popup_Payment_Methods::transfer_config( $st['payment_method'] );
            ?>
            <?php if ( $st['payment_status'] === 'rejected' ) : ?>
                <div class="mp-card mp-alert">
                    <strong><?php esc_html_e( 'Your receipt was not accepted.', 'menu-popup' ); ?></strong>
                    <?php if ( $st['reject_reason'] !== '' ) : ?>
                        <div><?php echo esc_html( $st['reject_reason'] ); ?></div>
                    <?php endif; ?>
                    <div><?php esc_html_e( 'Please check the transfer and send the receipt again.', 'menu-popup' ); ?></div>
                </div>
            <?php endif; ?>

            <?php if ( $cfg ) : ?>
            <div class="mp-card">
                <h2><?php echo esc_html( sprintf( __( 'Pay with %s', 'menu-popup' ), $cfg['label'] ) ); ?></h2>
                <div class="mp-amount"><?php echo esc_html( $money( $total ) ); ?></div>
                <p class="mp-muted"><?php esc_html_e( 'Transfer exactly this amount, and write the order number in the transfer note if you can.', 'menu-popup' ); ?></p>

                <div class="mp-field">
                    <span class="mp-k"><?php esc_html_e( 'Send to', 'menu-popup' ); ?></span>
                    <span class="mp-v" dir="ltr" id="mp-acc"><?php echo esc_html( $cfg['account'] ); ?></span>
                    <button type="button" class="mp-copy" data-copy="#mp-acc"><?php esc_html_e( 'Copy', 'menu-popup' ); ?></button>
                </div>
                <?php if ( $cfg['holder'] !== '' ) : ?>
                    <div class="mp-field"><span class="mp-k"><?php esc_html_e( 'Name', 'menu-popup' ); ?></span><span class="mp-v"><?php echo esc_html( $cfg['holder'] ); ?></span></div>
                <?php endif; ?>
                <?php if ( $cfg['extra'] !== '' ) : ?>
                    <div class="mp-field"><span class="mp-v"><?php echo esc_html( $cfg['extra'] ); ?></span></div>
                <?php endif; ?>
                <div class="mp-field">
                    <span class="mp-k"><?php esc_html_e( 'Order number', 'menu-popup' ); ?></span>
                    <span class="mp-v" dir="ltr" id="mp-num"><?php echo esc_html( $number ); ?></span>
                    <button type="button" class="mp-copy" data-copy="#mp-num"><?php esc_html_e( 'Copy', 'menu-popup' ); ?></button>
                </div>

                <?php if ( $cfg['link'] !== '' ) : ?>
                    <a class="mp-btn mp-btn-alt" href="<?php echo esc_url( $cfg['link'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open InstaPay', 'menu-popup' ); ?></a>
                <?php endif; ?>
                <?php if ( $cfg['qr'] !== '' ) : ?>
                    <img class="mp-qr" src="<?php echo esc_url( $cfg['qr'] ); ?>" alt="QR">
                <?php endif; ?>
                <?php if ( trim( $cfg['instructions'] ) !== '' ) : ?>
                    <p class="mp-muted" dir="auto"><?php echo nl2br( esc_html( $cfg['instructions'] ) ); ?></p>
                <?php endif; ?>

                <p class="mp-timer"><?php esc_html_e( 'Time left to send the receipt:', 'menu-popup' ); ?> <strong id="mp-left">--:--</strong></p>
            </div>

            <form class="mp-card" id="mp-proof-form" enctype="multipart/form-data">
                <h2><?php esc_html_e( 'Send your receipt', 'menu-popup' ); ?></h2>
                <label><?php esc_html_e( 'Transaction number', 'menu-popup' ); ?> *
                    <input type="text" name="reference" required maxlength="64" dir="ltr" autocomplete="off">
                </label>
                <label><?php esc_html_e( 'Phone number or name you sent from', 'menu-popup' ); ?>
                    <input type="text" name="sender" maxlength="64" dir="auto">
                </label>
                <label><?php esc_html_e( 'Screenshot of the transfer', 'menu-popup' ); ?><?php echo Menu_Popup_Payment_Methods::require_image() ? ' *' : ''; ?>
                    <input type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" <?php echo Menu_Popup_Payment_Methods::require_image() ? 'required' : ''; ?>>
                </label>
                <p class="mp-muted"><?php esc_html_e( 'JPG, PNG, WebP or PDF, up to 5 MB.', 'menu-popup' ); ?></p>
                <button type="submit" class="mp-btn"><?php esc_html_e( 'Send receipt', 'menu-popup' ); ?></button>
                <div class="mp-msg" id="mp-proof-msg" role="alert"></div>
            </form>
            <?php endif; ?>

        <?php elseif ( $st['status'] === 'awaiting_verification' ) : ?>
            <div class="mp-card mp-info">
                <?php esc_html_e( 'We received your receipt and are checking the payment. Your order will start as soon as it is confirmed. This page updates by itself.', 'menu-popup' ); ?>
            </div>
        <?php elseif ( $st['status'] === 'expired' ) : ?>
            <div class="mp-card mp-alert"><?php esc_html_e( 'This order was cancelled because no payment receipt arrived in time. Please place a new order.', 'menu-popup' ); ?></div>
        <?php elseif ( $st['status'] === 'cancelled' ) : ?>
            <div class="mp-card mp-alert"><?php esc_html_e( 'This order was cancelled. Please contact the restaurant if you have questions.', 'menu-popup' ); ?></div>
        <?php endif; ?>

        <div class="mp-card">
            <h2><?php esc_html_e( 'Your order', 'menu-popup' ); ?></h2>
            <ul class="mp-items">
                <?php foreach ( $items as $line ) : ?>
                    <li>
                        <span><?php echo esc_html( ( $line['name'] ?? '' ) . ( ! empty( $line['size'] ) ? ' (' . $line['size'] . ')' : '' ) . ' × ' . (int) ( $line['quantity'] ?? 1 ) ); ?></span>
                        <span><?php echo esc_html( $money( $line['line_total'] ?? 0 ) ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="mp-row"><span><?php esc_html_e( 'Subtotal', 'menu-popup' ); ?></span><span><?php echo esc_html( $money( $subtotal ) ); ?></span></div>
            <?php if ( $tax > 0 ) : ?>
                <div class="mp-row"><span><?php esc_html_e( 'Tax', 'menu-popup' ); ?></span><span><?php echo esc_html( $money( $tax ) ); ?></span></div>
            <?php endif; ?>
            <?php if ( $type === 'delivery' ) : ?>
                <div class="mp-row"><span><?php esc_html_e( 'Delivery', 'menu-popup' ); ?></span><span><?php echo esc_html( $money( $fee ) ); ?></span></div>
            <?php endif; ?>
            <div class="mp-row mp-total"><span><?php esc_html_e( 'Total', 'menu-popup' ); ?></span><span><?php echo esc_html( $money( $total ) ); ?></span></div>
            <div class="mp-row mp-muted"><span><?php esc_html_e( 'Payment', 'menu-popup' ); ?></span><span><?php echo esc_html( Menu_Popup_Payment_Methods::label( $st['payment_method'] ) ); ?></span></div>
        </div>
        <?php
        $body = ob_get_clean();

        $config = array(
            'ajax'    => admin_url( 'admin-ajax.php' ),
            'id'      => (int) $id,
            'k'       => self::ensure_key( $id ),
            'status'  => $st['status'],
            'left'    => (int) $st['seconds_left'],
            'copied'  => __( 'Copied', 'menu-popup' ),
            'sending' => __( 'Sending…', 'menu-popup' ),
            'error'   => __( 'Connection problem. Please try again.', 'menu-popup' ),
        );

        $this->page_shell( sprintf( __( 'Order #%s', 'menu-popup' ), $number ), $body, wp_json_encode( $config ) );
    }

    private function steps_html( array $st ) {
        $flow = array( 'pending', 'preparing', 'ready', 'delivered' );
        if ( $st['is_transfer'] ) {
            array_unshift( $flow, 'awaiting_payment', 'awaiting_verification' );
        }
        $labels = self::order_status_labels();
        $labels['awaiting_payment']      = __( 'Payment', 'menu-popup' );
        $labels['awaiting_verification'] = __( 'Check', 'menu-popup' );
        $labels['pending']               = __( 'Received', 'menu-popup' );

        $current = $st['status'] === 'confirmed' ? 'pending' : $st['status'];
        $pos     = array_search( $current, $flow, true );
        if ( $pos === false ) {
            return '';
        }

        $html = '<ol class="mp-steps">';
        foreach ( $flow as $i => $s ) {
            $cls   = $i < $pos ? 'done' : ( $i === $pos ? 'now' : '' );
            $html .= '<li class="' . esc_attr( $cls ) . '">' . esc_html( $labels[ $s ] ) . '</li>';
        }
        return $html . '</ol>';
    }

    private function page_shell( $title, $body, $config_json ) {
        $site = get_bloginfo( 'name' );
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' · ' . $site ); ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f4f5;color:#18181b;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Tahoma,Arial,sans-serif}
.mp-wrap{max-width:520px;margin:0 auto;padding:16px}
.mp-site{text-align:center;font-weight:700;font-size:18px;margin:6px 0 14px}
.mp-card{background:#fff;border-radius:14px;padding:18px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.mp-card h2{font-size:17px;margin:0 0 10px}
.mp-head{text-align:center}
.mp-number{font-size:20px;font-weight:700}
.mp-badge{display:inline-block;margin:8px 0 4px;padding:4px 14px;border-radius:99px;background:#e4e4e7;font-weight:600}
.mp-s-awaiting_payment{background:#fef3c7;color:#92400e}.mp-s-awaiting_verification{background:#dbeafe;color:#1e40af}
.mp-s-pending,.mp-s-confirmed,.mp-s-preparing{background:#e0e7ff;color:#3730a3}.mp-s-ready,.mp-s-delivered{background:#dcfce7;color:#166534}
.mp-s-cancelled,.mp-s-expired{background:#fee2e2;color:#991b1b}
.mp-steps{display:flex;list-style:none;padding:0;margin:14px 0 0;gap:4px}
.mp-steps li{flex:1;font-size:11px;padding-top:8px;border-top:4px solid #e4e4e7;color:#71717a}
.mp-steps li.done{border-color:#22c55e;color:#166534}.mp-steps li.now{border-color:#2563eb;color:#1e40af;font-weight:700}
.mp-amount{font-size:30px;font-weight:800;text-align:center;margin:4px 0}
.mp-muted{color:#71717a;font-size:13px}
.mp-field{display:flex;align-items:center;gap:8px;padding:10px 0;border-bottom:1px solid #f4f4f5;flex-wrap:wrap}
.mp-k{color:#71717a;min-width:90px}.mp-v{font-weight:700;flex:1;word-break:break-all}
.mp-copy{border:1px solid #d4d4d8;background:#fff;border-radius:8px;padding:4px 10px;cursor:pointer}
.mp-btn{display:block;width:100%;text-align:center;padding:12px;border:0;border-radius:10px;background:#18181b;color:#fff;font-size:16px;font-weight:600;cursor:pointer;text-decoration:none;margin-top:12px}
.mp-btn-alt{background:#7c3aed}.mp-btn[disabled]{opacity:.6}
.mp-qr{display:block;max-width:220px;margin:14px auto 0;border-radius:8px}
.mp-timer{text-align:center;margin:14px 0 0}
form label{display:block;margin-bottom:12px;font-weight:600}
form input[type=text],form input[type=file]{display:block;width:100%;margin-top:4px;padding:10px;border:1px solid #d4d4d8;border-radius:8px;font:inherit;background:#fff}
.mp-msg{margin-top:10px;font-weight:600}.mp-msg.err{color:#b91c1c}.mp-msg.ok{color:#166534}
.mp-alert{background:#fef2f2;color:#991b1b}.mp-info{background:#eff6ff;color:#1e3a8a}
.mp-items{list-style:none;margin:0 0 8px;padding:0}.mp-items li{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px solid #f4f4f5}
.mp-row{display:flex;justify-content:space-between;padding:3px 0}.mp-total{font-weight:800;font-size:17px;border-top:2px solid #18181b;margin-top:6px;padding-top:8px}
</style>
</head>
<body>
<div class="mp-wrap">
    <div class="mp-site"><?php echo esc_html( $site ); ?></div>
    <?php echo $body; // phpcs:ignore -- built from escaped parts ?>
</div>
<?php if ( $config_json !== '' ) : ?>
<script>
(function () {
    var C = <?php echo $config_json; // phpcs:ignore -- wp_json_encode ?>;
    function post(data, cb) {
        var x = new XMLHttpRequest();
        x.open('POST', C.ajax, true);
        x.onload = function () { var r = null; try { r = JSON.parse(x.responseText); } catch (e) {} cb(r); };
        x.onerror = function () { cb(null); };
        x.send(data);
    }
    function fd(extra) {
        var f = new FormData();
        f.append('order_id', C.id); f.append('k', C.k);
        for (var key in extra) { if (extra.hasOwnProperty(key)) { f.append(key, extra[key]); } }
        return f;
    }

    // copy buttons
    Array.prototype.forEach.call(document.querySelectorAll('.mp-copy'), function (b) {
        b.addEventListener('click', function () {
            var el = document.querySelector(b.getAttribute('data-copy'));
            if (!el) { return; }
            var t = el.textContent.trim(), old = b.textContent;
            var done = function () { b.textContent = C.copied; setTimeout(function () { b.textContent = old; }, 1500); };
            if (navigator.clipboard) { navigator.clipboard.writeText(t).then(done, done); }
            else { var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); try { document.execCommand('copy'); } catch (e) {} a.remove(); done(); }
        });
    });

    // countdown
    var left = C.left, $left = document.getElementById('mp-left');
    if ($left && left > 0) {
        var tick = function () {
            if (left <= 0) { location.reload(); return; }
            var m = Math.floor(left / 60), s = left % 60;
            $left.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            left--;
        };
        tick(); setInterval(tick, 1000);
    }

    // receipt form
    var form = document.getElementById('mp-proof-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var msg = document.getElementById('mp-proof-msg'), btn = form.querySelector('button[type=submit]');
            var data = new FormData(form);
            data.append('action', 'mp_submit_payment_proof'); data.append('order_id', C.id); data.append('k', C.k);
            btn.disabled = true; var label = btn.textContent; btn.textContent = C.sending; msg.className = 'mp-msg'; msg.textContent = '';
            post(data, function (r) {
                if (r && r.success) { location.reload(); return; }
                btn.disabled = false; btn.textContent = label;
                msg.className = 'mp-msg err';
                msg.textContent = (r && r.data && r.data.message) ? r.data.message : C.error;
            });
        });
    }

    // follow the order while it is still moving
    var finals = ['delivered', 'cancelled', 'expired'];
    if (finals.indexOf(C.status) === -1) {
        setInterval(function () {
            if (document.hidden) { return; }
            if (form && form.contains(document.activeElement)) { return; }
            post(fd({ action: 'mp_order_status' }), function (r) {
                if (r && r.success && r.data.status !== C.status) { location.reload(); }
            });
        }, 10000);
    }
})();
</script>
<?php endif; ?>
</body>
</html>
        <?php
    }
}
