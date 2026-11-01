<?php
/**
 * Vendor assistant React app: mount point, assets, shortcode and the small front stylesheet.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Front;

use Dev3\AiVendorAssistant\Core3\Core\Assets;
use Dev3\AiVendorAssistant\Marketplace\Adapter;
use Dev3\AiVendorAssistant\Marketplace\Manager;
use Dev3\AiVendorAssistant\Settings;
use Dev3\AiVendorAssistant\Vendor\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Vendor app.
 */
class Vendor_App {

	/**
	 * Whether the vendor bundle was registered on this request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Hooks.
	 */
	public static function register() {
		add_shortcode( 'dev3va_vendor_assistant', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 30 );
	}

	/**
	 * Registers the vendor bundle once.
	 */
	public static function register_assets() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		Assets::register( array( 'vendor' ) );
	}

	/**
	 * Front stylesheet on dashboards/store pages, and the app bundle on the assistant page.
	 */
	public static function enqueue() {
		$adapter = Manager::current();
		if ( ! $adapter ) {
			return;
		}
		$dashboard = ( function_exists( 'dokan_is_seller_dashboard' ) && dokan_is_seller_dashboard() ) || ( function_exists( 'is_wcfm_page' ) && is_wcfm_page() ) || ( function_exists( 'dokan_is_store_page' ) && dokan_is_store_page() );
		if ( $dashboard ) {
			wp_enqueue_style( 'dev3va-front', DEV3VA_URL . 'assets/css/front.css', array(), DEV3VA_VERSION );
		}
		if ( $adapter->is_assistant_page() ) {
			self::enqueue_app();
		}
	}

	/**
	 * Enqueues the app bundle.
	 */
	public static function enqueue_app() {
		self::register_assets();
		Assets::enqueue( 'vendor' );
		wp_enqueue_style( 'dev3va-front', DEV3VA_URL . 'assets/css/front.css', array(), DEV3VA_VERSION );
	}

	/**
	 * Mount markup (skin colours as CSS variables on the root).
	 *
	 * @param Adapter $adapter Adapter.
	 * @return string
	 */
	public static function mount( Adapter $adapter ) {
		self::enqueue_app();
		$skin   = $adapter->skin();
		$style  = sprintf(
			'--dev3va-skin-brand:%1$s;--dev3va-skin-brand-hover:%2$s;--dev3va-skin-brand-text:%3$s;--dev3va-skin-radius:%4$s;',
			$skin['brand'],
			$skin['brand_hover'],
			$skin['brand_text'],
			$skin['radius']
		);
		$config = array(
			'skin'      => $skin['id'],
			'menuLabel' => Settings::menu_label(),
		);
		return sprintf(
			'<div id="dev3va-vendor-root" class="dev3va-root dev3va-vendor dev3va-skin-%1$s" style="%2$s" data-config="%3$s"><div class="dev3va-vendor-boot" aria-busy="true"><span class="dev3va-vendor-boot__bar"></span><span class="dev3va-vendor-boot__bar"></span><span class="dev3va-vendor-boot__bar"></span></div><noscript>%4$s</noscript></div>',
			esc_attr( $skin['id'] ),
			esc_attr( $style ),
			esc_attr( wp_json_encode( $config ) ),
			esc_html__( 'The AI assistant needs JavaScript.', 'dev3-ai-vendor-assistant' )
		);
	}

	/**
	 * [dev3va_vendor_assistant] for any page.
	 *
	 * @return string
	 */
	public static function shortcode() {
		$adapter = Manager::current();
		if ( ! $adapter ) {
			return '';
		}
		$vendor = Access::current_vendor();
		if ( ! $vendor || ! Access::has_access( $vendor ) ) {
			return is_user_logged_in() ? '<p class="dev3va-note">' . esc_html__( 'The AI assistant is available to marketplace vendors.', 'dev3-ai-vendor-assistant' ) . '</p>' : '';
		}
		return self::mount( $adapter );
	}
}
