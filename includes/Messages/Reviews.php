<?php
/**
 * Product reviews on the vendor's products; replies are threaded review comments by the vendor.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Messages;

use Dev3\AiVendorAssistant\Core3\Api\Api_Error;
use Dev3\AiVendorAssistant\Features\Text;
use Dev3\AiVendorAssistant\Marketplace\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews source.
 */
class Reviews extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'reviews';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Product review', 'dev3-ai-vendor-assistant' );
	}

	/**
	 * Available.
	 *
	 * @return bool
	 */
	public function available() {
		return function_exists( 'wc_get_product' );
	}

	/**
	 * Vendor's product ids.
	 *
	 * @param int $vendor_id Vendor.
	 * @return int[]
	 */
	private function product_ids( $vendor_id ) {
		$adapter = Manager::current();
		return $adapter ? $adapter->product_ids( $vendor_id, array( 'post_status' => 'publish' ) ) : array();
	}

	/**
	 * Vendor reply under a review.
	 *
	 * @param \WP_Comment $comment   Review.
	 * @param int         $vendor_id Vendor.
	 * @return \WP_Comment|null
	 */
	private function vendor_reply( $comment, $vendor_id ) {
		$children = get_comments(
			array(
				'parent' => (int) $comment->comment_ID,
				'status' => 'approve',
				'number' => 5,
				'order'  => 'DESC',
			)
		);
		$adapter  = Manager::current();
		foreach ( $children as $child ) {
			if ( (int) $child->user_id && $adapter && $adapter->vendor_for_user( (int) $child->user_id ) === (int) $vendor_id ) {
				return $child;
			}
		}
		return null;
	}

	/**
	 * Shapes a review.
	 *
	 * @param \WP_Comment $c         Comment.
	 * @param int         $vendor_id Vendor.
	 * @return array
	 */
	private function shape( $c, $vendor_id ) {
		$reply = $this->vendor_reply( $c, $vendor_id );
		return $this->row(
			array(
				'id'         => (int) $c->comment_ID,
				'buyer'      => Text::first_name( $c->comment_author ),
				'subject'    => get_the_title( (int) $c->comment_post_ID ),
				'text'       => Text::block( $c->comment_content, 3000 ),
				'rating'     => (int) get_comment_meta( $c->comment_ID, 'rating', true ),
				'product_id' => (int) $c->comment_post_ID,
				'product'    => get_the_title( (int) $c->comment_post_ID ),
				'date'       => mysql_to_rfc3339( $c->comment_date_gmt ),
				'replied'    => (bool) $reply,
				'reply'      => $reply ? Text::block( $reply->comment_content, 3000 ) : '',
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
		$ids = $this->product_ids( $vendor_id );
		if ( ! $ids ) {
			return array();
		}
		$comments = get_comments(
			array(
				'post__in' => $ids,
				'type'     => 'review',
				'status'   => 'approve',
				'parent'   => 0,
				'number'   => (int) $limit,
				'orderby'  => 'comment_date_gmt',
				'order'    => 'DESC',
			)
		);
		$out      = array();
		foreach ( $comments as $c ) {
			$adapter = Manager::current();
			if ( $c->user_id && $adapter && $adapter->vendor_for_user( (int) $c->user_id ) === (int) $vendor_id ) {
				continue; // The vendor's own comment.
			}
			$out[] = $this->shape( $c, $vendor_id );
		}
		return $out;
	}

	/**
	 * One review the vendor owns.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $id        Comment id.
	 * @return array|null
	 */
	public function get( $vendor_id, $id ) {
		$c       = get_comment( (int) $id );
		$adapter = Manager::current();
		if ( ! $c || ! $adapter || ! $adapter->owns_product( $vendor_id, (int) $c->comment_post_ID ) || '1' !== (string) $c->comment_approved || 0 !== (int) $c->comment_parent ) {
			return null;
		}
		return $this->shape( $c, $vendor_id );
	}

	/**
	 * Posts the vendor's reply under the review.
	 *
	 * @param int    $vendor_id Vendor.
	 * @param int    $user_id   User.
	 * @param int    $id        Review.
	 * @param string $reply     Reply.
	 * @return true|\WP_Error
	 */
	public function send( $vendor_id, $user_id, $id, $reply ) {
		$item = $this->get( $vendor_id, $id );
		if ( ! $item ) {
			return Api_Error::make( 'dev3va_not_found', __( 'That item was not found in your store.', 'dev3-ai-vendor-assistant' ), '', 404 );
		}
		$user    = get_userdata( (int) $user_id );
		$adapter = Manager::current();
		$new     = wp_insert_comment(
			array(
				'comment_post_ID'      => (int) $item['product_id'],
				'comment_parent'       => (int) $id,
				'user_id'              => (int) $user_id,
				'comment_author'       => $adapter ? $adapter->store_name( $vendor_id ) : ( $user ? $user->display_name : '' ),
				'comment_author_email' => $user ? $user->user_email : '',
				'comment_content'      => $reply,
				'comment_type'         => 'review',
				'comment_approved'     => 1,
			)
		);
		if ( ! $new ) {
			return Api_Error::make( 'dev3va_send_failed', __( 'The reply could not be posted.', 'dev3-ai-vendor-assistant' ), '', 500 );
		}
		update_comment_meta( $new, '_dev3va_reply_by', (int) $user_id );
		return true;
	}
}
