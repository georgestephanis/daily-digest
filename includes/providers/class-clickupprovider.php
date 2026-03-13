<?php
/**
 * ClickUp provider adapter class.
 *
 * @package DailyDigest
 */

declare(strict_types=1);

namespace DailyDigest\Providers;

use DailyDigest\AbstractProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ClickUp provider adapter.
 */
class ClickupProvider extends AbstractProvider {
	/**
	 * Returns provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'clickup';
	}

	/**
	 * Returns provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'ClickUp';
	}

	/**
	 * Returns the Keyring service name for ClickUp.
	 *
	 * @return string
	 */
	public function get_keyring_service_name(): string {
		return 'daily_digest_clickup_pat';
	}

	/**
	 * Tests ClickUp credentials.
	 *
	 * @param array $provider_fields Provider field values.
	 *
	 * @return array{success:bool,message:string,details?:array}
	 */
	public function test_credentials( array $provider_fields ): array {
		$token = $this->get_token( \get_current_user_id() );

		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'message' => __( 'Connect ClickUp via Keyring first.', 'daily-digest' ),
			);
		}

		$response = $this->http_get(
			'https://api.clickup.com/api/v2/user',
			array(
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

		if ( 200 !== $status_code || ! \is_array( $payload ) || empty( $payload['user'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'ClickUp credentials test failed.', 'daily-digest' ),
			);
		}

		$user_name = isset( $payload['user']['username'] ) ? (string) $payload['user']['username'] : '';

		return array(
			'success' => true,
			'message' => __( 'ClickUp credentials are valid.', 'daily-digest' ),
			'details' => array(
				'user' => $user_name,
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
		$items  = $this->fetch_tasks( $user_id, $fields, $options );

		/**
		 * Filters ClickUp provider activity items.
		 *
		 * @since 0.1.0
		 *
		 * @param array           $items    Provider activity items.
		 * @param int             $user_id  WordPress user ID.
		 * @param array           $fields   Provider field values.
		 * @param array           $options  Digest options.
		 * @param ClickupProvider $provider Provider instance.
		 */
		$items = \apply_filters( 'daily_digest_provider_clickup_activity', $items, $user_id, $fields, $options, $this );

		if ( ! \is_array( $items ) ) {
			return array();
		}

		return $this->apply_time_window( $items, $options );
	}

	/**
	 * Fetches recently updated tasks from ClickUp.
	 *
	 * @param int   $user_id User ID.
	 * @param array $fields  Provider field values.
	 * @param array $options Digest options.
	 *
	 * @return array
	 */
	private function fetch_tasks( int $user_id, array $fields, array $options ): array {
		$token = $this->get_token( $user_id );

		if ( empty( $token ) ) {
			return array();
		}

		$team_ids = $this->resolve_team_ids( $user_id, $token );
		if ( empty( $team_ids ) ) {
			return array();
		}

		$days           = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;
		$since_unix     = \strtotime( '-' . $days . ' days' );
		$since_millis   = false !== $since_unix ? (int) $since_unix * 1000 : 0;
		$activity_items = array();
		$max_pages      = 5;

		foreach ( $team_ids as $team_id ) {
			$current_page = 0;
			$base_url     = 'https://api.clickup.com/api/v2/team/' . rawurlencode( (string) $team_id ) . '/task';

			while ( $current_page < $max_pages ) {
				$request_url = \add_query_arg(
					array(
						'include_closed'  => 'true',
						'page'            => (string) $current_page,
						'date_updated_gt' => (string) $since_millis,
					),
					$base_url
				);

				$response = $this->http_get(
					$request_url,
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $token,
						),
					)
				);

				if ( \is_wp_error( $response ) ) {
					break;
				}

				$status_code = (int) \wp_remote_retrieve_response_code( $response );
				if ( 200 !== $status_code ) {
					break;
				}

				$body    = (string) \wp_remote_retrieve_body( $response );
				$payload = \json_decode( $body, true );
				$tasks   = isset( $payload['tasks'] ) && \is_array( $payload['tasks'] ) ? $payload['tasks'] : array();

				if ( empty( $tasks ) ) {
					break;
				}

				foreach ( $tasks as $task ) {
					if ( ! \is_array( $task ) ) {
						continue;
					}

					$activity_items[] = $this->map_task_to_item( $task );
				}

				if ( count( $tasks ) < 100 ) {
					break;
				}

				++$current_page;
			}
		}

		return \array_values(
			\array_filter(
				$activity_items,
				static function ( array $item ): bool {
					return ! empty( $item['timestamp'] ) && ! empty( $item['title'] );
				}
			)
		);
	}

	/**
	 * Resolves ClickUp team IDs from Keyring metadata or API.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   ClickUp token.
	 *
	 * @return array<int, string>
	 */
	private function resolve_team_ids( int $user_id, string $token ): array {
		$team_ids = $this->get_token_meta( $user_id, 'team_ids' );

		if ( \is_array( $team_ids ) && ! empty( $team_ids ) ) {
			return \array_values(
				\array_filter(
					\array_map( 'strval', $team_ids ),
					static function ( string $team_id ): bool {
						return '' !== \trim( $team_id );
					}
				)
			);
		}

		$default_team_id = $this->get_token_meta( $user_id, 'default_team_id' );
		if ( \is_string( $default_team_id ) && '' !== \trim( $default_team_id ) ) {
			return array( \trim( $default_team_id ) );
		}

		$response = $this->http_get(
			'https://api.clickup.com/api/v2/team',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( \is_wp_error( $response ) || 200 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$payload = \json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		$teams   = isset( $payload['teams'] ) && \is_array( $payload['teams'] ) ? $payload['teams'] : array();

		if ( empty( $teams ) ) {
			return array();
		}

		$resolved = array();
		foreach ( $teams as $team ) {
			if ( ! \is_array( $team ) || empty( $team['id'] ) ) {
				continue;
			}

			$resolved[] = (string) $team['id'];
		}

		return $resolved;
	}

	/**
	 * Maps ClickUp task payload to digest item.
	 *
	 * @param array $task ClickUp task payload.
	 *
	 * @return array
	 */
	private function map_task_to_item( array $task ): array {
		$updated_millis = isset( $task['date_updated'] ) ? (string) $task['date_updated'] : '';
		$updated_unix   = ! empty( $updated_millis ) ? (int) floor( (int) $updated_millis / 1000 ) : 0;
		$timestamp      = $updated_unix > 0 ? \gmdate( 'c', $updated_unix ) : '';
		$title          = isset( $task['name'] ) ? (string) $task['name'] : __( 'ClickUp Task', 'daily-digest' );
		$status         = isset( $task['status']['status'] ) ? (string) $task['status']['status'] : '';
		$list_name      = isset( $task['list']['name'] ) ? (string) $task['list']['name'] : '';
		$url            = isset( $task['url'] ) ? (string) $task['url'] : '';
		$summary_parts  = array();

		if ( ! empty( $status ) ) {
			/* translators: %s: ClickUp task status value. */
			$summary_parts[] = \sprintf( __( 'Status: %s', 'daily-digest' ), $status );
		}

		if ( ! empty( $list_name ) ) {
			/* translators: %s: ClickUp list name. */
			$summary_parts[] = \sprintf( __( 'List: %s', 'daily-digest' ), $list_name );
		}

		return array(
			'provider'  => 'ClickUp',
			'type'      => 'task',
			'timestamp' => $timestamp,
			'title'     => $title,
			'summary'   => \implode( ' • ', $summary_parts ),
			'url'       => $url,
			'raw'       => $task,
		);
	}
}
