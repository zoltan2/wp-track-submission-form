<?php
/**
 * REST API Class
 *
 * Provides REST API endpoints for submissions
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_REST_API {

    private $namespace = 'tsf/v1';
    private $submission;
    private $logger;

    public function __construct() {
        $this->logger = TSF_Logger::get_instance();
        $this->submission = new TSF_Submission();
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST routes
     */
    public function register_routes() {
        // GET /tsf/v1/submissions
        register_rest_route($this->namespace, '/submissions', [
            'methods' => 'GET',
            'callback' => [$this, 'get_submissions'],
            'permission_callback' => [$this, 'check_permissions']
        ]);

        // POST /tsf/v1/submissions
        register_rest_route($this->namespace, '/submissions', [
            'methods' => 'POST',
            'callback' => [$this, 'create_submission'],
            'permission_callback' => [$this, 'verify_submission_permission']
        ]);

        // GET /tsf/v1/submissions/{id}
        register_rest_route($this->namespace, '/submissions/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_submission'],
            'permission_callback' => [$this, 'check_permissions']
        ]);

        // PUT /tsf/v1/submissions/{id}
        register_rest_route($this->namespace, '/submissions/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_submission'],
            'permission_callback' => [$this, 'check_edit_permissions']
        ]);

        // DELETE /tsf/v1/submissions/{id}
        register_rest_route($this->namespace, '/submissions/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_submission'],
            'permission_callback' => [$this, 'check_delete_permissions']
        ]);

        // GET /tsf/v1/stats
        register_rest_route($this->namespace, '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_permissions']
        ]);
    }

    /**
     * GET /tsf/v1/submissions
     */
    public function get_submissions($request) {
        $params = $request->get_params();

        $args = [
            'posts_per_page' => isset($params['per_page']) ? absint($params['per_page']) : 20,
            'paged' => isset($params['page']) ? absint($params['page']) : 1,
        ];

        if (isset($params['status'])) {
            $args['post_status'] = sanitize_text_field($params['status']);
        }

        $result = $this->submission->get_submissions($args);

        return new WP_REST_Response($result, 200);
    }

    /**
     * POST /tsf/v1/submissions
     */
    public function create_submission($request) {
        $data = $request->get_json_params();

        $result = $this->submission->create($data);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'error' => $result->get_error_message(),
                'data' => $result->get_error_data()
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'id' => $result,
            'message' => __('Submission created successfully', 'tsf')
        ], 201);
    }

    /**
     * GET /tsf/v1/submissions/{id}
     */
    public function get_submission($request) {
        $id = $request->get_param('id');
        $submission = $this->submission->get($id);

        if (!$submission) {
            return new WP_REST_Response([
                'error' => __('Submission not found', 'tsf')
            ], 404);
        }

        return new WP_REST_Response($submission, 200);
    }

    /**
     * PUT /tsf/v1/submissions/{id}
     */
    public function update_submission($request) {
        $id = $request->get_param('id');
        $data = $request->get_json_params();

        $result = $this->submission->update($id, $data);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'error' => $result->get_error_message()
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Submission updated successfully', 'tsf')
        ], 200);
    }

    /**
     * DELETE /tsf/v1/submissions/{id}
     */
    public function delete_submission($request) {
        $id = $request->get_param('id');

        $result = $this->submission->delete($id, true);

        if (!$result) {
            return new WP_REST_Response([
                'error' => __('Failed to delete submission', 'tsf')
            ], 400);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Submission deleted successfully', 'tsf')
        ], 200);
    }

    /**
     * GET /tsf/v1/stats
     */
    public function get_stats($request) {
        $period = $request->get_param('period') ?? '30days';
        $stats = $this->submission->get_stats($period);

        return new WP_REST_Response($stats, 200);
    }

    /**
     * Verify permission for public submission endpoint
     */
    public function verify_submission_permission($request) {
        // Verify nonce from X-WP-Nonce header
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            $this->logger->log('warning', 'REST API submission failed nonce verification', [
                'ip' => $this->get_client_ip(),
                'endpoint' => '/submissions'
            ]);
            return new WP_Error('rest_forbidden', __('Invalid security token', 'tsf'), ['status' => 403]);
        }

        // Rate limiting per IP
        $ip = $this->get_client_ip();
        $rate_key = 'tsf_rest_rate_' . hash_hmac('sha256', $ip, wp_salt('nonce'));
        $attempts = get_transient($rate_key) ?: 0;

        if ($attempts > 10) {
            $this->logger->log('warning', 'REST API rate limit exceeded', [
                'ip' => $ip,
                'attempts' => $attempts
            ]);
            return new WP_Error('rest_too_many_requests', __('Too many requests. Please wait before submitting again.', 'tsf'), ['status' => 429]);
        }

        set_transient($rate_key, $attempts + 1, 5 * MINUTE_IN_SECONDS);
        return true;
    }

    /**
     * Check read permissions
     * VUL-1 FIX: Enhanced permission consistency and logging
     */
    public function check_permissions($request) {
        // VUL-1 FIX: More consistent capability check with logging
        if (!current_user_can('edit_track_submissions')) {
            $this->logger->log('warning', 'REST API access denied - insufficient permissions', [
                'user_id' => get_current_user_id(),
                'endpoint' => $request->get_route(),
                'ip' => $this->get_client_ip()
            ]);
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to access this resource.', 'tsf'),
                ['status' => 403]
            );
        }

        // For single submission endpoints, verify ownership
        $id = $request->get_param('id');
        if ($id) {
            // VUL-1 FIX: Validate ID is numeric to prevent type juggling
            if (!is_numeric($id) || $id <= 0) {
                $this->logger->log('warning', 'REST API invalid ID parameter', [
                    'user_id' => get_current_user_id(),
                    'id_param' => $id
                ]);
                return new WP_Error('rest_invalid_param', __('Invalid submission ID.', 'tsf'), ['status' => 400]);
            }

            $post = get_post(absint($id));
            if (!$post || $post->post_type !== 'track_submission') {
                $this->logger->log('warning', 'REST API submission not found', [
                    'user_id' => get_current_user_id(),
                    'submission_id' => $id
                ]);
                return new WP_Error('rest_not_found', __('Submission not found.', 'tsf'), ['status' => 404]);
            }

            // Admin can access all, others only their own
            if (!current_user_can('manage_options')) {
                $email = get_post_meta($id, 'tsf_email', true);
                $user = wp_get_current_user();
                if ($email !== $user->user_email) {
                    $this->logger->log('warning', 'Unauthorized access attempt to submission', [
                        'user_id' => get_current_user_id(),
                        'submission_id' => $id,
                        'ip' => $this->get_client_ip()
                    ]);
                    return new WP_Error(
                        'rest_forbidden',
                        __('You do not have permission to access this submission.', 'tsf'),
                        ['status' => 403]
                    );
                }
            }
        }

        return true;
    }

    /**
     * Check edit permissions
     * VUL-1 FIX: Enhanced edit permission consistency
     */
    public function check_edit_permissions($request) {
        if (!current_user_can('edit_track_submissions')) {
            $this->logger->log('warning', 'REST API edit denied - insufficient permissions', [
                'user_id' => get_current_user_id(),
                'endpoint' => $request->get_route()
            ]);
            return new WP_Error('rest_forbidden', __('You do not have permission to edit submissions.', 'tsf'), ['status' => 403]);
        }

        $id = $request->get_param('id');
        if (!$id || !is_numeric($id) || $id <= 0) {
            return new WP_Error('rest_invalid_param', __('Invalid or missing submission ID.', 'tsf'), ['status' => 400]);
        }

        $post = get_post(absint($id));
        if (!$post || $post->post_type !== 'track_submission') {
            return new WP_Error('rest_not_found', __('Submission not found.', 'tsf'), ['status' => 404]);
        }

        // Admin can edit all, others only their own
        if (!current_user_can('manage_options')) {
            $email = get_post_meta($id, 'tsf_email', true);
            $user = wp_get_current_user();
            if ($email !== $user->user_email) {
                $this->logger->log('warning', 'Unauthorized edit attempt', [
                    'user_id' => get_current_user_id(),
                    'submission_id' => $id
                ]);
                return new WP_Error('rest_forbidden', __('You do not have permission to edit this submission.', 'tsf'), ['status' => 403]);
            }
        }

        return true;
    }

    /**
     * Check delete permissions (admin only)
     * VUL-1 FIX: Enhanced delete permission with logging
     */
    public function check_delete_permissions($request) {
        if (!current_user_can('manage_options')) {
            $this->logger->log('warning', 'REST API delete denied - admin only', [
                'user_id' => get_current_user_id(),
                'endpoint' => $request->get_route(),
                'ip' => $this->get_client_ip()
            ]);
            return new WP_Error('rest_forbidden', __('Only administrators can delete submissions.', 'tsf'), ['status' => 403]);
        }
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
}
