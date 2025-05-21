<?php
/**
 * Settings page for JetEngine Audio Stream
 */
// Check if the class already exists
if (!class_exists('JetEngine_Audio_Stream_Settings')):

class JetEngine_Audio_Stream_Settings {

    // Option name and group constants
    const OPTION_NAME = 'jetengine_audio_stream';
    const OPTION_GROUP = 'jetengine_audio_stream_options';
    const SETTINGS_PAGE = 'jetengine-audio-stream-settings';

    /**
     * Initialize the settings class
     */
    public static function init() {
        $instance = new self();
        return $instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        // Register hooks
        add_action('admin_menu', array($this, 'register_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'settings_admin_notices'));
    }

    /**
     * Register the settings page under Settings menu
     */
    public function register_settings_page() {
        add_options_page(
            __('Audio Streaming Settings', 'jetengine-audio-stream'),     // Page title
            __('Audio Streaming', 'jetengine-audio-stream'),              // Menu title
            'manage_options',                                             // Capability required
            self::SETTINGS_PAGE,                                          // Menu slug
            array($this, 'render_settings_page')                          // Callback function
        );
    }

    /**
     * Register settings, sections and fields
     */
    public function register_settings() {
        // Register the settings
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            array(
                'type' => 'array',
                'description' => __('Settings for JetEngine Audio Streaming', 'jetengine-audio-stream'),
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => array(
                    'enable_streaming' => true,
                    'allowed_file_types' => 'mp3,wav,ogg,m4a,flac',
                    'max_file_size' => 2048
                ),
            )
        );

        // Add settings section
        add_settings_section(
            'jetengine_audio_stream_section',
            __('Audio Streaming Settings', 'jetengine-audio-stream'),
            array($this, 'render_section_description'),
            self::SETTINGS_PAGE
        );

        // Add settings fields
        add_settings_field(
            'enable_streaming',
            __('Enable Streaming Mode', 'jetengine-audio-stream'),
            array($this, 'render_enable_streaming_field'),
            self::SETTINGS_PAGE,
            'jetengine_audio_stream_section'
        );

        add_settings_field(
            'allowed_file_types',
            __('Allowed File Types', 'jetengine-audio-stream'),
            array($this, 'render_allowed_file_types_field'),
            self::SETTINGS_PAGE,
            'jetengine_audio_stream_section'
        );

        add_settings_field(
            'max_file_size',
            __('Max File Size (MB)', 'jetengine-audio-stream'),
            array($this, 'render_max_file_size_field'),
            self::SETTINGS_PAGE,
            'jetengine_audio_stream_section'
        );
    }

    /**
     * Sanitize settings
     *
     * @param array $input The settings array
     * @return array Sanitized settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        // Sanitize enable_streaming (checkbox)
        $sanitized['enable_streaming'] = isset($input['enable_streaming']) ? (bool) $input['enable_streaming'] : false;

        // Sanitize allowed_file_types (text)
        if (isset($input['allowed_file_types'])) {
            // Remove any whitespace and validate only alphanumeric and comma
            $file_types = preg_replace('/\s+/', '', $input['allowed_file_types']);
            $file_types = preg_replace('/[^a-zA-Z0-9,]/', '', $file_types);
            $sanitized['allowed_file_types'] = $file_types;
        } else {
            $sanitized['allowed_file_types'] = 'mp3,wav,ogg';
        }

        // Sanitize max_file_size (number)
        if (isset($input['max_file_size'])) {
            $max_size = absint($input['max_file_size']);
            $sanitized['max_file_size'] = ($max_size > 0) ? $max_size : 2048;
        } else {
            $sanitized['max_file_size'] = 2048;
        }

        // Set a transient to show settings saved notice
        set_transient('jetengine_audio_stream_settings_saved', true, 5);

        return $sanitized;
    }

    /**
     * Display admin notices for settings
     */
    public function settings_admin_notices() {
        // Check if our settings just got saved
        if (get_transient('jetengine_audio_stream_settings_saved')) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Settings saved.', 'jetengine-audio-stream'); ?></p>
            </div>
            <?php
            delete_transient('jetengine_audio_stream_settings_saved');
        }
    }

    /**
     * Render section description
     */
    public function render_section_description() {
        echo '<p>' . __('Configure settings for JetEngine Audio Streaming plugin.', 'jetengine-audio-stream') . '</p>';
    }

    /**
     * Render enable streaming field
     */
    public function render_enable_streaming_field() {
        $options = get_option(self::OPTION_NAME);
        $enabled = isset($options['enable_streaming']) ? $options['enable_streaming'] : true;
        ?>
        <label for="enable_streaming">
            <input type="checkbox" 
                   id="enable_streaming" 
                   name="<?php echo self::OPTION_NAME; ?>[enable_streaming]" 
                   value="1" 
                   <?php checked($enabled, true); ?> />
            <?php _e('Enable full audio streaming for large files', 'jetengine-audio-stream'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, the plugin will serve audio files with proper streaming headers and range request support.', 'jetengine-audio-stream'); ?>
        </p>
        <?php
    }

    /**
     * Render allowed file types field
     */
    public function render_allowed_file_types_field() {
        $options = get_option(self::OPTION_NAME);
        $allowed_types = isset($options['allowed_file_types']) ? $options['allowed_file_types'] : 'mp3,wav,ogg,m4a,flac';
        ?>
        <input type="text" 
               id="allowed_file_types" 
               name="<?php echo self::OPTION_NAME; ?>[allowed_file_types]" 
               value="<?php echo esc_attr($allowed_types); ?>" 
               class="regular-text" />
        <p class="description">
            <?php _e('Comma-separated list of file extensions that can be streamed (e.g., mp3,wav,ogg).', 'jetengine-audio-stream'); ?>
        </p>
        <?php
    }

    /**
     * Render max file size field
     */
    public function render_max_file_size_field() {
        $options = get_option(self::OPTION_NAME);
        $max_size = isset($options['max_file_size']) ? $options['max_file_size'] : 2048;
        ?>
        <input type="number" 
               id="max_file_size" 
               name="<?php echo self::OPTION_NAME; ?>[max_file_size]" 
               value="<?php echo esc_attr($max_size); ?>" 
               min="1" 
               step="1" 
               class="small-text" />
        <p class="description">
            <?php _e('Maximum file size in megabytes (MB) that can be streamed.', 'jetengine-audio-stream'); ?>
        </p>
        <?php
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        // Ensure user has permission
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get active tab, default to first tab
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        
        // Define tabs
        $tabs = apply_filters('jetengine_audio_stream_settings_tabs', [
            'general' => __('General Settings', 'jetengine-audio-stream'),
        ]);
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <nav class="nav-tab-wrapper wp-clearfix">
                <?php foreach ($tabs as $tab_id => $tab_name) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => self::SETTINGS_PAGE, 'tab' => $tab_id], admin_url('options-general.php'))); ?>" 
                       class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_name); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            
            <div class="tab-content">
                <?php 
                if ($active_tab === 'general') {
                    // General Settings Tab (default)
                    ?>
                    <form method="post" action="options.php">
                        <?php
                        // Output security fields, settings sections and submit button
                        settings_fields(self::OPTION_GROUP);
                        do_settings_sections(self::SETTINGS_PAGE);
                        submit_button();
                        ?>
                    </form>
                    <?php
                } elseif ($active_tab === 'debug_log' && class_exists('JetEngine_Audio_Debug_Log')) {
                    // Debug Log Tab
                    JetEngine_Audio_Debug_Log::render_debug_log_tab();
                } else {
                    // Allow other plugins to add custom tabs
                    do_action('jetengine_audio_stream_render_tab_' . $active_tab);
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Get settings
     *
     * @return array The current settings
     */
    public static function get_settings() {
        $defaults = array(
            'enable_streaming' => true,
            'allowed_file_types' => 'mp3,wav,ogg,m4a,flac',
            'max_file_size' => 2048
        );
        
        $options = get_option(self::OPTION_NAME, $defaults);
        return wp_parse_args($options, $defaults);
    }
} 
endif; // End of JetEngine_Audio_Stream_Settings class check
?> 