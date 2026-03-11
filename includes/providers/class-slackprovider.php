<?php
/**
 * Slack provider adapter class.
 *
 * @package DailyDigest
 */

declare(strict_types=1);

namespace DailyDigest\Providers;

use DailyDigest\Contracts\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slack provider adapter.
 */
class SlackProvider implements ProviderInterface {
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
			'bot_token' => \__( 'Bot User OAuth Token (xoxb-…)', 'daily-digest' ),
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
		$token = isset( $provider_fields['bot_token'] ) ? \trim( (string) $provider_fields['bot_token'] ) : '';

		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'message' => __( 'Slack bot token is required.', 'daily-digest' ),
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

		$team = isset( $payload['team'] ) ? (string) $payload['team'] : '';

		return array(
			'success' => true,
			'message' => __( 'Slack credentials are valid.', 'daily-digest' ),
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
		$items  = $this->fetch_messages( $fields, $options );

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
	 * Fetches recent Slack messages for configured user.
	 *
	 * @param array $fields  Provider field values.
	 * @param array $options Digest options.
	 *
	 * @return array
	 */
	private function fetch_messages( array $fields, array $options ): array {
		$workspace = isset( $fields['workspace'] ) ? \trim( (string) $fields['workspace'] ) : '';
		$user_id   = isset( $fields['user_id'] ) ? \trim( (string) $fields['user_id'] ) : '';
		$token     = isset( $fields['bot_token'] ) ? \trim( (string) $fields['bot_token'] ) : '';

		if ( empty( $user_id ) || empty( $token ) ) {
			return array();
		}

		$days         = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;
		$oldest_unix  = \strtotime( '-' . $days . ' days' );
		$oldest_value = false !== $oldest_unix ? (string) $oldest_unix : '0';
		$items        = array();
		$channels     = $this->fetch_channels( $token );
		$max_channels = 20;
		$processed    = 0;

		foreach ( $channels as $channel ) {
			if ( $processed >= $max_channels ) {
				break;
			}

			$channel_id = isset( $channel['id'] ) ? (string) $channel['id'] : '';
			if ( empty( $channel_id ) ) {
				continue;
			}

			$history_payload = $this->call_api_method(
				'conversations.history',
				array(
					'channel'   => $channel_id,
					'limit'     => '100',
					'oldest'    => $oldest_value,
					'inclusive' => 'true',
				),
				$token
			);

			if ( null === $history_payload ) {
				++$processed;
				continue;
			}

			$messages = isset( $history_payload['messages'] ) && \is_array( $history_payload['messages'] ) ? $history_payload['messages'] : array();
			foreach ( $messages as $message ) {
				if ( ! \is_array( $message ) ) {
					continue;
				}

				if ( ! isset( $message['user'] ) || $user_id !== (string) $message['user'] ) {
					continue;
				}

				$items[] = $this->map_message_to_item( $message, $channel, $workspace );
			}

			++$processed;
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
	 * Fetches accessible channels from Slack.
	 *
	 * @param string $token Slack token.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_channels( string $token ): array {
		$channels = array();
		$cursor   = '';
		$max_runs = 5;
		$current  = 0;

		while ( $current < $max_runs ) {
			$params = array(
				'limit'            => '200',
				'exclude_archived' => 'true',
				'types'            => 'public_channel,private_channel',
			);

			if ( ! empty( $cursor ) ) {
				$params['cursor'] = $cursor;
			}

			$payload = $this->call_api_method( 'conversations.list', $params, $token );
			if ( null === $payload ) {
				break;
			}

			$batch = isset( $payload['channels'] ) && \is_array( $payload['channels'] ) ? $payload['channels'] : array();
			if ( ! empty( $batch ) ) {
				$channels = array_merge( $channels, $batch );
			}

			$next_cursor = isset( $payload['response_metadata']['next_cursor'] ) ? (string) $payload['response_metadata']['next_cursor'] : '';
			if ( empty( $next_cursor ) ) {
				break;
			}

			$cursor = $next_cursor;
			++$current;
		}

		return $channels;
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
	 * Maps Slack message payload to digest item.
	 *
	 * @param array  $message   Slack message payload.
	 * @param array  $channel   Slack channel payload.
	 * @param string $workspace Slack workspace subdomain.
	 *
	 * @return array
	 */
	private function map_message_to_item( array $message, array $channel, string $workspace ): array {
		$text         = isset( $message['text'] ) ? \trim( (string) $message['text'] ) : '';
		$timestamp_ts = isset( $message['ts'] ) ? (string) $message['ts'] : '';
		$timestamp    = '';

		if ( ! empty( $timestamp_ts ) ) {
			$timestamp = \gmdate( 'c', (int) floor( (float) $timestamp_ts ) );
		}

		$channel_name = isset( $channel['name'] ) ? (string) $channel['name'] : '';
		$title        = ! empty( $channel_name )
			? \sprintf(
				/* translators: %s: Slack channel name. */
				__( 'Message in #%s', 'daily-digest' ),
				$channel_name
			)
			: __( 'Slack Message', 'daily-digest' );

		$url = $this->build_message_url( $workspace, $channel, $timestamp_ts );

		return array(
			'provider'  => 'Slack',
			'type'      => 'message',
			'timestamp' => $timestamp,
			'title'     => $title,
			'summary'   => $text,
			'url'       => $url,
			'raw'       => $message,
		);
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
