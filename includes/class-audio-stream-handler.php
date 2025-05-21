<?php
namespace JetEngine_Audio_Stream;

/**
 * Class for handling audio streaming core functionality
 */
class Audio_Stream_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Add hooks for audio stream handling
		add_action( 'init', [ $this, 'init' ] );
	}

	/**
	 * Initialize the stream handler
	 */
	public function init() {
		// Setup any needed hooks or filters
		add_filter( 'upload_mimes', [ $this, 'add_audio_mime_types' ] );
		
		// Add support for audio post metadata
		add_action( 'add_attachment', [ $this, 'process_audio_attachment' ] );
		add_action( 'edit_attachment', [ $this, 'process_audio_attachment' ] );
	}

	/**
	 * Add additional audio MIME types to allowed uploads
	 * 
	 * @param array $mimes Array of allowed MIME types
	 * @return array Modified array of allowed MIME types
	 */
	public function add_audio_mime_types( $mimes ) {
		// Add additional audio types if not already supported
		$mimes['mp3'] = 'audio/mpeg';
		$mimes['wav'] = 'audio/wav';
		$mimes['ogg'] = 'audio/ogg';
		$mimes['m4a'] = 'audio/m4a';
		
		return $mimes;
	}

	/**
	 * Process audio attachment metadata
	 * 
	 * @param int $attachment_id The attachment ID
	 */
	public function process_audio_attachment( $attachment_id ) {
		// Only process audio files
		if ( ! $this->is_audio_file( $attachment_id ) ) {
			return;
		}

		// Extract and store duration metadata
		$this->extract_audio_metadata( $attachment_id );
		
		// Generate waveform data if needed
		$this->generate_waveform_data( $attachment_id );
	}
	
	/**
	 * Check if a file is an audio file
	 * 
	 * @param int $attachment_id The attachment ID
	 * @return bool Whether the attachment is an audio file
	 */
	public function is_audio_file( $attachment_id ) {
		$mime_type = get_post_mime_type( $attachment_id );
		return $mime_type && strpos( $mime_type, 'audio/' ) === 0;
	}
	
	/**
	 * Extract metadata from audio file
	 * 
	 * @param int $attachment_id The attachment ID
	 */
	public function extract_audio_metadata( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return;
		}
		
		// Get metadata using WordPress functions
		$metadata = wp_read_audio_metadata( $file_path );
		
		if ( ! empty( $metadata ) ) {
			// Store duration in post meta
			if ( ! empty( $metadata['length_formatted'] ) ) {
				update_post_meta( $attachment_id, '_audio_duration', $metadata['length_formatted'] );
			}
			
			// Store other useful metadata
			if ( ! empty( $metadata['filesize'] ) ) {
				update_post_meta( $attachment_id, '_audio_filesize', $metadata['filesize'] );
			}
			
			if ( ! empty( $metadata['bitrate'] ) ) {
				update_post_meta( $attachment_id, '_audio_bitrate', $metadata['bitrate'] );
			}
			
			if ( ! empty( $metadata['sample_rate'] ) ) {
				update_post_meta( $attachment_id, '_audio_sample_rate', $metadata['sample_rate'] );
			}
		}
	}
	
	/**
	 * Generate waveform data for audio file
	 * 
	 * @param int $attachment_id The attachment ID
	 * @param int $num_points Number of data points to generate for waveform
	 * @return bool Success status
	 */
	public function generate_waveform_data( $attachment_id, $num_points = 256 ) {
		// Check if waveform data already exists
		$existing_data = get_post_meta( $attachment_id, '_audio_waveform', true );
		
		if ( ! empty( $existing_data ) ) {
			return true; // Waveform data already exists
		}
		
		$file_path = get_attached_file( $attachment_id );
		
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}
		
		// A placeholder function for waveform generation
		// In a real implementation, this would use audio processing libraries
		// This is a simplified example
		$waveform_data = $this->generate_placeholder_waveform( $num_points );
		
		// Store the waveform data
		update_post_meta( $attachment_id, '_audio_waveform', wp_json_encode( $waveform_data ) );
		
		return true;
	}
	
	/**
	 * Generate placeholder waveform data
	 * This is just a demo implementation
	 * 
	 * @param int $num_points Number of data points
	 * @return array Waveform data array
	 */
	private function generate_placeholder_waveform( $num_points ) {
		$waveform = [];
		
		// Generate random waveform data between 0 and 1
		for ( $i = 0; $i < $num_points; $i++ ) {
			$waveform[] = mt_rand( 10, 100 ) / 100;
		}
		
		return $waveform;
	}
	
	/**
	 * Get waveform data for an audio file
	 * 
	 * @param int $attachment_id The attachment ID
	 * @return array|false Waveform data or false if not available
	 */
	public function get_waveform_data( $attachment_id ) {
		$waveform_data = get_post_meta( $attachment_id, '_audio_waveform', true );
		
		if ( empty( $waveform_data ) ) {
			// Try to generate the data if it doesn't exist
			$generated = $this->generate_waveform_data( $attachment_id );
			
			if ( $generated ) {
				$waveform_data = get_post_meta( $attachment_id, '_audio_waveform', true );
			}
		}
		
		if ( ! empty( $waveform_data ) ) {
			return json_decode( $waveform_data, true );
		}
		
		return false;
	}
} 