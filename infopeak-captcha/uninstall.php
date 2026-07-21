<?php
/**
 * Uninstall handler - removes the plugin's stored settings.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ipkcap_settings' );
