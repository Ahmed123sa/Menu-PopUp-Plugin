<?php
/**
 * License check.
 *
 * The plugin runs only on the domain written inside a license key that the
 * author signed. The key is checked here with a public key; it can only be
 * made with the author's private key, which is never shipped with the plugin.
 * No server and no internet connection are needed.
 *
 * Local development addresses (localhost, *.local, *.test …) work without a key.
 *
 * @package MenuPopup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Menu_Popup_License {

    const OPTION     = 'menu_popup_license_key';
    const PUBLIC_KEY = '9thz5B0qd2ka6pHXgEvm4xlY0nYpdoTMeQNai++JEU4=';
    const PAGE       = 'menu-popup-license';
    const AUTHOR     = 'Ahmed Ali';

    /** @var array|null cached result for this request */
    private static $state = null;

    /**
     * Site domain the key must match: lower case, without "www.".
     */
    public static function site_domain() {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        return self::normalize( (string) $host );
    }

    public static function normalize( $host ) {
        $host = strtolower( trim( (string) $host ) );
        $host = preg_replace( '#^https?://#', '', $host );
        $host = preg_replace( '#[/:].*$#', '', $host );
        return preg_replace( '/^www\./', '', $host );
    }

    public static function is_dev_host( $host ) {
        // Testing only: treat a local site like a real one (this can only make the check stricter)
        if ( defined( 'MENU_POPUP_LICENSE_STRICT' ) && MENU_POPUP_LICENSE_STRICT ) {
            return false;
        }
        if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
            return true;
        }
        return (bool) preg_match( '/\.(local|test|localhost)$/', $host );
    }

    private static function b64d( $s ) {
        $s = strtr( (string) $s, '-_', '+/' );
        $pad = strlen( $s ) % 4;
        if ( $pad ) {
            $s .= str_repeat( '=', 4 - $pad );
        }
        return base64_decode( $s, true );
    }

    /**
     * Read a key. Returns the payload array when the signature is genuine, else null.
     */
    public static function decode( $key ) {
        $key = trim( (string) $key );
        if ( strpos( $key, 'MP1-' ) !== 0 ) {
            return null;
        }
        $parts = explode( '.', substr( $key, 4 ) );
        if ( count( $parts ) !== 2 ) {
            return null;
        }
        $payload = self::b64d( $parts[0] );
        $sig     = self::b64d( $parts[1] );
        $pub     = base64_decode( self::PUBLIC_KEY, true );
        if ( $payload === false || $sig === false || $pub === false || strlen( $sig ) !== 64 || strlen( $pub ) !== 32 ) {
            return null;
        }
        if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
            return null;
        }
        try {
            if ( ! sodium_crypto_sign_verify_detached( $sig, $payload, $pub ) ) {
                return null;
            }
        } catch ( Exception $e ) {
            return null;
        } catch ( Error $e ) {
            return null;
        }
        $data = json_decode( $payload, true );
        return ( is_array( $data ) && ! empty( $data['d'] ) ) ? $data : null;
    }

    /**
     * Check a key against this site.
     *
     * @return array { ok: bool, reason: string, data: array|null, dev: bool }
     */
    public static function check( $key, $domain = null ) {
        $domain = $domain === null ? self::site_domain() : self::normalize( $domain );
        $dev    = self::is_dev_host( $domain );
        $data   = $key !== '' ? self::decode( $key ) : null;

        if ( $data ) {
            if ( self::normalize( $data['d'] ) !== $domain ) {
                return array( 'ok' => $dev, 'reason' => 'domain', 'data' => $data, 'dev' => $dev );
            }
            if ( ! empty( $data['e'] ) && wp_date( 'Y-m-d' ) > $data['e'] ) {
                return array( 'ok' => $dev, 'reason' => 'expired', 'data' => $data, 'dev' => $dev );
            }
            return array( 'ok' => true, 'reason' => 'valid', 'data' => $data, 'dev' => $dev );
        }

        return array( 'ok' => $dev, 'reason' => $key === '' ? 'missing' : 'invalid', 'data' => null, 'dev' => $dev );
    }

    public static function state() {
        if ( self::$state === null ) {
            self::$state = self::check( (string) get_option( self::OPTION, '' ) );
        }
        return self::$state;
    }

    public static function is_active() {
        $s = self::state();
        return $s['ok'];
    }

    /**
     * Hooks. $active tells whether the rest of the plugin was loaded.
     */
    public static function init( $active ) {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), $active ? 99 : 10 );
        add_action( 'admin_post_menu_popup_license', array( __CLASS__, 'save' ) );

        if ( ! $active ) {
            add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
            // Keep pages tidy: no raw shortcode text while the plugin is locked
            add_action( 'init', array( __CLASS__, 'placeholder_shortcodes' ) );
        }
    }

    public static function menu() {
        if ( self::is_active() ) {
            add_submenu_page( 'menu-popup-dashboard', __( 'License', 'menu-popup' ), __( 'License', 'menu-popup' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
        } else {
            add_menu_page( __( 'Menu Pop-up', 'menu-popup' ), __( 'Menu Pop-up', 'menu-popup' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ), 'dashicons-lock', 30 );
        }
    }

    public static function placeholder_shortcodes() {
        $cb = function () {
            if ( current_user_can( 'manage_options' ) ) {
                return '<p style="padding:12px;border:1px solid #f0b849;background:#fff8e5">'
                    . esc_html__( 'Menu Pop-up is not activated on this site.', 'menu-popup' )
                    . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Enter license key', 'menu-popup' ) . '</a></p>';
            }
            return '';
        };
        foreach ( array( 'menu-popup', 'menu-popup-featured', 'menu_popup_reservation' ) as $tag ) {
            add_shortcode( $tag, $cb );
        }
    }

    public static function notice() {
        if ( ! current_user_can( 'manage_options' ) || ( isset( $_GET['page'] ) && $_GET['page'] === self::PAGE ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>Menu Pop-up</strong>: '
            . esc_html__( 'the plugin is not activated on this site, so it is switched off. Your data is safe.', 'menu-popup' )
            . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Enter license key', 'menu-popup' ) . '</a></p></div>';
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Not allowed', 'menu-popup' ) );
        }
        check_admin_referer( 'menu_popup_license' );

        $key = isset( $_POST['license_key'] ) ? preg_replace( '/\s+/', '', wp_unslash( $_POST['license_key'] ) ) : '';
        $msg = 'removed';

        if ( isset( $_POST['remove'] ) ) {
            delete_option( self::OPTION );
        } else {
            $res = self::check( $key );
            if ( $res['reason'] === 'valid' ) {
                $was_active = self::is_active();
                update_option( self::OPTION, $key, true );
                if ( ! $was_active ) {
                    // Setup steps that were skipped while the plugin was locked
                    update_option( 'menu_popup_pending_activation', '1' );
                }
                $msg = 'saved';
            } else {
                $msg = $res['reason'];
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&mp_license=' . rawurlencode( $msg ) ) );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $s      = self::state();
        $stored = (string) get_option( self::OPTION, '' );
        $msgs   = array(
            'saved'   => array( 'success', __( 'License activated. Thank you!', 'menu-popup' ) ),
            'removed' => array( 'warning', __( 'License key removed.', 'menu-popup' ) ),
            'domain'  => array( 'error', __( 'This key was issued for a different domain.', 'menu-popup' ) ),
            'expired' => array( 'error', __( 'This key has expired.', 'menu-popup' ) ),
            'invalid' => array( 'error', __( 'This key is not valid.', 'menu-popup' ) ),
            'missing' => array( 'error', __( 'Please paste a license key.', 'menu-popup' ) ),
        );
        $flash = isset( $_GET['mp_license'] ) ? sanitize_key( $_GET['mp_license'] ) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Menu Pop-up License', 'menu-popup' ); ?></h1>

            <?php if ( isset( $msgs[ $flash ] ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $msgs[ $flash ][0] ); ?>"><p><?php echo esc_html( $msgs[ $flash ][1] ); ?></p></div>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th><?php esc_html_e( 'Status', 'menu-popup' ); ?></th>
                    <td>
                        <?php if ( $s['reason'] === 'valid' ) : ?>
                            <strong style="color:#008a20">● <?php esc_html_e( 'Active', 'menu-popup' ); ?></strong>
                        <?php elseif ( $s['dev'] ) : ?>
                            <strong style="color:#996800">● <?php esc_html_e( 'Development site (works without a key)', 'menu-popup' ); ?></strong>
                        <?php else : ?>
                            <strong style="color:#d63638">● <?php esc_html_e( 'Not activated', 'menu-popup' ); ?></strong>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'This site', 'menu-popup' ); ?></th>
                    <td><code><?php echo esc_html( self::site_domain() ); ?></code>
                        <p class="description"><?php esc_html_e( 'Send this domain to the plugin author to receive a license key.', 'menu-popup' ); ?></p></td>
                </tr>
                <?php if ( $s['data'] && $s['reason'] === 'valid' ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'Licensed to', 'menu-popup' ); ?></th>
                        <td><?php echo esc_html( isset( $s['data']['n'] ) ? $s['data']['n'] : '' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Valid until', 'menu-popup' ); ?></th>
                        <td><?php echo esc_html( ! empty( $s['data']['e'] ) ? $s['data']['e'] : __( 'No expiry', 'menu-popup' ) ); ?></td>
                    </tr>
                <?php endif; ?>
            </table>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'menu_popup_license' ); ?>
                <input type="hidden" name="action" value="menu_popup_license">
                <p><label for="mp-license-key"><strong><?php esc_html_e( 'License key', 'menu-popup' ); ?></strong></label></p>
                <textarea id="mp-license-key" name="license_key" rows="4" class="large-text code" spellcheck="false"><?php echo esc_textarea( $stored ); ?></textarea>
                <p>
                    <?php submit_button( __( 'Activate', 'menu-popup' ), 'primary', 'activate', false ); ?>
                    <?php if ( $stored !== '' ) : ?>
                        <?php submit_button( __( 'Remove key', 'menu-popup' ), 'secondary', 'remove', false ); ?>
                    <?php endif; ?>
                </p>
            </form>

            <p class="description" style="margin-top:24px">
                <?php
                /* translators: %s: author name */
                echo esc_html( sprintf( __( 'Menu Pop-up is developed by %s. Each license is valid for one domain.', 'menu-popup' ), self::AUTHOR ) );
                ?>
            </p>
        </div>
        <?php
    }
}
