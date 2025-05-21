<?php
namespace JetEngine_Audio_Stream;

/**
 * Class for handling plugin settings
 */
class Admin_Settings {

	/**
	 * Settings keys
	 */
	const SETTINGS_GROUP = 'jetengine_audio_stream_settings';
	const BUFFER_SIZE_OPTION = 'je_audio_buffer_size';
	const BITRATE_OPTION = 'je_audio_bitrate';
	const PRELOAD_DURATION_OPTION = 'je_audio_preload_duration';
	const DEBUG_OPTION = 'je_audio_debug_mode';
	const MAX_SIZE_OPTION = 'je_audio_max_size';
	const ENABLE_CLIPBOARD_OPTION = 'je_audio_enable_clipboard';
	const DISABLE_WAVEFORM_OPTION = 'je_audio_disable_waveform';

	/**
	 * Constructor
	 */
	public function __construct() {
		// Register hooks
		add_action( 'admin_menu', [ $this, 'register_options_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_je_audio_clear_cache', [ $this, 'clear_cache_ajax' ] );
	}

	/**
	 * Initialize the settings class
	 */
	public static function init() {
		$instance = new self();
		return $instance;
	}

	/**
	 * Register options page
	 */
	public function register_options_page() {
		add_options_page(
			__( 'JetEngine Audio Stream', 'jetengine-audio-stream' ), // Page title
			__( 'Audio Stream', 'jetengine-audio-stream' ),          // Menu title
			'manage_options',                                       // Capability
			'jetengine-audio-settings',                             // Menu slug
			[ $this, 'render_settings_page' ]                      // Callback function
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		// Register settings
		register_setting(
			self::SETTINGS_GROUP,
			self::BUFFER_SIZE_OPTION,
			[
				'type'              => 'number',
				'description'       => __( 'Buffer size in KB', 'jetengine-audio-stream' ),
				'sanitize_callback' => [ $this, 'sanitize_buffer_size' ],
				'default'           => 64,
			]
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::BITRATE_OPTION,
			[
				'type'              => 'number',
				'description'       => __( 'Default bitrate for streaming (kbps)', 'jetengine-audio-stream' ),
				'sanitize_callback' => [ $this, 'sanitize_bitrate' ],
				'default'           => 128,
			]
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::PRELOAD_DURATION_OPTION,
			[
				'type'              => 'number',
				'description'       => __( 'Default preload duration in seconds', 'jetengine-audio-stream' ),
				'sanitize_callback' => 'absint',
				'default'           => 30,
			]
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::DEBUG_OPTION,
			[
				'type'              => 'boolean',
				'description'       => __( 'Enable debug mode', 'jetengine-audio-stream' ),
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			]
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::MAX_SIZE_OPTION,
			[
				'type'              => 'number',
				'description'       => __( 'Maximum allowed file size in MB', 'jetengine-audio-stream' ),
				'sanitize_callback' => 'absint',
				'default'           => 50,
			]
		);
		
		register_setting(
			self::SETTINGS_GROUP,
			self::ENABLE_CLIPBOARD_OPTION,
			[
				'type'              => 'boolean',
				'description'       => __( 'Enable URL copying to clipboard', 'jetengine-audio-stream' ),
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			]
		);
		
		register_setting(
			self::SETTINGS_GROUP,
			self::DISABLE_WAVEFORM_OPTION,
			[
				'type'              => 'boolean',
				'description'       => __( 'Disable waveform visualization', 'jetengine-audio-stream' ),
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			]
		);

		// Register setting sections
		add_settings_section(
			'je_audio_stream_general',
			__( 'General Settings', 'jetengine-audio-stream' ),
			[ $this, 'render_general_section' ],
			self::SETTINGS_GROUP
		);

		add_settings_section(
			'je_audio_stream_streaming',
			__( 'Streaming Settings', 'jetengine-audio-stream' ),
			[ $this, 'render_streaming_section' ],
			self::SETTINGS_GROUP
		);

		add_settings_section(
			'je_audio_stream_cache',
			__( 'Cache Management', 'jetengine-audio-stream' ),
			[ $this, 'render_cache_section' ],
			self::SETTINGS_GROUP
		);

		add_settings_section(
			'je_audio_stream_debug',
			__( 'Debug Settings', 'jetengine-audio-stream' ),
			[ $this, 'render_debug_section' ],
			self::SETTINGS_GROUP
		);

		// Register setting fields - General section
		add_settings_field(
			'max_file_size',
			__( 'Maximum File Size (MB)', 'jetengine-audio-stream' ),
			[ $this, 'render_max_file_size_field' ],
			self::SETTINGS_GROUP,
			'je_audio_stream_general'
		);
		
		add_settings_field(
			'enable_clipboard',
			__( 'Enable URL Copying', 'jetengine-audio-stream' ),
			[ $this, 'render_enable_clipboard_field' ],
			self::SETTINGS_GROUP,
			'je_audio_stream_general'
		);
		
		add_settings_field(
			'disable_waveform',
			__( 'Disable Waveform', 'jetengine-audio-stream' ),
			[ $this, 'render_disable_waveform_field' ],
			self::SETTINGS_GROUP,
			'je_audio_stream_general'
		);

		// Register setting fields - Streaming section
		add_settings_field(
			'buffer_size',
			__( 'Buffer Size (KB)', 'jetengine-audio-stream' ),
			[ $this, 'render_buffer_size_field' ],
			self::SETTINGS_GROUP,
			'je_audio_stream_streaming'
		);

		add_settings_field(
			'bitrate',
			__( 'Default Bitrate (kbps)', 'jetengine-audio-stream' ),
			[ $this, 'render_bitrate_field' ],
			self::SETTINGS_GROUP,
			'je_audio_stream_streaming'
		);

		add_settings_field(
			'preload_duration',
			__( 'Preload Duration (seconds)', 'jetengine-audio-stream' ),
			[ $this, 'render_preload_duration_field' ],
			self::SETTINGS_GROUP,
			'je_audio_stream_streaming'
		);

		// Register setting fields - Debug section
		add_settings_field(
			'debug_mode',
			__( 'Debug Mode', 'jetengine-audio-stream' ),
			[ $this, 'render_debug_mode_field' ],
			self::SETTINGS_GROUP,
			'je_audio_stream_debug'
		);

		// Register setting fields - Cache section
		add_settings_field(
			'clear_cache',
			__( 'Clear Cache', 'jetengine-audio-stream' ),
			[ $this, 'render_clear_cache_field' ],
			self::SETTINGS_GROUP,
			'je_audio_stream_cache'
		);
	}

	/**
	 * Sanitize buffer size
	 *
	 * @param int $value The input value
	 * @return int Sanitized value
	 */
	public function sanitize_buffer_size( $value ) {
		$value = absint( $value );
		
		if ( $value < 8 ) {
			$value = 8; // Minimum 8 KB
		} elseif ( $value > 1024 ) {
			$value = 1024; // Maximum 1024 KB (1 MB)
		}
		
		return $value;
	}

	/**
	 * Sanitize bitrate
	 *
	 * @param int $value The input value
	 * @return int Sanitized value
	 */
	public function sanitize_bitrate( $value ) {
		$value = absint( $value );
		
		if ( $value < 64 ) {
			$value = 64; // Minimum 64 kbps
		} elseif ( $value > 320 ) {
			$value = 320; // Maximum 320 kbps
		}
		
		return $value;
	}

	/**
	 * Render general section description
	 */
	public function render_general_section() {
		echo '<p>' . esc_html__( 'Configure general audio streaming settings.', 'jetengine-audio-stream' ) . '</p>';
	}

	/**
	 * Render streaming section description
	 */
	public function render_streaming_section() {
		echo '<p>' . esc_html__( 'Configure audio streaming performance settings.', 'jetengine-audio-stream' ) . '</p>';
	}

	/**
	 * Render debug section description
	 */
	public function render_debug_section() {
		echo '<p>' . esc_html__( 'Configure debug settings for troubleshooting.', 'jetengine-audio-stream' ) . '</p>';
	}

	/**
	 * Render cache section description
	 */
	public function render_cache_section() {
		echo '<p>' . esc_html__( 'Manage audio streaming cache.', 'jetengine-audio-stream' ) . '</p>';
	}

	/**
	 * Render buffer size field
	 */
	public function render_buffer_size_field() {
		$value = get_option( self::BUFFER_SIZE_OPTION, 64 );
		?>
		<input type="number" name="<?php echo esc_attr( self::BUFFER_SIZE_OPTION ); ?>" value="<?php echo esc_attr( $value ); ?>" min="8" max="1024" step="8" />
		<p class="description">
			<?php esc_html_e( 'Size of each buffer chunk for audio streaming in kilobytes (KB). Larger values may improve playback but increase memory usage.', 'jetengine-audio-stream' ); ?>
		</p>
		<?php
	}

	/**
	 * Render bitrate field
	 */
	public function render_bitrate_field() {
		$value = get_option( self::BITRATE_OPTION, 128 );
		?>
		<input type="number" name="<?php echo esc_attr( self::BITRATE_OPTION ); ?>" value="<?php echo esc_attr( $value ); ?>" min="64" max="320" step="8" />
		<p class="description">
			<?php esc_html_e( 'Default streaming bitrate in kilobits per second (kbps). Higher values improve quality but increase bandwidth usage.', 'jetengine-audio-stream' ); ?>
		</p>
		<?php
	}

	/**
	 * Render preload duration field
	 */
	public function render_preload_duration_field() {
		$value = get_option( self::PRELOAD_DURATION_OPTION, 30 );
		?>
		<input type="number" name="<?php echo esc_attr( self::PRELOAD_DURATION_OPTION ); ?>" value="<?php echo esc_attr( $value ); ?>" min="5" max="120" step="5" />
		<p class="description">
			<?php esc_html_e( 'Amount of audio (in seconds) to preload before playback. Higher values reduce buffering but increase initial load time.', 'jetengine-audio-stream' ); ?>
		</p>
		<?php
	}

	/**
	 * Render max file size field
	 */
	public function render_max_file_size_field() {
		$value = get_option( self::MAX_SIZE_OPTION, 50 );
		?>
		<input type="number" name="<?php echo esc_attr( self::MAX_SIZE_OPTION ); ?>" value="<?php echo esc_attr( $value ); ?>" min="1" max="500" step="1" />
		<p class="description">
			<?php esc_html_e( 'Maximum allowed file size in megabytes (MB) for audio uploads.', 'jetengine-audio-stream' ); ?>
		</p>
		<?php
	}

	/**
	 * Render debug mode field
	 */
	public function render_debug_mode_field() {
		$value = get_option( self::DEBUG_OPTION, false );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::DEBUG_OPTION ); ?>" value="1" <?php checked( $value, true ); ?> />
			<?php esc_html_e( 'Enable debug mode (logs additional information to browser console)', 'jetengine-audio-stream' ); ?>
		</label>
		<?php
	}
	
	/**
	 * Render enable clipboard field
	 */
	public function render_enable_clipboard_field() {
		$value = get_option( self::ENABLE_CLIPBOARD_OPTION, true );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::ENABLE_CLIPBOARD_OPTION ); ?>" value="1" <?php checked( $value, true ); ?> />
			<?php esc_html_e( 'Show button to copy audio URL to clipboard', 'jetengine-audio-stream' ); ?>
		</label>
		<?php
	}
	
	/**
	 * Render disable waveform field
	 */
	public function render_disable_waveform_field() {
		$value = get_option( self::DISABLE_WAVEFORM_OPTION, false );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::DISABLE_WAVEFORM_OPTION ); ?>" value="1" <?php checked( $value, true ); ?> />
			<?php esc_html_e( 'Disable waveform visualization (may improve performance)', 'jetengine-audio-stream' ); ?>
		</label>
		<?php
	}

	/**
	 * Render clear cache field
	 */
	public function render_clear_cache_field() {
		$nonce = wp_create_nonce( 'je_audio_clear_cache_nonce' );
		?>
		<button type="button" id="je-audio-clear-cache" class="button button-secondary" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php esc_html_e( 'Clear Audio Cache', 'jetengine-audio-stream' ); ?>
		</button>
		<span id="je-audio-clear-cache-status" style="margin-left: 10px; display: none;"></span>
		<p class="description">
			<?php esc_html_e( 'Clears cached audio data, including waveform data.', 'jetengine-audio-stream' ); ?>
		</p>
		<script>
			jQuery(document).ready(function($) {
				$('#je-audio-clear-cache').on('click', function() {
					var button = $(this);
					var status = $('#je-audio-clear-cache-status');
					
					button.attr('disabled', true);
					status.text('<?php esc_html_e( 'Clearing cache...', 'jetengine-audio-stream' ); ?>').show();
					
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'je_audio_clear_cache',
							nonce: button.data('nonce')
						},
						success: function(response) {
							if (response.success) {
								status.text('<?php esc_html_e( 'Cache cleared successfully!', 'jetengine-audio-stream' ); ?>');
							} else {
								status.text('<?php esc_html_e( 'Error: ', 'jetengine-audio-stream' ); ?>' + response.data.message);
							}
						},
						error: function() {
							status.text('<?php esc_html_e( 'An error occurred while clearing the cache.', 'jetengine-audio-stream' ); ?>');
						},
						complete: function() {
							button.attr('disabled', false);
							setTimeout(function() {
								status.fadeOut();
							}, 3000);
						}
					});
				});
			});
		</script>
		<?php
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::SETTINGS_GROUP );
				submit_button();
				?>
			</form>
			
			<div class="je-audio-info-box" style="margin-top: 30px; background: #fff; padding: 15px; border-left: 4px solid #2271b1; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<h3><?php esc_html_e( 'Available Shortcodes', 'jetengine-audio-stream' ); ?></h3>
				<p><code>[jetengine_audio_player id="123"]</code> - <?php esc_html_e( 'Display audio player for attachment with ID 123', 'jetengine-audio-stream' ); ?></p>
				
				<h3><?php esc_html_e( 'JetEngine Integration', 'jetengine-audio-stream' ); ?></h3>
				<p><?php esc_html_e( 'Two macros are available for use in JetEngine:', 'jetengine-audio-stream' ); ?></p>
				<ul style="list-style-type: disc; padding-left: 20px;">
					<li><code>%audio_duration|attachment_id%</code> - <?php esc_html_e( 'Displays the audio duration', 'jetengine-audio-stream' ); ?></li>
					<li><code>%audio_player|attachment_id%</code> - <?php esc_html_e( 'Renders the audio player', 'jetengine-audio-stream' ); ?></li>
				</ul>
				
				<h3><?php esc_html_e( 'REST API Endpoints', 'jetengine-audio-stream' ); ?></h3>
				<ul style="list-style-type: disc; padding-left: 20px;">
					<li><code>/wp-json/jetengine-audio-stream/v1/play/{id}</code> - <?php esc_html_e( 'Stream audio for attachment with ID', 'jetengine-audio-stream' ); ?></li>
					<li><code>/wp-json/jetengine-audio-stream/v1/peaks/{id}</code> - <?php esc_html_e( 'Get waveform data for attachment with ID', 'jetengine-audio-stream' ); ?></li>
					<li><code>/wp-json/jetengine-audio-stream/v1/metadata/{id}</code> - <?php esc_html_e( 'Get audio metadata for attachment with ID', 'jetengine-audio-stream' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Clear cached audio chunks
	 * 
	 * @return int Number of cleared items
	 */
	public function clear_audio_chunks() {
		// Get chunk count from endpoint class
		$count = JetEngine_Audio_Endpoints::clear_cached_chunks();
		
		// Add an admin notice
		add_settings_error(
			self::SETTINGS_GROUP,
			'cache_cleared',
			sprintf(
				_n(
					'%d cached audio chunk cleared successfully.',
					'%d cached audio chunks cleared successfully.',
					$count,
					'jetengine-audio-stream'
				),
				$count
			),
			'success'
		);
		
		return $count;
	}

	/**
	 * AJAX handler for clearing cache
	 */
	public function clear_cache_ajax() {
		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission denied', 'jetengine-audio-stream')]);
		}
		
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'je_audio_clear_cache')) {
			wp_send_json_error(['message' => __('Invalid security token', 'jetengine-audio-stream')]);
		}
		
		// Clear audio chunks
		$chunks_count = $this->clear_audio_chunks();
		
		// Clear waveform cache (if applicable)
		$waveform_count = 0;
		if (class_exists('Audio_Stream_Handler') && method_exists('Audio_Stream_Handler', 'clear_waveform_cache')) {
			$waveform_count = Audio_Stream_Handler::clear_waveform_cache();
		}
		
		// Send success response
		wp_send_json_success([
			'message' => sprintf(
				__('Cache cleared: %d audio chunks, %d waveform data', 'jetengine-audio-stream'),
				$chunks_count,
				$waveform_count
			)
		]);
	}

	/**
	 * Get plugin settings
	 *
	 * @return array Settings array
	 */
	public function get_settings() {
		return [
			'buffer_size'      => intval( get_option( self::BUFFER_SIZE_OPTION, 64 ) ) * 1024, // Convert KB to bytes
			'bitrate'          => intval( get_option( self::BITRATE_OPTION, 128 ) ),
			'preload_duration' => intval( get_option( self::PRELOAD_DURATION_OPTION, 30 ) ),
			'debug_mode'       => (bool) get_option( self::DEBUG_OPTION, false ),
			'max_size'         => intval( get_option( self::MAX_SIZE_OPTION, 50 ) ) * 1024 * 1024, // Convert MB to bytes
			'enable_clipboard' => (bool) get_option( self::ENABLE_CLIPBOARD_OPTION, true ),
			'disable_waveform' => (bool) get_option( self::DISABLE_WAVEFORM_OPTION, false ),
		];
	}
} 