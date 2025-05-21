<?php
namespace JetEngine_Audio_Streaming;

// Add settings class reference
require_once JETENGINE_AUDIO_STREAM_PATH . 'includes/class-audio-stream-settings.php';
use \JetEngine_Audio_Stream_Settings;

/**
 * Class for handling REST API endpoints for audio streaming
 */
class REST_API {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_endpoints' ] );
	}

	/**
	 * Register REST API endpoints
	 */
	public function register_endpoints() {
		register_rest_route( 'jetengine-audio-stream/v1', '/play/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_stream_request' ],
			'permission_callback' => [ $this, 'permission_callback_play_endpoint' ],
			'args'                => [
				'id' => [
					'validate_callback' => function( $param ) {
						return is_numeric( $param ) && intval( $param ) > 0;
					},
					'sanitize_callback' => 'absint',
					'description'       => __( 'Audio recording post ID', 'jetengine-audio-streaming' ),
					'required'          => true,
				],
			],
		] );
		
		// New endpoint to resolve attachment ID by filename
		register_rest_route( 'jetengine-audio-stream/v1', '/resolve-id', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_resolve_id_request' ],
			'permission_callback' => '__return_true', // Consider more specific permissions
			'args'                => [
				'filename' => [
					'required' => true,
					'validate_callback' => function( $param ) {
						return is_string( $param ) && ! empty( $param );
					},
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		/*
		// Register the peaks endpoint
		register_rest_route( 'jetengine-audio-stream/v1', '/peaks/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_get_peaks_request' ],
			'permission_callback' => '__return_true', // Allow public access to peaks data
			'args'                => [
				'id' => [
					'required' => true,
					'validate_callback' => function( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
					'sanitize_callback' => 'absint',
				],
			],
		] );
		*/
	}

	/**
	 * Permission callback for the PLAY endpoint
	 * 
	 * @param \WP_REST_Request $request REST API request
	 * @return bool
	 */
	public function permission_callback_play_endpoint( $request ) {
		$request_id = $request->get_param('id');
		error_log(sprintf(
			'JetEngine Audio Streaming: PERMISSION_CALLBACK_PLAY_ENDPOINT for /play/ route. ID: %s. Request URI: %s',
			($request_id ?: 'N/A'),
			$_SERVER['REQUEST_URI'] ?? 'N/A'
		));
		// For now, keep it simple and allow, the main callback will do more checks.
		// You might want to add actual permission checks here later.
		return true;
	}

	/**
	 * Permission callback
	 * 
	 * @param \WP_REST_Request $request REST API request
	 * @return bool
	 */
	public function permission_callback( $request ) {
		// Log the request to help debug permission issues
		$request_url = $request->get_route();
		$request_method = $request->get_method();
		$request_id = $request->get_param('id');
		
		// Log detailed request information
		error_log(sprintf(
			'JetEngine Audio Streaming: Received %s request to endpoint %s with ID: %s',
			$request_method,
			$request_url,
			$request_id
		));
		
		// Always return true for testing purposes
		// This allows all users to access the endpoint
		return true;
	}

	/**
	 * Handle stream request
	 * 
	 * @param \WP_REST_Request $request REST API request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_stream_request( $request ) {
		// Get the attachment ID from the route
		$attachment_id = $request->get_param( 'id' );
		
		// Log the start of the request for debugging
		error_log( sprintf( 'JetEngine Audio Streaming: Processing stream request for attachment ID %d', $attachment_id ) );
		
		// Get the settings
		$streaming_settings = JetEngine_Audio_Stream_Settings::get_settings();
		
		// Check if streaming is enabled
		if ( empty( $streaming_settings['enable_streaming'] ) ) {
			error_log( 'JetEngine Audio Streaming: Streaming is disabled in settings' );
			return new \WP_Error(
				'streaming_disabled',
				__( 'Audio streaming is disabled in plugin settings', 'jetengine-audio-streaming' ),
				[ 'status' => 403 ]
			);
		}
		
		// Check attachment exists and is valid
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== get_post_type( $attachment ) ) {
			error_log( sprintf( 'JetEngine Audio Streaming: Attachment ID %d not found or not an attachment', $attachment_id ) );
			return new \WP_Error(
				'attachment_not_found',
				__( 'Audio file not found', 'jetengine-audio-streaming' ),
				[ 'status' => 404 ]
			);
		}
		
		// Check MIME type
		$mime_type = get_post_mime_type( $attachment );
		if ( strpos( $mime_type, 'audio/' ) !== 0 ) {
			error_log( sprintf( 'JetEngine Audio Streaming: Attachment ID %d is not an audio file (MIME: %s)', $attachment_id, $mime_type ) );
			return new \WP_Error(
				'not_audio_file',
				__( 'The requested file is not an audio file', 'jetengine-audio-streaming' ),
				[ 'status' => 404 ]
			);
		}
		
		// Get local file path
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			error_log( sprintf( 'JetEngine Audio Streaming: File not accessible for attachment ID %d: %s', $attachment_id, $file_path ? $file_path : 'N/A' ) );
			return new \WP_Error(
				'file_not_accessible',
				__( 'Audio file is not accessible', 'jetengine-audio-streaming' ),
				[ 'status' => 404 ]
			);
		}
		
		// Get file size and extension
		$file_size = filesize( $file_path );
		$file_extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		
		// Get direct URL to file
		$file_url = wp_get_attachment_url( $attachment_id );
		
		// Check if we have a Range header or HEAD request
		$has_range_header = isset( $_SERVER['HTTP_RANGE'] );
		$is_head_request = isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'HEAD';
		
		// For small MP3 or WAV files, redirect to direct URL unless range request
		$size_threshold = 10 * 1024 * 1024; // 10MB in bytes
		$is_small_file = $file_size < $size_threshold;
		$is_mp3_or_wav = in_array( $file_extension, [ 'mp3', 'wav' ] );
		
		if ( $is_small_file && $is_mp3_or_wav && !$has_range_header && !$is_head_request ) {
			error_log( sprintf( 
				'JetEngine Audio Streaming: Small %s file (%s), redirecting to direct URL: %s',
				strtoupper( $file_extension ),
				size_format( $file_size ),
				$file_url
			));
			
			// Return a redirect response
			$response = new \WP_REST_Response();
			$response->set_status( 302 ); // Temporary redirect
			$response->header( 'Location', $file_url );
			return $response;
		}
		
		// For larger files or range requests, continue with streaming
		error_log( sprintf( 
			'JetEngine Audio Streaming: Handling %s streaming for %s file (%s)',
			$has_range_header ? 'range-based' : 'full',
			$file_extension,
			size_format( $file_size )
		));
		
		// Check if file extension is in the allowed list
		$allowed_extensions = explode( ',', $streaming_settings['allowed_file_types'] );
		if ( !in_array( $file_extension, $allowed_extensions ) ) {
			error_log( sprintf( 'JetEngine Audio Streaming: File extension %s is not in the allowed list: %s', 
				$file_extension, 
				$streaming_settings['allowed_file_types'] 
			));
			return new \WP_Error(
				'file_type_not_allowed',
				__( 'This audio file type is not allowed for streaming', 'jetengine-audio-streaming' ),
				[ 'status' => 403 ]
			);
		}
		
		// Check against max size setting
		$max_size_bytes = $streaming_settings['max_file_size'] * 1024 * 1024; // Convert MB to bytes
		if ( $file_size > $max_size_bytes ) {
			error_log( sprintf( 'JetEngine Audio Streaming: File size %s exceeds the maximum allowed size %s', 
				size_format( $file_size ),
				size_format( $max_size_bytes )
			));
			return new \WP_Error(
				'file_too_large',
				__( 'This audio file exceeds the maximum allowed size for streaming', 'jetengine-audio-streaming' ),
				[ 'status' => 403 ]
			);
		}
		
		// Attempt to increase execution time for large files
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // 5 minutes
		}
		
		// Stream the file
		$this->stream_audio_file( $file_path, $attachment_id );
		exit; // Exit to prevent additional output
	}

	/**
	 * Convert a file URL to a local file path
	 * 
	 * @param string $url File URL
	 * @return string|bool File path or false if conversion failed
	 */
	private function get_file_path_from_url( $url ) {
		// If it's an attachment URL, try to get the file path from WordPress
		$attachment_id = attachment_url_to_postid( $url );
		
		if ( $attachment_id ) {
			// Preferred method: Use WordPress core function to get the file path
			$file_path = get_attached_file( $attachment_id );
			
			// Verify file exists and is readable
			if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
				// Check the file permissions
				$perms = fileperms( $file_path ) & 0777;
				if ( $perms < 0644 ) {
					error_log( sprintf( 'JetEngine Audio Streaming: File permissions too restrictive: %o (should be at least 644) for file: %s', $perms, $file_path ) );
				}
				
				// Validate the path is within the uploads directory
				$uploads_dir = wp_upload_dir();
				$uploads_basedir = wp_normalize_path( $uploads_dir['basedir'] );
				$real_file_path = realpath( $file_path );
				$real_uploads_dir = realpath( $uploads_basedir );
				
				if ( $real_file_path && $real_uploads_dir && 0 === strpos( $real_file_path, $real_uploads_dir ) ) {
					// File is safely within uploads directory
					return $file_path;
				} else {
					error_log( sprintf( 'JetEngine Audio Streaming: CRITICAL - get_file_path_from_url (attachment) - Security check failed. Real file path: %s, Real uploads dir: %s. File path: %s. URL: %s', ($real_file_path ?: 'null'), ($real_uploads_dir ?: 'null'), $file_path, $url ) );
					return false;
				}
			} else {
				error_log( sprintf( 'JetEngine Audio Streaming: File not readable: %s from URL: %s', ($file_path ?: 'N/A'), $url ) );
				return false;
			}
		}
		
		// If it's not an attachment, try to convert URL to path
		$site_url = site_url();
		$site_dir = wp_normalize_path( ABSPATH );
		
		// If the URL is not from this site, return false
		if ( 0 !== strpos( $url, $site_url ) ) {
			// Handle external URLs
			if ( 0 === strpos( $url, 'http' ) ) {
				// Create a temporary file
				error_log( sprintf( 'JetEngine Audio Streaming: Attempting to download external URL: %s', $url ) );
				$temp_file = download_url( $url, 30 ); // Add timeout
				
				if ( is_wp_error( $temp_file ) ) {
					error_log( sprintf( 'JetEngine Audio Streaming: Failed to download external file: %s. Error: %s', $url, $temp_file->get_error_message() ) );
					return false;
				}
				error_log( sprintf( 'JetEngine Audio Streaming: Successfully downloaded external file to: %s', $temp_file ) );
				return $temp_file;
			}
			
			error_log( sprintf( 'JetEngine Audio Streaming: URL does not belong to this site: %s', $url ) );
			return false;
		}
		
		// Sanitize the URL to prevent directory traversal attacks
		$relative_url = wp_normalize_path( substr( $url, strlen( $site_url ) ) );
		$relative_url = preg_replace( '#[\\/\\\\\\\\]+#', '/', $relative_url ); // Remove multiple slashes
		$relative_url = preg_replace( '#\\.\\./#', '', $relative_url ); // Remove parent directory references
		
		$file_path = path_join( $site_dir, $relative_url );
		
		// Verify the file exists and is readable
		if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
			// Validate the path is within the WordPress directory
			$real_file_path = realpath( $file_path );
			$real_site_dir = realpath( $site_dir );
			
			if ( $real_file_path && $real_site_dir && 0 === strpos( $real_file_path, $real_site_dir ) ) {
				// Check if file is in uploads directory (preferred) or at least in the WordPress directory
				$uploads_dir = wp_upload_dir();
				$uploads_basedir = wp_normalize_path( $uploads_dir['basedir'] );
				$real_uploads_dir = realpath( $uploads_basedir );
				
				if ( $real_uploads_dir && 0 === strpos( $real_file_path, $real_uploads_dir ) ) {
					// File is safely within uploads directory - preferred location
					error_log( sprintf( 'JetEngine Audio Streaming: File path resolved to uploads directory: %s for URL %s', $real_file_path, $url ) );
				} else {
					// File is within WordPress directory but not in uploads directory
					error_log( sprintf( 'JetEngine Audio Streaming: File path is within WordPress directory but not in uploads directory: %s for URL %s', $real_file_path, $url ) );
				}
				
				// Check the file permissions
				$perms = fileperms( $file_path ) & 0777;
				if ( $perms < 0644 ) {
					error_log( sprintf( 'JetEngine Audio Streaming: File permissions too restrictive: %o (should be at least 644) for file: %s from URL: %s', $perms, $file_path, $url ) );
				}
				
				return $file_path;
			} else {
				error_log( sprintf( 'JetEngine Audio Streaming: CRITICAL - get_file_path_from_url (site URL) - Security check failed. Real file path: %s, Real site dir: %s. File path: %s. URL: %s', ($real_file_path ?: 'null'), ($real_site_dir ?: 'null'), $file_path, $url ) );
				return false;
			}
		} else {
			error_log( sprintf( 'JetEngine Audio Streaming: File not found or not readable: %s from URL: %s', ($file_path ?: 'N/A'), $url ) );
			return false;
		}
	}

	/**
	 * Stream audio file with byte range support
	 * 
	 * @param string $file_path  Path to the audio file
	 * @param int    $attachment_id Attachment ID
	 * @return void
	 */
	private function stream_audio_file( $file_path, $attachment_id ) {
		// Get file details
		$file_size = filesize( $file_path );
		$mime_type = get_post_mime_type( $attachment_id ) ?: $this->get_mime_type( $file_path );
		
		// Security checks
		if ( !file_exists( $file_path ) || !is_readable( $file_path ) ) {
			error_log( sprintf( 'JetEngine Audio Streaming: File not accessible before streaming: %s', $file_path ) );
			status_header( 404 );
			exit( 'File not found' );
		}
		
		// Log streaming start
		error_log( sprintf( 'JetEngine Audio Streaming: Starting to stream file: %s, Size: %s, Type: %s', 
			$file_path,
			size_format( $file_size ),
			$mime_type
		));
		
		// Handle HEAD requests: send headers and exit
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'HEAD' ) {
			status_header( 200 ); // OK
			header( 'Content-Type: ' . $mime_type );
			header( 'Content-Length: ' . $file_size );
			header( 'Accept-Ranges: bytes' );
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', filemtime( $file_path ) ) . ' GMT' );
			header( 'Cache-Control: public, max-age=86400' );
			flush();
			exit;
		}
		
		// Default byte range
		$start = 0;
		$end = $file_size - 1;
		$length = $file_size;
		
		// Process Range header if present
		$range_header = isset( $_SERVER['HTTP_RANGE'] ) ? $_SERVER['HTTP_RANGE'] : null;
		
		if ( $range_header ) {
			// Parse the range header
			if ( preg_match('/bytes=\s*(\d*)-(\d*)/i', $range_header, $matches) ) {
				$start = empty( $matches[1] ) ? 0 : intval( $matches[1] );
				$end = empty( $matches[2] ) ? $file_size - 1 : intval( $matches[2] );
				
				// Validate the range
				if ( $start > $end || $start >= $file_size ) {
					// Range Not Satisfiable
					header( 'HTTP/1.1 416 Range Not Satisfiable' );
					header( 'Content-Range: bytes */' . $file_size );
					error_log( sprintf( 'JetEngine Audio Streaming: Invalid range request: %d-%d/%d', $start, $end, $file_size ) );
					exit;
				}
				
				// Adjust end if needed
				if ( $end >= $file_size ) {
					$end = $file_size - 1;
				}
				
				// Calculate the length of the requested range
				$length = $end - $start + 1;
				
				// Send partial content status and headers for range requests
				status_header( 206 ); // Partial Content
				header( 'HTTP/1.1 206 Partial Content' );
				header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size );
			} else {
				// Invalid range format
				header( 'HTTP/1.1 400 Bad Request' );
				error_log( 'JetEngine Audio Streaming: Invalid range format: ' . $range_header );
				exit( 'Invalid range request' );
			}
		} else {
			// Full content response
			status_header( 200 ); // OK
			header( 'HTTP/1.1 200 OK' );
		}
		
		// Set common response headers
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . $length );
		header( 'Accept-Ranges: bytes' );
		header( 'Cache-Control: public, max-age=86400' );
		
		// Disable output buffering to prevent memory issues with large files
		if ( ob_get_level() ) {
			ob_end_clean();
		}
		
		// Open the file for reading
		$handle = @fopen( $file_path, 'rb' );
		
		if ( !$handle ) {
			header( 'HTTP/1.1 500 Internal Server Error' );
			error_log( 'JetEngine Audio Streaming: Failed to open file for reading: ' . $file_path );
			exit( 'Failed to open file for reading' );
		}
		
		// Seek to the requested position for range requests
		if ( $start > 0 ) {
			fseek( $handle, $start );
		}
		
		// Stream the file
		$bytes_sent = 0;
		
		// For small complete file requests, use readfile for best performance
		if ( $length < 1048576 && $start === 0 && $end === $file_size - 1 ) { // 1MB threshold
			// Close the file handle first
			fclose( $handle );
			// Use readfile for better performance with small files
			$bytes_sent = readfile( $file_path );
		} else {
			// For range requests or larger files, stream in chunks
			$buffer_size = 524288; // 512KB buffer - good balance between memory usage and performance
			
			while ( !feof( $handle ) && $bytes_sent < $length && connection_status() === CONNECTION_NORMAL ) {
				// Calculate how much to read this iteration
				$bytes_to_read = min( $buffer_size, $length - $bytes_sent );
				
				// Read the chunk
				$buffer = fread( $handle, $bytes_to_read );
				if ( $buffer === false ) {
					break;
				}
				
				// Output the chunk
				echo $buffer;
				
				// Flush the output buffer to send data to client immediately
				flush();
				
				// Update sent counter
				$bytes_sent += strlen( $buffer );
				
				// Give the server a moment to breathe for very large files
				if ( $bytes_sent > 10485760 && $bytes_sent % $buffer_size === 0 ) { // Every 512KB after 10MB
					usleep( 10000 ); // 10ms pause to prevent server overload
				}
			}
			
			// Close the file handle
			fclose( $handle );
		}
		
		// Log streaming completion
		error_log( sprintf( 
			'JetEngine Audio Streaming: Stream completed. Sent: %s of %s. Range: %s-%s',
			size_format( $bytes_sent ),
			size_format( $file_size ),
			size_format( $start ),
			size_format( $end )
		));
		
		exit;
	}

	/**
	 * Get MIME type of file
	 * 
	 * @param string $file_path File path
	 * @return string MIME type
	 */
	private function get_mime_type( $file_path ) {
		$mime_types = [
			'mp3' => 'audio/mpeg',
			'ogg' => 'audio/ogg',
			'wav' => 'audio/wav',
			'm4a' => 'audio/mp4',
			'flac' => 'audio/flac',
		];
		
		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		
		if ( isset( $mime_types[ $ext ] ) ) {
			return $mime_types[ $ext ];
		}
		
		// Try to get MIME type using WordPress functions
		if ( function_exists( 'wp_check_filetype' ) ) {
			$filetype = wp_check_filetype( $file_path );
			if ( ! empty( $filetype['type'] ) ) {
				return $filetype['type'];
			}
		}
		
		// Default to binary stream if can't determine
		return 'application/octet-stream';
	}

	/**
	 * Get attachment ID from file path
	 * 
	 * @param string $file_path File path
	 * @return int|bool Attachment ID or false if not found
	 */
	private function get_attachment_id_from_path( $file_path ) {
		global $wpdb;
		
		// Normalize file path
		$file_path = wp_normalize_path( $file_path );
		$file_path = preg_replace( '|^.+?wp-content/|', '', $file_path );
		
		// Query the database for the attachment
		$query = $wpdb->prepare(
			"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
			$file_path
		);
		
		$attachment_id = $wpdb->get_var( $query );
		
		if ( $attachment_id ) {
			return (int) $attachment_id;
		}
		
		// Try with the uploads path
		$uploads_dir = wp_get_upload_dir();
		$uploads_basedir = wp_normalize_path( $uploads_dir['basedir'] );
		$uploads_path = str_replace( $uploads_basedir . '/', '', $file_path );
		
		$query = $wpdb->prepare(
			"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
			$uploads_path
		);
		
		$attachment_id = $wpdb->get_var( $query );
		
		if ( $attachment_id ) {
			return (int) $attachment_id;
		}
		
		return false;
	}

	/**
	 * Handle request to resolve filename to attachment ID
	 * 
	 * @param \WP_REST_Request $request REST API request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_resolve_id_request( $request ) {
		$filename = $request->get_param( 'filename' );
		
		if ( empty( $filename ) ) {
			return new \WP_Error(
				'invalid_filename',
				__( 'Filename parameter is required.', 'jetengine-audio-streaming' ),
				[ 'status' => 400 ]
			);
		}
		
		// Log the request for debugging
		error_log( sprintf( 'JetEngine Audio Streaming: resolve-id request for filename: %s', $filename ) );
		
		// Query the database to find the attachment ID
		global $wpdb;
		
		// First try with exact filename match (most reliable)
		$attachment_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
			'%/' . $wpdb->esc_like( $filename ) . '.%' // Look for /filename.ext in the meta value
		) );
		
		// If not found, try with just the filename (less directory-specific)
		if ( ! $attachment_id ) {
			$attachment_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( $filename ) . '.%' // Look for filename.ext anywhere
			) );
		}
		
		// If still not found, try with just the name part and no extension
		if ( ! $attachment_id ) {
			$attachment_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( $filename ) . '%' // Look for filename anywhere without requiring extension
			) );
		}
		
		if ( $attachment_id ) {
			// Found the attachment ID, get attachment info
			$attachment = get_post( $attachment_id );
			
			if ( ! $attachment ) {
				error_log( sprintf( 'JetEngine Audio Streaming: resolve-id found ID %d but post does not exist', $attachment_id ) );
				return new \WP_Error(
					'attachment_missing',
					__( 'Attachment found but post does not exist', 'jetengine-audio-streaming' ),
					[ 'status' => 404 ]
				);
			}
			
			// Use our utility function to get attachment info
			$info = jetstream_get_attachment_info( $attachment_id );
			
			// Build the response format to match the requested structure
			$response_data = [
				'id'      => (int) $attachment_id,
				'url'     => $info['url'],
				'mime'    => $info['mime'],
				'size'    => $info['size'],
				'success' => true
			];
			
			// Log success for debugging
			error_log( sprintf( 
				'JetEngine Audio Streaming: resolve-id success for "%s": ID=%d, size=%d, type=%s', 
				$filename, 
				$attachment_id, 
				$info['size'],
				$info['mime']
			) );
			
			$response = new \WP_REST_Response( $response_data );
			$response->set_status( 200 );
			return $response;
		} else {
			// Attachment not found
			error_log( sprintf( 'JetEngine Audio Streaming: resolve-id found no match for "%s"', $filename ) );
			return new \WP_Error(
				'not_found',
				__( 'Attachment not found', 'jetengine-audio-streaming' ),
				[ 'status' => 404 ]
			);
		}
	}

	/**
	 * Handle request to get waveform peaks data for an attachment.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	/*
	public function handle_get_peaks_request( $request ) {
		$attachment_id = $request['id'];
		// Use the central logger
		Plugin::instance()->log( "Received request for peaks data for attachment ID: {$attachment_id}" );

		// Check if the attachment exists and is an audio file (optional but recommended)
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			Plugin::instance()->log( "Attachment ID {$attachment_id} not found or is not an attachment." );
			return new \WP_Error( 'attachment_not_found', 'Attachment not found.', [ 'status' => 404 ] );
		}

		// Consider checking mime type: strpos( $attachment->post_mime_type, 'audio/' ) === 0

		// Retrieve peaks data from post meta (Corrected key to '_audio_peaks')
		$peaks_data = get_post_meta( $attachment_id, '_audio_peaks', true );

		if ( empty( $peaks_data ) ) {
			Plugin::instance()->log( "No peaks data found using key '_audio_peaks' for attachment ID: {$attachment_id}. Attempting on-demand generation..." );

			// Attempt to generate peaks on-demand
			if ( function_exists( '\JetEngine_Audio_Streaming\jetengine_audio_stream_generate_peaks' ) ) {
				$generated_peaks = jetengine_audio_stream_generate_peaks( $attachment_id );

				if ( $generated_peaks === false ) {
					Plugin::instance()->log( "On-demand peak generation failed for attachment ID: {$attachment_id}." );
					// Return 404 as generation failed (likely file issue or FFmpeg issue)
					return new \WP_Error( 'peaks_generation_failed', 'Failed to generate peaks data.', [ 'status' => 404 ] );
				} else {
					Plugin::instance()->log( "On-demand peak generation successful for attachment ID: {$attachment_id}. Re-fetching meta..." );
					// Re-fetch the meta data now that it should exist
					$peaks_data = get_post_meta( $attachment_id, '_audio_peaks', true );
					// If still empty after successful generation, something is wrong
					if ( empty( $peaks_data ) ) {
						Plugin::instance()->log( "ERROR: Peaks meta still empty after successful on-demand generation for ID: {$attachment_id}", [], true);
						return new \WP_Error( 'peaks_fetch_error', 'Error fetching peaks data after generation.', [ 'status' => 500 ] );
					}
				}
			} else {
				Plugin::instance()->log( "Peak generation function not found during on-demand attempt for ID: {$attachment_id}", [], true );
				// Function doesn't exist, so return the original 404
				return new \WP_Error( 'no_peaks', 'Peaks data not found for this attachment (generation unavailable).' , [ 'status' => 404 ] );
			}
		}

		// If we reach here, $peaks_data should contain the JSON string (either originally or after on-demand generation)
		$peaks_array = json_decode( $peaks_data, true );

		if ( ! is_array( $peaks_array ) ) {
			// If decoding fails or it's not an array, treat it as missing/invalid data.
			Plugin::instance()->log( "Invalid peaks data format for attachment ID: {$attachment_id}" );
			return new \WP_Error( 'invalid_peaks_format', 'Peaks data is in an invalid format.', [ 'status' => 500 ] ); // Internal server error might be more appropriate
		}

		Plugin::instance()->log( "Successfully retrieved peaks data for attachment ID: {$attachment_id}" );
		return new \WP_REST_Response( $peaks_array, 200 );
	}
	*/

	/**
	 * Permission callback to check if the user can read the attachment.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool True if the user can read the attachment, false otherwise.
	 */
	/*
	public function user_can_read_attachment( $request ) {
		$attachment_id = isset( $request['id'] ) ? absint( $request['id'] ) : 0;

		if ( ! $attachment_id ) {
			return false;
		}

		$attachment = get_post( $attachment_id );

		// Check if attachment exists and if the current user has permission to read it
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			// Even if it doesn't exist, we might want to return true here and let the main callback handle the 404
			// But checking existence ensures the ID is valid before further checks.
			return false;
		}

		// Use current_user_can check. 'read_post' capability should work for attachments.
		// You might need a more specific capability check depending on your security model.
		return current_user_can( 'read_post', $attachment_id );
	}
	*/
} 