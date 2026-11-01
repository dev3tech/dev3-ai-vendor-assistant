<?php
/**
 * Per-vendor monthly credits and the ledger.
 *
 * @package Dev3\AiVendorAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiVendorAssistant\Core3\Core\Cache;
use Dev3\AiVendorAssistant\Core3\Core\Settings_Store;
use Dev3\AiVendorAssistant\Data\Ledger;
use Dev3\AiVendorAssistant\Vendor\Quota;

class QuotaTest extends Base_Case {

	private function reply_payload() {
		return array(
			'reply'     => 'Hi Ana, thanks for your message.',
			'intent'    => 'shipping',
			'sentiment' => 'neutral',
			'summary'   => 'Asks about delivery',
		);
	}

	public function test_credits_are_charged_by_feature_weight_and_logged() {
		Settings_Store::update( array( 'default_credits' => 10 ) );
		$vendor = $this->vendor();
		wp_set_current_user( $vendor );
		$this->replies = array( $this->reply_payload() );
		$res = $this->call( 'POST', 'vendor/messages/draft', array( 'source' => 'manual', 'text' => 'When will it ship?' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['ai'] );
		$this->assertSame( 'Hi Ana, thanks for your message.', $res->get_data()['draft']['reply'] );

		$this->replies = array(
			array(
				'title'             => 'Speckled mug',
				'short_description' => 'Short',
				'description'       => '<p>Long</p>',
				'tags'              => array( 'mug' ),
			),
		);
		$this->call( 'POST', 'vendor/listing/draft', array( 'notes' => 'Speckled mug' ) );
		$status = Quota::status( $vendor );
		$this->assertSame( 3, $status['used'], 'reply 1 + listing 2' );
		$this->assertSame( 7, $status['remaining'] );
		$rows = Ledger::query( array( 'vendor_id' => $vendor ) )['rows'];
		$this->assertCount( 2, $rows );
		$this->assertSame( 'listing', $rows[0]['feature'] );
		$this->assertSame( 2, $rows[0]['credits'] );
		$this->assertSame( 200, $rows[0]['tokens'] );
		$this->assertGreaterThan( 0, $rows[0]['cost'] );
	}

	public function test_cached_answers_are_free() {
		$vendor = $this->vendor();
		$this->product( $vendor );
		wp_set_current_user( $vendor );
		$this->replies = array(
			array(
				'headline' => 'Steady month',
				'tips'     => array(
					array(
						'title'    => 'Add photos',
						'detail'   => 'x',
						'priority' => 'high',
						'action'   => 'listing',
					),
				),
			),
		);
		$this->assertSame( 200, $this->call( 'POST', 'vendor/insights/ai' )->get_status() );
		Cache::reset_memo();
		$second = $this->call( 'POST', 'vendor/insights/ai' )->get_data();
		$this->assertTrue( $second['meta']['cached'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 1, Quota::status( $vendor )['used'] );
		$rows = Ledger::query( array( 'vendor_id' => $vendor ) )['rows'];
		$this->assertTrue( $rows[0]['cached'] );
		$this->assertSame( 0, $rows[0]['credits'] );
	}

	public function test_quota_reached_blocks_ai_but_basic_helpers_keep_working() {
		Settings_Store::update( array( 'default_credits' => 1 ) );
		$vendor = $this->vendor();
		wp_set_current_user( $vendor );
		$this->replies = array( $this->reply_payload() );
		$this->call( 'POST', 'vendor/messages/draft', array( 'source' => 'manual', 'text' => 'Hello?' ) );
		$this->assertSame( 0, Quota::status( $vendor )['remaining'] );

		// Insights has no basic fallback inside the AI route: it returns the error envelope.
		$this->product( $vendor );
		$res = $this->call( 'POST', 'vendor/insights/ai' );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( 'dev3va_quota_reached', $res->get_data()['code'] );

		// Drafting features fall back to the basic helper and say why.
		$res = $this->call( 'POST', 'vendor/messages/draft', array( 'source' => 'manual', 'text' => 'Where is my parcel?' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $res->get_data()['ai'] );
		$this->assertSame( 'dev3va_quota_reached', $res->get_data()['fallback']['code'] );
		$this->assertSame( 'shipping', $res->get_data()['draft']['intent'] );
		$this->assertCount( 1, $this->requests, 'No Gemini call after the quota was used' );

		// The admin raises the limit: it applies immediately.
		Quota::set_limit( $vendor, 5 );
		$this->assertSame( 5, Quota::limit( $vendor ) );
		$this->assertTrue( $this->call( 'POST', 'vendor/messages/draft', array( 'source' => 'manual', 'text' => 'Hi again' ) )->get_data()['ai'] );
		Quota::set_limit( $vendor, '' );
		$this->assertSame( 1, Quota::limit( $vendor ), 'Clearing the override restores the default' );
	}

	public function test_zero_means_unlimited_and_weights_are_configurable() {
		Settings_Store::update(
			array(
				'default_credits' => 0,
				'credit_weights'  => array( 'reply' => 3 ),
			)
		);
		$vendor = $this->vendor();
		$this->assertTrue( Quota::status( $vendor )['unlimited'] );
		$this->assertNull( Quota::check( $vendor, 'listing' ) );
		$this->assertSame( 3, Quota::weight( 'reply' ) );
		add_filter( 'dev3va_feature_credits', static fn( $w, $f ) => 'price' === $f ? 0 : $w, 10, 2 );
		$this->assertSame( 0, Quota::weight( 'price' ) );
	}

	public function test_ai_off_and_missing_key_fall_back_without_charging() {
		$vendor = $this->vendor();
		wp_set_current_user( $vendor );
		Settings_Store::update( array( 'ai_enabled' => false ) );
		$res = $this->call( 'POST', 'vendor/listing/draft', array( 'notes' => "Speckled mug\nCapacity: 350 ml" ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'dev3va_ai_disabled', $res->get_data()['fallback']['code'] );
		$this->assertSame( 'Speckled mug', $res->get_data()['draft']['title'] );
		$this->assertFalse( $this->call( 'GET', 'vendor/me' )->get_data()['ai']['available'] );
		$this->assertSame( 0, Quota::status( $vendor )['used'] );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 0, Ledger::query( array( 'vendor_id' => $vendor ) )['total'] );
	}
}
