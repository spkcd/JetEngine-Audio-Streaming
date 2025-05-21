/**
 * JetEngine Audio Stream - Player
 * Implements true streaming audio playback with WaveSurfer.js
 */

document.addEventListener("DOMContentLoaded", function () {
    // Initialize all audio players on the page
    initializeAudioPlayers();
    
    // Reinitialize players when content is updated via AJAX (for JetEngine compatibility)
    document.addEventListener('jet-engine/listing/content-updated', function() {
        initializeAudioPlayers();
    });
    
    /**
     * Initialize all audio players on the page
     */
    function initializeAudioPlayers() {
        // Find all player containers
        document.querySelectorAll(".jet-audio-player, .jet-audio-player-container").forEach(function (playerContainer) {
            // Get audio ID and URL
            const audioId = playerContainer.getAttribute("data-audio-id");
            let audioUrl = playerContainer.getAttribute("data-url");
            
            // If we don't have a URL but have an ID, construct the REST API URL
            if (!audioUrl && audioId) {
                audioUrl = JetEngineAudioSettings.rest_url + audioId;
                playerContainer.setAttribute("data-url", audioUrl);
            }
            
            // If no audio URL is found, we can't proceed
            if (!audioUrl) {
                console.error("JetEngine Audio Stream: No audio URL or ID found for player", playerContainer);
                displayError(playerContainer, "Missing audio source configuration.");
                return;
            }
            
            // Create player elements if they don't exist
            setupPlayerDOM(playerContainer);
            
            // Initialize WaveSurfer
            initializeWaveSurfer(playerContainer, audioUrl);
        });
    }
    
    /**
     * Setup player DOM elements if they don't exist
     */
    function setupPlayerDOM(playerContainer) {
        // If the container only has a data attribute but no inner structure, create it
        if (playerContainer.children.length === 0) {
            playerContainer.innerHTML = `
                <div class="jet-audio-player__waveform"></div>
                <div class="jet-audio-player__controls">
                    <button class="jet-audio-player__play-pause">
                        <span class="dashicons dashicons-controls-play"></span>
                    </button>
                    <div class="jet-audio-player__time">
                        <span class="jet-audio-player__current-time">0:00</span>
                        <span class="jet-audio-player__duration">0:00</span>
                    </div>
                </div>
            `;
        }
        
        // Add player-specific classes
        playerContainer.classList.add('jet-audio-player');
        
        // Add loading class
        playerContainer.classList.add('jet-audio-player--loading');
    }
    
    /**
     * Initialize WaveSurfer instance for a player
     */
    function initializeWaveSurfer(playerContainer, audioUrl) {
        const waveformContainer = playerContainer.querySelector(".jet-audio-player__waveform") || 
                                  playerContainer.querySelector(".waveform");
        const playButton = playerContainer.querySelector(".jet-audio-player__play-pause") || 
                          playerContainer.querySelector(".play-button");
        const currentTimeElement = playerContainer.querySelector(".jet-audio-player__current-time");
        const durationElement = playerContainer.querySelector(".jet-audio-player__duration");
        
        // Create WaveSurfer instance
        const wave = WaveSurfer.create({
            container: waveformContainer,
            waveColor: "#0072ff",
            progressColor: "#00c6ff",
            barWidth: 2,
            barRadius: 2,
            height: 80,
            responsive: true,
            backend: 'MediaElement', // MediaElement backend for streaming support
            normalize: true,
            cursorWidth: 1,
            cursorColor: "#ff006e"
        });
        
        // Handle errors
        wave.on('error', function (err) {
            console.error("JetEngine Audio Stream: WaveSurfer error", err);
            displayError(playerContainer, "Error loading audio: " + err);
            playerContainer.classList.remove('jet-audio-player--loading');
            playerContainer.classList.add('jet-audio-player--error');
        });
        
        // When audio is ready to play
        wave.on('ready', function () {
            const duration = wave.getDuration();
            
            if (durationElement) {
                durationElement.textContent = formatTime(duration);
            }
            
            // Update time display
            if (currentTimeElement && durationElement) {
                currentTimeElement.textContent = "0:00";
            }
            
            // Remove loading class
            playerContainer.classList.remove('jet-audio-player--loading');
            
            // Update button state
            updatePlayButton(playButton, false);
        });
        
        // While playing, update current time
        wave.on('audioprocess', function () {
            if (currentTimeElement) {
                const currentTime = wave.getCurrentTime();
                currentTimeElement.textContent = formatTime(currentTime);
            }
        });
        
        // When playback finishes
        wave.on('finish', function() {
            updatePlayButton(playButton, false);
        });
        
        // Play/pause button click
        if (playButton) {
            playButton.addEventListener("click", function () {
                wave.playPause();
                updatePlayButton(playButton, wave.isPlaying());
            });
        }
        
        // When play state changes
        wave.on('play', function() {
            updatePlayButton(playButton, true);
        });
        
        wave.on('pause', function() {
            updatePlayButton(playButton, false);
        });
        
        // Load audio using the URL directly
        wave.load(audioUrl);
    }
    
    /**
     * Update play/pause button state
     */
    function updatePlayButton(button, isPlaying) {
        if (!button) return;
        
        if (button.querySelector('.dashicons')) {
            // Using dashicons
            const icon = button.querySelector('.dashicons');
            icon.className = isPlaying 
                ? 'dashicons dashicons-controls-pause' 
                : 'dashicons dashicons-controls-play';
        } else {
            // Simple text fallback
            button.innerHTML = isPlaying ? 'Pause' : 'Play';
        }
    }
    
    /**
     * Display error message in player
     */
    function displayError(playerContainer, message) {
        console.error("JetEngine Audio Stream: " + message);
        
        let errorElement = playerContainer.querySelector('.jet-audio-player__error');
        if (!errorElement) {
            errorElement = document.createElement('div');
            errorElement.className = 'jet-audio-player__error';
            
            const iconElement = document.createElement('span');
            iconElement.className = 'jet-audio-player__error-icon dashicons dashicons-warning';
            errorElement.appendChild(iconElement);
            
            const textElement = document.createElement('span');
            errorElement.appendChild(textElement);
            
            playerContainer.appendChild(errorElement);
        }
        
        const textElement = errorElement.querySelector('span:not(.jet-audio-player__error-icon)');
        if (textElement) {
            textElement.textContent = message;
        }
    }
    
    /**
     * Format time in seconds to MM:SS format
     */
    function formatTime(time) {
        if (isNaN(time) || !isFinite(time)) {
            return '0:00';
        }
        const minutes = Math.floor(time / 60);
        const seconds = Math.floor(time % 60);
        return `${minutes}:${seconds.toString().padStart(2, '0')}`;
    }
}); 