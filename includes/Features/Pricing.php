<?php
/**
 * Price suggestions from comparable marketplace listings (public fields only).
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
 * Pricing service.
 */
class Pricing {

	const GOALS = array( 'competitive', 'premium', 'quick_sale' );

	/**
	 * Comparable published listings. Only public fields are returned (never sales or costs).
	 *
	 * @param string $title        Title to match.
	 * @param int[]  $category_ids Categories.
	 * @param int    $exclude      Product to leave out.
	 * @param int    $vendor_id    Requesting vendor (marks own listings).
	 * @param int    $limit        Max results.
	 * @return array[]
	 */
	public static function comparables( $title, array $category_ids, $exclude = 0, $vendor_id = 0, $limit = 12 ) {
		$words = Text::words( $title );
		$args  = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'post__not_in'   => $exclude ? array( (int) $exclude ) : array(),
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'exclude-from-search', 'exclude-from-catalog' ),
					'operator' => 'NOT IN',
				),
			),
		);
		if ( $category_ids ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => array_map( 'intval', $category_ids ),
			);
		} elseif ( $words ) {
			$args['s'] = implode( ' ', array_slice( $words, 0, 3 ) );
		}
		$ids = ( new \WP_Query( $args ) )->posts;
		if ( ! $ids && ! empty( $args['s'] ) && count( $words ) > 1 ) {
			$args['s'] = $words[0];
			$ids       = ( new \WP_Query( $args ) )->posts;
		}
		$adapter = Manager::current();
		$rows    = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || $product->is_type( 'variation' ) ) {
				continue;
			}
			$price = (float) $product->get_price();
			if ( $price <= 0 ) {
				continue;
			}
			$owner  = $adapter ? $adapter->product_vendor( $id ) : 0;
			$score  = Text::similarity( $words, Text::words( $product->get_name() ) );
			$rows[] = array(
				'id'      => (int) $id,
				'title'   => $product->get_name(),
				'price'   => round( $price, 2 ),
				'rating'  => round( (float) $product->get_average_rating(), 1 ),
				'reviews' => (int) $product->get_review_count(),
				'store'   => $adapter && $owner ? $adapter->store_name( $owner ) : '',
				'url'     => get_permalink( $id ),
				'own'     => $vendor_id && $owner === (int) $vendor_id,
				'score'   => round( $score, 3 ),
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				$cmp = $b['score'] <=> $a['score'];
				return 0 !== $cmp ? $cmp : $a['price'] <=> $b['price'];
			}
		);
		return array_slice( $rows, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Price statistics.
	 *
	 * @param float[] $prices Prices.
	 * @return array { count, min, p25, median, p75, max, average }
	 */
	public static function stats( array $prices ) {
		$prices = array_values( array_filter( array_map( 'floatval', $prices ), static fn( $p ) => $p > 0 ) );
		sort( $prices );
		$n = count( $prices );
		if ( ! $n ) {
			return array(
				'count'   => 0,
				'min'     => 0,
				'p25'     => 0,
				'median'  => 0,
				'p75'     => 0,
				'max'     => 0,
				'average' => 0,
			);
		}
		return array(
			'count'   => $n,
			'min'     => round( $prices[0], 2 ),
			'p25'     => round( self::percentile( $prices, 25 ), 2 ),
			'median'  => round( self::percentile( $prices, 50 ), 2 ),
			'p75'     => round( self::percentile( $prices, 75 ), 2 ),
			'max'     => round( $prices[ $n - 1 ], 2 ),
			'average' => round( array_sum( $prices ) / $n, 2 ),
		);
	}

	/**
	 * Linear-interpolated percentile of a sorted list.
	 *
	 * @param float[] $sorted Sorted values.
	 * @param float   $p      Percentile 0-100.
	 * @return float
	 */
	public static function percentile( array $sorted, $p ) {
		$n = count( $sorted );
		if ( 1 === $n ) {
			return (float) $sorted[0];
		}
		$rank = ( $p / 100 ) * ( $n - 1 );
		$lo   = (int) floor( $rank );
		$hi   = (int) ceil( $rank );
		return (float) $sorted[ $lo ] + ( $rank - $lo ) * ( (float) $sorted[ $hi ] - (float) $sorted[ $lo ] );
	}

	/**
	 * Non-AI suggestion from the stats and goal.
	 *
	 * @param array  $stats Stats.
	 * @param string $goal  Goal.
	 * @return array|null { price, low, high, rationale, confidence }
	 */
	public static function basic( array $stats, $goal ) {
		if ( empty( $stats['count'] ) ) {
			return null;
		}
		$price = 'premium' === $goal ? $stats['p75'] : ( 'quick_sale' === $goal ? $stats['p25'] : $stats['median'] );
		/* translators: 1: number of listings, 2: low price, 3: high price */
		$why = sprintf( __( 'Based on %1$d similar listings; most sell between %2$s and %3$s.', 'dev3-ai-vendor-assistant' ), $stats['count'], Text::money( $stats['p25'] ), Text::money( $stats['p75'] ) );
		return array(
			'price'      => self::round_price( $price ),
			'low'        => self::round_price( $stats['p25'] ),
			'high'       => self::round_price( $stats['p75'] ),
			'rationale'  => $why,
			'confidence' => $stats['count'] >= 8 ? 'high' : ( $stats['count'] >= 3 ? 'medium' : 'low' ),
		);
	}

	/**
	 * Rounds to the store's decimals.
	 *
	 * @param float $price Price.
	 * @return float
	 */
	public static function round_price( $price ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return round( (float) $price, $decimals );
	}

	/**
	 * Builds a suggestion.
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $input     { product_id | title + category, goal, notes, mode }.
	 * @return array|\WP_Error
	 */
	public static function suggest( $vendor_id, array $input ) {
		$product = null;
		if ( ! empty( $input['product_id'] ) ) {
			$product = Access::product( $vendor_id, (int) $input['product_id'] );
			if ( is_wp_error( $product ) ) {
				return $product;
			}
		}
		$title = $product ? $product->get_name() : Text::line( $input['title'] ?? '', 120 );
		if ( '' === $title ) {
			return Api_Error::make( 'dev3va_price_needs_product', __( 'Pick one of your products or type a product title.', 'dev3-ai-vendor-assistant' ), '', 400 );
		}
		$cats  = $product ? $product->get_category_ids() : array_filter( array( absint( $input['category'] ?? 0 ) ) );
		$goal  = in_array( $input['goal'] ?? '', self::GOALS, true ) ? $input['goal'] : 'competitive';
		$notes = Text::block( $input['notes'] ?? '', 600 );
		$comps = self::comparables( $title, $cats, $product ? $product->get_id() : 0, $vendor_id, (int) Settings::get( 'comparables', 12 ) );
		if ( count( $comps ) < 3 && $cats ) {
			// Too few in the category: widen to a title search.
			$more  = self::comparables( $title, array(), $product ? $product->get_id() : 0, $vendor_id, (int) Settings::get( 'comparables', 12 ) );
			$comps = array_values( array_column( array_merge( $comps, $more ), null, 'id' ) );
		}
		$stats    = self::stats( wp_list_pluck( $comps, 'price' ) );
		$basic    = self::basic( $stats, $goal );
		$mode     = 'basic' === ( $input['mode'] ?? 'ai' ) ? 'basic' : 'ai';
		$ai       = null;
		$fallback = null;
		$meta     = array(
			'model'  => '',
			'tokens' => 0,
			'cached' => false,
		);
		if ( 'ai' === $mode ) {
			$result = self::ai( $vendor_id, $product, $title, $goal, $notes, $comps, $stats );
			if ( is_wp_error( $result ) ) {
				if ( ! in_array( $result->get_error_code(), Listing_Writer::FALLBACK_CODES, true ) ) {
					return $result;
				}
				$fallback = array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
			} else {
				$ai   = self::clamp( (array) ( $result['data'] ?? array() ), $stats );
				$meta = array(
					'model'  => (string) ( $result['model'] ?? '' ),
					'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
					'cached' => ! empty( $result['cached'] ),
				);
			}
		}
		$suggestion = $ai ? $ai : $basic;
		$draft_id   = Drafts::create(
			array(
				'vendor_id'   => $vendor_id,
				'feature'     => 'price',
				'object_type' => 'product',
				'object_id'   => $product ? $product->get_id() : 0,
				'source'      => $goal,
				'input'       => array(
					'title' => $title,
					'goal'  => $goal,
				),
				'draft'       => $suggestion ? $suggestion : array(),
				'ai'          => (bool) $ai,
				'model'       => $meta['model'],
				'tokens'      => $meta['tokens'],
			)
		);
		return array(
			'draft_id'    => $draft_id,
			'product'     => $product ? array(
				'id'    => $product->get_id(),
				'title' => $product->get_name(),
				'price' => (float) $product->get_regular_price(),
			) : array(
				'id'    => 0,
				'title' => $title,
				'price' => 0,
			),
			'goal'        => $goal,
			'comparables' => array_map(
				static function ( $c ) {
					unset( $c['score'] );
					return $c;
				},
				$comps
			),
			'stats'       => $stats,
			'basic'       => $basic,
			'suggestion'  => $suggestion,
			'ai'          => (bool) $ai,
			'fallback'    => $fallback,
			'meta'        => $meta,
			'currency'    => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '$',
		);
	}

	/**
	 * AI call.
	 *
	 * @param int              $vendor_id Vendor.
	 * @param \WC_Product|null $product   Product.
	 * @param string           $title     Title.
	 * @param string           $goal      Goal.
	 * @param string           $notes     Notes.
	 * @param array            $comps     Comparables.
	 * @param array            $stats     Stats.
	 * @return array|\WP_Error
	 */
	private static function ai( $vendor_id, $product, $title, $goal, $notes, array $comps, array $stats ) {
		Prompt_Library::register();
		$lines = array();
		foreach ( array_slice( $comps, 0, 20 ) as $c ) {
			$lines[] = sprintf( '%s | %s | %s stars | %d reviews', $c['title'], $c['price'], $c['rating'], $c['reviews'] );
		}
		$args = Prompts::build(
			'va_price',
			array(
				'product'     => array(
					'title'         => $title,
					'short'         => $product ? mb_substr( wp_strip_all_tags( $product->get_short_description() ), 0, 400 ) : '',
					'current_price' => $product ? $product->get_regular_price() : '',
					'categories'    => $product ? wp_strip_all_tags( wc_get_product_category_list( $product->get_id() ) ) : '',
				),
				'goal'        => str_replace( '_', ' ', $goal ),
				'notes'       => '' !== $notes ? $notes : 'None',
				'comparables' => $lines ? implode( "\n", $lines ) : 'No comparable listings found.',
				'stats'       => $stats,
				'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			),
			array( 'profile' => 'fast' )
		);
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		return Quota::run( $vendor_id, 'price', 'vendor_price', $args, $product ? $product->get_id() : 0 );
	}

	/**
	 * Keeps an AI price inside a sane band around the comparables.
	 *
	 * @param array $data  AI data.
	 * @param array $stats Stats.
	 * @return array
	 */
	public static function clamp( array $data, array $stats ) {
		$price = (float) ( $data['price'] ?? 0 );
		$low   = (float) ( $data['low'] ?? $price );
		$high  = (float) ( $data['high'] ?? $price );
		if ( ! empty( $stats['count'] ) ) {
			$floor = $stats['min'] * 0.5;
			$ceil  = $stats['max'] * 1.5;
			$price = min( $ceil, max( $floor, $price ) );
			$low   = min( $ceil, max( $floor, $low ) );
			$high  = min( $ceil, max( $floor, $high ) );
		}
		$price = max( 0.01, $price );
		if ( $low > $price ) {
			$low = $price;
		}
		if ( $high < $price ) {
			$high = $price;
		}
		return array(
			'price'      => self::round_price( $price ),
			'low'        => self::round_price( $low ),
			'high'       => self::round_price( $high ),
			'rationale'  => Text::block( $data['rationale'] ?? '', 600 ),
			'confidence' => in_array( $data['confidence'] ?? '', array( 'low', 'medium', 'high' ), true ) ? $data['confidence'] : 'medium',
		);
	}

	/**
	 * Sets the regular price of the vendor's product.
	 *
	 * @param int   $vendor_id  Vendor.
	 * @param int   $product_id Product.
	 * @param float $price      Price.
	 * @param int   $draft_id   Draft (optional).
	 * @return array|\WP_Error
	 */
	public static function apply( $vendor_id, $product_id, $price, $draft_id = 0 ) {
		$product = Access::product( $vendor_id, $product_id );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		$price = (float) $price;
		if ( $price <= 0 || $price > 10000000 ) {
			return Api_Error::make( 'dev3va_bad_price', __( 'Enter a price greater than zero.', 'dev3-ai-vendor-assistant' ), '', 400 );
		}
		if ( $product->is_type( 'variable' ) ) {
			return Api_Error::make( 'dev3va_variable_price', __( 'Variable products are priced per variation in the product editor.', 'dev3-ai-vendor-assistant' ), '', 400 );
		}
		$old = (float) $product->get_regular_price();
		$product->set_regular_price( wc_format_decimal( self::round_price( $price ) ) );
		$sale = (float) $product->get_sale_price();
		if ( $sale > 0 && $sale >= $price ) {
			$product->set_sale_price( '' );
		}
		$product->save();
		if ( $draft_id ) {
			$row = Drafts::get( $draft_id, $vendor_id );
			if ( $row && 'price' === $row['feature'] ) {
				Drafts::set_status( $row['id'], 'applied', $product->get_id() );
			}
		}
		return array(
			'product_id' => $product->get_id(),
			'old_price'  => $old,
			'price'      => (float) $product->get_regular_price(),
		);
	}
}
