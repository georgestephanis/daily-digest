<?php
/**
 * Daily Digest Slack Keyring service.
 *
 * @package DailyDigest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Daily_Digest_Keyring_Service_Slack' ) && class_exists( 'Keyring_Service_OAuth2' ) ) {
	/**
	 * Slack OAuth provider for Daily Digest.
	 */
	class Daily_Digest_Keyring_Service_Slack extends Keyring_Service_OAuth2 {
		const NAME  = 'daily_digest_slack';
		const LABEL = 'Daily Digest Slack';
		const SCOPE = 'search:read';

		/**
		 * Constructor.
		 */
		public function __construct() {
			parent::__construct();

			if ( ! KEYRING__HEADLESS_MODE ) {
				add_action( 'keyring_daily_digest_slack_manage_ui', array( $this, 'basic_ui' ) );
				add_filter( 'keyring_daily_digest_slack_basic_ui_intro', array( $this, 'basic_ui_intro' ) );
			}

			$this->set_endpoint( 'authorize', 'https://slack.com/oauth/v2/authorize', 'GET' );
			$this->set_endpoint( 'access_token', 'https://slack.com/api/oauth.v2.access', 'POST' );
			$this->set_endpoint( 'self', 'https://slack.com/api/auth.test', 'POST' );

			$creds = $this->get_credentials();
			if ( is_array( $creds ) ) {
				$this->key    = $creds['key'];
				$this->secret = $creds['secret'];
			}

			$this->authorization_header    = 'Bearer';
			$this->authorization_parameter = false;

			add_filter( 'keyring_daily_digest_slack_request_token_params', array( $this, 'request_token_params' ) );
			add_filter( 'keyring_daily_digest_slack_verify_token_post_params', array( $this, 'verify_token_post_params' ) );
		}

		/**
		 * Renders setup instructions in Keyring's manage screen.
		 */
		public function basic_ui_intro() {
			echo '<p>' . esc_html__( 'Create a Slack app and configure OAuth credentials for Daily Digest.', 'daily-digest' ) . '</p>';
			echo '<ol>';
			/* translators: %s is the Slack apps URL. */
			echo '<li>' . wp_kses_post( sprintf( __( 'Create an app at <a href="%s" target="_blank" rel="noopener noreferrer">api.slack.com/apps</a>.', 'daily-digest' ), 'https://api.slack.com/apps' ) ) . '</li>';
			/* translators: %s is the Slack OAuth user scope. */
			echo '<li>' . wp_kses_post( sprintf( __( 'In <strong>OAuth &amp; Permissions</strong>, add the user scope <code>%s</code>.', 'daily-digest' ), self::SCOPE ) ) . '</li>';
			/* translators: %s is the Keyring OAuth callback URL to configure in Slack. */
			echo '<li>' . wp_kses_post( sprintf( __( 'Add this redirect URL in Slack: <code>%s</code>.', 'daily-digest' ), esc_html( Keyring_Util::admin_url( $this->get_name(), array( 'action' => 'verify' ) ) ) ) ) . '</li>';
			echo '<li>' . esc_html__( 'Copy Client ID to API Key and Client Secret to API Secret, then save.', 'daily-digest' ) . '</li>';
			echo '</ol>';
		}

		/**
		 * Adds Slack-specific OAuth authorization parameters.
		 *
		 * @param array<string, mixed> $params Request parameters.
		 *
		 * @return array<string, mixed>
		 */
		public function request_token_params( array $params ): array {
			$params['user_scope'] = self::SCOPE;

			if ( isset( $params['scope'] ) ) {
				unset( $params['scope'] );
			}

			return $params;
		}

		/**
		 * Requests JSON from Slack token exchange endpoint.
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
		 * Normalizes Slack OAuth token response to the format expected by Keyring.
		 *
		 * @param string $token Raw token response body.
		 *
		 * @return array<string, mixed>
		 */
		public function parse_access_token( $token ) {
			$decoded = json_decode( (string) $token, true );
			if ( ! is_array( $decoded ) ) {
				return array();
			}

			if ( ! empty( $decoded['authed_user']['access_token'] ) && is_string( $decoded['authed_user']['access_token'] ) ) {
				$decoded['access_token'] = $decoded['authed_user']['access_token'];
			}

			return $decoded;
		}

		/**
		 * Builds token metadata from Slack auth context.
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

			$response = $this->request( 'https://slack.com/api/auth.test', array( 'method' => 'POST' ) );
			if ( Keyring_Util::is_error( $response ) || ! is_object( $response ) || empty( $response->ok ) ) {
				return array();
			}

			$team_url    = isset( $response->url ) ? (string) $response->url : '';
			$team_domain = '';

			if ( '' !== $team_url ) {
				$host = wp_parse_url( $team_url, PHP_URL_HOST );
				if ( is_string( $host ) && false !== strpos( $host, '.slack.com' ) ) {
					$team_domain = str_replace( '.slack.com', '', strtolower( $host ) );
				}
			}

			$name = '';
			if ( ! empty( $response->user ) ) {
				$name = (string) $response->user;
			} elseif ( ! empty( $response->team ) ) {
				$name = (string) $response->team;
			}

			return array(
				'name'        => $name,
				'team'        => isset( $response->team ) ? (string) $response->team : '',
				'team_id'     => isset( $response->team_id ) ? (string) $response->team_id : '',
				'user'        => isset( $response->user ) ? (string) $response->user : '',
				'user_id'     => isset( $response->user_id ) ? (string) $response->user_id : '',
				'team_url'    => $team_url,
				'team_domain' => $team_domain,
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
			$user = $token->get_meta( 'user' );
			if ( ! empty( $user ) ) {
				return (string) $user;
			}

			$name = $token->get_meta( 'name' );
			if ( ! empty( $name ) ) {
				return (string) $name;
			}

			$team = $token->get_meta( 'team' );
			if ( ! empty( $team ) ) {
				return (string) $team;
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

			if ( ! is_object( $response ) || empty( $response->ok ) ) {
				return array(
					'message' => __( 'Slack test request did not return an OK response.', 'daily-digest' ),
				);
			}

			return true;
		}
	}
}
