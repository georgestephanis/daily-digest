<?php
/**
 * Daily Digest ClickUp Keyring service.
 *
 * @package DailyDigest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Daily_Digest_Keyring_Service_Clickup' ) && class_exists( 'Daily_Digest_Keyring_Service_Base' ) ) {
	/**
	 * ClickUp token provider for Daily Digest.
	 */
	class Daily_Digest_Keyring_Service_Clickup extends Daily_Digest_Keyring_Service_Base {
		const NAME  = 'daily_digest_clickup';
		const LABEL = 'Daily Digest ClickUp';

		/**
		 * Constructor.
		 */
		public function __construct() {
			parent::__construct();

			if ( ! KEYRING__HEADLESS_MODE ) {
				add_action( 'keyring_daily_digest_clickup_manage_ui', array( $this, 'manage_ui' ) );
			}
		}

		/**
		 * ClickUp requires explicit service setup acknowledgement in Keyring.
		 *
		 * @return bool
		 */
		public function is_configured() {
			$creds = $this->get_credentials();

			return is_array( $creds ) && ! empty( $creds['configured'] );
		}

		/**
		 * Renders Keyring management UI for the ClickUp service.
		 */
		public function manage_ui() {
			if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'keyring-manage-' . $this->get_name() ) ) {
				Keyring::error( __( 'Invalid/missing management nonce.', 'keyring' ) );
				exit;
			}

			if ( isset( $_POST['dd_clickup_configured'] ) ) {
				$enabled = '1' === (string) wp_unslash( $_POST['dd_clickup_configured'] );

				$this->update_credentials(
					array(
						'configured' => $enabled ? '1' : '',
					)
				);

				Keyring::message( __( 'ClickUp service settings saved.', 'daily-digest' ) );
			}

			$creds        = $this->get_credentials();
			$configured   = is_array( $creds ) && ! empty( $creds['configured'] );
			$services_url = Keyring_Util::admin_url( false, array( 'action' => 'services' ) );

			echo '<div class="wrap">';
			echo '<h2>' . esc_html__( 'Keyring Service Management', 'keyring' ) . '</h2>';
			echo '<p><a href="' . esc_url( $services_url ) . '">' . esc_html__( '&larr; Back', 'keyring' ) . '</a></p>';
			echo '<h3>' . esc_html__( 'Daily Digest ClickUp', 'daily-digest' ) . '</h3>';
			echo '<p>' . esc_html__( 'Daily Digest uses a ClickUp personal token entered during the connection step. No client ID or secret is required.', 'daily-digest' ) . '</p>';
			echo '<p>' . esc_html__( 'Enable this service to allow users to connect ClickUp via Keyring.', 'daily-digest' ) . '</p>';

			echo '<form method="post" action="">';
			echo '<input type="hidden" name="service" value="' . esc_attr( $this->get_name() ) . '" />';
			echo '<input type="hidden" name="action" value="manage" />';
			wp_nonce_field( 'keyring-manage', 'kr_nonce', false );
			wp_nonce_field( 'keyring-manage-' . $this->get_name(), 'nonce', false );

			echo '<table class="form-table">';
			echo '<tr>';
			echo '<th scope="row">' . esc_html__( 'Service Enabled', 'daily-digest' ) . '</th>';
			echo '<td>';
			echo '<label for="dd-clickup-configured">';
			echo '<input type="checkbox" id="dd-clickup-configured" name="dd_clickup_configured" value="1" ' . checked( $configured, true, false ) . ' /> ';
			echo esc_html__( 'Enable ClickUp connections for Daily Digest', 'daily-digest' );
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
		 * Returns ClickUp token instructions.
		 *
		 * @return string
		 */
		protected function get_token_help_text() {
			return __( 'Paste a ClickUp personal API token.', 'daily-digest' );
		}

		/**
		 * Verifies a ClickUp token.
		 *
		 * @param string $token Token string.
		 *
		 * @return array{success:bool,message:string,meta?:array<string,mixed>}
		 */
		protected function verify_access_token( $token ) {
			$response = wp_remote_get(
				'https://api.clickup.com/api/v2/user',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => $token,
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
			if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 || ! is_array( $payload ) || empty( $payload['user']['username'] ) ) {
				return array(
					'success' => false,
					'message' => __( 'ClickUp token verification failed.', 'daily-digest' ),
				);
			}

			$teams_response = wp_remote_get(
				'https://api.clickup.com/api/v2/team',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => $token,
					),
				)
			);

			$team_ids     = array();
			$team_names   = array();
			$default_team = '';

			if ( ! is_wp_error( $teams_response ) && 200 === (int) wp_remote_retrieve_response_code( $teams_response ) ) {
				$teams_payload = json_decode( (string) wp_remote_retrieve_body( $teams_response ), true );
				$teams         = isset( $teams_payload['teams'] ) && is_array( $teams_payload['teams'] ) ? $teams_payload['teams'] : array();

				foreach ( $teams as $team ) {
					if ( ! is_array( $team ) || empty( $team['id'] ) ) {
						continue;
					}

					$team_ids[] = (string) $team['id'];
					if ( ! empty( $team['name'] ) ) {
						$team_names[] = (string) $team['name'];
					}
				}

				if ( ! empty( $team_ids ) ) {
					$default_team = (string) $team_ids[0];
				}
			}

			return array(
				'success' => true,
				'message' => __( 'ClickUp token verified.', 'daily-digest' ),
				'meta'    => array(
					'username'        => (string) $payload['user']['username'],
					'name'            => (string) $payload['user']['username'],
					'user_id'         => isset( $payload['user']['id'] ) ? (string) $payload['user']['id'] : '',
					'team_ids'        => $team_ids,
					'team_names'      => $team_names,
					'default_team_id' => $default_team,
				),
			);
		}
	}
}
