<?php
/**
 * Provider execution context class.
 *
 * @package DailyDigest
 */

declare(strict_types=1);

namespace DailyDigest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks which provider is currently executing.
 */
class ProviderExecutionContext {
	/**
	 * Current provider slug.
	 *
	 * @var string|null
	 */
	private static ?string $current_provider = null;

	/**
	 * Runs callback while marking the active provider.
	 *
	 * @param string   $provider_slug Provider slug.
	 * @param callable $callback      Callback to execute.
	 *
	 * @return array
	 */
	public static function run_with_provider( string $provider_slug, callable $callback ): array {
		$previous_provider      = self::$current_provider;
		self::$current_provider = \sanitize_key( $provider_slug );
		$result                 = array();

		try {
			$raw_result = $callback();
			if ( \is_array( $raw_result ) ) {
				$result = $raw_result;
			}
		} finally {
			self::$current_provider = $previous_provider;
		}

		return $result;
	}

	/**
	 * Returns the current provider slug.
	 *
	 * @return string|null
	 */
	public static function current_provider(): ?string {
		return self::$current_provider;
	}
}
