<?php
/**
 * Dokan (Lite, Pro through its documented keys and hooks).
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Marketplace;

use Dev3\AiVendorAssistant\Settings;
use Dev3\AiVendorAssistant\Vendor\Access;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dokan's own order table.

/**
 * Dokan adapter.
 */
class Dokan extends Adapter {

	const ENDPOINT = 'ai-assistant';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'dokan';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return function_exists( 'dokan_pro' ) ? 'Dokan Pro' : 'Dokan';
	}

	/**
	 * Active.
	 *
	 * @return bool
	 */
	public function active() {
		return function_exists( 'dokan' ) || class_exists( 'WeDevs_Dokan' );
	}

	/**
	 * Vendor roles.
	 *
	 * @return string[]
	 */
	public function vendor_roles() {
		return array( 'seller' );
	}

	/**
	 * Vendor check (staff excluded).
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public function is_vendor( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( user_can( $user_id, 'vendor_staff' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return false;
		}
		if ( function_exists( 'dokan_is_user_seller' ) ) {
			return (bool) dokan_is_user_seller( $user_id, true );
		}
		$user = get_userdata( $user_id );
		return $user && in_array( 'seller', (array) $user->roles, true );
	}

	/**
	 * Dokan Pro vendor staff: `vendor_staff` capability + `_vendor_id` user meta.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	protected function staff_vendor( $user_id ) {
		if ( ! user_can( (int) $user_id, 'vendor_staff' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return 0;
		}
		return (int) get_user_meta( (int) $user_id, '_vendor_id', true );
	}

	/**
	 * Raw Dokan profile settings.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array
	 */
	private function settings( $vendor_id ) {
		$info = get_user_meta( (int) $vendor_id, 'dokan_profile_settings', true );
		return is_array( $info ) ? $info : array();
	}

	/**
	 * Profile. Bio = Dokan Pro `vendor_biography`; SEO = Dokan Pro `store_seo[dokan-seo-meta-*]`.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array
	 */
	public function get_profile( $vendor_id ) {
		$info = $this->settings( $vendor_id );
		$seo  = isset( $info['store_seo'] ) && is_array( $info['store_seo'] ) ? $info['store_seo'] : array();
		return array(
			'store_name'      => isset( $info['store_name'] ) ? (string) $info['store_name'] : '',
			'bio'             => isset( $info['vendor_biography'] ) ? (string) $info['vendor_biography'] : '',
			'tagline'         => (string) get_user_meta( (int) $vendor_id, '_dev3va_tagline', true ),
			'seo_title'       => isset( $seo['dokan-seo-meta-title'] ) ? (string) $seo['dokan-seo-meta-title'] : '',
			'seo_description' => isset( $seo['dokan-seo-meta-desc'] ) ? (string) $seo['dokan-seo-meta-desc'] : '',
			'seo_keywords'    => isset( $seo['dokan-seo-meta-keywords'] ) ? (string) $seo['dokan-seo-meta-keywords'] : '',
		);
	}

	/**
	 * Saves profile fields into Dokan's own keys (so Dokan Pro shows them too).
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $fields    Fields.
	 * @return bool
	 */
	public function save_profile( $vendor_id, array $fields ) {
		$info = $this->settings( $vendor_id );
		$seo  = isset( $info['store_seo'] ) && is_array( $info['store_seo'] ) ? $info['store_seo'] : array();
		if ( array_key_exists( 'bio', $fields ) ) {
			$info['vendor_biography'] = wp_kses_post( (string) $fields['bio'] );
		}
		$map = array(
			'seo_title'       => 'dokan-seo-meta-title',
			'seo_description' => 'dokan-seo-meta-desc',
			'seo_keywords'    => 'dokan-seo-meta-keywords',
		);
		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				$seo[ $key ] = sanitize_text_field( (string) $fields[ $field ] );
			}
		}
		if ( array_key_exists( 'seo_title', $fields ) && empty( $seo['dokan-seo-og-title'] ) ) {
			$seo['dokan-seo-og-title'] = $seo['dokan-seo-meta-title'];
		}
		if ( array_key_exists( 'seo_description', $fields ) && empty( $seo['dokan-seo-og-desc'] ) ) {
			$seo['dokan-seo-og-desc'] = $seo['dokan-seo-meta-desc'];
		}
		$info['store_seo'] = $seo;
		if ( array_key_exists( 'tagline', $fields ) ) {
			update_user_meta( (int) $vendor_id, '_dev3va_tagline', sanitize_text_field( (string) $fields['tagline'] ) );
		}
		update_user_meta( (int) $vendor_id, 'dokan_profile_settings', $info );
		if ( function_exists( 'dokan' ) && isset( dokan()->vendor ) ) {
			// Dokan caches shop data per request.
			wp_cache_delete( (int) $vendor_id, 'user_meta' );
		}
		/**
		 * After DEV3 AI saved Dokan store profile fields.
		 *
		 * @param int   $vendor_id Vendor.
		 * @param array $fields    Fields.
		 */
		do_action( 'dev3va_dokan_profile_saved', (int) $vendor_id, $fields );
		return true;
	}

	/**
	 * Store URL.
	 *
	 * @param int $vendor_id Vendor.
	 * @return string
	 */
	public function store_url( $vendor_id ) {
		return function_exists( 'dokan_get_store_url' ) ? (string) dokan_get_store_url( (int) $vendor_id ) : parent::store_url( $vendor_id );
	}

	/**
	 * Orders from Dokan's `dokan_orders` table (falls back to the line-item scan).
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $since     Since (unix).
	 * @param int $limit     Limit.
	 * @return int[]
	 */
	public function order_ids( $vendor_id, $since = 0, $limit = 300 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dokan_orders';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return parent::order_ids( $vendor_id, $since, $limit );
		}
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE seller_id = %d ORDER BY order_id DESC LIMIT %d", (int) $vendor_id, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $ids ) {
			return parent::order_ids( $vendor_id, $since, $limit );
		}
		if ( $since ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( $id ) use ( $since ) {
						$order = wc_get_order( $id );
						return $order && $order->get_date_created() && $order->get_date_created()->getTimestamp() > $since;
					}
				)
			);
		}
		return $ids;
	}

	/**
	 * Dokan's "new product status" selling option.
	 *
	 * @param int $vendor_id Vendor.
	 * @return string
	 */
	public function new_product_status( $vendor_id ) {
		$status = function_exists( 'dokan_get_option' ) ? dokan_get_option( 'product_status', 'dokan_selling', 'pending' ) : 'pending';
		if ( 'publish' === $status && function_exists( 'dokan_is_seller_trusted' ) && ! dokan_is_seller_trusted( (int) $vendor_id ) && get_user_meta( (int) $vendor_id, 'dokan_publishing', true ) === 'no' ) {
			$status = 'pending';
		}
		return 'publish' === $status ? 'publish' : 'pending';
	}

	/**
	 * Skin: Dokan 4+ exposes its theme colour as a CSS variable.
	 *
	 * @return array
	 */
	public function skin() {
		return array(
			'id'          => 'dokan',
			'brand'       => 'var(--dokan-button-background-color, #7047EB)',
			'brand_hover' => 'var(--dokan-button-hover-background-color, #502BBF)',
			'brand_text'  => 'var(--dokan-button-text-color, #ffffff)',
			'radius'      => '6px',
		);
	}

	/**
	 * Product edit URL in the Dokan dashboard.
	 *
	 * @param int $product_id Product.
	 * @return string
	 */
	public function product_edit_url( $product_id ) {
		if ( function_exists( 'dokan_edit_product_url' ) ) {
			return (string) dokan_edit_product_url( (int) $product_id );
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
		$url = function_exists( 'dokan_get_navigation_url' ) ? dokan_get_navigation_url( self::ENDPOINT ) : home_url( '/' );
		return $route ? $url . '#/' . ltrim( $route, '/#' ) : $url;
	}

	/**
	 * Whether this is the assistant page.
	 *
	 * @return bool
	 */
	public function is_assistant_page() {
		global $wp;
		return function_exists( 'dokan_is_seller_dashboard' ) && dokan_is_seller_dashboard() && isset( $wp->query_vars[ self::ENDPOINT ] );
	}

	/**
	 * Hooks.
	 */
	public function register_dashboard() {
		add_filter( 'dokan_query_var_filter', array( $this, 'query_var' ) );
		add_filter( 'dokan_get_dashboard_nav', array( $this, 'nav' ), 30 );
		add_action( 'dokan_load_custom_template', array( $this, 'render' ) );
		add_action( 'dokan_product_edit_after_title', array( $this, 'product_shortcut' ), 20, 2 );
		add_action( 'dokan_store_profile_frame_after', array( $this, 'store_bio' ), 20, 2 );
		add_action( 'wp_head', array( $this, 'store_seo_tags' ), 2 );
		add_filter( 'pre_get_document_title', array( $this, 'store_title' ), 20 );
		add_action( 'init', array( $this, 'maybe_flush' ), 30 );
	}

	/**
	 * Adds the endpoint.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function query_var( $vars ) {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Asks Dokan to flush rewrite rules once after our endpoint appears.
	 */
	public function maybe_flush() {
		if ( get_option( 'dev3va_dokan_endpoint' ) !== DEV3VA_VERSION ) {
			update_option( 'dokan_rewrite_rules_needs_flashing', 'yes' );
			update_option( 'dev3va_dokan_endpoint', DEV3VA_VERSION, false );
		}
	}

	/**
	 * Navigation item (only for vendors with access).
	 *
	 * @param array $menus Menus.
	 * @return array
	 */
	public function nav( $menus ) {
		$vendor = Access::current_vendor();
		if ( ! $vendor || ! Access::has_access( $vendor ) ) {
			return $menus;
		}
		$menus[ self::ENDPOINT ] = array(
			'title'     => Settings::menu_label(),
			'icon'      => '<i class="fas fa-magic"></i>',
			'icon_name' => 'Sparkles',
			'url'       => $this->assistant_url(),
			'pos'       => (int) Settings::get( 'menu_position', 45 ),
		);
		return $menus;
	}

	/**
	 * Renders the page inside Dokan's dashboard layout.
	 *
	 * @param array $query_vars Query vars.
	 */
	public function render( $query_vars ) {
		if ( ! isset( $query_vars[ self::ENDPOINT ] ) ) {
			return;
		}
		do_action( 'dokan_dashboard_wrap_start' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Dokan hook.
		echo '<div class="dokan-dashboard-wrap">';
		do_action( 'dokan_dashboard_content_before' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Dokan hook.
		echo '<div class="dokan-dashboard-content dev3va-dokan-content"><article class="dev3va-dokan-area">';
		$vendor = Access::current_vendor();
		if ( ! $vendor || ! Access::has_access( $vendor ) ) {
			dokan_get_template_part( 'global/no-permission' );
		} else {
			echo $this->mount(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Vendor_App::mount.
		}
		echo '</article></div>';
		do_action( 'dokan_dashboard_content_after' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Dokan hook.
		echo '</div>';
		do_action( 'dokan_dashboard_wrap_end' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Dokan hook.
	}

	/**
	 * Shortcut on the Dokan product edit form.
	 *
	 * @param \WP_Post $post    Post.
	 * @param int      $post_id Product id.
	 */
	public function product_shortcut( $post, $post_id ) {
		unset( $post );
		Shortcuts::render( $this, (int) $post_id, 'dokan' );
	}

	/**
	 * Store whose page is being viewed (0 when not a store page).
	 *
	 * @return int
	 */
	private function viewed_store() {
		if ( ! function_exists( 'dokan_is_store_page' ) || ! dokan_is_store_page() ) {
			return 0;
		}
		$var  = function_exists( 'dokan_get_option' ) ? dokan_get_option( 'custom_store_url', 'dokan_general', 'store' ) : 'store';
		$slug = (string) get_query_var( $var );
		$user = $slug ? get_user_by( 'slug', $slug ) : false;
		return $user ? (int) $user->ID : 0;
	}

	/**
	 * Dokan Lite has no biography tab: print the bio under the store header (Pro shows its own tab).
	 *
	 * @param \WP_User|object $store_user Store user data.
	 * @param array           $store_info Store info.
	 */
	public function store_bio( $store_user, $store_info ) {
		if ( function_exists( 'dokan_pro' ) || empty( $store_info['vendor_biography'] ) ) {
			return;
		}
		$tagline = $store_user && isset( $store_user->ID ) ? (string) get_user_meta( (int) $store_user->ID, '_dev3va_tagline', true ) : '';
		echo '<section class="dev3va-store-bio">';
		if ( $tagline ) {
			echo '<p class="dev3va-store-bio__tagline">' . esc_html( $tagline ) . '</p>';
		}
		echo '<div class="dev3va-store-bio__text">' . wp_kses_post( wpautop( (string) $store_info['vendor_biography'] ) ) . '</div></section>';
	}

	/**
	 * Store SEO tags for Dokan Lite (Dokan Pro prints its own).
	 */
	public function store_seo_tags() {
		if ( function_exists( 'dokan_pro' ) || ! Settings::get( 'store_seo_output', true ) ) {
			return;
		}
		$vendor = $this->viewed_store();
		if ( ! $vendor ) {
			return;
		}
		$profile = $this->get_profile( $vendor );
		if ( $profile['seo_description'] ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $profile['seo_description'] ) );
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $profile['seo_description'] ) );
		}
		if ( $profile['seo_keywords'] ) {
			printf( '<meta name="keywords" content="%s" />' . "\n", esc_attr( $profile['seo_keywords'] ) );
		}
		if ( $profile['seo_title'] ) {
			printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $profile['seo_title'] ) );
		}
	}

	/**
	 * Store SEO title for Dokan Lite.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public function store_title( $title ) {
		if ( function_exists( 'dokan_pro' ) || ! Settings::get( 'store_seo_output', true ) ) {
			return $title;
		}
		$vendor = $this->viewed_store();
		if ( ! $vendor ) {
			return $title;
		}
		$profile = $this->get_profile( $vendor );
		return $profile['seo_title'] ? $profile['seo_title'] : $title;
	}
}
