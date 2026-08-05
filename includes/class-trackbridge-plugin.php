<?php
/**
 * Wires the plugin's services together.
 *
 * @package TrackBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin container.
 *
 * @since 1.0.0
 */
class Trackbridge_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Trackbridge_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Adapter registry.
	 *
	 * @var Trackbridge_Provider_Registry|null
	 */
	private $registry = null;

	/**
	 * Whether init() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Returns the shared instance.
	 *
	 * @since 1.0.0
	 * @return Trackbridge_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers the plugin's hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {
		if ( $this->booted ) {
			return;
		}

		$this->booted   = true;
		$this->registry = new Trackbridge_Provider_Registry(
			/**
			 * Filters the tracking plugin adapters, in preference order.
			 *
			 * The first available adapter wins when the tracking plugin setting is
			 * left on "Automatic".
			 *
			 * @since 1.0.0
			 *
			 * @param Trackbridge_Provider[] $providers Adapters in preference order.
			 */
			apply_filters(
				'trackbridge_providers',
				array(
					new Trackbridge_Provider_AST(),
					new Trackbridge_Provider_WCST(),
				)
			)
		);

		$logger = new Trackbridge_Logger( Trackbridge_Settings::logging_enabled() );

		( new Trackbridge_Sync( $this->registry, $logger ) )->register();

		if ( is_admin() ) {
			( new Trackbridge_Settings( $this->registry ) )->register();
		}
	}

	/**
	 * Returns the adapter registry.
	 *
	 * @since 1.0.0
	 * @return Trackbridge_Provider_Registry|null
	 */
	public function get_registry() {
		return $this->registry;
	}
}
