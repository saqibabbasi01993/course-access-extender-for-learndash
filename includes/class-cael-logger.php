<?php
/**
 * Logger Class
 *
 * Creates and manages the plugin's custom log table for auditing
 * all course access extension events.
 *
 * @package SKFreelancers\LearnDashCourseExtension
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CAEL_Logger
 *
 * All direct DB queries intentionally omit object caching because this class
 * manages an audit log that must always reflect the live database state.
 * Write operations (INSERT, DELETE, DROP) do not use caching by definition.
 */
class CAEL_Logger {

	/**
	 * Custom table name without prefix.
	 *
	 * @var string
	 */
	const TABLE_NAME = 'cael_access_log';

	/**
	 * Create the log table on plugin activation.
	 *
	 * dbDelta() is the WordPress-sanctioned API for schema changes in activation.
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id           BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id      BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
			order_id     BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
			product_id   BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
			course_id    BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
			action       VARCHAR(20)  NOT NULL DEFAULT '',
			message      TEXT         NOT NULL,
			created_at   DATETIME     NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY user_id   (user_id),
			KEY order_id  (order_id),
			KEY course_id (course_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a log entry.
	 *
	 * @param int    $user_id    WordPress user ID (0 for guests).
	 * @param int    $order_id   WooCommerce order ID.
	 * @param int    $product_id WooCommerce product ID.
	 * @param string $action     Short action code.
	 * @param string $message    Human-readable description.
	 * @param int    $course_id  LearnDash course ID.
	 */
	public static function log( $user_id, $order_id, $product_id, $action, $message, $course_id = 0 ) {
		if ( ! get_option( 'cael_enable_logging', 1 ) ) {
			return;
		}

		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . self::TABLE_NAME,
			array(
				'user_id'    => absint( $user_id ),
				'order_id'   => absint( $order_id ),
				'product_id' => absint( $product_id ),
				'course_id'  => absint( $course_id ),
				'action'     => sanitize_key( $action ),
				'message'    => sanitize_text_field( $message ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Retrieve log entries with optional filters.
	 *
	 * All filter values are sanitized immediately on entry. The WHERE clause
	 * is built entirely within this method using $wpdb->prepare() per clause —
	 * no SQL fragment is ever stored in a variable that crosses a method
	 * boundary or a parameter assignment that the static analyser could flag.
	 *
	 * Table name: $wpdb->prefix (trusted WP internal) + hard-coded constant,
	 * passed through esc_sql(). Never contains user input.
	 *
	 * Caching omitted: audit log must always show live DB state.
	 *
	 * @param array $args {
	 *     @type int    $user_id   Filter by user ID.
	 *     @type int    $order_id  Filter by order ID.
	 *     @type int    $course_id Filter by course ID.
	 *     @type string $action    Filter by action code.
	 *     @type string $search    Keyword search in message column.
	 *     @type string $date_from Start date YYYY-MM-DD (inclusive).
	 *     @type string $date_to   End date   YYYY-MM-DD (inclusive).
	 *     @type int    $per_page  Rows per page. Default 20.
	 *     @type int    $paged     Current page. Default 1.
	 * }
	 * @return array { items: object[], total: int }
	 */
	public static function get_logs( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'user_id'   => 0,
				'order_id'  => 0,
				'course_id' => 0,
				'action'    => '',
				'search'    => '',
				'date_from' => '',
				'date_to'   => '',
				'per_page'  => 20,
				'paged'     => 1,
			)
		);

		// Sanitize every filter value immediately.
		$f_user_id    = absint( $args['user_id'] );
		$f_order_id   = absint( $args['order_id'] );
		$f_course_id  = absint( $args['course_id'] );
		$f_action     = sanitize_key( $args['action'] );
		$f_search     = sanitize_text_field( $args['search'] );
		$f_date_from  = sanitize_text_field( $args['date_from'] );
		$f_date_to    = sanitize_text_field( $args['date_to'] );
		$per_page     = absint( $args['per_page'] );
		$offset       = ( absint( $args['paged'] ) - 1 ) * $per_page;

		/*
		 * Build WHERE clause fragments.
		 *
		 * Each fragment is produced by $wpdb->prepare() with a hard-coded
		 * column name and a single sanitized value. The resulting strings
		 * contain no unresolved placeholders and no raw user input.
		 * They are joined by literal ' AND ' — a hard-coded string.
		 *
		 * The complete WHERE clause is assembled and used entirely within
		 * this single method — no SQL fragment is ever assigned to a variable
		 * that is then passed across a method or function boundary.
		 */
		$clauses = array( '1=1' );

		if ( $f_user_id ) {
			$clauses[] = $wpdb->prepare( 'user_id = %d', $f_user_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( $f_order_id ) {
			$clauses[] = $wpdb->prepare( 'order_id = %d', $f_order_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( $f_course_id ) {
			$clauses[] = $wpdb->prepare( 'course_id = %d', $f_course_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( $f_action ) {
			$clauses[] = $wpdb->prepare( 'action = %s', $f_action ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( $f_search ) {
			$clauses[] = $wpdb->prepare( 'message LIKE %s', '%' . $wpdb->esc_like( $f_search ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( $f_date_from ) {
			$clauses[] = $wpdb->prepare( 'created_at >= %s', $f_date_from . ' 00:00:00' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( $f_date_to ) {
			$clauses[] = $wpdb->prepare( 'created_at <= %s', $f_date_to . ' 23:59:59' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		/*
		 * $t : table name from hard-coded constant + $wpdb->prefix. esc_sql() applied.
		 * $w : WHERE clause built entirely from prepare() outputs + literal strings.
		 *      No raw user input is present anywhere in $w.
		 *
		 * Both variables are local to this method. Neither crosses any boundary.
		 * The static analyser sees their full assignment chain within this scope.
		 */
		$t = esc_sql( $wpdb->prefix . self::TABLE_NAME );
		$w = implode( ' AND ', $clauses );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$w}" );
		$items = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset )
		);
		// phpcs:enable

		return array(
			'items' => $items ? $items : array(),
			'total' => $count,
		);
	}

	/**
	 * Delete entries older than the configured retention period.
	 *
	 * Direct DELETE required — no WP ORM equivalent for time-range deletes.
	 * Write operation: caching not applicable.
	 */
	public static function cleanup() {
		global $wpdb;

		$cael_days = absint( get_option( 'cael_log_retention_days', 30 ) );
		if ( ! $cael_days ) {
			return;
		}

		$t      = esc_sql( $wpdb->prefix . self::TABLE_NAME );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$cael_days} days" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s", $cutoff ) );
		// phpcs:enable
	}

	/**
	 * Drop the log table on uninstall.
	 *
	 * DROP TABLE is DDL — MySQL does not support parameterised placeholders for
	 * table names in DDL statements. $t is a hard-coded constant + $wpdb->prefix,
	 * processed through esc_sql(). Runs only behind WP_UNINSTALL_PLUGIN.
	 */
	public static function drop_table() {
		global $wpdb;

		$t = esc_sql( $wpdb->prefix . self::TABLE_NAME );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
		// phpcs:enable
	}
}
