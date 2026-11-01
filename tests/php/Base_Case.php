<?php
/**
 * Shared fixtures: vendors on the active marketplace, products, orders, mocked Gemini, REST helper.
 *
 * @package Dev3\AiVendorAssistant
 */

use Dev3\AiVendorAssistant\Core3\Api\Ai;
use Dev3\AiVendorAssistant\Core3\Core\Cache;
use Dev3\AiVendorAssistant\Core3\Core\Schema;
use Dev3\AiVendorAssistant\Core3\Core\Settings_Store;
use Dev3\AiVendorAssistant\Core3\Data\Usage_Repository;
use Dev3\AiVendorAssistant\Marketplace\Manager;

abstract class Base_Case extends WP_UnitTestCase {

	/**
	 * Captured Gemini requests.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Queue of JSON payloads the fake Gemini returns as text (last one repeats).
	 *
	 * @var array
	 */
	protected $replies = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3VA_DB_VERSION );
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Usage_Repository::flush();
		Cache::reset_memo();
		Cache::flush();
		Ai::$client = null;
		Manager::set( null );
		$this->requests = array();
		$this->replies  = array();
		add_filter( 'dev3va_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3va_jobs_use_action_scheduler', '__return_false' );
		add_filter( 'dev3va_vendor_rate_limit', static fn() => 1000 );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		reset_phpmailer_instance();
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3va_retry_delay_ms', '__return_zero' );
		remove_filter( 'dev3va_jobs_use_action_scheduler', '__return_false' );
		Manager::set( null );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Marketplace under test.
	 *
	 * @return string dokan|wcfm
	 */
	protected function marketplace() {
		return DEV3VA_TEST_MARKETPLACE;
	}

	/**
	 * Fake Gemini (generateContent with a JSON text part).
	 *
	 * @param mixed  $pre  Short-circuit.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function fake_http( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'generativelanguage.googleapis.com' ) ) {
			return $pre;
		}
		$this->requests[] = array(
			'url'  => $url,
			'body' => json_decode( (string) $args['body'], true ),
		);
		$next = count( $this->replies ) > 1 ? array_shift( $this->replies ) : ( $this->replies ? $this->replies[0] : array() );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'candidates'    => array(
						array(
							'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $next ) ) ) ),
							'finishReason' => 'STOP',
						),
					),
					'usageMetadata' => array(
						'promptTokenCount'     => 120,
						'candidatesTokenCount' => 80,
						'totalTokenCount'      => 200,
					),
					'modelVersion'  => 'gemini-2.5-flash',
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Creates a vendor on the marketplace under test.
	 *
	 * @param string $store Store name.
	 * @return int
	 */
	protected function vendor( $store = 'Clay & Co' ) {
		if ( 'wcfm' === $this->marketplace() ) {
			$id = self::factory()->user->create( array( 'role' => 'wcfm_vendor' ) );
			update_user_meta( $id, 'wcfmmp_profile_settings', array( 'store_name' => $store ) );
			update_user_meta( $id, 'store_name', $store );
		} else {
			$id = self::factory()->user->create( array( 'role' => 'seller' ) );
			update_user_meta( $id, 'dokan_enable_selling', 'yes' );
			update_user_meta( $id, 'dokan_profile_settings', array( 'store_name' => $store ) );
			update_user_meta( $id, 'dokan_store_name', $store );
		}
		return $id;
	}

	/**
	 * Staff member working for a vendor (Dokan Pro / WCFM Ultimate data model).
	 *
	 * @param int $vendor Vendor.
	 * @return int
	 */
	protected function staff( $vendor ) {
		if ( 'wcfm' === $this->marketplace() ) {
			$id = self::factory()->user->create( array( 'role' => 'shop_staff' ) );
			update_user_meta( $id, '_wcfm_vendor', $vendor );
		} else {
			$id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
			$user = get_user_by( 'id', $id );
			$user->add_cap( 'vendor_staff' );
			update_user_meta( $id, '_vendor_id', $vendor );
		}
		return $id;
	}

	/**
	 * Product owned by a vendor.
	 *
	 * @param int   $vendor Vendor.
	 * @param array $args   { name, price, status, cat }.
	 * @return int
	 */
	protected function product( $vendor, array $args = array() ) {
		$args    = wp_parse_args(
			$args,
			array(
				'name'   => 'Speckled stoneware mug',
				'price'  => '24',
				'status' => 'publish',
				'cat'    => 0,
			)
		);
		$product = new WC_Product_Simple();
		$product->set_name( $args['name'] );
		$product->set_regular_price( $args['price'] );
		$product->set_status( $args['status'] );
		if ( $args['cat'] ) {
			$product->set_category_ids( array( $args['cat'] ) );
		}
		$id = $product->save();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => $vendor,
			)
		);
		return $id;
	}

	/**
	 * Order for a product, with a buyer note.
	 *
	 * @param int    $product_id Product.
	 * @param string $note       Customer note.
	 * @param string $status     Status.
	 * @param int    $qty        Quantity.
	 * @return WC_Order
	 */
	protected function order( $product_id, $note = '', $status = 'processing', $qty = 1 ) {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $product_id ), $qty );
		$order->set_billing_first_name( 'Ana' );
		$order->set_billing_last_name( 'Silva' );
		$order->set_billing_email( 'ana@example.org' );
		$order->set_customer_note( $note );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();
		reset_phpmailer_instance();
		return $order;
	}

	/**
	 * Image attachment uploaded by a user.
	 *
	 * @param int $user User.
	 * @return int
	 */
	protected function photo( $user ) {
		$upload = wp_upload_bits( 'mug-' . wp_generate_password( 6, false ) . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z/C/HgAGgwJ/lK3Q6wAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$id     = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Mug',
				'post_status'    => 'inherit',
				'post_author'    => $user,
			),
			$upload['file']
		);
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		return $id;
	}

	/**
	 * REST call as the current user.
	 *
	 * @param string $method Method.
	 * @param string $route  Route under dev3va/v1.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send a valid nonce.
	 * @return WP_REST_Response
	 */
	protected function call( $method, $route, array $params = array(), $nonce = true ) {
		$req = new WP_REST_Request( $method, '/dev3va/v1/' . ltrim( $route, '/' ) );
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $req );
	}
}
