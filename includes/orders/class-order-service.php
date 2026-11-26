<?php
/**
 * Order Service
 *
 * Single entry point for creating orders. The browser only sends what the
 * customer chose (item ids, sizes, quantities, contact details). Every price,
 * fee and total is calculated here from the database, so a modified request
 * can never change what an order costs.
 *
 * AJAX action: mp_place_order
 *
 * @package    MenuPopup
 * @subpackage Orders
 */

if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_Order_Service {

    const MAX_LINES = 50;
    const MAX_QTY   = 99;

    /**
     * While true, items with no price (or 0) can be ordered as free items.
     * Set to false once every item has a real price.
     */
    const ALLOW_ZERO_PRICE = true;

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_mp_place_order', array($this, 'handle_place_order'));
        add_action('wp_ajax_nopriv_mp_place_order', array($this, 'handle_place_order'));
    }

    // ---------------------------------------------------------------------
    // AJAX
    // ---------------------------------------------------------------------

    public function handle_place_order() {
        if (!check_ajax_referer('menu_popup_nonce', 'nonce', false)) {
            wp_send_json_error(array(
                'code'    => 'security',
                'message' => __('Your session has expired. Please refresh the page and try again.', 'menu-popup'),
            ));
        }

        $input  = (isset($_POST['order']) && is_array($_POST['order'])) ? wp_unslash($_POST['order']) : array();
        $result = $this->create_order($input);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'data'    => $result->get_error_data(),
            ));
        }

        wp_send_json_success($result);
    }

    // ---------------------------------------------------------------------
    // Core
    // ---------------------------------------------------------------------

    /**
     * Validate, price and store an order.
     *
     * @param array $in Raw (unslashed) request data.
     * @return array|WP_Error Order summary on success.
     */
    public function create_order(array $in) {
        // A repeated request (double click, retry after a dropped connection)
        // returns the order that was already created instead of making a second one.
        $request_key = '';
        $request_id  = isset($in['request_id']) ? preg_replace('/[^A-Za-z0-9\-]/', '', (string) $in['request_id']) : '';
        if (strlen($request_id) >= 8 && strlen($request_id) <= 64) {
            $request_key = 'mp_order_req_' . md5($request_id);
            $cached      = get_transient($request_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // --- source & feature switches ------------------------------------
        $source = (isset($in['source']) && $in['source'] === 'whatsapp') ? 'whatsapp' : 'online';

        if ($source === 'online' && get_option('menu_popup_ordering_enabled', '0') !== '1') {
            return new WP_Error('ordering_disabled', __('Online ordering is currently unavailable.', 'menu-popup'));
        }
        if ($source === 'whatsapp' && get_option('menu_popup_whatsapp_enabled', '0') !== '1') {
            return new WP_Error('ordering_disabled', __('WhatsApp ordering is currently unavailable.', 'menu-popup'));
        }

        $order_type = 'pickup';
        if (isset($in['order_type'])) {
            if ($in['order_type'] === 'delivery') {
                $order_type = 'delivery';
            } elseif ($in['order_type'] === 'dine_in') {
                $order_type = 'dine_in';
            }
        }
        if ($order_type === 'delivery' && get_option('menu_popup_delivery_enabled', '0') !== '1') {
            return new WP_Error('delivery_disabled', __('Delivery is not available.', 'menu-popup'));
        }

        // Ordering from a table: the signed table code decides the table and the branch
        $table = null;
        if ($order_type === 'dine_in') {
            if ($source !== 'online' || !class_exists('Menu_Popup_Table_Ordering')) {
                return new WP_Error('table_disabled', __('Ordering from the table is not available.', 'menu-popup'));
            }
            $table = Menu_Popup_Table_Ordering::resolve(
                isset($in['table_id']) ? absint($in['table_id']) : 0,
                isset($in['table_sig']) ? sanitize_text_field((string) $in['table_sig']) : ''
            );
            if (is_wp_error($table)) {
                return $table;
            }
            $in['branch_id'] = $table['branch_id'];
        }

        // --- branch -------------------------------------------------------
        $branch_id = isset($in['branch_id']) ? absint($in['branch_id']) : 0;
        if ($branch_id > 0 && (get_post_type($branch_id) !== 'menu_popup_branch' || get_post_status($branch_id) !== 'publish')) {
            return new WP_Error('invalid_branch', __('The selected branch is not available.', 'menu-popup'));
        }

        // --- items --------------------------------------------------------
        $lines = $this->build_lines(isset($in['items']) ? $in['items'] : array(), $branch_id);
        if (is_wp_error($lines)) {
            return $lines;
        }

        list($subtotal, $tax) = $this->compute_totals($lines);

        // --- customer -----------------------------------------------------
        $customer = $this->read_customer($in, $order_type === 'dine_in');
        if (is_wp_error($customer)) {
            return $customer;
        }

        // --- delivery -----------------------------------------------------
        $delivery = array(
            'zone_id'   => 0,
            'zone_name' => '',
            'fee'       => 0.0,
            'lat'       => '',
            'lng'       => '',
            'details'   => '',
            'distance'  => '',
            'eta'       => 0,
        );

        if ($order_type === 'delivery') {
            if (get_option('menu_popup_customer_address_enabled', '0') === '1' && $customer['address'] === '') {
                return new WP_Error('missing_address', __('Please enter your delivery address.', 'menu-popup'));
            }

            $has_pin = isset($in['delivery_lat'], $in['delivery_lng']) && is_numeric($in['delivery_lat']) && is_numeric($in['delivery_lng'])
                && (float) $in['delivery_lat'] >= -90 && (float) $in['delivery_lat'] <= 90
                && (float) $in['delivery_lng'] >= -180 && (float) $in['delivery_lng'] <= 180;

            if ($this->uses_map_delivery()) {
                // The pin decides the branch, zone and fee. Nothing the browser says about them is trusted.
                if (!$has_pin) {
                    return new WP_Error('location_required', __('Please pin your delivery location on the map.', 'menu-popup'));
                }

                $lat = (float) $in['delivery_lat'];
                $lng = (float) $in['delivery_lng'];

                $quote = Menu_Popup_Delivery_Resolver::resolve($lat, $lng, $subtotal + $tax, $branch_id);
                if (empty($quote['ok'])) {
                    return new WP_Error('outside_area', $quote['message']);
                }

                // The address can belong to another branch than the one the menu was opened from
                if ($quote['branch_id'] > 0 && $quote['branch_id'] !== $branch_id) {
                    $branch_id = $quote['branch_id'];
                    $lines     = $this->lines_for_new_branch($in, $lines, $branch_id);
                    if (is_wp_error($lines)) {
                        return $lines;
                    }
                    list($subtotal, $tax) = $this->compute_totals($lines);

                    $quote = Menu_Popup_Delivery_Resolver::resolve($lat, $lng, $subtotal + $tax, $branch_id);
                    if (empty($quote['ok'])) {
                        return new WP_Error('outside_area', $quote['message']);
                    }
                }

                if ($quote['min_order'] > 0 && ($subtotal + $tax) < $quote['min_order']) {
                    $symbol = (string) get_option('menu_popup_currency_symbol', '$');
                    return new WP_Error('min_order', sprintf(
                        /* translators: 1: currency symbol, 2: minimum order, 3: current total */
                        __('Minimum order for your area is %1$s%2$s. Your current total is %1$s%3$s.', 'menu-popup'),
                        $symbol,
                        number_format($quote['min_order'], 2, '.', ''),
                        number_format($subtotal + $tax, 2, '.', '')
                    ));
                }

                $delivery['zone_id']   = $quote['zone_id'];
                $delivery['zone_name'] = $quote['zone_name'];
                $delivery['fee']       = $quote['fee'];
                $delivery['distance']  = (string) $quote['distance_km'];
                $delivery['eta']       = $quote['eta'];
                $delivery['lat']       = (string) round($lat, 7);
                $delivery['lng']       = (string) round($lng, 7);
            } else {
                // Map delivery is off or no zones exist: the older zone-by-id path
                $zone = $this->resolve_zone(isset($in['delivery_zone']) ? absint($in['delivery_zone']) : 0, $branch_id, $subtotal, $tax);
                if (is_wp_error($zone)) {
                    return $zone;
                }
                $delivery['zone_id']   = $zone['id'];
                $delivery['zone_name'] = $zone['name'];
                $delivery['fee']       = $zone['fee'];
                if ($branch_id === 0 && $zone['branch_id'] > 0) {
                    $branch_id = $zone['branch_id'];

                    // The zone belongs to a branch: price the cart at that branch
                    if (class_exists('Menu_Popup_Pricing') && Menu_Popup_Pricing::is_active()) {
                        $lines = $this->lines_for_new_branch($in, $lines, $branch_id);
                        if (is_wp_error($lines)) {
                            return $lines;
                        }
                        list($subtotal, $tax) = $this->compute_totals($lines);

                        $zone = $this->resolve_zone(isset($in['delivery_zone']) ? absint($in['delivery_zone']) : 0, $branch_id, $subtotal, $tax);
                        if (is_wp_error($zone)) {
                            return $zone;
                        }
                        $delivery['fee'] = $zone['fee'];
                    }
                }

                if ($has_pin) {
                    $delivery['lat'] = (string) round((float) $in['delivery_lat'], 7);
                    $delivery['lng'] = (string) round((float) $in['delivery_lng'], 7);
                }
            }
            if (isset($in['address_details'])) {
                $delivery['details'] = $this->clip(sanitize_text_field((string) $in['address_details']), 200);
            }
        }

        // --- store-wide minimum order (delivery and pickup; a table order has no minimum) ---
        if ($order_type !== 'dine_in') {
            $store_min = (float) get_option('menu_popup_minimum_order', '0');
            if ($store_min > 0 && ($subtotal + $tax) < $store_min) {
                $symbol = (string) get_option('menu_popup_currency_symbol', '$');
                return new WP_Error('min_order', sprintf(
                    /* translators: 1: currency symbol, 2: minimum order, 3: current total */
                    __('The minimum order is %1$s%2$s. Your current total is %1$s%3$s.', 'menu-popup'),
                    $symbol,
                    number_format($store_min, 2, '.', ''),
                    number_format($subtotal + $tax, 2, '.', '')
                ));
            }
        }

        $total = round($subtotal + $tax + $delivery['fee'], 2);

        // --- payment method -------------------------------------------------
        // Only methods offered right now are accepted. With the payment system
        // off there is no payment step and the order is taken as before.
        $payment_method = '';
        $payment_kind   = '';
        if ($order_type === 'dine_in') {
            // At a table the choices are the table's own (cash / card machine, or pay at the table, plus transfers)
            $wanted = !empty($in['payment_method']) ? sanitize_key((string) $in['payment_method']) : '';
            $offered = Menu_Popup_Table_Ordering::payment_methods();
            $found   = $wanted !== '' ? Menu_Popup_Table_Ordering::find_method($wanted) : (!empty($offered) ? $offered[0] : null);
            if (!$found) {
                return new WP_Error('invalid_payment', __('Please choose one of the available payment methods.', 'menu-popup'));
            }
            $payment_method = $found['id'];
            $payment_kind   = $found['kind'];
            if ($payment_kind === 'manual' && $customer['phone'] === '') {
                return new WP_Error('missing_phone', __('Please enter your phone number so we can reach you about your transfer.', 'menu-popup'));
            }
        } elseif ($source === 'online' && class_exists('Menu_Popup_Payment_Methods')) {
            $offered = Menu_Popup_Payment_Methods::available();
            if (!empty($offered)) {
                $wanted = !empty($in['payment_method']) ? sanitize_key((string) $in['payment_method']) : '';
                $found  = $wanted !== '' ? Menu_Popup_Payment_Methods::find_available($wanted) : null;
                if (!$found) {
                    return new WP_Error('invalid_payment', __('Please choose one of the available payment methods.', 'menu-popup'));
                }
                $payment_method = $found['id'];
                $payment_kind   = $found['kind'];
            }
        }
        $is_transfer    = $payment_kind === 'manual';
        $initial_status = $is_transfer ? 'awaiting_payment' : 'pending';

        // --- store -----------------------------------------------------------
        $order_id = wp_insert_post(array(
            'post_title'  => 'Order',
            'post_type'   => 'menu_order',
            'post_status' => 'publish',
            'post_date'   => current_time('mysql'),
        ), true);

        if (is_wp_error($order_id) || !$order_id) {
            return new WP_Error('save_failed', __('Failed to save order. Please try again.', 'menu-popup'));
        }

        $order_number = 'ORD-' . current_time('Ymd') . '-' . str_pad((string) $order_id, 4, '0', STR_PAD_LEFT);
        wp_update_post(array(
            'ID'         => $order_id,
            'post_title' => 'Order #' . $order_number,
        ));

        $currency = sanitize_text_field((string) get_option('menu_popup_currency_symbol', '$'));

        update_post_meta($order_id, '_order_number', $order_number);
        update_post_meta($order_id, '_customer_name', $customer['name']);
        update_post_meta($order_id, '_customer_phone', $customer['phone']);
        update_post_meta($order_id, '_customer_email', $customer['email']);
        update_post_meta($order_id, '_customer_address', $customer['address']);
        update_post_meta($order_id, '_special_instructions', $customer['instructions']);
        update_post_meta($order_id, '_order_items', wp_slash(wp_json_encode($lines)));
        update_post_meta($order_id, '_order_subtotal', $subtotal);
        update_post_meta($order_id, '_order_tax', $tax);
        update_post_meta($order_id, '_order_total', $total);
        update_post_meta($order_id, '_order_status', $initial_status);
        update_post_meta($order_id, '_currency_symbol', $currency);
        $access_key = wp_generate_password(24, false, false);
        update_post_meta($order_id, '_order_access_key', $access_key);

        update_post_meta($order_id, '_order_type', $order_type);
        update_post_meta($order_id, '_order_source', $source);
        update_post_meta($order_id, '_created_ts', time());
        if ($table !== null) {
            update_post_meta($order_id, '_table_id', $table['id']);
            update_post_meta($order_id, '_table_name', $table['name']);
        }
        update_post_meta($order_id, '_branch_id', $branch_id);
        update_post_meta($order_id, '_delivery_fee', $delivery['fee']);

        if ($order_type === 'delivery') {
            update_post_meta($order_id, '_delivery_zone_id', $delivery['zone_id']);
            update_post_meta($order_id, '_delivery_zone_name', $delivery['zone_name']);
            if ($delivery['lat'] !== '') {
                update_post_meta($order_id, '_delivery_lat', $delivery['lat']);
                update_post_meta($order_id, '_delivery_lng', $delivery['lng']);
            }
            if ($delivery['details'] !== '') {
                update_post_meta($order_id, '_delivery_address_details', $delivery['details']);
            }
            if ($delivery['distance'] !== '') {
                update_post_meta($order_id, '_delivery_distance_km', $delivery['distance']);
            }
            if ($delivery['eta'] > 0) {
                update_post_meta($order_id, '_delivery_eta_minutes', $delivery['eta']);
            }
        }

        $deadline_minutes = 0;
        if ($payment_method !== '') {
            update_post_meta($order_id, '_payment_method', $payment_method);
            if ($is_transfer) {
                $deadline_minutes = Menu_Popup_Payment_Methods::deadline_minutes();
                update_post_meta($order_id, '_payment_status', 'awaiting_proof');
                update_post_meta($order_id, '_payment_deadline', time() + $deadline_minutes * MINUTE_IN_SECONDS);
            } else {
                update_post_meta($order_id, '_payment_status', 'pending');
            }
        }

        $status_url = add_query_arg(array('mp_order' => (int) $order_id, 'k' => $access_key), home_url('/'));

        $result = array(
            'order_id'        => (int) $order_id,
            'order_number'    => $order_number,
            'status'          => $initial_status,
            'status_url'      => $status_url,
            'payment_kind'    => $payment_kind,
            'payment_deadline_minutes' => $deadline_minutes,
            'order_type'      => $order_type,
            'table_name'      => $table !== null ? $table['name'] : '',
            'source'          => $source,
            'branch_id'       => $branch_id,
            'items'           => $lines,
            'subtotal'        => $subtotal,
            'tax'             => $tax,
            'delivery_fee'    => $delivery['fee'],
            'delivery_zone'   => $delivery['zone_name'],
            'delivery_eta'    => $delivery['eta'],
            'total'           => $total,
            'currency_symbol' => $currency,
            'payment_method'  => $payment_method,
        );

        /**
         * Fires after an order has been stored.
         *
         * @param int   $order_id
         * @param array $result   Order summary (same data the browser receives).
         */
        do_action('menu_popup_order_created', $order_id, $result);

        if ($request_key !== '') {
            set_transient($request_key, $result, 10 * MINUTE_IN_SECONDS);
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // Items
    // ---------------------------------------------------------------------

    /**
     * The address belongs to another branch than the cart was priced for.
     * Re-price the cart there. When anything is unavailable or a price changed,
     * stop with branch_prices_changed so the customer can review and confirm
     * (the browser then sends confirm_branch with the branch id).
     *
     * @return array|WP_Error New lines, or the review error.
     */
    private function lines_for_new_branch(array $in, array $old_lines, $new_branch) {
        $new_branch = (int) $new_branch;
        $items      = isset($in['items']) ? $in['items'] : array();

        if (!class_exists('Menu_Popup_Pricing') || !Menu_Popup_Pricing::is_active()) {
            return $this->build_lines($items, $new_branch);
        }

        // Which cart items this branch does not sell
        $unavailable = array();
        foreach ($old_lines as $line) {
            if (!Menu_Popup_Pricing::is_available($line['id'], $new_branch)) {
                $unavailable[] = array('id' => (int) $line['id'], 'name' => $line['name']);
            }
        }

        $details = array(
            'branch_id'   => $new_branch,
            'branch_name' => (string) get_the_title($new_branch),
            'unavailable' => $unavailable,
            'prices'      => array(),
        );

        if (empty($unavailable)) {
            $new_lines = $this->build_lines($items, $new_branch);
            if (is_wp_error($new_lines)) {
                return $new_lines;
            }
            $changed = false;
            foreach ($new_lines as $i => $line) {
                $before = isset($old_lines[$i]) ? (float) $old_lines[$i]['price'] : (float) $line['price'];
                if (abs($before - (float) $line['price']) > 0.001) {
                    $changed = true;
                }
                $details['prices'][] = array(
                    'id'    => (int) $line['id'],
                    'size'  => $line['size'],
                    'price' => (float) $line['price'],
                );
            }
            $confirmed = isset($in['confirm_branch']) && absint($in['confirm_branch']) === $new_branch;
            if (!$changed || $confirmed) {
                return $new_lines;
            }
        }

        return new WP_Error(
            'branch_prices_changed',
            sprintf(
                /* translators: %s: branch name */
                __('Your address is served by %s. Prices or availability differ there. Please review your cart.', 'menu-popup'),
                $details['branch_name']
            ),
            $details
        );
    }

    /**
     * Turn the submitted cart into priced order lines.
     *
     * @return array|WP_Error
     */
    private function build_lines($items, $branch_id) {
        // Prices come from the branch the order is for
        if (class_exists('Menu_Popup_Pricing')) {
            Menu_Popup_Pricing::set_context($branch_id);
        }
        if (!is_array($items) || empty($items)) {
            return new WP_Error('empty_cart', __('Your cart is empty.', 'menu-popup'));
        }

        $merged = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id   = isset($item['id']) ? absint($item['id']) : 0;
            $size = isset($item['size']) ? sanitize_text_field((string) $item['size']) : '';
            $qty  = isset($item['quantity']) ? absint($item['quantity']) : 0;
            $name = isset($item['name']) ? $this->clip(sanitize_text_field((string) $item['name']), 150) : '';

            if ($id < 1 || $qty < 1) {
                continue;
            }

            $key = $id . '|' . $this->normalize_size($size);
            if (isset($merged[$key])) {
                $merged[$key]['quantity'] += $qty;
            } else {
                $merged[$key] = array('id' => $id, 'size' => $size, 'quantity' => $qty, 'name' => $name);
            }
        }

        if (empty($merged)) {
            return new WP_Error('empty_cart', __('Your cart is empty.', 'menu-popup'));
        }
        if (count($merged) > self::MAX_LINES) {
            return new WP_Error('too_many_items', __('Your cart has too many items.', 'menu-popup'));
        }

        $lines = array();
        foreach ($merged as $row) {
            if ($row['quantity'] > self::MAX_QTY) {
                return new WP_Error('invalid_quantity', __('Please reduce the quantity of an item in your cart.', 'menu-popup'));
            }

            $resolved = $this->resolve_item($row['id'], $row['size'], $branch_id);
            if (is_wp_error($resolved)) {
                return $resolved;
            }

            $label = $row['name'] !== '' ? $row['name'] : $resolved['label'];

            $lines[] = array(
                'id'           => $row['id'],
                'name'         => $label,
                'catalog_name' => $resolved['catalog_name'],
                'size'         => $resolved['size'],
                'quantity'     => $row['quantity'],
                'price'        => $resolved['price'],
                'line_total'   => round($resolved['price'] * $row['quantity'], 2),
            );
        }

        return $lines;
    }

    /**
     * Look up one menu item and its price.
     *
     * @return array|WP_Error
     */
    private function resolve_item($id, $size, $branch_id) {
        $unavailable = new WP_Error(
            'item_unavailable',
            __('One of the items in your cart is no longer available. Please review your cart.', 'menu-popup')
        );

        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish' || !in_array($post->post_type, array('menu_item', 'post'), true)) {
            $this->log_rejected($id, $size, $post ? 'type=' . $post->post_type . ' status=' . $post->post_status : 'post not found');
            return $unavailable;
        }
        // Regular posts only count as menu items when they carry the menu item data (legacy support).
        if ($post->post_type === 'post' && !metadata_exists('post', $id, '_menu_item_name')) {
            $this->log_rejected($id, $size, 'regular post without menu item data');
            return $unavailable;
        }

        $item_branch = get_post_meta($id, '_menu_item_branch', true);
        if ($branch_id > 0 && $item_branch !== '' && absint($item_branch) !== $branch_id) {
            $this->log_rejected($id, $size, 'branch mismatch item=' . $item_branch . ' order=' . $branch_id);
            $branch_name = (string) get_the_title($branch_id);
            $item_name   = (string) get_post_meta($id, '_menu_item_name', true);
            return new WP_Error('item_unavailable', sprintf(
                /* translators: 1: item name, 2: branch name */
                __('"%1$s" is not available at %2$s, the branch that serves your address. Please remove it from your cart or choose pickup.', 'menu-popup'),
                $item_name !== '' ? $item_name : $post->post_title,
                $branch_name !== '' ? $branch_name : __('that branch', 'menu-popup')
            ));
        }

        if ($branch_id > 0 && class_exists('Menu_Popup_Pricing') && !Menu_Popup_Pricing::is_available($id, $branch_id)) {
            $this->log_rejected($id, $size, 'switched off at branch ' . $branch_id);
            $branch_name = (string) get_the_title($branch_id);
            $item_name   = (string) get_post_meta($id, '_menu_item_name', true);
            return new WP_Error('item_unavailable', sprintf(
                /* translators: 1: item name, 2: branch name */
                __('"%1$s" is not available at %2$s. Please remove it from your cart.', 'menu-popup'),
                $item_name !== '' ? $item_name : $post->post_title,
                $branch_name !== '' ? $branch_name : __('that branch', 'menu-popup')
            ));
        }

        $catalog_name = (string) get_post_meta($id, '_menu_item_name', true);
        if ($catalog_name === '') {
            $catalog_name = $post->post_title;
        }

        $variations = get_post_meta($id, '_menu_item_sizes', true);
        $size_label = '';

        if (is_array($variations) && !empty($variations)) {
            $wanted = $this->normalize_size($size);
            $match  = null;

            if ($wanted === '') {
                if (count($variations) > 1) {
                    return new WP_Error('size_required', __('Please choose a size for every item in your cart.', 'menu-popup'));
                }
                $match = reset($variations);
            } else {
                foreach ($variations as $variation) {
                    if (is_array($variation) && isset($variation['size']) && $this->normalize_size($variation['size']) === $wanted) {
                        $match = $variation;
                        break;
                    }
                }
                if ($match === null) {
                    return new WP_Error('invalid_size', __('One of the sizes in your cart is no longer available. Please review your cart.', 'menu-popup'));
                }
            }

            $price_raw  = is_array($match) && isset($match['price']) ? $match['price'] : '';
            $size_label = is_array($match) && isset($match['size']) ? (string) $match['size'] : '';
        } else {
            $price_raw = get_post_meta($id, '_menu_item_price', true);
        }

        $price = $this->parse_price($price_raw);
        if ($price < 0 || ($price == 0 && !self::ALLOW_ZERO_PRICE)) {
            $this->log_rejected($id, $size, 'price is zero (raw=' . (is_scalar($price_raw) ? $price_raw : 'array') . ')');
            return $unavailable;
        }

        return array(
            'catalog_name' => $catalog_name,
            'size'         => $size_label,
            'price'        => round($price, 2),
            'label'        => $size_label !== '' ? $catalog_name . ' (' . $size_label . ')' : $catalog_name,
        );
    }

    // ---------------------------------------------------------------------
    // Customer & delivery
    // ---------------------------------------------------------------------

    /**
     * @return array|WP_Error
     */
    private function read_customer(array $in, $relaxed = false) {
        $name  = isset($in['customer_name']) ? $this->clip(sanitize_text_field((string) $in['customer_name']), 100) : '';
        $phone = isset($in['customer_phone']) ? sanitize_text_field((string) $in['customer_phone']) : '';
        $email = isset($in['customer_email']) ? sanitize_email((string) $in['customer_email']) : '';

        if (!$relaxed && get_option('menu_popup_customer_name_required', '1') === '1' && $name === '') {
            return new WP_Error('missing_name', __('Please enter your name.', 'menu-popup'));
        }
        if (!$relaxed && get_option('menu_popup_customer_phone_required', '1') === '1' && $phone === '') {
            return new WP_Error('missing_phone', __('Please enter your phone number.', 'menu-popup'));
        }
        // Digits (including Arabic-Indic digits), plus, dash, spaces and brackets.
        if ($phone !== '' && !preg_match('/^[\p{Nd}+\-\s()]{6,20}$/u', $phone)) {
            return new WP_Error('invalid_phone', __('Please enter a valid phone number.', 'menu-popup'));
        }
        if (!empty($in['customer_email']) && $email === '') {
            return new WP_Error('invalid_email', __('Please enter a valid email address.', 'menu-popup'));
        }

        return array(
            'name'         => $name,
            'phone'        => $phone,
            'email'        => $email,
            'address'      => isset($in['customer_address']) ? $this->clip(sanitize_textarea_field((string) $in['customer_address']), 500) : '',
            'instructions' => isset($in['special_instructions']) ? $this->clip(sanitize_textarea_field((string) $in['special_instructions']), 500) : '',
        );
    }

    /**
     * Find the delivery zone for an order and its fee.
     *
     * @return array|WP_Error
     */
    private function resolve_zone($zone_id, $branch_id, $subtotal, $tax) {
        if (!class_exists('Menu_Popup_Delivery_Zone_Model')) {
            return array('id' => 0, 'name' => '', 'fee' => 0.0, 'branch_id' => 0);
        }

        $model = new Menu_Popup_Delivery_Zone_Model();

        if ($zone_id === 0) {
            // Sites with no zones configured keep delivering with no zone and no fee.
            if (empty(Menu_Popup_Zone_Checker::get_vendor_zones($branch_id))) {
                return array('id' => 0, 'name' => '', 'fee' => 0.0, 'branch_id' => 0);
            }
            return new WP_Error('zone_required', __('Please select your delivery zone.', 'menu-popup'));
        }

        $zone = $model->get($zone_id);
        if (!$zone || (int) $zone['is_active'] !== 1) {
            return new WP_Error('invalid_zone', __('The selected delivery zone is not available.', 'menu-popup'));
        }

        // The zone must belong to the delivery mode in use, and not be paused
        $zone_is_manual = !empty($zone['is_manual']);
        if ($zone_is_manual !== (Menu_Popup_Delivery_Zone_Model::mode() === 'manual') || !empty($zone['is_paused'])) {
            return new WP_Error('invalid_zone', __('The selected delivery zone is not available.', 'menu-popup'));
        }

        $zone_branch = isset($zone['branch_id']) ? (int) $zone['branch_id'] : 0;
        if ($zone_branch > 0 && $branch_id > 0 && $zone_branch !== $branch_id) {
            return new WP_Error('invalid_zone', __('The selected delivery zone is not available for this branch.', 'menu-popup'));
        }

        $min_order = (float) $zone['minimum_order'];
        if ($min_order > 0 && ($subtotal + $tax) < $min_order) {
            $symbol = (string) get_option('menu_popup_currency_symbol', '$');
            return new WP_Error('min_order', sprintf(
                /* translators: 1: currency symbol, 2: minimum order, 3: current total */
                __('Minimum order for this delivery zone is %1$s%2$s. Your current total is %1$s%3$s.', 'menu-popup'),
                $symbol,
                number_format($min_order, 2, '.', ''),
                number_format($subtotal + $tax, 2, '.', '')
            ));
        }

        return array(
            'id'        => (int) $zone['id'],
            'name'      => (string) $zone['zone_name'],
            'fee'       => round((float) $zone['delivery_fee'], 2),
            'branch_id' => $zone_branch,
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return array [subtotal, tax]
     */
    private function compute_totals(array $lines) {
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += $line['line_total'];
        }
        $subtotal = round($subtotal, 2);

        $tax_rate = (float) get_option('menu_popup_tax_rate', '0');
        $tax_rate = max(0.0, min(100.0, $tax_rate));

        return array($subtotal, round($subtotal * $tax_rate / 100, 2));
    }

    /**
     * Map delivery applies when the setting is on and at least one zone exists.
     */
    private function uses_map_delivery() {
        if (!class_exists('Menu_Popup_Delivery_Zone_Model') || Menu_Popup_Delivery_Zone_Model::mode() !== 'map') {
            return false;
        }
        if (!class_exists('Menu_Popup_Delivery_Resolver') || !class_exists('Menu_Popup_Delivery_Zone_Model')) {
            return false;
        }

        $model = new Menu_Popup_Delivery_Zone_Model();
        return !empty($model->get_active_map_zones());
    }

    private function log_rejected($id, $size, $reason) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[menu-popup] order item rejected: id=' . $id . ' size="' . $size . '" reason: ' . $reason);
        }
    }

    private function parse_price($raw) {
        return (float) preg_replace('/[^\d.\-]/', '', (string) $raw);
    }

    private function normalize_size($size) {
        $size = preg_replace('/[\s_]+/u', ' ', trim((string) $size));
        return function_exists('mb_strtolower') ? mb_strtolower($size, 'UTF-8') : strtolower($size);
    }

    private function clip($text, $max) {
        return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
    }
}
