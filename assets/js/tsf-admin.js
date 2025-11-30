/**
 * Track Submission Form - Admin JavaScript
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Confirmation for delete actions
        $('[data-tsf-confirm]').on('click', function(e) {
            const message = $(this).data('tsf-confirm');
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });

        // Auto-dismiss success notices
        setTimeout(function() {
            $('.notice.is-dismissible').fadeOut();
        }, 5000);
    });

})(jQuery);
