/**
 * JetEngine Audio Stream - Standalone Player
 * Implements true streaming audio playback with WaveSurfer.js
 * Requires WaveSurfer.js to be loaded externally.
 */

document.addEventListener("DOMContentLoaded", function () {
    const CONFIG = {
        debugMode: true,
        // Use window.location.origin for absolute paths
        resolveEndpointBase: window.location.origin + '/wp-json/jetengine-audio-stream/v1/resolve-id',
        playEndpointBase: window.location.origin + '/wp-json/jetengine-audio-stream/v1/play/'
    };

    function logDebug(message, context = null) {
        if (CONFIG.debugMode) {
            if (context) {
                console.log(`[AudioPlayer] ${message}`, context);
            } else {
                console.log(`[AudioPlayer] ${message}`);
            }
        }
    }

    async function initializeWaveSurfers() {
        logDebug("Initializing players...");
        document.querySelectorAll(".audio-player").forEach(async function (player) {
            let audioUrl = player.getAttribute("data-url");
            let attachmentId = null;

            if (!audioUrl) {
                displayErrorMessage(player, "Missing audio URL configuration (data-url).");
                return;
            }

            // Ensure URL is absolute
            if (!audioUrl.startsWith("http")) {
                logDebug(`Converting relative URL to absolute: ${audioUrl}`);
                audioUrl = window.location.origin + audioUrl;
                logDebug(`Resulting absolute URL: ${audioUrl}`);
            }

            // Check if it's a direct file URL that needs resolving
            const fileMatch = audioUrl.match(/\/(?<filename>[\w-]+)\.(?<ext>mp3|wav|ogg|m4a|flac)$/i);
            if (fileMatch) {
                logDebug(`Direct file URL detected: ${audioUrl}. Attempting to resolve to endpoint.`);
                const resolveResult = await resolveEndpoint(fileMatch.groups.filename);
                if (resolveResult && resolveResult.id) {
                    attachmentId = resolveResult.id;
                    
                    // Use direct URL for small files (<10MB)
                    if (resolveResult.size && resolveResult.size < 10 * 1024 * 1024 && 
                        resolveResult.url && 
                        (resolveResult.mime === 'audio/mpeg' || resolveResult.mime === 'audio/wav')) {
                        
                        logDebug(`Small audio file detected (${(resolveResult.size/1024/1024).toFixed(2)}MB), using direct URL: ${resolveResult.url}`);
                        audioUrl = resolveResult.url;
                    } else {
                        // Use streaming endpoint for larger files
                        audioUrl = `${CONFIG.playEndpointBase}${attachmentId}`;
                        logDebug(`Using streaming endpoint for larger file: ${audioUrl}`);
                    }
                    
                    player.setAttribute("data-url", audioUrl); // Update the element attribute
                    logDebug(`Final audio URL: ${audioUrl} (Attachment ID: ${attachmentId})`);
                } else {
                    displayErrorMessage(player, `Failed to resolve attachment ID for ${fileMatch.groups.filename}.${fileMatch.groups.ext}`);
                    return;
                }
            } else {
                // If it's already a streaming URL, extract the ID
                const streamMatch = audioUrl.match(/\/play\/(\d+)/);
                if (streamMatch && streamMatch[1]) {
                    attachmentId = parseInt(streamMatch[1], 10);
                    logDebug(`Streaming URL detected: ${audioUrl} (Attachment ID: ${attachmentId})`);
                } else {
                     logDebug(`Could not extract attachment ID from URL: ${audioUrl}`);
                }
            }

            logDebug(`Initializing player for: ${audioUrl}`);
            initializePlayer(player, audioUrl);
        });
    }

    async function initializePlayer(player, audioUrl) {
        const waveformContainer = player.querySelector(".waveform");
        const playButton = player.querySelector(".play-button");
        const timeDisplay = player.querySelector(".time-display");

        if (!waveformContainer || !playButton) {
             displayErrorMessage(player, "Player structure incomplete (missing .waveform or .play-button).");
             return;
        }

        try {
            logDebug("Creating WaveSurfer instance...");
            const wave = WaveSurfer.create({
                container: waveformContainer,
                waveColor: "gray",
                progressColor: "blue",
                barWidth: 5,
                barRadius: 13,
                height: 80,
                responsive: true,
                fillParent: true,
                backend: 'MediaElement', // Use MediaElement backend for streaming support
            });
            logDebug("WaveSurfer instance created.", { url: audioUrl });

            // --- Event Listeners ---
            playButton.addEventListener("click", function () {
                 logDebug("Play/Pause button clicked.");
                 wave.playPause();
            });

             wave.on('play', () => {
                logDebug("Playback started.");
                 playButton.innerHTML = '<i aria-hidden="true" class="jki jki-pause-line"></i>';
             });

             wave.on('pause', () => {
                logDebug("Playback paused.");
                 playButton.innerHTML = '<i aria-hidden="true" class="jki jki-play"></i>';
             });

            wave.on('ready', function () {
                logDebug("WaveSurfer ready event fired.");
                const duration = wave.getDuration();
                if (timeDisplay) {
                     timeDisplay.textContent = `0:00 / ${formatTime(duration)}`;
                }
                 // Update button state in case it was paused initially
                 playButton.innerHTML = wave.isPlaying()
                    ? '<i aria-hidden="true" class="jki jki-pause-line"></i>'
                    : '<i aria-hidden="true" class="jki jki-play"></i>';
            });

            wave.on('audioprocess', function () {
                if (timeDisplay) {
                    const currentTime = wave.getCurrentTime();
                    const duration = wave.getDuration();
                    timeDisplay.textContent = `${formatTime(currentTime)} / ${formatTime(duration)}`;
                }
            });

             wave.on('finish', () => {
                logDebug("Playback finished.");
                playButton.innerHTML = '<i aria-hidden="true" class="jki jki-play"></i>';
                // Optional: Seek to start on finish
                // wave.seekTo(0);
             });

            wave.on('error', function (err) {
                console.error("Player error:", err);
                displayErrorMessage(player, `Error loading audio: ${err}`);
            });

            logDebug("WaveSurfer event listeners attached.");
            
            // Load audio directly from URL
            wave.load(audioUrl);

        } catch (error) {
            console.error("Error initializing WaveSurfer:", error);
            displayErrorMessage(player, `Failed to initialize player: ${error.message}`);
        }
    }

    /**
     * Resolve a filename (without extension) to a streaming endpoint URL.
     */
    async function resolveEndpoint(filename) {
        if (!filename) return null;

        const resolveUrl = `${CONFIG.resolveEndpointBase}?filename=${encodeURIComponent(filename)}`;
        logDebug(`Resolving filename '${filename}' using URL: ${resolveUrl}`);

        try {
            const response = await fetch(resolveUrl);
             if (!response.ok) {
                const errorData = await response.json().catch(() => ({ message: 'Unknown resolution error' }));
                throw new Error(`HTTP error ${response.status}: ${errorData.message || 'Failed to resolve'}`);
             }
             const data = await response.json();
             
             // Check for success flag in the new response format
             if (data.success && data.id) {
                logDebug(`Resolved filename '${filename}' to attachment ID: ${data.id}`);
                
                // Return the complete attachment info
                return { 
                    id: data.id,
                    url: data.url,
                    mime: data.mime,
                    size: data.size
                };
             } else {
                logDebug(`Filename '${filename}' not found via resolve endpoint.`);
                return null;
             }
        } catch (err) {
            console.error(`Error resolving endpoint for filename '${filename}':`, err);
            return null;
        }
    }

    function formatTime(time) {
         if (isNaN(time) || !isFinite(time)) {
             return '0:00';
         }
        const minutes = Math.floor(time / 60);
        const seconds = Math.floor(time % 60);
        return `${minutes}:${seconds.toString().padStart(2, '0')}`;
    }

    function displayErrorMessage(player, message) {
         logDebug(`Displaying error: "${message}"`);
        let errorContainer = player.querySelector('.audio-error');
         if (!errorContainer) {
            errorContainer = document.createElement('div');
            errorContainer.className = 'audio-error';
            errorContainer.style.color = '#d63638';
            errorContainer.style.marginTop = '10px';
            // Insert after waveform or at the end
            const waveform = player.querySelector('.waveform');
            if (waveform && waveform.nextSibling) {
                player.insertBefore(errorContainer, waveform.nextSibling);
            } else {
                player.appendChild(errorContainer);
            }
         }
        errorContainer.textContent = message;
    }

    // Initial execution
    initializeWaveSurfers();

    // Re-initialize if using JetSmartFilters AJAX
    document.addEventListener('jet-smart-filters/inited', function () {
         logDebug("JetSmartFilters detected. Subscribing to AJAX updates.");
        window.JetSmartFilters.events.subscribe('ajaxFilters/updated', function () {
             logDebug("JetSmartFilters updated. Re-initializing players...");
            initializeWaveSurfers();
        });
    });
}); 