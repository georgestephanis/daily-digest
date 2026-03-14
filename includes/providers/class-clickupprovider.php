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
					'Authorization' => $this->build_authorization_header( $token ),
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

		if ( isset( $options['since'] ) ) {
			$since_millis = (int) $options['since'] * 1000;
		} else {
			$days         = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;
			$since_unix   = \strtotime( '-' . $days . ' days' );
			$since_millis = false !== $since_unix ? (int) $since_unix * 1000 : 0;
		}
		$activity_items = array();
		$max_pages      = 5;
		$identity       = $this->build_user_identity_context( $user_id );

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
							'Authorization' => $this->build_authorization_header( $token ),
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

					if ( ! $this->is_task_relevant_to_user( $task, $identity ) ) {
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
					'Authorization' => $this->build_authorization_header( $token ),
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
	 * Builds a lightweight identity context for relevance filtering.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return array<string, string>
	 */
	private function build_user_identity_context( int $user_id ): array {
		$clickup_user_id = $this->get_token_meta( $user_id, 'user_id' );
		$username        = $this->get_token_meta( $user_id, 'username' );
		$name            = $this->get_token_meta( $user_id, 'name' );

		$context = array(
			'user_id'  => \is_scalar( $clickup_user_id ) ? \trim( (string) $clickup_user_id ) : '',
			'username' => \is_scalar( $username ) ? \trim( (string) $username ) : '',
			'name'     => \is_scalar( $name ) ? \trim( (string) $name ) : '',
		);

		if ( '' === $context['name'] && '' !== $context['username'] ) {
			$context['name'] = $context['username'];
		}

		if ( '' === $context['username'] && '' !== $context['name'] ) {
			$context['username'] = $context['name'];
		}

		return $context;
	}

	/**
	 * Determines whether a task payload is relevant to the connected user.
	 *
	 * @param array                $task     ClickUp task payload.
	 * @param array<string, string> $identity Identity context.
	 *
	 * @return bool
	 */
	private function is_task_relevant_to_user( array $task, array $identity ): bool {
		$user_id  = isset( $identity['user_id'] ) ? \trim( (string) $identity['user_id'] ) : '';
		$username = isset( $identity['username'] ) ? \trim( (string) $identity['username'] ) : '';
		$name     = isset( $identity['name'] ) ? \trim( (string) $identity['name'] ) : '';

		if ( '' === $user_id && '' === $username && '' === $name ) {
			return true;
		}

		if ( ! empty( $task['assignees'] ) && \is_array( $task['assignees'] ) ) {
			foreach ( $task['assignees'] as $assignee ) {
				if ( ! \is_array( $assignee ) || empty( $assignee['id'] ) ) {
					continue;
				}

				if ( '' !== $user_id && (string) $assignee['id'] === $user_id ) {
					return true;
				}
			}
		}

		if ( ! empty( $task['watchers'] ) && \is_array( $task['watchers'] ) ) {
			foreach ( $task['watchers'] as $watcher ) {
				if ( ! \is_array( $watcher ) || empty( $watcher['id'] ) ) {
					continue;
				}

				if ( '' !== $user_id && (string) $watcher['id'] === $user_id ) {
					return true;
				}
			}
		}

		if ( ! empty( $task['mentions'] ) && \is_array( $task['mentions'] ) ) {
			foreach ( $task['mentions'] as $mention ) {
				if ( ! \is_array( $mention ) ) {
					continue;
				}

				$mention_id       = isset( $mention['id'] ) ? \trim( (string) $mention['id'] ) : '';
				$mention_username = isset( $mention['username'] ) ? \trim( (string) $mention['username'] ) : '';

				if ( '' !== $user_id && '' !== $mention_id && $mention_id === $user_id ) {
					return true;
				}

				if ( '' !== $username && '' !== $mention_username && 0 === \strcasecmp( $mention_username, $username ) ) {
					return true;
				}
			}
		}

		if ( ! empty( $task['comment'] ) && \is_array( $task['comment'] ) ) {
			$comment = $task['comment'];

			if ( ! empty( $comment['assignee']['id'] ) && '' !== $user_id && (string) $comment['assignee']['id'] === $user_id ) {
				return true;
			}

			if ( ! empty( $comment['assigned_to']['id'] ) && '' !== $user_id && (string) $comment['assigned_to']['id'] === $user_id ) {
				return true;
			}
		}

		$haystack_parts = array();
		foreach ( array( 'name', 'text_content', 'description' ) as $field_key ) {
			if ( ! empty( $task[ $field_key ] ) && \is_scalar( $task[ $field_key ] ) ) {
				$haystack_parts[] = (string) $task[ $field_key ];
			}
		}

		if ( ! empty( $task['comment']['comment_text'] ) && \is_scalar( $task['comment']['comment_text'] ) ) {
			$haystack_parts[] = (string) $task['comment']['comment_text'];
		}

		$haystack = \strtolower( \implode( ' ', $haystack_parts ) );

		if ( '' !== $username && ( false !== \strpos( $haystack, \strtolower( '@' . $username ) ) || false !== \strpos( $haystack, \strtolower( $username ) ) ) ) {
			return true;
		}

		if ( '' !== $name && false !== \strpos( $haystack, \strtolower( $name ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Builds the ClickUp Authorization header value for the active service type.
	 *
	 * @param string $token Access token.
	 *
	 * @return string
	 */
	private function build_authorization_header( string $token ): string {
		$service_name = $this->get_keyring_service_name();

		if ( 'daily_digest_clickup_oauth2' === $service_name ) {
			return 'Bearer ' . $token;
		}

		return $token;
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
