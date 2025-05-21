<?php
/**
 * Settings Page for JetEngine Audio Stream
 * 
 * Handles admin settings and debug logs display.
 */
namespace JetEngine\Audio_Stream;

// Exit if accessed directly
defined('ABSPATH') || exit;

class Settings_Page {
    
    /**
     * Settings constants
     */
    const OPTION_NAME = 'jetengine_audio_stream_settings';
    const OPTION_GROUP = 'jetengine_audio_stream_options';
    const SETTINGS_PAGE = 'jetengine-audio-stream-settings';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register hooks - use a higher priority to ensure our callback runs
        add_action('admin_menu', array($this, 'register_settings_page'), 99);
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'settings_admin_notices'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Register debug log handler
        add_action('admin_post_jetengine_clear_audio_logs', array($this, 'clear_debug_logs'));
        
        // We no longer need the direct menu item as the main registration is working
        // $this->add_direct_menu_item();
    }
    
    /**
     * Add direct menu item as a fallback method
     */
    public function add_direct_menu_item() {
        add_action('admin_menu', function() {
            add_options_page(
                __('Audio Streaming Settings', 'jetengine-audio-stream'),
                __('Audio Streaming', 'jetengine-audio-stream'),
                'manage_options',
                self::SETTINGS_PAGE,
                array($this, 'render_settings_page')
            );
        }, 100);
    }
    
    /**
     * Register admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our settings page
        if ($hook !== 'settings_page_' . self::SETTINGS_PAGE) {
            return;
        }
        
        // Register and enqueue admin CSS
        wp_register_style(
            'jetengine-audio-admin',
            JETENGINE_AUDIO_STREAM_URL . 'assets/css/admin.css',
            array(),
            JETENGINE_AUDIO_STREAM_VERSION
        );
        
        wp_enqueue_style('jetengine-audio-admin');
    }
    
    /**
     * Register the settings page under Settings menu
     */
    public function register_settings_page() {
        // Add the options page under Settings
        add_options_page(
            __('Audio Streaming Settings', 'jetengine-audio-stream'),   // Page title
            __('Audio Streaming', 'jetengine-audio-stream'),            // Menu title 
            'manage_options',                                           // Capability
            self::SETTINGS_PAGE,                                        // Menu slug
            array($this, 'render_settings_page')                        // Callback function
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
                    'max_file_size' => 2048,
                    'debug_logs' => array()
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
        $current_settings = get_option(self::OPTION_NAME, array());
        
        // Keep debug logs
        $sanitized['debug_logs'] = isset($current_settings['debug_logs']) ? $current_settings['debug_logs'] : array();

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
        if (!isset($_GET['page']) || $_GET['page'] !== self::SETTINGS_PAGE) {
            return;
        }
        
        // Check if our settings just got saved
        if (get_transient('jetengine_audio_stream_settings_saved')) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Settings saved.', 'jetengine-audio-stream'); ?></p>
            </div>
            <?php
            delete_transient('jetengine_audio_stream_settings_saved');
        }
        
        // Check if logs were cleared
        if (isset($_GET['logs_cleared']) && $_GET['logs_cleared'] === '1') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Debug logs cleared successfully.', 'jetengine-audio-stream'); ?></p>
            </div>
            <?php
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
                   name="<?php echo esc_attr(self::OPTION_NAME . '[enable_streaming]'); ?>" 
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
               name="<?php echo esc_attr(self::OPTION_NAME . '[allowed_file_types]'); ?>" 
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
               name="<?php echo esc_attr(self::OPTION_NAME . '[max_file_size]'); ?>" 
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
        $tabs = array(
            'general' => __('General Settings', 'jetengine-audio-stream'),
            'debug_log' => __('Debug Log', 'jetengine-audio-stream')
        );
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <nav class="nav-tab-wrapper wp-clearfix">
                <?php foreach ($tabs as $tab_id => $tab_name) : ?>
                    <a href="<?php echo esc_url(add_query_arg(array('page' => self::SETTINGS_PAGE, 'tab' => $tab_id), admin_url('options-general.php'))); ?>" 
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
                } elseif ($active_tab === 'debug_log') {
                    // Debug Log Tab
                    $this->render_debug_log_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render debug log tab content
     */
    public function render_debug_log_tab() {
        // Only allow administrators to view logs
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>' . __('You do not have permission to view debug logs.', 'jetengine-audio-stream') . '</p></div>';
            return;
        }
        
        // Get logs
        $settings = get_option(self::OPTION_NAME, array());
        $logs = isset($settings['debug_logs']) ? array_slice($settings['debug_logs'], 0, 50) : array();
        
        // Render logs table
        ?>
        <div class="jetengine-audio-debug-log">
            <h2><?php _e('Audio Stream Debug Log', 'jetengine-audio-stream'); ?></h2>
            
            <p><?php _e('This log shows the latest 50 audio streaming requests. Debug logs are only visible to administrators.', 'jetengine-audio-stream'); ?></p>
            
            <!-- Clear logs button -->
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=jetengine_clear_audio_logs'), 'jetengine_clear_audio_logs')); ?>" 
                   class="button button-secondary">
                    <?php _e('Clear Debug Log', 'jetengine-audio-stream'); ?>
                </a>
            </p>
            
            <?php if (empty($logs)) : ?>
                <p><?php _e('No debug logs found.', 'jetengine-audio-stream'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e('Time', 'jetengine-audio-stream'); ?></th>
                            <th><?php _e('Attachment ID', 'jetengine-audio-stream'); ?></th>
                            <th><?php _e('Status', 'jetengine-audio-stream'); ?></th>
                            <th><?php _e('IP Address', 'jetengine-audio-stream'); ?></th>
                            <th><?php _e('Message', 'jetengine-audio-stream'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html($log['time']); ?></td>
                                <td>
                                    <?php 
                                    echo esc_html($log['attachment_id']);
                                    if (!empty($log['attachment_id'])) {
                                        echo ' <a href="' . esc_url(get_edit_post_link($log['attachment_id'])) . '" target="_blank">[view]</a>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="log-status log-status-<?php echo esc_attr($log['status']); ?>">
                                        <?php echo esc_html($log['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log['ip']); ?></td>
                                <td><?php echo esc_html($log['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Clear debug logs
     */
    public function clear_debug_logs() {
        // Check if user has permission
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to clear debug logs.', 'jetengine-audio-stream'));
        }
        
        // Check for valid nonce
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'jetengine_clear_audio_logs')) {
            // Get current settings and clear the logs
            $settings = get_option(self::OPTION_NAME, array());
            $settings['debug_logs'] = array();
            update_option(self::OPTION_NAME, $settings);
            
            // Redirect back to settings page with success message
            wp_redirect(add_query_arg(
                array('page' => self::SETTINGS_PAGE, 'tab' => 'debug_log', 'logs_cleared' => '1'),
                admin_url('options-general.php')
            ));
            exit;
        }
        
        // If nonce check fails, redirect back
        wp_redirect(add_query_arg(
            array('page' => self::SETTINGS_PAGE, 'tab' => 'debug_log'),
            admin_url('options-general.php')
        ));
        exit;
    }
} 