/**
 * JetEngine Audio Stream - Frontend Player
 * 
 * Implements WaveSurfer-based audio player with proper streaming support for large files
 */

document.addEventListener("DOMContentLoaded", function () {
    // Check if WaveSurfer is available
    if (typeof WaveSurfer === 'undefined') {
        console.error('JetEngine Audio Stream: WaveSurfer library not found. Please make sure it is properly loaded.');
        return;
    }
    
    console.log('JetEngine Audio Stream: Initializing player with WaveSurfer', WaveSurfer);

    const CONFIG = {
        debugMode: true,
        endpoint: '/wp-json/jetengine-audio-stream/v1/play/',
        resolveEndpoint: '/wp-json/jetengine-audio-stream/v1/resolve-id',
        // Improved handling of streaming with progressive loading
        preloadDuration: 300, // 5 minutes (in seconds)
        estimatedBitrate: 192000, // 192kbps (adjust as necessary)
        playerOptions: {
            // Standard WaveSurfer visualization
            waveColor: '#8395c2', // Color for unplayed waveform
            progressColor: '#4F4A85', // Color for played portion
            cursorColor: '#f85032', // Prominent cursor color
            cursorWidth: 2,
            // Standard WaveSurfer bar visualization
            barWidth: 2,
            barGap: 1,
            barRadius: 0,
            barHeight: 1, // Auto scale bars to audio amplitude
            barMinHeight: 1,
            // Keep responsive sizing
            responsive: true,
            height: 80,
            normalize: true, // Normalize amplitudes for better visualization
            // Player settings
            backend: 'MediaElement', // Required for proper streaming
            fillParent: true,
            mediaControls: false,
            interact: true,
            hideScrollbar: false,
            // Add fetch options to handle large files better
            fetchParams: {
                // Prevent using HTTP/3 (QUIC) which can cause errors with large files
                cache: 'force-cache',
                // Use a longer timeout for large files
                signal: AbortSignal.timeout(30000),
                // Explicitly request HTTP/1.1 or HTTP/2
                headers: {
                    'Accept': 'audio/*',
                    'X-HTTP-Version': 'HTTP/1.1',
                    'Connection': 'keep-alive',
                    'Cache-Control': 'no-cache',
                    'X-HTTP-Method-Override': 'GET'
                }
            },
            // Improve buffer settings for better playback
            minPxPerSec: 50, // Lower detail for better performance
            partialRender: true, 
            autoScroll: true,
            preload: 'auto', // Use standard HTML5 preload attribute
            // Use a progressive loading strategy
            audioStrategy: {
                getAudioBuffer: function(audioBuffer) {
                    // Return the buffer directly for better streaming
                    return audioBuffer;
                },
                loadAudio: function(xhr, container) {
                    // Let the MediaElement backend handle loading
                    return true;
                }
            },
            // Add error handling for better recovery
            onError: function(err) {
                console.error('WaveSurfer error:', err);
                // Try to continue playback if possible
                if (this && typeof this.play === 'function') {
                    setTimeout(() => {
                        try {
                            this.play();
                        } catch (e) {
                            console.error('Error resuming playback:', e);
                        }
                    }, 1000);
                }
            }
        }
    };

    let playerInstances = [];
    let initializedUrls = {}; // Track which URLs have been initialized

    /**
     * Helper function to escape string for RegExp
     */
    function escapeRegExp(string) {
      return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); // $& means the whole matched string
    }

    /**
     * Check if URL is a direct media file
     * @param {string} url - The URL to check
     * @returns {boolean} - True if it's a direct media file
     */
    function isDirectMediaFile(url) {
        // Check if the URL ends with a media extension
        const mediaExtensions = ['.mp3', '.wav', '.ogg', '.m4a', '.flac', '.aac'];
        const urlLower = url.toLowerCase();
        return mediaExtensions.some(ext => urlLower.endsWith(ext));
    }

    /**
     * Perform a HEAD request to check URL properties and redirects
     * @param {string} url - The URL to check
     * @returns {Promise<{isRedirect: boolean, redirectUrl: string|null, contentType: string|null, contentLength: number|null, isMP3orWAV: boolean}>}
     */
    async function checkUrlProperties(url) {
        try {
            console.log('Checking URL properties for:', url);
            const xhr = new XMLHttpRequest();
            xhr.open('HEAD', url, true);
            xhr.setRequestHeader('Accept', 'audio/*');
            xhr.setRequestHeader('X-HTTP-Version', 'HTTP/1.1');
            xhr.setRequestHeader('Connection', 'keep-alive');
            
            return new Promise((resolve) => {
                xhr.onreadystatechange = function() {
                    // Only handle completed requests
                    if (xhr.readyState !== 4) return;
                    
                    // Prepare the result object
                    const result = {
                        isRedirect: false,
                        redirectUrl: null,
                        contentType: null,
                        contentLength: null,
                        isMP3orWAV: false
                    };
                    
                    // Check for redirect (status 3xx)
                    if (xhr.status >= 300 && xhr.status < 400) {
                        const redirectUrl = xhr.getResponseHeader('Location');
                        console.log(`Detected redirect: ${url} → ${redirectUrl}`);
                        result.isRedirect = true;
                        result.redirectUrl = redirectUrl;
                        
                        // Check if the redirect points to a direct MP3 or WAV file
                        if (redirectUrl) {
                            const lowerUrl = redirectUrl.toLowerCase();
                            result.isMP3orWAV = lowerUrl.endsWith('.mp3') || lowerUrl.endsWith('.wav');
                        }
                    } 
                    // For successful requests, gather content information
                    else if (xhr.status >= 200 && xhr.status < 300) {
                        // Get content headers
                        result.contentType = xhr.getResponseHeader('Content-Type');
                        const contentLength = xhr.getResponseHeader('Content-Length');
                        
                        if (contentLength) {
                            result.contentLength = parseInt(contentLength, 10);
                        }
                        
                        // Check if it's a direct MP3 or WAV based on Content-Type
                        if (result.contentType) {
                            result.isMP3orWAV = result.contentType.includes('audio/mpeg') || 
                                               result.contentType.includes('audio/wav') ||
                                               result.contentType.includes('audio/x-wav');
                        }
                        
                        // Also check URL extension as backup
                        const lowerUrl = url.toLowerCase();
                        if (lowerUrl.endsWith('.mp3') || lowerUrl.endsWith('.wav')) {
                            result.isMP3orWAV = true;
                        }
                        
                        console.log(`URL properties: Type=${result.contentType}, Size=${result.contentLength}, MP3/WAV=${result.isMP3orWAV}`);
                    }
                    
                    resolve(result);
                };
                
                xhr.onerror = function() {
                    console.error('Error checking URL properties:', url);
                    resolve({
                        isRedirect: false,
                        redirectUrl: null,
                        contentType: null,
                        contentLength: null,
                        isMP3orWAV: false
                    });
                };
                
                xhr.send();
            });
        } catch (error) {
            console.error('Exception checking URL properties:', error);
            return {
                isRedirect: false,
                redirectUrl: null,
                contentType: null,
                contentLength: null,
                isMP3orWAV: false
            };
        }
    }

    /**
     * Initialize players on the page
     */
    function initializeWaveSurfers() {
        console.log('Searching for audio players...');
        
        // HOTFIX FOR MALFORMED URLS: Fix any malformed /play/http:// URLs
        document.querySelectorAll(".audio-player[data-url*='/play/http']").forEach(function(player) {
            const malformedUrl = player.getAttribute('data-url');
            console.log('🔴 Found player with malformed URL:', malformedUrl);
            
            // Extract the actual file URL part
            const urlParts = malformedUrl.split('/play/');
            if (urlParts.length < 2) return;
            
            const fileUrl = urlParts[1];
            if (!fileUrl.startsWith('http')) return;
            
            // Get just the filename without extension
            const parts = fileUrl.split('/');
            const filenameWithExt = parts[parts.length - 1];
            // Keep the extension when looking for the file
            //const filename = filenameWithExt.split('.')[0];
            const filename = filenameWithExt;
            
            console.log('Extracted filename for emergency fix:', filename);
            
            // Mark player as failed to ensure it's re-processed
            player.classList.add('failed');
            player.classList.remove('initialized');
            
            // Check if direct file access is possible
            if (filenameWithExt) {
                // Use our backend endpoint with just the filename
                const streamingUrl = `${window.location.origin}/wp-json/jetengine-audio-stream/v1/play/${filename}`;
                console.log('Using filename-based streaming URL:', streamingUrl);
                
                // Update the data-url to use just the filename with our endpoint
                player.setAttribute('data-url', streamingUrl);
                
                // Also set the direct URL as a fallback
                const directUrl = fileUrl;
                player.setAttribute('data-direct-url', directUrl);
            }
        });
        
        // Find all player containers from plugin
        document.querySelectorAll(".jet-audio-player-container").forEach(async function (player) {
            console.log('Found jet-audio-player-container:', player);
            let audioId = player.getAttribute("data-audio-id");
            
            // Skip already initialized players
            if (player.classList.contains('initialized')) {
                console.log('Skipping already initialized player');
                return;
            }
            
            let audioUrl = '';
            
            // If we have an ID, use the direct endpoint
            if (audioId) {
                audioUrl = CONFIG.endpoint + audioId;
                console.log('Audio ID found:', audioId, 'URL:', audioUrl);
            }
            
            if (!audioUrl) {
                console.error("Missing audio configuration", player);
                return;
            }
            
            // Add this check before initializing a player:
            if (initializedUrls[audioUrl]) {
                console.log('Skipping duplicate initialization for:', audioUrl);
                return;
            }
            initializedUrls[audioUrl] = true;
            
            // Create player UI elements
            createPlayerUI(player);
            
            // Mark as initialized
            player.classList.add('initialized');
            
            // Initialize WaveSurfer
            initializePlayer(player, audioUrl);
        });

        // Also support the Elementor implementation
        document.querySelectorAll(".audio-player").forEach(async function (player) {
            // Skip players without a data-url attribute
            const audioUrl = player.getAttribute("data-url");
            if (!audioUrl) {
                console.log('Player missing data-url attribute, skipping');
                return;
            }
            
            // More aggressive check for already initialized players to avoid duplicate initialization
            if (player.classList.contains('initialized') && !player.classList.contains('failed')) {
                console.log('Skipping already initialized player:', audioUrl);
                return;
            }

            // Clear failed state if we're retrying
            player.classList.remove('failed');
            
            // Check for the waveform container
            let waveformContainer = player.querySelector(".waveform");
            if (!waveformContainer) {
                console.log('Missing waveform container in Elementor player');
                return;
            }
            
            // Handle relative URLs
            let processedAudioUrl = audioUrl;
            if (!processedAudioUrl.startsWith("http")) {
                processedAudioUrl = window.location.origin + processedAudioUrl;
            }
            
            console.log('Found Elementor audio-player:', player);
            
            // DIRECT FIX: Check for malformed URLs with pattern /play/http
            // This is the immediate fix for the bug where URLs are like /play/https://...
            let finalAudioUrl = processedAudioUrl;
            
            if (processedAudioUrl.includes('/play/http')) {
                console.log('Elementor player URL starts with endpoint but is not a recognized ID or malformed file endpoint. Using as-is:', processedAudioUrl);
                
                try {
                    // Extract the filename from the URL
                    const urlParts = processedAudioUrl.split('/play/');
                    if (urlParts.length >= 2) {
                        const fullURL = urlParts[1];
                        // Get the filename with extension from the full URL
                        const urlObj = new URL(fullURL);
                        const pathParts = urlObj.pathname.split('/');
                        const filename = pathParts[pathParts.length - 1];
                        
                        console.log('Extracted filename from full URL:', filename);
                        
                        if (filename) {
                            // Use our modified endpoint with just the filename
                            finalAudioUrl = `/wp-json/jetengine-audio-stream/v1/play/${filename}`;
                            console.log('✅ Using simplified endpoint with just filename:', finalAudioUrl);
                            
                            // Update data-url attribute for future requests
                            player.setAttribute('data-url', finalAudioUrl);
                        }
                    }
                } catch (error) {
                    console.error('Exception while processing audio URL:', error);
                    // Continue with the original URL
                }
            } else {
                console.log('Standard audio URL format, using as-is:', processedAudioUrl);
            }
            
            // Mark as initialized
            player.classList.add('initialized');
            
            console.log(`Initialized Elementor player for: ${finalAudioUrl}`);
            initializeElementorPlayer(player, finalAudioUrl);
        });
    }

    /**
     * Create the player UI elements
     * @param {HTMLElement} container - The container element for the player
     * @returns {Object|null} Object with UI elements or null if creation failed
     */
    function createPlayerUI(container) {
        try {
            // Create container for the wavesurfer instance
            const wavesurferContainer = document.createElement('div');
            wavesurferContainer.className = 'jet-audio-waveform';
            container.appendChild(wavesurferContainer);
            
            // Create waveform container
            const waveformContainer = document.createElement('div');
            waveformContainer.className = 'jet-audio-waveform-container';
            container.appendChild(waveformContainer);
            
            // Create play button
            const playButton = document.createElement('button');
            playButton.className = 'jet-audio-play-button';
            playButton.innerHTML = '<i aria-hidden="true" class="jki jki-play"></i>';
            container.appendChild(playButton);
            
            // Create time display
            const timeDisplay = document.createElement('div');
            timeDisplay.className = 'jet-audio-time';
            timeDisplay.textContent = '0:00 / 0:00';
            container.appendChild(timeDisplay);
            
            // Create progress bar
            const progressBar = document.createElement('input');
            progressBar.type = 'range';
            progressBar.min = 0;
            progressBar.max = 100;
            progressBar.value = 0;
            progressBar.className = 'jet-audio-progress';
            container.appendChild(progressBar);
            
            return {
                wavesurferContainer,
                waveformContainer,
                progressBar,
                playButton,
                timeDisplay
            };
        } catch (err) {
            console.error('Error creating player UI:', err);
            return null;
        }
    }

    /**
     * Initialize and create a WaveSurfer player instance
     */
    async function initializePlayer(container, audioUrl) {
        console.log('Initializing player for URL:', audioUrl);
        
        // Add throttling for WaveSurfer load calls
        let seekThrottleTimeout = null;
        let lastSeekURL = null;

        function loadWaveSurferThrottled(waveSurfer, url) {
          if (url === lastSeekURL) return;
          lastSeekURL = url;

          if (seekThrottleTimeout) {
            clearTimeout(seekThrottleTimeout);
          }

          seekThrottleTimeout = setTimeout(() => {
            waveSurfer.load(url);
          }, 150); // Delay to prevent rapid seek overload
        }
        
        // Check if this is a direct file or a stream endpoint URL
        let isStreamEndpoint = audioUrl.includes('/jetengine-audio-stream/v1/play/');
        let finalUrl = audioUrl;
        let useStreaming = true;
        let fileSize = 0;
        let fileType = null;
        let isAudioFile = false;
        
        // Get file ID if this is a stream endpoint
        let fileId = null;
        if (isStreamEndpoint) {
            fileId = audioUrl.split('/').pop();
            console.log(`Detected stream endpoint with ID: ${fileId}`);
        }
        
        // Check if we have cached metadata
        let metadata = null;
        if (fileId && window.JetEngineAudioFileMetadata && window.JetEngineAudioFileMetadata[fileId]) {
            metadata = window.JetEngineAudioFileMetadata[fileId];
            console.log('Using cached metadata:', metadata);
            
            // Set file properties from metadata
            fileSize = metadata.fileSize || 0;
            fileType = metadata.mimeType || '';
            isAudioFile = fileType && fileType.startsWith('audio/');
            
            // For small audio files, use direct URL if available
            const isSmallFile = fileSize > 0 && fileSize < 10 * 1024 * 1024;
            
            if (isSmallFile && isAudioFile && metadata.url) {
                console.log(`Using direct URL from metadata for small ${fileType} file (${(fileSize/1024/1024).toFixed(2)}MB)`);
                finalUrl = metadata.url;
                useStreaming = false;
            }
        }
        
        // If we're using a stream endpoint, check URL properties with HEAD request
        if (isStreamEndpoint && useStreaming) {
            console.log('Checking stream endpoint properties with HEAD request');
            const urlProps = await checkUrlProperties(audioUrl);
            
            // Handle redirect if present
            if (urlProps.isRedirect && urlProps.redirectUrl) {
                console.log(`Following redirect to: ${urlProps.redirectUrl}`);
                finalUrl = urlProps.redirectUrl;
                
                // Do a HEAD request on the redirected URL to get its properties
                const redirectProps = await checkUrlProperties(urlProps.redirectUrl);
                
                // Update file properties from redirect
                fileType = redirectProps.contentType || '';
                fileSize = redirectProps.contentLength || 0;
                isAudioFile = fileType && fileType.startsWith('audio/');
                
                // If it's a small audio file, use directly
                if (isAudioFile && fileSize && fileSize < 10 * 1024 * 1024) {
                    console.log(`Small ${fileType} file detected (${(fileSize/1024/1024).toFixed(2)}MB), using directly`);
                    useStreaming = false;
                }
            } 
            // No redirect but we have content info
            else if (urlProps.contentType) {
                fileType = urlProps.contentType;
                fileSize = urlProps.contentLength || 0;
                isAudioFile = fileType && fileType.startsWith('audio/');
                
                // If it's a direct audio file and small enough, use directly
                if (isAudioFile && fileSize && fileSize < 10 * 1024 * 1024) {
                    console.log(`Small ${fileType} file detected (${(fileSize/1024/1024).toFixed(2)}MB), using directly`);
                    useStreaming = false;
                }
            }
        }
        
        // For non-stream endpoints, check if it's a direct media file
        if (!isStreamEndpoint) {
            const isDirectMedia = isDirectMediaFile(audioUrl);
            if (isDirectMedia) {
                console.log('URL is a direct media file, using directly');
                useStreaming = false;
                isAudioFile = true; // Assume it's audio if it has a media extension
            }
        }
        
        console.log(`Final audio URL: ${finalUrl} (Streaming: ${useStreaming ? 'Yes' : 'No'}, Audio: ${isAudioFile ? 'Yes' : 'No'})`);
        
        // Create UI elements for the player
        const playerUI = createPlayerUI(container);
        if (!playerUI) return;
        
        // Extract UI elements
        const { wavesurferContainer, waveformContainer, progressBar, playButton, timeDisplay } = playerUI;
        
        // Add loading indicator
        container.classList.add('loading');
        
        // Load audio using appropriate strategy
        try {
            console.log("Creating WaveSurfer instance");
            
            // Base player options - always use MediaElement backend
            const playerOptions = {
                ...CONFIG.playerOptions,
                backend: 'MediaElement',
                preload: 'auto', // Always use auto preload
                container: wavesurferContainer,
                waveColor: createGradient(wavesurferContainer, 'unplayed'),
                progressColor: createGradient(wavesurferContainer, 'played')
            };
            
            // Configure fetch options based on streaming vs direct
            if (!useStreaming) {
                // For direct files, simplify headers
                if (playerOptions.fetchParams && playerOptions.fetchParams.headers) {
                    // Keep basic headers but remove Range
                    delete playerOptions.fetchParams.headers['Range'];
                    // Use cache for better performance
                    playerOptions.fetchParams.cache = 'force-cache';
                }
            } else {
                // For streaming, ensure proper headers
                if (!playerOptions.fetchParams) {
                    playerOptions.fetchParams = {};
                }
                if (!playerOptions.fetchParams.headers) {
                    playerOptions.fetchParams.headers = {};
                }
                playerOptions.fetchParams.headers['Accept'] = 'audio/*';
            }
            
            // Create WaveSurfer instance
            const wavesurfer = WaveSurfer.create(playerOptions);
            console.log("WaveSurfer instance created");
            playerInstances.push(wavesurfer);
            
            // Track last seek time to prevent rapid seeks
            let lastSeekTime = 0;
            
            // Add seek handler with rate limiting and recovery
            wavesurfer.on("seek", () => {
                const now = Date.now();
                if (now - lastSeekTime < 500) {
                    console.log("Seek ignored to avoid overload");
                    return;
                }
                lastSeekTime = now;

                // Optionally reload or reinit only if backend is dead
                if (!wavesurfer.backend || !wavesurfer.backend.buffer) {
                    console.warn("Reinitializing due to seek error");
                    
                    // Use throttled loading for recovery attempts too
                    loadWaveSurferThrottled(wavesurfer, finalUrl);
                }
            });
            
            // Set up player controls
            if (playButton) {
                playButton.addEventListener('click', function() {
                    // ✳️ Safe playback toggle
                    if (!wavesurfer.backend || !wavesurfer.backend.buffer) {
                        console.warn("WaveSurfer backend missing, cannot play");
                        return;
                    }

                    try {
                        if (wavesurfer.isPlaying()) {
                            wavesurfer.pause();
                            playButton.innerHTML = '<i aria-hidden="true" class="jki jki-play"></i>';
                        } else {
                            wavesurfer.play();
                            playButton.innerHTML = '<i aria-hidden="true" class="jki jki-pause"></i>';
                        }
                    } catch (e) {
                        console.error("Playback error:", e);
                    }
                });
            }
            
            // Set up player events
            wavesurfer.on('ready', () => {
                container.classList.add('ready');
                const duration = wavesurfer.getDuration();
                if (timeDisplay) {
                    timeDisplay.textContent = `0:00 / ${formatTime(duration)}`;
                }
                console.log(`Player ready. Duration: ${duration}s`);
                
                if (progressBar) {
                    progressBar.value = 0;
                    progressBar.addEventListener('input', function() {
                        const seekPos = progressBar.value / 100;
                        wavesurfer.seekTo(seekPos);
                    });
                }
            });
            
            wavesurfer.on('audioprocess', () => {
                const currentTime = wavesurfer.getCurrentTime();
                const duration = wavesurfer.getDuration();
                const progress = currentTime / duration;
                
                if (progressBar) {
                    progressBar.value = progress * 100;
                }
                
                if (timeDisplay) {
                    timeDisplay.textContent = `${formatTime(currentTime)} / ${formatTime(duration)}`;
                }
            });
            
            wavesurfer.on('finish', () => {
                if (playButton) {
                    playButton.innerHTML = '<i aria-hidden="true" class="jki jki-play"></i>';
                }
                wavesurfer.seekTo(0);
            });
            
            // Add error handling with fallback to native player
            wavesurfer.on('error', (err) => {
                console.error('WaveSurfer error:', err);
                
                // Check if the error is a network error like QUIC_PROTOCOL_ERROR
                const isNetworkError = err && (
                    err.message?.includes('network') || 
                    err.message?.includes('fetch') || 
                    err.message?.includes('QUIC') ||
                    err.message?.includes('protocol')
                );
                
                // Always fall back to native player on error
                console.log(`Error with WaveSurfer${isNetworkError ? ' (network-related)' : ''}, falling back to native HTML5 audio player`);
                
                try {
                    // Cleanup the failed WaveSurfer instance
                    wavesurfer.destroy();
                    
                    // Remove the player from instances array
                    const instanceIndex = playerInstances.indexOf(wavesurfer);
                    if (instanceIndex > -1) {
                        playerInstances.splice(instanceIndex, 1);
                    }
                    
                    // Clear the container
                    wavesurferContainer.innerHTML = '';
                    
                    // Create native HTML5 audio element
                    const audioElement = document.createElement('audio');
                    audioElement.controls = true;
                    audioElement.preload = 'metadata'; // Use metadata instead of auto for better initial load
                    audioElement.crossOrigin = 'anonymous';
                    audioElement.src = finalUrl;
                    audioElement.style.width = '100%';
                    audioElement.style.margin = '10px 0';
                    audioElement.className = 'jet-audio-native-player';
                    
                    // Replace wavesurfer with the native player
                    wavesurferContainer.appendChild(audioElement);
                    
                    // Update container state
                    container.classList.add('native-player');
                    container.classList.add('ready');
                    container.classList.remove('loading');
                    
                    // Hide waveform-specific elements
                    if (progressBar) progressBar.style.display = 'none';
                    
                    // Setup audio element events
                    audioElement.addEventListener('loadedmetadata', () => {
                        if (timeDisplay) {
                            timeDisplay.textContent = `0:00 / ${formatTime(audioElement.duration)}`;
                        }
                    });
                    
                    audioElement.addEventListener('timeupdate', () => {
                        if (timeDisplay) {
                            timeDisplay.textContent = `${formatTime(audioElement.currentTime)} / ${formatTime(audioElement.duration)}`;
                        }
                    });
                    
                    audioElement.addEventListener('error', (audioErr) => {
                        console.error('Native player error:', audioErr);
                        displayErrorMessage(container, `Error playing audio: ${audioErr.target.error?.message || 'Unknown error'}`);
                    });
                    
                    // Set up play button functionality if it exists
                    if (playButton) {
                        playButton.addEventListener('click', function() {
                            if (audioElement.paused) {
                                audioElement.play().catch(e => console.error('Error playing audio:', e));
                                playButton.innerHTML = '<i aria-hidden="true" class="jki jki-pause"></i>';
                            } else {
                                audioElement.pause();
                                playButton.innerHTML = '<i aria-hidden="true" class="jki jki-play"></i>';
                            }
                        });
                    }
                } catch (fallbackErr) {
                    console.error('Error while falling back to native player:', fallbackErr);
                    displayErrorMessage(container, `Error loading audio: ${err}`);
                }
            });
            
            // Try to load the audio with WaveSurfer
            try {
                // Use our safe XHR loader to avoid QUIC protocol issues
                console.log(`Loading audio from ${finalUrl} using XHR to prevent protocol errors`);
                
                // Load using XHR and create a blob URL
                try {
                    const blobUrl = await safeLoadAudioWithXHR(finalUrl);
                    console.log('Successfully loaded audio via XHR, loading into WaveSurfer');
                    loadWaveSurferThrottled(wavesurfer, blobUrl);
                    container.classList.add('initialized');
                } catch (xhrError) {
                    console.error('Error during XHR load, falling back to native player:', xhrError);
                    wavesurfer.fireEvent('error', xhrError);
                }
            } catch (loadError) {
                console.error('Error during WaveSurfer load:', loadError);
                // Manually trigger the error handler to fall back to native player
                wavesurfer.fireEvent('error', loadError);
            }
            
            return wavesurfer;
        } catch (err) {
            console.error('Error creating WaveSurfer instance:', err);
            
            // Fallback directly to native audio if WaveSurfer creation fails
            try {
                const audioElement = document.createElement('audio');
                audioElement.controls = true;
                audioElement.preload = 'auto';
                audioElement.src = finalUrl;
                audioElement.style.width = '100%';
                audioElement.className = 'jet-audio-native-player';
                
                // Clear container and add native player
                wavesurferContainer.innerHTML = '';
                wavesurferContainer.appendChild(audioElement);
                
                // Update container state
                container.classList.add('native-player');
                container.classList.add('ready');
                container.classList.remove('loading');
                
                console.log('Created native HTML5 player as fallback');
            } catch (fallbackErr) {
                displayErrorMessage(container, `Error initializing player: ${err.message}`);
            }
            
            return null;
        }
    }
    
    /**
     * Fetches the duration of an audio file via a HEAD request
     */
    async function fetchAudioDuration(url) {
        console.log('Fetching audio duration for:', url);
        try {
            // Use XMLHttpRequest instead of fetch to avoid QUIC protocol issues
            const xhr = new XMLHttpRequest();
            xhr.open('HEAD', url, true);
            
            // Add custom headers to improve HEAD request handling
            xhr.setRequestHeader('Accept', 'audio/*');
            xhr.setRequestHeader('Cache-Control', 'no-cache');
            xhr.setRequestHeader('X-HTTP-Version', 'HTTP/1.1'); // Force HTTP/1.1
            xhr.setRequestHeader('Connection', 'keep-alive'); // Use persistent connections
            
            // Return a promise
            return new Promise((resolve, reject) => {
                xhr.onload = function() {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        // Try to get content length from headers
                        const contentLength = xhr.getResponseHeader('Content-Length');
                        const contentType = xhr.getResponseHeader('Content-Type');
                        
                        console.log('Headers received:', 
                            'Content-Length:', contentLength, 
                            'Content-Type:', contentType
                        );
                        
                        if (contentLength) {
                            // Estimate duration based on file size and bitrate
                            const fileSizeBytes = parseInt(contentLength, 10);
                            const estimatedDuration = (fileSizeBytes * 8) / CONFIG.estimatedBitrate;
                            
                            console.log(`Estimated duration: ${estimatedDuration}s from size ${fileSizeBytes} bytes`);
                            return resolve(estimatedDuration);
                        }
                        
                        // If no content length, try a secondary approach with a small GET request
                        console.log('No Content-Length in HEAD request, trying small GET request to determine file type');
                        tryGetWithRange(url, resolve, reject);
                    } else {
                        console.warn(`HEAD request failed with status ${xhr.status}, trying GET request`);
                        tryGetWithRange(url, resolve, reject);
                    }
                };
                
                xhr.onerror = function() {
                    console.error('Error fetching audio duration with HEAD');
                    tryGetWithRange(url, resolve, reject);
                };
                
                xhr.send();
            });
        } catch (error) {
            console.error('Exception in fetchAudioDuration:', error);
            return null;
        }
    }
    
    /**
     * Try a small range GET request to determine file properties
     */
    function tryGetWithRange(url, resolve, reject) {
        const xhr = new XMLHttpRequest();
        // Request only the first 128KB which should contain file headers
        xhr.open('GET', url, true);
        xhr.setRequestHeader('Range', 'bytes=0-131071');
        xhr.responseType = 'arraybuffer';
        
        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 300 || xhr.status === 206) {
                // Get content range header if available
                const contentRange = xhr.getResponseHeader('Content-Range');
                const contentLength = xhr.getResponseHeader('Content-Length');
                
                console.log('Range request headers:', 
                    'Content-Range:', contentRange, 
                    'Content-Length:', contentLength
                );
                
                let totalSize = 0;
                
                // Try to parse content-range header (format: "bytes 0-131071/4324351")
                if (contentRange) {
                    const match = contentRange.match(/bytes \d+-\d+\/(\d+)/);
                    if (match && match[1]) {
                        totalSize = parseInt(match[1], 10);
                    }
                } else if (contentLength) {
                    // If we got a full response, use content-length
                    totalSize = parseInt(contentLength, 10);
                }
                
                if (totalSize > 0) {
                    // Estimate duration using bitrate
                    const estimatedDuration = (totalSize * 8) / CONFIG.estimatedBitrate;
                    console.log(`Estimated duration from range request: ${estimatedDuration}s from size ${totalSize} bytes`);
                    resolve(estimatedDuration);
                } else {
                    console.warn('Could not determine file size from range request');
                    resolve(null);
                }
            } else {
                console.warn(`Range request failed with status ${xhr.status}`);
                resolve(null);
            }
        };
        
        xhr.onerror = function() {
            console.error('Error with range request');
            resolve(null); // Resolve with null to fall back to default loading
        };
        
        xhr.send();
    }
    
    /**
     * Function to fetch audio as blob to avoid QUIC protocol errors
     */
    async function fetchAudioAsBlob(url) {
        console.log('Fetching audio blob for:', url);
        try {
            // Use XMLHttpRequest to avoid QUIC issues
            const xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.responseType = 'blob';
            
            // Add progress tracking
            xhr.onprogress = function(e) {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    console.log(`Loading progress: ${percentComplete.toFixed(2)}%`);
                }
            };
            
            // Add custom headers to improve blob request handling
            xhr.setRequestHeader('Accept', 'audio/*');
            xhr.setRequestHeader('X-HTTP-Version', 'HTTP/1.1'); // Force HTTP/1.1
            xhr.setRequestHeader('Connection', 'keep-alive'); // Use persistent connections
            
            // Return a promise
            return new Promise((resolve, reject) => {
                xhr.onload = function() {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        const audioBlob = xhr.response;
                        // Create a blob URL from the response
                        const blobUrl = URL.createObjectURL(audioBlob);
                        console.log('Audio blob created:', blobUrl);
                        initializedUrls[url] = true;
                        resolve(blobUrl);
                    } else {
                        console.error('Error fetching audio:', xhr.status, xhr.statusText);
                        reject(new Error(`Failed to load audio: ${xhr.status}`));
                    }
                };
                
                xhr.onerror = function() {
                    console.error('Network error fetching audio');
                    reject(new Error('Network error fetching audio'));
                };
                
                xhr.send();
            });
        } catch (error) {
            console.error('Error fetching audio as blob:', error);
            throw error;
        }
    }
    
    /**
     * Initialize player for Elementor widget
     */
    async function initializeElementorPlayer(player, audioUrl) {
        console.log('Initializing Elementor player for:', audioUrl);
        
        // Add throttling for WaveSurfer load calls
        let seekThrottleTimeout = null;
        let lastSeekURL = null;

        function loadWaveSurferThrottled(waveSurfer, url) {
          if (url === lastSeekURL) return;
          lastSeekURL = url;

          if (seekThrottleTimeout) {
            clearTimeout(seekThrottleTimeout);
          }

          seekThrottleTimeout = setTimeout(() => {
            waveSurfer.load(url);
          }, 150); // Delay to prevent rapid seek overload
        }
        
        // DIRECT FIX: Check again for malformed URLs with /play/http pattern
        if (audioUrl.includes('/play/http')) {
            // Extract the direct URL after /play/
            const urlParts = audioUrl.split('/play/');
            if (urlParts.length > 1 && urlParts[1].startsWith('http')) {
                const directUrl = urlParts[1];
                
                // Try to extract just the filename
                try {
                    const url = new URL(directUrl);
                    const pathParts = url.pathname.split('/');
                    const filename = pathParts[pathParts.length - 1];
                    
                    // If we have a valid filename, use our endpoint with just the filename
                    if (filename) {
                        audioUrl = `/wp-json/jetengine-audio-stream/v1/play/${filename}`;
                        console.log('Fixed malformed URL in initializeElementorPlayer to use filename:', audioUrl);
                        
                        // Update the player's data-url attribute
                        player.setAttribute('data-url', audioUrl);
                    }
                } catch (error) {
                    console.error('Error parsing URL in initializeElementorPlayer:', error);
                    // Continue with the original URL
                }
            }
        }
        
        const waveformContainer = player.querySelector(".waveform");
        const playButton = player.querySelector(".play-button");
        const timeDisplay = player.querySelector(".time-display");

        if (!waveformContainer || !playButton || !timeDisplay) {
            console.error('Required Elementor player UI elements not found in', player);
            displayErrorMessage(player, 'Player UI incomplete for Elementor.');
            return;
        }

        // Check if this is a streaming endpoint URL
        const isStreamEndpoint = audioUrl.includes('/jetengine-audio-stream/v1/play/');
        let finalUrl = audioUrl;
        let useStreaming = true;
        let fileSize = 0;
        let fileType = null;
        let isAudioFile = false;
        
        // If this is a stream endpoint URL, do a HEAD request to check properties
        if (isStreamEndpoint) {
            console.log('Performing HEAD request to check stream endpoint properties');
            const urlProps = await checkUrlProperties(audioUrl);
            
            // Handle redirect (status 3xx)
            if (urlProps.isRedirect && urlProps.redirectUrl) {
                console.log(`Endpoint redirects to: ${urlProps.redirectUrl}`);
                finalUrl = urlProps.redirectUrl;
                
                // Check properties of the redirect target
                const redirectProps = await checkUrlProperties(urlProps.redirectUrl);
                
                fileType = redirectProps.contentType || '';
                fileSize = redirectProps.contentLength || 0;
                isAudioFile = fileType && fileType.startsWith('audio/');
                
                // If it's a small audio file, use directly
                if (isAudioFile && fileSize && fileSize < 10 * 1024 * 1024) {
                    console.log(`Redirect target is small ${fileType} file (${(fileSize/1024/1024).toFixed(2)}MB), using directly`);
                    useStreaming = false;
                }
            }
            // For successful responses with content info
            else if (urlProps.contentType) {
                fileType = urlProps.contentType;
                fileSize = urlProps.contentLength || 0;
                isAudioFile = fileType && fileType.startsWith('audio/');
                
                // If it's a small audio file, use directly
                if (isAudioFile && fileSize && fileSize < 10 * 1024 * 1024) {
                    console.log(`Endpoint serves small ${fileType} file (${(fileSize/1024/1024).toFixed(2)}MB), using directly`);
                    useStreaming = false;
                }
            }
        } else {
            // Not a streaming endpoint, check if it's a direct media file
            const isDirectMedia = isDirectMediaFile(audioUrl);
            if (isDirectMedia) {
                console.log('URL is a direct media file, using directly');
                useStreaming = false;
                isAudioFile = true; // Assume it's audio if it has a media extension
            }
        }
        
        console.log(`Final Elementor player URL: ${finalUrl} (Streaming: ${useStreaming ? 'Yes' : 'No'}, Audio: ${isAudioFile ? 'Yes' : 'No'})`);

        try {
            // Base player options - always use MediaElement and auto preload
            let wavesurferOptions = {
                container: waveformContainer,
                waveColor: createGradient(waveformContainer, 'unplayed'),
                progressColor: createGradient(waveformContainer, 'played'),
                cursorColor: '#f85032',
                cursorWidth: 2,
                barWidth: 2,
                barGap: 1,
                barRadius: 0,
                barHeight: 1,
                barMinHeight: 1,
                height: 100,
                normalize: true,
                backend: 'MediaElement', // Always use MediaElement backend
                mediaControls: false,
                hideScrollbar: false,
                autoCenter: true,
                responsive: true,
                xhr: null,
                transport: 'xhr', // Explicitly use XHR instead of fetch to avoid QUIC issues
                preload: 'auto' // Always use auto preload
            };
            
            // Configure fetch options based on streaming vs direct
            if (!useStreaming) {
                // For direct files, simplify headers
                if (wavesurferOptions.fetchParams && wavesurferOptions.fetchParams.headers) {
                    // Remove Range headers for direct files
                    delete wavesurferOptions.fetchParams.headers['Range'];
                    // Use cache for performance
                    wavesurferOptions.fetchParams.cache = 'force-cache';
                }
            } else {
                // For streaming, ensure proper headers
                if (!wavesurferOptions.fetchParams) {
                    wavesurferOptions.fetchParams = {};
                }
                if (!wavesurferOptions.fetchParams.headers) {
                    wavesurferOptions.fetchParams.headers = {};
                }
                wavesurferOptions.fetchParams.headers['Accept'] = 'audio/*';
                
                // For streaming endpoints, try to fetch duration first
                console.log('Using streaming setup for Elementor player, fetching duration first');
                const fetchedDuration = await fetchAudioDuration(finalUrl);
                if (fetchedDuration && fetchedDuration > 0) {
                    wavesurferOptions.duration = fetchedDuration;
                    console.log('Applying fetched duration to Elementor WaveSurfer options:', fetchedDuration);
                }
            }

            // Create WaveSurfer instance
            const wave = WaveSurfer.create(wavesurferOptions);
            playerInstances.push(wave);

            // Track last seek time to prevent rapid seeks
            let lastSeekTime = 0;
            
            // Add seek handler with rate limiting and recovery
            wave.on("seek", () => {
                const now = Date.now();
                if (now - lastSeekTime < 500) {
                    console.log("Seek ignored to avoid overload");
                    return;
                }
                lastSeekTime = now;

                // Optionally reload or reinit only if backend is dead
                if (!wave.backend || !wave.backend.buffer) {
                    console.warn("Reinitializing due to seek error");
                    
                    // Use throttled loading for recovery attempts too
                    loadWaveSurferThrottled(wave, finalUrl);
                }
            });

            // Set up basic event handlers
            if (playButton) {
                playButton.addEventListener("click", function() {
                    // ✳️ Safe playback toggle
                    if (!wave.backend || !wave.backend.buffer) {
                        console.warn("WaveSurfer backend missing, cannot play");
                        return;
                    }

                    try {
                        if (wave.isPlaying()) {
                            wave.pause();
                        } else {
                            wave.play();
                        }
                    } catch (e) {
                        console.error("Playback error:", e);
                    }
                });
            }

            wave.on('play', () => {
                if (playButton) playButton.innerHTML = '<i aria-hidden="true" class="jki jki-pause-line"></i>';
            });
            
            wave.on('pause', () => {
                if (playButton) playButton.innerHTML = '<i aria-hidden="true" class="jki jki-play"></i>';
            });

            wave.on('ready', function() {
                const duration = wave.getDuration();
                console.log('Elementor player ready. WaveSurfer reported duration:', duration);
                
                // Check if wavesurfer failed to properly decode the audio
                if (!wave.backend.buffer || isNaN(wave.getDuration())) {
                    console.warn('WaveSurfer failed to decode duration, using native fallback');
                    // Create fallback native player
                    try {
                        // Clean up the failed WaveSurfer instance
                        wave.destroy();
                        
                        // Remove from player instances
                        const instanceIndex = playerInstances.indexOf(wave);
                        if (instanceIndex > -1) {
                            playerInstances.splice(instanceIndex, 1);
                        }
                        
                        // Clear the waveform container
                        waveformContainer.innerHTML = '';
                        
                        // Create native HTML5 audio element
                        const audioElement = document.createElement('audio');
                        audioElement.controls = true;
                        audioElement.preload = 'auto';
                        audioElement.src = finalUrl;
                        audioElement.style.width = '100%';
                        audioElement.className = 'elementor-audio-native-player';
                        
                        // Add to container
                        waveformContainer.appendChild(audioElement);
                        player.classList.add('native-player');
                    } catch (fallbackErr) {
                        console.error('Error creating native player fallback:', fallbackErr);
                    }
                } else {
                    if (timeDisplay) {
                        timeDisplay.textContent = `0:00 / ${formatTime(duration)}`;
                    }
                }
            });

            wave.on('audioprocess', function() {
                if (timeDisplay) {
                    const currentTime = wave.getCurrentTime();
                    const duration = wave.getDuration();
                    // Update current time / total time
                    timeDisplay.textContent = `${formatTime(currentTime)} / ${formatTime(duration)}`;
                }
            });
            
            wave.on('finish', () => {
                if (playButton) {
                    playButton.innerHTML = '<i aria-hidden="true" class="jki jki-play"></i>';
                }
                wave.seekTo(0);
            });

            // Add error handling with fallback to native player
            wave.on('error', function(err) {
                console.error('Elementor player WaveSurfer error:', err);
                
                // Check if the error is a network error like QUIC_PROTOCOL_ERROR
                const isNetworkError = err && (
                    err.message?.includes('network') || 
                    err.message?.includes('fetch') || 
                    err.message?.includes('QUIC') ||
                    err.message?.includes('protocol')
                );
                
                // Check if we have a direct URL available from our earlier fix
                const directUrl = player.getAttribute('data-direct-url');
                
                if (directUrl) {
                    console.log('Attempting fallback to direct file URL:', directUrl);
                    
                    try {
                        // Create new audio element with direct URL
                        const audioElement = document.createElement('audio');
                        audioElement.controls = true;
                        audioElement.preload = 'metadata';
                        audioElement.crossOrigin = 'anonymous';
                        audioElement.src = directUrl; // Use direct URL instead
                        audioElement.style.width = '100%';
                        audioElement.className = 'elementor-audio-native-player direct-fallback';
                        
                        // Clean up failed WaveSurfer
                        wave.destroy();
                        waveformContainer.innerHTML = '';
                        
                        // Add native player
                        waveformContainer.appendChild(audioElement);
                        player.classList.add('direct-fallback');
                        
                        // Add error event listener
                        audioElement.addEventListener('error', (audioErr) => {
                            console.error('Native player direct URL error:', audioErr);
                            
                            // Try regular fallback if direct URL fails
                            audioElement.src = finalUrl;
                            console.log('Direct URL failed, trying endpoint URL instead');
                        });
                        
                        // Set up events
                        audioElement.addEventListener('loadedmetadata', () => {
                            if (timeDisplay) {
                                timeDisplay.textContent = `0:00 / ${formatTime(audioElement.duration)}`;
                            }
                        });
                        
                        return; // Skip the rest of the error handling
                    } catch (directError) {
                        console.error('Error using direct URL fallback:', directError);
                    }
                }
                
                // Continue with regular fallback if direct URL approach failed
                console.log(`Error with Elementor WaveSurfer${isNetworkError ? ' (network-related)' : ''}, falling back to native HTML5 audio player`);
                
                try {
                    // Clean up the failed WaveSurfer instance
                    wave.destroy();
                    
                    // Remove from player instances
                    const instanceIndex = playerInstances.indexOf(wave);
                    if (instanceIndex > -1) {
                        playerInstances.splice(instanceIndex, 1);
                    }
                    
                    // Clear the waveform container
                    waveformContainer.innerHTML = '';
                    
                    // Create native HTML5 audio element
                    const audioElement = document.createElement('audio');
                    audioElement.controls = true;
                    audioElement.preload = 'metadata';
                    audioElement.crossOrigin = 'anonymous';
                    audioElement.src = finalUrl;
                    audioElement.style.width = '100%';
                    audioElement.className = 'elementor-audio-native-player';
                    
                    // Add to container
                    waveformContainer.appendChild(audioElement);
                    player.classList.add('native-player');
                    
                    // Setup events
                    audioElement.addEventListener('loadedmetadata', () => {
                        if (timeDisplay) {
                            timeDisplay.textContent = `0:00 / ${formatTime(audioElement.duration)}`;
                        }
                    });
                    
                    audioElement.addEventListener('timeupdate', () => {
                        if (timeDisplay) {
                            timeDisplay.textContent = `${formatTime(audioElement.currentTime)} / ${formatTime(audioElement.duration)}`;
                        }
                    });
                    
                    audioElement.addEventListener('error', (audioErr) => {
                        console.error('Native player error:', audioErr);
                        displayErrorMessage(container, `Error playing audio: ${audioErr.target.error?.message || 'Unknown error'}`);
                    });
                    
                    // Set up play button for native player
                    if (playButton) {
                        playButton.addEventListener('click', function() {
                            if (audioElement.paused) {
                                audioElement.play().catch(e => console.error('Error playing audio:', e));
                                playButton.innerHTML = '<i aria-hidden="true" class="jki jki-pause-line"></i>';
                            } else {
                                audioElement.pause();
                                playButton.innerHTML = '<i aria-hidden="true" class="jki jki-play"></i>';
                            }
                        });
                    }
                    
                    // Handle native player errors
                    audioElement.addEventListener('error', (audioErr) => {
                        console.error('Native audio player error in Elementor:', audioErr);
                        if (timeDisplay) {
                            timeDisplay.textContent = "Error loading audio";
                        }
                        displayErrorMessage(player, `Could not play audio: ${audioErr.target.error?.message || 'Unknown error'}`);
                    });
                } catch (fallbackErr) {
                    console.error('Error creating native player fallback:', fallbackErr);
                    if (timeDisplay) {
                        timeDisplay.textContent = "Error loading audio";
                    }
                    displayErrorMessage(player, `Error loading audio: ${err.message || 'Unknown error'}`);
                }
            });

            // Load the audio
            try {
                console.log(`Loading audio from ${finalUrl} in Elementor player`);
                
                // Use our custom XHR implementation to avoid QUIC protocol errors
                try {
                    // Load audio with XHR instead of fetch
                    const blobUrl = await safeLoadAudioWithXHR(finalUrl);
                    console.log('Successfully created blob URL, loading into WaveSurfer');
                    loadWaveSurferThrottled(wave, blobUrl);
                } catch (error) {
                    console.error('JetEngine Audio Stream: XHR load error - falling back', error);

                    // Delay fallback slightly to prevent UI jitter on large files
                    setTimeout(() => {
                        // Create fallback native player
                        try {
                            // Clean up the failed WaveSurfer instance
                            wave.destroy();
                            
                            // Remove from player instances
                            const instanceIndex = playerInstances.indexOf(wave);
                            if (instanceIndex > -1) {
                                playerInstances.splice(instanceIndex, 1);
                            }
                            
                            // Clear the waveform container
                            waveformContainer.innerHTML = '';
                            
                            // Create native HTML5 audio element with crossOrigin set to anonymous
                            const audioElement = document.createElement('audio');
                            audioElement.controls = true;
                            audioElement.preload = 'metadata'; // Use metadata instead of auto for initial load
                            audioElement.crossOrigin = 'anonymous'; // Add crossOrigin
                            audioElement.src = finalUrl;
                            audioElement.style.width = '100%';
                            audioElement.className = 'elementor-audio-native-player';
                            
                            // Add to container
                            waveformContainer.appendChild(audioElement);
                            player.classList.add('native-player');
                            
                            // Show user-friendly message
                            console.log('Using native HTML5 audio player for better compatibility with large files');
                        } catch (fallbackErr) {
                            console.error('Error creating native player fallback:', fallbackErr);
                        }
                    }, 1000);
                }
            } catch (loadError) {
                console.error('Error during WaveSurfer load in Elementor player:', loadError);
                // Manually trigger the error handler to fall back to native player
                wave.fireEvent('error', loadError);
            }

        } catch (error) {
            console.error('Error creating Elementor WaveSurfer instance:', error);
            
            // Fallback directly to native audio if WaveSurfer creation fails
            try {
                const audioElement = document.createElement('audio');
                audioElement.controls = true;
                audioElement.preload = 'auto';
                audioElement.src = finalUrl;
                audioElement.style.width = '100%';
                audioElement.className = 'elementor-audio-native-player';
                
                // Clear container and add native player
                waveformContainer.innerHTML = '';
                waveformContainer.appendChild(audioElement);
                player.classList.add('native-player');
                
                console.log('Created native HTML5 player as fallback for Elementor');
            } catch (fallbackErr) {
                displayErrorMessage(player, 'Could not initialize Elementor player.');
            }
        }
    }
    
    /**
     * Create gradient for visualization
     */
    function createGradient(container, type = 'unplayed') {
        const ctx = document.createElement('canvas').getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, container.offsetHeight);
        
        if (type === 'played') {
            // Gradient for played portion
            gradient.addColorStop(0, "#2d5bbd");
            gradient.addColorStop(0.5, "#3e4bbd");
            gradient.addColorStop(1, "#4F4A85");
        } else {
            // Gradient for unplayed portion
            gradient.addColorStop(0, "#8bc4ff");
            gradient.addColorStop(0.7, "#6dacf7");
            gradient.addColorStop(1, "#8395c2");
        }
        
        return gradient;
    }
    
    /**
     * Resolve filename to attachment ID
     */
    async function resolveEndpoint(url) {
        console.log('Resolving endpoint for file path/URL:', url);

        // Special handling for already malformed URLs that start with the endpoint
        const endpointPrefix = CONFIG.endpoint.startsWith('http') 
            ? CONFIG.endpoint 
            : new URL(CONFIG.endpoint, window.location.origin).href;
        
        // If the URL is already in the format /play/https://.../file.mp3, extract the actual URL part
        if (url.startsWith(endpointPrefix) && url.substring(endpointPrefix.length).includes('http')) {
            url = url.substring(endpointPrefix.length);
            console.log('Extracted embedded URL from malformed endpoint:', url);
        }

        let absoluteFileUrl;
        try {
            // If url is already absolute, new URL(url) works. 
            // If it's relative like /wp-content/..., new URL(url, window.location.origin) makes it absolute.
            // If it's like "file.mp3", it becomes window.location.origin/file.mp3
            absoluteFileUrl = new URL(url, window.location.origin).href;
        } catch (e) {
            console.error("Invalid file path/URL for URL construction:", url, e);
            return null;
        }
        
        // Extract filename using a more robust approach
        let filenameWithoutExtension;
        
        try {
            const urlObj = new URL(absoluteFileUrl);
            const pathname = urlObj.pathname;
            
            // First, get the filename with extension from the path
            const pathParts = pathname.split('/');
            const filenameWithExt = pathParts[pathParts.length - 1];
            
            // Check if there's an extension
            const extensionMatch = filenameWithExt.match(/^(.+?)\.([^.]+)$/);
            if (extensionMatch) {
                filenameWithoutExtension = extensionMatch[1];
            } else {
                // No extension found, just use the whole filename
                filenameWithoutExtension = filenameWithExt;
            }
            
            // Handle special cases where filename might contain encoded characters
            filenameWithoutExtension = decodeURIComponent(filenameWithoutExtension);
            
            console.log('Extracted filename for resolution:', filenameWithoutExtension);
        } catch (e) {
            console.error("Error extracting filename from URL:", absoluteFileUrl, e);
            return null;
        }
        
        if (!filenameWithoutExtension || filenameWithoutExtension === '') {
            console.warn("Unable to extract valid filename from URL:", absoluteFileUrl);
            return null;
        }
        
        try {
            const resolveBaseUrl = CONFIG.resolveEndpoint.startsWith('http') 
                ? CONFIG.resolveEndpoint 
                : new URL(CONFIG.resolveEndpoint, window.location.origin).href;
            
            const resolverUrl = new URL(resolveBaseUrl);
            resolverUrl.searchParams.append('filename', filenameWithoutExtension);
            
            console.log('Querying resolve endpoint with filename:', filenameWithoutExtension);
            console.log('Full resolution URL:', resolverUrl.toString());
            
            const response = await fetch(resolverUrl.toString());
            if (!response.ok) {
                console.error(`Error resolving endpoint: ${response.status} ${response.statusText} for ${resolverUrl.toString()}`);
                try {
                    const errorData = await response.text();
                    console.error("Response body:", errorData);
                } catch (e) {
                    // ignore if can't read error body
                }
                return null;
            }
            
            const data = await response.json();
            console.log('Resolution response:', data);
            
            // Check if the response was successful
            if (data.success && data.id) {
                // Store file metadata in a global cache so it can be used by players
                if (!window.JetEngineAudioFileMetadata) {
                    window.JetEngineAudioFileMetadata = {};
                }
                
                // Cache the metadata using the id as key
                window.JetEngineAudioFileMetadata[data.id] = {
                    fileSize: data.size || 0,
                    mimeType: data.mime || '',
                    url: data.url || '',
                    isAudio: data.mime && data.mime.startsWith('audio/')
                };
                
                // Use direct URL for smaller files when available
                if (data.url && data.size && data.size < 10 * 1024 * 1024) {
                    console.log(`Using direct URL for small file (${(data.size/1024/1024).toFixed(2)}MB):`, data.url);
                    return data.url;
                }
                
                // For larger files, use the streaming endpoint
                const playBaseUrl = CONFIG.endpoint.startsWith('http') 
                    ? CONFIG.endpoint 
                    : new URL(CONFIG.endpoint, window.location.origin).href;
                    
                // Ensure playBaseUrl ends with a slash, then append ID
                const cleanPlayBaseUrl = playBaseUrl.endsWith('/') ? playBaseUrl : playBaseUrl + '/';
                return `${cleanPlayBaseUrl}${data.id}`;
            } else {
                console.warn("Resolution response did not contain expected data:", data);
                return null;
            }
        } catch (err) {
            console.error("Exception during endpoint resolution:", err);
            return null;
        }
    }
    
    /**
     * Format time as MM:SS
     */
    function formatTime(time) {
        if (!isFinite(time)) return "0:00";
        
        const minutes = Math.floor(time / 60);
        const seconds = Math.floor(time % 60);
        return `${minutes}:${seconds.toString().padStart(2, '0')}`;
    }
    
    /**
     * Display error message in player
     */
    function displayErrorMessage(player, message) {
        console.error('Player error:', message);
        
        // Mark the player as failed so it can be retried later
        player.classList.add('failed');
        
        // Remove any existing error messages
        const existingErrors = player.querySelectorAll('.audio-error');
        existingErrors.forEach(el => el.remove());
        
        // Create new error container
        const errorContainer = document.createElement('div');
        errorContainer.className = 'audio-error';
        errorContainer.textContent = message;
        errorContainer.style.color = '#d63638';
        errorContainer.style.marginTop = '10px';
        player.appendChild(errorContainer);
        
        // Add retry button if the error isn't a temporary one
        if (!message.includes('Format error')) {
            const retryButton = document.createElement('button');
            retryButton.className = 'retry-button';
            retryButton.textContent = 'Retry';
            retryButton.style.marginTop = '10px';
            retryButton.style.padding = '5px 10px';
            retryButton.style.background = '#135e96';
            retryButton.style.color = '#fff';
            retryButton.style.border = 'none';
            retryButton.style.borderRadius = '4px';
            retryButton.style.cursor = 'pointer';
            
            retryButton.addEventListener('click', function() {
                // Remove the 'initialized' class so the player will be re-initialized
                player.classList.remove('initialized');
                // Remove all error messages and audio elements
                player.querySelectorAll('.audio-error, audio').forEach(el => el.remove());
                // Trigger re-initialization
                initializeWaveSurfers();
            });
            
            errorContainer.appendChild(retryButton);
        }
    }
    
    /**
     * Show a toast notification
     * @param {string} message - The message to display
     * @param {number} duration - Duration in ms to show the toast
     */
    function showToast(message, duration = 5000) {
        // Create toast container if it doesn't exist
        let toastContainer = document.getElementById('jet-audio-toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'jet-audio-toast-container';
            toastContainer.style.position = 'fixed';
            toastContainer.style.bottom = '20px';
            toastContainer.style.right = '20px';
            toastContainer.style.zIndex = '9999';
            document.body.appendChild(toastContainer);
        }
        
        // Create the toast
        const toast = document.createElement('div');
        toast.className = 'jet-audio-toast';
        toast.textContent = message;
        toast.style.padding = '10px 15px';
        toast.style.margin = '5px 0';
        toast.style.backgroundColor = 'rgba(0, 0, 0, 0.7)';
        toast.style.color = 'white';
        toast.style.borderRadius = '4px';
        toast.style.boxShadow = '0 2px 5px rgba(0, 0, 0, 0.2)';
        toast.style.transition = 'opacity 0.3s ease-in-out';
        toast.style.opacity = '0';
        
        // Add to container
        toastContainer.appendChild(toast);
        
        // Trigger reflow and fade in
        setTimeout(() => {
            toast.style.opacity = '1';
        }, 10);
        
        // Auto-remove after duration
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
                
                // Remove container if empty
                if (toastContainer.children.length === 0) {
                    document.body.removeChild(toastContainer);
                }
            }, 300);
        }, duration);
    }
    
    /**
     * Create a retry button for failed audio player
     * @param {HTMLElement} container - Container to add the button to
     * @param {string} audioUrl - URL to retry loading
     * @param {Function} initFunction - Function to call on retry
     */
    function createRetryButton(container, audioUrl, initFunction) {
        const retryButton = document.createElement('button');
        retryButton.className = 'jet-audio-retry-button';
        retryButton.textContent = 'Retry Loading Player';
        retryButton.style.display = 'block';
        retryButton.style.margin = '10px auto';
        retryButton.style.padding = '8px 15px';
        retryButton.style.backgroundColor = '#4F4A85';
        retryButton.style.color = 'white';
        retryButton.style.border = 'none';
        retryButton.style.borderRadius = '4px';
        retryButton.style.cursor = 'pointer';
        
        retryButton.addEventListener('click', function() {
            // Remove the retry button
            container.removeChild(retryButton);
            
            // Clear the container
            const errorElements = container.querySelectorAll('.audio-error');
            errorElements.forEach(el => el.parentNode.removeChild(el));
            
            const audioElements = container.querySelectorAll('audio');
            audioElements.forEach(el => el.parentNode.removeChild(el));
            
            // Remove native player class
            container.classList.remove('native-player');
            
            // Call the init function again
            initFunction(container, audioUrl);
        });
        
        container.appendChild(retryButton);
        return retryButton;
    }
    
    // Initialize the global metadata cache if it doesn't exist
    if (!window.JetEngineAudioFileMetadata) {
        window.JetEngineAudioFileMetadata = {};
    }
    
    // Add URL resolution cache to avoid redundant API calls and ensure consistency
    if (!window.JetEngineAudioResolvedUrls) {
        window.JetEngineAudioResolvedUrls = {};
    }

    // Initialize players on document load
    console.log('JetEngine Audio Stream: Initializing players on document load');
    initializeWaveSurfers();
    
    // Add a failsafe check for when DOM content might change (SPA navigation, dynamic content loading)
    window.addEventListener('load', function() {
        console.log('Window loaded, re-initializing players');
        // Force a re-initialization of all players to catch any that might not have resolved correctly
        document.querySelectorAll(".audio-player.initialized").forEach(function(player) {
            // Check if the player has failed (has error message or no audio element)
            if (player.querySelector('.audio-error') || 
                (!player.querySelector('audio') && !player.querySelector('wavesurfer'))) {
                console.log('Found failed player, removing initialized class for retry');
                player.classList.remove('initialized');
            }
        });
        
        // Now run the initialization again
        setTimeout(initializeWaveSurfers, 500); // Small delay to ensure dynamic content is ready
    });
    
    // Use a MutationObserver to watch for dynamically added players
    if ('MutationObserver' in window) {
        const observer = new MutationObserver(function(mutations) {
            let needsInit = false;
            
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    // Check if any added nodes contain player elements
                    for (let i = 0; i < mutation.addedNodes.length; i++) {
                        const node = mutation.addedNodes[i];
                        if (node.nodeType === 1) { // Element node
                            if (node.classList && node.classList.contains('audio-player') && !node.classList.contains('initialized')) {
                                needsInit = true;
                                break;
                            }
                            if (node.querySelector && node.querySelector('.audio-player:not(.initialized)')) {
                                needsInit = true;
                                break;
                            }
                        }
                    }
                }
            });
            
            if (needsInit) {
                console.log('Detected dynamically added audio players, initializing');
                setTimeout(initializeWaveSurfers, 100);
            }
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
        console.log('Set up MutationObserver to detect dynamically added players');
    }

    // Listen for JetSmartFilters events if present
    if (window.JetSmartFilters) {
        console.log('JetSmartFilters detected, adding event listeners');
        document.addEventListener('jet-filter-content-rendered', function() {
            setTimeout(initializeWaveSurfers, 100);
        });
    }
    
    // Support Elementor frontend if available
    if (window.elementorFrontend) {
        console.log('Elementor frontend detected, adding hooks');
        try {
            elementorFrontend.hooks.addAction('frontend/element_ready/widget', function() {
                setTimeout(initializeWaveSurfers, 100);
            });
        } catch (e) {
            console.log('Elementor hooks not available, using fallback init method');
            // Fallback for older Elementor versions
            jQuery(document).on('elementor/frontend/init', function() {
                setTimeout(initializeWaveSurfers, 100);
            });
        }
    }

    // Expose this function so other scripts can trigger a re-initialization
    // This is used by listing-integration.js when it loads new audio elements
    window.JetEngineAudioStreamNeedsInit = function() {
        console.log('JetEngine Audio Stream: Re-initialization requested by external script');
        initializeWaveSurfers();
    };

    /**
     * Safely load audio using XHR instead of fetch to avoid QUIC protocol errors
     * @param {string} url - URL of the audio file
     * @returns {Promise<string>} - Blob URL for the loaded audio
     */
    async function safeLoadAudioWithXHR(url) {
        return new Promise((resolve, reject) => {
            console.log('Loading audio with XHR to avoid QUIC protocol errors:', url);
            const xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.responseType = 'blob';
            
            // Explicitly disable HTTP/3 (QUIC) protocol
            xhr.setRequestHeader('Accept', 'audio/*');
            xhr.setRequestHeader('X-HTTP-Version', 'HTTP/1.1'); // Force HTTP/1.1
            xhr.setRequestHeader('Connection', 'keep-alive'); // Use persistent connection
            xhr.setRequestHeader('Upgrade-Insecure-Requests', '1');
            xhr.setRequestHeader('Cache-Control', 'no-cache');
            xhr.setRequestHeader('Pragma', 'no-cache');
            
            xhr.onprogress = (e) => {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    console.log(`Loading audio: ${percentComplete.toFixed(2)}%`);
                }
            };
            
            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    const blob = xhr.response;
                    const objectUrl = URL.createObjectURL(blob);
                    console.log('Successfully loaded audio via XHR, created blob URL');
                    resolve(objectUrl);
                } else {
                    console.error(`XHR load failed: ${xhr.status} ${xhr.statusText}`);
                    reject(new Error(`Failed to load audio: ${xhr.status}`));
                }
            };
            
            xhr.onerror = function() {
                console.error('Network error loading audio via XHR');
                reject(new Error('Network error loading audio'));
            };
            
            xhr.send();
        });
    }
}); 