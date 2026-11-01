<?php
/**
 * Listing writer: photos + notes to a listing draft, then a vendor-owned product.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Features;

use Dev3\AiVendorAssistant\Core3\Api\Api_Error;
use Dev3\AiVendorAssistant\Core3\Api\Prompts;
use Dev3\AiVendorAssistant\Data\Drafts;
use Dev3\AiVendorAssistant\Marketplace\Manager;
use Dev3\AiVendorAssistant\Settings;
use Dev3\AiVendorAssistant\Vendor\Access;
use Dev3\AiVendorAssistant\Vendor\Quota;

defined( 'ABSPATH' ) || exit;

/**
 * Listing service.
 */
class Listing_Writer {

	/**
	 * Errors that switch an AI request to the basic writer instead of failing.
	 */
	const FALLBACK_CODES = array( 'dev3va_ai_disabled', 'dev3va_no_api_key', 'dev3va_cap_reached', 'dev3va_quota_reached' );

	/**
	 * Current values of a product (the "original" side of the diff).
	 *
	 * @param \WC_Product|null $product Product.
	 * @return array
	 */
	public static function current( $product ) {
		if ( ! $product ) {
			return array(
				'title'             => '',
				'short_description' => '',
				'description'       => '',
				'tags'              => '',
				'seo_title'         => '',
				'seo_description'   => '',
			);
		}
		$tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
		return array(
			'title'             => $product->get_name(),
			'short_description' => wp_strip_all_tags( $product->get_short_description() ),
			'description'       => Text::html( $product->get_description() ),
			'tags'              => is_array( $tags ) ? implode( ', ', $tags ) : '',
			'seo_title'         => (string) get_post_meta( $product->get_id(), Moderation::META_SEO_T, true ),
			'seo_description'   => (string) get_post_meta( $product->get_id(), Moderation::META_SEO_D, true ),
		);
	}

	/**
	 * Category choices { id, name } (names only go to the AI).
	 *
	 * @return array
	 */
	public static function categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 200,
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( 'uncategorized' === $term->slug ) {
				continue;
			}
			$out[] = array(
				'id'   => (int) $term->term_id,
				'name' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			);
		}
		return $out;
	}

	/**
	 * Builds a draft.
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $input     { photos[], notes, product_id, category, tone, mode }.
	 * @return array|\WP_Error
	 */
	public static function draft( $vendor_id, array $input ) {
		$notes    = Text::block( $input['notes'] ?? '', 3000 );
		$photos   = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['photos'] ?? array() ) ) ) ) );
		$max      = (int) Settings::get( 'max_photos', 6 );
		$product  = null;
		$category = absint( $input['category'] ?? 0 );
		if ( count( $photos ) > $max ) {
			/* translators: %d: max photos */
			return Api_Error::make( 'dev3va_too_many_photos', sprintf( __( 'Use up to %d photos.', 'dev3-ai-vendor-assistant' ), $max ), '', 400 );
		}
		foreach ( $photos as $photo ) {
			$owned = Access::photo( $vendor_id, $photo );
			if ( is_wp_error( $owned ) ) {
				return $owned;
			}
		}
		if ( ! empty( $input['product_id'] ) ) {
			$product = Access::product( $vendor_id, (int) $input['product_id'] );
			if ( is_wp_error( $product ) ) {
				return $product;
			}
		}
		if ( '' === $notes && ! $photos && ! $product ) {
			return Api_Error::make( 'dev3va_empty_listing', __( 'Add a photo or a few notes about the product first.', 'dev3-ai-vendor-assistant' ), '', 400 );
		}
		$original = self::current( $product );
		$mode     = 'basic' === ( $input['mode'] ?? 'ai' ) ? 'basic' : 'ai';
		$fallback = null;
		$meta     = array(
			'model'  => '',
			'tokens' => 0,
			'cached' => false,
		);
		$draft    = null;
		if ( 'ai' === $mode ) {
			$result = self::ai( $vendor_id, $notes, $photos, $product, $category, Text::tone( (string) ( $input['tone'] ?? '' ) ) );
			if ( is_wp_error( $result ) ) {
				if ( ! in_array( $result->get_error_code(), self::FALLBACK_CODES, true ) ) {
					return $result;
				}
				$fallback = array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
			} else {
				$draft = self::normalize( (array) ( $result['data'] ?? array() ), count( $photos ) );
				$meta  = array(
					'model'  => (string) ( $result['model'] ?? '' ),
					'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
					'cached' => ! empty( $result['cached'] ),
				);
			}
		}
		if ( null === $draft ) {
			$draft = self::basic( $notes, $product, count( $photos ) );
		}
		$draft['category_ids'] = self::match_categories( $draft['categories'], $category );
		$flags                 = Moderation::scan( $draft );
		$id                    = Drafts::create(
			array(
				'vendor_id'   => $vendor_id,
				'feature'     => 'listing',
				'object_type' => 'product',
				'object_id'   => $product ? $product->get_id() : 0,
				'source'      => $product ? 'update' : 'new',
				'input'       => array(
					'photos'   => $photos,
					'notes'    => $notes,
					'category' => $category,
				),
				'draft'       => $draft,
				'ai'          => null === $fallback && 'ai' === $mode,
				'model'       => $meta['model'],
				'tokens'      => $meta['tokens'],
			)
		);
		return array(
			'draft_id'   => $id,
			'draft'      => $draft,
			'original'   => $original,
			'product_id' => $product ? $product->get_id() : 0,
			'flags'      => $flags,
			'ai'         => null === $fallback && 'ai' === $mode,
			'fallback'   => $fallback,
			'meta'       => $meta,
			'moderation' => Moderation::state_for( null === $fallback && 'ai' === $mode, $flags ),
		);
	}

	/**
	 * AI call (vision).
	 *
	 * @param int              $vendor_id Vendor.
	 * @param string           $notes     Notes.
	 * @param int[]            $photos    Attachment ids.
	 * @param \WC_Product|null $product   Product.
	 * @param int              $category  Category hint.
	 * @param string           $tone      Tone.
	 * @return array|\WP_Error
	 */
	private static function ai( $vendor_id, $notes, array $photos, $product, $category, $tone ) {
		Prompt_Library::register();
		$adapter  = Manager::current();
		$cat_name = '';
		if ( $category ) {
			$term     = get_term( $category, 'product_cat' );
			$cat_name = $term && ! is_wp_error( $term ) ? $term->name : '';
		}
		$existing = $product ? array(
			'title'       => $product->get_name(),
			'short'       => mb_substr( wp_strip_all_tags( $product->get_short_description() ), 0, 400 ),
			'description' => mb_substr( wp_strip_all_tags( $product->get_description() ), 0, 1500 ),
			'price'       => $product->get_regular_price(),
		) : '';
		$images   = Photos::payload( $photos );
		$args     = Prompts::build(
			'va_listing',
			array(
				'store'       => $adapter ? $adapter->store_name( $vendor_id ) : '',
				'existing'    => $existing,
				'notes'       => '' !== $notes ? $notes : 'None, use the photos.',
				'category'    => $cat_name,
				'categories'  => implode( ', ', array_slice( wp_list_pluck( self::categories(), 'name' ), 0, 80 ) ),
				'photo_count' => count( $images ),
				'language'    => Text::language(),
				'tone'        => $tone,
			),
			array(
				'images'  => $images,
				'timeout' => 90,
				'cache'   => HOUR_IN_SECONDS,
			)
		);
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		return Quota::run( $vendor_id, 'listing', 'vendor_listing', $args, $product ? $product->get_id() : 0 );
	}

	/**
	 * Cleans an AI listing.
	 *
	 * @param array $data        Raw data.
	 * @param int   $photo_count Photos.
	 * @return array
	 */
	public static function normalize( array $data, $photo_count = 0 ) {
		$attributes = array();
		foreach ( (array) ( $data['attributes'] ?? array() ) as $attr ) {
			$name  = Text::line( is_array( $attr ) ? ( $attr['name'] ?? '' ) : '', 40 );
			$value = Text::line( is_array( $attr ) ? ( $attr['value'] ?? '' ) : '', 120 );
			if ( '' !== $name && '' !== $value ) {
				$attributes[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
			if ( count( $attributes ) >= 10 ) {
				break;
			}
		}
		return array(
			'title'             => Text::line( $data['title'] ?? '', 120 ),
			'short_description' => Text::block( $data['short_description'] ?? '', 400 ),
			'description'       => Text::html( $data['description'] ?? '', 4000 ),
			'tags'              => implode( ', ', Text::lines( $data['tags'] ?? array(), 10, 40 ) ),
			'categories'        => Text::lines( $data['categories'] ?? array(), 3, 80 ),
			'attributes'        => $attributes,
			'seo_title'         => Text::line( $data['seo_title'] ?? '', 70 ),
			'seo_description'   => Text::line( $data['seo_description'] ?? '', 170 ),
			'alt_texts'         => array_slice( Text::lines( $data['alt_texts'] ?? array(), 10, 140 ), 0, max( 0, (int) $photo_count ) ),
		);
	}

	/**
	 * Non-AI listing from the notes.
	 *
	 * @param string           $notes       Notes.
	 * @param \WC_Product|null $product     Product.
	 * @param int              $photo_count Photos.
	 * @return array
	 */
	public static function basic( $notes, $product = null, $photo_count = 0 ) {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $notes ) ) ) );
		$title = $lines ? $lines[0] : ( $product ? $product->get_name() : '' );
		$title = Text::line( preg_replace( '/[.!?]+$/', '', $title ), 120 );
		$title = '' !== $title ? mb_strtoupper( mb_substr( $title, 0, 1 ) ) . mb_substr( $title, 1 ) : '';
		$rest  = array_slice( $lines, 1 );
		$facts = array();
		$paras = array();
		foreach ( $rest as $line ) {
			if ( preg_match( '/^[-*\x{2022}]\s*(.+)$/u', $line, $m ) || preg_match( '/^([^:]{2,30}):\s*(.+)$/u', $line ) ) {
				$facts[] = isset( $m[1] ) ? $m[1] : $line;
			} else {
				$paras[] = $line;
			}
			unset( $m );
		}
		$attributes = array();
		foreach ( $facts as $fact ) {
			if ( preg_match( '/^([^:]{2,30}):\s*(.+)$/u', $fact, $m ) ) {
				$attributes[] = array(
					'name'  => Text::line( $m[1], 40 ),
					'value' => Text::line( $m[2], 120 ),
				);
			}
		}
		$short = $paras ? $paras[0] : ( $title ? $title . '.' : '' );
		$html  = '';
		foreach ( $paras as $p ) {
			$html .= '<p>' . esc_html( $p ) . '</p>';
		}
		if ( $facts ) {
			$html .= '<ul>';
			foreach ( $facts as $fact ) {
				$html .= '<li>' . esc_html( $fact ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( '' === $html && $product ) {
			$html = Text::html( $product->get_description() );
		}
		$tags = array_slice( Text::words( $title . ' ' . implode( ' ', $facts ) ), 0, 6 );
		$site = get_bloginfo( 'name' );
		return array(
			'title'             => $title,
			'short_description' => Text::block( $short, 400 ),
			'description'       => Text::html( $html, 4000 ),
			'tags'              => implode( ', ', $tags ),
			'categories'        => array(),
			'attributes'        => array_slice( $attributes, 0, 10 ),
			'seo_title'         => Text::line( $title . ( $site ? ' | ' . $site : '' ), 70 ),
			'seo_description'   => Text::line( mb_substr( wp_strip_all_tags( $short ), 0, 155 ), 170 ),
			'alt_texts'         => array_fill( 0, max( 0, (int) $photo_count ), Text::line( $title, 140 ) ),
		);
	}

	/**
	 * Maps suggested category names to existing term ids (plus the vendor's hint).
	 *
	 * @param string[] $names    Names.
	 * @param int      $category Hint id.
	 * @return int[]
	 */
	public static function match_categories( array $names, $category = 0 ) {
		$ids = array();
		if ( $category && term_exists( (int) $category, 'product_cat' ) ) {
			$ids[] = (int) $category;
		}
		$all = self::categories();
		foreach ( $names as $name ) {
			$needle = mb_strtolower( trim( (string) $name ) );
			foreach ( $all as $cat ) {
				if ( mb_strtolower( $cat['name'] ) === $needle ) {
					$ids[] = $cat['id'];
				}
			}
		}
		return array_values( array_unique( array_slice( $ids, 0, 3 ) ) );
	}

	/**
	 * Applies a draft: creates or updates the vendor's product.
	 *
	 * @param int   $vendor_id Vendor.
	 * @param int   $draft_id  Draft.
	 * @param array $fields    Edited fields (override the draft).
	 * @return array|\WP_Error
	 */
	public static function apply( $vendor_id, $draft_id, array $fields ) {
		$row = Drafts::get( $draft_id, $vendor_id );
		if ( ! $row || 'listing' !== $row['feature'] ) {
			return Access::not_found();
		}
		if ( 'pending' !== $row['status'] ) {
			return Api_Error::make( 'dev3va_draft_used', __( 'This draft was already used or discarded.', 'dev3-ai-vendor-assistant' ), '', 409 );
		}
		$draft = array_merge( $row['draft'], array_intersect_key( $fields, array_flip( array( 'title', 'short_description', 'description', 'tags', 'seo_title', 'seo_description', 'category_ids', 'attributes', 'price', 'alt_texts' ) ) ) );
		$title = Text::line( $draft['title'] ?? '', 120 );
		if ( '' === $title ) {
			return Api_Error::make( 'dev3va_title_required', __( 'The listing needs a title.', 'dev3-ai-vendor-assistant' ), '', 400 );
		}
		$flags = Moderation::scan( $draft );
		if ( $flags && 'block' === Settings::get( 'blocked_action', 'flag' ) ) {
			/* translators: %s: blocked words */
			return Api_Error::make( 'dev3va_blocked_terms', sprintf( __( 'Remove these words before saving: %s', 'dev3-ai-vendor-assistant' ), implode( ', ', $flags ) ), __( 'The marketplace does not allow them in listings.', 'dev3-ai-vendor-assistant' ), 422 );
		}
		$adapter = Manager::current();
		$product = null;
		if ( $row['object_id'] ) {
			$product = Access::product( $vendor_id, $row['object_id'] );
			if ( is_wp_error( $product ) ) {
				return $product;
			}
		}
		$photos = array();
		foreach ( (array) ( $row['input']['photos'] ?? array() ) as $photo ) {
			if ( ! is_wp_error( Access::photo( $vendor_id, (int) $photo ) ) ) {
				$photos[] = (int) $photo;
			}
		}
		$moderation = Moderation::state_for( (bool) $row['ai'], $flags );
		$is_new     = ! $product;
		if ( $is_new ) {
			$product = new \WC_Product_Simple();
		}
		$product->set_name( $title );
		$product->set_short_description( wp_kses_post( wpautop( Text::block( $draft['short_description'] ?? '', 400 ) ) ) );
		$product->set_description( Text::html( $draft['description'] ?? '', 6000 ) );
		$cats = array_values( array_filter( array_map( 'absint', (array) ( $draft['category_ids'] ?? array() ) ) ) );
		if ( $cats ) {
			$product->set_category_ids( $cats );
		}
		if ( isset( $draft['price'] ) && is_numeric( $draft['price'] ) && (float) $draft['price'] > 0 ) {
			$product->set_regular_price( wc_format_decimal( (float) $draft['price'] ) );
		}
		$attrs = array();
		foreach ( (array) ( $draft['attributes'] ?? array() ) as $i => $attr ) {
			$name  = Text::line( is_array( $attr ) ? ( $attr['name'] ?? '' ) : '', 40 );
			$value = Text::line( is_array( $attr ) ? ( $attr['value'] ?? '' ) : '', 120 );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$a = new \WC_Product_Attribute();
			$a->set_id( 0 );
			$a->set_name( $name );
			$a->set_options( array( $value ) );
			$a->set_position( (int) $i );
			$a->set_visible( true );
			$a->set_variation( false );
			$attrs[ sanitize_title( $name ) ] = $a;
		}
		if ( $attrs ) {
			$product->set_attributes( $is_new ? $attrs : array_merge( $product->get_attributes(), $attrs ) );
		}
		if ( $photos ) {
			$product->set_image_id( $photos[0] );
			$product->set_gallery_image_ids( array_slice( $photos, 1 ) );
		}
		$status = $adapter ? $adapter->new_product_status( $vendor_id ) : 'pending';
		if ( 'pending' === $moderation ) {
			$status = 'pending';
		} elseif ( ! $is_new ) {
			$status = $product->get_status();
		}
		$product->set_status( $status );
		$id = $product->save();
		if ( ! $id ) {
			return Api_Error::make( 'dev3va_save_failed', __( 'The listing could not be saved.', 'dev3-ai-vendor-assistant' ), '', 500 );
		}
		if ( $is_new ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_author' => (int) $vendor_id,
				)
			);
		}
		$tags = Text::lines( preg_split( '/\s*,\s*/', (string) ( $draft['tags'] ?? '' ) ), 12, 40 );
		wp_set_object_terms( $id, $tags, 'product_tag', false );
		$alts = (array) ( $draft['alt_texts'] ?? array() );
		foreach ( $photos as $i => $photo ) {
			wp_update_post(
				array(
					'ID'          => $photo,
					'post_parent' => $id,
				)
			);
			if ( Settings::get( 'apply_alt_text', true ) && ! empty( $alts[ $i ] ) ) {
				update_post_meta( $photo, '_wp_attachment_image_alt', Text::line( $alts[ $i ], 140 ) );
			}
		}
		Seo::save_product( $id, Text::line( $draft['seo_title'] ?? '', 70 ), Text::line( $draft['seo_description'] ?? '', 170 ) );
		if ( $row['ai'] ) {
			update_post_meta( $id, Moderation::META_AI, 1 );
		}
		if ( 'none' !== $moderation ) {
			update_post_meta( $id, Moderation::META, $moderation );
			update_post_meta( $id, Moderation::META_FLAGS, $flags );
			delete_post_meta( $id, Moderation::META_NOTE );
		}
		update_post_meta( $id, Moderation::META_DRAFT, (int) $row['id'] );
		Drafts::set_status( $row['id'], 'applied', $id );
		/**
		 * After a vendor applied a listing draft.
		 *
		 * @param int    $product_id Product.
		 * @param int    $vendor_id  Vendor.
		 * @param array  $draft      Final fields.
		 * @param string $moderation pending|flagged|none.
		 */
		do_action( 'dev3va_listing_applied', (int) $id, (int) $vendor_id, $draft, $moderation );
		return array(
			'product_id' => (int) $id,
			'created'    => $is_new,
			'status'     => get_post_status( $id ),
			'moderation' => $moderation,
			'flags'      => $flags,
			'edit_url'   => $adapter ? $adapter->product_edit_url( $id ) : '',
			'view_url'   => get_permalink( $id ),
		);
	}
}
