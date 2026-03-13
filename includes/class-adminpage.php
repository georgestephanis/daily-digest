<?php
/**
 * Admin page rendering class.
 *
 * @package DailyDigest
 */

declare(strict_types=1);

namespace DailyDigest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders user-facing Daily Digest settings and digest output in wp-admin.
 */
class AdminPage {
	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private ProviderRegistry $provider_registry;

	/**
	 * Per-user settings service.
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
	 * HTTP API logger service.
	 *
	 * @var ApiLogger
	 */
	private ApiLogger $api_logger;

	/**
	 * Keyring connection manager.
	 *
	 * @var KeyringConnectionManager
	 */
	private KeyringConnectionManager $keyring_connections;

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry $provider_registry Provider registry.
	 * @param UserSettings     $user_settings     User settings service.
	 * @param DigestService    $digest_service    Digest service.
	 * @param ApiLogger                $api_logger          API logger service.
	 * @param KeyringConnectionManager $keyring_connections Keyring connection manager.
	 */
	public function __construct( ProviderRegistry $provider_registry, UserSettings $user_settings, DigestService $digest_service, ApiLogger $api_logger, KeyringConnectionManager $keyring_connections ) {
		$this->provider_registry   = $provider_registry;
		$this->user_settings       = $user_settings;
		$this->digest_service      = $digest_service;
		$this->api_logger          = $api_logger;
		$this->keyring_connections = $keyring_connections;
	}

	/**
	 * Hooks page registration into wp-admin.
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'register_pages' ) );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Registers top-level Daily Digest menu and submenus.
	 */
	public function register_pages(): void {
		\add_menu_page(
			\__( 'Daily Digest', 'daily-digest' ),
			\__( 'Daily Digest', 'daily-digest' ),
			'read',
			'daily-digest',
			array( $this, 'render_overview_page' ),
			'dashicons-list-view',
			2.1
		);

		\add_submenu_page(
			'daily-digest',
			\__( 'Overview', 'daily-digest' ),
			\__( 'Overview', 'daily-digest' ),
			'read',
			'daily-digest',
			array( $this, 'render_overview_page' )
		);

		\add_submenu_page(
			'daily-digest',
			\__( 'Settings', 'daily-digest' ),
			\__( 'Settings', 'daily-digest' ),
			'read',
			'daily-digest-settings',
			array( $this, 'render_settings_page' )
		);

		\add_submenu_page(
			'daily-digest',
			\__( 'Logs', 'daily-digest' ),
			\__( 'Logs', 'daily-digest' ),
			'manage_options',
			'daily-digest-logs',
			array( $this, 'render_logs_page' )
		);
	}

	/**
	 * Renders digest overview for the current user.
	 */
	public function render_overview_page(): void {
		if ( ! \is_user_logged_in() ) {
			\wp_die( \esc_html__( 'You must be logged in to view this page.', 'daily-digest' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter input that does not change data.
		$days = isset( $_GET['days'] ) ? \max( 1, \absint( $_GET['days'] ) ) : 1;
		?>
		<div class="wrap">
			<h1>
				<?php \esc_html_e( 'Daily Digest Overview', 'daily-digest' ); ?>
				<a href="<?php echo \esc_url( \admin_url( 'admin.php?page=daily-digest-settings' ) ); ?>" class="page-title-action">
					<?php \esc_html_e( 'Settings', 'daily-digest' ); ?>
				</a>
			</h1>
			<p>
				<?php
				echo \esc_html__( 'View your unified activity digest below.', 'daily-digest' ) . ' ';
				echo '<a href="' . \esc_url( \admin_url( 'admin.php?page=daily-digest-settings' ) ) . '">' . \esc_html__( 'Configure providers in Settings.', 'daily-digest' ) . '</a>';
				?>
			</p>
			<div id="daily-digest-overview-app" data-initial-days="<?php echo \esc_attr( (string) $days ); ?>">
				<p><?php \esc_html_e( 'Loading activity…', 'daily-digest' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueues built React/DataViews assets for supported admin pages.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		$is_overview_page = 'toplevel_page_daily-digest' === $hook_suffix;
		$is_logs_page     = 'daily-digest_page_daily-digest-logs' === $hook_suffix;
		$is_settings_page = 'daily-digest_page_daily-digest-settings' === $hook_suffix;

		if ( ! $is_overview_page && ! $is_logs_page && ! $is_settings_page ) {
			return;
		}

		$asset_file = DAILY_DIGEST_PLUGIN_PATH . 'build/index.asset.php';
		if ( ! \file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;
		if ( ! \is_array( $asset ) ) {
			return;
		}

		$styles_file = DAILY_DIGEST_PLUGIN_PATH . 'build/style-index.css';
		if ( \file_exists( $styles_file ) ) {
			\wp_enqueue_style(
				'daily-digest-overview',
				DAILY_DIGEST_PLUGIN_URL . 'build/style-index.css',
				array( 'wp-components' ),
				$asset['version'] ?? DAILY_DIGEST_PLUGIN_VERSION
			);
		}

		if ( $is_settings_page ) {
			return;
		}

		\wp_enqueue_script(
			'daily-digest-overview',
			DAILY_DIGEST_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? DAILY_DIGEST_PLUGIN_VERSION,
			true
		);

		if ( $is_overview_page ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter input used only for app initialization.
			$days = isset( $_GET['days'] ) ? \max( 1, \absint( $_GET['days'] ) ) : 1;

			$overview_config = array(
				'restRoot'    => \esc_url_raw( \rest_url( 'daily-digest/v1' ) ),
				'restNonce'   => \wp_create_nonce( 'wp_rest' ),
				'currentUser' => \get_current_user_id(),
				'initialDays' => $days,
				'settingsUrl' => \admin_url( 'admin.php?page=daily-digest-settings' ),
			);

			\wp_add_inline_script(
				'daily-digest-overview',
				'window.dailyDigestOverviewConfig = ' . \wp_json_encode( $overview_config ) . ';',
				'before'
			);
		}

		if ( $is_logs_page && \current_user_can( 'manage_options' ) ) {
			$configured_providers = $this->get_configured_providers_for_user( \get_current_user_id() );

			$logs_page_config = array(
				'restRoot'            => \esc_url_raw( \rest_url( 'daily-digest/v1' ) ),
				'restNonce'           => \wp_create_nonce( 'wp_rest' ),
				'configuredProviders' => $configured_providers,
			);

			\wp_add_inline_script(
				'daily-digest-overview',
				'window.dailyDigestLogsPageConfig = ' . \wp_json_encode( $logs_page_config ) . ';',
				'before'
			);
		}
	}

	/**
	 * Returns configured and enabled providers for a user.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function get_configured_providers_for_user( int $user_id ): array {
		$settings = $this->user_settings->get_for_user( $user_id );
		$items    = array();

		foreach ( $this->provider_registry->all() as $provider ) {
			$slug              = $provider->get_slug();
			$provider_settings = $settings[ $slug ] ?? array();
			$enabled           = ! empty( $provider_settings['enabled'] );

			if ( ! $enabled ) {
				continue;
			}

			$items[] = array(
				'slug' => $slug,
				'name' => $provider->get_name(),
			);
		}

		return $items;
	}

	/**
	 * Renders provider settings page for the current user.
	 */
	public function render_settings_page(): void {
		if ( ! \is_user_logged_in() ) {
			\wp_die( \esc_html__( 'You must be logged in to view this page.', 'daily-digest' ) );
		}

		$current_user_id = \get_current_user_id();
		$this->maybe_save_settings( $current_user_id );
		$current_settings = $this->user_settings->get_for_user( $current_user_id );
		$rest_root        = \esc_url_raw( \rest_url( 'daily-digest/v1' ) );
		$nonce            = \wp_create_nonce( 'wp_rest' );
		$keyring_ready    = $this->keyring_connections->is_available();
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Daily Digest Settings', 'daily-digest' ); ?></h1>
			<p><?php \esc_html_e( 'Enable providers with the toggle below. Detailed connection metadata is available on demand.', 'daily-digest' ); ?></p>
			<?php if ( ! $keyring_ready ) : ?>
				<div class="notice notice-warning inline"><p><?php \esc_html_e( 'Keyring is not available. Provider connections cannot be created.', 'daily-digest' ); ?></p></div>
			<?php endif; ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: URL to Keyring management page. */
					\wp_kses_post( __( 'Manage connections in <a href="%s">Keyring</a>.', 'daily-digest' ) ),
					\esc_url( \admin_url( 'tools.php?page=keyring' ) )
				);
				?>
			</p>

			<form method="post" id="daily-digest-settings-form">
				<?php \wp_nonce_field( 'daily_digest_save_settings', 'daily_digest_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<?php foreach ( $this->provider_registry->all() as $provider ) : ?>
							<?php
							$slug              = $provider->get_slug();
							$provider_settings = $current_settings[ $slug ] ?? array();
							$enabled           = ! empty( $provider_settings['enabled'] );
							$fields            = $provider_settings['fields'] ?? array();
							$connected         = $this->keyring_connections->has_connection( $slug, $current_user_id );
							$connection_meta   = $this->keyring_connections->get_connection_meta_for_user( $slug, $current_user_id );
							$service_exists    = $this->keyring_connections->has_service( $slug );
							$service_ready     = $this->keyring_connections->is_service_configured( $slug );
							$can_test_provider = $service_exists && $service_ready;
							$meta_summary      = $this->get_connection_meta_summary( $slug, $connection_meta );
							?>
							<tr data-provider="<?php echo \esc_attr( $slug ); ?>">
								<th scope="row"><?php echo \esc_html( $provider->get_name() ); ?></th>
								<td>
									<label>
										<input type="checkbox" class="daily-digest-provider-toggle" name="daily_digest_settings[<?php echo \esc_attr( $slug ); ?>][enabled]" value="1" <?php \checked( $enabled ); ?> />
										<?php \esc_html_e( 'Enable provider', 'daily-digest' ); ?>
									</label>
									<?php foreach ( $provider->get_fields() as $field_key => $field_label ) : ?>
										<p>
											<label>
												<?php echo \esc_html( $field_label ); ?><br />
												<input class="regular-text" type="text" name="daily_digest_settings[<?php echo \esc_attr( $slug ); ?>][fields][<?php echo \esc_attr( $field_key ); ?>]" value="<?php echo \esc_attr( $fields[ $field_key ] ?? '' ); ?>" />
											</label>
										</p>
									<?php endforeach; ?>
									<p>
										<strong><?php \esc_html_e( 'Connection:', 'daily-digest' ); ?></strong>
										<?php echo $connected ? \esc_html__( 'Connected via Keyring', 'daily-digest' ) : \esc_html__( 'Not connected', 'daily-digest' ); ?>
									</p>
									<?php if ( $connected ) : ?>
										<p class="description">
											<?php
											/* translators: %s is a short connected-account summary, such as username/team. */
											echo \esc_html( sprintf( __( 'Connected as %s.', 'daily-digest' ), $meta_summary ) );
											?>
										</p>
										<details>
											<summary><?php \esc_html_e( 'View connection details', 'daily-digest' ); ?></summary>
											<p><?php echo wp_kses_post( $this->render_connection_meta( $slug, $connection_meta ) ); ?></p>
										</details>
									<?php endif; ?>
									<?php if ( $service_exists && ! $service_ready ) : ?>
										<p class="description">
											<?php \esc_html_e( 'Keyring credentials are not configured for this provider yet.', 'daily-digest' ); ?>
										</p>
									<?php endif; ?>
									<p>
										<button type="button" class="button button-secondary daily-digest-test-credentials" data-provider="<?php echo \esc_attr( $slug ); ?>" data-keyring-configured="<?php echo $can_test_provider ? '1' : '0'; ?>" <?php echo $can_test_provider ? '' : 'disabled="disabled" aria-disabled="true"'; ?>>
											<?php \esc_html_e( 'Test Connection', 'daily-digest' ); ?>
										</button>
										<span class="daily-digest-test-result" id="daily-digest-test-result-<?php echo \esc_attr( $slug ); ?>" style="margin-left:8px;"></span>
										<span class="daily-digest-save-result" id="daily-digest-save-result-<?php echo \esc_attr( $slug ); ?>" style="margin-left:8px;"></span>
									</p>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</form>
		</div>
		<script>
		(function() {
			const restRoot = <?php echo \wp_json_encode( $rest_root ); ?>;
			const restNonce = <?php echo \wp_json_encode( $nonce ); ?>;
			const buttons = document.querySelectorAll('.daily-digest-test-credentials');
			const providerToggles = document.querySelectorAll('.daily-digest-provider-toggle');
			const messageTimers = new WeakMap();

			const replaceMessage = (el, text, ok) => {
				if (!el) {
					return;
				}

				const existingTimer = messageTimers.get(el);
				if (existingTimer) {
					clearTimeout(existingTimer);
					messageTimers.delete(el);
				}

				const applyNewMessage = () => {
					el.textContent = text;
					el.style.color = ok ? '#0a7d18' : '#b32d2e';
					el.style.transition = 'opacity 140ms ease';
					el.style.opacity = '0';
					window.requestAnimationFrame(() => {
						el.style.opacity = '1';
					});
				};

				if ((el.textContent || '').trim() !== '') {
					el.style.transition = 'opacity 110ms ease';
					el.style.opacity = '0';
					const timer = window.setTimeout(() => {
						applyNewMessage();
						messageTimers.delete(el);
					}, 120);
					messageTimers.set(el, timer);
					return;
				}

				applyNewMessage();
			};

			const setResult = (provider, text, ok) => {
				const el = document.getElementById(`daily-digest-test-result-${provider}`);
				replaceMessage(el, text, ok);
			};

			const collectProviderFields = (provider) => {
				const fields = {};
				document.querySelectorAll(`input[name^="daily_digest_settings[${provider}][fields]"]`).forEach((input) => {
					const match = input.name.match(/\[fields\]\[([^\]]+)\]/);
					if (match && match[1]) {
						fields[match[1]] = input.value;
					}
				});
				return fields;
			};

			const collectProviderEnabled = (provider) => {
				const input = document.querySelector(`input[name="daily_digest_settings[${provider}][enabled]"]`);
				return !!(input && input.checked);
			};

			const setSaveResult = (provider, text, ok) => {
				const el = document.getElementById(`daily-digest-save-result-${provider}`);
				replaceMessage(el, text, ok);
			};

			const saveProviderState = async (provider) => {
				if (!provider) {
					return;
				}

				setSaveResult(provider, <?php echo \wp_json_encode( __( 'Saving…', 'daily-digest' ) ); ?>, true);

				const response = await fetch(`${restRoot}/providers/${encodeURIComponent(provider)}/credentials`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': restNonce,
					},
					body: JSON.stringify({
						fields: collectProviderFields(provider),
						enabled: collectProviderEnabled(provider),
					})
				});

				let payload = null;
				try {
					payload = await response.json();
				} catch (e) {
					payload = null;
				}

				if (!response.ok || !payload || !payload.success) {
					const message = payload && payload.message ? payload.message : <?php echo \wp_json_encode( __( 'Unable to save provider settings.', 'daily-digest' ) ); ?>;
					setSaveResult(provider, message, false);
					return;
				}

				setSaveResult(provider, payload.message || <?php echo \wp_json_encode( __( 'Provider settings saved.', 'daily-digest' ) ); ?>, true);
			};

			buttons.forEach((button) => {
				button.addEventListener('click', async () => {
					if (button.disabled || button.getAttribute('data-keyring-configured') !== '1') {
						return;
					}

					const provider = button.getAttribute('data-provider') || '';
					if (!provider) {
						return;
					}

					setResult(provider, <?php echo \wp_json_encode( __( 'Testing…', 'daily-digest' ) ); ?>, true);

					const response = await fetch(`${restRoot}/providers/${encodeURIComponent(provider)}/test-credentials`, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': restNonce,
						},
						body: JSON.stringify({ fields: collectProviderFields(provider) })
					});

					let payload = null;
					try {
						payload = await response.json();
					} catch (e) {
						payload = null;
					}

					if (!response.ok || !payload || !payload.success) {
						const message = payload && payload.message ? payload.message : <?php echo \wp_json_encode( __( 'Credential test failed.', 'daily-digest' ) ); ?>;
						setResult(provider, message, false);
						return;
					}

					setResult(provider, payload.message || <?php echo \wp_json_encode( __( 'Credentials are valid.', 'daily-digest' ) ); ?>, true);
				});
			});

			providerToggles.forEach((toggle) => {
				toggle.addEventListener('change', async () => {
					const row = toggle.closest('tr[data-provider]');
					const provider = row ? (row.getAttribute('data-provider') || '') : '';
					await saveProviderState(provider);
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Renders admin-only logs page.
	 */
	public function render_logs_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to view this page.', 'daily-digest' ) );
		}

		$this->maybe_save_log_settings();

		$logging_enabled   = $this->api_logger->is_enabled();
		$logging_directory = $this->api_logger->get_log_directory_path();
		$logging_url       = $this->api_logger->get_log_directory_url();
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Daily Digest Logs', 'daily-digest' ); ?></h1>
			<p><?php \esc_html_e( 'Manage API logging and review log entries.', 'daily-digest' ); ?></p>

			<form method="post">
				<?php \wp_nonce_field( 'daily_digest_save_log_settings', 'daily_digest_log_nonce' ); ?>
				<h2><?php \esc_html_e( 'Logging', 'daily-digest' ); ?></h2>
				<p>
					<label>
						<input type="checkbox" name="daily_digest_enable_logging" value="1" <?php \checked( $logging_enabled ); ?> />
						<?php \esc_html_e( 'Enable API request/response logging for provider activity.', 'daily-digest' ); ?>
					</label>
				</p>
				<?php if ( ! empty( $logging_directory ) ) : ?>
					<p>
						<?php
						echo \esc_html__( 'Logs are written to:', 'daily-digest' ) . ' ';
						echo '<code>' . \esc_html( $logging_directory ) . '</code>';
						?>
					</p>
				<?php endif; ?>
				<?php if ( ! empty( $logging_url ) ) : ?>
					<p>
						<a class="button button-secondary" href="<?php echo \esc_url( $logging_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php \esc_html_e( 'View Logs Directory', 'daily-digest' ); ?>
						</a>
					</p>
				<?php endif; ?>
				<p>
					<?php \submit_button( \__( 'Save Logging Settings', 'daily-digest' ), 'primary', 'daily_digest_save_log_settings', false ); ?>
					<?php \submit_button( \__( 'Run Logging Self-Test', 'daily-digest' ), 'secondary', 'daily_digest_run_log_test', false ); ?>
				</p>
			</form>

			<h2><?php \esc_html_e( 'Log Viewer', 'daily-digest' ); ?></h2>
			<div id="daily-digest-log-viewer-app">
				<p><?php \esc_html_e( 'Loading logs…', 'daily-digest' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders provider connection metadata as plain lines.
	 *
	 * @param string               $provider_slug Provider slug.
	 * @param array<string, mixed> $meta          Raw token metadata.
	 *
	 * @return string
	 */
	private function render_connection_meta( string $provider_slug, array $meta ): string {
		if ( empty( $meta ) ) {
			return \esc_html__( 'No metadata available.', 'daily-digest' );
		}

		$labels = $this->get_connection_meta_labels( $provider_slug );
		$lines  = array();

		foreach ( $labels as $meta_key => $label ) {
			if ( ! array_key_exists( $meta_key, $meta ) ) {
				continue;
			}

			$formatted_value = $this->format_connection_meta_value( $meta[ $meta_key ] );
			if ( '' === $formatted_value ) {
				continue;
			}

			$lines[] = '<strong>' . \esc_html( $label ) . ':</strong> ' . \esc_html( $formatted_value );
		}

		if ( empty( $lines ) ) {
			return \esc_html__( 'No metadata available.', 'daily-digest' );
		}

		return implode( '<br />', $lines );
	}

	/**
	 * Returns a short summary string for connected account metadata.
	 *
	 * @param string               $provider_slug Provider slug.
	 * @param array<string, mixed> $meta          Raw token metadata.
	 *
	 * @return string
	 */
	private function get_connection_meta_summary( string $provider_slug, array $meta ): string {
		if ( empty( $meta ) ) {
			return __( 'an unknown account', 'daily-digest' );
		}

		$priority_keys = array(
			'github'  => array( 'name', 'username', 'profile_url' ),
			'clickup' => array( 'name', 'username', 'team_names', 'user_id' ),
			'slack'   => array( 'user', 'name', 'team', 'team_domain', 'user_id' ),
		);

		$keys = $priority_keys[ $provider_slug ] ?? array( 'name', 'username', 'user_id' );

		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $meta ) ) {
				continue;
			}

			$value = $this->format_connection_meta_value( $meta[ $key ] );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return __( 'an unknown account', 'daily-digest' );
	}

	/**
	 * Returns known metadata label mappings by provider.
	 *
	 * @param string $provider_slug Provider slug.
	 *
	 * @return array<string, string>
	 */
	private function get_connection_meta_labels( string $provider_slug ): array {
		$map = array(
			'github'  => array(
				'name'        => __( 'Name', 'daily-digest' ),
				'username'    => __( 'Username', 'daily-digest' ),
				'profile_url' => __( 'Profile URL', 'daily-digest' ),
			),
			'clickup' => array(
				'name'            => __( 'Name', 'daily-digest' ),
				'username'        => __( 'Username', 'daily-digest' ),
				'user_id'         => __( 'User ID', 'daily-digest' ),
				'team_names'      => __( 'Teams', 'daily-digest' ),
				'default_team_id' => __( 'Default Team ID', 'daily-digest' ),
			),
			'slack'   => array(
				'user'        => __( 'Account', 'daily-digest' ),
				'team'        => __( 'Workspace', 'daily-digest' ),
				'team_domain' => __( 'Team Domain', 'daily-digest' ),
				'user_id'     => __( 'User ID', 'daily-digest' ),
				'team_url'    => __( 'Team URL', 'daily-digest' ),
			),
		);

		return $map[ $provider_slug ] ?? array();
	}

	/**
	 * Formats metadata values for display.
	 *
	 * @param mixed $value Raw metadata value.
	 *
	 * @return string
	 */
	private function format_connection_meta_value( $value ): string {
		if ( is_scalar( $value ) ) {
			return trim( (string) $value );
		}

		if ( is_array( $value ) ) {
			$values = array_filter(
				array_map(
					static function ( $item ): string {
						return is_scalar( $item ) ? trim( (string) $item ) : '';
					},
					$value
				)
			);

			return implode( ', ', $values );
		}

		return '';
	}

	/**
	 * Saves provider settings for the current user when submitted.
	 *
	 * @param int $user_id User ID.
	 */
	private function maybe_save_settings( int $user_id ): void {
		if ( 'POST' !== \strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		if ( ! \current_user_can( 'read' ) ) {
			return;
		}

		$nonce = $_POST['daily_digest_nonce'] ?? '';
		if ( ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $nonce ) ), 'daily_digest_save_settings' ) ) {
			return;
		}

		$settings = $_POST['daily_digest_settings'] ?? array();
		if ( ! \is_array( $settings ) ) {
			$settings = array();
		}

		$this->user_settings->save_for_user( $user_id, \wp_unslash( $settings ) );

		echo '<div class="notice notice-success is-dismissible"><p>' . \esc_html__( 'Daily Digest settings saved.', 'daily-digest' ) . '</p></div>';
	}

	/**
	 * Saves logging settings and optionally runs a logging self-test.
	 */
	private function maybe_save_log_settings(): void {
		if ( 'POST' !== \strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce = $_POST['daily_digest_log_nonce'] ?? '';
		if ( ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $nonce ) ), 'daily_digest_save_log_settings' ) ) {
			return;
		}

		$enable_logging = ! empty( $_POST['daily_digest_enable_logging'] );
		$this->api_logger->update_enabled( $enable_logging );

		echo '<div class="notice notice-success is-dismissible"><p>' . \esc_html__( 'Logging settings saved.', 'daily-digest' ) . '</p></div>';

		$run_log_test = ! empty( $_POST['daily_digest_run_log_test'] );
		if ( ! $run_log_test ) {
			return;
		}

		if ( ! $this->api_logger->is_enabled() ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . \esc_html__( 'Enable logging before running the self-test.', 'daily-digest' ) . '</p></div>';
			return;
		}

		$provider_slugs = array_keys( $this->provider_registry->all() );
		$test_results   = $this->api_logger->run_self_test( $provider_slugs );
		$summary        = sprintf(
			/* translators: 1: successful requests, 2: total requests. */
			\esc_html__( 'Logging self-test complete. %1$d/%2$d requests succeeded.', 'daily-digest' ),
			(int) $test_results['success'],
			(int) $test_results['total']
		);

		echo '<div class="notice notice-info is-dismissible"><p>' . \esc_html( $summary ) . '</p></div>';

		if ( ! empty( $test_results['errors'] ) && \is_array( $test_results['errors'] ) ) {
			$errors = array_map( 'sanitize_text_field', $test_results['errors'] );
			echo '<div class="notice notice-warning is-dismissible"><p>' . \esc_html__( 'Self-test errors:', 'daily-digest' ) . ' ' . \esc_html( implode( ' | ', $errors ) ) . '</p></div>';
		}
	}
}
