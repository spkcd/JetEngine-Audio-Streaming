<?php
/**
 * Audio Streaming Controller
 * 
 * Handles REST API endpoints for audio streaming and file resolution.
 */
namespace JetEngine\Audio_Stream;

// Exit if accessed directly
defined('ABSPATH') || exit;

class Streaming_Controller {
    
    /**
     * Register REST API routes
     */
    public static function register_routes() {
        register_rest_route('jetengine-audio-stream/v1', '/play/(?P<id>.*)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'handle_audio_stream'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('jetengine-audio-stream/v1', '/resolve-id', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'resolve_filename_to_id'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handle audio streaming request
     * 
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response|\WP_Error Response or error
     */
    public static function handle_audio_stream($request) {
        $id_or_path = $request['id'];
        $id = 0;
        
        // Check if the parameter is a numeric ID
        if (is_numeric($id_or_path)) {
            $id = intval($id_or_path);
        } else {
            // Check if it's a full URL and extract the filename
            if (filter_var($id_or_path, FILTER_VALIDATE_URL) || strpos($id_or_path, 'http') === 0) {
                // Extract only the filename from the URL
                $filename = basename(parse_url($id_or_path, PHP_URL_PATH));
            } else {
                // Assume it's a simple filename
                $filename = basename($id_or_path);
            }
            
            // Search for this filename in the Media Library
            $args = [
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key' => '_wp_attached_file',
                        'value' => $filename,
                        'compare' => 'LIKE',
                    ],
                ],
            ];
            
            $query = new \WP_Query($args);
            
            if ($query->have_posts()) {
                $id = $query->posts[0]->ID;
                self::log_stream_request($id, 'resolve_path', "Found attachment ID for filename: {$filename}");
            } else {
                self::log_stream_request(0, 'error', "No attachment found for filename: {$filename}");
                return new \WP_Error('file_not_found', 'File not found', ['status' => 404]);
            }
        }
        
        // Verify this is a valid attachment
        if (!$id || !wp_attachment_is('audio', $id)) {
            self::log_stream_request($id, 'error', 'Not a valid audio attachment');
            return new \WP_Error('invalid_attachment', 'Not a valid audio attachment', ['status' => 404]);
        }
        
        // Get file path
        $file_path = get_attached_file($id);
        
        if (!file_exists($file_path) || !is_readable($file_path)) {
            self::log_stream_request($id, 'error', 'File not found or not readable');
            return new \WP_Error('file_not_found', 'File not found or not readable', ['status' => 404]);
        }
        
        // Check file size - if less than 10MB, redirect to the direct file URL
        $file_size = filesize($file_path);
        $size_threshold = 10 * 1024 * 1024; // 10MB in bytes
        
        // Only redirect for GET requests, not for HEAD requests or if Range header is present
        $is_head_request = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'HEAD';
        $has_range_header = isset($_SERVER['HTTP_RANGE']);
        
        if ($file_size < $size_threshold && !$is_head_request && !$has_range_header) {
            $file_url = wp_get_attachment_url($id);
            self::log_stream_request($id, 'redirect', sprintf('File size (%s) below threshold, redirecting to direct URL', size_format($file_size)));
            
            // Return a redirect response
            $response = new \WP_REST_Response();
            $response->set_status(302); // Temporary redirect
            $response->header('Location', $file_url);
            return $response;
        }
        
        // For larger files, continue with streaming implementation
        // Check file extension against allowed types
        $settings = get_option('jetengine_audio_stream_settings', []);
        $allowed_types = !empty($settings['allowed_file_types']) ? 
                         explode(',', $settings['allowed_file_types']) : 
                         ['mp3', 'wav', 'ogg', 'm4a', 'flac'];
        
        $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_types)) {
            self::log_stream_request($id, 'error', "File type not allowed: {$file_extension}");
            return new \WP_Error('invalid_file_type', 'File type not allowed', ['status' => 403]);
        }
        
        // Check file size against max size
        $max_size_mb = !empty($settings['max_file_size']) ? (int)$settings['max_file_size'] : 2048;
        $file_size_mb = $file_size / (1024 * 1024);
        
        if ($file_size_mb > $max_size_mb) {
            self::log_stream_request($id, 'error', "File too large: {$file_size_mb}MB exceeds {$max_size_mb}MB limit");
            return new \WP_Error('file_too_large', 'File exceeds maximum allowed size', ['status' => 403]);
        }

        // Log streaming start
        self::log_stream_request($id, 'start', 'Starting audio stream for large file');
        
        // Stream the file with Range support
        self::stream_audio_file($file_path, $id);
        exit;
    }
    
    /**
     * Stream audio file with support for HTTP Range requests
     * 
     * @param string $file_path Path to the audio file
     * @param int $attachment_id Attachment ID
     */
    private static function stream_audio_file($file_path, $attachment_id) {
        // Prevent script abortion when users seek or close the tab
        @ignore_user_abort(true);
        // Remove time limit for large file streaming
        @set_time_limit(0);
        
        if (!file_exists($file_path)) {
            status_header(404);
            self::log_stream_request($attachment_id, 'error', 'File not found: ' . $file_path);
            exit('File not found');
        }
        
        $file_size = filesize($file_path);
        $start = 0;
        $end = $file_size - 1;
        $length = $file_size;
        
        // Get the MIME type
        $mime_type = get_post_mime_type($attachment_id);
        if (empty($mime_type)) {
            $mime_type = mime_content_type($file_path);
            
            // Fallback for common audio types if mime_content_type fails
            if (empty($mime_type)) {
                $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                $mime_map = [
                    'mp3' => 'audio/mpeg',
                    'wav' => 'audio/wav',
                    'ogg' => 'audio/ogg',
                    'm4a' => 'audio/mp4',
                    'flac' => 'audio/flac'
                ];
                $mime_type = isset($mime_map[$extension]) ? $mime_map[$extension] : 'application/octet-stream';
            }
        }

        // Handle HEAD requests first - they only need headers, not content
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'HEAD') {
            header('HTTP/1.1 200 OK');
            header('Content-Type: ' . $mime_type);
            header('Content-Length: ' . $file_size);
            header('Accept-Ranges: bytes');
            header('Cache-Control: public, max-age=86400');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($file_path)) . ' GMT');
            header('ETag: "' . md5(filemtime($file_path) . $file_size) . '"');
            
            // Log HEAD request
            self::log_stream_request(
                $attachment_id, 
                'head_request', 
                "HEAD request processed. File size: " . size_format($file_size)
            );
            
            // These two calls ensure headers are sent before script termination
            flush();
            if (function_exists('ob_flush') && ob_get_level()) {
                ob_flush();
            }
            exit;
        }

        // Set the content type header
        header('Content-Type: ' . $mime_type);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=86400');

        // Process range request (if present)
        if (isset($_SERVER['HTTP_RANGE'])) {
            $range = $_SERVER['HTTP_RANGE'];
            $range = str_replace('bytes=', '', $range);
            
            // Parse the range header
            if (strpos($range, '-') !== false) {
                [$start_range, $end_range] = explode('-', $range);
                $start = intval($start_range);
                $end = $end_range ? intval($end_range) : $file_size - 1;
                
                // Validate the range
                if ($start > $end || $start >= $file_size) {
                    // Range Not Satisfiable
                    header('Content-Range: bytes */' . $file_size);
                    header('HTTP/1.1 416 Range Not Satisfiable');
                    self::log_stream_request($attachment_id, 'error', "Invalid range request: $start-$end/$file_size");
                    exit;
                }
                
                // Adjust end if needed
                if ($end >= $file_size) {
                    $end = $file_size - 1;
                }
                
                // Calculate the length of requested range
                $length = $end - $start + 1;
                
                // Send partial content headers
                header('HTTP/1.1 206 Partial Content');
                header("Content-Range: bytes $start-$end/$file_size");
            } else {
                // Invalid range format
                header('HTTP/1.1 400 Bad Request');
                self::log_stream_request($attachment_id, 'error', 'Invalid range format: ' . $range);
                exit('Invalid range request');
            }
        } else {
            // Full content response
            header('HTTP/1.1 200 OK');
        }
        
        // Set content length header
        header("Content-Length: $length");
        
        // Disable output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Open the file
        $fp = @fopen($file_path, 'rb');
        
        if (!$fp) {
            header('HTTP/1.1 500 Internal Server Error');
            self::log_stream_request($attachment_id, 'error', 'Failed to open file for reading');
            exit('Failed to open file for reading');
        }
        
        // Seek to the requested start position for range requests
        if ($start > 0) {
            fseek($fp, $start);
        }
        
        // Stream the file in small chunks
        $buffer_size = 8192; // 8KB buffer size
        $bytes_sent = 0;
        
        while (!feof($fp) && ($pos = ftell($fp)) <= $end) {
            // Calculate how much to read
            if ($pos + $buffer_size > $end) {
                $buffer_size = $end - $pos + 1;
            }
            
            // Read and output the chunk
            $buffer = fread($fp, $buffer_size);
            if ($buffer === false) {
                break;
            }
            
            // Output and flush the buffer
            echo $buffer;
            flush();
            
            $bytes_sent += strlen($buffer);
            
            // Check if connection is still active - don't break on abort because we set ignore_user_abort
            if (connection_aborted() && !ignore_user_abort()) {
                break;
            }
        }
        
        fclose($fp);
        
        // Log successful stream completion
        self::log_stream_request(
            $attachment_id, 
            'complete', 
            sprintf(
                "Stream completed. Bytes sent: %s of %s. Range: %s-%s", 
                size_format($bytes_sent), 
                size_format($file_size),
                size_format($start),
                size_format($end)
            )
        );
        
        exit;
    }
    
    /**
     * Resolve a filename to a WordPress attachment ID
     *
     * @param \WP_REST_Request $request The request object
     * @return \WP_REST_Response|\WP_Error Response or error
     */
    public static function resolve_filename_to_id($request) {
        $filename = $request->get_param('filename');
        
        if (empty($filename)) {
            return new \WP_Error('missing_filename', 'Filename parameter is required', ['status' => 400]);
        }
        
        self::log_stream_request(0, 'resolve_id_request', "Searching for file: {$filename}");
        
        // Clean up the filename - remove any path info
        $filename = basename($filename);
        
        // Query attachments by filename
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_wp_attached_file',
                    'value' => $filename,
                    'compare' => 'LIKE',
                ],
            ],
        ];
        
        $query = new \WP_Query($args);
        
        if ($query->have_posts()) {
            $attachment_id = $query->posts[0]->ID;
            self::log_stream_request($attachment_id, 'resolve_id_success', "Found by file metadata");
            return rest_ensure_response([
                'id' => $attachment_id, 
                'success' => true,
                'method' => 'file_metadata'
            ]);
        }
        
        // Try to find by just the filename without extension as fallback
        $filename_no_ext = preg_replace('/\.[^.]+$/', '', $filename);
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            's' => $filename_no_ext,
            'posts_per_page' => 1,
        ];
        
        $query = new \WP_Query($args);
        
        if ($query->have_posts()) {
            $attachment_id = $query->posts[0]->ID;
            self::log_stream_request($attachment_id, 'resolve_id_success', "Found by filename search");
            return rest_ensure_response([
                'id' => $attachment_id, 
                'success' => true,
                'method' => 'title_search'
            ]);
        }
        
        self::log_stream_request(0, 'resolve_id_error', "No attachment found for filename: {$filename}");
        return new \WP_Error(
            'file_not_found', 
            'No attachment found with this filename', 
            ['status' => 404]
        );
    }
    
    /**
     * Log audio stream request
     * 
     * @param int $attachment_id The attachment ID
     * @param string $status Status of the request (success/error)
     * @param string $message Log message
     */
    private static function log_stream_request($attachment_id, $status, $message) {
        // Create log entry
        $log_entry = [
            'time' => current_time('mysql'),
            'timestamp' => time(),
            'attachment_id' => $attachment_id,
            'status' => $status,
            'message' => $message,
            'ip' => self::get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : 'Unknown',
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field($_SERVER['REQUEST_URI']) : '',
        ];
        
        // Get current settings
        $settings = get_option('jetengine_audio_stream_settings', []);
        
        // Get current logs
        $logs = isset($settings['debug_logs']) ? $settings['debug_logs'] : [];
        
        // Add new log to the beginning of the array
        array_unshift($logs, $log_entry);
        
        // Limit to 100 entries
        if (count($logs) > 100) {
            $logs = array_slice($logs, 0, 100);
        }
        
        // Update logs in settings
        $settings['debug_logs'] = $logs;
        update_option('jetengine_audio_stream_settings', $settings);
    }
    
    /**
     * Get client IP address
     *
     * @return string IP address
     */
    private static function get_client_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return sanitize_text_field($ip);
    }
} 