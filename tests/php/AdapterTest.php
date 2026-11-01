<?php
/**
 * Marketplace adapters against the real Dokan Lite / WCFM Marketplace code.
 *
 * @package Dev3\AiVendorAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiVendorAssistant\Marketplace\Dokan;
use Dev3\AiVendorAssistant\Marketplace\Manager;
use Dev3\AiVendorAssistant\Marketplace\Wcfm;
use Dev3\AiVendorAssistant\Vendor\Access;

class AdapterTest extends Base_Case {

	public function test_detects_the_active_marketplace() {
		$adapter = Manager::current();
		$this->assertNotNull( $adapter );
		$this->assertSame( $this->marketplace(), $adapter->id() );
		$this->assertInstanceOf( 'wcfm' === $this->marketplace() ? Wcfm::class : Dokan::class, $adapter );
		$ids = wp_list_pluck( Manager::detected(), 'active', 'id' );
		$this->assertTrue( $ids[ $this->marketplace() ] );
	}

	public function test_vendor_staff_and_customer_mapping() {
		$vendor   = $this->vendor();
		$staff    = $this->staff( $vendor );
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$adapter  = Manager::current();
		$this->assertTrue( $adapter->is_vendor( $vendor ) );
		$this->assertSame( $vendor, $adapter->vendor_for_user( $vendor ) );
		$this->assertSame( $vendor, $adapter->vendor_for_user( $staff ), 'Staff act for their vendor' );
		$this->assertFalse( $adapter->is_vendor( $staff ), 'Staff are not vendors themselves' );
		$this->assertSame( 0, $adapter->vendor_for_user( $customer ) );
		$this->assertSame( 0, $adapter->vendor_for_user( 0 ) );

		wp_set_current_user( $staff );
		$this->assertSame( $vendor, Access::current_vendor() );
		wp_set_current_user( $customer );
		$this->assertSame( 0, Access::current_vendor() );
	}

	public function test_product_and_order_ownership() {
		$a       = $this->vendor( 'A' );
		$b       = $this->vendor( 'B' );
		$pa      = $this->product( $a );
		$pb      = $this->product( $b );
		$adapter = Manager::current();
		$this->assertTrue( $adapter->owns_product( $a, $pa ) );
		$this->assertFalse( $adapter->owns_product( $a, $pb ) );
		$this->assertFalse( $adapter->owns_product( 0, $pa ) );
		$this->assertSame( array( $pa ), $adapter->product_ids( $a ) );

		$order = $this->order( $pa, 'Please gift wrap' );
		$this->assertTrue( $adapter->owns_order( $a, $order->get_id() ) );
		$this->assertFalse( $adapter->owns_order( $b, $order->get_id() ) );
		$this->assertContains( $order->get_id(), $adapter->order_ids( $a ) );
		$this->assertNotContains( $order->get_id(), $adapter->order_ids( $b ) );
	}

	public function test_profile_is_saved_into_the_marketplace_keys() {
		$vendor  = $this->vendor( 'Clay & Co' );
		$adapter = Manager::current();
		$adapter->save_profile(
			$vendor,
			array(
				'bio'             => '<p>Small batches.</p><script>x</script>',
				'tagline'         => 'Made by hand',
				'seo_title'       => 'Clay & Co mugs',
				'seo_description' => 'Handmade mugs',
				'seo_keywords'    => 'mugs, clay',
			)
		);
		$profile = $adapter->get_profile( $vendor );
		$this->assertSame( 'Clay & Co', $adapter->store_name( $vendor ) );
		$this->assertStringContainsString( 'Small batches.', $profile['bio'] );
		$this->assertStringNotContainsString( '<script>', $profile['bio'] );
		$this->assertSame( 'Made by hand', $profile['tagline'] );
		if ( 'wcfm' === $this->marketplace() ) {
			$raw = get_user_meta( $vendor, 'wcfmmp_profile_settings', true );
			$this->assertSame( 'Clay & Co mugs', $raw['store_seo']['wcfmmp-seo-meta-title'] );
			$this->assertSame( 'Handmade mugs', $raw['store_seo']['wcfmmp-seo-meta-desc'] );
			$this->assertStringContainsString( 'Small batches.', get_user_meta( $vendor, '_store_description', true ) );
			$this->assertSame( 'Clay & Co', $raw['store_name'], 'Other WCFM keys survive' );
		} else {
			$raw = get_user_meta( $vendor, 'dokan_profile_settings', true );
			$this->assertSame( 'Clay & Co mugs', $raw['store_seo']['dokan-seo-meta-title'] );
			$this->assertSame( 'mugs, clay', $raw['store_seo']['dokan-seo-meta-keywords'] );
			$this->assertStringContainsString( 'Small batches.', $raw['vendor_biography'] );
			$this->assertSame( 'Clay & Co', $raw['store_name'], 'Other Dokan keys survive' );
		}
	}

	public function test_dashboard_menu_only_for_vendors_with_access() {
		$vendor  = $this->vendor();
		$adapter = Manager::current();
		$adapter->register_dashboard();
		$menu = static function () {
			return 'wcfm' === DEV3VA_TEST_MARKETPLACE ? apply_filters( 'wcfm_menus', array() ) : apply_filters( 'dokan_get_dashboard_nav', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		};
		$key = 'wcfm' === $this->marketplace() ? 'wcfm-ai-assistant' : 'ai-assistant';

		wp_set_current_user( $vendor );
		$items = $menu();
		$this->assertArrayHasKey( $key, $items );
		$this->assertSame( 'AI Assistant', 'wcfm' === $this->marketplace() ? $items[ $key ]['label'] : $items[ $key ]['title'] );

		update_user_meta( $vendor, '_dev3va_access', '0' );
		$this->assertArrayNotHasKey( $key, $menu() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertArrayNotHasKey( $key, $menu() );
	}

	public function test_dashboard_page_renders_inside_the_marketplace_layout() {
		$vendor  = $this->vendor();
		$adapter = Manager::current();
		$adapter->register_dashboard();
		wp_set_current_user( $vendor );
		ob_start();
		if ( 'wcfm' === $this->marketplace() ) {
			do_action( 'wcfm_load_views', 'wcfm-ai-assistant' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$html = ob_get_clean();
			$this->assertStringContainsString( 'wcfm-collapse', $html );
			$this->assertStringContainsString( 'wcfm-page-headig', $html );
			$this->assertSame( 'ai-assistant', apply_filters( 'wcfm_query_vars', array() )['wcfm-ai-assistant'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		} else {
			do_action( 'dokan_load_custom_template', array( 'ai-assistant' => '' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$html = ob_get_clean();
			$this->assertStringContainsString( 'dokan-dashboard-wrap', $html );
			$this->assertStringContainsString( 'dokan-dashboard-content', $html );
			$this->assertContains( 'ai-assistant', apply_filters( 'dokan_query_var_filter', array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}
		$this->assertStringContainsString( 'id="dev3va-vendor-root"', $html );
		$this->assertStringContainsString( 'dev3va-skin-' . $this->marketplace(), $html );
		$this->assertTrue( wp_script_is( 'dev3-ai-vendor-assistant-vendor', 'enqueued' ) || ! file_exists( DEV3VA_PATH . 'build/vendor.asset.php' ) );
	}

	public function test_new_product_status_follows_the_marketplace() {
		$vendor  = $this->vendor();
		$adapter = Manager::current();
		if ( 'wcfm' === $this->marketplace() ) {
			$this->assertSame( 'publish', $adapter->new_product_status( $vendor ) );
			get_user_by( 'id', $vendor )->add_cap( 'publish_products', false );
			$this->assertSame( 'pending', $adapter->new_product_status( $vendor ) );
		} else {
			update_option( 'dokan_selling', array( 'product_status' => 'pending' ) );
			$this->assertSame( 'pending', $adapter->new_product_status( $vendor ) );
			update_option( 'dokan_selling', array( 'product_status' => 'publish' ) );
			$this->assertSame( 'publish', $adapter->new_product_status( $vendor ) );
		}
	}

	public function test_shortcut_links_only_for_own_products() {
		$a       = $this->vendor( 'A' );
		$b       = $this->vendor( 'B' );
		$pa      = $this->product( $a );
		$pb      = $this->product( $b );
		$adapter = Manager::current();
		wp_set_current_user( $a );
		ob_start();
		\Dev3\AiVendorAssistant\Marketplace\Shortcuts::render( $adapter, $pa, $adapter->id() );
		$own = ob_get_clean();
		ob_start();
		\Dev3\AiVendorAssistant\Marketplace\Shortcuts::render( $adapter, $pb, $adapter->id() );
		$other = ob_get_clean();
		$this->assertStringContainsString( 'listing/' . $pa, $own );
		$this->assertStringContainsString( 'pricing/' . $pa, $own );
		$this->assertSame( '', $other );
	}
}
