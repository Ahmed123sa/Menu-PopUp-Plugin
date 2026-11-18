<?php
/**
 * Comprehensive Debug Logger for Reservation System
 * Provides detailed logging throughout the entire reservation flow
 */

if (!defined('ABSPATH')) {
    exit;
}

class Reservation_Debug_Logger {

    private static $debug_enabled = true;
    private static $log_file;
    private static $prefix = '[RESERVATION DEBUG]';

    /**
     * Initialize debug logging system
     */
    public static function init() {
        self::$log_file = WP_CONTENT_DIR . '/reservation-debug.log';
        self::$debug_enabled = defined('WP_DEBUG') && WP_DEBUG;

        if (self::$debug_enabled) {
            add_action('wp_ajax_menu_popup_debug_log', array(__CLASS__, 'view_debug_log'));
        }
    }

    /**
     * Master logging function with categories
     */
    public static function log($message, $category = 'GENERAL', $data = null, $level = 'INFO') {
        if (!self::$debug_enabled) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $formatted_message = sprintf(
            "[%s] %s [%s] %s",
            $timestamp,
            self::$prefix,
            $category,
            $message
        );

        if ($data !== null) {
            $formatted_data = is_array($data) || is_object($data) ? json_encode($data, JSON_PRETTY_PRINT) : $data;
            $formatted_message .= " | DATA: " . $formatted_data;
        }

        // Write to log file
        self::write_to_file($formatted_message);

        // Also output to error log in development
        if ($level === 'ERROR') {
            error_log($formatted_message);
        }

        // Output to browser console if in admin (but NOT during AJAX requests or POST saves)
        if (current_user_can('manage_options') &&
            function_exists('wp_admin_bar_render') &&
            !wp_doing_ajax() &&
            !defined('DOING_AJAX') &&
            $_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo "<script>console.log(" . json_encode($formatted_message) . ");</script>";
        }
    }

    /**
     * Specific logging methods for different components
     */
    public static function log_frontend($message, $data = null) {
        self::log($message, 'FRONTEND', $data);
        // Also send to browser console for frontend debugging
        if (wp_doing_ajax()) {
            echo "<!-- DEBUG: " . esc_html($message) . " -->";
        }
    }

    public static function log_backend($message, $data = null) {
        self::log($message, 'BACKEND', $data);
    }

    public static function log_api($action, $data = null, $response = null) {
        if ($response !== null) {
            self::log("API {$action} RESPONSE", 'API', array('request' => $data, 'response' => $response));
        } else {
            self::log("API {$action} REQUEST", 'API', $data);
        }
    }

    public static function log_database($operation, $table, $data = null, $result = null) {
        self::log("DB {$operation} on {$table}", 'DATABASE', array('data' => $data, 'result' => $result));
    }

    public static function log_step($step_name, $step_data = null, $user_id = null) {
        $user_info = $user_id ? "User: {$user_id}" : "Anonymous User";
        self::log("STEP: {$step_name} | {$user_info}", 'WORKFLOW', $step_data);
    }

    public static function log_error($error_message, $context = null, $trace = null) {
        $error_data = array(
            'message' => $error_message,
            'context' => $context,
            'trace' => $trace ?: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
        );
        self::log("ERROR: {$error_message}", 'ERROR', $error_data, 'ERROR');
    }

    public static function log_pwa($event, $data = null) {
        self::log("PWA {$event}", 'PWA', $data);
    }

    public static function log_social($action, $data = null) {
        self::log("SOCIAL {$action}", 'SOCIAL', $data);
    }

    /**
     * Write to debug log file
     */
    private static function write_to_file($message) {
        if (!file_exists(dirname(self::$log_file))) {
            wp_mkdir_p(dirname(self::$log_file));
        }

        $message .= "\n" . str_repeat('=', 80) . "\n";
        file_put_contents(self::$log_file, $message, FILE_APPEND | LOCK_EX);
    }

    /**
     * Clear debug log file
     */
    public static function clear_log() {
        if (file_exists(self::$log_file)) {
            file_put_contents(self::$log_file, '');
            self::log('Debug log cleared', 'SYSTEM');
        }
    }

    /**
     * Get debug log contents for admin viewing
     */
    public static function view_debug_log() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        if (!file_exists(self::$log_file)) {
            wp_send_json(array('log_content' => 'No debug log found.'));
        }

        $log_content = file_get_contents(self::$log_file);

        // Format for display
        $lines = explode("\n", $log_content);
        $recent_lines = array_slice($lines, -100); // Last 100 lines

        wp_send_json(array(
            'log_content' => implode("\n", $recent_lines),
            'total_lines' => count($lines)
        ));
    }

    /**
     * Add debug info to admin bar
     */
    public static function add_admin_bar_debug($wp_admin_bar) {
        if (!current_user_can('manage_options') || !self::$debug_enabled) {
            return;
        }

        $wp_admin_bar->add_node(array(
            'id' => 'reservation-debug',
            'title' => '🛠️ Reservation Debug',
            'href' => '#'
        ));

        $wp_admin_bar->add_node(array(
            'id' => 'reservation-debug-view',
            'parent' => 'reservation-debug',
            'title' => 'View Debug Log',
            'href' => '#',
            'meta' => array('onclick' => 'reservation_debug_view_log(); return false;')
        ));

        $wp_admin_bar->add_node(array(
            'id' => 'reservation-debug-clear',
            'parent' => 'reservation-debug',
            'title' => 'Clear Debug Log',
            'href' => '#',
            'meta' => array('onclick' => 'reservation_debug_clear_log(); return false;')
        ));
    }

    /**
     * Get system info for debugging
     */
    public static function get_system_info() {
        return array(
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
            'log_file' => self::$log_file,
            'log_file_exists' => file_exists(self::$log_file),
            'log_file_writable' => is_writable(dirname(self::$log_file)),
        );
    }
}

// Initialize debug logging
Reservation_Debug_Logger::init();

// Add admin bar debug menu if in admin
if (is_admin()) {
    add_action('admin_bar_menu', array('Reservation_Debug_Logger', 'add_admin_bar_debug'), 999);
}

// Add system info to AJAX debug data
add_action('wp_ajax_menu_popup_system_info', function() {
    wp_send_json(Reservation_Debug_Logger::get_system_info());
});

// Handle JavaScript debug logs
add_action('wp_ajax_menu_popup_debug_log_js', function() {
    if (!isset($_POST['message']) || !isset($_POST['level'])) {
        wp_die('Missing parameters');
    }

    $message = sanitize_text_field($_POST['message']);
    $level = sanitize_text_field($_POST['level']);
    $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : 'JAVASCRIPT';
    $data = isset($_POST['data']) ? json_decode(stripslashes($_POST['data']), true) : null;

    Reservation_Debug_Logger::log("[JS] {$message}", $category, $data, $level);
    wp_send_json_success();
});

add_action('wp_ajax_nopriv_menu_popup_debug_log_js', function() {
    // Allow even non-logged-in users to send debug logs for tracking issues
    if (!isset($_POST['message']) || !isset($_POST['level'])) {
        wp_die('Missing parameters');
    }

    $message = sanitize_text_field($_POST['message']);
    $level = sanitize_text_field($_POST['level']);
    $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : 'FRONTEND';
    $data = isset($_POST['data']) ? json_decode(stripslashes($_POST['data']), true) : null;

    Reservation_Debug_Logger::log("[FRONTEND] {$message}", $category, $data, $level);
    wp_send_json_success();
});
