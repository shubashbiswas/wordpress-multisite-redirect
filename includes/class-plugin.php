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
		add_action( 'wp_footer', array( $this, 'render_region_modal' ), 25 );

		// Admin Bar Quick Switcher
		add_action( 'admin_bar_menu', array( $this, 'register_admin_bar_switcher' ), 100 );

		// Shortcode for Frontend Regional Switcher
		add_shortcode( 'geo_regional_switcher', array( $this, 'render_frontend_switcher_shortcode' ) );

		// Register native Gutenberg Block (for Block Themes & Full Site Editing)
		add_action( 'init', array( $this, 'register_gutenberg_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );

		// Register Classic WordPress Widget (for Classic Themes & Widget areas)
		add_action( 'widgets_init', array( $this, 'register_classic_widget' ) );

		// WordPress Navigation Menu Integration (#region-modal link in any theme)
		add_filter( 'nav_menu_link_attributes', array( $this, 'filter_nav_menu_link_attributes' ), 10, 3 );
		add_filter( 'nav_menu_item_title', array( $this, 'filter_nav_menu_title' ), 10, 4 );

		// Global template action hook
		add_action( 'grr_region_switcher', array( $this, 'render_header_cart_element' ) );

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
		$options                  = get_site_option( 'grr_options', array() );
		$enable_frontend_switcher = ! isset( $options['enable_frontend_switcher'] ) || ! empty( $options['enable_frontend_switcher'] );
		if ( ! $enable_frontend_switcher ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'style' => 'footer', // 'footer', 'cart', or 'header'
			),
			$atts,
			'geo_regional_switcher'
		);

		$site_bd_id     = (int) ( $options['site_bd'] ?? 0 );
		$site_in_id     = (int) ( $options['site_in'] ?? 0 );
		$current_blog_id = get_current_blog_id();

		if ( $current_blog_id === $site_bd_id ) {
			$current_code = 'BD';
		} elseif ( $current_blog_id === $site_in_id ) {
			$current_code = 'IN';
		} else {
			$current_code = 'Global';
		}

		ob_start();

		if ( 'cart' === $atts['style'] || 'header' === $atts['style'] ) {
			?>
			<button type="button" class="grr-header-cart-trigger ct-header-item" aria-haspopup="dialog" aria-expanded="false" aria-controls="grrRegionModal" title="<?php echo esc_attr( sprintf( __( 'Select Region (%s)', 'geo-regional-router' ), $current_code ) ); ?>">
				<span class="grr-location-icon" aria-hidden="true">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
						<circle cx="12" cy="10" r="3"></circle>
					</svg>
				</span>
				<span class="grr-header-code"><?php echo esc_html( $current_code ); ?></span>
			</button>
			<?php
		} else {
			?>
			<button type="button" class="grr-footer-trigger" aria-haspopup="dialog" aria-expanded="false" aria-controls="grrRegionModal">
				<span class="grr-location-icon" aria-hidden="true">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
						<circle cx="12" cy="10" r="3"></circle>
					</svg>
				</span>
				<span class="grr-trigger-text"><?php esc_html_e( 'Region:', 'geo-regional-router' ); ?> <strong><?php echo esc_html( $current_code ); ?></strong></span>
				<span class="grr-trigger-chevron" aria-hidden="true">▾</span>
			</button>
			<?php
		}

		return (string) ob_get_clean();
	}

	/**
	 * Render header cart region switcher element directly.
	 */
	public function render_header_cart_element(): void {
		echo $this->render_frontend_switcher_shortcode( array( 'style' => 'cart' ) );
	}

	/**
	 * Register native Gutenberg Block for Site Editor and Block themes.
	 */
	public function register_gutenberg_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'grr-block-editor-js',
			GRR_PLUGIN_URL . 'assets/grr-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n' ),
			GRR_VERSION,
			true
		);

		register_block_type(
			'grr/region-switcher',
			array(
				'api_version'     => 2,
				'title'           => __( 'Regional Store Switcher', 'geo-regional-router' ),
				'category'        => 'widgets',
				'icon'            => 'location-alt',
				'description'     => __( 'Displays the Regional Store Switcher button with map icon and country code.', 'geo-regional-router' ),
				'editor_script'   => 'grr-block-editor-js',
				'render_callback' => array( $this, 'render_block_element' ),
				'attributes'      => array(
					'style' => array(
						'type'    => 'string',
						'default' => 'cart',
					),
				),
			)
		);
	}

	/**
	 * Enqueue Gutenberg block editor assets.
	 */
	public function enqueue_block_editor_assets(): void {
		wp_enqueue_script(
			'grr-block-editor-js',
			GRR_PLUGIN_URL . 'assets/grr-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n' ),
			GRR_VERSION,
			true
		);
	}

	/**
	 * Render Gutenberg Block element.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public function render_block_element( array $attributes = array() ): string {
		$style = sanitize_key( $attributes['style'] ?? 'cart' );
		return $this->render_frontend_switcher_shortcode( array( 'style' => $style ) );
	}

	/**
	 * Register Classic WordPress Widget for Widget areas and sidebars.
	 */
	public function register_classic_widget(): void {
		register_widget( __NAMESPACE__ . '\Switcher_Widget' );
	}

	/**
	 * Enhance menu items with href="#region-modal" in any WordPress navigation menu.
	 */
	public function filter_nav_menu_link_attributes( array $atts, $item, $args ): array {
		if ( isset( $item->url ) && false !== strpos( $item->url, '#region-modal' ) ) {
			$atts['class']         = trim( ( $atts['class'] ?? '' ) . ' grr-header-cart-trigger grr-region-trigger' );
			$atts['aria-haspopup'] = 'dialog';
			$atts['aria-expanded'] = 'false';
			$atts['aria-controls'] = 'grrRegionModal';
			$atts['role']          = 'button';
		}
		return $atts;
	}

	/**
	 * Automatically inject current country code into menu title if URL is #region-modal.
	 */
	public function filter_nav_menu_title( string $title, $item, $args, $depth ): string {
		if ( isset( $item->url ) && false !== strpos( $item->url, '#region-modal' ) ) {
			$options         = get_site_option( 'grr_options', array() );
			$site_bd_id      = (int) ( $options['site_bd'] ?? 0 );
			$site_in_id      = (int) ( $options['site_in'] ?? 0 );
			$current_blog_id = get_current_blog_id();

			if ( $current_blog_id === $site_bd_id ) {
				$current_code = 'BD';
			} elseif ( $current_blog_id === $site_in_id ) {
				$current_code = 'IN';
			} else {
				$current_code = 'Global';
			}

			if ( empty( $title ) || '#region-modal' === $title || 'Region' === $title ) {
				return '📍 ' . $current_code;
			}
		}
		return $title;
	}

	/**
	 * Output Full-Screen Region Selector Modal Overlay in wp_footer.
	 */
	public function render_region_modal(): void {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}

		$options                  = get_site_option( 'grr_options', array() );
		$enable_frontend_switcher = ! isset( $options['enable_frontend_switcher'] ) || ! empty( $options['enable_frontend_switcher'] );
		if ( ! $enable_frontend_switcher ) {
			return;
		}

		$rendered = true;

		$site_global_id = (int) ( $options['site_global'] ?? 1 );
		$site_bd_id     = (int) ( $options['site_bd'] ?? 0 );
		$site_in_id     = (int) ( $options['site_in'] ?? 0 );

		$global_url = $site_global_id > 0 ? get_home_url( $site_global_id, '/' ) : home_url( '/' );
		$bd_url     = $site_bd_id > 0 ? get_home_url( $site_bd_id, '/' ) : '';
		$in_url     = $site_in_id > 0 ? get_home_url( $site_in_id, '/' ) : '';

		$req_uri    = $_SERVER['REQUEST_URI'] ?? '/';
		$clean_path = wp_parse_url( $req_uri, PHP_URL_PATH ) ?: '/';

		if ( $site_bd_id > 0 ) {
			$bd_path = trim( (string) wp_parse_url( $bd_url, PHP_URL_PATH ), '/' );
			if ( ! empty( $bd_path ) && 0 === strpos( trim( $clean_path, '/' ), $bd_path ) ) {
				$clean_path = substr( trim( $clean_path, '/' ), strlen( $bd_path ) );
			}
		}

		if ( $site_in_id > 0 ) {
			$in_path = trim( (string) wp_parse_url( $in_url, PHP_URL_PATH ), '/' );
			if ( ! empty( $in_path ) && 0 === strpos( trim( $clean_path, '/' ), $in_path ) ) {
				$clean_path = substr( trim( $clean_path, '/' ), strlen( $in_path ) );
			}
		}

		$query      = ! empty( $_SERVER['QUERY_STRING'] ) ? '?' . sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
		$url_global = add_query_arg( 'grr_set_country', 'GLOBAL', rtrim( $global_url, '/' ) . '/' . ltrim( $clean_path, '/' ) . $query );
		$url_bd     = ! empty( $bd_url ) ? add_query_arg( 'grr_set_country', 'BD', rtrim( $bd_url, '/' ) . '/' . ltrim( $clean_path, '/' ) . $query ) : '';
		$url_in     = ! empty( $in_url ) ? add_query_arg( 'grr_set_country', 'IN', rtrim( $in_url, '/' ) . '/' . ltrim( $clean_path, '/' ) . $query ) : '';

		$current_blog_id = get_current_blog_id();
		?>
		<div id="grrRegionModal" class="grr-region-modal-overlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="grrRegionModalTitle">
			<div class="grr-region-modal-backdrop" id="grrRegionModalBackdrop"></div>
			<div class="grr-region-modal-dialog">
				<div class="grr-region-modal-header">
					<div class="grr-region-modal-title-wrap">
						<div class="grr-region-modal-icon">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
								<circle cx="12" cy="10" r="3"></circle>
							</svg>
						</div>
						<div>
							<h2 id="grrRegionModalTitle" class="grr-region-modal-title"><?php esc_html_e( 'Choose your location', 'geo-regional-router' ); ?></h2>
							<p class="grr-region-modal-subtitle"><?php esc_html_e( 'Select your shopping destination to view regional products, local pricing, and delivery options.', 'geo-regional-router' ); ?></p>
						</div>
					</div>
					<button type="button" class="grr-region-modal-close" id="grrCloseRegionModal" aria-label="<?php esc_attr_e( 'Close', 'geo-regional-router' ); ?>">✕</button>
				</div>

				<div class="grr-region-grid">
					<!-- Global Card -->
					<a href="<?php echo esc_url( $url_global ); ?>" class="grr-region-card <?php echo $current_blog_id === $site_global_id ? 'is-current' : ''; ?>">
						<div class="grr-region-card-flag">🌐</div>
						<div class="grr-region-card-info">
							<div class="grr-region-card-name"><?php esc_html_e( 'Global Store', 'geo-regional-router' ); ?></div>
							<div class="grr-region-card-meta"><?php esc_html_e( 'International Shipping • USD ($)', 'geo-regional-router' ); ?></div>
						</div>
						<?php if ( $current_blog_id === $site_global_id ) : ?>
							<span class="grr-current-badge"><?php esc_html_e( '✓ Current Region', 'geo-regional-router' ); ?></span>
						<?php else : ?>
							<span class="grr-select-arrow" aria-hidden="true">→</span>
						<?php endif; ?>
					</a>

					<!-- Bangladesh Card -->
					<?php if ( ! empty( $url_bd ) ) : ?>
					<a href="<?php echo esc_url( $url_bd ); ?>" class="grr-region-card <?php echo $current_blog_id === $site_bd_id ? 'is-current' : ''; ?>">
						<div class="grr-region-card-flag">🇧🇩</div>
						<div class="grr-region-card-info">
							<div class="grr-region-card-name"><?php esc_html_e( 'Bangladesh Store', 'geo-regional-router' ); ?></div>
							<div class="grr-region-card-meta"><?php esc_html_e( 'Local Delivery • BDT (৳) • বাংলা', 'geo-regional-router' ); ?></div>
						</div>
						<?php if ( $current_blog_id === $site_bd_id ) : ?>
							<span class="grr-current-badge"><?php esc_html_e( '✓ Current Region', 'geo-regional-router' ); ?></span>
						<?php else : ?>
							<span class="grr-select-arrow" aria-hidden="true">→</span>
						<?php endif; ?>
					</a>
					<?php endif; ?>

					<!-- India Card -->
					<?php if ( ! empty( $url_in ) ) : ?>
					<a href="<?php echo esc_url( $url_in ); ?>" class="grr-region-card <?php echo $current_blog_id === $site_in_id ? 'is-current' : ''; ?>">
						<div class="grr-region-card-flag">🇮🇳</div>
						<div class="grr-region-card-info">
							<div class="grr-region-card-name"><?php esc_html_e( 'India Store', 'geo-regional-router' ); ?></div>
							<div class="grr-region-card-meta"><?php esc_html_e( 'Local Delivery • INR (₹) • English', 'geo-regional-router' ); ?></div>
						</div>
						<?php if ( $current_blog_id === $site_in_id ) : ?>
							<span class="grr-current-badge"><?php esc_html_e( '✓ Current Region', 'geo-regional-router' ); ?></span>
						<?php else : ?>
							<span class="grr-select-arrow" aria-hidden="true">→</span>
						<?php endif; ?>
					</a>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue frontend CSS for switcher and Geo-Prompt modal/banner.
	 */
	public function enqueue_frontend_assets(): void {
		$options                  = get_site_option( 'grr_options', array() );
		$is_enabled               = ! empty( $options['enabled'] );
		$routing_mode             = $options['routing_mode'] ?? 'prompt';
		$has_switcher             = ! isset( $options['enable_frontend_switcher'] ) || ! empty( $options['enable_frontend_switcher'] );

		if ( $has_switcher ) {
			wp_enqueue_style( 'grr-frontend-css', GRR_PLUGIN_URL . 'assets/admin.css', array(), GRR_VERSION );
			// Enqueue grr-prompt-js so region modal and header cart handlers are active
			wp_enqueue_script( 'grr-prompt-js', GRR_PLUGIN_URL . 'assets/grr-prompt.js', array(), GRR_VERSION, true );
		}

		// Client-side Geo-Prompt (Full Page Cache compatible)
		if ( $is_enabled && 'prompt' === $routing_mode ) {
			wp_enqueue_style( 'grr-prompt-css', GRR_PLUGIN_URL . 'assets/grr-prompt.css', array(), GRR_VERSION );
			wp_enqueue_script( 'grr-prompt-js', GRR_PLUGIN_URL . 'assets/grr-prompt.js', array(), GRR_VERSION, true );
		}

		$site_bd_id     = (int) ( $options['site_bd'] ?? 0 );
		$site_in_id     = (int) ( $options['site_in'] ?? 0 );
		$current_blog_id = get_current_blog_id();

		if ( $current_blog_id === $site_bd_id ) {
			$current_code = 'BD';
		} elseif ( $current_blog_id === $site_in_id ) {
			$current_code = 'IN';
		} else {
			$current_code = 'Global';
		}

		$persistence = $options['cookie_persistence'] ?? '7d';
		$cookie_ttl  = 7;
		if ( '24h' === $persistence ) {
			$cookie_ttl = 1;
		} elseif ( '30d' === $persistence ) {
			$cookie_ttl = 30;
		} elseif ( 'session' === $persistence || 'disabled' === $persistence ) {
			$cookie_ttl = 0;
		}

		wp_localize_script(
			'grr-prompt-js',
			'grrPromptConfig',
			array(
				'restUrl'                  => esc_url_raw( rest_url( 'grr/v1/detect' ) ),
				'style'                    => sanitize_key( $options['prompt_style'] ?? 'card' ),
				'delay'                    => max( 0, (float) ( $options['prompt_delay'] ?? 1.5 ) ) * 1000,
				'autoHide'                 => ! empty( $options['prompt_auto_hide'] ) ? (int) $options['prompt_auto_hide'] : 7,
				'cookieTtl'                => $cookie_ttl,
				'countdown'                => max( 0, (int) ( $options['auto_redirect_countdown'] ?? 0 ) ),
				'currentSiteId'            => $current_blog_id,
				'currentCode'              => $current_code,
				'promptEnabled'            => ( $is_enabled && 'prompt' === $routing_mode ),
				'i18n'                     => array(
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

/**
 * Class Switcher_Widget
 * Universal Classic WordPress Widget for any widget area.
 */
class Switcher_Widget extends \WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'grr_switcher_widget',
			__( 'Regional Store Switcher', 'geo-regional-router' ),
			array(
				'description' => __( 'Displays location pin and regional switcher button.', 'geo-regional-router' ),
			)
		);
	}

	/**
	 * Output widget content on frontend.
	 *
	 * @param array $args Widget display arguments.
	 * @param array $instance Widget instance settings.
	 */
	public function widget( $args, $instance ): void {
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$style = ! empty( $instance['style'] ) ? sanitize_key( $instance['style'] ) : 'cart';
		echo do_shortcode( '[geo_regional_switcher style="' . esc_attr( $style ) . '"]' );
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render widget settings form in WP Admin.
	 *
	 * @param array $instance Current widget instance.
	 */
	public function form( $instance ): void {
		$style = ! empty( $instance['style'] ) ? sanitize_key( $instance['style'] ) : 'cart';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>"><?php esc_html_e( 'Display Style:', 'geo-regional-router' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
				<option value="cart" <?php selected( 'cart', $style ); ?>><?php esc_html_e( 'Compact Pill (📍 BD)', 'geo-regional-router' ); ?></option>
				<option value="footer" <?php selected( 'footer', $style ); ?>><?php esc_html_e( 'Full Button (📍 Region: BD ▾)', 'geo-regional-router' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * Sanitize widget options upon save.
	 *
	 * @param array $new_instance New settings.
	 * @param array $old_instance Previous settings.
	 * @return array Sanitized settings.
	 */
	public function update( $new_instance, $old_instance ): array {
		$instance          = array();
		$instance['style'] = sanitize_key( $new_instance['style'] ?? 'cart' );
		return $instance;
	}
}
