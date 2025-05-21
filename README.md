# JetEngine Audio Stream

![JetEngine Audio Stream](assets/images/banner.jpg)

**Robust audio streaming for WordPress with WaveSurfer visualization**

JetEngine Audio Stream enhances WordPress with professional audio streaming capabilities, allowing you to serve large audio files efficiently through true HTTP range requests and chunked byte streaming.

## Key Features

- **True HTTP Range Request Support**: Properly stream large audio files with seekable playback
- **Robust Seek Handling**: Optimized for very large audio files with debounced seeking
- **Beautiful Waveform Visualization**: Powered by WaveSurfer.js
- **Resilient Playback**: Graceful handling of network issues and connection drops
- **Admin Settings**: Customize allowed file types and size limits
- **Debugging Tools**: Detailed logging for streaming requests
- **Shortcode Support**: Easy embedding in any post or page
- **JetEngine Integration**: Compatible with JetEngine dynamic listings

## Technical Details

JetEngine Audio Stream is built for performance and reliability, especially with large audio files. The plugin implements:

- Chunked streaming with optimized buffer sizes
- Intelligent request throttling and debouncing
- Full HTTP 206 Partial Content support
- Script abortion protection
- Comprehensive error handling with graceful fallbacks
- Protection against QUIC protocol errors 
- Memory usage optimization

## Installation

1. Upload the plugin files to the `/wp-content/plugins/jetengine-audio-stream` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings > Audio Streaming to configure the plugin options.

## Usage

### Basic Shortcode

```
[jetengine_audio_player id="123"]
```

Where `123` is the WordPress attachment ID of your audio file.

### Available Shortcode Attributes

- `id` - WordPress attachment ID of the audio file (required)
- `width` - Width of the player (default: "100%")
- `height` - Height of the player (default: "auto")
- `show_time` - Whether to show the time display (default: true)
- `show_waveform` - Whether to show the waveform visualization (default: true)

### JetEngine Dynamic Tag Usage

In JetEngine listings, you can use dynamic tags to display audio players for each listing item:

```
[jetengine_audio_player id="%post_meta(audio_file_id)%"]
```

### REST API

The plugin provides a REST API endpoint for direct streaming:

```
/wp-json/jetengine-audio-stream/v1/play/{id_or_url}
```

Where `{id_or_url}` can be:
- A numeric WordPress attachment ID
- A simple filename
- A full URL to the audio file

Example:
```
/wp-json/jetengine-audio-stream/v1/play/123
/wp-json/jetengine-audio-stream/v1/play/myaudio.mp3
/wp-json/jetengine-audio-stream/v1/play/https://example.com/wp-content/uploads/2023/05/audio-file.mp3
```

## Custom Integration

For developers, you can integrate the audio player directly into your theme or plugin using:

```php
<?php
// Get audio attachment ID
$audio_id = 123;

// Render player
echo do_shortcode('[jetengine_audio_player id="' . $audio_id . '"]');
?>
```

Or using JavaScript:

```javascript
// Initialize player on any container
document.addEventListener('DOMContentLoaded', function() {
    // If JetEngine Audio Stream's script is loaded
    if (window.JetEngineAudioStreamNeedsInit) {
        // Trigger re-initialization
        window.JetEngineAudioStreamNeedsInit();
    }
});
```

## Performance Considerations

- The plugin uses chunked streaming with a default buffer size of 8KB
- For files under 10MB, the plugin automatically redirects to direct file URLs
- HTTP Range requests are fully supported for seeking in large files
- Fallback to native HTML5 audio player if streaming issues occur

## Browser Compatibility

The plugin is tested and works with:
- Chrome, Firefox, Safari, Edge (latest versions)
- Mobile browsers on iOS and Android
- Various network conditions including slow connections

## Support

For support or feature requests, please use the GitHub issues tracker.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

- [WaveSurfer.js](https://wavesurfer-js.org/) for waveform visualization 

Developed by [SPARKWEB Studio](https://sparkwebstudio.com) - Last updated: May 21, 2025 