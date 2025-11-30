<?php
/**
 * Mailer Class
 *
 * Advanced email system with HTML templates, placeholders, and scheduling
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_Mailer {

    private $logger;
    private $template_dir;

    public function __construct() {
        $this->logger = TSF_Logger::get_instance();
        $this->template_dir = TSF_PLUGIN_DIR . 'templates/emails/';
    }

    /**
     * Send new submission notification to admin
     */
    public function send_admin_notification($submission_data, $post_id = null) {
        $to = get_option('tsf_notification_email', get_option('admin_email'));

        if (!is_email($to)) {
            $this->logger->error('Invalid admin notification email', ['email' => $to]);
            return false;
        }

        // VUL-6 FIX: Sanitize email subject to prevent header injection
        // Remove newlines and sanitize user input before using in email subject
        $subject = sprintf(
            __('[New Submission] %s - %s', 'tsf'),
            $this->sanitize_email_header($submission_data['artist']),
            $this->sanitize_email_header($submission_data['track_title'])
        );

        // Add admin URL for quick access to submission
        if ($post_id) {
            $submission_data['admin_url'] = admin_url('post.php?post=' . $post_id . '&action=edit');
            $submission_data['post_id'] = $post_id;
        }

        $template = $this->load_template('admin-notification.php', $submission_data);

        return $this->send_email($to, $subject, $template, [
            'content_type' => 'text/html'
        ]);
    }

    /**
     * Send confirmation to submitter (artist)
     */
    public function send_submission_confirmation($submission_data) {
        $to = $submission_data['email'];

        if (!is_email($to)) {
            return false;
        }

        // VUL-6 FIX: Sanitize email subject
        $subject = sprintf(
            __('Thank you for submitting "%s"', 'tsf'),
            $this->sanitize_email_header($submission_data['track_title'])
        );

        $template = $this->load_template('submission-confirmation.php', $submission_data);

        return $this->send_email($to, $subject, $template, [
            'content_type' => 'text/html'
        ]);
    }

    /**
     * Send artist confirmation email on successful submission
     */
    public function send_artist_confirmation($submission_data, $quality_score = null) {
        $to = $submission_data['email'];

        if (!is_email($to)) {
            return false;
        }

        $artist = $this->sanitize_email_header($submission_data['artist']);
        $track_title = $this->sanitize_email_header($submission_data['track_title']);

        $subject = sprintf(__('Track Submission Confirmed - %s', 'tsf'), $track_title);

        // Build email body
        $body_parts = [
            sprintf(__("Hi %s,", 'tsf'), $artist),
            "",
            __("Thank you for submitting your track!", 'tsf'),
            "",
            __("Track Details:", 'tsf'),
            sprintf(__("- Title: %s", 'tsf'), $track_title),
            sprintf(__("- Genre: %s", 'tsf'), $submission_data['genre']),
            sprintf(__("- Release Date: %s", 'tsf'), $submission_data['release_date']),
        ];

        // Add quality score if available
        if ($quality_score !== null) {
            $body_parts[] = "";
            $body_parts[] = sprintf(__("Quality Score: %d/100", 'tsf'), $quality_score);
        }

        // Add next steps
        $body_parts[] = "";
        $body_parts[] = __("What happens next:", 'tsf');
        $body_parts[] = __("1. Our team will review your submission", 'tsf');
        $body_parts[] = __("2. You'll hear back from us within 5-7 business days", 'tsf');

        // Add Dropbox upload link if configured
        $dropbox_url = get_option('tsf_dropbox_url');
        if ($dropbox_url && get_option('tsf_dropbox_method', 'file_request') === 'file_request') {
            $body_parts[] = "";
            $body_parts[] = sprintf(__("Please upload your MP3 file here: %s", 'tsf'), $dropbox_url);
        }

        $body_parts[] = "";
        $body_parts[] = __("Best regards,", 'tsf');
        $body_parts[] = __("The Team", 'tsf');

        $body = implode("\n", $body_parts);

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        return wp_mail($to, $subject, $body, $headers);
    }

    /**
     * Send status update notification
     */
    public function send_status_update($post_id, $status) {
        $email = get_post_meta($post_id, 'tsf_email', true);

        if (!is_email($email)) {
            return false;
        }

        $data = $this->get_submission_data($post_id);
        $data['status'] = $status;

        $subjects = [
            'approved' => __('Your track has been approved! 🎉', 'tsf'),
            'rejected' => __('Update on your submission', 'tsf'),
            'pending' => __('Your track is under review', 'tsf'),
        ];

        $subject = $subjects[$status] ?? __('Status Update', 'tsf');
        // VUL-6 FIX: Sanitize email subject
        $subject = sprintf($subject . ' - %s', $this->sanitize_email_header($data['track_title']));

        $template = $this->load_template("status-{$status}.php", $data);

        return $this->send_email($email, $subject, $template, [
            'content_type' => 'text/html'
        ]);
    }

    /**
     * Send weekly digest
     */
    public function send_weekly_digest($submissions, $stats) {
        $to = get_option('tsf_notification_email', get_option('admin_email'));

        if (!is_email($to)) {
            return false;
        }

        $subject = sprintf(
            __('Weekly Digest - %d new submissions', 'tsf'),
            count($submissions)
        );

        $data = [
            'submissions' => $submissions,
            'stats' => $stats,
            'period_start' => date('Y-m-d', strtotime('-7 days')),
            'period_end' => date('Y-m-d'),
        ];

        $template = $this->load_template('weekly-digest.php', $data);

        return $this->send_email($to, $subject, $template, [
            'content_type' => 'text/html'
        ]);
    }

    /**
     * Load email template
     */
    private function load_template($template_name, $data = []) {
        $template_path = $this->template_dir . $template_name;

        // If custom template doesn't exist, use default
        if (!file_exists($template_path)) {
            return $this->get_default_template($template_name, $data);
        }

        // SECURITY: Don't use extract() - pass data array explicitly to template
        // Template can access via $template_data array
        $template_data = $data;

        ob_start();
        include $template_path;
        $content = ob_get_clean();

        return $this->wrap_template($content);
    }

    /**
     * Get default template (fallback)
     */
    private function get_default_template($template_name, $data) {
        $content = '';

        switch ($template_name) {
            case 'admin-notification.php':
                $content = $this->get_admin_notification_template($data);
                break;

            case 'submission-confirmation.php':
                $content = $this->get_submission_confirmation_template($data);
                break;

            case 'status-approved.php':
                $content = $this->get_status_approved_template($data);
                break;

            case 'status-rejected.php':
                $content = $this->get_status_rejected_template($data);
                break;

            case 'weekly-digest.php':
                $content = $this->get_weekly_digest_template($data);
                break;

            default:
                $content = '<p>' . __('Email content not available', 'tsf') . '</p>';
        }

        return $this->wrap_template($content);
    }

    /**
     * Wrap content in HTML email template
     */
    private function wrap_template($content) {
        $site_name = get_bloginfo('name');
        $site_url = home_url();

        $html = '<!DOCTYPE html>
<html lang="' . get_locale() . '">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($site_name) . '</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; color: #ffffff; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .footer { background: #f8f8f8; padding: 20px; text-align: center; font-size: 12px; color: #666; }
        .button { display: inline-block; padding: 12px 24px; background: #667eea; color: #ffffff !important; text-decoration: none; border-radius: 4px; margin: 10px 0; }
        .info-box { background: #f8f9fa; border-left: 4px solid #667eea; padding: 15px; margin: 15px 0; }
        .success-box { background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 15px 0; }
        .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; }
        table.details { width: 100%; border-collapse: collapse; margin: 15px 0; }
        table.details td { padding: 10px; border-bottom: 1px solid #eee; }
        table.details td:first-child { font-weight: bold; width: 30%; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>' . esc_html($site_name) . '</h1>
        </div>
        <div class="content">
            ' . $content . '
        </div>
        <div class="footer">
            <p>&copy; ' . date('Y') . ' ' . esc_html($site_name) . '. ' . __('All rights reserved.', 'tsf') . '</p>
            <p><a href="' . esc_url($site_url) . '" style="color: #667eea;">' . esc_html($site_name) . '</a></p>
        </div>
    </div>
</body>
</html>';

        return $html;
    }

    /**
     * Admin notification template
     */
    private function get_admin_notification_template($data) {
        return sprintf(
            '<h2>%s</h2>
            <p>%s</p>
            <table class="details">
                <tr><td>%s</td><td>%s</td></tr>
                <tr><td>%s</td><td>%s</td></tr>
                <tr><td>%s</td><td>%s</td></tr>
                <tr><td>%s</td><td>%s</td></tr>
                <tr><td>%s</td><td>%s</td></tr>
                <tr><td>%s</td><td><a href="%s" style="color: #667eea;">%s</a></td></tr>
                <tr><td>%s</td><td>%s</td></tr>
            </table>
            <a href="%s" class="button">%s</a>',
            __('New Track Submission', 'tsf'),
            __('A new track has been submitted for review:', 'tsf'),
            __('Artist', 'tsf'), esc_html($data['artist']),
            __('Track', 'tsf'), esc_html($data['track_title']),
            __('Genre', 'tsf'), esc_html($data['genre']),
            __('Release Date', 'tsf'), esc_html($data['release_date']),
            __('Email', 'tsf'), esc_html($data['email']),
            __('Track URL', 'tsf'), esc_url($data['track_url']), __('Listen', 'tsf'),
            __('Description', 'tsf'), esc_html($data['description']),
            admin_url('edit.php?post_type=track_submission'),
            __('View All Submissions', 'tsf')
        );
    }

    /**
     * Submission confirmation template
     */
    private function get_submission_confirmation_template($data) {
        $qc_section = '';

        // Add QC report if available
        if (!empty($data['qc_report'])) {
            $qc = is_string($data['qc_report']) ? json_decode($data['qc_report'], true) : $data['qc_report'];
            $score = $qc['quality_score'] ?? 0;

            // Determine box class based on score
            $box_class = 'warning-box';
            if ($score >= 80) {
                $box_class = 'success-box';
            }

            $qc_section = sprintf(
                '<div class="%s">
                    <h3>📊 %s</h3>
                    <p><strong>%s:</strong> %d%%</p>
                    <p><strong>%s:</strong> %s kbps | %s Hz | %s</p>',
                $box_class,
                __('MP3 Quality Report', 'tsf'),
                __('Quality Score', 'tsf'), $score,
                __('Audio Info', 'tsf'),
                $qc['audio']['bitrate_kbps'] ?? 'N/A',
                $qc['audio']['samplerate_hz'] ?? 'N/A',
                ($qc['audio']['channels'] ?? 0) == 2 ? 'Stereo' : 'Mono'
            );

            // Add recommendations if any
            if (!empty($qc['recommendations'])) {
                $qc_section .= sprintf('<h4>💡 %s</h4><ul>', __('How to Improve', 'tsf'));
                foreach ($qc['recommendations'] as $rec) {
                    $qc_section .= '<li>' . esc_html($rec) . '</li>';
                }
                $qc_section .= '</ul>';
            }

            $qc_section .= '</div>';
        }

        return sprintf(
            '<h2>%s</h2>
            <p>%s <strong>%s</strong>,</p>
            <div class="success-box">
                <p>%s <strong>"%s"</strong> %s</p>
            </div>
            %s
            <p>%s</p>
            <div class="info-box">
                <h3>%s</h3>
                <ul>
                    <li>%s</li>
                    <li>%s</li>
                    <li>%s</li>
                </ul>
            </div>
            <p>%s</p>',
            __('Thank You for Your Submission!', 'tsf'),
            __('Hello', 'tsf'), esc_html($data['artist']),
            __('Your track', 'tsf'), esc_html($data['track_title']), __('has been successfully submitted', 'tsf'),
            $qc_section,
            __('Our team will review your submission and get back to you soon.', 'tsf'),
            __('What happens next?', 'tsf'),
            __('Our team reviews your track within 5-7 business days', 'tsf'),
            __('You\'ll receive an email notification once reviewed', 'tsf'),
            __('If approved, your track will be featured on our platform', 'tsf'),
            __('Thank you for being part of our community!', 'tsf')
        );
    }

    /**
     * Status approved template
     */
    private function get_status_approved_template($data) {
        return sprintf(
            '<h2>%s 🎉</h2>
            <p>%s <strong>%s</strong>,</p>
            <div class="success-box">
                <p><strong>%s</strong> %s</p>
            </div>
            <p>%s</p>
            <a href="%s" class="button">%s</a>',
            __('Congratulations!', 'tsf'),
            __('Hello', 'tsf'), esc_html($data['artist']),
            __('Your track "' . esc_html($data['track_title']) . '"', 'tsf'), __('has been approved!', 'tsf'),
            __('Your music will be featured on our platform. Thank you for your contribution!', 'tsf'),
            home_url(),
            __('Visit Our Site', 'tsf')
        );
    }

    /**
     * Status rejected template
     */
    private function get_status_rejected_template($data) {
        return sprintf(
            '<h2>%s</h2>
            <p>%s <strong>%s</strong>,</p>
            <div class="warning-box">
                <p>%s <strong>"%s"</strong> %s</p>
            </div>
            <p>%s</p>
            <p>%s</p>',
            __('Update on Your Submission', 'tsf'),
            __('Hello', 'tsf'), esc_html($data['artist']),
            __('Thank you for submitting', 'tsf'), esc_html($data['track_title']), __('for review', 'tsf'),
            __('Unfortunately, we are unable to feature this track at this time.', 'tsf'),
            __('We encourage you to submit other tracks in the future!', 'tsf')
        );
    }

    /**
     * Weekly digest template
     */
    private function get_weekly_digest_template($data) {
        $submissions_html = '';
        foreach ($data['submissions'] as $submission) {
            $submissions_html .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($submission['artist']),
                esc_html($submission['track_title']),
                esc_html($submission['genre'])
            );
        }

        return sprintf(
            '<h2>%s</h2>
            <p>%s <strong>%s</strong> %s <strong>%s</strong>:</p>
            <table class="details">
                <thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead>
                <tbody>%s</tbody>
            </table>
            <a href="%s" class="button">%s</a>',
            __('Weekly Submissions Report', 'tsf'),
            __('From', 'tsf'), $data['period_start'], __('to', 'tsf'), $data['period_end'],
            __('Artist', 'tsf'), __('Track', 'tsf'), __('Genre', 'tsf'),
            $submissions_html,
            admin_url('edit.php?post_type=track_submission'),
            __('View All Submissions', 'tsf')
        );
    }

    /**
     * Send email
     */
    private function send_email($to, $subject, $message, $args = []) {
        $defaults = [
            'content_type' => 'text/html',
            'from_name' => get_bloginfo('name'),
            'from_email' => get_option('admin_email'),
        ];

        $args = wp_parse_args($args, $defaults);

        // Set content type
        add_filter('wp_mail_content_type', function() use ($args) {
            return $args['content_type'];
        });

        // Set from name and email
        add_filter('wp_mail_from_name', function() use ($args) {
            return $args['from_name'];
        });

        add_filter('wp_mail_from', function() use ($args) {
            return $args['from_email'];
        });

        $headers = [];
        if ($args['content_type'] === 'text/html') {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }

        $result = wp_mail($to, $subject, $message, $headers);

        // Remove filters
        remove_all_filters('wp_mail_content_type');
        remove_all_filters('wp_mail_from_name');
        remove_all_filters('wp_mail_from');

        if (!$result) {
            $this->logger->error('Failed to send email', [
                'to' => $to,
                'subject' => $subject
            ]);
        } else {
            $this->logger->info('Email sent successfully', [
                'to' => $to,
                'subject' => $subject
            ]);
        }

        return $result;
    }

    /**
     * Sanitize email subject/header to prevent header injection
     * Removes newlines, carriage returns, and other control characters
     *
     * @param string $text Text to sanitize
     * @return string Sanitized text
     */
    private function sanitize_email_header($text) {
        // Remove all newlines, carriage returns, and null bytes
        $text = str_replace(["\r", "\n", "\0", "%0a", "%0d"], '', $text);

        // Remove other control characters (ASCII 0-31 except space)
        $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text);

        // Sanitize as text field and trim
        $text = sanitize_text_field($text);

        // Limit length to prevent excessively long subjects
        $text = substr($text, 0, 200);

        return $text;
    }

    /**
     * Get submission data for email
     */
    private function get_submission_data($post_id) {
        return [
            'artist' => get_post_meta($post_id, 'tsf_artist', true),
            'track_title' => get_post_meta($post_id, 'tsf_track_title', true),
            'genre' => get_post_meta($post_id, 'tsf_genre', true),
            'duration' => get_post_meta($post_id, 'tsf_duration', true),
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
        ];
    }
}
