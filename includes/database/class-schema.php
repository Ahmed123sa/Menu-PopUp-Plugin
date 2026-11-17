<?php
/**
 * Database Schema Handler
 * 
 * Creates and manages all custom database tables for the plugin
 * 
 * @package MenuPopup
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database Schema Class
 * 
 * Handles creation and versioning of all custom tables
 */
class Menu_Popup_Database_Schema {
    
    /**
     * Database version for migrations
     */
    const DB_VERSION = '1.0.0';
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get current database version
     */
    public static function get_db_version() {
        return get_option('menu_popup_db_version', '0');
    }
    
    /**
     * Check if tables need to be created/upgraded
     */
    public static function needs_upgrade() {
        return version_compare(self::get_db_version(), self::DB_VERSION, '<');
    }
    
    /**
     * Create all database tables
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Orders table
        $orders_table = $wpdb->prefix . 'menu_popup_orders';
        $orders_sql = "CREATE TABLE {$orders_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_number VARCHAR(50) NOT NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(50) NOT NULL,
            customer_email VARCHAR(255) DEFAULT NULL,
            branch_id BIGINT(20) UNSIGNED DEFAULT NULL,
            order_type ENUM('delivery', 'pickup', 'dine_in') DEFAULT 'pickup',
            delivery_zone_id BIGINT(20) UNSIGNED DEFAULT NULL,
            delivery_fee DECIMAL(10,2) DEFAULT 0.00,
            subtotal DECIMAL(10,2) NOT NULL,
            tax_amount DECIMAL(10,2) DEFAULT 0.00,
            discount_amount DECIMAL(10,2) DEFAULT 0.00,
            total_amount DECIMAL(10,2) NOT NULL,
            status ENUM('pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled', 'refunded') DEFAULT 'pending',
            payment_method VARCHAR(50) DEFAULT NULL,
            payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
            payment_id BIGINT(20) UNSIGNED DEFAULT NULL,
            special_instructions TEXT DEFAULT NULL,
            delivery_address TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_number (order_number),
            KEY status (status),
            KEY branch_id (branch_id),
            KEY created_at (created_at),
            KEY customer_phone (customer_phone)
        ) $charset_collate;";
        
        // Order Items table
        $order_items_table = $wpdb->prefix . 'menu_popup_order_items';
        $order_items_sql = "CREATE TABLE {$order_items_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            menu_item_id BIGINT(20) UNSIGNED NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            item_name_ar VARCHAR(255) DEFAULT NULL,
            quantity INT(11) NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL,
            total_price DECIMAL(10,2) NOT NULL,
            item_options TEXT DEFAULT NULL,
            item_notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY menu_item_id (menu_item_id)
        ) $charset_collate;";
        
        // Reservations table
        $reservations_table = $wpdb->prefix . 'menu_popup_reservations';
        $reservations_sql = "CREATE TABLE {$reservations_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            reservation_number VARCHAR(50) NOT NULL,
            branch_id BIGINT(20) UNSIGNED NOT NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(50) NOT NULL,
            customer_email VARCHAR(255) DEFAULT NULL,
            party_size INT(11) NOT NULL DEFAULT 1,
            reservation_date DATE NOT NULL,
            reservation_time TIME NOT NULL,
            duration_minutes INT(11) DEFAULT 120,
            table_id BIGINT(20) UNSIGNED DEFAULT NULL,
            zone VARCHAR(50) DEFAULT NULL,
            status ENUM('pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show') DEFAULT 'pending',
            special_requests TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            confirmed_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY reservation_number (reservation_number),
            KEY branch_id (branch_id),
            KEY reservation_date (reservation_date),
            KEY status (status),
            KEY customer_phone (customer_phone)
        ) $charset_collate;";
        
        // Reservation Tables (floor plan)
        $tables_table = $wpdb->prefix . 'menu_popup_reservation_tables';
        $tables_sql = "CREATE TABLE {$tables_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id BIGINT(20) UNSIGNED NOT NULL,
            table_number VARCHAR(20) NOT NULL,
            table_name VARCHAR(100) DEFAULT NULL,
            capacity_min INT(11) NOT NULL DEFAULT 1,
            capacity_max INT(11) NOT NULL DEFAULT 4,
            zone VARCHAR(50) DEFAULT NULL,
            position_x INT(11) DEFAULT 0,
            position_y INT(11) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY branch_id (branch_id),
            KEY is_active (is_active)
        ) $charset_collate;";
        
        // Delivery Zones table
        $zones_table = $wpdb->prefix . 'menu_popup_delivery_zones';
        $zones_sql = "CREATE TABLE {$zones_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            branch_id BIGINT(20) UNSIGNED DEFAULT NULL,
            zone_name VARCHAR(100) NOT NULL,
            zone_name_ar VARCHAR(100) DEFAULT NULL,
            delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            minimum_order DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            estimated_delivery_minutes INT(11) DEFAULT 30,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY branch_id (branch_id),
            KEY is_active (is_active)
        ) $charset_collate;";
        
        // Run all table creation queries
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($orders_sql);
        dbDelta($order_items_sql);
        dbDelta($reservations_sql);
        dbDelta($tables_sql);
        dbDelta($zones_sql);
        
        // Update database version
        update_option('menu_popup_db_version', self::DB_VERSION);
        
        return true;
    }
    
    /**
     * Drop all plugin tables (for uninstall)
     */
    public function drop_tables() {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'menu_popup_orders',
            $wpdb->prefix . 'menu_popup_order_items',
            $wpdb->prefix . 'menu_popup_reservations',
            $wpdb->prefix . 'menu_popup_reservation_tables',
            $wpdb->prefix . 'menu_popup_delivery_zones'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        
        delete_option('menu_popup_db_version');
        
        return true;
    }
    
    /**
     * Get table names
     */
    public function get_table_names() {
        global $wpdb;
        
        return [
            'orders' => $wpdb->prefix . 'menu_popup_orders',
            'order_items' => $wpdb->prefix . 'menu_popup_order_items',
            'reservations' => $wpdb->prefix . 'menu_popup_reservations',
            'reservation_tables' => $wpdb->prefix . 'menu_popup_reservation_tables',
            'delivery_zones' => $wpdb->prefix . 'menu_popup_delivery_zones'
        ];
    }
}

/**
 * Order Model Class
 * 
 * Handles CRUD operations for orders
 */
class Menu_Popup_Order_Model {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'menu_popup_orders';
    }
    
    /**
     * Create a new order
     */
    public function create(array $data) {
        global $wpdb;
        
        // Generate unique order number
        $order_number = $this->generate_order_number();
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'order_number' => $order_number,
                'customer_name' => sanitize_text_field($data['customer_name']),
                'customer_phone' => sanitize_text_field($data['customer_phone']),
                'customer_email' => !empty($data['customer_email']) ? sanitize_email($data['customer_email']) : null,
                'branch_id' => !empty($data['branch_id']) ? absint($data['branch_id']) : null,
                'order_type' => sanitize_text_field($data['order_type'] ?? 'pickup'),
                'delivery_zone_id' => !empty($data['delivery_zone_id']) ? absint($data['delivery_zone_id']) : null,
                'delivery_fee' => !empty($data['delivery_fee']) ? (float) $data['delivery_fee'] : 0,
                'subtotal' => (float) $data['subtotal'],
                'tax_amount' => !empty($data['tax_amount']) ? (float) $data['tax_amount'] : 0,
                'discount_amount' => !empty($data['discount_amount']) ? (float) $data['discount_amount'] : 0,
                'total_amount' => (float) $data['total_amount'],
                'status' => 'pending',
                'payment_method' => !empty($data['payment_method']) ? sanitize_text_field($data['payment_method']) : null,
                'payment_status' => 'pending',
                'special_instructions' => !empty($data['special_instructions']) ? sanitize_textarea_field($data['special_instructions']) : null,
                'delivery_address' => !empty($data['delivery_address']) ? sanitize_textarea_field($data['delivery_address']) : null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%d', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result) {
            $order_id = $wpdb->insert_id;
            
            // Add order items if provided
            if (!empty($data['items']) && is_array($data['items'])) {
                $this->add_items($order_id, $data['items']);
            }
            
            return $order_id;
        }
        
        return false;
    }
    
    /**
     * Generate unique order number
     */
    private function generate_order_number() {
        return 'ORD-' . strtoupper(uniqid()) . '-' . date('Ymd');
    }
    
    /**
     * Add items to an order
     */
    public function add_items(int $order_id, array $items) {
        global $wpdb;
        
        $items_table = $wpdb->prefix . 'menu_popup_order_items';
        
        foreach ($items as $item) {
            $wpdb->insert(
                $items_table,
                [
                    'order_id' => $order_id,
                    'menu_item_id' => absint($item['menu_item_id']),
                    'item_name' => sanitize_text_field($item['item_name']),
                    'item_name_ar' => !empty($item['item_name_ar']) ? sanitize_text_field($item['item_name_ar']) : null,
                    'quantity' => absint($item['quantity']),
                    'unit_price' => (float) $item['unit_price'],
                    'total_price' => (float) $item['total_price'],
                    'item_options' => !empty($item['item_options']) ? json_encode($item['item_options']) : null,
                    'item_notes' => !empty($item['item_notes']) ? sanitize_textarea_field($item['item_notes']) : null
                ],
                ['%d', '%d', '%s', '%s', '%d', '%f', '%f', '%s', '%s']
            );
        }
    }
    
    /**
     * Get order by ID
     */
    public function get(int $id) {
        global $wpdb;
        
        $order = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id),
            ARRAY_A
        );
        
        if ($order) {
            $order['items'] = $this->get_items($id);
        }
        
        return $order;
    }
    
    /**
     * Get order by order number
     */
    public function get_by_number(string $order_number) {
        global $wpdb;
        
        $order = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE order_number = %s", $order_number),
            ARRAY_A
        );
        
        if ($order) {
            $order['items'] = $this->get_items($order['id']);
        }
        
        return $order;
    }
    
    /**
     * Get order items
     */
    public function get_items(int $order_id) {
        global $wpdb;
        
        $items_table = $wpdb->prefix . 'menu_popup_order_items';
        
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$items_table} WHERE order_id = %d", $order_id),
            ARRAY_A
        );
    }
    
    /**
     * Update order status
     */
    public function update_status(int $id, string $status) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            [
                'status' => sanitize_text_field($status),
                'updated_at' => current_time('mysql')
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
    }
    
    /**
     * Update payment status
     */
    public function update_payment_status(int $id, string $status, int $payment_id = null) {
        global $wpdb;
        
        $data = [
            'payment_status' => sanitize_text_field($status),
            'updated_at' => current_time('mysql')
        ];
        
        if ($payment_id !== null) {
            $data['payment_id'] = absint($payment_id);
        }
        
        return $wpdb->update(
            $this->table_name,
            $data,
            ['id' => $id],
            ['%s', '%s', '%d'],
            ['%d']
        );
    }
    
    /**
     * Get orders with filters
     */
    public function get_orders(array $args = []) {
        global $wpdb;
        
        $defaults = [
            'status' => '',
            'branch_id' => '',
            'payment_status' => '',
            'order_type' => '',
            'date_from' => '',
            'date_to' => '',
            'search' => '',
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where = [];
        $params = [];
        
        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }
        
        if (!empty($args['branch_id'])) {
            $where[] = 'branch_id = %d';
            $params[] = absint($args['branch_id']);
        }
        
        if (!empty($args['payment_status'])) {
            $where[] = 'payment_status = %s';
            $params[] = $args['payment_status'];
        }
        
        if (!empty($args['order_type'])) {
            $where[] = 'order_type = %s';
            $params[] = $args['order_type'];
        }
        
        if (!empty($args['date_from'])) {
            $where[] = 'created_at >= %s';
            $params[] = $args['date_from'];
        }
        
        if (!empty($args['date_to'])) {
            $where[] = 'created_at <= %s';
            $params[] = $args['date_to'];
        }
        
        if (!empty($args['search'])) {
            $where[] = '(order_number LIKE %s OR customer_name LIKE %s OR customer_phone LIKE %s)';
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $allowed_orderby = ['id', 'order_number', 'status', 'total_amount', 'created_at'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        
        $query = "SELECT * FROM {$this->table_name} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params[] = absint($args['limit']);
        $params[] = absint($args['offset']);
        
        return $wpdb->get_results(
            $wpdb->prepare($query, $params),
            ARRAY_A
        );
    }
    
    /**
     * Get order count
     */
    public function count(array $args = []) {
        global $wpdb;
        
        $where = [];
        $params = [];
        
        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }
        
        if (!empty($args['branch_id'])) {
            $where[] = 'branch_id = %d';
            $params[] = absint($args['branch_id']);
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} {$where_sql}", $params)
        );
    }
}

/**
 * Reservation Model Class
 */
class Menu_Popup_Reservation_Model {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'menu_popup_reservations';
    }
    
    /**
     * Create a new reservation
     */
    public function create(array $data) {
        global $wpdb;
        
        $reservation_number = $this->generate_reservation_number();
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'reservation_number' => $reservation_number,
                'branch_id' => absint($data['branch_id']),
                'customer_name' => sanitize_text_field($data['customer_name']),
                'customer_phone' => sanitize_text_field($data['customer_phone']),
                'customer_email' => !empty($data['customer_email']) ? sanitize_email($data['customer_email']) : null,
                'party_size' => absint($data['party_size']),
                'reservation_date' => sanitize_text_field($data['reservation_date']),
                'reservation_time' => sanitize_text_field($data['reservation_time']),
                'duration_minutes' => !empty($data['duration_minutes']) ? absint($data['duration_minutes']) : 120,
                'table_id' => !empty($data['table_id']) ? absint($data['table_id']) : null,
                'zone' => !empty($data['zone']) ? sanitize_text_field($data['zone']) : null,
                'status' => 'pending',
                'special_requests' => !empty($data['special_requests']) ? sanitize_textarea_field($data['special_requests']) : null,
                'created_at' => current_time('mysql')
            ],
            ['%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Generate reservation number
     */
    private function generate_reservation_number() {
        return 'RES-' . strtoupper(uniqid()) . '-' . date('Ymd');
    }
    
    /**
     * Get reservation by ID
     */
    public function get(int $id) {
        global $wpdb;
        
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id),
            ARRAY_A
        );
    }
    
    /**
     * Get reservation by number
     */
    public function get_by_number(string $number) {
        global $wpdb;
        
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE reservation_number = %s", $number),
            ARRAY_A
        );
    }
    
    /**
     * Update reservation status
     */
    public function update_status(int $id, string $status) {
        global $wpdb;
        
        $data = [
            'status' => sanitize_text_field($status),
            'updated_at' => current_time('mysql')
        ];
        
        if ($status === 'confirmed') {
            $data['confirmed_at'] = current_time('mysql');
        } elseif ($status === 'cancelled') {
            $data['cancelled_at'] = current_time('mysql');
        }
        
        return $wpdb->update(
            $this->table_name,
            $data,
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }
    
    /**
     * Get reservations by date and branch
     */
    public function get_by_date_branch(string $date, int $branch_id) {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE reservation_date = %s AND branch_id = %d AND status != 'cancelled' ORDER BY reservation_time",
                $date,
                $branch_id
            ),
            ARRAY_A
        );
    }
    
    /**
     * Check table availability
     */
    public function is_table_available(int $table_id, string $date, string $time, int $duration = 120) {
        global $wpdb;
        
        // Calculate time range
        $start_time = $time;
        $end_time = date('H:i:s', strtotime($time . ' + ' . $duration . ' minutes'));
        
        // Check for overlapping reservations
        $overlapping = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} 
                WHERE table_id = %d 
                AND reservation_date = %s 
                AND status NOT IN ('cancelled', 'no_show')
                AND (
                    (reservation_time < %s AND ADDTIME(reservation_time, SEC_TO_TIME(duration_minutes*60)) > %s)
                    OR (reservation_time >= %s AND reservation_time < %s)
                )",
                $table_id,
                $date,
                $end_time,
                $start_time,
                $start_time,
                $end_time
            )
        );
        
        return (int) $overlapping === 0;
    }
}
