<?php
/**
 * Integration tests for the real WooCommerce hook path.
 *
 * @package TrackBridge
 */

/**
 * Exercises the sync end to end against a real order save.
 */
class SyncIntegrationTest extends WP_UnitTestCase {

	/**
	 * Provider test double.
	 *
	 * @var Trackbridge_Stub_Provider
	 */
	private $provider;

	/**
	 * Prepares a clean provider and known settings for each test.
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
	 * Creates a processing order.
	 *
	 * @return WC_Order
	 */
	private function create_order() {
		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Writes a value into the bridge field and saves, as the mobile app does.
	 *
	 * @param WC_Order $order Order to update.
	 * @param string   $value Value to store.
	 * @param string   $key   Optional. Field name.
	 * @return WC_Order Freshly loaded order.
	 */
	private function set_field( WC_Order $order, $value, $key = 'tracking_number' ) {
		$order->update_meta_data( $key, $value );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Returns the stored tracking items for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	private function get_tracking_items( $order_id ) {
		$order = wc_get_order( $order_id );
		$items = $order->get_meta( '_wc_shipment_tracking_items', true );

		return is_array( $items ) ? $items : array();
	}

	public function test_the_expected_order_storage_is_active() {
		$expected_hpos = '1' === getenv( 'TRACKBRIDGE_HPOS' );

		$this->assertSame(
			$expected_hpos,
			Trackbridge_Settings::is_hpos_enabled(),
			'The suite must run against the storage engine the CI matrix selected.'
		);
	}

	public function test_writes_tracking_from_the_custom_field() {
		$order = $this->create_order();
		$order = $this->set_field( $order, '1234567890' );

		$items = $this->get_tracking_items( $order->get_id() );

		$this->assertCount( 1, $items );
		$this->assertSame( '1234567890', $items[0]['tracking_number'] );
		$this->assertSame( 'gls', $items[0]['tracking_provider'] );
	}

	public function test_removes_the_custom_field_after_syncing() {
		$order = $this->create_order();
		$order = $this->set_field( $order, '1234567890' );

		$this->assertSame( '', $order->get_meta( 'tracking_number', true ) );
	}

	public function test_completes_the_order() {
		$order = $this->create_order();
		$order = $this->set_field( $order, '1234567890' );

		$this->assertTrue( $order->has_status( 'completed' ) );
	}

	public function test_does_not_revert_the_status_written_by_the_provider() {
		$order    = $this->create_order();
		$order_id = $order->get_id();

		$this->set_field( $order, '1234567890' );

		// Reload from scratch: a stale-instance save would have put it back.
		$reloaded = wc_get_order( $order_id );

		$this->assertTrue( $reloaded->has_status( 'completed' ) );
		$this->assertCount( 1, $this->get_tracking_items( $order_id ) );
	}

	public function test_leaves_the_status_alone_when_completion_is_disabled() {
		update_option( 'trackbridge_mark_completed', 'no' );

		$order = $this->create_order();
		$order = $this->set_field( $order, '1234567890' );

		$this->assertTrue( $order->has_status( 'processing' ) );
		$this->assertCount( 1, $this->get_tracking_items( $order->get_id() ) );
	}

	public function test_adds_one_tracking_item_per_parcel() {
		$order = $this->create_order();
		$order = $this->set_field( $order, '111111, 222222' );

		$items = $this->get_tracking_items( $order->get_id() );

		$this->assertCount( 2, $items );
		$this->assertSame( '111111', $items[0]['tracking_number'] );
		$this->assertSame( '222222', $items[1]['tracking_number'] );
	}

	public function test_does_not_duplicate_tracking_on_a_repeated_save() {
		update_option( 'trackbridge_delete_after_sync', 'no' );

		$order = $this->create_order();
		$order = $this->set_field( $order, '1234567890' );

		// Saving again re-runs the sync with the value still in place.
		$order->save();

		$this->assertCount( 1, $this->get_tracking_items( $order->get_id() ) );
		$this->assertCount( 1, $this->provider->calls, 'The second pass must skip, not re-add.' );
	}

	public function test_does_not_duplicate_when_a_stale_order_instance_is_saved_again() {
		$order = $this->create_order();

		$order->update_meta_data( 'tracking_number', '1234567890' );
		$order->save();

		/*
		 * $order is now stale: the sync deleted the field and wrote tracking
		 * through other instances. Saving it again writes the old value back,
		 * which must not produce a second tracking item.
		 */
		$order->save();

		$this->assertCount(
			1,
			$this->get_tracking_items( $order->get_id() ),
			'Idempotency must not depend on the freshness of the order instance.'
		);
	}

	public function test_does_not_loop_when_the_sync_saves_the_order() {
		$order = $this->create_order();
		$this->set_field( $order, '1234567890' );

		$this->assertCount( 1, $this->provider->calls, 'The recursion guard must keep the sync to a single pass.' );
	}

	public function test_keeps_the_field_when_the_provider_fails() {
		$this->provider->should_fail = true;

		$order = $this->create_order();
		$order = $this->set_field( $order, '1234567890' );

		$this->assertSame( '1234567890', $order->get_meta( 'tracking_number', true ), 'A failed sync must not discard the value.' );
		$this->assertTrue( $order->has_status( 'processing' ) );
		$this->assertSame( array(), $this->get_tracking_items( $order->get_id() ) );
	}

	public function test_ignores_orders_without_the_field() {
		$order = $this->create_order();
		$order->update_meta_data( 'unrelated_field', 'value' );
		$order->save();

		$this->assertSame( array(), $this->provider->calls );
		$this->assertTrue( wc_get_order( $order->get_id() )->has_status( 'processing' ) );
	}

	public function test_ignores_a_blank_field_value() {
		$order = $this->create_order();
		$this->set_field( $order, '   ' );

		$this->assertSame( array(), $this->provider->calls );
	}

	public function test_honours_a_custom_field_name() {
		update_option( 'trackbridge_meta_key', 'gls_tracking' );

		$order = $this->create_order();
		$order = $this->set_field( $order, '1234567890', 'gls_tracking' );

		$this->assertCount( 1, $this->get_tracking_items( $order->get_id() ) );
		$this->assertSame( '', $order->get_meta( 'gls_tracking', true ) );
	}

	public function test_ignores_an_underscore_prefixed_field_name_setting() {
		// The mobile apps cannot create such fields, so the setting falls back.
		update_option( 'trackbridge_meta_key', '_hidden_field' );

		$this->assertSame( 'hidden_field', Trackbridge_Settings::get_meta_key() );
	}

	public function test_records_a_note_when_no_usable_number_is_found() {
		$order = $this->create_order();
		$order = $this->set_field( $order, '--' );

		$this->assertSame( array(), $this->provider->calls );
		$this->assertSame( '--', $order->get_meta( 'tracking_number', true ), 'An unusable value is kept for the shop owner to fix.' );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertNotEmpty( $notes );
	}

	public function test_uses_a_carrier_prefix_only_when_enabled() {
		update_option( 'trackbridge_allow_carrier_override', 'yes' );

		$order = $this->create_order();
		$order = $this->set_field( $order, 'dhl:1234567890' );

		$items = $this->get_tracking_items( $order->get_id() );

		$this->assertCount( 1, $items );
		$this->assertSame( 'dhl', $items[0]['tracking_provider'] );
		$this->assertSame( '1234567890', $items[0]['tracking_number'] );
	}

	public function test_emails_the_customer_when_the_order_completes() {
		reset_phpmailer_instance();

		$order = $this->create_order();
		$order->set_billing_email( 'customer@example.com' );
		$order->save();

		$this->set_field( wc_get_order( $order->get_id() ), '1234567890' );

		$mailer = tests_retrieve_phpmailer_instance();

		$this->assertNotEmpty( $mailer->mock_sent, 'Completing the order should email the customer.' );
	}

	public function test_does_not_email_when_emailing_is_disabled() {
		update_option( 'trackbridge_send_email', 'no' );
		reset_phpmailer_instance();

		$order = $this->create_order();
		$order->set_billing_email( 'customer@example.com' );
		$order->save();

		$order = $this->set_field( wc_get_order( $order->get_id() ), '1234567890' );

		$mailer     = tests_retrieve_phpmailer_instance();
		$recipients = array();

		foreach ( $mailer->mock_sent as $sent ) {
			$recipients[] = $sent['to'][0][0];
		}

		$this->assertTrue( $order->has_status( 'completed' ), 'The order should still complete.' );
		$this->assertNotContains( 'customer@example.com', $recipients, 'The customer must not be emailed.' );
	}
}
