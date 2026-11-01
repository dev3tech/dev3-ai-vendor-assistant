<?php
/**
 * Loads WooCommerce + one marketplace before the plugin, and installs their tables/roles.
 *
 * Default: Dokan Lite. Run the WCFM pass with DEV3VA_MARKETPLACE=wcfm:
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/dev3-ai-vendor-assistant env DEV3VA_MARKETPLACE=wcfm vendor/bin/phpunit
 *
 * @package Dev3\AiVendorAssistant
 */

define( 'DEV3VA_TEST_MARKETPLACE', 'wcfm' === getenv( 'DEV3VA_MARKETPLACE' ) ? 'wcfm' : 'dokan' );

tests_add_filter(
	'dev3va_test_dependencies',
	static function () {
		$deps = array( file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ? 'woocommerce/woocommerce.php' : 'woocommerce.latest-stable/woocommerce.php' );
		if ( 'wcfm' === DEV3VA_TEST_MARKETPLACE ) {
			$deps[] = 'wc-frontend-manager/wc_frontend_manager.php';
			$deps[] = 'wc-multivendor-marketplace/wc-multivendor-marketplace.php';
		} else {
			$deps[] = 'dokan-lite/dokan.php';
		}
		return $deps;
	}
);

// WCFM checks active_plugins to detect itself.
tests_add_filter(
	'pre_option_active_plugins',
	static function () {
		return 'wcfm' === DEV3VA_TEST_MARKETPLACE
			? array( 'woocommerce/woocommerce.php', 'wc-frontend-manager/wc_frontend_manager.php', 'wc-multivendor-marketplace/wc-multivendor-marketplace.php', 'dev3-ai-vendor-assistant/dev3-ai-vendor-assistant.php' )
			: array( 'woocommerce/woocommerce.php', 'dokan-lite/dokan.php', 'dev3-ai-vendor-assistant/dev3-ai-vendor-assistant.php' );
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		global $wpdb;
		if ( ! class_exists( 'WC_Install' ) ) {
			return;
		}
		WC_Install::install();
		if ( class_exists( '\\Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer' ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
		}
		if ( 'dokan' === DEV3VA_TEST_MARKETPLACE && class_exists( '\\WeDevs\\Dokan\\Install\\Installer' ) ) {
			( new \WeDevs\Dokan\Install\Installer() )->do_install();
		}
		if ( 'wcfm' === DEV3VA_TEST_MARKETPLACE ) {
			delete_option( 'wcfmmp_table_install' );
			delete_option( 'wcfm_table_install' );
			if ( class_exists( 'WCFM_Install' ) ) {
				new WCFM_Install();
			}
			if ( class_exists( 'WCFMmp_Install' ) ) {
				new WCFMmp_Install();
			}
			if ( ! get_role( 'wcfm_vendor' ) ) {
				add_role( 'wcfm_vendor', 'Store Vendor', array( 'read' => true, 'edit_products' => true, 'publish_products' => true, 'upload_files' => true ) );
			}
			if ( ! get_role( 'shop_staff' ) ) {
				add_role( 'shop_staff', 'Shop Staff', array( 'read' => true ) );
			}
		}
		// Start from empty order stores: HPOS + marketplace tables survive the WP test installer between runs.
		foreach ( array( 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'dokan_orders', 'wcfm_marketplace_orders', 'wcfm_enquiries', 'wcfm_enquiries_response', 'dev3va_ledger', 'dev3va_drafts' ) as $dev3va_table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $dev3va_table ) ) ) {
				$wpdb->query( "DELETE FROM {$wpdb->prefix}{$dev3va_table}" ); // phpcs:ignore
			}
		}
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reload roles the installers just added.
		wp_roles();
	}
);

tests_add_filter(
	'wp_mail_from',
	static function () {
		return 'shop@example.org';
	}
);
