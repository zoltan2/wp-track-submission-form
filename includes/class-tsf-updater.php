<?php
/**
 * Track Submission Form Plugin Updater
 * Handles automatic updates from GitHub (private repo) or custom JSON server
 *
 * @package TrackSubmissionForm
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_Updater {

    private $plugin_slug;
    private $plugin_basename;
    private $version;
    private $github_repo;
    private $github_token;
    private $json_update_url;
    private $cache_key;
    private $cache_allowed;

    /**
     * Constructor
     */
    public function __construct($plugin_file, $github_repo = '', $github_token = '', $json_url = '') {
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->plugin_basename = $plugin_file;
        $this->version = $this->get_plugin_version();
        $this->github_repo = $github_repo; // Format: "username/repo"
        $this->github_token = $github_token; // Personal Access Token for private repos
        $this->json_update_url = $json_url; // Fallback JSON URL
        $this->cache_key = 'tsf_update_check';
        $this->cache_allowed = true;

        // Hook into WordPress update system
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('site_transient_update_plugins', [$this, 'check_update']);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);

        // Add custom update check action
        add_action('admin_init', [$this, 'maybe_force_check']);
    }

    /**
     * Get current plugin version from header
     */
    private function get_plugin_version() {
        $plugin_data = get_file_data($this->plugin_basename, ['Version' => 'Version']);
        return $plugin_data['Version'] ?? '1.0.0';
    }

    /**
     * Check for updates
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get remote version info
        $remote = $this->get_remote_info();

        if (!$remote || !isset($remote['version'])) {
            return $transient;
        }

        // Compare versions
        if (version_compare($this->version, $remote['version'], '<')) {
            $plugin = [
                'slug' => $this->plugin_slug,
                'plugin' => $this->plugin_slug,
                'new_version' => $remote['version'],
                'url' => $remote['homepage'] ?? '',
                'package' => $remote['download_url'] ?? '',
                'tested' => $remote['tested'] ?? '',
                'requires_php' => $remote['requires_php'] ?? '7.4',
                'compatibility' => new stdClass(),
            ];

            $transient->response[$this->plugin_slug] = (object) $plugin;
        }

        return $transient;
    }

    /**
     * Get plugin information for details screen
     */
    public function plugin_info($false, $action, $response) {
        if ($action !== 'plugin_information') {
            return $false;
        }

        if (empty($response->slug) || $response->slug !== $this->plugin_slug) {
            return $false;
        }

        $remote = $this->get_remote_info();

        if (!$remote) {
            return $false;
        }

        $plugin = [
            'name' => $remote['name'] ?? 'Track Submission Form',
            'slug' => $this->plugin_slug,
            'version' => $remote['version'],
            'author' => $remote['author'] ?? 'Zoltan Janosi',
            'homepage' => $remote['homepage'] ?? '',
            'requires' => $remote['requires'] ?? '5.0',
            'tested' => $remote['tested'] ?? '',
            'requires_php' => $remote['requires_php'] ?? '7.4',
            'download_link' => $remote['download_url'] ?? '',
            'sections' => [
                'description' => $remote['description'] ?? '',
                'changelog' => $remote['changelog'] ?? '',
            ],
            'banners' => $remote['banners'] ?? [],
            'icons' => $remote['icons'] ?? [],
        ];

        return (object) $plugin;
    }

    /**
     * Get remote version info (GitHub primary, JSON fallback)
     */
    private function get_remote_info() {
        // Check cache first (12 hours)
        if ($this->cache_allowed) {
            $cached = get_transient($this->cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $remote = false;

        // Try GitHub first (for private repos)
        if (!empty($this->github_repo) && !empty($this->github_token)) {
            $remote = $this->get_github_info();
        }

        // Fallback to JSON server
        if (!$remote && !empty($this->json_update_url)) {
            $remote = $this->get_json_info();
        }

        // Cache result for 12 hours
        if ($remote) {
            set_transient($this->cache_key, $remote, 12 * HOUR_IN_SECONDS);
        }

        return $remote;
    }

    /**
     * Get version info from GitHub releases (supports private repos)
     */
    private function get_github_info() {
        $api_url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";

        $args = [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ],
            'timeout' => 10,
        ];

        // Add authentication for private repos
        if (!empty($this->github_token)) {
            $args['headers']['Authorization'] = 'token ' . $this->github_token;
        }

        $response = wp_remote_get($api_url, $args);

        if (is_wp_error($response)) {
            error_log('TSF Updater: GitHub API error - ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('TSF Updater: GitHub API returned status ' . $status_code);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['tag_name'])) {
            return false;
        }

        // Extract version from tag (remove 'v' prefix if present)
        $version = ltrim($data['tag_name'], 'v');

        // Find ZIP asset
        $download_url = '';
        if (!empty($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (strpos($asset['name'], '.zip') !== false) {
                    $download_url = $asset['browser_download_url'];
                    // Add token to download URL for private repos (SECURITY FIX)
                    if (!empty($this->github_token)) {
                        $download_url = add_query_arg('access_token', $this->github_token, $download_url);
                    }
                    break;
                }
            }
        }

        // Fallback to zipball if no zip asset found
        if (empty($download_url)) {
            $download_url = $data['zipball_url'] ?? '';
            // Add token to download URL for private repos
            if (!empty($this->github_token)) {
                $download_url = add_query_arg('access_token', $this->github_token, $download_url);
            }
        }

        return [
            'version' => $version,
            'name' => $data['name'] ?? 'Version ' . $version,
            'description' => $data['body'] ?? '',
            'changelog' => $this->format_changelog($data['body'] ?? ''),
            'download_url' => $download_url,
            'homepage' => "https://github.com/{$this->github_repo}",
            'author' => 'Zoltan Janosi',
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
            'source' => 'github'
        ];
    }

    /**
     * Get version info from custom JSON server
     */
    private function get_json_info() {
        $response = wp_remote_get($this->json_update_url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('TSF Updater: JSON server error - ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['version'])) {
            return false;
        }

        $data['source'] = 'json';
        return $data;
    }

    /**
     * Format changelog from Markdown to HTML
     */
    private function format_changelog($markdown) {
        // Simple Markdown to HTML conversion
        $html = $markdown;

        // Headers
        $html = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $html);

        // Lists
        $html = preg_replace('/^\- (.*?)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>\n?)+/s', '<ul>$0</ul>', $html);

        // Bold
        $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);

        // Line breaks
        $html = nl2br($html);

        return $html;
    }

    /**
     * After plugin installation
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        $install_directory = plugin_dir_path($this->plugin_basename);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;

        // Clear update cache
        delete_transient($this->cache_key);

        if ($this->plugin_slug === $hook_extra['plugin']) {
            activate_plugin($this->plugin_slug);
        }

        return $result;
    }

    /**
     * Force update check via admin action
     */
    public function maybe_force_check() {
        if (!current_user_can('update_plugins')) {
            return;
        }

        if (isset($_GET['tsf_force_check']) && wp_verify_nonce($_GET['_wpnonce'], 'tsf_force_check')) {
            // Clear cache
            delete_transient($this->cache_key);
            delete_site_transient('update_plugins');

            // Force WordPress to check for updates
            wp_update_plugins();

            // Redirect back
            wp_safe_redirect(remove_query_arg(['tsf_force_check', '_wpnonce']));
            exit;
        }
    }

    /**
     * Get update status for admin display
     */
    public function get_update_status() {
        $remote = $this->get_remote_info();

        if (!$remote) {
            return [
                'status' => 'error',
                'message' => 'Unable to check for updates',
                'current_version' => $this->version,
            ];
        }

        $update_available = version_compare($this->version, $remote['version'], '<');

        return [
            'status' => $update_available ? 'available' : 'up_to_date',
            'current_version' => $this->version,
            'latest_version' => $remote['version'],
            'update_available' => $update_available,
            'changelog' => $remote['changelog'] ?? '',
            'source' => $remote['source'] ?? 'unknown',
        ];
    }
}
