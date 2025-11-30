=== Track Submission Form ===
Version: 3.4.0

== Changelog ==

= 3.4.0 =
* Feature: Dropbox API integration for automatic MP3 uploads
* Added: Admin setting to choose between File Request (manual) or API (automatic) upload methods
* Added: Dropbox API Access Token field in settings
* Added: Configurable destination folder path for API uploads
* Added: Automatic upload to Dropbox using official API endpoints
* Added: Better error handling with Dropbox API error messages
* Added: Stores Dropbox path in post meta for uploaded files
* Fixed: PHP 7.4 compatibility (replaced str_starts_with with substr)

= 3.3.23 =
* Added: Enhanced debug logging for Dropbox upload failures (captures response status and body)
* Debug: Help diagnose why Dropbox File Request uploads return 500 error

= 3.3.22 =
* Fixed: MP3 quality score now displays correctly in admin (API was returning 'score' instead of 'quality_score')
* Fixed: API now returns all score breakdown fields (total_score, metadata_score, audio_score, professional_score)
* Fixed: PHP 8.x deprecation warning in MP3 duration formatting (implicit float to int conversion)

= 3.3.21 =
* Added: Comprehensive debug logging for QC report saving
* Added: Debug log to verify post meta saved correctly
* Added: QC report data structure logging
* Debug: Help diagnose why MP3 quality score not showing in admin
* Debug: Help diagnose Dropbox upload issues

= 3.3.20 =
* Fixed: Track title in Step 4 recap

== How to Check Debug Logs ==

1. Enable debug logging in wp-config.php:
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);

2. Check logs at: /wp-content/debug.log

3. Look for lines starting with "TSF DEBUG -"
