<?php
/**
 * Logger Class
 *
 * Comprehensive logging system with levels, rotation, and admin viewing
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_Logger {

    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';

    private static $instance = null;
    private $log_table;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->log_table = $wpdb->prefix . 'tsf_logs';
        $this->init_database();
    }

    private function init_database() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->log_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context longtext,
            user_id bigint(20),
            ip_address varchar(45),
            user_agent varchar(255),
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY created_at (created_at),
            KEY user_id (user_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Log a debug message
     */
    public function debug($message, $context = []) {
        return $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log an info message
     */
    public function info($message, $context = []) {
        return $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log a warning
     */
    public function warning($message, $context = []) {
        return $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log an error
     */
    public function error($message, $context = []) {
        return $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log a critical error
     */
    public function critical($message, $context = []) {
        return $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * Main logging method
     */
    private function log($level, $message, $context = []) {
        global $wpdb;

        // Don't log debug messages in production
        if ($level === self::LEVEL_DEBUG && !WP_DEBUG) {
            return false;
        }

        $user_id = get_current_user_id();
        $ip_address = $this->get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

        $result = $wpdb->insert(
            $this->log_table,
            [
                'level' => $level,
                'message' => $message,
                'context' => wp_json_encode($context),
                'user_id' => $user_id ?: null,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        // Also log to PHP error log for critical errors
        if (in_array($level, [self::LEVEL_ERROR, self::LEVEL_CRITICAL], true)) {
            error_log(sprintf('[TSF %s] %s %s', strtoupper($level), $message, wp_json_encode($context)));
        }

        return $result !== false;
    }

    /**
     * Get logs with filters
     */
    public function get_logs($args = []) {
        global $wpdb;

        $defaults = [
            'level' => null,
            'user_id' => null,
            'date_from' => null,
            'date_to' => null,
            'limit' => 100,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];
        $where_values = [];

        if ($args['level']) {
            $where[] = 'level = %s';
            $where_values[] = $args['level'];
        }

        if ($args['user_id']) {
            $where[] = 'user_id = %d';
            $where_values[] = $args['user_id'];
        }

        if ($args['date_from']) {
            $where[] = 'created_at >= %s';
            $where_values[] = $args['date_from'];
        }

        if ($args['date_to']) {
            $where[] = 'created_at <= %s';
            $where_values[] = $args['date_to'];
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT * FROM {$this->log_table}
                WHERE {$where_clause}
                ORDER BY {$args['orderby']} {$args['order']}
                LIMIT %d OFFSET %d";

        $where_values[] = $args['limit'];
        $where_values[] = $args['offset'];

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Get log count
     */
    public function get_log_count($level = null) {
        global $wpdb;

        if ($level) {
            return $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->log_table} WHERE level = %s",
                $level
            ));
        }

        return $wpdb->get_var("SELECT COUNT(*) FROM {$this->log_table}");
    }

    /**
     * Clear old logs
     */
    public function clear_old_logs($days = 30) {
        global $wpdb;

        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->log_table} WHERE created_at < %s",
            $date
        ));

        if ($deleted) {
            $this->info("Cleared {$deleted} old log entries", ['days' => $days]);
        }

        return $deleted;
    }

    /**
     * Clear all logs
     */
    public function clear_all_logs() {
        global $wpdb;
        return $wpdb->query("TRUNCATE TABLE {$this->log_table}");
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
}
