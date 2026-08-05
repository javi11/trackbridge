<?php
/**
 * Integration tests for pre-creating the bridge field on new orders.
 *
 * @package TrackBridge
 */

/**
 * Covers seeding the bridge field so it is ready to fill in on a phone.
 *
 * Both mobile apps filter custom fields by key prefix only, never by value, so
 * an empty value still shows as an editable row. The REST test below is the one
 * that actually pins the contract with the app, since that is how the app reads
 * an order.
 */
class SeedFieldTest extends WP_UnitTestCase {

	/**
	 * Provider test double.
	 *
	 * @var Trackbridge_Stub_Provider
	 */
	private $provider;

	/**
	 * Prepares known settings for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->provider = Trackbridge_Stub_Provider::instance();
		$this->provider->reset();

		update_option( 'trackbridge_carrier', 'gls' );
		update_option( 'trackbridge_meta_key', 'tracking_number' );
	}

	/**
	 * Creates an order the way checkout and the REST API do.
	 *
	 * @return WC_Order Freshly loaded order.
	 */
	private function create_order() {
		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	public function test_seeds_the_bridge_field_on_a_new_order() {
		$order = $this->create_order();

		$this->assertTrue(
			$order->meta_exists( 'tracking_number' ),
			'A new order should carry the field, ready to fill in from the app.'
		);
		$this->assertSame( '', $order->get_meta( 'tracking_number', true ) );
	}

	public function test_the_seeded_field_is_visible_through_the_rest_api() {
		$order = $this->create_order();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'meta_data', $data );

		$keys = array();

		foreach ( $data['meta_data'] as $meta ) {
			$meta   = is_object( $meta ) ? $meta->get_data() : $meta;
			$keys[] = $meta['key'];
		}

		$this->assertContains(
			'tracking_number',
			$keys,
			'The app reads orders over the REST API, so the field must appear there.'
		);
	}

	public function test_does_not_seed_when_the_setting_is_disabled() {
		update_option( 'trackbridge_seed_new_orders', 'no' );

		$order = $this->create_order();

		$this->assertFalse( $order->meta_exists( 'tracking_number' ) );
	}

	public function test_seeds_using_the_configured_field_name() {
		update_option( 'trackbridge_meta_key', 'gls_tracking' );

		$order = $this->create_order();

		$this->assertTrue( $order->meta_exists( 'gls_tracking' ) );
		$this->assertFalse( $order->meta_exists( 'tracking_number' ) );
	}

	public function test_does_not_overwrite_a_value_supplied_at_creation() {
		// One single create-save, with the value already on the order.
		$order = new WC_Order();
		$order->set_status( 'processing' );
		$order->update_meta_data( 'tracking_number', '1234567890' );
		$order->save();

		// The value must survive seeding and be synced, not blanked.
		$this->assertNotEmpty( $this->provider->calls, 'The supplied value must still be synced.' );
		$this->assertSame( '1234567890', $this->provider->calls[0]['number'] );
	}

	public function test_seeding_alone_does_not_trigger_a_sync() {
		$this->create_order();

		$this->assertSame(
			array(),
			$this->provider->calls,
			'An empty seeded field must not look like a shipment.'
		);
	}

	public function test_does_not_re_add_the_field_after_a_sync_cleared_it() {
		$order = $this->create_order();

		$order->update_meta_data( 'tracking_number', '1234567890' );
		$order->save();

		$synced = wc_get_order( $order->get_id() );

		$this->assertFalse(
			$synced->meta_exists( 'tracking_number' ),
			'Re-seeding after a sync would make a shipped order look unshipped.'
		);
	}

	public function test_seeding_does_not_change_the_order_status() {
		$order = $this->create_order();

		$this->assertTrue( $order->has_status( 'processing' ) );
	}
}
