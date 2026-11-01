<?php
/**
 * Store bio + SEO writer.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Features;

use Dev3\AiVendorAssistant\Core3\Api\Api_Error;
use Dev3\AiVendorAssistant\Core3\Api\Prompts;
use Dev3\AiVendorAssistant\Data\Drafts;
use Dev3\AiVendorAssistant\Marketplace\Manager;
use Dev3\AiVendorAssistant\Vendor\Access;
use Dev3\AiVendorAssistant\Vendor\Quota;

defined( 'ABSPATH' ) || exit;

/**
 * Profile service.
 */
class Profile {

	const FIELDS = array( 'bio', 'tagline', 'seo_title', 'seo_description', 'seo_keywords' );

	/**
	 * Current profile + facts.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array
	 */
	public static function get( $vendor_id ) {
		$adapter = Manager::current();
		$profile = $adapter ? $adapter->get_profile( $vendor_id ) : array();
		$profile = wp_parse_args(
			$profile,
			array_fill_keys( array_merge( array( 'store_name' ), self::FIELDS ), '' )
		);
		$facts   = self::facts( $vendor_id );
		return array(
			'profile'    => array(
				'bio'             => Text::html( wpautop( $profile['bio'] ), 3000 ),
				'tagline'         => (string) $profile['tagline'],
				'seo_title'       => (string) $profile['seo_title'],
				'seo_description' => (string) $profile['seo_description'],
				'seo_keywords'    => (string) $profile['seo_keywords'],
			),
			'store_name' => $adapter ? $adapter->store_name( $vendor_id ) : '',
			'store_url'  => $adapter ? $adapter->store_url( $vendor_id ) : '',
			'facts'      => $facts,
			'policies'   => (string) get_user_meta( (int) $vendor_id, '_dev3va_policies', true ),
		);
	}

	/**
	 * Categories + top products of the vendor.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array { categories[], products[] }
	 */
	public static function facts( $vendor_id ) {
		$adapter = Manager::current();
		$ids     = $adapter ? $adapter->product_ids(
			$vendor_id,
			array(
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'orderby'        => 'meta_value_num',
				'meta_key'       => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'          => 'DESC',
			)
		) : array();
		$cats    = array();
		$titles  = array();
		foreach ( $ids as $id ) {
			if ( count( $titles ) < 8 ) {
				$titles[] = get_the_title( $id );
			}
			foreach ( wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) ) as $name ) {
				$cats[ $name ] = ( $cats[ $name ] ?? 0 ) + 1;
			}
		}
		arsort( $cats );
		return array(
			'categories' => array_map(
				static function ( $n ) {
					return html_entity_decode( $n, ENT_QUOTES, 'UTF-8' );
				},
				array_slice( array_keys( $cats ), 0, 6 )
			),
			'products'   => $titles,
			'count'      => count( $ids ),
		);
	}

	/**
	 * Draft (AI or basic).
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $input     { notes, tone, mode }.
	 * @return array|\WP_Error
	 */
	public static function draft( $vendor_id, array $input ) {
		$current  = self::get( $vendor_id );
		$notes    = Text::block( $input['notes'] ?? '', 1500 );
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
				'va_profile',
				array(
					'store'      => $current['store_name'],
					'categories' => $current['facts']['categories'] ? implode( ', ', $current['facts']['categories'] ) : 'Not set yet',
					'products'   => $current['facts']['products'] ? implode( '; ', $current['facts']['products'] ) : 'No products yet',
					'bio'        => wp_strip_all_tags( $current['profile']['bio'] ) ? mb_substr( wp_strip_all_tags( $current['profile']['bio'] ), 0, 1200 ) : 'None',
					'notes'      => '' !== $notes ? $notes : 'None',
					'language'   => Text::language(),
					'tone'       => Text::tone( (string) ( $input['tone'] ?? '' ) ),
				),
				array( 'profile' => 'fast' )
			);
			$result = is_wp_error( $args ) ? $args : Quota::run( $vendor_id, 'profile', 'vendor_profile', $args );
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
					'bio'             => Text::html( wpautop( (string) ( $data['bio'] ?? '' ) ), 3000 ),
					'tagline'         => Text::line( $data['tagline'] ?? '', 90 ),
					'seo_title'       => Text::line( $data['seo_title'] ?? '', 65 ),
					'seo_description' => Text::line( $data['seo_description'] ?? '', 165 ),
					'seo_keywords'    => implode( ', ', Text::lines( $data['keywords'] ?? array(), 8, 40 ) ),
				);
				$meta  = array(
					'model'  => (string) ( $result['model'] ?? '' ),
					'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
					'cached' => ! empty( $result['cached'] ),
				);
			}
		}
		if ( null === $draft ) {
			$draft = self::basic( $current, $notes );
		}
		$id = Drafts::create(
			array(
				'vendor_id'   => $vendor_id,
				'feature'     => 'profile',
				'object_type' => 'store',
				'object_id'   => $vendor_id,
				'draft'       => $draft,
				'ai'          => (bool) $meta['model'],
				'model'       => $meta['model'],
				'tokens'      => $meta['tokens'],
			)
		);
		return array(
			'draft_id' => $id,
			'draft'    => $draft,
			'original' => $current['profile'],
			'ai'       => (bool) $meta['model'],
			'fallback' => $fallback,
			'meta'     => $meta,
		);
	}

	/**
	 * Template profile.
	 *
	 * @param array  $current Current data.
	 * @param string $notes   Notes.
	 * @return array
	 */
	public static function basic( array $current, $notes ) {
		$store = $current['store_name'] ? $current['store_name'] : __( 'Our store', 'dev3-ai-vendor-assistant' );
		$cats  = $current['facts']['categories'];
		$what  = $cats ? implode( ', ', array_slice( $cats, 0, 3 ) ) : __( 'quality products', 'dev3-ai-vendor-assistant' );
		/* translators: 1: store name, 2: categories */
		$p1 = sprintf( __( 'Welcome to %1$s. We offer %2$s, picked and packed with care.', 'dev3-ai-vendor-assistant' ), $store, mb_strtolower( $what ) );
		$p2 = '' !== $notes ? $notes : __( 'Have a question about an item? Send us a message and we will be happy to help.', 'dev3-ai-vendor-assistant' );
		/* translators: 1: store name, 2: categories */
		$desc = sprintf( __( 'Shop %2$s from %1$s. Friendly service and carefully packed orders.', 'dev3-ai-vendor-assistant' ), $store, mb_strtolower( $what ) );
		return array(
			'bio'             => '<p>' . esc_html( $p1 ) . '</p><p>' . esc_html( $p2 ) . '</p>',
			/* translators: %s: categories */
			'tagline'         => Text::line( sprintf( __( '%s, chosen with care', 'dev3-ai-vendor-assistant' ), $what ), 90 ),
			'seo_title'       => Text::line( $store . ( $cats ? ' | ' . $cats[0] : '' ), 65 ),
			'seo_description' => Text::line( $desc, 160 ),
			'seo_keywords'    => implode( ', ', array_slice( array_merge( array_map( 'mb_strtolower', $cats ), array( mb_strtolower( $store ) ) ), 0, 8 ) ),
		);
	}

	/**
	 * Saves fields through the adapter (and the vendor's own reply policy).
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $fields    Fields.
	 * @param int   $draft_id  Draft (optional).
	 * @return array|\WP_Error
	 */
	public static function apply( $vendor_id, array $fields, $draft_id = 0 ) {
		$adapter = Manager::current();
		if ( ! $adapter ) {
			return Api_Error::make( 'dev3va_no_marketplace', __( 'No marketplace plugin is active.', 'dev3-ai-vendor-assistant' ), '', 400 );
		}
		$row = null;
		if ( $draft_id ) {
			$row = Drafts::get( (int) $draft_id, $vendor_id );
			if ( ! $row || 'profile' !== $row['feature'] ) {
				return Access::not_found();
			}
		}
		$clean = array();
		$limit = array(
			'tagline'         => 90,
			'seo_title'       => 70,
			'seo_description' => 170,
			'seo_keywords'    => 300,
		);
		foreach ( self::FIELDS as $key ) {
			if ( ! array_key_exists( $key, $fields ) ) {
				continue;
			}
			$clean[ $key ] = 'bio' === $key ? Text::html( (string) $fields[ $key ], 3000 ) : Text::line( $fields[ $key ], $limit[ $key ] );
		}
		if ( array_key_exists( 'policies', $fields ) ) {
			update_user_meta( (int) $vendor_id, '_dev3va_policies', Text::block( $fields['policies'], 2000 ) );
		}
		if ( $clean ) {
			$adapter->save_profile( $vendor_id, $clean );
		}
		if ( $row ) {
			Drafts::set_status( $row['id'], 'applied' );
		}
		return self::get( $vendor_id );
	}
}
