=== Track Submission Form ===
Version: 3.6.0

== Changelog ==

= 3.6.0 - DROPBOX OAUTH 2.0 =
* Added: OAuth 2.0 refresh token support for Dropbox API
* Added: Automatic token renewal - never expires again!
* Added: New OAuth setup wizard in settings
* Added: App Key and App Secret configuration
* Added: One-click authorization flow
* Added: Disconnect/reconnect Dropbox option
* Improved: Token automatically refreshes before API calls
* Improved: Better error messages for Dropbox connection issues
* Removed: Deprecated 4-hour short-lived token method

= 3.5.2 =
* Fixed: Instrumental field now displays as editable checkbox in admin (was read-only text)

= 3.5.1 =
* Fixed: Instrumental field (Y/N) can now be edited in admin metabox
* Added: Direct admin URL link in notification emails to admins
* Added: Automatic confirmation email sent to artists upon successful submission
* Added: Plugin version number displayed in admin settings page
* Improved: Track URL field is now optional for future releases (>30 days away)
* Fixed: Multi-track listings now display properly in Step 4 recap
* UX: Better messaging for optional vs required fields

= 3.5.0 - SECURITY RELEASE =
**CRITICAL SECURITY FIXES - All users should upgrade immediately**

* SECURITY: Fixed N+1 query problem causing performance degradation (CRITICAL)
* SECURITY: Added proper number validation in QC report to prevent XSS (CRITICAL)
* SECURITY: Added MIME type validation to file uploads (CRITICAL)
* SECURITY: Added file size enforcement (50MB limit)
* SECURITY: Replaced user-controlled filenames with secure random names
* SECURITY: Removed dangerous extract() usage in email templates (HIGH)
* SECURITY: Gated sensitive debug logging behind WP_DEBUG checks (HIGH)
* SECURITY: Strengthened Content Security Policy headers
* SECURITY: Added Permissions-Policy header
* SECURITY: Improved CSP to block unsafe resources
* Performance: Optimized multi-track fetching with single JOIN query instead of N+1 queries
* Performance: Reduced database queries from 151 to 1 for 10-track albums

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
