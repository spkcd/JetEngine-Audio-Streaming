<?php
namespace JetEngine_Audio_Streaming;

/**
 * Class for integrating audio streaming endpoint with JetEngine listings
 */
class Listing_Integration {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Filter dynamic field output for audio-recording post type
		add_filter( 'jet-engine/listings/dynamic-field/field-value', [ $this, 'modify_audio_field_value' ], 10, 2 );
		
		// Filter dynamic link attributes
		add_filter( 'jet-engine/listings/dynamic-link/attr', [ $this, 'modify_dynamic_link_attr' ], 10, 2 );
		
		// Add script for handling AJAX-loaded listings
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_listing_scripts' ] );
		
		// Support data-url attributes in dynamic field
		add_filter( 'jet-engine/listings/allowed-html-attrs', [ $this, 'allow_data_url_attr' ] );
	}

	/**
	 * Modify audio field value to use the streaming endpoint
	 * 
	 * @param mixed $value   Field value
	 * @param array $settings Field settings
	 * @return mixed
	 */
	public function modify_audio_field_value( $value, $settings ) {
		// Only modify if this is an audio-recording post
		$object = jet_engine()->listings->data->get_current_object();
		
		if ( ! is_object( $object ) || ! isset( $object->post_type ) || 'audio-recording' !== $object->post_type ) {
			return $value;
		}
		
		// Only modify if we're dealing with a field that typically contains audio URL
		if ( empty( $settings['dynamic_field_post_meta'] ) ) {
			return $value;
		}
		
		$meta_field = $settings['dynamic_field_post_meta'];
		
		// Check if this is an audio field by name
		if ( ! in_array( $meta_field, [ 'recording', 'recording_download', 'audio', 'audio_file' ] ) ) {
			return $value;
		}
		
		// Replace with streaming endpoint URL
		$post_id = $object->ID;
		$streaming_url = rest_url( 'jetengine-audio-stream/v1/play/' . $post_id );
		
		// Log the URL for debugging
		$this->log_url( $streaming_url, $post_id );
		
		return $streaming_url;
	}

	/**
	 * Modify dynamic link attributes for audio recordings
	 * 
	 * @param array $attrs    Link attributes
	 * @param array $settings Link settings
	 * @return array
	 */
	public function modify_dynamic_link_attr( $attrs, $settings ) {
		// Only modify if this is an audio-recording post
		$object = jet_engine()->listings->data->get_current_object();
		
		if ( ! is_object( $object ) || ! isset( $object->post_type ) || 'audio-recording' !== $object->post_type ) {
			return $attrs;
		}
		
		// Check if this is meant to be an audio link by class or context
		$is_audio_link = false;
		
		if ( isset( $attrs['class'] ) && strpos( $attrs['class'], 'audio' ) !== false ) {
			$is_audio_link = true;
		}
		
		if ( isset( $settings['dynamic_link_source'] ) && 'meta_field' === $settings['dynamic_link_source'] ) {
			if ( ! empty( $settings['dynamic_link_source_meta_field'] ) ) {
				$meta_field = $settings['dynamic_link_source_meta_field'];
				
				if ( in_array( $meta_field, [ 'recording', 'recording_download', 'audio', 'audio_file' ] ) ) {
					$is_audio_link = true;
				}
			}
		}
		
		if ( $is_audio_link ) {
			$post_id = $object->ID;
			$streaming_url = rest_url( 'jetengine-audio-stream/v1/play/' . $post_id );
			
			// Set the actual href attribute
			$attrs['href'] = $streaming_url;
			
			// Also add data-url attribute for JavaScript players
			$attrs['data-url'] = $streaming_url;
			$attrs['data-post-id'] = $post_id;
			$attrs['data-post-type'] = 'audio-recording';
			
			// Log the URL for debugging
			$this->log_url( $streaming_url, $post_id );
		}
		
		return $attrs;
	}

	/**
	 * Allow data-url attribute in dynamic field
	 * 
	 * @param array $attrs Allowed HTML attributes
	 * @return array
	 */
	public function allow_data_url_attr( $attrs ) {
		$attrs[] = 'data-url';
		$attrs[] = 'data-post-id';
		$attrs[] = 'data-post-type';
		
		return $attrs;
	}

	/**
	 * Enqueue scripts for listing integration
	 */
	public function enqueue_listing_scripts() {
		// Enqueue with dependency on our main player script
		// This ensures our main script is loaded first
		wp_enqueue_script(
			'jetengine-audio-listing',
			JETENGINE_AUDIO_STREAMING_URL . 'assets/js/listing-integration.js',
			[ 'jquery', 'jet-engine-frontend', 'jetengine-audio-player' ],
			JETENGINE_AUDIO_STREAMING_VERSION,
			true
		);
		
		wp_localize_script( 'jetengine-audio-listing', 'JetEngineAudioListing', [
			'rest_url' => rest_url( 'jetengine-audio-stream/v1/play/' ),
			'debug'    => Plugin::instance()->get_settings()['debug_mode'],
			// Add a flag to indicate that our main player should handle initialization
			'mainPlayerHandlesInitialization' => true
		] );
	}

	/**
	 * Log URL for debugging
	 * 
	 * @param string $url     URL
	 * @param int    $post_id Post ID
	 */
	private function log_url( $url, $post_id ) {
		if ( ! Plugin::instance()->get_settings()['debug_mode'] ) {
			return;
		}
		
		// Only log if debugging is enabled
		error_log( sprintf( 
			'JetEngine Audio Streaming: Generated streaming URL %s for post ID %d', 
			$url, 
			$post_id 
		) );
	}
} 