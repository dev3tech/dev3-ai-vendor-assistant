<?php
/**
 * Vendors only ever see their own data: capability, nonce, feature and ownership checks on every
 * vendor route.
 *
 * @package Dev3\AiVendorAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiVendorAssistant\Core3\Core\Settings_Store;
use Dev3\AiVendorAssistant\Data\Drafts;

class IsolationTest extends Base_Case {

	/**
	 * Vendor routes (method, route, body).
	 *
	 * @return array
	 */
	private function routes() {
		return array(
			array( 'GET', 'vendor/me', array() ),
			array( 'GET', 'vendor/products', array() ),
			array( 'POST', 'vendor/listing/draft', array( 'notes' => 'Mug' ) ),
			array( 'POST', 'vendor/price', array( 'title' => 'Mug' ) ),
			array( 'GET', 'vendor/messages', array() ),
			array( 'GET', 'vendor/profile', array() ),
			array( 'GET', 'vendor/insights', array() ),
			array( 'GET', 'vendor/history', array() ),
		);
	}

	public function test_logged_out_users_and_customers_are_refused() {
		foreach ( $this->routes() as $r ) {
			$this->assertSame( 401, $this->call( $r[0], $r[1], $r[2] )->get_status(), $r[1] );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		foreach ( $this->routes() as $r ) {
			$this->assertSame( 403, $this->call( $r[0], $r[1], $r[2] )->get_status(), $r[1] );
		}
		if ( 'wcfm' === $this->marketplace() ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
			$this->assertSame( 403, $this->call( 'GET', 'vendor/me' )->get_status(), 'Shop managers are not WCFM vendors' );
		} else {
			// Dokan treats admins/shop managers as vendors of their own store; they still only see their own data.
			$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
			$this->product( $this->vendor( 'Other' ) );
			wp_set_current_user( $manager );
			$this->assertSame( array(), $this->call( 'GET', 'vendor/products' )->get_data()['rows'] );
		}
	}

	public function test_nonce_is_required() {
		wp_set_current_user( $this->vendor() );
		$res = $this->call( 'GET', 'vendor/me', array(), false );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'dev3va_bad_nonce', $res->get_data()['code'] );
		$this->assertSame( 200, $this->call( 'GET', 'vendor/me' )->get_status() );
	}

	public function test_access_switch_and_feature_switches() {
		$vendor = $this->vendor();
		wp_set_current_user( $vendor );
		update_user_meta( $vendor, '_dev3va_access', '0' );
		$this->assertSame( 403, $this->call( 'GET', 'vendor/me' )->get_status() );
		delete_user_meta( $vendor, '_dev3va_access' );

		Settings_Store::update( array( 'features' => array( 'price' => false ) ) );
		$res = $this->call( 'POST', 'vendor/price', array( 'title' => 'Mug' ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'dev3va_feature_off', $res->get_data()['code'] );
		$this->assertFalse( $this->call( 'GET', 'vendor/me' )->get_data()['features']['price'] );

		update_user_meta( $vendor, '_dev3va_features', array( 'price' => 'on' ) );
		$this->assertSame( 200, $this->call( 'POST', 'vendor/price', array( 'title' => 'Mug', 'mode' => 'basic' ) )->get_status(), 'Per-vendor override wins' );
		update_user_meta( $vendor, '_dev3va_features', array( 'reply' => 'off' ) );
		Settings_Store::update( array( 'features' => array( 'price' => true ) ) );
		$this->assertSame( 403, $this->call( 'GET', 'vendor/messages' )->get_status() );
	}

	public function test_vendor_cannot_touch_another_vendors_products_photos_or_drafts() {
		$a  = $this->vendor( 'A' );
		$b  = $this->vendor( 'B' );
		$pb = $this->product( $b, array( 'name' => 'B secret mug' ) );
		$ph = $this->photo( $b );
		wp_set_current_user( $b );
		$draft = $this->call(
			'POST',
			'vendor/listing/draft',
			array(
				'notes' => 'B notes',
				'mode'  => 'basic',
			)
		)->get_data();
		$this->assertArrayHasKey( 'draft_id', $draft );

		wp_set_current_user( $a );
		$this->assertSame( array(), $this->call( 'GET', 'vendor/products' )->get_data()['rows'] );
		$checks = array(
			array( 'POST', 'vendor/listing/draft', array( 'product_id' => $pb, 'mode' => 'basic' ) ),
			array( 'POST', 'vendor/listing/draft', array( 'photos' => array( $ph ), 'notes' => 'x', 'mode' => 'basic' ) ),
			array( 'POST', 'vendor/listing/apply', array( 'draft_id' => $draft['draft_id'], 'fields' => array( 'title' => 'Stolen' ) ) ),
			array( 'POST', 'vendor/price', array( 'product_id' => $pb, 'mode' => 'basic' ) ),
			array( 'POST', 'vendor/price/apply', array( 'product_id' => $pb, 'price' => 1 ) ),
			array( 'POST', 'vendor/drafts/' . $draft['draft_id'] . '/reject', array() ),
		);
		foreach ( $checks as $c ) {
			$res = $this->call( $c[0], $c[1], $c[2] );
			$this->assertSame( 404, $res->get_status(), $c[1] . ' ' . wp_json_encode( $c[2] ) );
		}
		$this->assertSame( '24', wc_get_product( $pb )->get_regular_price(), 'Price untouched' );
		$this->assertSame( 'pending', Drafts::get( $draft['draft_id'] )['status'], 'Draft untouched' );
		$history = $this->call( 'GET', 'vendor/history' )->get_data();
		$this->assertSame( 0, $history['total'] );
	}

	public function test_vendor_cannot_read_or_answer_another_vendors_messages() {
		$a     = $this->vendor( 'A' );
		$b     = $this->vendor( 'B' );
		$pb    = $this->product( $b );
		$buyer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$c     = self::factory()->comment->create(
			array(
				'comment_post_ID' => $pb,
				'comment_type'    => 'review',
				'comment_author'  => 'Ana Silva',
				'user_id'         => $buyer,
				'comment_content' => 'Private feedback for B',
			)
		);
		$order = $this->order( $pb, 'Leave at the back door' );

		wp_set_current_user( $a );
		$inbox = $this->call( 'GET', 'vendor/messages' )->get_data();
		$this->assertSame( array(), $inbox['items'] );
		foreach ( array( array( 'reviews', $c ), array( 'order_notes', $order->get_id() ) ) as $m ) {
			$this->assertSame( 404, $this->call( 'POST', 'vendor/messages/draft', array( 'source' => $m[0], 'id' => $m[1], 'mode' => 'basic' ) )->get_status() );
			$this->assertSame( 404, $this->call( 'POST', 'vendor/messages/send', array( 'source' => $m[0], 'id' => $m[1], 'reply' => 'Hi' ) )->get_status() );
		}
		$this->assertCount( 0, get_comments( array( 'parent' => $c ) ) );

		wp_set_current_user( $b );
		$inbox = $this->call( 'GET', 'vendor/messages' )->get_data();
		$this->assertCount( 2, $inbox['items'] );
		$this->assertSame( 'Ana', $inbox['items'][0]['buyer'], 'Only the first name is exposed' );
		$this->assertStringNotContainsString( 'ana@example.org', wp_json_encode( $inbox ) );
	}

	public function test_staff_work_on_their_vendors_data_only() {
		$vendor = $this->vendor( 'A' );
		$other  = $this->vendor( 'B' );
		$staff  = $this->staff( $vendor );
		$p      = $this->product( $vendor );
		$po     = $this->product( $other );
		wp_set_current_user( $staff );
		$me = $this->call( 'GET', 'vendor/me' )->get_data();
		$this->assertSame( $vendor, $me['vendor_id'] );
		$this->assertSame( array( $p ), wp_list_pluck( $this->call( 'GET', 'vendor/products' )->get_data()['rows'], 'id' ) );
		$this->assertSame( 404, $this->call( 'POST', 'vendor/price/apply', array( 'product_id' => $po, 'price' => 5 ) )->get_status() );
		$this->assertSame( 200, $this->call( 'POST', 'vendor/price/apply', array( 'product_id' => $p, 'price' => 30 ) )->get_status() );
		$this->assertSame( '30', wc_get_product( $p )->get_regular_price() );

		$photo = $this->photo( $staff );
		$res   = $this->call( 'POST', 'vendor/listing/draft', array( 'photos' => array( $photo ), 'notes' => 'Mug', 'mode' => 'basic' ) );
		$this->assertSame( 200, $res->get_status(), 'Photos uploaded by staff belong to the vendor' );
	}

	public function test_admin_routes_are_admin_only() {
		wp_set_current_user( $this->vendor() );
		foreach ( array( 'admin/overview', 'admin/vendors', 'admin/ledger', 'admin/moderation' ) as $route ) {
			$this->assertSame( 403, $this->call( 'GET', $route )->get_status(), $route );
		}
		$this->assertSame( 403, $this->call( 'POST', 'admin/vendors/1', array( 'quota' => 9999 ) )->get_status() );
	}
}
