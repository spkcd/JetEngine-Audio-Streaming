=== JetEngine Audio Stream ===
Contributors: sparkwebstudio
Tags: audio, jetengine, streaming, waveform, media, wavesurfer
Requires at least: 5.6
Tested up to: 6.4
Stable tag: 1.2.1
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Implements true audio streaming for large files in JetEngine with WaveSurfer visualization and robust seek handling.

== Description ==

JetEngine Audio Stream enhances JetEngine with professional audio streaming capabilities, allowing you to serve large audio files efficiently through true HTTP range requests and byte streaming.

**Key Features:**

* True HTTP Range request support for proper audio streaming
* Robust seek handling even with very large audio files
* Beautiful waveform visualization using WaveSurfer.js
* Graceful handling of network issues and connection drops
* Admin settings page for customizing allowed file types and size limits
* Detailed debugging log for tracking streaming requests
* Shortcode support for easy embedding in any post or page
* Compatible with JetEngine dynamic listings

This plugin is ideal for podcast sites, audio course platforms, music collections, and any WordPress site that needs to stream large audio files efficiently.

**Technical Improvements in 1.2.0:**

* Optimized chunked streaming for large files
* Enhanced seek handling and request throttling
* Protection against script abortion during user navigation
* Better error handling with fallback to native HTML5 audio
* Performance optimizations for network variations

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/jetengine-audio-stream` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings > Audio Streaming to configure the plugin options.

== Usage ==

**Basic Shortcode:**

```
[jetengine_audio_player id="123"]
```

Where `123` is the attachment ID of your audio file.

**Available Shortcode Attributes:**

* `id` - The WordPress attachment ID of the audio file (required)
* `width` - Width of the player (default: "100%")
* `height` - Height of the player (default: "auto")
* `show_time` - Whether to show the time display (default: true)
* `show_waveform` - Whether to show the waveform visualization (default: true)

**JetEngine Dynamic Tag Usage:**

In JetEngine listings, you can use dynamic tags to display audio players for each listing item. For example:

```
[jetengine_audio_player id="%post_meta(audio_file_id)%"]
```

**Using URLs Instead of IDs:**

You can now also pass full URLs to the audio file in REST API endpoints:

```
/wp-json/jetengine-audio-stream/v1/play/https://example.com/wp-content/uploads/2023/05/audio-file.mp3
```

The plugin will extract the filename and find the matching attachment in the Media Library.

== Frequently Asked Questions ==

= What audio formats are supported? =

By default, the plugin supports MP3, WAV, OGG, M4A, and FLAC files. You can customize the allowed file types in the settings.

= Is there a file size limit? =

The default maximum file size is 2GB, but you can adjust this in the settings page. The optimized streaming in version 1.2.0 handles very large files efficiently.

= How does the improved seek functionality work? =

Version 1.2.0 implements intelligent request throttling, debouncing, and robust error handling to prevent issues when users rapidly seek through audio files. The server-side implementation also ensures requests complete even if the user navigates away.

= Can I customize the player appearance? =

Yes, you can use custom CSS to style the player. The plugin provides base styling that can be overridden.

= Does this work with JetEngine listings? =

Yes, the plugin is fully compatible with JetEngine listings and can be used with dynamic tags.

= What happens if streaming fails on mobile or slow connections? =

The plugin now includes intelligent fallback to the native HTML5 audio player if streaming issues occur, ensuring playback even in challenging network conditions.

== Changelog ==

= 1.2.0 =
* Added support for passing full URLs to the REST API endpoint
* Improved streaming performance with optimized chunking
* Implemented robust seek handling to prevent failures during rapid seeking
* Enhanced error recovery with intelligent fallbacks
* Added protection against script abortion when users navigate away
* Fixed QUIC protocol errors on some browsers
* Improved compatibility with slow or unstable connections
* Added XHR transport fallback for better cross-browser support

= 1.1.0 =
* Added debug logging system
* Improved streaming performance
* Added support for more audio formats
* Fixed issue with large file handling

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.2.0 =
This version significantly improves the handling of large audio files and seeking operations, with better error recovery and network resilience.

= 1.1.0 =
This version adds debugging capabilities and improves streaming performance.

= 1.0.0 =
Initial release of the plugin.