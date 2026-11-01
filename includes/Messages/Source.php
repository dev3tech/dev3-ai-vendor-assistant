<?php
/**
 * A buyer-message source (reviews, order notes, inquiries…). Every method is vendor-scoped.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Source contract.
 */
abstract class Source {

	/**
	 * Id (reviews, order_notes…).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Whether this source exists on the site.
	 *
	 * @return bool
	 */
	abstract public function available();

	/**
	 * Recent messages for the vendor.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $limit     Limit.
	 * @return array[] Message rows (see self::row()).
	 */
	abstract public function items( $vendor_id, $limit = 30 );

	/**
	 * One message, only when it belongs to the vendor (else null).
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $id        Message id.
	 * @return array|null
	 */
	abstract public function get( $vendor_id, $id );

	/**
	 * Sends a reply back to the source.
	 *
	 * @param int    $vendor_id Vendor.
	 * @param int    $user_id   Acting user (vendor or staff).
	 * @param int    $id        Message id.
	 * @param string $reply     Reply text.
	 * @return true|\WP_Error
	 */
	abstract public function send( $vendor_id, $user_id, $id, $reply );

	/**
	 * Standard row.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	protected function row( array $data ) {
		return array_merge(
			array(
				'source'       => $this->id(),
				'source_label' => $this->label(),
				'id'           => 0,
				'buyer'        => '',
				'subject'      => '',
				'text'         => '',
				'rating'       => 0,
				'product_id'   => 0,
				'product'      => '',
				'order_id'     => 0,
				'date'         => '',
				'replied'      => false,
				'reply'        => '',
			),
			$data
		);
	}
}
