<?php
/**
 * Which features a vendor gets: global switch per feature + per-vendor override.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Vendor;

use Dev3\AiVendorAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Feature resolution.
 */
class Features {

	/**
	 * Global switch.
	 *
	 * @param string $feature Feature.
	 * @return bool
	 */
	public static function globally_enabled( $feature ) {
		$features = (array) Settings::get( 'features', array() );
		return in_array( $feature, Settings::FEATURES, true ) && ! empty( $features[ $feature ] );
	}

	/**
	 * Per-vendor overrides { feature: inherit|on|off }.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array
	 */
	public static function overrides( $vendor_id ) {
		$raw = get_user_meta( (int) $vendor_id, '_dev3va_features', true );
		$out = array();
		foreach ( Settings::FEATURES as $feature ) {
			$value             = is_array( $raw ) && isset( $raw[ $feature ] ) ? (string) $raw[ $feature ] : 'inherit';
			$out[ $feature ]   = in_array( $value, array( 'inherit', 'on', 'off' ), true ) ? $value : 'inherit';
		}
		return $out;
	}

	/**
	 * Saves overrides.
	 *
	 * @param int   $vendor_id Vendor.
	 * @param array $input     { feature: inherit|on|off }.
	 * @return array
	 */
	public static function save_overrides( $vendor_id, array $input ) {
		$current = self::overrides( $vendor_id );
		foreach ( Settings::FEATURES as $feature ) {
			if ( isset( $input[ $feature ] ) && in_array( (string) $input[ $feature ], array( 'inherit', 'on', 'off' ), true ) ) {
				$current[ $feature ] = (string) $input[ $feature ];
			}
		}
		update_user_meta( (int) $vendor_id, '_dev3va_features', $current );
		return $current;
	}

	/**
	 * Effective switch for a vendor.
	 *
	 * @param string $feature   Feature.
	 * @param int    $vendor_id Vendor.
	 * @return bool
	 */
	public static function enabled( $feature, $vendor_id ) {
		if ( ! in_array( $feature, Settings::FEATURES, true ) || ! Access::has_access( $vendor_id ) ) {
			return false;
		}
		$override = self::overrides( $vendor_id )[ $feature ];
		$enabled  = 'inherit' === $override ? self::globally_enabled( $feature ) : 'on' === $override;
		/**
		 * Final say on whether a vendor gets a feature.
		 *
		 * @param bool   $enabled   Enabled.
		 * @param string $feature   Feature id.
		 * @param int    $vendor_id Vendor.
		 */
		return (bool) apply_filters( 'dev3va_vendor_feature_enabled', $enabled, $feature, (int) $vendor_id );
	}

	/**
	 * Effective map.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array feature => bool
	 */
	public static function for_vendor( $vendor_id ) {
		$out = array();
		foreach ( Settings::FEATURES as $feature ) {
			$out[ $feature ] = self::enabled( $feature, $vendor_id );
		}
		return $out;
	}
}
