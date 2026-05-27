<?php
/**
 * HYT Database — WordPress veritabani ile tum pipeline/log islemleri.
 */
defined( 'ABSPATH' ) || exit;

class HYT_Database {

	public static function install(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$table_pipeline = $wpdb->prefix . 'hyt_pipeline';
		$sql_pipeline   = "CREATE TABLE IF NOT EXISTS {$table_pipeline} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			drive_file_id   VARCHAR(255)  DEFAULT '',
			file_name       VARCHAR(255)  DEFAULT '',
			title           VARCHAR(512)  DEFAULT '',
			raw_content     LONGTEXT      DEFAULT '',
			status          VARCHAR(50)   DEFAULT 'pending',
			step            VARCHAR(100)  DEFAULT '',
			post_id         BIGINT(20) UNSIGNED DEFAULT 0,
			seo_data        LONGTEXT      DEFAULT '',
			payload         LONGTEXT      DEFAULT '',
			flag_seo        TINYINT(1)    DEFAULT 0,
			flag_img        TINYINT(1)    DEFAULT 0,
			flag_video      TINYINT(1)    DEFAULT 0,
			flag_social     TINYINT(1)    DEFAULT 0,
			flag_backlink   TINYINT(1)    DEFAULT 0,
			flag_review     TINYINT(1)    DEFAULT 0,
			heygen_video_id VARCHAR(255)  DEFAULT '',
			heygen_video_url TEXT         DEFAULT '',
			review_status   VARCHAR(50)   DEFAULT '',
			review_note     TEXT          DEFAULT '',
			error_message   TEXT          DEFAULT '',
			scheduled_at    DATETIME      DEFAULT NULL,
			created_at      DATETIME      DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY step (step(50)),
			KEY drive_file_id (drive_file_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$table_logs = $wpdb->prefix . 'hyt_logs';
		$sql_logs   = "CREATE TABLE IF NOT EXISTS {$table_logs} (
			id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			level      VARCHAR(20)  DEFAULT 'info',
			context    VARCHAR(100) DEFAULT '',
			message    TEXT         DEFAULT '',
			data       LONGTEXT     DEFAULT '',
			created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY created_at (created_at)
		) {$charset_collate};";

		$table_dist = $wpdb->prefix . 'hyt_distribution';
		$sql_dist   = "CREATE TABLE IF NOT EXISTS {$table_dist} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			pipeline_id   BIGINT(20) UNSIGNED DEFAULT 0,
			channel       VARCHAR(50)  DEFAULT '',
			status        VARCHAR(50)  DEFAULT 'pending',
			channel_id    VARCHAR(255) DEFAULT '',
			response      LONGTEXT     DEFAULT '',
			created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY pipeline_id (pipeline_id),
			KEY channel (channel)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_pipeline );
		dbDelta( $sql_logs );
		dbDelta( $sql_dist );

		update_option( 'hyt_db_version', HYT_VERSION );
	}

	public static function insert_pipeline( array $data ): int {
		global $wpdb;
		$table  = $wpdb->prefix . 'hyt_pipeline';
		$defaults = [
			'drive_file_id'   => '', 'file_name' => '', 'title' => '',
			'raw_content'     => '', 'status' => 'pending', 'step' => '',
			'post_id'         => 0, 'seo_data' => '', 'payload' => '',
			'flag_seo'        => 0, 'flag_img' => 0, 'flag_video' => 0,
			'flag_social'     => 0, 'flag_backlink' => 0,
			'heygen_video_id' => '', 'heygen_video_url' => '',
			'error_message'   => '', 'scheduled_at' => null,
		];
		$row = wp_parse_args( $data, $defaults );
		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	public static function update_pipeline( int $id, array $data ): bool {
		global $wpdb;
		return false !== $wpdb->update( $wpdb->prefix . 'hyt_pipeline', $data, [ 'id' => $id ] );
	}

	public static function get_pipeline( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hyt_pipeline WHERE id = %d", $id ) );
	}

	public static function find_by_drive_file_id( string $file_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hyt_pipeline WHERE drive_file_id = %s LIMIT 1", $file_id ) );
	}

	public static function pipeline_exists_by_title( string $title ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hyt_pipeline WHERE title = %s AND status NOT IN ('trash') LIMIT 1", $title ) );
	}

	public static function count_pipelines( array $where = [] ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'hyt_pipeline';
		$sql   = "SELECT COUNT(*) FROM {$table}";

		$filter_keys = ['per_page', 'page'];
		$filtered_where = array_diff_key( $where, array_flip( $filter_keys ) );

		$conditions = [];
		if ( ! empty( $filtered_where ) ) {
			foreach ( $filtered_where as $key => $value ) {
				if ( is_array( $value ) ) {
					$ph = implode( ',', array_fill( 0, count( $value ), '%s' ) );
					$conditions[] = $wpdb->prepare( "{$key} IN ({$ph})", ...$value );
				} else {
					$conditions[] = $wpdb->prepare( "{$key} = %s", $value );
				}
			}
			$sql .= ' WHERE ' . implode( ' AND ', $conditions );
		}

		return (int) $wpdb->get_var( $sql );
	}

	public static function get_pipelines( array $args = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'hyt_pipeline';

		$defaults = [ 'status' => null, 'orderby' => 'created_at', 'order' => 'DESC', 'per_page' => 50, 'page' => 1 ];
		$args    = wp_parse_args( $args, $defaults );

		$sql  = "SELECT * FROM {$table}";
		$conditions = [];
		if ( $args['status'] ) { $conditions[] = $wpdb->prepare( 'status = %s', $args['status'] ); }
		if ( ! empty( $conditions ) ) { $sql .= ' WHERE ' . implode( ' AND ', $conditions ); }

		$orderby = in_array( $args['orderby'], [ 'created_at', 'title', 'status', 'step' ], true ) ? $args['orderby'] : 'created_at';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		$sql    .= " ORDER BY {$orderby} {$order}";

		$per_page = (int) $args['per_page'];
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;
		$sql     .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, $offset );

		return $wpdb->get_results( $sql );
	}

	public static function insert_log( string $level, string $context, string $message, $data = null ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'hyt_logs', [
			'level'      => sanitize_text_field( $level ),
			'context'    => sanitize_text_field( $context ),
			'message'    => sanitize_textarea_field( $message ),
			'data'       => is_string( $data ) ? $data : ( is_array( $data ) || is_object( $data ) ? wp_json_encode( $data ) : '' ),
			'created_at' => current_time( 'mysql' ),
		] );
	}

	public static function get_logs( array $args = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'hyt_logs';

		$defaults = [ 'level' => '', 'context' => '', 'per_page' => 50, 'page' => 1 ];
		$args    = wp_parse_args( $args, $defaults );

		$sql = "SELECT * FROM {$table}";
		$conditions = [];
		if ( $args['level'] ) { $conditions[] = $wpdb->prepare( 'level = %s', $args['level'] ); }
		if ( $args['context'] ) { $conditions[] = $wpdb->prepare( 'context = %s', $args['context'] ); }
		if ( ! empty( $conditions ) ) { $sql .= ' WHERE ' . implode( ' AND ', $conditions ); }

		$sql .= ' ORDER BY id DESC';

		$per_page = (int) $args['per_page'];
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;
		$sql     .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, $offset );

		return $wpdb->get_results( $sql );
	}

	public static function count_logs( array $where = [] ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'hyt_logs';
		$sql   = "SELECT COUNT(*) FROM {$table}";
		$conditions = [];

		$filter_keys = ['per_page', 'page'];
		$filtered_where = array_diff_key( $where, array_flip( $filter_keys ) );

		if ( ! empty( $filtered_where['level'] ) ) { $conditions[] = $wpdb->prepare( 'level = %s', $filtered_where['level'] ); }
		if ( ! empty( $filtered_where['context'] ) ) { $conditions[] = $wpdb->prepare( 'context = %s', $filtered_where['context'] ); }
		if ( ! empty( $conditions ) ) { $sql .= ' WHERE ' . implode( ' AND ', $conditions ); }

		return (int) $wpdb->get_var( $sql );
	}

	public static function get_log_contexts(): array {
		global $wpdb;
		$results = $wpdb->get_col( "SELECT DISTINCT context FROM {$wpdb->prefix}hyt_logs WHERE context != '' ORDER BY context ASC" );
		return is_array( $results ) ? $results : [];
	}

	public static function clear_logs(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}hyt_logs" );
	}

	public static function insert_distribution( array $data ): int {
		global $wpdb;
		$defaults = [ 'pipeline_id' => 0, 'channel' => '', 'status' => 'pending', 'channel_id' => '', 'response' => '' ];
		$row = wp_parse_args( $data, $defaults );
		$wpdb->insert( $wpdb->prefix . 'hyt_distribution', $row );
		return (int) $wpdb->insert_id;
	}
}