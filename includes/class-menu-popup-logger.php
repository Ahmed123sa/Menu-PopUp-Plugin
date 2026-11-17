<?php
/**
 * Logger Class
 * 
 * Handles logging for debugging and error tracking
 * 
 * @package MenuPopup
 * @subpackage Core
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logger Class
 */
class Menu_Popup_Logger {
    
    /**
     * Log levels
     */
    const DEBUG = 'debug';
    const INFO = 'info';
    const WARNING = 'warning';
    const ERROR = 'error';
    const CRITICAL = 'critical';
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Log file path
     */
    private $log_file;
    
    /**
     * Whether logging is enabled
     */
    private $enabled;
    
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
     * Constructor
     */
    private function __construct() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/menu-popup-logs';
        
        // Create log directory if it doesn't exist
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        $this->log_file = $log_dir . '/plugin.log';
        $this->enabled = defined('WP_DEBUG') && WP_DEBUG;
    }
    
    /**
     * Log a message
     */
    public function log($level, $message, array $context = []) {
        if (!$this->enabled && $level === self::DEBUG) {
            return;
        }
        
        $timestamp = current_time('mysql');
        $context_str = !empty($context) ? ' ' . json_encode($context) : '';
        $log_entry = "[{$timestamp}] [{$level}] {$message}{$context_str}\n";
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
        file_put_contents($this->log_file, $log_entry, FILE_APPEND);
        
        // Also log to error_log for critical issues
        if (in_array($level, [self::ERROR, self::CRITICAL], true)) {
            error_log("[MenuPopup {$level}] {$message}");
        }
    }
    
    /**
     * Log debug message
     */
    public function debug($message, array $context = []) {
        $this->log(self::DEBUG, $message, $context);
    }
    
    /**
     * Log info message
     */
    public function info($message, array $context = []) {
        $this->log(self::INFO, $message, $context);
    }
    
    /**
     * Log warning message
     */
    public function warning($message, array $context = []) {
        $this->log(self::WARNING, $message, $context);
    }
    
    /**
     * Log error message
     */
    public function error($message, array $context = []) {
        $this->log(self::ERROR, $message, $context);
    }
    
    /**
     * Log critical message
     */
    public function critical($message, array $context = []) {
        $this->log(self::CRITICAL, $message, $context);
    }
    
    /**
     * Log AJAX request
     */
    public function log_ajax($action, $data = []) {
        $this->info('AJAX Request', [
            'action' => $action,
            'user_id' => get_current_user_id(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'data_keys' => array_keys($data)
        ]);
    }
    
    /**
     * Log payment transaction
     */
    public function log_payment($event, $order_id, $data = []) {
        $this->info('Payment Event', [
            'event' => $event,
            'order_id' => $order_id,
            'data' => $data
        ]);
    }
    
    /**
     * Log exception
     */
    public function log_exception(\Exception $exception) {
        $this->error('Exception: ' . $exception->getMessage(), [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
    
    /**
     * Get log file path
     */
    public function get_log_file_path() {
        return $this->log_file;
    }
    
    /**
     * Get recent log entries
     */
    public function get_recent_entries($lines = 100) {
        if (!file_exists($this->log_file)) {
            return [];
        }
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file
        $content = file($this->log_file);
        $content = array_slice($content, -$lines);
        
        return $content;
    }
    
    /**
     * Clear log file
     */
    public function clear() {
        if (file_exists($this->log_file)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_unlink
            unlink($this->log_file);
        }
    }
    
    /**
     * Get log file size in bytes
     */
    public function get_log_size() {
        if (!file_exists($this->log_file)) {
            return 0;
        }
        
        return filesize($this->log_file);
    }
}

/**
 * Security Helper Functions
 */
class Menu_Popup_Security {
    
    /**
     * Verify AJAX nonce
     */
    public static function verify_ajax_nonce($nonce, $action = 'menu_popup_nonce') {
        if (!check_ajax_referer($action, 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed', 'menu-popup')
            ], 403);
            return false;
        }
        return true;
    }
    
    /**
     * Verify capability
     */
    public static function verify_capability($capability = 'manage_options') {
        if (!current_user_can($capability)) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action', 'menu-popup')
            ], 403);
            return false;
        }
        return true;
    }
    
    /**
     * Sanitize input data
     */
    public static function sanitize_input($data, $type = 'text') {
        switch ($type) {
            case 'text':
                return sanitize_text_field($data);
            case 'email':
                return sanitize_email($data);
            case 'url':
                return esc_url_raw($data);
            case 'textarea':
                return sanitize_textarea_field($data);
            case 'int':
                return absint($data);
            case 'float':
                return (float) sanitize_text_field($data);
            case 'array':
                return array_map('sanitize_text_field', $data);
            default:
                return sanitize_text_field($data);
        }
    }
    
    /**
     * Validate required fields
     */
    public static function validate_required($data, $required_fields) {
        $errors = [];
        
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                $errors[] = sprintf(__('Field %s is required', 'menu-popup'), $field);
            }
        }
        
        if (!empty($errors)) {
            wp_send_json_error([
                'message' => implode(', ', $errors),
                'errors' => $errors
            ], 400);
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate phone number
     */
    public static function validate_phone($phone) {
        // Remove common formatting characters
        $phone = preg_replace('/[\s\-\(\)\+]/', '', $phone);
        
        // Check if it contains only digits and is reasonable length
        if (!preg_match('/^[0-9]{8,15}$/', $phone)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate email
     */
    public static function validate_email($email) {
        return is_email($email);
    }
    
    /**
     * Check if request method is POST
     */
    public static function require_post_method() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error([
                'message' => __('Invalid request method', 'menu-popup')
            ], 405);
            return false;
        }
        return true;
    }
}
