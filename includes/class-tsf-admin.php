<?php
/**
 * Admin Class
 *
 * Handles admin interface, metaboxes, and admin-only features
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_Admin {

    private $logger;
    private $workflow;
    private $exporter;

    public function __construct() {
            error_log('TSF: Admin class constructor started');
        $this->logger = TSF_Logger::get_instance();
        $this->workflow = new TSF_Workflow();
        $this->exporter = new TSF_Exporter();

            error_log('TSF: Admin dependencies loaded');
        $this->init_hooks();
            error_log('TSF: Admin hooks initialized');
    }

    private function init_hooks() {
        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_pages']);

        // Dashboard widget
        add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);
        // Admin notices
        add_action('admin_notices', [$this, 'admin_notices']);

        // Enqueue admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Handle export actions
        add_action('admin_init', [$this, 'handle_export']);

        // Add meta boxes
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);

        // Save meta box data
        add_action('save_post_track_submission', [$this, 'save_meta_box_data'], 10, 2);
    }

    /**
     * Register dashboard widget showing recent blocked IPs (rate-limit)
     */
    public function register_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'tsf_blocked_ips_widget',
            __('TSF: Recent blocked IPs', 'tsf'),
            [$this, 'render_blocked_ips_widget']
        );
    }

    /**
     * Render dashboard widget content: top blocked IPs and download link
     */
    public function render_blocked_ips_widget() {
        if (!class_exists('TSF_Logger')) {
            echo '<p>' . __('Logger not available', 'tsf') . '</p>';
            return;
        }

        $logger = TSF_Logger::get_instance();
        $logs = $logger->get_logs(['level' => 'warning', 'limit' => 1000]);

        $blocked = [];
        foreach ($logs as $log) {
            $ctx = json_decode($log->context, true);
            if (!is_array($ctx)) {
                continue;
            }
            if (isset($ctx['attempt']) && $ctx['attempt'] === 'rate_limit_exceeded') {
                $ip = $log->ip_address ?: 'unknown';
                if (!isset($blocked[$ip])) {
                    $blocked[$ip] = ['count' => 0, 'last' => $log->created_at];
                }
                $blocked[$ip]['count']++;
                if (strtotime($log->created_at) > strtotime($blocked[$ip]['last'])) {
                    $blocked[$ip]['last'] = $log->created_at;
                }
            }
        }

        // Sort by count desc
        uasort($blocked, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        // Prepare download URL (nonce-protected)
        $download_url = add_query_arg([
            'page' => 'tsf-logs',
            'blocked' => 1,
            'download_blocked' => 1,
        ], admin_url('admin.php'));
        $download_url = wp_nonce_url($download_url, 'tsf_download_blocked');

        echo '<p>' . sprintf(__('Top %d blocked IPs (by attempts)', 'tsf'), min(10, count($blocked))) . ' — <a href="' . esc_url($download_url) . '">' . __('Download CSV', 'tsf') . '</a></p>';

        if (empty($blocked)) {
            echo '<p>' . __('No recent blocked IPs', 'tsf') . '</p>';
            return;
        }

        echo '<table style="width:100%;"><thead><tr><th>' . __('IP', 'tsf') . '</th><th>' . __('Attempts', 'tsf') . '</th><th>' . __('Last seen', 'tsf') . '</th></tr></thead><tbody>';
        $i = 0;
        foreach ($blocked as $ip => $data) {
            if ($i++ >= 10) break;
            echo '<tr><td>' . esc_html($ip) . '</td><td>' . esc_html($data['count']) . '</td><td>' . esc_html($data['last']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_pages() {
        // Main menu
        add_menu_page(
            __('Track Submissions', 'tsf'),
            __('Track Submissions', 'tsf'),
            'edit_posts',
            'tsf-submissions',
            [$this, 'submissions_page'],
            'dashicons-format-audio',
            25
        );

        // Dashboard submenu
        add_submenu_page(
            'tsf-submissions',
            __('Dashboard', 'tsf'),
            __('Dashboard', 'tsf'),
            'edit_posts',
            'tsf-dashboard',
            [$this, 'dashboard_page']
        );

        // All Submissions
        add_submenu_page(
            'tsf-submissions',
            __('All Submissions', 'tsf'),
            __('All Submissions', 'tsf'),
            'edit_posts',
            'edit.php?post_type=track_submission'
        );

        // Settings
        add_submenu_page(
            'tsf-submissions',
            __('Settings', 'tsf'),
            __('Settings', 'tsf'),
            'manage_options',
            'tsf-settings',
            [$this, 'settings_page']
        );

        // Export
        add_submenu_page(
            'tsf-submissions',
            __('Export', 'tsf'),
            __('Export', 'tsf'),
            'export',
            'tsf-export',
            [$this, 'export_page']
        );

        // Logs
        add_submenu_page(
            'tsf-submissions',
            __('Logs', 'tsf'),
            __('Logs', 'tsf'),
            'manage_options',
            'tsf-logs',
            [$this, 'logs_page']
        );
    }

    /**
     * Submissions page (redirect to CPT list)
     */
    public function submissions_page() {
        wp_redirect(admin_url('edit.php?post_type=track_submission'));
        exit;
    }

    /**
     * Dashboard page
     */
    public function dashboard_page() {
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have sufficient permissions', 'tsf'));
        }

        include TSF_PLUGIN_DIR . 'templates/admin/dashboard.php';
    }

    /**
     * Settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions', 'tsf'));
        }

        // Handle form submission
            if (isset($_POST['tsf_settings_submit'])) {
            check_admin_referer('tsf_settings', 'tsf_settings_nonce');

            update_option('tsf_dropbox_url', esc_url_raw($_POST['tsf_dropbox_url']));
            update_option('tsf_notification_email', sanitize_email($_POST['tsf_notification_email']));
            update_option('tsf_report_day', sanitize_text_field($_POST['tsf_report_day']));
            update_option('tsf_report_time', sanitize_text_field($_POST['tsf_report_time']));
            // Rate limit duration in seconds (default 300 = 5 minutes)
            $rate_limit_seconds = absint($_POST['tsf_rate_limit_seconds'] ?? 300);
            update_option('tsf_rate_limit_seconds', $rate_limit_seconds);

            // Handle role capability assignments for bypassing rate limit
            $selected_roles = isset($_POST['tsf_bypass_roles']) ? array_map('sanitize_text_field', (array) $_POST['tsf_bypass_roles']) : [];
            if (function_exists('get_editable_roles')) {
                foreach (get_editable_roles() as $role_key => $role_info) {
                    $role_obj = get_role($role_key);
                    if (!$role_obj) {
                        continue;
                    }

                    if (in_array($role_key, $selected_roles, true)) {
                        $role_obj->add_cap('tsf_bypass_rate_limit');
                    } else {
                        $role_obj->remove_cap('tsf_bypass_rate_limit');
                    }
                }
            }

            echo '<div class="notice notice-success"><p>' . __('Settings saved', 'tsf') . '</p></div>';
        }

        $dropbox_url = get_option('tsf_dropbox_url', '');
        $notification_email = get_option('tsf_notification_email', get_option('admin_email'));
        $report_day = get_option('tsf_report_day', 'thursday');
        $report_time = get_option('tsf_report_time', '09:00');
    $rate_limit_seconds = (int) get_option('tsf_rate_limit_seconds', 300);
    $rate_limit_seconds = apply_filters('tsf_rate_limit_seconds', $rate_limit_seconds);
    $rate_limit_seconds = apply_filters('tsf_rate_limit_duration', $rate_limit_seconds);

        ?>
        <div class="wrap">
            <h1><?php _e('Track Submission Settings', 'tsf'); ?></h1>

            <form method="post">
                <?php wp_nonce_field('tsf_settings', 'tsf_settings_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th><?php _e('Dropbox URL', 'tsf'); ?></th>
                        <td>
                            <input type="url" name="tsf_dropbox_url" value="<?php echo esc_attr($dropbox_url); ?>" class="regular-text">
                            <p class="description"><?php _e('File upload request URL', 'tsf'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Notification Email', 'tsf'); ?></th>
                        <td>
                            <input type="email" name="tsf_notification_email" value="<?php echo esc_attr($notification_email); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Weekly Report Day', 'tsf'); ?></th>
                        <td>
                            <select name="tsf_report_day">
                                <?php foreach(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day): ?>
                                    <option value="<?php echo $day; ?>" <?php selected($report_day, $day); ?>>
                                        <?php echo ucfirst($day); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Report Time', 'tsf'); ?></th>
                        <td>
                            <input type="time" name="tsf_report_time" value="<?php echo esc_attr($report_time); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Rate limit (seconds)', 'tsf'); ?></th>
                        <td>
                            <label for="tsf_rate_limit_preset" style="display:block; margin-bottom:6px;">
                                <?php _e('Preset', 'tsf'); ?>
                                <select id="tsf_rate_limit_preset" style="margin-left:6px;">
                                    <option value="custom"><?php _e('Custom (enter seconds below)', 'tsf'); ?></option>
                                    <option value="0"><?php _e('Disabled', 'tsf'); ?> — 0</option>
                                    <option value="300"><?php _e('5 minutes', 'tsf'); ?> — 300</option>
                                    <option value="3600"><?php _e('1 hour', 'tsf'); ?> — 3600</option>
                                    <option value="86400"><?php _e('24 hours', 'tsf'); ?> — 86400</option>
                                </select>
                            </label>

                            <input type="number" min="0" name="tsf_rate_limit_seconds" value="<?php echo esc_attr($rate_limit_seconds); ?>" class="small-text">
                            <p class="description"><?php _e('Number of seconds to block repeated submissions from the same IP. Use 0 to disable. Choose a preset or enter a custom value.', 'tsf'); ?></p>
                            <p class="description"><strong><?php _e('Recommendation:', 'tsf'); ?></strong> <?php _e('For public forms we recommend 24 hours (86400 seconds). For testing or low-traffic sites, 5 minutes (300 seconds) is suitable.', 'tsf'); ?></p>

                            <script>
                                (function(){
                                    var preset = document.getElementById('tsf_rate_limit_preset');
                                    var input = document.querySelector('input[name="tsf_rate_limit_seconds"]');
                                    if (!preset || !input) return;

                                    // Initialize preset select based on current numeric value
                                    var current = String(input.value);
                                    var found = false;
                                    for (var i=0;i<preset.options.length;i++){
                                        if (preset.options[i].value === current) { preset.selectedIndex = i; found = true; break; }
                                    }
                                    if (!found) { preset.value = 'custom'; }

                                    preset.addEventListener('change', function(){
                                        if (this.value === 'custom') return;
                                        input.value = this.value;
                                    });
                                })();
                            </script>
                        </td>
                    </tr>

                    <tr>
                        <th><?php _e('Bypass capability (roles)', 'tsf'); ?></th>
                        <td>
                            <?php
                            // List editable roles and show checkboxes to grant/remove the capability
                            if (function_exists('get_editable_roles')) {
                                $editable_roles = get_editable_roles();
                                foreach ($editable_roles as $role_key => $role_info) {
                                    $role_obj = get_role($role_key);
                                    $has = $role_obj && $role_obj->has_cap('tsf_bypass_rate_limit');
                                    ?>
                                    <label style="display:block; margin-bottom:4px;">
                                        <input type="checkbox" name="tsf_bypass_roles[]" value="<?php echo esc_attr($role_key); ?>" <?php checked($has); ?>>
                                        <?php echo esc_html($role_info['name']); ?>
                                    </label>
                                    <?php
                                }
                            } else {
                                _e('Role management not available on this WordPress installation.', 'tsf');
                            }
                            ?>
                            <p class="description"><?php _e('Select roles that should be allowed to bypass the submission rate limit. Administrator is recommended for QA.', 'tsf'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Settings', 'tsf'), 'primary', 'tsf_settings_submit'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Export page
     */
    public function export_page() {
        if (!current_user_can('export')) {
            wp_die(__('You do not have sufficient permissions', 'tsf'));
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Export Submissions', 'tsf'); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field('tsf_export', 'tsf_export_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th><?php _e('Format', 'tsf'); ?></th>
                        <td>
                            <select name="export_format" required>
                                <option value="csv">CSV</option>
                                <option value="json">JSON</option>
                                <option value="xml">XML</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Status', 'tsf'); ?></th>
                        <td>
                            <select name="status">
                                <option value=""><?php _e('All', 'tsf'); ?></option>
                                <?php foreach ($this->workflow->get_statuses() as $status => $label): ?>
                                    <option value="<?php echo esc_attr($status); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Date Range', 'tsf'); ?></th>
                        <td>
                            <input type="date" name="date_from"> <?php _e('to', 'tsf'); ?> <input type="date" name="date_to">
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Export', 'tsf'), 'primary', 'tsf_export_submit'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Logs page
     */
    public function logs_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions', 'tsf'));
        }

        // CSV download for blocked IPs
        if (isset($_GET['download_blocked'])) {
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tsf_download_blocked')) {
                wp_die(__('Invalid nonce', 'tsf'));
            }
            // Prepare CSV output
            $logs_all = $this->logger->get_logs(['level' => 'warning', 'limit' => 5000]);
            $rows = [];
            foreach ($logs_all as $log) {
                $ctx = json_decode($log->context, true);
                if (is_array($ctx) && isset($ctx['attempt']) && $ctx['attempt'] === 'rate_limit_exceeded') {
                    $rows[] = [$log->ip_address, $log->created_at, $log->message, json_encode($ctx)];
                }
            }

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="tsf-blocked-ips.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ip_address', 'created_at', 'message', 'context']);
            foreach ($rows as $r) fputcsv($out, $r);
            fclose($out);
            exit;
        }

        $level = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : null;
        $show_blocked = isset($_GET['blocked']);
        $logs = $this->logger->get_logs(['level' => $level, 'limit' => 200]);

        // If requested, filter logs to only rate-limit blocked attempts
        if ($show_blocked) {
            $filtered = [];
            foreach ($logs as $log) {
                $ctx = json_decode($log->context, true);
                if (is_array($ctx) && isset($ctx['attempt']) && $ctx['attempt'] === 'rate_limit_exceeded') {
                    $filtered[] = $log;
                }
            }
            $logs = $filtered;
        }

        ?>
        <div class="wrap">
            <h1><?php _e('System Logs', 'tsf'); ?></h1>

            <p>
                <a href="?page=tsf-logs" <?php echo !$level && !$show_blocked ? 'class="current"' : ''; ?>><?php _e('All', 'tsf'); ?></a> |
                <a href="?page=tsf-logs&level=error"><?php _e('Errors', 'tsf'); ?></a> |
                <a href="?page=tsf-logs&level=warning"><?php _e('Warnings', 'tsf'); ?></a> |
                <a href="?page=tsf-logs&level=info"><?php _e('Info', 'tsf'); ?></a> |
                <a href="?page=tsf-logs&blocked=1"><?php _e('Blocked IPs (rate-limit)', 'tsf'); ?></a>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Level', 'tsf'); ?></th>
                        <th><?php _e('Message', 'tsf'); ?></th>
                        <th><?php _e('User', 'tsf'); ?></th>
                        <th><?php _e('IP', 'tsf'); ?></th>
                        <th><?php _e('Date', 'tsf'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><strong><?php echo esc_html(strtoupper($log->level)); ?></strong></td>
                            <td><?php echo esc_html($log->message); ?></td>
                            <td><?php echo $log->user_id ? esc_html(get_userdata($log->user_id)->display_name) : '-'; ?></td>
                            <td><?php echo esc_html($log->ip_address); ?></td>
                            <td><?php echo esc_html($log->created_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Handle export action
     */
    public function handle_export() {
        if (!isset($_POST['tsf_export_submit'])) {
            return;
        }

        check_admin_referer('tsf_export', 'tsf_export_nonce');

        if (!current_user_can('export')) {
            wp_die(__('Insufficient permissions', 'tsf'));
        }

        $format = sanitize_text_field($_POST['export_format']);
        $filters = [
            'status' => isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '',
            'date_from' => isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '',
            'date_to' => isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '',
        ];

        switch ($format) {
            case 'json':
                $this->exporter->export_json($filters);
                break;
            case 'xml':
                $this->exporter->export_xml($filters);
                break;
            default:
                $this->exporter->export_csv($filters);
        }
    }

    /**
     * Admin notices
     */
    public function admin_notices() {
        if (isset($_GET['bulk_status_updated'])) {
            $count = absint($_GET['bulk_status_updated']);
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                sprintf(__('%d submissions updated', 'tsf'), $count)
            );
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'tsf-') === false && $hook !== 'edit.php') {
            return;
        }

        wp_enqueue_style('tsf-admin', TSF_PLUGIN_URL . 'assets/css/tsf-admin.css', [], TSF_VERSION);
        wp_enqueue_script('tsf-admin', TSF_PLUGIN_URL . 'assets/js/tsf-admin.js', ['jquery'], TSF_VERSION, true);
    }

    /**
     * Add meta boxes for track submission editing
     */
    public function add_meta_boxes() {
        add_meta_box(
            'tsf_submission_details',
            __('Submission Details', 'tsf'),
            [$this, 'render_submission_details_meta_box'],
            'track_submission',
            'normal',
            'high'
        );
    }

    /**
     * Render submission details meta box
     */
    public function render_submission_details_meta_box($post) {
        // Add nonce for security
        wp_nonce_field('tsf_save_meta_box', 'tsf_meta_box_nonce');

        // Get current values
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

        ?>
        <style>
            .tsf-meta-box-field { margin-bottom: 15px; }
            .tsf-meta-box-field label { display: inline-block; width: 150px; font-weight: 600; }
            .tsf-meta-box-field input[type="text"],
            .tsf-meta-box-field input[type="email"],
            .tsf-meta-box-field input[type="url"],
            .tsf-meta-box-field input[type="date"],
            .tsf-meta-box-field select,
            .tsf-meta-box-field textarea { width: 60%; }
            .tsf-meta-box-field textarea { height: 100px; }
        </style>

        <div class="tsf-meta-box-field">
            <label><?php _e('Artist Name', 'tsf'); ?>:</label>
            <input type="text" name="tsf_artist" value="<?php echo esc_attr($artist); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Track Title', 'tsf'); ?>:</label>
            <input type="text" name="tsf_track_title" value="<?php echo esc_attr($track_title); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Genre', 'tsf'); ?>:</label>
            <input type="text" name="tsf_genre" value="<?php echo esc_attr($genre); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Duration', 'tsf'); ?>:</label>
            <input type="text" name="tsf_duration" value="<?php echo esc_attr($duration); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Instrumental', 'tsf'); ?>:</label>
            <select name="tsf_instrumental">
                <option value="Yes" <?php selected($instrumental, 'Yes'); ?>><?php _e('Yes', 'tsf'); ?></option>
                <option value="No" <?php selected($instrumental, 'No'); ?>><?php _e('No', 'tsf'); ?></option>
            </select>
            <p class="description"><?php _e('Editable: Select whether this track is instrumental', 'tsf'); ?></p>
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Release Date', 'tsf'); ?>:</label>
            <input type="date" name="tsf_release_date" value="<?php echo esc_attr($release_date); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Email', 'tsf'); ?>:</label>
            <input type="email" name="tsf_email" value="<?php echo esc_attr($email); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Phone', 'tsf'); ?>:</label>
            <input type="text" name="tsf_phone" value="<?php echo esc_attr($phone); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Platform', 'tsf'); ?>:</label>
            <input type="text" name="tsf_platform" value="<?php echo esc_attr($platform); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Track URL', 'tsf'); ?>:</label>
            <input type="url" name="tsf_track_url" value="<?php echo esc_attr($track_url); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Social URL', 'tsf'); ?>:</label>
            <input type="url" name="tsf_social_url" value="<?php echo esc_attr($social_url); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Type', 'tsf'); ?>:</label>
            <input type="text" name="tsf_type" value="<?php echo esc_attr($type); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Label', 'tsf'); ?>:</label>
            <input type="text" name="tsf_label" value="<?php echo esc_attr($label); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Country', 'tsf'); ?>:</label>
            <input type="text" name="tsf_country" value="<?php echo esc_attr($country); ?>" readonly />
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Description', 'tsf'); ?>:</label>
            <textarea name="tsf_description" readonly><?php echo esc_textarea($description); ?></textarea>
        </div>

        <div class="tsf-meta-box-field">
            <label><?php _e('Opt-in', 'tsf'); ?>:</label>
            <input type="checkbox" name="tsf_optin" value="1" <?php checked($optin, 1); ?> disabled />
            <?php _e('Agreed to receive promotional emails', 'tsf'); ?>
        </div>
        <?php
    }

    /**
     * Save meta box data
     */
    public function save_meta_box_data($post_id, $post) {
        // Verify nonce
        if (!isset($_POST['tsf_meta_box_nonce']) || !wp_verify_nonce($_POST['tsf_meta_box_nonce'], 'tsf_save_meta_box')) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Only save instrumental field (the only editable one)
        if (isset($_POST['tsf_instrumental'])) {
            update_post_meta($post_id, 'tsf_instrumental', sanitize_text_field($_POST['tsf_instrumental']));
        }
    }
}
