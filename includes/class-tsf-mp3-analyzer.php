<?php
/**
 * MP3 Quality Analyzer using getID3
 *
 * @package TrackSubmissionForm
 * @since 3.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSF_MP3_Analyzer {

    private $getID3;

    public function __construct() {
        // Load getID3 library
        require_once TSF_PLUGIN_DIR . 'lib/getid3/getid3.php';
        $this->getID3 = new getID3();
    }

    /**
     * Analyze MP3 file and generate QC report
     *
     * @param string $file_path Full path to MP3 file
     * @return array QC report with metadata, audio info, and recommendations
     */
    public function analyze($file_path) {
        if (!file_exists($file_path)) {
            return $this->error_response('File not found');
        }

        // VUL-13 FIX: Validate MP3 file magic bytes before processing
        // This prevents malicious non-MP3 files from being analyzed by getID3
        if (!$this->validate_mp3_magic_bytes($file_path)) {
            error_log('TSF Security: Invalid MP3 file detected - ' . basename($file_path));
            return $this->error_response('Invalid MP3 file format. File does not contain valid MP3 magic bytes.');
        }

        // Additional file size validation (50MB max)
        $filesize = filesize($file_path);
        $max_size = apply_filters('tsf_max_file_size', 50 * 1024 * 1024); // 50MB default
        if ($filesize > $max_size) {
            return $this->error_response(sprintf(
                'File too large. Maximum size is %s MB.',
                round($max_size / 1024 / 1024, 1)
            ));
        }

        try {
            $info = $this->getID3->analyze($file_path);

            if (isset($info['error'])) {
                return $this->error_response(implode(', ', $info['error']));
            }

            $report = [
                'success' => true,
                'metadata' => $this->extract_metadata($info),
                'audio' => $this->extract_audio_info($info),
                'quality_score' => 0,
                'recommendations' => []
            ];

            // Calculate quality score and generate recommendations
            $this->calculate_quality_score($report);

            return $report;

        } catch (Exception $e) {
            return $this->error_response($e->getMessage());
        }
    }

    /**
     * Extract ID3 metadata
     */
    private function extract_metadata($info) {
        $tags = isset($info['tags']) ? $info['tags'] : [];
        $id3v2 = isset($tags['id3v2']) ? $tags['id3v2'] : [];
        $id3v1 = isset($tags['id3v1']) ? $tags['id3v1'] : [];

        // Prefer ID3v2, fallback to ID3v1
        $metadata = [
            'title' => $this->get_tag($id3v2, 'title', $id3v1),
            'artist' => $this->get_tag($id3v2, 'artist', $id3v1),
            'album' => $this->get_tag($id3v2, 'album', $id3v1),
            'year' => $this->get_tag($id3v2, 'year', $id3v1),
            'genre' => $this->get_tag($id3v2, 'genre', $id3v1),
            'comment' => $this->get_tag($id3v2, 'comment', $id3v1),
        ];

        // Check for artwork
        $metadata['has_artwork'] = false;
        if (isset($info['comments']['picture'])) {
            $metadata['has_artwork'] = true;
        }

        return $metadata;
    }

    /**
     * Extract audio technical info
     */
    private function extract_audio_info($info) {
        $audio = isset($info['audio']) ? $info['audio'] : [];

        $bitrate = isset($info['bitrate']) ? round($info['bitrate'] / 1000) : 0;
        $sample_rate = isset($audio['sample_rate']) ? $audio['sample_rate'] : 0;
        $channels = isset($audio['channels']) ? $audio['channels'] : 0;
        $channelmode = isset($audio['channelmode']) ? $audio['channelmode'] : 'unknown';

        // Duration in seconds
        $duration_seconds = isset($info['playtime_seconds']) ? $info['playtime_seconds'] : 0;
        $duration_formatted = $this->format_duration($duration_seconds);

        // File size
        $filesize = isset($info['filesize']) ? $info['filesize'] : 0;
        $filesize_formatted = $this->format_filesize($filesize);

        // Bitrate type (CBR/VBR)
        $bitrate_mode = 'CBR';
        if (isset($audio['bitrate_mode']) && $audio['bitrate_mode'] === 'vbr') {
            $bitrate_mode = 'VBR';
        }

        return [
            'bitrate_kbps' => $bitrate,
            'bitrate_mode' => $bitrate_mode,
            'samplerate_hz' => $sample_rate,
            'channels' => $channels,
            'channelmode' => $channelmode,
            'duration_seconds' => round($duration_seconds, 2),
            'duration_formatted' => $duration_formatted,
            'filesize' => $filesize,
            'filesize_formatted' => $filesize_formatted
        ];
    }

    /**
     * Calculate quality score (0-100) and generate recommendations
     */
    private function calculate_quality_score(&$report) {
        $metadata_score = 0;
        $audio_score = 0;
        $professional_score = 0;
        $recommendations = [];

        $metadata = $report['metadata'];
        $audio = $report['audio'];

        // ID3 Tags scoring (40 points total)
        if (!empty($metadata['title'])) {
            $metadata_score += 10;
        } else {
            $recommendations[] = 'Add ID3 title tag';
        }

        if (!empty($metadata['artist'])) {
            $metadata_score += 10;
        } else {
            $recommendations[] = 'Add ID3 artist name';
        }

        if (!empty($metadata['album'])) {
            $metadata_score += 10;
        } else {
            $recommendations[] = 'Add ID3 album name';
        }

        if ($metadata['has_artwork']) {
            $metadata_score += 10;
        } else {
            $recommendations[] = 'Add album artwork (cover art)';
        }

        // Audio Quality scoring (30 points total)
        // Bitrate (30 points)
        if ($audio['bitrate_kbps'] >= 320) {
            $audio_score += 30;
        } elseif ($audio['bitrate_kbps'] >= 256) {
            $audio_score += 25;
            $recommendations[] = 'Prefer 320 kbps CBR for best quality';
        } elseif ($audio['bitrate_kbps'] >= 192) {
            $audio_score += 20;
            $recommendations[] = 'Upgrade bitrate to 320 kbps CBR';
        } elseif ($audio['bitrate_kbps'] >= 128) {
            $audio_score += 10;
            $recommendations[] = 'Bitrate too low - use at least 192 kbps, prefer 320 kbps';
        } else {
            $recommendations[] = 'Bitrate critically low - must be at least 192 kbps';
        }

        // Professional Quality scoring (30 points total)
        // Sample rate (15 points)
        if ($audio['samplerate_hz'] >= 44100) {
            $professional_score += 15;
        } else {
            $professional_score += 5;
            $recommendations[] = 'Use 44.1 kHz sample rate (CD quality)';
        }

        // Channels (15 points)
        if ($audio['channels'] === 2) {
            $professional_score += 15;
        } elseif ($audio['channels'] === 1) {
            $professional_score += 10;
            $recommendations[] = 'Stereo (2 channels) preferred over mono';
        }

        // Bitrate mode preference
        if ($audio['bitrate_mode'] === 'VBR') {
            $recommendations[] = 'CBR (Constant Bitrate) is preferred over VBR for streaming';
        }

        $total_score = $metadata_score + $audio_score + $professional_score;

        $report['quality_score'] = min(100, $total_score);
        $report['total_score'] = min(100, $total_score);
        $report['metadata_score'] = $metadata_score;
        $report['audio_score'] = $audio_score;
        $report['professional_score'] = $professional_score;
        $report['recommendations'] = $recommendations;
    }

    /**
     * Get tag value with fallback
     */
    private function get_tag($primary, $key, $fallback = []) {
        if (isset($primary[$key][0])) {
            return $primary[$key][0];
        }
        if (isset($fallback[$key][0])) {
            return $fallback[$key][0];
        }
        return '';
    }

    /**
     * Format duration (seconds to mm:ss)
     */
    private function format_duration($seconds) {
        $minutes = floor($seconds / 60);
        $seconds = round((int)$seconds % 60);
        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Format file size
     */
    private function format_filesize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }

    /**
     * Validate MP3 file magic bytes
     * MP3 files start with either:
     * - FF FB (MPEG-1 Layer 3)
     * - FF FA (MPEG-1 Layer 3, less common)
     * - FF F3 (MPEG-2 Layer 3)
     * - FF F2 (MPEG-2 Layer 3, less common)
     * - ID3 (ID3v2 tag, followed by FF FB/FA/F3/F2)
     *
     * @param string $file_path Full path to file
     * @return bool True if valid MP3, false otherwise
     */
    private function validate_mp3_magic_bytes($file_path) {
        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            return false;
        }

        // Read first 10 bytes
        $header = fread($handle, 10);
        fclose($handle);

        if (strlen($header) < 3) {
            return false;
        }

        // Check for ID3v2 tag (starts with "ID3")
        if (substr($header, 0, 3) === 'ID3') {
            // ID3v2 tag present - MP3 data follows after tag
            // We trust getID3 to validate the actual MP3 frames after the tag
            return true;
        }

        // Check for MP3 frame sync (FF Fx where x = A, B, 2, or 3)
        $byte1 = ord($header[0]);
        $byte2 = ord($header[1]);

        // First byte must be 0xFF
        if ($byte1 !== 0xFF) {
            return false;
        }

        // Second byte must be 0xFA, 0xFB, 0xF2, or 0xF3 (upper nibble F, lower nibble A/B/2/3)
        $valid_second_bytes = [0xFA, 0xFB, 0xF2, 0xF3];
        if (!in_array($byte2, $valid_second_bytes, true)) {
            return false;
        }

        return true;
    }

    /**
     * Error response structure
     */
    private function error_response($message) {
        return [
            'success' => false,
            'error' => $message,
            'metadata' => [],
            'audio' => [],
            'quality_score' => 0,
            'recommendations' => []
        ];
    }
}
