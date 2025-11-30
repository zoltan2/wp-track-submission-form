<?php
/**
 * Upload Instructions Template
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="tsf-upload-instructions">
    <h2><?php _e('Thank you for submitting your track!', 'tsf'); ?></h2>

    <p><?php _e('Your track information has been successfully saved. Now, please upload your audio file.', 'tsf'); ?></p>

    <h3><?php _e('Before You Upload: MP3 Tagging Checklist', 'tsf'); ?></h3>

    <p><?php _e('Clean metadata is essential for your music to be searchable and properly credited. Make sure your MP3 includes:', 'tsf'); ?></p>

    <ul>
        <li><?php _e('🎵 Track Title', 'tsf'); ?></li>
        <li><?php _e('🧑‍🎤 Artist Name', 'tsf'); ?></li>
        <li><?php _e('💿 Album Name', 'tsf'); ?></li>
        <li><?php _e('📅 Year of Release (YYYY format)', 'tsf'); ?></li>
        <li><?php _e('🔢 Track Number', 'tsf'); ?></li>
        <li><?php _e('🎧 Genre', 'tsf'); ?></li>
        <li><?php _e('🖼️ Cover Image (minimum 1000×1000 px)', 'tsf'); ?></li>
        <li><?php _e('🆔 ISRC Code (if available)', 'tsf'); ?></li>
        <li><?php _e('📝 Composer / Songwriter', 'tsf'); ?></li>
        <li><?php _e('⚖️ Copyright Notice', 'tsf'); ?></li>
    </ul>

    <?php if ($dropbox_url): ?>
        <p><a href="<?php echo esc_url($dropbox_url); ?>" class="button button-primary" target="_blank">
            <?php _e('Upload Your Track', 'tsf'); ?>
        </a></p>
    <?php endif; ?>

    <p class="tsf-note"><?php _e('Make sure to tag your file correctly before uploading. This helps us process your submission faster!', 'tsf'); ?></p>
</div>
