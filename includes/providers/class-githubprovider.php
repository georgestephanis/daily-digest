<?php
/**
 * GitHub provider adapter class.
 *
 * @package DailyDigest
 */

declare(strict_types=1);

namespace DailyDigest\Providers;

use DailyDigest\Contracts\ProviderInterface;
use DailyDigest\KeyringConnectionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub provider adapter.
 */
class GithubProvider implements ProviderInterface {
	/**
	 * Keyring connection manager.
	 *
	 * @var KeyringConnectionManager
	 */
	private KeyringConnectionManager $keyring_connections;

	/**
	 * Constructor.
	 *
	 * @param KeyringConnectionManager|null $keyring_connections Keyring connection manager.
	 */
	public function __construct( ?KeyringConnectionManager $keyring_connections = null ) {
		$this->keyring_connections = $keyring_connections ?? new KeyringConnectionManager();
	}

	/**
	 * Returns provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'github';
	}

	/**
	 * Returns provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'GitHub';
	}

	/**
	 * Returns provider settings fields.
	 *
	 * @return array
	 */
	public function get_fields(): array {
		return array();
	}

	/**
	 * Tests GitHub credentials.
	 *
	 * @param array $provider_fields Provider field values.
	 *
	 * @return array{success:bool,message:string,details?:array}
	 */
	public function test_credentials( array $provider_fields ): array {
		$token    = $this->keyring_connections->get_access_token_string( 'github', \get_current_user_id() );
		$username = (string) $this->keyring_connections->get_connection_meta_for_user( 'github', \get_current_user_id(), 'username' );

		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'message' => __( 'Connect GitHub via Keyring first.', 'daily-digest' ),
			);
		}

		$response = \wp_remote_get(
			'https://api.github.com/user',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'Authorization'        => 'Bearer ' . $token,
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => ! empty( $username ) ? $username : 'DailyDigestWP/' . DAILY_DIGEST_PLUGIN_VERSION,
				),
			)
		);

		if ( \is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$status_code = (int) \wp_remote_retrieve_response_code( $response );
		$payload     = \json_decode( (string) \wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code || ! \is_array( $payload ) ) {
			return array(
				'success' => false,
				'message' => __( 'GitHub credentials test failed.', 'daily-digest' ),
			);
		}

		$api_login = isset( $payload['login'] ) ? (string) $payload['login'] : '';
		if ( ! empty( $username ) && 0 !== strcasecmp( $username, $api_login ) ) {
			return array(
				'success' => false,
				'message' => __( 'GitHub username does not match token owner.', 'daily-digest' ),
				'details' => array(
					'api_login' => $api_login,
				),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'GitHub credentials are valid.', 'daily-digest' ),
			'details' => array(
				'login' => $api_login,
			),
		);
	}

	/**
	 * Fetches activity data from integration callbacks.
	 *
	 * @param int   $user_id           User ID.
	 * @param array $provider_settings Provider settings.
	 * @param array $options           Query options.
	 *
	 * @return array
	 */
	public function fetch_activity( int $user_id, array $provider_settings = array(), array $options = array() ): array {
		$fields = $provider_settings['fields'] ?? array();
		$items  = $this->fetch_notifications( $user_id, $fields, $options );

		/**
		 * Filters GitHub provider activity items.
		 *
		 * @since 0.1.0
		 *
		 * @param array          $items    Provider activity items.
		 * @param int            $user_id  WordPress user ID.
		 * @param array          $fields   Provider field values.
		 * @param array          $options  Digest options.
		 * @param GithubProvider $provider Provider instance.
		 */
		$items = \apply_filters( 'daily_digest_provider_github_activity', $items, $user_id, $fields, $options, $this );

		if ( ! \is_array( $items ) ) {
			return array();
		}

		return $this->apply_time_window( $items, $options );
	}

	/**
	 * Fetches notifications from GitHub API.
	 *
	 * @param array $fields  Provider field values.
	 * @param array $options Digest options.
	 *
	 * @return array
	 */
	private function fetch_notifications( int $user_id, array $fields, array $options ): array {
		$token = $this->keyring_connections->get_access_token_string( 'github', $user_id );

		if ( empty( $token ) ) {
			return array();
		}

		$username = (string) $this->keyring_connections->get_connection_meta_for_user( 'github', $user_id, 'username' );
		$days     = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;
		$since    = \gmdate( 'c', \strtotime( '-' . $days . ' days' ) );

		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'Authorization'        => 'Bearer ' . $token,
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => ! empty( $username ) ? $username : 'DailyDigestWP/' . DAILY_DIGEST_PLUGIN_VERSION,
		);

		$base_url     = 'https://api.github.com/notifications';
		$all_items    = array();
		$max_pages    = 5;
		$current_page = 1;

		while ( $current_page <= $max_pages ) {
			$request_url = \add_query_arg(
				array(
					'all'      => 'true',
					'since'    => $since,
					'per_page' => 50,
					'page'     => $current_page,
				),
				$base_url
			);

			$response = \wp_remote_get(
				$request_url,
				array(
					'timeout' => 15,
					'headers' => $headers,
				)
			);

			if ( \is_wp_error( $response ) ) {
				break;
			}

			$status_code = (int) \wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				break;
			}

			$body          = (string) \wp_remote_retrieve_body( $response );
			$notifications = \json_decode( $body, true );

			if ( ! \is_array( $notifications ) || empty( $notifications ) ) {
				break;
			}

			foreach ( $notifications as $notification ) {
				if ( ! \is_array( $notification ) ) {
					continue;
				}

				$all_items[] = $this->map_notification_to_item( $notification );
			}

			if ( \count( $notifications ) < 50 ) {
				break;
			}

			++$current_page;
		}

		return \array_values(
			\array_filter(
				$all_items,
				static function ( array $item ): bool {
					return ! empty( $item['timestamp'] ) && ! empty( $item['title'] );
				}
			)
		);
	}

	/**
	 * Maps one GitHub notification payload into digest item schema.
	 *
	 * @param array $notification GitHub notification payload.
	 *
	 * @return array
	 */
	private function map_notification_to_item( array $notification ): array {
		$subject          = isset( $notification['subject'] ) && \is_array( $notification['subject'] ) ? $notification['subject'] : array();
		$repository       = isset( $notification['repository'] ) && \is_array( $notification['repository'] ) ? $notification['repository'] : array();
		$timestamp        = isset( $notification['updated_at'] ) ? (string) $notification['updated_at'] : '';
		$subject_title    = isset( $subject['title'] ) ? (string) $subject['title'] : __( 'GitHub Notification', 'daily-digest' );
		$subject_type     = isset( $subject['type'] ) ? (string) $subject['type'] : 'Notification';
		$reason           = isset( $notification['reason'] ) ? (string) $notification['reason'] : '';
		$repository_name  = isset( $repository['full_name'] ) ? (string) $repository['full_name'] : '';
		$url              = $this->build_notification_url( $notification );
		$summary_segments = array();

		if ( ! empty( $repository_name ) ) {
			$summary_segments[] = $repository_name;
		}

		if ( ! empty( $reason ) ) {
			/* translators: %s: notification reason from GitHub API. */
			$summary_segments[] = \sprintf( __( 'Reason: %s', 'daily-digest' ), $reason );
		}

		return array(
			'provider'  => 'GitHub',
			'type'      => $subject_type,
			'timestamp' => $timestamp,
			'title'     => $subject_title,
			'summary'   => \implode( ' • ', $summary_segments ),
			'url'       => $url,
			'raw'       => $notification,
		);
	}

	/**
	 * Builds a user-friendly URL from a GitHub notification payload.
	 *
	 * @param array $notification GitHub notification payload.
	 *
	 * @return string
	 */
	private function build_notification_url( array $notification ): string {
		$subject    = isset( $notification['subject'] ) && \is_array( $notification['subject'] ) ? $notification['subject'] : array();
		$repository = isset( $notification['repository'] ) && \is_array( $notification['repository'] ) ? $notification['repository'] : array();

		$subject_url      = isset( $subject['url'] ) ? (string) $subject['url'] : '';
		$repository_url   = isset( $repository['html_url'] ) ? (string) $repository['html_url'] : '';
		$subject_web_path = '';

		if ( ! empty( $subject_url ) ) {
			if ( \preg_match( '#/repos/[^/]+/[^/]+/(issues|pulls|discussions|commits|releases)/([^/]+)#', $subject_url, $matches ) ) {
				$subject_web_path = $matches[1] . '/' . $matches[2];
			}
		}

		if ( ! empty( $repository_url ) && ! empty( $subject_web_path ) ) {
			return \trailingslashit( $repository_url ) . $subject_web_path;
		}

		if ( ! empty( $repository_url ) ) {
			return $repository_url;
		}

		return '';
	}

	/**
	 * Applies day-window filtering to activity items.
	 *
	 * @param array $items   Activity items.
	 * @param array $options Query options.
	 *
	 * @return array
	 */
	private function apply_time_window( array $items, array $options ): array {
		$days      = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;
		$threshold = \strtotime( '-' . $days . ' days' );

		return \array_values(
			\array_filter(
				$items,
				static function ( array $item ) use ( $threshold ): bool {
					if ( empty( $item['timestamp'] ) ) {
						return false;
					}

					return \strtotime( (string) $item['timestamp'] ) >= $threshold;
				}
			)
		);
	}
}
