<?php
/**
 * JetEngine Audio Stream Debug Log Handler
 * 
 * Provides functionality for logging and viewing debug information
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class JetEngine_Audio_Debug_Log {

    // Option name for storing logs
    const OPTION_NAME = 'jetengine_audio_debug_logs';
    
    /**
     * Initialize the debug log class
     */
    public static function init() {
        // Add the debug log tab to settings page
        add_filter('jetengine_audio_stream_settings_tabs', array(__CLASS__, 'add_debug_log_tab'));
        
        // Handle log clearing action
        add_action('admin_post_jetengine_clear_audio_logs', array(__CLASS__, 'clear_logs'));
        
        return new self();
    }
    
    /**
     * Add logs to the debug log
     *
     * @param int $attachment_id The attachment ID
     * @param string $status The status of the request (success/error)
     * @param string $message The log message
     * @return bool Success or failure
     */
    public static function add_log($attachment_id, $status, $message) {
        // Create log entry
        $log_entry = array(
            'time' => current_time('mysql'),
            'timestamp' => time(),
            'attachment_id' => $attachment_id,
            'status' => $status,
            'message' => $message,
            'ip' => self::get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : 'Unknown',
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field($_SERVER['REQUEST_URI']) : '',
        );
        
        // Get current logs
        $logs = get_option(self::OPTION_NAME, array());
        
        // Add new log to the beginning of the array
        array_unshift($logs, $log_entry);
        
        // Limit to 100 entries
        if (count($logs) > 100) {
            $logs = array_slice($logs, 0, 100);
        }
        
        // Save logs
        return update_option(self::OPTION_NAME, $logs);
    }
    
    /**
     * Get debug logs
     *
     * @param int $limit Number of logs to return (default: 50)
     * @return array Array of log entries
     */
    public static function get_logs($limit = 50) {
        $logs = get_option(self::OPTION_NAME, array());
        
        // Limit the number of logs returned
        if (count($logs) > $limit) {
            $logs = array_slice($logs, 0, $limit);
        }
        
        return $logs;
    }
    
    /**
     * Clear all debug logs
     *
     * @return bool Success or failure
     */
    public static function clear_logs() {
        // Check if user has permission
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to clear debug logs.', 'jetengine-audio-stream'));
        }
        
        // Check for valid nonce
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'jetengine_clear_audio_logs')) {
            $result = delete_option(self::OPTION_NAME);
            
            // Redirect back to settings page with success message
            wp_redirect(add_query_arg(
                array('page' => 'jetengine-audio-stream-settings', 'tab' => 'debug_log', 'logs_cleared' => '1'),
                admin_url('options-general.php')
            ));
            exit;
        }
        
        // If nonce check fails, redirect back with error
        wp_redirect(add_query_arg(
            array('page' => 'jetengine-audio-stream-settings', 'tab' => 'debug_log', 'error' => '1'),
            admin_url('options-general.php')
        ));
        exit;
    }
    
    /**
     * Add debug log tab to settings page
     *
     * @param array $tabs Current tabs
     * @return array Modified tabs
     */
    public static function add_debug_log_tab($tabs) {
        $tabs['debug_log'] = __('Debug Log', 'jetengine-audio-stream');
        return $tabs;
    }
    
    /**
     * Render debug log tab content
     */
    public static function render_debug_log_tab() {
        // Only allow administrators to view logs
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>' . __('You do not have permission to view debug logs.', 'jetengine-audio-stream') . '</p></div>';
            return;
        }
        
        // Check if the logs were cleared
        if (isset($_GET['logs_cleared']) && $_GET['logs_cleared'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Debug logs cleared successfully.', 'jetengine-audio-stream') . '</p></div>';
        }
        
        // Check for errors
        if (isset($_GET['error']) && $_GET['error'] === '1') {
            echo '<div class="notice notice-error is-dismissible"><p>' . __('Error clearing debug logs.', 'jetengine-audio-stream') . '</p></div>';
        }
        
        // Get logs
        $logs = self::get_logs(50);
        
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
        
        <style>
            .log-status {
                display: inline-block;
                padding: 3px 6px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .log-status-success, .log-status-complete {
                background-color: #d4edda;
                color: #155724;
            }
            .log-status-error {
                background-color: #f8d7da;
                color: #721c24;
            }
            .log-status-start {
                background-color: #cce5ff;
                color: #004085;
            }
            .log-status-resolve_id_request,
            .log-status-resolve_id_success {
                background-color: #d1ecf1;
                color: #0c5460;
            }
        </style>
        <?php
    }
    
    /**
     * Get client IP address
     *
     * @return string IP address
     */
    private static function get_client_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return sanitize_text_field($ip);
    }
} 