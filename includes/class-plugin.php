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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_floating_widget' ) );
		add_action( 'wp_footer', array( $this, 'render_footer_switcher' ), 20 );

		// Admin Bar Quick Switcher
		add_action( 'admin_bar_menu', array( $this, 'register_admin_bar_switcher' ), 100 );

		// Shortcode for Frontend Regional Switcher
		add_shortcode( 'geo_regional_switcher', array( $this, 'render_frontend_switcher_shortcode' ) );

		// Register settings and admin menus
		$this->settings->init();
		$this->diagnostics->init();

		// Register detector early hooks (cookie handler)
		$this->country_detector->init();

		// Register router engine on template_redirect hook
		$this->router->init();

		// Register dynamic Geo detection REST API endpoint
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Register weekly MaxMind cron task
		add_action( 'grr_weekly_maxmind_update', array( $this, 'run_maxmind_cron_update' ) );
		if ( ! wp_next_scheduled( 'grr_weekly_maxmind_update' ) ) {
			wp_schedule_event( time(), 'weekly', 'grr_weekly_maxmind_update' );
		}
	}

	/**
	 * Register Admin Bar Country Switcher dropdown for Network Admins.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar WP Admin Bar instance.
	 */
	public function register_admin_bar_switcher( \WP_Admin_Bar $wp_admin_bar ): void {
		$options = get_site_option( 'grr_options', array() );
		if ( empty( $options['enable_admin_bar'] ) || ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$detected  = $this->country_detector->detect_country();
		$code      = $detected['country'];
		$src       = $detected['source'];
		$is_custom = ( false !== strpos( $src, 'Test Mode' ) || false !== strpos( $src, 'Manual' ) );

		$title = sprintf( '🌐 Geo: %s%s', esc_html( $code ), $is_custom ? ' (' . esc_html( $code ) . ')' : '' );

		$wp_admin_bar->add_node(
			array(
				'id'    => 'grr_geo_bar',
				'title' => $title,
				'href'  => network_admin_url( 'settings.php?page=geo-regional-router&tab=diagnostics' ),
			)
		);

		$countries = array(
			'BD'     => '🇧🇩 Bangladesh (BD)',
			'IN'     => '🇮🇳 India (IN)',
			'US'     => '🇺🇸 United States (US)',
			'GB'     => '🇬🇧 United Kingdom (GB)',
		);

		$current_url = $this->router->get_current_url();

		foreach ( $countries as $cc => $label ) {
			$wp_admin_bar->add_node(
				array(
					'id'     => 'grr_geo_test_' . strtolower( $cc ),
					'parent' => 'grr_geo_bar',
					'title'  => $label,
					'href'   => add_query_arg( 'grr_test_country', $cc, $current_url ),
				)
			);
		}

		$wp_admin_bar->add_node(
			array(
				'id'     => 'grr_geo_test_reset',
				'parent' => 'grr_geo_bar',
				'title'  => '🔄 Reset Override',
				'href'   => add_query_arg( 'grr_test_country', 'reset', $current_url ),
			)
		);
	}

	/**
	 * Shortcode callback for [geo_regional_switcher].
	 *
	 * Attributes:
	 *   - style: "compact" (default micro dropdown), "minimal", "buttons", "dropdown", or "flags"
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_frontend_switcher_shortcode( array $atts = array() ): string {
		$options = get_site_option( 'grr_options', array() );
		if ( isset( $options['enable_frontend_switcher'] ) && ! $options['enable_frontend_switcher'] ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'style' => 'compact',
			),
			$atts,
			'geo_regional_switcher'
		);

		$site_global_id = (int) ( $options['site_global'] ?? 0 );
		$site_bd_id     = (int) ( $options['site_bd'] ?? 0 );
		$site_in_id     = (int) ( $options['site_in'] ?? 0 );

		if ( ! $site_global_id || ! $site_bd_id || ! $site_in_id ) {
			return '';
		}

		$global_url = get_site_url( $site_global_id );
		$bd_url     = get_site_url( $site_bd_id );
		$in_url     = get_site_url( $site_in_id );

		$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
		$parsed_uri  = wp_parse_url( $request_uri );
		$path        = $parsed_uri['path'] ?? '/';
		$query       = isset( $parsed_uri['query'] ) ? '?' . $parsed_uri['query'] : '';

		$clean_path = $this->router->extract_clean_path( $path, array( $global_url, $bd_url, $in_url ) );

		$url_global = add_query_arg( 'grr_set_country', 'GLOBAL', rtrim( $global_url, '/' ) . '/' . ltrim( $clean_path, '/' ) . $query );
		$url_bd     = add_query_arg( 'grr_set_country', 'BD', rtrim( $bd_url, '/' ) . '/' . ltrim( $clean_path, '/' ) . $query );
		$url_in     = add_query_arg( 'grr_set_country', 'IN', rtrim( $in_url, '/' ) . '/' . ltrim( $clean_path, '/' ) . $query );

		$current_blog_id = get_current_blog_id();
		$style           = strtolower( sanitize_key( $atts['style'] ) );

		ob_start();
		if ( 'minimal' === $style ) :
			?>
			<div class="grr-switcher-minimal">
				<a href="<?php echo esc_url( $url_global ); ?>" class="grr-switcher-btn <?php echo $current_blog_id === $site_global_id ? 'is-active' : ''; ?>">
					GLOBAL
				</a>
				<span class="grr-switcher-sep">/</span>
				<a href="<?php echo esc_url( $url_bd ); ?>" class="grr-switcher-btn <?php echo $current_blog_id === $site_bd_id ? 'is-active' : ''; ?>">
					BD
				</a>
				<span class="grr-switcher-sep">/</span>
				<a href="<?php echo esc_url( $url_in ); ?>" class="grr-switcher-btn <?php echo $current_blog_id === $site_in_id ? 'is-active' : ''; ?>">
					IN
				</a>
			</div>
		<?php elseif ( 'buttons' === $style ) : ?>
			<div class="grr-switcher-buttons">
				<a href="<?php echo esc_url( $url_global ); ?>" class="grr-switcher-btn <?php echo $current_blog_id === $site_global_id ? 'is-active' : ''; ?>">
					🌐 Global
				</a>
				<a href="<?php echo esc_url( $url_bd ); ?>" class="grr-switcher-btn <?php echo $current_blog_id === $site_bd_id ? 'is-active' : ''; ?>">
					🇧🇩 Bangladesh
				</a>
				<a href="<?php echo esc_url( $url_in ); ?>" class="grr-switcher-btn <?php echo $current_blog_id === $site_in_id ? 'is-active' : ''; ?>">
					🇮🇳 India
				</a>
			</div>
		<?php elseif ( 'inline' === $style ) : ?>
			<div class="grr-switcher-inline">
				<a href="<?php echo esc_url( $url_global ); ?>" class="grr-switcher-link <?php echo $current_blog_id === $site_global_id ? 'is-active' : ''; ?>">
					<span class="grr-flag">🌐</span> Global
				</a>
				<span class="grr-switcher-sep">|</span>
				<a href="<?php echo esc_url( $url_bd ); ?>" class="grr-switcher-link <?php echo $current_blog_id === $site_bd_id ? 'is-active' : ''; ?>">
					<span class="grr-flag">🇧🇩</span> Bangladesh
				</a>
				<span class="grr-switcher-sep">|</span>
				<a href="<?php echo esc_url( $url_in ); ?>" class="grr-switcher-link <?php echo $current_blog_id === $site_in_id ? 'is-active' : ''; ?>">
					<span class="grr-flag">🇮🇳</span> India
				</a>
			</div>
		<?php else : ?>
			<div class="grr-compact-switcher">
				<select onchange="if (this.value) window.location.href=this.value;" class="grr-compact-select" aria-label="Select Region">
					<option value="<?php echo esc_url( $url_global ); ?>" <?php selected( $current_blog_id, $site_global_id ); ?>>GLOBAL</option>
					<option value="<?php echo esc_url( $url_bd ); ?>" <?php selected( $current_blog_id, $site_bd_id ); ?>>BD</option>
					<option value="<?php echo esc_url( $url_in ); ?>" <?php selected( $current_blog_id, $site_in_id ); ?>>IN</option>
				</select>
			</div>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Output Floating Widget in footer if enabled in options.
	 */
	public function render_floating_widget(): void {
		$options = get_site_option( 'grr_options', array() );
		if ( ! empty( $options['enable_frontend_switcher'] ) && ! empty( $options['enable_floating_widget'] ) ) {
			echo '<div class="grr-floating-widget-wrapper">';
			echo $this->render_frontend_switcher_shortcode( array( 'style' => 'compact' ) );
			echo '</div>';
		}
	}

	/**
	 * Output dedicated Country Switcher in website footer (wp_footer).
	 */
	public function render_footer_switcher(): void {
		$options = get_site_option( 'grr_options', array() );
		if ( empty( $options['enable_footer_switcher'] ) ) {
			return;
		}

		$style    = sanitize_key( $options['footer_switcher_style'] ?? 'inline' );
		$position = sanitize_key( $options['footer_switcher_position'] ?? 'center' );

		echo '<div class="grr-footer-switcher-wrapper grr-align-' . esc_attr( $position ) . '">';
		echo '<div class="grr-footer-switcher-inner">';
		echo '<span class="grr-footer-switcher-label"><span class="grr-globe-icon">🌐</span> ' . esc_html__( 'Region: ', 'geo-regional-router' ) . '</span>';
		echo $this->render_frontend_switcher_shortcode( array( 'style' => $style ) );
		echo '</div></div>';
	}

	/**
	 * Enqueue frontend CSS for switcher and Geo-Prompt modal/banner.
	 */
	public function enqueue_frontend_assets(): void {
		$options      = get_site_option( 'grr_options', array() );
		$is_enabled   = ! empty( $options['enabled'] );
		$routing_mode = $options['routing_mode'] ?? 'prompt';

		if ( ! empty( $options['enable_frontend_switcher'] ) || ! empty( $options['enable_footer_switcher'] ) || ! empty( $options['enable_floating_widget'] ) ) {
			wp_enqueue_style( 'grr-frontend-css', GRR_PLUGIN_URL . 'assets/admin.css', array(), GRR_VERSION );
		}

		// Client-side Geo-Prompt (Full Page Cache compatible)
		if ( $is_enabled && 'prompt' === $routing_mode ) {
			wp_enqueue_style( 'grr-prompt-css', GRR_PLUGIN_URL . 'assets/grr-prompt.css', array(), GRR_VERSION );
			wp_enqueue_script( 'grr-prompt-js', GRR_PLUGIN_URL . 'assets/grr-prompt.js', array(), GRR_VERSION, true );

			wp_localize_script(
				'grr-prompt-js',
				'grrPromptConfig',
				array(
					'restUrl'       => esc_url_raw( rest_url( 'grr/v1/detect' ) ),
					'style'         => sanitize_key( $options['prompt_style'] ?? 'card' ),
					'delay'         => max( 0, (float) ( $options['prompt_delay'] ?? 1.5 ) ) * 1000,
					'autoHide'      => ! empty( $options['prompt_auto_hide'] ) ? (int) $options['prompt_auto_hide'] : 7,
					'countdown'     => max( 0, (int) ( $options['auto_redirect_countdown'] ?? 0 ) ),
					'currentSiteId' => get_current_blog_id(),
					'i18n'          => array(
						'visitingFrom' => __( 'Visiting from %s?', 'geo-regional-router' ),
						'message'      => __( 'We noticed you are visiting from %1$s. Would you like to switch to our %2$s for local pricing and service?', 'geo-regional-router' ),
						'switchBtn'    => __( 'Switch to %s', 'geo-regional-router' ),
						'stayBtn'      => __( 'Stay on this site', 'geo-regional-router' ),
						'redirecting'  => __( 'Redirecting in %d seconds…', 'geo-regional-router' ),
						'cancel'       => __( 'Cancel', 'geo-regional-router' ),
					),
				)
			);
		}
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

		wp_enqueue_style( 'grr-admin-css', GRR_PLUGIN_URL . 'assets/admin.css', array(), GRR_VERSION );
		wp_enqueue_script( 'grr-admin-js', GRR_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), GRR_VERSION, true );

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
	 * Cron job handler for downloading latest MaxMind database.
	 */
	public function run_maxmind_cron_update(): void {
		$options     = get_site_option( 'grr_options', array() );
		$license_key = trim( (string) ( $options['maxmind_license_key'] ?? '' ) );
		$db_path     = trim( (string) ( $options['maxmind_db_path'] ?? '' ) );

		if ( empty( $license_key ) || empty( $db_path ) ) {
			return;
		}

		$download_url = sprintf(
			'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&license_key=%s&suffix=tar.gz',
			urlencode( $license_key )
		);

		$response = wp_remote_get( $download_url, array( 'timeout' => 60 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->logger->log( 'MaxMind Cron update failed: Unable to download database.' );
			return;
		}

		$this->logger->log( 'MaxMind Cron update completed successfully.' );
	}

	/**
	 * Register REST API route for dynamic Geo detection.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'grr/v1',
			'/detect',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_detect_country' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST API Callback: Dynamic Country Detection & Route Calculation.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function rest_detect_country( \WP_REST_Request $request ): \WP_REST_Response {
		$options = get_site_option( 'grr_options', array() );

		$override_url = sanitize_url( (string) $request->get_param( 'current_url' ) );
		if ( empty( $override_url ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$override_url = sanitize_url( (string) $_SERVER['HTTP_REFERER'] );
		}

		$detected = $this->country_detector->detect_country();
		$country  = $detected['country'];

		$dest = $this->router->calculate_destination( $country, $options, $override_url );

		$country_names = array(
			'BD' => 'Bangladesh',
			'IN' => 'India',
			'US' => 'United States',
			'GB' => 'United Kingdom',
			'CA' => 'Canada',
			'AU' => 'Australia',
			'DE' => 'Germany',
			'FR' => 'France',
			'AE' => 'United Arab Emirates',
			'SG' => 'Singapore',
			'PK' => 'Pakistan',
			'MY' => 'Malaysia',
			'SA' => 'Saudi Arabia',
		);

		$flags = array(
			'BD' => '🇧🇩',
			'IN' => '🇮🇳',
			'US' => '🇺🇸',
			'GB' => '🇬🇧',
			'CA' => '🇨🇦',
			'AU' => '🇦🇺',
			'DE' => '🇩🇪',
			'FR' => '🇫🇷',
			'AE' => '🇦🇪',
			'SG' => '🇸🇬',
			'PK' => '🇵🇰',
			'MY' => '🇲🇾',
			'SA' => '🇸🇦',
		);

		$country_name = $country_names[ $country ] ?? $country;
		$flag         = $flags[ $country ] ?? '🌐';

		$site_bd_id = (int) ( $options['site_bd'] ?? 0 );
		$site_in_id = (int) ( $options['site_in'] ?? 0 );

		if ( $dest['target_site_id'] === $site_bd_id ) {
			$target_label = 'Bangladesh Store';
		} elseif ( $dest['target_site_id'] === $site_in_id ) {
			$target_label = 'India Store';
		} else {
			$target_label = 'Global Store';
		}

		$response_data = array(
			'success'        => true,
			'country'        => $country,
			'country_name'   => $country_name,
			'flag'           => $flag,
			'should_switch'  => (bool) $dest['should_redirect'],
			'current_url'    => $dest['current_url'],
			'target_url'     => $dest['target_url'],
			'target_site_id' => $dest['target_site_id'],
			'target_label'   => $target_label,
			'source'         => $detected['source'],
		);

		$response = rest_ensure_response( $response_data );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );

		return $response;
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

	/**
	 * Get Diagnostics.
	 *
	 * @return Diagnostics
	 */
	public function get_diagnostics(): Diagnostics {
		return $this->diagnostics;
	}
}
