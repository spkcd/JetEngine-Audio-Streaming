<?php
/**
 * Audio player template
 * 
 * Template for rendering the JetEngine audio player for streaming.
 * 
 * @var string $player_id The unique ID for this player instance
 * @var int    $attachment_id The ID of the audio attachment
 * @var array  $atts Shortcode attributes
 */

// Don't allow direct script access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define default attributes
$default_atts = [
	'width' => $atts['width'] ?? '100%',
	'height' => $atts['height'] ?? 'auto',
	'show_time' => $atts['show_time'] ?? true,
	'show_waveform' => $atts['show_waveform'] ?? true,
];

// Get the URL for the streaming endpoint
$stream_url = rest_url( 'jetengine-audio-stream/v1/play/' . $attachment_id );

// Get settings
$admin_settings = new JetEngine_Audio_Stream\Admin_Settings();
$settings = $admin_settings->get_settings();

// Should we display the waveform?
$show_waveform = $default_atts['show_waveform'] && !$settings['disable_waveform'];

// Get file information
$file_type = get_post_mime_type( $attachment_id );
$file_url = wp_get_attachment_url( $attachment_id );
$file_title = get_the_title( $attachment_id );
?>

<div 
	id="<?php echo esc_attr( $player_id ); ?>" 
	class="jet-audio-player" 
	data-post-id="<?php echo esc_attr( $attachment_id ); ?>"
	data-file-type="<?php echo esc_attr( $file_type ); ?>"
	style="width: <?php echo esc_attr( $default_atts['width'] ); ?>; height: <?php echo esc_attr( $default_atts['height'] ); ?>;"
>
	<?php if ( $show_waveform ) : ?>
	<div class="jet-audio-player__waveform"></div>
	<?php endif; ?>
	
	<div class="jet-audio-player__chunk-indicator">
		<div class="jet-audio-player__chunk-loaded"></div>
	</div>
	
	<div class="jet-audio-player__controls">
		<button class="jet-audio-player__play-pause">
			<span class="dashicons dashicons-controls-play"></span>
		</button>
		
		<?php if ( $default_atts['show_time'] ) : ?>
		<div class="jet-audio-player__time">
			<span class="jet-audio-player__current-time">0:00</span>
			<span class="jet-audio-player__duration">0:00</span>
		</div>
		<?php endif; ?>
		
		<?php if ( $settings['enable_clipboard'] ) : ?>
		<button class="jet-audio-player__copy-url" data-url="<?php echo esc_attr( $stream_url ); ?>" title="<?php esc_attr_e( 'Copy audio URL', 'jetengine-audio-stream' ); ?>">
			<span class="dashicons dashicons-clipboard"></span>
		</button>
		<?php endif; ?>
		
		<a href="<?php echo esc_url( $file_url ); ?>" class="jet-audio-player__download" download="<?php echo esc_attr( $file_title ); ?>" title="<?php esc_attr_e( 'Download audio file', 'jetengine-audio-stream' ); ?>">
			<span class="dashicons dashicons-download"></span>
		</a>
	</div>
</div> 