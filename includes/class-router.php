<?php
namespace GRR;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Router
 * High-performance URL routing engine for geographic Multisite redirection.
 */
class Router {

	/**
	 * Country Detector instance.
	 *
	 * @var Country_Detector
	 */
	private Country_Detector $country_detector;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Country_Detector $country_detector Country detector.
	 * @param Logger           $logger Logger.
	 */
	public function __construct( Country_Detector $country_detector, Logger $logger ) {
		$this->country_detector = $country_detector;
		$this->logger           = $logger;
	}

	/**
	 * Flag to prevent double execution across multiple hooks.
	 *
	 * @var bool
	 */
	private bool $has_executed = false;

	/**
	 * Register router hooks.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'process_routing' ), 1 );
		add_action( 'template_redirect', array( $this, 'process_routing' ), 1 );
		add_action( 'wp_head', array( $this, 'output_hreflang_tags' ), 2 );
		add_action( 'send_headers', array( $this, 'output_edge_cache_headers' ) );
	}

	/**
	 * Output Edge Cache Vary headers if enabled.
	 */
	public function output_edge_cache_headers(): void {
		$options = get_site_option( 'grr_options', array() );
		if ( ! empty( $options['enable_edge_headers'] ) && ! headers_sent() ) {
			header( 'Vary: CF-IPCountry, Accept-Language', false );
		}
	}

	/**
	 * Output SEO hreflang alternate links in page <head> if enabled.
	 */
	public function output_hreflang_tags(): void {
		$options = get_site_option( 'grr_options', array() );
		if ( empty( $options['enable_hreflang'] ) ) {
			return;
		}

		$site_global_id = (int) ( $options['site_global'] ?? 0 );
		$site_bd_id     = (int) ( $options['site_bd'] ?? 0 );
		$site_in_id     = (int) ( $options['site_in'] ?? 0 );

		if ( ! $site_global_id || ! $site_bd_id || ! $site_in_id ) {
			return;
		}

		$global_url = get_site_url( $site_global_id );
		$bd_url     = get_site_url( $site_bd_id );
		$in_url     = get_site_url( $site_in_id );

		$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
		$parsed_uri  = wp_parse_url( $request_uri );
		$path        = $parsed_uri['path'] ?? '/';
		$query       = isset( $parsed_uri['query'] ) ? '?' . $parsed_uri['query'] : '';

		$clean_path = $this->extract_clean_path( $path, array( $global_url, $bd_url, $in_url ) );

		$href_default = rtrim( $global_url, '/' ) . '/' . ltrim( $clean_path, '/' ) . $query;
		$href_bd      = rtrim( $bd_url, '/' ) . '/' . ltrim( $clean_path, '/' ) . $query;
		$href_in      = rtrim( $in_url, '/' ) . '/' . ltrim( $clean_path, '/' ) . $query;

		echo "\n<!-- Geo Regional Router SEO Hreflang Tags -->\n";
		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $href_default ) . '" />' . "\n";
		echo '<link rel="alternate" hreflang="bn-BD" href="' . esc_url( $href_bd ) . '" />' . "\n";
		echo '<link rel="alternate" hreflang="hi-IN" href="' . esc_url( $href_in ) . '" />' . "\n";
	}

	/**
	 * Main routing execution callback.
	 */
	public function process_routing(): void {
		if ( $this->has_executed ) {
			return;
		}

		$options = get_site_option( 'grr_options', array() );

		if ( empty( $options['enabled'] ) ) {
			return;
		}

		if ( headers_sent() ) {
			$this->logger->log( 'Skipping routing: Headers already sent.' );
			return;
		}

		// Check bypass URL parameter
		if ( $this->has_bypass_parameter() ) {
			$this->logger->log( 'Skipping routing: Explicit ?skipredirect parameter present.' );
			return;
		}

		// Check exclude / skip conditions
		$skip_reason = $this->get_skip_reason( $options );
		if ( false !== $skip_reason ) {
			$this->logger->log( 'Skipping routing: ' . $skip_reason );
			return;
		}

		$this->has_executed = true;

		// Detect country
		$country_data = $this->country_detector->detect_country();
		$country      = $country_data['country'];

		/**
		 * Filter detected country code.
		 *
		 * @param string $country 2-letter ISO country code.
		 * @param string $current_url Current request URL.
		 */
		$country = apply_filters( 'geo_regional_router_country', $country, $this->get_current_url() );

		if ( 'UNKNOWN' === $country || empty( $country ) ) {
			$this->logger->log( 'Skipping routing: Country code unknown or fallback.' );
			return;
		}

		// Calculate routing destination
		$route_result = $this->calculate_destination( $country, $options );

		if ( ! $route_result['should_redirect'] ) {
			$this->logger->log( 'No redirect needed: ' . $route_result['reason'] );
			return;
		}

		$target_url   = $route_result['target_url'];
		$current_url  = $route_result['current_url'];
		$status_code  = (int) ( $options['redirect_status'] ?? 302 );

		/**
		 * Filter whether routing should occur.
		 *
		 * @param bool   $should_redirect
		 * @param string $current_url
		 * @param string $target_url
		 * @param string $country
		 */
		$should_redirect = apply_filters( 'geo_regional_router_should_redirect', true, $current_url, $target_url, $country );

		if ( ! $should_redirect ) {
			$this->logger->log( 'Skipping routing: Blocked by geo_regional_router_should_redirect filter.' );
			return;
		}

		/**
		 * Filter final redirect URL.
		 *
		 * @param string $target_url
		 * @param string $current_url
		 * @param string $country
		 */
		$target_url = apply_filters( 'geo_regional_router_redirect_url', $target_url, $current_url, $country );

		// Set persistence cookie if enabled
		$this->handle_cookie_persistence( $country, $options );

		$this->logger->log( sprintf( 'Redirecting [%s] visitor to %s (Status %d)', $country, $target_url, $status_code ) );

		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0', true );
			header( 'Pragma: no-cache', true );
			header( 'Expires: 0', true );
			header( 'Vary: CF-IPCountry, Accept-Language, Cookie', false );
		}

		// Perform safe redirect
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_multisite_hosts' ) );
		wp_safe_redirect( $target_url, $status_code );
		exit;
	}

	/**
	 * Calculate destination target URL and routing decision.
	 *
	 * @param string $country 2-letter ISO Country Code.
	 * @param array  $options Plugin options array.
	 * @return array
	 */
	public function calculate_destination( string $country, array $options ): array {
		$current_blog_id = get_current_blog_id();
		$current_url     = $this->get_current_url();

		$site_global_id = (int) ( $options['site_global'] ?? 0 );
		$site_bd_id     = (int) ( $options['site_bd'] ?? 0 );
		$site_in_id     = (int) ( $options['site_in'] ?? 0 );

		if ( ! $site_global_id || ! $site_bd_id || ! $site_in_id ) {
			return array(
				'should_redirect' => false,
				'reason'          => 'Incomplete site mapping configuration.',
				'current_url'     => $current_url,
				'target_url'      => $current_url,
				'target_site_id'  => $current_blog_id,
			);
		}

		// Map country code to target site ID
		switch ( strtoupper( $country ) ) {
			case 'BD':
				$target_site_id = $site_bd_id;
				break;
			case 'IN':
				$target_site_id = $site_in_id;
				break;
			default:
				$target_site_id = $site_global_id;
				break;
		}

		// Obtain site URLs
		$global_site_url = get_site_url( $site_global_id );
		$bd_site_url     = get_site_url( $site_bd_id );
		$in_site_url     = get_site_url( $site_in_id );
		$target_site_url = get_site_url( $target_site_id );

		// Parse request path
		$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
		$parsed_uri  = wp_parse_url( $request_uri );
		$path        = $parsed_uri['path'] ?? '/';
		$query       = isset( $parsed_uri['query'] ) ? '?' . $parsed_uri['query'] : '';

		// Extract clean relative path by stripping site path prefixes
		$clean_path = $this->extract_clean_path( $path, array( $global_site_url, $bd_site_url, $in_site_url ) );

		// Construct target full URL
		$target_url = rtrim( $target_site_url, '/' ) . '/' . ltrim( $clean_path, '/' ) . $query;

		// Normalize URLs for exact comparison
		$normalized_current = $this->normalize_url( $current_url );
		$normalized_target  = $this->normalize_url( $target_url );

		if ( $normalized_current === $normalized_target ) {
			return array(
				'should_redirect' => false,
				'reason'          => 'Visitor is already on the correct site URL.',
				'current_url'     => $current_url,
				'target_url'      => $target_url,
				'target_site_id'  => $target_site_id,
			);
		}

		return array(
			'should_redirect' => true,
			'reason'          => sprintf( 'Country [%s] maps to Site ID %d.', $country, $target_site_id ),
			'current_url'     => $current_url,
			'target_url'      => $target_url,
			'target_site_id'  => $target_site_id,
		);
	}

	/**
	 * Extract inner path stripped of any regional prefixes (/bd/, /in/).
	 *
	 * @param string $path Raw request path.
	 * @param array  $site_urls Configured multisite home URLs.
	 * @return string Clean relative path e.g. /about/
	 */
	public function extract_clean_path( string $path, array $site_urls ): string {
		$clean_path = $path;

		// Collect path prefixes from configured sites
		$prefixes = array();
		foreach ( $site_urls as $site_url ) {
			$parsed = wp_parse_url( $site_url );
			if ( ! empty( $parsed['path'] ) && '/' !== $parsed['path'] ) {
				$prefixes[] = rtrim( $parsed['path'], '/' ) . '/';
			}
		}

		// Also explicitly include standard regional prefixes /bd/ and /in/
		$prefixes[] = '/bd/';
		$prefixes[] = '/in/';
		$prefixes   = array_unique( $prefixes );

		// Strip regional prefix if path starts with it
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $clean_path, $prefix ) ) {
				$clean_path = '/' . substr( $clean_path, strlen( $prefix ) );
				break;
			}
		}

		// Prevent double prefixing edge case
		foreach ( array( '/bd/', '/in/' ) as $prefix ) {
			if ( 0 === strpos( $clean_path, $prefix ) ) {
				$clean_path = '/' . substr( $clean_path, strlen( $prefix ) );
			}
		}

		return '/' . ltrim( $clean_path, '/' );
	}

	/**
	 * Check if ?skipredirect is present in query parameters.
	 *
	 * @return bool
	 */
	private function has_bypass_parameter(): bool {
		if ( isset( $_GET['skipredirect'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Evaluate skip conditions.
	 *
	 * @param array $options Plugin settings.
	 * @return string|false Skip reason string if skipped, false if routing should proceed.
	 */
	private function get_skip_reason( array $options ) {
		// Logged-in administrator check
		if ( ! empty( $options['skip_logged_in_admins'] ) && ( current_user_can( 'manage_options' ) || current_user_can( 'manage_network_options' ) ) ) {
			return 'Logged-in administrator skipping enabled.';
		}

		// Logged-in user check
		if ( ! empty( $options['skip_logged_in_users'] ) && is_user_logged_in() ) {
			return 'Logged-in user skipping enabled.';
		}

		// Bot / Crawler check
		if ( ! empty( $options['skip_bots'] ) && $this->is_bot() ) {
			return 'Search engine crawler / bot detected.';
		}

		// REST API check
		if ( ! empty( $options['skip_rest'] ) ) {
			if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || 0 === strpos( $_SERVER['REQUEST_URI'] ?? '', '/wp-json/' ) ) {
				return 'REST API request.';
			}
		}

		// AJAX check
		if ( ! empty( $options['skip_ajax'] ) && wp_doing_ajax() ) {
			return 'AJAX request.';
		}

		// Cron check
		if ( ! empty( $options['skip_cron'] ) && ( wp_doing_cron() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) ) {
			return 'WP-Cron execution.';
		}

		// Admin URLs check
		if ( ! empty( $options['skip_admin_urls'] ) && ( is_admin() || false !== strpos( $_SERVER['REQUEST_URI'] ?? '', '/wp-admin/' ) ) ) {
			return 'Admin URL request.';
		}

		// XML-RPC check
		if ( ! empty( $options['skip_xmlrpc'] ) && ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || false !== strpos( $_SERVER['REQUEST_URI'] ?? '', 'xmlrpc.php' ) ) ) {
			return 'XML-RPC request.';
		}

		// Feeds check
		if ( ! empty( $options['skip_feeds'] ) && is_feed() ) {
			return 'Feed request.';
		}

		// Sitemaps check
		if ( ! empty( $options['skip_sitemaps'] ) && ( is_sitemap() || false !== strpos( $_SERVER['REQUEST_URI'] ?? '', 'sitemap' ) ) ) {
			return 'Sitemap request.';
		}

		// Previews check
		if ( ! empty( $options['skip_previews'] ) && ( is_preview() || isset( $_GET['preview'] ) ) ) {
			return 'WordPress preview request.';
		}

		// Login page check
		if ( false !== strpos( $_SERVER['REQUEST_URI'] ?? '', 'wp-login.php' ) ) {
			return 'wp-login.php request.';
		}

		return false;
	}

	/**
	 * Detect bots and crawlers via User-Agent inspection.
	 *
	 * @return bool
	 */
	private function is_bot(): bool {
		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
		if ( empty( $user_agent ) ) {
			return false;
		}

		$bot_regex = '/(Googlebot|bingbot|Baiduspider|YandexBot|DuckDuckBot|Slurp|facebookexternalhit|Twitterbot|LinkedInBot|bot|spider|crawler)/i';
		return (bool) preg_match( $bot_regex, $user_agent );
	}

	/**
	 * Get current full URL safely.
	 *
	 * @return string
	 */
	public function get_current_url(): string {
		$is_ssl = is_ssl() ? 'https://' : 'http://';
		$host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
		$uri    = $_SERVER['REQUEST_URI'] ?? '/';
		return $is_ssl . $host . $uri;
	}

	/**
	 * Normalize URL for exact equality checking.
	 *
	 * @param string $url URL to normalize.
	 * @return string
	 */
	private function normalize_url( string $url ): string {
		$parsed = wp_parse_url( $url );
		$scheme = strtolower( $parsed['scheme'] ?? 'http' );
		$host   = strtolower( $parsed['host'] ?? '' );
		$path   = rtrim( $parsed['path'] ?? '/', '/' );
		$query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		return $scheme . '://' . $host . ( empty( $path ) ? '' : $path ) . '/' . $query;
	}

	/**
	 * Handle cookie persistence if enabled.
	 *
	 * @param string $country ISO country code.
	 * @param array  $options Plugin settings.
	 */
	private function handle_cookie_persistence( string $country, array $options ): void {
		$mode = $options['cookie_persistence'] ?? 'disabled';
		if ( 'disabled' === $mode ) {
			return;
		}

		$expiry = 0;
		if ( '24h' === $mode ) {
			$expiry = time() + DAY_IN_SECONDS;
		} elseif ( '7d' === $mode ) {
			$expiry = time() + ( 7 * DAY_IN_SECONDS );
		}

		if ( headers_sent() ) {
			return;
		}

		$cookie_name = 'grr_visitor_country';
		if ( empty( $_COOKIE[ $cookie_name ] ) || $_COOKIE[ $cookie_name ] !== $country ) {
			setcookie( $cookie_name, $country, $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
	}

	/**
	 * Allow all configured multisite hosts in wp_safe_redirect.
	 *
	 * @param array $hosts Allowed hosts array.
	 * @return array
	 */
	public function allow_multisite_hosts( array $hosts ): array {
		$sites = get_sites();
		foreach ( $sites as $site ) {
			$domain = $site->domain;
			if ( ! in_array( $domain, $hosts, true ) ) {
				$hosts[] = $domain;
			}
		}
		return $hosts;
	}
}
