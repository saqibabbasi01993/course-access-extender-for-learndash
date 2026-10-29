<?php
/**
 * Admin Page Class
 *
 * Renders the plugin admin area with four tabs:
 *  1. Settings
 *  2. Students  ← NEW: enrollment list, expiry management, purchase history modal
 *  3. Activity Log
 *
 * @package SKFreelancers\LearnDashCourseExtension
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CAEL_Admin
 */
class CAEL_Admin {

	/** @var CAEL_Admin|null */
	private static $instance = null;

	/** @var string Admin page hook suffix. */
	private $page_hook;

	/** @return CAEL_Admin */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . CAEL_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );

		// AJAX: fetch purchase history for a student.
		add_action( 'wp_ajax_cael_get_purchase_history', array( $this, 'ajax_get_purchase_history' ) );
	}

	// -------------------------------------------------------------------------
	// Menu & Settings
	// -------------------------------------------------------------------------

	/** Register the submenu page. */
	public function register_menu() {
		$this->page_hook = add_submenu_page(
			'woocommerce',
			__( 'Course Access Extender', 'course-access-extender-for-learndash' ),
			__( 'Course Access Extender', 'course-access-extender-for-learndash' ),
			'manage_woocommerce',
			'cael-settings',
			array( $this, 'render_page' )
		);
	}

	/** Register Settings API fields. */
	public function register_settings() {
		$settings = array(
			'cael_extension_days'          => array( 'type' => 'integer', 'default' => 90,    'sanitize_callback' => 'absint' ),
			'cael_enable_logging'          => array( 'type' => 'integer', 'default' => 1,     'sanitize_callback' => 'absint' ),
			'cael_log_retention_days'      => array( 'type' => 'integer', 'default' => 30,    'sanitize_callback' => 'absint' ),
			'cael_delete_data_on_uninstall'=> array( 'type' => 'integer', 'default' => 0,     'sanitize_callback' => 'absint' ),
		);

		foreach ( $settings as $key => $args ) {
			register_setting( 'cael_settings_group', $key, $args );
		}

		add_settings_section( 'cael_general', __( 'General Settings', 'course-access-extender-for-learndash' ), '__return_empty_string', 'cael-settings' );

		add_settings_field( 'cael_extension_days', __( 'Extension Duration (days)', 'course-access-extender-for-learndash' ), array( $this, 'field_extension_days' ), 'cael-settings', 'cael_general' );
		add_settings_field( 'cael_enable_logging', __( 'Enable Activity Logging', 'course-access-extender-for-learndash' ), array( $this, 'field_enable_logging' ), 'cael-settings', 'cael_general' );
		add_settings_field( 'cael_log_retention_days', __( 'Log Retention (days)', 'course-access-extender-for-learndash' ), array( $this, 'field_log_retention' ), 'cael-settings', 'cael_general' );
		add_settings_field( 'cael_delete_data_on_uninstall', __( 'Delete Data on Uninstall', 'course-access-extender-for-learndash' ), array( $this, 'field_delete_on_uninstall' ), 'cael-settings', 'cael_general' );
	}

	/** Field: extension days. */
	public function field_extension_days() {
		$v = absint( get_option( 'cael_extension_days', 90 ) );
		printf( '<input type="number" name="ldce_extension_days" value="%d" min="1" max="3650" class="small-text"><p class="description">%s</p>', esc_attr( $v ), esc_html__( 'Days of access added per Course Extension purchase.', 'course-access-extender-for-learndash' ) );
	}

	/** Field: enable logging. */
	public function field_enable_logging() {
		$v = get_option( 'cael_enable_logging', 1 );
		printf( '<label><input type="checkbox" name="ldce_enable_logging" value="1" %s> %s</label><p class="description">%s</p>', checked( 1, $v, false ), esc_html__( 'Enabled', 'course-access-extender-for-learndash' ), esc_html__( 'Log all access extension events to the Activity Log tab.', 'course-access-extender-for-learndash' ) );
	}

	/** Field: log retention. */
	public function field_log_retention() {
		$v = absint( get_option( 'cael_log_retention_days', 30 ) );
		printf( '<input type="number" name="ldce_log_retention_days" value="%d" min="0" max="365" class="small-text"><p class="description">%s</p>', esc_attr( $v ), esc_html__( 'Entries older than this are auto-deleted. Use 0 to keep forever.', 'course-access-extender-for-learndash' ) );
	}

	/** Field: delete data on uninstall. */
	public function field_delete_on_uninstall() {
		$v = get_option( 'cael_delete_data_on_uninstall', 0 );
		printf(
			'<label><input type="checkbox" name="ldce_delete_data_on_uninstall" value="1" %s> %s</label><p class="description">%s</p>',
			checked( 1, $v, false ),
			esc_html__( 'Enabled', 'course-access-extender-for-learndash' ),
			esc_html__( 'When checked, deleting this plugin will permanently remove all log entries and plugin settings. Leave unchecked to preserve data across plugin updates and reinstalls.', 'course-access-extender-for-learndash' )
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue CSS and JS on the plugin's admin page.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style( 'cael-admin', CAEL_PLUGIN_URL . 'admin/css/admin.css', array(), CAEL_VERSION . '.2' );
		wp_enqueue_script( 'cael-admin', CAEL_PLUGIN_URL . 'admin/js/admin.js', array( 'jquery' ), CAEL_VERSION . '.2', true );

		wp_localize_script(
			'cael-admin',
			'caelAdmin',
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'cael_adjust_expiry_nonce' ),
				'historyNonce'      => wp_create_nonce( 'cael_purchase_history_nonce' ),
				'confirmAdjust'     => __( 'Update this student\'s expiry date?', 'course-access-extender-for-learndash' ),
				'saveLabel'         => __( 'Save', 'course-access-extender-for-learndash' ),
				'saving'            => __( 'Saving…', 'course-access-extender-for-learndash' ),
				'saved'             => __( 'Saved!', 'course-access-extender-for-learndash' ),
				'errorMsg'          => __( 'An error occurred. Please try again.', 'course-access-extender-for-learndash' ),
				'loadingHistory'    => __( 'Loading purchase history…', 'course-access-extender-for-learndash' ),
				'noHistory'         => __( 'No course-related purchases found for this student.', 'course-access-extender-for-learndash' ),
				'activeLabel'       => __( 'Active', 'course-access-extender-for-learndash' ),
				'expiredLabel'      => __( 'Expired', 'course-access-extender-for-learndash' ),
				'expiredBadgeLabel' => __( 'Expired', 'course-access-extender-for-learndash' ),
				'confirmClear'      => __( 'Clear ALL log entries? This cannot be undone.', 'course-access-extender-for-learndash' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Main page router
	// -------------------------------------------------------------------------

	/** Render the full admin page. */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'course-access-extender-for-learndash' ) );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification

		$tabs = array(
			'settings' => __( 'Settings', 'course-access-extender-for-learndash' ),
			'students' => __( 'Students', 'course-access-extender-for-learndash' ),
			'logs'     => __( 'Activity Log', 'course-access-extender-for-learndash' ),
		);
		?>
		<div class="wrap ldce-wrap">
			<h1><?php esc_html_e( 'Course Access Extender for LearnDash', 'course-access-extender-for-learndash' ); ?></h1>
			<p class="ldce-byline"><?php esc_html_e( 'by SKFreelancers', 'course-access-extender-for-learndash' ); ?></p>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cael-settings&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo esc_attr( $active_tab === $slug ? 'nav-tab-active' : '' ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="ldce-tab-content">
				<?php
				switch ( $active_tab ) {
					case 'students':
						$this->render_students_tab();
						break;
					case 'logs':
						$this->render_logs_tab();
						break;
					default:
						$this->render_settings_tab();
						break;
				}
				?>
			</div>
		</div>

		<?php
		// Only render the modal HTML on the Students tab where it is needed.
		if ( 'students' === $active_tab ) {
			$this->render_purchase_history_modal();
		}
		?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab: Settings
	// -------------------------------------------------------------------------

	/** Render the settings tab. */
	private function render_settings_tab() {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'cael_settings_group' );
			do_settings_sections( 'cael-settings' );
			submit_button( __( 'Save Settings', 'course-access-extender-for-learndash' ) );
			?>
		</form>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab: Students
	// -------------------------------------------------------------------------

	/** Render the Students tab. */
	private function render_students_tab() {
		// phpcs:disable WordPress.Security.NonceVerification
		$course_filter  = isset( $_GET['ldce_course'] ) ? absint( $_GET['ldce_course'] ) : 0;
		$status_filter  = isset( $_GET['ldce_status'] ) ? sanitize_key( $_GET['ldce_status'] ) : 'all';
		$search         = isset( $_GET['ldce_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ldce_search'] ) ) : '';
		$orderby        = isset( $_GET['ldce_orderby'] ) ? sanitize_key( $_GET['ldce_orderby'] ) : 'name';
		$order          = ( isset( $_GET['ldce_order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_GET['ldce_order'] ) ) ) ) ? 'DESC' : 'ASC';
		$paged          = isset( $_GET['ldce_paged'] ) ? absint( $_GET['ldce_paged'] ) : 1;
		// phpcs:enable

		$per_page = 20;
		$courses  = CAEL_Students::get_courses_list();

		// Read new filter vars (declared later in the filters form block but needed here).
		$expiry_within_pre = isset( $_GET['ldce_expiry_within'] ) ? absint( $_GET['ldce_expiry_within'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$enrolled_from_pre = isset( $_GET['ldce_enrolled_from'] ) ? sanitize_text_field( wp_unslash( $_GET['ldce_enrolled_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$enrolled_to_pre   = isset( $_GET['ldce_enrolled_to'] ) ? sanitize_text_field( wp_unslash( $_GET['ldce_enrolled_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$result = CAEL_Students::get_enrolled_students(
			array(
				'course_id'     => $course_filter,
				'search'        => $search,
				'status'        => $status_filter,
				'orderby'       => $orderby,
				'order'         => $order,
				'per_page'      => $per_page,
				'paged'         => $paged,
				'expiry_within' => $expiry_within_pre,
				'enrolled_from' => $enrolled_from_pre,
				'enrolled_to'   => $enrolled_to_pre,
			)
		);

		$students    = $result['items'];
		$total       = $result['total'];
		$total_pages = (int) ceil( $total / $per_page );
		$base_url    = admin_url( 'admin.php?page=cael-settings&tab=students' );
		?>

		<div class="ldce-students-header">
			<h2><?php esc_html_e( 'Enrolled Students', 'course-access-extender-for-learndash' ); ?></h2>
			<p class="description"><?php esc_html_e( 'View all enrolled students, their access expiry, enrollment date, and adjust expiry if needed. Click History to see a student\'s purchase history.', 'course-access-extender-for-learndash' ); ?></p>
		</div>

		<!-- Filters -->
		<?php
		// Reuse the vars already read above to avoid duplication.
		$expiry_within = $expiry_within_pre;
		$enrolled_from = $enrolled_from_pre;
		$enrolled_to   = $enrolled_to_pre;
		?>
		<form method="get" class="ldce-filters">
			<input type="hidden" name="page" value="cael-settings">
			<input type="hidden" name="tab" value="students">

			<!-- Row 1: Course, Status, Search -->
			<div class="ldce-filter-row">
				<select name="ldce_course">
					<option value="0"><?php esc_html_e( 'All Courses', 'course-access-extender-for-learndash' ); ?></option>
					<?php foreach ( $courses as $cid => $ctitle ) : ?>
						<option value="<?php echo esc_attr( $cid ); ?>" <?php selected( $course_filter, $cid ); ?>>
							<?php echo esc_html( $ctitle ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="ldce_status">
					<option value="all"     <?php selected( $status_filter, 'all' ); ?>><?php esc_html_e( 'All Statuses', 'course-access-extender-for-learndash' ); ?></option>
					<option value="active"  <?php selected( $status_filter, 'active' ); ?>><?php esc_html_e( 'Active', 'course-access-extender-for-learndash' ); ?></option>
					<option value="expired" <?php selected( $status_filter, 'expired' ); ?>><?php esc_html_e( 'Expired', 'course-access-extender-for-learndash' ); ?></option>
				</select>

				<input type="text" name="ldce_search" value="<?php echo esc_attr( $search ); ?>"
				       placeholder="<?php esc_attr_e( 'Search name or email...', 'course-access-extender-for-learndash' ); ?>"
				       class="ldce-search-input">
			</div>

			<!-- Row 2: Expiry-within shortcut + Enrollment date range -->
			<div class="ldce-filter-row ldce-filter-row--secondary">
				<label class="ldce-filter-label">
					<?php esc_html_e( 'Expiring within', 'course-access-extender-for-learndash' ); ?>
					<select name="ldce_expiry_within">
						<option value="0"  <?php selected( $expiry_within, 0 ); ?>><?php esc_html_e( 'Any time', 'course-access-extender-for-learndash' ); ?></option>
						<option value="7"  <?php selected( $expiry_within, 7 ); ?>><?php esc_html_e( '7 days', 'course-access-extender-for-learndash' ); ?></option>
						<option value="14" <?php selected( $expiry_within, 14 ); ?>><?php esc_html_e( '14 days', 'course-access-extender-for-learndash' ); ?></option>
						<option value="30" <?php selected( $expiry_within, 30 ); ?>><?php esc_html_e( '30 days', 'course-access-extender-for-learndash' ); ?></option>
					</select>
				</label>

				<span class="ldce-filter-sep"><?php esc_html_e( 'Enrolled from', 'course-access-extender-for-learndash' ); ?></span>
				<input type="date" name="ldce_enrolled_from" value="<?php echo esc_attr( $enrolled_from ); ?>" class="ldce-date-filter">
				<span class="ldce-filter-sep"><?php esc_html_e( 'to', 'course-access-extender-for-learndash' ); ?></span>
				<input type="date" name="ldce_enrolled_to" value="<?php echo esc_attr( $enrolled_to ); ?>" class="ldce-date-filter">

				<?php submit_button( __( 'Filter', 'course-access-extender-for-learndash' ), 'secondary', '', false ); ?>

				<?php
				$has_filters = $search || $course_filter || 'all' !== $status_filter || $expiry_within || $enrolled_from || $enrolled_to;
				if ( $has_filters ) : ?>
					<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Reset', 'course-access-extender-for-learndash' ); ?></a>
				<?php endif; ?>
			</div>
		</form>


		<!-- Results summary -->
		<p class="ldce-results-count">
			<?php
			printf(
				/* translators: %d number of students */
				esc_html( _n( '%d student found', '%d students found', $total, 'course-access-extender-for-learndash' ) ),
				absint( $total )
			);
			?>
		</p>

		<?php if ( empty( $students ) ) : ?>
			<div class="ldce-empty-state">
				<p><?php esc_html_e( 'No students match your current filters.', 'course-access-extender-for-learndash' ); ?></p>
			</div>
		<?php else : ?>

			<!-- Sort helpers -->
			<?php
			$sort_url = function( $col ) use ( $base_url, $course_filter, $status_filter, $search, $orderby, $order ) {
				$new_order = ( $orderby === $col && 'ASC' === $order ) ? 'desc' : 'asc';
				return add_query_arg( array( 'ldce_course' => $course_filter, 'ldce_status' => $status_filter, 'ldce_search' => $search, 'ldce_orderby' => $col, 'ldce_order' => $new_order ), $base_url );
			};
			$sort_arrow = function( $col ) use ( $orderby, $order ) {
				if ( $orderby !== $col ) return '';
				return 'ASC' === $order ? ' ▲' : ' ▼';
			};
			?>

			<table class="widefat striped ldce-students-table">
				<thead>
					<tr>
						<th><a href="<?php echo esc_url( $sort_url( 'name' ) ); ?>"><?php esc_html_e( 'Student', 'course-access-extender-for-learndash' ); ?><?php echo esc_html( $sort_arrow( 'name' ) ); ?></a></th>
						<th><?php esc_html_e( 'Email', 'course-access-extender-for-learndash' ); ?></th>
						<th><?php esc_html_e( 'Course', 'course-access-extender-for-learndash' ); ?></th>
						<th><?php esc_html_e( 'Enrolled Since', 'course-access-extender-for-learndash' ); ?></th>
						<th><a href="<?php echo esc_url( $sort_url( 'expiry' ) ); ?>"><?php esc_html_e( 'Access Expiry', 'course-access-extender-for-learndash' ); ?><?php echo esc_html( $sort_arrow( 'expiry' ) ); ?></a></th>
						<th><?php esc_html_e( 'Days Left', 'course-access-extender-for-learndash' ); ?></th>
						<th><?php esc_html_e( 'Status', 'course-access-extender-for-learndash' ); ?></th>
						<th><?php esc_html_e( 'Adjust Expiry', 'course-access-extender-for-learndash' ); ?></th>
						<th><?php esc_html_e( 'History', 'course-access-extender-for-learndash' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $students as $student ) : ?>
						<tr id="ldce-student-row-<?php echo esc_attr( $student['user_id'] . '_' . $student['course_id'] ); ?>">
							<!-- Name -->
							<td>
								<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $student['user_id'] ) ); ?>" target="_blank">
									<?php echo esc_html( $student['display_name'] ); ?>
								</a>
							</td>

							<!-- Email -->
							<td><?php echo esc_html( $student['email'] ); ?></td>

							<!-- Course -->
							<td>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $student['course_id'] . '&action=edit' ) ); ?>" target="_blank">
									<?php echo esc_html( $student['course_title'] ); ?>
								</a>
							</td>

							<!-- Enrolled since -->
							<td class="ldce-enrolled-since-cell">
								<?php
								// Show original enrollment date. We check our saved meta first
								// (_ldce_enrolled_since_) which preserves the real enrollment
								// date even after admin expiry adjustments shift access_from.
								$enrolled_since_meta = get_user_meta( $student['user_id'], CAEL_Access_Extender::ENROLLED_SINCE_META_PREFIX . $student['course_id'], true );
								if ( $enrolled_since_meta ) {
									echo esc_html( wp_date( 'M j, Y', absint( $enrolled_since_meta ) ) );
								} else {
									// Fall back to the raw access_from value from LD.
									$raw_access_from = get_user_meta( $student['user_id'], 'course_' . $student['course_id'] . '_access_from', true );
									echo $raw_access_from ? esc_html( wp_date( 'M j, Y', absint( $raw_access_from ) ) ) : '—';
								}
								?>
							</td>

							<!-- Expiry date -->
							<td class="ldce-expiry-cell"
							    data-expiry="<?php echo esc_attr( $student['expiry_date'] ); ?>"
							    id="ldce-expiry-display-<?php echo esc_attr( $student['user_id'] . '_' . $student['course_id'] ); ?>">
								<?php echo esc_html( $student['expiry_f'] ); ?>
							</td>

							<!-- Days left -->
							<td>
								<?php if ( null === $student['days_left'] ) : ?>
									<span class="ldce-days-none">—</span>
								<?php elseif ( $student['is_active'] ) : ?>
									<span class="ldce-days-active"><?php echo absint( $student['days_left'] ); ?></span>
								<?php else : ?>
									<span class="ldce-days-expired"><?php esc_html_e( 'Expired', 'course-access-extender-for-learndash' ); ?></span>
								<?php endif; ?>
							</td>

							<!-- Status badge -->
							<td>
								<?php if ( $student['is_active'] ) : ?>
									<span class="ldce-badge ldce-badge--active"><?php esc_html_e( 'Active', 'course-access-extender-for-learndash' ); ?></span>
								<?php else : ?>
									<span class="ldce-badge ldce-badge--expired"><?php esc_html_e( 'Expired', 'course-access-extender-for-learndash' ); ?></span>
								<?php endif; ?>
							</td>

							<!-- Adjust expiry inline -->
							<td class="ldce-adjust-cell">
								<div class="ldce-adjust-form">
									<input
										type="date"
										class="ldce-date-picker"
										value="<?php echo esc_attr( $student['expiry_date'] ); ?>"
										min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"
										data-user-id="<?php echo esc_attr( $student['user_id'] ); ?>"
										data-course-id="<?php echo esc_attr( $student['course_id'] ); ?>"
									>
									<button
										type="button"
										class="button button-small ldce-save-expiry"
										data-user-id="<?php echo esc_attr( $student['user_id'] ); ?>"
										data-course-id="<?php echo esc_attr( $student['course_id'] ); ?>">
										<?php esc_html_e( 'Save', 'course-access-extender-for-learndash' ); ?>
									</button>
									<span class="ldce-save-feedback" id="ldce-feedback-<?php echo esc_attr( $student['user_id'] . '_' . $student['course_id'] ); ?>"></span>
								</div>
							</td>

							<!-- Purchase history trigger -->
							<td class="ldce-history-cell">
								<button
									type="button"
									class="button button-small ldce-view-history"
									data-user-id="<?php echo esc_attr( $student['user_id'] ); ?>"
									data-course-id="<?php echo esc_attr( $student['course_id'] ); ?>"
									data-student-name="<?php echo esc_attr( $student['display_name'] ); ?>"
									title="<?php esc_attr_e( 'View Purchase History', 'course-access-extender-for-learndash' ); ?>">
									📋 <?php esc_html_e( 'History', 'course-access-extender-for-learndash' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post( paginate_links( array(
							'base'      => add_query_arg( array( 'ldce_course' => $course_filter, 'ldce_status' => $status_filter, 'ldce_search' => urlencode( $search ), 'ldce_orderby' => $orderby, 'ldce_order' => strtolower( $order ), 'ldce_paged' => '%#%' ), $base_url ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $total_pages,
							'prev_text' => __( '&laquo; Previous', 'course-access-extender-for-learndash' ),
							'next_text' => __( 'Next &raquo;', 'course-access-extender-for-learndash' ),
						) ) );
						?>
					</div>
				</div>
			<?php endif; ?>

		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Purchase history modal markup (rendered once, shown via JS)
	// -------------------------------------------------------------------------

	/** Render the purchase history modal shell. */
	private function render_purchase_history_modal() {
		?>
		<div id="ldce-history-modal" class="ldce-modal ldce-modal--hidden" role="dialog" aria-modal="true" aria-labelledby="ldce-modal-title" aria-hidden="true">
			<div class="ldce-modal-overlay"></div>
			<div class="ldce-modal-box">
				<div class="ldce-modal-header">
					<h2 id="ldce-modal-title"><?php esc_html_e( 'Purchase History', 'course-access-extender-for-learndash' ); ?></h2>
					<button type="button" class="ldce-modal-close" aria-label="<?php esc_attr_e( 'Close', 'course-access-extender-for-learndash' ); ?>">&times;</button>
				</div>
				<div class="ldce-modal-body">
					<div id="ldce-history-content">
						<p class="ldce-loading"><?php esc_html_e( 'Loading…', 'course-access-extender-for-learndash' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX: purchase history
	// -------------------------------------------------------------------------

	/** AJAX handler — returns the purchase history HTML for a student. */
	public function ajax_get_purchase_history() {
		check_ajax_referer( 'cael_purchase_history_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'course-access-extender-for-learndash' ) ) );
			return;
		}

		$user_id   = absint( isset( $_POST['user_id'] ) ? $_POST['user_id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$course_id = absint( isset( $_POST['course_id'] ) ? $_POST['course_id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid user.', 'course-access-extender-for-learndash' ) ) );
			return;
		}

		$history = CAEL_Students::get_student_purchase_history( $user_id, $course_id );

		if ( empty( $history ) ) {
			wp_send_json_success( array( 'html' => '<p class="ldce-empty">' . esc_html__( 'No course-related purchases found for this student.', 'course-access-extender-for-learndash' ) . '</p>' ) );
			return;
		}

		ob_start();
		?>
		<table class="widefat striped ldce-history-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order', 'course-access-extender-for-learndash' ); ?></th>
					<th><?php esc_html_e( 'Date', 'course-access-extender-for-learndash' ); ?></th>
					<th><?php esc_html_e( 'Product', 'course-access-extender-for-learndash' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'course-access-extender-for-learndash' ); ?></th>
					<th><?php esc_html_e( 'Total', 'course-access-extender-for-learndash' ); ?></th>
					<th><?php esc_html_e( 'Status', 'course-access-extender-for-learndash' ); ?></th>
					<th><?php esc_html_e( 'Type', 'course-access-extender-for-learndash' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history as $row ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $row['order_url'] ); ?>" target="_blank">
								#<?php echo absint( $row['order_id'] ); ?>
							</a>
						</td>
						<td><?php echo esc_html( $row['date'] ); ?></td>
						<td><?php echo esc_html( $row['product_name'] ); ?></td>
						<td><?php echo absint( $row['qty'] ); ?></td>
						<td><?php echo wp_kses_post( $row['total'] ); ?></td>
						<td>
							<span class="ldce-badge ldce-badge--order-<?php echo esc_attr( $row['status'] ); ?>">
								<?php echo esc_html( $row['status_label'] ); ?>
							</span>
						</td>
						<td>
							<?php if ( $row['is_extension'] ) : ?>
								<span class="ldce-badge ldce-badge--extended"><?php esc_html_e( 'Extension', 'course-access-extender-for-learndash' ); ?></span>
							<?php else : ?>
								<span class="ldce-badge ldce-badge--granted"><?php esc_html_e( 'Main Course', 'course-access-extender-for-learndash' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	// -------------------------------------------------------------------------
	// Tab: Activity Log
	// -------------------------------------------------------------------------

	/**
	 * Render the Activity Log tab with full filter and search bar.
	 */
	private function render_logs_tab() {
		// phpcs:disable WordPress.Security.NonceVerification
		$paged       = isset( $_GET['log_page'] ) ? absint( $_GET['log_page'] ) : 1;
		$log_course  = isset( $_GET['log_course'] ) ? absint( $_GET['log_course'] ) : 0;
		$log_action  = isset( $_GET['log_action'] ) ? sanitize_key( $_GET['log_action'] ) : '';
		$log_search  = isset( $_GET['log_search'] ) ? sanitize_text_field( wp_unslash( $_GET['log_search'] ) ) : '';
		$log_from    = isset( $_GET['log_from'] ) ? sanitize_text_field( wp_unslash( $_GET['log_from'] ) ) : '';
		$log_to      = isset( $_GET['log_to'] ) ? sanitize_text_field( wp_unslash( $_GET['log_to'] ) ) : '';
		// phpcs:enable

		$per_page    = 20;
		$base_url    = admin_url( 'admin.php?page=cael-settings&tab=logs' );
		$courses     = CAEL_Students::get_courses_list();

		// Known action types for the action filter dropdown.
		$action_types = array(
			'extended'        => __( 'Extended (purchase)', 'course-access-extender-for-learndash' ),
			'granted'         => __( 'Granted (purchase)', 'course-access-extender-for-learndash' ),
			'admin_adjusted'  => __( 'Admin Adjusted', 'course-access-extender-for-learndash' ),
			'profile_updated' => __( 'Profile Updated', 'course-access-extender-for-learndash' ),
			'skipped'         => __( 'Skipped', 'course-access-extender-for-learndash' ),
		);

		// Handle clear logs POST.
		if (
			isset( $_POST['cael_clear_logs'], $_POST['cael_clear_logs_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cael_clear_logs_nonce'] ) ), 'cael_clear_logs_action' ) &&
			current_user_can( 'manage_woocommerce' )
		) {
			global $wpdb;
			$table = $wpdb->prefix . CAEL_Logger::TABLE_NAME;
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Activity log cleared.', 'course-access-extender-for-learndash' ) . '</p></div>';
			$log_course = $log_action = $log_search = $log_from = $log_to = '';
		}

		$result      = CAEL_Logger::get_logs(
			array(
				'course_id'  => $log_course,
				'action'     => $log_action,
				'search'     => $log_search,
				'date_from'  => $log_from,
				'date_to'    => $log_to,
				'per_page'   => $per_page,
				'paged'      => $paged,
			)
		);
		$items       = $result['items'];
		$total       = $result['total'];
		$total_pages = (int) ceil( $total / $per_page );
		$has_filters = $log_course || $log_action || $log_search || $log_from || $log_to;
		?>

		<!-- Log header -->
		<div class="ldce-students-header">
			<h2><?php esc_html_e( 'Activity Log', 'course-access-extender-for-learndash' ); ?></h2>
			<p class="description"><?php esc_html_e( 'A full audit trail of every access grant, extension, admin adjustment, and profile change. Use the filters below to narrow results.', 'course-access-extender-for-learndash' ); ?></p>
		</div>

		<!-- Filter form -->
		<form method="get" class="ldce-filters">
			<input type="hidden" name="page" value="cael-settings">
			<input type="hidden" name="tab" value="logs">

			<!-- Row 1: Course, Action type, Search -->
			<div class="ldce-filter-row">
				<select name="log_course">
					<option value="0"><?php esc_html_e( 'All Courses', 'course-access-extender-for-learndash' ); ?></option>
					<?php foreach ( $courses as $cid => $ctitle ) : ?>
						<option value="<?php echo esc_attr( $cid ); ?>" <?php selected( $log_course, $cid ); ?>>
							<?php echo esc_html( $ctitle ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="log_action">
					<option value=""><?php esc_html_e( 'All Actions', 'course-access-extender-for-learndash' ); ?></option>
					<?php foreach ( $action_types as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $log_action, $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<input type="text" name="log_search" value="<?php echo esc_attr( $log_search ); ?>"
				       placeholder="<?php esc_attr_e( 'Search log messages...', 'course-access-extender-for-learndash' ); ?>"
				       class="ldce-search-input">
			</div>

			<!-- Row 2: Date range + submit -->
			<div class="ldce-filter-row ldce-filter-row--secondary">
				<span class="ldce-filter-sep"><?php esc_html_e( 'Date from', 'course-access-extender-for-learndash' ); ?></span>
				<input type="date" name="log_from" value="<?php echo esc_attr( $log_from ); ?>" class="ldce-date-filter">
				<span class="ldce-filter-sep"><?php esc_html_e( 'to', 'course-access-extender-for-learndash' ); ?></span>
				<input type="date" name="log_to" value="<?php echo esc_attr( $log_to ); ?>" class="ldce-date-filter">

				<?php submit_button( __( 'Filter', 'course-access-extender-for-learndash' ), 'secondary', '', false ); ?>

				<?php if ( $has_filters ) : ?>
					<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Reset', 'course-access-extender-for-learndash' ); ?></a>
				<?php endif; ?>
			</div>
		</form>

		<!-- Summary + clear -->
		<div class="ldce-log-actions">
			<form method="post">
				<?php wp_nonce_field( 'cael_clear_logs_action', 'cael_clear_logs_nonce' ); ?>
				<button type="submit" name="cael_clear_logs" class="button button-secondary ldce-confirm-clear"
					data-confirm="<?php esc_attr_e( 'Clear ALL log entries? This cannot be undone.', 'course-access-extender-for-learndash' ); ?>">
					<?php esc_html_e( 'Clear All Logs', 'course-access-extender-for-learndash' ); ?>
				</button>
			</form>
			<p class="ldce-log-count">
				<?php
				if ( $has_filters ) {
					printf(
						/* translators: 1: filtered count */
						esc_html__( '%d entries match your filters.', 'course-access-extender-for-learndash' ),
						absint( $total )
					);
				} else {
					printf(
						/* translators: %d total */
						esc_html__( '%d total entries', 'course-access-extender-for-learndash' ),
						absint( $total )
					);
				}
				?>
			</p>
		</div>

		<?php if ( empty( $items ) ) : ?>
			<div class="ldce-empty-state">
				<p><?php echo $has_filters ? esc_html__( 'No log entries match your filters.', 'course-access-extender-for-learndash' ) : esc_html__( 'No activity logged yet.', 'course-access-extender-for-learndash' ); ?></p>
			</div>
		<?php else : ?>
			<table class="widefat striped ldce-log-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date & Time', 'course-access-extender-for-learndash' ); ?></th>
						<th><?php esc_html_e( 'User', 'course-access-extender-for-learndash' ); ?></th>
						<th><?php esc_html_e( 'Order', 'course-access-extender-for-learndash' ); ?></th>
						<th><?php esc_html_e( 'Course', 'course-access-extender-for-learndash' ); ?></th>
						<th><?php esc_html_e( 'Action', 'course-access-extender-for-learndash' ); ?></th>
						<th><?php esc_html_e( 'Message', 'course-access-extender-for-learndash' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $row ) : ?>
						<tr>
							<td class="ldce-log-date"><?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $row->created_at ) ) ); ?></td>
							<td>
								<?php
								if ( $row->user_id ) {
									$u = get_userdata( $row->user_id );
									if ( $u ) {
										printf(
											'<a href="%s">%s</a><br><small class="ldce-log-email">%s</small>',
											esc_url( admin_url( 'user-edit.php?user_id=' . absint( $row->user_id ) ) ),
											esc_html( $u->display_name ),
											esc_html( $u->user_email )
										);
									} else {
										echo esc_html( '#' . absint( $row->user_id ) );
									}
								} else {
									esc_html_e( 'Guest', 'course-access-extender-for-learndash' );
								}
								?>
							</td>
							<td>
								<?php if ( $row->order_id ) : ?>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $row->order_id ) . '&action=edit' ) ); ?>" target="_blank">
										#<?php echo absint( $row->order_id ); ?>
									</a>
								<?php else : ?>
									<span class="ldce-log-na">&mdash;</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $row->course_id ) : ?>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $row->course_id ) . '&action=edit' ) ); ?>" target="_blank">
										<?php echo esc_html( get_the_title( $row->course_id ) ); ?>
									</a>
								<?php else : ?>
									<span class="ldce-log-na">&mdash;</span>
								<?php endif; ?>
							</td>
							<td>
								<span class="ldce-badge ldce-badge--<?php echo esc_attr( $row->action ); ?>">
									<?php echo esc_html( str_replace( '_', ' ', $row->action ) ); ?>
								</span>
							</td>
							<td class="ldce-log-msg"><?php echo esc_html( $row->message ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						$paginate_args = array(
							'base'      => add_query_arg(
								array(
									'log_course' => $log_course,
									'log_action' => $log_action,
									'log_search' => rawurlencode( $log_search ),
									'log_from'   => $log_from,
									'log_to'     => $log_to,
									'log_page'   => '%#%',
								),
								$base_url
							),
							'format'    => '',
							'current'   => $paged,
							'total'     => $total_pages,
							'prev_text' => __( '&laquo; Previous', 'course-access-extender-for-learndash' ),
							'next_text' => __( 'Next &raquo;', 'course-access-extender-for-learndash' ),
						);
						echo wp_kses_post( paginate_links( $paginate_args ) );
						?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Misc
	// -------------------------------------------------------------------------

	/**
	 * Add Settings link to the plugins list table.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=cael-settings' ) ), esc_html__( 'Settings', 'course-access-extender-for-learndash' ) ) );
		return $links;
	}
}
