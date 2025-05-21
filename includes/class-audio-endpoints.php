<?php

class JetEngine_Audio_Endpoints {

    // Cache expiration time in seconds (1 hour)
    const CACHE_EXPIRATION = 3600;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('jetengine-audio-stream/v1', '/play/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'handle_audio_stream'],
            'permission_callback' => '__return_true',
        ]);

        // Add new route for resolving filenames to IDs
        register_rest_route('jetengine-audio-stream/v1', '/resolve-id', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'resolve_filename_to_id'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_audio_stream($request) {
        $id = intval($request['id']);
        
        // Verify this is a valid attachment
        if (!wp_attachment_is('audio', $id)) {
            self::log_stream_request($id, 'error', 'Not a valid audio attachment');
            return new WP_Error('invalid_attachment', 'Not a valid audio attachment', ['status' => 404]);
        }
        
        $file_path = get_attached_file($id);

        if (!file_exists($file_path) || !is_readable($file_path)) {
            self::log_stream_request($id, 'error', 'File not found or not readable');
            return new WP_Error('file_not_found', 'File not found or not readable', ['status' => 404]);
        }

        // Log streaming start
        self::log_stream_request($id, 'start', 'Starting audio stream');
        
        // Stream the file with Range support
        self::stream_audio_file($file_path, $id);
        exit;
    }

    private static function stream_audio_file($file_path, $attachment_id) {
        $file_size = filesize($file_path);
        $start = 0;
        $end = $file_size - 1;
        $length = $file_size;
        
        // Get the MIME type - supports various audio formats
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

        // Handle HEAD requests: send headers and exit
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'HEAD' ) {
            status_header( 200 ); // OK
            header( 'Content-Type: ' . $mime_type );
            header( 'Content-Length: ' . $file_size );
            header( 'Accept-Ranges: bytes' );
            // Optionally, add Last-Modified or ETag for client-side caching
            // header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', filemtime( $file_path ) ) . ' GMT' );
            // header( 'ETag: "' . md5( filemtime( $file_path ) . $file_size ) . '"' );
            self::log_stream_request($attachment_id, 'info', 'HEAD request processed. File size: ' . $file_size);
            flush(); // Force output of headers
            exit;
        }

        // Set the content type header
        header('Content-Type: ' . $mime_type);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=86400');

        // Process range request (if present)
        if (isset($_SERVER['HTTP_RANGE'])) {
            $range = $_SERVER['HTTP_RANGE'];
            
            // Parse the range header
            if (preg_match('/bytes=\s*(\d*)-(\d*)/i', $range, $matches)) {
                $start = empty($matches[1]) ? 0 : intval($matches[1]);
                $end = empty($matches[2]) ? $file_size - 1 : intval($matches[2]);
                
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
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size);
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
        header('Content-Length: ' . $length);
        
        // Disable output buffering
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Open the file
        $handle = @fopen($file_path, 'rb');
        
        if (!$handle) {
            header('HTTP/1.1 500 Internal Server Error');
            self::log_stream_request($attachment_id, 'error', 'Failed to open file for reading');
            exit('Failed to open file for reading');
        }
        
        // Seek to the requested start position for range requests
        if ($start > 0) {
            fseek($handle, $start);
        }
        
        // Choose the most efficient method based on server capabilities and file size
        if (function_exists('http_throttle') && $length > 1048576) {
            // Use throttling for very large files if available
            http_throttle(0, $length);
            $bytes_sent = fpassthru($handle);
        } else if ($length < 2097152 && $start === 0 && $end === $file_size - 1) {
            // For smaller full-file requests, use readfile for best performance
            fclose($handle);
            $bytes_sent = readfile($file_path);
        } else {
            // For other cases, stream in manageable chunks
            $buffer_size = 8192; // 8KB buffer size
            $bytes_sent = 0;
            
            while (!feof($handle) && $bytes_sent < $length) {
                // Calculate how much to read
                $bytes_to_read = min($buffer_size, $length - $bytes_sent);
                
                // Read and output the chunk
                $buffer = fread($handle, $bytes_to_read);
                if ($buffer === false) {
                    break;
                }
                
                // Output and flush the buffer
                echo $buffer;
                flush();
                
                $bytes_sent += strlen($buffer);
                
                // Allow client to disconnect
                if (connection_status() !== CONNECTION_NORMAL) {
                    break;
                }
            }
            
            fclose($handle);
        }
        
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
    }

    /**
     * Log audio stream request
     * 
     * @param int $attachment_id The attachment ID
     * @param string $status Status of the request (success/error)
     * @param string $message Log message
     */
    private static function log_stream_request($attachment_id, $status, $message) {
        // Always log to the debug log, regardless of debug mode setting
        if (class_exists('JetEngine_Audio_Debug_Log')) {
            JetEngine_Audio_Debug_Log::add_log($attachment_id, $status, $message);
        }
        
        // Also log to error_log if debug mode is enabled
        if (get_option('je_audio_debug_mode', false)) {
            error_log(sprintf(
                '[JetEngine Audio Streaming] [%s] ID: %d, Status: %s, Message: %s',
                current_time('mysql'),
                $attachment_id,
                $status,
                $message
            ));
        }
    }

    /**
     * Clear cached chunks
     * 
     * @return int Number of cleared cache items
     */
    public static function clear_cached_chunks() {
        global $wpdb;
        
        $count = 0;
        $like = $wpdb->esc_like('_transient_jet_audio_chunk_') . '%';
        $transients = $wpdb->get_col($wpdb->prepare("
            SELECT option_name 
            FROM $wpdb->options 
            WHERE option_name LIKE %s
        ", $like));
        
        foreach ($transients as $transient) {
            $key = str_replace('_transient_', '', $transient);
            if (delete_transient($key)) {
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Resolve a filename to a WordPress attachment ID
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error Response or error
     */
    public static function resolve_filename_to_id($request) {
        $filename = $request->get_param('filename');
        
        if (empty($filename)) {
            return new WP_Error('missing_filename', 'Filename parameter is required', ['status' => 400]);
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
        
        $query = new WP_Query($args);
        
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
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            $attachment_id = $query->posts[0]->ID;
            self::log_stream_request($attachment_id, 'resolve_id_success', "Found by filename search");
            return rest_ensure_response([
                'id' => $attachment_id, 
                'success' => true,
                'method' => 'title_search'
            ]);
        }
        
        // Try to find by exact title match as last resort
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'title' => $filename_no_ext,
            'exact' => true,
            'posts_per_page' => 1,
        ];
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            $attachment_id = $query->posts[0]->ID;
            self::log_stream_request($attachment_id, 'resolve_id_success', "Found by exact title match");
            return rest_ensure_response([
                'id' => $attachment_id, 
                'success' => true,
                'method' => 'exact_title'
            ]);
        }
        
        self::log_stream_request(0, 'resolve_id_error', "No attachment found for filename: {$filename}");
        return new WP_Error(
            'file_not_found', 
            'No attachment found with this filename', 
            ['status' => 404]
        );
    }
} 