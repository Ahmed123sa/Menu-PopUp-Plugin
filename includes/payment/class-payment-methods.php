<?php
/**
 * Payment methods offered at checkout.
 *
 * One place that answers "which payment methods can the customer pick right
 * now", for the checkout, the order service and the order status page.
 *
 *  - cash          Cash on delivery / at pickup (the existing COD switch)
 *  - manual        Transfers the customer proves with a reference number and a
 *                  screenshot: InstaPay, the mobile wallets and bank transfer.
 *                  The order waits until an admin confirms the money arrived.
 *  - online        Fawry (redirect to the gateway)
 *
 * @package MenuPopup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu_Popup_Payment_Methods {

    const OPTION_PREFIX = 'menu_popup_mt_';

    /**
     * Transfer methods the admin can switch on one by one.
     */
    public static function transfer_definitions() {
        return array(
            'instapay'      => array(
                'label'         => 'InstaPay',
                'label_ar'      => 'إنستاباي',
                'icon'          => '⚡',
                'account_label' => __( 'InstaPay address (IPA) or phone', 'menu-popup' ),
                'has_link'      => true,
            ),
            'vodafone_cash' => array(
                'label'         => 'Vodafone Cash',
                'label_ar'      => 'فودافون كاش',
                'icon'          => '📱',
                'account_label' => __( 'Wallet number', 'menu-popup' ),
                'has_link'      => false,
            ),
            'orange_cash'   => array(
                'label'         => 'Orange Cash',
                'label_ar'      => 'أورنج كاش',
                'icon'          => '📱',
                'account_label' => __( 'Wallet number', 'menu-popup' ),
                'has_link'      => false,
            ),
            'etisalat_cash' => array(
                'label'         => 'e& Cash',
                'label_ar'      => 'اتصالات كاش',
                'icon'          => '📱',
                'account_label' => __( 'Wallet number', 'menu-popup' ),
                'has_link'      => false,
            ),
            'we_pay'        => array(
                'label'         => 'WE Pay',
                'label_ar'      => 'وي باي',
                'icon'          => '📱',
                'account_label' => __( 'Wallet number', 'menu-popup' ),
                'has_link'      => false,
            ),
        );
    }

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------

    public static function opt( $key, $field, $default = '' ) {
        return (string) get_option( self::OPTION_PREFIX . $key . '_' . $field, $default );
    }

    /**
     * Minutes the customer has to send the proof before the order expires.
     */
    public static function deadline_minutes() {
        $m = (int) get_option( self::OPTION_PREFIX . 'deadline', 15 );
        return max( 5, min( 240, $m ? $m : 15 ) );
    }

    public static function require_image() {
        return get_option( self::OPTION_PREFIX . 'require_image', '1' ) === '1';
    }

    public static function payments_on() {
        return get_option( 'menu_popup_payment_enabled', '0' ) === '1';
    }

    /**
     * Data the customer needs to make a transfer with this method.
     *
     * @return array|null
     */
    public static function transfer_config( $id ) {
        if ( $id === 'bank_transfer' ) {
            $account = trim( (string) get_option( 'menu_popup_payment_bank_transfer_account_number', '' ) );
            $iban    = trim( (string) get_option( 'menu_popup_payment_bank_transfer_iban', '' ) );
            $lines   = array();
            $bank    = trim( (string) get_option( 'menu_popup_payment_bank_transfer_bank_name', '' ) );
            if ( $bank !== '' ) {
                $lines[] = $bank;
            }
            if ( $iban !== '' ) {
                $lines[] = 'IBAN: ' . $iban;
            }
            return array(
                'id'           => 'bank_transfer',
                'label'        => __( 'Bank Transfer', 'menu-popup' ),
                'label_ar'     => 'تحويل بنكي',
                'icon'         => '🏦',
                'enabled'      => get_option( 'menu_popup_payment_bank_transfer_enabled', '0' ) === '1',
                'account'      => $account !== '' ? $account : $iban,
                'holder'       => (string) get_option( 'menu_popup_payment_bank_transfer_account_holder', '' ),
                'link'         => '',
                'qr'           => '',
                'extra'        => implode( ' · ', $lines ),
                'instructions' => (string) get_option( 'menu_popup_payment_bank_transfer_instructions', '' ),
            );
        }

        $defs = self::transfer_definitions();
        if ( ! isset( $defs[ $id ] ) ) {
            return null;
        }

        return array(
            'id'           => $id,
            'label'        => $defs[ $id ]['label'],
            'label_ar'     => $defs[ $id ]['label_ar'],
            'icon'         => $defs[ $id ]['icon'],
            'enabled'      => self::opt( $id, 'enabled', '0' ) === '1',
            'account'      => trim( self::opt( $id, 'account' ) ),
            'holder'       => trim( self::opt( $id, 'holder' ) ),
            'link'         => $defs[ $id ]['has_link'] ? trim( self::opt( $id, 'link' ) ) : '',
            'qr'           => trim( self::opt( $id, 'qr' ) ),
            'extra'        => '',
            'instructions' => self::opt( $id, 'instructions' ),
        );
    }

    public static function is_transfer( $id ) {
        return $id === 'bank_transfer' || array_key_exists( $id, self::transfer_definitions() );
    }

    /**
     * Payment methods the customer can choose from, in display order.
     * Empty when the payment system is off: the order is then taken without
     * a payment step, as before.
     *
     * @return array[] each: id, label, label_ar, description, kind, icon
     */
    public static function available() {
        if ( ! self::payments_on() ) {
            return array();
        }

        $list = array();

        if ( get_option( 'menu_popup_payment_cod_enabled', '0' ) === '1' ) {
            $list[] = array(
                'id'          => 'cod',
                'label'       => __( 'Cash', 'menu-popup' ),
                'label_ar'    => 'كاش',
                'description' => __( 'Pay in cash on delivery or at pickup', 'menu-popup' ),
                'kind'        => 'cash',
                'icon'        => '💵',
            );
        }

        $ids = array_merge( array_keys( self::transfer_definitions() ), array( 'bank_transfer' ) );
        foreach ( $ids as $id ) {
            $c = self::transfer_config( $id );
            if ( ! $c || ! $c['enabled'] || $c['account'] === '' ) {
                continue;
            }
            $list[] = array(
                'id'          => $id,
                'label'       => $c['label'],
                'label_ar'    => $c['label_ar'],
                'description' => __( 'Transfer now, then send the receipt. Your order starts after we confirm it.', 'menu-popup' ),
                'kind'        => 'manual',
                'icon'        => $c['icon'],
            );
        }

        if ( get_option( 'menu_popup_payment_fawry_enabled', '0' ) === '1' ) {
            $list[] = array(
                'id'          => 'fawry',
                'label'       => __( 'Fawry', 'menu-popup' ),
                'label_ar'    => 'فوري',
                'description' => __( 'Pay online with Fawry', 'menu-popup' ),
                'kind'        => 'online',
                'icon'        => '💳',
            );
        }

        return $list;
    }

    public static function find_available( $id ) {
        foreach ( self::available() as $m ) {
            if ( $m['id'] === $id ) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Label of any payment method id, for the dashboard.
     */
    public static function label( $id ) {
        if ( $id === '' || $id === null ) {
            return __( 'Not set', 'menu-popup' );
        }
        if ( $id === 'cod' ) {
            return __( 'Cash', 'menu-popup' );
        }
        if ( $id === 'fawry' ) {
            return 'Fawry';
        }
        if ( $id === 'card_pos' ) {
            return __( 'Card (machine)', 'menu-popup' );
        }
        if ( $id === 'table' ) {
            return __( 'Pay at the table', 'menu-popup' );
        }
        $c = self::transfer_config( $id );
        return $c ? $c['label'] : $id;
    }

    // ---------------------------------------------------------------------
    // Admin settings (rendered inside the Payment tab form)
    // ---------------------------------------------------------------------

    public static function render_settings() {
        $defs = self::transfer_definitions();
        ?>
        <h3><?php esc_html_e( 'InstaPay and mobile wallets', 'menu-popup' ); ?></h3>
        <p class="description">
            <?php esc_html_e( 'The customer transfers the order total, then enters the transaction number and uploads a screenshot. The order goes to the kitchen only after you confirm the payment in the Order Dashboard.', 'menu-popup' ); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="mp_mt_deadline"><?php esc_html_e( 'Time to send the receipt', 'menu-popup' ); ?></label></th>
                <td>
                    <input type="number" min="5" max="240" id="mp_mt_deadline" name="<?php echo esc_attr( self::OPTION_PREFIX . 'deadline' ); ?>" value="<?php echo esc_attr( self::deadline_minutes() ); ?>" class="small-text"> <?php esc_html_e( 'minutes', 'menu-popup' ); ?>
                    <p class="description"><?php esc_html_e( 'Orders without a receipt after this time are cancelled automatically.', 'menu-popup' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Screenshot', 'menu-popup' ); ?></th>
                <td>
                    <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_PREFIX . 'require_image' ); ?>" value="1" <?php checked( self::require_image() ); ?>> <?php esc_html_e( 'Customer must upload a screenshot of the transfer (the transaction number is always required)', 'menu-popup' ); ?></label>
                </td>
            </tr>
        </table>

        <?php foreach ( $defs as $id => $def ) :
            $c      = self::transfer_config( $id );
            $prefix = self::OPTION_PREFIX . $id . '_';
            ?>
            <div class="mp-transfer-method" data-method="<?php echo esc_attr( $id ); ?>" style="border:1px solid #dcdcde;border-radius:6px;padding:4px 16px;margin:12px 0;background:#fff;">
                <h4 style="margin:12px 0;">
                    <label>
                        <input type="checkbox" class="mp-transfer-toggle" name="<?php echo esc_attr( $prefix . 'enabled' ); ?>" value="1" <?php checked( $c['enabled'] ); ?>>
                        <?php echo esc_html( $def['icon'] . ' ' . $def['label'] . ' / ' . $def['label_ar'] ); ?>
                    </label>
                </h4>
                <table class="form-table mp-transfer-fields" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html( $def['account_label'] ); ?></th>
                        <td><input type="text" name="<?php echo esc_attr( $prefix . 'account' ); ?>" value="<?php echo esc_attr( $c['account'] ); ?>" class="regular-text" dir="ltr"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Account holder name', 'menu-popup' ); ?></th>
                        <td><input type="text" name="<?php echo esc_attr( $prefix . 'holder' ); ?>" value="<?php echo esc_attr( $c['holder'] ); ?>" class="regular-text"></td>
                    </tr>
                    <?php if ( $def['has_link'] ) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'InstaPay payment link', 'menu-popup' ); ?></th>
                        <td>
                            <input type="url" name="<?php echo esc_attr( $prefix . 'link' ); ?>" value="<?php echo esc_attr( $c['link'] ); ?>" class="regular-text" dir="ltr" placeholder="https://ipn.eg/S/...">
                            <p class="description"><?php esc_html_e( 'Optional. Opens the InstaPay app on the customer\'s phone.', 'menu-popup' ); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'QR code image', 'menu-popup' ); ?></th>
                        <td>
                            <input type="url" name="<?php echo esc_attr( $prefix . 'qr' ); ?>" value="<?php echo esc_attr( $c['qr'] ); ?>" class="regular-text mp-qr-url" dir="ltr">
                            <button type="button" class="button mp-qr-pick"><?php esc_html_e( 'Choose image', 'menu-popup' ); ?></button>
                            <p class="description"><?php esc_html_e( 'Optional.', 'menu-popup' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Extra instructions', 'menu-popup' ); ?></th>
                        <td><textarea name="<?php echo esc_attr( $prefix . 'instructions' ); ?>" rows="2" class="large-text" dir="auto"><?php echo esc_textarea( $c['instructions'] ); ?></textarea></td>
                    </tr>
                </table>
            </div>
        <?php endforeach; ?>

        <script>
        jQuery(function ($) {
            function sync() {
                $('.mp-transfer-method').each(function () {
                    $(this).find('.mp-transfer-fields').toggle($(this).find('.mp-transfer-toggle').is(':checked'));
                });
            }
            $(document).on('change', '.mp-transfer-toggle', sync);
            sync();

            $(document).on('click', '.mp-qr-pick', function (e) {
                e.preventDefault();
                if (!window.wp || !wp.media) { return; }
                var $input = $(this).siblings('.mp-qr-url');
                var frame = wp.media({ title: 'QR', multiple: false, library: { type: 'image' } });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    $input.val(att.url);
                });
                frame.open();
            });
        });
        </script>
        <?php
    }

    /**
     * Save the transfer settings posted with the Payment form.
     */
    public static function save_from_request( array $post ) {
        $p = self::OPTION_PREFIX;

        if ( isset( $post[ $p . 'deadline' ] ) ) {
            update_option( $p . 'deadline', max( 5, min( 240, absint( $post[ $p . 'deadline' ] ) ) ) );
        }
        update_option( $p . 'require_image', ( isset( $post[ $p . 'require_image' ] ) && $post[ $p . 'require_image' ] === '1' ) ? '1' : '0' );

        foreach ( self::transfer_definitions() as $id => $def ) {
            $k = $p . $id . '_';
            update_option( $k . 'enabled', ( isset( $post[ $k . 'enabled' ] ) && $post[ $k . 'enabled' ] === '1' ) ? '1' : '0' );
            update_option( $k . 'account', sanitize_text_field( wp_unslash( $post[ $k . 'account' ] ?? '' ) ) );
            update_option( $k . 'holder', sanitize_text_field( wp_unslash( $post[ $k . 'holder' ] ?? '' ) ) );
            if ( $def['has_link'] ) {
                update_option( $k . 'link', esc_url_raw( wp_unslash( $post[ $k . 'link' ] ?? '' ) ) );
            }
            update_option( $k . 'qr', esc_url_raw( wp_unslash( $post[ $k . 'qr' ] ?? '' ) ) );
            update_option( $k . 'instructions', sanitize_textarea_field( wp_unslash( $post[ $k . 'instructions' ] ?? '' ) ) );
        }
    }
}
