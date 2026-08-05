<?php
/**
 * Contract implemented by every tracking plugin adapter.
 *
 * @package TrackBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for a third-party shipment tracking plugin.
 *
 * Adapters translate a tracking number and carrier into whatever API the
 * underlying plugin exposes. They never change the order status or send email:
 * TrackBridge owns those decisions so behaviour stays identical across plugins.
 *
 * @since 1.0.0
 */
interface Trackbridge_Provider {

	/**
	 * Returns the stable identifier used in settings.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_id();

	/**
	 * Returns the human-readable plugin name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether the underlying plugin is active and exposes the API we need.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_available();

	/**
	 * Returns the selectable carriers as a slug => label map.
	 *
	 * An empty array means the plugin cannot enumerate carriers, and the
	 * settings screen should fall back to a free-text carrier field.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_carriers();

	/**
	 * Writes a tracking number onto an order.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order   Order to add tracking to.
	 * @param string   $number  Tracking number.
	 * @param string   $carrier Carrier slug.
	 * @return bool True when the tracking number was stored.
	 */
	public function add_tracking( $order, $number, $carrier );

	/**
	 * Returns the tracking items already stored on an order.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order Order to inspect.
	 * @return array List of tracking item arrays.
	 */
	public function get_existing_tracking( $order );

	/**
	 * Whether an order already carries this exact tracking number.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order   Order to inspect.
	 * @param string   $number  Tracking number.
	 * @param string   $carrier Carrier slug.
	 * @return bool
	 */
	public function has_tracking( $order, $number, $carrier );
}
