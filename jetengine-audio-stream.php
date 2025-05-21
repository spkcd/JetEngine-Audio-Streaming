<?php
/**
 * Plugin Name: JetEngine Audio Stream
 * Description: Implements full audio streaming for large files in JetEngine with WaveSurfer visualization.
 * Version: 1.2.1
 * Author: SPARKWEB Studio
 * Author URI: https://sparkwebstudio.com
 * Text Domain: jetengine-audio-stream
 * Domain Path: /languages
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

// Define plugin constants
define('JETENGINE_AUDIO_STREAM_PATH', plugin_dir_path(__FILE__));
define('JETENGINE_AUDIO_STREAM_URL', plugin_dir_url(__FILE__));
define('JETENGINE_AUDIO_STREAM_VERSION', '1.2.1');

// Include required files
require_once JETENGINE_AUDIO_STREAM_PATH . 'includes/Streaming_Controller.php';
require_once JETENGINE_AUDIO_STREAM_PATH . 'includes/Settings_Page.php';
require_once JETENGINE_AUDIO_STREAM_PATH . 'includes/functions.php';

// Include debugging tools (remove in production)
if (file_exists(plugin_dir_path(__FILE__) . 'debug-settings.php')) {
    include_once plugin_dir_path(__FILE__) . 'debug-settings.php';
}

/**
 * Initialize the plugin
 */
function jetengine_audio_stream_init() {
    // Load text domain for translations
    load_plugin_textdomain(
        'jetengine-audio-stream',
        false, 
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
    
    // Register shortcodes
    add_shortcode('jetengine_audio_player', 'jetengine_audio_player_shortcode');
}
add_action('init', 'jetengine_audio_stream_init');

/**
 * Initialize the REST API controller
 */
function jetengine_audio_stream_rest_init() {
    JetEngine\Audio_Stream\Streaming_Controller::register_routes();
}
add_action('rest_api_init', 'jetengine_audio_stream_rest_init');

/**
 * Initialize the admin settings page
 */
function jetengine_audio_stream_admin_init() {
    // Create a new instance of the Settings_Page class 
    $settings = new JetEngine\Audio_Stream\Settings_Page();
}
// Use plugins_loaded to initialize admin components
add_action('plugins_loaded', 'jetengine_audio_stream_admin_init');

/**
 * Plugin activation
 */
register_activation_hook(__FILE__, 'jetengine_audio_stream_activate');
function jetengine_audio_stream_activate() {
    // Initialize default settings
    $default_settings = array(
        'enable_streaming' => true,
        'allowed_file_types' => 'mp3,wav,ogg,m4a,flac',
        'max_file_size' => 2048,
        'debug_logs' => array()
    );
    
    add_option('jetengine_audio_stream_settings', $default_settings);
    
    // Flush rewrite rules to ensure our endpoints work
    flush_rewrite_rules();
}

/**
 * Plugin deactivation
 */
register_deactivation_hook(__FILE__, 'jetengine_audio_stream_deactivate');
function jetengine_audio_stream_deactivate() {
    // Clean up transients
    global $wpdb;
    $like = $wpdb->esc_like('_transient_jet_audio_') . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name LIKE %s", $like));
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Enqueue frontend scripts and styles
 */
function jetengine_audio_stream_enqueue_assets() {
    // Register WaveSurfer dependency
    wp_register_script(
        'wavesurfer',
        'https://unpkg.com/wavesurfer.js@6.6.3/dist/wavesurfer.min.js',
        array(),
        '6.6.3',
        true
    );
    
    // Register and enqueue our player script
    wp_register_script(
        'jetengine-audio-player',
        JETENGINE_AUDIO_STREAM_URL . 'assets/js/frontend.js',
        array('jquery', 'wavesurfer'),
        JETENGINE_AUDIO_STREAM_VERSION,
        true
    );
    
    // Register CSS
    wp_register_style(
        'jetengine-audio-player',
        JETENGINE_AUDIO_STREAM_URL . 'assets/css/frontend.css',
        array(),
        JETENGINE_AUDIO_STREAM_VERSION
    );
    
    // Localize script with essential data
    wp_localize_script(
        'jetengine-audio-player',
        'JetEngineAudioSettings',
        array(
            'rest_url' => rest_url('jetengine-audio-stream/v1/play/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'debug_mode' => true
        )
    );
    
    // Get plugin instance settings
    if (class_exists('\JetEngine_Audio_Streaming\Plugin')) {
        $settings = \JetEngine_Audio_Streaming\Plugin::instance()->get_settings();
        if (!empty($settings)) {
            // Add additional settings to localized script
            wp_localize_script(
                'jetengine-audio-player',
                'JetEngineAudioExtraSettings',
                array(
                    'chunk_size' => isset($settings['chunk_size']) ? floatval($settings['chunk_size']) : 1,
                    'max_file_size' => isset($settings['max_file_size']) ? intval($settings['max_file_size']) : 50,
                    'enable_clipboard' => isset($settings['enable_clipboard']) ? (bool)$settings['enable_clipboard'] : true,
                    'log_url' => admin_url('admin-ajax.php?action=jet_audio_streaming_log'),
                    'nonce' => wp_create_nonce('jet_audio_streaming_log'),
                )
            );
        }
    }
    
    // Always enqueue the scripts on the front end
    if (!is_admin()) {
        wp_enqueue_script('wavesurfer');
        wp_enqueue_script('jetengine-audio-player');
        wp_enqueue_style('jetengine-audio-player');
        
        // Remove potential conflicting scripts
        add_action('wp_print_scripts', 'jetengine_audio_stream_remove_conflicting_scripts', 100);
    }
}
add_action('wp_enqueue_scripts', 'jetengine_audio_stream_enqueue_assets', 10);

/**
 * Remove conflicting scripts to prevent multiple player initializations
 */
function jetengine_audio_stream_remove_conflicting_scripts() {
    // Remove potentially conflicting player scripts
    wp_dequeue_script('jetengine-audio-player-old');
    
    // If legacy script is registered using same handle, ensure it's our version
    global $wp_scripts;
    if (isset($wp_scripts->registered['jetengine-audio-player'])) {
        $src = $wp_scripts->registered['jetengine-audio-player']->src;
        if (strpos($src, 'frontend.js') === false) {
            // If not our frontend.js, replace with the correct one
            wp_deregister_script('jetengine-audio-player');
            wp_register_script(
                'jetengine-audio-player',
                JETENGINE_AUDIO_STREAM_URL . 'assets/js/frontend.js',
                array('jquery', 'wavesurfer'),
                JETENGINE_AUDIO_STREAM_VERSION,
                true
            );
            wp_enqueue_script('jetengine-audio-player');
        }
    }
}

/**
 * Audio player shortcode
 * 
 * @param array $atts Shortcode attributes
 * @return string Rendered shortcode HTML
 */
function jetengine_audio_player_shortcode($atts) {
    $atts = shortcode_atts(array(
        'id' => 0,
        'width' => '100%',
        'height' => 'auto',
        'show_time' => true,
        'show_waveform' => true,
    ), $atts, 'jetengine_audio_player');
    
    $attachment_id = absint($atts['id']);
    
    if (!$attachment_id) {
        return '<p>' . esc_html__('Audio file ID is required.', 'jetengine-audio-stream') . '</p>';
    }
    
    // Check if the attachment exists and is an audio file
    $attachment = get_post($attachment_id);
    if (!$attachment || strpos(get_post_mime_type($attachment), 'audio/') === false) {
        return '<p>' . esc_html__('Invalid audio file.', 'jetengine-audio-stream') . '</p>';
    }
    
    // Enqueue required assets
    wp_enqueue_script('jetengine-audio-player');
    wp_enqueue_style('jetengine-audio-player');
    
    // Generate a unique ID for the player
    $player_id = 'jet-audio-player-' . $attachment_id . '-' . uniqid();
    
    // Generate the player HTML
    $html = '<div id="' . esc_attr($player_id) . '" class="jet-audio-player-container" ';
    $html .= 'data-audio-id="' . esc_attr($attachment_id) . '" ';
    $html .= 'style="width: ' . esc_attr($atts['width']) . '; height: ' . esc_attr($atts['height']) . ';">';
    $html .= '</div>';
    
    return $html;
} 