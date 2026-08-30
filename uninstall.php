<?php
/**
 * Uninstall Handler for Geo Regional Router.
 *
 * Safe cleanup script executed only when plugin is deleted via WordPress Admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = get_site_option( 'grr_options', array() );

// Only delete plugin options if explicitly requested by administrator
if ( ! empty( $options['delete_data_on_uninstall'] ) ) {
	delete_site_option( 'grr_options' );
}

// Clean up debug log file if present
$upload_dir = wp_upload_dir();
$log_file   = trailingslashit( $upload_dir['basedir'] ) . 'geo-regional-router-debug.log';
if ( file_exists( $log_file ) ) {
	@unlink( $log_file );
}
