<?php
/**
 * WCFM inquiries (free WCFM "Enquiry" module): `wcfm_enquiries` + `wcfm_enquiries_response`.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Messages;

use Dev3\AiVendorAssistant\Core3\Api\Api_Error;
use Dev3\AiVendorAssistant\Features\Text;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WCFM's own tables.

/**
 * Inquiry source.
 */
class Wcfm_Inquiries extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'wcfm_inquiries';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Inquiry', 'dev3-ai-vendor-assistant' );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'wcfm_enquiries';
	}

	/**
	 * Available when the WCFM table exists.
	 *
	 * @return bool
	 */
	public function available() {
		global $wpdb;
		return $this->table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table() ) );
	}

	/**
	 * Shapes a row.
	 *
	 * @param array $r Row.
	 * @return array
	 */
	private function shape( array $r ) {
		return $this->row(
			array(
				'id'         => (int) $r['ID'],
				'buyer'      => Text::first_name( $r['customer_name'] ),
				'subject'    => $r['product_id'] ? get_the_title( (int) $r['product_id'] ) : __( 'Store inquiry', 'dev3-ai-vendor-assistant' ),
				'text'       => Text::block( wp_strip_all_tags( $r['enquiry'] ), 3000 ),
				'product_id' => (int) $r['product_id'],
				'product'    => $r['product_id'] ? get_the_title( (int) $r['product_id'] ) : '',
				'date'       => mysql_to_rfc3339( $r['posted'] ),
				'replied'    => '' !== trim( (string) $r['reply'] ),
				'reply'      => Text::block( wp_strip_all_tags( (string) $r['reply'] ), 3000 ),
			)
		);
	}

	/**
	 * Items.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $limit     Limit.
	 * @return array[]
	 */
	public function items( $vendor_id, $limit = 30 ) {
		global $wpdb;
		if ( ! $this->available() ) {
			return array();
		}
		$table = $this->table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE vendor_id = %d ORDER BY ID DESC LIMIT %d", (int) $vendor_id, (int) $limit ), ARRAY_A );
		return array_map( array( $this, 'shape' ), (array) $rows );
	}

	/**
	 * One inquiry of the vendor.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $id        Id.
	 * @return array|null
	 */
	public function get( $vendor_id, $id ) {
		global $wpdb;
		if ( ! $this->available() ) {
			return null;
		}
		$table = $this->table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ID = %d AND vendor_id = %d", (int) $id, (int) $vendor_id ), ARRAY_A );
		return $row ? $this->shape( $row ) : null;
	}

	/**
	 * Writes the reply like WCFM's own inquiry board does, then emails the buyer.
	 *
	 * @param int    $vendor_id Vendor.
	 * @param int    $user_id   User.
	 * @param int    $id        Inquiry.
	 * @param string $reply     Reply.
	 * @return true|\WP_Error
	 */
	public function send( $vendor_id, $user_id, $id, $reply ) {
		global $wpdb;
		if ( ! $this->get( $vendor_id, $id ) ) {
			return Api_Error::make( 'dev3va_not_found', __( 'That item was not found in your store.', 'dev3-ai-vendor-assistant' ), '', 404 );
		}
		$table = $this->table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ID = %d", (int) $id ), ARRAY_A );
		$html  = wp_kses_post( wpautop( $reply ) );
		$wpdb->insert(
			$wpdb->prefix . 'wcfm_enquiries_response',
			array(
				'reply'          => $html,
				'enquiry_id'     => (int) $id,
				'product_id'     => (int) $row['product_id'],
				'vendor_id'      => (int) $vendor_id,
				'customer_id'    => (int) $row['customer_id'],
				'customer_name'  => (string) $row['customer_name'],
				'customer_email' => (string) $row['customer_email'],
				'reply_by'       => (int) $user_id,
				'posted'         => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);
		$wpdb->update(
			$table,
			array(
				'reply'    => $html,
				'reply_by' => (int) $user_id,
				'replied'  => current_time( 'Y-m-d' ),
			),
			array( 'ID' => (int) $id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		if ( is_email( $row['customer_email'] ) ) {
			$subject = sprintf(
				/* translators: %s: site name */
				__( '%s: Reply to your inquiry', 'dev3-ai-vendor-assistant' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			);
			wp_mail( $row['customer_email'], $subject, $reply );
		}
		/**
		 * After DEV3 AI posted a WCFM inquiry reply.
		 *
		 * @param int $id        Inquiry.
		 * @param int $vendor_id Vendor.
		 */
		do_action( 'dev3va_wcfm_inquiry_replied', (int) $id, (int) $vendor_id );
		return true;
	}
}
