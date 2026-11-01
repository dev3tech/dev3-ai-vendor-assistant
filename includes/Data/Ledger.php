<?php
/**
 * Per-vendor AI ledger: one row per AI request a vendor triggered.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Data;

use Dev3\AiVendorAssistant\Core3\Data\Usage_Repository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, names from $wpdb->prefix.

/**
 * Ledger repository.
 */
class Ledger {

	/**
	 * Current month key (UTC), e.g. 2026-10.
	 *
	 * @return string
	 */
	public static function month() {
		return gmdate( 'Y-m' );
	}

	/**
	 * Records a row.
	 *
	 * @param array $row { vendor_id, user_id, feature, credits, model, prompt_tokens, output_tokens, cached, status, error_code, object_id }.
	 * @return int Row id.
	 */
	public static function record( array $row ) {
		global $wpdb;
		$row  = wp_parse_args(
			$row,
			array(
				'vendor_id'     => 0,
				'user_id'       => get_current_user_id(),
				'feature'       => '',
				'credits'       => 0,
				'model'         => '',
				'prompt_tokens' => 0,
				'output_tokens' => 0,
				'cached'        => 0,
				'status'        => 'ok',
				'error_code'    => '',
				'object_id'     => 0,
			)
		);
		$cost = $row['cached'] ? 0 : Usage_Repository::estimate_cost( $row['model'], (int) $row['prompt_tokens'], (int) $row['output_tokens'] );
		$wpdb->insert(
			Tables::ledger(),
			array(
				'vendor_id'     => (int) $row['vendor_id'],
				'user_id'       => (int) $row['user_id'],
				'feature'       => substr( sanitize_key( $row['feature'] ), 0, 20 ),
				'credits'       => max( 0, (int) $row['credits'] ),
				'model'         => substr( (string) $row['model'], 0, 80 ),
				'prompt_tokens' => max( 0, (int) $row['prompt_tokens'] ),
				'output_tokens' => max( 0, (int) $row['output_tokens'] ),
				'cost'          => round( (float) $cost, 6 ),
				'cached'        => $row['cached'] ? 1 : 0,
				'status'        => substr( sanitize_key( $row['status'] ), 0, 20 ),
				'error_code'    => substr( sanitize_key( $row['error_code'] ), 0, 60 ),
				'object_id'     => (int) $row['object_id'],
				'month'         => self::month(),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%f', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Credits used by a vendor in a month.
	 *
	 * @param int    $vendor_id Vendor.
	 * @param string $month     Month key.
	 * @return int
	 */
	public static function credits_used( $vendor_id, $month = '' ) {
		global $wpdb;
		$table = Tables::ledger();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(credits),0) FROM {$table} WHERE vendor_id = %d AND month = %s AND status = 'ok'", (int) $vendor_id, $month ? $month : self::month() ) );
	}

	/**
	 * Month totals per vendor.
	 *
	 * @param string $month Month.
	 * @return array vendor_id => { credits, requests, tokens, cost }
	 */
	public static function totals_by_vendor( $month = '' ) {
		global $wpdb;
		$table = Tables::ledger();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT vendor_id, SUM(credits) credits, COUNT(*) requests, SUM(prompt_tokens + output_tokens) tokens, SUM(cost) cost FROM {$table} WHERE month = %s AND status = 'ok' GROUP BY vendor_id", $month ? $month : self::month() ), ARRAY_A );
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['vendor_id'] ] = array(
				'credits'  => (int) $r['credits'],
				'requests' => (int) $r['requests'],
				'tokens'   => (int) $r['tokens'],
				'cost'     => round( (float) $r['cost'], 4 ),
			);
		}
		return $out;
	}

	/**
	 * Month totals per feature (optionally for one vendor).
	 *
	 * @param string $month     Month.
	 * @param int    $vendor_id Vendor (0 = all).
	 * @return array feature => { credits, requests, cost }
	 */
	public static function totals_by_feature( $month = '', $vendor_id = 0 ) {
		global $wpdb;
		$table = Tables::ledger();
		$month = $month ? $month : self::month();
		if ( $vendor_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT feature, SUM(credits) credits, COUNT(*) requests, SUM(cost) cost FROM {$table} WHERE month = %s AND vendor_id = %d AND status = 'ok' GROUP BY feature", $month, (int) $vendor_id ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT feature, SUM(credits) credits, COUNT(*) requests, SUM(cost) cost FROM {$table} WHERE month = %s AND status = 'ok' GROUP BY feature", $month ), ARRAY_A );
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['feature'] ] = array(
				'credits'  => (int) $r['credits'],
				'requests' => (int) $r['requests'],
				'cost'     => round( (float) $r['cost'], 4 ),
			);
		}
		return $out;
	}

	/**
	 * Paged rows.
	 *
	 * @param array $args { vendor_id, feature, month, page, per_page }.
	 * @return array { rows, total }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args   = wp_parse_args(
			$args,
			array(
				'vendor_id' => 0,
				'feature'   => '',
				'month'     => '',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$table  = Tables::ledger();
		$where  = array( '1=1' );
		$params = array();
		if ( $args['vendor_id'] ) {
			$where[]  = 'vendor_id = %d';
			$params[] = (int) $args['vendor_id'];
		}
		if ( $args['feature'] ) {
			$where[]  = 'feature = %s';
			$params[] = (string) $args['feature'];
		}
		if ( $args['month'] ) {
			$where[]  = 'month = %s';
			$params[] = (string) $args['month'];
		}
		$per    = min( 200, max( 1, (int) $args['per_page'] ) );
		$offset = ( max( 1, (int) $args['page'] ) - 1 ) * $per;
		$sql_w  = implode( ' AND ', $where );
		$total  = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_w}", $params ) : "SELECT COUNT(*) FROM {$table} WHERE {$sql_w}" ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return array(
			'rows'  => array_map( array( __CLASS__, 'shape' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Casts a row.
	 *
	 * @param array $r Row.
	 * @return array
	 */
	public static function shape( array $r ) {
		return array(
			'id'         => (int) $r['id'],
			'vendor_id'  => (int) $r['vendor_id'],
			'user_id'    => (int) $r['user_id'],
			'feature'    => (string) $r['feature'],
			'credits'    => (int) $r['credits'],
			'model'      => (string) $r['model'],
			'tokens'     => (int) $r['prompt_tokens'] + (int) $r['output_tokens'],
			'cost'       => round( (float) $r['cost'], 6 ),
			'cached'     => (bool) $r['cached'],
			'status'     => (string) $r['status'],
			'error_code' => (string) $r['error_code'],
			'object_id'  => (int) $r['object_id'],
			'month'      => (string) $r['month'],
			'created_at' => mysql_to_rfc3339( $r['created_at'] ),
		);
	}

	/**
	 * Rows by a user (privacy export).
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$table = Tables::ledger();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d OR vendor_id = %d ORDER BY id DESC LIMIT 1000", (int) $user_id, (int) $user_id ), ARRAY_A );
	}

	/**
	 * Anonymises a user's rows (privacy eraser). Vendor totals are kept for accounting.
	 *
	 * @param int $user_id User.
	 * @return int Rows changed.
	 */
	public static function anonymise_user( $user_id ) {
		global $wpdb;
		return (int) $wpdb->update( Tables::ledger(), array( 'user_id' => 0 ), array( 'user_id' => (int) $user_id ), array( '%d' ), array( '%d' ) );
	}
}
