<?php
/**
 * Moderation of AI-written listings: blocked terms, review queue, approve/reject, AI check.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Features;

use Dev3\AiVendorAssistant\Core3\Api\Ai;
use Dev3\AiVendorAssistant\Core3\Api\Api_Error;
use Dev3\AiVendorAssistant\Core3\Api\Prompts;
use Dev3\AiVendorAssistant\Marketplace\Manager;
use Dev3\AiVendorAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Moderation service.
 */
class Moderation {

	const META       = '_dev3va_moderation';
	const META_NOTE  = '_dev3va_moderation_note';
	const META_FLAGS = '_dev3va_flags';
	const META_AI    = '_dev3va_ai_written';
	const META_CHECK = '_dev3va_ai_check';
	const META_DRAFT = '_dev3va_draft_id';
	const META_SEO_T = '_dev3va_seo_title';
	const META_SEO_D = '_dev3va_seo_description';

	/**
	 * Blocked terms found in a listing.
	 *
	 * @param array $fields Listing fields (strings or string arrays).
	 * @return string[] Terms found.
	 */
	public static function scan( array $fields ) {
		$terms = Settings::blocked_terms();
		if ( ! $terms ) {
			return array();
		}
		$haystack = '';
		foreach ( $fields as $value ) {
			$haystack .= ' ' . ( is_array( $value ) ? implode( ' ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : (string) $value );
		}
		$haystack = mb_strtolower( wp_strip_all_tags( $haystack ) );
		$found    = array();
		foreach ( $terms as $term ) {
			if ( '' !== $term && false !== mb_strpos( $haystack, $term ) ) {
				$found[] = $term;
			}
		}
		return $found;
	}

	/**
	 * Moderation state for a newly applied listing.
	 *
	 * @param bool  $ai_written Whether AI wrote it.
	 * @param array $flags      Blocked terms found.
	 * @return string pending|flagged|none
	 */
	public static function state_for( $ai_written, array $flags ) {
		$mode = (string) Settings::get( 'moderation_mode', 'review' );
		if ( $ai_written && 'review' === $mode ) {
			return 'pending';
		}
		if ( $flags || ( $ai_written && 'flag' === $mode ) ) {
			return 'flagged';
		}
		return 'none';
	}

	/**
	 * Queue (pending + flagged by default).
	 *
	 * @param array $args { status, vendor_id, page, per_page, search }.
	 * @return array { rows, total, counts }
	 */
	public static function queue( array $args = array() ) {
		$args     = wp_parse_args(
			$args,
			array(
				'status'    => 'open',
				'vendor_id' => 0,
				'page'      => 1,
				'per_page'  => 20,
				'search'    => '',
			)
		);
		$statuses = 'open' === $args['status'] ? array( 'pending', 'flagged' ) : array( sanitize_key( $args['status'] ) );
		$query    = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
			'posts_per_page' => min( 100, max( 1, (int) $args['per_page'] ) ),
			'paged'          => max( 1, (int) $args['page'] ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META,
					'value'   => $statuses,
					'compare' => 'IN',
				),
			),
		);
		if ( $args['vendor_id'] ) {
			$query['author'] = (int) $args['vendor_id'];
		}
		if ( '' !== $args['search'] ) {
			$query['s'] = (string) $args['search'];
		}
		$q    = new \WP_Query( $query );
		$rows = array_map( array( __CLASS__, 'shape' ), $q->posts );
		return array(
			'rows'   => $rows,
			'total'  => (int) $q->found_posts,
			'counts' => self::counts(),
		);
	}

	/**
	 * Counts per state.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_value state, COUNT(*) n FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'product' AND p.post_status <> 'trash' GROUP BY pm.meta_value", self::META ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$out  = array(
			'pending'  => 0,
			'flagged'  => 0,
			'approved' => 0,
			'rejected' => 0,
		);
		foreach ( (array) $rows as $r ) {
			if ( isset( $out[ $r['state'] ] ) ) {
				$out[ $r['state'] ] = (int) $r['n'];
			}
		}
		return $out;
	}

	/**
	 * Admin row for a product.
	 *
	 * @param \WP_Post|int $post Post.
	 * @return array
	 */
	public static function shape( $post ) {
		$post    = get_post( $post );
		$product = wc_get_product( $post->ID );
		$adapter = Manager::current();
		$vendor  = (int) $post->post_author;
		$image   = $product ? (int) $product->get_image_id() : 0;
		$check   = get_post_meta( $post->ID, self::META_CHECK, true );
		return array(
			'id'          => (int) $post->ID,
			'title'       => get_the_title( $post ),
			'status'      => (string) $post->post_status,
			'moderation'  => (string) get_post_meta( $post->ID, self::META, true ),
			'note'        => (string) get_post_meta( $post->ID, self::META_NOTE, true ),
			'flags'       => array_values( (array) get_post_meta( $post->ID, self::META_FLAGS, true ) ),
			'ai_written'  => (bool) get_post_meta( $post->ID, self::META_AI, true ),
			'vendor_id'   => $vendor,
			'vendor'      => $adapter ? $adapter->store_name( $vendor ) : '',
			'price'       => $product ? (string) $product->get_regular_price() : '',
			'price_html'  => $product ? Text::money( (float) $product->get_regular_price() ) : '',
			'short'       => $product ? wp_strip_all_tags( $product->get_short_description() ) : '',
			'description' => $product ? Text::html( $product->get_description() ) : '',
			'tags'        => $product ? wp_list_pluck( get_the_terms( $post->ID, 'product_tag' ) ? get_the_terms( $post->ID, 'product_tag' ) : array(), 'name' ) : array(),
			'image'       => $image ? (string) wp_get_attachment_image_url( $image, 'medium' ) : '',
			'check'       => is_array( $check ) ? $check : null,
			'modified'    => mysql_to_rfc3339( $post->post_modified_gmt ),
			'edit_url'    => get_edit_post_link( $post->ID, 'raw' ),
			'view_url'    => get_permalink( $post->ID ),
		);
	}

	/**
	 * Approves (publishes) or rejects (back to draft + reason) a listing.
	 *
	 * @param int    $product_id Product.
	 * @param string $action     approve|reject.
	 * @param string $note       Reason (reject).
	 * @return array|\WP_Error
	 */
	public static function decide( $product_id, $action, $note = '' ) {
		$post = get_post( (int) $product_id );
		if ( ! $post || 'product' !== $post->post_type || '' === (string) get_post_meta( $post->ID, self::META, true ) ) {
			return Api_Error::make( 'dev3va_not_found', __( 'Listing not found in the moderation queue.', 'dev3-ai-vendor-assistant' ), '', 404 );
		}
		$note = Text::block( $note, 1000 );
		if ( 'approve' === $action ) {
			if ( 'publish' !== $post->post_status ) {
				wp_update_post(
					array(
						'ID'          => $post->ID,
						'post_status' => 'publish',
					)
				);
			}
			update_post_meta( $post->ID, self::META, 'approved' );
		} elseif ( 'reject' === $action ) {
			if ( '' === $note ) {
				return Api_Error::make( 'dev3va_note_required', __( 'Give the vendor a reason for the rejection.', 'dev3-ai-vendor-assistant' ), '', 400 );
			}
			wp_update_post(
				array(
					'ID'          => $post->ID,
					'post_status' => 'draft',
				)
			);
			update_post_meta( $post->ID, self::META, 'rejected' );
		} else {
			return Api_Error::make( 'dev3va_bad_action', __( 'Unknown moderation action.', 'dev3-ai-vendor-assistant' ), '', 400 );
		}
		update_post_meta( $post->ID, self::META_NOTE, $note );
		self::notify( $post->ID, $action, $note );
		/**
		 * After an admin approved or rejected an AI-written listing.
		 *
		 * @param int    $product_id Product.
		 * @param string $action     approve|reject.
		 * @param string $note       Note.
		 */
		do_action( 'dev3va_listing_moderated', (int) $post->ID, $action, $note );
		return self::shape( $post->ID );
	}

	/**
	 * Emails the vendor about the decision.
	 *
	 * @param int    $product_id Product.
	 * @param string $action     Action.
	 * @param string $note       Note.
	 */
	private static function notify( $product_id, $action, $note ) {
		if ( ! Settings::get( 'notify_vendor', true ) ) {
			return;
		}
		$post   = get_post( $product_id );
		$vendor = get_userdata( (int) $post->post_author );
		if ( ! $vendor || ! is_email( $vendor->user_email ) ) {
			return;
		}
		$title = get_the_title( $post );
		if ( 'approve' === $action ) {
			/* translators: %s: product title */
			$subject = sprintf( __( 'Your listing "%s" is live', 'dev3-ai-vendor-assistant' ), $title );
			/* translators: %s: product title */
			$body = sprintf( __( 'Good news: the marketplace approved your listing "%s". It is now published.', 'dev3-ai-vendor-assistant' ), $title );
		} else {
			/* translators: %s: product title */
			$subject = sprintf( __( 'Your listing "%s" needs changes', 'dev3-ai-vendor-assistant' ), $title );
			/* translators: 1: product title, 2: reason */
			$body = sprintf( __( "The marketplace returned your listing \"%1\$s\" to draft.\n\nReason: %2\$s\n\nEdit the listing in your vendor dashboard and submit it again.", 'dev3-ai-vendor-assistant' ), $title, $note );
		}
		$adapter = Manager::current();
		if ( $adapter ) {
			$body .= "\n\n" . $adapter->assistant_url( 'history' );
		}
		wp_mail( $vendor->user_email, $subject, $body );
	}

	/**
	 * DEV3 AI policy check on a listing (admin).
	 *
	 * @param int $product_id Product.
	 * @return array|\WP_Error
	 */
	public static function ai_check( $product_id ) {
		$row = get_post( (int) $product_id );
		if ( ! $row || 'product' !== $row->post_type ) {
			return Api_Error::make( 'dev3va_not_found', __( 'Listing not found.', 'dev3-ai-vendor-assistant' ), '', 404 );
		}
		$item = self::shape( $row );
		Prompt_Library::register();
		$args = Prompts::build(
			'va_moderation',
			array(
				'policy'      => (string) Settings::get( 'moderation_policy', '' ) ? (string) Settings::get( 'moderation_policy', '' ) : 'No extra policy.',
				'title'       => $item['title'],
				'short'       => mb_substr( $item['short'], 0, 600 ),
				'description' => mb_substr( wp_strip_all_tags( $item['description'] ), 0, 3000 ),
				'tags'        => implode( ', ', $item['tags'] ),
				'price'       => $item['price_html'],
			),
			array( 'profile' => 'fast' )
		);
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		$result = Ai::run( 'admin_listing_check', $args, 'admin' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data  = (array) ( $result['data'] ?? array() );
		$check = array(
			'verdict' => in_array( $data['verdict'] ?? '', array( 'approve', 'review', 'reject' ), true ) ? $data['verdict'] : 'review',
			'summary' => Text::line( $data['summary'] ?? '', 300 ),
			'issues'  => Text::lines( $data['issues'] ?? array(), 8, 200 ),
			'model'   => (string) ( $result['model'] ?? '' ),
			'at'      => gmdate( 'c' ),
		);
		update_post_meta( $row->ID, self::META_CHECK, $check );
		return $check;
	}
}
