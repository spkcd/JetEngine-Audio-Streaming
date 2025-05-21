<?php
namespace JetEngine_Audio_Streaming;

/**
 * Main plugin class
 */
class Plugin {

	/**
	 * Instance
	 *
	 * @var Plugin
	 */
	private static $instance = null;

	/**
	 * Plugin settings
	 * 
	 * @var array
	 */
	private $settings = [];

	/**
	 * Logs instance
	 * 
	 * @var Audio_Logs
	 */
	private $logs = null;

	/**
	 * Settings page instance
	 * 
	 * @var Audio_Settings
	 */
	private $settings_page = null;

	/**
	 * Instance.
	 *
	 * Ensures only one instance of the plugin class is loaded or can be loaded.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_components();
		$this->init_hooks();
	}

	/**
	 * Initialize components
	 */
	private function init_components() {
		require_once JETENGINE_AUDIO_STREAMING_PATH . 'includes/class-audio-endpoint.php';
		require_once JETENGINE_AUDIO_STREAMING_PATH . 'includes/class-rest-api.php';
		require_once JETENGINE_AUDIO_STREAMING_PATH . 'includes/class-listing-integration.php';
		require_once JETENGINE_AUDIO_STREAMING_PATH . 'includes/class-audio-logs.php';
		require_once JETENGINE_AUDIO_STREAMING_PATH . 'includes/class-audio-settings.php';
		require_once JETENGINE_AUDIO_STREAMING_PATH . 'includes/class-audio-converter.php';
		
		new Audio_Endpoint();
		new REST_API();
		new Listing_Integration();
		$this->logs = new Audio_Logs();
		$this->settings_page = new Audio_Settings();
		new Audio_Converter();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Register player assets
		add_action( 'wp_enqueue_scripts', [ $this, 'register_frontend_assets' ] );
		
		// Add admin notices
		add_action( 'admin_notices', [ $this, 'check_requirements' ] );
		
		// Register AJAX logging endpoint
		add_action( 'wp_ajax_jet_audio_streaming_log', [ $this, 'handle_log_request' ] );
		add_action( 'wp_ajax_nopriv_jet_audio_streaming_log', [ $this, 'handle_log_request' ] );
		
		// Generate peaks data when attachment metadata is generated (on upload/update)
		// add_filter( 'wp_generate_attachment_metadata', [ $this, 'maybe_generate_audio_peaks' ], 10, 2 );
	}

	/**
	 * Check plugin requirements
	 */
	public function check_requirements() {
		// Only show on plugin pages
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->base, 'jet-engine' ) === false ) {
			return;
		}

		// Check if exec() function is available (as a general check)
		$exec_function_disabled = !function_exists('exec') || in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ) );
		
		if ( $exec_function_disabled ) {
			$this->add_admin_notice(
				__( 'Warning: The PHP exec() function is disabled on your server. Some advanced features of JetEngine Audio Streaming may not work properly.', 'jetengine-audio-streaming' ),
				'warning'
			);
		}
		
		/*
		// Check for FFmpeg 
		// This section is now commented out to revert to a pre-soundwave improvement state.
		if ( ! $exec_function_disabled ) { // Assuming $exec_disabled was meant to be $exec_function_disabled from the prior revert
			$ffmpeg_available = false;
			
			// Try to detect FFmpeg
			@exec( 'which ffmpeg', $output, $return_var );
			if ( $return_var === 0 && ! empty( $output ) ) {
				$ffmpeg_available = true;
			} else {
				@exec( 'ffmpeg -version', $output, $return_var );
				if ( $return_var === 0 ) {
					$ffmpeg_available = true;
				}
			}
			
			if ( ! $ffmpeg_available ) {
				$this->add_admin_notice(
					__( 'FFmpeg is not available on your server. Advanced audio processing features will not be available. Please contact your hosting provider to install FFmpeg.', 'jetengine-audio-streaming' ),
					'warning'
				);
			}
		}
		*/
	}

	/**
	 * Add admin notice
	 * 
	 * @param string $message Message to display
	 * @param string $type    Notice type (error, warning, success, info)
	 */
	private function add_admin_notice( $message, $type = 'error' ) {
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo wp_kses_post( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Register settings tab in JetEngine dashboard
	 */
	public function register_settings_tab( $tabs ) {
		// Check if $tabs is an array, if not initialize it as an empty array
		if ( !is_array( $tabs ) ) {
			$tabs = array();
		}
		
		$tabs['audio-streaming'] = [
			'label'  => esc_html__( 'Audio Streaming', 'jetengine-audio-streaming' ),
			'cb'     => [ $this, 'render_settings_page' ],
			'notice' => false,
		];
		
		return $tabs;
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		$default_settings = [
			'chunk_size'   => 1, // Default chunk size in MB
			'max_file_size' => 50, // Maximum allowed file size in MB
			'debug_mode'   => false, // Debug mode toggled off by default
		];
		
		$this->settings = $this->get_settings();
		
		if ( isset( $_POST['jet_audio_streaming_settings'] ) ) {
			$this->update_settings();
		}
		
		// Get recent logs if we have the logs component
		$recent_logs = [];
		$network_stats = [];
		
		if ( $this->get_logs() ) {
			$recent_logs = $this->get_logs()->get_recent_logs( 10 );
			$network_stats = $this->get_logs()->get_network_stats( 5 );
		}
		
		?>
		<form id="jet-engine-audio-streaming-form" method="POST" action="">
			<div class="cx-vui-panel">
				<cx-vui-component-wrapper
					label="<?php esc_html_e( 'Default Chunk Size (MB)', 'jetengine-audio-streaming' ); ?>"
					description="<?php esc_html_e( 'Size of audio chunks for streaming in megabytes', 'jetengine-audio-streaming' ); ?>"
				>
					<cx-vui-input
						name="chunk_size"
						:wrapper-css="[ 'equalwidth' ]"
						size="fullwidth"
						type="number"
						:min="0.1"
						:max="10"
						:step="0.1"
						v-model="settings.chunk_size"
					></cx-vui-input>
				</cx-vui-component-wrapper>
				
				<cx-vui-component-wrapper
					label="<?php esc_html_e( 'Maximum File Size (MB)', 'jetengine-audio-streaming' ); ?>"
					description="<?php esc_html_e( 'Maximum allowed audio file size in megabytes', 'jetengine-audio-streaming' ); ?>"
				>
					<cx-vui-input
						name="max_file_size"
						:wrapper-css="[ 'equalwidth' ]"
						size="fullwidth"
						type="number"
						:min="1"
						:max="10240"
						:step="1"
						v-model="settings.max_file_size"
					></cx-vui-input>
				</cx-vui-component-wrapper>
				
				<cx-vui-component-wrapper
					label="<?php esc_html_e( 'Debug Mode', 'jetengine-audio-streaming' ); ?>"
					description="<?php esc_html_e( 'Enable debug mode for testing', 'jetengine-audio-streaming' ); ?>"
				>
					<cx-vui-switcher
						name="debug_mode"
						:wrapper-css="[ 'equalwidth' ]"
						v-model="settings.debug_mode"
					></cx-vui-switcher>
				</cx-vui-component-wrapper>
			</div>
			
			<!-- Network Status Indicator -->
			<div class="cx-vui-panel">
				<div class="cx-vui-title"><?php esc_html_e( 'Network Status', 'jetengine-audio-streaming' ); ?></div>
				
				<?php if ( ! empty( $network_stats ) && $network_stats['samples'] > 0 ) : 
					$avg_duration = round( $network_stats['avg_duration'] );
					$status_class = $avg_duration > 2000 ? 'cx-vui-notice-warning' : 'cx-vui-notice-success';
				?>
					<div class="cx-vui-subtitle">
						<?php esc_html_e( 'Average Download Time', 'jetengine-audio-streaming' ); ?>:
						<span class="<?php echo esc_attr( $status_class ); ?>">
							<?php echo esc_html( $avg_duration ); ?> ms
						</span>
						<span class="cx-vui-notice-info">
							<?php echo sprintf( __( 'Based on the last %d chunks', 'jetengine-audio-streaming' ), $network_stats['samples'] ); ?>
						</span>
					</div>
					
					<?php if ( $avg_duration > 2000 ) : ?>
						<div class="cx-vui-notice cx-vui-notice-warning">
							<div class="cx-vui-notice-content">
								<?php esc_html_e( 'Warning: Average download time exceeds 2 seconds. Consider reducing your chunk size for better performance.', 'jetengine-audio-streaming' ); ?>
							</div>
						</div>
					<?php endif; ?>
					
					<div class="cx-vui-text">
						<ul>
							<li>
								<?php esc_html_e( 'Min Duration', 'jetengine-audio-streaming' ); ?>: 
								<?php echo esc_html( round( $network_stats['min_duration'] ) ); ?> ms
							</li>
							<li>
								<?php esc_html_e( 'Max Duration', 'jetengine-audio-streaming' ); ?>: 
								<?php echo esc_html( round( $network_stats['max_duration'] ) ); ?> ms
							</li>
						</ul>
					</div>
				<?php else : ?>
					<div class="cx-vui-notice cx-vui-notice-info">
						<div class="cx-vui-notice-content">
							<?php esc_html_e( 'No download metrics available yet. Statistics will appear after audio chunks have been streamed.', 'jetengine-audio-streaming' ); ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
			
			<!-- Cache Management -->
			<div class="cx-vui-panel">
				<div class="cx-vui-title"><?php esc_html_e( 'Cache Management', 'jetengine-audio-streaming' ); ?></div>
				
				<div class="cx-vui-component">
					<div class="cx-vui-component__meta">
						<label class="cx-vui-component__label"><?php esc_html_e( 'Clear Audio Cache', 'jetengine-audio-streaming' ); ?></label>
						<div class="cx-vui-component__desc"><?php esc_html_e( 'Remove all cached audio chunks to refresh content', 'jetengine-audio-streaming' ); ?></div>
					</div>
					<div class="cx-vui-component__control">
						<cx-vui-button
							button-style="accent-border"
							size="mini"
							:loading="clearingCache"
							@click="clearCache"
						>
							<span slot="label"><?php esc_html_e( 'Clear Cache', 'jetengine-audio-streaming' ); ?></span>
						</cx-vui-button>
						<div class="cx-vui-component__clear-cache-result" v-if="cacheMessage">
							{{ cacheMessage }}
						</div>
					</div>
				</div>
			</div>
			
			<!-- Debug Log -->
			<?php if ( $this->settings['debug_mode'] ) : ?>
				<div class="cx-vui-panel">
					<div class="cx-vui-title"><?php esc_html_e( 'Debug Log', 'jetengine-audio-streaming' ); ?></div>
					
					<?php if ( ! empty( $recent_logs ) ) : ?>
						<div class="cx-vui-component">
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
						</div>
					<?php else : ?>
						<div class="cx-vui-notice cx-vui-notice-info">
							<div class="cx-vui-notice-content">
								<?php esc_html_e( 'No logs available yet. Logs will appear after audio chunks have been streamed.', 'jetengine-audio-streaming' ); ?>
							</div>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			
			<div class="jet-engine-audio-streaming-actions">
				<cx-vui-button
					button-style="accent"
					custom-css="fullwidth"
					:loading="saving"
					@click="saveSettings"
				>
					<span slot="label"><?php esc_html_e( 'Save Settings', 'jetengine-audio-streaming' ); ?></span>
				</cx-vui-button>
			</div>
			
			<input type="hidden" name="jet_audio_streaming_settings" value="1">
			<?php wp_nonce_field( 'jet-audio-streaming', 'jet-audio-streaming-nonce' ); ?>
		</form>
		
		<!-- Add some CSS for our logs table and status indicators -->
		<style>
			.status-error { color: #d63638; font-weight: bold; }
			.status-warning { color: #dba617; font-weight: bold; }
			.status-success { color: #00a32a; font-weight: bold; }
			
			.duration-warning { color: #d63638; font-weight: bold; }
			.duration-notice { color: #dba617; }
			
			.cx-vui-notice-warning { color: #dba617; font-weight: bold; }
			.cx-vui-notice-success { color: #00a32a; font-weight: bold; }
			.cx-vui-notice-info { color: #72aee6; font-style: italic; font-size: 0.9em; margin-left: 10px; }
		</style>
		
		<script>
			new Vue({
				el: '#jet-engine-audio-streaming-form',
				data: {
					settings: <?php echo json_encode( $this->settings ); ?>,
					saving: false,
					clearingCache: false,
					cacheMessage: ''
				},
				methods: {
					saveSettings: function() {
						this.saving = true;
						this.$el.submit();
					},
					clearCache: function() {
						if (this.clearingCache) {
							return;
						}
						
						this.clearingCache = true;
						this.cacheMessage = '';
						
						// Create a nonce for security
						const data = new FormData();
						data.append('action', 'jet_audio_streaming_clear_cache');
						data.append('nonce', '<?php echo wp_create_nonce( 'jet_audio_streaming_clear_cache' ); ?>');
						
						fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
							method: 'POST',
							body: data,
							credentials: 'same-origin'
						})
						.then(response => response.json())
						.then(result => {
							this.clearingCache = false;
							
							if (result.success) {
								this.cacheMessage = result.data.message;
								
								// Refresh the page after 2 seconds to update the logs
								setTimeout(() => {
									window.location.reload();
								}, 2000);
							} else {
								this.cacheMessage = 'Error: ' + (result.data ? result.data.message : 'Unknown error');
							}
						})
						.catch(error => {
							this.clearingCache = false;
							this.cacheMessage = 'Error: ' + error.message;
						});
					}
				}
			});
		</script>
		<?php
	}

	/**
	 * Update settings
	 */
	public function update_settings() {
		if ( ! isset( $_POST['jet-audio-streaming-nonce'] ) || ! wp_verify_nonce( $_POST['jet-audio-streaming-nonce'], 'jet-audio-streaming' ) ) {
			return;
		}
		
		// Save individual settings with the specified option keys
		update_option( 'je_audio_chunk_size', floatval( $_POST['chunk_size'] ) );
		update_option( 'je_audio_max_size', intval( $_POST['max_file_size'] ) );
		update_option( 'je_audio_debug_mode', isset( $_POST['debug_mode'] ) ? true : false );
		
		// Save WAV to MP3 conversion settings
		update_option( 'je_audio_mp3_bitrate', intval( $_POST['mp3_bitrate'] ?? 128 ) );
		update_option( 'je_audio_mp3_samplerate', intval( $_POST['mp3_samplerate'] ?? 44100 ) );
		update_option( 'je_audio_auto_convert', isset( $_POST['auto_convert'] ) ? true : false );
		update_option( 'je_audio_enable_clipboard', isset( $_POST['enable_clipboard'] ) ? true : false );
		
		// Update settings cache
		$this->settings = $this->get_settings();
		
		add_action( 'admin_notices', function() {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Settings saved successfully!', 'jetengine-audio-streaming' ); ?></p>
			</div>
			<?php
		} );
	}

	/**
	 * Register frontend assets
	 */
	public function register_frontend_assets() {
		// Get settings to pass to JavaScript
		$settings = $this->get_settings();
		$debug_mode = (bool) $settings['debug_mode'];
		
		// Using a different handle to avoid conflicts with the main plugin script
		// Instead of directly loading our JS, rely on the main plugin to load it
		// This prevents multiple WaveSurfer initializations for the same elements
		
		/*
		// Determine which assets to load based on debug mode
		$js_file = $debug_mode ? 'player.js' : 'min/player.min.js';
		$css_file = $debug_mode ? 'player.css' : 'min/player.min.css';
		
		wp_register_script(
			'wavesurfer',
			'https://unpkg.com/wavesurfer.js@6.6.3/dist/wavesurfer.min.js',
			[],
			'6.6.3',
			true
		);
		
		wp_register_script(
			'jetengine-audio-player',
			JETENGINE_AUDIO_STREAMING_URL . 'assets/js/' . $js_file,
			[ 'wavesurfer' ],
			JETENGINE_AUDIO_STREAMING_VERSION,
			true
		);
		*/
		
		// Still register our CSS file
		$css_file = $debug_mode ? 'player.css' : 'min/player.min.css';
		wp_register_style(
			'jetengine-audio-player-legacy',
			JETENGINE_AUDIO_STREAMING_URL . 'assets/css/' . $css_file,
			[],
			JETENGINE_AUDIO_STREAMING_VERSION
		);
		
		// Let the main plugin script handle player initialization
		// This comment indicates we've intentionally disabled duplicate script loading
		$this->log('Audio player scripts loading from main plugin file - player.js/player.min.js disabled to avoid conflicts');
	}

	/**
	 * Get plugin settings
	 * 
	 * @return array
	 */
	public function get_settings() {
		if ( empty( $this->settings ) ) {
			$default_settings = [
				'chunk_size'    => 1, // Default chunk size in MB
				'max_file_size' => 50, // Maximum allowed file size in MB
				'debug_mode'    => false, // Debug mode toggled off by default
				'enable_clipboard' => true, // Default to true, assuming it's a useful feature
			];
			
			// Get individual settings using the specified option keys
			$chunk_size = get_option( 'je_audio_chunk_size', $default_settings['chunk_size'] );
			$max_file_size = get_option( 'je_audio_max_size', $default_settings['max_file_size'] );
			$debug_mode = get_option( 'je_audio_debug_mode', $default_settings['debug_mode'] );
			$enable_clipboard = get_option( 'je_audio_enable_clipboard', $default_settings['enable_clipboard'] );
			
			$this->settings = [
				'chunk_size'    => $chunk_size,
				'max_file_size' => $max_file_size,
				'debug_mode'    => $debug_mode,
				'enable_clipboard' => $enable_clipboard,
			];
		}
		
		return $this->settings;
	}

	/**
	 * Get logs instance
	 * 
	 * @return Audio_Logs
	 */
	public function get_logs() {
		return $this->logs;
	}

	/**
	 * Get settings page instance
	 * 
	 * @return Audio_Settings
	 */
	public function get_settings_page() {
		return $this->settings_page;
	}

	/**
	 * Log debug message to error log if debug mode is enabled
	 * 
	 * @param string $message Message to log
	 * @param array  $context Additional context data
	 * @param bool   $force   Force log even if debug mode is disabled
	 */
	public function log( $message, $context = [], $force = false ) {
		$settings = $this->get_settings();
		
		if ( $settings['debug_mode'] || $force ) {
			$log_message = '[JetEngine Audio Streaming] ' . $message;
			
			if ( ! empty( $context ) ) {
				$log_message .= ' | Context: ' . json_encode( $context );
			}
			
			error_log( $log_message );
			
			return true;
		}
		
		return false;
	}
	
	/**
	 * Handle AJAX log request from the JavaScript player
	 */
	public function handle_log_request() {
		// Verify nonce
		if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'jet_audio_streaming_log' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
		}
		
		// Get log data
		$type = isset( $_REQUEST['type'] ) ? sanitize_text_field( $_REQUEST['type'] ) : 'info';
		$message = isset( $_REQUEST['message'] ) ? sanitize_text_field( $_REQUEST['message'] ) : '';
		$context = isset( $_REQUEST['context'] ) ? $_REQUEST['context'] : [];
		
		// Format context if it's a string (JSON)
		if ( is_string( $context ) && ! empty( $context ) ) {
			$context = json_decode( $context, true );
		}
		
		// Sanitize context
		if ( is_array( $context ) ) {
			array_walk_recursive( $context, function( &$value ) {
				if ( is_string( $value ) ) {
					$value = sanitize_text_field( $value );
				}
			});
		} else {
			$context = [];
		}
		
		// Force log errors regardless of debug mode
		$force = ( $type === 'error' );
		
		// Log the message
		$logged = $this->log( $message, $context, $force );
		
		// Return success
		wp_send_json_success( [ 
			'logged' => $logged,
			'message' => $message,
		] );
	}

	/**
	 * Callback for the wp_generate_attachment_metadata filter.
	 * Checks if the attachment is an audio file and triggers peak generation.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Original metadata.
	 */
	public function maybe_generate_audio_peaks( $metadata, $attachment_id ) {
		// Ensure the peak generation function exists
		if ( ! function_exists('\JetEngine_Audio_Streaming\jetengine_audio_stream_generate_peaks') ) {
			error_log("JetEngine Audio Streaming: Peak generation function not found when attempting to generate peaks for attachment ID {$attachment_id}.");
			return $metadata; // Return original metadata
		}

		// Get the attachment post
		$attachment = get_post( $attachment_id );

		// Check if it's a valid post and an audio file
		if ( $attachment && isset( $attachment->post_mime_type ) && strpos( $attachment->post_mime_type, 'audio/' ) === 0 ) {
			error_log("JetEngine Audio Streaming: Triggering peak generation for audio attachment ID {$attachment_id} (MIME: {$attachment->post_mime_type}).");

			// Call the peak generation function
			$peaks = jetengine_audio_stream_generate_peaks( $attachment_id );

			if ( $peaks === false ) {
				error_log("JetEngine Audio Streaming: Peak generation failed for attachment ID {$attachment_id}.");
			} else {
				error_log("JetEngine Audio Streaming: Peak generation successful for attachment ID {$attachment_id}.");
				// Optionally, you could add the peaks count to the metadata, but it's not standard.
				// $metadata['audio_peaks_count'] = count($peaks);
			}
		} else {
			// Optional: Log if it's not an audio file (can be noisy)
			// error_log("JetEngine Audio Streaming: Skipping peak generation for non-audio attachment ID {$attachment_id}.");
		}

		// Always return the original metadata, whether modified or not
		return $metadata;
	}
} 