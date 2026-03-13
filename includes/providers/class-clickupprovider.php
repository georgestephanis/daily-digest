<?php
/**
 * ClickUp provider adapter class.
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
 * ClickUp provider adapter.
 */
class ClickupProvider implements ProviderInterface {
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
	 * Returns provider settings fields.
	 *
	 * @return array
	 */
	public function get_fields(): array {
		return array(
			'workspace_id' => \__( 'Workspace ID', 'daily-digest' ),
		);
	}

	/**
	 * Tests ClickUp credentials.
	 *
	 * @param array $provider_fields Provider field values.
	 *
	 * @return array{success:bool,message:string,details?:array}
	 */
	public function test_credentials( array $provider_fields ): array {
		$token = $this->keyring_connections->get_access_token_string( 'clickup', \get_current_user_id() );

		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'message' => __( 'Connect ClickUp via Keyring first.', 'daily-digest' ),
			);
		}

		$response = \wp_remote_get(
			'https://api.clickup.com/api/v2/user',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => $token,
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
	 * @param array $fields  Provider field values.
	 * @param array $options Digest options.
	 *
	 * @return array
	 */
	private function fetch_tasks( int $user_id, array $fields, array $options ): array {
		$workspace_id = isset( $fields['workspace_id'] ) ? \trim( (string) $fields['workspace_id'] ) : '';
		$token        = $this->keyring_connections->get_access_token_string( 'clickup', $user_id );

		if ( empty( $workspace_id ) || empty( $token ) ) {
			return array();
		}

		$days           = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;
		$since_unix     = \strtotime( '-' . $days . ' days' );
		$since_millis   = false !== $since_unix ? (int) $since_unix * 1000 : 0;
		$base_url       = 'https://api.clickup.com/api/v2/team/' . rawurlencode( $workspace_id ) . '/task';
		$current_page   = 0;
		$max_pages      = 5;
		$activity_items = array();

		while ( $current_page < $max_pages ) {
			$request_url = \add_query_arg(
				array(
					'include_closed'  => 'true',
					'page'            => (string) $current_page,
					'date_updated_gt' => (string) $since_millis,
				),
				$base_url
			);

			$response = \wp_remote_get(
				$request_url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => $token,
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
