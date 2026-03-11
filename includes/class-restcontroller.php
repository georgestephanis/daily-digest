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
	 * Constructor.
	 *
	 * @param ProviderRegistry $provider_registry Provider registry.
	 * @param UserSettings     $user_settings     User settings.
	 * @param DigestService    $digest_service    Digest service.
	 */
	public function __construct( ProviderRegistry $provider_registry, UserSettings $user_settings, DigestService $digest_service ) {
		$this->provider_registry = $provider_registry;
		$this->user_settings     = $user_settings;
		$this->digest_service    = $digest_service;
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
	}

	/**
	 * Gets combined digest data.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_digest( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = \get_current_user_id();
		$days    = max( 1, (int) $request->get_param( 'days' ) );
		$items   = $this->digest_service->get_digest_for_user( $user_id, array( 'days' => $days ) );

		return new \WP_REST_Response(
			array(
				'items' => $items,
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
	 * Checks read permission for authenticated users.
	 *
	 * @return bool
	 */
	public function can_read(): bool {
		return \is_user_logged_in() && \current_user_can( 'read' );
	}
}
