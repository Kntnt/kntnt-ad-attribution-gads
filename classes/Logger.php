<?php
/**
 * Diagnostic logger for Google Ads API communication.
 *
 * Writes timestamped entries to a dedicated log file in wp-content/uploads/.
 * Logging is controlled by the `enable_logging` setting. Sensitive values
 * (client_secret, refresh_token, access tokens) are masked to reveal only
 * the last 4 characters.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   1.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution_Gads;

/**
 * File-based diagnostic logger with size rotation and credential masking.
 *
 * @since 1.4.0
 */
final class Logger {

	/**
	 * Log directory name inside the uploads folder.
	 *
	 * @var string
	 * @since 1.4.0
	 */
	public const DIR_NAME = 'kntnt-ad-attr-gads';

	/**
	 * Log filename.
	 *
	 * @var string
	 * @since 1.4.0
	 */
	private const FILE_NAME = 'kntnt-ad-attr-gads.log';

	/**
	 * Maximum log file size in bytes (500 KB).
	 *
	 * @var int
	 * @since 1.4.0
	 */
	private const MAX_SIZE = 512_000;

	/**
	 * Bytes to keep when trimming an oversized log file (~250 KB).
	 *
	 * @var int
	 * @since 1.4.0
	 */
	private const TRIM_KEEP = 256_000;

	/**
	 * Settings instance for checking the enable_logging flag.
	 *
	 * @var Settings
	 * @since 1.4.0
	 */
	private readonly Settings $settings;

	/**
	 * Creates the logger with a Settings dependency.
	 *
	 * @param Settings $settings Plugin settings instance.
	 *
	 * @since 1.4.0
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Logs an informational message.
	 *
	 * @param string $message Log message.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function info( string $message ): void {
		$this->write( 'INFO', $message );
	}

	/**
	 * Logs an error message.
	 *
	 * @param string $message Log message.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function error( string $message ): void {
		$this->write( 'ERROR', $message );
	}

	/**
	 * Masks a string, revealing only the last 4 characters.
	 *
	 * @param string $value String to mask.
	 *
	 * @return string Masked string (e.g. "****hA_Z") or empty if input is empty.
	 * @since 1.4.0
	 */
	public static function mask( string $value ): string {
		$visible = 4;
		$length  = strlen( $value );

		if ( $length === 0 ) {
			return '';
		}

		if ( $length <= $visible ) {
			return str_repeat( '*', $length );
		}

		return str_repeat( '*', $length - $visible ) . substr( $value, -$visible );
	}

	/**
	 * Returns the absolute path to the log file.
	 *
	 * @return string Full filesystem path.
	 * @since 1.4.0
	 */
	public function get_path(): string {
		return $this->get_dir() . '/' . self::FILE_NAME;
	}

	/**
	 * Returns the log file path relative to ABSPATH.
	 *
	 * @return string Relative path suitable for display.
	 * @since 1.4.0
	 */
	public function get_relative_path(): string {
		return str_replace( ABSPATH, '', $this->get_path() );
	}

	/**
	 * Reads the entire log file contents.
	 *
	 * @return string Log contents or empty string if the file doesn't exist.
	 * @since 1.4.0
	 */
	public function get_contents(): string {
		$path = $this->get_path();
		return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/**
	 * Deletes the log file.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function clear(): void {
		$path = $this->get_path();
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Checks whether the log file exists.
	 *
	 * @return bool True if the log file exists.
	 * @since 1.4.0
	 */
	public function exists(): bool {
		return file_exists( $this->get_path() );
	}

	/**
	 * Returns the absolute path to the log directory.
	 *
	 * @return string Full filesystem path to the log directory.
	 * @since 1.4.0
	 */
	private function get_dir(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/' . self::DIR_NAME;
	}

	/**
	 * Writes a timestamped entry to the log file.
	 *
	 * No-ops when logging is disabled. Trims the file when it exceeds
	 * the size limit, keeping approximately the last 250 KB.
	 *
	 * @param string $level   Log level (INFO or ERROR).
	 * @param string $message Log message.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function write( string $level, string $message ): void {

		// No-op when logging is disabled.
		if ( ! $this->settings->get( 'enable_logging' ) ) {
			return;
		}

		$path = $this->get_path();
		$dir  = dirname( $path );

		// Ensure the directory exists.
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Trim the file if it exceeds the size limit.
		if ( file_exists( $path ) && filesize( $path ) > self::MAX_SIZE ) {
			$this->trim( $path );
		}

		// Format: [2026-02-26 14:30:00+01:00] INFO Message
		$timestamp = wp_date( 'Y-m-d H:i:sP' );
		$line      = "[{$timestamp}] {$level} {$message}" . PHP_EOL;

		file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Trims the log file to approximately TRIM_KEEP bytes.
	 *
	 * Reads the tail of the file and cuts at the nearest line boundary
	 * to avoid partial lines.
	 *
	 * @param string $path Absolute path to the log file.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function trim( string $path ): void {
		$contents = file_get_contents( $path );

		if ( $contents === false ) {
			return;
		}

		// Keep the last ~250 KB.
		$tail = substr( $contents, -self::TRIM_KEEP );

		// Cut at the first newline to avoid a partial opening line.
		$first_newline = strpos( $tail, "\n" );
		if ( $first_newline !== false ) {
			$tail = substr( $tail, $first_newline + 1 );
		}

		file_put_contents( $path, $tail, LOCK_EX );
	}

}
