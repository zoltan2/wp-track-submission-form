<?php
/**
 * Exporter Class
 *
 * Handles data export in multiple formats (CSV, JSON, XML)
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_Exporter {

    private $logger;

    public function __construct() {
        $this->logger = TSF_Logger::get_instance();
    }

    /**
     * Export submissions to CSV
     */
    public function export_csv($filters = []) {
        $submissions = $this->get_filtered_submissions($filters);

        if (empty($submissions)) {
            return false;
        }

        $filename = 'tsf_export_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Headers
        $headers = [
            'ID', 'Created At', 'Artist', 'Track Title', 'Genre', 'Duration',
            'Instrumental', 'Release Date', 'Email', 'Phone', 'Platform',
            'Track URL', 'Social URL', 'Type', 'Label', 'Country',
            'Description', 'Optin', 'Status'
        ];
        fputcsv($output, $headers);

        // Data
        foreach ($submissions as $sub) {
            fputcsv($output, [
                $sub['id'],
                $sub['created_at'],
                $sub['artist'],
                $sub['track_title'],
                $sub['genre'],
                $sub['duration'],
                $sub['instrumental'],
                $sub['release_date'],
                $sub['email'],
                $sub['phone'],
                $sub['platform'],
                $sub['track_url'],
                $sub['social_url'],
                $sub['type'],
                $sub['label'],
                $sub['country'],
                $sub['description'],
                $sub['optin'],
                $sub['status']
            ]);
        }

        fclose($output);

        $this->logger->info('CSV export completed', [
            'count' => count($submissions),
            'filters' => $filters
        ]);

        exit;
    }

    /**
     * Export submissions to JSON
     */
    public function export_json($filters = []) {
        $submissions = $this->get_filtered_submissions($filters);

        $filename = 'tsf_export_' . date('Ymd_His') . '.json';

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        echo wp_json_encode([
            'exported_at' => current_time('mysql'),
            'count' => count($submissions),
            'filters' => $filters,
            'submissions' => $submissions
        ], JSON_PRETTY_PRINT);

        $this->logger->info('JSON export completed', [
            'count' => count($submissions)
        ]);

        exit;
    }

    /**
     * Export submissions to XML
     */
    public function export_xml($filters = []) {
        $submissions = $this->get_filtered_submissions($filters);

        $filename = 'tsf_export_' . date('Ymd_His') . '.xml';

        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><submissions></submissions>');
        $xml->addAttribute('exported_at', current_time('mysql'));
        $xml->addAttribute('count', count($submissions));

        foreach ($submissions as $sub) {
            $submission = $xml->addChild('submission');
            foreach ($sub as $key => $value) {
                $submission->addChild($key, htmlspecialchars($value ?? ''));
            }
        }

        echo $xml->asXML();

        $this->logger->info('XML export completed', [
            'count' => count($submissions)
        ]);

        exit;
    }

    /**
     * Get filtered submissions
     */
    private function get_filtered_submissions($filters) {
        $meta_query = ['relation' => 'AND'];

        // Genre filter
        if (!empty($filters['genre'])) {
            $meta_query[] = [
                'key' => 'tsf_genre',
                'value' => sanitize_text_field($filters['genre']),
                'compare' => '='
            ];
        }

        // Type filter
        if (!empty($filters['type'])) {
            $meta_query[] = [
                'key' => 'tsf_type',
                'value' => sanitize_text_field($filters['type']),
                'compare' => '='
            ];
        }

        // Label filter
        if (!empty($filters['label'])) {
            $meta_query[] = [
                'key' => 'tsf_label',
                'value' => sanitize_text_field($filters['label']),
                'compare' => '='
            ];
        }

        // Country filter
        if (!empty($filters['country'])) {
            $meta_query[] = [
                'key' => 'tsf_country',
                'value' => sanitize_text_field($filters['country']),
                'compare' => '='
            ];
        }

        // Date range filter
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $meta_query[] = [
                'key' => 'tsf_created_at',
                'value' => [
                    sanitize_text_field($filters['date_from']) . ' 00:00:00',
                    sanitize_text_field($filters['date_to']) . ' 23:59:59'
                ],
                'compare' => 'BETWEEN',
                'type' => 'DATETIME'
            ];
        }

        $args = [
            'post_type' => 'track_submission',
            'post_status' => !empty($filters['status']) ? $filters['status'] : 'any',
            'posts_per_page' => -1,
            'meta_query' => $meta_query,
            'orderby' => 'meta_value',
            'meta_key' => 'tsf_created_at',
            'order' => 'ASC'
        ];

        $query = new WP_Query($args);
        $submissions = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                $submissions[] = [
                    'id' => $post_id,
                    'created_at' => get_post_meta($post_id, 'tsf_created_at', true),
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
                    'status' => get_post_status($post_id)
                ];
            }
            wp_reset_postdata();
        }

        return $submissions;
    }
}
