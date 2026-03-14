<?php
/**
 * Daily Digest Harvest PAT Keyring service.
 *
 * @package DailyDigest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Daily_Digest_Keyring_Service_Harvest_PAT' ) && class_exists( 'Daily_Digest_Keyring_Service_Base' ) ) {
	/**
	 * Harvest personal access token provider for Daily Digest.
	 */
	class Daily_Digest_Keyring_Service_Harvest_PAT extends Daily_Digest_Keyring_Service_Base {
		const NAME  = 'daily_digest_harvest_pat';
		const LABEL = 'Daily Digest Harvest Personal Token';

		/**
		 * Constructor.
		 */
		public function __construct() {
			parent::__construct();

			if ( ! KEYRING__HEADLESS_MODE ) {
				add_action( 'keyring_daily_digest_harvest_pat_manage_ui', array( $this, 'manage_ui' ) );
			}
		}

		/**
		 * Harvest PAT mode requires explicit service setup acknowledgement in Keyring.
		 *
		 * @return bool
		 */
		public function is_configured() {
			$creds = $this->get_credentials();

			return is_array( $creds ) && ! empty( $creds['configured'] );
		}

		/**
		 * Renders Keyring management UI for the Harvest PAT service.
		 */
		public function manage_ui() {
			if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'keyring-manage-' . $this->get_name() ) ) {
				Keyring::error( __( 'Invalid/missing management nonce.', 'keyring' ) );
				exit;
			}

			if ( isset( $_POST['dd_harvest_pat_configured'] ) ) {
				$enabled = '1' === (string) wp_unslash( $_POST['dd_harvest_pat_configured'] );
				$this->update_credentials(
					array(
						'configured' => $enabled ? '1' : '',
					)
				);

				Keyring::message( __( 'Harvest personal token service settings saved.', 'daily-digest' ) );
			}

			$creds        = $this->get_credentials();
			$configured   = is_array( $creds ) && ! empty( $creds['configured'] );
			$services_url = Keyring_Util::admin_url( false, array( 'action' => 'services' ) );

			echo '<div class="wrap">';
			echo '<h2>' . esc_html__( 'Keyring Service Management', 'keyring' ) . '</h2>';
			echo '<p><a href="' . esc_url( $services_url ) . '">' . esc_html__( '&larr; Back', 'keyring' ) . '</a></p>';
			echo '<h3>' . esc_html__( 'Daily Digest Harvest Personal Token', 'daily-digest' ) . '</h3>';
			echo '<p>' . esc_html__( 'Daily Digest uses a Harvest personal token and account ID entered during the connection step. No client ID or secret is required.', 'daily-digest' ) . '</p>';
			echo '<p>' . esc_html__( 'Enable this service to allow users to connect Harvest via personal access token.', 'daily-digest' ) . '</p>';
			echo '<form method="post" action="">';
			echo '<input type="hidden" name="service" value="' . esc_attr( $this->get_name() ) . '" />';
			echo '<input type="hidden" name="action" value="manage" />';
			wp_nonce_field( 'keyring-manage', 'kr_nonce', false );
			wp_nonce_field( 'keyring-manage-' . $this->get_name(), 'nonce', false );
			echo '<table class="form-table">';
			echo '<tr>';
			echo '<th scope="row">' . esc_html__( 'Service Enabled', 'daily-digest' ) . '</th>';
			echo '<td>';
			echo '<label for="dd-harvest-pat-configured">';
			echo '<input type="checkbox" id="dd-harvest-pat-configured" name="dd_harvest_pat_configured" value="1" ' . checked( $configured, true, false ) . ' /> ';
			echo esc_html__( 'Enable Harvest personal-token connections for Daily Digest', 'daily-digest' );
			echo '</label>';
			echo '</td>';
			echo '</tr>';
			echo '</table>';

			echo '<p class="submitbox">';
			echo '<input type="submit" name="submit" value="' . esc_attr__( 'Save Changes', 'keyring' ) . '" id="submit" class="button-primary" />';
			echo '<a href="' . esc_url( $services_url ) . '" class="submitdelete" style="margin-left:2em;">' . esc_html__( 'Cancel', 'keyring' ) . '</a>';
			echo '</p>';
			echo '</form>';
			echo '</div>';
		}

		/**
		 * Returns Harvest token instructions.
		 *
		 * @return string
		 */
		protected function get_token_help_text() {
			return __( 'Paste a Harvest personal access token and account ID from your Harvest developer settings.', 'daily-digest' );
		}

		/**
		 * Renders extra form row for required Harvest account ID.
		 *
		 * @return string
		 */
		protected function get_additional_fields_ui() {
			return '<tr><th scope="row"><label for="dd-harvest-account-id">' . esc_html__( 'Account ID', 'daily-digest' ) . '</label></th><td><input type="text" class="regular-text" id="dd-harvest-account-id" name="account_id" required /></td></tr>';
		}

		/**
		 * Adds account metadata extracted from token request form.
		 *
		 * @return array<string, mixed>
		 */
		protected function extract_additional_meta() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Base verify_token() validates keyring-verify nonce before calling this method.
			$account_id = isset( $_POST['account_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) ) : '';

			if ( '' === $account_id ) {
				return array();
			}

			return array(
				'account_id' => $account_id,
			);
		}

		/**
		 * Verifies a Harvest personal token.
		 *
		 * @param string $token Token string.
		 *
		 * @return array{success:bool,message:string,meta?:array<string,mixed>}
		 */
		protected function verify_access_token( $token ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Base verify_token() validates keyring-verify nonce before calling this method.
			$account_id = isset( $_POST['account_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) ) : '';

			if ( '' === $account_id ) {
				return array(
					'success' => false,
					'message' => __( 'Harvest account ID is required.', 'daily-digest' ),
				);
			}

			$response = wp_remote_get(
				'https://api.harvestapp.com/v2/users/me',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization'      => 'Bearer ' . $token,
						'Harvest-Account-Id' => $account_id,
						'User-Agent'         => $this->build_user_agent(),
						'Accept'             => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'message' => $response->get_error_message(),
				);
			}

			$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $payload ) || empty( $payload['id'] ) ) {
				return array(
					'success' => false,
					'message' => __( 'Harvest token verification failed.', 'daily-digest' ),
				);
			}

			$name = trim(
				implode(
					' ',
					array_filter(
						array(
							isset( $payload['first_name'] ) ? (string) $payload['first_name'] : '',
							isset( $payload['last_name'] ) ? (string) $payload['last_name'] : '',
						)
					)
				)
			);

			return array(
				'success' => true,
				'message' => __( 'Harvest token verified.', 'daily-digest' ),
				'meta'    => array(
					'name'       => '' !== $name ? $name : ( isset( $payload['email'] ) ? (string) $payload['email'] : '' ),
					'email'      => isset( $payload['email'] ) ? (string) $payload['email'] : '',
					'user_id'    => isset( $payload['id'] ) ? (string) $payload['id'] : '',
					'account_id' => $account_id,
				),
			);
		}

		/**
		 * Tests whether the current personal token is valid.
		 *
		 * @return bool|mixed True on success, or response/error details on failure.
		 */
		public function test_connection() {
			if ( empty( $this->token ) ) {
				return array(
					'message' => __( 'No Harvest token is set for testing.', 'daily-digest' ),
				);
			}

			$account_id = '';
			if ( is_object( $this->token ) && method_exists( $this->token, 'get_meta' ) ) {
				$account_id = (string) $this->token->get_meta( 'account_id' );
			}

			if ( '' === trim( $account_id ) ) {
				return array(
					'message' => __( 'No Harvest account ID is set for this token.', 'daily-digest' ),
				);
			}

			$response = wp_remote_get(
				'https://api.harvestapp.com/v2/users/me',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization'      => 'Bearer ' . (string) $this->token,
						'Harvest-Account-Id' => $account_id,
						'User-Agent'         => $this->build_user_agent(),
						'Accept'             => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return array(
					'message' => __( 'Harvest token verification failed.', 'daily-digest' ),
				);
			}

			return true;
		}

		/**
		 * Builds a Harvest-compatible User-Agent header value.
		 *
		 * @return string
		 */
		private function build_user_agent(): string {
			$site_name = (string) get_bloginfo( 'name' );
			$admin     = (string) get_bloginfo( 'admin_email' );

			if ( '' !== trim( $site_name ) && '' !== trim( $admin ) ) {
				return trim( $site_name ) . ' (' . trim( $admin ) . ')';
			}

			return 'DailyDigestWP/' . DAILY_DIGEST_PLUGIN_VERSION;
		}
	}
}
