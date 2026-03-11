<?php

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
	 * Constructor.
	 *
	 * @param ProviderRegistry $provider_registry Provider registry.
	 * @param UserSettings     $user_settings     User settings service.
	 * @param DigestService    $digest_service    Digest service.
	 */
	public function __construct( ProviderRegistry $provider_registry, UserSettings $user_settings, DigestService $digest_service ) {
		$this->provider_registry = $provider_registry;
		$this->user_settings     = $user_settings;
		$this->digest_service    = $digest_service;
	}

	/**
	 * Hooks page registration into wp-admin.
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/**
	 * Registers Users > Daily Digest menu page.
	 */
	public function register_page(): void {
		\add_users_page(
			\__( 'Daily Digest', 'daily-digest' ),
			\__( 'Daily Digest', 'daily-digest' ),
			'read',
			'daily-digest',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders settings and digest for the current user.
	 */
	public function render_page(): void {
		if ( ! \is_user_logged_in() ) {
			\wp_die( \esc_html__( 'You must be logged in to view this page.', 'daily-digest' ) );
		}

		$current_user_id = \get_current_user_id();
		$this->maybe_save_settings( $current_user_id );

		$current_settings = $this->user_settings->get_for_user( $current_user_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter input that does not change data.
		$days         = isset( $_GET['days'] ) ? \max( 1, \absint( $_GET['days'] ) ) : 1;
		$digest_items = $this->digest_service->get_digest_for_user(
			$current_user_id,
			array(
				'days' => $days,
			)
		);
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Daily Digest', 'daily-digest' ); ?></h1>
			<p><?php \esc_html_e( 'Connect providers and view a unified activity digest.', 'daily-digest' ); ?></p>

			<h2><?php \esc_html_e( 'Provider Settings', 'daily-digest' ); ?></h2>
			<form method="post">
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
							<tr>
								<th scope="row"><?php echo \esc_html( $provider->get_name() ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="daily_digest_settings[<?php echo \esc_attr( $slug ); ?>][enabled]" value="1" <?php \checked( $enabled ); ?> />
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
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php \submit_button( \__( 'Save Settings', 'daily-digest' ) ); ?>
			</form>

			<hr />
			<h2><?php \esc_html_e( 'Digest', 'daily-digest' ); ?></h2>
			<form method="get" style="margin-bottom: 1em;">
				<input type="hidden" name="page" value="daily-digest" />
				<label>
					<?php \esc_html_e( 'Window (days):', 'daily-digest' ); ?>
					<input type="number" min="1" max="30" name="days" value="<?php echo \esc_attr( (string) $days ); ?>" />
				</label>
				<?php \submit_button( \__( 'Refresh Digest', 'daily-digest' ), 'secondary', '', false ); ?>
			</form>

			<?php if ( empty( $digest_items ) ) : ?>
				<p><?php \esc_html_e( 'No activity found for enabled providers.', 'daily-digest' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php \esc_html_e( 'Time', 'daily-digest' ); ?></th>
							<th><?php \esc_html_e( 'Provider', 'daily-digest' ); ?></th>
							<th><?php \esc_html_e( 'Type', 'daily-digest' ); ?></th>
							<th><?php \esc_html_e( 'Title', 'daily-digest' ); ?></th>
							<th><?php \esc_html_e( 'Summary', 'daily-digest' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $digest_items as $item ) : ?>
							<tr>
								<td><?php echo \esc_html( \get_date_from_gmt( $item['timestamp'], 'Y-m-d H:i:s' ) ); ?></td>
								<td><?php echo \esc_html( $item['provider'] ); ?></td>
								<td><?php echo \esc_html( $item['type'] ); ?></td>
								<td>
									<?php if ( ! empty( $item['url'] ) ) : ?>
										<a href="<?php echo \esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo \esc_html( $item['title'] ); ?></a>
									<?php else : ?>
										<?php echo \esc_html( $item['title'] ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo \esc_html( $item['summary'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
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
}
