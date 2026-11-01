<?php
/**
 * Price suggestions from comparables, buyer-message sources and reply sending.
 *
 * @package Dev3\AiVendorAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiVendorAssistant\Features\Pricing;
use Dev3\AiVendorAssistant\Messages\Inbox;

class PricingMessagesTest extends Base_Case {

	public function test_percentiles_and_basic_goals() {
		$stats = Pricing::stats( array( 10, 20, 30, 40, 0, -5 ) );
		$this->assertSame( 4, $stats['count'] );
		$this->assertEquals( 10, $stats['min'] );
		$this->assertEquals( 17.5, $stats['p25'] );
		$this->assertEquals( 25, $stats['median'] );
		$this->assertEquals( 32.5, $stats['p75'] );
		$this->assertEquals( 25, Pricing::basic( $stats, 'competitive' )['price'] );
		$this->assertEquals( 32.5, Pricing::basic( $stats, 'premium' )['price'] );
		$this->assertEquals( 17.5, Pricing::basic( $stats, 'quick_sale' )['price'] );
		$this->assertNull( Pricing::basic( Pricing::stats( array() ), 'competitive' ) );
		$clamped = Pricing::clamp( array( 'price' => 900, 'low' => 1, 'high' => 1000, 'confidence' => 'sure' ), $stats );
		$this->assertEquals( 60, $clamped['price'], 'AI prices stay within 0.5x min - 1.5x max' );
		$this->assertEquals( 5, $clamped['low'] );
		$this->assertSame( 'medium', $clamped['confidence'] );
	}

	public function test_comparables_use_public_listings_only() {
		$cat  = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Mugs' ) );
		$a    = $this->vendor( 'A' );
		$b    = $this->vendor( 'B' );
		$mine = $this->product( $a, array( 'name' => 'Speckled stoneware mug', 'price' => '24', 'cat' => $cat ) );
		$this->product( $b, array( 'name' => 'Blue stoneware mug', 'price' => '20', 'cat' => $cat ) );
		$this->product( $b, array( 'name' => 'Large stoneware mug', 'price' => '30', 'cat' => $cat ) );
		$this->product( $b, array( 'name' => 'Draft mug', 'price' => '99', 'cat' => $cat, 'status' => 'draft' ) );
		$this->product( $b, array( 'name' => 'Free mug', 'price' => '0', 'cat' => $cat ) );
		update_post_meta( $mine, 'total_sales', 50 );
		wp_set_current_user( $a );
		$res = $this->call( 'POST', 'vendor/price', array( 'product_id' => $mine, 'mode' => 'basic' ) );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertEqualsCanonicalizing( array( 'Blue stoneware mug', 'Large stoneware mug' ), wp_list_pluck( $data['comparables'], 'title' ) );
		$this->assertSame( 'B', $data['comparables'][0]['store'] );
		$this->assertArrayNotHasKey( 'total_sales', $data['comparables'][0] );
		$this->assertArrayNotHasKey( 'score', $data['comparables'][0] );
		$this->assertEquals( 25, $data['suggestion']['price'] );
		$this->assertFalse( $data['ai'] );

		$this->replies = array( array( 'price' => 27.5, 'low' => 22, 'high' => 31, 'rationale' => 'Handmade sells higher.', 'confidence' => 'high' ) );
		$ai            = $this->call( 'POST', 'vendor/price', array( 'product_id' => $mine, 'goal' => 'premium' ) )->get_data();
		$this->assertTrue( $ai['ai'] );
		$this->assertEquals( 27.5, $ai['suggestion']['price'] );
		$prompt = wp_json_encode( $this->requests[0]['body'] );
		$this->assertStringContainsString( 'Blue stoneware mug', $prompt );
		$this->assertStringNotContainsString( 'Draft mug', $prompt );
		$this->assertStringContainsString( 'premium', $prompt );

		$apply = $this->call( 'POST', 'vendor/price/apply', array( 'product_id' => $mine, 'price' => 27.5, 'draft_id' => $ai['draft_id'] ) );
		$this->assertSame( 200, $apply->get_status() );
		$this->assertSame( '27.5', wc_get_product( $mine )->get_regular_price() );
		$this->assertSame( 400, $this->call( 'POST', 'vendor/price/apply', array( 'product_id' => $mine, 'price' => 0 ) )->get_status() );
	}

	public function test_reviews_inbox_reply_and_unanswered_filter() {
		$vendor = $this->vendor();
		$pid    = $this->product( $vendor );
		$buyer  = self::factory()->user->create( array( 'role' => 'customer' ) );
		$review = self::factory()->comment->create(
			array(
				'comment_post_ID' => $pid,
				'comment_type'    => 'review',
				'comment_author'  => 'Ana Silva',
				'user_id'         => $buyer,
				'comment_content' => 'Lovely, but it took two weeks to arrive.',
			)
		);
		update_comment_meta( $review, 'rating', 4 );
		wp_set_current_user( $vendor );
		$inbox = $this->call( 'GET', 'vendor/messages', array( 'filter' => 'unanswered' ) )->get_data();
		$this->assertSame( 1, $inbox['counts']['unanswered'] );
		$this->assertSame( 4, $inbox['items'][0]['rating'] );

		$this->replies = array( array( 'reply' => 'Hi Ana, sorry for the wait!', 'intent' => 'shipping', 'sentiment' => 'neutral', 'summary' => 'Slow delivery' ) );
		$draft         = $this->call( 'POST', 'vendor/messages/draft', array( 'source' => 'reviews', 'id' => $review, 'instructions' => 'Mention faster shipping now' ) )->get_data();
		$this->assertTrue( $draft['can_send'] );
		$prompt = wp_json_encode( $this->requests[0]['body'] );
		$this->assertStringContainsString( 'Mention faster shipping now', $prompt );
		$this->assertStringContainsString( 'Speckled stoneware mug', $prompt );
		$this->assertStringNotContainsString( 'Silva', $prompt, 'Only the first name goes to the AI' );

		$sent = $this->call( 'POST', 'vendor/messages/send', array( 'source' => 'reviews', 'id' => $review, 'reply' => 'Hi Ana, sorry for the wait!', 'draft_id' => $draft['draft_id'] ) );
		$this->assertSame( 200, $sent->get_status() );
		$this->assertTrue( $sent->get_data()['replied'] );
		$children = get_comments( array( 'parent' => $review ) );
		$this->assertCount( 1, $children );
		$this->assertSame( $vendor, (int) $children[0]->user_id );
		$this->assertSame( 'review', $children[0]->comment_type );
		$this->assertSame( 'Clay & Co', $children[0]->comment_author );
		$this->assertSame( 0, $this->call( 'GET', 'vendor/messages', array( 'filter' => 'unanswered' ) )->get_data()['counts']['unanswered'] );
		$this->assertSame( 400, $this->call( 'POST', 'vendor/messages/send', array( 'source' => 'reviews', 'id' => $review, 'reply' => '' ) )->get_status() );
	}

	public function test_order_notes_reply_as_customer_note() {
		$vendor = $this->vendor();
		$pid    = $this->product( $vendor );
		$order  = $this->order( $pid, 'Can you ship before Friday?' );
		$this->order( $pid, '' );
		wp_set_current_user( $vendor );
		$items = wp_list_filter( $this->call( 'GET', 'vendor/messages' )->get_data()['items'], array( 'source' => 'order_notes' ) );
		$this->assertCount( 1, $items );
		$item = reset( $items );
		$this->assertSame( $order->get_id(), $item['order_id'] );
		$this->assertSame( 'Ana', $item['buyer'] );

		$basic = $this->call( 'POST', 'vendor/messages/draft', array( 'source' => 'order_notes', 'id' => $order->get_id(), 'mode' => 'basic' ) )->get_data();
		$this->assertSame( 'shipping', $basic['draft']['intent'] );
		$this->assertStringContainsString( 'Hi Ana,', $basic['draft']['reply'] );

		$this->call( 'POST', 'vendor/messages/send', array( 'source' => 'order_notes', 'id' => $order->get_id(), 'reply' => 'Yes, it ships Thursday.' ) );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id(), 'type' => 'customer' ) );
		$this->assertSame( 'Yes, it ships Thursday.', $notes[0]->content );
		$this->assertTrue( wc_get_order( $order->get_id() )->get_meta( '_dev3va_reply' ) === 'Yes, it ships Thursday.' );
	}

	public function test_wcfm_inquiries_or_dokan_pro_support_source() {
		global $wpdb;
		$vendor = $this->vendor();
		$other  = $this->vendor( 'Other' );
		$pid    = $this->product( $vendor );
		if ( 'wcfm' === $this->marketplace() ) {
			$wpdb->insert(
				$wpdb->prefix . 'wcfm_enquiries',
				array(
					'enquiry'        => 'Is the glaze food safe?',
					'reply'          => '',
					'product_id'     => $pid,
					'author_id'      => $vendor,
					'vendor_id'      => $vendor,
					'customer_id'    => 0,
					'customer_name'  => 'Ben Ode',
					'customer_email' => 'ben@example.org',
				)
			);
			$id     = (int) $wpdb->insert_id;
			$source = 'wcfm_inquiries';
		} else {
			// Dokan Pro "Store Support" stub: same post type and meta keys as the Pro module.
			register_post_type( 'dokan_store_support', array( 'public' => false ) );
			$buyer = self::factory()->user->create( array( 'role' => 'customer', 'first_name' => 'Ben' ) );
			$id    = self::factory()->post->create(
				array(
					'post_type'    => 'dokan_store_support',
					'post_title'   => 'Food safe glaze?',
					'post_content' => 'Is the glaze food safe?',
					'post_author'  => $buyer,
					'post_status'  => 'open',
				)
			);
			update_post_meta( $id, 'store_id', $vendor );
			$source = 'dokan_support';
		}
		wp_set_current_user( $other );
		$this->assertSame( 404, $this->call( 'POST', 'vendor/messages/send', array( 'source' => $source, 'id' => $id, 'reply' => 'x' ) )->get_status() );
		wp_set_current_user( $vendor );
		$items = wp_list_filter( $this->call( 'GET', 'vendor/messages' )->get_data()['items'], array( 'source' => $source ) );
		$this->assertCount( 1, $items );
		$this->assertSame( 'Ben', reset( $items )['buyer'] );
		reset_phpmailer_instance();
		$res = $this->call( 'POST', 'vendor/messages/send', array( 'source' => $source, 'id' => $id, 'reply' => 'Yes, it is lead-free and food safe.' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['replied'] );
		if ( 'wcfm' === $this->marketplace() ) {
			$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wcfm_enquiries_response WHERE enquiry_id = %d", $id ) ) );
			$this->assertSame( 'ben@example.org', tests_retrieve_phpmailer_instance()->get_recipient( 'to' )->address );
		} else {
			$this->assertCount( 1, get_comments( array( 'post_id' => $id ) ) );
		}
	}

	public function test_manual_message_and_intent_detection() {
		$this->assertSame( 'returns', Inbox::intent( 'I want a refund, it arrived broken' ) );
		$this->assertSame( 'sizing', Inbox::intent( 'Does it fit a large hand?' ) );
		$this->assertSame( 'praise', Inbox::intent( 'Love it!' ) );
		$this->assertSame( 'product_question', Inbox::intent( 'Is it microwave safe?' ) );
		$vendor = $this->vendor();
		wp_set_current_user( $vendor );
		$res = $this->call( 'POST', 'vendor/messages/draft', array( 'source' => 'manual', 'text' => 'Do you restock the blue mug?', 'buyer' => 'Cleo Grant', 'mode' => 'basic' ) )->get_data();
		$this->assertFalse( $res['can_send'] );
		$this->assertSame( 'stock', $res['draft']['intent'] );
		$this->assertStringContainsString( 'Hi Cleo,', $res['draft']['reply'] );
		$this->assertSame( 400, $this->call( 'POST', 'vendor/messages/draft', array( 'source' => 'manual', 'text' => '' ) )->get_status() );
	}
}
