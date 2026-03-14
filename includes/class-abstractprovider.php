<?php
/**
 * Abstract provider base class.
 *
 * @package DailyDigest
 */

declare(strict_types=1);

namespace DailyDigest;

use DailyDigest\Contracts\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class providing shared behavior for activity providers.
 *
 * Concrete providers should extend this class and implement the remaining
 * abstract methods from ProviderInterface: get_slug(), get_name(),
 * test_credentials(), and fetch_activity().
 */
abstract class AbstractProvider implements ProviderInterface {
	/**
	 * Keyring connection manager.
	 *
	 * @var KeyringConnectionManager
	 */
	protected KeyringConnectionManager $keyring_connections;

	/**
	 * Constructor.
	 *
	 * @param KeyringConnectionManager|null $keyring_connections Keyring connection manager.
	 */
	public function __construct( ?KeyringConnectionManager $keyring_connections = null ) {
		$this->keyring_connections = $keyring_connections ?? new KeyringConnectionManager();
	}

	/**
	 * Returns the Keyring service name for this provider.
	 *
	 * Override in subclasses when the Keyring service name differs from the
	 * provider slug (e.g. custom Daily Digest Keyring services).
	 *
	 * @return string
	 */
	public function get_keyring_service_name(): string {
		return $this->get_slug();
	}

	/**
	 * Returns provider-specific field definitions.
	 *
	 * Override to return field config arrays consumed by the settings UI.
	 *
	 * @return array
	 */
	public function get_fields(): array {
		return array();
	}

	/**
	 * Returns the Keyring access token string for this provider and user.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return string
	 */
	protected function get_token( int $user_id ): string {
		return $this->keyring_connections->get_access_token_string( $this->get_slug(), $user_id );
	}

	/**
	 * Returns a Keyring token metadata value for this provider and user.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Metadata key.
	 *
	 * @return mixed
	 */
	protected function get_token_meta( int $user_id, string $meta_key ) {
		return $this->keyring_connections->get_connection_meta_for_user( $this->get_slug(), $user_id, $meta_key );
	}

	/**
	 * Performs a GET request with standard plugin defaults.
	 *
	 * Merges caller-supplied args over a default timeout of 15 seconds.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Optional wp_remote_get args (e.g. 'headers', 'timeout').
	 *
	 * @return array|\WP_Error
	 */
	protected function http_get( string $url, array $args = array() ) {
		return \wp_remote_get(
			$url,
			\wp_parse_args( $args, array( 'timeout' => 15 ) )
		);
	}

	/**
	 * Applies time-window filtering to activity items.
	 *
	 * Items lacking a 'timestamp' key or outside the configured window are
	 * removed. The returned array is re-indexed.
	 *
	 * Accepts either a 'since'/'until' pair of Unix timestamps or a 'days' count.
	 *
	 * @param array $items   Activity items, each having a string 'timestamp' key.
	 * @param array $options Query options; uses 'since'/'until' or 'days' (default 1).
	 *
	 * @return array
	 */
	protected function apply_time_window( array $items, array $options ): array {
		if ( isset( $options['since'] ) ) {
			$since = (int) $options['since'];
			$until = isset( $options['until'] ) ? (int) $options['until'] : \PHP_INT_MAX;
		} else {
			$days  = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;
			$since = (int) \strtotime( '-' . $days . ' days' );
			$until = \PHP_INT_MAX;
		}

		return \array_values(
			\array_filter(
				$items,
				static function ( array $item ) use ( $since, $until ): bool {
					if ( empty( $item['timestamp'] ) ) {
						return false;
					}

					$ts = (int) \strtotime( (string) $item['timestamp'] );
					return $ts >= $since && $ts <= $until;
				}
			)
		);
	}
}
