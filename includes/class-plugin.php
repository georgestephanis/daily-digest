<?php
/**
 * Main plugin bootstrap class.
 *
 * @package DailyDigest
 */

declare(strict_types=1);

namespace DailyDigest;

use DailyDigest\Providers\ClickupProvider;
use DailyDigest\Providers\GithubProvider;
use DailyDigest\Providers\SlackProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin composition root.
 */
class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Boot state guard.
	 *
	 * @var bool
	 */
	private bool $booted = false;

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
	 * Digest aggregation service.
	 *
	 * @var DigestService
	 */
	private DigestService $digest_service;

	/**
	 * Admin page presenter.
	 *
	 * @var AdminPage
	 */
	private AdminPage $admin_page;

	/**
	 * Returns singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boots plugin services and admin registration.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->provider_registry = new ProviderRegistry();
		$this->user_settings     = new UserSettings();
		$this->digest_service    = new DigestService( $this->provider_registry, $this->user_settings );
		$this->admin_page        = new AdminPage( $this->provider_registry, $this->user_settings, $this->digest_service );

		$this->register_builtin_providers();
		\do_action( 'daily_digest_register_providers', $this->provider_registry );
		\do_action( 'dd_register_providers', $this->provider_registry );

		$this->admin_page->register();
		$this->booted = true;
	}

	/**
	 * Registers built-in provider adapters.
	 */
	private function register_builtin_providers(): void {
		$this->provider_registry->register( new GithubProvider() );
		$this->provider_registry->register( new ClickupProvider() );
		$this->provider_registry->register( new SlackProvider() );
	}
}
