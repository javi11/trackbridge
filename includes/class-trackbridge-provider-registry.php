<?php
/**
 * Holds the known tracking plugin adapters and resolves the active one.
 *
 * @package TrackBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registry of tracking plugin adapters.
 *
 * @since 1.0.0
 */
class Trackbridge_Provider_Registry {

	/**
	 * Settings value meaning "use whichever plugin is active".
	 *
	 * @var string
	 */
	const AUTO = 'auto';

	/**
	 * Registered adapters, keyed by identifier, in preference order.
	 *
	 * @var Trackbridge_Provider[]
	 */
	private $providers = array();

	/**
	 * Builds the registry.
	 *
	 * @since 1.0.0
	 *
	 * @param Trackbridge_Provider[] $providers Adapters in preference order.
	 */
	public function __construct( array $providers ) {
		foreach ( $providers as $provider ) {
			if ( $provider instanceof Trackbridge_Provider ) {
				$this->providers[ $provider->get_id() ] = $provider;
			}
		}
	}

	/**
	 * Returns every registered adapter.
	 *
	 * @since 1.0.0
	 * @return Trackbridge_Provider[]
	 */
	public function get_all() {
		return $this->providers;
	}

	/**
	 * Returns the adapters whose plugin is currently active.
	 *
	 * @since 1.0.0
	 * @return Trackbridge_Provider[]
	 */
	public function get_available() {
		$available = array();

		foreach ( $this->providers as $id => $provider ) {
			if ( $provider->is_available() ) {
				$available[ $id ] = $provider;
			}
		}

		return $available;
	}

	/**
	 * Returns a single adapter by identifier.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Adapter identifier.
	 * @return Trackbridge_Provider|null
	 */
	public function get( $id ) {
		return isset( $this->providers[ $id ] ) ? $this->providers[ $id ] : null;
	}

	/**
	 * Resolves the adapter to use for a given settings preference.
	 *
	 * An explicit preference is only honoured when that plugin is actually
	 * active; otherwise resolution falls back to the first available adapter so
	 * a deactivated plugin does not silently stop tracking from syncing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $preference Adapter identifier or `auto`.
	 * @return Trackbridge_Provider|null Null when no tracking plugin is active.
	 */
	public function resolve( $preference ) {
		$available = $this->get_available();

		if ( empty( $available ) ) {
			return null;
		}

		if ( self::AUTO !== $preference && isset( $available[ $preference ] ) ) {
			return $available[ $preference ];
		}

		return reset( $available );
	}
}
