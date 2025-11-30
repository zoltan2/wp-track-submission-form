<?php
/**
 * Frontend Form Template
 *
 * @package TrackSubmissionForm
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="<?php echo esc_attr($atts['class']); ?>">
    <form id="tsf-submission-form" method="post" novalidate>
        <?php wp_nonce_field('tsf_nonce', 'nonce'); ?>

        <!-- Honeypot -->
        <input type="text" name="tsf_hp" style="display:none;" tabindex="-1" autocomplete="off" />

        <!-- Progress bar will be injected by JS -->

        <div class="tsf-field">
            <label for="artist"><?php _e('Artist Name', 'tsf'); ?> <span class="required">*</span></label>
            <input type="text" id="artist" name="artist" required aria-required="true" />
        </div>

        <div class="tsf-field">
            <label for="track_title"><?php _e('Track Title', 'tsf'); ?> <span class="required">*</span></label>
            <input type="text" id="track_title" name="track_title" required aria-required="true" />
        </div>

        <div class="tsf-field-group">
            <div class="tsf-field">
                <label for="genre"><?php _e('Genre', 'tsf'); ?> <span class="required">*</span></label>
                <select id="genre" name="genre" required aria-required="true">
                    <option value=""><?php _e('Select Genre', 'tsf'); ?></option>
                    <?php foreach ($genres as $genre): ?>
                        <option value="<?php echo esc_attr($genre); ?>"><?php echo esc_html($genre); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="tsf-field">
                <label for="type"><?php _e('Type', 'tsf'); ?> <span class="required">*</span></label>
                <select id="type" name="type" required aria-required="true">
                    <option value=""><?php _e('Select Type', 'tsf'); ?></option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($type); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="tsf-field-group">
            <div class="tsf-field">
                <label for="duration"><?php _e('Duration (mm:ss)', 'tsf'); ?> <span class="required">*</span></label>
                <input type="text" id="duration" name="duration" pattern="[0-9]{1,3}:[0-5][0-9]" placeholder="3:45" required aria-required="true" />
                <span class="tsf-field-hint"><?php _e('Format: minutes:seconds (e.g., 3:45)', 'tsf'); ?></span>
            </div>

            <div class="tsf-field">
                <label for="instrumental"><?php _e('Instrumental', 'tsf'); ?> <span class="required">*</span></label>
                <select id="instrumental" name="instrumental" required aria-required="true">
                    <option value=""><?php _e('Select', 'tsf'); ?></option>
                    <option value="Yes"><?php _e('Yes', 'tsf'); ?></option>
                    <option value="No"><?php _e('No', 'tsf'); ?></option>
                </select>
            </div>
        </div>

        <div class="tsf-field-group">
            <div class="tsf-field">
                <label for="release_date"><?php _e('Release Date', 'tsf'); ?> <span class="required">*</span></label>
                <input type="date" id="release_date" name="release_date" max="<?php echo esc_attr($max_date); ?>" required aria-required="true" />
            </div>

            <div class="tsf-field">
                <label for="label"><?php _e('Label', 'tsf'); ?> <span class="required">*</span></label>
                <select id="label" name="label" required aria-required="true">
                    <option value=""><?php _e('Select Label', 'tsf'); ?></option>
                    <?php foreach ($labels as $label): ?>
                        <option value="<?php echo esc_attr($label); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="tsf-field">
            <label for="email"><?php _e('Email', 'tsf'); ?> <span class="required">*</span></label>
            <input type="email" id="email" name="email" required aria-required="true" />
        </div>

        <div class="tsf-field-group">
            <div class="tsf-field">
                <label for="phone"><?php _e('Phone', 'tsf'); ?></label>
                <input type="tel" id="phone" name="phone" />
            </div>

            <div class="tsf-field">
                <label for="country"><?php _e('Country', 'tsf'); ?> <span class="required">*</span></label>
                <input type="text" id="country" name="country" required aria-required="true" />
            </div>
        </div>

        <div class="tsf-field">
            <label for="platform"><?php _e('Platform', 'tsf'); ?> <span class="required">*</span></label>
            <select id="platform" name="platform" required aria-required="true">
                <option value=""><?php _e('Select Platform', 'tsf'); ?></option>
                <?php foreach ($platforms as $platform): ?>
                    <option value="<?php echo esc_attr($platform); ?>"><?php echo esc_html($platform); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="tsf-field">
            <label for="track_url"><?php _e('Track URL', 'tsf'); ?> <span class="required">*</span></label>
            <input type="url" id="track_url" name="track_url" placeholder="https://" required aria-required="true" />
            <span class="tsf-field-hint"><?php _e('Link to your track on Spotify, Bandcamp, YouTube, etc.', 'tsf'); ?></span>
        </div>

        <div class="tsf-field">
            <label for="social_url"><?php _e('Social Media URL', 'tsf'); ?></label>
            <input type="url" id="social_url" name="social_url" placeholder="https://" />
            <span class="tsf-field-hint"><?php _e('Instagram, Facebook, or your website', 'tsf'); ?></span>
        </div>

        <div class="tsf-field">
            <label for="description"><?php _e('Description', 'tsf'); ?> <span class="required">*</span></label>
            <textarea id="description" name="description" rows="5" required aria-required="true"></textarea>
            <span class="tsf-field-hint"><?php _e('Tell us about your track (minimum 10 characters)', 'tsf'); ?></span>
        </div>

        <div class="tsf-field">
            <label>
                <input type="checkbox" id="optin" name="optin" value="1" />
                <?php _e('I agree to receive promotional emails', 'tsf'); ?>
            </label>
        </div>

        <div class="tsf-field">
            <button type="submit" id="tsf-submit-btn" class="tsf-submit-button">
                <?php _e('Submit Track', 'tsf'); ?>
            </button>
        </div>

        <div id="tsf-message" class="tsf-message" style="display:none;" role="status" aria-live="polite"></div>
    </form>
</div>
