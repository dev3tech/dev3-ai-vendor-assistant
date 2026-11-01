<?php
/**
 * Vendor routes (dev3va/v1/vendor/*): logged-in vendor or staff, strict nonce, feature switch,
 * and ownership checks inside every service.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Rest;

use Dev3\AiVendorAssistant\Core3\Api\Ai;
use Dev3\AiVendorAssistant\Core3\Api\Api_Error;
use Dev3\AiVendorAssistant\Core3\Core\Rate_Limiter;
use Dev3\AiVendorAssistant\Core3\Rest\Base_Controller;
use Dev3\AiVendorAssistant\Data\Drafts;
use Dev3\AiVendorAssistant\Features\Insights;
use Dev3\AiVendorAssistant\Features\Listing_Writer;
use Dev3\AiVendorAssistant\Features\Moderation;
use Dev3\AiVendorAssistant\Features\Photos;
use Dev3\AiVendorAssistant\Features\Pricing;
use Dev3\AiVendorAssistant\Features\Profile;
use Dev3\AiVendorAssistant\Marketplace\Manager;
use Dev3\AiVendorAssistant\Messages\Inbox;
use Dev3\AiVendorAssistant\Settings;
use Dev3\AiVendorAssistant\Vendor\Access;
use Dev3\AiVendorAssistant\Vendor\Features;
use Dev3\AiVendorAssistant\Vendor\Quota;

defined( 'ABSPATH' ) || exit;

/**
 * Vendor controller.
 */
class Vendor_Controller extends Base_Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		$routes = array(
			array( 'vendor/me', 'GET', 'me', '' ),
			array( 'vendor/products', 'GET', 'products', '' ),
			array( 'vendor/photos', 'POST', 'photo', 'listing' ),
			array( 'vendor/listing/draft', 'POST', 'listing_draft', 'listing' ),
			array( 'vendor/listing/apply', 'POST', 'listing_apply', 'listing' ),
			array( 'vendor/price', 'POST', 'price', 'price' ),
			array( 'vendor/price/apply', 'POST', 'price_apply', 'price' ),
			array( 'vendor/messages', 'GET', 'messages', 'reply' ),
			array( 'vendor/messages/draft', 'POST', 'message_draft', 'reply' ),
			array( 'vendor/messages/send', 'POST', 'message_send', 'reply' ),
			array( 'vendor/profile', 'GET', 'profile', 'profile' ),
			array( 'vendor/profile/draft', 'POST', 'profile_draft', 'profile' ),
			array( 'vendor/profile/apply', 'POST', 'profile_apply', 'profile' ),
			array( 'vendor/insights', 'GET', 'insights', 'insights' ),
			array( 'vendor/insights/ai', 'POST', 'insights_ai', 'insights' ),
			array( 'vendor/history', 'GET', 'history', '' ),
			array( 'vendor/drafts/(?P<id>\d+)/reject', 'POST', 'reject_draft', '' ),
		);
		foreach ( $routes as $route ) {
			list( $path, $method, $callback, $feature ) = $route;
			register_rest_route(
				self::NS,
				'/' . $path,
				array(
					'methods'             => $method,
					'callback'            => array( $this, $callback ),
					'permission_callback' => function ( \WP_REST_Request $request ) use ( $feature ) {
						return $this->vendor_permission( $request, $feature );
					},
				)
			);
		}
	}

	/**
	 * Vendor permission: nonce, logged-in vendor/staff with access, feature enabled, rate limit on POST.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $feature Feature ('' = any vendor).
	 * @return bool|\WP_Error
	 */
	public function vendor_permission( \WP_REST_Request $request, $feature = '' ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'dev3va_forbidden', __( 'Log in to your vendor account first.', 'dev3-ai-vendor-assistant' ), array( 'status' => 401 ) );
		}
		if ( ! $this->nonce_ok( $request ) ) {
			return new \WP_Error( 'dev3va_bad_nonce', __( 'Your session has expired. Reload the page and try again.', 'dev3-ai-vendor-assistant' ), array( 'status' => 403 ) );
		}
		$vendor = Access::current_vendor();
		if ( ! $vendor || ! Access::has_access( $vendor ) ) {
			return new \WP_Error( 'dev3va_forbidden', __( 'The AI assistant is not available for your account.', 'dev3-ai-vendor-assistant' ), array( 'status' => 403 ) );
		}
		if ( $feature && ! Features::enabled( $feature, $vendor ) ) {
			return new \WP_Error( 'dev3va_feature_off', __( 'This feature is turned off for your store.', 'dev3-ai-vendor-assistant' ), array( 'status' => 403 ) );
		}
		if ( 'POST' === $request->get_method() ) {
			/**
			 * Vendor POST requests per minute per user.
			 *
			 * @param int $limit Limit.
			 */
			$limit = (int) apply_filters( 'dev3va_vendor_rate_limit', 30 );
			if ( ! Rate_Limiter::hit( 'vendor', (string) get_current_user_id(), $limit ) ) {
				return new \WP_Error( 'dev3va_rate_limited', __( 'Too many requests. Please wait a moment.', 'dev3-ai-vendor-assistant' ), array( 'status' => 429 ) );
			}
		}
		return true;
	}

	/**
	 * Current vendor id (permission already verified).
	 *
	 * @return int
	 */
	private function vendor() {
		return Access::current_vendor();
	}

	/**
	 * GET vendor/me.
	 *
	 * @return \WP_REST_Response
	 */
	public function me() {
		$vendor  = $this->vendor();
		$adapter = Manager::current();
		$status  = Ai::status();
		return $this->respond(
			array(
				'vendor_id'   => $vendor,
				'store_name'  => $adapter ? $adapter->store_name( $vendor ) : '',
				'store_url'   => $adapter ? $adapter->store_url( $vendor ) : '',
				'marketplace' => $adapter ? array(
					'id'    => $adapter->id(),
					'label' => $adapter->label(),
				) : null,
				'features'    => Features::for_vendor( $vendor ),
				'labels'      => Settings::feature_labels(),
				'quota'       => Quota::status( $vendor ),
				'weights'     => array_combine( Settings::FEATURES, array_map( array( Quota::class, 'weight' ), Settings::FEATURES ) ),
				'ai'          => array(
					'available' => ! empty( $status['enabled'] ) && ! empty( $status['key_set'] ) && empty( $status['cap_reached'] ),
				),
				'branding'    => (bool) Settings::get( 'vendor_branding', true ),
				'moderation'  => (string) Settings::get( 'moderation_mode', 'review' ),
				'max_photos'  => (int) Settings::get( 'max_photos', 6 ),
				'tone'        => (string) Settings::get( 'tone', 'friendly' ),
				'categories'  => Listing_Writer::categories(),
				'currency'    => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '$',
			)
		);
	}

	/**
	 * GET vendor/products.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function products( \WP_REST_Request $request ) {
		$vendor  = $this->vendor();
		$adapter = Manager::current();
		$search  = sanitize_text_field( (string) $request['search'] );
		$args    = array(
			'posts_per_page' => min( 50, max( 1, (int) ( $request['per_page'] ? $request['per_page'] : 20 ) ) ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$rows = array();
		foreach ( $adapter ? $adapter->product_ids( $vendor, $args ) : array() as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$image  = $product->get_image_id();
			$rows[] = array(
				'id'         => (int) $id,
				'title'      => $product->get_name(),
				'status'     => $product->get_status(),
				'price'      => (string) $product->get_regular_price(),
				'type'       => $product->get_type(),
				'image'      => $image ? (string) wp_get_attachment_image_url( $image, 'thumbnail' ) : '',
				'moderation' => (string) get_post_meta( $id, Moderation::META, true ),
				'note'       => (string) get_post_meta( $id, Moderation::META_NOTE, true ),
				'edit_url'   => $adapter->product_edit_url( $id ),
			);
		}
		return $this->respond( array( 'rows' => $rows ) );
	}

	/**
	 * POST vendor/photos (multipart "file").
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function photo( \WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return $this->respond( Api_Error::make( 'dev3va_upload_failed', __( 'Choose a photo to upload.', 'dev3-ai-vendor-assistant' ), '', 400 ) );
		}
		return $this->respond( Photos::upload( $files['file'] ), 201 );
	}

	/**
	 * POST vendor/listing/draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function listing_draft( \WP_REST_Request $request ) {
		return $this->respond(
			Listing_Writer::draft(
				$this->vendor(),
				array(
					'photos'     => (array) $request['photos'],
					'notes'      => (string) $request['notes'],
					'product_id' => (int) $request['product_id'],
					'category'   => (int) $request['category'],
					'tone'       => (string) $request['tone'],
					'mode'       => (string) $request['mode'],
				)
			)
		);
	}

	/**
	 * POST vendor/listing/apply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function listing_apply( \WP_REST_Request $request ) {
		return $this->respond( Listing_Writer::apply( $this->vendor(), (int) $request['draft_id'], is_array( $request['fields'] ) ? $request['fields'] : array() ) );
	}

	/**
	 * POST vendor/price.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function price( \WP_REST_Request $request ) {
		return $this->respond(
			Pricing::suggest(
				$this->vendor(),
				array(
					'product_id' => (int) $request['product_id'],
					'title'      => (string) $request['title'],
					'category'   => (int) $request['category'],
					'goal'       => (string) $request['goal'],
					'notes'      => (string) $request['notes'],
					'mode'       => (string) $request['mode'],
				)
			)
		);
	}

	/**
	 * POST vendor/price/apply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function price_apply( \WP_REST_Request $request ) {
		return $this->respond( Pricing::apply( $this->vendor(), (int) $request['product_id'], (float) $request['price'], (int) $request['draft_id'] ) );
	}

	/**
	 * GET vendor/messages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function messages( \WP_REST_Request $request ) {
		return $this->respond( Inbox::items( $this->vendor(), 'unanswered' === $request['filter'] ? 'unanswered' : 'all' ) );
	}

	/**
	 * POST vendor/messages/draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function message_draft( \WP_REST_Request $request ) {
		return $this->respond(
			Inbox::draft(
				$this->vendor(),
				array(
					'source'       => (string) $request['source'],
					'id'           => (int) $request['id'],
					'text'         => (string) $request['text'],
					'buyer'        => (string) $request['buyer'],
					'product_id'   => (int) $request['product_id'],
					'tone'         => (string) $request['tone'],
					'instructions' => (string) $request['instructions'],
					'mode'         => (string) $request['mode'],
				)
			)
		);
	}

	/**
	 * POST vendor/messages/send.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function message_send( \WP_REST_Request $request ) {
		return $this->respond(
			Inbox::send(
				$this->vendor(),
				get_current_user_id(),
				array(
					'source'   => (string) $request['source'],
					'id'       => (int) $request['id'],
					'reply'    => (string) $request['reply'],
					'draft_id' => (int) $request['draft_id'],
				)
			)
		);
	}

	/**
	 * GET vendor/profile.
	 *
	 * @return \WP_REST_Response
	 */
	public function profile() {
		return $this->respond( Profile::get( $this->vendor() ) );
	}

	/**
	 * POST vendor/profile/draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function profile_draft( \WP_REST_Request $request ) {
		return $this->respond(
			Profile::draft(
				$this->vendor(),
				array(
					'notes' => (string) $request['notes'],
					'tone'  => (string) $request['tone'],
					'mode'  => (string) $request['mode'],
				)
			)
		);
	}

	/**
	 * POST vendor/profile/apply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function profile_apply( \WP_REST_Request $request ) {
		return $this->respond( Profile::apply( $this->vendor(), is_array( $request['fields'] ) ? $request['fields'] : array(), (int) $request['draft_id'] ) );
	}

	/**
	 * GET vendor/insights.
	 *
	 * @return \WP_REST_Response
	 */
	public function insights() {
		return $this->respond( Insights::report( $this->vendor() ) );
	}

	/**
	 * POST vendor/insights/ai.
	 *
	 * @return \WP_REST_Response
	 */
	public function insights_ai() {
		return $this->respond( Insights::ai_summary( $this->vendor() ) );
	}

	/**
	 * GET vendor/history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function history( \WP_REST_Request $request ) {
		$vendor  = $this->vendor();
		$feature = in_array( (string) $request['feature'], Settings::FEATURES, true ) ? (string) $request['feature'] : '';
		$data    = Drafts::for_vendor(
			$vendor,
			array(
				'feature'  => $feature,
				'page'     => max( 1, (int) $request['page'] ),
				'per_page' => 20,
			)
		);
		$adapter = Manager::current();
		foreach ( $data['rows'] as &$row ) {
			unset( $row['input'] );
			$row['title'] = self::history_title( $row );
			if ( 'listing' === $row['feature'] && $row['object_id'] && $adapter && $adapter->owns_product( $vendor, $row['object_id'] ) ) {
				$row['moderation'] = (string) get_post_meta( $row['object_id'], Moderation::META, true );
				$row['note']       = (string) get_post_meta( $row['object_id'], Moderation::META_NOTE, true );
				$row['edit_url']   = $adapter->product_edit_url( $row['object_id'] );
			}
		}
		unset( $row );
		$data['quota'] = Quota::status( $vendor );
		return $this->respond( $data );
	}

	/**
	 * Row title for the history list.
	 *
	 * @param array $row Draft row.
	 * @return string
	 */
	private static function history_title( array $row ) {
		$draft = $row['draft'];
		switch ( $row['feature'] ) {
			case 'listing':
				return (string) ( $draft['title'] ?? '' );
			case 'price':
				return isset( $draft['price'] ) ? Settings::feature_labels()['price'] . ': ' . html_entity_decode( wp_strip_all_tags( wc_price( (float) $draft['price'] ) ), ENT_QUOTES, 'UTF-8' ) : '';
			case 'reply':
				return (string) ( $draft['summary'] ?? '' );
			case 'profile':
				return (string) ( $draft['tagline'] ?? '' );
			case 'insights':
				return (string) ( $draft['headline'] ?? '' );
		}
		return '';
	}

	/**
	 * POST vendor/drafts/<id>/reject.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reject_draft( \WP_REST_Request $request ) {
		$row = Drafts::get( (int) $request['id'], $this->vendor() );
		if ( ! $row ) {
			return $this->respond( Access::not_found() );
		}
		if ( 'pending' === $row['status'] ) {
			Drafts::set_status( $row['id'], 'rejected' );
		}
		return $this->respond(
			array(
				'id'     => $row['id'],
				'status' => 'pending' === $row['status'] ? 'rejected' : $row['status'],
			)
		);
	}
}
