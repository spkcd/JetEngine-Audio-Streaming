<?php
/**
 * JetEngine Audio Streaming
 * Utility functions for the plugin
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get the file size of an attachment in bytes
 *
 * @param int $attachment_id The attachment ID
 * @return int File size in bytes, 0 if file not found
 */
function jetengine_audio_stream_get_file_size( $attachment_id ) {
    // Get the file path
    $file_path = get_attached_file( $attachment_id );
    
    // Check if file exists and is readable
    if ( $file_path && file_exists( $file_path ) && is_readable( $file_path ) ) {
        return filesize( $file_path );
    }
    
    // Return 0 if file not found or not readable
    return 0;
}

/**
 * Format file size in human-readable format (for demonstration purposes)
 *
 * @param int $attachment_id The attachment ID
 * @return string Formatted file size (e.g., "1.5 MB") or "File not found"
 */
function jetengine_audio_stream_format_file_size( $attachment_id ) {
    $size_bytes = jetengine_audio_stream_get_file_size( $attachment_id );
    
    if ( $size_bytes <= 0 ) {
        return 'File not found';
    }
    
    // Format the size using WordPress function
    return size_format( $size_bytes, 2 );
}

/**
 * Get comprehensive attachment information
 *
 * @param int $attachment_id The attachment ID
 * @return array Array containing attachment URL, MIME type, size in bytes, and filename
 */
function jetstream_get_attachment_info( $attachment_id ) {
    // Initialize return array with default values
    $info = array(
        'url'      => '',
        'mime'     => '',
        'size'     => 0,
        'filename' => '',
    );
    
    // Check if attachment exists
    if ( ! $attachment_id || ! get_post( $attachment_id ) ) {
        return $info;
    }
    
    // Get file path
    $file_path = get_attached_file( $attachment_id );
    
    // Only proceed if file exists
    if ( $file_path && file_exists( $file_path ) && is_readable( $file_path ) ) {
        // Get attachment URL
        $info['url'] = wp_get_attachment_url( $attachment_id );
        
        // Get MIME type
        $info['mime'] = get_post_mime_type( $attachment_id );
        
        // Get file size
        $info['size'] = filesize( $file_path );
        
        // Get filename
        $info['filename'] = basename( $file_path );
    }
    
    return $info;
} 