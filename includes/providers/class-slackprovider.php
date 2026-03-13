<?php
/**
 * Slack provider adapter class.
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
 * Slack provider adapter.
 */
class SlackProvider implements ProviderInterface {
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
		return 'slack';
	}

	/**
	 * Returns provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Slack';
	}

	/**
	 * Returns provider settings fields.
	 *
	 * @return array
	 */
	public function get_fields(): array {
		return array(
			'workspace' => \__( 'Workspace Subdomain (e.g. myworkspace)', 'daily-digest' ),
			'user_id'   => \__( 'Slack User ID (U…)', 'daily-digest' ),
		);
	}

	/**
	 * Tests Slack credentials.
	 *
	 * @param array $provider_fields Provider field values.
	 *
	 * @return array{success:bool,message:string,details?:array}
	 */
	public function test_credentials( array $provider_fields ): array {
		$token = $this->keyring_connections->get_access_token_string( 'slack', \get_current_user_id() );

		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'message' => __( 'Connect Slack via Keyring first. Use a user token (xoxp-...) with search:read scope.', 'daily-digest' ),
			);
		}

		$response = \wp_remote_get(
			'https://slack.com/api/auth.test',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
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

		if ( 200 !== $status_code || ! \is_array( $payload ) || empty( $payload['ok'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Slack credentials test failed.', 'daily-digest' ),
			);
		}

		$search_payload = $this->call_api_method(
			'search.messages',
			array(
				'query' => 'from:me',
				'count' => '1',
			),
			$token
		);

		if ( null === $search_payload ) {
			return array(
				'success' => false,
				'message' => __( 'Slack token validated, but message search failed. Ensure the token has search:read scope and is a user token.', 'daily-digest' ),
			);
		}

		$team = isset( $payload['team'] ) ? (string) $payload['team'] : '';

		return array(
			'success' => true,
			'message' => __( 'Slack credentials are valid for notification queries.', 'daily-digest' ),
			'details' => array(
				'team' => $team,
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
		$items  = $this->fetch_notification_items( $user_id, $fields, $options );

		/**
		 * Filters Slack provider activity items.
		 *
		 * @since 0.1.0
		 *
		 * @param array         $items    Provider activity items.
		 * @param int           $user_id  WordPress user ID.
		 * @param array         $fields   Provider field values.
		 * @param array         $options  Digest options.
		 * @param SlackProvider $provider Provider instance.
		 */
		$items = \apply_filters( 'daily_digest_provider_slack_activity', $items, $user_id, $fields, $options, $this );

		if ( ! \is_array( $items ) ) {
			return array();
		}

		return $this->apply_time_window( $items, $options );
	}

	/**
	 * Fetches Slack notification-like activity and own comments via search API.
	 *
	 * @param array $fields  Provider field values.
	 * @param array $options Digest options.
	 *
	 * @return array
	 */
	private function fetch_notification_items( int $user_id, array $fields, array $options ): array {
		$workspace  = isset( $fields['workspace'] ) ? \trim( (string) $fields['workspace'] ) : '';
		$slack_user = isset( $fields['user_id'] ) ? \trim( (string) $fields['user_id'] ) : '';
		$token      = $this->keyring_connections->get_access_token_string( 'slack', $user_id );

		if ( empty( $slack_user ) || empty( $token ) ) {
			return array();
		}

		$days       = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;
		$after_date = \gmdate( 'Y-m-d', \strtotime( '-' . $days . ' days' ) );
		$queries    = array(
			array(
				'type'  => 'notification',
				'query' => '<@' . $slack_user . '> after:' . $after_date,
			),
			array(
				'type'  => 'comment',
				'query' => 'from:me after:' . $after_date,
			),
		);
		$items      = array();
		$seen       = array();

		foreach ( $queries as $query_item ) {
			$query_type  = isset( $query_item['type'] ) ? (string) $query_item['type'] : '';
			$query_value = isset( $query_item['query'] ) ? (string) $query_item['query'] : '';

			if ( empty( $query_type ) || empty( $query_value ) ) {
				continue;
			}

			$matches = $this->search_messages( $query_value, $token );

			foreach ( $matches as $match ) {
				if ( ! \is_array( $match ) ) {
					continue;
				}

				$item = $this->map_search_match_to_item( $match, $query_type, $workspace );
				if ( empty( $item ) ) {
					continue;
				}

				$dedupe_key = (string) ( $item['url'] ?? '' ) . '|' . (string) ( $item['timestamp'] ?? '' ) . '|' . (string) ( $item['type'] ?? '' );
				if ( isset( $seen[ $dedupe_key ] ) ) {
					continue;
				}

				$seen[ $dedupe_key ] = true;
				$items[]             = $item;
			}
		}

		return \array_values(
			\array_filter(
				$items,
				static function ( array $item ): bool {
					return ! empty( $item['timestamp'] ) && ! empty( $item['title'] );
				}
			)
		);
	}

	/**
	 * Searches Slack messages with pagination.
	 *
	 * @param string $query Search query string.
	 * @param string $token Slack token.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function search_messages( string $query, string $token ): array {
		$matches   = array();
		$page      = 1;
		$max_pages = 3;

		while ( $page <= $max_pages ) {
			$payload = $this->call_api_method(
				'search.messages',
				array(
					'query'    => $query,
					'count'    => '100',
					'page'     => (string) $page,
					'sort'     => 'timestamp',
					'sort_dir' => 'desc',
				),
				$token
			);

			if ( null === $payload ) {
				break;
			}

			$batch = isset( $payload['messages']['matches'] ) && \is_array( $payload['messages']['matches'] ) ? $payload['messages']['matches'] : array();
			if ( ! empty( $batch ) ) {
				$matches = \array_merge( $matches, $batch );
			}

			$page_count = isset( $payload['messages']['pagination']['page_count'] ) ? \absint( $payload['messages']['pagination']['page_count'] ) : 1;
			if ( $page >= $page_count ) {
				break;
			}

			++$page;
		}

		return $matches;
	}

	/**
	 * Maps Slack search match payload to digest item.
	 *
	 * @param array  $search_item Slack search result item.
	 * @param string $query_type Query type label.
	 * @param string $workspace  Slack workspace subdomain.
	 *
	 * @return array
	 */
	private function map_search_match_to_item( array $search_item, string $query_type, string $workspace ): array {
		$text         = isset( $search_item['text'] ) ? \trim( (string) $search_item['text'] ) : '';
		$timestamp_ts = isset( $search_item['ts'] ) ? (string) $search_item['ts'] : '';
		$timestamp    = '';

		if ( ! empty( $timestamp_ts ) ) {
			$timestamp = \gmdate( 'c', (int) \floor( (float) $timestamp_ts ) );
		}

		$channel      = isset( $search_item['channel'] ) && \is_array( $search_item['channel'] ) ? $search_item['channel'] : array();
		$channel_name = isset( $channel['name'] ) ? (string) $channel['name'] : '';

		if ( 'comment' === $query_type ) {
			$title = ! empty( $channel_name )
				? \sprintf(
					/* translators: %s: Slack channel name. */
					__( 'Your comment in #%s', 'daily-digest' ),
					$channel_name
				)
				: __( 'Your Slack Comment', 'daily-digest' );
		} else {
			$title = ! empty( $channel_name )
				? \sprintf(
					/* translators: %s: Slack channel name. */
					__( 'Mention in #%s', 'daily-digest' ),
					$channel_name
				)
				: __( 'Slack Notification', 'daily-digest' );
		}

		$url = isset( $search_item['permalink'] ) ? \esc_url_raw( (string) $search_item['permalink'] ) : '';
		if ( empty( $url ) ) {
			$url = $this->build_message_url( $workspace, $channel, $timestamp_ts );
		}

		return array(
			'provider'  => 'Slack',
			'type'      => 'comment' === $query_type ? 'comment' : 'notification',
			'timestamp' => $timestamp,
			'title'     => $title,
			'summary'   => $text,
			'url'       => $url,
			'raw'       => $search_item,
		);
	}

	/**
	 * Calls a Slack Web API method.
	 *
	 * @param string $method Slack method name.
	 * @param array  $params Request parameters.
	 * @param string $token  Slack token.
	 *
	 * @return array|null
	 */
	private function call_api_method( string $method, array $params, string $token ): ?array {
		$request_url = \add_query_arg( $params, 'https://slack.com/api/' . $method );
		$response    = \wp_remote_get(
			$request_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( \is_wp_error( $response ) ) {
			return null;
		}

		$status_code = (int) \wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return null;
		}

		$body    = (string) \wp_remote_retrieve_body( $response );
		$payload = \json_decode( $body, true );

		if ( ! \is_array( $payload ) || empty( $payload['ok'] ) ) {
			return null;
		}

		return $payload;
	}

	/**
	 * Builds Slack message URL if workspace value is available.
	 *
	 * @param string $workspace Slack workspace subdomain.
	 * @param array  $channel   Slack channel payload.
	 * @param string $ts        Slack message timestamp value.
	 *
	 * @return string
	 */
	private function build_message_url( string $workspace, array $channel, string $ts ): string {
		$workspace  = \preg_replace( '/[^a-z0-9-]/i', '', \strtolower( $workspace ) );
		$channel_id = isset( $channel['id'] ) ? (string) $channel['id'] : '';
		$clean_ts   = str_replace( '.', '', $ts );

		if ( empty( $workspace ) || empty( $channel_id ) || empty( $clean_ts ) ) {
			return '';
		}

		return sprintf( 'https://%s.slack.com/archives/%s/p%s', $workspace, $channel_id, $clean_ts );
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
