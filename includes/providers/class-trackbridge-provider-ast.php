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
 * Uses `add_tracking_item()`, which exists in the free plugin as well as Pro.
 * The Pro-only `ast_insert_tracking_number()` helper is deliberately avoided so
 * the free tier is fully supported, and AST's own `insert_tracking_item()` is
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
	 * Transient caching the carrier list.
	 *
	 * @var string
	 */
	const CARRIERS_TRANSIENT = 'trackbridge_ast_carriers';

	/**
	 * How long the carrier list stays cached, in seconds.
	 *
	 * @var int
	 */
	const CARRIERS_TTL = 43200;

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
	 * Whether AST is active and exposes the API we rely on.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_available() {
		return null !== $this->get_ast_instance();
	}

	/**
	 * Returns AST's carriers as a `ts_slug => provider_name` map.
	 *
	 * AST's own admin dropdown stores `ts_slug` as the tracking provider, so
	 * TrackBridge must store the same value for tracking links to resolve.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_carriers() {
		$cached = get_transient( self::CARRIERS_TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$carriers = $this->fetch_carriers();

		set_transient( self::CARRIERS_TRANSIENT, $carriers, self::CARRIERS_TTL );

		return $carriers;
	}

	/**
	 * Writes a tracking number onto an order via AST.
	 *
	 * `status_shipped` is intentionally omitted from the arguments: when it is
	 * set, AST completes the order itself on a separate order instance, which
	 * would race with TrackBridge's own save. TrackBridge handles the status
	 * transition so the behaviour matches the WooCommerce Shipment Tracking
	 * adapter exactly.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order   Order to add tracking to.
	 * @param string   $number  Tracking number.
	 * @param string   $carrier Carrier slug (AST `ts_slug`).
	 * @return bool True when the tracking number was stored.
	 */
	public function add_tracking( $order, $number, $carrier ) {
		$instance = $this->get_ast_instance();

		if ( null === $instance || ! $order instanceof WC_Order ) {
			return false;
		}

		$result = $instance->add_tracking_item(
			$order->get_id(),
			array(
				'tracking_provider' => $carrier,
				'tracking_number'   => $number,
				'date_shipped'      => $this->get_ship_date(),
			)
		);

		return is_array( $result ) && ! empty( $result['tracking_number'] );
	}

	/**
	 * Returns AST's main instance when the expected API is present.
	 *
	 * @since 1.0.0
	 * @return object|null
	 */
	private function get_ast_instance() {
		if ( ! function_exists( 'wc_advanced_shipment_tracking' ) ) {
			return null;
		}

		$instance = wc_advanced_shipment_tracking();

		if ( ! is_object( $instance ) || ! method_exists( $instance, 'add_tracking_item' ) ) {
			return null;
		}

		return $instance;
	}

	/**
	 * Reads the carrier list straight from AST's provider table.
	 *
	 * AST has no public accessor that returns the full list in a stable shape,
	 * so the table is read directly and the result cached in a transient.
	 *
	 * @since 1.0.0
	 * @return array Map of `ts_slug` => provider name.
	 */
	private function fetch_carriers() {
		global $wpdb;

		$table = $this->get_providers_table();

		if ( '' === $table ) {
			return array();
		}

		/*
		 * A table name cannot be a prepared placeholder. It is safe here because
		 * get_providers_table() only returns a name matching a strict allowlist
		 * that also exists in the database, and the result is cached by the caller.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT provider_name, ts_slug FROM `{$table}` ORDER BY provider_name ASC" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$carriers = array();

		foreach ( $rows as $row ) {
			if ( empty( $row->ts_slug ) || empty( $row->provider_name ) ) {
				continue;
			}

			$carriers[ (string) $row->ts_slug ] = (string) $row->provider_name;
		}

		return $carriers;
	}

	/**
	 * Resolves and validates AST's provider table name.
	 *
	 * AST computes its own table name (it points multisite subsites at the main
	 * blog's table), so its value is preferred over rebuilding the name here.
	 *
	 * @since 1.0.0
	 * @return string Validated table name, or an empty string when unusable.
	 */
	private function get_providers_table() {
		global $wpdb;

		$instance = $this->get_ast_instance();
		$table    = $wpdb->prefix . 'woo_shippment_provider';

		if ( null !== $instance && isset( $instance->table ) && is_string( $instance->table ) && '' !== $instance->table ) {
			$table = $instance->table;
		}

		// Only ever accept a plain identifier; never anything that could break out of the query.
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check for a third-party table.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $exists === $table ? $table : '';
	}
}
