<?php
/**
 * "Improve with AI" / "Suggest a price" links on the marketplace product forms.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Marketplace;

use Dev3\AiVendorAssistant\Vendor\Access;
use Dev3\AiVendorAssistant\Vendor\Features;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the shortcut bar.
 */
class Shortcuts {

	/**
	 * Prints links for a product the current vendor owns.
	 *
	 * @param Adapter $adapter    Adapter.
	 * @param int     $product_id Product (0 = new product).
	 * @param string  $skin       dokan|wcfm.
	 */
	public static function render( Adapter $adapter, $product_id, $skin ) {
		$vendor = Access::current_vendor();
		if ( ! $vendor || ! Access::has_access( $vendor ) ) {
			return;
		}
		if ( $product_id && ! $adapter->owns_product( $vendor, $product_id ) ) {
			return;
		}
		$links = array();
		if ( Features::enabled( 'listing', $vendor ) ) {
			$links[] = array(
				'url'   => $adapter->assistant_url( $product_id ? 'listing/' . $product_id : 'listing' ),
				'label' => $product_id ? __( 'Improve this listing with AI', 'dev3-ai-vendor-assistant' ) : __( 'Write this listing with AI', 'dev3-ai-vendor-assistant' ),
			);
		}
		if ( $product_id && Features::enabled( 'price', $vendor ) ) {
			$links[] = array(
				'url'   => $adapter->assistant_url( 'pricing/' . $product_id ),
				'label' => __( 'Suggest a price', 'dev3-ai-vendor-assistant' ),
			);
		}
		if ( ! $links ) {
			return;
		}
		echo '<div class="dev3va-shortcuts dev3va-shortcuts--' . esc_attr( $skin ) . '">';
		echo '<span class="dev3va-shortcuts__mark" aria-hidden="true">&#10022;</span>';
		foreach ( $links as $link ) {
			echo '<a class="dev3va-shortcuts__link" href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a>';
		}
		echo '</div>';
	}
}
