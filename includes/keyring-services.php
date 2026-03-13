<?php
/**
 * Daily Digest custom Keyring services.
 *
 * @package DailyDigest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Keyring_Service' ) || class_exists( 'Daily_Digest_Keyring_Service_Base' ) ) {
	return;
}

/**
 * Base class for manual token-based Daily Digest Keyring services.
 */
abstract class Daily_Digest_Keyring_Service_Base extends Keyring_Service {
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
	 */
	public function request_token() {
		if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'keyring-request-' . $this->get_name() ) ) {
			Keyring::error( __( 'Invalid/missing request nonce.', 'keyring' ) );
			exit;
		}

		echo '<div class="wrap">';
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

/**
 * ClickUp token provider for Daily Digest.
 */
class Daily_Digest_Keyring_Service_Clickup extends Daily_Digest_Keyring_Service_Base {
	const NAME  = 'daily_digest_clickup';
	const LABEL = 'Daily Digest ClickUp';

	protected function get_token_help_text() {
		return __( 'Paste a ClickUp personal API token.', 'daily-digest' );
	}

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

		$team_ids      = array();
		$team_names    = array();
		$default_team  = '';

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
		echo '<li>' . wp_kses_post( sprintf( __( 'Create an app at <a href="%s" target="_blank" rel="noopener noreferrer">api.slack.com/apps</a>.', 'daily-digest' ), 'https://api.slack.com/apps' ) ) . '</li>';
		echo '<li>' . wp_kses_post( sprintf( __( 'In <strong>OAuth &amp; Permissions</strong>, add the user scope <code>%s</code>.', 'daily-digest' ), self::SCOPE ) ) . '</li>';
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
		if ( ! empty( $response->team ) ) {
			$name = (string) $response->team;
		} elseif ( ! empty( $response->user ) ) {
			$name = (string) $response->user;
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
}

add_action( 'keyring_load_services', array( 'Daily_Digest_Keyring_Service_Clickup', 'init' ) );
add_action( 'keyring_load_services', array( 'Daily_Digest_Keyring_Service_Slack', 'init' ) );
