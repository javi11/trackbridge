<?php
/**
 * Watches the bridge custom field and forwards it to the tracking plugin.
 *
 * @package TrackBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core sync: custom field in, shipment tracking out.
 *
 * Hooks `woocommerce_update_order`, which fires from both the legacy post data
 * store and High-Performance Order Storage. That single hook therefore covers
 * the mobile app (WooCommerce REST API), wp-admin and programmatic saves,
 * without needing storage-specific or REST-specific hooks.
 *
 * @since 1.0.0
 */
class Trackbridge_Sync {

	/**
	 * Orders currently being processed, keyed by order ID.
	 *
	 * Saving the order re-fires `woocommerce_update_order`, so re-entry has to be
	 * blocked explicitly. Processing also terminates naturally once the field is
	 * deleted, but this guard keeps a partially failed sync from looping.
	 *
	 * @var array<int, bool>
	 */
	private static $in_flight = array();

	/**
	 * Adapter registry.
	 *
	 * @var Trackbridge_Provider_Registry
	 */
	private $registry;

	/**
	 * Logger.
	 *
	 * @var Trackbridge_Logger
	 */
	private $logger;

	/**
	 * Builds the sync service.
	 *
	 * @since 1.0.0
	 *
	 * @param Trackbridge_Provider_Registry $registry Adapter registry.
	 * @param Trackbridge_Logger            $logger   Logger.
	 */
	public function __construct( Trackbridge_Provider_Registry $registry, Trackbridge_Logger $logger ) {
		$this->registry = $registry;
		$this->logger   = $logger;
	}

	/**
	 * Registers the WooCommerce hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_update_order', array( $this, 'maybe_sync' ), 20, 2 );
		add_action( 'woocommerce_new_order', array( $this, 'maybe_sync' ), 20, 2 );

		// Runs before the sync so an order created with a value is still synced.
		add_action( 'woocommerce_new_order', array( $this, 'maybe_seed_field' ), 10, 2 );
	}

	/**
	 * Adds the bridge field, empty, to a newly created order.
	 *
	 * The mobile apps can create custom fields themselves, so this is only a
	 * convenience: it means the field is already listed on the order, and
	 * shipping becomes "tap the value" instead of "type the field name". Both
	 * apps filter custom fields by key prefix and never by value, so an empty
	 * value still shows as an editable row.
	 *
	 * Only new orders are seeded. Re-adding the field after a sync has cleared it
	 * would make a shipped order look unshipped.
	 *
	 * @since 1.1.0
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Optional. Order object, when the hook supplies one.
	 * @return void
	 */
	public function maybe_seed_field( $order_id, $order = null ) {
		if ( ! Trackbridge_Settings::seeds_new_orders() ) {
			return;
		}

		$order_id = absint( $order_id );

		if ( 0 === $order_id || isset( self::$in_flight[ $order_id ] ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$meta_key = Trackbridge_Settings::get_meta_key();

		if ( '' === $meta_key || $order->meta_exists( $meta_key ) ) {
			return;
		}

		$order->update_meta_data( $meta_key, '' );

		// Only the meta is written, so the order status is left untouched.
		$order->save_meta_data();
	}

	/**
	 * Syncs the order when the bridge field holds a value.
	 *
	 * @since 1.0.0
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Optional. Order object, when the hook supplies one.
	 * @return void
	 */
	public function maybe_sync( $order_id, $order = null ) {
		$order_id = absint( $order_id );

		if ( 0 === $order_id || isset( self::$in_flight[ $order_id ] ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$meta_key = Trackbridge_Settings::get_meta_key();

		if ( '' === $meta_key ) {
			return;
		}

		$raw = $order->get_meta( $meta_key, true );

		if ( ! is_scalar( $raw ) || '' === trim( (string) $raw ) ) {
			return;
		}

		self::$in_flight[ $order_id ] = true;

		try {
			$this->sync( $order, $meta_key, (string) $raw );
		} catch ( Exception $exception ) {
			$this->logger->error(
				sprintf(
					/* translators: %s: error message. */
					__( 'Unexpected error while syncing tracking: %s', 'trackbridge' ),
					$exception->getMessage()
				),
				$order
			);
		} finally {
			unset( self::$in_flight[ $order_id ] );
		}
	}

	/**
	 * Runs a sync for an order whose bridge field holds a value.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order    Order to sync.
	 * @param string   $meta_key Bridge field name.
	 * @param string   $raw      Raw field value.
	 * @return void
	 */
	private function sync( WC_Order $order, $meta_key, $raw ) {
		$provider = $this->registry->resolve( Trackbridge_Settings::get_provider_preference() );

		if ( null === $provider ) {
			$this->logger->error(
				__( 'No supported shipment tracking plugin is active, so the tracking number was left in place.', 'trackbridge' ),
				$order
			);
			return;
		}

		$carriers = $provider->get_carriers();
		$intents  = Trackbridge_Value_Parser::parse(
			$raw,
			array(
				'default_carrier'        => Trackbridge_Settings::get_carrier(),
				'allow_carrier_override' => Trackbridge_Settings::allows_carrier_override(),
				'split_multiple'         => Trackbridge_Settings::splits_multiple(),
				'known_carriers'         => $carriers,
			)
		);

		if ( empty( $intents ) ) {
			$this->logger->error(
				sprintf(
					/* translators: %s: the value typed into the custom field. */
					__( 'No usable tracking number could be read from "%s".', 'trackbridge' ),
					$raw
				),
				$order
			);
			return;
		}

		$outcome  = $this->write_tracking( $order, $provider, $intents, $carriers );
		$decision = Trackbridge_Sync_Decision::decide(
			$outcome,
			array(
				'delete'            => Trackbridge_Settings::deletes_after_sync(),
				'mark_completed'    => Trackbridge_Settings::marks_completed(),
				'send_email'        => Trackbridge_Settings::sends_email(),
				'already_completed' => $order->has_status( 'completed' ),
			)
		);

		$this->apply_decision( $order->get_id(), $meta_key, $decision );

		/**
		 * Fires after TrackBridge has finished syncing an order.
		 *
		 * @since 1.0.0
		 *
		 * @param int                  $order_id Order ID.
		 * @param array                $outcome  Counts of added, skipped and failed numbers.
		 * @param array                $decision Follow-up actions that were applied.
		 * @param Trackbridge_Provider $provider Adapter that handled the write.
		 */
		do_action( 'trackbridge_tracking_synced', $order->get_id(), $outcome, $decision, $provider );
	}

	/**
	 * Writes each parsed tracking number through the adapter.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order             $order    Order to add tracking to.
	 * @param Trackbridge_Provider $provider Adapter to write through.
	 * @param array                $intents  Parsed tracking intents.
	 * @param array                $carriers Known carriers, slug => label.
	 * @return array Counts keyed `added`, `skipped` and `failed`.
	 */
	private function write_tracking( WC_Order $order, Trackbridge_Provider $provider, array $intents, array $carriers ) {
		$outcome = array(
			'added'   => 0,
			'skipped' => 0,
			'failed'  => 0,
		);

		/*
		 * Check for existing tracking against freshly loaded data. The instance the
		 * hook hands over can be stale — adapters write tracking through their own
		 * order instance, and a caller holding an older copy can save it again —
		 * and a stale copy would make an already-tracked shipment look new.
		 */
		$known = wc_get_order( $order->get_id() );

		if ( ! $known instanceof WC_Order ) {
			$known = $order;
		}

		foreach ( $intents as $intent ) {
			$number  = $intent['number'];
			$carrier = $intent['carrier'];

			if ( '' === $carrier ) {
				++$outcome['failed'];
				$this->logger->error(
					sprintf(
						/* translators: %s: tracking number. */
						__( 'No carrier is configured, so tracking number %s was not added.', 'trackbridge' ),
						$number
					),
					$order
				);
				continue;
			}

			if ( $provider->has_tracking( $known, $number, $carrier ) ) {
				++$outcome['skipped'];
				continue;
			}

			if ( $provider->add_tracking( $order, $number, $carrier ) ) {
				++$outcome['added'];
				$this->logger->info(
					sprintf(
						/* translators: 1: tracking number, 2: carrier name. */
						__( 'Added tracking number %1$s (%2$s).', 'trackbridge' ),
						$number,
						$this->describe_carrier( $carrier, $carriers )
					),
					$order
				);
				continue;
			}

			++$outcome['failed'];
			$this->logger->error(
				sprintf(
					/* translators: 1: tracking number, 2: carrier name, 3: tracking plugin name. */
					__( 'Failed to add tracking number %1$s (%2$s) via %3$s.', 'trackbridge' ),
					$number,
					$this->describe_carrier( $carrier, $carriers ),
					$provider->get_label()
				),
				$order
			);
		}

		return $outcome;
	}

	/**
	 * Applies the follow-up actions on a freshly loaded order.
	 *
	 * The order is reloaded because the adapter wrote tracking meta through its
	 * own order instance; saving the stale instance from the hook could revert
	 * those writes or the order status.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $order_id Order ID.
	 * @param string $meta_key Bridge field name.
	 * @param array  $decision Follow-up actions from Trackbridge_Sync_Decision.
	 * @return void
	 */
	private function apply_decision( $order_id, $meta_key, array $decision ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $decision['delete_meta'] ) {
			$order->delete_meta_data( $meta_key );
		}

		if ( $decision['complete_order'] ) {
			$note = __( 'Completed by TrackBridge after shipment tracking was added.', 'trackbridge' );

			if ( $decision['suppress_email'] ) {
				$this->without_completed_email(
					function () use ( $order, $note ) {
						$order->update_status( 'completed', $note );
					}
				);
			} else {
				$order->update_status( 'completed', $note );
			}
		} else {
			$order->save();
		}

		if ( $decision['trigger_email'] ) {
			$this->trigger_completed_email( $order );
		}
	}

	/**
	 * Runs a callback with WooCommerce's completed-order email detached.
	 *
	 * @since 1.0.0
	 *
	 * @param callable $callback Callback to run.
	 * @return void
	 */
	private function without_completed_email( callable $callback ) {
		$email   = $this->get_completed_order_email();
		$hook    = 'woocommerce_order_status_completed_notification';
		$removed = false;

		if ( null !== $email ) {
			$removed = remove_action( $hook, array( $email, 'trigger' ) );
		}

		try {
			$callback();
		} finally {
			if ( $removed ) {
				add_action( $hook, array( $email, 'trigger' ) );
			}
		}
	}

	/**
	 * Sends the completed-order email for an order.
	 *
	 * Used when no status transition happens, which is the case for an order
	 * that is already completed or when completing is switched off.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order $order Order to email about.
	 * @return void
	 */
	private function trigger_completed_email( WC_Order $order ) {
		$email = $this->get_completed_order_email();

		if ( null === $email ) {
			$this->logger->error(
				__( 'The completed-order email is unavailable, so no tracking email was sent.', 'trackbridge' ),
				$order
			);
			return;
		}

		$email->trigger( $order->get_id(), $order );
	}

	/**
	 * Returns WooCommerce's customer completed-order email instance.
	 *
	 * @since 1.0.0
	 * @return WC_Email|null
	 */
	private function get_completed_order_email() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$woocommerce = WC();

		if ( ! is_object( $woocommerce ) || ! method_exists( $woocommerce, 'mailer' ) ) {
			return null;
		}

		$mailer = $woocommerce->mailer();

		if ( ! is_object( $mailer ) || ! isset( $mailer->emails['WC_Email_Customer_Completed_Order'] ) ) {
			return null;
		}

		$email = $mailer->emails['WC_Email_Customer_Completed_Order'];

		return method_exists( $email, 'trigger' ) ? $email : null;
	}

	/**
	 * Returns the display name for a carrier slug.
	 *
	 * @since 1.0.0
	 *
	 * @param string $carrier  Carrier slug.
	 * @param array  $carriers Known carriers, slug => label.
	 * @return string
	 */
	private function describe_carrier( $carrier, array $carriers ) {
		return isset( $carriers[ $carrier ] ) ? (string) $carriers[ $carrier ] : (string) $carrier;
	}
}
