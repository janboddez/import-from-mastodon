<?php
/**
 * Mastodon 'API client' of sorts, responsible for turning recent toots into
 * WordPress posts.
 *
 * @package Import_From_Mastodon
 */

namespace Import_From_Mastodon;

/**
 * Mastodon 'API client' of sorts, responsible for turning recent toots into
 * WordPress posts.
 */
class Mastodon_Handler {
	/**
	 * Array that holds Share on Mastodon's settings, which this plugin shares.
	 *
	 * @var array $options Plugin options.
	 */
	private $options = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Fetch settings from database. Fall back onto an empty array if none
		// exist.
		$this->options = get_option( 'share_on_mastodon_settings', array() );

		add_action( 'import_from_mastodon_get_toots', array( $this, 'get_toots' ) );
	}

	/**
	 * Pulls statuses off Mastodon and adds 'em to WordPress.
	 */
	public function get_toots() {
		if ( empty( $this->options['mastodon_access_token'] ) ) {
			return;
		}

		if ( empty( $this->options['mastodon_client_id'] ) ) {
			return;
		}

		$query_string = http_build_query(
			array(
				'exclude_replies' => 'true',
				'limit'           => 40,
				'since_id'        => $this->get_latest_toot(),
			)
		);

		$response = wp_remote_get(
			esc_url_raw( apply_filters( 'import_from_mastodon_host', $this->options['mastodon_host'] ) . '/api/v1/accounts/' . $this->get_account_id() . '/statuses?' . $query_string ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . apply_filters( 'import_from_mastodon_access_token', $this->options['mastodon_access_token'] ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		$body     = wp_remote_retrieve_body( $response );
		$statuses = @json_decode( $body );

		if ( empty( $statuses ) || ! is_array( $statuses ) ) {
			return;
		}

		// Reverse the array, so that the most recent status is inserted last.
		$statuses = array_reverse( $statuses );

		foreach ( $statuses as $status ) {
			if ( ! empty( $status->reblog ) || ! empty( $status->reblogged ) ) {
				// Skip boosts.
				continue;
			}

			$content = '';
			$title   = '';

			if ( ! empty( $status->content ) ) {
				$content = trim( html_entity_decode( wp_strip_all_tags( $status->content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$title   = wp_trim_words( $content, 10 );

				$content = apply_filters( 'toots_to_post_content', $content, $status->content );
				$title   = apply_filters( 'toots_to_post_title', $title, $status->content );
			}

			if ( empty( $content ) ) {
				// Skip.
				continue;
			}

			if ( ! empty( $status->in_reply_to_id ) ) {
				// Ignore replies.
				continue;
			}

			$args = array(
				'post_title'    => $title,
				'post_content'  => $content,
				'post_status'   => apply_filters( 'import_from_mastodon_post_status', 'draft' ),
				'post_type'     => apply_filters( 'import_from_mastodon_post_type', 'post' ),
				'post_date_gmt' => ( ! empty( $status->created_at ) ? date( 'Y-m-d H:i:s', strtotime( $status->created_at ) ) : '' ),
			);

			$post_id = wp_insert_post( $args );

			if ( is_wp_error( $post_id ) || 0 === $post_id ) {
				// Skip.
				continue;
			}

			if ( ! post_type_supports( get_post_type( $post_id ), 'custom-fields' ) ) {
				// We're done here.
				continue;
			}

			if ( ! empty( $status->id ) ) {
				update_post_meta( $post_id, '_import_from_mastodon_id', (int) $status->id );
			}

			if ( ! empty( $status->url ) ) {
				update_post_meta( $post_id, '_share_on_mastodon_url', esc_url_raw( $status->url ) );
				do_action( 'import_from_mastodon_after_insert_url', $post_id );
			}
		}
	}

	/**
	 * Get authenticated user's account ID.
	 *
	 * @return string|void The account ID.
	 */
	private function get_account_id() {
		if ( empty( $this->options['mastodon_access_token'] ) ) {
			return;
		}

		if ( empty( $this->options['mastodon_client_id'] ) ) {
			return;
		}

		$response = wp_remote_get(
			esc_url_raw( apply_filters( 'import_from_mastodon_host', $this->options['mastodon_host'] ) . '/api/v1/accounts/verify_credentials' ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . apply_filters( 'import_from_mastodon_access_token', $this->options['mastodon_access_token'] ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// An error occurred.
			error_log( print_r( $response, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}

		$body    = wp_remote_retrieve_body( $response );
		$account = @json_decode( $body );

		if ( ! empty( $account->id ) ) {
			return (int) $account->id;
		}
	}

	/**
	 * Returns the most recent toot's (Mastodon, not WordPress) ID.
	 *
	 * @return int|void Post ID.
	 */
	private function get_latest_toot() {
		// Fetch the most recent toot's post ID.
		$query = new \WP_Query(
			array(
				'post_type'   => apply_filters( 'import_from_mastodon_post_type', 'post' ),
				'post_status' => 'any',
				'orderby'     => 'ID',
				'order'       => 'DESC',
				'limit'       => 1,
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'key'     => '_import_from_mastodon_id',
					'type'    => 'NUMERIC',
					'compare' => 'EXISTS',
				),
				'fields'      => 'ids',
			)
		);
		$posts = $query->posts;

		if ( empty( $posts ) || ! is_array( $posts ) ) {
			return;
		}

		// Return Mastodon ID of most recent post with a Mastodon ID.
		return get_post_meta( reset( $posts ), '_import_from_mastodon_id', true );
	}

	/**
	 * Given a Mastodon status ID, returns the corresponding post ID. No longer
	 * used.
	 *
	 * @param  int $toot_id Toot ID.
	 * @return int|void     Post ID.
	 */
	private function get_post( $toot_id ) {
		// Fetch exactly one post with this Mastodon ID.
		$query = new \WP_Query(
			array(
				'post_type'      => apply_filters( 'import_from_mastodon_post_type', 'post' ),
				'post_status'    => 'any',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'limit'          => 1,
				'meta_key'       => '_import_from_mastodon_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value_num' => (int) $toot_id,
				'fields'         => 'ids',
			)
		);
		$posts = $query->posts;

		if ( ! empty( $posts ) && is_array( $posts ) ) {
			return reset( $posts );
		}
	}
}
