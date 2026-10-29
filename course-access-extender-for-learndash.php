<?php
/**
 * Plugin Name:       Course Access Extender for LearnDash
 * Plugin URI:        https://skfreelancers.com/
 * Description:       Extend course access functionality for LearnDash LMS. This is an independent plugin and is not affiliated with or endorsed by LearnDash.
 * Version:           1.1.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            SKFreelancers
 * Author URI:        https://skfreelancers.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       course-access-extender-for-learndash
 * Domain Path:       /languages
 *
 * @package SKFreelancers\LearnDashCourseExtension
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CAEL_VERSION', '1.1.4' );
define( 'CAEL_PLUGIN_FILE', __FILE__ );
define( 'CAEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CAEL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CAEL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check required plugins are active.
 *
 * @return array List of missing plugin names.
 */
function cael_check_dependencies() {
	$missing = array();
	if ( ! class_exists( 'WooCommerce' ) ) {
		$missing[] = 'WooCommerce';
	}
	if ( ! function_exists( 'learndash_get_course_id' ) && ! defined( 'LEARNDASH_VERSION' ) ) {
		$missing[] = 'LearnDash LMS';
	}
	return $missing;
}

/**
 * Show admin notice when dependencies are missing.
 */
function cael_dependency_notice() {
	$missing = cael_check_dependencies();
	if ( ! empty( $missing ) ) {
		$message = sprintf(
			/* translators: %s: comma-separated list of missing plugins */
			__( '<strong>Course Access Extender for LearnDash</strong> requires: %s.', 'course-access-extender-for-learndash' ),
			implode( ', ', array_map( 'esc_html', $missing ) )
		);
		printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses( $message, array( 'strong' => array() ) ) );
	}
}

/**
 * Boot the plugin after all plugins are loaded.
 */
function cael_init() {
	$missing = cael_check_dependencies();
	if ( ! empty( $missing ) ) {
		add_action( 'admin_notices', 'cael_dependency_notice' );
		return;
	}

	require_once CAEL_PLUGIN_DIR . 'includes/class-cael-logger.php';
	require_once CAEL_PLUGIN_DIR . 'includes/class-cael-access-extender.php';
	require_once CAEL_PLUGIN_DIR . 'includes/class-cael-students.php';
	require_once CAEL_PLUGIN_DIR . 'admin/class-cael-product-settings.php';
	require_once CAEL_PLUGIN_DIR . 'admin/class-cael-admin.php';

	CAEL_Access_Extender::get_instance();
	CAEL_Admin::get_instance();
	CAEL_Product_Settings::get_instance();
}
add_action( 'plugins_loaded', 'cael_init' );

/**
 * Activation: create DB table, set defaults, schedule cron.
 */
function cael_activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-cael-logger.php';
	CAEL_Logger::create_table();

	$defaults = array(
		'cael_extension_days'           => 90,
		'cael_enable_logging'           => 1,
		'cael_log_retention_days'       => 30,
		'cael_delete_data_on_uninstall' => 0,  // Default OFF — preserve data on updates.
	);
	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value );
		}
	}

	if ( ! wp_next_scheduled( 'cael_cleanup_logs' ) ) {
		wp_schedule_event( time(), 'daily', 'cael_cleanup_logs' );
	}
}
register_activation_hook( CAEL_PLUGIN_FILE, 'cael_activate' );

/**
 * Deactivation: remove cron.
 */
function cael_deactivate() {
	wp_clear_scheduled_hook( 'cael_cleanup_logs' );
}
register_deactivation_hook( CAEL_PLUGIN_FILE, 'cael_deactivate' );
