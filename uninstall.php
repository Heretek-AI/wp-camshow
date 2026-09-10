<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'cbcs_settings' );

if ( is_array( $settings ) && ! empty( $settings['rooms'] ) ) {
	foreach ( preg_split( '/[\r\n,]+/', (string) $settings['rooms'] ) as $room ) {
		$room = strtolower( trim( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $room ) ) );
		if ( '' !== $room ) {
			delete_transient( 'cbcs_status_' . md5( $room ) );
		}
	}
}

wp_clear_scheduled_hook( 'cbcs_cron_refresh' );
delete_option( 'cbcs_settings' );
