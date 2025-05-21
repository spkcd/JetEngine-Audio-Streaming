# How JetEngine Audio Stream Works

This document provides a technical overview of the JetEngine Audio Stream plugin, explaining how both the frontend and backend components work together to provide robust audio streaming.

## System Architecture

The plugin consists of two main components:

1. **Backend (PHP)**: Handles file processing, streaming, and REST API endpoints
2. **Frontend (JavaScript)**: Controls the player UI, waveform visualization, and error handling

## Backend Implementation

### REST API Endpoints

The plugin registers two main REST API endpoints:

- `/wp-json/jetengine-audio-stream/v1/play/{id_or_url}`: The primary endpoint for streaming audio
- `/wp-json/jetengine-audio-stream/v1/resolve-id`: A utility endpoint to convert filenames to attachment IDs

### Audio Streaming Process

1. **Request Handling**:
   - The `handle_audio_stream()` method processes incoming requests
   - It supports numeric IDs, simple filenames, and full URLs
   - When given a full URL, it extracts the filename and searches the Media Library

2. **Stream Implementation**:
   - The `stream_audio_file()` method implements true byte streaming
   - Sets `ignore_user_abort(true)` to ensure streaming continues even if users seek or navigate away
   - Uses `set_time_limit(0)` to prevent PHP timeouts for large files

3. **HTTP Range Support**:
   - Implements full HTTP/1.1 Range header support (RFC 7233)
   - Sends proper 206 Partial Content responses
   - Handles range validation and errors

4. **Chunked Streaming**:
   - Uses an 8KB buffer size for efficient memory usage
   - Reads and sends file data in chunks with `fread()` and `flush()`
   - Tracks position with `ftell()` for precise seek operations

5. **Performance Optimizations**:
   - For small files (<10MB), redirects to direct file URLs
   - Uses proper MIME type detection
   - Implements caching headers for better performance

## Frontend Implementation

### Player Initialization

1. **Player Detection**:
   - Scans for player containers with appropriate selectors
   - Supports both plugin shortcodes and Elementor widgets
   - Handles dynamic content updates

2. **WaveSurfer Integration**:
   - Creates WaveSurfer.js instances for waveform visualization
   - Configures correct backend (MediaElement for streaming support)
   - Sets up event handlers for playback control

### Robust Seek Handling

1. **Request Throttling**:
   - Implements debouncing for rapid seek operations
   - Uses the `loadWaveSurferThrottled()` function with a 150ms delay
   - Tracks and prevents duplicate load requests

2. **Error Recovery**:
   - Detects backend or buffer errors during seek operations
   - Implements auto-recovery by reloading the audio source
   - Rate-limits seek operations to 500ms intervals

3. **Defensive Playback**:
   - Validates backend and buffer existence before play attempts
   - Wraps operations in try/catch blocks to prevent uncaught exceptions
   - Provides clear console warnings when operations fail

### XHR Transport Layer

1. **QUIC Protocol Error Prevention**:
   - Uses custom `safeLoadAudioWithXHR()` function instead of fetch API
   - Sets specific headers to force HTTP/1.1 instead of HTTP/3
   - Creates blob URLs for more reliable playback

2. **Progressive Loading**:
   - Handles audio data in chunks for efficient loading
   - Provides progress indicators during loading
   - Allows playback to start before the entire file is loaded

### Fallback Mechanisms

1. **Native Audio Fallback**:
   - Automatically falls back to HTML5 audio player on errors
   - Preserves player controls and time display
   - Maintains event listeners for consistent user experience

2. **Error Type Detection**:
   - Identifies network-related errors vs. decoding errors
   - Provides different fallback strategies based on error type
   - Logs detailed error information for troubleshooting

3. **Cross-Browser Compatibility**:
   - Handles differences in browser implementations
   - Sets appropriate attributes like `crossOrigin="anonymous"`
   - Uses metadata preloading for faster initial load

## Data Flow

1. User clicks play or seeks in audio player
2. Frontend JavaScript initiates request to REST API endpoint
3. Backend PHP processes request, validates file
4. Streaming begins with appropriate headers
5. Frontend receives audio data in chunks
6. WaveSurfer renders waveform and handles playback
7. User interacts (seek, pause, etc.)
8. Request throttling and error handling ensure smooth experience

## Error Handling

The plugin implements comprehensive error handling at multiple levels:

- **Network Level**: Handles connection drops, slow connections
- **Browser Level**: Addresses QUIC protocol errors and cross-origin issues
- **PHP Level**: Manages file access errors, invalid requests
- **UI Level**: Provides fallbacks and user feedback

## Technical Innovations

1. **Chunked Streaming**: Efficient memory usage even for very large files
2. **Debounced Seeking**: Prevents server overload from rapid seek operations
3. **Script Abortion Protection**: Ensures streams complete even when users navigate away
4. **Fallback Chain**: Multiple fallback mechanisms for maximum compatibility
5. **URL Processing**: Ability to handle full URLs as input for better flexibility

## Performance Considerations

- Buffer size is optimized for balance between memory usage and efficiency
- Small files bypass streaming for better performance
- Headers are set for optimal caching
- JavaScript is optimized to prevent memory leaks
- Connection status is monitored to release resources appropriately

---

This technical overview provides insights into how JetEngine Audio Stream implements robust audio streaming. For specific code details, refer to the source files in the repository. 