<?php
/**
 * Daily Digest ClickUp Keyring service.
 *
 * @package DailyDigest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Daily_Digest_Keyring_Service_Clickup' ) && class_exists( 'Keyring_Service_OAuth2' ) ) {
	/**
	 * ClickUp OAuth provider for Daily Digest.
	 */
	class Daily_Digest_Keyring_Service_Clickup extends Keyring_Service_OAuth2 {
		const NAME  = 'daily_digest_clickup';
		const LABEL = 'Daily Digest ClickUp';

		/**
		 * Constructor.
		 */
		public function __construct() {
			parent::__construct();

			if ( ! KEYRING__HEADLESS_MODE ) {
				add_action( 'keyring_daily_digest_clickup_manage_ui', array( $this, 'basic_ui' ) );
				add_filter( 'keyring_daily_digest_clickup_basic_ui_intro', array( $this, 'basic_ui_intro' ) );
			}

			$this->set_endpoint( 'authorize', 'https://app.clickup.com/api', 'GET' );
			$this->set_endpoint( 'access_token', 'https://api.clickup.com/api/v2/oauth/token', 'POST' );
			$this->set_endpoint( 'self', 'https://api.clickup.com/api/v2/user', 'GET' );

			$creds = $this->get_credentials();
			if ( is_array( $creds ) ) {
				$this->app_id = $creds['app_id'];
				$this->key    = $creds['key'];
				$this->secret = $creds['secret'];
			}

			$this->authorization_header    = 'Bearer';
			$this->authorization_parameter = false;

			add_filter( 'keyring_daily_digest_clickup_verify_token_params', array( $this, 'verify_token_params' ) );
			add_filter( 'keyring_daily_digest_clickup_verify_token_post_params', array( $this, 'verify_token_post_params' ) );
			add_action( 'pre_keyring_daily_digest_clickup_verify', array( $this, 'redirect_incoming_verify' ) );

			// ClickUp callbacks should use a stable redirect URI.
			$this->callback_url = remove_query_arg( array( 'nonce', 'kr_nonce' ), $this->callback_url );
		}

		/**
		 * Renders setup instructions in Keyring's manage screen.
		 */
		public function basic_ui_intro() {
			echo '<p>' . esc_html__( 'Create a ClickUp OAuth app and configure credentials for Daily Digest.', 'daily-digest' ) . '</p>';
			echo '<ol>';
			echo '<li>' . wp_kses_post( sprintf( __( 'Create an app in ClickUp settings using <a href="%s" target="_blank" rel="noopener noreferrer">ClickUp OAuth docs</a>.', 'daily-digest' ), 'https://developer.clickup.com/docs/authentication#oauth-flow' ) ) . '</li>';
			echo '<li>' . wp_kses_post( sprintf( __( 'Set this Redirect URL in ClickUp: <code>%s</code>.', 'daily-digest' ), esc_html( Keyring_Util::admin_url( $this->get_name(), array( 'action' => 'verify' ) ) ) ) ) . '</li>';
			echo '<li>' . esc_html__( 'Copy Client ID to API Key and Client Secret to API Secret, then save.', 'daily-digest' ) . '</li>';
			echo '</ol>';
		}

		/**
		 * Restricts token exchange params to ClickUp-required fields.
		 *
		 * @param array<string, mixed> $params Request parameters.
		 *
		 * @return array<string, mixed>
		 */
		public function verify_token_params( array $params ): array {
			return array(
				'client_id'     => isset( $params['client_id'] ) ? $params['client_id'] : '',
				'client_secret' => isset( $params['client_secret'] ) ? $params['client_secret'] : '',
				'code'          => isset( $params['code'] ) ? $params['code'] : '',
			);
		}

		/**
		 * Requests JSON response from ClickUp token endpoint.
		 *
		 * @param array<string, mixed> $params Request parameters.
		 *
		 * @return array<string, mixed>
		 */
		public function verify_token_post_params( array $params ): array {
			$body = isset( $params['body'] ) && is_array( $params['body'] ) ? $params['body'] : array();

			$params['headers'] = array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			);
			$params['body']    = wp_json_encode( $body );

			return $params;
		}

		/**
		 * Redirects incoming verify requests to include Keyring nonces.
		 *
		 * @param array<string, mixed> $request Request values.
		 */
		public function redirect_incoming_verify( array $request ) {
			if ( isset( $request['kr_nonce'] ) ) {
				return;
			}

			$kr_nonce = wp_create_nonce( 'keyring-verify' );
			$nonce    = wp_create_nonce( 'keyring-verify-' . $this->get_name() );

			wp_safe_redirect(
				Keyring_Util::admin_url(
					$this->get_name(),
					array(
						'action'   => 'verify',
						'kr_nonce' => $kr_nonce,
						'nonce'    => $nonce,
						'state'    => isset( $request['state'] ) ? (string) $request['state'] : '',
						'code'     => isset( $request['code'] ) ? (string) $request['code'] : '',
					)
				)
			);
			exit;
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
		 * Builds token metadata from ClickUp user/workspace context.
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

			$user_response  = $this->request( 'https://api.clickup.com/api/v2/user', array( 'method' => 'GET' ) );
			$teams_response = $this->request( 'https://api.clickup.com/api/v2/team', array( 'method' => 'GET' ) );

			$meta = array();
			if ( ! Keyring_Util::is_error( $user_response ) && is_object( $user_response ) && ! empty( $user_response->user ) && is_object( $user_response->user ) ) {
				$meta['username'] = isset( $user_response->user->username ) ? (string) $user_response->user->username : '';
				$meta['name']     = isset( $user_response->user->username ) ? (string) $user_response->user->username : '';
				$meta['user_id']  = isset( $user_response->user->id ) ? (string) $user_response->user->id : '';
			}

			$team_ids     = array();
			$team_names   = array();
			$default_team = '';
			if ( ! Keyring_Util::is_error( $teams_response ) && is_object( $teams_response ) && ! empty( $teams_response->teams ) && is_array( $teams_response->teams ) ) {
				foreach ( $teams_response->teams as $team ) {
					if ( ! is_object( $team ) || empty( $team->id ) ) {
						continue;
					}

					$team_ids[] = (string) $team->id;
					if ( ! empty( $team->name ) ) {
						$team_names[] = (string) $team->name;
					}
				}

				if ( ! empty( $team_ids ) ) {
					$default_team = (string) $team_ids[0];
				}
			}

			$meta['team_ids']        = $team_ids;
			$meta['team_names']      = $team_names;
			$meta['default_team_id'] = $default_team;

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

			$username = $token->get_meta( 'username' );
			if ( ! empty( $username ) ) {
				return (string) $username;
			}

			return $this->get_label();
		}
	}
}
