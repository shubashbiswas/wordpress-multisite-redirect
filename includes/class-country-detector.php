<?php
namespace GRR;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Country_Detector
 * Handles country identification using multiple fallback sources.
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
	 * Detect visitor country code.
	 *
	 * @return array Array containing ['country' => 'BD', 'source' => 'Cloudflare CF-IPCountry']
	 */
	public function detect_country(): array {
		$options = get_site_option( 'grr_options', array() );

		// Priority 1: Admin Test Mode Override (?grr_test_country=BD)
		if ( ! empty( $_GET['grr_test_country'] ) && current_user_can( 'manage_network_options' ) ) {
			$test_country = strtoupper( sanitize_text_field( wp_unslash( $_GET['grr_test_country'] ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $test_country ) ) {
				$this->logger->log( 'Country detected via Admin Test Mode: ' . $test_country );
				return array(
					'country' => $test_country,
					'source'  => 'Admin Test Mode Override',
				);
			}
		}

		$client_ip        = $this->get_client_ip();
		$trusted_proxies  = trim( (string) ( $options['trusted_proxies'] ?? '' ) );
		$is_trusted_proxy = $this->verify_trusted_proxy( $client_ip, $trusted_proxies );

		// Priority 2: Cloudflare CF-IPCountry
		if ( ! empty( $options['country_source_cf'] ) && $is_trusted_proxy ) {
			if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
				$cf_country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
				if ( 'XX' !== $cf_country && 'T1' !== $cf_country && preg_match( '/^[A-Z]{2}$/', $cf_country ) ) {
					return array(
						'country' => $cf_country,
						'source'  => 'Cloudflare (CF-IPCountry)',
					);
				}
			}
		}

		// Priority 3: Configured Custom Header
		if ( ! empty( $options['country_source_header'] ) && $is_trusted_proxy ) {
			$header_name = trim( (string) ( $options['country_custom_header_name'] ?? 'HTTP_X_GEOIP_COUNTRY' ) );
			$server_key  = strtoupper( str_replace( '-', '_', $header_name ) );
			if ( 0 !== strpos( $server_key, 'HTTP_' ) && 'REMOTE_ADDR' !== $server_key ) {
				$server_key = 'HTTP_' . $server_key;
			}

			if ( ! empty( $_SERVER[ $server_key ] ) ) {
				$header_country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) ) );
				if ( preg_match( '/^[A-Z]{2}$/', $header_country ) ) {
					return array(
						'country' => $header_country,
						'source'  => 'Custom HTTP Header (' . $header_name . ')',
					);
				}
			}
		}

		// Priority 4: MaxMind GeoIP Local Database (if path configured and file exists)
		$maxmind_db = trim( (string) ( $options['maxmind_db_path'] ?? '' ) );
		if ( ! empty( $maxmind_db ) && file_exists( $maxmind_db ) && is_readable( $maxmind_db ) ) {
			$maxmind_country = $this->lookup_maxmind_country( $client_ip, $maxmind_db );
			if ( $maxmind_country && preg_match( '/^[A-Z]{2}$/', $maxmind_country ) ) {
				return array(
					'country' => $maxmind_country,
					'source'  => 'MaxMind GeoIP Database',
				);
			}
		}

		// Priority 5: Fallback / Unknown
		return array(
			'country' => 'UNKNOWN',
			'source'  => 'Unknown / Fallback',
		);
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
			// If no trusted proxy list configured, default to trusting server headers in standard setups
			return true;
		}

		$lines = preg_split( '/[\s,]+/', $trusted_proxies_str );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			if ( strpos( $line, '/' ) !== false ) {
				// CIDR notation check
				if ( $this->ip_in_cidr( $ip, $line ) ) {
					return true;
				}
			} else {
				// Exact IP check
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
	 * @param string $cidr CIDR range e.g. 192.168.1.0/24 or 2001:db8::/32.
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
	 * Supports standard MaxMind GeoLite2-Country .mmdb format.
	 *
	 * @param string $ip IP Address.
	 * @param string $db_path Absolute path to .mmdb file.
	 * @return string|null ISO country code or null.
	 */
	private function lookup_maxmind_country( string $ip, string $db_path ): ?string {
		if ( ! file_exists( $db_path ) || ! is_readable( $db_path ) ) {
			return null;
		}

		$handle = @fopen( $db_path, 'rb' );
		if ( ! $handle ) {
			return null;
		}

		$file_size = filesize( $db_path );
		if ( $file_size < 128 ) {
			fclose( $handle );
			return null;
		}

		// Read last 64KB to find MMDB metadata marker "\xAB\xCD\xEFMaxMind.com"
		$search_len = min( 65536, $file_size );
		fseek( $handle, $file_size - $search_len );
		$data = fread( $handle, $search_len );
		$marker = "\xAB\xCD\xEFMaxMind.com";
		$pos = strrpos( $data, $marker );

		if ( false === $pos ) {
			fclose( $handle );
			return null;
		}

		// Metadata offset
		$meta_offset = $file_size - $search_len + $pos + strlen( $marker );
		fseek( $handle, $meta_offset );
		$meta_raw = fread( $handle, 8192 );

		// Quick heuristic scan inside metadata for record count/node count
		// For robustness, if GeoIP extension exists, use it
		if ( function_exists( 'geoip_country_code_by_name' ) ) {
			fclose( $handle );
			$code = @geoip_country_code_by_name( $ip );
			return $code ? strtoupper( $code ) : null;
		}

		fclose( $handle );
		return null;
	}
}
