<?php
/**
 * Notes buyers left at checkout on orders with the vendor's items; replies are customer notes.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Messages;

use Dev3\AiVendorAssistant\Core3\Api\Api_Error;
use Dev3\AiVendorAssistant\Features\Text;
use Dev3\AiVendorAssistant\Marketplace\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Order-notes source (HPOS-safe: only the WooCommerce order API is used).
 */
class Order_Notes extends Source {

	const META_REPLY = '_dev3va_reply';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'order_notes';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Order note', 'dev3-ai-vendor-assistant' );
	}

	/**
	 * Available.
	 *
	 * @return bool
	 */
	public function available() {
		return function_exists( 'wc_get_order' );
	}

	/**
	 * Shapes an order.
	 *
	 * @param \WC_Order $order     Order.
	 * @param int       $vendor_id Vendor.
	 * @return array
	 */
	private function shape( $order, $vendor_id ) {
		$adapter = Manager::current();
		$names   = array();
		$first   = 0;
		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( $adapter && $adapter->product_vendor( $pid ) === (int) $vendor_id ) {
				$names[] = $item->get_name();
				$first   = $first ? $first : $pid;
			}
		}
		$reply = (string) $order->get_meta( self::META_REPLY );
		$date  = $order->get_date_created();
		return $this->row(
			array(
				'id'         => (int) $order->get_id(),
				'buyer'      => Text::first_name( $order->get_billing_first_name() ),
				/* translators: %s: order number */
				'subject'    => sprintf( __( 'Order #%s', 'dev3-ai-vendor-assistant' ), $order->get_order_number() ),
				'text'       => Text::block( $order->get_customer_note(), 3000 ),
				'product_id' => $first,
				'product'    => implode( ', ', array_slice( $names, 0, 3 ) ),
				'order_id'   => (int) $order->get_id(),
				'date'       => $date ? $date->date( 'c' ) : '',
				'replied'    => '' !== $reply,
				'reply'      => $reply,
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
		$adapter = Manager::current();
		if ( ! $adapter ) {
			return array();
		}
		$out = array();
		foreach ( $adapter->order_ids( $vendor_id, time() - 120 * DAY_IN_SECONDS, 200 ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || '' === trim( (string) $order->get_customer_note() ) ) {
				continue;
			}
			$out[] = $this->shape( $order, $vendor_id );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * One order note the vendor may see.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $id        Order id.
	 * @return array|null
	 */
	public function get( $vendor_id, $id ) {
		$adapter = Manager::current();
		$order   = wc_get_order( (int) $id );
		if ( ! $adapter || ! $order || ! $adapter->owns_order( $vendor_id, $order->get_id() ) || '' === trim( (string) $order->get_customer_note() ) ) {
			return null;
		}
		return $this->shape( $order, $vendor_id );
	}

	/**
	 * Adds a customer note (WooCommerce emails it to the buyer).
	 *
	 * @param int    $vendor_id Vendor.
	 * @param int    $user_id   User.
	 * @param int    $id        Order.
	 * @param string $reply     Reply.
	 * @return true|\WP_Error
	 */
	public function send( $vendor_id, $user_id, $id, $reply ) {
		if ( ! $this->get( $vendor_id, $id ) ) {
			return Api_Error::make( 'dev3va_not_found', __( 'That item was not found in your store.', 'dev3-ai-vendor-assistant' ), '', 404 );
		}
		$order = wc_get_order( (int) $id );
		$note  = $order->add_order_note( $reply, 1, true );
		if ( ! $note ) {
			return Api_Error::make( 'dev3va_send_failed', __( 'The reply could not be added to the order.', 'dev3-ai-vendor-assistant' ), '', 500 );
		}
		$order->update_meta_data( self::META_REPLY, $reply );
		$order->update_meta_data( '_dev3va_reply_by', (int) $user_id );
		$order->save();
		return true;
	}
}
