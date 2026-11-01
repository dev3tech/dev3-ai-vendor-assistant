<?php
/**
 * Monthly AI credits per vendor, enforced before every AI call and recorded in the ledger.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Vendor;

use Dev3\AiVendorAssistant\Core3\Api\Ai;
use Dev3\AiVendorAssistant\Core3\Api\Api_Error;
use Dev3\AiVendorAssistant\Data\Ledger;
use Dev3\AiVendorAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Credits.
 */
class Quota {

	/**
	 * Credits a feature costs per AI request.
	 *
	 * @param string $feature Feature.
	 * @return int
	 */
	public static function weight( $feature ) {
		$weights = (array) Settings::get( 'credit_weights', array() );
		$weight  = isset( $weights[ $feature ] ) ? (int) $weights[ $feature ] : 1;
		/**
		 * Credits per AI request of a feature.
		 *
		 * @param int    $weight  Credits.
		 * @param string $feature Feature.
		 */
		return max( 0, (int) apply_filters( 'dev3va_feature_credits', $weight, $feature ) );
	}

	/**
	 * Monthly limit for a vendor (0 = unlimited).
	 *
	 * @param int $vendor_id Vendor.
	 * @return int
	 */
	public static function limit( $vendor_id ) {
		$override = get_user_meta( (int) $vendor_id, '_dev3va_quota', true );
		$limit    = ( '' === $override || null === $override || false === $override ) ? (int) Settings::get( 'default_credits', 100 ) : (int) $override;
		/**
		 * Monthly credits for a vendor (0 = unlimited).
		 *
		 * @param int $limit     Limit.
		 * @param int $vendor_id Vendor.
		 */
		return max( 0, (int) apply_filters( 'dev3va_vendor_credit_limit', $limit, (int) $vendor_id ) );
	}

	/**
	 * Sets (or clears with '') a vendor override.
	 *
	 * @param int        $vendor_id Vendor.
	 * @param int|string $limit     Limit or '' for the default.
	 */
	public static function set_limit( $vendor_id, $limit ) {
		if ( '' === $limit || null === $limit ) {
			delete_user_meta( (int) $vendor_id, '_dev3va_quota' );
			return;
		}
		update_user_meta( (int) $vendor_id, '_dev3va_quota', min( 100000, max( 0, (int) $limit ) ) );
	}

	/**
	 * Whether the vendor has an override.
	 *
	 * @param int $vendor_id Vendor.
	 * @return bool
	 */
	public static function has_override( $vendor_id ) {
		return '' !== (string) get_user_meta( (int) $vendor_id, '_dev3va_quota', true );
	}

	/**
	 * Status { limit, used, remaining, unlimited, percent, month, resets_at }.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array
	 */
	public static function status( $vendor_id ) {
		$limit = self::limit( $vendor_id );
		$used  = Ledger::credits_used( $vendor_id );
		$next  = strtotime( gmdate( 'Y-m-01 00:00:00' ) . ' +1 month' );
		return array(
			'limit'     => $limit,
			'used'      => $used,
			'remaining' => $limit ? max( 0, $limit - $used ) : null,
			'unlimited' => 0 === $limit,
			'percent'   => $limit ? min( 100, (int) round( $used / $limit * 100 ) ) : 0,
			'month'     => Ledger::month(),
			'resets_at' => gmdate( 'c', $next ),
		);
	}

	/**
	 * Error when the vendor cannot spend `$credits` more, else null.
	 *
	 * @param int    $vendor_id Vendor.
	 * @param string $feature   Feature.
	 * @return \WP_Error|null
	 */
	public static function check( $vendor_id, $feature ) {
		$limit = self::limit( $vendor_id );
		if ( 0 === $limit ) {
			return null;
		}
		$need = self::weight( $feature );
		if ( Ledger::credits_used( $vendor_id ) + $need > $limit ) {
			return Api_Error::make(
				'dev3va_quota_reached',
				__( 'Your monthly AI credits are used up.', 'dev3-ai-vendor-assistant' ),
				__( 'The basic (non-AI) helpers still work. Credits reset on the 1st of the month, or ask the marketplace to raise your limit.', 'dev3-ai-vendor-assistant' ),
				429
			);
		}
		return null;
	}

	/**
	 * Runs an AI call on behalf of a vendor: feature + quota check, then Ai::run, then a ledger row.
	 *
	 * @param int    $vendor_id Vendor.
	 * @param string $feature   Feature.
	 * @param string $task      Usage-log task.
	 * @param array  $args      Ai::run args.
	 * @param int    $object_id Related object.
	 * @return array|\WP_Error
	 */
	public static function run( $vendor_id, $feature, $task, array $args, $object_id = 0 ) {
		$blocked = self::check( $vendor_id, $feature );
		if ( $blocked ) {
			return $blocked;
		}
		$result = Ai::run( $task, $args, 'vendor' );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			// Only count errors that reached Gemini (guards like AI off or the cap never spend tokens).
			if ( is_array( $data ) && ! empty( $data['status'] ) && ! in_array( $result->get_error_code(), array( 'dev3va_ai_disabled', 'dev3va_cap_reached', 'dev3va_no_api_key' ), true ) ) {
				Ledger::record(
					array(
						'vendor_id'  => $vendor_id,
						'feature'    => $feature,
						'credits'    => 0,
						'status'     => 'error',
						'error_code' => $result->get_error_code(),
						'object_id'  => $object_id,
					)
				);
			}
			return $result;
		}
		$cached = ! empty( $result['cached'] );
		Ledger::record(
			array(
				'vendor_id'     => $vendor_id,
				'feature'       => $feature,
				'credits'       => $cached ? 0 : self::weight( $feature ),
				'model'         => isset( $result['model'] ) ? $result['model'] : '',
				'prompt_tokens' => $cached ? 0 : (int) ( $result['usage']['prompt'] ?? 0 ),
				'output_tokens' => $cached ? 0 : (int) ( $result['usage']['output'] ?? 0 ),
				'cached'        => $cached,
				'object_id'     => $object_id,
			)
		);
		return $result;
	}
}
