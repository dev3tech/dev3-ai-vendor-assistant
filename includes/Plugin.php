<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant;

use Dev3\AiVendorAssistant\Core3\Core\Assets;
use Dev3\AiVendorAssistant\Core3\Core\Privacy;
use Dev3\AiVendorAssistant\Core3\Core\Schema;
use Dev3\AiVendorAssistant\Data\Drafts;
use Dev3\AiVendorAssistant\Data\Ledger;
use Dev3\AiVendorAssistant\Data\Tables;
use Dev3\AiVendorAssistant\Features\Prompt_Library;
use Dev3\AiVendorAssistant\Features\Seo;
use Dev3\AiVendorAssistant\Front\Vendor_App;
use Dev3\AiVendorAssistant\Marketplace\Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3va_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3VA_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		delete_option( 'dev3va_dokan_endpoint' );
		delete_option( 'dev3va_wcfm_endpoint' );
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( 'dev3va_run_job' );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( ! has_filter( 'dev3va_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			add_filter( 'dev3va_settings_defaults', array( Settings::class, 'defaults' ) );
			add_filter( 'dev3va_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
			add_filter( 'dev3va_schema_sql', array( Tables::class, 'sql' ), 10, 2 );
		}
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', array( $this, 'textdomain' ), 1 );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3VA_DB_VERSION ) );
		add_action( 'init', array( $this, 'maybe_install_front' ), 5 );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compat' ) );
		Privacy::init();
		add_action( 'init', array( $this, 'privacy' ), 5 );
		Prompt_Library::register();
		Seo::register();
		Vendor_App::register();
		// The marketplace plugins load before us (alphabetical) but resolve on plugins_loaded; hook the dashboard after.
		add_action( 'init', array( $this, 'marketplace' ), 0 );
		if ( is_admin() ) {
			add_action( 'init', static fn() => Assets::register( array( 'admin' ) ) );
			( new Admin\Admin_Page() )->register();
		}
	}

	/**
	 * Loads translations (on init, as WP 6.7 requires).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-vendor-assistant', false, dirname( plugin_basename( DEV3VA_FILE ) ) . '/languages' );
	}

	/**
	 * Installs tables on the first front-end request after an update (vendors may never open wp-admin).
	 */
	public function maybe_install_front() {
		if ( ! is_admin() ) {
			Schema::maybe_install( DEV3VA_DB_VERSION );
		}
	}

	/**
	 * Hooks the active marketplace dashboard.
	 */
	public function marketplace() {
		$adapter = Manager::current();
		if ( $adapter ) {
			$adapter->register_dashboard();
		}
	}

	/**
	 * WooCommerce feature compatibility (HPOS + Cart/Checkout blocks): orders are read only through
	 * the WooCommerce order API, and the plugin adds nothing to cart or checkout.
	 */
	public function declare_compat() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3VA_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3VA_FILE, true );
		}
	}

	/**
	 * GDPR exporter/eraser for the vendor ledger and drafts.
	 */
	public function privacy() {
		Privacy::add_exporter(
			__( 'Vendor AI activity', 'dev3-ai-vendor-assistant' ),
			static function ( $email, $page = 1 ) {
				unset( $page );
				$user = get_user_by( 'email', $email );
				$data = array();
				if ( $user ) {
					foreach ( Ledger::for_user( $user->ID ) as $row ) {
						$data[] = array(
							'group_id'    => 'dev3va-ledger',
							'group_label' => __( 'Vendor AI activity', 'dev3-ai-vendor-assistant' ),
							'item_id'     => 'dev3va-ledger-' . $row['id'],
							'data'        => array(
								array(
									'name'  => __( 'Date', 'dev3-ai-vendor-assistant' ),
									'value' => $row['created_at'],
								),
								array(
									'name'  => __( 'Feature', 'dev3-ai-vendor-assistant' ),
									'value' => $row['feature'],
								),
								array(
									'name'  => __( 'Credits', 'dev3-ai-vendor-assistant' ),
									'value' => $row['credits'],
								),
							),
						);
					}
					foreach ( Drafts::for_user( $user->ID ) as $row ) {
						$data[] = array(
							'group_id'    => 'dev3va-drafts',
							'group_label' => __( 'Vendor AI drafts', 'dev3-ai-vendor-assistant' ),
							'item_id'     => 'dev3va-draft-' . $row['id'],
							'data'        => array(
								array(
									'name'  => __( 'Date', 'dev3-ai-vendor-assistant' ),
									'value' => $row['created_at'],
								),
								array(
									'name'  => __( 'Feature', 'dev3-ai-vendor-assistant' ),
									'value' => $row['feature'],
								),
								array(
									'name'  => __( 'Draft', 'dev3-ai-vendor-assistant' ),
									'value' => $row['draft'],
								),
							),
						);
					}
				}
				return array(
					'data' => $data,
					'done' => true,
				);
			}
		);
		Privacy::add_eraser(
			__( 'Vendor AI activity', 'dev3-ai-vendor-assistant' ),
			static function ( $email, $page = 1 ) {
				unset( $page );
				$user    = get_user_by( 'email', $email );
				$removed = 0;
				if ( $user ) {
					$removed += Drafts::delete_for_user( $user->ID );
					$removed += Ledger::anonymise_user( $user->ID );
				}
				return array(
					'items_removed'  => $removed,
					'items_retained' => false,
					'messages'       => array(),
					'done'           => true,
				);
			}
		);
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Vendor_Controller() )->register_routes();
		( new Rest\Admin_Controller() )->register_routes();
	}
}
