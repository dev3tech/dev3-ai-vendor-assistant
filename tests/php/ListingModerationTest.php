<?php
/**
 * Listing writer (photos + notes → product) and moderation of AI-written listings.
 *
 * @package Dev3\AiVendorAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiVendorAssistant\Core3\Core\Settings_Store;
use Dev3\AiVendorAssistant\Features\Listing_Writer;
use Dev3\AiVendorAssistant\Features\Moderation;

class ListingModerationTest extends Base_Case {

	private $cat = 0;

	public function set_up() {
		parent::set_up();
		$this->cat = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Mugs',
			)
		);
		if ( 'dokan' === $this->marketplace() ) {
			update_option( 'dokan_selling', array( 'product_status' => 'publish' ) );
		}
	}

	private function ai_listing( array $extra = array() ) {
		return array_merge(
			array(
				'title'             => 'Speckled Stoneware Mug, 350 ml',
				'short_description' => 'A hand-thrown mug with a speckled glaze.',
				'description'       => '<p>Made by hand.</p><script>alert(1)</script><ul><li>350 ml</li></ul>',
				'tags'              => array( 'mug', 'stoneware', 'handmade' ),
				'categories'        => array( 'Mugs', 'Not a real category' ),
				'attributes'        => array(
					array(
						'name'  => 'Capacity',
						'value' => '350 ml',
					),
				),
				'seo_title'         => 'Speckled stoneware mug',
				'seo_description'   => 'Hand-thrown speckled mug, 350 ml.',
				'alt_texts'         => array( 'Speckled mug on a table', 'Extra alt' ),
			),
			$extra
		);
	}

	public function test_ai_draft_sends_photos_and_notes_and_cleans_the_result() {
		$vendor = $this->vendor();
		$photo  = $this->photo( $vendor );
		wp_set_current_user( $vendor );
		$this->replies = array( $this->ai_listing() );
		$res           = $this->call(
			'POST',
			'vendor/listing/draft',
			array(
				'photos' => array( $photo ),
				'notes'  => 'Speckled mug, 350 ml',
				'tone'   => 'luxury',
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertTrue( $data['ai'] );
		$this->assertSame( 'Speckled Stoneware Mug, 350 ml', $data['draft']['title'] );
		$this->assertStringNotContainsString( '<script>', $data['draft']['description'] );
		$this->assertSame( 'mug, stoneware, handmade', $data['draft']['tags'] );
		$this->assertSame( array( $this->cat ), $data['draft']['category_ids'], 'Only existing categories are matched' );
		$this->assertCount( 1, $data['draft']['alt_texts'], 'One alt text per photo' );
		$this->assertSame( 'pending', $data['moderation'] );

		$body  = $this->requests[0]['body'];
		$parts = $body['contents'][0]['parts'];
		$this->assertSame( 'image/png', $parts[1]['inline_data']['mime_type'] ?? $parts[0]['inline_data']['mime_type'] ?? '' );
		$this->assertStringContainsString( 'Speckled mug, 350 ml', wp_json_encode( $body ) );
		$this->assertStringContainsString( 'luxury', wp_json_encode( $body ) );
		$this->assertArrayHasKey( 'responseSchema', $body['generationConfig'] );
	}

	public function test_apply_creates_a_vendor_owned_pending_product_for_review() {
		$vendor = $this->vendor();
		$photo  = $this->photo( $vendor );
		$photo2 = $this->photo( $vendor );
		wp_set_current_user( $vendor );
		$this->replies = array( $this->ai_listing() );
		$draft         = $this->call( 'POST', 'vendor/listing/draft', array( 'photos' => array( $photo, $photo2 ), 'notes' => 'Mug' ) )->get_data();
		$res           = $this->call(
			'POST',
			'vendor/listing/apply',
			array(
				'draft_id' => $draft['draft_id'],
				'fields'   => array(
					'title' => 'Edited mug title',
					'price' => 26,
				),
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$out     = $res->get_data();
		$product = wc_get_product( $out['product_id'] );
		$this->assertTrue( $out['created'] );
		$this->assertSame( 'Edited mug title', $product->get_name() );
		$this->assertSame( 'pending', $product->get_status(), 'Review mode holds AI listings' );
		$this->assertSame( $vendor, (int) get_post_field( 'post_author', $product->get_id() ) );
		$this->assertSame( '26', $product->get_regular_price() );
		$this->assertSame( $photo, (int) $product->get_image_id() );
		$this->assertSame( array( $photo2 ), array_map( 'intval', $product->get_gallery_image_ids() ) );
		$this->assertSame( array( $this->cat ), $product->get_category_ids() );
		$this->assertSame( '350 ml', $product->get_attribute( 'Capacity' ) );
		$this->assertEqualsCanonicalizing( array( 'mug', 'stoneware', 'handmade' ), wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) ) );
		$this->assertSame( 'Speckled mug on a table', get_post_meta( $photo, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'pending', get_post_meta( $product->get_id(), Moderation::META, true ) );
		$this->assertSame( '1', get_post_meta( $product->get_id(), Moderation::META_AI, true ) );
		$this->assertSame( 'Speckled stoneware mug', get_post_meta( $product->get_id(), Moderation::META_SEO_T, true ) );

		$again = $this->call( 'POST', 'vendor/listing/apply', array( 'draft_id' => $draft['draft_id'] ) );
		$this->assertSame( 409, $again->get_status(), 'A draft is used once' );
	}

	public function test_flag_and_off_modes_and_basic_listings() {
		$vendor = $this->vendor();
		wp_set_current_user( $vendor );
		Settings_Store::update( array( 'moderation_mode' => 'flag' ) );
		$this->replies = array( $this->ai_listing() );
		$draft         = $this->call( 'POST', 'vendor/listing/draft', array( 'notes' => 'Mug' ) )->get_data();
		$out           = $this->call( 'POST', 'vendor/listing/apply', array( 'draft_id' => $draft['draft_id'] ) )->get_data();
		$this->assertSame( 'publish', get_post_status( $out['product_id'] ), 'Flag mode publishes with the marketplace status' );
		$this->assertSame( 'flagged', get_post_meta( $out['product_id'], Moderation::META, true ) );

		Settings_Store::update( array( 'moderation_mode' => 'review' ) );
		$basic = $this->call( 'POST', 'vendor/listing/draft', array( 'notes' => "Blue bowl\nHand painted in Porto.\nDiameter: 18 cm", 'mode' => 'basic' ) )->get_data();
		$this->assertFalse( $basic['ai'] );
		$this->assertSame( 'Blue bowl', $basic['draft']['title'] );
		$this->assertSame( array( array( 'name' => 'Diameter', 'value' => '18 cm' ) ), $basic['draft']['attributes'] );
		$this->assertStringContainsString( 'Hand painted in Porto.', $basic['draft']['description'] );
		$out = $this->call( 'POST', 'vendor/listing/apply', array( 'draft_id' => $basic['draft_id'] ) )->get_data();
		$this->assertSame( 'none', $out['moderation'], 'Basic (non-AI) listings are not AI-written' );
		$this->assertSame( 'publish', get_post_status( $out['product_id'] ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_blocked_terms_flag_or_block() {
		$vendor = $this->vendor();
		wp_set_current_user( $vendor );
		Settings_Store::update(
			array(
				'blocked_terms'   => "replica\nwhatsapp",
				'moderation_mode' => 'off',
			)
		);
		$this->replies = array( $this->ai_listing( array( 'title' => 'Replica designer mug' ) ) );
		$draft         = $this->call( 'POST', 'vendor/listing/draft', array( 'notes' => 'Mug' ) )->get_data();
		$this->assertSame( array( 'replica' ), $draft['flags'] );
		$out = $this->call( 'POST', 'vendor/listing/apply', array( 'draft_id' => $draft['draft_id'] ) )->get_data();
		$this->assertSame( 'flagged', $out['moderation'] );
		$this->assertSame( array( 'replica' ), get_post_meta( $out['product_id'], Moderation::META_FLAGS, true ) );

		Settings_Store::update( array( 'blocked_action' => 'block' ) );
		$draft = $this->call( 'POST', 'vendor/listing/draft', array( 'notes' => 'Mug' ) )->get_data();
		$res   = $this->call( 'POST', 'vendor/listing/apply', array( 'draft_id' => $draft['draft_id'] ) );
		$this->assertSame( 422, $res->get_status() );
		$this->assertSame( 'dev3va_blocked_terms', $res->get_data()['code'] );
		$ok = $this->call(
			'POST',
			'vendor/listing/apply',
			array(
				'draft_id' => $draft['draft_id'],
				'fields'   => array( 'title' => 'Designer-style mug' ),
			)
		);
		$this->assertSame( 200, $ok->get_status(), 'Editing the word out lets the vendor save' );
	}

	public function test_improving_an_existing_listing_keeps_it_and_shows_the_original() {
		$vendor = $this->vendor();
		$pid    = $this->product( $vendor, array( 'name' => 'Old mug' ) );
		wp_set_current_user( $vendor );
		Settings_Store::update( array( 'moderation_mode' => 'off' ) );
		$this->replies = array( $this->ai_listing() );
		$draft         = $this->call( 'POST', 'vendor/listing/draft', array( 'product_id' => $pid ) )->get_data();
		$this->assertSame( 'Old mug', $draft['original']['title'] );
		$this->assertStringContainsString( 'Old mug', wp_json_encode( $this->requests[0]['body'] ) );
		$out = $this->call( 'POST', 'vendor/listing/apply', array( 'draft_id' => $draft['draft_id'] ) )->get_data();
		$this->assertFalse( $out['created'] );
		$this->assertSame( $pid, $out['product_id'] );
		$this->assertSame( 'publish', get_post_status( $pid ) );
		$this->assertSame( 'Speckled Stoneware Mug, 350 ml', get_the_title( $pid ) );
	}

	public function test_admin_approves_and_rejects_with_a_note() {
		$vendor = $this->vendor();
		wp_set_current_user( $vendor );
		$this->replies = array( $this->ai_listing() );
		$d1            = $this->call( 'POST', 'vendor/listing/draft', array( 'notes' => 'Mug' ) )->get_data();
		$p1            = $this->call( 'POST', 'vendor/listing/apply', array( 'draft_id' => $d1['draft_id'] ) )->get_data()['product_id'];
		$d2            = $this->call( 'POST', 'vendor/listing/draft', array( 'notes' => 'Mug 2' ) )->get_data();
		$p2            = $this->call( 'POST', 'vendor/listing/apply', array( 'draft_id' => $d2['draft_id'] ) )->get_data()['product_id'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$queue = $this->call( 'GET', 'admin/moderation' )->get_data();
		$this->assertSame( 2, $queue['total'] );
		$this->assertSame( 2, $queue['counts']['pending'] );
		$row = $queue['rows'][0];
		$this->assertSame( 'Clay & Co', $row['vendor'] );
		$this->assertTrue( $row['ai_written'] );

		reset_phpmailer_instance();
		$ok = $this->call( 'POST', 'admin/moderation/' . $p1, array( 'action' => 'approve' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'publish', get_post_status( $p1 ) );
		$this->assertSame( 'approved', get_post_meta( $p1, Moderation::META, true ) );
		$this->assertStringContainsString( 'is live', tests_retrieve_phpmailer_instance()->get_sent()->subject );

		$this->assertSame( 400, $this->call( 'POST', 'admin/moderation/' . $p2, array( 'action' => 'reject' ) )->get_status(), 'Reject needs a note' );
		reset_phpmailer_instance();
		$this->call(
			'POST',
			'admin/moderation/' . $p2,
			array(
				'action' => 'reject',
				'note'   => 'Please add real photos.',
			)
		);
		$this->assertSame( 'draft', get_post_status( $p2 ) );
		$this->assertSame( 'rejected', get_post_meta( $p2, Moderation::META, true ) );
		$this->assertStringContainsString( 'Please add real photos.', tests_retrieve_phpmailer_instance()->get_sent()->body );
		$this->assertSame( 0, $this->call( 'GET', 'admin/moderation' )->get_data()['total'] );
		$this->assertSame( 1, $this->call( 'GET', 'admin/moderation', array( 'status' => 'rejected' ) )->get_data()['total'] );

		wp_set_current_user( $vendor );
		$history = $this->call( 'GET', 'vendor/history', array( 'feature' => 'listing' ) )->get_data();
		$rejected = wp_list_filter( $history['rows'], array( 'object_id' => $p2 ) );
		$rejected = reset( $rejected );
		$this->assertSame( 'rejected', $rejected['moderation'] );
		$this->assertSame( 'Please add real photos.', $rejected['note'] );
	}

	public function test_admin_ai_policy_check() {
		$vendor = $this->vendor();
		$pid    = $this->product( $vendor );
		update_post_meta( $pid, Moderation::META, 'flagged' );
		Settings_Store::update( array( 'moderation_policy' => 'No medical claims.' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->replies = array(
			array(
				'verdict' => 'review',
				'summary' => 'Mentions health benefits.',
				'issues'  => array( 'Claims the mug cures colds' ),
			),
		);
		$res = $this->call( 'POST', 'admin/moderation/' . $pid . '/check' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'review', $res->get_data()['verdict'] );
		$this->assertStringContainsString( 'No medical claims.', wp_json_encode( $this->requests[0]['body'] ) );
		$this->assertSame( 'review', get_post_meta( $pid, Moderation::META_CHECK, true )['verdict'] );
	}

	public function test_basic_listing_parser_and_category_matching() {
		$basic = Listing_Writer::basic( "handmade linen apron.\n- Pocket on the front\nColour: sand", null, 2 );
		$this->assertSame( 'Handmade linen apron', $basic['title'] );
		$this->assertSame( array( array( 'name' => 'Colour', 'value' => 'sand' ) ), $basic['attributes'] );
		$this->assertStringContainsString( '<li>Pocket on the front</li>', $basic['description'] );
		$this->assertCount( 2, $basic['alt_texts'] );
		$this->assertSame( array( $this->cat ), Listing_Writer::match_categories( array( 'mugs' ) ) );
	}
}
