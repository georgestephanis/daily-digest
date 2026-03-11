<?php

declare(strict_types=1);

namespace DailyDigest;

use DailyDigest\Contracts\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and resolves digest providers.
 */
class ProviderRegistry {
	/**
	 * Registered providers keyed by slug.
	 *
	 * @var array<string, ProviderInterface>
	 */
	private array $providers = array();

	/**
	 * Registers a provider instance.
	 *
	 * @param ProviderInterface $provider Provider instance.
	 */
	public function register( ProviderInterface $provider ): void {
		$this->providers[ $provider->get_slug() ] = $provider;
	}

	/**
	 * Retrieves a provider by slug.
	 *
	 * @param string $slug Provider slug.
	 *
	 * @return ProviderInterface|null
	 */
	public function get( string $slug ): ?ProviderInterface {
		return $this->providers[ $slug ] ?? null;
	}

	/**
	 * Returns all registered providers.
	 *
	 * @return array<string, ProviderInterface>
	 */
	public function all(): array {
		return $this->providers;
	}
}
