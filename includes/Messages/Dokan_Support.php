<?php
/**
 * Dokan Pro "Store Support" topics (post type `dokan_store_support`, store in `store_id` meta,
 * replies are comments). Dokan Pro is paid: this source follows its documented data model and is
 * tested with a stub post type.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Messages;

use Dev3\AiVendorAssistant\Core3\Api\Api_Error;
use Dev3\AiVendorAssistant\Features\Text;
use Dev3\AiVendorAssistant\Marketplace\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Store-support source.
 */
class Dokan_Support extends Source {

	const POST_TYPE = 'dokan_store_support';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'dokan_support';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Support topic', 'dev3-ai-vendor-assistant' );
	}

	/**
	 * Available when Dokan Pro registered its post type.
	 *
	 * @return bool
	 */
	public function available() {
		return post_type_exists( self::POST_TYPE );
	}

	/**
	 * Meta key with the store id.
	 *
	 * @return string
	 */
	private function store_key() {
		/**
		 * Meta key Dokan Pro stores the topic's vendor in.
		 *
		 * @param string $key Key.
		 */
		return (string) apply_filters( 'dev3va_dokan_support_store_meta', 'store_id' );
	}

	/**
	 * Shapes a topic.
	 *
	 * @param \WP_Post $post      Topic.
	 * @param int      $vendor_id Vendor.
	 * @return array
	 */
	private function shape( $post, $vendor_id ) {
		$adapter = Manager::current();
		$reply   = '';
		foreach ( get_comments(
			array(
				'post_id' => $post->ID,
				'number'  => 10,
				'order'   => 'DESC',
			)
		) as $c ) {
			if ( $c->user_id && $adapter && $adapter->vendor_for_user( (int) $c->user_id ) === (int) $vendor_id ) {
				$reply = $c->comment_content;
				break;
			}
		}
		$author = get_userdata( (int) $post->post_author );
		return $this->row(
			array(
				'id'       => (int) $post->ID,
				'buyer'    => Text::first_name( $author ? ( $author->first_name ? $author->first_name : $author->display_name ) : '' ),
				'subject'  => get_the_title( $post ),
				'text'     => Text::block( wp_strip_all_tags( $post->post_content ), 3000 ),
				'order_id' => (int) get_post_meta( $post->ID, 'order_id', true ),
				'date'     => mysql_to_rfc3339( $post->post_date_gmt ),
				'replied'  => '' !== $reply,
				'reply'    => Text::block( $reply, 3000 ),
			)
		);
	}

	/**
	 * Items.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $limit     Limit.
	 * @return array[]
	 */
	public function items( $vendor_id, $limit = 30 ) {
		if ( ! $this->available() ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => (int) $limit,
				'meta_key'       => $this->store_key(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (int) $vendor_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			$out[] = $this->shape( $post, $vendor_id );
		}
		return $out;
	}

	/**
	 * One topic of the vendor.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $id        Topic.
	 * @return array|null
	 */
	public function get( $vendor_id, $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || (int) get_post_meta( $post->ID, $this->store_key(), true ) !== (int) $vendor_id ) {
			return null;
		}
		return $this->shape( $post, $vendor_id );
	}

	/**
	 * Adds the vendor's reply as a topic comment.
	 *
	 * @param int    $vendor_id Vendor.
	 * @param int    $user_id   User.
	 * @param int    $id        Topic.
	 * @param string $reply     Reply.
	 * @return true|\WP_Error
	 */
	public function send( $vendor_id, $user_id, $id, $reply ) {
		if ( ! $this->get( $vendor_id, $id ) ) {
			return Api_Error::make( 'dev3va_not_found', __( 'That item was not found in your store.', 'dev3-ai-vendor-assistant' ), '', 404 );
		}
		$user = get_userdata( (int) $user_id );
		$new  = wp_insert_comment(
			array(
				'comment_post_ID'      => (int) $id,
				'user_id'              => (int) $user_id,
				'comment_author'       => $user ? $user->display_name : '',
				'comment_author_email' => $user ? $user->user_email : '',
				'comment_content'      => $reply,
				'comment_approved'     => 1,
			)
		);
		if ( ! $new ) {
			return Api_Error::make( 'dev3va_send_failed', __( 'The reply could not be posted.', 'dev3-ai-vendor-assistant' ), '', 500 );
		}
		/**
		 * After DEV3 AI replied to a Dokan Pro support topic (Dokan Pro sends its own emails on comment hooks).
		 *
		 * @param int $id        Topic.
		 * @param int $comment   Comment id.
		 * @param int $vendor_id Vendor.
		 */
		do_action( 'dev3va_dokan_support_replied', (int) $id, (int) $new, (int) $vendor_id );
		return true;
	}
}
