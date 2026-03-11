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
	 * Constructor.
	 *
	 * @param ProviderRegistry $provider_registry Provider registry.
	 * @param UserSettings     $user_settings     User settings service.
	 * @param DigestService    $digest_service    Digest service.
	 * @param ApiLogger        $api_logger        API logger service.
	 */
	public function __construct( ProviderRegistry $provider_registry, UserSettings $user_settings, DigestService $digest_service, ApiLogger $api_logger ) {
		$this->provider_registry = $provider_registry;
		$this->user_settings     = $user_settings;
		$this->digest_service    = $digest_service;
		$this->api_logger        = $api_logger;
	}

	/**
	 * Hooks page registration into wp-admin.
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'register_pages' ) );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_overview_assets' ) );
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
	 * Enqueues built React/DataViews assets for the overview page.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 */
	public function enqueue_overview_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_daily-digest' !== $hook_suffix ) {
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

		\wp_enqueue_script(
			'daily-digest-overview',
			DAILY_DIGEST_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? DAILY_DIGEST_PLUGIN_VERSION,
			true
		);

		$styles_file = DAILY_DIGEST_PLUGIN_PATH . 'build/style-index.css';
		if ( \file_exists( $styles_file ) ) {
			\wp_enqueue_style(
				'daily-digest-overview',
				DAILY_DIGEST_PLUGIN_URL . 'build/style-index.css',
				array( 'wp-components' ),
				$asset['version'] ?? DAILY_DIGEST_PLUGIN_VERSION
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter input used only for app initialization.
		$days = isset( $_GET['days'] ) ? \max( 1, \absint( $_GET['days'] ) ) : 1;

		$config = array(
			'restRoot'    => \esc_url_raw( \rest_url( 'daily-digest/v1' ) ),
			'restNonce'   => \wp_create_nonce( 'wp_rest' ),
			'currentUser' => \get_current_user_id(),
			'initialDays' => $days,
			'settingsUrl' => \admin_url( 'admin.php?page=daily-digest-settings' ),
			'i18n'        => array(
				'digestTitle' => \__( 'Digest', 'daily-digest' ),
				'daysLabel'   => \__( 'Window (days):', 'daily-digest' ),
				'refresh'     => \__( 'Refresh Digest', 'daily-digest' ),
				'loading'     => \__( 'Loading activity…', 'daily-digest' ),
				'loadError'   => \__( 'Unable to load digest data.', 'daily-digest' ),
				'noItems'     => \__( 'No activity found for enabled providers.', 'daily-digest' ),
				'time'        => \__( 'Time', 'daily-digest' ),
				'provider'    => \__( 'Provider', 'daily-digest' ),
				'type'        => \__( 'Type', 'daily-digest' ),
				'title'       => \__( 'Title', 'daily-digest' ),
				'summary'     => \__( 'Summary', 'daily-digest' ),
				'openItem'    => \__( 'Open item', 'daily-digest' ),
			),
		);

		\wp_add_inline_script(
			'daily-digest-overview',
			'window.dailyDigestOverviewConfig = ' . \wp_json_encode( $config ) . ';',
			'before'
		);
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
		$current_settings   = $this->user_settings->get_for_user( $current_user_id );
		$can_manage_options = \current_user_can( 'manage_options' );
		$logging_enabled    = $this->api_logger->is_enabled();
		$logging_directory  = $this->api_logger->get_log_directory_path();
		$logging_url        = $this->api_logger->get_log_directory_url();
		$rest_root          = \esc_url_raw( \rest_url( 'daily-digest/v1' ) );
		$nonce              = \wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Daily Digest Settings', 'daily-digest' ); ?></h1>
			<p><?php \esc_html_e( 'Enable and configure providers for your digest.', 'daily-digest' ); ?></p>

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
							?>
							<tr data-provider="<?php echo \esc_attr( $slug ); ?>">
								<th scope="row"><?php echo \esc_html( $provider->get_name() ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="daily_digest_settings[<?php echo \esc_attr( $slug ); ?>][enabled]" value="1" <?php \checked( $enabled ); ?> />
										<?php \esc_html_e( 'Enable provider', 'daily-digest' ); ?>
									</label>
									<?php $setup_links = $this->get_provider_setup_links( $slug ); ?>
									<?php if ( ! empty( $setup_links ) ) : ?>
										<p>
											<strong><?php \esc_html_e( 'Setup Docs:', 'daily-digest' ); ?></strong>
											<?php
											$rendered_links = array();
											foreach ( $setup_links as $label => $url ) {
												$rendered_links[] = '<a href="' . \esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . \esc_html( $label ) . '</a>';
											}
											echo wp_kses_post( implode( ' | ', $rendered_links ) );
											?>
										</p>
									<?php endif; ?>
									<?php foreach ( $provider->get_fields() as $field_key => $field_label ) : ?>
										<p>
											<label>
												<?php echo \esc_html( $field_label ); ?><br />
												<input class="regular-text" type="text" name="daily_digest_settings[<?php echo \esc_attr( $slug ); ?>][fields][<?php echo \esc_attr( $field_key ); ?>]" value="<?php echo \esc_attr( $fields[ $field_key ] ?? '' ); ?>" />
											</label>
										</p>
									<?php endforeach; ?>
									<p>
										<button type="button" class="button button-secondary daily-digest-test-credentials" data-provider="<?php echo \esc_attr( $slug ); ?>">
											<?php \esc_html_e( 'Test Credentials', 'daily-digest' ); ?>
										</button>
										<button type="button" class="button button-primary daily-digest-save-credentials" data-provider="<?php echo \esc_attr( $slug ); ?>">
											<?php \esc_html_e( 'Save Provider', 'daily-digest' ); ?>
										</button>
										<span class="daily-digest-test-result" id="daily-digest-test-result-<?php echo \esc_attr( $slug ); ?>" style="margin-left:8px;"></span>
										<span class="daily-digest-save-result" id="daily-digest-save-result-<?php echo \esc_attr( $slug ); ?>" style="margin-left:8px;"></span>
									</p>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $can_manage_options ) : ?>
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
						<?php \submit_button( \__( 'Run Logging Self-Test', 'daily-digest' ), 'secondary', 'daily_digest_run_log_test', false ); ?>
					</p>
				<?php endif; ?>

				<?php \submit_button( \__( 'Save Settings', 'daily-digest' ) ); ?>
			</form>
		</div>
		<script>
		(function() {
			const restRoot = <?php echo \wp_json_encode( $rest_root ); ?>;
			const restNonce = <?php echo \wp_json_encode( $nonce ); ?>;
			const buttons = document.querySelectorAll('.daily-digest-test-credentials');
			const saveButtons = document.querySelectorAll('.daily-digest-save-credentials');

			const setResult = (provider, text, ok) => {
				const el = document.getElementById(`daily-digest-test-result-${provider}`);
				if (!el) {
					return;
				}
				el.textContent = text;
				el.style.color = ok ? '#0a7d18' : '#b32d2e';
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
				if (!el) {
					return;
				}
				el.textContent = text;
				el.style.color = ok ? '#0a7d18' : '#b32d2e';
			};

			buttons.forEach((button) => {
				button.addEventListener('click', async () => {
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

			saveButtons.forEach((button) => {
				button.addEventListener('click', async () => {
					const provider = button.getAttribute('data-provider') || '';
					if (!provider) {
						return;
					}

					button.disabled = true;
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
						const message = payload && payload.message ? payload.message : <?php echo \wp_json_encode( __( 'Unable to save provider credentials.', 'daily-digest' ) ); ?>;
						setSaveResult(provider, message, false);
						button.disabled = false;
						return;
					}

					setSaveResult(provider, payload.message || <?php echo \wp_json_encode( __( 'Provider credentials saved.', 'daily-digest' ) ); ?>, true);
					button.disabled = false;
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Returns setup documentation links for a provider slug.
	 *
	 * @param string $provider_slug Provider slug.
	 *
	 * @return array<string, string>
	 */
	private function get_provider_setup_links( string $provider_slug ): array {
		$docs = array(
			'github'  => array(
				'Create Personal Access Token' => 'https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens',
				'GitHub API Authentication'    => 'https://docs.github.com/en/rest/authentication/authenticating-to-the-rest-api',
			),
			'clickup' => array(
				'ClickUp API Authentication' => 'https://developer.clickup.com/docs/authentication',
				'Generate Personal Token'    => 'https://help.clickup.com/hc/en-us/articles/6303426241687-Use-the-ClickUp-API',
			),
			'slack'   => array(
				'Create Slack App'                      => 'https://api.slack.com/apps',
				'OAuth & Permissions (Bot/User Tokens)' => 'https://api.slack.com/authentication/oauth-v2',
				'Find Slack User ID'                    => 'https://api.slack.com/methods/users.lookupByEmail',
			),
		);

		return $docs[ $provider_slug ] ?? array();
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

		if ( \current_user_can( 'manage_options' ) ) {
			$enable_logging = ! empty( $_POST['daily_digest_enable_logging'] );
			$this->api_logger->update_enabled( $enable_logging );

			$run_log_test = ! empty( $_POST['daily_digest_run_log_test'] );
			if ( $run_log_test ) {
				if ( ! $this->api_logger->is_enabled() ) {
					echo '<div class="notice notice-warning is-dismissible"><p>' . \esc_html__( 'Enable logging before running the self-test.', 'daily-digest' ) . '</p></div>';
				} else {
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
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . \esc_html__( 'Daily Digest settings saved.', 'daily-digest' ) . '</p></div>';
	}
}
