<?php
/**
 * TSF Form V2 - Multi-step Form Renderer
 *
 * @package TrackSubmissionForm
 * @since 3.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_Form_V2 {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Register shortcode (same as v1)
        add_shortcode('track_submission_form', [$this, 'render']);

        // Enqueue assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets() {
        global $post;

        // Only load on pages with shortcode
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'track_submission_form')) {
            return;
        }

        // JS - Country select (load first, no dependencies)
        wp_enqueue_script(
            'tsf-country-select',
            TSF_PLUGIN_URL . 'assets/js/tsf-country-select.js',
            [],
            TSF_VERSION,
            true
        );

        // JS - Multi-step form
        // Get cache buster for JS too
        $cache_buster = get_option('tsf_cache_buster', TSF_VERSION);

        wp_enqueue_script(
            'tsf-form-v2',
            TSF_PLUGIN_URL . 'assets/js/tsf-form-v2.js',
            ['tsf-country-select'],
            $cache_buster,
            true
        );

        // Localize script
        wp_localize_script('tsf-form-v2', 'tsfFormData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tsf_form_v2'),
            'rest_url' => rest_url('tsf/v1/'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'step1_title' => __('Find Your Track', 'tsf'),
                'step2_title' => __('Upload Your Track', 'tsf'),
                'step3_title' => __('How Can We Reach You?', 'tsf'),
                'step4_title' => __('Almost Done!', 'tsf'),
                'verifying' => __('Verifying...', 'tsf'),
                'analyzing' => __('Analyzing MP3...', 'tsf'),
                'saving' => __('Saving...', 'tsf'),
                'prev' => __('Previous', 'tsf'),
                'next' => __('Next', 'tsf'),
                'submit' => __('Submit Track', 'tsf'),
                'restore_draft' => __('You have an unsaved submission. Would you like to restore it?', 'tsf'),
            ]
            ,
            // Server-side flags to control MP3 analysis requirement and fallback
            'require_mp3_analysis' => (bool) get_option('tsf_require_mp3_analysis', true),
            'allow_submission_without_mp3' => (bool) get_option('tsf_allow_submission_without_mp3', false),
            // Whether current user can bypass rate limiting (useful for admin QA)
            'is_admin' => current_user_can('tsf_bypass_rate_limit'),
        ]);

        // CSS - Modern design with cache busting
        $cache_buster = get_option('tsf_cache_buster', TSF_VERSION);
        wp_enqueue_style(
            'tsf-form-v2',
            TSF_PLUGIN_URL . 'assets/css/tsf-form-v2.css',
            [],
            $cache_buster
        );
    }

    public function render($atts = []) {
        $atts = shortcode_atts([
            'class' => 'tsf-form-v2'
        ], $atts);

        // Get options
        $genres = get_option('tsf_genres', ['Pop', 'Rock', 'Electronic/Dance', 'Folk', 'Alternative', 'Metal', 'Jazz', 'R&B', 'Hip-Hop/Rap', 'Autre']);
        $platforms = get_option('tsf_platforms', ['Spotify', 'SoundCloud', 'YouTube Music', 'Apple Music', 'Deezer', 'Bandcamp', 'Other']);
        $types = get_option('tsf_types', ['Album', 'EP', 'Single']);
        $labels = get_option('tsf_labels', ['Indie', 'Label']);
        $max_future_years = get_option('tsf_max_future_years', 2);
        $max_date = date('Y-m-d', strtotime("+{$max_future_years} years"));

        ob_start();
        ?>
        <div class="<?php echo esc_attr($atts['class']); ?>" id="tsf-form-wrapper">

            <!-- Progress Bar -->
            <div class="tsf-progress-container" role="progressbar" aria-valuenow="25" aria-valuemin="0" aria-valuemax="100">
                <div class="tsf-progress-steps">
                    <div class="tsf-step active" data-step="1">
                        <div class="tsf-step-number">1</div>
                        <div class="tsf-step-label"><?php _e('Find Track', 'tsf'); ?></div>
                    </div>
                    <div class="tsf-step" data-step="2">
                        <div class="tsf-step-number">2</div>
                        <div class="tsf-step-label"><?php _e('Upload', 'tsf'); ?></div>
                    </div>
                    <div class="tsf-step" data-step="3">
                        <div class="tsf-step-number">3</div>
                        <div class="tsf-step-label"><?php _e('Contact', 'tsf'); ?></div>
                    </div>
                    <div class="tsf-step" data-step="4">
                        <div class="tsf-step-number">4</div>
                        <div class="tsf-step-label"><?php _e('Submit', 'tsf'); ?></div>
                    </div>
                </div>
                <div class="tsf-progress-bar">
                    <div class="tsf-progress-fill" style="width: 25%;"></div>
                </div>
                <div class="tsf-progress-footer">
                    <div class="tsf-progress-text">25% <?php _e('Complete', 'tsf'); ?></div>
                    <div class="tsf-autosave-indicator" id="tsf-autosave-indicator">
                        <span class="tsf-autosave-icon">💾</span>
                        <span class="tsf-autosave-text"><?php _e('Draft saved', 'tsf'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Form -->
            <form id="tsf-multi-step-form" method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('tsf_form_v2', 'tsf_nonce'); ?>

                <!-- Honeypot -->
                <input type="text" name="tsf_hp" style="position:absolute;left:-9999px;" tabindex="-1" autocomplete="off" />

                <!-- Step 1: Find Your Track (Merged: Track URL + Basic Info) -->
                <div class="tsf-form-step active" data-step="1">
                    <h2 class="tsf-step-title"><?php _e('Find Your Track', 'tsf'); ?></h2>
                    <p class="tsf-step-description"><?php _e('Paste your track link from Spotify, SoundCloud, or any streaming platform', 'tsf'); ?></p>

                    <!-- Platform auto-detected from URL -->
                    <input type="hidden" name="platform" id="tsf-platform-hidden" />

                    <!-- Track URL - PRIMARY FIELD -->
                    <div class="tsf-form-row">
                        <div class="tsf-form-col-12">
                            <?php echo $this->render_field([
                                'name' => 'track_url',
                                'label' => __('Track URL', 'tsf'),
                                'type' => 'url',
                                'required' => true,
                                'placeholder' => 'https://open.spotify.com/track/... or https://soundcloud.com/...',
                                'hint' => __('Paste your track link - we\'ll automatically verify it and fill in details', 'tsf')
                            ]); ?>

                            <!-- Platform detection badge -->
                            <div id="tsf-platform-badge" class="tsf-platform-badge" style="display:none;">
                                <span class="tsf-platform-icon"></span>
                                <span class="tsf-platform-name"></span>
                            </div>

                            <button type="button" class="tsf-btn tsf-btn-secondary" id="tsf-verify-track">
                                <span class="tsf-btn-icon">🔍</span>
                                <?php _e('Verify Track', 'tsf'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Track Preview (shown after verification) -->
                    <div id="tsf-track-preview" class="tsf-track-preview" style="display:none;">
                        <h3><?php _e('Track Preview', 'tsf'); ?></h3>
                        <div class="tsf-preview-card">
                            <img src="" alt="" class="tsf-preview-cover" />
                            <div class="tsf-preview-info">
                                <div class="tsf-preview-title"></div>
                                <div class="tsf-preview-artist"></div>
                                <div class="tsf-preview-album"></div>
                            </div>
                            <div class="tsf-preview-status"></div>
                        </div>
                    </div>

                    <!-- Empty State - Before Track Verification -->
                    <div id="tsf-track-empty-state" class="tsf-empty-state">
                        <div class="tsf-empty-state-icon">🎵</div>
                        <h4 class="tsf-empty-state-title"><?php _e('Paste your track link above', 'tsf'); ?></h4>
                        <p class="tsf-empty-state-text"><?php _e('We support Spotify, SoundCloud, Apple Music, YouTube, and more. Once verified, we\'ll automatically detect your track details.', 'tsf'); ?></p>
                        <div class="tsf-empty-state-examples">
                            <strong><?php _e('Examples:', 'tsf'); ?></strong>
                            <code>https://open.spotify.com/track/...</code>
                            <code>https://soundcloud.com/artist/track</code>
                        </div>
                    </div>

                    <div class="tsf-step-divider">
                        <span><?php _e('Additional Details', 'tsf'); ?></span>
                    </div>

                    <!-- Artist Name - Full Width -->
                    <div class="tsf-form-row">
                        <div class="tsf-form-col-12">
                            <?php echo $this->render_field([
                                'name' => 'artist',
                                'label' => __('Artist Name', 'tsf'),
                                'type' => 'text',
                                'required' => true,
                                'placeholder' => __('e.g., Taylor Swift, The Beatles', 'tsf'),
                                'hint' => __('Your official artist name exactly as it appears on streaming platforms', 'tsf'),
                                'autocomplete' => 'name'
                            ]); ?>
                        </div>
                    </div>

                    <!-- Genre -->
                    <div class="tsf-form-row">
                        <div class="tsf-form-col-12">
                            <?php echo $this->render_field([
                                'name' => 'genre',
                                'label' => __('Genre', 'tsf'),
                                'type' => 'select',
                                'options' => $genres,
                                'required' => true
                            ]); ?>
                        </div>
                    </div>

                    <!-- Album/Project Name - Full Width, Optional (moved down after Genre) -->
                    <div class="tsf-form-row">
                        <div class="tsf-form-col-12">
                            <?php echo $this->render_field([
                                'name' => 'album_title',
                                'label' => __('Album / Project Name', 'tsf'),
                                'type' => 'text',
                                'required' => false,
                                'hint' => __('Leave blank to use the first track name as release title', 'tsf'),
                                'placeholder' => __('Optional - e.g., "Summer Vibes EP" or "Midnight Sessions"', 'tsf')
                            ]); ?>
                        </div>
                    </div>

                    <!-- Number of Tracks -->
                    <div class="tsf-form-row">
                        <div class="tsf-form-col-12">
                            <?php echo $this->render_field([
                                'name' => 'track_count',
                                'label' => __('Number of Tracks', 'tsf'),
                                'type' => 'number',
                                'required' => true,
                                'value' => '1',
                                'min' => '1',
                                'max' => '20',
                                'hint' => __('How many tracks are in this release? (1 for single, 2-6 for EP, 7+ for album)', 'tsf')
                            ]); ?>
                        </div>
                    </div>

                    <!-- Duration and instrumental will be auto-detected from MP3 upload -->
                    <input type="hidden" name="duration" id="tsf-duration-hidden" />
                    <input type="hidden" name="instrumental" id="tsf-instrumental-hidden" value="No" />

                    <!-- Release Date - Hybrid Quick Select System -->
                    <div class="tsf-release-date-wrapper">
                        <label class="tsf-label">
                            <?php _e('Release Date', 'tsf'); ?> <span class="tsf-required">*</span>
                        </label>

                        <!-- Release Status Radio Buttons -->
                        <div class="tsf-release-status-group">
                            <label class="tsf-radio-option">
                                <input type="radio" name="release_status" value="already_released" id="tsf-status-already" checked />
                                <span class="tsf-radio-label"><?php _e('Already Released', 'tsf'); ?></span>
                            </label>
                            <label class="tsf-radio-option">
                                <input type="radio" name="release_status" value="releasing_soon" id="tsf-status-soon" />
                                <span class="tsf-radio-label"><?php _e('Releasing Soon', 'tsf'); ?></span>
                            </label>
                            <label class="tsf-radio-option">
                                <input type="radio" name="release_status" value="future" id="tsf-status-future" />
                                <span class="tsf-radio-label"><?php _e('Future', 'tsf'); ?></span>
                            </label>
                        </div>

                        <!-- Quick Select Buttons: Already Released -->
                        <div id="tsf-quick-select-already" class="tsf-quick-select-group" data-status="already_released">
                            <button type="button" class="tsf-quick-btn" data-date-action="today">
                                <?php _e('Today', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn" data-date-action="yesterday">
                                <?php _e('Yesterday', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn" data-date-action="this-week">
                                <?php _e('This Week', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn" data-date-action="this-month">
                                <?php _e('This Month', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn" data-date-action="last-month">
                                <?php _e('Last Month', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn tsf-quick-btn-calendar" data-date-action="pick-date">
                                📅 <?php _e('Pick Date', 'tsf'); ?>
                            </button>
                        </div>

                        <!-- Quick Select Buttons: Releasing Soon -->
                        <div id="tsf-quick-select-soon" class="tsf-quick-select-group" data-status="releasing_soon" style="display:none;">
                            <button type="button" class="tsf-quick-btn" data-date-action="tomorrow">
                                <?php _e('Tomorrow', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn" data-date-action="this-week">
                                <?php _e('This Week', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn" data-date-action="next-week">
                                <?php _e('Next Week', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn" data-date-action="this-month">
                                <?php _e('This Month', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn" data-date-action="next-month">
                                <?php _e('Next Month', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn tsf-quick-btn-calendar" data-date-action="pick-date">
                                📅 <?php _e('Pick Date', 'tsf'); ?>
                            </button>
                        </div>

                        <!-- Quick Select Buttons: Future -->
                        <div id="tsf-quick-select-future" class="tsf-quick-select-group" data-status="future" style="display:none;">
                            <button type="button" class="tsf-quick-btn" data-date-action="3-months">
                                <?php _e('3 Months', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn" data-date-action="6-months">
                                <?php _e('6 Months', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn" data-date-action="1-year">
                                <?php _e('1 Year', 'tsf'); ?>
                            </button>
                            <button type="button" class="tsf-quick-btn tsf-quick-btn-calendar" data-date-action="pick-date">
                                📅 <?php _e('Pick Date', 'tsf'); ?>
                            </button>
                        </div>

                        <!-- Day Picker (for week selections) -->
                        <div id="tsf-day-picker" class="tsf-day-picker" style="display:none;">
                            <div class="tsf-day-picker-header">
                                <span id="tsf-day-picker-title"></span>
                                <button type="button" class="tsf-day-picker-close">×</button>
                            </div>
                            <div id="tsf-day-picker-days" class="tsf-day-picker-days">
                                <!-- Days will be generated by JavaScript -->
                            </div>
                        </div>

                        <!-- Month Calendar (for month selections) -->
                        <div id="tsf-month-calendar" class="tsf-month-calendar" style="display:none;">
                            <div class="tsf-month-calendar-header">
                                <button type="button" class="tsf-month-nav" data-nav="prev">‹</button>
                                <span id="tsf-month-calendar-title"></span>
                                <button type="button" class="tsf-month-nav" data-nav="next">›</button>
                                <button type="button" class="tsf-month-calendar-close">×</button>
                            </div>
                            <div id="tsf-month-calendar-grid" class="tsf-month-calendar-grid">
                                <!-- Calendar will be generated by JavaScript -->
                            </div>
                        </div>

                        <!-- Standard Date Picker Fallback (for "Pick Date" button) -->
                        <div id="tsf-date-picker-fallback" class="tsf-date-picker-fallback" style="display:none;">
                            <input type="date" id="tsf-date-picker-input" max="<?php echo esc_attr($max_date); ?>" />
                            <div class="tsf-date-picker-actions">
                                <button type="button" class="tsf-date-picker-cancel"><?php _e('Cancel', 'tsf'); ?></button>
                                <button type="button" class="tsf-date-picker-confirm"><?php _e('Confirm', 'tsf'); ?></button>
                            </div>
                        </div>

                        <!-- Selected Date Display & Hidden Input -->
                        <div id="tsf-selected-date-display" class="tsf-selected-date-display" style="display:none;">
                            <div class="tsf-selected-date-content">
                                <span class="tsf-selected-date-icon">📅</span>
                                <span id="tsf-selected-date-text"></span>
                                <button type="button" class="tsf-selected-date-change" aria-label="<?php esc_attr_e('Change date', 'tsf'); ?>">
                                    <?php _e('Change', 'tsf'); ?>
                                </button>
                            </div>
                        </div>

                        <input type="hidden" name="release_date" id="tsf-release-date-hidden" required />
                        <input type="hidden" name="release_date_method" id="tsf-release-date-method" />
                    </div>

                    <!-- Type will be auto-determined (Single/EP/Album) based on track count and duration -->
                    <input type="hidden" name="type" id="tsf-type-hidden" value="" />

                    <!-- Track Repeater - Always Visible -->
                    <div id="tsf-tracks-repeater" class="tsf-tracks-repeater">
                        <div class="tsf-tracks-repeater-header">
                            <h4>
                                🎵 <?php _e('Track Details', 'tsf'); ?>
                                <span class="tsf-track-count-badge" id="tsf-track-count">1</span>
                            </h4>
                            <p class="tsf-auto-classification-notice">
                                <?php _e('Add track titles and ISRC codes below. Your release will be automatically categorized (Single, EP, or Album) based on track count.', 'tsf'); ?>
                            </p>
                        </div>

                        <!-- Visual Track Preview Cards -->
                        <div id="tsf-tracks-preview" class="tsf-tracks-preview" style="display:none;">
                            <div class="tsf-tracks-preview-header">
                                <span class="tsf-tracks-preview-label"><?php _e('Track Order', 'tsf'); ?></span>
                                <span class="tsf-tracks-preview-hint"><?php _e('Drag to reorder', 'tsf'); ?></span>
                            </div>
                            <div id="tsf-tracks-preview-list" class="tsf-tracks-preview-list">
                                <!-- Preview cards added dynamically -->
                            </div>
                        </div>

                        <div id="tsf-tracks-container">
                            <!-- Tracks will be added dynamically (starts with 1) -->
                        </div>
                        <button type="button" class="tsf-add-track-btn" id="tsf-add-track-btn">
                            <span>+</span>
                            <?php _e('Add Another Track', 'tsf'); ?>
                        </button>
                        <p class="tsf-track-limit-notice" id="tsf-track-limit-notice" style="display:none;">
                            <?php _e('Maximum 20 tracks allowed', 'tsf'); ?>
                        </p>
                    </div>

                    <!-- Social URL (Optional) -->
                    <div class="tsf-form-row">
                        <div class="tsf-form-col-12">
                            <?php echo $this->render_field([
                                'name' => 'social_url',
                                'label' => __('Instagram, Facebook, or Website', 'tsf'),
                                'type' => 'url',
                                'placeholder' => 'https://instagram.com/yourname',
                                'hint' => __('Your social media profile or website (optional)', 'tsf')
                            ]); ?>
                        </div>
                    </div>
                </div>

                <!-- Step 2: MP3 Upload & Quality (was Step 3) -->
                <div class="tsf-form-step" data-step="2">
                    <h2 class="tsf-step-title"><?php _e('Upload Your Track', 'tsf'); ?></h2>
                    <p class="tsf-step-description"><?php _e('Upload your MP3 file and we\'ll check its quality for you', 'tsf'); ?></p>

                    <div class="tsf-quality-info-box">
                        <h4>💡 <?php _e('Why Check Quality?', 'tsf'); ?></h4>
                        <p><?php _e('We\'ll analyze your MP3\'s metadata (artist, title, artwork) and audio quality (bitrate, sample rate) to ensure it meets professional standards. You\'ll get instant feedback with tips to improve if needed.', 'tsf'); ?></p>
                    </div>

                    <div class="tsf-form-row">
                        <div class="tsf-form-col-12">
                            <div class="tsf-upload-area" id="tsf-mp3-upload-area">
                                <div class="tsf-upload-icon">📁</div>
                                <h3><?php _e('Upload MP3 for Analysis', 'tsf'); ?></h3>
                                <p><?php _e('Drag & drop or click to select', 'tsf'); ?></p>
                                <input type="file" name="tsf_mp3_file" id="tsf-mp3-file" accept=".mp3,audio/mpeg" />
                                <p class="tsf-upload-note"><?php _e('Max 50MB • MP3 format only', 'tsf'); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Quality Score (shown after analysis) -->
                    <div id="tsf-quality-score" class="tsf-quality-score" style="display:none;">
                        <h3><?php _e('Quality Score', 'tsf'); ?></h3>
                        <div class="tsf-score-card">
                            <div class="tsf-score-circle">
                                <svg viewBox="0 0 100 100">
                                    <circle cx="50" cy="50" r="45" class="tsf-score-bg"></circle>
                                    <circle cx="50" cy="50" r="45" class="tsf-score-fill"></circle>
                                </svg>
                                <div class="tsf-score-value">0%</div>
                            </div>
                            <div class="tsf-score-details">
                                <div class="tsf-score-category">
                                    <span class="tsf-score-label"><?php _e('Metadata', 'tsf'); ?></span>
                                    <span class="tsf-score-points">0/40</span>
                                </div>
                                <div class="tsf-score-category">
                                    <span class="tsf-score-label"><?php _e('Audio Quality', 'tsf'); ?></span>
                                    <span class="tsf-score-points">0/30</span>
                                </div>
                                <div class="tsf-score-category">
                                    <span class="tsf-score-label"><?php _e('Professional', 'tsf'); ?></span>
                                    <span class="tsf-score-points">0/30</span>
                                </div>
                            </div>
                        </div>
                        <div class="tsf-score-recommendations"></div>
                    </div>
                </div>

                <!-- Step 3: Contact & Label (was Step 4) -->
                <div class="tsf-form-step" data-step="3">
                    <h2 class="tsf-step-title"><?php _e('How Can We Reach You?', 'tsf'); ?></h2>
                    <p class="tsf-step-description"><?php _e('Share your contact details so we can get back to you', 'tsf'); ?></p>

                    <div class="tsf-form-row">
                        <div class="tsf-form-col-6">
                            <?php echo $this->render_field([
                                'name' => 'email',
                                'label' => __('Email', 'tsf'),
                                'type' => 'email',
                                'required' => true,
                                'autocomplete' => 'email'
                            ]); ?>
                        </div>
                        <div class="tsf-form-col-6">
                            <?php echo $this->render_field([
                                'name' => 'phone',
                                'label' => __('Phone', 'tsf'),
                                'type' => 'tel',
                                'placeholder' => '+32 xxx xx xx xx',
                                'autocomplete' => 'tel'
                            ]); ?>
                        </div>
                    </div>

                    <div class="tsf-form-row">
                        <div class="tsf-form-col-6">
                            <?php echo $this->render_field([
                                'name' => 'label',
                                'label' => __('Label', 'tsf'),
                                'type' => 'select',
                                'options' => $labels,
                                'required' => true
                            ]); ?>
                        </div>
                        <div class="tsf-form-col-6">
                            <?php echo $this->render_country_field(); ?>
                        </div>
                    </div>

                    <!-- Label Manager Section (shown when label = "Label") -->
                    <div id="tsf-label-manager-section" class="tsf-label-manager-section" style="display:none;">
                        <div class="tsf-label-manager-header">
                            <h4>🏢 <?php _e('Label Manager Contact', 'tsf'); ?></h4>
                        </div>
                        <div class="tsf-label-manager-fields">
                            <?php echo $this->render_field([
                                'name' => 'label_manager_name',
                                'label' => __('Manager Full Name', 'tsf'),
                                'type' => 'text',
                                'autocomplete' => 'name'
                            ]); ?>

                            <?php echo $this->render_field([
                                'name' => 'label_manager_email',
                                'label' => __('Manager Email', 'tsf'),
                                'type' => 'email',
                                'autocomplete' => 'email'
                            ]); ?>

                            <?php echo $this->render_field([
                                'name' => 'label_manager_phone',
                                'label' => __('Manager Phone', 'tsf'),
                                'type' => 'tel',
                                'placeholder' => '+32 xxx xx xx xx',
                                'autocomplete' => 'tel'
                            ]); ?>

                            <?php echo $this->render_field([
                                'name' => 'label_website',
                                'label' => __('Label Website', 'tsf'),
                                'type' => 'url',
                                'placeholder' => 'https://...'
                            ]); ?>

                            <?php echo $this->render_field([
                                'name' => 'label_vat',
                                'label' => __('VAT Number', 'tsf'),
                                'type' => 'text',
                                'placeholder' => 'BE0123456789',
                                'hint' => __('Optional - for invoicing purposes', 'tsf')
                            ]); ?>
                        </div>

                        <!-- Manager Newsletter Opt-in -->
                        <div class="tsf-label-manager-optin" style="margin-top: 1.5rem; padding: 1.25rem; background: white; border-radius: 8px;">
                            <h5><?php _e('Newsletter Subscription (Manager)', 'tsf'); ?></h5>
                            <label class="tsf-label-optin-label">
                                <input
                                    type="checkbox"
                                    name="label_manager_optin"
                                    value="1"
                                    id="tsf-label-manager-optin"
                                />
                                <span class="tsf-label-optin-text">
                                    <strong><?php _e('I agree to receive the newsletter and occasional promotional emails.', 'tsf'); ?></strong>
                                    <br>
                                    <small><?php _e('You can unsubscribe anytime.', 'tsf'); ?></small>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Review & Submit (was Step 5) -->
                <div class="tsf-form-step" data-step="4">
                    <h2 class="tsf-step-title"><?php _e('Almost Done!', 'tsf'); ?></h2>
                    <p class="tsf-step-description"><?php _e('One last thing - tell us about your track and review your submission', 'tsf'); ?></p>

                    <!-- Newsletter Opt-in (Prominent GDPR-compliant card) -->
                    <div class="tsf-newsletter-optin-card">
                        <div class="tsf-optin-header">
                            <span class="tsf-optin-icon">📬</span>
                            <h4><?php _e('Stay Connected', 'tsf'); ?></h4>
                        </div>
                        <div class="tsf-optin-content">
                            <label class="tsf-optin-label">
                                <input
                                    type="checkbox"
                                    name="optin"
                                    value="1"
                                    id="tsf-newsletter-optin"
                                    aria-describedby="tsf-optin-description"
                                />
                                <span class="tsf-optin-text" id="tsf-optin-description">
                                    <strong><?php _e('Yes, I want to receive the newsletter and occasional promotional emails about new music opportunities.', 'tsf'); ?></strong>
                                    <small><?php _e('You can unsubscribe anytime. We respect your privacy.', 'tsf'); ?></small>
                                </span>
                            </label>
                        </div>
                        <p class="tsf-optin-privacy">
                            <a href="<?php echo esc_url(get_privacy_policy_url() ?: '/privacy-policy'); ?>" target="_blank"><?php _e('Privacy Policy', 'tsf'); ?></a> •
                            <?php _e('We\'ll never share your email with third parties', 'tsf'); ?>
                        </p>
                    </div>

                    <div class="tsf-form-row">
                        <div class="tsf-form-col-12">
                            <?php echo $this->render_field([
                                'name' => 'description',
                                'label' => __('Tell us about your track', 'tsf'),
                                'type' => 'textarea',
                                'required' => true,
                                'rows' => 5,
                                'placeholder' => __('Share the story behind your music, your inspiration, or anything you\'d like us to know...', 'tsf'),
                                'hint' => __('Please provide a short description — this field is required to complete your submission', 'tsf')
                            ]); ?>
                        </div>
                    </div>

                    <!-- Summary Card -->
                    <div id="tsf-summary-card" class="tsf-summary-card">
                        <h3><?php _e('Submission Summary', 'tsf'); ?></h3>
                        <div class="tsf-summary-content"></div>
                    </div>
                </div>

                <!-- Navigation Buttons -->
                <div class="tsf-form-navigation">
                    <button type="button" class="tsf-btn tsf-btn-secondary" id="tsf-prev-btn" style="display:none;">
                        ← <?php _e('Previous', 'tsf'); ?>
                    </button>
                    <button type="button" class="tsf-btn tsf-btn-primary" id="tsf-next-btn">
                        <?php _e('Next', 'tsf'); ?> →
                    </button>
                    <button type="button" class="tsf-btn tsf-btn-success" id="tsf-submit-btn" style="display:none;">
                        <?php _e('Submit Track', 'tsf'); ?> ✓
                    </button>
                </div>

                <!-- Messages -->
                <div id="tsf-form-message" class="tsf-form-message" role="alert" aria-live="polite"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a form field
     */
    private function render_field($args) {
        $defaults = [
            'name' => '',
            'label' => '',
            'type' => 'text',
            'value' => '',
            'placeholder' => '',
            'required' => false,
            'hint' => '',
            'options' => [],
            'rows' => 4,
            'pattern' => '',
            'min' => '',
            'max' => '',
            'autocomplete' => ''
        ];

        $args = wp_parse_args($args, $defaults);
        extract($args);

        $required_attr = $required ? 'required' : '';
        $required_label = $required ? ' <span class="tsf-required">*</span>' : '';

        ob_start();
        ?>
        <div class="tsf-field-wrapper" data-field="<?php echo esc_attr($name); ?>">
            <label for="tsf-<?php echo esc_attr($name); ?>" class="tsf-label">
                <?php echo esc_html($label); ?><?php echo $required_label; ?>
            </label>

            <?php if ($type === 'select'): ?>
                <select
                    id="tsf-<?php echo esc_attr($name); ?>"
                    name="<?php echo esc_attr($name); ?>"
                    class="tsf-input tsf-select"
                    <?php echo $required_attr; ?>
                >
                    <option value=""><?php _e('Select...', 'tsf'); ?></option>
                    <?php foreach ($options as $opt_value => $opt_label): ?>
                        <?php if (is_numeric($opt_value)): $opt_value = $opt_label; endif; ?>
                        <option value="<?php echo esc_attr($opt_value); ?>">
                            <?php echo esc_html($opt_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ($type === 'textarea'): ?>
                <textarea
                    id="tsf-<?php echo esc_attr($name); ?>"
                    name="<?php echo esc_attr($name); ?>"
                    class="tsf-input tsf-textarea"
                    rows="<?php echo esc_attr($rows); ?>"
                    placeholder="<?php echo esc_attr($placeholder); ?>"
                    <?php echo $required_attr; ?>
                ><?php echo esc_textarea($value); ?></textarea>

            <?php else: ?>
                <input
                    type="<?php echo esc_attr($type); ?>"
                    id="tsf-<?php echo esc_attr($name); ?>"
                    name="<?php echo esc_attr($name); ?>"
                    class="tsf-input"
                    value="<?php echo esc_attr($value); ?>"
                    placeholder="<?php echo esc_attr($placeholder); ?>"
                    <?php if ($pattern): ?>pattern="<?php echo esc_attr($pattern); ?>"<?php endif; ?>
                    <?php if ($min): ?>min="<?php echo esc_attr($min); ?>"<?php endif; ?>
                    <?php if ($max): ?>max="<?php echo esc_attr($max); ?>"<?php endif; ?>
                    <?php if ($autocomplete): ?>autocomplete="<?php echo esc_attr($autocomplete); ?>"<?php endif; ?>
                    <?php echo $required_attr; ?>
                />
            <?php endif; ?>

            <div class="tsf-validation-feedback"></div>

            <?php if ($hint): ?>
                <div class="tsf-field-hint"><?php echo esc_html($hint); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render searchable country dropdown
     */
    private function render_country_field() {
        ob_start();
        ?>
        <div class="tsf-field-wrapper" data-field="country">
            <label for="tsf-country-search" class="tsf-label">
                <?php _e('Country', 'tsf'); ?> <span class="tsf-required">*</span>
            </label>

            <div class="tsf-country-select-wrapper">
                <input
                    type="text"
                    id="tsf-country-search"
                    class="tsf-country-search"
                    placeholder="<?php esc_attr_e('🔍 Search your country...', 'tsf'); ?>"
                    autocomplete="off"
                    aria-label="<?php esc_attr_e('Search countries', 'tsf'); ?>"
                    aria-autocomplete="list"
                    aria-controls="tsf-country-dropdown"
                />

                <input
                    type="hidden"
                    name="country"
                    id="tsf-country-value"
                    class="tsf-country-value"
                    required
                />

                <div class="tsf-country-dropdown" id="tsf-country-dropdown" role="listbox">
                    <!-- Populated by JavaScript -->
                </div>

                <div class="tsf-country-selected" id="tsf-country-selected">
                    <span class="tsf-country-flag"></span>
                    <span class="tsf-country-name"></span>
                    <button type="button" class="tsf-country-clear" aria-label="<?php esc_attr_e('Clear selection', 'tsf'); ?>">×</button>
                </div>
            </div>

            <div class="tsf-validation-feedback"></div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize
TSF_Form_V2::get_instance();
