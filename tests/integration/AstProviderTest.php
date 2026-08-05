<?php
/**
 * Integration tests for the Advanced Shipment Tracking adapter.
 *
 * @package TrackBridge
 */

/**
 * Exercises the AST adapter against the real Advanced Shipment Tracking plugin.
 *
 * These tests exist because a test double cannot catch the adapter calling an
 * API that does not exist. Version 1.0.0 shipped with `is_available()` checking
 * for `add_tracking_item()` on the object returned by
 * `wc_advanced_shipment_tracking()` — but that object is the main plugin class,
 * and the method lives on `WC_Advanced_Shipment_Tracking_Actions`. The adapter
 * therefore reported "no supported tracking plugin is active" on every store
 * that had AST active.
 */
class AstProviderTest extends WP_UnitTestCase {

	/**
	 * Adapter under test.
	 *
	 * @var Trackbridge_Provider_AST
	 */
	private $provider;

	/**
	 * Builds a fresh adapter for each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->provider = new Trackbridge_Provider_AST();

		/*
		 * AST memoises its carrier list on a singleton. The per-test transaction
		 * rolls back the table but not that in-memory copy, so it has to be
		 * cleared or carriers leak between tests.
		 */
		$this->reset_ast_provider_cache();
	}

	/**
	 * Clears AST's memoised carrier list.
	 *
	 * @return void
	 */
	private function reset_ast_provider_cache() {
		$actions = WC_Advanced_Shipment_Tracking_Actions::get_instance();

		$property = new ReflectionProperty( $actions, 'providers' );
		$property->setAccessible( true );
		$property->setValue( $actions, array() );
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

	public function test_advanced_shipment_tracking_is_actually_loaded() {
		$this->assertTrue(
			class_exists( 'WC_Advanced_Shipment_Tracking_Actions' ),
			'The AST plugin must be present for this suite to mean anything.'
		);
	}

	public function test_the_adapter_reports_itself_available() {
		$this->assertTrue(
			$this->provider->is_available(),
			'AST is active, so the adapter must detect it.'
		);
	}

	public function test_the_registry_resolves_ast_automatically() {
		$registry = new Trackbridge_Provider_Registry(
			array( new Trackbridge_Provider_AST(), new Trackbridge_Provider_WCST() )
		);

		$resolved = $registry->resolve( Trackbridge_Provider_Registry::AUTO );

		$this->assertNotNull( $resolved, 'A store with AST active must resolve a provider.' );
		$this->assertSame( 'ast', $resolved->get_id() );
	}

	/**
	 * Inserts carriers into AST's real table.
	 *
	 * AST fills this table from api.trackship.com through an Action Scheduler
	 * job, so seeding it here keeps the test hermetic while still exercising
	 * AST's real schema and its own get_providers() accessor.
	 *
	 * @param array $carriers Map of ts_slug => provider name.
	 * @return void
	 */
	private function seed_carriers( array $carriers ) {
		global $wpdb;

		$actions = WC_Advanced_Shipment_Tracking_Actions::get_instance();

		foreach ( $carriers as $slug => $name ) {
			$wpdb->insert(
				$actions->table,
				array(
					'provider_name'    => $name,
					'ts_slug'          => $slug,
					'provider_url'     => 'https://example.com/track?n=%1$s',
					'shipping_country' => 'Global',
					'display_in_order' => 1,
				)
			);
		}

		// get_providers() memoises on the singleton, so drop the cached copy.
		$this->reset_ast_provider_cache();
	}

	public function test_carriers_are_listed_from_ast() {
		$this->seed_carriers(
			array(
				'gls'        => 'GLS',
				'dhl-parcel' => 'DHL Parcel',
			)
		);

		$carriers = $this->provider->get_carriers();

		$this->assertNotEmpty( $carriers, 'The adapter must read AST\'s carrier table.' );
		$this->assertSame( 'GLS', $carriers['gls'], 'Carriers map ts_slug to the display name.' );
		$this->assertSame( 'DHL Parcel', $carriers['dhl-parcel'] );
	}

	public function test_the_default_carrier_is_usable_when_ast_lists_it() {
		$this->seed_carriers( array( Trackbridge_Settings::DEFAULT_CARRIER => 'GLS' ) );

		$this->assertArrayHasKey(
			Trackbridge_Settings::DEFAULT_CARRIER,
			$this->provider->get_carriers(),
			'The default carrier slug must survive the mapping.'
		);
	}

	public function test_an_unpopulated_carrier_table_degrades_gracefully() {
		// A freshly activated AST has no carriers until its remote fetch runs.
		$this->assertSame(
			array(),
			$this->provider->get_carriers(),
			'An empty list must not error; the settings screen falls back to free text.'
		);
		$this->assertTrue( $this->provider->is_available(), 'AST is still usable for writing tracking.' );
	}

	public function test_writes_a_tracking_number_into_ast() {
		$order = $this->create_order();

		$added = $this->provider->add_tracking( $order, '1234567890', Trackbridge_Settings::DEFAULT_CARRIER );

		$this->assertTrue( $added, 'The adapter must report a successful write.' );

		$items = wc_get_order( $order->get_id() )->get_meta( '_wc_shipment_tracking_items', true );

		$this->assertIsArray( $items );
		$this->assertCount( 1, $items );
		$this->assertSame( '1234567890', $items[0]['tracking_number'] );
		$this->assertSame( Trackbridge_Settings::DEFAULT_CARRIER, $items[0]['tracking_provider'] );
	}

	public function test_does_not_change_the_order_status_itself() {
		$order = $this->create_order();

		$this->provider->add_tracking( $order, '1234567890', Trackbridge_Settings::DEFAULT_CARRIER );

		$this->assertTrue(
			wc_get_order( $order->get_id() )->has_status( 'processing' ),
			'TrackBridge owns the status transition, not the adapter.'
		);
	}

	public function test_reports_existing_tracking_for_idempotency() {
		$order = $this->create_order();

		$this->provider->add_tracking( $order, '1234567890', Trackbridge_Settings::DEFAULT_CARRIER );

		$fresh = wc_get_order( $order->get_id() );

		$this->assertTrue( $this->provider->has_tracking( $fresh, '1234567890', Trackbridge_Settings::DEFAULT_CARRIER ) );
		$this->assertFalse( $this->provider->has_tracking( $fresh, '9999999999', Trackbridge_Settings::DEFAULT_CARRIER ) );
	}

	public function test_a_full_sync_reaches_ast_through_the_plugin() {
		// Use the real registry rather than the bootstrap's test double.
		add_filter(
			'trackbridge_providers',
			function () {
				return array( new Trackbridge_Provider_AST() );
			},
			20
		);

		$sync = new Trackbridge_Sync(
			new Trackbridge_Provider_Registry( array( new Trackbridge_Provider_AST() ) ),
			new Trackbridge_Logger( false )
		);

		update_option( 'trackbridge_carrier', Trackbridge_Settings::DEFAULT_CARRIER );

		$order = $this->create_order();
		$order->update_meta_data( 'tracking_number', '5556667778' );
		$order->save();

		$sync->maybe_sync( $order->get_id() );

		$fresh = wc_get_order( $order->get_id() );
		$items = $fresh->get_meta( '_wc_shipment_tracking_items', true );

		$this->assertIsArray( $items );
		$this->assertCount( 1, $items );
		$this->assertSame( '5556667778', $items[0]['tracking_number'] );
		$this->assertTrue( $fresh->has_status( 'completed' ) );
		$this->assertSame( '', $fresh->get_meta( 'tracking_number', true ) );
	}
}
