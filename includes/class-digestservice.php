<?php
/**
 * Digest aggregation service class.
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
 * Aggregates normalized digest activity across enabled providers.
 */
class DigestService {
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
	 * Constructor.
	 *
	 * @param ProviderRegistry $provider_registry Provider registry.
	 * @param UserSettings     $user_settings     User settings service.
	 */
	public function __construct( ProviderRegistry $provider_registry, UserSettings $user_settings ) {
		$this->provider_registry = $provider_registry;
		$this->user_settings     = $user_settings;
	}

	/**
	 * Returns digest items for a user.
	 *
	 * Provider results are cached in transients (15 minutes) to avoid hammering
	 * external APIs on every page load.
	 *
	 * @param int   $user_id User ID.
	 * @param array $options Query options.
	 *
	 * @return array
	 */
	public function get_digest_for_user( int $user_id, array $options = array() ): array {
		$all_provider_settings = $this->user_settings->get_for_user( $user_id );
		$digest_items          = array();
		$days                  = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;

		foreach ( $this->provider_registry->all() as $slug => $provider ) {
			$provider_settings = $all_provider_settings[ $slug ] ?? array();
			if ( empty( $provider_settings['enabled'] ) ) {
				continue;
			}

			$cache_key      = 'dd_cache_' . $user_id . '_' . $slug . '_' . $days;
			$provider_items = \get_transient( $cache_key );

			if ( false === $provider_items ) {
				$provider_items = ProviderExecutionContext::run_with_provider(
					(string) $slug,
					static function () use ( $provider, $user_id, $provider_settings, $options ): array {
						return $provider->fetch_activity( $user_id, $provider_settings, $options );
					}
				);
				\set_transient( $cache_key, $provider_items, 15 * MINUTE_IN_SECONDS );
			}

			foreach ( $provider_items as $item ) {
				$normalized = $this->normalize_item( $item, $provider );
				if ( null !== $normalized ) {
					$digest_items[] = $normalized;
				}
			}
		}

		\usort(
			$digest_items,
			static function ( array $left, array $right ): int {
				return \strcmp( $right['timestamp'], $left['timestamp'] );
			}
		);

		return $digest_items;
	}

	/**
	 * Clears the activity cache for a provider and user.
	 *
	 * Called when a user disconnects a provider or saves new credentials to
	 * ensure the next request reflects the updated connection state.
	 *
	 * @param int    $user_id       User ID.
	 * @param string $provider_slug Provider slug.
	 *
	 * @return void
	 */
	public function clear_provider_cache( int $user_id, string $provider_slug ): void {
		for ( $days = 1; $days <= 90; $days++ ) {
			\delete_transient( 'dd_cache_' . $user_id . '_' . $provider_slug . '_' . $days );
		}
	}

	/**
	 * Returns digest items for one provider for a user.
	 *
	 * @param int    $user_id       User ID.
	 * @param string $provider_slug Provider slug.
	 * @param array  $options       Query options.
	 *
	 * @return array
	 */
	public function get_provider_digest_for_user( int $user_id, string $provider_slug, array $options = array() ): array {
		$provider = $this->provider_registry->get( $provider_slug );
		if ( null === $provider ) {
			return array();
		}

		$all_provider_settings = $this->user_settings->get_for_user( $user_id );
		$provider_settings     = $all_provider_settings[ $provider_slug ] ?? array();

		if ( empty( $provider_settings['enabled'] ) ) {
			return array();
		}

		$days           = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;
		$cache_key      = 'dd_cache_' . $user_id . '_' . $provider_slug . '_' . $days;
		$provider_items = \get_transient( $cache_key );

		if ( false === $provider_items ) {
			$provider_items = ProviderExecutionContext::run_with_provider(
				$provider_slug,
				static function () use ( $provider, $user_id, $provider_settings, $options ): array {
					return $provider->fetch_activity( $user_id, $provider_settings, $options );
				}
			);
			\set_transient( $cache_key, $provider_items, 15 * MINUTE_IN_SECONDS );
		}

		$digest_items = array();
		foreach ( $provider_items as $item ) {
			$normalized = $this->normalize_item( $item, $provider );
			if ( null !== $normalized ) {
				$digest_items[] = $normalized;
			}
		}

		\usort(
			$digest_items,
			static function ( array $left, array $right ): int {
				return \strcmp( $right['timestamp'], $left['timestamp'] );
			}
		);

		return $digest_items;
	}

	/**
	 * Normalizes a provider activity item to digest schema.
	 *
	 * @param array             $item     Raw provider item.
	 * @param ProviderInterface $provider Provider instance.
	 *
	 * @return array|null
	 */
	private function normalize_item( array $item, ProviderInterface $provider ): ?array {
		if ( empty( $item['timestamp'] ) || empty( $item['title'] ) ) {
			return null;
		}

		$parsed_timestamp = \strtotime( (string) $item['timestamp'] );
		if ( false === $parsed_timestamp ) {
			return null;
		}

		return array(
			'provider'  => $item['provider'] ?? $provider->get_name(),
			'type'      => $item['type'] ?? 'activity',
			'timestamp' => \gmdate( 'c', $parsed_timestamp ),
			'title'     => \sanitize_text_field( (string) $item['title'] ),
			'summary'   => isset( $item['summary'] ) ? \sanitize_text_field( (string) $item['summary'] ) : '',
			'url'       => isset( $item['url'] ) ? \esc_url_raw( (string) $item['url'] ) : '',
			'raw'       => $item['raw'] ?? array(),
		);
	}
}
