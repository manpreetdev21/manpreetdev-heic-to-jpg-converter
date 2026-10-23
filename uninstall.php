<?php
/**
 * Removes the plugin's settings and log when it is deleted.
 * Converted images and kept .heic originals are left untouched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'hic_settings', 'hic_version', 'hic_logs', 'hic_stats' ) as $hic_option ) {
	delete_option( $hic_option );
}
delete_post_meta_by_key( '_hic_failed' );
