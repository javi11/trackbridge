<?php
/**
 * Removes TrackBridge data when the plugin is deleted.
 *
 * Order data written through the tracking plugins is deliberately left intact:
 * it belongs to Advanced Shipment Tracking / WooCommerce Shipment Tracking, not
 * to TrackBridge.
 *
 * @package TrackBridge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$trackbridge_options = array(
	'trackbridge_meta_key',
	'trackbridge_provider',
	'trackbridge_carrier',
	'trackbridge_mark_completed',
	'trackbridge_send_email',
	'trackbridge_delete_after_sync',
	'trackbridge_allow_carrier_override',
	'trackbridge_split_multiple',
	'trackbridge_logging',
);

foreach ( $trackbridge_options as $trackbridge_option ) {
	delete_option( $trackbridge_option );
}
