<?php

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
	 * @param int   $user_id User ID.
	 * @param array $options Query options.
	 *
	 * @return array
	 */
	public function get_digest_for_user( int $user_id, array $options = array() ): array {
		$all_provider_settings = $this->user_settings->get_for_user( $user_id );
		$digest_items          = array();

		foreach ( $this->provider_registry->all() as $slug => $provider ) {
			$provider_settings = $all_provider_settings[ $slug ] ?? array();
			if ( empty( $provider_settings['enabled'] ) ) {
				continue;
			}

			$provider_items = $provider->fetch_activity( $user_id, $provider_settings, $options );

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
