<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiVendorAssistant
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3va_settings = get_option( 'dev3va_settings', array() );
if ( empty( $dev3va_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3va_usage', 'dev3va_core_cache', 'dev3va_core_jobs', 'dev3va_core_embeddings', 'dev3va_ledger', 'dev3va_drafts' ) as $dev3va_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3va_table}" );
}
foreach ( array( 'dev3va_settings', 'dev3va_db_version', 'dev3va_dokan_endpoint', 'dev3va_wcfm_endpoint' ) as $dev3va_option ) {
	delete_option( $dev3va_option );
}
delete_transient( 'dev3va_models' );
wp_unschedule_hook( 'dev3va_run_job' );
wp_unschedule_hook( 'dev3va_daily' );
foreach ( array( '_dev3va_access', '_dev3va_features', '_dev3va_quota', '_dev3va_policies', '_dev3va_tagline' ) as $dev3va_meta ) {
	delete_metadata( 'user', 0, $dev3va_meta, '', true );
}
// Product moderation flags are removed; listings themselves stay (they belong to the vendors).
foreach ( array( '_dev3va_moderation', '_dev3va_moderation_note', '_dev3va_flags', '_dev3va_ai_check', '_dev3va_draft_id' ) as $dev3va_meta ) {
	delete_metadata( 'post', 0, $dev3va_meta, '', true );
}
