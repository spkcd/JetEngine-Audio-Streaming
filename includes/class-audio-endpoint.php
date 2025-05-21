<?php
namespace JetEngine_Audio_Streaming;

/**
 * Class for handling chunked audio streaming endpoint
 */
class Audio_Endpoint {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_endpoint' ] );
		add_action( 'wp_ajax_jet_engine_audio_stream', [ $this, 'handle_stream_request' ] );
		add_action( 'wp_ajax_nopriv_jet_engine_audio_stream', [ $this, 'handle_stream_request' ] );
		
		// Register custom field for JetEngine
		add_filter( 'jet-engine/listing/data-sources/dynamic-field/custom-controls', [ $this, 'register_dynamic_field_controls' ] );
		add_filter( 'jet-engine/listing/dynamic-field/callback/audio_player', [ $this, 'render_audio_player' ], 10, 3 );
		add_filter( 'jet-engine/listing/dynamic-field/callback/rest_audio_player', [ $this, 'render_rest_audio_player' ], 10, 3 );
		
		// Add shortcode for audio player
		add_shortcode( 'jetengine_audio_player', [ $this, 'audio_player_shortcode' ] );
	}

	/**
	 * Register REST API endpoint for streaming audio
	 */
	public function register_endpoint() {
		add_rewrite_rule(
			'jet-engine-audio/([^/]+)/([^/]+)/?$',
			'index.php?jet_audio_file=$matches[1]&jet_audio_chunk=$matches[2]',
			'top'
		);
		
		add_rewrite_tag( '%jet_audio_file%', '([^&]+)' );
		add_rewrite_tag( '%jet_audio_chunk%', '([^&]+)' );
		
		add_action( 'template_redirect', [ $this, 'handle_audio_request' ] );
	}

	/**
	 * Handle stream request via REST API
	 */
	public function handle_audio_request() {
		$file_id = get_query_var( 'jet_audio_file' );
		$chunk = get_query_var( 'jet_audio_chunk' );
		
		if ( empty( $file_id ) || empty( $chunk ) ) {
			return;
		}
		
		$file_id = absint( $file_id );
		$chunk = absint( $chunk );
		
		$this->stream_audio_chunk( $file_id, $chunk );
		exit;
	}

	/**
	 * Handle AJAX stream request
	 */
	public function handle_stream_request() {
		if ( ! isset( $_GET['file_id'] ) || ! isset( $_GET['chunk'] ) ) {
			wp_send_json_error( [ 'message' => 'Missing required parameters' ] );
		}
		
		$file_id = absint( $_GET['file_id'] );
		$chunk = absint( $_GET['chunk'] );
		
		$this->stream_audio_chunk( $file_id, $chunk );
		exit;
	}

	/**
	 * Stream audio chunk
	 * 
	 * @param int $file_id Attachment ID
	 * @param int $chunk   Chunk number
	 */
	private function stream_audio_chunk( $file_id, $chunk ) {
		// Get plugin settings
		$settings = Plugin::instance()->get_settings();
		$debug_mode = $settings['debug_mode'];
		$chunk_size_mb = $settings['chunk_size'];
		$max_file_size = $settings['max_file_size'] * 1024 * 1024; // Convert MB to bytes
		
		// Start timing the request
		$start_time = microtime(true);
		
		if ( $debug_mode ) {
			$log_context = [
				'file_id' => $file_id,
				'chunk' => $chunk,
				'chunk_size_mb' => $chunk_size_mb,
				'timestamp' => time()
			];
			
			Plugin::instance()->log(
				sprintf( 'Processing chunk request for file ID %d, chunk %d', $file_id, $chunk ),
				$log_context
			);
		}
		
		// Initialize log data
		$log_data = [
			'log_type' => 'request',
			'message' => sprintf( 'Chunk request for file ID %d, chunk %d', $file_id, $chunk ),
			'file_id' => $file_id,
			'chunk_index' => $chunk,
		];
		
		// Check cache for the first chunk (for fast initial loading)
		if ( $chunk === 0 ) {
			$cache_key = 'jet_audio_chunk_' . $file_id . '_0';
			$cached_chunk = get_transient( $cache_key );
			
			if ( $cached_chunk ) {
				if ( $debug_mode ) {
					$log_context = [
						'file_id' => $file_id,
						'chunk' => $chunk,
						'cache_key' => $cache_key,
						'cache_status' => 'hit',
						'timestamp' => time()
					];
					
					Plugin::instance()->log(
						sprintf( 'Cache hit for file ID %d, chunk %d', $file_id, $chunk ),
						$log_context
					);
				}
				
				// Set proper headers for chunked content
				$file_size = $cached_chunk['file_size'];
				$mime_type = $cached_chunk['mime_type'];
				$buffer = $cached_chunk['data'];
				$chunk_size = $chunk_size_mb * 1024 * 1024;
				$start = 0;
				$end = min( $chunk_size - 1, $file_size - 1 );
				
				// Update log data
				$log_data['cache_status'] = 'hit';
				$log_data['byte_start'] = $start;
				$log_data['byte_end'] = $end;
				$log_data['file_size'] = $file_size;
				$log_data['status_code'] = 206;
				
				header( 'Content-Type: ' . $mime_type );
				header( 'Content-Length: ' . strlen( $buffer ) );
				header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size );
				header( 'Accept-Ranges: bytes' );
				header( 'X-JetEngine-Cache: hit' ); // Add header for debugging
				status_header( 206 ); // Partial Content
				
				// Calculate request duration
				$end_time = microtime(true);
				$duration = round(($end_time - $start_time) * 1000); // in milliseconds
				$log_data['duration'] = $duration;
				
				// Log the request
				if ( Plugin::instance()->get_logs() ) {
					Plugin::instance()->get_logs()->log_chunk( $log_data );
				}
				
				echo $buffer;
				exit;
			} else if ( $debug_mode ) {
				$log_context = [
					'file_id' => $file_id,
					'chunk' => $chunk,
					'cache_key' => $cache_key,
					'cache_status' => 'miss',
					'timestamp' => time()
				];
				
				Plugin::instance()->log(
					sprintf( 'Cache miss for file ID %d, chunk %d', $file_id, $chunk ),
					$log_context
				);
				
				// Update log data
				$log_data['cache_status'] = 'miss';
			}
		}
		
		$attachment = get_post( $file_id );
		
		if ( ! $attachment ) {
			if ( $debug_mode ) {
				$log_context = [
					'file_id' => $file_id,
					'response_status' => 404,
					'timestamp' => time()
				];
				
				Plugin::instance()->log(
					sprintf( 'File ID %d not found', $file_id ),
					$log_context
				);
			}
			
			// Update log data
			$log_data['message'] = sprintf( 'File ID %d not found', $file_id );
			$log_data['status_code'] = 404;
			$log_data['log_type'] = 'error';
			
			// Calculate request duration
			$end_time = microtime(true);
			$duration = round(($end_time - $start_time) * 1000); // in milliseconds
			$log_data['duration'] = $duration;
			
			// Log the request
			if ( Plugin::instance()->get_logs() ) {
				Plugin::instance()->get_logs()->log_chunk( $log_data );
			}
			
			status_header( 404 );
			exit( 'File not found' );
		}
		
		$file_path = get_attached_file( $file_id );
		
		if ( ! file_exists( $file_path ) ) {
			if ( $debug_mode ) {
				$log_context = [
					'file_id' => $file_id,
					'file_path' => basename( $file_path ),
					'response_status' => 404,
					'timestamp' => time()
				];
				
				Plugin::instance()->log(
					sprintf( 'File path %s does not exist', $file_path ),
					$log_context
				);
			}
			
			// Update log data
			$log_data['message'] = sprintf( 'File path does not exist for ID %d', $file_id );
			$log_data['status_code'] = 404;
			$log_data['log_type'] = 'error';
			
			// Calculate request duration
			$end_time = microtime(true);
			$duration = round(($end_time - $start_time) * 1000); // in milliseconds
			$log_data['duration'] = $duration;
			
			// Log the request
			if ( Plugin::instance()->get_logs() ) {
				Plugin::instance()->get_logs()->log_chunk( $log_data );
			}
			
			status_header( 404 );
			exit( 'File not found' );
		}
		
		// Check file size against maximum allowed
		$file_size = filesize( $file_path );
		
		if ( $max_file_size > 0 && $file_size > $max_file_size ) {
			if ( $debug_mode ) {
				$log_context = [
					'file_id' => $file_id,
					'file_size' => $file_size,
					'max_file_size' => $max_file_size,
					'response_status' => 403,
					'timestamp' => time()
				];
				
				Plugin::instance()->log(
					sprintf( 
						'File size exceeds maximum allowed (%s > %s)',
						size_format( $file_size ),
						size_format( $max_file_size )
					),
					$log_context
				);
			}
			
			// Update log data
			$log_data['message'] = sprintf( 'File size exceeds maximum allowed (%s > %s)', 
				size_format( $file_size ), size_format( $max_file_size ) );
			$log_data['file_size'] = $file_size;
			$log_data['status_code'] = 403;
			$log_data['log_type'] = 'error';
			
			// Calculate request duration
			$end_time = microtime(true);
			$duration = round(($end_time - $start_time) * 1000); // in milliseconds
			$log_data['duration'] = $duration;
			
			// Log the request
			if ( Plugin::instance()->get_logs() ) {
				Plugin::instance()->get_logs()->log_chunk( $log_data );
			}
			
			status_header( 403 );
			exit( sprintf( 'File is too large. Maximum allowed size is %s.', size_format( $max_file_size ) ) );
		}
		
		// Convert chunk size to bytes
		$chunk_size = $chunk_size_mb * 1024 * 1024;
		
		// Calculate start and end bytes for the requested chunk
		$start = $chunk * $chunk_size;
		$end = min( $start + $chunk_size - 1, $file_size - 1 );
		
		// Update log data with byte range
		$log_data['byte_start'] = $start;
		$log_data['byte_end'] = $end;
		$log_data['file_size'] = $file_size;
		
		// If requested chunk is beyond file size, return 416 Range Not Satisfiable
		if ( $start >= $file_size ) {
			if ( $debug_mode ) {
				$log_context = [
					'file_id' => $file_id,
					'file_size' => $file_size,
					'chunk' => $chunk,
					'start_byte' => $start,
					'response_status' => 416,
					'timestamp' => time()
				];
				
				Plugin::instance()->log(
					sprintf( 'Requested chunk is beyond file size (start: %d, file size: %d)', $start, $file_size ),
					$log_context
				);
			}
			
			// Update log data
			$log_data['message'] = sprintf( 'Requested chunk is beyond file size (start: %d, file size: %d)', 
				$start, $file_size );
			$log_data['status_code'] = 416;
			$log_data['log_type'] = 'error';
			
			// Calculate request duration
			$end_time = microtime(true);
			$duration = round(($end_time - $start_time) * 1000); // in milliseconds
			$log_data['duration'] = $duration;
			
			// Log the request
			if ( Plugin::instance()->get_logs() ) {
				Plugin::instance()->get_logs()->log_chunk( $log_data );
			}
			
			status_header( 416 );
			header( 'Content-Range: bytes */' . $file_size );
			exit;
		}
		
		if ( $debug_mode ) {
			// Calculate playback position as approximate percentage of the file
			$playback_position = ($start / $file_size) * 100;
			
			$log_context = [
				'file_id' => $file_id,
				'file_path' => basename( $file_path ),
				'file_size' => $file_size,
				'chunk' => $chunk,
				'start_byte' => $start,
				'end_byte' => $end,
				'chunk_size' => $chunk_size,
				'estimated_playback_position' => round( $playback_position, 2 ) . '%',
				'response_status' => 206,
				'timestamp' => time()
			];
			
			Plugin::instance()->log(
				sprintf( 
					'Serving chunk %d (bytes %d-%d/%d, chunk size: %s)',
					$chunk,
					$start,
					$end,
					$file_size,
					size_format( $chunk_size )
				),
				$log_context
			);
		}
		
		// Set proper headers for chunked content
		$mime_type = get_post_mime_type( $file_id );
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size );
		header( 'Accept-Ranges: bytes' );
		header( 'X-JetEngine-Cache: miss' ); // Add header for debugging
		status_header( 206 ); // Partial Content
		
		// Update log data
		$log_data['status_code'] = 206;
		$log_data['cache_status'] = 'miss';
		
		// Read and output the file chunk with optimized buffer
		$handle = fopen( $file_path, 'rb' );
		fseek( $handle, $start );
		
		// Optimized buffer reading for large files
		// Use a moderate buffer size of 8KB for better performance
		$buffer_size = 8192; // 8KB buffer
		$bytes_to_read = $end - $start + 1;
		$buffer = '';
		
		// Read in smaller chunks to avoid memory issues with very large files
		while (!feof($handle) && $bytes_to_read > 0) {
			$chunk = fread($handle, min($buffer_size, $bytes_to_read));
			if ($chunk === false) {
				break;
			}
			$buffer .= $chunk;
			$bytes_to_read -= strlen($chunk);
		}
		
		fclose( $handle );
		
		// Cache the first chunk for faster initial loading (5 minutes)
		if ( $chunk === 0 ) {
			$cache_key = 'jet_audio_chunk_' . $file_id . '_0';
			$cache_data = [
				'data' => $buffer,
				'file_size' => $file_size,
				'mime_type' => $mime_type
			];
			
			set_transient( $cache_key, $cache_data, 5 * MINUTE_IN_SECONDS );
			
			if ( $debug_mode ) {
				$log_context = [
					'file_id' => $file_id,
					'chunk' => $chunk,
					'cache_key' => $cache_key,
					'expires_in' => '5 minutes',
					'timestamp' => time()
				];
				
				Plugin::instance()->log(
					sprintf( 'Cached first chunk for file ID %d', $file_id ),
					$log_context
				);
			}
		}
		
		// Calculate request duration
		$end_time = microtime(true);
		$duration = round(($end_time - $start_time) * 1000); // in milliseconds
		$log_data['duration'] = $duration;
		
		// Log the request
		if ( Plugin::instance()->get_logs() ) {
			Plugin::instance()->get_logs()->log_chunk( $log_data );
		}
		
		// Enhanced logging for audio stream requests (if enabled)
		if ( apply_filters( 'jetengine_audio_stream_enable_logging', false ) ) {
			$log_entry = sprintf(
				'[%s] Audio chunk request: File ID %d, Chunk %d, Bytes %d-%d/%d (%s), Duration: %dms, Status: %d',
				date('Y-m-d H:i:s'),
				$file_id,
				$chunk,
				$start,
				$end,
				$file_size,
				size_format( $end - $start + 1 ),
				$duration,
				206
			);
			
			if ( apply_filters( 'jetengine_audio_stream_log_to_file', false ) ) {
				// Log to a file in the wp-content directory
				$log_dir = WP_CONTENT_DIR . '/jet-audio-logs';
				if ( ! file_exists( $log_dir ) ) {
					wp_mkdir_p( $log_dir );
				}
				
				$log_file = $log_dir . '/audio-stream-' . date('Y-m-d') . '.log';
				error_log( $log_entry . PHP_EOL, 3, $log_file );
			} else {
				// Log to PHP error log
				error_log( $log_entry );
			}
		}
		
		echo $buffer;
		
		if ( $debug_mode ) {
			$bytes_sent = $end - $start + 1;
			
			$log_context = [
				'file_id' => $file_id,
				'chunk' => $chunk,
				'bytes_sent' => $bytes_sent,
				'timestamp' => time()
			];
			
			Plugin::instance()->log(
				sprintf( 'Successfully sent chunk %d (%s)', $chunk, size_format( $bytes_sent ) ),
				$log_context
			);
		}
		
		exit;
	}

	/**
	 * Register custom controls for JetEngine Dynamic Field
	 * 
	 * @param array $controls
	 * @return array
	 */
	public function register_dynamic_field_controls( $controls ) {
		$controls['audio_player'] = [
			'label'     => __( 'Audio Player with Chunked Streaming', 'jetengine-audio-streaming' ),
			'callback'  => 'audio_player',
			'group'     => 'text',
			'position'  => 99,
			'help'      => __( 'Render audio player with chunked streaming support', 'jetengine-audio-streaming' ),
		];
		
		$controls['rest_audio_player'] = [
			'label'     => __( 'REST API Audio Player', 'jetengine-audio-streaming' ),
			'callback'  => 'rest_audio_player',
			'group'     => 'text',
			'position'  => 100,
			'help'      => __( 'Render audio player using REST API endpoint for audio-recording post type', 'jetengine-audio-streaming' ),
		];
		
		return $controls;
	}

	/**
	 * Render custom audio player
	 * 
	 * @param mixed  $result
	 * @param array  $settings
	 * @param object $dynamic_field
	 * @return string
	 */
	public function render_audio_player( $result, $settings, $dynamic_field ) {
		$attachment_id = $result;
		
		if ( ! $attachment_id ) {
			return '';
		}
		
		$attachment = get_post( $attachment_id );
		
		if ( ! $attachment || strpos( get_post_mime_type( $attachment ), 'audio/' ) === false ) {
			return '';
		}
		
		// Enqueue required scripts and styles
		wp_enqueue_script( 'jetengine-audio-player' );
		wp_enqueue_style( 'jetengine-audio-player' );
		
		// Get file info
		$file_url = wp_get_attachment_url( $attachment_id );
		$file_path = get_attached_file( $attachment_id );
		$file_size = file_exists( $file_path ) ? filesize( $file_path ) : 0;
		$mime_type = get_post_mime_type( $attachment_id );
		
		// Get plugin settings
		$plugin_settings = Plugin::instance()->get_settings();
		$chunk_size = $plugin_settings['chunk_size'] * 1024 * 1024; // Convert MB to bytes
		$total_chunks = ceil( $file_size / $chunk_size );
		$debug_mode = $plugin_settings['debug_mode'];
		
		// Prepare player data
		$player_data = [
			'file_id'      => $attachment_id,
			'file_url'     => $file_url,
			'file_size'    => $file_size,
			'mime_type'    => $mime_type,
			'chunk_size'   => $chunk_size,
			'total_chunks' => $total_chunks,
			'endpoint'     => home_url( 'wp-admin/admin-ajax.php?action=jet_engine_audio_stream' ),
			'debug_mode'   => $debug_mode,
			'use_range'    => true, // Enable Range header support
		];
		
		// Generate unique player ID
		$player_id = 'jet-audio-player-' . $attachment_id . '-' . uniqid();
		
		// Output HTML
		ob_start();
		?>
		<div class="jet-audio-player" id="<?php echo esc_attr( $player_id ); ?>">
			<div class="jet-audio-player__waveform"></div>
			<div class="jet-audio-player__chunk-indicator">
				<div class="jet-audio-player__chunk-loaded"></div>
			</div>
			<div class="jet-audio-player__controls">
				<button class="jet-audio-player__play-pause">
					<span class="dashicons dashicons-controls-play"></span>
				</button>
				<div class="jet-audio-player__time">
					<span class="jet-audio-player__current-time">0:00</span>
					<span class="jet-audio-player__duration">0:00</span>
				</div>
				<?php if ( Plugin::instance()->get_settings()['enable_clipboard'] ) : ?>
				<button class="jet-audio-player__copy-url" data-url="<?php echo esc_attr( $file_url ); ?>">
					<span class="dashicons dashicons-clipboard"></span>
				</button>
				<?php endif; ?>
			</div>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			if (typeof JetEngineAudioPlayer !== 'undefined') {
				new JetEngineAudioPlayer('<?php echo esc_attr( $player_id ); ?>', <?php echo json_encode( $player_data ); ?>);
				
				// Initialize copy to clipboard button
				<?php if ( Plugin::instance()->get_settings()['enable_clipboard'] ) : ?>
				const copyButton = document.querySelector('#<?php echo esc_attr( $player_id ); ?> .jet-audio-player__copy-url');
				if (copyButton) {
					copyButton.addEventListener('click', function() {
						const url = this.dataset.url;
						const tempInput = document.createElement('input');
						document.body.appendChild(tempInput);
						tempInput.value = url;
						tempInput.select();
						
						try {
							document.execCommand('copy');
							
							// Show success indicator
							const originalHTML = this.innerHTML;
							this.innerHTML = '<span class="dashicons dashicons-yes"></span>';
							
							// Restore original HTML after 2 seconds
							setTimeout(function() {
								copyButton.innerHTML = originalHTML;
							}, 2000);
						} catch (err) {
							alert('Failed to copy URL to clipboard');
						}
						
						document.body.removeChild(tempInput);
					});
				}
				<?php endif; ?>
			}
		});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render REST API audio player for audio-recording post type
	 * 
	 * @param mixed  $result
	 * @param array  $settings
	 * @param object $dynamic_field
	 * @return string
	 */
	public function render_rest_audio_player( $result, $settings, $dynamic_field ) {
		$post_id = $result;
		
		if ( ! $post_id ) {
			return '';
		}
		
		$post = get_post( $post_id );
		
		if ( ! $post || 'audio-recording' !== get_post_type( $post ) ) {
			return '';
		}
		
		// Enqueue required scripts and styles
		wp_enqueue_script( 'jetengine-audio-player' );
		wp_enqueue_style( 'jetengine-audio-player' );
		
		// Try to get file size information if available
		$file_size = 0;
		$mime_type = '';
		
		// Try to get from JetEngine meta fields
		if ( function_exists( 'jet_engine' ) ) {
			$recording_field = get_post_meta( $post_id, 'recording', true );
			$download_field = get_post_meta( $post_id, 'recording_download', true );
			
			if ( ! empty( $recording_field ) ) {
				$attachment_id = attachment_url_to_postid( $recording_field );
				if ( $attachment_id ) {
					$file_path = get_attached_file( $attachment_id );
					if ( file_exists( $file_path ) ) {
						$file_size = filesize( $file_path );
						$mime_type = get_post_mime_type( $attachment_id );
					}
				}
			} elseif ( ! empty( $download_field ) ) {
				$attachment_id = attachment_url_to_postid( $download_field );
				if ( $attachment_id ) {
					$file_path = get_attached_file( $attachment_id );
					if ( file_exists( $file_path ) ) {
						$file_size = filesize( $file_path );
						$mime_type = get_post_mime_type( $attachment_id );
					}
				}
			}
		}
		
		// If still no file info, try to get from attached audio file
		if ( $file_size === 0 ) {
			$attachments = get_posts( [
				'post_type'      => 'attachment',
				'posts_per_page' => 1,
				'post_parent'    => $post_id,
				'post_mime_type' => 'audio',
			] );
			
			if ( ! empty( $attachments ) ) {
				$attachment_id = $attachments[0]->ID;
				$file_path = get_attached_file( $attachment_id );
				if ( file_exists( $file_path ) ) {
					$file_size = filesize( $file_path );
					$mime_type = get_post_mime_type( $attachment_id );
				}
			}
		}
		
		// Get plugin settings for chunk size
		$plugin_settings = Plugin::instance()->get_settings();
		$chunk_size = $plugin_settings['chunk_size'] * 1024 * 1024; // Convert MB to bytes
		$total_chunks = $file_size > 0 ? ceil( $file_size / $chunk_size ) : 0;
		
		// Prepare player data
		$player_data = [
			'post_id'      => $post_id,
			'post_type'    => 'audio-recording',
			'rest_url'     => rest_url( 'jetengine-audio-stream/v1/play/' ),
			'file_size'    => $file_size,
			'mime_type'    => $mime_type,
			'chunk_size'   => $chunk_size,
			'total_chunks' => $total_chunks,
			'debug_mode'   => $plugin_settings['debug_mode'],
		];
		
		// Generate unique player ID
		$player_id = 'jet-rest-audio-player-' . $post_id . '-' . uniqid();
		
		// Output HTML
		ob_start();
		?>
		<div class="jet-audio-player" id="<?php echo esc_attr( $player_id ); ?>">
			<div class="jet-audio-player__waveform"></div>
			<div class="jet-audio-player__chunk-indicator">
				<div class="jet-audio-player__chunk-loaded"></div>
			</div>
			<div class="jet-audio-player__controls">
				<button class="jet-audio-player__play-pause">
					<span class="dashicons dashicons-controls-play"></span>
				</button>
				<div class="jet-audio-player__time">
					<span class="jet-audio-player__current-time">0:00</span>
					<span class="jet-audio-player__duration">0:00</span>
				</div>
				<?php 
				// Add copy URL button if enabled in settings
				$rest_url = rest_url( 'jetengine-audio-stream/v1/play/' . $post_id );
				if ( Plugin::instance()->get_settings()['enable_clipboard'] ) : 
				?>
				<button class="jet-audio-player__copy-url" data-url="<?php echo esc_attr( $rest_url ); ?>">
					<span class="dashicons dashicons-clipboard"></span>
				</button>
				<?php endif; ?>
			</div>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			if (typeof JetEngineAudioPlayer !== 'undefined') {
				new JetEngineAudioPlayer('<?php echo esc_attr( $player_id ); ?>', <?php echo json_encode( $player_data ); ?>);
				
				// Initialize copy to clipboard button
				<?php if ( Plugin::instance()->get_settings()['enable_clipboard'] ) : ?>
				const copyButton = document.querySelector('#<?php echo esc_attr( $player_id ); ?> .jet-audio-player__copy-url');
				if (copyButton) {
					copyButton.addEventListener('click', function() {
						const url = this.dataset.url;
						const tempInput = document.createElement('input');
						document.body.appendChild(tempInput);
						tempInput.value = url;
						tempInput.select();
						
						try {
							document.execCommand('copy');
							
							// Show success indicator
							const originalHTML = this.innerHTML;
							this.innerHTML = '<span class="dashicons dashicons-yes"></span>';
							
							// Restore original HTML after 2 seconds
							setTimeout(function() {
								copyButton.innerHTML = originalHTML;
							}, 2000);
						} catch (err) {
							alert('Failed to copy URL to clipboard');
						}
						
						document.body.removeChild(tempInput);
					});
				}
				<?php endif; ?>
			}
		});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Audio player shortcode
	 * 
	 * @param array $atts Shortcode attributes
	 * @return string
	 */
	public function audio_player_shortcode( $atts ) {
		$atts = shortcode_atts( [
			'id'        => 0,
			'post_type' => '',
		], $atts, 'jetengine_audio_player' );
		
		$id = absint( $atts['id'] );
		
		if ( ! $id ) {
			return '';
		}
		
		// If post type is explicitly set to audio-recording, use REST API player
		if ( 'audio-recording' === $atts['post_type'] ) {
			$post = get_post( $id );
			
			if ( ! $post || 'audio-recording' !== get_post_type( $post ) ) {
				return '';
			}
			
			return $this->render_rest_audio_player( $id, [], null );
		}
		
		// Otherwise try to use media attachment player
		$attachment = get_post( $id );
		
		if ( ! $attachment || 'attachment' !== $attachment->post_type || strpos( get_post_mime_type( $attachment ), 'audio/' ) === false ) {
			return '';
		}
		
		return $this->render_audio_player( $id, [], null );
	}
} 