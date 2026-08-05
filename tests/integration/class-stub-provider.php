<?php
/**
 * Test double standing in for a real tracking plugin.
 *
 * @package TrackBridge
 */

/**
 * Records calls and writes tracking meta the way the real adapters do.
 *
 * Crucially it writes through a *separate* order instance, exactly as Advanced
 * Shipment Tracking does, so the integration tests reproduce the stale-instance
 * hazard that the sync class is designed to avoid.
 */
class Trackbridge_Stub_Provider extends Trackbridge_Abstract_Provider {

	/**
	 * Shared instance so tests can configure the provider the plugin uses.
	 *
	 * @var Trackbridge_Stub_Provider|null
	 */
	private static $instance = null;

	/**
	 * Whether add_tracking() should report failure.
	 *
	 * @var bool
	 */
	public $should_fail = false;

	/**
	 * Carriers this provider claims to support.
	 *
	 * @var array
	 */
	public $carriers = array(
		'gls' => 'GLS',
		'dhl' => 'DHL Parcel',
	);

	/**
	 * Every add_tracking() call, as number => carrier pairs.
	 *
	 * @var array
	 */
	public $calls = array();

	/**
	 * Returns the shared instance.
	 *
	 * @return Trackbridge_Stub_Provider
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Clears recorded state between tests.
	 *
	 * @return void
	 */
	public function reset() {
		$this->should_fail = false;
		$this->calls       = array();
		$this->carriers    = array(
			'gls' => 'GLS',
			'dhl' => 'DHL Parcel',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_id() {
		return 'stub';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Stub Tracking';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function get_carriers() {
		return $this->carriers;
	}

	/**
	 * Records the call and writes tracking meta on a separate order instance.
	 *
	 * @param WC_Order $order   Order to add tracking to.
	 * @param string   $number  Tracking number.
	 * @param string   $carrier Carrier slug.
	 * @return bool
	 */
	public function add_tracking( $order, $number, $carrier ) {
		$this->calls[] = array(
			'number'  => $number,
			'carrier' => $carrier,
		);

		if ( $this->should_fail ) {
			return false;
		}

		$fresh = wc_get_order( $order->get_id() );

		if ( ! $fresh instanceof WC_Order ) {
			return false;
		}

		$items = $fresh->get_meta( self::TRACKING_META_KEY, true );

		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$items[] = array(
			'tracking_provider' => $carrier,
			'tracking_number'   => $number,
			'date_shipped'      => time(),
			'tracking_id'       => md5( $carrier . $number . microtime() ),
		);

		$fresh->update_meta_data( self::TRACKING_META_KEY, $items );
		$fresh->save_meta_data();

		return true;
	}
}
