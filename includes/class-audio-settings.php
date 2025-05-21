<?php
namespace JetEngine_Audio_Streaming;

/**
 * Class for handling audio streaming settings
 */
class Audio_Settings {

	/**
	 * Settings fields
	 *
	 * @var array
	 */
	private $settings = [];

	/**
	 * Constructor
	 */
	public function __construct() {
		// Register hooks
		add_action( 'admin_menu', [ $this, 'register_options_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_jet_audio_streaming_clear_cache', [ $this, 'clear_cache_ajax' ] );
	}

	/**
	 * Register settings page under the main Settings menu
	 */
	public function register_options_page() {
		add_options_page(
			__( 'JetEngine Audio Streaming', 'jetengine-audio-streaming' ), // Page title
			__( 'Audio Streaming', 'jetengine-audio-streaming' ),          // Menu title
			'manage_options',                                            // Capability
			'jetengine-audio-settings',                                  // Menu slug
			[ $this, 'render_settings_page' ]                           // Callback function
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		// Register settings
		register_setting(
			'jet_audio_streaming_settings',
			'je_audio_chunk_size',
			[
				'type'              => 'number',
				'description'       => __( 'Default chunk size in MB', 'jetengine-audio-streaming' ),
				'sanitize_callback' => [ $this, 'sanitize_chunk_size' ],
				'default'           => 1,
			]
		);

		register_setting(
			'jet_audio_streaming_settings',
			'je_audio_debug_mode',
			[
				'type'              => 'boolean',
				'description'       => __( 'Enable debug mode', 'jetengine-audio-streaming' ),
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			]
		);

		register_setting(
			'jet_audio_streaming_settings',
			'je_audio_max_size',
			[
				'type'              => 'number',
				'description'       => __( 'Maximum allowed file size in MB', 'jetengine-audio-streaming' ),
				'sanitize_callback' => 'absint',
				'default'           => 50,
			]
		);
		
		// Register WAV to MP3 conversion settings
		register_setting(
			'jet_audio_streaming_settings',
			'je_audio_mp3_bitrate',
			[
				'type'              => 'number',
				'description'       => __( 'MP3 bitrate in kbps', 'jetengine-audio-streaming' ),
				'sanitize_callback' => [ $this, 'sanitize_bitrate' ],
				'default'           => 128,
			]
		);

		register_setting(
			'jet_audio_streaming_settings',
			'je_audio_mp3_samplerate',
			[
				'type'              => 'number',
				'description'       => __( 'MP3 sampling rate in Hz', 'jetengine-audio-streaming' ),
				'sanitize_callback' => [ $this, 'sanitize_samplerate' ],
				'default'           => 44100,
			]
		);

		register_setting(
			'jet_audio_streaming_settings',
			'je_audio_auto_convert',
			[
				'type'              => 'boolean',
				'description'       => __( 'Enable automatic WAV to MP3 conversion', 'jetengine-audio-streaming' ),
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			]
		);
		
		register_setting(
			'jet_audio_streaming_settings',
			'je_audio_enable_clipboard',
			[
				'type'              => 'boolean',
				'description'       => __( 'Enable URL copying to clipboard', 'jetengine-audio-streaming' ),
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			]
		);

		// Register setting sections
		add_settings_section(
			'jet_audio_streaming_general',
			__( 'General Settings', 'jetengine-audio-streaming' ),
			[ $this, 'render_general_section' ],
			'jet_audio_streaming_settings'
		);

		add_settings_section(
			'jet_audio_streaming_cache',
			__( 'Cache Management', 'jetengine-audio-streaming' ),
			[ $this, 'render_cache_section' ],
			'jet_audio_streaming_settings'
		);
		
		add_settings_section(
			'jet_audio_streaming_conversion',
			__( 'WAV to MP3 Conversion', 'jetengine-audio-streaming' ),
			[ $this, 'render_conversion_section' ],
			'jet_audio_streaming_settings'
		);

		add_settings_section(
			'jet_audio_streaming_debug',
			__( 'Debug Settings', 'jetengine-audio-streaming' ),
			[ $this, 'render_debug_section' ],
			'jet_audio_streaming_settings'
		);

		// Register setting fields
		add_settings_field(
			'chunk_size',
			__( 'Chunk Size (MB)', 'jetengine-audio-streaming' ),
			[ $this, 'render_chunk_size_field' ],
			'jet_audio_streaming_settings',
			'jet_audio_streaming_general'
		);

		add_settings_field(
			'max_file_size',
			__( 'Maximum File Size (MB)', 'jetengine-audio-streaming' ),
			[ $this, 'render_max_file_size_field' ],
			'jet_audio_streaming_settings',
			'jet_audio_streaming_general'
		);
		
		add_settings_field(
			'enable_clipboard',
			__( 'Enable URL Copying', 'jetengine-audio-streaming' ),
			[ $this, 'render_enable_clipboard_field' ],
			'jet_audio_streaming_settings',
			'jet_audio_streaming_general'
		);
		
		// Register conversion settings fields
		add_settings_field(
			'mp3_bitrate',
			__( 'Bitrate (kbps)', 'jetengine-audio-streaming' ),
			[ $this, 'render_mp3_bitrate_field' ],
			'jet_audio_streaming_settings',
			'jet_audio_streaming_conversion'
		);
		
		add_settings_field(
			'mp3_samplerate',
			__( 'Sampling Rate (Hz)', 'jetengine-audio-streaming' ),
			[ $this, 'render_mp3_samplerate_field' ],
			'jet_audio_streaming_settings',
			'jet_audio_streaming_conversion'
		);
		
		add_settings_field(
			'auto_convert',
			__( 'Auto-Conversion', 'jetengine-audio-streaming' ),
			[ $this, 'render_auto_convert_field' ],
			'jet_audio_streaming_settings',
			'jet_audio_streaming_conversion'
		);

		add_settings_field(
			'debug_mode',
			__( 'Debug Mode', 'jetengine-audio-streaming' ),
			[ $this, 'render_debug_mode_field' ],
			'jet_audio_streaming_settings',
			'jet_audio_streaming_debug'
		);

		add_settings_field(
			'clear_cache',
			__( 'Clear Cache', 'jetengine-audio-streaming' ),
			[ $this, 'render_clear_cache_field' ],
			'jet_audio_streaming_settings',
			'jet_audio_streaming_cache'
		);
	}

	/**
	 * Sanitize chunk size value
	 *
	 * @param mixed $value
	 * @return float
	 */
	public function sanitize_chunk_size( $value ) {
		$value = floatval( $value );
		
		// Ensure chunk size is between 0.1 and 10 MB
		if ( $value < 0.1 ) {
			$value = 0.1;
		} elseif ( $value > 10 ) {
			$value = 10;
		}
		
		return $value;
	}

	/**
	 * Sanitize bitrate value
	 *
	 * @param mixed $value
	 * @return int
	 */
	public function sanitize_bitrate( $value ) {
		$value = intval( $value );
		
		// Ensure bitrate is between 64 and 320 kbps
		if ( $value < 64 ) {
			$value = 64;
		} elseif ( $value > 320 ) {
			$value = 320;
		}
		
		return $value;
	}

	/**
	 * Sanitize sample rate value
	 *
	 * @param mixed $value
	 * @return int
	 */
	public function sanitize_samplerate( $value ) {
		$value = intval( $value );
		$allowed_rates = [ 22050, 32000, 44100, 48000 ];
		
		// If not in allowed rates, default to 44100
		if ( ! in_array( $value, $allowed_rates ) ) {
			$value = 44100;
		}
		
		return $value;
	}

	/**
	 * Render general section
	 */
	public function render_general_section() {
		echo '<p>' . esc_html__( 'Configure general audio streaming settings.', 'jetengine-audio-streaming' ) . '</p>';
	}

	/**
	 * Render debug section
	 */
	public function render_debug_section() {
		echo '<p>' . esc_html__( 'Debug settings for troubleshooting.', 'jetengine-audio-streaming' ) . '</p>';
	}

	/**
	 * Render cache section
	 */
	public function render_cache_section() {
		echo '<p>' . esc_html__( 'Manage cached audio chunks.', 'jetengine-audio-streaming' ) . '</p>';
	}

	/**
	 * Render conversion section
	 */
	public function render_conversion_section() {
		echo '<p>' . esc_html__( 'Settings for automatic WAV to MP3 conversion.', 'jetengine-audio-streaming' ) . '</p>';
	}

	/**
	 * Render chunk size field
	 */
	public function render_chunk_size_field() {
		$chunk_size = get_option( 'je_audio_chunk_size', 1 );
		?>
		<input type="number" name="je_audio_chunk_size" min="0.1" max="10" step="0.1" value="<?php echo esc_attr( $chunk_size ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Size of audio chunks for streaming in megabytes. Smaller chunks load faster but require more requests.', 'jetengine-audio-streaming' ); ?></p>
		<?php
	}

	/**
	 * Render max file size field
	 */
	public function render_max_file_size_field() {
		$max_file_size = get_option( 'je_audio_max_size', 50 );
		?>
		<input type="number" name="je_audio_max_size" min="1" max="10240" step="1" value="<?php echo esc_attr( $max_file_size ); ?>" class="regular-text" />
		<p class="description"><?php esc_html_e( 'Maximum allowed audio file size in megabytes.', 'jetengine-audio-streaming' ); ?></p>
		<?php
	}

	/**
	 * Render debug mode field
	 */
	public function render_debug_mode_field() {
		$debug_mode = get_option( 'je_audio_debug_mode', false );
		?>
		<label>
			<input type="checkbox" name="je_audio_debug_mode" value="1" <?php checked( $debug_mode ); ?> />
			<?php esc_html_e( 'Enable debug mode for testing and troubleshooting', 'jetengine-audio-streaming' ); ?>
		</label>
		<?php
	}

	/**
	 * Render clear cache field
	 */
	public function render_clear_cache_field() {
		$nonce = wp_create_nonce( 'jet_audio_streaming_clear_cache' );
		?>
		<button type="button" id="jet-audio-clear-cache" class="button button-secondary" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php esc_html_e( 'Clear Audio Cache', 'jetengine-audio-streaming' ); ?>
		</button>
		<span id="jet-audio-cache-message" style="display: none; margin-left: 10px;"></span>
		<p class="description"><?php esc_html_e( 'Remove all cached audio chunks to refresh content.', 'jetengine-audio-streaming' ); ?></p>
		
		<script>
			jQuery(document).ready(function($) {
				$('#jet-audio-clear-cache').on('click', function() {
					const button = $(this);
					const messageEl = $('#jet-audio-cache-message');
					const nonce = button.data('nonce');
					
					// Disable button and show loading state
					button.prop('disabled', true).text('<?php esc_html_e( 'Clearing...', 'jetengine-audio-streaming' ); ?>');
					messageEl.hide();
					
					// Send AJAX request
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'jet_audio_streaming_clear_cache',
							nonce: nonce
						},
						success: function(response) {
							button.prop('disabled', false).text('<?php esc_html_e( 'Clear Audio Cache', 'jetengine-audio-streaming' ); ?>');
							
							if (response.success) {
								messageEl.text(response.data.message).css('color', 'green').show();
							} else {
								messageEl.text(response.data.message || '<?php esc_html_e( 'An error occurred', 'jetengine-audio-streaming' ); ?>').css('color', 'red').show();
							}
							
							// Hide message after 5 seconds
							setTimeout(function() {
								messageEl.fadeOut();
							}, 5000);
						},
						error: function() {
							button.prop('disabled', false).text('<?php esc_html_e( 'Clear Audio Cache', 'jetengine-audio-streaming' ); ?>');
							messageEl.text('<?php esc_html_e( 'An error occurred while clearing cache', 'jetengine-audio-streaming' ); ?>').css('color', 'red').show();
							
							// Hide message after 5 seconds
							setTimeout(function() {
								messageEl.fadeOut();
							}, 5000);
						}
					});
				});
			});
		</script>
		<?php
	}

	/**
	 * Render MP3 bitrate field
	 */
	public function render_mp3_bitrate_field() {
		$bitrate = get_option( 'je_audio_mp3_bitrate', 128 );
		?>
		<select name="je_audio_mp3_bitrate" class="regular-text">
			<option value="64" <?php selected( $bitrate, 64 ); ?>>64 kbps</option>
			<option value="96" <?php selected( $bitrate, 96 ); ?>>96 kbps</option>
			<option value="128" <?php selected( $bitrate, 128 ); ?>>128 kbps</option>
			<option value="192" <?php selected( $bitrate, 192 ); ?>>192 kbps</option>
			<option value="256" <?php selected( $bitrate, 256 ); ?>>256 kbps</option>
			<option value="320" <?php selected( $bitrate, 320 ); ?>>320 kbps</option>
		</select>
		<p class="description"><?php esc_html_e( 'Bitrate for MP3 conversion. Higher values mean better quality but larger file size.', 'jetengine-audio-streaming' ); ?></p>
		<?php
	}

	/**
	 * Render MP3 sample rate field
	 */
	public function render_mp3_samplerate_field() {
		$samplerate = get_option( 'je_audio_mp3_samplerate', 44100 );
		?>
		<select name="je_audio_mp3_samplerate" class="regular-text">
			<option value="22050" <?php selected( $samplerate, 22050 ); ?>>22.05 kHz</option>
			<option value="32000" <?php selected( $samplerate, 32000 ); ?>>32 kHz</option>
			<option value="44100" <?php selected( $samplerate, 44100 ); ?>>44.1 kHz (CD Quality)</option>
			<option value="48000" <?php selected( $samplerate, 48000 ); ?>>48 kHz</option>
		</select>
		<p class="description"><?php esc_html_e( 'Sampling rate for MP3 conversion. 44.1 kHz is standard CD quality.', 'jetengine-audio-streaming' ); ?></p>
		<?php
	}

	/**
	 * Render auto convert field
	 */
	public function render_auto_convert_field() {
		$auto_convert = get_option( 'je_audio_auto_convert', true );
		?>
		<label>
			<input type="checkbox" name="je_audio_auto_convert" value="1" <?php checked( $auto_convert ); ?> />
			<?php esc_html_e( 'Automatically convert WAV files to MP3 when uploaded', 'jetengine-audio-streaming' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When enabled, WAV files will be automatically converted to MP3 format for better streaming performance.', 'jetengine-audio-streaming' ); ?></p>
		<?php
	}
	
	/**
	 * Render enable clipboard field
	 */
	public function render_enable_clipboard_field() {
		$enable_clipboard = get_option( 'je_audio_enable_clipboard', true );
		?>
		<label>
			<input type="checkbox" name="je_audio_enable_clipboard" value="1" <?php checked( $enable_clipboard ); ?> />
			<?php esc_html_e( 'Enable copying audio URLs to clipboard', 'jetengine-audio-streaming' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Adds a "Copy URL" button next to audio players for easy sharing.', 'jetengine-audio-streaming' ); ?></p>
		<?php
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		
		// Get recent logs if we have the logs component
		$recent_logs = [];
		$network_stats = [];
		
		if ( Plugin::instance()->get_logs() ) {
			$recent_logs = Plugin::instance()->get_logs()->get_recent_logs( 10 );
			$network_stats = Plugin::instance()->get_logs()->get_network_stats( 5 );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			
			<form action="options.php" method="post">
				<?php
				// Output security fields
				settings_fields( 'jet_audio_streaming_settings' );
				
				// Output setting sections and fields
				do_settings_sections( 'jet_audio_streaming_settings' );
				
				// Output save settings button
				submit_button( __( 'Save Settings', 'jetengine-audio-streaming' ) );
				?>
			</form>
			
			<?php if ( get_option( 'je_audio_debug_mode', false ) ) : ?>
				<!-- Network Status Indicator -->
				<div class="card">
					<h2><?php esc_html_e( 'Network Status', 'jetengine-audio-streaming' ); ?></h2>
					
					<?php if ( ! empty( $network_stats ) && $network_stats['samples'] > 0 ) : 
						$avg_duration = round( $network_stats['avg_duration'] );
						$status_class = $avg_duration > 2000 ? 'notice-warning' : 'notice-success';
					?>
						<div class="notice <?php echo esc_attr( $status_class ); ?> inline">
							<p>
								<strong><?php esc_html_e( 'Average Download Time', 'jetengine-audio-streaming' ); ?>:</strong>
								<?php echo esc_html( $avg_duration ); ?> ms
								<span class="description">
									<?php echo sprintf( __( 'Based on the last %d chunks', 'jetengine-audio-streaming' ), $network_stats['samples'] ); ?>
								</span>
							</p>
							
							<?php if ( $avg_duration > 2000 ) : ?>
								<p>
									<?php esc_html_e( 'Warning: Average download time exceeds 2 seconds. Consider reducing your chunk size for better performance.', 'jetengine-audio-streaming' ); ?>
								</p>
							<?php endif; ?>
							
							<p>
								<strong><?php esc_html_e( 'Min Duration', 'jetengine-audio-streaming' ); ?>:</strong> 
								<?php echo esc_html( round( $network_stats['min_duration'] ) ); ?> ms
								|
								<strong><?php esc_html_e( 'Max Duration', 'jetengine-audio-streaming' ); ?>:</strong> 
								<?php echo esc_html( round( $network_stats['max_duration'] ) ); ?> ms
							</p>
						</div>
					<?php else : ?>
						<div class="notice notice-info inline">
							<p>
								<?php esc_html_e( 'No download metrics available yet. Statistics will appear after audio chunks have been streamed.', 'jetengine-audio-streaming' ); ?>
							</p>
						</div>
					<?php endif; ?>
				</div>
				
				<!-- Debug Log -->
				<div class="card">
					<h2><?php esc_html_e( 'Debug Log', 'jetengine-audio-streaming' ); ?></h2>
					
					<?php if ( ! empty( $recent_logs ) ) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Time', 'jetengine-audio-streaming' ); ?></th>
									<th><?php esc_html_e( 'File ID', 'jetengine-audio-streaming' ); ?></th>
									<th><?php esc_html_e( 'Chunk', 'jetengine-audio-streaming' ); ?></th>
									<th><?php esc_html_e( 'Byte Range', 'jetengine-audio-streaming' ); ?></th>
									<th><?php esc_html_e( 'Status', 'jetengine-audio-streaming' ); ?></th>
									<th><?php esc_html_e( 'Cache', 'jetengine-audio-streaming' ); ?></th>
									<th><?php esc_html_e( 'Duration', 'jetengine-audio-streaming' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $recent_logs as $log ) : ?>
									<tr>
										<td>
											<?php echo esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $log['log_time'] ) ) ); ?>
										</td>
										<td>
											<?php echo esc_html( $log['file_id'] ); ?>
										</td>
										<td>
											<?php echo esc_html( $log['chunk_index'] ); ?>
										</td>
										<td>
											<?php if ( $log['byte_start'] || $log['byte_end'] ) : ?>
												<?php echo esc_html( size_format( $log['byte_start'] ) . ' - ' . size_format( $log['byte_end'] ) ); ?>
											<?php else : ?>
												-
											<?php endif; ?>
										</td>
										<td>
											<?php 
											$status_class = '';
											
											if ( $log['status_code'] >= 400 ) {
												$status_class = 'error';
											} elseif ( $log['status_code'] >= 300 ) {
												$status_class = 'warning';
											} elseif ( $log['status_code'] >= 200 ) {
												$status_class = 'success';
											}
											?>
											<span class="status-<?php echo esc_attr( $status_class ); ?>">
												<?php echo esc_html( $log['status_code'] ); ?>
											</span>
										</td>
										<td>
											<?php echo esc_html( $log['cache_status'] ?: '-' ); ?>
										</td>
										<td>
											<?php 
											if ( $log['duration'] ) {
												$duration_class = '';
												if ( $log['duration'] > 2000 ) {
													$duration_class = 'warning';
												} elseif ( $log['duration'] > 1000 ) {
													$duration_class = 'notice';
												}
												
												echo '<span class="duration-' . esc_attr( $duration_class ) . '">';
												echo esc_html( $log['duration'] . ' ms' );
												echo '</span>';
											} else {
												echo '-';
											}
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<div class="notice notice-info inline">
							<p>
								<?php esc_html_e( 'No logs available yet. Logs will appear after audio chunks have been streamed.', 'jetengine-audio-streaming' ); ?>
							</p>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		
		<!-- Add some CSS for our logs table and status indicators -->
		<style>
			.status-error { color: #d63638; font-weight: bold; }
			.status-warning { color: #dba617; font-weight: bold; }
			.status-success { color: #00a32a; font-weight: bold; }
			
			.duration-warning { color: #d63638; font-weight: bold; }
			.duration-notice { color: #dba617; }
			
			.card {
				margin-top: 20px;
				padding: 0 20px 20px;
				background: #fff;
				border: 1px solid #e5e5e5;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
			}
			
			.card h2 {
				margin: 1em 0;
				padding: 0;
				font-size: 1.5em;
			}
			
			.description {
				color: #757575;
				font-style: italic;
				margin-left: 10px;
			}
		</style>
		<?php
	}

	/**
	 * AJAX handler for clearing cache
	 */
	public function clear_cache_ajax() {
		// Check for nonce and permissions
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'jet_audio_streaming_clear_cache' ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid security token', 'jetengine-audio-streaming' ) ] );
		}
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'jetengine-audio-streaming' ) ] );
		}
		
		// Clear cache by using the logs instance if available
		$count = 0;
		if ( Plugin::instance()->get_logs() ) {
			$count = Plugin::instance()->get_logs()->clear_cache();
		} else {
			// Fallback method if logs instance is not available
			global $wpdb;
			
			// Find all cache keys with jet_audio_chunk prefix
			$cache_keys = $wpdb->get_col(
				"SELECT option_name FROM {$wpdb->options} 
				WHERE option_name LIKE '_transient_jet_audio_chunk_%' 
				OR option_name LIKE '_transient_timeout_jet_audio_chunk_%'"
			);
			
			// Delete each transient
			foreach ( $cache_keys as $key ) {
				// Extract the base key name (without _transient_ or _transient_timeout_ prefix)
				if ( strpos( $key, '_transient_timeout_' ) === 0 ) {
					$transient_name = substr( $key, strlen( '_transient_timeout_' ) );
				} else {
					$transient_name = substr( $key, strlen( '_transient_' ) );
				}
				
				// Delete the transient
				if ( delete_transient( $transient_name ) ) {
					$count++;
				}
			}
			
			// Log the cache clearing
			Plugin::instance()->log(
				sprintf( 'Cleared %d cached audio chunks', $count ),
				[ 'source' => 'admin' ],
				true
			);
		}
		
		// Send response
		wp_send_json_success( [
			'message' => sprintf( 
				__( 'Successfully cleared %d cached audio chunks', 'jetengine-audio-streaming' ), 
				$count 
			),
			'count' => $count,
		] );
	}

	/**
	 * Get settings
	 * 
	 * @return array
	 */
	public function get_settings() {
		if ( empty( $this->settings ) ) {
			$default_settings = [
				'chunk_size'        => 1,     // Default chunk size in MB
				'max_file_size'     => 50,    // Maximum allowed file size in MB
				'debug_mode'        => false, // Debug mode toggled off by default
				'mp3_bitrate'       => 128,   // Default MP3 bitrate
				'mp3_samplerate'    => 44100, // Default MP3 sample rate
				'auto_convert'      => true,  // Auto WAV to MP3 conversion enabled by default
				'enable_clipboard'  => true,  // URL copying to clipboard enabled by default
			];
			
			// Get individual settings using the specified option keys
			$chunk_size = get_option( 'je_audio_chunk_size', $default_settings['chunk_size'] );
			$max_file_size = get_option( 'je_audio_max_size', $default_settings['max_file_size'] );
			$debug_mode = get_option( 'je_audio_debug_mode', $default_settings['debug_mode'] );
			$mp3_bitrate = get_option( 'je_audio_mp3_bitrate', $default_settings['mp3_bitrate'] );
			$mp3_samplerate = get_option( 'je_audio_mp3_samplerate', $default_settings['mp3_samplerate'] );
			$auto_convert = get_option( 'je_audio_auto_convert', $default_settings['auto_convert'] );
			$enable_clipboard = get_option( 'je_audio_enable_clipboard', $default_settings['enable_clipboard'] );
			
			$this->settings = [
				'chunk_size'        => $chunk_size,
				'max_file_size'     => $max_file_size,
				'debug_mode'        => $debug_mode,
				'mp3_bitrate'       => $mp3_bitrate,
				'mp3_samplerate'    => $mp3_samplerate,
				'auto_convert'      => $auto_convert,
				'enable_clipboard'  => $enable_clipboard,
			];
		}
		
		return $this->settings;
	}
} 