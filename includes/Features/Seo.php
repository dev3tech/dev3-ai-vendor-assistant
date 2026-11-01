<?php
/**
 * Product SEO title/description: stored by DEV3, written into Yoast / Rank Math when active,
 * printed on the product page otherwise.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Features;

defined( 'ABSPATH' ) || exit;

/**
 * SEO helpers.
 */
class Seo {

	/**
	 * Hooks the front-end output.
	 */
	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 3 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'title' ), 15 );
	}

	/**
	 * Whether an SEO plugin owns the head.
	 *
	 * @return bool
	 */
	public static function seo_plugin() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' );
	}

	/**
	 * Saves product SEO fields.
	 *
	 * @param int    $product_id  Product.
	 * @param string $title       Title.
	 * @param string $description Description.
	 */
	public static function save_product( $product_id, $title, $description ) {
		update_post_meta( $product_id, Moderation::META_SEO_T, $title );
		update_post_meta( $product_id, Moderation::META_SEO_D, $description );
		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $product_id, '_yoast_wpseo_title', $title );
			update_post_meta( $product_id, '_yoast_wpseo_metadesc', $description );
		}
		if ( class_exists( 'RankMath' ) ) {
			update_post_meta( $product_id, 'rank_math_title', $title );
			update_post_meta( $product_id, 'rank_math_description', $description );
		}
	}

	/**
	 * Meta description on single products when no SEO plugin is active.
	 */
	public static function head() {
		if ( self::seo_plugin() || ! is_singular( 'product' ) ) {
			return;
		}
		$desc = (string) get_post_meta( get_queried_object_id(), Moderation::META_SEO_D, true );
		if ( '' !== $desc ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $desc ) );
		}
	}

	/**
	 * Document title on single products when no SEO plugin is active.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function title( $title ) {
		if ( self::seo_plugin() || ! is_singular( 'product' ) ) {
			return $title;
		}
		$seo = (string) get_post_meta( get_queried_object_id(), Moderation::META_SEO_T, true );
		return '' !== $seo ? $seo : $title;
	}
}
