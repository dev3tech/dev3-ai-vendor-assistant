<?php
/**
 * WCFM Frontend Manager + WCFM Marketplace (free); WCFM Ultimate staff through documented meta.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Marketplace;

use Dev3\AiVendorAssistant\Settings;
use Dev3\AiVendorAssistant\Vendor\Access;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WCFM's own order table.

/**
 * WCFM adapter.
 */
class Wcfm extends Adapter {

	const ENDPOINT_KEY = 'wcfm-ai-assistant';
	const ENDPOINT     = 'ai-assistant';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'wcfm';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return class_exists( 'WCFMu' ) ? 'WCFM Ultimate' : 'WCFM Marketplace';
	}

	/**
	 * Active: WCFM Marketplace is the vendor layer; WCFM is the dashboard.
	 *
	 * @return bool
	 */
	public function active() {
		return class_exists( 'WCFMmp' ) || function_exists( 'wcfmmp_get_store_url' );
	}

	/**
	 * Vendor roles.
	 *
	 * @return string[]
	 */
	public function vendor_roles() {
		return array( 'wcfm_vendor' );
	}

	/**
	 * Vendor check.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public function is_vendor( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return false;
		}
		$is = in_array( 'wcfm_vendor', (array) $user->roles, true );
		if ( ! $is && function_exists( 'wcfm_is_vendor' ) && ! in_array( 'shop_staff', (array) $user->roles, true ) ) {
			$is = (bool) wcfm_is_vendor( (int) $user_id );
		}
		if ( $is && get_user_meta( (int) $user_id, '_disable_vendor', true ) ) {
			$is = false;
		}
		return $is;
	}

	/**
	 * WCFM staff: `shop_staff` role + `_wcfm_vendor` user meta.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	protected function staff_vendor( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! in_array( 'shop_staff', (array) $user->roles, true ) ) {
			return 0;
		}
		return (int) get_user_meta( (int) $user_id, '_wcfm_vendor', true );
	}

	/**
	 * Raw profile settings.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array
	 */
	private function settings( $vendor_id ) {
		$info = get_user_meta( (int) $vendor_id, 'wcfmmp_profile_settings', true );
		return is_array( $info ) ? $info : array();
	}

	/**
	 * Profile.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array
	 */
	public function get_profile( $vendor_id ) {
		$info = $this->settings( $vendor_id );
		$seo  = isset( $info['store_seo'] ) && is_array( $info['store_seo'] ) ? $info['store_seo'] : array();
		$name = isset( $info['store_name'] ) ? (string) $info['store_name'] : (string) get_user_meta( (int) $vendor_id, 'store_name', true );
		return array(
			'store_name'      => $name,
			'bio'             => (string) get_user_meta( (int) $vendor_id, '_store_description', true ),
			'tagline'         => (string) get_user_meta( (int) $vendor_id, '_dev3va_tagline', true ),
			'seo_title'       => isset( $seo['wcfmmp-seo-meta-title'] ) ? (string) $seo['wcfmmp-seo-meta-title'] : '',
			'seo_description' => isset( $seo['wcfmmp-seo-meta-desc'] ) ? (string) $seo['wcfmmp-seo-meta-desc'] : '',
			'seo_keywords'    => isset( $seo['wcfmmp-seo-meta-keywords'] ) ? (string) $seo['wcfmmp-seo-meta-keywords'] : '',
		);
	}

	/**
	 * Saves into WCFM's own keys (WCFM prints the description and SEO tags itself).
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $fields    Fields.
	 * @return bool
	 */
	public function save_profile( $vendor_id, array $fields ) {
		$info = $this->settings( $vendor_id );
		$seo  = isset( $info['store_seo'] ) && is_array( $info['store_seo'] ) ? $info['store_seo'] : array();
		if ( array_key_exists( 'bio', $fields ) ) {
			$bio                      = wp_kses_post( (string) $fields['bio'] );
			$info['shop_description'] = $bio;
			update_user_meta( (int) $vendor_id, '_store_description', $bio );
		}
		$map = array(
			'seo_title'       => array( 'wcfmmp-seo-meta-title', 'wcfmmp-seo-og-title', 'wcfmmp-seo-twitter-title' ),
			'seo_description' => array( 'wcfmmp-seo-meta-desc', 'wcfmmp-seo-og-desc', 'wcfmmp-seo-twitter-desc' ),
			'seo_keywords'    => array( 'wcfmmp-seo-meta-keywords' ),
		);
		foreach ( $map as $field => $keys ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $fields[ $field ] );
			foreach ( $keys as $i => $key ) {
				if ( 0 === $i || empty( $seo[ $key ] ) ) {
					$seo[ $key ] = $value;
				}
			}
		}
		$info['store_seo'] = $seo;
		if ( array_key_exists( 'tagline', $fields ) ) {
			update_user_meta( (int) $vendor_id, '_dev3va_tagline', sanitize_text_field( (string) $fields['tagline'] ) );
		}
		update_user_meta( (int) $vendor_id, 'wcfmmp_profile_settings', $info );
		/**
		 * After DEV3 AI saved WCFM store profile fields.
		 *
		 * @param int   $vendor_id Vendor.
		 * @param array $fields    Fields.
		 */
		do_action( 'dev3va_wcfm_profile_saved', (int) $vendor_id, $fields );
		return true;
	}

	/**
	 * Store URL.
	 *
	 * @param int $vendor_id Vendor.
	 * @return string
	 */
	public function store_url( $vendor_id ) {
		return function_exists( 'wcfmmp_get_store_url' ) ? (string) wcfmmp_get_store_url( (int) $vendor_id ) : parent::store_url( $vendor_id );
	}

	/**
	 * Product owner (WCFM filter aware).
	 *
	 * @param int $product_id Product.
	 * @return int
	 */
	public function product_vendor( $product_id ) {
		$owner = parent::product_vendor( $product_id );
		/**
		 * WCFM's vendor-by-product filter (multi-vendor product catalogues may change it).
		 *
		 * @param int $owner      Vendor id.
		 * @param int $product_id Product.
		 */
		return (int) apply_filters( 'dev3va_wcfm_product_vendor', $owner, (int) $product_id );
	}

	/**
	 * Orders from `wcfm_marketplace_orders`.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $since     Since.
	 * @param int $limit     Limit.
	 * @return int[]
	 */
	public function order_ids( $vendor_id, $since = 0, $limit = 300 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcfm_marketplace_orders';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return parent::order_ids( $vendor_id, $since, $limit );
		}
		if ( $since ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$table} WHERE vendor_id = %d AND created > %s ORDER BY order_id DESC LIMIT %d", (int) $vendor_id, gmdate( 'Y-m-d H:i:s', (int) $since ), (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$table} WHERE vendor_id = %d ORDER BY order_id DESC LIMIT %d", (int) $vendor_id, (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$ids = array_map( 'intval', (array) $ids );
		return $ids ? $ids : parent::order_ids( $vendor_id, $since, $limit );
	}

	/**
	 * WCFM: vendors with `publish_products` publish directly, others need review.
	 *
	 * @param int $vendor_id Vendor.
	 * @return string
	 */
	public function new_product_status( $vendor_id ) {
		return user_can( (int) $vendor_id, 'publish_products' ) ? 'publish' : 'pending'; // phpcs:ignore WordPress.WP.Capabilities.Unknown
	}

	/**
	 * Skin from WCFM's colour settings.
	 *
	 * @return array
	 */
	public function skin() {
		$options = (array) get_option( 'wcfm_options', array() );
		$brand   = isset( $options['wc_frontend_manager_base_highlight_color_settings'] ) ? sanitize_hex_color( $options['wc_frontend_manager_base_highlight_color_settings'] ) : '';
		$head    = isset( $options['wc_frontend_manager_primary_bg_color_settings'] ) ? sanitize_hex_color( $options['wc_frontend_manager_primary_bg_color_settings'] ) : '';
		return array(
			'id'          => 'wcfm',
			'brand'       => $brand ? $brand : '#17a2b8',
			'brand_hover' => $head ? $head : '#1C2B36',
			'brand_text'  => '#ffffff',
			'radius'      => '3px',
		);
	}

	/**
	 * Product manager URL.
	 *
	 * @param int $product_id Product.
	 * @return string
	 */
	public function product_edit_url( $product_id ) {
		if ( function_exists( 'get_wcfm_edit_product_url' ) ) {
			return (string) get_wcfm_edit_product_url( (int) $product_id );
		}
		return parent::product_edit_url( $product_id );
	}

	/**
	 * Assistant URL.
	 *
	 * @param string $route Hash route.
	 * @return string
	 */
	public function assistant_url( $route = '' ) {
		$url = ( function_exists( 'wcfm_get_endpoint_url' ) && function_exists( 'get_wcfm_page' ) ) ? wcfm_get_endpoint_url( self::ENDPOINT_KEY, '', get_wcfm_page() ) : home_url( '/' );
		return $route ? $url . '#/' . ltrim( $route, '/#' ) : $url;
	}

	/**
	 * Whether this is the assistant page.
	 *
	 * @return bool
	 */
	public function is_assistant_page() {
		global $wp;
		return isset( $wp->query_vars[ self::ENDPOINT_KEY ] ) || isset( $wp->query_vars[ self::ENDPOINT ] );
	}

	/**
	 * Hooks.
	 */
	public function register_dashboard() {
		add_filter( 'wcfm_query_vars', array( $this, 'query_vars' ), 40 );
		add_filter( 'wcfm_endpoint_title', array( $this, 'endpoint_title' ), 40, 2 );
		add_filter( 'wcfm_endpoints_slug', array( $this, 'endpoints_slug' ) );
		add_filter( 'wcfm_menus', array( $this, 'menus' ), 40 );
		add_action( 'wcfm_load_views', array( $this, 'render' ), 40 );
		add_action( 'init', array( $this, 'endpoints' ), 40 );
		add_action( 'after_wcfm_products_manage_title', array( $this, 'product_shortcut' ), 20 );
	}

	/**
	 * Adds the endpoint.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$modified                   = function_exists( 'wcfm_get_option' ) ? (array) wcfm_get_option( 'wcfm_endpoints', array() ) : array();
		$vars[ self::ENDPOINT_KEY ] = ! empty( $modified[ self::ENDPOINT_KEY ] ) ? $modified[ self::ENDPOINT_KEY ] : self::ENDPOINT;
		return $vars;
	}

	/**
	 * Endpoint slug list (WCFM settings "endpoints" screen).
	 *
	 * @param array $endpoints Endpoints.
	 * @return array
	 */
	public function endpoints_slug( $endpoints ) {
		$endpoints[ self::ENDPOINT_KEY ] = self::ENDPOINT;
		return $endpoints;
	}

	/**
	 * Re-registers WCFM endpoints with ours and flushes once.
	 */
	public function endpoints() {
		global $WCFM_Query; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		if ( isset( $WCFM_Query ) && is_object( $WCFM_Query ) && method_exists( $WCFM_Query, 'init_query_vars' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
			$WCFM_Query->init_query_vars(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
			$WCFM_Query->add_endpoints(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		}
		if ( get_option( 'dev3va_wcfm_endpoint' ) !== DEV3VA_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'dev3va_wcfm_endpoint', DEV3VA_VERSION, false );
		}
	}

	/**
	 * Page title.
	 *
	 * @param string $title    Title.
	 * @param string $endpoint Endpoint.
	 * @return string
	 */
	public function endpoint_title( $title, $endpoint ) {
		return self::ENDPOINT_KEY === $endpoint ? Settings::menu_label() : $title;
	}

	/**
	 * Menu item.
	 *
	 * @param array $menus Menus.
	 * @return array
	 */
	public function menus( $menus ) {
		$vendor = Access::current_vendor();
		if ( ! $vendor || ! Access::has_access( $vendor ) ) {
			return $menus;
		}
		$menus[ self::ENDPOINT_KEY ] = array(
			'label'    => Settings::menu_label(),
			'url'      => $this->assistant_url(),
			'icon'     => 'magic',
			'priority' => max( 1, (int) round( (int) Settings::get( 'menu_position', 45 ) / 10 ) ),
		);
		return $menus;
	}

	/**
	 * View inside WCFM's layout.
	 *
	 * @param string $end_point Endpoint.
	 */
	public function render( $end_point ) {
		if ( self::ENDPOINT_KEY !== $end_point ) {
			return;
		}
		$vendor = Access::current_vendor();
		echo '<div class="collapse wcfm-collapse" id="wcfm_dev3va_assistant">';
		echo '<div class="wcfm-page-headig"><span class="wcfmfa fa-magic"></span><span class="wcfm-page-heading-text">' . esc_html( Settings::menu_label() ) . '</span>';
		do_action( 'wcfm_page_heading' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WCFM hook.
		echo '</div><div class="wcfm-collapse-content"><div id="wcfm_page_load"></div>';
		echo '<div class="wcfm-container"><div class="wcfm-content dev3va-wcfm-content">';
		if ( ! $vendor || ! Access::has_access( $vendor ) ) {
			echo '<p>' . esc_html__( 'The AI assistant is not available for your account.', 'dev3-ai-vendor-assistant' ) . '</p>';
		} else {
			echo $this->mount(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Vendor_App::mount.
		}
		echo '</div></div></div></div>';
	}

	/**
	 * Shortcut in the WCFM product manager.
	 */
	public function product_shortcut() {
		global $wp;
		$product_id = 0;
		if ( isset( $wp->query_vars['wcfm-products-manage'] ) ) {
			$product_id = absint( $wp->query_vars['wcfm-products-manage'] );
		}
		Shortcuts::render( $this, $product_id, 'wcfm' );
	}
}
