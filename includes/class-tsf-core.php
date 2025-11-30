<?php
/**
 * Core Class
 *
 * Main orchestrator - initializes all components
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_Core {

    private static $instance = null;
    private $logger;
    private $validator;
    private $workflow;
    private $mailer;
    private $submission;
    private $admin;
    private $dashboard;
    private $exporter;
    private $rest_api;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once TSF_PLUGIN_DIR . 'includes/class-tsf-logger.php';
        require_once TSF_PLUGIN_DIR . 'includes/class-tsf-validator.php';
        require_once TSF_PLUGIN_DIR . 'includes/class-tsf-workflow.php';
        require_once TSF_PLUGIN_DIR . 'includes/class-tsf-mailer.php';
        require_once TSF_PLUGIN_DIR . 'includes/class-tsf-submission.php';
        require_once TSF_PLUGIN_DIR . 'includes/class-tsf-admin.php';
        require_once TSF_PLUGIN_DIR . 'includes/class-tsf-dashboard.php';
        require_once TSF_PLUGIN_DIR . 'includes/class-tsf-exporter.php';
        require_once TSF_PLUGIN_DIR . 'includes/class-tsf-rest-api.php';
    }

    /**
     * Initialize components
     */
    private function init_components() {
        $this->logger = TSF_Logger::get_instance();
        $this->validator = new TSF_Validator();
        $this->workflow = new TSF_Workflow();
        $this->mailer = new TSF_Mailer();
        $this->submission = new TSF_Submission();
        $this->dashboard = new TSF_Dashboard();
        $this->exporter = new TSF_Exporter();

        // Admin components only in admin
        if (is_admin()) {
            $this->admin = new TSF_Admin();
        }

        // REST API
        $this->rest_api = new TSF_REST_API();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Register post type
        add_action('init', [$this, 'register_post_type']);

        // Shortcodes
        add_shortcode('track_submission_form', [$this, 'render_form']);
        add_shortcode('tsf_upload_instructions', [$this, 'render_upload_instructions']);

        // Assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);

        // AJAX
        add_action('wp_ajax_tsf_submit', [$this, 'handle_ajax_submission']);
        add_action('wp_ajax_nopriv_tsf_submit', [$this, 'handle_ajax_submission']);
        add_action('wp_ajax_tsf_submit_v2', [$this, 'handle_ajax_submission']);
        add_action('wp_ajax_nopriv_tsf_submit_v2', [$this, 'handle_ajax_submission']);

        // Security headers
        add_action('send_headers', [$this, 'add_security_headers']);

        // Cron jobs
        add_action('tsf_weekly_report', [$this, 'send_weekly_report']);
        add_action('tsf_cleanup_files', [$this, 'cleanup_old_files']);
    }

    /**
     * Register custom post type
     */
    public function register_post_type() {
        register_post_type('track_submission', [
            'label' => __('Track Submissions', 'tsf'),
            'labels' => [
                'name' => __('Track Submissions', 'tsf'),
                'singular_name' => __('Track Submission', 'tsf'),
                'add_new' => __('Add New', 'tsf'),
                'add_new_item' => __('Add New Submission', 'tsf'),
                'edit_item' => __('Edit Submission', 'tsf'),
                'view_item' => __('View Submission', 'tsf'),
                'search_items' => __('Search Submissions', 'tsf'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'tsf-submissions',
            'capability_type' => 'post',
            'supports' => ['title'],
            'has_archive' => false,
        ]);
    }

    /**
     * Render submission form
     */
    public function render_form($atts = []) {
        $atts = shortcode_atts(['class' => 'tsf-form'], $atts);

        $genres = get_option('tsf_genres', ['Pop', 'Rock', 'Electronic/Dance', 'Folk', 'Alternative', 'Metal', 'Jazz', 'R&B', 'Hip-Hop/Rap', 'Autre']);
        $platforms = get_option('tsf_platforms', ['Spotify', 'Bandcamp', 'Youtube Music', 'Apple Music', 'Deezer', 'Soundcloud', 'Other']);
        $types = get_option('tsf_types', ['Album', 'EP', 'Single']);
        $labels = get_option('tsf_labels', ['Indie', 'Label']);
        $max_future_years = get_option('tsf_max_future_years', 2);
        $max_date = date('Y-m-d', strtotime("+{$max_future_years} years"));

        ob_start();
        include TSF_PLUGIN_DIR . 'templates/form.php';
        return ob_get_clean();
    }

    /**
     * Render upload instructions
     */
    public function render_upload_instructions($atts = []) {
        $dropbox_url = get_option('tsf_dropbox_url', '');

        ob_start();
        include TSF_PLUGIN_DIR . 'templates/upload-instructions.php';
        return ob_get_clean();
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        global $post;

        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'track_submission_form')) {
            return;
        }

        wp_enqueue_script('tsf-form', TSF_PLUGIN_URL . 'assets/js/tsf-form.js', [], TSF_VERSION, true);
        wp_localize_script('tsf-form', 'tsfData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tsf_nonce'),
            'dropbox' => esc_url(get_option('tsf_dropbox_url')),
            'messages' => [
                'error' => __('An error occurred. Please try again.', 'tsf'),
                'loading' => __('Processing...', 'tsf'),
                'success' => __('Submission successful!', 'tsf'),
            ]
        ]);

        // Legacy CSS removed - Form V2 handles its own styling (tsf-form-v2.css)
    }

    /**
     * Handle AJAX submission
     */
    public function handle_ajax_submission() {
        // Verify nonce
        if (!isset($_POST['tsf_nonce']) || !wp_verify_nonce($_POST['tsf_nonce'], 'tsf_form_v2')) {
            wp_send_json_error(['message' => __('Security check failed', 'tsf')], 403);
        }

        // Rate limiting
        $ip_address = $this->get_client_ip();
        $recent = get_transient('tsf_rate_limit_' . md5($ip_address));
        // Allow administrators to bypass rate limiting when logged in
        if ($recent && !current_user_can('tsf_bypass_rate_limit')) {
            // Log the blocked attempt for monitoring
            if ($this->logger) {
                $this->logger->warning('Rate limit exceeded', [
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

        // Prepare data
        // Handle album_title (optional) - fallback to first track title if empty
        $album_title = sanitize_text_field($_POST['album_title'] ?? '');
        $tracks = isset($_POST['tracks']) ? $_POST['tracks'] : [];
        $first_track_title = '';

        // VUL-20 FIX: Server-side validation of maximum track count
        // Frontend limit is 50, but must be enforced server-side to prevent bypass
        $max_tracks = apply_filters('tsf_max_tracks', 50); // Allow filtering for flexibility
        if (!empty($tracks) && is_array($tracks) && count($tracks) > $max_tracks) {
            $this->logger->log('warning', 'Track count exceeded maximum', [
                'ip' => $this->get_client_ip(),
                'track_count' => count($tracks),
                'max_allowed' => $max_tracks
            ]);
            wp_send_json_error([
                'message' => sprintf(
                    __('Maximum %d tracks allowed per submission. Please reduce the number of tracks.', 'tsf'),
                    $max_tracks
                )
            ], 400);
        }

        // Tracks are sent as tracks[1], tracks[2], etc. (index starts at 1)
        // Find the first track (lowest index)
        if (!empty($tracks) && is_array($tracks)) {
            ksort($tracks); // Sort by key to get first track
            $first_track = reset($tracks); // Get first element
            if (isset($first_track['title'])) {
                $first_track_title = sanitize_text_field($first_track['title']);
            }
        }

        // Use album_title if provided, otherwise use first track title
        $track_title = !empty($album_title) ? $album_title : $first_track_title;

        // Fallback to artist name if still empty (safety)
        if (empty($track_title)) {
            $track_title = sanitize_text_field($_POST['artist'] ?? 'Untitled');
        }

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
            $track_url = $_POST['track_url'] ?? '';
            if (stripos($track_url, 'spotify') !== false) {
                $platform = 'Spotify';
            } elseif (stripos($track_url, 'soundcloud') !== false) {
                $platform = 'Soundcloud';
            } elseif (stripos($track_url, 'bandcamp') !== false) {
                $platform = 'Bandcamp';
            } elseif (stripos($track_url, 'youtube') !== false) {
                $platform = 'Youtube Music';
            } elseif (stripos($track_url, 'apple') !== false || stripos($track_url, 'music.apple') !== false) {
                $platform = 'Apple Music';
            } elseif (stripos($track_url, 'deezer') !== false) {
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

        // Capture QC report if provided
        $qc_report = isset($_POST['qc_report']) ? $_POST['qc_report'] : '';
        if (!empty($qc_report) && is_string($qc_report)) {
            // Validate JSON
            $decoded = json_decode($qc_report, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // Whitelist allowed keys for security
                $valid_keys = ['metadata', 'audio', 'quality_score', 'recommendations', 'success', 'error'];
                $qc_report = array_intersect_key($decoded, array_flip($valid_keys));

                // Sanitize nested data
                if (isset($qc_report['metadata']) && is_array($qc_report['metadata'])) {
                    $qc_report['metadata'] = array_map('sanitize_text_field', $qc_report['metadata']);
                }
                if (isset($qc_report['recommendations']) && is_array($qc_report['recommendations'])) {
                    $qc_report['recommendations'] = array_map('sanitize_text_field', $qc_report['recommendations']);
                }
                // Ensure quality_score is numeric
                if (isset($qc_report['quality_score'])) {
                    $qc_report['quality_score'] = absint($qc_report['quality_score']);
                }
            } else {
                $qc_report = '';
            }
        }

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
            'qc_report' => $qc_report,
        ];
        // Enforce MP3 analysis requirement if configured. Keep a fallback if allowed.
        $require_analysis = (bool) get_option('tsf_require_mp3_analysis', true);
        $allow_skip = (bool) get_option('tsf_allow_submission_without_mp3', false);
        $skipped = isset($_POST['mp3_analysis_skipped']) && sanitize_text_field($_POST['mp3_analysis_skipped']) === '1';

        if ($require_analysis && empty($qc_report)) {
            if ($allow_skip && $skipped) {
                // Mark that analysis was skipped so downstream code can store a flag
                $data['mp3_analysis_skipped'] = 1;
            } else {
                wp_send_json_error([
                    'message' => __('Please upload and analyze your MP3 before submitting. If you have trouble, contact support or enable the submission fallback in plugin settings.', 'tsf')
                ], 400);
            }
        }

        // Create submission
        $result = $this->submission->create($data);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'errors' => $result->get_error_data()
            ], 400);
        }

    // Set rate limit duration from option (seconds). If 0, do not set.
    // Allow programmatic overrides via filters for backward compatibility.
    $rate_limit_seconds = (int) get_option('tsf_rate_limit_seconds', 300);
    $rate_limit_seconds = apply_filters('tsf_rate_limit_seconds', $rate_limit_seconds);
    // Legacy filter name supported in upgrade guide
    $rate_limit_seconds = apply_filters('tsf_rate_limit_duration', $rate_limit_seconds);
        if ($rate_limit_seconds > 0) {
            set_transient('tsf_rate_limit_' . md5($ip_address), true, $rate_limit_seconds);
        }

        wp_send_json_success([
            'message' => __('Submission saved successfully', 'tsf'),
            'redirect' => home_url('/thanks-submit/')
        ]);
    }

    /**
     * Add security headers
     */
    public function add_security_headers() {
        if (!is_admin() && is_singular()) {
            global $post;
            if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'track_submission_form')) {
                // Strengthened CSP with specific directives
                $csp = "default-src 'none'; " .
                       "script-src 'self'; " .
                       "style-src 'self' 'unsafe-inline'; " .  // unsafe-inline needed for WordPress inline styles
                       "img-src 'self' https: data:; " .
                       "font-src 'self' https://fonts.gstatic.com; " .
                       "connect-src 'self' " . admin_url('admin-ajax.php', 'relative') . " " . rest_url('tsf/v1/', 'relative') . "; " .
                       "frame-src https://embeds.beehiiv.com https://www.youtube.com https://open.spotify.com https://w.soundcloud.com https://bandcamp.com; " .
                       "object-src 'none'; " .
                       "base-uri 'self'; " .
                       "form-action 'self'; " .
                       "upgrade-insecure-requests;";

                header("Content-Security-Policy: " . $csp);
                header('X-Frame-Options: SAMEORIGIN');
                header('X-Content-Type-Options: nosniff');
                header('Referrer-Policy: strict-origin-when-cross-origin');
                header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
            }
        }
    }

    /**
     * Send weekly report
     */
    public function send_weekly_report() {
        $timezone = wp_timezone();
        $today = new DateTime('now', $timezone);
        $last_week = clone $today;
        $last_week->modify('-7 days');

        // Get submissions
        $submissions = $this->submission->get_submissions([
            'posts_per_page' => -1,
            'date_query' => [
                [
                    'after' => $last_week->format('Y-m-d'),
                    'before' => $today->format('Y-m-d'),
                    'inclusive' => true,
                ]
            ]
        ]);

        if (!empty($submissions['submissions'])) {
            $this->mailer->send_weekly_digest($submissions['submissions'], [
                'total' => $submissions['total']
            ]);
        }

        $this->logger->info('Weekly report sent', ['count' => $submissions['total']]);
    }

    /**
     * Cleanup old files
     */
    public function cleanup_old_files() {
        $upload_dir = wp_upload_dir();
        $tsf_dir = trailingslashit($upload_dir['basedir']) . 'tsf-reports/';

        if (!is_dir($tsf_dir)) {
            return;
        }

        // VUL-8 FIX: Normalize base directory path for path traversal protection
        $tsf_dir_real = realpath($tsf_dir);
        if ($tsf_dir_real === false) {
            $this->logger->error('Cleanup failed: invalid directory path', ['dir' => $tsf_dir]);
            return;
        }

        $retention_days = get_option('tsf_file_retention_days', 90);
        $cutoff_time = time() - ($retention_days * DAY_IN_SECONDS);
        $deleted = 0;

        try {
            $iterator = new DirectoryIterator($tsf_dir_real);
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isFile() && $fileinfo->getExtension() === 'csv') {
                    // VUL-8 FIX: Verify file is within the allowed directory
                    $file_real_path = $fileinfo->getRealPath();

                    // Ensure file is within tsf-reports directory (prevent path traversal)
                    if (strpos($file_real_path, $tsf_dir_real) !== 0) {
                        $this->logger->error('Security: Path traversal attempt detected', [
                            'file' => $fileinfo->getFilename(),
                            'path' => $file_real_path
                        ]);
                        continue; // Skip this file
                    }

                    if ($fileinfo->getMTime() < $cutoff_time) {
                        if (@unlink($file_real_path)) {
                            $deleted++;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Cleanup failed', ['error' => $e->getMessage()]);
        }

        if ($deleted > 0) {
            $this->logger->info("Cleanup completed: deleted {$deleted} files");
        }
    }

    /**
     * Get client IP
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
