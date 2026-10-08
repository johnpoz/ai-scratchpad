<?php
/**
 * Uninstall AI Scratchpad.
 *
 * Runs only when the plugin is deleted from the Plugins screen, never on
 * deactivation. Deactivating keeps the journal and notes intact; deleting the
 * plugin is the one point where the site owner has said they are done with them.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Journal and notes.
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}ai_scratchpad`" );

delete_option( 'ai_scratchpad_settings' );
delete_option( 'ai_scratchpad_db_version' );

// Left behind by 0.3.0 to 0.4.2, which bundled a built-in MCP server with its
// own OAuth sign-in server. 0.5.0 removes these on upgrade, but a site deleted
// straight from an older version, or one that never ran the upgrade, may still
// have them.
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}ai_scratchpad_tokens`" );
delete_option( 'ai_scratchpad_oauth_clients' );

// Admin notices live a minute as transients, and the old sign-in server's
// authorization codes five. Neither is worth leaving behind.
foreach ( array( 'ai_sp_oauth_code_', 'ai_scratchpad_admin_error_' ) as $prefix ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . $prefix ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
		)
	);
}
