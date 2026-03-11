<?php
/**
 * User settings persistence class.
 *
 * @package DailyDigest
 */

declare(strict_types=1);

namespace DailyDigest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles per-user provider settings persistence.
 */
class UserSettings {
	/**
	 * Canonical user meta key for provider settings.
	 *
	 * @var string
	 */
	private string $meta_key = 'daily_digest_provider_settings';

	/**
	 * Legacy user meta key used in older plugin versions.
	 *
	 * @var string
	 */
	private string $legacy_meta_key = 'dd_provider_settings';

	/**
	 * Retrieves provider settings for a user.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array
	 */
	public function get_for_user( int $user_id ): array {
		$settings = \get_user_meta( $user_id, $this->meta_key, true );

		if ( ! \is_array( $settings ) ) {
			$settings = \get_user_meta( $user_id, $this->legacy_meta_key, true );
		}

		if ( ! \is_array( $settings ) ) {
			return array();
		}

		return $settings;
	}

	/**
	 * Saves provider settings for a user.
	 *
	 * @param int   $user_id  User ID.
	 * @param array $settings Submitted settings.
	 */
	public function save_for_user( int $user_id, array $settings ): void {
		$sanitized = array();

		foreach ( $settings as $provider_slug => $provider_settings ) {
			$slug = \sanitize_key( (string) $provider_slug );
			if ( ! \is_array( $provider_settings ) || empty( $slug ) ) {
				continue;
			}

			$sanitized_provider            = array();
			$sanitized_provider['enabled'] = ! empty( $provider_settings['enabled'] );
			$sanitized_provider['fields']  = array();
			$input_fields                  = $provider_settings['fields'] ?? array();

			if ( \is_array( $input_fields ) ) {
				foreach ( $input_fields as $field_key => $value ) {
					$sanitized_provider['fields'][ \sanitize_key( (string) $field_key ) ] = \sanitize_text_field( (string) $value );
				}
			}

			$sanitized[ $slug ] = $sanitized_provider;
		}

		\update_user_meta( $user_id, $this->meta_key, $sanitized );
		\delete_user_meta( $user_id, $this->legacy_meta_key );
	}
}
