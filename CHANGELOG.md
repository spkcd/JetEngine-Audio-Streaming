# Changelog

All notable changes to the JetEngine Audio Stream plugin will be documented in this file.

## [1.2.1] - 2025-05-21


### Added
- Support for passing full URLs to the REST API endpoint
- XHR transport fallback for better cross-browser compatibility
- Protection against script abortion when users navigate away
- Improved error detection with specific error type identification
- Intelligent debouncing for rapid seek operations

### Enhanced
- Significantly improved streaming performance with optimized chunking
- Better handling of large audio files with efficient memory usage
- More robust HTTP Range request handling
- Enhanced seek operations to prevent failures during rapid user interaction
- Improved error recovery with graceful fallbacks to native HTML5 audio
- Better compatibility with unstable or slow network connections

### Fixed
- QUIC protocol errors on Chrome and other Chromium-based browsers
- Issues with seeking in large audio files causing playback to fail
- Memory usage problems when streaming very large files
- Connection handling when users navigate away during playback
- Multiple concurrent request issues when seeking rapidly

## [1.1.0] - 2025-05-15

### Added
- Debug logging system for tracking streaming requests
- Support for additional audio formats
- Improved streaming performance
- Additional player customization options

### Fixed
- Issues with large file handling
- Various minor bugs and edge cases

## [1.0.0] - 2025-05-11

### Initial Release
- True HTTP Range request support for audio streaming
- WaveSurfer.js integration for waveform visualization
- Admin settings page for configuration
- Shortcode support for embedding players
- JetEngine dynamic tags compatibility 