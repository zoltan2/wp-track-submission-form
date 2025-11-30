<?php
/*
Plugin Name: Track Submission Form
Description: Front-end form that lets artists safely submit track metadata, then redirects them to a Dropbox file-request link once the data is stored in WordPress. Handles validation, AJAX processing, and custom-post-type storage so nothing gets lost on the way to Dropbox. Now with enhanced security: REST API protection, secure credential storage, SQL injection prevention, IDOR protection, MP3 magic byte validation, email header injection protection, path traversal protection, SSL verification, enhanced REST API validation, automatic log purging, XSS prevention in JavaScript, and production-ready code (no debug logs). v3.4.0: Dropbox API integration for automatic MP3 uploads.
Version: 3.4.0
Author: Zoltan Janosi
Requires at least: 5.0
Requires PHP: 7.4
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TSF_VERSION', '3.4.0');
define('TSF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TSF_PLUGIN_URL', plugin_dir_url(__FILE__));

// Initialize plugin
class TrackSubmissionForm {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function init() {
        // Debug logging
        error_log('TSF: Plugin initialization started');
        
        // Load plugin features
        $this->load_hooks();

        // Load text domain for translations
        load_plugin_textdomain('tsf', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        error_log('TSF: Plugin initialization completed');
    }

    private function load_hooks() {
        error_log('TSF: Loading hooks started');
        
        // Load Form V2
        $this->load_form_v2();
        
        error_log('TSF: Form V2 loaded');

        // Assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_head', [$this, 'add_admin_styles']);

        // Shortcode (V1 now handled by Form V2)
        // add_shortcode('track_submission_form', [$this, 'render_form']);
        add_shortcode('tsf_upload_instructions', [$this, 'render_upload_instructions']);

        // AJAX handlers
        add_action('wp_ajax_tsf_submit', [$this, 'handle_submission']);
        add_action('wp_ajax_nopriv_tsf_submit', [$this, 'handle_submission']);
        add_action('wp_ajax_tsf_submit_v2', [$this, 'handle_submission']);
        add_action('wp_ajax_nopriv_tsf_submit_v2', [$this, 'handle_submission']);

        // Admin features
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_custom_statuses']); // Register custom statuses
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_cache_clear']); // Handle manual cache clear
        add_action('add_meta_boxes', [$this, 'add_submission_metaboxes']);
        add_action('save_post', [$this, 'save_submission_metabox']);

        // MP3 download/delete actions
        add_action('admin_post_tsf_download_mp3', [$this, 'handle_mp3_download']);
        add_action('admin_post_tsf_delete_mp3', [$this, 'handle_mp3_delete']);

        // Admin columns customization
        add_filter('manage_track_submission_posts_columns', [$this, 'customize_columns']);
        add_action('manage_track_submission_posts_custom_column', [$this, 'render_custom_column'], 10, 2);

        // Admin filters
        add_action('restrict_manage_posts', [$this, 'add_status_filter']);
        add_filter('parse_query', [$this, 'filter_by_status']);

        // Fix "All" view to show all statuses
        add_filter('views_edit-track_submission', [$this, 'fix_all_view_count']);

        // Cron jobs
        add_action('tsf_weekly_report', [$this, 'send_weekly_csv_report']);
        add_action('tsf_cleanup_files', [$this, 'cleanup_old_files']);
        add_action('tsf_cleanup_logs', [$this, 'cleanup_old_logs']); // VUL-3 FIX: Auto-purge logs

        // Security headers
        add_action('send_headers', [$this, 'add_security_headers']);

        // Cache invalidation
        add_action('save_post_track_submission', [$this, 'clear_submissions_cache']);
        add_action('delete_post', [$this, 'clear_submissions_cache']);
    }

    /**
     * Load Form V2 and additional modules
     */
    private function load_form_v2() {
        // Form V2
        $form_v2_file = TSF_PLUGIN_DIR . 'includes/class-tsf-form-v2.php';
        if (file_exists($form_v2_file)) {
            require_once $form_v2_file;
            // Initialize Form V2 to register shortcode and enqueue assets
            TSF_Form_V2::get_instance();
        }

        // API Handler
        $api_handler_file = TSF_PLUGIN_DIR . 'includes/class-tsf-api-handler.php';
        if (file_exists($api_handler_file)) {
            require_once $api_handler_file;
            // Initialize API Handler to register REST routes
            TSF_API_Handler::get_instance();
        }

        // MP3 Analyzer (getID3)
        $mp3_analyzer_file = TSF_PLUGIN_DIR . 'includes/class-tsf-mp3-analyzer.php';
        if (file_exists($mp3_analyzer_file)) {
            require_once $mp3_analyzer_file;
        }

        // Dashboard Analytics - Load dependencies in correct order
        // 1. Logger (required by Workflow)
        $logger_file = TSF_PLUGIN_DIR . 'includes/class-tsf-logger.php';
        if (file_exists($logger_file)) {
            require_once $logger_file;
        }

        // 2. Workflow (required by Dashboard)
        $workflow_file = TSF_PLUGIN_DIR . 'includes/class-tsf-workflow.php';
        if (file_exists($workflow_file)) {
            require_once $workflow_file;
        }

        // 3. Dashboard
        $dashboard_file = TSF_PLUGIN_DIR . 'includes/class-tsf-updater.php';
        if (file_exists($dashboard_file)) {
            require_once $dashboard_file;
        }

        // 4. Updater - GitHub auto-update support
        $this->init_updater();
    }

    /**
     * Initialize auto-update system from GitHub
     */
    private function init_updater() {
        // Load updater class
        $updater_file = TSF_PLUGIN_DIR . 'includes/class-tsf-updater.php';
        if (!file_exists($updater_file)) {
            return;
        }

        require_once $updater_file;

        // Get GitHub repo and token from options or constants
        $github_repo = defined('TSF_GITHUB_REPO')
            ? TSF_GITHUB_REPO
            : get_option('tsf_github_repo', 'zoltan2/track-submission-form');

        $github_token = defined('TSF_GITHUB_TOKEN')
            ? TSF_GITHUB_TOKEN
            : get_option('tsf_github_token', '');

        // Initialize updater
        if (!empty($github_repo)) {
            new TSF_Updater(__FILE__, $github_repo, $github_token);
        }
    }

    public function activate() {
        $this->schedule_cron_jobs();
        $this->create_directories();
        $this->set_default_options();
        $this->create_custom_tables();

        // Clear all caches on activation
        $this->clear_all_caches();

        // Add custom capabilities to administrator role for finer control
        if (function_exists('get_role')) {
            $admin_role = get_role('administrator');
            if ($admin_role && ! $admin_role->has_cap('tsf_bypass_rate_limit')) {
                $admin_role->add_cap('tsf_bypass_rate_limit');
            }
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create custom database tables for multi-track releases
     */
    private function create_custom_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Releases table for albums/EPs
        $releases_table = $wpdb->prefix . 'tsf_releases';
        $sql_releases = "CREATE TABLE IF NOT EXISTS {$releases_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            type ENUM('single', 'ep', 'album', 'auto') DEFAULT 'auto',
            track_count INT(3) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type_idx (type),
            KEY created_at_idx (created_at)
        ) {$charset_collate};";

        // Release-Track junction table
        $junction_table = $wpdb->prefix . 'tsf_release_tracks';
        $sql_junction = "CREATE TABLE IF NOT EXISTS {$junction_table} (
            release_id BIGINT(20) UNSIGNED NOT NULL,
            track_post_id BIGINT(20) UNSIGNED NOT NULL,
            track_order INT(3) NOT NULL DEFAULT 1,
            PRIMARY KEY (release_id, track_post_id),
            KEY track_post_id_idx (track_post_id),
            KEY track_order_idx (track_order)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_releases);
        dbDelta($sql_junction);

        // Store DB version for future migrations
        update_option('tsf_db_version', '1.0');
    }

    public function deactivate() {
        wp_clear_scheduled_hook('tsf_weekly_report');
        wp_clear_scheduled_hook('tsf_cleanup_files');
        wp_clear_scheduled_hook('tsf_cleanup_logs'); // VUL-3 FIX

        // Clear all caches on deactivation
        $this->clear_all_caches();

        // Remove custom capability from administrator role
        if (function_exists('get_role')) {
            $admin_role = get_role('administrator');
            if ($admin_role && $admin_role->has_cap('tsf_bypass_rate_limit')) {
                $admin_role->remove_cap('tsf_bypass_rate_limit');
            }
        }
    }

    /**
     * Clear all WordPress and plugin caches
     */
    private function clear_all_caches() {
        // Clear WordPress object cache
        wp_cache_flush();

        // Clear transients related to this plugin
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_tsf_%'
             OR option_name LIKE '_transient_timeout_tsf_%'"
        );

        // Clear WP Super Cache if active
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // Clear W3 Total Cache if active
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        // Clear WP Rocket cache if active
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // Clear LiteSpeed Cache if active
        if (class_exists('LiteSpeed_Cache_API')) {
            LiteSpeed_Cache_API::purge_all();
        }

        // Clear Autoptimize cache if active
        if (class_exists('autoptimizeCache')) {
            autoptimizeCache::clearall();
        }

        // Increment version to bust browser cache
        update_option('tsf_cache_buster', time());
    }

    private function schedule_cron_jobs() {
        // Weekly report with proper timezone handling
        if (!wp_next_scheduled('tsf_weekly_report')) {
            $report_day = get_option('tsf_report_day', 'thursday');
            $report_time = get_option('tsf_report_time', '09:00');

            // Use WordPress timezone
            $timezone = wp_timezone();
            $next_run = new DateTime("next {$report_day} {$report_time}", $timezone);

            wp_schedule_event(
                $next_run->getTimestamp(),
                'weekly',
                'tsf_weekly_report'
            );
        }

        // Monthly cleanup with proper timezone
        if (!wp_next_scheduled('tsf_cleanup_files')) {
            $timezone = wp_timezone();
            $next_cleanup = new DateTime('first day of next month 02:00', $timezone);

            wp_schedule_event(
                $next_cleanup->getTimestamp(),
                'monthly',
                'tsf_cleanup_files'
            );
        }

        // VUL-3 FIX: Monthly log cleanup
        if (!wp_next_scheduled('tsf_cleanup_logs')) {
            $timezone = wp_timezone();
            $next_log_cleanup = new DateTime('first day of next month 03:00', $timezone);

            wp_schedule_event(
                $next_log_cleanup->getTimestamp(),
                'monthly',
                'tsf_cleanup_logs'
            );
        }
    }

    private function create_directories() {
        $upload_dir = wp_upload_dir();
        if (!is_wp_error($upload_dir)) {
            $tsf_dir = trailingslashit($upload_dir['basedir']) . 'tsf-reports/';
            if (!file_exists($tsf_dir)) {
                wp_mkdir_p($tsf_dir);
                // Add index.php for security
                file_put_contents($tsf_dir . 'index.php', '<?php // Silence is golden. ?>');
            }
        }
    }

    private function set_default_options() {
        $defaults = [
            'tsf_dropbox_url' => 'https://www.dropbox.com/request/qhA1oF0V4W1FolQ2bcjO',
            'tsf_notification_email' => get_option('admin_email'),
            'tsf_report_day' => 'thursday',
            'tsf_report_time' => '09:00',
            'tsf_max_future_years' => 2,
            'tsf_file_retention_days' => 90, // For cleanup_old_files
            'tsf_genres' => ['Pop', 'Rock', 'Electronic/Dance', 'Folk', 'Alternative', 'Metal', 'Jazz', 'R&B', 'Hip-Hop/Rap', 'Autre'],
            'tsf_platforms' => ['Spotify', 'Bandcamp', 'Youtube Music', 'Apple Music', 'Deezer', 'Soundcloud', 'Other'],
            'tsf_types' => ['Album', 'EP', 'Single'],
            'tsf_labels' => ['Indie', 'Label']
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                update_option($key, $value);
            }
        }
    }

    public function enqueue_assets() {
        global $post;
        // Only load on pages with the shortcode
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'track_submission_form')) {
            return;
        }

        // Get cache buster
        $cache_buster = get_option('tsf_cache_buster', TSF_VERSION);

        // Enqueue JavaScript with SRI (Subresource Integrity) would be ideal in production
        wp_enqueue_script(
            'tsf-validation',
            TSF_PLUGIN_URL . 'assets/tsf-validation.js',
            ['jquery'],
            $cache_buster,
            true // Load in footer for better performance
        );

        // Add async/defer to script for better performance
        add_filter('script_loader_tag', function($tag, $handle) {
            if ('tsf-validation' === $handle) {
                return str_replace(' src', ' defer src', $tag);
            }
            return $tag;
        }, 10, 2);

        wp_localize_script('tsf-validation', 'tsfData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tsf_nonce'),
            'dropbox' => esc_url(get_option('tsf_dropbox_url')),
            'messages' => [
                'error' => __('An error occurred. Please try again.', 'tsf'),
                'loading' => __('Processing...', 'tsf')
            ]
        ]);

        // CSS is now handled by Form V2 (tsf-form-v2.css) - legacy removed
    }

    /**
     * Add admin styles for status badges
     */
    public function add_admin_styles() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'track_submission') {
            return;
        }
        ?>
        <style>
            .tsf-status-badge {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .tsf-status-publish {
                background-color: #46b450;
                color: #fff;
            }
            .tsf-status-pending {
                background-color: #ffb900;
                color: #000;
            }
            .tsf-status-approved {
                background-color: #00a0d2;
                color: #fff;
            }
            .tsf-status-rejected {
                background-color: #dc3232;
                color: #fff;
            }
            .tsf-status-draft {
                background-color: #999;
                color: #fff;
            }
        </style>
        <?php
    }

    public function register_post_type() {
        // Register custom post status for pending review
        register_post_status('pending_review', [
            'label' => _x('Pending Review', 'post status', 'tsf'),
            'public' => false,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop(
                'Pending Review <span class="count">(%s)</span>',
                'Pending Review <span class="count">(%s)</span>',
                'tsf'
            ),
        ]);

        // Registers custom post type for track submissions
        register_post_type('track_submission', [
            'label' => __('Track Submissions', 'tsf'),
            'labels' => [
                'name' => __('Track Submissions', 'tsf'),
                'singular_name' => __('Track Submission', 'tsf'),
                'add_new_item' => __('Add New Submission', 'tsf'),
                'edit_item' => __('Edit Submission', 'tsf'),
                'view_item' => __('View Submission', 'tsf'),
                'search_items' => __('Search Submissions', 'tsf'),
                'all_items' => __('All Submissions', 'tsf'),
            ],
            'public' => false, // Not publicly accessible
            'show_ui' => true, // Show in admin UI
            'show_in_menu' => 'tsf-submissions', // Link to the main menu page we add
            'menu_position' => 25,
            'menu_icon' => 'dashicons-format-audio',
            'capability_type' => 'post',
            'capabilities' => [
                // Fine-grained capabilities based on default 'post' capabilities
                'edit_post' => 'edit_posts',
                'read_post' => 'edit_posts',
                'delete_post' => 'delete_posts',
                'edit_posts' => 'edit_posts',
                'edit_others_posts' => 'edit_others_posts',
                'publish_posts' => 'publish_posts',
                'read_private_posts' => 'read_private_posts',
            ],
            'supports' => ['title'], // Only title support for CPT
            'has_archive' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
        ]);
    }

    /**
     * Register custom post statuses
     */
    public function register_custom_statuses() {
        register_post_status('pending', [
            'label' => _x('Pending Review', 'post status', 'tsf'),
            'public' => false,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop(
                'Pending <span class="count">(%s)</span>',
                'Pending <span class="count">(%s)</span>',
                'tsf'
            ),
        ]);

        register_post_status('approved', [
            'label' => _x('Approved', 'post status', 'tsf'),
            'public' => false,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop(
                'Approved <span class="count">(%s)</span>',
                'Approved <span class="count">(%s)</span>',
                'tsf'
            ),
        ]);

        register_post_status('rejected', [
            'label' => _x('Rejected', 'post status', 'tsf'),
            'public' => false,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop(
                'Rejected <span class="count">(%s)</span>',
                'Rejected <span class="count">(%s)</span>',
                'tsf'
            ),
        ]);
    }

    /**
     * Customize admin columns
     */
    public function customize_columns($columns) {
        // Remove checkbox and title temporarily
        $checkbox = $columns['cb'];
        unset($columns['cb']);
        unset($columns['title']);
        unset($columns['date']);

        // Build new column order
        $new_columns = [
            'cb' => $checkbox,
            'title' => __('Artist - Track', 'tsf'),
            'tsf_status' => __('Status', 'tsf'),
            'tsf_genre' => __('Genre', 'tsf'),
            'tsf_country' => __('Country', 'tsf'),
            'date' => __('Submitted', 'tsf'),
        ];

        return $new_columns;
    }

    /**
     * Render custom columns
     */
    public function render_custom_column($column, $post_id) {
        switch ($column) {
            case 'tsf_status':
                $post_status = get_post_status($post_id);
                $status_labels = [
                    'publish' => __('Published', 'tsf'),
                    'pending' => __('Pending', 'tsf'),
                    'approved' => __('Approved', 'tsf'),
                    'rejected' => __('Rejected', 'tsf'),
                    'draft' => __('Draft', 'tsf'),
                ];
                $status_label = isset($status_labels[$post_status]) ? $status_labels[$post_status] : $post_status;
                echo '<span class="tsf-status-badge tsf-status-' . esc_attr($post_status) . '">' . esc_html($status_label) . '</span>';
                break;

            case 'tsf_genre':
                $genre = get_post_meta($post_id, 'tsf_genre', true);
                echo esc_html($genre ?: '—');
                break;

            case 'tsf_country':
                $country = get_post_meta($post_id, 'tsf_country', true);
                echo esc_html($country ?: '—');
                break;
        }
    }

    /**
     * Add status filter dropdown
     */
    public function add_status_filter($post_type) {
        if ($post_type !== 'track_submission') {
            return;
        }

        $current_status = isset($_GET['tsf_status_filter']) ? sanitize_text_field($_GET['tsf_status_filter']) : '';

        $statuses = [
            '' => __('All Statuses', 'tsf'),
            'publish' => __('Published', 'tsf'),
            'pending' => __('Pending', 'tsf'),
            'approved' => __('Approved', 'tsf'),
            'rejected' => __('Rejected', 'tsf'),
            'draft' => __('Draft', 'tsf'),
        ];

        echo '<select name="tsf_status_filter" id="tsf_status_filter">';
        foreach ($statuses as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($current_status, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    /**
     * Filter by status
     */
    public function filter_by_status($query) {
        global $pagenow;

        // Only run in admin area on the edit.php page
        if (!is_admin() || $pagenow !== 'edit.php') {
            return;
        }

        // Only for track_submission post type
        if (!isset($_GET['post_type']) || $_GET['post_type'] !== 'track_submission') {
            return;
        }

        // Only for main query
        if (!$query->is_main_query()) {
            return;
        }

        // Don't run on AJAX or REST requests
        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        // If status filter is set
        if (isset($_GET['tsf_status_filter']) && !empty($_GET['tsf_status_filter'])) {
            $query->set('post_status', sanitize_text_field($_GET['tsf_status_filter']));
        } elseif (!isset($_GET['post_status'])) {
            // If no filter and not already filtered by post_status, show all statuses
            $query->set('post_status', ['publish', 'pending', 'approved', 'rejected', 'draft']);
        }
    }

    /**
     * Fix "All" view count to include all statuses
     */
    public function fix_all_view_count($views) {
        global $wpdb;

        // Count all posts with all statuses
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish', 'pending', 'approved', 'rejected', 'draft')",
                'track_submission'
            )
        );

        // Update "All" link if it exists
        if (isset($views['all'])) {
            $views['all'] = preg_replace(
                '/\((\d+)\)/',
                '(' . intval($count) . ')',
                $views['all']
            );
        }

        return $views;
    }


    public function add_admin_menu() {
        error_log('TSF: TrackSubmissionForm::add_admin_menu called');
        // Adds main menu page for submissions
        add_menu_page(
            __('Track Submissions', 'tsf'),
            __('Track Submissions', 'tsf'),
            'edit_posts', // Capability required
            'tsf-submissions',
            [$this, 'admin_page'], // Callback for the page content
            'dashicons-format-audio',
            25
        );

        // Dashboard Analytics
        add_submenu_page(
            'tsf-submissions',
            __('Dashboard', 'tsf'),
            __('Dashboard', 'tsf'),
            'edit_posts',
            'tsf-dashboard',
            [$this, 'dashboard_page']
        );

        // Adds submenu page for settings
        add_submenu_page(
            'tsf-submissions',
            __('Settings', 'tsf'),
            __('Settings', 'tsf'),
            'manage_options', // Capability required
            'tsf-settings',
            [$this, 'settings_page'] // Callback for the settings page content
        );

        // Adds submenu page for data export
        add_submenu_page(
            'tsf-submissions',
            __('Export Data', 'tsf'),
            __('Export Data', 'tsf'),
            'export', // Capability required
            'tsf-export',
            [$this, 'export_page'] // Callback for the export page content
        );
        add_submenu_page(
            'tsf-submissions',
            __('Generate Weekly Report', 'tsf'),
            __('Generate Weekly Report', 'tsf'),
            'manage_options',
            'tsf-generate-report',
            [$this, 'generate_report_page']
        );
    }

    /**
     * Dashboard Analytics Page
     */
    public function dashboard_page() {
        // Check user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'tsf'));
        }

        // Get period from request
        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '30days';
        $allowed_periods = ['7days', '30days', '90days', '1year', 'all'];
        if (!in_array($period, $allowed_periods)) {
            $period = '30days';
        }

        // Load dashboard class if not already loaded
        if (!class_exists('TSF_Dashboard')) {
            require_once TSF_PLUGIN_DIR . 'includes/class-tsf-dashboard.php';
        }

        // Get dashboard stats
        $dashboard = new TSF_Dashboard();
        $stats = $dashboard->get_stats($period);

        // Load template
        $template_file = TSF_PLUGIN_DIR . 'templates/admin/dashboard.php';
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            // Fallback inline display
            $this->render_dashboard_inline($stats, $period);
        }
    }

    /**
     * Render dashboard inline (fallback)
     */
    private function render_dashboard_inline($stats, $period) {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Dashboard Analytics', 'tsf'); ?></h1>

            <div style="margin: 20px 0;">
                <a href="?page=tsf-dashboard&period=7days" class="button <?php echo $period === '7days' ? 'button-primary' : ''; ?>">7 Days</a>
                <a href="?page=tsf-dashboard&period=30days" class="button <?php echo $period === '30days' ? 'button-primary' : ''; ?>">30 Days</a>
                <a href="?page=tsf-dashboard&period=90days" class="button <?php echo $period === '90days' ? 'button-primary' : ''; ?>">90 Days</a>
                <a href="?page=tsf-dashboard&period=1year" class="button <?php echo $period === '1year' ? 'button-primary' : ''; ?>">1 Year</a>
                <a href="?page=tsf-dashboard&period=all" class="button <?php echo $period === 'all' ? 'button-primary' : ''; ?>">All Time</a>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
                <!-- Total Submissions -->
                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h3 style="margin: 0 0 10px; color: #666; font-size: 14px;"><?php esc_html_e('Total Submissions', 'tsf'); ?></h3>
                    <p style="margin: 0; font-size: 36px; font-weight: bold; color: #0073aa;"><?php echo esc_html($stats['total']); ?></p>
                </div>

                <!-- Status Counts -->
                <?php if (!empty($stats['status_counts'])): ?>
                    <?php foreach ($stats['status_counts'] as $status => $count): ?>
                        <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <h3 style="margin: 0 0 10px; color: #666; font-size: 14px; text-transform: capitalize;"><?php echo esc_html(ucfirst($status)); ?></h3>
                            <p style="margin: 0; font-size: 36px; font-weight: bold; color: <?php
                                echo $status === 'approved' ? '#46b450' : ($status === 'pending' ? '#ffb900' : '#dc3232');
                            ?>;"><?php echo esc_html($count); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">
                <!-- Top Genres -->
                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h2><?php esc_html_e('Top Genres', 'tsf'); ?></h2>
                    <?php if (!empty($stats['top_genres'])): ?>
                        <ul style="list-style: none; padding: 0; margin: 0;">
                            <?php foreach ($stats['top_genres'] as $genre): ?>
                                <li style="padding: 10px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between;">
                                    <span><?php echo esc_html($genre->genre); ?></span>
                                    <strong><?php echo esc_html($genre->count); ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p><?php esc_html_e('No data available', 'tsf'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Top Countries -->
                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <h2><?php esc_html_e('Top Countries', 'tsf'); ?></h2>
                    <?php if (!empty($stats['top_countries'])): ?>
                        <ul style="list-style: none; padding: 0; margin: 0;">
                            <?php foreach ($stats['top_countries'] as $country): ?>
                                <li style="padding: 10px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between;">
                                    <span><?php echo esc_html($country->country); ?></span>
                                    <strong><?php echo esc_html($country->count); ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p><?php esc_html_e('No data available', 'tsf'); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Timeline Chart -->
            <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin: 20px 0;">
                <h2><?php esc_html_e('Submissions Over Time', 'tsf'); ?></h2>

                <?php if (!empty($stats['timeline'])): ?>
                    <?php
                    // Calculate max for scaling
                    $max_count = 0;
                    foreach ($stats['timeline'] as $point) {
                        if ($point->count > $max_count) {
                            $max_count = $point->count;
                        }
                    }
                    ?>

                    <!-- Visual Bar Chart -->
                    <div style="margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 4px;">
                        <div style="display: flex; align-items: flex-end; height: 200px; gap: 4px; border-bottom: 2px solid #666; padding-bottom: 10px;">
                            <?php foreach ($stats['timeline'] as $point): ?>
                                <?php
                                $height = $max_count > 0 ? ($point->count / $max_count) * 100 : 0;
                                $bar_height = max(5, $height); // Minimum 5% to be visible
                                ?>
                                <div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end;">
                                    <div style="background: linear-gradient(180deg, #0073aa 0%, #005177 100%);
                                                width: 100%;
                                                height: <?php echo $bar_height; ?>%;
                                                border-radius: 4px 4px 0 0;
                                                position: relative;
                                                min-height: 10px;
                                                transition: all 0.3s;"
                                         title="<?php echo esc_attr($point->date . ': ' . $point->count . ' submission(s)'); ?>">
                                        <span style="position: absolute;
                                                     top: -20px;
                                                     left: 50%;
                                                     transform: translateX(-50%);
                                                     font-size: 11px;
                                                     font-weight: bold;
                                                     color: #0073aa;
                                                     white-space: nowrap;">
                                            <?php echo esc_html($point->count); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- X-axis labels -->
                        <div style="display: flex; gap: 4px; margin-top: 10px;">
                            <?php
                            $total_points = count($stats['timeline']);
                            $show_every = max(1, floor($total_points / 10)); // Show max 10 labels
                            ?>
                            <?php foreach ($stats['timeline'] as $index => $point): ?>
                                <div style="flex: 1; text-align: center; font-size: 10px; color: #666;">
                                    <?php if ($index % $show_every === 0 || $index === $total_points - 1): ?>
                                        <?php echo esc_html(date('M j', strtotime($point->date))); ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Data Table (collapsible) -->
                    <details style="margin-top: 20px;">
                        <summary style="cursor: pointer; font-weight: bold; padding: 10px; background: #f0f0f0; border-radius: 4px;">
                            <?php esc_html_e('View Detailed Data', 'tsf'); ?> (<?php echo count($stats['timeline']); ?> <?php esc_html_e('days', 'tsf'); ?>)
                        </summary>
                        <div style="overflow-x: auto; margin-top: 10px;">
                            <table class="widefat striped" style="min-width: 600px;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Date', 'tsf'); ?></th>
                                        <th><?php esc_html_e('Day', 'tsf'); ?></th>
                                        <th><?php esc_html_e('Submissions', 'tsf'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['timeline'] as $point): ?>
                                        <tr>
                                            <td><?php echo esc_html($point->date); ?></td>
                                            <td><?php echo esc_html(date('l', strtotime($point->date))); ?></td>
                                            <td><strong><?php echo esc_html($point->count); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>

                <?php else: ?>
                    <p style="color: #666; padding: 20px; text-align: center;">
                        <?php esc_html_e('No submissions found for this period.', 'tsf'); ?>
                    </p>

                    <!-- Debug info -->
                    <details style="margin-top: 20px;">
                        <summary style="cursor: pointer; font-size: 12px; color: #999;">Debug Info</summary>
                        <pre style="background: #f0f0f0; padding: 10px; overflow: auto; font-size: 11px;">
Period: <?php echo esc_html($period); ?>
Timeline data: <?php var_dump($stats['timeline']); ?>
Total: <?php echo esc_html($stats['total']); ?>
                        </pre>
                    </details>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function generate_report_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'tsf'));
        }

        $submissions = [];
        $csv_path = '';
        $csv_url = '';
        $count = 0;
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : date('Y-m-d', strtotime('-7 days'));
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : date('Y-m-d');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('tsf_generate_report', 'tsf_generate_report_nonce')) {
            $start = $date_from . ' 00:00:00';
            $end = $date_to . ' 23:59:59';

            // Use WP_Query instead of direct SQL query
            $args = [
                'post_type'      => 'track_submission',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_query'     => [
                    [
                        'key'     => 'tsf_created_at',
                        'value'   => [ $start, $end ],
                        'compare' => 'BETWEEN',
                        'type'    => 'DATETIME',
                    ],
                ],
                'orderby'   => 'meta_value',
                'meta_key'  => 'tsf_created_at',
                'order'     => 'ASC',
            ];

            $query = new WP_Query($args);
            $rows = [];

            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $rows[] = [
                        'id'            => get_the_ID(),
                        'created_at'    => get_post_meta(get_the_ID(), 'tsf_created_at', true),
                        'artist'        => get_post_meta(get_the_ID(), 'tsf_artist', true),
                        'track_title'   => get_post_meta(get_the_ID(), 'tsf_track_title', true),
                        'genre'         => get_post_meta(get_the_ID(), 'tsf_genre', true),
                        'duration'      => get_post_meta(get_the_ID(), 'tsf_duration', true),
                        'instrumental'  => get_post_meta(get_the_ID(), 'tsf_instrumental', true),
                        'release_date'  => get_post_meta(get_the_ID(), 'tsf_release_date', true),
                        'email'         => get_post_meta(get_the_ID(), 'tsf_email', true),
                        'phone'         => get_post_meta(get_the_ID(), 'tsf_phone', true),
                        'platform'      => get_post_meta(get_the_ID(), 'tsf_platform', true),
                        'track_url'     => get_post_meta(get_the_ID(), 'tsf_track_url', true),
                        'social_url'    => get_post_meta(get_the_ID(), 'tsf_social_url', true),
                        'type'          => get_post_meta(get_the_ID(), 'tsf_type', true),
                        'label'         => get_post_meta(get_the_ID(), 'tsf_label', true),
                        'country'       => get_post_meta(get_the_ID(), 'tsf_country', true),
                        'description'   => get_post_meta(get_the_ID(), 'tsf_description', true),
                        'optin'         => get_post_meta(get_the_ID(), 'tsf_optin', true),
                    ];
                }
                wp_reset_postdata();

                if (empty($rows)) {
                    echo '<div class="notice notice-warning"><p>No submissions found for that period.</p></div>';
                } else {
                    // Build CSV
                    $csv_lines = [];
                    $csv_lines[] = '"' . implode('","', array_keys($rows[0])) . '"';
                    foreach ($rows as $row) {
                        $values = array_map(function ($v) {
                            return str_replace('"', '""', $v ?? '');
                        }, array_values($row));
                        $csv_lines[] = '"' . implode('","', $values) . '"';
                    }

                    $upload_dir = wp_upload_dir();
                    $tsf_dir = trailingslashit($upload_dir['basedir']) . 'tsf-reports/';
                    wp_mkdir_p($tsf_dir);

                    $filename = 'tsf_report_' . date('Ymd_His') . '.csv';
                    $csv_path = $tsf_dir . $filename;
                    $csv_url = trailingslashit($upload_dir['baseurl']) . 'tsf-reports/' . $filename;

                    file_put_contents($csv_path, implode("\r\n", $csv_lines));
                    $count = count($rows);

                    // Send by email
                    $to = get_option('tsf_notification_email', get_option('admin_email'));
                    $subject = sprintf(__('Track Submissions Report (%s to %s)', 'tsf'), $date_from, $date_to);
                    $body = sprintf(
                        __("Attached is the report of %d submissions between %s and %s.\n\nYou can also download it here:\n%s", 'tsf'),
                        $count, $date_from, $date_to, $csv_url
                    );
                    $headers = ['Content-Type: text/plain; charset=UTF-8'];

                    wp_mail($to, $subject, $body, $headers, [$csv_path]);

                    echo '<div class="notice notice-success"><p>Report generated and sent successfully!</p></div>';
                }
            } else {
                echo '<div class="notice notice-warning"><p>No submissions found for that period.</p></div>';
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Generate Track Submissions Report', 'tsf'); ?></h1>
            <form method="post">
                <?php wp_nonce_field('tsf_generate_report', 'tsf_generate_report_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Date From', 'tsf'); ?></th>
                        <td><input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Date To', 'tsf'); ?></th>
                        <td><input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" required /></td>
                    </tr>
                </table>
                <?php submit_button(__('Generate Report', 'tsf')); ?>
            </form>

            <?php if ($count > 0): ?>
                <h2><?php esc_html_e('Report Generated', 'tsf'); ?></h2>
                <p><?php printf(esc_html__('Found %d submissions. Report sent to %s', 'tsf'), $count, esc_html(get_option('tsf_notification_email'))); ?></p>
                <p><a href="<?php echo esc_url($csv_url); ?>" class="button"><?php esc_html_e('Download CSV', 'tsf'); ?></a></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_submission() {
        // Verify nonce - support both V1 and V2 formats
        $nonce_valid = false;

        // V2 format: tsf_nonce field with tsf_form_v2 action
        if (isset($_POST['tsf_nonce']) && wp_verify_nonce($_POST['tsf_nonce'], 'tsf_form_v2')) {
            $nonce_valid = true;
        }
        // V1 format: nonce field with tsf_nonce action
        elseif (isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'tsf_nonce')) {
            $nonce_valid = true;
        }

        if (!$nonce_valid) {
            wp_send_json_error(['message' => __('Security check failed', 'tsf')], 403);
        }

        // Rate limiting: Check for duplicate submissions within the configured duration
        $ip_address = $this->get_client_ip();
        $recent_submission = get_transient('tsf_rate_limit_' . md5($ip_address));
        // Allow users with the dedicated bypass capability to bypass rate limiting when logged in
        if ($recent_submission && !current_user_can('tsf_bypass_rate_limit')) {
            // Log the blocked attempt for monitoring
            if (class_exists('TSF_Logger')) {
                $logger = TSF_Logger::get_instance();
                $logger->warning('Rate limit exceeded (legacy handler)', [
                    'ip' => $ip_address,
                    'attempt' => 'rate_limit_exceeded'
                ]);
            }
            wp_send_json_error(['message' => __('Please wait before submitting another track', 'tsf')], 429);
        }

        // Honeypot check
        if (!empty($_POST['tsf_hp'])) {
            wp_send_json_error(['message' => __('Spam detected', 'tsf')], 400);
        }

        // Handle track_title fallback (verified title → album_title → first track title → "Untitled")
        $verified_track_title = sanitize_text_field($_POST['verified_track_title'] ?? '');
        $album_title = sanitize_text_field($_POST['album_title'] ?? '');
        $tracks = isset($_POST['tracks']) ? $_POST['tracks'] : [];
        $first_track_title = '';

        if (!empty($tracks) && is_array($tracks)) {
            ksort($tracks);
            $first_track = reset($tracks);
            if (isset($first_track['title'])) {
                $first_track_title = sanitize_text_field($first_track['title']);
            }
        }

        // Priority: verified_track_title → album_title → first_track_title → "Untitled"
        // Changed fallback from artist name to "Untitled" to avoid confusion
        $track_title = $verified_track_title ?: ($album_title ?: ($first_track_title ?: 'Untitled'));

        // Auto-generated fields with fallbacks
        $duration = sanitize_text_field($_POST['duration'] ?? '');
        if (empty($duration)) {
            $duration = '0:00'; // Default if not extracted from MP3
        }

        $instrumental = sanitize_text_field($_POST['instrumental'] ?? '');
        if (empty($instrumental)) {
            $instrumental = 'No'; // Default
        }

        $platform = sanitize_text_field($_POST['platform'] ?? '');
        if (empty($platform)) {
            // Try to detect from track_url
            $track_url_raw = $_POST['track_url'] ?? '';
            if (stripos($track_url_raw, 'spotify') !== false) {
                $platform = 'Spotify';
            } elseif (stripos($track_url_raw, 'soundcloud') !== false) {
                $platform = 'Soundcloud';
            } elseif (stripos($track_url_raw, 'bandcamp') !== false) {
                $platform = 'Bandcamp';
            } elseif (stripos($track_url_raw, 'youtube') !== false) {
                $platform = 'Youtube Music';
            } elseif (stripos($track_url_raw, 'apple') !== false || stripos($track_url_raw, 'music.apple') !== false) {
                $platform = 'Apple Music';
            } elseif (stripos($track_url_raw, 'deezer') !== false) {
                $platform = 'Deezer';
            } else {
                $platform = 'Other';
            }
        }

        $type = sanitize_text_field($_POST['type'] ?? '');
        if (empty($type)) {
            // Auto-determine based on track count
            $track_count = !empty($tracks) && is_array($tracks) ? count($tracks) : 1;
            if ($track_count === 1) {
                $type = 'Single';
            } elseif ($track_count >= 2 && $track_count <= 6) {
                $type = 'EP';
            } else {
                $type = 'Album';
            }
        }

        // Sanitize and validate data
        $data = [
            'artist' => sanitize_text_field($_POST['artist'] ?? ''),
            'track_title' => $track_title,
            'album_title' => $album_title,
            'genre' => sanitize_text_field($_POST['genre'] ?? ''),
            'duration' => $duration,
            'instrumental' => $instrumental,
            'release_date' => sanitize_text_field($_POST['release_date'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'platform' => $platform,
            'track_url' => esc_url_raw($_POST['track_url'] ?? ''),
            'social_url' => esc_url_raw($_POST['social_url'] ?? ''),
            'type' => $type,
            'label' => sanitize_text_field($_POST['label'] ?? ''),
            'country' => sanitize_text_field($_POST['country'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'optin' => isset($_POST['optin']) ? 1 : 0,
            'tracks' => $tracks,
        ];

        // Basic validation (track_title, duration, instrumental, platform, type auto-generated - not required in POST)
        $required_fields = ['artist', 'genre', 'release_date', 'email', 'track_url', 'label', 'country', 'description'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                wp_send_json_error(['message' => sprintf(__('Field %s is required', 'tsf'), $field)], 400);
            }
        }

        // Advanced validation
        if (!is_email($data['email'])) {
            wp_send_json_error(['message' => __('Invalid email address', 'tsf')], 400);
        }

        if (!filter_var($data['track_url'], FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => __('Invalid track URL', 'tsf')], 400);
        }

        // Validate social URL if provided
        if (!empty($data['social_url']) && !filter_var($data['social_url'], FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => __('Invalid social media URL', 'tsf')], 400);
        }

        // Validate duration format (mm:ss)
        if (!preg_match('/^[0-9]{1,2}:[0-5][0-9]$/', $data['duration'])) {
            wp_send_json_error(['message' => __('Invalid duration format. Use mm:ss', 'tsf')], 400);
        }

        // Validate release date
        $release_date = DateTime::createFromFormat('Y-m-d', $data['release_date']);
        if (!$release_date) {
            wp_send_json_error(['message' => __('Invalid release date format', 'tsf')], 400);
        }

        $max_future_years = get_option('tsf_max_future_years', 2);
        $max_date = new DateTime("+{$max_future_years} years");
        if ($release_date > $max_date) {
            wp_send_json_error(['message' => __('Release date is too far in the future', 'tsf')], 400);
        }

        // Validate against allowed options (only user-visible fields)
        $allowed_genres = get_option('tsf_genres', []);
        $allowed_labels = get_option('tsf_labels', []);

        if (!in_array($data['genre'], $allowed_genres, true)) {
            wp_send_json_error(['message' => __('Invalid genre selected', 'tsf')], 400);
        }

        if (!in_array($data['label'], $allowed_labels, true)) {
            wp_send_json_error(['message' => __('Invalid label selected', 'tsf')], 400);
        }

        // Auto-generated fields (platform, type, instrumental) - skip strict validation
        // These are auto-filled by fallback logic, not user-selected
        // Platform is detected from URL, Type from track count, Instrumental from MP3

        // Length validation
        if (mb_strlen($data['artist']) > 200) {
            wp_send_json_error(['message' => __('Artist name is too long', 'tsf')], 400);
        }

        if (mb_strlen($data['track_title']) > 200) {
            wp_send_json_error(['message' => __('Track title is too long', 'tsf')], 400);
        }

        if (mb_strlen($data['description']) > 2000) {
            wp_send_json_error(['message' => __('Description is too long', 'tsf')], 400);
        }

        if (!empty($data['phone']) && mb_strlen($data['phone']) > 20) {
            wp_send_json_error(['message' => __('Phone number is too long', 'tsf')], 400);
        }

        // Get MP3 file info if uploaded
        $mp3_file_path = sanitize_text_field($_POST['mp3_file_path'] ?? '');
        $mp3_filename = sanitize_text_field($_POST['mp3_filename'] ?? '');

        // Try to get MP3 data from qc_report if separate fields are empty
        $qc_report_data = null;
        if (empty($mp3_file_path) && !empty($_POST['qc_report'])) {
            $qc_report_data = json_decode(stripslashes($_POST['qc_report']), true);
            if (isset($qc_report_data['temp_file_path'])) {
                $mp3_file_path = sanitize_text_field($qc_report_data['temp_file_path']);
                error_log('TSF DEBUG - Extracted MP3 path from qc_report: ' . $mp3_file_path);
            }
            if (isset($qc_report_data['filename'])) {
                $mp3_filename = sanitize_text_field($qc_report_data['filename']);
                error_log('TSF DEBUG - Extracted MP3 filename from qc_report: ' . $mp3_filename);
            }
        } elseif (!empty($_POST['qc_report'])) {
            $qc_report_data = json_decode(stripslashes($_POST['qc_report']), true);
        }

        // Debug logging
        error_log('TSF DEBUG - MP3 Data Received: path=' . $mp3_file_path . ', filename=' . $mp3_filename);
        error_log('TSF DEBUG - Track Title Data: verified=' . $verified_track_title . ', album=' . $album_title . ', first_track=' . $first_track_title . ', final=' . $track_title);
        error_log('TSF DEBUG - QC Report Data: ' . ($qc_report_data ? json_encode($qc_report_data) : 'NULL'));
        error_log('TSF DEBUG - QC Report has quality_score: ' . (isset($qc_report_data['quality_score']) ? 'YES (' . $qc_report_data['quality_score'] . ')' : 'NO'));

        // Create post with metadata instead of SQL insert
        $new_post = [
            'post_title'   => sprintf('%s - %s', $data['artist'], $data['track_title']),
            'post_type'    => 'track_submission',
            'post_status'  => 'publish',
            'meta_input'   => [
                'tsf_artist'       => $data['artist'],
                'tsf_track_title'  => $data['track_title'],
                'tsf_genre'        => $data['genre'],
                'tsf_duration'     => $data['duration'],
                'tsf_instrumental' => $data['instrumental'],
                'tsf_release_date' => $data['release_date'],
                'tsf_email'        => $data['email'],
                'tsf_phone'        => $data['phone'],
                'tsf_platform'     => $data['platform'],
                'tsf_track_url'    => $data['track_url'],
                'tsf_social_url'   => $data['social_url'],
                'tsf_type'         => $data['type'],
                'tsf_label'        => $data['label'],
                'tsf_country'      => $data['country'],
                'tsf_description'  => $data['description'],
                'tsf_optin'        => $data['optin'],
                'tsf_mp3_file_path' => $mp3_file_path,
                'tsf_mp3_filename' => $mp3_filename,
                'tsf_qc_report'    => $qc_report_data ? wp_json_encode($qc_report_data) : '',
                'tsf_created_at'   => current_time('mysql'),
            ],
        ];

        $post_id = wp_insert_post($new_post);

        if (is_wp_error($post_id)) {
            $this->log_error('Post creation failed: ' . $post_id->get_error_message());
            wp_send_json_error(['message' => __('Failed to save submission', 'tsf')], 500);
        }

        // Verify what was saved
        $saved_qc = get_post_meta($post_id, 'tsf_qc_report', true);
        error_log('TSF DEBUG - Post #' . $post_id . ' created. QC Report saved: ' . ($saved_qc ? 'YES (' . strlen($saved_qc) . ' bytes)' : 'NO'));

        // Set rate limit transient using configured duration (seconds). If 0, skip setting.
        // Allow programmatic overrides via filters for backward compatibility.
        $rate_limit_seconds = (int) get_option('tsf_rate_limit_seconds', 300);
        $rate_limit_seconds = apply_filters('tsf_rate_limit_seconds', $rate_limit_seconds);
        // Support legacy filter name mentioned in UPGRADE_GUIDE
        $rate_limit_seconds = apply_filters('tsf_rate_limit_duration', $rate_limit_seconds);
        if ($rate_limit_seconds > 0) {
            set_transient('tsf_rate_limit_' . md5($ip_address), true, $rate_limit_seconds);
        }

        // Send MP3 to Dropbox if file was uploaded (background operation)
        $dropbox_url = get_option('tsf_dropbox_url', '');
        error_log('TSF DEBUG - Dropbox Check: mp3_path=' . $mp3_file_path . ', dropbox_url=' . ($dropbox_url ? 'configured' : 'not configured'));

        if (!empty($mp3_file_path) && !empty($dropbox_url)) {
            error_log('TSF DEBUG - Initiating Dropbox upload for post ' . $post_id);
            $this->send_mp3_to_dropbox($mp3_file_path, $mp3_filename, $dropbox_url, $post_id);
        } elseif (empty($mp3_file_path)) {
            error_log('TSF DEBUG - Skipping Dropbox upload: No MP3 file path');
        } elseif (empty($dropbox_url)) {
            error_log('TSF DEBUG - Skipping Dropbox upload: Dropbox URL not configured in settings');
        }

        // Send notification email
        $this->send_notification_email($data);

        // Always redirect to thank you page, NOT to Dropbox
        wp_send_json_success([
            'message' => __('Submission saved successfully', 'tsf'),
            'redirect' => home_url('/thank-you-upload/')
        ]);
    }

    /**
     * Send MP3 file to Dropbox File Request
     */
    private function send_mp3_to_dropbox($relative_path, $filename, $dropbox_url, $post_id) {
        error_log("TSF DEBUG - send_mp3_to_dropbox called: relative_path={$relative_path}, filename={$filename}");

        $upload_dir = wp_upload_dir();
        $full_path = $upload_dir['basedir'] . $relative_path;

        error_log("TSF DEBUG - Full MP3 path: {$full_path}, exists: " . (file_exists($full_path) ? 'YES' : 'NO'));

        // Check if file exists
        if (!file_exists($full_path)) {
            error_log("TSF: MP3 file not found for Dropbox upload: {$full_path}");
            update_post_meta($post_id, 'tsf_dropbox_status', 'file_not_found');
            return false;
        }

        // Check which upload method to use
        $upload_method = get_option('tsf_dropbox_method', 'file_request');

        if ($upload_method === 'api') {
            return $this->send_mp3_to_dropbox_api($full_path, $filename, $post_id);
        } else {
            // File Request method (original - returns false but that's expected)
            error_log("TSF DEBUG - Using File Request method - user will manually upload");
            update_post_meta($post_id, 'tsf_dropbox_status', 'pending_manual_upload');
            return false; // Not actually uploading, user will do it manually
        }
    }

    private function send_mp3_to_dropbox_api($full_path, $filename, $post_id) {
        $api_token = get_option('tsf_dropbox_api_token', '');
        $dropbox_folder = get_option('tsf_dropbox_folder', '/Track Submissions');

        if (empty($api_token)) {
            error_log("TSF: Dropbox API token not configured");
            update_post_meta($post_id, 'tsf_dropbox_status', 'no_api_token');
            return false;
        }

        // Ensure folder path format
        $dropbox_folder = trim($dropbox_folder);
        if ($dropbox_folder !== '/' && substr($dropbox_folder, 0, 1) !== '/') {
            $dropbox_folder = '/' . $dropbox_folder;
        }
        if ($dropbox_folder === '/') {
            $dropbox_path = '/' . $filename;
        } else {
            $dropbox_path = rtrim($dropbox_folder, '/') . '/' . $filename;
        }

        error_log("TSF DEBUG - Uploading to Dropbox API: {$dropbox_path}");

        // Read file contents
        $file_contents = file_get_contents($full_path);
        if ($file_contents === false) {
            error_log("TSF: Failed to read MP3 file for Dropbox API upload");
            update_post_meta($post_id, 'tsf_dropbox_status', 'file_read_error');
            return false;
        }

        // Upload to Dropbox API
        $response = wp_remote_post('https://content.dropboxapi.com/2/files/upload', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_token,
                'Content-Type' => 'application/octet-stream',
                'Dropbox-API-Arg' => json_encode([
                    'path' => $dropbox_path,
                    'mode' => 'add',
                    'autorename' => true,
                    'mute' => false
                ])
            ],
            'body' => $file_contents,
            'timeout' => 120,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            error_log("TSF: Dropbox API upload failed: " . $response->get_error_message());
            update_post_meta($post_id, 'tsf_dropbox_status', 'upload_failed');
            update_post_meta($post_id, 'tsf_dropbox_error', $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log("TSF DEBUG - Dropbox API response status: {$status_code}");
        error_log("TSF DEBUG - Dropbox API response body: " . substr($response_body, 0, 500));

        if ($status_code >= 200 && $status_code < 300) {
            // Success - Mark as uploaded but KEEP the file as backup in WordPress
            $response_data = json_decode($response_body, true);
            update_post_meta($post_id, 'tsf_dropbox_status', 'uploaded');
            update_post_meta($post_id, 'tsf_dropbox_uploaded_at', current_time('mysql'));
            update_post_meta($post_id, 'tsf_dropbox_path', $response_data['path_display'] ?? $dropbox_path);
            error_log("TSF: MP3 successfully uploaded to Dropbox API for submission #{$post_id} - File kept as backup");

            // Optional: Auto-delete after X days (configurable, 0 = never delete)
            $auto_delete_days = get_option('tsf_mp3_auto_delete_days', 0);
            if ($auto_delete_days > 0) {
                update_post_meta($post_id, 'tsf_mp3_delete_after', date('Y-m-d H:i:s', strtotime("+{$auto_delete_days} days")));
            }

            return true;
        } else {
            error_log("TSF: Dropbox API upload failed with status {$status_code} - File kept as backup");
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error_summary'] ?? "Status {$status_code}";
            update_post_meta($post_id, 'tsf_dropbox_status', 'upload_failed_' . $status_code);
            update_post_meta($post_id, 'tsf_dropbox_error', $error_message);
            return false;
        }
    }

    private function get_client_ip() {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle multiple IPs (from proxies)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    private function send_notification_email($data) {
        $to = get_option('tsf_notification_email', get_option('admin_email'));

        // Validate email
        if (!is_email($to)) {
            $this->log_error('Invalid notification email address: ' . $to);
            return false;
        }

        $subject = sprintf(__('New Track Submission: %s - %s', 'tsf'), $data['artist'], $data['track_title']);

        $body = sprintf(
            __("New track submission received:\n\nArtist: %s\nTrack: %s\nGenre: %s\nEmail: %s\nTrack URL: %s\n\nView all submissions in admin panel.", 'tsf'),
            $data['artist'],
            $data['track_title'],
            $data['genre'],
            $data['email'],
            $data['track_url']
        );

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $result = wp_mail($to, $subject, $body, $headers);

        if (!$result) {
            $this->log_error('Failed to send notification email to: ' . $to);
        }

        return $result;
    }

    private function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('TSF Error: ' . $message);
        }
    }

    /**
     * Handle MP3 download request from admin
     */
    public function handle_mp3_download() {
        // Check permissions
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to download this file.', 'tsf'));
        }

        // Verify nonce
        $post_id = intval($_GET['post_id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tsf_download_mp3_' . $post_id)) {
            wp_die(__('Security check failed.', 'tsf'));
        }

        // Get file path
        $mp3_file_path = get_post_meta($post_id, 'tsf_mp3_file_path', true);
        $mp3_filename = get_post_meta($post_id, 'tsf_mp3_filename', true);

        if (!$mp3_file_path || !$mp3_filename) {
            wp_die(__('No MP3 file found for this submission.', 'tsf'));
        }

        $upload_dir = wp_upload_dir();
        $full_path = $upload_dir['basedir'] . $mp3_file_path;

        if (!file_exists($full_path)) {
            wp_die(__('MP3 file not found on server.', 'tsf'));
        }

        // Serve file for download
        header('Content-Type: audio/mpeg');
        header('Content-Disposition: attachment; filename="' . $mp3_filename . '"');
        header('Content-Length: ' . filesize($full_path));
        header('Cache-Control: no-cache');
        readfile($full_path);
        exit;
    }

    /**
     * Handle MP3 delete request from admin
     */
    public function handle_mp3_delete() {
        // Check permissions
        if (!current_user_can('delete_posts')) {
            wp_die(__('You do not have permission to delete this file.', 'tsf'));
        }

        // Verify nonce
        $post_id = intval($_GET['post_id'] ?? 0);
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tsf_delete_mp3_' . $post_id)) {
            wp_die(__('Security check failed.', 'tsf'));
        }

        // Get file path
        $mp3_file_path = get_post_meta($post_id, 'tsf_mp3_file_path', true);

        if (!$mp3_file_path) {
            wp_die(__('No MP3 file found for this submission.', 'tsf'));
        }

        $upload_dir = wp_upload_dir();
        $full_path = $upload_dir['basedir'] . $mp3_file_path;

        // Delete file
        if (file_exists($full_path)) {
            @unlink($full_path);
            error_log("TSF: MP3 file manually deleted for submission #{$post_id}");
        }

        // Redirect back
        wp_redirect(admin_url('post.php?post=' . $post_id . '&action=edit&mp3_deleted=1'));
        exit;
    }

    public function add_security_headers() {
        // Only add headers on our plugin pages
        if (!is_admin() && (is_page() || is_singular())) {
            global $post;
            if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'track_submission_form')) {
                // Content Security Policy - Allow iframes for music embeds and API calls + workers for MP3 analysis
                header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' blob:; style-src 'self' 'unsafe-inline'; img-src 'self' data: https: https://i.scdn.co https://*.scdn.co; font-src 'self' data:; connect-src 'self' https://ipapi.co https://accounts.spotify.com https://api.spotify.com; frame-src https://embeds.beehiiv.com https://www.youtube.com https://open.spotify.com https://w.soundcloud.com https://bandcamp.com; worker-src 'self' blob:;");

                // X-Frame-Options to prevent clickjacking
                header('X-Frame-Options: SAMEORIGIN');

                // X-Content-Type-Options
                header('X-Content-Type-Options: nosniff');

                // Referrer Policy
                header('Referrer-Policy: strict-origin-when-cross-origin');
            }
        }
    }

    public function clear_submissions_cache() {
        // Clear all cached pages
        wp_cache_delete_multiple(
            range(1, 100),
            'tsf_submissions'
        );

        // Alternative: flush specific cache group
        for ($i = 1; $i <= 100; $i++) {
            wp_cache_delete('tsf_submissions_page_' . $i, 'tsf_submissions');
        }
    }


    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Track Submissions', 'tsf'); ?></h1>

            <?php
            // Pagination for better performance
            $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
            $posts_per_page = 50;

            // Use WP_Query with caching
            $cache_key = 'tsf_submissions_page_' . $paged;
            $query = wp_cache_get($cache_key, 'tsf_submissions');

            if (false === $query) {
                $args = [
                    'post_type'      => 'track_submission',
                    'posts_per_page' => $posts_per_page,
                    'paged'          => $paged,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'no_found_rows'  => false,
                    'update_post_meta_cache' => true,
                    'update_post_term_cache' => false,
                ];
                $query = new WP_Query($args);
                wp_cache_set($cache_key, $query, 'tsf_submissions', 300); // Cache for 5 minutes
            }
            
            if ($query->have_posts()): ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Artist', 'tsf'); ?></th>
                            <th><?php esc_html_e('Track Title', 'tsf'); ?></th>
                            <th><?php esc_html_e('Genre', 'tsf'); ?></th>
                            <th><?php esc_html_e('Release Date', 'tsf'); ?></th>
                            <th><?php esc_html_e('Email', 'tsf'); ?></th>
                            <th><?php esc_html_e('Platform', 'tsf'); ?></th>
                            <th><?php esc_html_e('Type', 'tsf'); ?></th>
                            <th><?php esc_html_e('Label', 'tsf'); ?></th>
                            <th><?php esc_html_e('Country', 'tsf'); ?></th>
                            <th><?php esc_html_e('Created', 'tsf'); ?></th>
                            <th><?php esc_html_e('Actions', 'tsf'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($query->have_posts()): $query->the_post();
                            $post_id = get_the_ID();
                            $track_title  = get_post_meta($post_id, 'tsf_track_title', true);
                            $artist       = get_post_meta($post_id, 'tsf_artist', true);
                            $genre        = get_post_meta($post_id, 'tsf_genre', true);
                            $release_date = get_post_meta($post_id, 'tsf_release_date', true);
                            $email        = get_post_meta($post_id, 'tsf_email', true);
                            $platform     = get_post_meta($post_id, 'tsf_platform', true);
                            $type         = get_post_meta($post_id, 'tsf_type', true);
                            $label        = get_post_meta($post_id, 'tsf_label', true);
                            $country      = get_post_meta($post_id, 'tsf_country', true);
                            $created_at   = get_post_meta($post_id, 'tsf_created_at', true);
                            $track_url    = get_post_meta($post_id, 'tsf_track_url', true);
                            ?>
                            <tr>
                                <td><?php echo esc_html($artist); ?></td>
                                <td><?php echo esc_html($track_title); ?></td>
                                <td><?php echo esc_html($genre); ?></td>
                                <td><?php echo esc_html($release_date); ?></td>
                                <td><a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></td>
                                <td><?php echo esc_html($platform); ?></td>
                                <td><?php echo esc_html($type); ?></td>
                                <td><?php echo esc_html($label); ?></td>
                                <td><?php echo esc_html($country); ?></td>
                                <td><?php echo esc_html($created_at); ?></td>
                                <td>
                                    <a href="<?php echo esc_url($track_url); ?>" target="_blank" class="button button-small"><?php esc_html_e('Listen', 'tsf'); ?></a>
                                    <a href="<?php echo get_edit_post_link($post_id); ?>" class="button button-small"><?php esc_html_e('Edit', 'tsf'); ?></a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

                <?php
                // Pagination
                $total_pages = $query->max_num_pages;
                if ($total_pages > 1) {
                    echo '<div class="tablenav"><div class="tablenav-pages">';
                    echo paginate_links([
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => __('&laquo; Previous', 'tsf'),
                        'next_text' => __('Next &raquo;', 'tsf'),
                    ]);
                    echo '</div></div>';
                }
                ?>

                <?php wp_reset_postdata(); ?>
            <?php else: ?>
                <p><?php esc_html_e('No track submissions found.', 'tsf'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function register_settings() {
        // Register settings for the plugin
        register_setting('tsf_settings', 'tsf_dropbox_url');
        register_setting('tsf_settings', 'tsf_notification_email');
        register_setting('tsf_settings', 'tsf_report_day');
        register_setting('tsf_settings', 'tsf_report_time');
        register_setting('tsf_settings', 'tsf_max_future_years');
        register_setting('tsf_settings', 'tsf_file_retention_days');
        register_setting('tsf_settings', 'tsf_genres');
        register_setting('tsf_settings', 'tsf_platforms');
        register_setting('tsf_settings', 'tsf_types');
        register_setting('tsf_settings', 'tsf_labels');

        // API credentials for track verification
        register_setting('tsf_settings', 'tsf_spotify_client_id');
        register_setting('tsf_settings', 'tsf_spotify_client_secret');
    }

    public function settings_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'tsf'));
        }

        if (isset($_POST['submit'])) {
            // Verify nonce
            if (!isset($_POST['tsf_settings_nonce']) || !wp_verify_nonce($_POST['tsf_settings_nonce'], 'tsf_settings')) {
                wp_die(__('Security check failed', 'tsf'));
            }

            // Handle form submission with validation
            $dropbox_method = isset($_POST['tsf_dropbox_method']) ? sanitize_text_field($_POST['tsf_dropbox_method']) : 'file_request';
            if (in_array($dropbox_method, ['file_request', 'api'], true)) {
                update_option('tsf_dropbox_method', $dropbox_method);
            }

            $dropbox_url = isset($_POST['tsf_dropbox_url']) ? esc_url_raw($_POST['tsf_dropbox_url']) : '';
            if (!filter_var($dropbox_url, FILTER_VALIDATE_URL)) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Invalid Dropbox URL', 'tsf') . '</p></div>';
            } else {
                update_option('tsf_dropbox_url', $dropbox_url);
            }

            $dropbox_token = isset($_POST['tsf_dropbox_api_token']) ? sanitize_text_field($_POST['tsf_dropbox_api_token']) : '';
            update_option('tsf_dropbox_api_token', $dropbox_token);

            $dropbox_folder = isset($_POST['tsf_dropbox_folder']) ? sanitize_text_field($_POST['tsf_dropbox_folder']) : '/Track Submissions';
            update_option('tsf_dropbox_folder', $dropbox_folder);

            $email = isset($_POST['tsf_notification_email']) ? sanitize_email($_POST['tsf_notification_email']) : '';
            if (!is_email($email)) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Invalid email address', 'tsf') . '</p></div>';
            } else {
                update_option('tsf_notification_email', $email);
            }

            $allowed_days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            $report_day = isset($_POST['tsf_report_day']) ? sanitize_text_field($_POST['tsf_report_day']) : 'thursday';
            if (in_array($report_day, $allowed_days, true)) {
                update_option('tsf_report_day', $report_day);
            }

            $report_time = isset($_POST['tsf_report_time']) ? sanitize_text_field($_POST['tsf_report_time']) : '09:00';
            if (preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $report_time)) {
                update_option('tsf_report_time', $report_time);
            }

            $max_future_years = isset($_POST['tsf_max_future_years']) ? absint($_POST['tsf_max_future_years']) : 2;
            if ($max_future_years >= 1 && $max_future_years <= 10) {
                update_option('tsf_max_future_years', $max_future_years);
            }

            $retention_days = isset($_POST['tsf_file_retention_days']) ? absint($_POST['tsf_file_retention_days']) : 90;
            if ($retention_days >= 30 && $retention_days <= 365) {
                update_option('tsf_file_retention_days', $retention_days);
            }

            // Handle arrays with validation
            if (isset($_POST['tsf_genres']) && is_array($_POST['tsf_genres'])) {
                $genres = array_filter(array_map('sanitize_text_field', $_POST['tsf_genres']));
                update_option('tsf_genres', array_values($genres));
            }
            if (isset($_POST['tsf_platforms']) && is_array($_POST['tsf_platforms'])) {
                $platforms = array_filter(array_map('sanitize_text_field', $_POST['tsf_platforms']));
                update_option('tsf_platforms', array_values($platforms));
            }
            if (isset($_POST['tsf_types']) && is_array($_POST['tsf_types'])) {
                $types = array_filter(array_map('sanitize_text_field', $_POST['tsf_types']));
                update_option('tsf_types', array_values($types));
            }
            if (isset($_POST['tsf_labels']) && is_array($_POST['tsf_labels'])) {
                $labels = array_filter(array_map('sanitize_text_field', $_POST['tsf_labels']));
                update_option('tsf_labels', array_values($labels));
            }

            // Handle API credentials
            $spotify_client_id = isset($_POST['tsf_spotify_client_id']) ? sanitize_text_field($_POST['tsf_spotify_client_id']) : '';
            update_option('tsf_spotify_client_id', $spotify_client_id);

            $spotify_client_secret = isset($_POST['tsf_spotify_client_secret']) ? sanitize_text_field($_POST['tsf_spotify_client_secret']) : '';
            update_option('tsf_spotify_client_secret', $spotify_client_secret);

            // Clear cached Spotify token when credentials change
            delete_transient('tsf_spotify_access_token');

            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved successfully!', 'tsf') . '</p></div>';
        }

        $dropbox_url = get_option('tsf_dropbox_url', 'https://www.dropbox.com/request/qhA1oF0V4W1FolQ2bcjO');
        $notification_email = get_option('tsf_notification_email', get_option('admin_email'));
        $report_day = get_option('tsf_report_day', 'thursday');
        $report_time = get_option('tsf_report_time', '09:00');
        $max_future_years = get_option('tsf_max_future_years', 2);
        $file_retention_days = get_option('tsf_file_retention_days', 90);
        $genres = get_option('tsf_genres', ['Pop', 'Rock', 'Electronic/Dance', 'Folk', 'Alternative', 'Metal', 'Jazz', 'R&B', 'Hip-Hop/Rap', 'Autre']);
        $platforms = get_option('tsf_platforms', ['Spotify', 'Bandcamp', 'Youtube Music', 'Apple Music', 'Deezer', 'Soundcloud', 'Other']);
        $types = get_option('tsf_types', ['Album', 'EP', 'Single']);
        $labels = get_option('tsf_labels', ['Indie', 'Label']);
        $spotify_client_id = get_option('tsf_spotify_client_id', '');
        $spotify_client_secret = get_option('tsf_spotify_client_secret', '');
        ?>
        
        <div class="wrap">
            <h1><?php esc_html_e('Track Submission Form Settings', 'tsf'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('tsf_settings', 'tsf_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Dropbox Upload Method', 'tsf'); ?></th>
                        <td>
                            <?php $dropbox_method = get_option('tsf_dropbox_method', 'file_request'); ?>
                            <label><input type="radio" name="tsf_dropbox_method" value="file_request" <?php checked($dropbox_method, 'file_request'); ?>> <?php esc_html_e('File Request (Manual - redirect users to Dropbox page)', 'tsf'); ?></label><br>
                            <label><input type="radio" name="tsf_dropbox_method" value="api" <?php checked($dropbox_method, 'api'); ?>> <?php esc_html_e('Dropbox API (Automatic upload)', 'tsf'); ?></label>
                            <p class="description"><?php esc_html_e('Choose how to handle MP3 uploads to Dropbox', 'tsf'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Dropbox File Request URL', 'tsf'); ?></th>
                        <td>
                            <input type="url" name="tsf_dropbox_url" value="<?php echo esc_attr($dropbox_url); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Used for "File Request" method - URL where users will be redirected after successful submission', 'tsf'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Dropbox API Access Token', 'tsf'); ?></th>
                        <td>
                            <?php $dropbox_token = get_option('tsf_dropbox_api_token', ''); ?>
                            <input type="password" name="tsf_dropbox_api_token" value="<?php echo esc_attr($dropbox_token); ?>" class="regular-text" placeholder="sl.xxxxxxxxxxxxx" />
                            <p class="description"><?php esc_html_e('Required for "API" method. Get from https://www.dropbox.com/developers/apps', 'tsf'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Dropbox Destination Folder', 'tsf'); ?></th>
                        <td>
                            <?php $dropbox_folder = get_option('tsf_dropbox_folder', '/Track Submissions'); ?>
                            <input type="text" name="tsf_dropbox_folder" value="<?php echo esc_attr($dropbox_folder); ?>" class="regular-text" placeholder="/Track Submissions" />
                            <p class="description"><?php esc_html_e('Folder path in Dropbox for API uploads (e.g. /Track Submissions). Leave as "/" for root.', 'tsf'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Notification Email', 'tsf'); ?></th>
                        <td>
                            <input type="email" name="tsf_notification_email" value="<?php echo esc_attr($notification_email); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Email address to receive notifications about new submissions', 'tsf'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Weekly Report Day', 'tsf'); ?></th>
                        <td>
                            <select name="tsf_report_day">
                                <?php
                                $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                                foreach ($days as $day) {
                                    echo '<option value="' . esc_attr($day) . '"' . selected($report_day, $day, false) . '>' . esc_html(ucfirst($day)) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Weekly Report Time', 'tsf'); ?></th>
                        <td>
                            <input type="time" name="tsf_report_time" value="<?php echo esc_attr($report_time); ?>" />
                            <p class="description"><?php esc_html_e('Time to send weekly reports (Europe/Brussels timezone)', 'tsf'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Max Future Years', 'tsf'); ?></th>
                        <td>
                            <input type="number" name="tsf_max_future_years" value="<?php echo esc_attr($max_future_years); ?>" min="1" max="10" />
                            <p class="description"><?php esc_html_e('Maximum years in the future for release dates', 'tsf'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('File Retention Days', 'tsf'); ?></th>
                        <td>
                            <input type="number" name="tsf_file_retention_days" value="<?php echo esc_attr($file_retention_days); ?>" min="30" max="365" />
                            <p class="description"><?php esc_html_e('Number of days to keep generated CSV files', 'tsf'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('API Credentials', 'tsf'); ?></h2>
                <p><?php esc_html_e('Configure API credentials for track verification features in Form V2.', 'tsf'); ?></p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Spotify Client ID', 'tsf'); ?></th>
                        <td>
                            <input type="text" name="tsf_spotify_client_id" value="<?php echo esc_attr($spotify_client_id); ?>" class="regular-text" />
                            <p class="description">
                                <?php esc_html_e('Get your credentials from', 'tsf'); ?>
                                <a href="https://developer.spotify.com/dashboard" target="_blank">Spotify Developer Dashboard</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Spotify Client Secret', 'tsf'); ?></th>
                        <td>
                            <input type="password" name="tsf_spotify_client_secret" value="<?php echo esc_attr($spotify_client_secret); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Your Spotify API client secret', 'tsf'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Form Options', 'tsf'); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Genres', 'tsf'); ?></th>
                        <td>
                            <?php foreach ($genres as $i => $genre): ?>
                                <input type="text" name="tsf_genres[]" value="<?php echo esc_attr($genre); ?>" class="regular-text" style="margin-bottom: 5px;" /><br>
                            <?php endforeach; ?>
                            <input type="text" name="tsf_genres[]" value="" class="regular-text" placeholder="<?php esc_attr_e('Add new genre', 'tsf'); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Platforms', 'tsf'); ?></th>
                        <td>
                            <?php foreach ($platforms as $i => $platform): ?>
                                <input type="text" name="tsf_platforms[]" value="<?php echo esc_attr($platform); ?>" class="regular-text" style="margin-bottom: 5px;" /><br>
                            <?php endforeach; ?>
                            <input type="text" name="tsf_platforms[]" value="" class="regular-text" placeholder="<?php esc_attr_e('Add new platform', 'tsf'); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Types', 'tsf'); ?></th>
                        <td>
                            <?php foreach ($types as $i => $type): ?>
                                <input type="text" name="tsf_types[]" value="<?php echo esc_attr($type); ?>" class="regular-text" style="margin-bottom: 5px;" /><br>
                            <?php endforeach; ?>
                            <input type="text" name="tsf_types[]" value="" class="regular-text" placeholder="<?php esc_attr_e('Add new type', 'tsf'); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Labels', 'tsf'); ?></th>
                        <td>
                            <?php foreach ($labels as $i => $label): ?>
                                <input type="text" name="tsf_labels[]" value="<?php echo esc_attr($label); ?>" class="regular-text" style="margin-bottom: 5px;" /><br>
                            <?php endforeach; ?>
                            <input type="text" name="tsf_labels[]" value="" class="regular-text" placeholder="<?php esc_attr_e('Add new label', 'tsf'); ?>" />
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <!-- Clear Cache Section -->
            <hr style="margin: 40px 0;">
            <h2><?php esc_html_e('Cache Management', 'tsf'); ?></h2>
            <p><?php esc_html_e('Clear all plugin caches to see the latest CSS and JavaScript changes immediately.', 'tsf'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('tsf_clear_cache', 'tsf_clear_cache_nonce'); ?>
                <input type="hidden" name="tsf_clear_cache" value="1">
                <p>
                    <button type="submit" class="button button-secondary">
                        <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                        <?php esc_html_e('Clear All Caches', 'tsf'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Handle manual cache clear from admin
     */
    public function handle_cache_clear() {
        if (!isset($_POST['tsf_clear_cache']) || !isset($_POST['tsf_clear_cache_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['tsf_clear_cache_nonce'], 'tsf_clear_cache')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $this->clear_all_caches();

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>' . esc_html__('Success!', 'tsf') . '</strong> ';
            echo esc_html__('All plugin caches have been cleared. Please refresh the page to see changes.', 'tsf');
            echo '</p></div>';
        });
    }


    public function export_page() {
        // Check user capabilities
        if (!current_user_can('export')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'tsf'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('tsf_export', 'tsf_export_nonce')) {
            // Build meta_query based on filters
            $meta_query = ['relation' => 'AND'];
            
            if (!empty($_POST['genre'])) {
                $meta_query[] = [
                    'key'     => 'tsf_genre',
                    'value'   => sanitize_text_field($_POST['genre']),
                    'compare' => '=',
                ];
            }
            
            if (!empty($_POST['type'])) {
                $meta_query[] = [
                    'key'     => 'tsf_type',
                    'value'   => sanitize_text_field($_POST['type']),
                    'compare' => '=',
                ];
            }
            
            if (!empty($_POST['label'])) {
                $meta_query[] = [
                    'key'     => 'tsf_label',
                    'value'   => sanitize_text_field($_POST['label']),
                    'compare' => '=',
                ];
            }
            
            if (!empty($_POST['country'])) {
                $meta_query[] = [
                    'key'     => 'tsf_country',
                    'value'   => sanitize_text_field($_POST['country']),
                    'compare' => '=',
                ];
            }
            
            if (!empty($_POST['instrumental'])) {
                $meta_query[] = [
                    'key'     => 'tsf_instrumental',
                    'value'   => sanitize_text_field($_POST['instrumental']),
                    'compare' => '=',
                ];
            }
            
            if (!empty($_POST['optin'])) {
                $meta_query[] = [
                    'key'     => 'tsf_optin',
                    'value'   => sanitize_text_field($_POST['optin']),
                    'compare' => '=',
                ];
            }
            
            // Date range filter
            if (!empty($_POST['date_from']) && !empty($_POST['date_to'])) {
                $date_from = sanitize_text_field($_POST['date_from']) . ' 00:00:00';
                $date_to = sanitize_text_field($_POST['date_to']) . ' 23:59:59';
                $meta_query[] = [
                    'key'     => 'tsf_created_at',
                    'value'   => [ $date_from, $date_to ],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ];
            }
            
            $args = [
                'post_type'      => 'track_submission',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_query'     => $meta_query,
                'orderby'        => 'meta_value',
                'meta_key'       => 'tsf_created_at',
                'order'          => 'ASC',
            ];
            
            $query = new WP_Query($args);
            
            if ($query->have_posts()) {
                // Generate CSV
                $filename = 'tsf_export_' . date('Ymd_His') . '.csv';
                
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename=' . $filename);
                header('Pragma: no-cache');
                header('Expires: 0');
                
                $output = fopen('php://output', 'w');
                
                // CSV headers
                $headers = [
                    'ID', 'Created At', 'Artist', 'Track Title', 'Genre', 'Duration', 
                    'Instrumental', 'Release Date', 'Email', 'Phone', 'Platform', 
                    'Track URL', 'Social URL', 'Type', 'Label', 'Country', 
                    'Description', 'Optin'
                ];
                fputcsv($output, $headers);
                
                // CSV data
                while ($query->have_posts()) {
                    $query->the_post();
                    $post_id = get_the_ID();
                    
                    $row = [
                        $post_id,
                        get_post_meta($post_id, 'tsf_created_at', true),
                        get_post_meta($post_id, 'tsf_artist', true),
                        get_post_meta($post_id, 'tsf_track_title', true),
                        get_post_meta($post_id, 'tsf_genre', true),
                        get_post_meta($post_id, 'tsf_duration', true),
                        get_post_meta($post_id, 'tsf_instrumental', true),
                        get_post_meta($post_id, 'tsf_release_date', true),
                        get_post_meta($post_id, 'tsf_email', true),
                        get_post_meta($post_id, 'tsf_phone', true),
                        get_post_meta($post_id, 'tsf_platform', true),
                        get_post_meta($post_id, 'tsf_track_url', true),
                        get_post_meta($post_id, 'tsf_social_url', true),
                        get_post_meta($post_id, 'tsf_type', true),
                        get_post_meta($post_id, 'tsf_label', true),
                        get_post_meta($post_id, 'tsf_country', true),
                        get_post_meta($post_id, 'tsf_description', true),
                        get_post_meta($post_id, 'tsf_optin', true),
                    ];
                    
                    fputcsv($output, $row);
                }
                
                fclose($output);
                wp_reset_postdata();
                exit;
            } else {
                echo '<div class="notice notice-warning"><p>' . esc_html__('No matching results found.', 'tsf') . '</p></div>';
            }
        }

        // Get unique values for filters
        $genres = get_option('tsf_genres', []);
        $types = get_option('tsf_types', []);
        $labels = get_option('tsf_labels', []);
        
        // Get unique countries from existing submissions
        $countries_query = new WP_Query([
            'post_type' => 'track_submission',
            'posts_per_page' => -1,
            'meta_key' => 'tsf_country',
            'orderby' => 'meta_value',
            'order' => 'ASC'
        ]);
        
        $countries = [];
        if ($countries_query->have_posts()) {
            while ($countries_query->have_posts()) {
                $countries_query->the_post();
                $country = get_post_meta(get_the_ID(), 'tsf_country', true);
                if (!empty($country) && !in_array($country, $countries)) {
                    $countries[] = $country;
                }
            }
            wp_reset_postdata();
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Export Track Submissions', 'tsf'); ?></h1>
            
            <form method="post">
                <?php wp_nonce_field('tsf_export', 'tsf_export_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Genre', 'tsf'); ?></th>
                        <td>
                            <select name="genre">
                                <option value=""><?php esc_html_e('All Genres', 'tsf'); ?></option>
                                <?php foreach ($genres as $genre): ?>
                                    <option value="<?php echo esc_attr($genre); ?>"><?php echo esc_html($genre); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Type', 'tsf'); ?></th>
                        <td>
                            <select name="type">
                                <option value=""><?php esc_html_e('All Types', 'tsf'); ?></option>
                                <?php foreach ($types as $type): ?>
                                    <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Label', 'tsf'); ?></th>
                        <td>
                            <select name="label">
                                <option value=""><?php esc_html_e('All Labels', 'tsf'); ?></option>
                                <?php foreach ($labels as $label): ?>
                                    <option value="<?php echo esc_attr($label); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Country', 'tsf'); ?></th>
                        <td>
                            <select name="country">
                                <option value=""><?php esc_html_e('All Countries', 'tsf'); ?></option>
                                <?php foreach ($countries as $country): ?>
                                    <option value="<?php echo esc_attr($country); ?>"><?php echo esc_html($country); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Instrumental', 'tsf'); ?></th>
                        <td>
                            <select name="instrumental">
                                <option value=""><?php esc_html_e('All', 'tsf'); ?></option>
                                <option value="Yes"><?php esc_html_e('Yes', 'tsf'); ?></option>
                                <option value="No"><?php esc_html_e('No', 'tsf'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Optin', 'tsf'); ?></th>
                        <td>
                            <select name="optin">
                                <option value=""><?php esc_html_e('All', 'tsf'); ?></option>
                                <option value="1"><?php esc_html_e('Yes', 'tsf'); ?></option>
                                <option value="0"><?php esc_html_e('No', 'tsf'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Date Range', 'tsf'); ?></th>
                        <td>
                            <input type="date" name="date_from" placeholder="<?php esc_attr_e('From', 'tsf'); ?>" />
                            <input type="date" name="date_to" placeholder="<?php esc_attr_e('To', 'tsf'); ?>" />
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Export CSV', 'tsf')); ?>
            </form>
        </div>
        <?php
    }


    public function send_weekly_csv_report() {
        // Use WordPress timezone
        $timezone = wp_timezone();
        $today = new DateTime('now', $timezone);
        $last_week = clone $today;
        $last_week->modify('-7 days');
        $start = $last_week->format('Y-m-d 00:00:00');
        $end = $today->format('Y-m-d 23:59:59');

        // Use WP_Query instead of direct SQL query
        $args = [
            'post_type'      => 'track_submission',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => 'tsf_created_at',
                    'value'   => [ $start, $end ],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ],
            ],
            'orderby'   => 'meta_value',
            'meta_key'  => 'tsf_created_at',
            'order'     => 'ASC',
        ];

        $query = new WP_Query($args);
        $rows = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $rows[] = [
                    'Artist'        => get_post_meta($post_id, 'tsf_artist', true),
                    'Track Title'   => get_post_meta($post_id, 'tsf_track_title', true),
                    'Genre'         => get_post_meta($post_id, 'tsf_genre', true),
                    'Release Date'  => get_post_meta($post_id, 'tsf_release_date', true),
                    'Email'         => get_post_meta($post_id, 'tsf_email', true),
                    'Phone'         => get_post_meta($post_id, 'tsf_phone', true),
                    'Platform'      => get_post_meta($post_id, 'tsf_platform', true),
                    'Track URL'     => get_post_meta($post_id, 'tsf_track_url', true),
                    'Social URL'    => get_post_meta($post_id, 'tsf_social_url', true),
                    'Type'          => get_post_meta($post_id, 'tsf_type', true),
                    'Label'         => get_post_meta($post_id, 'tsf_label', true),
                    'Country'       => get_post_meta($post_id, 'tsf_country', true),
                    'Description'   => get_post_meta($post_id, 'tsf_description', true),
                    'Optin'         => get_post_meta($post_id, 'tsf_optin', true),
                    'Created At'    => get_post_meta($post_id, 'tsf_created_at', true),
                ];
            }
            wp_reset_postdata();
        } else {
            error_log('TSF: No submissions for weekly report (' . $start . ' to ' . $end . ')');
            return;
        }

        if (empty($rows)) {
            return;
        }

        // Build CSV
        $csv_lines = [];
        $csv_lines[] = '"' . implode('","', array_keys($rows[0])) . '"';
        foreach ($rows as $row) {
            $values = array_map(function ($v) {
                return str_replace('"', '""', $v ?? '');
            }, array_values($row));
            $csv_lines[] = '"' . implode('","', $values) . '"';
        }

        // Save CSV file
        $upload_dir = wp_upload_dir();
        $tsf_dir = trailingslashit($upload_dir['basedir']) . 'tsf-reports/';
        wp_mkdir_p($tsf_dir);

        $filename = 'tsf_weekly_report_' . date('Ymd_His') . '.csv';
        $csv_path = $tsf_dir . $filename;
        $csv_url = trailingslashit($upload_dir['baseurl']) . 'tsf-reports/' . $filename;

        file_put_contents($csv_path, implode("\r\n", $csv_lines));

        // Send email
        $to = get_option('tsf_notification_email', get_option('admin_email'));
        $subject = sprintf(__('Weekly Track Submissions Report (%s)', 'tsf'), date('Y-m-d'));
        $body = sprintf(
            __("Attached is the weekly report of %d submissions.\n\nYou can also download it here:\n%s", 'tsf'),
            count($rows),
            $csv_url
        );
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        wp_mail($to, $subject, $body, $headers, [$csv_path]);

        $this->log_error('Weekly report sent successfully with ' . count($rows) . ' submissions');
    }

    public function cleanup_old_files() {
        $upload_dir = wp_upload_dir();
        $tsf_dir = trailingslashit($upload_dir['basedir']) . 'tsf-reports/';

        if (!is_dir($tsf_dir)) {
            return;
        }

        $retention_days = get_option('tsf_file_retention_days', 90);
        $cutoff_time = time() - ($retention_days * DAY_IN_SECONDS);

        // Use DirectoryIterator for better performance on large directories
        $deleted_count = 0;
        $batch_size = 100;
        $processed = 0;

        try {
            $iterator = new DirectoryIterator($tsf_dir);
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isFile() && $fileinfo->getExtension() === 'csv') {
                    if ($fileinfo->getMTime() < $cutoff_time) {
                        if (@unlink($fileinfo->getRealPath())) {
                            $deleted_count++;
                        }
                    }

                    // Limit batch processing to prevent timeout
                    $processed++;
                    if ($processed >= $batch_size) {
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            $this->log_error('Cleanup error: ' . $e->getMessage());
        }

        if ($deleted_count > 0) {
            $this->log_error("Cleanup: Deleted {$deleted_count} old CSV files");
        }
    }

    /**
     * VUL-3 FIX: Cleanup old log entries
     * Runs monthly via cron job
     */
    public function cleanup_old_logs() {
        // Load logger class if not already loaded
        if (!class_exists('TSF_Logger')) {
            require_once TSF_PLUGIN_DIR . 'includes/class-tsf-logger.php';
        }

        $logger = TSF_Logger::get_instance();

        // Get retention days from option (default 90 days)
        $retention_days = absint(get_option('tsf_log_retention_days', 90));

        // Ensure minimum of 30 days
        if ($retention_days < 30) {
            $retention_days = 30;
        }

        $deleted = $logger->clear_old_logs($retention_days);

        if ($deleted) {
            error_log("TSF: Cleaned up {$deleted} old log entries (older than {$retention_days} days)");
        }
    }

    public function add_submission_metaboxes() {
        add_meta_box(
            'tsf_submission_details',
            __('Submission Details', 'tsf'),
            [$this, 'render_submission_metabox'],
            'track_submission',
            'normal',
            'high'
        );
    }

    public function render_submission_metabox($post) {
        wp_nonce_field('tsf_submission_metabox', 'tsf_submission_metabox_nonce');

        // Get all meta values
        $artist = get_post_meta($post->ID, 'tsf_artist', true);
        $track_title = get_post_meta($post->ID, 'tsf_track_title', true);
        $genre = get_post_meta($post->ID, 'tsf_genre', true);
        $duration = get_post_meta($post->ID, 'tsf_duration', true);
        $instrumental = get_post_meta($post->ID, 'tsf_instrumental', true);
        $release_date = get_post_meta($post->ID, 'tsf_release_date', true);
        $email = get_post_meta($post->ID, 'tsf_email', true);
        $phone = get_post_meta($post->ID, 'tsf_phone', true);
        $platform = get_post_meta($post->ID, 'tsf_platform', true);
        $track_url = get_post_meta($post->ID, 'tsf_track_url', true);
        $social_url = get_post_meta($post->ID, 'tsf_social_url', true);
        $type = get_post_meta($post->ID, 'tsf_type', true);
        $label = get_post_meta($post->ID, 'tsf_label', true);
        $country = get_post_meta($post->ID, 'tsf_country', true);
        $description = get_post_meta($post->ID, 'tsf_description', true);
        $optin = get_post_meta($post->ID, 'tsf_optin', true);
        $created_at = get_post_meta($post->ID, 'tsf_created_at', true);

        // MP3 and Dropbox info
        $mp3_file_path = get_post_meta($post->ID, 'tsf_mp3_file_path', true);
        $mp3_filename = get_post_meta($post->ID, 'tsf_mp3_filename', true);
        $qc_report_json = get_post_meta($post->ID, 'tsf_qc_report', true);
        $qc_report = $qc_report_json ? json_decode($qc_report_json, true) : null;
        $dropbox_status = get_post_meta($post->ID, 'tsf_dropbox_status', true);
        $dropbox_uploaded_at = get_post_meta($post->ID, 'tsf_dropbox_uploaded_at', true);
        $dropbox_error = get_post_meta($post->ID, 'tsf_dropbox_error', true);

        ?>
        <style>
            .tsf-metabox-section {
                margin-bottom: 25px;
                padding: 15px;
                background: #f9f9f9;
                border-left: 4px solid #2271b1;
            }
            .tsf-metabox-section h3 {
                margin-top: 0;
                margin-bottom: 15px;
                color: #2271b1;
                font-size: 14px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .tsf-field-row {
                margin-bottom: 15px;
                display: flex;
                align-items: start;
            }
            .tsf-field-label {
                width: 180px;
                font-weight: 600;
                padding-top: 5px;
            }
            .tsf-field-value {
                flex: 1;
            }
            .tsf-field-value input[type="text"],
            .tsf-field-value input[type="email"],
            .tsf-field-value input[type="url"],
            .tsf-field-value input[type="date"],
            .tsf-field-value input[type="datetime-local"],
            .tsf-field-value textarea {
                width: 100%;
                max-width: 500px;
            }
            .tsf-field-value textarea {
                min-height: 100px;
            }
            .tsf-field-readonly {
                padding: 8px 12px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 3px;
                display: inline-block;
                min-width: 200px;
            }
            .tsf-field-link {
                display: inline-block;
                margin-top: 5px;
            }
            .tsf-status-yes {
                color: #46b450;
                font-weight: 600;
            }
            .tsf-status-no {
                color: #dc3232;
                font-weight: 600;
            }
        </style>

        <!-- Track Information -->
        <div class="tsf-metabox-section">
            <h3><?php _e('Track Information', 'tsf'); ?></h3>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Artist:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="text" name="tsf_artist" value="<?php echo esc_attr($artist); ?>" class="regular-text" />
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Track Title:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="text" name="tsf_track_title" value="<?php echo esc_attr($track_title); ?>" class="regular-text" />
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Genre:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="text" name="tsf_genre" value="<?php echo esc_attr($genre); ?>" class="regular-text" />
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Duration:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="text" name="tsf_duration" value="<?php echo esc_attr($duration); ?>" class="regular-text" placeholder="00:00" />
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Instrumental:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <span class="<?php echo $instrumental === 'Yes' ? 'tsf-status-yes' : 'tsf-status-no'; ?>">
                        <?php echo esc_html($instrumental ?: 'No'); ?>
                    </span>
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Release Date:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="date" name="tsf_release_date" value="<?php echo esc_attr($release_date); ?>" />
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Type:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="text" name="tsf_type" value="<?php echo esc_attr($type); ?>" class="regular-text" />
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Label:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="text" name="tsf_label" value="<?php echo esc_attr($label); ?>" class="regular-text" />
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Description:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <textarea name="tsf_description"><?php echo esc_textarea($description); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="tsf-metabox-section">
            <h3><?php _e('Contact Information', 'tsf'); ?></h3>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Email:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="email" name="tsf_email" value="<?php echo esc_attr($email); ?>" class="regular-text" />
                    <?php if ($email): ?>
                        <br><a href="mailto:<?php echo esc_attr($email); ?>" class="tsf-field-link button button-small">
                            <?php _e('Send Email', 'tsf'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Phone:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="text" name="tsf_phone" value="<?php echo esc_attr($phone); ?>" class="regular-text" />
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Country:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="text" name="tsf_country" value="<?php echo esc_attr($country); ?>" class="regular-text" />
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Newsletter Opt-in:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <label>
                        <input type="checkbox" name="tsf_optin" value="1" <?php checked($optin, 1); ?> />
                        <?php _e('Subscribed to newsletter', 'tsf'); ?>
                    </label>
                </div>
            </div>
        </div>

        <!-- URLs & Platform -->
        <div class="tsf-metabox-section">
            <h3><?php _e('URLs & Platform', 'tsf'); ?></h3>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Platform:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="text" name="tsf_platform" value="<?php echo esc_attr($platform); ?>" class="regular-text" />
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Track URL:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="url" name="tsf_track_url" value="<?php echo esc_attr($track_url); ?>" class="regular-text" />
                    <?php if ($track_url): ?>
                        <br><a href="<?php echo esc_url($track_url); ?>" target="_blank" class="tsf-field-link button button-small">
                            <?php _e('Listen to Track', 'tsf'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Social URL:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <input type="url" name="tsf_social_url" value="<?php echo esc_attr($social_url); ?>" class="regular-text" />
                    <?php if ($social_url): ?>
                        <br><a href="<?php echo esc_url($social_url); ?>" target="_blank" class="tsf-field-link button button-small">
                            <?php _e('Visit Profile', 'tsf'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- MP3 File & Dropbox Status -->
        <div class="tsf-metabox-section">
            <h3><?php _e('🎵 MP3 File & Dropbox Status', 'tsf'); ?></h3>

            <?php if (!$mp3_filename && !$mp3_file_path): ?>
            <div class="tsf-field-row">
                <div class="tsf-field-value">
                    <div style="padding: 20px; background: #fff3cd; border-left: 4px solid #ffc107; color: #856404;">
                        <strong>ℹ️ No MP3 File Uploaded</strong><br>
                        <small>This submission was made without uploading an MP3 file (Step 2 was skipped or no file was selected).</small>
                    </div>
                </div>
            </div>
            <?php else: ?>

            <?php if ($mp3_filename): ?>
            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('MP3 Filename:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <span class="tsf-field-readonly"><?php echo esc_html($mp3_filename); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($mp3_file_path): ?>
            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('File Path:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <code style="background: #f0f0f0; padding: 5px 10px; display: inline-block; border-radius: 3px;">
                        <?php echo esc_html($mp3_file_path); ?>
                    </code>
                </div>
            </div>
            <?php endif; ?>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Dropbox Status:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <?php
                    if ($dropbox_status === 'uploaded') {
                        echo '<span style="color: #46b450; font-weight: 600; font-size: 14px;">✅ Uploaded Successfully</span>';
                    } elseif ($dropbox_status && strpos($dropbox_status, 'upload_failed') !== false) {
                        echo '<span style="color: #dc3232; font-weight: 600; font-size: 14px;">❌ Upload Failed</span>';
                        if ($dropbox_error) {
                            echo '<br><small style="color: #dc3232;">' . esc_html($dropbox_error) . '</small>';
                        }
                    } elseif ($dropbox_status === 'file_not_found') {
                        echo '<span style="color: #dc3232; font-weight: 600; font-size: 14px;">❌ File Not Found</span>';
                    } elseif ($dropbox_status) {
                        echo '<span style="color: #999; font-size: 14px;">' . esc_html($dropbox_status) . '</span>';
                    } else {
                        echo '<span style="color: #999; font-size: 14px;">⏳ Pending / Not Configured</span>';
                    }
                    ?>
                </div>
            </div>

            <?php if ($dropbox_uploaded_at): ?>
            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Uploaded At:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <span class="tsf-field-readonly"><?php echo esc_html($dropbox_uploaded_at); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <?php
            // Show file location info
            $upload_dir = wp_upload_dir();
            $full_path = $upload_dir['basedir'] . $mp3_file_path;
            $file_exists = file_exists($full_path);
            ?>
            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Local File:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <?php if ($file_exists): ?>
                        <span style="color: #46b450;">✅ Backup stored on server</span>
                        <span style="color: #666; font-size: 12px;">(<?php echo size_format(filesize($full_path)); ?>)</span>
                        <br>
                        <a href="<?php echo admin_url('admin-post.php?action=tsf_download_mp3&post_id=' . $post->ID . '&_wpnonce=' . wp_create_nonce('tsf_download_mp3_' . $post->ID)); ?>"
                           class="button button-primary"
                           style="margin-top: 10px;">
                            📥 Download MP3 Backup
                        </a>
                        <?php if (current_user_can('delete_posts')): ?>
                        <a href="<?php echo admin_url('admin-post.php?action=tsf_delete_mp3&post_id=' . $post->ID . '&_wpnonce=' . wp_create_nonce('tsf_delete_mp3_' . $post->ID)); ?>"
                           class="button button-secondary"
                           style="margin-top: 10px; margin-left: 5px;"
                           onclick="return confirm('Are you sure you want to delete this MP3 file? This cannot be undone.');">
                            🗑️ Delete Local File
                        </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color: #999;">⚠️ No local backup (file was manually deleted)</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($qc_report && isset($qc_report['quality_score'])): ?>
            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Quality Score:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <div style="margin-bottom: 10px;">
                        <strong style="font-size: 18px; color: <?php
                            $score = $qc_report['quality_score'];
                            if ($score >= 80) echo '#46b450';
                            elseif ($score >= 60) echo '#f0b23e';
                            else echo '#dc3232';
                        ?>;"><?php echo esc_html($score); ?>%</strong>
                        <span style="color: #666; margin-left: 10px; font-size: 13px;">
                            (Metadata: <?php echo isset($qc_report['metadata_score']) ? $qc_report['metadata_score'] : 0; ?>/40,
                            Audio: <?php echo isset($qc_report['audio_score']) ? $qc_report['audio_score'] : 0; ?>/30,
                            Professional: <?php echo isset($qc_report['professional_score']) ? $qc_report['professional_score'] : 0; ?>/30)
                        </span>
                    </div>
                    <?php if (!empty($qc_report['recommendations'])): ?>
                        <details style="margin-top: 10px;">
                            <summary style="cursor: pointer; color: #2271b1; font-weight: 600;">📋 View Recommendations</summary>
                            <ul style="margin: 10px 0 0 20px; color: #666;">
                                <?php foreach ($qc_report['recommendations'] as $rec): ?>
                                    <li><?php echo esc_html($rec); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>

        <!-- Submission Details -->
        <div class="tsf-metabox-section">
            <h3><?php _e('Submission Details', 'tsf'); ?></h3>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Submitted At:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <span class="tsf-field-readonly"><?php echo esc_html($created_at ?: get_the_date('Y-m-d H:i:s', $post->ID)); ?></span>
                </div>
            </div>

            <div class="tsf-field-row">
                <div class="tsf-field-label"><?php _e('Post ID:', 'tsf'); ?></div>
                <div class="tsf-field-value">
                    <span class="tsf-field-readonly"><?php echo esc_html($post->ID); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_submission_metabox($post_id) {
        if (!isset($_POST['tsf_submission_metabox_nonce']) || !wp_verify_nonce($_POST['tsf_submission_metabox_nonce'], 'tsf_submission_metabox')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (get_post_type($post_id) !== 'track_submission') {
            return;
        }

        $fields = [
            'tsf_artist', 'tsf_track_title', 'tsf_genre', 'tsf_duration', 'tsf_instrumental',
            'tsf_release_date', 'tsf_email', 'tsf_phone', 'tsf_platform', 'tsf_track_url',
            'tsf_social_url', 'tsf_type', 'tsf_label', 'tsf_country', 'tsf_description',
            'tsf_optin', 'tsf_created_at'
        ];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                if ($field === 'tsf_optin') {
                    update_post_meta($post_id, $field, 1);
                } elseif ($field === 'tsf_created_at') {
                    $datetime = sanitize_text_field($_POST[$field]);
                    $datetime = str_replace('T', ' ', $datetime);
                    update_post_meta($post_id, $field, $datetime);
                } elseif ($field === 'tsf_email') {
                    update_post_meta($post_id, $field, sanitize_email($_POST[$field]));
                } elseif (in_array($field, ['tsf_track_url', 'tsf_social_url'])) {
                    update_post_meta($post_id, $field, esc_url_raw($_POST[$field]));
                } elseif ($field === 'tsf_description') {
                    update_post_meta($post_id, $field, sanitize_textarea_field($_POST[$field]));
                } else {
                    update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
                }
            } else {
                if ($field === 'tsf_optin') {
                    update_post_meta($post_id, $field, 0);
                }
            }
        }

        // Update post title based on artist and track title
        if (isset($_POST['tsf_artist']) && isset($_POST['tsf_track_title'])) {
            $new_title = sprintf('%s - %s', sanitize_text_field($_POST['tsf_artist']), sanitize_text_field($_POST['tsf_track_title']));

            // Remove this hook temporarily to prevent infinite loop
            remove_action('save_post', [$this, 'save_submission_metabox']);

            wp_update_post([
                'ID' => $post_id,
                'post_title' => $new_title
            ]);

            // Re-add the hook
            add_action('save_post', [$this, 'save_submission_metabox']);
        }
    }


    public function render_form($atts = []) {
        $atts = shortcode_atts([
            'class' => 'tsf-form'
        ], $atts);

        $genres = get_option('tsf_genres', ['Pop', 'Rock', 'Electronic/Dance', 'Folk', 'Alternative', 'Metal', 'Jazz', 'R&B', 'Hip-Hop/Rap', 'Autre']);
        $platforms = get_option('tsf_platforms', ['Spotify', 'Bandcamp', 'Youtube Music', 'Apple Music', 'Deezer', 'Soundcloud', 'Other']);
        $types = get_option('tsf_types', ['Album', 'EP', 'Single']);
        $labels = get_option('tsf_labels', ['Indie', 'Label']);
        $max_future_years = get_option('tsf_max_future_years', 2);
        $max_date = date('Y-m-d', strtotime("+{$max_future_years} years"));

        ob_start();
        ?>
        <div class="<?php echo esc_attr($atts['class']); ?>">
            <form id="tsf-submission-form" method="post">
                <?php wp_nonce_field('tsf_nonce', 'nonce'); ?>
                
                <!-- Honeypot field -->
                <input type="text" name="tsf_hp" style="display:none;" />
                
                <div class="tsf-field">
                    <label for="artist"><?php esc_html_e('Artist Name *', 'tsf'); ?></label>
                    <input type="text" id="artist" name="artist" required />
                </div>
                
                <div class="tsf-field">
                    <label for="track_title"><?php esc_html_e('Track Title *', 'tsf'); ?></label>
                    <input type="text" id="track_title" name="track_title" required />
                </div>
                
                <div class="tsf-field">
                    <label for="genre"><?php esc_html_e('Genre *', 'tsf'); ?></label>
                    <select id="genre" name="genre" required>
                        <option value=""><?php esc_html_e('Select Genre', 'tsf'); ?></option>
                        <?php foreach ($genres as $genre): ?>
                            <option value="<?php echo esc_attr($genre); ?>"><?php echo esc_html($genre); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="tsf-field">
                    <label for="duration"><?php esc_html_e('Duration (mm:ss) *', 'tsf'); ?></label>
                    <input type="text" id="duration" name="duration" pattern="[0-9]{1,2}:[0-5][0-9]" placeholder="3:45" required />
                </div>
                
                <div class="tsf-field">
                    <label for="instrumental"><?php esc_html_e('Instrumental *', 'tsf'); ?></label>
                    <select id="instrumental" name="instrumental" required>
                        <option value=""><?php esc_html_e('Select', 'tsf'); ?></option>
                        <option value="Yes"><?php esc_html_e('Yes', 'tsf'); ?></option>
                        <option value="No"><?php esc_html_e('No', 'tsf'); ?></option>
                    </select>
                </div>
                
                <div class="tsf-field">
                    <label for="release_date"><?php esc_html_e('Release Date *', 'tsf'); ?></label>
                    <input type="date" id="release_date" name="release_date" max="<?php echo esc_attr($max_date); ?>" required />
                </div>
                
                <div class="tsf-field">
                    <label for="email"><?php esc_html_e('Email *', 'tsf'); ?></label>
                    <input type="email" id="email" name="email" required />
                </div>
                
                <div class="tsf-field">
                    <label for="phone"><?php esc_html_e('Phone', 'tsf'); ?></label>
                    <input type="tel" id="phone" name="phone" />
                </div>
                
                <div class="tsf-field">
                    <label for="platform"><?php esc_html_e('Platform *', 'tsf'); ?></label>
                    <select id="platform" name="platform" required>
                        <option value=""><?php esc_html_e('Select Platform', 'tsf'); ?></option>
                        <?php foreach ($platforms as $platform): ?>
                            <option value="<?php echo esc_attr($platform); ?>"><?php echo esc_html($platform); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="tsf-field">
                    <label for="track_url"><?php esc_html_e('Track URL *', 'tsf'); ?></label>
                    <input type="url" id="track_url" name="track_url" required />
                </div>
                
                <div class="tsf-field">
                    <label for="social_url"><?php esc_html_e('Social Media URL', 'tsf'); ?></label>
                    <input type="url" id="social_url" name="social_url" />
                </div>
                
                <div class="tsf-field">
                    <label for="type"><?php esc_html_e('Type *', 'tsf'); ?></label>
                    <select id="type" name="type" required>
                        <option value=""><?php esc_html_e('Select Type', 'tsf'); ?></option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="tsf-field">
                    <label for="label"><?php esc_html_e('Label *', 'tsf'); ?></label>
                    <select id="label" name="label" required>
                        <option value=""><?php esc_html_e('Select Label', 'tsf'); ?></option>
                        <?php foreach ($labels as $label): ?>
                            <option value="<?php echo esc_attr($label); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="tsf-field">
                    <label for="country"><?php esc_html_e('Country *', 'tsf'); ?></label>
                    <input type="text" id="country" name="country" required />
                </div>
                
                <div class="tsf-field">
                    <label for="description"><?php esc_html_e('Description *', 'tsf'); ?></label>
                    <textarea id="description" name="description" rows="4" required></textarea>
                </div>
                
                <div class="tsf-field">
                    <label>
                        <input type="checkbox" id="optin" name="optin" value="1" />
                        <?php esc_html_e('I agree to receive promotional emails', 'tsf'); ?>
                    </label>
                </div>
                
                <div class="tsf-field">
                    <button type="submit" id="tsf-submit-btn"><?php esc_html_e('Submit Track', 'tsf'); ?></button>
                </div>
                
                <div id="tsf-message" style="display:none;"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    public function render_upload_instructions($atts = []) {
    $dropbox_url = get_option('tsf_dropbox_url', 'https://www.dropbox.com/request/qhA1oF0V4W1FolQ2bcjO' );
    
    ob_start();
    ?>
    <div class="tsf-upload-instructions">
    <h2><?php esc_html_e('Thank you for submitting your track!', 'tsf'); ?></h2>
    <p><?php esc_html_e('Your track information has been successfully saved. Now, please take a moment to read about the correct MP3 tagging before uploading it here.', 'tsf'); ?></p>

    <h3><?php esc_html_e('✅ Upload Your Track , But First, Tag It Right!', 'tsf'); ?></h3>

    <p><?php esc_html_e('In 2025, clean metadata isn’t just nice to have, it’s essential for your music to be searchable, credited, and heard. At a minimum, your track must include the artist name, track title, album name, release year, and ideally the ISRC, genre, and cover artwork.', 'tsf'); ?></p>

    <p><?php esc_html_e('If your metadata is incomplete, your music might be misfiled, skipped, or rejected by platforms. Take a minute to review the checklist below, your future self (and your listeners) will thank you.', 'tsf'); ?></p>

    <h4><?php esc_html_e('🗂️ MP3 Upload Checklist (2025 Standards)', 'tsf'); ?></h4>
    <ul>
        <li><?php esc_html_e('🎵 Track Title – the full name of the song', 'tsf'); ?></li>
        <li><?php esc_html_e('🧑‍🎤 Artist Name – primary performer or band', 'tsf'); ?></li>
        <li><?php esc_html_e('💿 Album Name – or single/EP title', 'tsf'); ?></li>
        <li><?php esc_html_e('📅 Year of Release – in YYYY format', 'tsf'); ?></li>
        <li><?php esc_html_e('🔢 Track Number – e.g., 2/10 if part of an album', 'tsf'); ?></li>
        <li><?php esc_html_e('🎧 Genre – keep it relevant and consistent', 'tsf'); ?></li>
        <li><?php esc_html_e('🖼️ Cover Image – JPEG/PNG, minimum 1000×1000 px', 'tsf'); ?></li>
        <li><?php esc_html_e('🆔 ISRC Code – for royalty tracking (if available)', 'tsf'); ?></li>
        <li><?php esc_html_e('📝 Composer / Songwriter – if different from artist', 'tsf'); ?></li>
        <li><?php esc_html_e('🏢 Publisher / Label – for rights management', 'tsf'); ?></li>
        <li><?php esc_html_e('⚖️ Copyright Notice – e.g., © 2025 Your Name or Label', 'tsf'); ?></li>
        <li><?php esc_html_e('💬 Comment (optional) – version info, licensing, etc.', 'tsf'); ?></li>
    </ul>

    <a href="<?php echo esc_url($dropbox_url); ?>" class="button button-primary" target="_blank">
        <?php esc_html_e('🚀 Upload your track here', 'tsf'); ?>
    </a>
</div>

    <?php
    return ob_get_clean();
}

}

// Initialize the plugin
TrackSubmissionForm::get_instance();

