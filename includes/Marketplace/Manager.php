<?php
/**
 * Picks the active marketplace adapter.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Marketplace;

use Dev3\AiVendorAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter registry.
 */
class Manager {

	/**
	 * Resolved adapter (null = not resolved yet, false = none).
	 *
	 * @var Adapter|false|null
	 */
	private static $current = null;

	/**
	 * All adapters keyed by id.
	 *
	 * @return Adapter[]
	 */
	public static function adapters() {
		$adapters = array(
			'dokan' => new Dokan(),
			'wcfm'  => new Wcfm(),
		);
		/**
		 * Registers more marketplace adapters (instances of Marketplace\Adapter).
		 *
		 * @param Adapter[] $adapters Adapters by id.
		 */
		$adapters = apply_filters( 'dev3va_marketplace_adapters', $adapters );
		return array_filter(
			(array) $adapters,
			static function ( $a ) {
				return $a instanceof Adapter;
			}
		);
	}

	/**
	 * Active adapter, honouring the forced choice in Settings.
	 *
	 * @return Adapter|null
	 */
	public static function current() {
		if ( null === self::$current ) {
			self::$current = false;
			$adapters      = self::adapters();
			$forced        = (string) Settings::get( 'marketplace', 'auto' );
			if ( 'auto' !== $forced && isset( $adapters[ $forced ] ) && $adapters[ $forced ]->active() ) {
				self::$current = $adapters[ $forced ];
			} else {
				foreach ( $adapters as $adapter ) {
					if ( $adapter->active() ) {
						self::$current = $adapter;
						break;
					}
				}
			}
		}
		return self::$current ? self::$current : null;
	}

	/**
	 * Detected marketplaces (for the admin dashboard).
	 *
	 * @return array[] { id, label, active }
	 */
	public static function detected() {
		$out = array();
		foreach ( self::adapters() as $id => $adapter ) {
			$out[] = array(
				'id'     => $id,
				'label'  => $adapter->label(),
				'active' => $adapter->active(),
			);
		}
		return $out;
	}

	/**
	 * Forces an adapter (tests) or clears the resolution.
	 *
	 * @param Adapter|null $adapter Adapter.
	 */
	public static function set( $adapter = null ) {
		self::$current = $adapter;
	}
}
