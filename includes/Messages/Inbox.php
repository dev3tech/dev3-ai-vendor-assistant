<?php
/**
 * Buyer-message inbox + reply drafts.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Messages;

use Dev3\AiVendorAssistant\Core3\Api\Api_Error;
use Dev3\AiVendorAssistant\Core3\Api\Prompts;
use Dev3\AiVendorAssistant\Data\Drafts;
use Dev3\AiVendorAssistant\Features\Listing_Writer;
use Dev3\AiVendorAssistant\Features\Prompt_Library;
use Dev3\AiVendorAssistant\Features\Text;
use Dev3\AiVendorAssistant\Marketplace\Manager;
use Dev3\AiVendorAssistant\Settings;
use Dev3\AiVendorAssistant\Vendor\Quota;

defined( 'ABSPATH' ) || exit;

/**
 * Inbox service.
 */
class Inbox {

	/**
	 * Sources keyed by id.
	 *
	 * @return Source[]
	 */
	public static function sources() {
		$sources = array(
			'reviews'        => new Reviews(),
			'order_notes'    => new Order_Notes(),
			'wcfm_inquiries' => new Wcfm_Inquiries(),
			'dokan_support'  => new Dokan_Support(),
		);
		/**
		 * Adds buyer-message sources (instances of Messages\Source).
		 *
		 * @param Source[] $sources Sources by id.
		 */
		$sources = apply_filters( 'dev3va_message_sources', $sources );
		return array_filter(
			(array) $sources,
			static function ( $s ) {
				return $s instanceof Source && $s->available();
			}
		);
	}

	/**
	 * Messages for a vendor, newest first.
	 *
	 * @param int    $vendor_id Vendor.
	 * @param string $filter    all|unanswered.
	 * @param int    $limit     Limit.
	 * @return array { items, sources, counts }
	 */
	public static function items( $vendor_id, $filter = 'all', $limit = 60 ) {
		$items   = array();
		$sources = array();
		foreach ( self::sources() as $id => $source ) {
			$sources[] = array(
				'id'    => $id,
				'label' => $source->label(),
			);
			foreach ( $source->items( $vendor_id, $limit ) as $item ) {
				$items[] = $item;
			}
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( (string) $b['date'], (string) $a['date'] );
			}
		);
		$unanswered = count( array_filter( $items, static fn( $i ) => empty( $i['replied'] ) ) );
		if ( 'unanswered' === $filter ) {
			$items = array_values( array_filter( $items, static fn( $i ) => empty( $i['replied'] ) ) );
		}
		return array(
			'items'   => array_slice( $items, 0, $limit ),
			'sources' => $sources,
			'counts'  => array(
				'unanswered' => $unanswered,
			),
		);
	}

	/**
	 * Resolves a message the vendor owns.
	 *
	 * @param int    $vendor_id Vendor.
	 * @param string $source    Source id.
	 * @param int    $id        Id.
	 * @return array|\WP_Error
	 */
	public static function message( $vendor_id, $source, $id ) {
		$sources = self::sources();
		$item    = isset( $sources[ $source ] ) ? $sources[ $source ]->get( $vendor_id, (int) $id ) : null;
		if ( ! $item ) {
			return Api_Error::make( 'dev3va_not_found', __( 'That item was not found in your store.', 'dev3-ai-vendor-assistant' ), '', 404 );
		}
		return $item;
	}

	/**
	 * Detects the intent with keywords (basic mode).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function intent( $text ) {
		$text = mb_strtolower( (string) $text );
		$map  = array(
			'returns'   => array( 'return', 'refund', 'exchange', 'money back', 'broken', 'damaged', 'wrong item' ),
			'shipping'  => array( 'ship', 'deliver', 'tracking', 'arrive', 'courier', 'dispatch', 'parcel', 'package', 'post', 'when will', 'where is my' ),
			'sizing'    => array( 'size', 'fit', 'measure', 'length', 'width', 'small', 'large' ),
			'stock'     => array( 'stock', 'available', 'restock', 'back in', 'sold out' ),
			'complaint' => array( 'disappoint', 'terrible', 'bad', 'poor', 'worst', 'angry', 'never again' ),
			'praise'    => array( 'love', 'great', 'excellent', 'perfect', 'amazing', 'thank' ),
		);
		foreach ( $map as $intent => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== mb_strpos( $text, $needle ) ) {
					return $intent;
				}
			}
		}
		return false !== strpos( $text, '?' ) ? 'product_question' : 'other';
	}

	/**
	 * Template reply (basic mode).
	 *
	 * @param array  $item  Message.
	 * @param string $store Store name.
	 * @return array { reply, intent, sentiment, summary }
	 */
	public static function basic( array $item, $store ) {
		$intent  = self::intent( $item['text'] );
		$name    = $item['buyer'] ? $item['buyer'] : __( 'there', 'dev3-ai-vendor-assistant' );
		$product = $item['product'] ? $item['product'] : __( 'your order', 'dev3-ai-vendor-assistant' );
		/* translators: %s: buyer first name */
		$hello     = sprintf( __( 'Hi %s,', 'dev3-ai-vendor-assistant' ), $name );
		$templates = array(
			/* translators: %s: product */
			'shipping'         => __( 'Thanks for your message about %s. We are checking the shipping status now and will send you an update with tracking details as soon as possible.', 'dev3-ai-vendor-assistant' ),
			/* translators: %s: product */
			'returns'          => __( 'We are sorry to hear there is a problem with %s. Please reply with your order number and a photo if anything is damaged, and we will sort out the next steps under our return policy.', 'dev3-ai-vendor-assistant' ),
			/* translators: %s: product */
			'sizing'           => __( 'Good question about the fit of %s. We will check the exact measurements for you and reply shortly so you can pick the right size.', 'dev3-ai-vendor-assistant' ),
			/* translators: %s: product */
			'stock'            => __( 'Thanks for your interest in %s. We are checking stock now and will let you know when it is available.', 'dev3-ai-vendor-assistant' ),
			/* translators: %s: product */
			'complaint'        => __( 'We are really sorry %s did not meet your expectations. We would like to make this right; please tell us a little more and we will help straight away.', 'dev3-ai-vendor-assistant' ),
			/* translators: %s: product */
			'praise'           => __( 'Thank you so much for the kind words about %s! It means a lot to our small team. We hope to see you again soon.', 'dev3-ai-vendor-assistant' ),
			/* translators: %s: product */
			'product_question' => __( 'Thanks for your question about %s. We will check the details and get back to you shortly.', 'dev3-ai-vendor-assistant' ),
			/* translators: %s: product */
			'other'            => __( 'Thanks for getting in touch about %s. We will get back to you shortly.', 'dev3-ai-vendor-assistant' ),
		);
		/* translators: %s: store name */
		$sign     = $store ? sprintf( __( 'Best regards, %s', 'dev3-ai-vendor-assistant' ), $store ) : __( 'Best regards', 'dev3-ai-vendor-assistant' );
		$negative = in_array( $intent, array( 'complaint', 'returns' ), true ) || ( $item['rating'] && $item['rating'] <= 2 );
		$positive = 'praise' === $intent || ( $item['rating'] >= 4 );
		return array(
			'reply'     => $hello . "\n\n" . sprintf( $templates[ $intent ], $product ) . "\n\n" . $sign,
			'intent'    => $intent,
			'sentiment' => $negative ? 'negative' : ( $positive ? 'positive' : 'neutral' ),
			'summary'   => Text::line( wp_trim_words( $item['text'], 18, '…' ), 160 ),
		);
	}

	/**
	 * Product facts for the prompt.
	 *
	 * @param int $product_id Product.
	 * @return array|string
	 */
	private static function product_facts( $product_id ) {
		$product = $product_id ? wc_get_product( (int) $product_id ) : null;
		if ( ! $product ) {
			return 'None';
		}
		return array(
			'title'  => $product->get_name(),
			'price'  => Text::money( (float) $product->get_price() ),
			'stock'  => $product->get_stock_status() . ( $product->managing_stock() ? ' (' . (int) $product->get_stock_quantity() . ')' : '' ),
			'short'  => mb_substr( wp_strip_all_tags( $product->get_short_description() ), 0, 400 ),
			'weight' => $product->get_weight(),
		);
	}

	/**
	 * Order facts (vendor's items only; no addresses or emails).
	 *
	 * @param int $order_id  Order.
	 * @param int $vendor_id Vendor.
	 * @return array|string
	 */
	private static function order_facts( $order_id, $vendor_id ) {
		$order   = $order_id ? wc_get_order( (int) $order_id ) : null;
		$adapter = Manager::current();
		if ( ! $order || ! $adapter || ! $adapter->owns_order( $vendor_id, $order->get_id() ) ) {
			return 'None';
		}
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( $adapter->product_vendor( (int) $item->get_product_id() ) === (int) $vendor_id ) {
				$items[] = $item->get_name() . ' x ' . $item->get_quantity();
			}
		}
		$date = $order->get_date_created();
		return array(
			'number'   => $order->get_order_number(),
			'status'   => wc_get_order_status_name( $order->get_status() ),
			'date'     => $date ? $date->date( 'Y-m-d' ) : '',
			'items'    => $items,
			'shipping' => $order->get_shipping_method(),
		);
	}

	/**
	 * Store policies given to the AI: marketplace text + the vendor's own.
	 *
	 * @param int $vendor_id Vendor.
	 * @return string
	 */
	public static function policies( $vendor_id ) {
		$parts = array_filter(
			array(
				(string) Settings::get( 'reply_policies', '' ),
				(string) get_user_meta( (int) $vendor_id, '_dev3va_policies', true ),
			)
		);
		return $parts ? implode( "\n", $parts ) : 'No written policies: do not promise refunds, discounts or dates.';
	}

	/**
	 * Builds a reply draft.
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $input     { source, id, text, tone, instructions, mode }.
	 * @return array|\WP_Error
	 */
	public static function draft( $vendor_id, array $input ) {
		$source = sanitize_key( $input['source'] ?? '' );
		if ( 'manual' === $source ) {
			$text = Text::block( $input['text'] ?? '', 3000 );
			if ( '' === $text ) {
				return Api_Error::make( 'dev3va_empty_message', __( 'Paste the buyer message first.', 'dev3-ai-vendor-assistant' ), '', 400 );
			}
			$item = array(
				'source'     => 'manual',
				'id'         => 0,
				'buyer'      => Text::first_name( $input['buyer'] ?? '' ),
				'subject'    => '',
				'text'       => $text,
				'rating'     => 0,
				'product_id' => 0,
				'product'    => '',
				'order_id'   => 0,
			);
			if ( ! empty( $input['product_id'] ) ) {
				$adapter = Manager::current();
				if ( $adapter && $adapter->owns_product( $vendor_id, (int) $input['product_id'] ) ) {
					$item['product_id'] = (int) $input['product_id'];
					$item['product']    = get_the_title( (int) $input['product_id'] );
				}
			}
		} else {
			$item = self::message( $vendor_id, $source, (int) ( $input['id'] ?? 0 ) );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
		}
		$adapter  = Manager::current();
		$store    = $adapter ? $adapter->store_name( $vendor_id ) : '';
		$mode     = 'basic' === ( $input['mode'] ?? 'ai' ) ? 'basic' : 'ai';
		$draft    = null;
		$fallback = null;
		$meta     = array(
			'model'  => '',
			'tokens' => 0,
			'cached' => false,
		);
		if ( 'ai' === $mode ) {
			Prompt_Library::register();
			$args   = Prompts::build(
				'va_reply',
				array(
					'store'        => $store,
					'buyer'        => $item['buyer'] ? $item['buyer'] : 'unknown',
					'source'       => $item['source'] . ( $item['rating'] ? ' (' . (int) $item['rating'] . ' stars)' : '' ),
					'message'      => $item['text'],
					'product'      => self::product_facts( $item['product_id'] ),
					'order'        => self::order_facts( $item['order_id'], $vendor_id ),
					'policies'     => self::policies( $vendor_id ),
					'instructions' => Text::block( $input['instructions'] ?? '', 500 ) ? Text::block( $input['instructions'] ?? '', 500 ) : 'None',
					'tone'         => Text::tone( (string) ( $input['tone'] ?? '' ) ),
				),
				array( 'profile' => 'fast' )
			);
			$result = is_wp_error( $args ) ? $args : Quota::run( $vendor_id, 'reply', 'vendor_reply', $args, (int) $item['id'] );
			if ( is_wp_error( $result ) ) {
				if ( ! in_array( $result->get_error_code(), Listing_Writer::FALLBACK_CODES, true ) ) {
					return $result;
				}
				$fallback = array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
			} else {
				$data  = (array) ( $result['data'] ?? array() );
				$draft = array(
					'reply'     => Text::block( $data['reply'] ?? '', 3000 ),
					'intent'    => Text::line( $data['intent'] ?? 'other', 30 ),
					'sentiment' => in_array( $data['sentiment'] ?? '', array( 'positive', 'neutral', 'negative' ), true ) ? $data['sentiment'] : 'neutral',
					'summary'   => Text::line( $data['summary'] ?? '', 160 ),
				);
				$meta  = array(
					'model'  => (string) ( $result['model'] ?? '' ),
					'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
					'cached' => ! empty( $result['cached'] ),
				);
			}
		}
		if ( null === $draft || '' === $draft['reply'] ) {
			$draft = self::basic( $item, $store );
		}
		$id = Drafts::create(
			array(
				'vendor_id'   => $vendor_id,
				'feature'     => 'reply',
				'object_type' => 'message',
				'object_id'   => (int) $item['id'],
				'source'      => $item['source'],
				'input'       => array(
					'text' => mb_substr( $item['text'], 0, 500 ),
				),
				'draft'       => $draft,
				'ai'          => null === $fallback && 'ai' === $mode && $meta['model'],
				'model'       => $meta['model'],
				'tokens'      => $meta['tokens'],
			)
		);
		return array(
			'draft_id' => $id,
			'message'  => $item,
			'draft'    => $draft,
			'ai'       => (bool) $meta['model'],
			'fallback' => $fallback,
			'meta'     => $meta,
			'can_send' => 'manual' !== $item['source'],
		);
	}

	/**
	 * Sends a reply.
	 *
	 * @param int   $vendor_id Vendor.
	 * @param int   $user_id   Acting user.
	 * @param array $input     { source, id, reply, draft_id }.
	 * @return array|\WP_Error
	 */
	public static function send( $vendor_id, $user_id, array $input ) {
		$source  = sanitize_key( $input['source'] ?? '' );
		$reply   = Text::block( $input['reply'] ?? '', 3000 );
		$sources = self::sources();
		if ( '' === $reply ) {
			return Api_Error::make( 'dev3va_empty_reply', __( 'Write a reply first.', 'dev3-ai-vendor-assistant' ), '', 400 );
		}
		if ( ! isset( $sources[ $source ] ) ) {
			return Api_Error::make( 'dev3va_not_found', __( 'That item was not found in your store.', 'dev3-ai-vendor-assistant' ), '', 404 );
		}
		$sent = $sources[ $source ]->send( $vendor_id, $user_id, (int) ( $input['id'] ?? 0 ), $reply );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		if ( ! empty( $input['draft_id'] ) ) {
			$row = Drafts::get( (int) $input['draft_id'], $vendor_id );
			if ( $row && 'reply' === $row['feature'] ) {
				Drafts::set_status( $row['id'], 'applied' );
			}
		}
		/**
		 * After a vendor sent a buyer reply through the assistant.
		 *
		 * @param string $source    Source id.
		 * @param int    $id        Message id.
		 * @param int    $vendor_id Vendor.
		 */
		do_action( 'dev3va_reply_sent', $source, (int) ( $input['id'] ?? 0 ), (int) $vendor_id );
		return self::message( $vendor_id, $source, (int) ( $input['id'] ?? 0 ) );
	}
}
