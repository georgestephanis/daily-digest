<?php
/**
 * Daily Digest Asana Keyring service.
 *
 * @package DailyDigest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Daily_Digest_Keyring_Service_Asana' ) && class_exists( 'Keyring_Service_OAuth2' ) ) {
	/**
	 * Asana OAuth provider for Daily Digest.
	 */
	class Daily_Digest_Keyring_Service_Asana extends Keyring_Service_OAuth2 {
		const NAME  = 'daily_digest_asana';
		const LABEL = 'Daily Digest Asana';
		const SCOPE = 'default';

		/**
		 * Constructor.
		 */
		public function __construct() {
			parent::__construct();

			if ( ! KEYRING__HEADLESS_MODE ) {
				add_action( 'keyring_daily_digest_asana_manage_ui', array( $this, 'basic_ui' ) );
				add_filter( 'keyring_daily_digest_asana_basic_ui_intro', array( $this, 'basic_ui_intro' ) );
			}

			$this->set_endpoint( 'authorize', 'https://app.asana.com/-/oauth_authorize', 'GET' );
			$this->set_endpoint( 'access_token', 'https://app.asana.com/-/oauth_token', 'POST' );
			$this->set_endpoint( 'self', 'https://app.asana.com/api/1.0/users/me', 'GET' );

			$creds = $this->get_credentials();
			if ( is_array( $creds ) ) {
				$this->key    = $creds['key'];
				$this->secret = $creds['secret'];
			}

			$this->authorization_header    = 'Bearer';
			$this->authorization_parameter = false;

			add_filter( 'keyring_daily_digest_asana_request_token_params', array( $this, 'request_token_params' ) );
			add_filter( 'keyring_daily_digest_asana_verify_token_post_params', array( $this, 'verify_token_post_params' ) );
		}

		/**
		 * Renders setup instructions in Keyring's manage screen.
		 */
		public function basic_ui_intro() {
			echo '<p>' . esc_html__( 'Create an Asana app and configure OAuth credentials for Daily Digest.', 'daily-digest' ) . '</p>';
			echo '<ol>';
			/* translators: %s is the Asana developer apps URL. */
			echo '<li>' . wp_kses_post( sprintf( __( 'Create an app at <a href="%s" target="_blank" rel="noopener noreferrer">developers.asana.com</a>.', 'daily-digest' ), 'https://developers.asana.com/docs/create-an-app' ) ) . '</li>';
			/* translators: %s is the Keyring OAuth callback URL to configure in Asana. */
			echo '<li>' . wp_kses_post( sprintf( __( 'Add this redirect URL in Asana: <code>%s</code>.', 'daily-digest' ), esc_html( Keyring_Util::admin_url( $this->get_name(), array( 'action' => 'verify' ) ) ) ) ) . '</li>';
			echo '<li>' . esc_html__( 'Copy Client ID to API Key and Client Secret to API Secret, then save.', 'daily-digest' ) . '</li>';
			echo '</ol>';
		}

		/**
		 * Adds Asana-specific OAuth authorization parameters.
		 *
		 * @param array<string, mixed> $params Request parameters.
		 *
		 * @return array<string, mixed>
		 */
		public function request_token_params( array $params ): array {
			$params['scope'] = self::SCOPE;

			return $params;
		}

		/**
		 * Requests JSON from Asana token exchange endpoint.
		 *
		 * @param array<string, mixed> $params Request parameters.
		 *
		 * @return array<string, mixed>
		 */
		public function verify_token_post_params( array $params ): array {
			$params['headers'] = array(
				'Accept' => 'application/json',
			);

			return $params;
		}

		/**
		 * Parses token response.
		 *
		 * @param string $token Raw token response body.
		 *
		 * @return array<string, mixed>
		 */
		public function parse_access_token( $token ) {
			$decoded = json_decode( (string) $token, true );

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Builds token metadata from Asana user/workspace context.
		 *
		 * @param mixed $token Parsed token payload.
		 *
		 * @return array<string, mixed>
		 */
		public function build_token_meta( $token ) {
			$token_data = is_array( $token ) ? $token : array();
			$access     = isset( $token_data['access_token'] ) ? (string) $token_data['access_token'] : '';

			if ( '' === $access ) {
				return array();
			}

			$this->set_token(
				new Keyring_Access_Token(
					$this->get_name(),
					$access,
					array()
				)
			);

			$user_response       = $this->request( 'https://app.asana.com/api/1.0/users/me', array( 'method' => 'GET' ) );
			$workspaces_response = $this->request( 'https://app.asana.com/api/1.0/workspaces', array( 'method' => 'GET' ) );

			$meta = array();
			if ( ! Keyring_Util::is_error( $user_response ) && is_object( $user_response ) && ! empty( $user_response->data ) && is_object( $user_response->data ) ) {
				$meta['name']    = isset( $user_response->data->name ) ? (string) $user_response->data->name : '';
				$meta['email']   = isset( $user_response->data->email ) ? (string) $user_response->data->email : '';
				$meta['user_id'] = isset( $user_response->data->gid ) ? (string) $user_response->data->gid : '';
			}

			$workspace_ids   = array();
			$workspace_names = array();

			if ( ! Keyring_Util::is_error( $workspaces_response ) && is_object( $workspaces_response ) && ! empty( $workspaces_response->data ) && is_array( $workspaces_response->data ) ) {
				foreach ( $workspaces_response->data as $workspace ) {
					if ( ! is_object( $workspace ) || empty( $workspace->gid ) ) {
						continue;
					}

					$workspace_ids[] = (string) $workspace->gid;
					if ( ! empty( $workspace->name ) ) {
						$workspace_names[] = (string) $workspace->name;
					}
				}
			}

			$meta['workspace_ids']        = $workspace_ids;
			$meta['workspace_names']      = $workspace_names;
			$meta['default_workspace_id'] = ! empty( $workspace_ids ) ? (string) $workspace_ids[0] : '';

			return $meta;
		}

		/**
		 * Returns display text for stored connection.
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

			$email = $token->get_meta( 'email' );
			if ( ! empty( $email ) ) {
				return (string) $email;
			}

			return $this->get_label();
		}

		/**
		 * Tests whether the current connection token is valid.
		 *
		 * @return bool|mixed True on success, or response/error details on failure.
		 */
		public function test_connection() {
			$response = $this->request( $this->self_url, array( 'method' => $this->self_method ) );

			if ( Keyring_Util::is_error( $response ) ) {
				return $response;
			}

			if ( ! is_object( $response ) || empty( $response->data ) || ! is_object( $response->data ) || empty( $response->data->gid ) ) {
				return array(
					'message' => __( 'Asana test request did not return a valid user payload.', 'daily-digest' ),
				);
			}

			return true;
		}
	}
}
