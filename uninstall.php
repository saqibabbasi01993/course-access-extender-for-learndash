<?php
/**
 * Uninstall Handler
 *
 * This file runs ONLY when the plugin is deleted via Plugins > Delete.
 * It does NOT run on deactivation or when uploading a plugin update.
 *
 * IMPORTANT — Data preservation by default:
 * By default this plugin does NOT delete log data or the database table
 * on uninstall. This prevents accidental data loss when updating the
 * plugin by uploading a new zip (which can trigger uninstall on some
 * WordPress configurations).
 *
 * To enable full data removal on delete, go to:
 *   WooCommerce → Course Access Extender → Settings
 * and enable "Delete all data on uninstall" before deleting the plugin.
 *
 * @package SKFreelancers\LearnDashCourseExtension
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Only wipe data if the admin has explicitly opted in.
$cael_delete_data = get_option( 'cael_delete_data_on_uninstall', false );

if ( $cael_delete_data ) {
	// Remove all plugin options.
	$cael_options = array(
		'cael_extension_days',
		'cael_enable_logging',
		'cael_log_retention_days',
		'cael_delete_data_on_uninstall',
	);
	foreach ( $cael_options as $cael_option ) {
		delete_option( $cael_option );
	}

	// Drop the log table.
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-cael-logger.php';
	CAEL_Logger::drop_table();
}

// Always clear the cron event — it has no data value.
wp_clear_scheduled_hook( 'cael_cleanup_logs' );
