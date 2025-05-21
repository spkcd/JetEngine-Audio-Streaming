/**
 * JetEngine Audio Streaming Admin Scripts
 * Handles clipboard functionality and manual WAV to MP3 conversion
 */
(function($) {
    'use strict';
    
    // Initialize on document ready
    $(document).ready(function() {
        initClipboardFunctionality();
        initManualConversion();
    });
    
    /**
     * Initialize clipboard copy functionality
     */
    function initClipboardFunctionality() {
        // Handle copy URL button clicks
        $(document).on('click', '.jet-audio-copy-url', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const url = button.data('url');
            
            // Create a temporary input for copying
            const tempInput = $('<input>');
            $('body').append(tempInput);
            tempInput.val(url).select();
            
            try {
                // Copy to clipboard
                document.execCommand('copy');
                
                // Show success indicator
                const originalText = button.text();
                button.text(JetEngineAudioConverter.copySuccess);
                
                // Restore original text after 2 seconds
                setTimeout(function() {
                    button.text(originalText);
                }, 2000);
            } catch (err) {
                // Show error message
                alert(JetEngineAudioConverter.copyError);
                console.error('Copy failed:', err);
            }
            
            // Remove temporary input
            tempInput.remove();
        });
    }
    
    /**
     * Initialize manual conversion functionality
     */
    function initManualConversion() {
        // Handle manual conversion button clicks
        $(document).on('click', '.jet-audio-convert-to-mp3', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const attachmentId = button.data('id');
            const spinner = button.next('.spinner');
            
            // Disable button and show spinner
            button.prop('disabled', true);
            spinner.addClass('is-active');
            
            // Send AJAX request to convert file
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'jet_audio_convert_file',
                    attachment_id: attachmentId,
                    nonce: JetEngineAudioConverter.convertNonce
                },
                success: function(response) {
                    spinner.removeClass('is-active');
                    
                    if (response.success) {
                        // Show success message
                        button.parent().html(
                            '<div class="notice notice-success inline"><p>' + 
                            response.data.message + 
                            '</p></div>' +
                            '<a href="' + 
                            'post.php?post=' + response.data.attachment_id + '&action=edit' + 
                            '" class="button">' +
                            'View MP3' +
                            '</a>'
                        );
                    } else {
                        // Show error message and re-enable button
                        alert(response.data.message);
                        button.prop('disabled', false);
                    }
                },
                error: function() {
                    // Show generic error and re-enable button
                    spinner.removeClass('is-active');
                    alert('An error occurred during conversion. Please try again.');
                    button.prop('disabled', false);
                }
            });
        });
    }
    
})(jQuery); 