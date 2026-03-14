<?php
/**
 * Harvest provider adapter class.
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
 * Harvest provider adapter.
 */
class HarvestProvider extends AbstractProvider {
	/**
	 * Returns provider slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'harvest';
	}

	/**
	 * Returns provider display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Harvest';
	}

	/**
	 * Returns Harvest provider field definitions.
	 *
	 * @return array
	 */
	public function get_fields(): array {
		return array(
			'account_id' => __( 'Account ID (optional override)', 'daily-digest' ),
		);
	}

	/**
	 * Returns the default Keyring service name for Harvest.
	 *
	 * @return string
	 */
	public function get_keyring_service_name(): string {
		return 'daily_digest_harvest_pat';
	}

	/**
	 * Tests Harvest credentials.
	 *
	 * @param array $provider_fields Provider field values.
	 *
	 * @return array{success:bool,message:string,details?:array}
	 */
	public function test_credentials( array $provider_fields ): array {
		$user_id    = \get_current_user_id();
		$token      = $this->get_token( $user_id );
		$account_id = $this->resolve_account_id( $user_id, $provider_fields );

		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'message' => __( 'Connect Harvest via Keyring first.', 'daily-digest' ),
			);
		}

		if ( '' === $account_id ) {
			return array(
				'success' => false,
				'message' => __( 'Harvest account ID is missing. Add it in the provider settings or reconnect.', 'daily-digest' ),
			);
		}

		$response = $this->http_get(
			'https://api.harvestapp.com/v2/users/me',
			array(
				'headers' => $this->build_harvest_headers( $token, $account_id ),
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

		if ( 200 !== $status_code || ! \is_array( $payload ) || empty( $payload['id'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Harvest credentials test failed.', 'daily-digest' ),
			);
		}

		$user_name = trim(
			implode(
				' ',
				array_filter(
					array(
						isset( $payload['first_name'] ) ? (string) $payload['first_name'] : '',
						isset( $payload['last_name'] ) ? (string) $payload['last_name'] : '',
					)
				)
			)
		);

		return array(
			'success' => true,
			'message' => __( 'Harvest credentials are valid.', 'daily-digest' ),
			'details' => array(
				'user'       => '' !== $user_name ? $user_name : ( isset( $payload['email'] ) ? (string) $payload['email'] : '' ),
				'account_id' => $account_id,
			),
		);
	}

	/**
	 * Fetches Harvest activity data.
	 *
	 * @param int   $user_id           User ID.
	 * @param array $provider_settings Provider settings.
	 * @param array $options           Query options.
	 *
	 * @return array
	 */
	public function fetch_activity( int $user_id, array $provider_settings = array(), array $options = array() ): array {
		$fields = $provider_settings['fields'] ?? array();
		$items  = $this->fetch_time_entries( $user_id, $fields, $options );

		/**
		 * Filters Harvest provider activity items.
		 *
		 * @since 0.1.0
		 *
		 * @param array           $items    Provider activity items.
		 * @param int             $user_id  WordPress user ID.
		 * @param array           $fields   Provider field values.
		 * @param array           $options  Digest options.
		 * @param HarvestProvider $provider Provider instance.
		 */
		$items = \apply_filters( 'daily_digest_provider_harvest_activity', $items, $user_id, $fields, $options, $this );

		if ( ! \is_array( $items ) ) {
			return array();
		}

		return $this->apply_time_window( $items, $options );
	}

	/**
	 * Fetches time entries from Harvest.
	 *
	 * @param int   $user_id User ID.
	 * @param array $fields  Provider field values.
	 * @param array $options Digest options.
	 *
	 * @return array
	 */
	private function fetch_time_entries( int $user_id, array $fields, array $options ): array {
		$token = $this->get_token( $user_id );
		if ( '' === $token ) {
			return array();
		}

		$account_id = $this->resolve_account_id( $user_id, $fields );
		if ( '' === $account_id ) {
			return array();
		}

		$days      = isset( $options['days'] ) ? \max( 1, \absint( $options['days'] ) ) : 1;
		$from_date = \gmdate( 'Y-m-d', \strtotime( '-' . ( $days - 1 ) . ' days' ) );
		$to_date   = \gmdate( 'Y-m-d' );

		$meta_user_id = (string) $this->get_token_meta( $user_id, 'user_id' );
		$page         = 1;
		$max_pages    = 5;
		$items        = array();

		while ( $page <= $max_pages ) {
			$query_args = array(
				'from'     => $from_date,
				'to'       => $to_date,
				'page'     => (string) $page,
				'per_page' => '2000',
			);

			if ( '' !== $meta_user_id ) {
				$query_args['user_id'] = $meta_user_id;
			}

			$request_url = \add_query_arg( $query_args, 'https://api.harvestapp.com/v2/time_entries' );
			$response    = $this->http_get(
				$request_url,
				array(
					'headers' => $this->build_harvest_headers( $token, $account_id ),
				)
			);

			if ( \is_wp_error( $response ) ) {
				break;
			}

			if ( 200 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
				break;
			}

			$payload = \json_decode( (string) \wp_remote_retrieve_body( $response ), true );
			$entries = isset( $payload['time_entries'] ) && \is_array( $payload['time_entries'] ) ? $payload['time_entries'] : array();

			if ( empty( $entries ) ) {
				break;
			}

			foreach ( $entries as $entry ) {
				if ( ! \is_array( $entry ) ) {
					continue;
				}

				if ( '' !== $meta_user_id ) {
					$entry_user_id = isset( $entry['user']['id'] ) ? (string) $entry['user']['id'] : '';
					if ( '' !== $entry_user_id && $entry_user_id !== $meta_user_id ) {
						continue;
					}
				}

				$items[] = $this->map_entry_to_item( $entry );
			}

			$next_page = isset( $payload['next_page'] ) ? (int) $payload['next_page'] : 0;
			if ( $next_page <= 0 ) {
				break;
			}

			$page = $next_page;
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
	 * Resolves Harvest account ID from provider fields or token metadata.
	 *
	 * @param int   $user_id User ID.
	 * @param array $fields  Provider field values.
	 *
	 * @return string
	 */
	private function resolve_account_id( int $user_id, array $fields ): string {
		if ( ! empty( $fields['account_id'] ) ) {
			return trim( (string) $fields['account_id'] );
		}

		$meta_account_id = $this->get_token_meta( $user_id, 'account_id' );
		if ( \is_scalar( $meta_account_id ) && '' !== trim( (string) $meta_account_id ) ) {
			return trim( (string) $meta_account_id );
		}

		$account_ids = $this->get_token_meta( $user_id, 'account_ids' );
		if ( \is_array( $account_ids ) && ! empty( $account_ids[0] ) ) {
			return trim( (string) $account_ids[0] );
		}

		return '';
	}

	/**
	 * Builds Harvest API request headers.
	 *
	 * @param string $token      Access token.
	 * @param string $account_id Harvest account ID.
	 *
	 * @return array<string, string>
	 */
	private function build_harvest_headers( string $token, string $account_id ): array {
		return array(
			'Authorization'      => 'Bearer ' . $token,
			'Harvest-Account-Id' => $account_id,
			'User-Agent'         => $this->build_user_agent(),
			'Accept'             => 'application/json',
		);
	}

	/**
	 * Maps Harvest time entry payload to digest item.
	 *
	 * @param array $entry Harvest time entry payload.
	 *
	 * @return array
	 */
	private function map_entry_to_item( array $entry ): array {
		$project_name = isset( $entry['project']['name'] ) ? (string) $entry['project']['name'] : '';
		$task_name    = isset( $entry['task']['name'] ) ? (string) $entry['task']['name'] : '';
		$client_name  = isset( $entry['client']['name'] ) ? (string) $entry['client']['name'] : '';
		$notes        = isset( $entry['notes'] ) ? trim( (string) $entry['notes'] ) : '';
		$hours        = isset( $entry['hours'] ) ? (string) $entry['hours'] : '';
		$spent_date   = isset( $entry['spent_date'] ) ? (string) $entry['spent_date'] : '';
		$updated_at   = isset( $entry['updated_at'] ) ? (string) $entry['updated_at'] : '';
		$timestamp    = '' !== $updated_at ? $updated_at : ( '' !== $spent_date ? $spent_date . 'T12:00:00Z' : '' );

		$title_parts = array_values(
			array_filter(
				array( $project_name, $task_name ),
				static function ( string $part ): bool {
					return '' !== trim( $part );
				}
			)
		);

		$title = ! empty( $title_parts )
			? implode( ' - ', $title_parts )
			: __( 'Harvest Time Entry', 'daily-digest' );

		$summary_parts = array();
		if ( '' !== $hours ) {
			/* translators: %s is the decimal number of hours tracked. */
			$summary_parts[] = sprintf( __( 'Hours: %s', 'daily-digest' ), $hours );
		}
		if ( '' !== $client_name ) {
			/* translators: %s is the Harvest client name. */
			$summary_parts[] = sprintf( __( 'Client: %s', 'daily-digest' ), $client_name );
		}
		if ( '' !== $spent_date ) {
			/* translators: %s is the Harvest spent date. */
			$summary_parts[] = sprintf( __( 'Date: %s', 'daily-digest' ), $spent_date );
		}
		if ( '' !== $notes ) {
			$summary_parts[] = $notes;
		}

		$url = '';
		if ( isset( $entry['external_reference']['permalink'] ) ) {
			$url = (string) $entry['external_reference']['permalink'];
		}

		return array(
			'provider'  => 'Harvest',
			'type'      => 'time_entry',
			'timestamp' => $timestamp,
			'title'     => $title,
			'summary'   => implode( ' • ', $summary_parts ),
			'url'       => $url,
			'raw'       => $entry,
		);
	}

	/**
	 * Builds a Harvest-compatible User-Agent header value.
	 *
	 * @return string
	 */
	private function build_user_agent(): string {
		$site_name = (string) \get_bloginfo( 'name' );
		$admin     = (string) \get_bloginfo( 'admin_email' );

		if ( '' !== trim( $site_name ) && '' !== trim( $admin ) ) {
			return trim( $site_name ) . ' (' . trim( $admin ) . ')';
		}

		return 'DailyDigestWP/' . DAILY_DIGEST_PLUGIN_VERSION;
	}
}
