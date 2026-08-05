<?php
/**
 * Logging for TrackBridge.
 *
 * @package TrackBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log and to order notes.
 *
 * Failures are always recorded: a tracking number entered on a phone that
 * silently fails to sync is worse than a noisy log. Successes are only recorded
 * when the shop has opted into logging.
 *
 * @since 1.0.0
 */
class Trackbridge_Logger {

	/**
	 * Log source shown in WooCommerce > Status > Logs.
	 *
	 * @var string
	 */
	const SOURCE = 'trackbridge';

	/**
	 * Whether verbose success logging is enabled.
	 *
	 * @var bool
	 */
	private $verbose;

	/**
	 * Builds the logger.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $verbose Whether to record successful syncs.
	 */
	public function __construct( $verbose ) {
		$this->verbose = (bool) $verbose;
	}

	/**
	 * Records a successful or informational event.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $message Message to record.
	 * @param WC_Order|null $order   Optional. Order to annotate.
	 * @return void
	 */
	public function info( $message, $order = null ) {
		if ( ! $this->verbose ) {
			return;
		}

		$this->write( 'info', $message );
		$this->note( $order, $message );
	}

	/**
	 * Records a failure, regardless of the logging setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $message Message to record.
	 * @param WC_Order|null $order   Optional. Order to annotate.
	 * @return void
	 */
	public function error( $message, $order = null ) {
		$this->write( 'error', $message );
		$this->note( $order, $message );
	}

	/**
	 * Writes to the WooCommerce logger when it is available.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Message to record.
	 * @return void
	 */
	private function write( $level, $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();

		if ( ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			return;
		}

		$logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Adds a private order note.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Order|null $order   Order to annotate.
	 * @param string        $message Message to record.
	 * @return void
	 */
	private function note( $order, $message ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: log message. */
				__( 'TrackBridge: %s', 'trackbridge' ),
				$message
			)
		);
	}
}
