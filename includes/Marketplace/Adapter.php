<?php
/**
 * Base marketplace adapter: WooCommerce-generic behaviour shared by Dokan and WCFM.
 *
 * Subclasses implement detection, vendor/staff mapping, store profile storage and the dashboard
 * integration. Register more adapters with the `dev3va_marketplace_adapters` filter.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Marketplace;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter contract + shared helpers.
 */
abstract class Adapter {

	/**
	 * Adapter id (dokan, wcfm…).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Whether the marketplace plugin is active.
	 *
	 * @return bool
	 */
	abstract public function active();

	/**
	 * Whether a user is a vendor (not staff).
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	abstract public function is_vendor( $user_id );

	/**
	 * Vendor a staff user works for (0 when the user is not staff).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	abstract protected function staff_vendor( $user_id );

	/**
	 * Store profile { store_name, bio, tagline, seo_title, seo_description, seo_keywords }.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array
	 */
	abstract public function get_profile( $vendor_id );

	/**
	 * Saves profile fields (only the keys given).
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $fields    Fields.
	 * @return bool
	 */
	abstract public function save_profile( $vendor_id, array $fields );

	/**
	 * Hooks the vendor dashboard page, menu and shortcuts.
	 */
	abstract public function register_dashboard();

	/**
	 * URL of the assistant page in the vendor dashboard.
	 *
	 * @param string $route Hash route (e.g. "listing?product=12").
	 * @return string
	 */
	abstract public function assistant_url( $route = '' );

	/**
	 * Whether the current request is the assistant page.
	 *
	 * @return bool
	 */
	abstract public function is_assistant_page();

	/**
	 * Status for a new vendor product (marketplace setting).
	 *
	 * @param int $vendor_id Vendor.
	 * @return string publish|pending
	 */
	abstract public function new_product_status( $vendor_id );

	/**
	 * UI skin: { id, brand, brand_ink, header_bg, header_text, radius }.
	 *
	 * @return array
	 */
	abstract public function skin();

	/**
	 * Roles that mark vendors (for admin listings).
	 *
	 * @return string[]
	 */
	abstract public function vendor_roles();

	/**
	 * Vendor id for a user: the vendor themself, the vendor a staff member works for, or 0.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public function vendor_for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0;
		}
		$staff_of = (int) $this->staff_vendor( $user_id );
		if ( $staff_of > 0 && $this->is_vendor( $staff_of ) ) {
			return $staff_of;
		}
		return $this->is_vendor( $user_id ) ? $user_id : 0;
	}

	/**
	 * Store name.
	 *
	 * @param int $vendor_id Vendor.
	 * @return string
	 */
	public function store_name( $vendor_id ) {
		$profile = $this->get_profile( $vendor_id );
		if ( ! empty( $profile['store_name'] ) ) {
			return (string) $profile['store_name'];
		}
		$user = get_userdata( (int) $vendor_id );
		return $user ? (string) $user->display_name : '';
	}

	/**
	 * Public store URL.
	 *
	 * @param int $vendor_id Vendor.
	 * @return string
	 */
	public function store_url( $vendor_id ) {
		return get_author_posts_url( (int) $vendor_id );
	}

	/**
	 * Vendor that owns a product (0 = none).
	 *
	 * @param int $product_id Product.
	 * @return int
	 */
	public function product_vendor( $product_id ) {
		$post = get_post( (int) $product_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return 0;
		}
		if ( 'product_variation' === $post->post_type ) {
			$post = get_post( (int) $post->post_parent );
			if ( ! $post ) {
				return 0;
			}
		}
		return (int) $post->post_author;
	}

	/**
	 * Whether a vendor owns a product.
	 *
	 * @param int $vendor_id  Vendor.
	 * @param int $product_id Product.
	 * @return bool
	 */
	public function owns_product( $vendor_id, $product_id ) {
		$vendor_id = (int) $vendor_id;
		return $vendor_id > 0 && $this->product_vendor( $product_id ) === $vendor_id;
	}

	/**
	 * A vendor's product ids.
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $args      Extra WP_Query args.
	 * @return int[]
	 */
	public function product_ids( $vendor_id, array $args = array() ) {
		$query = new \WP_Query(
			array_merge(
				array(
					'post_type'              => 'product',
					'post_status'            => array( 'publish', 'pending', 'draft', 'private' ),
					'author'                 => (int) $vendor_id,
					'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				),
				$args
			)
		);
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Order ids that contain this vendor's items, newest first.
	 *
	 * The generic version scans recent orders' line items; adapters override it with their
	 * marketplace order tables and fall back here when those are missing.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $since     Unix time (0 = any).
	 * @param int $limit     Max orders.
	 * @return int[]
	 */
	public function order_ids( $vendor_id, $since = 0, $limit = 300 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$args = array(
			'limit'   => min( 1000, max( 1, (int) $limit ) * 3 ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
			'type'    => 'shop_order',
		);
		if ( $since ) {
			$args['date_created'] = '>' . (int) $since;
		}
		$ids = array();
		foreach ( wc_get_orders( $args ) as $order ) {
			if ( $this->order_has_vendor_items( $order, $vendor_id ) ) {
				$ids[] = (int) $order->get_id();
				if ( count( $ids ) >= $limit ) {
					break;
				}
			}
		}
		return $ids;
	}

	/**
	 * Whether a vendor may see an order (it contains at least one of their items).
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $order_id  Order.
	 * @return bool
	 */
	public function owns_order( $vendor_id, $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		return $order && $vendor_id > 0 && $this->order_has_vendor_items( $order, $vendor_id );
	}

	/**
	 * Line-item check.
	 *
	 * @param \WC_Order $order     Order.
	 * @param int       $vendor_id Vendor.
	 * @return bool
	 */
	protected function order_has_vendor_items( $order, $vendor_id ) {
		if ( ! $order || ! is_callable( array( $order, 'get_items' ) ) ) {
			return false;
		}
		foreach ( $order->get_items() as $item ) {
			$pid = is_callable( array( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0;
			if ( $pid && $this->product_vendor( $pid ) === (int) $vendor_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * URL to edit a product in the vendor dashboard.
	 *
	 * @param int $product_id Product.
	 * @return string
	 */
	public function product_edit_url( $product_id ) {
		return get_permalink( (int) $product_id );
	}

	/**
	 * Shared mount markup for the vendor app.
	 *
	 * @return string
	 */
	public function mount() {
		return \Dev3\AiVendorAssistant\Front\Vendor_App::mount( $this );
	}
}
