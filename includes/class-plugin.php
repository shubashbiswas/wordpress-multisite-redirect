<?php
namespace GRR;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 * Main orchestrator for Geo Regional Router.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Country Detector instance.
	 *
	 * @var Country_Detector
	 */
	private Country_Detector $country_detector;

	/**
	 * Router instance.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Diagnostics instance.
	 *
	 * @var Diagnostics
	 */
	private Diagnostics $diagnostics;

	/**
	 * Main instance getter.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->logger           = new Logger();
		$this->country_detector = new Country_Detector( $this->logger );
		$this->router           = new Router( $this->country_detector, $this->logger );
		$this->settings         = new Settings();
		$this->diagnostics      = new Diagnostics( $this->country_detector, $this->router );

		$this->init_hooks();
	}

	/**
	 * Initialize plugin hooks.
	 */
	private function init_hooks(): void {
		add_action( 'network_admin_notices', array( $this, 'check_network_configuration_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Register settings and admin menus
		$this->settings->init();
		$this->diagnostics->init();

		// Register router engine on template_redirect hook
		$this->router->init();
	}

	/**
	 * Display warning notice if plugin is enabled but site mapping is incomplete.
	 */
	public function check_network_configuration_notice(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$options = get_site_option( 'grr_options', array() );
		$enabled = ! empty( $options['enabled'] );

		if ( ! $enabled ) {
			return;
		}

		$site_global = (int) ( $options['site_global'] ?? 0 );
		$site_bd     = (int) ( $options['site_bd'] ?? 0 );
		$site_in     = (int) ( $options['site_in'] ?? 0 );

		if ( ! $site_global || ! $site_bd || ! $site_in ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Geo Regional Router is enabled, but site mappings (Global, Bangladesh, India) are incomplete. Please complete configuration under Network Admin > Settings > Geo Regional Router.', 'geo-regional-router' );
			echo '</p></div>';
		}
	}

	/**
	 * Enqueue admin scripts & styles on plugin network settings page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'geo-regional-router' ) ) {
			return;
		}

		wp_enqueue_style(
			'grr-admin-css',
			GRR_PLUGIN_URL . 'assets/admin.css',
			array(),
			GRR_VERSION
		);

		wp_enqueue_script(
			'grr-admin-js',
			GRR_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			GRR_VERSION,
			true
		);

		wp_localize_script(
			'grr-admin-js',
			'grrAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'grr_diagnostics_nonce' ),
			)
		);
	}

	/**
	 * Get Logger.
	 *
	 * @return Logger
	 */
	public function get_logger(): Logger {
		return $this->logger;
	}

	/**
	 * Get Country Detector.
	 *
	 * @return Country_Detector
	 */
	public function get_country_detector(): Country_Detector {
		return $this->country_detector;
	}

	/**
	 * Get Router.
	 *
	 * @return Router
	 */
	public function get_router(): Router {
		return $this->router;
	}
}
