<?php
/**
 * Dashboard Class
 *
 * Handles analytics dashboard and statistics
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_Dashboard {

    private $workflow;

    public function __construct() {
        $this->workflow = new TSF_Workflow();
    }

    /**
     * Get dashboard statistics
     */
    public function get_stats($period = '30days') {
        global $wpdb;

        $date_from = $this->get_period_start($period);

        // Total submissions - Use post_date instead of tsf_created_at
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'track_submission'
            AND post_date >= %s",
            $date_from
        ));

        // Status counts
        $status_counts = $this->workflow->get_all_counts();

        // Top genres
        $top_genres = $this->get_top_genres(10);

        // Top countries
        $top_countries = $this->get_top_countries(10);

        // Submissions over time
        $timeline = $this->get_timeline_data($period);

        return [
            'total' => (int) $total,
            'status_counts' => $status_counts,
            'top_genres' => $top_genres,
            'top_countries' => $top_countries,
            'timeline' => $timeline,
            'period' => $period
        ];
    }

    /**
     * Get top genres
     */
    private function get_top_genres($limit = 10) {
        global $wpdb;

        // VUL-7 FIX: Add additional security layer for GROUP BY query
        // While $wpdb->prepare() handles the LIMIT, we need to validate meta_value isn't manipulated
        // by ensuring only valid/expected genre values are returned
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value as genre, COUNT(*) as count
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'tsf_genre'
            AND meta_value != ''
            GROUP BY meta_value
            ORDER BY count DESC
            LIMIT %d",
            $limit
        ));

        // Additional sanitization: filter results to only include expected genres
        $valid_genres = get_option('tsf_genres', [
            'Pop', 'Rock', 'Electronic/Dance', 'Folk', 'Alternative',
            'Metal', 'Jazz', 'R&B', 'Hip-Hop/Rap', 'Autre', 'Other'
        ]);

        // Create case-insensitive whitelist
        $valid_genres_lower = array_map('strtolower', $valid_genres);

        // Filter results to only include whitelisted genres
        $results = array_filter($results, function($item) use ($valid_genres_lower) {
            return in_array(strtolower($item->genre), $valid_genres_lower, true);
        });

        return array_values($results); // Re-index array
    }

    /**
     * Get top countries
     */
    private function get_top_countries($limit = 10) {
        global $wpdb;

        // VUL-7 FIX: Add security validation for GROUP BY query
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value as country, COUNT(*) as count
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'tsf_country'
            AND meta_value != ''
            GROUP BY meta_value
            ORDER BY count DESC
            LIMIT %d",
            $limit
        ));

        // Sanitize results - ensure only valid country codes/names
        // Countries should be 2-letter ISO codes or recognized country names
        $results = array_filter($results, function($item) {
            $country = $item->country;
            // Allow 2-letter ISO codes (uppercase), or reasonable country names (alphanumeric + spaces/dashes)
            return preg_match('/^[A-Z]{2}$/', $country) || preg_match('/^[\p{L}\s\-]{2,50}$/u', $country);
        });

        return array_values($results); // Re-index array
    }

    /**
     * Get timeline data (submissions per day)
     */
    private function get_timeline_data($period) {
        global $wpdb;

        $date_from = $this->get_period_start($period);

        // Use post_date instead of tsf_created_at
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(post_date) as date, COUNT(*) as count
            FROM {$wpdb->posts}
            WHERE post_type = 'track_submission'
            AND post_date >= %s
            GROUP BY DATE(post_date)
            ORDER BY date ASC",
            $date_from
        ));

        return $results;
    }

    /**
     * Get period start date
     */
    private function get_period_start($period) {
        switch ($period) {
            case '7days':
                return date('Y-m-d H:i:s', strtotime('-7 days'));
            case '30days':
                return date('Y-m-d H:i:s', strtotime('-30 days'));
            case '90days':
                return date('Y-m-d H:i:s', strtotime('-90 days'));
            case '1year':
                return date('Y-m-d H:i:s', strtotime('-1 year'));
            case 'all':
                return date('Y-m-d H:i:s', strtotime('-10 years')); // Far enough back to get all
            default:
                return date('Y-m-d H:i:s', strtotime('-30 days'));
        }
    }

    /**
     * Get approval rate
     */
    public function get_approval_rate() {
        $counts = $this->workflow->get_all_counts();

        $total_reviewed = $counts['approved'] + $counts['rejected'];

        if ($total_reviewed === 0) {
            return 0;
        }

        return round(($counts['approved'] / $total_reviewed) * 100, 2);
    }
}
