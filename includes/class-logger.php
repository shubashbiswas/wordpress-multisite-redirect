<?php
namespace GRR;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger
 * Privacy-friendly debug logging system.
 */
class Logger {

	/**
	 * Log file absolute path.
	 *
	 * @var string
	 */
	private string $log_filepath;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$upload_dir         = wp_upload_dir();
		$this->log_filepath = trailingslashit( $upload_dir['basedir'] ) . 'geo-regional-router-debug.log';
	}

	/**
	 * Write a message to the debug log file if debug mode is enabled.
	 *
	 * @param string $message Log entry message.
	 */
	public function log( string $message ): void {
		$options = get_site_option( 'grr_options', array() );
		if ( empty( $options['debug_mode'] ) ) {
			return;
		}

		$this->ensure_directory_protection();

		$timestamp = current_time( 'mysql' );
		$entry     = sprintf( "[%s] %s\n", $timestamp, $this->sanitize_log_message( $message ) );

		@file_put_contents( $this->log_filepath, $entry, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Ensure upload folder has .htaccess protection against public HTTP browsing.
	 */
	private function ensure_directory_protection(): void {
		$dir = dirname( $this->log_filepath );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess_file = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			@file_put_contents( $htaccess_file, "Order deny,allow\nDeny from all\n<Files ~ \"^.*\">\n  Require all denied\n</Files>\n" );
		}

		$index_file = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, '' );
		}
	}

	/**
	 * Remove sensitive data patterns from log messages.
	 *
	 * @param string $message
	 * @return string
	 */
	private function sanitize_log_message( string $message ): string {
		// Strip IP addresses if any accidentally slipped into message
		$message = preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[REDACTED_IP]', $message );
		$message = preg_replace( '/\b[0-9a-fA-F]{1,4}:(?:[0-9a-fA-F]{1,4}:){1,7}[0-9a-fA-F]{1,4}\b/', '[REDACTED_IP]', $message );
		return $message;
	}

	/**
	 * Clear the debug log file.
	 *
	 * @return bool
	 */
	public function clear_log(): bool {
		if ( file_exists( $this->log_filepath ) ) {
			return @unlink( $this->log_filepath );
		}
		return false;
	}

	/**
	 * Get absolute file path to debug log.
	 *
	 * @return string
	 */
	public function get_log_filepath(): string {
		return $this->log_filepath;
	}
}
