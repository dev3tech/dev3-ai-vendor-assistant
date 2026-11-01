<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Admin;

use Dev3\AiVendorAssistant\Core3\Core\Assets;
use Dev3\AiVendorAssistant\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3VA_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$count      = \Dev3\AiVendorAssistant\Features\Moderation::counts();
		$open       = (int) $count['pending'] + (int) $count['flagged'];
		$badge      = $open ? ' <span class="awaiting-mod count-' . $open . '"><span class="pending-count">' . number_format_i18n( $open ) . '</span></span>' : '';
		$this->hook = (string) add_menu_page( DEV3VA_NAME, __( 'DEV3 AI Vendors', 'dev3-ai-vendor-assistant' ) . $badge, Capabilities::manage(), DEV3VA_SLUG, array( $this, 'render' ), 'dashicons-store', 57 );
		$subs       = array(
			''           => __( 'Dashboard', 'dev3-ai-vendor-assistant' ),
			'vendors'    => __( 'Vendors & quotas', 'dev3-ai-vendor-assistant' ),
			'moderation' => __( 'Moderation', 'dev3-ai-vendor-assistant' ),
			'activity'   => __( 'Activity', 'dev3-ai-vendor-assistant' ),
			'settings'   => __( 'Settings', 'dev3-ai-vendor-assistant' ),
		);
		foreach ( $subs as $route => $label ) {
			add_submenu_page( DEV3VA_SLUG, $label, $label, Capabilities::manage(), '' === $route ? DEV3VA_SLUG : 'admin.php?page=' . DEV3VA_SLUG . '#/' . $route );
		}
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3va-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3VA_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3va-admin-root" class="dev3va-root dev3va-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3VA_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-vendor-assistant' ) . '</a>' );
		return $links;
	}
}
