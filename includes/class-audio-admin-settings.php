<?php

class JetEngine_Audio_Admin_Settings {

    // Settings option names
    const OPTION_GROUP = 'jetengine_audio_stream_settings';
    const OPTION_PAGE = 'jetengine_audio_stream';
    const BUFFER_SIZE_OPTION = 'jetengine_audio_buffer_size';
    const BITRATE_OPTION = 'jetengine_audio_bitrate';
    const PRELOAD_DURATION_OPTION = 'jetengine_audio_preload_duration';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_menu_page() {
        add_menu_page(
            'Audio Stream Settings', 
            'Audio Stream', 
            'manage_options', 
            'jetengine-audio-stream', 
            [__CLASS__, 'render_settings_page'],
            'dashicons-format-audio',
            81
        );
    }

    public static function register_settings() {
        // Register settings
        register_setting(
            self::OPTION_GROUP,
            self::BUFFER_SIZE_OPTION,
            [
                'type' => 'integer',
                'description' => 'Buffer size for streaming in KB',
                'sanitize_callback' => [__CLASS__, 'sanitize_buffer_size'],
                'default' => 64,
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            self::BITRATE_OPTION,
            [
                'type' => 'integer',
                'description' => 'Default bitrate for streaming (kbps)',
                'sanitize_callback' => [__CLASS__, 'sanitize_bitrate'],
                'default' => 128,
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            self::PRELOAD_DURATION_OPTION,
            [
                'type' => 'integer',
                'description' => 'Default preload duration in seconds',
                'sanitize_callback' => 'absint',
                'default' => 30,
            ]
        );

        // Register settings section
        add_settings_section(
            'jetengine_audio_stream_section',
            'Streaming Settings',
            [__CLASS__, 'render_section_description'],
            self::OPTION_PAGE
        );

        // Register settings fields
        add_settings_field(
            'buffer_size',
            'Buffer Size (KB)',
            [__CLASS__, 'render_buffer_size_field'],
            self::OPTION_PAGE,
            'jetengine_audio_stream_section'
        );

        add_settings_field(
            'bitrate',
            'Default Bitrate (kbps)',
            [__CLASS__, 'render_bitrate_field'],
            self::OPTION_PAGE,
            'jetengine_audio_stream_section'
        );

        add_settings_field(
            'preload_duration',
            'Preload Duration (seconds)',
            [__CLASS__, 'render_preload_duration_field'],
            self::OPTION_PAGE,
            'jetengine_audio_stream_section'
        );
    }

    public static function sanitize_buffer_size($value) {
        $value = absint($value);
        
        // Ensure value is within reasonable limits
        if ($value < 8) {
            $value = 8; // Minimum 8 KB
        } elseif ($value > 1024) {
            $value = 1024; // Maximum 1 MB
        }
        
        return $value;
    }

    public static function sanitize_bitrate($value) {
        $value = absint($value);
        
        // Ensure value is within reasonable limits
        if ($value < 32) {
            $value = 32; // Minimum 32 kbps
        } elseif ($value > 320) {
            $value = 320; // Maximum 320 kbps
        }
        
        return $value;
    }

    public static function render_section_description() {
        echo '<p>Configure audio streaming settings to optimize performance based on your server capabilities and typical file sizes.</p>';
    }

    public static function render_buffer_size_field() {
        $buffer_size = get_option(self::BUFFER_SIZE_OPTION, 64);
        ?>
        <input type="number" 
               name="<?php echo self::BUFFER_SIZE_OPTION; ?>" 
               value="<?php echo esc_attr($buffer_size); ?>" 
               min="8" 
               max="1024" 
               step="8" />
        <p class="description">
            Buffer size used when streaming audio files. Larger values may improve streaming for users with fast connections, but could cause issues on slower connections. Recommended: 64KB.
        </p>
        <?php
    }

    public static function render_bitrate_field() {
        $bitrate = get_option(self::BITRATE_OPTION, 128);
        ?>
        <input type="number" 
               name="<?php echo self::BITRATE_OPTION; ?>" 
               value="<?php echo esc_attr($bitrate); ?>" 
               min="32" 
               max="320" 
               step="16" />
        <p class="description">
            Default bitrate for audio streaming. Affects the quality/bandwidth tradeoff. Higher values provide better audio quality but require more bandwidth. Recommended: 128kbps for most use cases.
        </p>
        <?php
    }

    public static function render_preload_duration_field() {
        $preload_duration = get_option(self::PRELOAD_DURATION_OPTION, 30);
        ?>
        <input type="number" 
               name="<?php echo self::PRELOAD_DURATION_OPTION; ?>" 
               value="<?php echo esc_attr($preload_duration); ?>" 
               min="5" 
               max="180" 
               step="5" />
        <p class="description">
            How many seconds of audio to preload before starting playback. Lower values start playback faster but may increase buffering. Higher values give smoother playback but longer initial load time.
        </p>
        <?php
    }

    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Audio Stream Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::OPTION_PAGE);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
} 