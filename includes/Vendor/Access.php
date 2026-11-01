<?php
/**
 * Who is the current vendor, and what may they touch. Every vendor route goes through here.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Vendor;

use Dev3\AiVendorAssistant\Core3\Api\Api_Error;
use Dev3\AiVendorAssistant\Marketplace\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Ownership + access checks.
 */
class Access {

	/**
	 * Vendor id for the logged-in user (vendor or staff), or 0.
	 *
	 * @return int
	 */
	public static function current_vendor() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}
		$adapter = Manager::current();
		$vendor  = $adapter ? $adapter->vendor_for_user( $user_id ) : 0;
		/**
		 * Vendor id the current user acts for (0 = not a vendor).
		 *
		 * @param int $vendor  Vendor id.
		 * @param int $user_id User id.
		 */
		return (int) apply_filters( 'dev3va_current_vendor', $vendor, $user_id );
	}

	/**
	 * Admin switch: may this vendor use the assistant at all?
	 *
	 * @param int $vendor_id Vendor.
	 * @return bool
	 */
	public static function has_access( $vendor_id ) {
		if ( (int) $vendor_id <= 0 ) {
			return false;
		}
		$flag = get_user_meta( (int) $vendor_id, '_dev3va_access', true );
		return '0' !== (string) $flag;
	}

	/**
	 * Throws the standard "not your item" error.
	 *
	 * @return \WP_Error
	 */
	public static function not_found() {
		return Api_Error::make( 'dev3va_not_found', __( 'That item was not found in your store.', 'dev3-ai-vendor-assistant' ), '', 404 );
	}

	/**
	 * Product owned by the vendor, or WP_Error (404, so ids of other stores are not confirmed).
	 *
	 * @param int $vendor_id  Vendor.
	 * @param int $product_id Product.
	 * @return \WC_Product|\WP_Error
	 */
	public static function product( $vendor_id, $product_id ) {
		$adapter = Manager::current();
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
		if ( ! $adapter || ! $product || ! $adapter->owns_product( $vendor_id, $product->get_id() ) ) {
			return self::not_found();
		}
		return $product;
	}

	/**
	 * Attachment uploaded by the vendor (or their staff), or WP_Error.
	 *
	 * @param int $vendor_id     Vendor.
	 * @param int $attachment_id Attachment.
	 * @return \WP_Post|\WP_Error
	 */
	public static function photo( $vendor_id, $attachment_id ) {
		$post = get_post( (int) $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $post ) ) {
			return self::not_found();
		}
		$author  = (int) $post->post_author;
		$adapter = Manager::current();
		$owner   = $adapter ? $adapter->vendor_for_user( $author ) : 0;
		if ( $owner !== (int) $vendor_id ) {
			return self::not_found();
		}
		return $post;
	}

	/**
	 * Order containing the vendor's items, or WP_Error.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $order_id  Order.
	 * @return \WC_Order|\WP_Error
	 */
	public static function order( $vendor_id, $order_id ) {
		$adapter = Manager::current();
		$order   = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! $adapter || ! $order || ! $adapter->owns_order( $vendor_id, $order->get_id() ) ) {
			return self::not_found();
		}
		return $order;
	}
}
