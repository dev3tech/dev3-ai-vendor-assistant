<?php
/**
 * Performance report (own data only) + rule-based tips + DEV3 AI summary.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Features;

use Dev3\AiVendorAssistant\Core3\Api\Prompts;
use Dev3\AiVendorAssistant\Data\Drafts;
use Dev3\AiVendorAssistant\Marketplace\Manager;
use Dev3\AiVendorAssistant\Messages\Reviews;
use Dev3\AiVendorAssistant\Settings;
use Dev3\AiVendorAssistant\Vendor\Quota;

defined( 'ABSPATH' ) || exit;

/**
 * Insights service.
 */
class Insights {

	const DAYS = 30;

	/**
	 * Order statuses that count as sales.
	 *
	 * @return string[]
	 */
	private static function paid_statuses() {
		/**
		 * Order statuses counted as sales in vendor insights.
		 *
		 * @param string[] $statuses Statuses (without wc- prefix).
		 */
		return (array) apply_filters( 'dev3va_insights_paid_statuses', array( 'processing', 'completed', 'on-hold' ) );
	}

	/**
	 * Computes the report.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $now       Unix time (tests).
	 * @return array
	 */
	public static function report( $vendor_id, $now = 0 ) {
		$now     = $now ? (int) $now : time();
		$adapter = Manager::current();
		$start   = $now - self::DAYS * DAY_IN_SECONDS;
		$prev    = $now - 2 * self::DAYS * DAY_IN_SECONDS;
		$empty   = array(
			'orders'  => 0,
			'revenue' => 0.0,
			'items'   => 0,
		);
		$periods = array(
			'cur'    => $empty,
			'before' => $empty,
		);
		$sold    = array();
		$paid    = self::paid_statuses();
		$orders  = $adapter ? $adapter->order_ids( $vendor_id, $prev, 500 ) : array();
		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || ! in_array( $order->get_status(), $paid, true ) || ! $order->get_date_created() ) {
				continue;
			}
			$time = $order->get_date_created()->getTimestamp();
			if ( $time < $prev || $time > $now ) {
				continue;
			}
			$bucket = $time >= $start ? 'cur' : 'before';
			$total  = 0.0;
			$qty    = 0;
			foreach ( $order->get_items() as $item ) {
				$pid = (int) $item->get_product_id();
				if ( $adapter->product_vendor( $pid ) !== (int) $vendor_id ) {
					continue;
				}
				$total += (float) $item->get_total();
				$qty   += (int) $item->get_quantity();
				if ( 'cur' === $bucket ) {
					if ( ! isset( $sold[ $pid ] ) ) {
						$sold[ $pid ] = array(
							'id'      => $pid,
							'title'   => $item->get_name(),
							'qty'     => 0,
							'revenue' => 0.0,
						);
					}
					$sold[ $pid ]['qty']     += (int) $item->get_quantity();
					$sold[ $pid ]['revenue'] += (float) $item->get_total();
				}
			}
			if ( $qty ) {
				++$periods[ $bucket ]['orders'];
				$periods[ $bucket ]['revenue'] += $total;
				$periods[ $bucket ]['items']   += $qty;
			}
		}
		usort( $sold, static fn( $a, $b ) => $b['qty'] <=> $a['qty'] );
		$cur     = $periods['cur'];
		$before  = $periods['before'];
		$catalog = self::catalog( $vendor_id, $sold, $now );
		$reviews = new Reviews();
		$open    = count( array_filter( $reviews->items( $vendor_id, 50 ), static fn( $r ) => empty( $r['replied'] ) ) );
		$stats   = array(
			'days'               => self::DAYS,
			'orders'             => $cur['orders'],
			'orders_prev'        => $before['orders'],
			'revenue'            => round( $cur['revenue'], 2 ),
			'revenue_prev'       => round( $before['revenue'], 2 ),
			'aov'                => $cur['orders'] ? round( $cur['revenue'] / $cur['orders'], 2 ) : 0,
			'items_sold'         => $cur['items'],
			'revenue_change'     => $before['revenue'] > 0 ? round( ( $cur['revenue'] - $before['revenue'] ) / $before['revenue'] * 100, 1 ) : null,
			'top_products'       => array_map(
				static function ( $p ) {
					$p['revenue'] = round( $p['revenue'], 2 );
					return $p;
				},
				array_slice( array_values( $sold ), 0, 5 )
			),
			'products'           => $catalog['published'],
			'no_sales'           => $catalog['no_sales'],
			'low_stock'          => $catalog['low_stock'],
			'quality'            => $catalog['quality'],
			'rating'             => $catalog['rating'],
			'reviews'            => $catalog['reviews'],
			'unanswered_reviews' => $open,
			'currency'           => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '$',
		);
		return array(
			'stats' => $stats,
			'tips'  => self::tips( $stats ),
		);
	}

	/**
	 * Catalogue checks.
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $sold      Sold products (current period) keyed by id.
	 * @param int   $now       Now.
	 * @return array
	 */
	private static function catalog( $vendor_id, array $sold, $now ) {
		$adapter   = Manager::current();
		$ids       = $adapter ? $adapter->product_ids( $vendor_id, array( 'post_status' => 'publish' ) ) : array();
		$threshold = (int) Settings::get( 'low_stock', 3 );
		$sold_ids  = wp_list_pluck( $sold, 'id' );
		$no_sales  = array();
		$low       = array();
		$quality   = array();
		$rating    = 0.0;
		$reviews   = 0;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$title  = $product->get_name();
			$issues = array();
			if ( ! $product->get_image_id() ) {
				$issues[] = 'no_image';
			}
			if ( mb_strlen( wp_strip_all_tags( $product->get_description() . ' ' . $product->get_short_description() ) ) < 120 ) {
				$issues[] = 'thin_description';
			}
			if ( '' === (string) $product->get_price() && ! $product->is_type( 'variable' ) && ! $product->is_type( 'grouped' ) ) {
				$issues[] = 'no_price';
			}
			$cats = $product->get_category_ids();
			$unc  = (int) get_option( 'default_product_cat', 0 );
			if ( ! $cats || ( 1 === count( $cats ) && (int) $cats[0] === $unc ) ) {
				$issues[] = 'no_category';
			}
			if ( $issues ) {
				$quality[] = array(
					'id'     => (int) $id,
					'title'  => $title,
					'issues' => $issues,
				);
			}
			if ( $product->managing_stock() && (int) $product->get_stock_quantity() <= $threshold ) {
				$low[] = array(
					'id'    => (int) $id,
					'title' => $title,
					'stock' => (int) $product->get_stock_quantity(),
				);
			}
			$created = $product->get_date_created();
			if ( ! in_array( (int) $id, $sold_ids, true ) && $created && $created->getTimestamp() < $now - 14 * DAY_IN_SECONDS ) {
				$no_sales[] = array(
					'id'    => (int) $id,
					'title' => $title,
				);
			}
			$count = (int) $product->get_review_count();
			if ( $count ) {
				$rating  += (float) $product->get_average_rating() * $count;
				$reviews += $count;
			}
		}
		return array(
			'published' => count( $ids ),
			'no_sales'  => array_slice( $no_sales, 0, 10 ),
			'low_stock' => array_slice( $low, 0, 10 ),
			'quality'   => array_slice( $quality, 0, 20 ),
			'rating'    => $reviews ? round( $rating / $reviews, 2 ) : 0,
			'reviews'   => $reviews,
		);
	}

	/**
	 * Rule-based tips.
	 *
	 * @param array $s Stats.
	 * @return array[] { title, detail, priority, action }
	 */
	public static function tips( array $s ) {
		$tips = array();
		if ( 0 === (int) $s['products'] ) {
			$tips[] = array(
				'title'    => __( 'Add your first listing', 'dev3-ai-vendor-assistant' ),
				'detail'   => __( 'Upload a few photos and notes, and the listing writer drafts the title, description and tags for you.', 'dev3-ai-vendor-assistant' ),
				'priority' => 'high',
				'action'   => 'listing',
			);
			return $tips;
		}
		$quality = array();
		foreach ( $s['quality'] as $q ) {
			foreach ( $q['issues'] as $issue ) {
				$quality[ $issue ][] = $q['title'];
			}
		}
		if ( ! empty( $quality['no_image'] ) ) {
			$tips[] = array(
				/* translators: %d: number of products */
				'title'    => sprintf( _n( '%d listing has no photo', '%d listings have no photo', count( $quality['no_image'] ), 'dev3-ai-vendor-assistant' ), count( $quality['no_image'] ) ),
				/* translators: %s: product titles */
				'detail'   => sprintf( __( 'Listings with photos sell far better. Start with: %s.', 'dev3-ai-vendor-assistant' ), implode( ', ', array_slice( $quality['no_image'], 0, 3 ) ) ),
				'priority' => 'high',
				'action'   => 'listing',
			);
		}
		if ( ! empty( $quality['thin_description'] ) ) {
			$tips[] = array(
				/* translators: %d: number of products */
				'title'    => sprintf( _n( '%d listing needs a better description', '%d listings need better descriptions', count( $quality['thin_description'] ), 'dev3-ai-vendor-assistant' ), count( $quality['thin_description'] ) ),
				/* translators: %s: product titles */
				'detail'   => sprintf( __( 'Short descriptions answer fewer buyer questions. Improve: %s.', 'dev3-ai-vendor-assistant' ), implode( ', ', array_slice( $quality['thin_description'], 0, 3 ) ) ),
				'priority' => 'medium',
				'action'   => 'listing',
			);
		}
		if ( ! empty( $quality['no_price'] ) ) {
			$tips[] = array(
				'title'    => __( 'Some listings have no price', 'dev3-ai-vendor-assistant' ),
				/* translators: %s: product titles */
				'detail'   => sprintf( __( 'Buyers cannot purchase them. Get a suggestion for: %s.', 'dev3-ai-vendor-assistant' ), implode( ', ', array_slice( $quality['no_price'], 0, 3 ) ) ),
				'priority' => 'high',
				'action'   => 'pricing',
			);
		}
		if ( ! empty( $s['low_stock'] ) ) {
			$tips[] = array(
				'title'    => __( 'Restock your best items', 'dev3-ai-vendor-assistant' ),
				/* translators: %s: product titles */
				'detail'   => sprintf( __( 'Low stock: %s.', 'dev3-ai-vendor-assistant' ), implode( ', ', array_map( static fn( $p ) => $p['title'] . ' (' . $p['stock'] . ')', array_slice( $s['low_stock'], 0, 3 ) ) ) ),
				'priority' => 'medium',
				'action'   => 'none',
			);
		}
		if ( ! empty( $s['unanswered_reviews'] ) ) {
			$tips[] = array(
				/* translators: %d: number of reviews */
				'title'    => sprintf( _n( 'Reply to %d review', 'Reply to %d reviews', (int) $s['unanswered_reviews'], 'dev3-ai-vendor-assistant' ), (int) $s['unanswered_reviews'] ),
				'detail'   => __( 'Answering reviews, especially critical ones, builds trust with new buyers.', 'dev3-ai-vendor-assistant' ),
				'priority' => 'medium',
				'action'   => 'messages',
			);
		}
		if ( count( $s['no_sales'] ) >= 3 ) {
			$tips[] = array(
				/* translators: %d: number of products */
				'title'    => sprintf( __( '%d listings had no sales this month', 'dev3-ai-vendor-assistant' ), count( $s['no_sales'] ) ),
				/* translators: %s: product titles */
				'detail'   => sprintf( __( 'Check their price against similar listings: %s.', 'dev3-ai-vendor-assistant' ), implode( ', ', array_slice( wp_list_pluck( $s['no_sales'], 'title' ), 0, 3 ) ) ),
				'priority' => 'medium',
				'action'   => 'pricing',
			);
		}
		if ( null !== $s['revenue_change'] && $s['revenue_change'] < -15 ) {
			$tips[] = array(
				/* translators: %s: percent */
				'title'    => sprintf( __( 'Revenue is down %s%%', 'dev3-ai-vendor-assistant' ), number_format_i18n( abs( $s['revenue_change'] ), 0 ) ),
				'detail'   => __( 'Refresh your top listings and store bio, and compare prices with similar listings.', 'dev3-ai-vendor-assistant' ),
				'priority' => 'high',
				'action'   => 'profile',
			);
		}
		if ( ! $tips ) {
			$tips[] = array(
				'title'    => __( 'Your store looks healthy', 'dev3-ai-vendor-assistant' ),
				'detail'   => __( 'Keep listings fresh and reply to buyers quickly. Check back next week.', 'dev3-ai-vendor-assistant' ),
				'priority' => 'low',
				'action'   => 'none',
			);
		}
		return $tips;
	}

	/**
	 * DEV3 AI summary (cached per day and stats hash).
	 *
	 * @param int $vendor_id Vendor.
	 * @return array|\WP_Error
	 */
	public static function ai_summary( $vendor_id ) {
		$report = self::report( $vendor_id );
		$stats  = $report['stats'];
		Prompt_Library::register();
		$adapter = Manager::current();
		$compact = $stats;
		unset( $compact['currency'] );
		$compact['quality'] = array_map(
			static function ( $q ) {
				return $q['title'] . ': ' . implode( ', ', $q['issues'] );
			},
			array_slice( $stats['quality'], 0, 10 )
		);
		$args               = Prompts::build(
			'va_insights',
			array(
				'store'  => $adapter ? $adapter->store_name( $vendor_id ) : '',
				'days'   => self::DAYS,
				'stats'  => $compact,
				'issues' => wp_list_pluck( $report['tips'], 'title' ),
			),
			array( 'profile' => 'fast' )
		);
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		$result = Quota::run( $vendor_id, 'insights', 'vendor_insights', $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = (array) ( $result['data'] ?? array() );
		$tips = array();
		foreach ( (array) ( $data['tips'] ?? array() ) as $tip ) {
			if ( ! is_array( $tip ) ) {
				continue;
			}
			$tips[] = array(
				'title'    => Text::line( $tip['title'] ?? '', 90 ),
				'detail'   => Text::block( $tip['detail'] ?? '', 400 ),
				'priority' => in_array( $tip['priority'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $tip['priority'] : 'medium',
				'action'   => in_array( $tip['action'] ?? '', array( 'listing', 'pricing', 'messages', 'profile', 'none' ), true ) ? $tip['action'] : 'none',
			);
		}
		$summary = array(
			'headline' => Text::line( $data['headline'] ?? '', 160 ),
			'tips'     => array_slice( $tips, 0, 6 ),
		);
		Drafts::create(
			array(
				'vendor_id'   => $vendor_id,
				'feature'     => 'insights',
				'object_type' => 'store',
				'object_id'   => $vendor_id,
				'draft'       => $summary,
				'ai'          => true,
				'model'       => (string) ( $result['model'] ?? '' ),
				'tokens'      => (int) ( $result['usage']['total'] ?? 0 ),
			)
		);
		return array(
			'summary' => $summary,
			'meta'    => array(
				'model'  => (string) ( $result['model'] ?? '' ),
				'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
				'cached' => ! empty( $result['cached'] ),
			),
			'report'  => $report,
		);
	}
}
