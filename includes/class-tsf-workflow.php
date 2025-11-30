<?php
/**
 * Workflow Class
 *
 * Manages submission status workflow (draft, pending, approved, rejected)
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_Workflow {

    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING = 'pending';
    const STATUS_PENDING_REVIEW = 'pending_review';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_ARCHIVED = 'archived';

    private $logger;

    public function __construct() {
        $this->logger = TSF_Logger::get_instance();
        $this->init_hooks();
    }

    private function init_hooks() {
        // Register custom post statuses
        add_action('init', [$this, 'register_custom_statuses']);

        // Add status columns to admin
        add_filter('manage_track_submission_posts_columns', [$this, 'add_status_column']);
        add_action('manage_track_submission_posts_custom_column', [$this, 'render_status_column'], 10, 2);

        // Add bulk actions
        add_filter('bulk_actions-edit-track_submission', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-track_submission', [$this, 'handle_bulk_actions'], 10, 3);

        // Add status filter
        add_action('restrict_manage_posts', [$this, 'add_status_filter']);
        add_filter('parse_query', [$this, 'filter_by_status']);
    }

    /**
     * Register custom post statuses
     */
    public function register_custom_statuses() {
        register_post_status(self::STATUS_PENDING, [
            'label' => _x('Pending', 'post status', 'tsf'),
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

        register_post_status(self::STATUS_PENDING_REVIEW, [
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

        register_post_status(self::STATUS_APPROVED, [
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

        register_post_status(self::STATUS_REJECTED, [
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

        register_post_status(self::STATUS_ARCHIVED, [
            'label' => _x('Archived', 'post status', 'tsf'),
            'public' => false,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop(
                'Archived <span class="count">(%s)</span>',
                'Archived <span class="count">(%s)</span>',
                'tsf'
            ),
        ]);
    }

    /**
     * Get submission status
     */
    public function get_status($post_id) {
        return get_post_status($post_id);
    }

    /**
     * Set submission status
     */
    public function set_status($post_id, $new_status, $note = '') {
        $old_status = $this->get_status($post_id);

        if (!$this->is_valid_status($new_status)) {
            $this->logger->error('Invalid status transition attempted', [
                'post_id' => $post_id,
                'from' => $old_status,
                'to' => $new_status
            ]);
            return false;
        }

        // Update post status
        $result = wp_update_post([
            'ID' => $post_id,
            'post_status' => $new_status
        ]);

        if (is_wp_error($result)) {
            $this->logger->error('Failed to update submission status', [
                'post_id' => $post_id,
                'error' => $result->get_error_message()
            ]);
            return false;
        }

        // Log status change
        $this->log_status_change($post_id, $old_status, $new_status, $note);

        // Trigger notification email
        $this->send_status_notification($post_id, $new_status);

        // Fire action hook for extensibility
        do_action('tsf_status_changed', $post_id, $old_status, $new_status);

        return true;
    }

    /**
     * Log status change
     */
    private function log_status_change($post_id, $old_status, $new_status, $note) {
        $history = get_post_meta($post_id, 'tsf_status_history', true) ?: [];

        $history[] = [
            'from' => $old_status,
            'to' => $new_status,
            'note' => $note,
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql')
        ];

        update_post_meta($post_id, 'tsf_status_history', $history);

        $this->logger->info('Submission status changed', [
            'post_id' => $post_id,
            'from' => $old_status,
            'to' => $new_status,
            'note' => $note
        ]);
    }

    /**
     * Get status history
     */
    public function get_status_history($post_id) {
        return get_post_meta($post_id, 'tsf_status_history', true) ?: [];
    }

    /**
     * Send status notification email
     */
    private function send_status_notification($post_id, $status) {
        // Only send notifications for certain statuses
        if (!in_array($status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            return;
        }

        $email = get_post_meta($post_id, 'tsf_email', true);
        if (!is_email($email)) {
            return;
        }

        $artist = get_post_meta($post_id, 'tsf_artist', true);
        $track_title = get_post_meta($post_id, 'tsf_track_title', true);

        if ($status === self::STATUS_APPROVED) {
            $subject = sprintf(__('Your track "%s" has been approved!', 'tsf'), $track_title);
            $message = sprintf(
                __("Hello %s,\n\nGreat news! Your track \"%s\" has been approved and will be featured.\n\nThank you for your submission!", 'tsf'),
                $artist,
                $track_title
            );
        } else {
            $subject = sprintf(__('Update on your track submission "%s"', 'tsf'), $track_title);
            $message = sprintf(
                __("Hello %s,\n\nThank you for submitting \"%s\". Unfortunately, we are unable to feature it at this time.\n\nFeel free to submit other tracks in the future!", 'tsf'),
                $artist,
                $track_title
            );
        }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        wp_mail($email, $subject, $message, $headers);

        $this->logger->info('Status notification sent', [
            'post_id' => $post_id,
            'status' => $status,
            'email' => $email
        ]);
    }

    /**
     * Check if status is valid
     */
    private function is_valid_status($status) {
        $valid_statuses = [
            'draft',
            'publish',
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_ARCHIVED
        ];
        return in_array($status, $valid_statuses, true);
    }

    /**
     * Get all available statuses
     */
    public function get_statuses() {
        return [
            'draft' => __('Draft', 'tsf'),
            'publish' => __('Published', 'tsf'),
            self::STATUS_PENDING => __('Pending', 'tsf'),
            self::STATUS_PENDING_REVIEW => __('Pending Review', 'tsf'),
            self::STATUS_APPROVED => __('Approved', 'tsf'),
            self::STATUS_REJECTED => __('Rejected', 'tsf'),
            self::STATUS_ARCHIVED => __('Archived', 'tsf'),
        ];
    }

    /**
     * Add status column to admin list
     */
    public function add_status_column($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['submission_status'] = __('Status', 'tsf');
            }
        }
        return $new_columns;
    }

    /**
     * Render status column
     */
    public function render_status_column($column, $post_id) {
        if ($column === 'submission_status') {
            $status = $this->get_status($post_id);
            $statuses = $this->get_statuses();
            $status_label = $statuses[$status] ?? $status;

            $colors = [
                'draft' => '#999',
                'publish' => '#0073aa',
                self::STATUS_PENDING => '#f0b849',
                self::STATUS_PENDING_REVIEW => '#f0b849',
                self::STATUS_APPROVED => '#46b450',
                self::STATUS_REJECTED => '#dc3232',
                self::STATUS_ARCHIVED => '#666',
            ];

            $color = $colors[$status] ?? '#999';

            // Validate color format to prevent XSS
            if (!preg_match('/^#[0-9a-f]{3,6}$/i', $color)) {
                $color = '#999999'; // Safe default
            }

            echo '<span style="display:inline-block;padding:3px 8px;border-radius:3px;background:' . esc_attr($color) . ';color:#fff;font-size:11px;font-weight:600;">';
            echo esc_html($status_label);
            echo '</span>';
        }
    }

    /**
     * Add bulk actions
     */
    public function add_bulk_actions($actions) {
        $actions['tsf_pending_review'] = __('Mark as Pending Review', 'tsf');
        $actions['tsf_approve'] = __('Mark as Approved', 'tsf');
        $actions['tsf_reject'] = __('Mark as Rejected', 'tsf');
        $actions['tsf_pending'] = __('Mark as Pending', 'tsf');
        $actions['tsf_archive'] = __('Archive', 'tsf');
        return $actions;
    }

    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        $status_map = [
            'tsf_pending_review' => self::STATUS_PENDING_REVIEW,
            'tsf_approve' => self::STATUS_APPROVED,
            'tsf_reject' => self::STATUS_REJECTED,
            'tsf_pending' => self::STATUS_PENDING,
            'tsf_archive' => self::STATUS_ARCHIVED,
        ];

        if (!isset($status_map[$action])) {
            return $redirect_to;
        }

        $new_status = $status_map[$action];
        $updated = 0;

        foreach ($post_ids as $post_id) {
            if ($this->set_status($post_id, $new_status, 'Bulk action')) {
                $updated++;
            }
        }

        $redirect_to = add_query_arg('bulk_status_updated', $updated, $redirect_to);

        return $redirect_to;
    }

    /**
     * Add status filter dropdown
     */
    public function add_status_filter($post_type) {
        if ($post_type !== 'track_submission') {
            return;
        }

        $current_status = isset($_GET['submission_status']) ? $_GET['submission_status'] : '';
        $statuses = $this->get_statuses();

        echo '<select name="submission_status">';
        echo '<option value="">' . esc_html__('All Statuses', 'tsf') . '</option>';
        foreach ($statuses as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($current_status, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    /**
     * Filter submissions by status
     */
    public function filter_by_status($query) {
        global $pagenow, $typenow;

        if ($pagenow === 'edit.php' && $typenow === 'track_submission' && isset($_GET['submission_status']) && $_GET['submission_status'] !== '') {
            $query->set('post_status', sanitize_text_field($_GET['submission_status']));
        }
    }

    /**
     * Get submissions count by status
     */
    public function get_count_by_status($status) {
        $counts = wp_count_posts('track_submission');
        return isset($counts->$status) ? $counts->$status : 0;
    }

    /**
     * Get all status counts
     */
    public function get_all_counts() {
        $counts = wp_count_posts('track_submission');
        return [
            'draft' => $counts->draft ?? 0,
            'publish' => $counts->publish ?? 0,
            'pending' => $counts->{self::STATUS_PENDING} ?? 0,
            'approved' => $counts->{self::STATUS_APPROVED} ?? 0,
            'rejected' => $counts->{self::STATUS_REJECTED} ?? 0,
            'archived' => $counts->{self::STATUS_ARCHIVED} ?? 0,
        ];
    }
}
