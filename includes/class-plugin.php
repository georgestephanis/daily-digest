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
	 * Keyring connection manager.
	 *
	 * @var KeyringConnectionManager
	 */
	private KeyringConnectionManager $keyring_connections;

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

		$this->provider_registry   = new ProviderRegistry();
		$this->user_settings       = new UserSettings();
		$this->api_logger          = new ApiLogger();
		$this->keyring_connections = new KeyringConnectionManager();
		$this->digest_service      = new DigestService( $this->provider_registry, $this->user_settings );
		$this->rest_controller     = new RestController( $this->provider_registry, $this->user_settings, $this->digest_service, $this->api_logger, $this->keyring_connections );
		$this->admin_page          = new AdminPage( $this->provider_registry, $this->user_settings, $this->digest_service, $this->api_logger, $this->keyring_connections );

		$this->register_keyring_scope_filters();
		$this->register_keyring_services();
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
		$this->provider_registry->register( new GithubProvider( $this->keyring_connections ) );
		$this->provider_registry->register( new ClickupProvider( $this->keyring_connections ) );
		$this->provider_registry->register( new SlackProvider( $this->keyring_connections ) );
	}

	/**
	 * Loads custom Keyring services for Daily Digest providers.
	 */
	private function register_keyring_services(): void {
		if ( ! \class_exists( '\\Keyring' ) ) {
			return;
		}

		require_once DAILY_DIGEST_PLUGIN_PATH . 'includes/keyring-services.php';
	}

	/**
	 * Registers Keyring OAuth scope filters used by Daily Digest.
	 */
	private function register_keyring_scope_filters(): void {
		\add_filter( 'keyring_github_scope', array( $this, 'filter_github_scope' ) );
	}

	/**
	 * Ensures Daily Digest requests the OAuth scopes it needs for GitHub.
	 *
	 * @param string $scope Existing Keyring scope string.
	 *
	 * @return string
	 */
	public function filter_github_scope( string $scope ): string {
		$required_scopes = array( 'notifications' );

		/**
		 * Filters required GitHub OAuth scopes for Daily Digest.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string> $required_scopes Required scope list.
		 */
		$required_scopes = \apply_filters( 'daily_digest_github_oauth_scopes', $required_scopes );

		$current_scopes = array();
		if ( '' !== trim( $scope ) ) {
			$parsed_scopes = preg_split( '/\s+/', trim( $scope ) );
			if ( is_array( $parsed_scopes ) ) {
				$current_scopes = $parsed_scopes;
			}
		}

		$normalized_required = array_map( 'strval', $required_scopes );
		$all_scopes          = array_unique( array_merge( $current_scopes, $normalized_required ) );
		$all_scopes          = array_values( array_filter( $all_scopes, 'strlen' ) );

		return implode( ' ', $all_scopes );
	}
}
