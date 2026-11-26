<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the plugin's two custom tables:
 * - "leads" -- one row per gate pass / download event (the download log)
 * - "subscribers" -- one row per unique email, aggregated across events
 * Plus creation/upgrade and CRUD helpers for both.
 */
class ADX_GR_DB {

	const DB_VERSION = '1.2';
	const SUBSCRIBERS_TABLE = 'adx_gr_subscribers';

	/**
	 * Columns that existed before the 1.2 "field_data" column was
	 * added. Kept so legacy rows (and the CSV export / list tables)
	 * still work for values captured before this schema change,
	 * without needing to backfill field_data for old rows.
	 */
	const LEGACY_FIELD_COLUMNS = array( 'first_name', 'last_name', 'company', 'role', 'state', 'project_name' );

	public static function activate() {
		self::create_tables();
		ADX_GR_CPT::register_post_type();
		flush_rewrite_rules();
	}

	/**
	 * Runs on every load (not just activation) so an already-installed
	 * site picks up schema changes without needing a deactivate/reactivate.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'adx_gr_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
		}

		// Whenever the plugin is updated, forget the file sizes and counts
		// it looked up earlier; the library page fills them in again. A
		// lookup remembered from before an upload finished, or by an older
		// version, can't linger.
		if ( get_option( 'adx_gr_lookup_cache_version' ) !== ADX_GR_VERSION ) {
			self::clear_lookup_cache();
			update_option( 'adx_gr_lookup_cache_version', ADX_GR_VERSION, false );
		}
	}

	/**
	 * Forgets every remembered file size and zip file count (hosted
	 * elsewhere, or read from disk).
	 */
	public static function clear_lookup_cache() {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_adx_gr_rsize_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_adx_gr_rsize_' ) . '%',
				$wpdb->esc_like( '_transient_adx_gr_rzip_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_adx_gr_rzip_' ) . '%'
			)
		);

		delete_post_meta_by_key( '_adx_gr_download_info' );
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$leads_table = $wpdb->prefix . ADX_GR_LEADS_TABLE;
		$sql         = "CREATE TABLE {$leads_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			first_name VARCHAR(100) NOT NULL DEFAULT '',
			last_name VARCHAR(100) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL,
			company VARCHAR(190) NOT NULL DEFAULT '',
			role VARCHAR(100) NOT NULL DEFAULT '',
			state VARCHAR(100) NOT NULL DEFAULT '',
			project_name VARCHAR(190) NOT NULL DEFAULT '',
			field_data LONGTEXT NULL,
			resource_id BIGINT(20) UNSIGNED NOT NULL,
			resource_title VARCHAR(255) NOT NULL DEFAULT '',
			zerobounce_status VARCHAR(50) NOT NULL DEFAULT '',
			zerobounce_sub_status VARCHAR(50) NOT NULL DEFAULT '',
			via_cookie TINYINT(1) NOT NULL DEFAULT 0,
			ip_address VARCHAR(100) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			page_url VARCHAR(500) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY resource_id (resource_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		$subscribers_table = $wpdb->prefix . self::SUBSCRIBERS_TABLE;
		$sql               = "CREATE TABLE {$subscribers_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			field_data LONGTEXT NULL,
			zerobounce_status VARCHAR(50) NOT NULL DEFAULT '',
			zerobounce_sub_status VARCHAR(50) NOT NULL DEFAULT '',
			downloads_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'adx_gr_db_version', self::DB_VERSION );
	}

	public static function insert_lead( array $data ) {
		global $wpdb;

		$table = $wpdb->prefix . ADX_GR_LEADS_TABLE;

		$defaults = array(
			'email'                 => '',
			'fields'                => array(),
			'resource_id'           => 0,
			'resource_title'        => '',
			'zerobounce_status'     => '',
			'zerobounce_sub_status' => '',
			'via_cookie'            => 0,
			'ip_address'            => '',
			'user_agent'            => '',
			'page_url'              => '',
			'created_at'            => current_time( 'mysql' ),
		);

		$data   = wp_parse_args( $data, $defaults );
		$fields = is_array( $data['fields'] ) ? $data['fields'] : array();

		$row = array(
			'email'                 => sanitize_email( $data['email'] ),
			'resource_id'           => absint( $data['resource_id'] ),
			'resource_title'        => sanitize_text_field( $data['resource_title'] ),
			'field_data'            => wp_json_encode( $fields ),
			'zerobounce_status'     => sanitize_text_field( $data['zerobounce_status'] ),
			'zerobounce_sub_status' => sanitize_text_field( $data['zerobounce_sub_status'] ),
			'via_cookie'            => $data['via_cookie'] ? 1 : 0,
			'ip_address'            => sanitize_text_field( $data['ip_address'] ),
			'user_agent'            => sanitize_text_field( mb_substr( (string) $data['user_agent'], 0, 250 ) ),
			'page_url'              => sanitize_text_field( mb_substr( (string) $data['page_url'], 0, 490 ) ),
			'created_at'            => $data['created_at'],
		);

		foreach ( self::LEGACY_FIELD_COLUMNS as $column ) {
			$row[ $column ] = isset( $fields[ $column ] ) ? sanitize_text_field( $fields[ $column ] ) : '';
		}

		$formats = array();
		foreach ( $row as $key => $value ) {
			$formats[] = in_array( $key, array( 'resource_id', 'via_cookie' ), true ) ? '%d' : '%s';
		}

		$wpdb->insert( $table, $row, $formats );

		return $wpdb->insert_id;
	}

	public static function get_leads( array $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . ADX_GR_LEADS_TABLE;

		$defaults = array(
			'search'   => '',
			'orderby'  => 'created_at',
			'order'    => 'DESC',
			'per_page' => 20,
			'paged'    => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$where   .= ' AND (email LIKE %s OR resource_title LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$allowed_orderby = array( 'created_at', 'email', 'resource_title', 'zerobounce_status' );
		$orderby          = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order            = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = max( 0, ( absint( $args['paged'] ) - 1 ) * $per_page );

		$sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$prepared = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $wpdb->get_results( $prepared );
	}

	public static function count_leads( $search = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . ADX_GR_LEADS_TABLE;

		if ( ! empty( $search ) ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE email LIKE %s OR resource_title LIKE %s",
					$like,
					$like
				)
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public static function get_all_leads_for_export() {
		global $wpdb;
		$table = $wpdb->prefix . ADX_GR_LEADS_TABLE;
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );
	}

	/**
	 * How many downloads, and by how many different people, were logged in
	 * [$from, $to). Counted in the database, so it stays cheap however many
	 * there are.
	 *
	 * @return array [ downloads, people ]
	 */
	public static function count_leads_between( $from, $to ) {
		global $wpdb;
		$table = $wpdb->prefix . ADX_GR_LEADS_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS downloads, COUNT(DISTINCT LOWER(email)) AS people FROM {$table} WHERE created_at >= %s AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to
			)
		);

		return array( $row ? (int) $row->downloads : 0, $row ? (int) $row->people : 0 );
	}

	/**
	 * Downloads logged in [$from, $to), oldest first. Both bounds are
	 * site-local MySQL datetimes, the same clock created_at is stored in.
	 */
	public static function get_leads_between( $from, $to ) {
		global $wpdb;
		$table = $wpdb->prefix . ADX_GR_LEADS_TABLE;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE created_at >= %s AND created_at < %s ORDER BY created_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$to
			)
		);
	}

	/**
	 * Empties the download log (every gate-pass event). Does not touch
	 * the Subscribers table -- clearing the event log is meant for
	 * tidying up test/junk entries, not for forgetting who subscribed.
	 *
	 * @return bool True once the table is confirmed empty, false if the
	 *              query failed (e.g. a DB permissions issue) -- checked
	 *              by re-counting rather than trusting query()'s return
	 *              value, since TRUNCATE can return a false-y result on
	 *              some setups even when it actually ran.
	 */
	public static function clear_leads() {
		global $wpdb;
		$table = $wpdb->prefix . ADX_GR_LEADS_TABLE;
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return 0 === self::count_leads();
	}

	/**
	 * Reads one field's value off a lead/download row: prefers the
	 * JSON field_data blob (works for any configured field, including
	 * ones added after this row was recorded, which will just be
	 * blank), falling back to the fixed legacy column for rows
	 * recorded before field_data existed.
	 */
	public static function get_row_field_value( $row, $key ) {
		if ( ! empty( $row->field_data ) ) {
			$decoded = json_decode( $row->field_data, true );
			if ( is_array( $decoded ) && array_key_exists( $key, $decoded ) ) {
				return $decoded[ $key ];
			}
		}

		if ( in_array( $key, self::LEGACY_FIELD_COLUMNS, true ) && isset( $row->$key ) ) {
			return $row->$key;
		}

		return '';
	}

	/**
	 * Inserts or updates the single "subscribers" row for this email:
	 * merges in the latest submitted field values, bumps the download
	 * count and last_seen timestamp. Skipped for cookie-remembered
	 * downloads that somehow precede any known subscriber record
	 * (shouldn't happen in practice, since the cookie is only set after
	 * a first successful upsert). Pass $is_download = false for a
	 * gate-form "login" that hasn't downloaded anything yet.
	 */
	public static function upsert_subscriber( $email, array $fields, $zb_status = '', $zb_sub_status = '', $is_download = true ) {
		global $wpdb;

		$table = $wpdb->prefix . self::SUBSCRIBERS_TABLE;
		$email = sanitize_email( $email );
		$now   = current_time( 'mysql' );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ) );

		if ( $existing ) {
			$merged = array();
			if ( ! empty( $existing->field_data ) ) {
				$decoded = json_decode( $existing->field_data, true );
				$merged  = is_array( $decoded ) ? $decoded : array();
			}
			foreach ( $fields as $key => $value ) {
				if ( '' !== trim( (string) $value ) ) {
					$merged[ $key ] = $value;
				}
			}

			$update = array(
				'field_data'      => wp_json_encode( $merged ),
				'downloads_count' => (int) $existing->downloads_count + ( $is_download ? 1 : 0 ),
				'last_seen'       => $now,
			);
			// 'cookie' isn't a real ZeroBounce verdict -- it just marks a
			// remembered repeat visit where no check was re-run. Keep the
			// subscriber's last genuine ZeroBounce status intact instead
			// of overwriting it on every return download.
			if ( $zb_status && 'cookie' !== $zb_status ) {
				$update['zerobounce_status']     = sanitize_text_field( $zb_status );
				$update['zerobounce_sub_status'] = sanitize_text_field( $zb_sub_status );
			}

			$wpdb->update( $table, $update, array( 'id' => $existing->id ) );

			return (int) $existing->id;
		}

		$wpdb->insert(
			$table,
			array(
				'email'                 => $email,
				'field_data'            => wp_json_encode( $fields ),
				'zerobounce_status'     => 'cookie' === $zb_status ? '' : sanitize_text_field( $zb_status ),
				'zerobounce_sub_status' => 'cookie' === $zb_status ? '' : sanitize_text_field( $zb_sub_status ),
				'downloads_count'       => $is_download ? 1 : 0,
				'first_seen'            => $now,
				'last_seen'             => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * Whether this email is already in the Subscribers list.
	 */
	public static function subscriber_exists( $email ) {
		global $wpdb;

		$table = $wpdb->prefix . self::SUBSCRIBERS_TABLE;

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE email = %s LIMIT 1", sanitize_email( $email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The form values last saved for this email, or an empty array.
	 */
	public static function get_subscriber_fields( $email ) {
		global $wpdb;

		$table = $wpdb->prefix . self::SUBSCRIBERS_TABLE;
		$json  = $wpdb->get_var( $wpdb->prepare( "SELECT field_data FROM {$table} WHERE email = %s", sanitize_email( $email ) ) );
		$data  = $json ? json_decode( $json, true ) : array();

		return is_array( $data ) ? $data : array();
	}

	public static function get_subscribers( array $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . self::SUBSCRIBERS_TABLE;

		$defaults = array(
			'search'   => '',
			'orderby'  => 'last_seen',
			'order'    => 'DESC',
			'per_page' => 20,
			'paged'    => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$where   .= ' AND (email LIKE %s OR field_data LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$allowed_orderby = array( 'email', 'downloads_count', 'first_seen', 'last_seen' );
		$orderby          = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'last_seen';
		$order            = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = max( 0, ( absint( $args['paged'] ) - 1 ) * $per_page );

		$sql      = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$prepared = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $wpdb->get_results( $prepared );
	}

	public static function count_subscribers( $search = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . self::SUBSCRIBERS_TABLE;

		if ( ! empty( $search ) ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email LIKE %s OR field_data LIKE %s", $like, $like )
			);
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Removes subscriber rows by ID. Their entries in the download log
	 * are left alone, same as clear_leads() leaves subscribers alone --
	 * the two are managed independently.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function delete_subscribers( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = $wpdb->prefix . self::SUBSCRIBERS_TABLE;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Subscriber rows for the given emails, keyed by lowercased email.
	 */
	public static function get_subscribers_by_emails( array $emails ) {
		global $wpdb;

		$emails = array_values( array_unique( array_filter( array_map( 'sanitize_email', $emails ) ) ) );

		if ( empty( $emails ) ) {
			return array();
		}

		$table        = $wpdb->prefix . self::SUBSCRIBERS_TABLE;
		$placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email IN ({$placeholders})", $emails ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$by_email = array();
		foreach ( $rows as $row ) {
			$by_email[ strtolower( $row->email ) ] = $row;
		}

		return $by_email;
	}

	public static function get_all_subscribers_for_export() {
		global $wpdb;
		$table = $wpdb->prefix . self::SUBSCRIBERS_TABLE;
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY last_seen DESC" );
	}

	public static function maybe_drop_tables() {
		global $wpdb;
		$leads_table       = $wpdb->prefix . ADX_GR_LEADS_TABLE;
		$subscribers_table = $wpdb->prefix . self::SUBSCRIBERS_TABLE;
		$wpdb->query( "DROP TABLE IF EXISTS {$leads_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$subscribers_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
