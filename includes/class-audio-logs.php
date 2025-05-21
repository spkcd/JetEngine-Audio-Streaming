<?php
namespace JetEngine_Audio_Streaming;

/**
 * Class for handling audio streaming logs and cache
 */
class Audio_Logs {

	/**
	 * Table name
	 */
	private $table_name;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'jetengine_audio_logs';
		
		// Create logs table if needed
		$this->create_table();
		
		// Register hooks
		add_action( 'wp_ajax_jet_audio_streaming_clear_cache', [ $this, 'clear_cache_ajax' ] );
	}

	/**
	 * Create logs table if it doesn't exist
	 */
	private function create_table() {
		global $wpdb;
		
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			log_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			log_type varchar(20) NOT NULL,
			message text NOT NULL,
			file_id bigint(20),
			chunk_index int(11),
			byte_start bigint(20),
			byte_end bigint(20),
			file_size bigint(20),
			status_code int(11),
			duration int(11),
			cache_status varchar(20),
			ip_address varchar(100),
			user_agent text,
			PRIMARY KEY (id),
			KEY log_time (log_time),
			KEY log_type (log_type),
			KEY file_id (file_id)
		) $charset_collate;";
		
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	/**
	 * Log chunk request or response
	 * 
	 * @param array $data Log data
	 * @return int|false Log ID on success, false on failure
	 */
	public function log_chunk( $data ) {
		global $wpdb;
		
		// Get current time in WordPress format
		$log_time = current_time( 'mysql' );
		
		// Prepare data
		$insert = [
			'log_time' => $log_time,
			'log_type' => isset( $data['log_type'] ) ? $data['log_type'] : 'request',
			'message' => isset( $data['message'] ) ? $data['message'] : '',
			'file_id' => isset( $data['file_id'] ) ? (int) $data['file_id'] : 0,
			'chunk_index' => isset( $data['chunk_index'] ) ? (int) $data['chunk_index'] : 0,
			'byte_start' => isset( $data['byte_start'] ) ? (int) $data['byte_start'] : 0,
			'byte_end' => isset( $data['byte_end'] ) ? (int) $data['byte_end'] : 0,
			'file_size' => isset( $data['file_size'] ) ? (int) $data['file_size'] : 0,
			'status_code' => isset( $data['status_code'] ) ? (int) $data['status_code'] : 0,
			'duration' => isset( $data['duration'] ) ? (int) $data['duration'] : 0,
			'cache_status' => isset( $data['cache_status'] ) ? $data['cache_status'] : '',
			'ip_address' => $_SERVER['REMOTE_ADDR'],
			'user_agent' => $_SERVER['HTTP_USER_AGENT'],
		];
		
		// Insert log
		$result = $wpdb->insert( $this->table_name, $insert );
		
		if ( $result ) {
			return $wpdb->insert_id;
		}
		
		return false;
	}

	/**
	 * Get the last X chunk logs
	 * 
	 * @param int $limit Number of logs to retrieve
	 * @return array Array of log data
	 */
	public function get_recent_logs( $limit = 10 ) {
		global $wpdb;
		
		$query = $wpdb->prepare(
			"SELECT * FROM {$this->table_name} ORDER BY log_time DESC LIMIT %d",
			$limit
		);
		
		$logs = $wpdb->get_results( $query, ARRAY_A );
		
		return $logs ?: [];
	}

	/**
	 * Get stats for network status
	 * 
	 * @param int $num_samples Number of samples to consider
	 * @return array Stats data
	 */
	public function get_network_stats( $num_samples = 5 ) {
		global $wpdb;
		
		$query = $wpdb->prepare(
			"SELECT AVG(duration) as avg_duration, MAX(duration) as max_duration, MIN(duration) as min_duration 
			FROM {$this->table_name} 
			WHERE duration > 0 
			ORDER BY log_time DESC LIMIT %d",
			$num_samples
		);
		
		$stats = $wpdb->get_row( $query, ARRAY_A );
		
		if ( !$stats ) {
			return [
				'avg_duration' => 0,
				'max_duration' => 0,
				'min_duration' => 0,
				'samples' => 0,
			];
		}
		
		// Count how many samples were actually used
		$query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table_name} 
			WHERE duration > 0 
			ORDER BY log_time DESC LIMIT %d",
			$num_samples
		);
		
		$count = $wpdb->get_var( $query );
		
		$stats['samples'] = (int) $count;
		
		return $stats;
	}

	/**
	 * Clear all cached audio chunks
	 * 
	 * @return int Number of cache items deleted
	 */
	public function clear_cache() {
		global $wpdb;
		
		$count = 0;
		
		// Find all cache keys with jet_audio_chunk prefix
		$cache_keys = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_jet_audio_chunk_%' 
			OR option_name LIKE '_transient_timeout_jet_audio_chunk_%'"
		);
		
		// Delete each transient
		foreach ( $cache_keys as $key ) {
			// Extract the base key name (without _transient_ or _transient_timeout_ prefix)
			if ( strpos( $key, '_transient_timeout_' ) === 0 ) {
				$transient_name = substr( $key, strlen( '_transient_timeout_' ) );
			} else {
				$transient_name = substr( $key, strlen( '_transient_' ) );
			}
			
			// Delete the transient
			if ( delete_transient( $transient_name ) ) {
				$count++;
			}
		}
		
		// Log the cache clearing
		Plugin::instance()->log(
			sprintf( 'Cleared %d cached audio chunks', $count ),
			[ 'source' => 'admin' ],
			true
		);
		
		return $count;
	}

	/**
	 * AJAX handler for clearing cache
	 */
	public function clear_cache_ajax() {
		// Check for nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'jet_audio_streaming_clear_cache' ) ) {
			wp_send_json_error( [ 'message' => 'Invalid security token' ] );
		}
		
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied' ] );
		}
		
		// Clear cache
		$count = $this->clear_cache();
		
		// Send response
		wp_send_json_success( [
			'message' => sprintf( 'Successfully cleared %d cached audio chunks', $count ),
			'count' => $count,
		] );
	}
} 