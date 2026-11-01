<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant;

use Dev3\AiVendorAssistant\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3va_settings_defaults / dev3va_settings_sanitize.
 */
class Settings {

	/**
	 * Vendor features (ids).
	 */
	const FEATURES = array( 'listing', 'price', 'reply', 'profile', 'insights' );

	/**
	 * Adds plugin defaults. No translated strings here: defaults are read before init.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'marketplace'       => 'auto',
				'features'          => array_fill_keys( self::FEATURES, true ),
				'default_credits'   => 100,
				'credit_weights'    => array(
					'listing'  => 2,
					'price'    => 1,
					'reply'    => 1,
					'profile'  => 1,
					'insights' => 1,
				),
				'moderation_mode'   => 'review',
				'blocked_terms'     => '',
				'blocked_action'    => 'flag',
				'moderation_policy' => '',
				'menu_label'        => '',
				'menu_position'     => 45,
				'vendor_branding'   => true,
				'tone'              => 'friendly',
				'language'          => '',
				'comparables'       => 12,
				'low_stock'         => 3,
				'reply_policies'    => '',
				'store_seo_output'  => true,
				'notify_vendor'     => true,
				'max_photos'        => 6,
				'apply_alt_text'    => true,
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		$enums = array(
			'marketplace'     => array( 'auto', 'dokan', 'wcfm' ),
			'moderation_mode' => array( 'off', 'review', 'flag' ),
			'blocked_action'  => array( 'flag', 'block' ),
			'tone'            => array( 'friendly', 'professional', 'enthusiastic', 'concise', 'luxury' ),
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $input[ $key ] ) && in_array( (string) $input[ $key ], $allowed, true ) ) {
				$out[ $key ] = (string) $input[ $key ];
			}
		}
		if ( isset( $input['features'] ) && is_array( $input['features'] ) ) {
			$features = isset( $current['features'] ) && is_array( $current['features'] ) ? $current['features'] : array();
			foreach ( self::FEATURES as $feature ) {
				if ( array_key_exists( $feature, $input['features'] ) ) {
					$features[ $feature ] = (bool) $input['features'][ $feature ];
				}
			}
			$out['features'] = $features;
		}
		if ( isset( $input['credit_weights'] ) && is_array( $input['credit_weights'] ) ) {
			$weights = isset( $current['credit_weights'] ) && is_array( $current['credit_weights'] ) ? $current['credit_weights'] : array();
			foreach ( self::FEATURES as $feature ) {
				if ( isset( $input['credit_weights'][ $feature ] ) ) {
					$weights[ $feature ] = min( 20, max( 0, (int) $input['credit_weights'][ $feature ] ) );
				}
			}
			$out['credit_weights'] = $weights;
		}
		$ints = array(
			'default_credits' => array( 0, 100000 ),
			'menu_position'   => array( 1, 999 ),
			'comparables'     => array( 3, 40 ),
			'low_stock'       => array( 0, 1000 ),
			'max_photos'      => array( 1, 10 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		foreach ( array( 'vendor_branding', 'store_seo_output', 'notify_vendor', 'apply_alt_text' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			}
		}
		if ( isset( $input['menu_label'] ) ) {
			$out['menu_label'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['menu_label'] ) ), 0, 40 );
		}
		if ( isset( $input['language'] ) ) {
			$out['language'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['language'] ) ), 0, 40 );
		}
		foreach ( array(
			'blocked_terms'     => 2000,
			'moderation_policy' => 3000,
			'reply_policies'    => 3000,
		) as $key => $max ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input[ $key ] ) ), 0, $max );
			}
		}
		return $out;
	}

	/**
	 * Shortcut to a setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Vendor-facing menu label.
	 *
	 * @return string
	 */
	public static function menu_label() {
		$label = (string) self::get( 'menu_label', '' );
		return '' !== $label ? $label : __( 'AI Assistant', 'dev3-ai-vendor-assistant' );
	}

	/**
	 * Feature labels.
	 *
	 * @return array
	 */
	public static function feature_labels() {
		return array(
			'listing'  => __( 'Listing writer', 'dev3-ai-vendor-assistant' ),
			'price'    => __( 'Price suggestions', 'dev3-ai-vendor-assistant' ),
			'reply'    => __( 'Buyer replies', 'dev3-ai-vendor-assistant' ),
			'profile'  => __( 'Store bio & SEO', 'dev3-ai-vendor-assistant' ),
			'insights' => __( 'Performance tips', 'dev3-ai-vendor-assistant' ),
		);
	}

	/**
	 * Blocked terms as a list.
	 *
	 * @return string[]
	 */
	public static function blocked_terms() {
		$raw   = (string) self::get( 'blocked_terms', '' );
		$terms = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $raw ) ) );
		return array_values( array_unique( array_map( 'mb_strtolower', $terms ) ) );
	}
}
