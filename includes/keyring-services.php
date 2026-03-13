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
 * Slack token provider for Daily Digest.
 */
class Daily_Digest_Keyring_Service_Slack extends Daily_Digest_Keyring_Service_Base {
	const NAME  = 'daily_digest_slack';
	const LABEL = 'Daily Digest Slack';

	protected function get_token_help_text() {
		return __( 'Paste a Slack user token (xoxp-...) with search:read scope.', 'daily-digest' );
	}

	protected function verify_access_token( $token ) {
		$response = wp_remote_get(
			'https://slack.com/api/auth.test',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
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
		if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 || ! is_array( $payload ) || empty( $payload['ok'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Slack token verification failed.', 'daily-digest' ),
			);
		}

		$name = '';
		if ( ! empty( $payload['team'] ) ) {
			$name = (string) $payload['team'];
		}

		if ( '' === $name && ! empty( $payload['user'] ) ) {
			$name = (string) $payload['user'];
		}

		$team_url    = isset( $payload['url'] ) ? (string) $payload['url'] : '';
		$team_domain = '';

		if ( ! empty( $team_url ) ) {
			$host = wp_parse_url( $team_url, PHP_URL_HOST );
			if ( is_string( $host ) && false !== strpos( $host, '.slack.com' ) ) {
				$team_domain = str_replace( '.slack.com', '', strtolower( $host ) );
			}
		}

		return array(
			'success' => true,
			'message' => __( 'Slack token verified.', 'daily-digest' ),
			'meta'    => array(
				'name'        => $name,
				'team'        => isset( $payload['team'] ) ? (string) $payload['team'] : '',
				'team_id'     => isset( $payload['team_id'] ) ? (string) $payload['team_id'] : '',
				'user_id'     => isset( $payload['user_id'] ) ? (string) $payload['user_id'] : '',
				'team_url'    => $team_url,
				'team_domain' => $team_domain,
			),
		);
	}
}

add_action( 'keyring_load_services', array( 'Daily_Digest_Keyring_Service_Clickup', 'init' ) );
add_action( 'keyring_load_services', array( 'Daily_Digest_Keyring_Service_Slack', 'init' ) );
