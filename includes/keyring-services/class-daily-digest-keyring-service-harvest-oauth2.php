<?php
/**
 * Daily Digest Harvest OAuth2 Keyring service.
 *
 * @package DailyDigest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Daily_Digest_Keyring_Service_Harvest_OAuth2' ) && class_exists( 'Keyring_Service_OAuth2' ) ) {
	/**
	 * Harvest OAuth2 provider for Daily Digest.
	 */
	class Daily_Digest_Keyring_Service_Harvest_OAuth2 extends Keyring_Service_OAuth2 {
		const NAME  = 'daily_digest_harvest_oauth2';
		const LABEL = 'Daily Digest Harvest OAuth2';

		/**
		 * Constructor.
		 */
		public function __construct() {
			parent::__construct();

			if ( ! KEYRING__HEADLESS_MODE ) {
				add_action( 'keyring_daily_digest_harvest_oauth2_manage_ui', array( $this, 'basic_ui' ) );
				add_filter( 'keyring_daily_digest_harvest_oauth2_basic_ui_intro', array( $this, 'basic_ui_intro' ) );
			}

			$this->set_endpoint( 'authorize', 'https://id.getharvest.com/oauth2/authorize', 'GET' );
			$this->set_endpoint( 'access_token', 'https://id.getharvest.com/api/v2/oauth2/token', 'POST' );
			$this->set_endpoint( 'self', 'https://id.getharvest.com/api/v2/accounts', 'GET' );

			$creds = $this->get_credentials();
			if ( is_array( $creds ) ) {
				$this->app_id = $creds['app_id'];
				$this->key    = $creds['key'];
				$this->secret = $creds['secret'];
			}

			$this->authorization_header    = 'Bearer';
			$this->authorization_parameter = false;

			add_filter( 'keyring_daily_digest_harvest_oauth2_verify_token_post_params', array( $this, 'verify_token_post_params' ) );
			add_action( 'pre_keyring_daily_digest_harvest_oauth2_verify', array( $this, 'redirect_incoming_verify' ) );

			// Harvest callbacks should use a stable redirect URI.
			$this->callback_url = remove_query_arg( array( 'nonce', 'kr_nonce' ), $this->callback_url );
		}

		/**
		 * Renders setup instructions in Keyring's manage screen.
		 */
		public function basic_ui_intro() {
			echo '<p>' . esc_html__( 'Create a Harvest OAuth application and configure credentials for Daily Digest.', 'daily-digest' ) . '</p>';
			echo '<ol>';
			/* translators: %s is the Harvest OAuth documentation URL. */
			echo '<li>' . wp_kses_post( sprintf( __( 'Create an OAuth application using <a href="%s" target="_blank" rel="noopener noreferrer">Harvest authentication docs</a>.', 'daily-digest' ), 'https://help.getharvest.com/api-v2/authentication-api/authentication/authentication/' ) ) . '</li>';
			/* translators: %s is the Keyring OAuth callback URL to configure in Harvest. */
			echo '<li>' . wp_kses_post( sprintf( __( 'Set this Redirect URL in Harvest: <code>%s</code>.', 'daily-digest' ), esc_html( Keyring_Util::admin_url( $this->get_name(), array( 'action' => 'verify' ) ) ) ) ) . '</li>';
			echo '<li>' . esc_html__( 'Copy Client ID to API Key and Client Secret to API Secret, then save.', 'daily-digest' ) . '</li>';
			echo '</ol>';
		}

		/**
		 * Adds Harvest-required headers for token exchange.
		 *
		 * @param array<string, mixed> $params Request parameters.
		 *
		 * @return array<string, mixed>
		 */
		public function verify_token_post_params( array $params ): array {
			$headers = isset( $params['headers'] ) && is_array( $params['headers'] ) ? $params['headers'] : array();

			$headers['Accept']     = 'application/json';
			$headers['User-Agent'] = $this->build_user_agent();

			$params['headers'] = $headers;

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
		 * Builds token metadata from Harvest account context.
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

			$response = wp_remote_get(
				'https://id.getharvest.com/api/v2/accounts',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $access,
						'Accept'        => 'application/json',
						'User-Agent'    => $this->build_user_agent(),
					),
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return array();
			}

			$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $payload ) ) {
				return array();
			}

			$user        = isset( $payload['user'] ) && is_array( $payload['user'] ) ? $payload['user'] : array();
			$accounts    = isset( $payload['accounts'] ) && is_array( $payload['accounts'] ) ? $payload['accounts'] : array();
			$harvest     = array();
			$account_ids = array();

			foreach ( $accounts as $account ) {
				if ( ! is_array( $account ) || empty( $account['id'] ) ) {
					continue;
				}

				// phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- Keep concise assignment formatting in loop body.
				$account_id = (string) $account['id'];
				$account_ids[] = $account_id;

				if ( empty( $harvest ) && isset( $account['product'] ) && 'harvest' === strtolower( (string) $account['product'] ) ) {
					$harvest = $account;
				}
			}

			if ( empty( $harvest ) && ! empty( $accounts[0] ) && is_array( $accounts[0] ) ) {
				$harvest = $accounts[0];
			}

			$full_name = trim(
				implode(
					' ',
					array_filter(
						array(
							isset( $user['first_name'] ) ? (string) $user['first_name'] : '',
							isset( $user['last_name'] ) ? (string) $user['last_name'] : '',
						)
					)
				)
			);

			return array(
				'name'           => '' !== $full_name ? $full_name : ( isset( $user['email'] ) ? (string) $user['email'] : '' ),
				'email'          => isset( $user['email'] ) ? (string) $user['email'] : '',
				'user_id'        => isset( $user['id'] ) ? (string) $user['id'] : '',
				'account_id'     => isset( $harvest['id'] ) ? (string) $harvest['id'] : '',
				'account_name'   => isset( $harvest['name'] ) ? (string) $harvest['name'] : '',
				'harvest_scopes' => isset( $token_data['scope'] ) ? (string) $token_data['scope'] : '',
				'account_ids'    => $account_ids,
			);
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
			if ( empty( $this->token ) ) {
				return array(
					'message' => __( 'No Harvest token is set for testing.', 'daily-digest' ),
				);
			}

			$response = wp_remote_get(
				'https://id.getharvest.com/api/v2/accounts',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . (string) $this->token,
						'Accept'        => 'application/json',
						'User-Agent'    => $this->build_user_agent(),
					),
				)
			);

			$payload = ! is_wp_error( $response )
				? json_decode( (string) wp_remote_retrieve_body( $response ) )
				: null;

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_object( $payload ) || empty( $payload->accounts ) ) {
				return array(
					'message' => __( 'Harvest test request did not return account access.', 'daily-digest' ),
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
