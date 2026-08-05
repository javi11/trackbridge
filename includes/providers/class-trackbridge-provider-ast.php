<?php
/**
 * Adapter for Advanced Shipment Tracking (zorem).
 *
 * @package TrackBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes tracking numbers through Advanced Shipment Tracking.
 *
 * The API lives on `WC_Advanced_Shipment_Tracking_Actions`, reached through its
 * `get_instance()` singleton. Note that `wc_advanced_shipment_tracking()` returns
 * a *different* object — the main plugin class — which has no tracking methods at
 * all. TrackBridge 1.0.0 checked that object for `add_tracking_item()` and so
 * reported "no supported tracking plugin is active" on every store running AST.
 *
 * `ast_add_tracking_number()` is used as a fallback for editions that expose the
 * global helper but not the class. AST's Pro-only `insert_tracking_item()` is
 * avoided because it interpolates the carrier into an unescaped SQL query.
 *
 * @since 1.0.0
 */
class Trackbridge_Provider_AST extends Trackbridge_Abstract_Provider {

	/**
	 * Provider identifier stored in settings.
	 *
	 * @var string
	 */
	const ID = 'ast';

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
		return __( 'Advanced Shipment Tracking', 'trackbridge' );
	}

	/**
	 * Whether AST is active and exposes an API we can write through.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_available() {
		return null !== $this->get_actions() || function_exists( 'ast_add_tracking_number' );
	}

	/**
	 * Returns AST's carriers as a `ts_slug => provider name` map.
	 *
	 * AST's own admin dropdown stores `ts_slug` as the tracking provider, so
	 * TrackBridge must store the same value for tracking links to resolve.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_carriers() {
		$actions = $this->get_actions();

		if ( null === $actions || ! method_exists( $actions, 'get_providers' ) ) {
			return array();
		}

		$providers = $actions->get_providers();

		if ( ! is_array( $providers ) ) {
			return array();
		}

		$carriers = array();

		foreach ( $providers as $slug => $provider ) {
			$slug = (string) $slug;

			if ( '' === $slug ) {
				continue;
			}

			$carriers[ $slug ] = $this->extract_provider_name( $provider, $slug );
		}

		asort( $carriers );

		return $carriers;
	}

	/**
	 * Writes a tracking number onto an order via AST.
	 *
	 * The shipped status is deliberately sent as `0` (not shipped): AST reacts to
	 * that value by transitioning the order, and TrackBridge owns the status
	 * transition so behaviour stays identical across tracking plugins.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order   Order to add tracking to.
	 * @param string   $number  Tracking number.
	 * @param string   $carrier Carrier slug (AST `ts_slug`).
	 * @return bool True when the tracking number was stored.
	 */
	public function add_tracking( $order, $number, $carrier ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$order_id = $order->get_id();
		$actions  = $this->get_actions();

		if ( null !== $actions ) {
			/*
			 * AST calls strtotime() on date_shipped without checking that the key
			 * exists, so it must always be supplied. This is also why the global
			 * helper is not preferred: it defaults the date to null.
			 */
			$actions->add_tracking_item(
				$order_id,
				array(
					'tracking_provider' => $carrier,
					'tracking_number'   => $number,
					'date_shipped'      => $this->get_ship_date(),
					'status_shipped'    => 0,
				)
			);
		} elseif ( function_exists( 'ast_add_tracking_number' ) ) {
			ast_add_tracking_number( $order_id, $number, $carrier, $this->get_ship_date(), 0 );
		} else {
			return false;
		}

		// Confirm against fresh data rather than trusting a return value.
		$fresh = wc_get_order( $order_id );

		return $fresh instanceof WC_Order && $this->has_tracking( $fresh, $number, $carrier );
	}

	/**
	 * Returns AST's actions instance when the expected API is present.
	 *
	 * @since 1.0.0
	 * @return object|null
	 */
	private function get_actions() {
		if ( ! class_exists( 'WC_Advanced_Shipment_Tracking_Actions' ) ) {
			return null;
		}

		if ( ! is_callable( array( 'WC_Advanced_Shipment_Tracking_Actions', 'get_instance' ) ) ) {
			return null;
		}

		$actions = WC_Advanced_Shipment_Tracking_Actions::get_instance();

		if ( ! is_object( $actions ) || ! method_exists( $actions, 'add_tracking_item' ) ) {
			return null;
		}

		return $actions;
	}

	/**
	 * Reads a carrier's display name from AST's provider entry.
	 *
	 * AST returns an array per carrier, but older releases returned a plain
	 * string, so both shapes are accepted.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $provider Provider entry from AST.
	 * @param string $slug     Carrier slug, used as the fallback label.
	 * @return string
	 */
	private function extract_provider_name( $provider, $slug ) {
		if ( is_array( $provider ) && ! empty( $provider['provider_name'] ) ) {
			return (string) $provider['provider_name'];
		}

		if ( is_string( $provider ) && '' !== $provider ) {
			return $provider;
		}

		return $slug;
	}
}
