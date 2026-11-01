<?php
/**
 * Store profile, performance insights, admin routes and privacy tools.
 *
 * @package Dev3\AiVendorAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiVendorAssistant\Core3\Core\Settings_Store;
use Dev3\AiVendorAssistant\Data\Ledger;
use Dev3\AiVendorAssistant\Features\Insights;
use Dev3\AiVendorAssistant\Marketplace\Manager;

class ProfileInsightsAdminTest extends Base_Case {

	public function test_profile_draft_and_apply_write_marketplace_fields() {
		$cat    = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Mugs' ) );
		$vendor = $this->vendor();
		$this->product( $vendor, array( 'cat' => $cat ) );
		wp_set_current_user( $vendor );
		$basic = $this->call( 'POST', 'vendor/profile/draft', array( 'mode' => 'basic', 'notes' => 'Family workshop.' ) )->get_data();
		$this->assertFalse( $basic['ai'] );
		$this->assertStringContainsString( 'Clay &amp; Co', $basic['draft']['bio'] );
		$this->assertStringContainsString( 'Family workshop.', $basic['draft']['bio'] );
		$this->assertStringContainsString( 'mugs', $basic['draft']['seo_keywords'] );

		$this->replies = array( array( 'bio' => 'We make mugs.', 'tagline' => 'Mugs by hand', 'seo_title' => 'Clay & Co | Mugs', 'seo_description' => 'Handmade mugs from Porto.', 'keywords' => array( 'mugs', 'ceramics' ) ) );
		$ai            = $this->call( 'POST', 'vendor/profile/draft', array( 'notes' => 'Porto' ) )->get_data();
		$this->assertTrue( $ai['ai'] );
		$this->assertStringContainsString( 'Mugs', wp_json_encode( $this->requests[0]['body'] ), 'Categories go to the prompt' );
		$res = $this->call( 'POST', 'vendor/profile/apply', array( 'draft_id' => $ai['draft_id'], 'fields' => $ai['draft'] ) );
		$this->assertSame( 200, $res->get_status() );
		$profile = Manager::current()->get_profile( $vendor );
		$this->assertSame( 'Handmade mugs from Porto.', $profile['seo_description'] );
		$this->assertSame( 'mugs, ceramics', $profile['seo_keywords'] );
		$this->assertSame( 'Mugs by hand', $profile['tagline'] );
		$this->call( 'POST', 'vendor/profile/apply', array( 'fields' => array( 'policies' => 'Returns within 30 days.' ) ) );
		$this->assertSame( 'Returns within 30 days.', get_user_meta( $vendor, '_dev3va_policies', true ) );
		$other = $this->vendor( 'Other' );
		wp_set_current_user( $other );
		$this->assertSame( 404, $this->call( 'POST', 'vendor/profile/apply', array( 'draft_id' => $ai['draft_id'], 'fields' => array( 'tagline' => 'x' ) ) )->get_status() );
		$this->assertSame( '', Manager::current()->get_profile( $other )['tagline'], 'Nothing saved for a foreign draft' );
	}

	public function test_insights_compare_periods_and_find_issues() {
		$vendor = $this->vendor();
		$other  = $this->vendor( 'Other' );
		$mug    = $this->product( $vendor, array( 'name' => 'Mug', 'price' => '20' ) );
		$bowl   = $this->product( $vendor, array( 'name' => 'Bowl', 'price' => '' ) );
		$foreign = $this->product( $other, array( 'name' => 'Foreign', 'price' => '100' ) );
		$p = wc_get_product( $mug );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 2 );
		$p->save();
		$this->order( $mug, '', 'completed', 3 );
		$old = $this->order( $mug, '', 'completed', 1 );
		$old->set_date_created( time() - 40 * DAY_IN_SECONDS );
		$old->save();
		$this->order( $mug, '', 'cancelled', 5 );
		$this->order( $foreign, '', 'completed', 1 );

		$report = Insights::report( $vendor );
		$s      = $report['stats'];
		$this->assertSame( 1, $s['orders'] );
		$this->assertEquals( 60, $s['revenue'] );
		$this->assertEquals( 20, $s['revenue_prev'] );
		$this->assertEquals( 200, $s['revenue_change'] );
		$this->assertSame( 'Mug', $s['top_products'][0]['title'] );
		$this->assertSame( 3, $s['top_products'][0]['qty'] );
		$this->assertSame( 'Mug', $s['low_stock'][0]['title'] );
		$issues = wp_list_pluck( $s['quality'], 'issues', 'title' );
		$this->assertContains( 'no_price', $issues['Bowl'] );
		$this->assertContains( 'no_image', $issues['Mug'] );
		$this->assertStringNotContainsString( 'Foreign', wp_json_encode( $report ) );
		$titles = wp_list_pluck( $report['tips'], 'title' );
		$this->assertNotEmpty( preg_grep( '/no photo/', $titles ) );
		$this->assertNotEmpty( preg_grep( '/no price/', $titles ) );

		wp_set_current_user( $vendor );
		$this->replies = array( array( 'headline' => 'Mug sales tripled', 'tips' => array( array( 'title' => 'Restock Mug', 'detail' => 'Only 2 left.', 'priority' => 'high', 'action' => 'bogus' ) ) ) );
		$ai            = $this->call( 'POST', 'vendor/insights/ai' )->get_data();
		$this->assertSame( 'Mug sales tripled', $ai['summary']['headline'] );
		$this->assertSame( 'none', $ai['summary']['tips'][0]['action'], 'Unknown actions are dropped' );
		$this->assertStringNotContainsString( 'ana@example.org', wp_json_encode( $this->requests[0]['body'] ), 'No buyer data in the prompt' );
	}

	public function test_admin_overview_vendors_update_and_ledger_csv() {
		$vendor = $this->vendor();
		Ledger::record( array( 'vendor_id' => $vendor, 'user_id' => $vendor, 'feature' => 'listing', 'credits' => 2, 'model' => 'gemini-2.5-flash', 'prompt_tokens' => 1000, 'output_tokens' => 500 ) );
		Ledger::record( array( 'vendor_id' => $vendor, 'user_id' => $vendor, 'feature' => 'reply', 'credits' => 1, 'model' => '=cmd', 'prompt_tokens' => 10, 'output_tokens' => 5 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$overview = $this->call( 'GET', 'admin/overview' )->get_data();
		$this->assertSame( $this->marketplace(), $overview['marketplace']['id'] );
		$this->assertSame( 3, $overview['totals']['credits'] );
		$this->assertSame( 2, $overview['by_feature']['listing']['credits'] );
		$this->assertSame( 'Clay & Co', $overview['top'][0]['store'] );

		$rows = $this->call( 'GET', 'admin/vendors' )->get_data()['rows'];
		$row  = wp_list_filter( $rows, array( 'id' => $vendor ) );
		$row  = reset( $row );
		$this->assertSame( 3, $row['quota']['used'] );

		$res = $this->call( 'POST', 'admin/vendors/' . $vendor, array( 'access' => false, 'quota' => 250, 'overrides' => array( 'price' => 'off', 'reply' => 'bogus' ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $res->get_data()['access'] );
		$this->assertSame( 250, $res->get_data()['quota']['limit'] );
		$this->assertSame( 'off', $res->get_data()['overrides']['price'] );
		$this->assertSame( 'inherit', $res->get_data()['overrides']['reply'] );
		$this->assertSame( 404, $this->call( 'POST', 'admin/vendors/' . self::factory()->user->create(), array( 'quota' => 1 ) )->get_status() );

		$csv = $this->call( 'GET', 'admin/ledger', array( 'format' => 'csv' ) )->get_data()['csv'];
		$this->assertStringStartsWith( 'date,store,user,feature', $csv );
		$this->assertStringContainsString( "\"'=cmd\"", $csv, 'Formula injection is neutralised' );
		$filtered = $this->call( 'GET', 'admin/ledger', array( 'feature' => 'reply' ) )->get_data();
		$this->assertSame( 1, $filtered['total'] );
	}

	public function test_privacy_export_and_erase() {
		$vendor = $this->vendor();
		$user   = get_userdata( $vendor );
		wp_set_current_user( $vendor );
		$this->call( 'POST', 'vendor/messages/draft', array( 'source' => 'manual', 'text' => 'Hello', 'mode' => 'basic' ) );
		Ledger::record( array( 'vendor_id' => $vendor, 'user_id' => $vendor, 'feature' => 'reply', 'credits' => 1 ) );
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$key       = 'dev3-ai-vendor-assistant-vendor-ai-activity';
		$this->assertArrayHasKey( $key, $exporters );
		$export = call_user_func( $exporters[ $key ]['callback'], $user->user_email, 1 );
		$groups = array_unique( wp_list_pluck( $export['data'], 'group_id' ) );
		$this->assertEqualsCanonicalizing( array( 'dev3va-ledger', 'dev3va-drafts' ), array_values( $groups ) );
		$erase = call_user_func( $erasers[ $key ]['callback'], $user->user_email, 1 );
		$this->assertGreaterThanOrEqual( 2, $erase['items_removed'] );
		$this->assertSame( 0, $this->call( 'GET', 'vendor/history' )->get_data()['total'] );
		$this->assertSame( 1, Ledger::credits_used( $vendor ), 'Vendor credit totals survive anonymisation' );
	}

	public function test_dokan_lite_store_seo_output() {
		if ( 'dokan' !== $this->marketplace() ) {
			$this->assertTrue( Settings_Store::get( 'store_seo_output' ) ); // WCFM prints its own tags.
			return;
		}
		$vendor  = $this->vendor();
		$adapter = Manager::current();
		$adapter->save_profile( $vendor, array( 'bio' => '<p>Hello buyers</p>', 'tagline' => 'Mugs by hand', 'seo_description' => 'Handmade mugs' ) );
		ob_start();
		$adapter->store_bio( get_userdata( $vendor ), get_user_meta( $vendor, 'dokan_profile_settings', true ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Hello buyers', $html );
		$this->assertStringContainsString( 'Mugs by hand', $html );
	}
}
