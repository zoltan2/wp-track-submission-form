# Track Submission Form - WordPress Plugin

![Version](https://img.shields.io/badge/version-3.5.2-blue.svg)
![Security](https://img.shields.io/badge/security-hardened-brightgreen.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL%20v2-green.svg)
![Status](https://img.shields.io/badge/status-production--ready-brightgreen.svg)

A professional WordPress plugin for artists to submit track metadata with automatic MP3 quality analysis and Dropbox integration.

## Features

### 🎵 Multi-Step Submission Form
- Artist and track metadata collection
- Country selection with flag display
- Genre, platform, and label customization
- Release date validation
- Track listing with dynamic fields

### 🔍 MP3 Quality Analysis
- Automatic MP3 file analysis using getID3 library
- Quality scoring based on:
  - Metadata completeness (ID3 tags, artwork, ISRC)
  - Audio quality (bitrate, sample rate, channels)
  - Professional standards (CBR vs VBR, optimal settings)
- Real-time quality score display (0-100)
- Detailed recommendations for improvements

### ☁️ Dropbox Integration
- **Dual upload modes**:
  - File Request (manual): Redirect users to Dropbox File Request page
  - Dropbox API (automatic): Automatic upload via Dropbox API
- Configurable destination folder
- MP3 backup retention in WordPress
- Upload status tracking

### 🔒 Security Features
- REST API protection with nonce validation
- SQL injection prevention
- XSS prevention
- IDOR protection
- MP3 magic byte validation
- Email header injection protection
- Path traversal protection
- SSL verification for external requests
- Rate limiting
- Automatic log purging

### 📊 Admin Features
- Custom post type for submissions
- Detailed submission metaboxes showing:
  - Artist and track information
  - MP3 quality score and analysis
  - Dropbox upload status
  - Submission metadata
- Export submissions to CSV
- Dashboard widget for blocked IPs
- Comprehensive logging system
- Weekly email reports

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher
- PHP extensions: `fileinfo`, `mbstring`, `curl`

## Installation

1. Download the latest release ZIP file
2. Go to WordPress Admin → Plugins → Add New
3. Click "Upload Plugin" and select the ZIP file
4. Click "Install Now" and then "Activate"
5. Go to Track Submissions → Settings to configure

## Configuration

### Basic Settings

Go to **Track Submissions → Settings** in WordPress admin.

#### Dropbox Configuration

**Option 1: File Request (Manual Upload)**
1. Set **Dropbox Upload Method** to "File Request"
2. Enter your Dropbox File Request URL
3. Artists will be redirected to upload MP3 manually after form submission

**Option 2: Dropbox API (Automatic Upload)**

1. Create a Dropbox App:
   - Go to https://www.dropbox.com/developers/apps/create
   - Choose **Scoped access**
   - Choose **App folder** or **Full Dropbox**
   - Name your app (e.g., "Track Submission Form")
   - Click "Create App"

2. Configure permissions:
   - Go to **Permissions** tab
   - Enable `files.content.write`
   - Click "Submit"

3. Generate access token:
   - Go to **Settings** tab
   - Click "Generate" under "Generated access token"
   - Copy the token (starts with `sl.`)

4. Configure in WordPress:
   - Set **Dropbox Upload Method** to "Dropbox API"
   - Paste your **API Access Token**
   - Set **Destination Folder** (e.g., `/Track Submissions`)
   - Save settings

#### Other Settings

- **Notification Email**: Receive alerts for new submissions
- **Weekly Report**: Configure day and time for summary reports
- **File Retention**: Set how long to keep MP3 files (default: 90 days)
- **Genres/Platforms/Labels**: Customize dropdown options

### Form Integration

Add the form to any page using the shortcode:

```
[track_submission_form]
```

Or use the block editor to insert the Track Submission Form block.

## Usage

### For Artists (Frontend)

1. Navigate to the page with the form
2. Fill out the multi-step form:
   - **Step 1**: Artist information and track details
   - **Step 2**: Upload MP3 file for analysis
   - **Step 3**: Review quality score and recommendations
   - **Step 4**: Review and submit
3. After submission:
   - See confirmation message
   - If using File Request: Redirected to Dropbox to upload MP3
   - If using API: MP3 automatically uploaded to Dropbox

### For Admins (Backend)

1. View submissions at **Track Submissions** in admin menu
2. Click any submission to see:
   - Artist and track information
   - MP3 quality analysis and score
   - Dropbox upload status
   - Full submission metadata
3. Export submissions to CSV from the submissions list
4. Configure settings at **Track Submissions → Settings**

## MP3 Quality Scoring

The plugin analyzes uploaded MP3 files and assigns a quality score (0-100) based on:

### Metadata Score (30 points)
- ID3 title tag: 5 points
- ID3 artist tag: 5 points
- ID3 album tag: 5 points
- ID3 year tag: 5 points
- Artwork embedded: 5 points
- ISRC code: 5 points

### Audio Quality Score (30 points)
- Bitrate 320 kbps: 15 points
- Bitrate 256-319 kbps: 10 points
- Bitrate 192-255 kbps: 5 points
- Sample rate 44.1/48 kHz: 10 points
- Stereo channels: 5 points

### Professional Score (30 points)
- CBR (Constant Bitrate): 15 points
- No clipping detected: 10 points
- Optimal duration (>30s, <15min): 5 points

### Total Score Interpretation
- **90-100**: Excellent - Professional quality
- **75-89**: Good - Minor improvements possible
- **60-74**: Fair - Several improvements recommended
- **Below 60**: Poor - Significant improvements needed

## Development

### File Structure

```
track-submission-form/
├── assets/
│   ├── css/              # Stylesheets
│   ├── js/               # JavaScript files
│   └── tsf-validation.js # Form validation
├── includes/
│   ├── class-tsf-admin.php        # Admin interface
│   ├── class-tsf-api-handler.php  # REST API endpoints
│   ├── class-tsf-core.php         # Core functionality
│   ├── class-tsf-dashboard.php    # Dashboard widgets
│   ├── class-tsf-exporter.php     # CSV export
│   ├── class-tsf-form-v2.php      # Form handler
│   ├── class-tsf-logger.php       # Logging system
│   ├── class-tsf-mailer.php       # Email notifications
│   ├── class-tsf-mp3-analyzer.php # MP3 analysis
│   ├── class-tsf-rest-api.php     # REST API
│   ├── class-tsf-submission.php   # Submission handler
│   ├── class-tsf-updater.php      # Plugin updates
│   ├── class-tsf-validator.php    # Input validation
│   └── class-tsf-workflow.php     # Workflow management
├── lib/
│   └── getid3/           # getID3 library for MP3 analysis
├── templates/
│   ├── admin/            # Admin templates
│   ├── emails/           # Email templates
│   └── form.php          # Form template
├── README.txt            # WordPress.org readme
└── track-submission-form.php  # Main plugin file
```

### Hooks and Filters

#### Actions
- `tsf_before_form_render` - Before form renders
- `tsf_after_form_render` - After form renders
- `tsf_before_submission` - Before processing submission
- `tsf_after_submission` - After successful submission
- `tsf_mp3_uploaded` - After MP3 uploaded to Dropbox

#### Filters
- `tsf_form_fields` - Modify form fields
- `tsf_quality_score` - Modify quality score calculation
- `tsf_genres` - Customize genre list
- `tsf_platforms` - Customize platform list
- `tsf_email_content` - Customize notification email

## Changelog

### 3.4.0 (2025-11-30)
- Feature: Dropbox API integration for automatic MP3 uploads
- Added: Admin setting to choose between File Request or API upload
- Added: Dropbox API Access Token configuration
- Added: Configurable destination folder for API uploads
- Added: Better error handling with detailed API responses
- Fixed: PHP 7.4 compatibility

### 3.3.22 (2025-11-30)
- Fixed: MP3 quality score display in admin
- Fixed: API response field naming (quality_score)
- Fixed: PHP 8.x deprecation warning in duration formatting

### 3.3.20 (2025-11-30)
- Fixed: Track title display in Step 4 recap

## Support

For issues, questions, or feature requests, please open an issue on GitHub.

## License

GPL v2 or later

## Credits

- Developed by Zoltan Janosi
- Uses [getID3](https://www.getid3.org/) library for MP3 analysis
- Integrates with [Dropbox API](https://www.dropbox.com/developers)

---

**Version**: 3.4.0
**Requires WordPress**: 5.0+
**Requires PHP**: 7.4+
**Tested up to**: WordPress 6.4
**License**: GPLv2 or later
