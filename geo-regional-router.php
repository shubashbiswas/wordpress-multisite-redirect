<?php
/**
 * Plugin Name: Geo Regional Router
 * Plugin URI: https://example.com/plugins/geo-regional-router
 * Description: Production-ready WordPress Multisite country-based URL routing engine for multi-regional WordPress installations.
 * Version: 1.0.4
 * Author: Antigravity
 * Author URI: https://example.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: geo-regional-router
 * Network: true
 * Requires at least: 6.0
 * Requires PHP: 8.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'GRR_VERSION', '1.0.4' );
define( 'GRR_PLUGIN_FILE', __FILE__ );
define( 'GRR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GRR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GRR_MIN_PHP_VERSION', '8.3' );

/**
 * Autoloader for GRR classes.
 */
spl_autoload_register( function ( string $class_name ): void {
	$prefix   = 'GRR\\';
	$base_dir = GRR_PLUGIN_DIR . 'includes/';

	$len = strlen( $prefix );
	if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
		return;
	}

	$relative_class = substr( $class_name, $len );
	$file           = $base_dir . 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Activation check and hook.
 */
register_activation_hook( __FILE__, function (): void {
	if ( ! is_multisite() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'Geo Regional Router requires WordPress Multisite to be enabled.', 'geo-regional-router' ),
			esc_html__( 'Plugin Activation Error', 'geo-regional-router' ),
			array( 'back_link' => true )
		);
	}

	if ( version_compare( PHP_VERSION, GRR_MIN_PHP_VERSION, '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			sprintf(
				/* translators: %s: Minimum PHP version requirement */
				esc_html__( 'Geo Regional Router requires PHP version %s or higher.', 'geo-regional-router' ),
				GRR_MIN_PHP_VERSION
			),
			esc_html__( 'Plugin Activation Error', 'geo-regional-router' ),
			array( 'back_link' => true )
		);
	}

	// Set default settings if not already defined
	if ( false === get_site_option( 'grr_options' ) ) {
		$default_options = array(
			'enabled'                      => 0,
			'routing_mode'                 => 'prompt',
			'redirect_status'              => 302,
			'prompt_style'                 => 'card',
			'prompt_delay'                 => 1.5,
			'prompt_auto_hide'             => 7,
			'auto_redirect_countdown'      => 0,
			'enable_footer_switcher'       => 0,
			'footer_switcher_style'        => 'inline',
			'footer_switcher_position'     => 'center',
			'site_global'                  => get_main_site_id(),
			'site_bd'                      => 0,
			'site_in'                      => 0,
			'skip_logged_in_admins'        => 1,
			'skip_logged_in_users'         => 0,
			'skip_bots'                    => 1,
			'skip_rest'                    => 1,
			'skip_ajax'                    => 1,
			'skip_cron'                    => 1,
			'skip_admin_urls'              => 1,
			'skip_xmlrpc'                  => 1,
			'skip_feeds'                   => 0,
			'skip_sitemaps'                => 1,
			'skip_previews'                => 1,
			'cookie_persistence'           => 'disabled',
			'country_source_cf'            => 1,
			'country_source_header'        => 0,
			'country_custom_header_name'   => 'HTTP_X_GEOIP_COUNTRY',
			'trusted_proxies'              => '',
			'maxmind_db_path'              => '',
			'debug_mode'                   => 0,
			'delete_data_on_uninstall'     => 0,
		);
		update_site_option( 'grr_options', $default_options );
	}
} );

/**
 * Bootstrap the plugin once all plugins are loaded.
 */
add_action( 'plugins_loaded', function (): void {
	if ( is_multisite() && version_compare( PHP_VERSION, GRR_MIN_PHP_VERSION, '>=' ) ) {
		\GRR\Plugin::get_instance();
	}
} );
