<?php
/**
 * Vendor drafts (AI or basic): every result a vendor can accept, edit or reject.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Data;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, names from $wpdb->prefix.

/**
 * Drafts repository. Every read for a vendor is scoped by vendor_id.
 */
class Drafts {

	/**
	 * Creates a draft.
	 *
	 * @param array $row { vendor_id, user_id, feature, object_type, object_id, source, input, draft, ai, model, tokens }.
	 * @return int
	 */
	public static function create( array $row ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			Tables::drafts(),
			array(
				'vendor_id'   => (int) ( $row['vendor_id'] ?? 0 ),
				'user_id'     => (int) ( $row['user_id'] ?? get_current_user_id() ),
				'feature'     => substr( sanitize_key( $row['feature'] ?? '' ), 0, 20 ),
				'object_type' => substr( sanitize_key( $row['object_type'] ?? '' ), 0, 20 ),
				'object_id'   => (int) ( $row['object_id'] ?? 0 ),
				'source'      => substr( sanitize_key( $row['source'] ?? '' ), 0, 40 ),
				'input'       => wp_json_encode( $row['input'] ?? array() ),
				'draft'       => wp_json_encode( $row['draft'] ?? array() ),
				'status'      => 'pending',
				'ai'          => empty( $row['ai'] ) ? 0 : 1,
				'model'       => substr( (string) ( $row['model'] ?? '' ), 0, 80 ),
				'tokens'      => (int) ( $row['tokens'] ?? 0 ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * One draft, only when it belongs to the vendor.
	 *
	 * @param int $id        Id.
	 * @param int $vendor_id Vendor (0 = any, admin use).
	 * @return array|null
	 */
	public static function get( $id, $vendor_id = 0 ) {
		global $wpdb;
		$table = Tables::drafts();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		if ( $vendor_id && (int) $row['vendor_id'] !== (int) $vendor_id ) {
			return null;
		}
		return self::shape( $row );
	}

	/**
	 * Updates status (and optionally the object id).
	 *
	 * @param int    $id        Id.
	 * @param string $status    Status.
	 * @param int    $object_id Object id (0 = keep).
	 * @return bool
	 */
	public static function set_status( $id, $status, $object_id = 0 ) {
		global $wpdb;
		$data = array(
			'status'     => sanitize_key( $status ),
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( $object_id ) {
			$data['object_id'] = (int) $object_id;
		}
		return false !== $wpdb->update( Tables::drafts(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * A vendor's drafts.
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $args      { feature, status, page, per_page }.
	 * @return array { rows, total }
	 */
	public static function for_vendor( $vendor_id, array $args = array() ) {
		global $wpdb;
		$args   = wp_parse_args(
			$args,
			array(
				'feature'  => '',
				'status'   => '',
				'page'     => 1,
				'per_page' => 20,
			)
		);
		$table  = Tables::drafts();
		$where  = array( 'vendor_id = %d' );
		$params = array( (int) $vendor_id );
		if ( $args['feature'] ) {
			$where[]  = 'feature = %s';
			$params[] = (string) $args['feature'];
		}
		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		$sql_w  = implode( ' AND ', $where );
		$per    = min( 100, max( 1, (int) $args['per_page'] ) );
		$offset = ( max( 1, (int) $args['page'] ) - 1 ) * $per;
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_w}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
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
		$input = json_decode( (string) $r['input'], true );
		$draft = json_decode( (string) $r['draft'], true );
		return array(
			'id'          => (int) $r['id'],
			'vendor_id'   => (int) $r['vendor_id'],
			'user_id'     => (int) $r['user_id'],
			'feature'     => (string) $r['feature'],
			'object_type' => (string) $r['object_type'],
			'object_id'   => (int) $r['object_id'],
			'source'      => (string) $r['source'],
			'input'       => is_array( $input ) ? $input : array(),
			'draft'       => is_array( $draft ) ? $draft : array(),
			'status'      => (string) $r['status'],
			'ai'          => (bool) $r['ai'],
			'model'       => (string) $r['model'],
			'tokens'      => (int) $r['tokens'],
			'created_at'  => mysql_to_rfc3339( $r['created_at'] ),
			'updated_at'  => mysql_to_rfc3339( $r['updated_at'] ),
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
		$table = Tables::drafts();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 500", (int) $user_id ), ARRAY_A );
	}

	/**
	 * Deletes a user's drafts (privacy eraser).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function delete_for_user( $user_id ) {
		global $wpdb;
		return (int) $wpdb->delete( Tables::drafts(), array( 'user_id' => (int) $user_id ), array( '%d' ) );
	}
}
