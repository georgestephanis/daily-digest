<?php

declare(strict_types=1);

namespace DailyDigest\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for activity providers.
 */
interface ProviderInterface {
	/**
	 * Returns provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Returns provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Returns provider-specific field definitions.
	 *
	 * @return array
	 */
	public function get_fields(): array;

	/**
	 * Fetches activity items for the given user.
	 *
	 * @param int   $user_id           User ID.
	 * @param array $provider_settings Provider settings.
	 * @param array $options           Query options.
	 *
	 * @return array
	 */
	public function fetch_activity( int $user_id, array $provider_settings = array(), array $options = array() ): array;
}
