<?php
/**
 * Validator Class
 *
 * Advanced validation with custom rules and detailed error messages
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_Validator {

    private $errors = [];
    private $logger;

    public function __construct() {
        $this->logger = TSF_Logger::get_instance();
    }

    /**
     * Validate submission data
     *
     * @param array $data Submission data
     * @return bool True if valid, false otherwise
     */
    public function validate_submission($data) {
        $this->errors = [];

        // Required fields validation
        $this->validate_required_fields($data);

        // Field-specific validation
        if (!empty($data['artist'])) {
            $this->validate_artist($data['artist']);
        }

        if (!empty($data['track_title'])) {
            $this->validate_track_title($data['track_title']);
        }

        if (!empty($data['email'])) {
            $this->validate_email($data['email']);
        }

        if (!empty($data['phone'])) {
            $this->validate_phone($data['phone']);
        }

        if (!empty($data['duration'])) {
            $this->validate_duration($data['duration']);
        }

        if (!empty($data['release_date'])) {
            $this->validate_release_date($data['release_date']);
        }

        if (!empty($data['track_url'])) {
            $this->validate_url($data['track_url'], 'track_url');
        }

        if (!empty($data['social_url'])) {
            $this->validate_url($data['social_url'], 'social_url');
        }

        if (!empty($data['description'])) {
            $this->validate_description($data['description']);
        }

        if (!empty($data['country'])) {
            $this->validate_country($data['country']);
        }

        // Validate against allowed options (only user-visible fields)
        $this->validate_genre($data['genre'] ?? '');
        $this->validate_label($data['label'] ?? '');

        // Auto-generated fields (platform, type, instrumental) - skip strict validation
        // These are auto-filled by fallback logic, not user-selected

        // Log validation result
        if (!empty($this->errors)) {
            $this->logger->warning('Submission validation failed', [
                'errors' => $this->errors,
                'data' => $this->sanitize_log_data($data)
            ]);
        }

        return empty($this->errors);
    }

    /**
     * Validate required fields
     */
    private function validate_required_fields($data) {
        $required_fields = [
            'artist' => __('Artist name', 'tsf'),
            // track_title is generated automatically from album_title or first track
            'genre' => __('Genre', 'tsf'),
            // duration is auto-extracted from MP3 upload (fallback: '0:00')
            // instrumental is auto-detected from MP3 (fallback: 'No')
            'release_date' => __('Release date', 'tsf'),
            'email' => __('Email', 'tsf'),
            // platform is auto-detected from track_url (fallback: 'Other')
            'track_url' => __('Track URL', 'tsf'),
            // type is auto-calculated from track count (fallback: 'Single')
            'label' => __('Label', 'tsf'),
            'country' => __('Country', 'tsf'),
            'description' => __('Description', 'tsf'),
        ];

        foreach ($required_fields as $field => $label) {
            if (empty($data[$field]) || trim($data[$field]) === '') {
                $this->add_error($field, sprintf(__('%s is required', 'tsf'), $label));
            }
        }
    }

    /**
     * Validate artist name
     */
    private function validate_artist($artist) {
        if (mb_strlen($artist) < 2) {
            $this->add_error('artist', __('Artist name must be at least 2 characters', 'tsf'));
        }

        if (mb_strlen($artist) > 200) {
            $this->add_error('artist', __('Artist name must not exceed 200 characters', 'tsf'));
        }

        // Check for suspicious patterns
        if (preg_match('/[<>{}]/', $artist)) {
            $this->add_error('artist', __('Artist name contains invalid characters', 'tsf'));
        }
    }

    /**
     * Validate track title
     */
    private function validate_track_title($title) {
        if (mb_strlen($title) < 2) {
            $this->add_error('track_title', __('Track title must be at least 2 characters', 'tsf'));
        }

        if (mb_strlen($title) > 200) {
            $this->add_error('track_title', __('Track title must not exceed 200 characters', 'tsf'));
        }

        if (preg_match('/[<>{}]/', $title)) {
            $this->add_error('track_title', __('Track title contains invalid characters', 'tsf'));
        }
    }

    /**
     * Validate email
     */
    private function validate_email($email) {
        if (!is_email($email)) {
            $this->add_error('email', __('Invalid email address', 'tsf'));
            return;
        }

        // Check for disposable email domains
        $disposable_domains = ['tempmail.com', '10minutemail.com', 'guerrillamail.com', 'mailinator.com'];
        $domain = substr(strrchr($email, "@"), 1);

        if (in_array($domain, $disposable_domains, true)) {
            $this->add_error('email', __('Please use a permanent email address', 'tsf'));
        }
    }

    /**
     * Validate phone number
     */
    private function validate_phone($phone) {
        // Remove common separators
        $clean_phone = preg_replace('/[\s\-\(\)\+]/', '', $phone);

        if (!preg_match('/^[0-9]{8,20}$/', $clean_phone)) {
            $this->add_error('phone', __('Invalid phone number format', 'tsf'));
        }
    }

    /**
     * Validate duration format (mm:ss)
     */
    private function validate_duration($duration) {
        if (!preg_match('/^[0-9]{1,3}:[0-5][0-9]$/', $duration)) {
            $this->add_error('duration', __('Duration must be in mm:ss format (e.g., 3:45)', 'tsf'));
            return;
        }

        // Validate reasonable duration (0:30 to 60:00)
        list($minutes, $seconds) = explode(':', $duration);
        $total_seconds = ($minutes * 60) + $seconds;

        if ($total_seconds < 30) {
            $this->add_error('duration', __('Track duration must be at least 30 seconds', 'tsf'));
        }

        if ($total_seconds > 3600) {
            $this->add_error('duration', __('Track duration must not exceed 60 minutes', 'tsf'));
        }
    }

    /**
     * Validate release date
     */
    private function validate_release_date($date) {
        $date_obj = DateTime::createFromFormat('Y-m-d', $date);

        if (!$date_obj) {
            $this->add_error('release_date', __('Invalid date format', 'tsf'));
            return;
        }

        // Check if date is not too far in the past (e.g., before 1900)
        $min_date = new DateTime('1900-01-01');
        if ($date_obj < $min_date) {
            $this->add_error('release_date', __('Release date is too far in the past', 'tsf'));
        }

        // Check if date is not too far in the future
        $max_future_years = get_option('tsf_max_future_years', 2);
        $max_date = new DateTime("+{$max_future_years} years");

        if ($date_obj > $max_date) {
            $this->add_error('release_date', sprintf(
                __('Release date cannot be more than %d years in the future', 'tsf'),
                $max_future_years
            ));
        }
    }

    /**
     * Validate URL
     */
    private function validate_url($url, $field) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $field_label = $field === 'track_url' ? __('Track URL', 'tsf') : __('Social media URL', 'tsf');
            $this->add_error($field, sprintf(__('%s is not a valid URL', 'tsf'), $field_label));
            return;
        }

        // Check for valid URL scheme
        $parsed = parse_url($url);
        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
            $this->add_error($field, __('URL must use http or https protocol', 'tsf'));
        }

        // Validate track URL platforms
        if ($field === 'track_url') {
            $valid_domains = ['spotify.com', 'bandcamp.com', 'youtube.com', 'youtu.be', 'soundcloud.com', 'apple.com', 'music.apple.com', 'deezer.com'];
            $host = $parsed['host'] ?? '';
            $host = str_replace('www.', '', $host);

            $is_valid_platform = false;
            foreach ($valid_domains as $domain) {
                if (strpos($host, $domain) !== false) {
                    $is_valid_platform = true;
                    break;
                }
            }

            if (!$is_valid_platform) {
                $this->add_error($field, __('Track URL must be from a supported platform (Spotify, Bandcamp, YouTube, SoundCloud, Apple Music, Deezer)', 'tsf'));
            }
        }
    }

    /**
     * Validate description
     */
    private function validate_description($description) {
        if (mb_strlen($description) < 10) {
            $this->add_error('description', __('Description must be at least 10 characters', 'tsf'));
        }

        if (mb_strlen($description) > 2000) {
            $this->add_error('description', __('Description must not exceed 2000 characters', 'tsf'));
        }

        // Check for spam patterns
        $spam_patterns = [
            '/\b(?:viagra|cialis|casino|lottery|winner)\b/i',
            '/\b(?:click here|buy now)\b/i',
            '/https?:\/\/[^\s]+\s+https?:\/\//i', // Multiple URLs
        ];

        foreach ($spam_patterns as $pattern) {
            if (preg_match($pattern, $description)) {
                $this->add_error('description', __('Description contains prohibited content', 'tsf'));
                break;
            }
        }
    }

    /**
     * Validate country
     */
    private function validate_country($country) {
        if (mb_strlen($country) < 2) {
            $this->add_error('country', __('Country name must be at least 2 characters', 'tsf'));
        }

        if (mb_strlen($country) > 100) {
            $this->add_error('country', __('Country name must not exceed 100 characters', 'tsf'));
        }
    }

    /**
     * Validate genre against allowed options
     */
    private function validate_genre($genre) {
        $allowed_genres = get_option('tsf_genres', []);
        if (!in_array($genre, $allowed_genres, true)) {
            $this->add_error('genre', __('Invalid genre selected', 'tsf'));
        }
    }

    /**
     * Validate platform against allowed options
     */
    private function validate_platform($platform) {
        $allowed_platforms = get_option('tsf_platforms', []);
        if (!in_array($platform, $allowed_platforms, true)) {
            $this->add_error('platform', __('Invalid platform selected', 'tsf'));
        }
    }

    /**
     * Validate type against allowed options
     */
    private function validate_type($type) {
        $allowed_types = get_option('tsf_types', []);
        if (!in_array($type, $allowed_types, true)) {
            $this->add_error('type', __('Invalid type selected', 'tsf'));
        }
    }

    /**
     * Validate label against allowed options
     */
    private function validate_label($label) {
        $allowed_labels = get_option('tsf_labels', []);
        if (!in_array($label, $allowed_labels, true)) {
            $this->add_error('label', __('Invalid label selected', 'tsf'));
        }
    }

    /**
     * Validate instrumental value
     */
    private function validate_instrumental($instrumental) {
        if (!in_array($instrumental, ['Yes', 'No'], true)) {
            $this->add_error('instrumental', __('Invalid instrumental value', 'tsf'));
        }
    }

    /**
     * Add validation error
     */
    private function add_error($field, $message) {
        $this->errors[$field] = $message;
    }

    /**
     * Get all validation errors
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Get first error
     */
    public function get_first_error() {
        return !empty($this->errors) ? reset($this->errors) : '';
    }

    /**
     * Check if has errors
     */
    public function has_errors() {
        return !empty($this->errors);
    }

    /**
     * Sanitize data for logging (remove sensitive info)
     */
    private function sanitize_log_data($data) {
        $sanitized = $data;
        if (isset($sanitized['email'])) {
            $sanitized['email'] = '***@' . substr(strrchr($sanitized['email'], "@"), 1);
        }
        if (isset($sanitized['phone'])) {
            $sanitized['phone'] = '***' . substr($sanitized['phone'], -4);
        }
        return $sanitized;
    }
}
