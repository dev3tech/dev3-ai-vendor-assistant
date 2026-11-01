<?php
/**
 * Plugin tables (ledger + drafts), added to the core dbDelta run.
 *
 * @package Dev3\AiVendorAssistant
 */

namespace Dev3\AiVendorAssistant\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names and CREATE statements.
 */
class Tables {

	/**
	 * Ledger table.
	 *
	 * @return string
	 */
	public static function ledger() {
		global $wpdb;
		return $wpdb->prefix . 'dev3va_ledger';
	}

	/**
	 * Drafts table.
	 *
	 * @return string
	 */
	public static function drafts() {
		global $wpdb;
		return $wpdb->prefix . 'dev3va_drafts';
	}

	/**
	 * Hooked to dev3va_schema_sql.
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function sql( $sql, $charset ) {
		$ledger = self::ledger();
		$drafts = self::drafts();
		$sql[]  = "CREATE TABLE {$ledger} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			vendor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			feature varchar(20) NOT NULL DEFAULT '',
			credits smallint(5) unsigned NOT NULL DEFAULT 0,
			model varchar(80) NOT NULL DEFAULT '',
			prompt_tokens int(10) unsigned NOT NULL DEFAULT 0,
			output_tokens int(10) unsigned NOT NULL DEFAULT 0,
			cost decimal(12,6) NOT NULL DEFAULT 0,
			cached tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'ok',
			error_code varchar(60) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			month char(7) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY vendor_month (vendor_id,month),
			KEY month (month),
			KEY created (created_at)
		) {$charset};";
		$sql[]  = "CREATE TABLE {$drafts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			vendor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			feature varchar(20) NOT NULL DEFAULT '',
			object_type varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source varchar(40) NOT NULL DEFAULT '',
			input longtext NOT NULL,
			draft longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			ai tinyint(1) NOT NULL DEFAULT 0,
			model varchar(80) NOT NULL DEFAULT '',
			tokens int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY vendor_feature (vendor_id,feature),
			KEY status (status)
		) {$charset};";
		return $sql;
	}

	/**
	 * Drops plugin tables (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( array( self::ledger(), self::drafts() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}
}
