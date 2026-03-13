<?php
/**
 * REST controller class.
 *
 * @package DailyDigest
 */

declare(strict_types=1);

namespace DailyDigest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles Daily Digest REST endpoints.
 */
class RestController {
	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private ProviderRegistry $provider_registry;

	/**
	 * User settings service.
	 *
	 * @var UserSettings
	 */
	private UserSettings $user_settings;

	/**
	 * Digest service.
	 *
	 * @var DigestService
	 */
	private DigestService $digest_service;

	/**
	 * API logger service.
	 *
	 * @var ApiLogger
	 */
	private ApiLogger $api_logger;

	/**
	 * Keyring connection manager.
	 *
	 * @var KeyringConnectionManager
	 */
	private KeyringConnectionManager $keyring_connections;

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry $provider_registry Provider registry.
	 * @param UserSettings     $user_settings     User settings.
	 * @param DigestService    $digest_service    Digest service.
	 * @param ApiLogger                $api_logger          API logger service.
	 * @param KeyringConnectionManager $keyring_connections Keyring connection manager.
	 */
	public function __construct( ProviderRegistry $provider_registry, UserSettings $user_settings, DigestService $digest_service, ApiLogger $api_logger, KeyringConnectionManager $keyring_connections ) {
		$this->provider_registry   = $provider_registry;
		$this->user_settings       = $user_settings;
		$this->digest_service      = $digest_service;
		$this->api_logger          = $api_logger;
		$this->keyring_connections = $keyring_connections;
	}

	/**
	 * Registers route hooks.
	 */
	public function register(): void {
		\add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers REST routes.
	 */
	public function register_routes(): void {
		\register_rest_route(
			'daily-digest/v1',
			'/digest',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_digest' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'days' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		\register_rest_route(
			'daily-digest/v1',
			'/providers/(?P<provider>[a-z0-9_-]+)/activity',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_provider_activity' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'days' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		\register_rest_route(
			'daily-digest/v1',
			'/providers/(?P<provider>[a-z0-9_-]+)/test-credentials',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_provider_credentials' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		\register_rest_route(
			'daily-digest/v1',
			'/providers/(?P<provider>[a-z0-9_-]+)/credentials',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_provider_credentials' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		\register_rest_route(
			'daily-digest/v1',
			'/providers/(?P<provider>[a-z0-9_-]+)/disconnect',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'disconnect_provider_connection' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		\register_rest_route(
			'daily-digest/v1',
			'/logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_logs' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'provider' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'limit'    => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Returns parsed log entries from provider log files.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_logs( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_slug = \sanitize_key( (string) $request->get_param( 'provider' ) );
		$limit         = (int) $request->get_param( 'limit' );

		if ( $limit < 1 ) {
			$limit = 300;
		}

		if ( $limit > 2000 ) {
			$limit = 2000;
		}

		$entries = array();
		$files   = $this->get_log_files( $provider_slug );

		foreach ( $files as $file_path ) {
			if ( ! \is_readable( $file_path ) ) {
				continue;
			}

			$provider_from_file = \sanitize_key( (string) \pathinfo( $file_path, PATHINFO_FILENAME ) );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- Reading plugin-owned debug logs from uploads.
			$lines = \file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( ! \is_array( $lines ) ) {
				continue;
			}

			foreach ( $lines as $line ) {
				$decoded = \json_decode( (string) $line, true );
				if ( ! \is_array( $decoded ) ) {
					continue;
				}

				$entries[] = $this->normalize_log_entry( $provider_from_file, $decoded );
			}
		}

		\usort(
			$entries,
			static function ( array $left, array $right ): int {
				return \strcmp( (string) ( $right['timestamp'] ?? '' ), (string) ( $left['timestamp'] ?? '' ) );
			}
		);

		$total_count = \count( $entries );
		$entries     = \array_slice( $entries, 0, $limit );

		return new \WP_REST_Response(
			array(
				'items' => $entries,
				'total' => $total_count,
			),
			200
		);
	}

	/**
	 * Normalizes one raw log entry for UI rendering.
	 *
	 * @param string               $provider_slug Provider slug from file name.
	 * @param array<string, mixed> $payload       Raw log payload.
	 *
	 * @return array<string, string>
	 */
	private function normalize_log_entry( string $provider_slug, array $payload ): array {
		$args             = isset( $payload['args'] ) && \is_array( $payload['args'] ) ? $payload['args'] : array();
		$data             = isset( $payload['data'] ) && \is_array( $payload['data'] ) ? $payload['data'] : array();
		$method           = isset( $args['method'] ) ? \sanitize_text_field( (string) $args['method'] ) : '';
		$status           = '';
		$summary          = '';
		$response_summary = $this->build_response_summary( $data );

		if ( isset( $data['response'] ) && \is_array( $data['response'] ) ) {
			if ( isset( $data['response']['code'] ) ) {
				$status = (string) \absint( $data['response']['code'] );
			}

			if ( isset( $data['response']['message'] ) ) {
				$summary = \sanitize_text_field( (string) $data['response']['message'] );
			}
		}

		if ( isset( $data['error_messages'] ) && \is_array( $data['error_messages'] ) ) {
			$errors  = \array_map( 'sanitize_text_field', $data['error_messages'] );
			$summary = \implode( ' | ', $errors );
		}

		if ( empty( $summary ) && isset( $payload['context'] ) ) {
			$summary = \sanitize_text_field( (string) $payload['context'] );
		}

		return array(
			'timestamp'        => \sanitize_text_field( (string) ( $payload['timestamp'] ?? '' ) ),
			'provider'         => \sanitize_text_field( $provider_slug ),
			'context'          => \sanitize_text_field( (string) ( $payload['context'] ?? '' ) ),
			'method'           => $method,
			'status'           => $status,
			'url'              => \esc_url_raw( (string) ( $payload['url'] ?? '' ) ),
			'summary'          => $summary,
			'response_summary' => $response_summary,
		);
	}

	/**
	 * Builds a concise response summary from raw HTTP data.
	 *
	 * @param array<string, mixed> $data HTTP debug data payload.
	 *
	 * @return string
	 */
	private function build_response_summary( array $data ): string {
		if ( isset( $data['error_messages'] ) && \is_array( $data['error_messages'] ) ) {
			$errors = \array_map( 'sanitize_text_field', $data['error_messages'] );
			return \implode( ' | ', $errors );
		}

		if ( ! isset( $data['body'] ) || ! \is_string( $data['body'] ) ) {
			return '';
		}

		$body = \trim( $data['body'] );
		if ( '' === $body ) {
			return '';
		}

		$decoded_json = \json_decode( $body, true );
		if ( \is_array( $decoded_json ) ) {
			if ( isset( $decoded_json['message'] ) && \is_scalar( $decoded_json['message'] ) ) {
				return \sanitize_text_field( (string) $decoded_json['message'] );
			}

			if ( isset( $decoded_json['error'] ) && \is_scalar( $decoded_json['error'] ) ) {
				return \sanitize_text_field( (string) $decoded_json['error'] );
			}

			$keys = \array_slice( \array_keys( $decoded_json ), 0, 6 );
			if ( ! empty( $keys ) ) {
				/* translators: %s: comma-separated JSON keys. */
				return \sprintf( \__( 'JSON keys: %s', 'daily-digest' ), \sanitize_text_field( \implode( ', ', $keys ) ) );
			}
		}

		$normalized = \preg_replace( '/\s+/', ' ', \wp_strip_all_tags( $body ) );
		if ( ! \is_string( $normalized ) ) {
			$normalized = $body;
		}

		$max_length = 180;
		if ( \strlen( $normalized ) > $max_length ) {
			return \sanitize_text_field( \substr( $normalized, 0, $max_length ) . '…' );
		}

		return \sanitize_text_field( $normalized );
	}

	/**
	 * Gets log file paths for one provider or all providers.
	 *
	 * @param string $provider_slug Optional provider slug filter.
	 *
	 * @return array<int, string>
	 */
	private function get_log_files( string $provider_slug = '' ): array {
		$directory = $this->api_logger->get_log_directory_path();
		if ( empty( $directory ) || ! \is_dir( $directory ) ) {
			return array();
		}

		if ( ! empty( $provider_slug ) ) {
			$file = \trailingslashit( $directory ) . $provider_slug . '.log';
			return \file_exists( $file ) ? array( $file ) : array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Reading plugin-owned debug logs from uploads.
		$files = \glob( \trailingslashit( $directory ) . '*.log' );

		if ( false === $files || ! \is_array( $files ) ) {
			return array();
		}

		return $files;
	}

	/**
	 * Gets combined digest data.
	 *
	 * The response includes a 'providers' map showing the connection health of
	 * each registered provider so the UI can distinguish "no activity" from
	 * "not connected".
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_digest( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id   = \get_current_user_id();
		$days      = max( 1, (int) $request->get_param( 'days' ) );
		$items     = $this->digest_service->get_digest_for_user( $user_id, array( 'days' => $days ) );
		$providers = array();

		foreach ( $this->provider_registry->all() as $slug => $provider ) {
			$connected          = $this->keyring_connections->has_connection( (string) $slug, $user_id );
			$providers[ $slug ] = array(
				'name'      => $provider->get_name(),
				'connected' => $connected,
				'error'     => $connected ? null : __( 'No connection found. Connect this provider on the Settings page.', 'daily-digest' ),
			);
		}

		return new \WP_REST_Response(
			array(
				'items'     => $items,
				'providers' => $providers,
			),
			200
		);
	}

	/**
	 * Gets activity for a single provider.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_provider_activity( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_slug = \sanitize_key( (string) $request->get_param( 'provider' ) );
		$provider      = $this->provider_registry->get( $provider_slug );

		if ( null === $provider ) {
			return new \WP_REST_Response(
				array(
					'message' => __( 'Provider not found.', 'daily-digest' ),
				),
				404
			);
		}

		$user_id = \get_current_user_id();
		$days    = max( 1, (int) $request->get_param( 'days' ) );
		$items   = $this->digest_service->get_provider_digest_for_user( $user_id, $provider_slug, array( 'days' => $days ) );

		return new \WP_REST_Response(
			array(
				'provider' => $provider_slug,
				'items'    => $items,
			),
			200
		);
	}

	/**
	 * Tests credentials for one provider.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function test_provider_credentials( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_slug = \sanitize_key( (string) $request->get_param( 'provider' ) );
		$provider      = $this->provider_registry->get( $provider_slug );

		if ( null === $provider ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Provider not found.', 'daily-digest' ),
				),
				404
			);
		}

		$fields = $request->get_param( 'fields' );
		if ( ! \is_array( $fields ) ) {
			$settings = $this->user_settings->get_for_user( \get_current_user_id() );
			$fields   = isset( $settings[ $provider_slug ]['fields'] ) && \is_array( $settings[ $provider_slug ]['fields'] ) ? $settings[ $provider_slug ]['fields'] : array();
		}

		$results = $provider->test_credentials( $fields );
		$status  = ! empty( $results['success'] ) ? 200 : 400;

		return new \WP_REST_Response( $results, $status );
	}

	/**
	 * Saves credentials for one provider for the current user.
	 *
	 * Request body:
	 * - fields: array of provider field values.
	 * - enabled: optional bool.
	 * - test_after_save: optional bool, runs test_credentials and returns result.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function save_provider_credentials( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_slug = \sanitize_key( (string) $request->get_param( 'provider' ) );
		$provider      = $this->provider_registry->get( $provider_slug );

		if ( null === $provider ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Provider not found.', 'daily-digest' ),
				),
				404
			);
		}

		$fields = $request->get_param( 'fields' );
		if ( ! \is_array( $fields ) ) {
			$fields = array();
		}

		$allowed_fields  = array_keys( $provider->get_fields() );
		$filtered_fields = array();

		foreach ( $fields as $field_key => $field_value ) {
			$normalized_key = \sanitize_key( (string) $field_key );
			if ( ! in_array( $normalized_key, $allowed_fields, true ) ) {
				continue;
			}

			$filtered_fields[ $normalized_key ] = \sanitize_text_field( (string) $field_value );
		}

		$user_id       = \get_current_user_id();
		$user_settings = $this->user_settings->get_for_user( $user_id );
		$existing      = isset( $user_settings[ $provider_slug ] ) && \is_array( $user_settings[ $provider_slug ] ) ? $user_settings[ $provider_slug ] : array();

		if ( isset( $request['enabled'] ) ) {
			$enabled = (bool) $request->get_param( 'enabled' );
		} else {
			$enabled = ! empty( $existing['enabled'] );
		}

		$user_settings[ $provider_slug ] = array(
			'enabled' => $enabled,
			'fields'  => $filtered_fields,
		);

		$this->user_settings->save_for_user( $user_id, $user_settings );
		$this->digest_service->clear_provider_cache( $user_id, $provider_slug );

		$response_data = array(
			'success'  => true,
			'message'  => __( 'Provider credentials saved.', 'daily-digest' ),
			'provider' => $provider_slug,
			'enabled'  => $enabled,
			'fields'   => array_keys( $filtered_fields ),
		);

		if ( ! empty( $request->get_param( 'test_after_save' ) ) ) {
			$test_results             = $provider->test_credentials( $filtered_fields );
			$response_data['test']    = $test_results;
			$response_data['success'] = ! empty( $test_results['success'] );
			$response_data['message'] = ! empty( $test_results['message'] ) ? (string) $test_results['message'] : $response_data['message'];
			$status                   = $response_data['success'] ? 200 : 400;

			return new \WP_REST_Response( $response_data, $status );
		}

		return new \WP_REST_Response( $response_data, 200 );
	}

	/**
	 * Disconnects the current user from a provider in Keyring.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function disconnect_provider_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_slug = \sanitize_key( (string) $request->get_param( 'provider' ) );
		$provider      = $this->provider_registry->get( $provider_slug );

		if ( null === $provider ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Provider not found.', 'daily-digest' ),
				),
				404
			);
		}

		if ( ! $this->keyring_connections->is_available() ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Keyring is not available.', 'daily-digest' ),
				),
				400
			);
		}

		$user_id       = \get_current_user_id();
		$deleted_count = $this->keyring_connections->disconnect_user( $provider_slug, $user_id );
		$this->digest_service->clear_provider_cache( $user_id, $provider_slug );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Provider disconnected.', 'daily-digest' ),
				'deleted' => $deleted_count,
			),
			200
		);
	}

	/**
	 * Checks read permission for authenticated users.
	 *
	 * @return bool
	 */
	public function can_read(): bool {
		return \is_user_logged_in() && \current_user_can( 'read' );
	}

	/**
	 * Checks manage_options capability for admin-only endpoints.
	 *
	 * @return bool
	 */
	public function can_manage_options(): bool {
		return \is_user_logged_in() && \current_user_can( 'manage_options' );
	}
}
