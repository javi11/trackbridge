<?php
/**
 * Shared behaviour for tracking plugin adapters.
 *
 * @package TrackBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base adapter implementing the parts both tracking plugins share.
 *
 * Advanced Shipment Tracking and WooCommerce Shipment Tracking both store their
 * tracking items in the same order meta key, so reading existing tracking is
 * identical for both and lives here.
 *
 * @since 1.0.0
 */
abstract class Trackbridge_Abstract_Provider implements Trackbridge_Provider {

	/**
	 * Order meta key shared by both supported tracking plugins.
	 *
	 * @var string
	 */
	const TRACKING_META_KEY = '_wc_shipment_tracking_items';

	/**
	 * Returns the tracking items already stored on an order.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order Order to inspect.
	 * @return array List of tracking item arrays.
	 */
	public function get_existing_tracking( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$items = $order->get_meta( self::TRACKING_META_KEY, true );

		if ( ! is_array( $items ) ) {
			return array();
		}

		return array_values( array_filter( $items, 'is_array' ) );
	}

	/**
	 * Whether an order already carries this exact tracking number and carrier.
	 *
	 * Comparison is case-insensitive because carriers are inconsistent about
	 * the casing of alphanumeric tracking numbers.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order   Order to inspect.
	 * @param string   $number  Tracking number.
	 * @param string   $carrier Carrier slug.
	 * @return bool
	 */
	public function has_tracking( $order, $number, $carrier ) {
		$number  = strtolower( (string) $number );
		$carrier = strtolower( (string) $carrier );

		foreach ( $this->get_existing_tracking( $order ) as $item ) {
			$existing_number = isset( $item['tracking_number'] ) ? strtolower( (string) $item['tracking_number'] ) : '';

			if ( $existing_number !== $number ) {
				continue;
			}

			$existing_carrier = $this->extract_carrier( $item );

			if ( '' === $existing_carrier || $existing_carrier === $carrier ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads the carrier from a stored tracking item.
	 *
	 * Both plugins fall back to a custom provider name when the shipment used a
	 * carrier that is not in their provider list.
	 *
	 * @since 1.0.0
	 *
	 * @param array $item Stored tracking item.
	 * @return string Lowercased carrier, or an empty string when absent.
	 */
	protected function extract_carrier( array $item ) {
		foreach ( array( 'tracking_provider', 'custom_tracking_provider' ) as $key ) {
			if ( ! empty( $item[ $key ] ) && is_string( $item[ $key ] ) ) {
				return strtolower( $item[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Returns the ship date to record, in the store's local timezone.
	 *
	 * @since 1.0.0
	 * @return string Date in `Y-m-d` format.
	 */
	protected function get_ship_date() {
		return (string) current_time( 'Y-m-d' );
	}
}
