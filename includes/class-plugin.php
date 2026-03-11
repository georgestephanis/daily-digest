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
	 * HTTP API logger service.
	 *
	 * @var ApiLogger
	 */
	private ApiLogger $api_logger;

	/**
	 * REST controller service.
	 *
	 * @var RestController
	 */
	private RestController $rest_controller;

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
		$this->api_logger        = new ApiLogger();
		$this->digest_service    = new DigestService( $this->provider_registry, $this->user_settings );
		$this->rest_controller   = new RestController( $this->provider_registry, $this->user_settings, $this->digest_service );
		$this->admin_page        = new AdminPage( $this->provider_registry, $this->user_settings, $this->digest_service, $this->api_logger );

		$this->register_builtin_providers();
		/**
		 * Registers external Daily Digest providers.
		 *
		 * @since 0.1.0
		 *
		 * @param ProviderRegistry $provider_registry Provider registry instance.
		 */
		\do_action( 'daily_digest_register_providers', $this->provider_registry );

		$this->api_logger->register_hooks();
		$this->rest_controller->register();
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
