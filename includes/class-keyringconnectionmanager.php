<?php
/**
 * Keyring connection manager.
 *
 * @package DailyDigest
 */

declare(strict_types=1);

namespace DailyDigest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Daily Digest provider connections stored in Keyring.
 */
class KeyringConnectionManager {
	/**
	 * Maps provider slugs to Keyring service names.
	 *
	 * Populated at boot time via register_service() so that each provider
	 * owns its own Keyring service name declaration.
	 *
	 * @var array<string, string>
	 */
	private array $service_map = array();

	/**
	 * Checks if Keyring is loaded.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return \class_exists( '\\Keyring' ) && \class_exists( '\\Keyring_Util' );
	}

	/**
	 * Registers a provider's Keyring service name.
	 *
	 * Called during plugin boot after all providers are registered so that
	 * providers declare their own service names rather than requiring a
	 * hardcoded map in this class.
	 *
	 * @param string $provider_slug Provider slug (e.g. 'clickup').
	 * @param string $service_name  Keyring service name (e.g. 'daily_digest_clickup').
	 *
	 * @return void
	 */
	public function register_service( string $provider_slug, string $service_name ): void {
		$this->service_map[ $provider_slug ] = $service_name;
	}

	/**
	 * Returns service name for a provider slug.
	 *
	 * @param string $provider_slug Provider slug.
	 *
	 * @return string
	 */
	public function get_service_name( string $provider_slug ): string {
		return $this->service_map[ $provider_slug ] ?? '';
	}

	/**
	 * Returns URL for connecting a provider via Keyring.
	 *
	 * @param string $provider_slug Provider slug.
	 * @param string $context       Context string for connection flow.
	 *
	 * @return string
	 */
	public function get_connect_url( string $provider_slug, string $context = 'daily-digest-settings' ): string {
		if ( ! $this->is_available() ) {
			return '';
		}

		$service = $this->get_service_name( $provider_slug );
		if ( '' === $service ) {
			return '';
		}

		return (string) \Keyring_Util::admin_url(
			$service,
			array(
				'action'   => 'request',
				'kr_nonce' => \wp_create_nonce( 'keyring-request' ),
				'nonce'    => \wp_create_nonce( 'keyring-request-' . $service ),
				'for'      => $context,
			)
		);
	}

	/**
	 * Returns URL for managing a provider token in Keyring.
	 *
	 * @param string $provider_slug Provider slug.
	 *
	 * @return string
	 */
	public function get_manage_url( string $provider_slug ): string {
		if ( ! $this->is_available() ) {
			return '';
		}

		$service = $this->get_service_name( $provider_slug );
		if ( '' === $service ) {
			return '';
		}

		return (string) \Keyring_Util::admin_url(
			$service,
			array(
				'action'   => 'tokens',
				'kr_nonce' => \wp_create_nonce( 'keyring-tokens' ),
			)
		);
	}

	/**
	 * Returns URL for configuring a provider service in Keyring.
	 *
	 * @param string $provider_slug Provider slug.
	 *
	 * @return string
	 */
	public function get_configure_url( string $provider_slug ): string {
		if ( ! $this->is_available() ) {
			return '';
		}

		$service = $this->get_service_name( $provider_slug );
		if ( '' === $service ) {
			return '';
		}

		return (string) \Keyring_Util::admin_url(
			$service,
			array(
				'action'   => 'manage',
				'kr_nonce' => \wp_create_nonce( 'keyring-manage' ),
				'nonce'    => \wp_create_nonce( 'keyring-manage-' . $service ),
			)
		);
	}

	/**
	 * Checks whether a provider's Keyring service is available.
	 *
	 * @param string $provider_slug Provider slug.
	 *
	 * @return bool
	 */
	public function has_service( string $provider_slug ): bool {
		if ( ! $this->is_available() ) {
			return false;
		}

		$service = $this->get_service_name( $provider_slug );
		if ( '' === $service ) {
			return false;
		}

		$service_object = \Keyring::get_service_by_name( $service );

		return \is_object( $service_object );
	}

	/**
	 * Checks whether a provider's Keyring service is configured and ready to connect.
	 *
	 * @param string $provider_slug Provider slug.
	 *
	 * @return bool
	 */
	public function is_service_configured( string $provider_slug ): bool {
		if ( ! $this->has_service( $provider_slug ) ) {
			return false;
		}

		$service        = $this->get_service_name( $provider_slug );
		$service_object = \Keyring::get_service_by_name( $service );

		if ( ! \is_object( $service_object ) || ! \method_exists( $service_object, 'is_configured' ) ) {
			return false;
		}

		return (bool) $service_object->is_configured();
	}

	/**
	 * Returns first Keyring token for provider and user.
	 *
	 * @param string $provider_slug Provider slug.
	 * @param int    $user_id       User ID.
	 *
	 * @return object|null
	 */
	public function get_token_for_user( string $provider_slug, int $user_id ) {
		$tokens = $this->get_tokens_for_user( $provider_slug, $user_id );
		if ( empty( $tokens ) ) {
			return null;
		}

		$token = \reset( $tokens );
		return \is_object( $token ) ? $token : null;
	}

	/**
	 * Returns all Keyring tokens for provider and user.
	 *
	 * @param string $provider_slug Provider slug.
	 * @param int    $user_id       User ID.
	 *
	 * @return array<int, object>
	 */
	public function get_tokens_for_user( string $provider_slug, int $user_id ): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		$service = $this->get_service_name( $provider_slug );
		if ( '' === $service ) {
			return array();
		}

		$store = \Keyring::get_token_store();
		if ( ! \is_object( $store ) || ! \method_exists( $store, 'get_tokens' ) ) {
			return array();
		}

		$tokens = $store->get_tokens(
			array(
				'type'    => 'access',
				'service' => $service,
				'user_id' => $user_id,
			)
		);

		return \is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Returns connection status for provider and user.
	 *
	 * @param string $provider_slug Provider slug.
	 * @param int    $user_id       User ID.
	 *
	 * @return bool
	 */
	public function has_connection( string $provider_slug, int $user_id ): bool {
		return null !== $this->get_token_for_user( $provider_slug, $user_id );
	}

	/**
	 * Returns token string for provider and user.
	 *
	 * @param string $provider_slug Provider slug.
	 * @param int    $user_id       User ID.
	 *
	 * @return string
	 */
	public function get_access_token_string( string $provider_slug, int $user_id ): string {
		$token = $this->get_token_for_user( $provider_slug, $user_id );
		if ( null === $token || ! isset( $token->token ) ) {
			return '';
		}

		$raw_token = $token->token;
		return \is_string( $raw_token ) ? \trim( $raw_token ) : '';
	}

	/**
	 * Returns token metadata for provider and user, or one metadata value.
	 *
	 * @param string $provider_slug Provider slug.
	 * @param int    $user_id       User ID.
	 * @param string $meta_key      Optional metadata key.
	 *
	 * @return array<string, mixed>|mixed|null
	 */
	public function get_connection_meta_for_user( string $provider_slug, int $user_id, string $meta_key = '' ) {
		$token = $this->get_token_for_user( $provider_slug, $user_id );
		if ( null === $token || ! \method_exists( $token, 'get_meta' ) ) {
			return '' === $meta_key ? array() : null;
		}

		$meta = $token->get_meta();
		if ( ! \is_array( $meta ) ) {
			return '' === $meta_key ? array() : null;
		}

		unset( $meta['type'] );

		if ( '' !== $meta_key ) {
			return $meta[ $meta_key ] ?? null;
		}

		return $meta;
	}

	/**
	 * Deletes all provider tokens for the user.
	 *
	 * @param string $provider_slug Provider slug.
	 * @param int    $user_id       User ID.
	 *
	 * @return int Number of deleted tokens.
	 */
	public function disconnect_user( string $provider_slug, int $user_id ): int {
		if ( ! $this->is_available() ) {
			return 0;
		}

		$store = \Keyring::get_token_store();
		if ( ! \is_object( $store ) || ! \method_exists( $store, 'delete' ) ) {
			return 0;
		}

		$deleted = 0;
		$tokens  = $this->get_tokens_for_user( $provider_slug, $user_id );

		foreach ( $tokens as $token ) {
			if ( ! \is_object( $token ) || ! \method_exists( $token, 'get_uniq_id' ) ) {
				continue;
			}

			$token_id = (int) $token->get_uniq_id();
			if ( $token_id < 1 ) {
				continue;
			}

			$deleted_result = $store->delete( array( 'id' => $token_id ) );
			if ( false !== $deleted_result ) {
				++$deleted;
			}
		}

		return $deleted;
	}
}
