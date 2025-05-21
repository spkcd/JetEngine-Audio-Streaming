<?php
namespace JetEngine_Audio_Streaming;

/**
 * Class for handling WAV to MP3 conversion
 */
class Audio_Converter {

	private static $ffmpeg_path_cache = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Hook into WordPress media upload process
		add_filter( 'wp_handle_upload', [ $this, 'process_audio_upload' ], 10, 2 );
		
		// Hook into WordPress attachment creation
		add_action( 'add_attachment', [ $this, 'process_attachment' ] );
		
		// Hook into JetEngine form submission if available
		add_filter( 'jet-engine/forms/uploaded-files', [ $this, 'process_jetengine_uploads' ], 10, 2 );
		
		// Register AJAX endpoint for manual conversion
		add_action( 'wp_ajax_jet_audio_convert_file', [ $this, 'handle_manual_conversion' ] );
		
		// Add clipboard copy button to media library
		add_filter( 'attachment_fields_to_edit', [ $this, 'add_copy_url_button' ], 10, 2 );
		
		// Add admin scripts for clipboard functionality
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		
		// Add success message after conversion
		add_action( 'admin_notices', [ $this, 'display_conversion_notice' ] );
	}
	
	/**
	 * Enqueue admin scripts for clipboard functionality
	 */
	public function enqueue_admin_scripts() {
		// Get plugin settings
		$settings = Plugin::instance()->get_settings();
		
		// Only enqueue if clipboard functionality is enabled
		if ( empty( $settings['enable_clipboard'] ) ) {
			return;
		}
		
		wp_enqueue_script(
			'jetengine-audio-converter-admin',
			JETENGINE_AUDIO_STREAMING_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			JETENGINE_AUDIO_STREAMING_VERSION,
			true
		);
		
		wp_localize_script(
			'jetengine-audio-converter-admin',
			'JetEngineAudioConverter',
			[
				'copySuccess' => __( 'URL copied to clipboard!', 'jetengine-audio-streaming' ),
				'copyError'   => __( 'Failed to copy URL. Please try again.', 'jetengine-audio-streaming' ),
				'convertNonce' => wp_create_nonce( 'jet_audio_convert' )
			]
		);
	}
	
	/**
	 * Process a newly added attachment
	 * 
	 * @param int $attachment_id New attachment ID
	 */
	public function process_attachment( $attachment_id ) {
		// Get plugin settings
		$settings = Plugin::instance()->get_settings();
		
		// Check if auto-conversion is enabled
		if ( empty( $settings['auto_convert'] ) ) {
			return;
		}
		
		// Get attachment data
		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			return;
		}
		
		// Check if it's a WAV file
		$mime_type = get_post_mime_type( $attachment );
		if ( $mime_type !== 'audio/wav' && $mime_type !== 'audio/x-wav' ) {
			return;
		}
		
		// Get file path
		$file_path = get_attached_file( $attachment_id );
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return;
		}
		
		// Convert WAV to MP3
		$converted_file = $this->convert_wav_to_mp3( $file_path );
		
		// If conversion was successful, create a new attachment for the MP3
		if ( $converted_file && file_exists( $converted_file ) ) {
			// Create a new attachment
			$filename = basename( $converted_file );
			$filetype = wp_check_filetype( $filename, null );
			$wp_upload_dir = wp_upload_dir();
			
			$attachment_data = [
				'guid'           => $wp_upload_dir['url'] . '/' . $filename,
				'post_mime_type' => $filetype['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_parent'    => $attachment->post_parent // Keep the same parent if any
			];
			
			// Insert the attachment
			$mp3_attachment_id = wp_insert_attachment( $attachment_data, $converted_file, $attachment->post_parent );
			
			if ( ! is_wp_error( $mp3_attachment_id ) ) {
				// Generate metadata for the attachment
				require_once( ABSPATH . 'wp-admin/includes/image.php' );
				$attach_data = wp_generate_attachment_metadata( $mp3_attachment_id, $converted_file );
				wp_update_attachment_metadata( $mp3_attachment_id, $attach_data );
				
				// Log the conversion
				$this->log_conversion( $converted_file, $settings['debug_mode'] );
				
				// Store the original WAV ID for reference
				update_post_meta( $mp3_attachment_id, '_converted_from_wav', $attachment_id );
				
				// Store the MP3 ID in the WAV attachment for reference
				update_post_meta( $attachment_id, '_converted_to_mp3', $mp3_attachment_id );
				
				// Set a transient to display admin notice
				set_transient( 'jetengine_audio_conversion_success', [
					'wav_id' => $attachment_id,
					'mp3_id' => $mp3_attachment_id,
					'filename' => $filename
				], 60 );

				// Generate peaks data for the new MP3 attachment
				/*
				if ( function_exists( '\JetEngine_Audio_Streaming\jetengine_audio_stream_generate_peaks' ) ) {
					Plugin::instance()->log( "Generating peaks for newly converted MP3: ID {$mp3_attachment_id}" );
					$peaks_result = jetengine_audio_stream_generate_peaks( $mp3_attachment_id );
					if ( $peaks_result === false ) {
						// Peak generation function handles its own internal logging on failure
						Plugin::instance()->log( "Peak generation failed for newly converted MP3: ID {$mp3_attachment_id}", [], true ); // Force log this specific failure context
					} else {
						Plugin::instance()->log( "Peak generation successful for newly converted MP3: ID {$mp3_attachment_id}" );
					}
				} else {
					// This case should ideally not happen if the plugin is loaded correctly, but log it if it does.
					Plugin::instance()->log( "Peak generation function `jetengine_audio_stream_generate_peaks` not found during MP3 conversion: ID {$mp3_attachment_id}", [], true );
				}
				*/
			}
		} else {
			// Log the conversion failure
			if ( $settings['debug_mode'] ) {
				error_log( sprintf(
					'JetEngine Audio Streaming: Failed to convert WAV to MP3 - %s',
					basename( $file_path )
				));
			}
			
			// Set a transient to display error notice
			set_transient( 'jetengine_audio_conversion_error', [
				'wav_id' => $attachment_id,
				'filename' => basename( $file_path )
			], 60 );
		}
	}
	
	/**
	 * Process audio uploads through WordPress media uploader
	 * 
	 * @param array $upload   Upload data
	 * @param string $context Upload context
	 * @return array Modified upload data
	 */
	public function process_audio_upload( $upload, $context ) {
		// Get plugin settings
		$settings = Plugin::instance()->get_settings();
		
		// Check if auto-conversion is enabled
		if ( empty( $settings['auto_convert'] ) ) {
			return $upload;
		}
		
		// Only process when file is a WAV
		if ( $upload['type'] !== 'audio/wav' && $upload['type'] !== 'audio/x-wav' ) {
			return $upload;
		}
		
		// Convert WAV to MP3
		$converted_file = $this->convert_wav_to_mp3( $upload['file'] );
		
		// If conversion was successful, replace the upload data
		if ( $converted_file && file_exists( $converted_file ) ) {
			// Update file info
			$upload['file'] = $converted_file;
			$upload['url'] = str_replace( basename( $upload['url'] ), basename( $converted_file ), $upload['url'] );
			$upload['type'] = 'audio/mpeg';
			
			// Log the conversion
			$this->log_conversion( $upload['file'], $settings['debug_mode'] );
		}
		
		return $upload;
	}
	
	/**
	 * Add copy URL button to attachment edit screen
	 * 
	 * @param array $form_fields Array of form fields
	 * @param object $post       Attachment post object
	 * @return array Modified form fields
	 */
	public function add_copy_url_button( $form_fields, $post ) {
		// Get plugin settings
		$settings = Plugin::instance()->get_settings();
		
		// Only add button if clipboard functionality is enabled
		if ( empty( $settings['enable_clipboard'] ) ) {
			return $form_fields;
		}
		
		// Only add for audio files
		if ( strpos( $post->post_mime_type, 'audio/' ) === false ) {
			return $form_fields;
		}
		
		// Get file URL
		$file_url = wp_get_attachment_url( $post->ID );
		
		// Add copy button
		$form_fields['copy_url'] = [
			'label' => __( 'Audio URL', 'jetengine-audio-streaming' ),
			'input' => 'html',
			'html' => '
				<div class="copy-url-field">
					<input type="text" class="widefat" value="' . esc_attr( $file_url ) . '" readonly />
					<button type="button" class="button button-small jet-audio-copy-url" data-url="' . esc_attr( $file_url ) . '">
						' . __( 'Copy', 'jetengine-audio-streaming' ) . '
					</button>
				</div>
				<p class="description">' . __( 'Copy the audio file URL to clipboard', 'jetengine-audio-streaming' ) . '</p>
			'
		];
		
		// Check if this is a WAV file that can be converted
		if ( in_array( $post->post_mime_type, [ 'audio/wav', 'audio/x-wav' ] ) ) {
			// Check if it's already been converted
			$mp3_id = get_post_meta( $post->ID, '_converted_to_mp3', true );
			
			if ( $mp3_id ) {
				$mp3_url = wp_get_attachment_url( $mp3_id );
				
				// Add link to the MP3 version
				$form_fields['mp3_version'] = [
					'label' => __( 'MP3 Version', 'jetengine-audio-streaming' ),
					'input' => 'html',
					'html' => '
						<a href="' . esc_url( admin_url( 'post.php?post=' . $mp3_id . '&action=edit' ) ) . '" class="button">
							' . __( 'View MP3', 'jetengine-audio-streaming' ) . '
						</a>
						<p class="description">' . __( 'This WAV file has been converted to MP3 format', 'jetengine-audio-streaming' ) . '</p>
					'
				];
			} else {
				// Add convert button
				$form_fields['convert_to_mp3'] = [
					'label' => __( 'Convert to MP3', 'jetengine-audio-streaming' ),
					'input' => 'html',
					'html' => '
						<button type="button" class="button jet-audio-convert-to-mp3" data-id="' . esc_attr( $post->ID ) . '">
							' . __( 'Convert Now', 'jetengine-audio-streaming' ) . '
						</button>
						<span class="spinner" style="float: none; margin: 0 0 0 5px;"></span>
						<p class="description">' . __( 'Convert this WAV file to MP3 format for better streaming', 'jetengine-audio-streaming' ) . '</p>
					'
				];
			}
		}
		
		// Add a note if this is an MP3 converted from WAV
		$wav_id = get_post_meta( $post->ID, '_converted_from_wav', true );
		if ( $wav_id ) {
			$form_fields['wav_source'] = [
				'label' => __( 'Source File', 'jetengine-audio-streaming' ),
				'input' => 'html',
				'html' => '
					<a href="' . esc_url( admin_url( 'post.php?post=' . $wav_id . '&action=edit' ) ) . '" class="button">
						' . __( 'View WAV', 'jetengine-audio-streaming' ) . '
					</a>
					<p class="description">' . __( 'This MP3 was converted from a WAV file', 'jetengine-audio-streaming' ) . '</p>
				'
			];
		}
		
		return $form_fields;
	}
	
	/**
	 * Display success or error notice after conversion
	 */
	public function display_conversion_notice() {
		// Check for success message
		$success = get_transient( 'jetengine_audio_conversion_success' );
		if ( $success ) {
			delete_transient( 'jetengine_audio_conversion_success' );
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						__( 'WAV file successfully converted to MP3: %s', 'jetengine-audio-streaming' ),
						'<strong>' . esc_html( $success['filename'] ) . '</strong>'
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $success['mp3_id'] . '&action=edit' ) ); ?>" class="button button-small">
						<?php esc_html_e( 'View MP3', 'jetengine-audio-streaming' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
		
		// Check for error message
		$error = get_transient( 'jetengine_audio_conversion_error' );
		if ( $error ) {
			delete_transient( 'jetengine_audio_conversion_error' );
			?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php 
					printf(
						__( 'Failed to convert WAV file to MP3: %s. Please check if FFmpeg is installed and working correctly.', 'jetengine-audio-streaming' ),
						'<strong>' . esc_html( $error['filename'] ) . '</strong>'
					);
					?>
				</p>
			</div>
			<?php
		}
	}
	
	/**
	 * Process audio uploads through JetEngine forms
	 * 
	 * @param array $files   Uploaded files data
	 * @param array $uploads Raw uploads data
	 * @return array Modified files data
	 */
	public function process_jetengine_uploads( $files, $uploads ) {
		// Get plugin settings
		$settings = Plugin::instance()->get_settings();
		
		// Check if auto-conversion is enabled
		if ( empty( $settings['auto_convert'] ) ) {
			return $files;
		}
		
		foreach ( $files as $field_name => $file_data ) {
			// Check if this is a WAV file
			if ( isset( $file_data['type'] ) && ( $file_data['type'] === 'audio/wav' || $file_data['type'] === 'audio/x-wav' ) ) {
				// Convert WAV to MP3
				$converted_file = $this->convert_wav_to_mp3( $file_data['tmp_name'] );
				
				// If conversion was successful, replace the file data
				if ( $converted_file && file_exists( $converted_file ) ) {
					// Update file info
					$files[$field_name]['tmp_name'] = $converted_file;
					$files[$field_name]['name'] = pathinfo( $file_data['name'], PATHINFO_FILENAME ) . '.mp3';
					$files[$field_name]['type'] = 'audio/mpeg';
					
					// Log the conversion
					$this->log_conversion( $converted_file, $settings['debug_mode'] );
				}
			}
		}
		
		return $files;
	}
	
	/**
	 * Handle manual conversion AJAX request
	 */
	public function handle_manual_conversion() {
		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'jet_audio_convert' ) ) {
			wp_send_json_error( [
				'message' => __( 'Security check failed', 'jetengine-audio-streaming' )
			] );
		}
		
		// Check for attachment ID
		if ( ! isset( $_POST['attachment_id'] ) || empty( $_POST['attachment_id'] ) ) {
			wp_send_json_error( [
				'message' => __( 'No file specified', 'jetengine-audio-streaming' )
			] );
		}
		
		$attachment_id = absint( $_POST['attachment_id'] );
		$attachment = get_post( $attachment_id );
		
		// Check if attachment exists and is an audio file
		if ( ! $attachment || strpos( get_post_mime_type( $attachment ), 'audio/' ) === false ) {
			wp_send_json_error( [
				'message' => __( 'Invalid file or not an audio file', 'jetengine-audio-streaming' )
			] );
		}
		
		// Get file path
		$file_path = get_attached_file( $attachment_id );
		
		if ( ! file_exists( $file_path ) ) {
			wp_send_json_error( [
				'message' => __( 'File not found', 'jetengine-audio-streaming' )
			] );
		}
		
		// Check if it's a WAV file
		$mime_type = get_post_mime_type( $attachment );
		if ( $mime_type !== 'audio/wav' && $mime_type !== 'audio/x-wav' ) {
			wp_send_json_error( [
				'message' => __( 'File is not a WAV file', 'jetengine-audio-streaming' )
			] );
		}
		
		// Check if FFmpeg is available
		if ( ! $this->check_ffmpeg() ) {
			wp_send_json_error( [
				'message' => __( 'FFmpeg is not available on your server. Please contact your hosting provider to install FFmpeg.', 'jetengine-audio-streaming' )
			] );
		}
		
		// Convert the file
		$converted_file = $this->convert_wav_to_mp3( $file_path );
		
		if ( ! $converted_file || ! file_exists( $converted_file ) ) {
			wp_send_json_error( [
				'message' => __( 'Conversion failed', 'jetengine-audio-streaming' )
			] );
		}
		
		// Get the settings
		$settings = Plugin::instance()->get_settings();
		
		// Create a new attachment for the MP3 file
		$filename = basename( $converted_file );
		$filetype = wp_check_filetype( $filename, null );
		$wp_upload_dir = wp_upload_dir();
		
		$attachment_data = [
			'guid'           => $wp_upload_dir['url'] . '/' . $filename,
			'post_mime_type' => $filetype['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $attachment->post_parent // Keep the same parent if any
		];
		
		// Insert the attachment
		$attach_id = wp_insert_attachment( $attachment_data, $converted_file, $attachment->post_parent );
		
		if ( is_wp_error( $attach_id ) ) {
			wp_send_json_error( [
				'message' => $attach_id->get_error_message()
			] );
		}
		
		// Generate metadata
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		$attach_data = wp_generate_attachment_metadata( $attach_id, $converted_file );
		wp_update_attachment_metadata( $attach_id, $attach_data );
		
		// Store the original WAV ID for reference
		update_post_meta( $attach_id, '_converted_from_wav', $attachment_id );
		
		// Store the MP3 ID in the WAV attachment for reference
		update_post_meta( $attachment_id, '_converted_to_mp3', $attach_id );
		
		// Log the conversion
		$this->log_conversion( $converted_file, $settings['debug_mode'] );

		// Generate peaks data for the new MP3 attachment
		/*
		if ( function_exists( '\JetEngine_Audio_Streaming\jetengine_audio_stream_generate_peaks' ) ) {
			Plugin::instance()->log( "Generating peaks for manually converted MP3: ID {$attach_id}" );
			$peaks_result = jetengine_audio_stream_generate_peaks( $attach_id );
			if ( $peaks_result === false ) {
				// Peak generation function handles its own internal logging on failure
				Plugin::instance()->log( "Peak generation failed for manually converted MP3: ID {$attach_id}", [], true ); // Force log this specific failure context
			} else {
				Plugin::instance()->log( "Peak generation successful for manually converted MP3: ID {$attach_id}" );
			}
		} else {
			// This case should ideally not happen if the plugin is loaded correctly, but log it if it does.
			Plugin::instance()->log( "Peak generation function `jetengine_audio_stream_generate_peaks` not found during manual MP3 conversion: ID {$attach_id}", [], true );
		}
		*/

		// Return success with new attachment ID
		wp_send_json_success( [
			'message'        => __( 'File converted successfully', 'jetengine-audio-streaming' ),
			'attachment_id'  => $attach_id,
			'file_url'       => wp_get_attachment_url( $attach_id ),
			'filename'       => $filename
		] );
	}
	
	/**
	 * Convert WAV file to MP3
	 * 
	 * @param string $file_path Path to WAV file
	 * @return string|bool Path to MP3 file or false if conversion failed
	 */
	public function convert_wav_to_mp3( $file_path ) {
		// Check if file exists
		if ( ! file_exists( $file_path ) ) {
			return false;
		}
		
		// Get settings
		$settings = Plugin::instance()->get_settings();
		$bitrate = $settings['mp3_bitrate'];
		$samplerate = $settings['mp3_samplerate'];
		$debug_mode = $settings['debug_mode'];
		
		// Get output path (same directory, but .mp3 extension)
		$output_path = pathinfo( $file_path, PATHINFO_DIRNAME ) . '/' . 
					   pathinfo( $file_path, PATHINFO_FILENAME ) . '.mp3';
		
		// Check if ffmpeg is available
		$ffmpeg_available = $this->check_ffmpeg();
		
		if ( ! $ffmpeg_available ) {
			if ( $debug_mode ) {
				error_log( 'JetEngine Audio Streaming: FFmpeg not available for WAV to MP3 conversion' );
			}
			return false;
		}
		
		// Build ffmpeg command
		$cmd = sprintf(
			'ffmpeg -i %s -vn -ar %d -ac 2 -b:a %dk -f mp3 %s',
			escapeshellarg( $file_path ),
			$samplerate,
			$bitrate,
			escapeshellarg( $output_path )
		);
		
		// Run the command
		@exec( $cmd . ' 2>&1', $output, $return_var );
		
		// Log output if debugging is enabled
		if ( $debug_mode && $output ) {
			error_log( 'JetEngine Audio Streaming: FFmpeg output - ' . print_r( $output, true ) );
		}
		
		// Check if conversion was successful
		if ( $return_var !== 0 || ! file_exists( $output_path ) ) {
			if ( $debug_mode ) {
				error_log( sprintf( 'JetEngine Audio Streaming: FFmpeg conversion failed with code %d', $return_var ) );
			}
			return false;
		}
		
		return $output_path;
	}
	
	/**
	 * Check if FFmpeg is available
	 * 
	 * @return bool True if ffmpeg is available, false otherwise
	 */
	public function check_ffmpeg() {
		// Check if exec function is available
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		
		// Try to detect FFmpeg
		@exec( 'which ffmpeg', $output, $return_var );
		if ( $return_var === 0 && ! empty( $output ) ) {
			return true;
		}
		
		// Alternative check
		@exec( 'ffmpeg -version', $output, $return_var );
		if ( $return_var === 0 ) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * Log conversion for debugging
	 * 
	 * @param string $file_path Path to converted file
	 * @param bool $debug_mode Whether debug mode is enabled
	 */
	public function log_conversion( $file_path, $debug_mode ) {
		if ( ! $debug_mode ) {
			return;
		}
		
		$file_size = filesize( $file_path );
		$formatted_size = size_format( $file_size );
		
		error_log( sprintf( 
			'JetEngine Audio Streaming: Converted WAV to MP3 - %s (%s)',
			basename( $file_path ),
			$formatted_size
		) );
		
		// Log to plugin logs if available
		if ( method_exists( Plugin::instance(), 'log' ) ) {
			Plugin::instance()->log( 
				sprintf( 'Converted WAV to MP3: %s', basename( $file_path ) ),
				[
					'file_size' => $file_size,
					'file_path' => $file_path
				]
			);
		}
	}

	/**
	 * Get the path to the FFmpeg binary.
	 * Assumes FFmpeg is installed via Composer in the WP root's vendor directory.
	 *
	 * @return string|false The path to FFmpeg or false if not found/executable.
	 */
	public static function get_ffmpeg_binary_path() {
		error_log("JetEngine Audio Streaming: get_ffmpeg_binary_path called but is disabled.");
		self::$ffmpeg_path_cache = false; // Ensure cache reflects disabled state
		return false;
	}

	/**
	 * Check if FFmpeg is available on the server.
	 *
	 * @return bool True if FFmpeg is available and executable, false otherwise.
	 */
	public static function is_ffmpeg_available() {
		error_log("JetEngine Audio Streaming: is_ffmpeg_available called but is disabled.");
		return false; // Consistently return false as FFmpeg features are disabled.
	}

	/**
	 * Generate waveform peaks data for an audio attachment using FFmpeg.
	 *
	 * @param int $attachment_id The ID of the attachment.
	 * @param int $num_peaks The number of peaks to generate (default: 256).
	 * @return array|false Array of normalized peak values (0.0 to 1.0) on success, false on failure.
	 */
	public static function generate_peaks( $attachment_id, $num_peaks = 256 ) {
		error_log("JetEngine Audio Streaming: generate_peaks called for attachment ID {$attachment_id} but is disabled.");
		return false;
	}
} 

/**
 * Wrapper function to generate waveform peaks data for an audio attachment.
 *
 * @param int $attachment_id The ID of the attachment.
 * @param int $num_peaks The number of peaks to generate (default: 256).
 * @return array|false Array of normalized peak values (0.0 to 1.0) on success, false on failure.
 */
function jetengine_audio_stream_generate_peaks( $attachment_id, $num_peaks = 256 ) {
	// Ensure the class is loaded. It should be if this file is included correctly.
	if ( ! class_exists('\JetEngine_Audio_Streaming\Audio_Converter') ) {
		 error_log("JetEngine Audio Streaming: Audio_Converter class not found when calling jetengine_audio_stream_generate_peaks.");
		return false;
	}
	return \JetEngine_Audio_Streaming\Audio_Converter::generate_peaks( $attachment_id, $num_peaks );
} 