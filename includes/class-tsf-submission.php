<?php
/**
 * Submission Class
 *
 * Handles CRUD operations for track submissions
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_Submission {

    private $logger;
    private $validator;
    private $workflow;
    private $mailer;

    public function __construct() {
        $this->logger = TSF_Logger::get_instance();
        $this->validator = new TSF_Validator();
        $this->workflow = new TSF_Workflow();
        $this->mailer = new TSF_Mailer();
    }

    /**
     * Create a new submission
     */
    public function create($data) {
        // Validate data
        if (!$this->validator->validate_submission($data)) {
            return new WP_Error('validation_failed',
                $this->validator->get_first_error(),
                ['errors' => $this->validator->get_errors()]
            );
        }

        // Check if this is a multi-track submission
        $tracks = isset($data['tracks']) && is_array($data['tracks']) ? $data['tracks'] : [];
        $track_count = count($tracks);

        if ($track_count > 1) {
            // Multi-track submission: create release and individual tracks
            return $this->create_multi_track_submission($data, $tracks);
        } else {
            // Single track submission (legacy behavior)
            return $this->create_single_track_submission($data);
        }
    }

    /**
     * Create a single track submission
     */
    private function create_single_track_submission($data) {
        // Create post
        $post_id = wp_insert_post([
            'post_title'   => sprintf('%s - %s', $data['artist'], $data['track_title']),
            'post_type'    => 'track_submission',
            'post_status'  => 'pending_review',
            'meta_input'   => [
                'tsf_artist'       => $data['artist'],
                'tsf_track_title'  => $data['track_title'],
                'tsf_genre'        => $data['genre'],
                'tsf_duration'     => $data['duration'],
                'tsf_instrumental' => $data['instrumental'],
                'tsf_release_date' => $data['release_date'],
                'tsf_email'        => $data['email'],
                'tsf_phone'        => $data['phone'] ?? '',
                'tsf_platform'     => $data['platform'],
                'tsf_track_url'    => $data['track_url'],
                'tsf_social_url'   => $data['social_url'] ?? '',
                'tsf_type'         => $data['type'],
                'tsf_label'        => $data['label'],
                'tsf_country'      => $data['country'],
                'tsf_description'  => $data['description'],
                'tsf_optin'        => $data['optin'] ?? 0,
                'tsf_qc_report'    => !empty($data['qc_report']) ? json_encode($data['qc_report']) : '',
                'tsf_mp3_analysis_skipped' => isset($data['mp3_analysis_skipped']) ? 1 : 0,
                'tsf_created_at'   => current_time('mysql'),
            ],
        ]);

        if (is_wp_error($post_id)) {
            $this->logger->error('Failed to create submission', [
                'error' => $post_id->get_error_message()
            ]);
            return $post_id;
        }

        // Log creation
        $this->logger->info('New submission created', [
            'post_id' => $post_id,
            'artist' => $data['artist'],
            'track' => $data['track_title']
        ]);

        // Send emails
        $this->mailer->send_admin_notification($data);
        $this->mailer->send_submission_confirmation($data);

        // Trigger action
        do_action('tsf_submission_created', $post_id, $data);

        return $post_id;
    }

    /**
     * Create a multi-track submission (album/EP)
     */
    private function create_multi_track_submission($data, $tracks) {
        global $wpdb;

        // Determine release type based on track count
        $track_count = count($tracks);
        $release_type = 'auto';
        if ($track_count === 1) {
            $release_type = 'single';
        } elseif ($track_count >= 2 && $track_count <= 6) {
            $release_type = 'ep';
        } elseif ($track_count >= 7) {
            $release_type = 'album';
        }

        // Use album_title if provided, otherwise use first track title
        $release_title = !empty($data['album_title']) ? $data['album_title'] : $data['track_title'];

        // Create release record
        $releases_table = $wpdb->prefix . 'tsf_releases';
        $inserted = $wpdb->insert(
            $releases_table,
            [
                'title' => $release_title,
                'type' => $release_type,
                'track_count' => $track_count,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );

        if (!$inserted) {
            $this->logger->error('Failed to create release record', [
                'error' => $wpdb->last_error
            ]);
            return new WP_Error('release_creation_failed', __('Failed to create release', 'tsf'));
        }

        $release_id = $wpdb->insert_id;

        // Create individual track posts
        $track_post_ids = [];
        $track_order = 1;

        foreach ($tracks as $track_index => $track_data) {
            // Prepare track data
            $track_title = isset($track_data['title']) ? sanitize_text_field($track_data['title']) : "Track {$track_order}";
            $track_isrc = isset($track_data['isrc']) ? sanitize_text_field($track_data['isrc']) : '';
            $track_instrumental = isset($track_data['instrumental']) ? sanitize_text_field($track_data['instrumental']) : 'No';

            // Create track post
            $track_post_id = wp_insert_post([
                'post_title'   => sprintf('%s - %s (Track %d)', $data['artist'], $track_title, $track_order),
                'post_type'    => 'track_submission',
                'post_status'  => 'pending_review',
                'meta_input'   => [
                    'tsf_artist'       => $data['artist'],
                    'tsf_track_title'  => $track_title,
                    'tsf_genre'        => $data['genre'],
                    'tsf_duration'     => $data['duration'] ?? '',
                    'tsf_instrumental' => $track_instrumental,
                    'tsf_release_date' => $data['release_date'],
                    'tsf_email'        => $data['email'],
                    'tsf_phone'        => $data['phone'] ?? '',
                    'tsf_platform'     => $data['platform'],
                    'tsf_track_url'    => $data['track_url'],
                    'tsf_social_url'   => $data['social_url'] ?? '',
                    'tsf_type'         => $release_type,
                    'tsf_label'        => $data['label'],
                    'tsf_country'      => $data['country'],
                    'tsf_description'  => $data['description'],
                    'tsf_optin'        => $data['optin'] ?? 0,
                    'tsf_isrc'         => $track_isrc,
                    'tsf_mp3_analysis_skipped' => isset($data['mp3_analysis_skipped']) ? 1 : 0,
                    'tsf_release_id'   => $release_id,
                    'tsf_track_order'  => $track_order,
                    'tsf_qc_report'    => !empty($data['qc_report']) ? json_encode($data['qc_report']) : '',
                    'tsf_created_at'   => current_time('mysql'),
                ],
            ]);

            if (is_wp_error($track_post_id)) {
                $this->logger->error('Failed to create track post', [
                    'error' => $track_post_id->get_error_message(),
                    'track_order' => $track_order
                ]);
                continue;
            }

            // Link track to release in junction table
            $junction_table = $wpdb->prefix . 'tsf_release_tracks';
            $wpdb->insert(
                $junction_table,
                [
                    'release_id' => $release_id,
                    'track_post_id' => $track_post_id,
                    'track_order' => $track_order,
                ],
                ['%d', '%d', '%d']
            );

            $track_post_ids[] = $track_post_id;
            $track_order++;
        }

        // Log creation
        $this->logger->info('Multi-track submission created', [
            'release_id' => $release_id,
            'artist' => $data['artist'],
            'release' => $release_title,
            'track_count' => count($track_post_ids)
        ]);

        // Send emails
        $this->mailer->send_admin_notification($data);
        $this->mailer->send_submission_confirmation($data);

        // Trigger action
        do_action('tsf_multi_track_submission_created', $release_id, $track_post_ids, $data);

        // Return the first track post ID for backward compatibility
        return !empty($track_post_ids) ? $track_post_ids[0] : $release_id;
    }

    /**
     * Get submission by ID
     */
    public function get($post_id, $check_permissions = true) {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'track_submission') {
            return null;
        }

        // Permission check to prevent IDOR (Insecure Direct Object Reference)
        if ($check_permissions) {
            if (!current_user_can('edit_track_submissions')) {
                error_log('TSF Security: Unauthorized get() attempt by non-privileged user for submission ' . $post_id);
                return null;
            }

            // Non-admins can only view their own submissions
            if (!current_user_can('manage_options')) {
                $email = get_post_meta($post_id, 'tsf_email', true);
                $user = wp_get_current_user();
                if ($email !== $user->user_email) {
                    error_log('TSF Security: User ' . get_current_user_id() . ' attempted to access submission ' . $post_id . ' without ownership');
                    return null;
                }
            }
        }

        return [
            'id' => $post_id,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'artist' => get_post_meta($post_id, 'tsf_artist', true),
            'track_title' => get_post_meta($post_id, 'tsf_track_title', true),
            'genre' => get_post_meta($post_id, 'tsf_genre', true),
            'duration' => get_post_meta($post_id, 'tsf_duration', true),
            'instrumental' => get_post_meta($post_id, 'tsf_instrumental', true),
            'release_date' => get_post_meta($post_id, 'tsf_release_date', true),
            'email' => get_post_meta($post_id, 'tsf_email', true),
            'phone' => get_post_meta($post_id, 'tsf_phone', true),
            'platform' => get_post_meta($post_id, 'tsf_platform', true),
            'track_url' => get_post_meta($post_id, 'tsf_track_url', true),
            'social_url' => get_post_meta($post_id, 'tsf_social_url', true),
            'type' => get_post_meta($post_id, 'tsf_type', true),
            'label' => get_post_meta($post_id, 'tsf_label', true),
            'country' => get_post_meta($post_id, 'tsf_country', true),
            'description' => get_post_meta($post_id, 'tsf_description', true),
            'optin' => get_post_meta($post_id, 'tsf_optin', true),
            'created_at' => get_post_meta($post_id, 'tsf_created_at', true),
        ];
    }

    /**
     * Update submission
     */
    public function update($post_id, $data) {
        // Validate data
        if (!$this->validator->validate_submission($data)) {
            return new WP_Error('validation_failed',
                $this->validator->get_first_error()
            );
        }

        // Update post
        $result = wp_update_post([
            'ID' => $post_id,
            'post_title' => sprintf('%s - %s', $data['artist'], $data['track_title'])
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        // Update meta
        foreach ($data as $key => $value) {
            update_post_meta($post_id, 'tsf_' . $key, $value);
        }

        $this->logger->info('Submission updated', ['post_id' => $post_id]);

        do_action('tsf_submission_updated', $post_id, $data);

        return true;
    }

    /**
     * Delete submission
     */
    public function delete($post_id, $force = false) {
        $result = wp_delete_post($post_id, $force);

        if ($result) {
            $this->logger->info('Submission deleted', ['post_id' => $post_id]);
            do_action('tsf_submission_deleted', $post_id);
        }

        return $result;
    }

    /**
     * Get submissions with filters
     */
    public function get_submissions($args = []) {
        $defaults = [
            'post_type' => 'track_submission',
            'posts_per_page' => 20,
            'paged' => 1,
            'post_status' => 'any',
        ];

        $query_args = wp_parse_args($args, $defaults);

        $query = new WP_Query($query_args);

        $submissions = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $submissions[] = $this->get(get_the_ID());
            }
            wp_reset_postdata();
        }

        return [
            'submissions' => $submissions,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
        ];
    }

    /**
     * Get statistics
     */
    public function get_stats($period = '30days') {
        global $wpdb;

        $date_query = $this->get_date_query($period);

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'track_submission'
            AND pm.meta_key = 'tsf_created_at'
            AND pm.meta_value >= %s",
            $date_query
        ));

        $by_status = $this->workflow->get_all_counts();

        return [
            'total' => (int) $total,
            'by_status' => $by_status,
            'period' => $period,
        ];
    }

    /**
     * Get date query for period
     */
    private function get_date_query($period) {
        // Whitelist allowed periods to prevent SQL injection
        $allowed_periods = ['7days', '30days', '90days', 'year'];

        // Validate input against whitelist
        if (!in_array($period, $allowed_periods, true)) {
            // Log suspicious activity
            error_log('TSF Security: Invalid period parameter: ' . sanitize_text_field($period));
            $period = '30days'; // Safe default
        }

        switch ($period) {
            case '7days':
                return date('Y-m-d H:i:s', strtotime('-7 days'));
            case '30days':
                return date('Y-m-d H:i:s', strtotime('-30 days'));
            case '90days':
                return date('Y-m-d H:i:s', strtotime('-90 days'));
            case 'year':
                return date('Y-m-d H:i:s', strtotime('-1 year'));
            default:
                return date('Y-m-d H:i:s', strtotime('-30 days'));
        }
    }

    /**
     * Get release by ID with all associated tracks
     */
    public function get_release($release_id) {
        global $wpdb;

        $releases_table = $wpdb->prefix . 'tsf_releases';
        $junction_table = $wpdb->prefix . 'tsf_release_tracks';

        // Get release info
        $release = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$releases_table} WHERE id = %d",
            $release_id
        ), ARRAY_A);

        if (!$release) {
            return null;
        }

        // Get associated track posts
        $track_ids = $wpdb->get_results($wpdb->prepare(
            "SELECT track_post_id, track_order
            FROM {$junction_table}
            WHERE release_id = %d
            ORDER BY track_order ASC",
            $release_id
        ), ARRAY_A);

        $tracks = [];
        foreach ($track_ids as $track_info) {
            $track = $this->get($track_info['track_post_id']);
            if ($track) {
                $track['track_order'] = $track_info['track_order'];
                $tracks[] = $track;
            }
        }

        $release['tracks'] = $tracks;

        return $release;
    }

    /**
     * Get all tracks for a release
     */
    public function get_release_tracks($release_id) {
        global $wpdb;

        $junction_table = $wpdb->prefix . 'tsf_release_tracks';

        $track_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT track_post_id
            FROM {$junction_table}
            WHERE release_id = %d
            ORDER BY track_order ASC",
            $release_id
        ));

        $tracks = [];
        foreach ($track_ids as $track_id) {
            $track = $this->get($track_id);
            if ($track) {
                $tracks[] = $track;
            }
        }

        return $tracks;
    }
}
