<?php
/**
 * Payment Database Handler
 * 
 * Handles all database operations for payments
 * 
 * @package MenuPopup
 * @subpackage Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Payment Database Handler Class
 */
class PaymentDatabaseHandler {
    
    /**
     * Singleton instance
     * 
     * @var PaymentDatabaseHandler
     */
    private static $instance = null;
    
    /**
     * Payments table name
     * 
     * @var string
     */
    private $payments_table;
    
    /**
     * Log table name
     * 
     * @var string
     */
    private $log_table;
    
    /**
     * Get singleton instance
     * 
     * @return PaymentDatabaseHandler
     */
    public static function get_instance(): PaymentDatabaseHandler {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->payments_table = $wpdb->prefix . 'menu_popup_payments';
        $this->log_table = $wpdb->prefix . 'menu_popup_payment_log';
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Payments table
        $payments_sql = "CREATE TABLE IF NOT EXISTS {$this->payments_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            gateway_id VARCHAR(50) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency VARCHAR(10) DEFAULT 'EGP',
            status ENUM('pending', 'processing', 'paid', 'failed', 'cancelled', 'expired', 'refunded') DEFAULT 'pending',
            transaction_id VARCHAR(100) DEFAULT NULL,
            reference_number VARCHAR(100) DEFAULT NULL,
            payment_method_code VARCHAR(50) DEFAULT NULL,
            customer_email VARCHAR(100) DEFAULT NULL,
            customer_phone VARCHAR(20) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            gateway_response LONGTEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY gateway_id (gateway_id),
            KEY status (status),
            KEY reference_number (reference_number),
            KEY transaction_id (transaction_id)
        ) $charset_collate;";
        
        // Payment log table
        $log_sql = "CREATE TABLE IF NOT EXISTS {$this->log_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id BIGINT(20) UNSIGNED DEFAULT NULL,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            gateway_id VARCHAR(50) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            event_message TEXT DEFAULT NULL,
            request_data LONGTEXT DEFAULT NULL,
            response_data LONGTEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY payment_id (payment_id),
            KEY order_id (order_id),
            KEY gateway_id (gateway_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($payments_sql);
        dbDelta($log_sql);
    }
    
    /**
     * Create a new payment record
     * 
     * @param array $data Payment data
     * @return int|false Payment ID or false on failure
     */
    public function create_payment(array $data) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->payments_table,
            [
                'order_id' => absint($data['order_id']),
                'gateway_id' => sanitize_text_field($data['gateway_id']),
                'amount' => (float) $data['amount'],
                'currency' => sanitize_text_field($data['currency'] ?? 'EGP'),
                'status' => sanitize_text_field($data['status'] ?? 'pending'),
                'transaction_id' => !empty($data['transaction_id']) ? sanitize_text_field($data['transaction_id']) : null,
                'reference_number' => !empty($data['reference_number']) ? sanitize_text_field($data['reference_number']) : null,
                'payment_method_code' => !empty($data['payment_method_code']) ? sanitize_text_field($data['payment_method_code']) : null,
                'customer_email' => !empty($data['customer_email']) ? sanitize_email($data['customer_email']) : null,
                'customer_phone' => !empty($data['customer_phone']) ? sanitize_text_field($data['customer_phone']) : null,
                'notes' => !empty($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
                'gateway_response' => !empty($data['gateway_response']) ? json_encode($data['gateway_response']) : null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Get payment by ID
     * 
     * @param int $payment_id
     * @return array|null
     */
    public function get_payment(int $payment_id): ?array {
        global $wpdb;
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->payments_table} WHERE id = %d",
                $payment_id
            ),
            ARRAY_A
        );
        
        return $result ? $this->parse_payment_record($result) : null;
    }
    
    /**
     * Get payment by order ID
     * 
     * @param int $order_id
     * @return array|null
     */
    public function get_payment_by_order_id(int $order_id): ?array {
        global $wpdb;
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->payments_table} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
                $order_id
            ),
            ARRAY_A
        );
        
        return $result ? $this->parse_payment_record($result) : null;
    }
    
    /**
     * Get payment by reference number
     * 
     * @param string $reference
     * @return array|null
     */
    public function get_payment_by_reference(string $reference): ?array {
        global $wpdb;
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->payments_table} WHERE reference_number = %s",
                $reference
            ),
            ARRAY_A
        );
        
        return $result ? $this->parse_payment_record($result) : null;
    }
    
    /**
     * Get payment by transaction ID
     * 
     * @param string $transaction_id
     * @return array|null
     */
    public function get_payment_by_transaction(string $transaction_id): ?array {
        global $wpdb;
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->payments_table} WHERE transaction_id = %s",
                $transaction_id
            ),
            ARRAY_A
        );
        
        return $result ? $this->parse_payment_record($result) : null;
    }
    
    /**
     * Update payment status
     * 
     * @param int $payment_id
     * @param string $status
     * @param array $additional_data
     * @return bool
     */
    public function update_payment_status(int $payment_id, string $status, array $additional_data = []): bool {
        global $wpdb;
        
        $update_data = [
            'status' => sanitize_text_field($status),
            'updated_at' => current_time('mysql')
        ];
        
        // Set completed_at when status is paid
        if ($status === 'paid') {
            $update_data['completed_at'] = current_time('mysql');
        }
        
        // Add additional data
        if (!empty($additional_data['transaction_id'])) {
            $update_data['transaction_id'] = sanitize_text_field($additional_data['transaction_id']);
        }
        if (!empty($additional_data['reference_number'])) {
            $update_data['reference_number'] = sanitize_text_field($additional_data['reference_number']);
        }
        if (!empty($additional_data['notes'])) {
            $update_data['notes'] = sanitize_textarea_field($additional_data['notes']);
        }
        if (!empty($additional_data['gateway_response'])) {
            $update_data['gateway_response'] = json_encode($additional_data['gateway_response']);
        }
        
        $result = $wpdb->update(
            $this->payments_table,
            $update_data,
            ['id' => $payment_id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Update payment by order ID
     * 
     * @param int $order_id
     * @param string $status
     * @param array $additional_data
     * @return bool
     */
    public function update_payment_by_order(int $order_id, string $status, array $additional_data = []): bool {
        global $wpdb;
        
        $update_data = [
            'status' => sanitize_text_field($status),
            'updated_at' => current_time('mysql')
        ];
        
        if ($status === 'paid') {
            $update_data['completed_at'] = current_time('mysql');
        }
        
        if (!empty($additional_data['transaction_id'])) {
            $update_data['transaction_id'] = sanitize_text_field($additional_data['transaction_id']);
        }
        if (!empty($additional_data['reference_number'])) {
            $update_data['reference_number'] = sanitize_text_field($additional_data['reference_number']);
        }
        if (!empty($additional_data['gateway_response'])) {
            $update_data['gateway_response'] = json_encode($additional_data['gateway_response']);
        }
        
        $result = $wpdb->update(
            $this->payments_table,
            $update_data,
            ['order_id' => $order_id],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Get payments by status
     * 
     * @param string $status
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function get_payments_by_status(string $status, int $limit = 50, int $offset = 0): array {
        global $wpdb;
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->payments_table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $status,
                $limit,
                $offset
            ),
            ARRAY_A
        );
        
        return array_map([$this, 'parse_payment_record'], $results);
    }
    
    /**
     * Get payments by gateway
     * 
     * @param string $gateway_id
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function get_payments_by_gateway(string $gateway_id, int $limit = 50, int $offset = 0): array {
        global $wpdb;
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->payments_table} WHERE gateway_id = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $gateway_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );
        
        return array_map([$this, 'parse_payment_record'], $results);
    }
    
    /**
     * Get payments by date range
     * 
     * @param string $start_date
     * @param string $end_date
     * @param string $status
     * @return array
     */
    public function get_payments_by_date_range(string $start_date, string $end_date, string $status = ''): array {
        global $wpdb;
        
        $query = "SELECT * FROM {$this->payments_table} WHERE created_at BETWEEN %s AND %s";
        $params = [$start_date, $end_date];
        
        if (!empty($status)) {
            $query .= " AND status = %s";
            $params[] = $status;
        }
        
        $query .= " ORDER BY created_at DESC";
        
        $results = $wpdb->get_results(
            $wpdb->prepare($query, $params),
            ARRAY_A
        );
        
        return array_map([$this, 'parse_payment_record'], $results);
    }
    
    /**
     * Get payment statistics
     * 
     * @param string $start_date
     * @param string $end_date
     * @param string $gateway_id
     * @return array
     */
    public function get_statistics(string $start_date, string $end_date, string $gateway_id = ''): array {
        global $wpdb;
        
        $query = "SELECT 
            gateway_id,
            status,
            COUNT(*) as count,
            SUM(amount) as total_amount
          FROM {$this->payments_table}
          WHERE created_at BETWEEN %s AND %s";
        $params = [$start_date, $end_date];
        
        if (!empty($gateway_id)) {
            $query .= " AND gateway_id = %s";
            $params[] = $gateway_id;
        }
        
        $query .= " GROUP BY gateway_id, status";
        
        $results = $wpdb->get_results(
            $wpdb->prepare($query, $params),
            ARRAY_A
        );
        
        $statistics = [
            'total_orders' => 0,
            'total_revenue' => 0,
            'by_gateway' => [],
            'by_status' => []
        ];
        
        foreach ($results as $row) {
            $gateway = $row['gateway_id'];
            $status = $row['status'];
            
            if (!isset($statistics['by_gateway'][$gateway])) {
                $statistics['by_gateway'][$gateway] = [
                    'count' => 0,
                    'total' => 0
                ];
            }
            
            $statistics['by_gateway'][$gateway]['count'] += (int) $row['count'];
            $statistics['by_gateway'][$gateway]['total'] += (float) $row['total_amount'];
            
            if (!isset($statistics['by_status'][$status])) {
                $statistics['by_status'][$status] = [
                    'count' => 0,
                    'total' => 0
                ];
            }
            
            $statistics['by_status'][$status]['count'] += (int) $row['count'];
            $statistics['by_status'][$status]['total'] += (float) $row['total_amount'];
            
            $statistics['total_orders'] += (int) $row['count'];
            $statistics['total_revenue'] += (float) $row['total_amount'];
        }
        
        return $statistics;
    }
    
    /**
     * Check if order has pending payment
     * 
     * @param int $order_id
     * @return bool
     */
    public function has_pending_payment(int $order_id): bool {
        global $wpdb;
        
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->payments_table} WHERE order_id = %d AND status = 'pending'",
                $order_id
            )
        );
        
        return (int) $count > 0;
    }
    
    /**
     * Delete payment records for an order
     * 
     * @param int $order_id
     * @return bool
     */
    public function delete_payments_by_order(int $order_id): bool {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->payments_table,
            ['order_id' => $order_id],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Log payment event
     * 
     * @param array $data
     * @return int|false
     */
    public function log_event(array $data): int|false {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->log_table,
            [
                'payment_id' => !empty($data['payment_id']) ? absint($data['payment_id']) : null,
                'order_id' => absint($data['order_id']),
                'gateway_id' => sanitize_text_field($data['gateway_id']),
                'event_type' => sanitize_text_field($data['event_type']),
                'event_message' => !empty($data['message']) ? sanitize_textarea_field($data['message']) : null,
                'request_data' => !empty($data['request']) ? json_encode($data['request']) : null,
                'response_data' => !empty($data['response']) ? json_encode($data['response']) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => current_time('mysql')
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Get payment logs
     * 
     * @param int $order_id
     * @param int $limit
     * @return array
     */
    public function get_logs(int $order_id, int $limit = 100): array {
        global $wpdb;
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->log_table} WHERE order_id = %d ORDER BY created_at DESC LIMIT %d",
                $order_id,
                $limit
            ),
            ARRAY_A
        );
        
        return $results;
    }
    
    /**
     * Get all payments with pagination
     * 
     * @param int $page
     * @param int $per_page
     * @param string $status
     * @param string $gateway
     * @return array
     */
    public function get_all_payments(int $page = 1, int $per_page = 20, string $status = '', string $gateway = ''): array {
        global $wpdb;
        
        $offset = ($page - 1) * $per_page;
        
        $query = "SELECT * FROM {$this->payments_table} WHERE 1=1";
        $count_query = "SELECT COUNT(*) FROM {$this->payments_table} WHERE 1=1";
        $params = [];
        
        if (!empty($status)) {
            $query .= " AND status = %s";
            $count_query .= " AND status = %s";
            $params[] = $status;
        }
        
        if (!empty($gateway)) {
            $query .= " AND gateway_id = %s";
            $count_query .= " AND gateway_id = %s";
            $params[] = $gateway;
        }
        
        $query .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        
        $total = $wpdb->get_var($wpdb->prepare($count_query, array_slice($params, 0, -2)));
        $results = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);
        
        return [
            'data' => array_map([$this, 'parse_payment_record'], $results),
            'total' => (int) $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page
        ];
    }
    
    /**
     * Parse payment record
     * 
     * @param array $record
     * @return array
     */
    private function parse_payment_record(array $record): array {
        if (!empty($record['gateway_response'])) {
            $record['gateway_response'] = json_decode($record['gateway_response'], true);
        }
        
        return $record;
    }
    
    /**
     * Get total payments count
     * 
     * @return int
     */
    public function get_total_count(): int {
        global $wpdb;
        
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->payments_table}");
    }
    
    /**
     * Get pending payments count
     * 
     * @return int
     */
    public function get_pending_count(): int {
        global $wpdb;
        
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->payments_table} WHERE status = 'pending'"
            )
        );
    }
}
