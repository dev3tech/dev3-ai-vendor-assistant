<?php
/**
 * Plugin Name:       DEV3 AI Vendor Assistant for Dokan & WCFM
 * Plugin URI:        https://dev3.tech/item/dev3-ai-vendor-assistant
 * Description:       An AI assistant inside the Dokan and WCFM vendor dashboards: listing writer, price suggestions, buyer reply drafts, store bio/SEO and performance tips, with admin quotas and moderation.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-vendor-assistant
 * Domain Path:       /languages
 *
 * @package Dev3\AiVendorAssistant
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3VA_VERSION', '1.0.0' );
define( 'DEV3VA_FILE', __FILE__ );
define( 'DEV3VA_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3VA_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3VA_SLUG', 'dev3-ai-vendor-assistant' );
define( 'DEV3VA_NAME', 'DEV3 AI Vendor Assistant for Dokan & WCFM' );
define( 'DEV3VA_MIN_PHP', '7.4' );
define( 'DEV3VA_MIN_WP', '6.0' );
define( 'DEV3VA_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3VA_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiVendorAssistant\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3VA_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiVendorAssistant\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiVendorAssistant\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiVendorAssistant\Plugin', 'instance' ) );
