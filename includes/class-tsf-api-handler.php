<?php
/**
 * API Handler for Track Verification
 * Handles Spotify, SoundCloud, YouTube Music, and Bandcamp API calls
 *
 * @package TrackSubmissionForm
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_API_Handler {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * API credentials from WordPress options
     */
    private $spotify_client_id;
    private $spotify_client_secret;
    private $spotify_access_token;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Load API credentials - prioritize wp-config.php constants (more secure)
        // For production: define('TSF_SPOTIFY_CLIENT_ID', 'your_id') in wp-config.php
        // For production: define('TSF_SPOTIFY_CLIENT_SECRET', 'your_secret') in wp-config.php
        $this->spotify_client_id = defined('TSF_SPOTIFY_CLIENT_ID')
            ? TSF_SPOTIFY_CLIENT_ID
            : get_option('tsf_spotify_client_id', '');

        $this->spotify_client_secret = defined('TSF_SPOTIFY_CLIENT_SECRET')
            ? TSF_SPOTIFY_CLIENT_SECRET
            : get_option('tsf_spotify_client_secret', '');

        // Register REST API routes
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('tsf/v1', '/verify-track', [
            'methods' => 'POST',
            'callback' => [$this, 'verify_track'],
            'permission_callback' => [$this, 'verify_api_permission'],
        ]);

        register_rest_route('tsf/v1', '/analyze-mp3', [
            'methods' => 'POST',
            'callback' => [$this, 'analyze_mp3'],
            'permission_callback' => [$this, 'verify_api_permission'],
        ]);
    }

    /**
     * Verify permission for API endpoints with rate limiting
     */
    public function verify_api_permission($request) {
        // Verify nonce
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            error_log('TSF API: Invalid nonce from IP ' . $this->get_client_ip());
            return new WP_Error('rest_forbidden', __('Invalid security token', 'tsf'), ['status' => 403]);
        }

        // Rate limiting per IP (10 requests per minute)
        $ip = $this->get_client_ip();
        $rate_key = 'tsf_api_rate_' . hash_hmac('sha256', $ip, wp_salt('nonce'));
        $attempts = get_transient($rate_key) ?: 0;

        if ($attempts > 10) {
            error_log('TSF API: Rate limit exceeded from IP ' . $ip . ' (' . $attempts . ' attempts)');
            return new WP_Error('rest_too_many_requests', __('Too many API requests. Please wait before trying again.', 'tsf'), ['status' => 429]);
        }

        set_transient($rate_key, $attempts + 1, 60); // 1 minute window
        return true;
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
                    $ip = explode(',', $ip)[0];
                }
                $ip = filter_var(trim($ip), FILTER_VALIDATE_IP);
                if ($ip) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Verify track from streaming platform
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function verify_track(WP_REST_Request $request) {
        // Log request for debugging
        error_log('TSF: verify_track called');

        $platform = $request->get_param('platform');
        $url = $request->get_param('url');

        // Log parameters
        error_log('TSF: Platform=' . $platform . ', URL=' . $url);

        // Validate parameters
        if (empty($platform) || empty($url)) {
            error_log('TSF: Missing parameters');
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Platform and URL are required', 'tsf')
            ], 400);
        }

        // Verify URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            error_log('TSF: Invalid URL format');
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Invalid URL format', 'tsf')
            ], 400);
        }

        // Normalize platform name (convert to lowercase)
        $platform = strtolower($platform);
        error_log('TSF: Normalized platform=' . $platform);

        // Route to appropriate handler
        switch ($platform) {
            case 'spotify':
                $result = $this->verify_spotify($url);
                break;
            case 'soundcloud':
                $result = $this->verify_soundcloud($url);
                break;
            case 'youtube':
            case 'youtube music':
                $result = $this->verify_youtube($url);
                break;
            case 'bandcamp':
                $result = $this->verify_bandcamp($url);
                break;
            case 'other':
                $result = [
                    'success' => true,
                    'data' => [
                        'title' => __('Manual Verification Required', 'tsf'),
                        'artist' => '',
                        'album' => '',
                        'cover' => '',
                        'duration' => '',
                        'url' => $url,
                        'match_score' => 0,
                        'verified' => false
                    ]
                ];
                break;
            default:
                error_log('TSF: Unsupported platform: ' . $platform);
                return new WP_REST_Response([
                    'success' => false,
                    'message' => sprintf(__('Unsupported platform: %s. Please select a valid platform.', 'tsf'), $platform)
                ], 400);
        }

        // Log result
        error_log('TSF: Verification result - success=' . ($result['success'] ? 'true' : 'false'));
        if (!$result['success']) {
            error_log('TSF: Error message=' . ($result['message'] ?? 'No message'));
        }

        if ($result['success']) {
            return new WP_REST_Response($result, 200);
        } else {
            return new WP_REST_Response($result, 400);
        }
    }

    /**
     * Verify Spotify track
     *
     * @param string $url Spotify track URL
     * @return array
     */
    private function verify_spotify($url) {
        error_log('TSF: verify_spotify called with URL=' . $url);

        // Extract track ID from URL
        // Formats: https://open.spotify.com/track/TRACK_ID or spotify:track:TRACK_ID
        preg_match('/track[\/:]([a-zA-Z0-9]+)/', $url, $matches);

        if (empty($matches[1])) {
            error_log('TSF: Could not extract Spotify track ID from URL');
            return [
                'success' => false,
                'message' => __('Invalid Spotify URL format. Expected format: https://open.spotify.com/track/TRACK_ID', 'tsf')
            ];
        }

        $track_id = $matches[1];
        error_log('TSF: Extracted Spotify track ID=' . $track_id);

        // Get access token
        $access_token = $this->get_spotify_access_token();

        if (!$access_token) {
            error_log('TSF: Failed to get Spotify access token');
            return [
                'success' => false,
                'message' => __('Unable to authenticate with Spotify API. Please check API credentials in plugin settings.', 'tsf')
            ];
        }

        error_log('TSF: Got Spotify access token, calling API');

        // Call Spotify API
        // VUL-15 FIX: Enable SSL verification for external API calls
        $response = wp_remote_get("https://api.spotify.com/v1/tracks/{$track_id}", [
            'headers' => [
                'Authorization' => "Bearer {$access_token}"
            ],
            'timeout' => 15,
            'sslverify' => true // Enforce SSL certificate validation
        ]);

        if (is_wp_error($response)) {
            error_log('TSF: Spotify API request failed: ' . $response->get_error_message());
            return [
                'success' => false,
                'message' => __('Failed to connect to Spotify API: ', 'tsf') . $response->get_error_message()
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        error_log('TSF: Spotify API response status=' . $status_code);

        if ($status_code !== 200) {
            error_log('TSF: Spotify API error - Status ' . $status_code . ': ' . wp_remote_retrieve_body($response));

            // Parse error message from Spotify
            $error_message = __('Track not found on Spotify', 'tsf');
            if (isset($body['error']['message'])) {
                $error_message = $body['error']['message'];
            }

            return [
                'success' => false,
                'message' => $error_message
            ];
        }

        // Extract track data
        $track_data = [
            'title' => $body['name'] ?? '',
            'artist' => !empty($body['artists']) ? $body['artists'][0]['name'] : '',
            'album' => $body['album']['name'] ?? '',
            'cover' => !empty($body['album']['images']) ? $body['album']['images'][0]['url'] : '',
            'duration' => isset($body['duration_ms']) ? $this->format_duration($body['duration_ms']) : '',
            'url' => $body['external_urls']['spotify'] ?? $url,
            'match_score' => 100,
            'verified' => true
        ];

        error_log('TSF: Successfully verified Spotify track: ' . $track_data['title']);

        return [
            'success' => true,
            'data' => $track_data
        ];
    }

    /**
     * Get Spotify access token using client credentials flow
     *
     * @return string|false
     */
    private function get_spotify_access_token() {
        // Check if we have a cached token
        $cached_token = get_transient('tsf_spotify_access_token');
        if ($cached_token) {
            error_log('TSF: Using cached Spotify token');
            return $cached_token;
        }

        error_log('TSF: No cached token, requesting new Spotify access token');

        // Check if credentials are configured
        if (empty($this->spotify_client_id) || empty($this->spotify_client_secret)) {
            error_log('TSF: Spotify credentials not configured. Client ID empty: ' . (empty($this->spotify_client_id) ? 'yes' : 'no') . ', Secret empty: ' . (empty($this->spotify_client_secret) ? 'yes' : 'no'));
            return false;
        }

        // Request new token
        // VUL-15 FIX: Enable SSL verification
        $response = wp_remote_post('https://accounts.spotify.com/api/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->spotify_client_id . ':' . $this->spotify_client_secret),
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => [
                'grant_type' => 'client_credentials'
            ],
            'timeout' => 15,
            'sslverify' => true // Enforce SSL certificate validation
        ]);

        if (is_wp_error($response)) {
            error_log('TSF: Spotify token request failed: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        error_log('TSF: Spotify token response status=' . $status_code);

        if ($status_code !== 200) {
            error_log('TSF: Spotify token error: ' . wp_remote_retrieve_body($response));
        }

        if (empty($body['access_token'])) {
            error_log('TSF: No access_token in Spotify response');
            return false;
        }

        error_log('TSF: Successfully obtained Spotify access token');

        // Cache token for 55 minutes (tokens expire after 1 hour)
        set_transient('tsf_spotify_access_token', $body['access_token'], 55 * MINUTE_IN_SECONDS);

        return $body['access_token'];
    }

    /**
     * Verify SoundCloud track
     *
     * @param string $url SoundCloud track URL
     * @return array
     */
    private function verify_soundcloud($url) {
        // SoundCloud oEmbed endpoint
        $oembed_url = 'https://soundcloud.com/oembed?format=json&url=' . urlencode($url);

        // VUL-15 FIX: Enable SSL verification
        $response = wp_remote_get($oembed_url, [
            'timeout' => 15,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Failed to connect to SoundCloud', 'tsf')
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return [
                'success' => false,
                'message' => __('Track not found on SoundCloud', 'tsf')
            ];
        }

        // Parse title (usually "Artist - Title" format)
        $title_parts = explode(' - ', $body['title'] ?? '', 2);

        $track_data = [
            'title' => count($title_parts) > 1 ? $title_parts[1] : $body['title'],
            'artist' => $body['author_name'] ?? (count($title_parts) > 1 ? $title_parts[0] : ''),
            'album' => '',
            'cover' => $body['thumbnail_url'] ?? '',
            'duration' => '',
            'url' => $body['author_url'] ?? $url,
            'match_score' => 90,
            'verified' => true
        ];

        return [
            'success' => true,
            'data' => $track_data
        ];
    }

    /**
     * Verify YouTube track
     *
     * @param string $url YouTube URL
     * @return array
     */
    private function verify_youtube($url) {
        // Extract video ID
        preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $matches);

        if (empty($matches[1])) {
            return [
                'success' => false,
                'message' => __('Invalid YouTube URL format', 'tsf')
            ];
        }

        $video_id = $matches[1];

        // YouTube oEmbed endpoint
        $oembed_url = 'https://www.youtube.com/oembed?format=json&url=' . urlencode($url);

        // VUL-15 FIX: Enable SSL verification
        $response = wp_remote_get($oembed_url, [
            'timeout' => 15,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Failed to connect to YouTube', 'tsf')
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return [
                'success' => false,
                'message' => __('Video not found on YouTube', 'tsf')
            ];
        }

        // Parse title (try to extract artist from title)
        $title = $body['title'] ?? '';
        $title_parts = preg_split('/[-–—]/', $title, 2);

        $track_data = [
            'title' => count($title_parts) > 1 ? trim($title_parts[1]) : $title,
            'artist' => $body['author_name'] ?? (count($title_parts) > 1 ? trim($title_parts[0]) : ''),
            'album' => '',
            'cover' => $body['thumbnail_url'] ?? "https://img.youtube.com/vi/{$video_id}/maxresdefault.jpg",
            'duration' => '',
            'url' => $url,
            'match_score' => 80,
            'verified' => true
        ];

        return [
            'success' => true,
            'data' => $track_data
        ];
    }

    /**
     * Verify Bandcamp track
     *
     * @param string $url Bandcamp URL
     * @return array
     */
    private function verify_bandcamp($url) {
        // Bandcamp doesn't have an official API, so we'll do basic scraping
        // VUL-15 FIX: Enable SSL verification
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Failed to connect to Bandcamp', 'tsf')
            ];
        }

        $html = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return [
                'success' => false,
                'message' => __('Track not found on Bandcamp', 'tsf')
            ];
        }

        // Extract data from HTML (basic parsing)
        $title = '';
        $artist = '';
        $cover = '';

        // Try to extract title from meta tags
        if (preg_match('/<meta property="og:title" content="([^"]+)"/', $html, $matches)) {
            $title = html_entity_decode($matches[1]);
        }

        // Try to extract artist
        if (preg_match('/<meta property="og:site_name" content="([^"]+)"/', $html, $matches)) {
            $artist = html_entity_decode($matches[1]);
        }

        // Try to extract cover image
        if (preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $matches)) {
            $cover = $matches[1];
        }

        $track_data = [
            'title' => $title,
            'artist' => $artist,
            'album' => '',
            'cover' => $cover,
            'duration' => '',
            'url' => $url,
            'match_score' => 75,
            'verified' => true
        ];

        return [
            'success' => true,
            'data' => $track_data
        ];
    }

    /**
     * Format duration from milliseconds to MM:SS
     *
     * @param int $ms Duration in milliseconds
     * @return string
     */
    private function format_duration($ms) {
        $seconds = floor($ms / 1000);
        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Analyze MP3 file using getID3
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function analyze_mp3(WP_REST_Request $request) {
        // Check if file was uploaded
        $files = $request->get_file_params();
        if (empty($files['mp3_file'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('No MP3 file uploaded', 'tsf')
            ], 400);
        }

        $file = $files['mp3_file'];

        // Validate file type
        $allowed_types = ['audio/mpeg', 'audio/mp3'];
        if (!in_array($file['type'], $allowed_types)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Invalid file type. Please upload an MP3 file.', 'tsf')
            ], 400);
        }

        // Validate file extension (additional security layer)
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'mp3') {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Invalid file extension. Only .mp3 files are allowed.', 'tsf')
            ], 400);
        }

        // Validate file size (50MB max)
        $max_size = 50 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('File too large. Maximum size is 50MB.', 'tsf')
            ], 400);
        }

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('File upload error occurred.', 'tsf')
            ], 400);
        }

        // Save MP3 to WordPress uploads directory temporarily
        $upload_dir = wp_upload_dir();
        $tsf_upload_dir = $upload_dir['basedir'] . '/tsf-submissions';

        // Create directory if it doesn't exist
        if (!file_exists($tsf_upload_dir)) {
            wp_mkdir_p($tsf_upload_dir);
            // Add .htaccess to protect directory
            file_put_contents($tsf_upload_dir . '/.htaccess', 'deny from all');
        }

        // Generate unique filename: timestamp_random_originalname.mp3
        $unique_filename = time() . '_' . wp_generate_password(8, false) . '_' . sanitize_file_name($file['name']);
        $destination = $tsf_upload_dir . '/' . $unique_filename;

        // Move uploaded file to permanent location
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Failed to save MP3 file.', 'tsf')
            ], 500);
        }

        // Analyze using our MP3 Analyzer class
        try {
            $analyzer = new TSF_MP3_Analyzer();
            $analysis = $analyzer->analyze($destination);

            // Check if analysis succeeded
            if (!$analysis['success']) {
                // Delete file if analysis failed
                @unlink($destination);
                return new WP_REST_Response([
                    'success' => false,
                    'message' => $analysis['error'] ?? __('Failed to analyze MP3', 'tsf')
                ], 400);
            }

            // Return analysis results with quality score + file path
            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'metadata' => $analysis['metadata'],
                    'audio' => $analysis['audio'],
                    'quality_score' => $analysis['quality_score'],
                    'total_score' => $analysis['total_score'],
                    'metadata_score' => $analysis['metadata_score'],
                    'audio_score' => $analysis['audio_score'],
                    'professional_score' => $analysis['professional_score'],
                    'recommendations' => $analysis['recommendations'],
                    'temp_file_path' => str_replace($upload_dir['basedir'], '', $destination), // Relative path
                    'filename' => $unique_filename
                ]
            ], 200);

        } catch (Exception $e) {
            // Delete file on error
            @unlink($destination);
            // Log full error for debugging (not exposed to user)
            error_log('TSF MP3 Analysis Error: ' . $e->getMessage());

            return new WP_REST_Response([
                'success' => false,
                'message' => __('Error analyzing MP3. Please try a different file or contact support.', 'tsf')
            ], 500);
        }
    }

    /**
     * Calculate MP3 quality score based on metadata and audio quality
     *
     * @param array $metadata
     * @param array $audio_info
     * @return array
     */
    private function calculate_mp3_quality_score($metadata, $audio_info) {
        $metadata_score = 0;
        $audio_score = 0;
        $professional_score = 0;
        $missing_tags = [];
        $recommendations = [];

        // Metadata scoring (40 points max)
        if (!empty($metadata['artist'])) {
            $metadata_score += 10;
        } else {
            $missing_tags[] = 'Artist Name';
        }

        if (!empty($metadata['title'])) {
            $metadata_score += 10;
        } else {
            $missing_tags[] = 'Track Title';
        }

        if (!empty($metadata['album'])) {
            $metadata_score += 10;
        } else {
            $missing_tags[] = 'Album Name';
        }

        if (!empty($metadata['year'])) {
            $metadata_score += 5;
        } else {
            $missing_tags[] = 'Release Year';
        }

        if ($metadata['has_cover']) {
            $metadata_score += 5;
        } else {
            $missing_tags[] = 'Album Artwork';
        }

        // Audio quality scoring (30 points max)
        $bitrate = $audio_info['bitrate'];
        if ($bitrate >= 320) {
            $audio_score = 30;
        } elseif ($bitrate >= 256) {
            $audio_score = 25;
            $recommendations[] = 'Consider using 320kbps for best quality';
        } elseif ($bitrate >= 192) {
            $audio_score = 20;
            $recommendations[] = 'Bitrate is acceptable, but 320kbps is recommended';
        } elseif ($bitrate >= 128) {
            $audio_score = 10;
            $recommendations[] = 'Low bitrate detected. Please use at least 192kbps';
        } else {
            $audio_score = 0;
            $recommendations[] = 'Bitrate too low. Minimum 128kbps required';
        }

        // Professional scoring (30 points max)
        // Sample rate (10 points)
        if ($audio_info['sample_rate'] >= 44100) {
            $professional_score += 10;
        } else {
            $professional_score += 5;
            $recommendations[] = 'Sample rate should be at least 44.1kHz';
        }

        // Stereo channels (10 points)
        if ($audio_info['channels'] == 2) {
            $professional_score += 10;
        } else {
            $professional_score += 5;
            $recommendations[] = 'Track should be in stereo';
        }

        // CBR vs VBR (10 points)
        if ($audio_info['bitrate_mode'] == 'cbr') {
            $professional_score += 10;
        } else {
            $professional_score += 8;
        }

        // Calculate total score
        $total_score = $metadata_score + $audio_score + $professional_score;

        return [
            'total' => $total_score,
            'metadata' => $metadata_score,
            'audio' => $audio_score,
            'professional' => $professional_score,
            'missing_tags' => $missing_tags,
            'recommendations' => $recommendations
        ];
    }

    /**
     * Format filesize in human-readable format
     *
     * @param int $bytes
     * @return string
     */
    private function format_filesize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Detect if track is instrumental
     *
     * @param array $metadata ID3 tag metadata
     * @param array $file_info getID3 file information
     * @return bool True if instrumental, false otherwise
     */
    private function detect_instrumental($metadata, $file_info) {
        // Method 1: Check for "instrumental" keyword in title, genre, or comments
        $text_fields = [
            strtolower($metadata['title'] ?? ''),
            strtolower($metadata['genre'] ?? ''),
            strtolower($file_info['tags']['id3v2']['comment'][0] ?? ''),
            strtolower($file_info['tags']['id3v1']['comment'][0] ?? '')
        ];

        foreach ($text_fields as $field) {
            if (strpos($field, 'instrumental') !== false ||
                strpos($field, 'instru') !== false ||
                strpos($field, 'karaoke') !== false ||
                strpos($field, 'backing track') !== false) {
                return true;
            }
        }

        // Method 2: Check genre (some genres are typically instrumental)
        $instrumental_genres = ['classical', 'jazz', 'ambient', 'electronic', 'soundtrack', 'score'];
        $genre_lower = strtolower($metadata['genre'] ?? '');
        foreach ($instrumental_genres as $inst_genre) {
            if (strpos($genre_lower, $inst_genre) !== false) {
                // Not a definitive indicator, but flag for review
                // We'll return false here to avoid false positives
            }
        }

        // Method 3: Check for specific ID3 tags that indicate instrumental
        // Some taggers use custom frames for this
        if (isset($file_info['id3v2']['TXXX'])) {
            foreach ($file_info['id3v2']['TXXX'] as $txxx) {
                $description = strtolower($txxx['description'] ?? '');
                $data = strtolower($txxx['data'] ?? '');

                if ($description === 'instrumental' && ($data === 'yes' || $data === '1' || $data === 'true')) {
                    return true;
                }
            }
        }

        // Default: assume not instrumental
        return false;
    }
}

// Initialize
TSF_API_Handler::get_instance();
