<?php
/**
 * Asana provider adapter class.
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
 * Asana provider adapter.
 */
class AsanaProvider extends AbstractProvider {
	/**
	 * Returns provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'asana';
	}

	/**
	 * Returns provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Asana';
	}

	/**
	 * Returns the Keyring service name for Asana.
	 *
	 * @return string
	 */
	public function get_keyring_service_name(): string {
		return 'daily_digest_asana';
	}

	/**
	 * Tests Asana credentials.
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
				'message' => __( 'Connect Asana via Keyring first.', 'daily-digest' ),
			);
		}

		$response = $this->http_get(
			'https://app.asana.com/api/1.0/users/me',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
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
		$user        = isset( $payload['data'] ) && \is_array( $payload['data'] ) ? $payload['data'] : array();

		if ( 200 !== $status_code || empty( $user['gid'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Asana credentials test failed.', 'daily-digest' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Asana credentials are valid.', 'daily-digest' ),
			'details' => array(
				'user' => isset( $user['name'] ) ? (string) $user['name'] : '',
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
		 * Filters Asana provider activity items.
		 *
		 * @since 0.1.0
		 *
		 * @param array         $items    Provider activity items.
		 * @param int           $user_id  WordPress user ID.
		 * @param array         $fields   Provider field values.
		 * @param array         $options  Digest options.
		 * @param AsanaProvider $provider Provider instance.
		 */
		$items = \apply_filters( 'daily_digest_provider_asana_activity', $items, $user_id, $fields, $options, $this );

		if ( ! \is_array( $items ) ) {
			return array();
		}

		return $this->apply_time_window( $items, $options );
	}

	/**
	 * Fetches recently modified tasks from Asana.
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

		$workspace_ids = $this->resolve_workspace_ids( $user_id, $token );
		if ( empty( $workspace_ids ) ) {
			return array();
		}

		if ( isset( $options['since'] ) ) {
			$modified_since = \gmdate( 'c', (int) $options['since'] );
		} else {
			$days           = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;
			$modified_since = \gmdate( 'c', (int) \strtotime( '-' . $days . ' days' ) );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
		);

		$items = array();

		foreach ( $workspace_ids as $workspace_id ) {
			$offset      = '';
			$page_count  = 0;
			$max_pages   = 5;
			$request_url = 'https://app.asana.com/api/1.0/tasks';

			while ( $page_count < $max_pages ) {
				$query_args = array(
					'assignee'       => 'me',
					'workspace'      => $workspace_id,
					'modified_since' => $modified_since,
					'limit'          => '100',
					'opt_fields'     => 'gid,name,notes,completed,completed_at,modified_at,due_on,due_at,permalink_url,projects.name',
				);

				if ( '' !== $offset ) {
					$query_args['offset'] = $offset;
				}

				$response = $this->http_get(
					\add_query_arg( $query_args, $request_url ),
					array( 'headers' => $headers )
				);

				if ( \is_wp_error( $response ) ) {
					break;
				}

				if ( 200 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
					break;
				}

				$payload = \json_decode( (string) \wp_remote_retrieve_body( $response ), true );
				$tasks   = isset( $payload['data'] ) && \is_array( $payload['data'] ) ? $payload['data'] : array();

				if ( empty( $tasks ) ) {
					break;
				}

				foreach ( $tasks as $task ) {
					if ( ! \is_array( $task ) ) {
						continue;
					}

					$items[] = $this->map_task_to_item( $task );
				}

				$offset = isset( $payload['next_page']['offset'] ) ? (string) $payload['next_page']['offset'] : '';
				if ( '' === $offset ) {
					break;
				}

				++$page_count;
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
	 * Resolves Asana workspace IDs from token metadata or API.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Asana token.
	 *
	 * @return array<int, string>
	 */
	private function resolve_workspace_ids( int $user_id, string $token ): array {
		$workspace_ids = $this->get_token_meta( $user_id, 'workspace_ids' );

		if ( \is_array( $workspace_ids ) && ! empty( $workspace_ids ) ) {
			return \array_values(
				\array_filter(
					\array_map( 'strval', $workspace_ids ),
					static function ( string $workspace_id ): bool {
						return '' !== \trim( $workspace_id );
					}
				)
			);
		}

		$default_workspace_id = (string) $this->get_token_meta( $user_id, 'default_workspace_id' );
		if ( '' !== \trim( $default_workspace_id ) ) {
			return array( $default_workspace_id );
		}

		$response = $this->http_get(
			'https://app.asana.com/api/1.0/workspaces',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( \is_wp_error( $response ) || 200 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$payload    = \json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		$workspaces = isset( $payload['data'] ) && \is_array( $payload['data'] ) ? $payload['data'] : array();

		$resolved = array();
		foreach ( $workspaces as $workspace ) {
			if ( ! \is_array( $workspace ) || empty( $workspace['gid'] ) ) {
				continue;
			}

			$resolved[] = (string) $workspace['gid'];
		}

		return $resolved;
	}

	/**
	 * Maps one Asana task payload into digest item schema.
	 *
	 * @param array $task Asana task payload.
	 *
	 * @return array
	 */
	private function map_task_to_item( array $task ): array {
		$title        = isset( $task['name'] ) ? (string) $task['name'] : __( 'Asana Task', 'daily-digest' );
		$timestamp    = isset( $task['completed_at'] ) ? (string) $task['completed_at'] : ( isset( $task['modified_at'] ) ? (string) $task['modified_at'] : '' );
		$task_url     = isset( $task['permalink_url'] ) ? (string) $task['permalink_url'] : '';
		$completed    = ! empty( $task['completed'] );
		$task_type    = $completed ? __( 'Task Completed', 'daily-digest' ) : __( 'Task Updated', 'daily-digest' );
		$summary      = array();
		$project_name = '';

		if ( isset( $task['projects'][0]['name'] ) ) {
			$project_name = (string) $task['projects'][0]['name'];
		}

		if ( '' !== $project_name ) {
			/* translators: %s: project name. */
			$summary[] = \sprintf( __( 'Project: %s', 'daily-digest' ), $project_name );
		}

		if ( isset( $task['due_on'] ) && '' !== (string) $task['due_on'] ) {
			/* translators: %s: due date. */
			$summary[] = \sprintf( __( 'Due: %s', 'daily-digest' ), (string) $task['due_on'] );
		}

		if ( '' === $timestamp && isset( $task['due_at'] ) ) {
			$timestamp = (string) $task['due_at'];
		}

		if ( '' === $timestamp && isset( $task['due_on'] ) && '' !== (string) $task['due_on'] ) {
			$timestamp = (string) $task['due_on'] . 'T00:00:00+00:00';
		}

		return array(
			'provider'  => 'Asana',
			'type'      => $task_type,
			'timestamp' => $timestamp,
			'title'     => $title,
			'summary'   => \implode( ' | ', $summary ),
			'url'       => $task_url,
			'raw'       => $task,
		);
	}
}
