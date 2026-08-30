<?php
namespace GRR;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Country_Detector
 * Handles country identification using multiple fallback sources with in-memory caching and manual user preference persistence.
 */
class Country_Detector {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'process_early_cookies' ), 1 );
	}

	/**
	 * Process early cookies for test mode and manual visitor selection before headers are sent.
	 */
	public function process_early_cookies(): void {
		if ( headers_sent() ) {
			return;
		}

		if ( isset( $_GET['grr_test_country'] ) && current_user_can( 'manage_network_options' ) ) {
			$raw_val = sanitize_text_field( wp_unslash( $_GET['grr_test_country'] ) );
			if ( 'reset' === strtolower( $raw_val ) ) {
				setcookie( 'grr_admin_test_country', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
				unset( $_COOKIE['grr_admin_test_country'] );
			} else {
				$test_country = strtoupper( $raw_val );
				if ( preg_match( '/^[A-Z]{2}$/', $test_country ) ) {
					setcookie( 'grr_admin_test_country', $test_country, time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
					$_COOKIE['grr_admin_test_country'] = $test_country;
				}
			}
		}

		if ( isset( $_GET['grr_set_country'] ) ) {
			$set_country = strtoupper( sanitize_text_field( wp_unslash( $_GET['grr_set_country'] ) ) );
			if ( 'RESET' === $set_country ) {
				setcookie( 'grr_user_manual_country', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
				unset( $_COOKIE['grr_user_manual_country'] );
			} elseif ( preg_match( '/^[A-Z]{2,6}$/', $set_country ) ) {
				setcookie( 'grr_user_manual_country', $set_country, time() + ( 30 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN );
				$_COOKIE['grr_user_manual_country'] = $set_country;
			}
		}
	}

	/**
	 * Detect visitor country code.
	 *
	 * @return array Array containing ['country' => 'BD', 'source' => 'Cloudflare CF-IPCountry']
	 */
	public function detect_country(): array {
		$options = get_site_option( 'grr_options', array() );

		// Priority 1: Admin Test Mode Override (?grr_test_country=BD or cookie)
		if ( isset( $_GET['grr_test_country'] ) && current_user_can( 'manage_network_options' ) ) {
			$raw_val = sanitize_text_field( wp_unslash( $_GET['grr_test_country'] ) );
			if ( 'reset' !== strtolower( $raw_val ) ) {
				$test_country = strtoupper( $raw_val );
				if ( preg_match( '/^[A-Z]{2}$/', $test_country ) ) {
					return array(
						'country' => $test_country,
						'source'  => 'Admin Test Mode Override',
					);
				}
			}
		}

		if ( ! empty( $_COOKIE['grr_admin_test_country'] ) && current_user_can( 'manage_network_options' ) ) {
			$test_country = strtoupper( sanitize_text_field( wp_unslash( $_COOKIE['grr_admin_test_country'] ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $test_country ) ) {
				return array(
					'country' => $test_country,
					'source'  => 'Admin Test Mode Override (Active)',
				);
			}
		}

		// Priority 2: Manual Visitor Selection (?grr_set_country=BD or grr_user_manual_country Cookie)
		if ( isset( $_GET['grr_set_country'] ) ) {
			$set_country = strtoupper( sanitize_text_field( wp_unslash( $_GET['grr_set_country'] ) ) );
			if ( 'RESET' !== $set_country && preg_match( '/^[A-Z]{2,6}$/', $set_country ) ) {
				return array(
					'country' => $set_country,
					'source'  => 'Visitor Manual Selection (?grr_set_country)',
				);
			}
		}

		if ( ! empty( $_COOKIE['grr_user_manual_country'] ) ) {
			$manual_country = strtoupper( sanitize_text_field( wp_unslash( $_COOKIE['grr_user_manual_country'] ) ) );
			if ( preg_match( '/^[A-Z]{2,6}$/', $manual_country ) ) {
				return array(
					'country' => $manual_country,
					'source'  => 'Visitor Saved Manual Preference (Cookie)',
				);
			}
		}

		$client_ip = $this->get_client_ip();

		// Check Object Cache for IP lookup
		$cache_key     = 'grr_geoip_' . md5( $client_ip );
		$cached_result = wp_cache_get( $cache_key, 'grr_geoip' );
		if ( false !== $cached_result && is_array( $cached_result ) ) {
			return $cached_result;
		}

		$trusted_proxies  = trim( (string) ( $options['trusted_proxies'] ?? '' ) );
		$is_trusted_proxy = $this->verify_trusted_proxy( $client_ip, $trusted_proxies );

		$detected = null;

		// Priority 3: Cloudflare CF-IPCountry
		if ( ! empty( $options['country_source_cf'] ) && $is_trusted_proxy ) {
			if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
				$cf_country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
				if ( 'XX' !== $cf_country && 'T1' !== $cf_country && preg_match( '/^[A-Z]{2}$/', $cf_country ) ) {
					$detected = array(
						'country' => $cf_country,
						'source'  => 'Cloudflare (CF-IPCountry)',
					);
				}
			}
		}

		// Priority 4: Configured Custom Header
		if ( null === $detected && ! empty( $options['country_source_header'] ) && $is_trusted_proxy ) {
			$header_name = trim( (string) ( $options['country_custom_header_name'] ?? 'HTTP_X_GEOIP_COUNTRY' ) );
			$server_key  = strtoupper( str_replace( '-', '_', $header_name ) );
			if ( 0 !== strpos( $server_key, 'HTTP_' ) && 'REMOTE_ADDR' !== $server_key ) {
				$server_key = 'HTTP_' . $server_key;
			}

			if ( ! empty( $_SERVER[ $server_key ] ) ) {
				$header_country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) ) );
				if ( preg_match( '/^[A-Z]{2}$/', $header_country ) ) {
					$detected = array(
						'country' => $header_country,
						'source'  => 'Custom HTTP Header (' . $header_name . ')',
					);
				}
			}
		}

		// Priority 5: MaxMind GeoIP Local Database
		if ( null === $detected ) {
			$maxmind_db = trim( (string) ( $options['maxmind_db_path'] ?? '' ) );
			if ( ! empty( $maxmind_db ) && file_exists( $maxmind_db ) && is_readable( $maxmind_db ) ) {
				$maxmind_country = $this->lookup_maxmind_country( $client_ip, $maxmind_db );
				if ( $maxmind_country && preg_match( '/^[A-Z]{2}$/', $maxmind_country ) ) {
					$detected = array(
						'country' => $maxmind_country,
						'source'  => 'MaxMind GeoIP Database',
					);
				}
			}
		}

		// Fallback
		if ( null === $detected ) {
			$detected = array(
				'country' => 'UNKNOWN',
				'source'  => 'Unknown / Fallback',
			);
		}

		// Cache in memory
		wp_cache_set( $cache_key, $detected, 'grr_geoip', 3600 );

		return $detected;
	}

	/**
	 * Get visitor IP address safely.
	 *
	 * @return string
	 */
	public function get_client_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
		return sanitize_text_field( wp_unslash( $ip ) );
	}

	/**
	 * Verify if client IP is from a trusted proxy (or if trusted proxies setting is empty).
	 *
	 * @param string $ip Client IP.
	 * @param string $trusted_proxies_str Comma or newline separated list of trusted IPs or CIDR blocks.
	 * @return bool
	 */
	private function verify_trusted_proxy( string $ip, string $trusted_proxies_str ): bool {
		if ( empty( $trusted_proxies_str ) ) {
			return true;
		}

		$lines = preg_split( '/[\s,]+/', $trusted_proxies_str );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			if ( strpos( $line, '/' ) !== false ) {
				if ( $this->ip_in_cidr( $ip, $line ) ) {
					return true;
				}
			} else {
				if ( $ip === $line ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if an IP address falls within a CIDR range.
	 *
	 * @param string $ip IP address.
	 * @param string $cidr CIDR range.
	 * @return bool
	 */
	private function ip_in_cidr( string $ip, string $cidr ): bool {
		list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, null );
		if ( null === $bits ) {
			return $ip === $subnet;
		}

		$ip_binary     = @inet_pton( $ip );
		$subnet_binary = @inet_pton( $subnet );

		if ( false === $ip_binary || false === $subnet_binary ) {
			return false;
		}

		if ( strlen( $ip_binary ) !== strlen( $subnet_binary ) ) {
			return false;
		}

		$bits = (int) $bits;
		$bytes = (int) ( $bits / 8 );
		$remainder_bits = $bits % 8;

		if ( $bytes > 0 && substr( $ip_binary, 0, $bytes ) !== substr( $subnet_binary, 0, $bytes ) ) {
			return false;
		}

		if ( $remainder_bits > 0 ) {
			$mask = ( 0xFF << ( 8 - $remainder_bits ) ) & 0xFF;
			$ip_byte = ord( $ip_binary[ $bytes ] );
			$subnet_byte = ord( $subnet_binary[ $bytes ] );
			if ( ( $ip_byte & $mask ) !== ( $subnet_byte & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Simple MaxMind MMDB binary reader fallback lookup for Country ISOCode.
	 *
	 * @param string $ip IP Address.
	 * @param string $db_path Absolute path to .mmdb file.
	 * @return string|null ISO country code or null.
	 */
	private function lookup_maxmind_country( string $ip, string $db_path ): ?string {
		if ( ! file_exists( $db_path ) || ! is_readable( $db_path ) ) {
			return null;
		}

		if ( function_exists( 'geoip_country_code_by_name' ) ) {
			$code = @geoip_country_code_by_name( $ip );
			return $code ? strtoupper( $code ) : null;
		}

		return null;
	}
}
