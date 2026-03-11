<?php
/**
 * API logger class.
 *
 * @package DailyDigest
 */

declare(strict_types=1);

namespace DailyDigest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs provider HTTP requests and responses.
 */
class ApiLogger {
	/**
	 * Option key for logging toggle.
	 *
	 * @var string
	 */
	private string $logging_option_key = 'daily_digest_enable_logging';

	/**
	 * Uploads subdirectory for log files.
	 *
	 * @var string
	 */
	private string $log_directory = 'daily-digest-logs';

	/**
	 * Registers logging hooks.
	 */
	public function register_hooks(): void {
		\add_action( 'http_api_debug', array( $this, 'handle_http_api_debug' ), 10, 5 );
	}

	/**
	 * Returns whether logging is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) \get_option( $this->logging_option_key, false );
	}

	/**
	 * Updates logging enabled state.
	 *
	 * @param bool $enabled Whether logging should be enabled.
	 */
	public function update_enabled( bool $enabled ): void {
		\update_option( $this->logging_option_key, $enabled ? '1' : '0' );
	}

	/**
	 * Returns full log directory path.
	 *
	 * @return string
	 */
	public function get_log_directory_path(): string {
		$upload_dir = \wp_upload_dir();
		$base_dir   = $upload_dir['basedir'] ?? '';

		if ( empty( $base_dir ) ) {
			return '';
		}

		return \trailingslashit( $base_dir ) . $this->log_directory;
	}

	/**
	 * Returns log directory URL.
	 *
	 * @return string
	 */
	public function get_log_directory_url(): string {
		$upload_dir = \wp_upload_dir();
		$base_url   = $upload_dir['baseurl'] ?? '';

		if ( empty( $base_url ) ) {
			return '';
		}

		return \trailingslashit( $base_url ) . $this->log_directory . '/';
	}

	/**
	 * Handles HTTP API debug callback and writes provider logs.
	 *
	 * @param mixed  $response Response or request data.
	 * @param string $context  Context type.
	 * @param string $transport_class Transport class.
	 * @param array  $args     Request arguments.
	 * @param string $url      Request URL.
	 */
	public function handle_http_api_debug( $response, string $context, string $transport_class, array $args, string $url ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$provider_slug = ProviderExecutionContext::current_provider();
		if ( empty( $provider_slug ) ) {
			return;
		}

		if ( 'request' !== $context && 'response' !== $context ) {
			return;
		}

		$payload = array(
			'timestamp'       => \gmdate( 'c' ),
			'context'         => $context,
			'url'             => $url,
			'transport_class' => $transport_class,
			'args'            => $args,
			'data'            => $this->normalize_response_data( $response ),
		);

		$this->append_log_entry( $provider_slug, $payload );
	}

	/**
	 * Normalizes HTTP debug response data for logging.
	 *
	 * @param mixed $response Response data.
	 *
	 * @return mixed
	 */
	private function normalize_response_data( $response ) {
		if ( \is_wp_error( $response ) ) {
			return array(
				'error_code'     => $response->get_error_code(),
				'error_messages' => $response->get_error_messages(),
				'error_data'     => $response->get_all_error_data(),
			);
		}

		if ( \is_array( $response ) || \is_scalar( $response ) || null === $response ) {
			return $response;
		}

		return (array) $response;
	}

	/**
	 * Appends one log line to the provider log file.
	 *
	 * @param string $provider_slug Provider slug.
	 * @param array  $payload       Log payload.
	 */
	private function append_log_entry( string $provider_slug, array $payload ): void {
		$directory = $this->get_log_directory_path();
		if ( empty( $directory ) ) {
			return;
		}

		if ( ! \wp_mkdir_p( $directory ) ) {
			return;
		}

		$index_file = \trailingslashit( $directory ) . 'index.php';
		if ( ! \file_exists( $index_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Append-only local debug log file.
			\file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		$provider_slug = \sanitize_key( $provider_slug );
		if ( empty( $provider_slug ) ) {
			$provider_slug = 'unknown';
		}

		$log_file = \trailingslashit( $directory ) . $provider_slug . '.log';
		$line     = \wp_json_encode( $payload );

		if ( false === $line ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Append-only local debug log file.
		\file_put_contents( $log_file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}
}
