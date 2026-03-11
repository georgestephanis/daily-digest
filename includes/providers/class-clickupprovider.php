<?php
/**
 * ClickUp provider adapter class.
 *
 * @package DailyDigest
 */

declare(strict_types=1);

namespace DailyDigest\Providers;

use DailyDigest\Contracts\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ClickUp provider adapter.
 */
class ClickupProvider implements ProviderInterface {
	/**
	 * Returns provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'clickup';
	}

	/**
	 * Returns provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'ClickUp';
	}

	/**
	 * Returns provider settings fields.
	 *
	 * @return array
	 */
	public function get_fields(): array {
		return array(
			'workspace_id' => \__( 'Workspace ID', 'daily-digest' ),
			'token'        => \__( 'API Token', 'daily-digest' ),
		);
	}

	/**
	 * Fetches activity data from integration callbacks.
	 *
	 * @param int   $user_id           User ID.
	 * @param array $provider_settings Provider settings.
	 * @param array $options           Query options.
	 *
	 * @return array
	 */
	public function fetch_activity( int $user_id, array $provider_settings = array(), array $options = array() ): array {
		$fields = $provider_settings['fields'] ?? array();
		$items  = \apply_filters( 'daily_digest_provider_clickup_activity', array(), $user_id, $fields, $options, $this );

		if ( empty( $items ) ) {
			$items = \apply_filters( 'dd_provider_clickup_activity', array(), $user_id, $fields, $options, $this );
		}

		if ( ! \is_array( $items ) ) {
			return array();
		}

		return $this->apply_time_window( $items, $options );
	}

	/**
	 * Applies day-window filtering to activity items.
	 *
	 * @param array $items   Activity items.
	 * @param array $options Query options.
	 *
	 * @return array
	 */
	private function apply_time_window( array $items, array $options ): array {
		$days      = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;
		$threshold = \strtotime( '-' . $days . ' days' );

		return \array_values(
			\array_filter(
				$items,
				static function ( array $item ) use ( $threshold ): bool {
					if ( empty( $item['timestamp'] ) ) {
						return false;
					}

					return \strtotime( (string) $item['timestamp'] ) >= $threshold;
				}
			)
		);
	}
}
