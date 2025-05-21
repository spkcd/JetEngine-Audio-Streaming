/**
 * JetEngine Audio Streaming - Listing Integration
 * Handles integration with JetEngine listings
 */
(function($) {
    'use strict';

    /**
     * Audio listing integration
     */
    var JetEngineAudioListingIntegration = {
        
        /**
         * Initialize
         */
        init: function() {
            // Check if frontend.js should handle player initialization
            if (typeof JetEngineAudioListing !== 'undefined' && 
                JetEngineAudioListing.mainPlayerHandlesInitialization === true) {
                console.log('JetEngine Audio Streaming: Using main frontend.js for player initialization');
                // Only update URLs but don't initialize players
                this.shouldInitializePlayers = false;
            } else {
                // Fall back to initializing players in this script if frontend.js is not handling it
                this.shouldInitializePlayers = true;
                console.log('JetEngine Audio Streaming: Using listing-integration.js for player initialization');
            }
            
            // Process listing on page load
            this.processCurrentListings();
            
            // Listen for JetEngine listing updates
            $(document).on('jet-engine/listing/grid-items-loaded', this.onListingUpdated);
            $(document).on('jet-filter-content-rendered', this.onListingUpdated);
            $(document).on('jet-engine/listing/after-load-more', this.onListingUpdated);
        },
        
        /**
         * Process current listings on the page
         */
        processCurrentListings: function() {
            $('.jet-listing-grid__item').each(function() {
                JetEngineAudioListingIntegration.processListingItem($(this));
            });
        },
        
        /**
         * On listing updated via AJAX
         * 
         * @param {Object} event Event object
         * @param {Object} $grid Grid element
         */
        onListingUpdated: function(event, $grid) {
            if ($grid) {
                $grid.find('.jet-listing-grid__item').each(function() {
                    JetEngineAudioListingIntegration.processListingItem($(this));
                });
            } else {
                // If $grid is not available, process all items
                JetEngineAudioListingIntegration.processCurrentListings();
            }
            
            // Signal to frontend.js that new audio elements need initialization if they're handling it
            if (!JetEngineAudioListingIntegration.shouldInitializePlayers && 
                typeof window.JetEngineAudioStreamNeedsInit === 'function') {
                window.JetEngineAudioStreamNeedsInit();
            }
        },
        
        /**
         * Process a single listing item
         * 
         * @param {Object} $item jQuery listing item
         */
        processListingItem: function($item) {
            var $audioLinks = $item.find('a[href*="recording"], a[href*="audio"], a.audio-link, a.jet-audio-player-link');
            
            $audioLinks.each(function() {
                var $link = $(this);
                var postId = $link.data('post-id');
                
                // Skip already processed links
                if ($link.data('streaming-processed')) {
                    return;
                }
                
                if (!postId) {
                    // Try to extract post ID from various sources
                    postId = JetEngineAudioListingIntegration.extractPostId($link, $item);
                }
                
                if (postId) {
                    // Set the streaming URL
                    var streamingUrl = JetEngineAudioListing.rest_url + postId;
                    
                    // Update the link href and data-url attributes
                    $link.attr('href', streamingUrl);
                    $link.attr('data-url', streamingUrl);
                    $link.attr('data-post-id', postId);
                    $link.attr('data-post-type', 'audio-recording');
                    
                    // Mark as processed
                    $link.data('streaming-processed', true);
                    
                    // Add class for frontend.js to find
                    $link.addClass('jet-audio-player-link');
                    
                    // Log if debugging is enabled
                    if (JetEngineAudioListing.debug) {
                        console.log('JetEngine Audio Streaming: Updated link URL to', streamingUrl, 'for post ID', postId);
                    }
                }
            });
            
            // Also handle HTML5 audio elements
            var $audioElements = $item.find('audio');
            
            $audioElements.each(function() {
                var $audio = $(this);
                var postId = $audio.data('post-id');
                
                // Skip already processed elements
                if ($audio.data('streaming-processed')) {
                    return;
                }
                
                if (!postId) {
                    // Try to extract post ID from item
                    postId = JetEngineAudioListingIntegration.extractPostId($audio, $item);
                }
                
                if (postId) {
                    // Set the streaming URL
                    var streamingUrl = JetEngineAudioListing.rest_url + postId;
                    
                    // Update the audio source
                    $audio.attr('src', streamingUrl);
                    $audio.attr('data-url', streamingUrl);
                    $audio.attr('data-post-id', postId);
                    
                    // If there are source elements, update those too
                    $audio.find('source').attr('src', streamingUrl);
                    
                    // Mark as processed
                    $audio.data('streaming-processed', true);
                    
                    // Add class for frontend.js to find
                    $audio.closest('.jet-audio-player-wrapper').addClass('jet-audio-player-container');
                    
                    // Log if debugging is enabled
                    if (JetEngineAudioListing.debug) {
                        console.log('JetEngine Audio Streaming: Updated audio URL to', streamingUrl, 'for post ID', postId);
                    }
                }
            });
        },
        
        /**
         * Extract post ID from various sources
         * 
         * @param {Object} $element jQuery element
         * @param {Object} $item    jQuery listing item
         * @return {number|null}    Post ID or null
         */
        extractPostId: function($element, $item) {
            // Try to get from data attribute
            var postId = $element.data('post-id');
            
            if (postId) {
                return postId;
            }
            
            // Try to get from listing item data
            postId = $item.data('post-id');
            
            if (postId) {
                return postId;
            }
            
            // Try to get from URL if it contains recording or audio in the path
            var href = $element.attr('href');
            
            if (href) {
                // Check if URL has /wp-json/jetengine-audio-stream/v1/play/
                var restMatch = href.match(/\/wp-json\/jetengine-audio-stream\/v1\/play\/(\d+)/);
                
                if (restMatch && restMatch[1]) {
                    return parseInt(restMatch[1], 10);
                }
                
                // Try to extract post ID from post permalink
                var postMatch = href.match(/\/(recording|audio-recording)\/([^\/]+)\/(\d+)\/?$/);
                
                if (postMatch && postMatch[3]) {
                    return parseInt(postMatch[3], 10);
                }
            }
            
            // Try to get from item ID attribute if it follows a pattern like 'jet-post-12345'
            var itemId = $item.attr('id');
            
            if (itemId) {
                var itemMatch = itemId.match(/jet-post-(\d+)/);
                
                if (itemMatch && itemMatch[1]) {
                    return parseInt(itemMatch[1], 10);
                }
            }
            
            return null;
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        JetEngineAudioListingIntegration.init();
    });
    
}(jQuery)); 