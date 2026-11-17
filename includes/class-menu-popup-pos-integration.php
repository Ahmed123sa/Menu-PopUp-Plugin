<?php
/**
 * POS Integration Class
 * Handles sending orders to Point of Sale systems
 */

if (!defined('ABSPATH')) {
    exit;
}

class Menu_Popup_POS_Integration {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Hook to send order to POS when order is created
        add_action('menu_popup_send_to_pos', array($this, 'send_order_to_pos'), 10, 1);
        
        // Register AJAX handler for manual POS resend
        add_action('wp_ajax_menu_popup_resend_to_pos', array($this, 'ajax_resend_order_to_pos'));
    }

    /**
     * Send order to POS system
     */
    public function send_order_to_pos($order_data) {
        $pos_enabled = get_option('menu_popup_pos_enabled', '0');
        $pos_type = get_option('menu_popup_pos_type', 'gloriafood');
        
        if ($pos_enabled !== '1') {
            return;
        }

        // Log the attempt
        $this->log_pos_activity('info', 'Attempting to send order to POS', array(
            'order_number' => $order_data['order_number'],
            'pos_type' => $pos_type
        ));

        try {
            switch ($pos_type) {
                case 'gloriafood':
                    $result = $this->send_to_gloriafood($order_data);
                    break;
                case 'custom':
                    $result = $this->send_to_custom_api($order_data);
                    break;
                default:
                    $result = new WP_Error('invalid_pos', 'Invalid POS system type');
            }

            if (is_wp_error($result)) {
                $this->log_pos_activity('error', 'Failed to send order to POS', array(
                    'order_number' => $order_data['order_number'],
                    'error' => $result->get_error_message()
                ));
                
                // Store failure in order meta for later retry
                update_post_meta($order_data['order_id'], '_pos_send_error', $result->get_error_message());
                update_post_meta($order_data['order_id'], '_pos_send_status', 'failed');
            } else {
                $this->log_pos_activity('success', 'Order sent to POS successfully', array(
                    'order_number' => $order_data['order_number'],
                    'pos_response' => $result
                ));
                
                // Store success in order meta
                update_post_meta($order_data['order_id'], '_pos_send_status', 'sent');
                update_post_meta($order_data['order_id'], '_pos_response', json_encode($result));
                
                // Check if auto-accept is enabled
                $pos_auto_accept = get_option('menu_popup_pos_auto_accept', '0');
                if ($pos_auto_accept === '1') {
                    // Auto-accept the order in our system
                    update_post_meta($order_data['order_id'], '_order_status', 'confirmed');
                }
            }

        } catch (Exception $e) {
            $this->log_pos_activity('error', 'Exception sending order to POS', array(
                'order_number' => $order_data['order_number'],
                'exception' => $e->getMessage()
            ));
            
            update_post_meta($order_data['order_id'], '_pos_send_error', $e->getMessage());
            update_post_meta($order_data['order_id'], '_pos_send_status', 'error');
        }
    }

    /**
     * Send order to GloriaFood POS
     */
    private function send_to_gloriafood($order_data) {
        $api_key = get_option('menu_popup_pos_api_key', '');
        $location_id = get_option('menu_popup_pos_location_id', '');
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'GloriaFood API key not configured');
        }

        // GloriaFood API endpoint (example - actual endpoint may vary)
        $api_url = 'https://api.gloriafood.com/v1/orders';
        
        // Prepare order data for GloriaFood format
        $items = array();
        foreach ($order_data['items'] as $item) {
            $items[] = array(
                'name' => $item['name'],
                'quantity' => intval($item['quantity']),
                'price' => floatval($item['price']),
                'notes' => isset($item['notes']) ? $item['notes'] : ''
            );
        }

        // Build GloriaFood order payload
        $payload = array(
            'order' => array(
                'reference' => $order_data['order_number'],
                'client' => array(
                    'name' => $order_data['customer_name'],
                    'phone' => $order_data['customer_phone'],
                    'email' => !empty($order_data['customer_email']) ? $order_data['customer_email'] : '',
                    'address' => !empty($order_data['customer_address']) ? $order_data['customer_address'] : ''
                ),
                'items' => $items,
                'total' => floatval($order_data['total']),
                'note' => !empty($order_data['special_instructions']) ? $order_data['special_instructions'] : ''
            )
        );

        // Add location if specified
        if (!empty($location_id)) {
            $payload['order']['location_id'] = $location_id;
        }

        // Send to API
        $response = wp_remote_post($api_url, array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'Accept' => 'application/json'
            ),
            'body' => json_encode($payload),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            return json_decode($body, true);
        } else {
            return new WP_Error('api_error', 'GloriaFood API error: ' . $response_code . ' - ' . $body);
        }
    }

    /**
     * Send order to custom API endpoint
     */
    private function send_to_custom_api($order_data) {
        $api_url = get_option('menu_popup_pos_api_key', '');
        
        if (empty($api_url)) {
            return new WP_Error('no_api_url', 'Custom API URL not configured');
        }

        // Validate URL
        if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'Invalid API URL');
        }

        // Prepare order data - use a standard format that can be adapted
        $payload = array(
            'order_id' => $order_data['order_id'],
            'order_number' => $order_data['order_number'],
            'customer' => array(
                'name' => $order_data['customer_name'],
                'phone' => $order_data['customer_phone'],
                'email' => !empty($order_data['customer_email']) ? $order_data['customer_email'] : '',
                'address' => !empty($order_data['customer_address']) ? $order_data['customer_address'] : ''
            ),
            'items' => $order_data['items'],
            'totals' => array(
                'subtotal' => floatval($order_data['subtotal']),
                'tax' => floatval($order_data['tax']),
                'total' => floatval($order_data['total'])
            ),
            'currency' => $order_data['currency_symbol'],
            'special_instructions' => !empty($order_data['special_instructions']) ? $order_data['special_instructions'] : '',
            'created_at' => current_time('mysql')
        );

        // Send to custom API
        $response = wp_remote_post($api_url, array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($payload),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            $decoded = json_decode($body, true);
            return !empty($decoded) ? $decoded : array('success' => true);
        } else {
            return new WP_Error('api_error', 'Custom API error: ' . $response_code . ' - ' . $body);
        }
    }

    /**
     * AJAX handler to manually resend order to POS
     */
    public function ajax_resend_order_to_pos() {
        check_ajax_referer('menu_popup_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'menu-popup'));
        }

        $order_id = intval($_POST['order_id']);
        
        if (!$order_id) {
            wp_send_json_error(__('Invalid order ID', 'menu-popup'));
        }

        // Get order data from database
        $order_data = array(
            'order_id' => $order_id,
            'order_number' => get_post_meta($order_id, '_order_number', true),
            'customer_name' => get_post_meta($order_id, '_customer_name', true),
            'customer_phone' => get_post_meta($order_id, '_customer_phone', true),
            'customer_email' => get_post_meta($order_id, '_customer_email', true),
            'customer_address' => get_post_meta($order_id, '_customer_address', true),
            'items' => json_decode(get_post_meta($order_id, '_order_items', true), true),
            'subtotal' => get_post_meta($order_id, '_order_subtotal', true),
            'tax' => get_post_meta($order_id, '_order_tax', true),
            'total' => get_post_meta($order_id, '_order_total', true),
            'special_instructions' => get_post_meta($order_id, '_special_instructions', true),
            'currency_symbol' => get_post_meta($order_id, '_currency_symbol', true)
        );

        // Send to POS
        $this->send_order_to_pos($order_data);
        
        // Get updated status
        $pos_status = get_post_meta($order_id, '_pos_send_status', true);
        $pos_error = get_post_meta($order_id, '_pos_send_error', true);
        
        if ($pos_status === 'sent') {
            wp_send_json_success(array(
                'message' => __('Order sent to POS successfully', 'menu-popup'),
                'pos_status' => $pos_status
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to send order to POS', 'menu-popup'),
                'error' => $pos_error,
                'pos_status' => $pos_status
            ));
        }
    }

    /**
     * Log POS activity for debugging
     */
    private function log_pos_activity($level, $message, $data = array()) {
        // Get existing logs
        $logs = get_option('menu_popup_pos_logs', array());
        
        // Add new log entry
        $logs[] = array(
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'data' => $data
        );
        
        // Keep only last 100 entries
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }
        
        update_option('menu_popup_pos_logs', $logs);
        
        // Also log to error log for critical errors
        if ($level === 'error') {
            error_log('Menu Popup POS Error: ' . $message . ' - ' . json_encode($data));
        }
    }

    /**
     * Get POS logs for admin display
     */
    public static function get_pos_logs($limit = 50) {
        $logs = get_option('menu_popup_pos_logs', array());
        return array_slice($logs, -$limit);
    }

    /**
     * Clear POS logs
     */
    public static function clear_pos_logs() {
        delete_option('menu_popup_pos_logs');
    }
}

// Initialize the POS integration
function menu_popup_pos_integration() {
    return Menu_Popup_POS_Integration::get_instance();
}

// Initialize on plugins_loaded
add_action('plugins_loaded', 'menu_popup_pos_integration');
