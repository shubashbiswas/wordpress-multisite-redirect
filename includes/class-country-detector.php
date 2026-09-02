<?php

namespace GRR;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Country_Detector
 * Handles country identification using multiple fallback sources with in-memory caching and manual user preference persistence.
 */
class Country_Detector
{

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
	public function __construct(Logger $logger)
	{
		$this->logger = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void
	{
		add_action('init', array($this, 'process_early_cookies'), 1);
	}

	/**
	 * Process early cookies for test mode and manual visitor selection before headers are sent.
	 */
	public function process_early_cookies(): void
	{
		if (headers_sent()) {
			return;
		}

		if (isset($_GET['grr_test_country']) && current_user_can('manage_network_options')) {
			$raw_val = sanitize_text_field(wp_unslash($_GET['grr_test_country']));
			if ('reset' === strtolower($raw_val)) {
				setcookie('grr_admin_test_country', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
				unset($_COOKIE['grr_admin_test_country']);
			} else {
				$test_country = strtoupper($raw_val);
				if (preg_match('/^[A-Z]{2}$/', $test_country)) {
					setcookie('grr_admin_test_country', $test_country, time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
					$_COOKIE['grr_admin_test_country'] = $test_country;
				}
			}
		}

		if (isset($_GET['grr_set_country'])) {
			$options     = get_site_option('grr_options', array());
			$persistence = $options['cookie_persistence'] ?? '7d';
			$ttl_days    = 7;
			if ( '24h' === $persistence ) {
				$ttl_days = 1;
			} elseif ( '30d' === $persistence ) {
				$ttl_days = 30;
			} elseif ( 'session' === $persistence || 'disabled' === $persistence ) {
				$ttl_days = 0;
			}

			$set_country = strtoupper(sanitize_text_field(wp_unslash($_GET['grr_set_country'])));
			if ('RESET' === $set_country) {
				setcookie('grr_user_manual_country', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
				unset($_COOKIE['grr_user_manual_country']);
			} elseif (preg_match('/^[A-Z]{2,6}$/', $set_country)) {
				$expiry = ($ttl_days > 0) ? time() + ($ttl_days * DAY_IN_SECONDS) : 0;
				setcookie('grr_user_manual_country', $set_country, $expiry, COOKIEPATH, COOKIE_DOMAIN);
				$_COOKIE['grr_user_manual_country'] = $set_country;
			}
		}
	}

	/**
	 * Detect visitor country code.
	 *
	 * @return array Array containing ['country' => 'BD', 'source' => 'Cloudflare CF-IPCountry']
	 */
	public function detect_country(): array
	{
		$options = get_site_option('grr_options', array());

		// Priority 1: Admin Test Mode Override (?grr_test_country=BD or cookie)
		if (isset($_GET['grr_test_country']) && current_user_can('manage_network_options')) {
			$raw_val = sanitize_text_field(wp_unslash($_GET['grr_test_country']));
			if ('reset' !== strtolower($raw_val)) {
				$test_country = strtoupper($raw_val);
				if (preg_match('/^[A-Z]{2}$/', $test_country)) {
					return array(
						'country' => $test_country,
						'source'  => 'Admin Test Mode Override',
					);
				}
			}
		}

		if (! empty($_COOKIE['grr_admin_test_country']) && current_user_can('manage_network_options')) {
			$test_country = strtoupper(sanitize_text_field(wp_unslash($_COOKIE['grr_admin_test_country'])));
			if (preg_match('/^[A-Z]{2}$/', $test_country)) {
				return array(
					'country' => $test_country,
					'source'  => 'Admin Test Mode Override (Active)',
				);
			}
		}

		// Priority 2: Manual Visitor Selection (?grr_set_country=BD or grr_user_manual_country Cookie)
		if (isset($_GET['grr_set_country'])) {
			$set_country = strtoupper(sanitize_text_field(wp_unslash($_GET['grr_set_country'])));
			if ('RESET' !== $set_country && preg_match('/^[A-Z]{2,6}$/', $set_country)) {
				return array(
					'country' => $set_country,
					'source'  => 'Visitor Manual Selection (?grr_set_country)',
				);
			}
		}

		if (! empty($_COOKIE['grr_user_manual_country'])) {
			$manual_country = strtoupper(sanitize_text_field(wp_unslash($_COOKIE['grr_user_manual_country'])));
			if (preg_match('/^[A-Z]{2,6}$/', $manual_country)) {
				return array(
					'country' => $manual_country,
					'source'  => 'Visitor Saved Manual Preference (Cookie)',
				);
			}
		}

		// Priority 2.5: Saved Visitor Country Cookie (grr_visitor_country)
		if (! empty($_COOKIE['grr_visitor_country'])) {
			$cookie_country = strtoupper(sanitize_text_field(wp_unslash($_COOKIE['grr_visitor_country'])));
			if (preg_match('/^[A-Z]{2}$/', $cookie_country)) {
				return array(
					'country' => $cookie_country,
					'source'  => 'Visitor Country Cookie (grr_visitor_country)',
				);
			}
		}

		$client_ip = $this->get_client_ip();

		// Check Object Cache for IP lookup
		$cache_key     = 'grr_geoip_' . md5($client_ip);
		$cached_result = wp_cache_get($cache_key, 'grr_geoip');
		if (false !== $cached_result && is_array($cached_result)) {
			return $cached_result;
		}

		$trusted_proxies  = trim((string) ($options['trusted_proxies'] ?? ''));
		$is_trusted_proxy = $this->verify_trusted_proxy($client_ip, $trusted_proxies);

		$detected = null;

		// Priority 3: Cloudflare CF-IPCountry
		$use_cf = ! empty($options['country_source_cf']);
		if ($use_cf && ! empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
			$cf_country = strtoupper(sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY'])));
			if ('XX' !== $cf_country && 'T1' !== $cf_country && preg_match('/^[A-Z]{2}$/', $cf_country)) {
				$detected = array(
					'country' => $cf_country,
					'source'  => 'Cloudflare (CF-IPCountry)',
				);
			}
		}

		// Priority 4: Configured Custom Header
		if (null === $detected && ! empty($options['country_source_header']) && $is_trusted_proxy) {
			$header_name = trim((string) ($options['country_custom_header_name'] ?? 'HTTP_X_GEOIP_COUNTRY'));
			$server_key  = strtoupper(str_replace('-', '_', $header_name));
			if (0 !== strpos($server_key, 'HTTP_') && 'REMOTE_ADDR' !== $server_key) {
				$server_key = 'HTTP_' . $server_key;
			}

			if (! empty($_SERVER[$server_key])) {
				$header_country = strtoupper(sanitize_text_field(wp_unslash($_SERVER[$server_key])));
				if (preg_match('/^[A-Z]{2}$/', $header_country)) {
					$detected = array(
						'country' => $header_country,
						'source'  => 'Custom HTTP Header (' . $header_name . ')',
					);
				}
			}
		}

		// Priority 5: MaxMind GeoIP Local Database
		if (null === $detected) {
			$maxmind_db = trim((string) ($options['maxmind_db_path'] ?? ''));
			if (empty($maxmind_db) || ! file_exists($maxmind_db)) {
				$bundled_path = plugin_dir_path(dirname(__FILE__)) . 'assets/GeoLite2/GeoLite2-Country.mmdb';
				if (file_exists($bundled_path)) {
					$maxmind_db = $bundled_path;
				}
			}

			if (! empty($maxmind_db) && file_exists($maxmind_db) && is_readable($maxmind_db)) {
				$maxmind_country = $this->lookup_maxmind_country($client_ip, $maxmind_db);
				if ($maxmind_country && preg_match('/^[A-Z]{2}$/', $maxmind_country)) {
					$detected = array(
						'country' => $maxmind_country,
						'source'  => 'MaxMind GeoIP Database',
					);
				}
			}
		}

		// Fallback
		if (null === $detected) {
			$detected = array(
				'country' => 'UNKNOWN',
				'source'  => 'Unknown / Fallback',
			);
		}

		// Cache in memory only if valid country
		if ('UNKNOWN' !== $detected['country']) {
			wp_cache_set($cache_key, $detected, 'grr_geoip', 3600);
		}

		return $detected;
	}

	/**
	 * Get visitor IP address safely (supports Cloudflare and reverse proxies).
	 *
	 * @return string
	 */
	public function get_client_ip(): string
	{
		if (! empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			$ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
			if (filter_var($ip, FILTER_VALIDATE_IP)) {
				return $ip;
			}
		}

		if (! empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ips       = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			$client_ip = trim(wp_unslash($ips[0]));
			if (filter_var($client_ip, FILTER_VALIDATE_IP)) {
				return sanitize_text_field($client_ip);
			}
		}

		if (! empty($_SERVER['HTTP_X_REAL_IP'])) {
			$ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_REAL_IP']));
			if (filter_var($ip, FILTER_VALIDATE_IP)) {
				return $ip;
			}
		}

		$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
		return sanitize_text_field(wp_unslash($ip));
	}

	/**
	 * Verify if client IP is from a trusted proxy (or if trusted proxies setting is empty).
	 *
	 * @param string $ip Client IP.
	 * @param string $trusted_proxies_str Comma or newline separated list of trusted IPs or CIDR blocks.
	 * @return bool
	 */
	private function verify_trusted_proxy(string $ip, string $trusted_proxies_str): bool
	{
		if (empty($trusted_proxies_str)) {
			return true;
		}

		$lines = preg_split('/[\s,]+/', $trusted_proxies_str);
		foreach ($lines as $line) {
			$line = trim($line);
			if (empty($line)) {
				continue;
			}

			if (strpos($line, '/') !== false) {
				if ($this->ip_in_cidr($ip, $line)) {
					return true;
				}
			} else {
				if ($ip === $line) {
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
	private function ip_in_cidr(string $ip, string $cidr): bool
	{
		list($subnet, $bits) = array_pad(explode('/', $cidr, 2), 2, null);
		if (null === $bits) {
			return $ip === $subnet;
		}

		$ip_binary     = @inet_pton($ip);
		$subnet_binary = @inet_pton($subnet);

		if (false === $ip_binary || false === $subnet_binary) {
			return false;
		}

		if (strlen($ip_binary) !== strlen($subnet_binary)) {
			return false;
		}

		$bits = (int) $bits;
		$bytes = (int) ($bits / 8);
		$remainder_bits = $bits % 8;

		if ($bytes > 0 && substr($ip_binary, 0, $bytes) !== substr($subnet_binary, 0, $bytes)) {
			return false;
		}

		if ($remainder_bits > 0) {
			$mask = (0xFF << (8 - $remainder_bits)) & 0xFF;
			$ip_byte = ord($ip_binary[$bytes]);
			$subnet_byte = ord($subnet_binary[$bytes]);
			if (($ip_byte & $mask) !== ($subnet_byte & $mask)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Native pure-PHP MaxMind MMDB binary reader for Country ISO Code.
	 *
	 * @param string $ip IP Address.
	 * @param string $db_path Absolute path to .mmdb file.
	 * @return string|null ISO country code or null.
	 */
	private function lookup_maxmind_country(string $ip, string $db_path): ?string
	{
		if (! file_exists($db_path) || ! is_readable($db_path)) {
			return null;
		}

		$handle = @fopen($db_path, 'rb');
		if (! $handle) {
			return null;
		}

		$file_size = filesize($db_path);
		$read_len  = min(20480, $file_size);
		fseek($handle, $file_size - $read_len);
		$buf = fread($handle, $read_len);

		$marker = "\xab\xcd\xefMaxMind.com";
		$pos    = strrpos($buf, $marker);
		if (false === $pos) {
			fclose($handle);
			return null;
		}

		$meta_offset = $file_size - $read_len + $pos + strlen($marker);
		fseek($handle, $meta_offset);

		$data_section_offset = 0;
		$meta_res            = $this->mmdb_decode_data($handle, $meta_offset, $data_section_offset);
		$meta                = $meta_res['value'] ?? array();

		$node_count          = $meta['node_count'] ?? 0;
		$record_size         = $meta['record_size'] ?? 24;
		$search_tree_size    = ($node_count * $record_size * 2) / 8;
		$data_section_offset = $search_tree_size + 16;

		$packed = @inet_pton($ip);
		if (false === $packed) {
			fclose($handle);
			return null;
		}

		if (4 === strlen($packed)) {
			$packed = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . $packed;
		}

		$node_num   = 0;
		$total_bits = strlen($packed) * 8;
		$result     = null;

		for ($i = 0; $i < $total_bits; $i++) {
			$byte_idx = (int) ($i / 8);
			$bit_idx  = 7 - ($i % 8);
			$bit      = (ord($packed[$byte_idx]) >> $bit_idx) & 1;

			list($left, $right) = $this->mmdb_read_node($handle, $node_num, $record_size);
			$next = $bit ? $right : $left;

			if ($next >= $node_count) {
				if ($next > $node_count) {
					$data_offset = $data_section_offset + ($next - $node_count - 16);
					$res         = $this->mmdb_decode_data($handle, $data_offset, $data_section_offset);
					$data        = $res['value'] ?? null;
					if (is_array($data)) {
						if (isset($data['country']['iso_code'])) {
							$result = strtoupper((string) $data['country']['iso_code']);
						} elseif (isset($data['registered_country']['iso_code'])) {
							$result = strtoupper((string) $data['registered_country']['iso_code']);
						} elseif (isset($data['represented_country']['iso_code'])) {
							$result = strtoupper((string) $data['represented_country']['iso_code']);
						} elseif (isset($data['continent']['code'])) {
							$result = strtoupper((string) $data['continent']['code']);
						}
					}
				}
				break;
			}
			$node_num = $next;
		}

		fclose($handle);
		return $result;
	}

	/**
	 * Decode MMDB data structures.
	 */
	private function mmdb_decode_data($handle, int $offset, int $data_section_offset): array
	{
		fseek($handle, $offset);
		$char = fgetc($handle);
		if (false === $char) {
			return array('value' => null, 'offset' => $offset);
		}
		$ctrl   = ord($char);
		$offset++;

		$type = $ctrl >> 5;
		$size = $ctrl & 0x1f;

		if (1 === $type) {
			$p_size = ($ctrl >> 3) & 0x03;
			if (0 === $p_size) {
				$b1 = ord(fgetc($handle));
				$offset++;
				$pointer_val = (($ctrl & 0x07) << 8) + $b1;
			} elseif (1 === $p_size) {
				$b1 = ord(fgetc($handle));
				$b2 = ord(fgetc($handle));
				$offset += 2;
				$pointer_val = (($ctrl & 0x07) << 16) + ($b1 << 8) + $b2 + 2048;
			} elseif (2 === $p_size) {
				$b1 = ord(fgetc($handle));
				$b2 = ord(fgetc($handle));
				$b3 = ord(fgetc($handle));
				$offset += 3;
				$pointer_val = (($ctrl & 0x07) << 24) + ($b1 << 16) + ($b2 << 8) + $b3 + 526336;
			} else {
				$bytes       = fread($handle, 4);
				$offset     += 4;
				$unpacked    = unpack('N', $bytes);
				$pointer_val = $unpacked[1] ?? 0;
			}
			$target = $data_section_offset + $pointer_val;
			$res    = $this->mmdb_decode_data($handle, $target, $data_section_offset);
			return array('value' => $res['value'], 'offset' => $offset);
		}

		if (0 === $type) {
			$ext_type = ord(fgetc($handle));
			$offset++;
			$type = $ext_type + 7;
		}

		if ($size >= 29) {
			$extra_bytes = $size - 28;
			$bytes       = fread($handle, $extra_bytes);
			$offset     += $extra_bytes;
			if (1 === $extra_bytes) {
				$size = ord($bytes) + 29;
			} elseif (2 === $extra_bytes) {
				$unpacked = unpack('n', $bytes);
				$size     = ($unpacked[1] ?? 0) + 285;
			} elseif (3 === $extra_bytes) {
				$unpacked = unpack('N', "\x00" . $bytes);
				$size     = ($unpacked[1] ?? 0) + 65821;
			}
		}

		switch ($type) {
			case 2:
				$val = $size > 0 ? fread($handle, $size) : '';
				$offset += $size;
				return array('value' => $val, 'offset' => $offset);
			case 4:
				$val = $size > 0 ? fread($handle, $size) : '';
				$offset += $size;
				return array('value' => $val, 'offset' => $offset);
			case 5:
			case 6:
			case 8:
			case 9:
			case 10:
				$val = 0;
				if ($size > 0) {
					$bytes   = fread($handle, $size);
					$offset += $size;
					for ($i = 0; $i < $size; $i++) {
						$val = ($val << 8) + ord($bytes[$i]);
					}
				}
				return array('value' => $val, 'offset' => $offset);
			case 7:
				$map = array();
				for ($i = 0; $i < $size; $i++) {
					$k_res  = $this->mmdb_decode_data($handle, $offset, $data_section_offset);
					$offset = $k_res['offset'];
					$v_res  = $this->mmdb_decode_data($handle, $offset, $data_section_offset);
					$offset = $v_res['offset'];
					if (null !== $k_res['value']) {
						$map[$k_res['value']] = $v_res['value'];
					}
				}
				return array('value' => $map, 'offset' => $offset);
			case 11:
				$arr = array();
				for ($i = 0; $i < $size; $i++) {
					$item_res = $this->mmdb_decode_data($handle, $offset, $data_section_offset);
					$offset   = $item_res['offset'];
					$arr[]    = $item_res['value'];
				}
				return array('value' => $arr, 'offset' => $offset);
			case 14:
				return array('value' => (0 !== $size), 'offset' => $offset);
			default:
				if ($size > 0) {
					fread($handle, $size);
					$offset += $size;
				}
				return array('value' => null, 'offset' => $offset);
		}
	}

	/**
	 * Read single node from MMDB search tree.
	 */
	private function mmdb_read_node($handle, int $node_num, int $record_size): array
	{
		if (24 === $record_size) {
			fseek($handle, $node_num * 6);
			$bytes = fread($handle, 6);
			$left  = (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
			$right = (ord($bytes[3]) << 16) | (ord($bytes[4]) << 8) | ord($bytes[5]);
			return array($left, $right);
		} elseif (28 === $record_size) {
			fseek($handle, $node_num * 7);
			$bytes  = fread($handle, 7);
			$middle = ord($bytes[3]);
			$left   = ((($middle >> 4) & 0x0f) << 24) | (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
			$right  = (($middle & 0x0f) << 24) | (ord($bytes[4]) << 16) | (ord($bytes[5]) << 8) | ord($bytes[6]);
			return array($left, $right);
		} elseif (32 === $record_size) {
			fseek($handle, $node_num * 8);
			$bytes = fread($handle, 8);
			$left  = unpack('N', substr($bytes, 0, 4))[1];
			$right = unpack('N', substr($bytes, 4, 4))[1];
			return array($left, $right);
		}
		return array(0, 0);
	}
}
