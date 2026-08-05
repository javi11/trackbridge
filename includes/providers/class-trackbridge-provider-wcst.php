<?php
/**
 * Adapter for the official WooCommerce Shipment Tracking extension.
 *
 * @package TrackBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes tracking numbers through WooCommerce Shipment Tracking.
 *
 * The extension exposes `wc_st_add_tracking_number()`, which returns nothing,
 * so a successful write is confirmed by re-reading the order meta.
 *
 * @since 1.0.0
 */
class Trackbridge_Provider_WCST extends Trackbridge_Abstract_Provider {

	/**
	 * Provider identifier stored in settings.
	 *
	 * @var string
	 */
	const ID = 'wcst';

	/**
	 * Returns the stable identifier used in settings.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_id() {
		return self::ID;
	}

	/**
	 * Returns the human-readable plugin name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_label() {
		return __( 'WooCommerce Shipment Tracking', 'trackbridge' );
	}

	/**
	 * Whether the extension is active and exposes its helper function.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_available() {
		return function_exists( 'wc_st_add_tracking_number' );
	}

	/**
	 * Returns the extension's carriers as a name => name map.
	 *
	 * The extension identifies carriers by display name rather than by slug, and
	 * groups them by country. When the provider list cannot be read an empty
	 * array is returned, which makes the settings screen fall back to a
	 * free-text carrier field.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_carriers() {
		$providers = $this->fetch_providers();
		$carriers  = array();

		foreach ( $providers as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( array_keys( $value ) as $name ) {
					$carriers[ (string) $name ] = (string) $name;
				}
				continue;
			}

			$carriers[ (string) $key ] = (string) $key;
		}

		ksort( $carriers );

		return $carriers;
	}

	/**
	 * Writes a tracking number onto an order via the extension.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order   Order to add tracking to.
	 * @param string   $number  Tracking number.
	 * @param string   $carrier Carrier name.
	 * @return bool True when the tracking number was stored.
	 */
	public function add_tracking( $order, $number, $carrier ) {
		if ( ! $this->is_available() || ! $order instanceof WC_Order ) {
			return false;
		}

		wc_st_add_tracking_number( $order->get_id(), $number, $carrier, $this->get_ship_date() );

		// The helper returns nothing, so confirm the write against fresh data.
		$fresh = wc_get_order( $order->get_id() );

		return $fresh instanceof WC_Order && $this->has_tracking( $fresh, $number, $carrier );
	}

	/**
	 * Reads the extension's provider list, tolerating API differences.
	 *
	 * @since 1.0.0
	 * @return array Raw provider list, possibly grouped by country.
	 */
	private function fetch_providers() {
		if ( ! class_exists( 'WC_Shipment_Tracking_Actions' ) || ! is_callable( array( 'WC_Shipment_Tracking_Actions', 'get_instance' ) ) ) {
			return array();
		}

		$instance = WC_Shipment_Tracking_Actions::get_instance();

		if ( ! is_object( $instance ) || ! method_exists( $instance, 'get_providers' ) ) {
			return array();
		}

		$providers = $instance->get_providers();

		return is_array( $providers ) ? $providers : array();
	}
}
