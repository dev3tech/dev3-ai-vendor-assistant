<?php
/**
 * Marketplace-admin routes (dev3va/v1/admin/*).
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Rest;

use Dev3\AiVendorAssistant\Core3\Api\Ai;
use Dev3\AiVendorAssistant\Core3\Api\Api_Error;
use Dev3\AiVendorAssistant\Core3\Rest\Base_Controller;
use Dev3\AiVendorAssistant\Data\Ledger;
use Dev3\AiVendorAssistant\Features\Moderation;
use Dev3\AiVendorAssistant\Marketplace\Manager;
use Dev3\AiVendorAssistant\Settings;
use Dev3\AiVendorAssistant\Vendor\Access;
use Dev3\AiVendorAssistant\Vendor\Features;
use Dev3\AiVendorAssistant\Vendor\Quota;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller.
 */
class Admin_Controller extends Base_Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		$routes = array(
			array( 'admin/overview', 'GET', 'overview' ),
			array( 'admin/vendors', 'GET', 'vendors' ),
			array( 'admin/vendors/(?P<id>\d+)', 'POST', 'update_vendor' ),
			array( 'admin/ledger', 'GET', 'ledger' ),
			array( 'admin/moderation', 'GET', 'moderation' ),
			array( 'admin/moderation/(?P<id>\d+)', 'POST', 'decide' ),
			array( 'admin/moderation/(?P<id>\d+)/check', 'POST', 'check' ),
		);
		foreach ( $routes as $route ) {
			register_rest_route(
				self::NS,
				'/' . $route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
	}

	/**
	 * Vendor ids (paged).
	 *
	 * @param array $args { search, page, per_page }.
	 * @return array { ids, total }
	 */
	private function vendor_query( array $args ) {
		$adapter = Manager::current();
		if ( ! $adapter ) {
			return array(
				'ids'   => array(),
				'total' => 0,
			);
		}
		$query = array(
			'role__in'    => $adapter->vendor_roles(),
			'number'      => min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) ),
			'paged'       => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'fields'      => 'ID',
			'count_total' => true,
		);
		if ( ! empty( $args['search'] ) ) {
			$query['search']         = '*' . sanitize_text_field( (string) $args['search'] ) . '*';
			$query['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}
		$q = new \WP_User_Query( $query );
		return array(
			'ids'   => array_map( 'intval', (array) $q->get_results() ),
			'total' => (int) $q->get_total(),
		);
	}

	/**
	 * Vendor row.
	 *
	 * @param int   $id     Vendor.
	 * @param array $totals Ledger totals by vendor.
	 * @return array
	 */
	private function vendor_row( $id, array $totals ) {
		$adapter = Manager::current();
		$user    = get_userdata( $id );
		$quota   = Quota::status( $id );
		$usage   = $totals[ $id ] ?? array(
			'credits'  => 0,
			'requests' => 0,
			'tokens'   => 0,
			'cost'     => 0,
		);
		return array(
			'id'           => (int) $id,
			'store'        => $adapter ? $adapter->store_name( $id ) : '',
			'name'         => $user ? $user->display_name : '',
			'email'        => $user ? $user->user_email : '',
			'store_url'    => $adapter ? $adapter->store_url( $id ) : '',
			'access'       => Access::has_access( $id ),
			'overrides'    => Features::overrides( $id ),
			'features'     => Features::for_vendor( $id ),
			'quota'        => $quota,
			'has_override' => Quota::has_override( $id ),
			'override'     => Quota::has_override( $id ) ? (int) get_user_meta( $id, '_dev3va_quota', true ) : null,
			'usage'        => $usage,
			'products'     => $adapter ? count( $adapter->product_ids( $id ) ) : 0,
		);
	}

	/**
	 * GET admin/overview.
	 *
	 * @return \WP_REST_Response
	 */
	public function overview() {
		$adapter = Manager::current();
		$totals  = Ledger::totals_by_vendor();
		$vendors = $this->vendor_query( array( 'per_page' => 1 ) );
		$top     = $totals;
		uasort( $top, static fn( $a, $b ) => $b['credits'] <=> $a['credits'] );
		$top_rows = array();
		foreach ( array_slice( array_keys( $top ), 0, 5, true ) as $vendor_id ) {
			$top_rows[] = array(
				'id'      => (int) $vendor_id,
				'store'   => $adapter ? $adapter->store_name( $vendor_id ) : '',
				'credits' => $top[ $vendor_id ]['credits'],
				'limit'   => Quota::limit( $vendor_id ),
				'cost'    => $top[ $vendor_id ]['cost'],
			);
		}
		$sum = array(
			'credits'  => array_sum( wp_list_pluck( $totals, 'credits' ) ),
			'requests' => array_sum( wp_list_pluck( $totals, 'requests' ) ),
			'cost'     => round( array_sum( wp_list_pluck( $totals, 'cost' ) ), 4 ),
			'active'   => count( $totals ),
		);
		return $this->respond(
			array(
				'marketplace' => $adapter ? array(
					'id'    => $adapter->id(),
					'label' => $adapter->label(),
				) : null,
				'detected'    => Manager::detected(),
				'vendors'     => $vendors['total'],
				'month'       => Ledger::month(),
				'totals'      => $sum,
				'by_feature'  => Ledger::totals_by_feature(),
				'top'         => $top_rows,
				'moderation'  => Moderation::counts(),
				'ai'          => Ai::status(),
				'labels'      => Settings::feature_labels(),
				'settings'    => array(
					'default_credits' => (int) Settings::get( 'default_credits', 100 ),
					'moderation_mode' => (string) Settings::get( 'moderation_mode', 'review' ),
				),
			)
		);
	}

	/**
	 * GET admin/vendors.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function vendors( \WP_REST_Request $request ) {
		$found  = $this->vendor_query(
			array(
				'search'   => (string) $request['search'],
				'page'     => (int) $request['page'],
				'per_page' => (int) ( $request['per_page'] ? $request['per_page'] : 20 ),
			)
		);
		$totals = Ledger::totals_by_vendor();
		return $this->respond(
			array(
				'rows'  => array_map(
					function ( $id ) use ( $totals ) {
						return $this->vendor_row( $id, $totals );
					},
					$found['ids']
				),
				'total' => $found['total'],
			)
		);
	}

	/**
	 * POST admin/vendors/<id>: { access, overrides, quota ('' = default) }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_vendor( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$adapter = Manager::current();
		if ( ! $adapter || ! $adapter->is_vendor( $id ) ) {
			return $this->respond( Api_Error::make( 'dev3va_not_vendor', __( 'That user is not a vendor.', 'dev3-ai-vendor-assistant' ), '', 404 ) );
		}
		$params = $request->get_json_params() ? $request->get_json_params() : $request->get_params();
		if ( array_key_exists( 'access', $params ) ) {
			update_user_meta( $id, '_dev3va_access', $params['access'] ? '1' : '0' );
		}
		if ( isset( $params['overrides'] ) && is_array( $params['overrides'] ) ) {
			Features::save_overrides( $id, $params['overrides'] );
		}
		if ( array_key_exists( 'quota', $params ) ) {
			Quota::set_limit( $id, ( '' === $params['quota'] || null === $params['quota'] ) ? '' : (int) $params['quota'] );
		}
		return $this->respond( $this->vendor_row( $id, Ledger::totals_by_vendor() ) );
	}

	/**
	 * GET admin/ledger (format=csv for a download).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ledger( \WP_REST_Request $request ) {
		$data    = Ledger::query(
			array(
				'vendor_id' => (int) $request['vendor_id'],
				'feature'   => in_array( (string) $request['feature'], Settings::FEATURES, true ) ? (string) $request['feature'] : '',
				'month'     => preg_match( '/^\d{4}-\d{2}$/', (string) $request['month'] ) ? (string) $request['month'] : '',
				'page'      => max( 1, (int) $request['page'] ),
				'per_page'  => 'csv' === $request['format'] ? 200 : 25,
			)
		);
		$adapter = Manager::current();
		$names   = array();
		foreach ( $data['rows'] as &$row ) {
			if ( ! isset( $names[ $row['vendor_id'] ] ) ) {
				$names[ $row['vendor_id'] ] = $adapter ? $adapter->store_name( $row['vendor_id'] ) : '';
			}
			$row['store'] = $names[ $row['vendor_id'] ];
			$user         = $row['user_id'] ? get_userdata( $row['user_id'] ) : null;
			$row['user']  = $user ? $user->display_name : '';
		}
		unset( $row );
		if ( 'csv' === $request['format'] ) {
			$lines = array( 'date,store,user,feature,credits,model,tokens,cost_usd,cached,status' );
			foreach ( $data['rows'] as $r ) {
				$lines[] = implode(
					',',
					array_map(
						static function ( $v ) {
							$v = (string) $v;
							if ( preg_match( '/^[=+\-@]/', $v ) ) {
								$v = "'" . $v; // Neutralise spreadsheet formulas.
							}
							return '"' . str_replace( '"', '""', $v ) . '"';
						},
						array( $r['created_at'], $r['store'], $r['user'], $r['feature'], $r['credits'], $r['model'], $r['tokens'], $r['cost'], $r['cached'] ? 'yes' : 'no', $r['status'] )
					)
				);
			}
			$data['csv'] = implode( "\n", $lines );
		}
		return $this->respond( $data );
	}

	/**
	 * GET admin/moderation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function moderation( \WP_REST_Request $request ) {
		$status = (string) $request['status'];
		return $this->respond(
			Moderation::queue(
				array(
					'status'    => in_array( $status, array( 'open', 'pending', 'flagged', 'approved', 'rejected' ), true ) ? $status : 'open',
					'vendor_id' => (int) $request['vendor_id'],
					'page'      => max( 1, (int) $request['page'] ),
					'search'    => sanitize_text_field( (string) $request['search'] ),
				)
			)
		);
	}

	/**
	 * POST admin/moderation/<id>: { action: approve|reject, note }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function decide( \WP_REST_Request $request ) {
		return $this->respond( Moderation::decide( (int) $request['id'], (string) $request['action'], (string) $request['note'] ) );
	}

	/**
	 * POST admin/moderation/<id>/check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function check( \WP_REST_Request $request ) {
		return $this->respond( Moderation::ai_check( (int) $request['id'] ) );
	}
}
