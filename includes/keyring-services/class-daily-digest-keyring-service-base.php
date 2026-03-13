<?php
/**
 * Daily Digest Keyring service base classes.
 *
 * @package DailyDigest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Daily_Digest_Keyring_Service_Base' ) && class_exists( 'Keyring_Service' ) ) {
	/**
	 * Base class for manual token-based Daily Digest Keyring services.
	 */
	abstract class Daily_Digest_Keyring_Service_Base extends Keyring_Service {
		/**
		 * Constructor.
		 */
		public function __construct() {
			parent::__construct();

			if ( ! KEYRING__HEADLESS_MODE ) {
				add_action( 'keyring_' . $this->get_name() . '_request_ui', array( $this, 'request_ui' ) );
			}
		}

		/**
		 * Returns provider-specific token instructions.
		 *
		 * @return string
		 */
		abstract protected function get_token_help_text();

		/**
		 * Verifies a token with the remote API.
		 *
		 * @param string $token Token string.
		 *
		 * @return array{success:bool,message:string,meta?:array<string,mixed>}
		 */
		abstract protected function verify_access_token( $token );

		/**
		 * Starts token request flow.
		 *
		 * Keyring invokes this on admin_init before page rendering, so it should
		 * not output markup directly.
		 */
		public function request_token() {
			// Intentionally empty: Keyring runs this action during admin_init.
			// Rendering is handled by request_ui() on keyring_{service}_request_ui.
		}

		/**
		 * Renders token request UI.
		 */
		public function request_ui() {
			if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'keyring-request-' . $this->get_name() ) ) {
				Keyring::error( __( 'Invalid/missing request nonce.', 'keyring' ) );
				exit;
			}

			echo '<div class="wrap">';
			/* translators: %s is the provider label, such as Slack or ClickUp. */
			echo '<h2>' . esc_html( sprintf( __( 'Connect %s', 'daily-digest' ), $this->get_label() ) ) . '</h2>';
			echo '<p><a href="' . esc_url( Keyring_Util::admin_url( false, array( 'action' => 'tokens' ) ) ) . '">' . esc_html__( '&larr; Back to Connections', 'daily-digest' ) . '</a></p>';
			echo '<p>' . wp_kses_post( $this->get_token_help_text() ) . '</p>';
			echo '<form method="post" action="">';
			echo '<input type="hidden" name="action" value="verify" />';
			echo '<input type="hidden" name="service" value="' . esc_attr( $this->get_name() ) . '" />';
			wp_nonce_field( 'keyring-verify', 'kr_nonce', false );
			wp_nonce_field( 'keyring-verify-' . $this->get_name(), 'nonce', false );
			echo '<table class="form-table">';
			echo '<tr><th scope="row"><label for="dd-keyring-token">' . esc_html__( 'Access Token', 'daily-digest' ) . '</label></th>';
			echo '<td><input type="text" class="regular-text" id="dd-keyring-token" name="token" required /></td></tr>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Subclasses return trusted table row markup for this form.
			echo $this->get_additional_fields_ui();
			echo '</table>';
			echo '<p class="submit">';
			echo '<input type="submit" class="button button-primary" value="' . esc_attr__( 'Save Connection', 'daily-digest' ) . '" />';
			echo '</p>';
			echo '</form>';
			echo '</div>';
		}

		/**
		 * Verifies and stores the submitted token.
		 */
		public function verify_token() {
			if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'keyring-verify-' . $this->get_name() ) ) {
				Keyring::error( __( 'Invalid/missing verification nonce.', 'keyring' ) );
				exit;
			}

			$token = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
			if ( '' === $token ) {
				Keyring::error( __( 'A token is required to create the connection.', 'daily-digest' ) );
				return;
			}

			$verification = $this->verify_access_token( $token );
			if ( empty( $verification['success'] ) ) {
				Keyring::error( ! empty( $verification['message'] ) ? (string) $verification['message'] : __( 'Token verification failed.', 'daily-digest' ) );
				return;
			}

			$meta = isset( $verification['meta'] ) && is_array( $verification['meta'] ) ? $verification['meta'] : array();
			$meta = array_merge( $meta, $this->extract_additional_meta() );

			$access_token = new Keyring_Access_Token( $this->get_name(), $token, $meta );
			$id           = $this->store_token( $access_token );

			if ( ! $id ) {
				Keyring::error( __( 'Unable to store the token.', 'daily-digest' ) );
				return;
			}

			$this->verified( $id );
		}

		/**
		 * Performs an authenticated request.
		 *
		 * @param string $url URL to request.
		 * @param array  $params Request options.
		 *
		 * @return mixed
		 */
		public function request( $url, array $params ) {
			if ( $this->requires_token() && empty( $this->token ) ) {
				return new Keyring_Error( 'keyring-request-error', __( 'No token.', 'daily-digest' ) );
			}

			$params['headers'] = isset( $params['headers'] ) && is_array( $params['headers'] ) ? $params['headers'] : array();
			if ( ! empty( $this->token ) ) {
				$params['headers']['Authorization'] = 'Bearer ' . (string) $this->token;
			}

			$response = wp_remote_request( $url, $params );
			if ( is_wp_error( $response ) ) {
				return new Keyring_Error( 'keyring-request-error', $response->get_error_message() );
			}

			$this->set_request_response_code( wp_remote_retrieve_response_code( $response ) );
			if ( '2' !== substr( (string) wp_remote_retrieve_response_code( $response ), 0, 1 ) ) {
				return new Keyring_Error( 'keyring-request-error', $response );
			}

			$body = (string) wp_remote_retrieve_body( $response );
			if ( '' === $body ) {
				return array();
			}

			$decoded = json_decode( $body );
			return null === $decoded ? $body : $decoded;
		}

		/**
		 * Returns display text for a stored token.
		 *
		 * @param Keyring_Access_Token $token Token.
		 *
		 * @return string
		 */
		public function get_display( Keyring_Access_Token $token ) {
			$name = $token->get_meta( 'name' );
			if ( ! empty( $name ) ) {
				return (string) $name;
			}

			$username = $token->get_meta( 'username' );
			if ( ! empty( $username ) ) {
				return (string) $username;
			}

			return $this->get_label();
		}

		/**
		 * Optional extra fields in request UI.
		 *
		 * @return string
		 */
		protected function get_additional_fields_ui() {
			return '';
		}

		/**
		 * Optional metadata extracted from request UI.
		 *
		 * @return array<string, mixed>
		 */
		protected function extract_additional_meta() {
			return array();
		}
	}
}
