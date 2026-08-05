<?php
/**
 * Settings screen and option accessors.
 *
 * @package TrackBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the TrackBridge settings section and reads its options.
 *
 * @since 1.0.0
 */
class Trackbridge_Settings {

	/**
	 * Settings section slug under WooCommerce > Settings > Shipping.
	 *
	 * @var string
	 */
	const SECTION = 'trackbridge';

	/**
	 * Default bridge field name.
	 *
	 * Must not begin with an underscore: the WooCommerce mobile apps refuse to
	 * create such keys and hide them from the custom fields list.
	 *
	 * @var string
	 */
	const DEFAULT_META_KEY = 'tracking_number';

	/**
	 * Default carrier slug.
	 *
	 * @var string
	 */
	const DEFAULT_CARRIER = 'gls';

	/**
	 * Adapter registry.
	 *
	 * @var Trackbridge_Provider_Registry
	 */
	private $registry;

	/**
	 * Builds the settings service.
	 *
	 * @since 1.0.0
	 *
	 * @param Trackbridge_Provider_Registry $registry Adapter registry.
	 */
	public function __construct( Trackbridge_Provider_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Registers the admin hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_get_sections_shipping', array( $this, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_shipping', array( $this, 'get_settings' ), 10, 2 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_trackbridge_meta_key', array( $this, 'sanitize_meta_key' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( TRACKBRIDGE_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Adds the TrackBridge section to the Shipping settings tab.
	 *
	 * @since 1.0.0
	 *
	 * @param array $sections Existing sections.
	 * @return array
	 */
	public function add_section( $sections ) {
		$sections[ self::SECTION ] = __( 'TrackBridge', 'trackbridge' );

		return $sections;
	}

	/**
	 * Adds a settings shortcut to the plugin list row.
	 *
	 * @since 1.0.0
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::get_settings_url() ),
			esc_html__( 'Settings', 'trackbridge' )
		);

		return $links;
	}

	/**
	 * Returns the URL of the TrackBridge settings section.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_settings_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=shipping&section=' . self::SECTION );
	}

	/**
	 * Returns the settings fields for the TrackBridge section.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $settings        Settings for the current section.
	 * @param string $current_section Section being rendered.
	 * @return array
	 */
	public function get_settings( $settings, $current_section ) {
		if ( self::SECTION !== $current_section ) {
			return $settings;
		}

		$provider = $this->registry->resolve( self::get_provider_preference() );
		$carriers = null === $provider ? array() : $provider->get_carriers();

		return array_merge(
			array(
				array(
					'name' => __( 'TrackBridge', 'trackbridge' ),
					'type' => 'title',
					'desc' => $this->get_status_description( $provider ),
					'id'   => 'trackbridge_options',
				),
				array(
					'name'     => __( 'Custom field name', 'trackbridge' ),
					'desc_tip' => __( 'The order custom field TrackBridge watches. Type this exact name in the mobile app. It cannot start with an underscore, because the mobile apps refuse to create such fields and hide them from the list.', 'trackbridge' ),
					'id'       => 'trackbridge_meta_key',
					'type'     => 'text',
					'default'  => self::DEFAULT_META_KEY,
					'desc'     => __( 'Letters, numbers, dashes and underscores. Must not start with an underscore.', 'trackbridge' ),
				),
				array(
					'name'     => __( 'Tracking plugin', 'trackbridge' ),
					'desc_tip' => __( 'Which tracking plugin receives the number. Automatic uses whichever supported plugin is active.', 'trackbridge' ),
					'id'       => 'trackbridge_provider',
					'type'     => 'select',
					'default'  => Trackbridge_Provider_Registry::AUTO,
					'options'  => $this->get_provider_options(),
				),
			),
			array( $this->get_carrier_field( $carriers ) ),
			array(
				array(
					'name'    => __( 'Mark the order completed', 'trackbridge' ),
					'desc'    => __( 'Complete the order once tracking has been added.', 'trackbridge' ),
					'id'      => 'trackbridge_mark_completed',
					'type'    => 'checkbox',
					'default' => 'yes',
				),
				array(
					'name'     => __( 'Email the customer', 'trackbridge' ),
					'desc'     => __( 'Send the completed-order email, which your tracking plugin fills with the tracking details.', 'trackbridge' ),
					'desc_tip' => __( 'The email is sent even when the order is already completed, so a late tracking number still reaches the customer.', 'trackbridge' ),
					'id'       => 'trackbridge_send_email',
					'type'     => 'checkbox',
					'default'  => 'yes',
				),
				array(
					'name'     => __( 'Clear the field after syncing', 'trackbridge' ),
					'desc'     => __( 'Remove the custom field once the tracking number has been stored.', 'trackbridge' ),
					'desc_tip' => __( 'Keeps orders tidy and leaves the field ready for the next shipment. The value is always kept when syncing fails.', 'trackbridge' ),
					'id'       => 'trackbridge_delete_after_sync',
					'type'     => 'checkbox',
					'default'  => 'yes',
				),
				array(
					'name'     => __( 'Allow a carrier prefix', 'trackbridge' ),
					'desc'     => __( 'Let the value choose the carrier, for example GLS:1234567890.', 'trackbridge' ),
					'desc_tip' => __( 'Only carrier names your tracking plugin knows are accepted. Without this the configured carrier is always used.', 'trackbridge' ),
					'id'       => 'trackbridge_allow_carrier_override',
					'type'     => 'checkbox',
					'default'  => 'no',
				),
				array(
					'name'    => __( 'Accept several parcels', 'trackbridge' ),
					'desc'    => __( 'Treat commas and semicolons as separators, for example 111111, 222222.', 'trackbridge' ),
					'id'      => 'trackbridge_split_multiple',
					'type'    => 'checkbox',
					'default' => 'yes',
				),
				array(
					'name'     => __( 'Log every sync', 'trackbridge' ),
					'desc'     => __( 'Record successful syncs in order notes and WooCommerce logs.', 'trackbridge' ),
					'desc_tip' => __( 'Failures are always recorded, whether or not this is enabled.', 'trackbridge' ),
					'id'       => 'trackbridge_logging',
					'type'     => 'checkbox',
					'default'  => 'no',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'trackbridge_options',
				),
			)
		);
	}

	/**
	 * Builds the status panel shown above the fields.
	 *
	 * @since 1.0.0
	 *
	 * @param Trackbridge_Provider|null $provider Resolved adapter, if any.
	 * @return string HTML description.
	 */
	private function get_status_description( $provider ) {
		$lines = array(
			esc_html__( 'Open an order in the WooCommerce mobile app, add the custom field below, and TrackBridge turns it into real shipment tracking.', 'trackbridge' ),
		);

		if ( null === $provider ) {
			$lines[] = '<strong>' . esc_html__( 'No supported tracking plugin is active.', 'trackbridge' ) . '</strong> '
				. esc_html__( 'Install Advanced Shipment Tracking or WooCommerce Shipment Tracking to start syncing.', 'trackbridge' );
		} else {
			$lines[] = sprintf(
				/* translators: %s: tracking plugin name. */
				esc_html__( 'Detected tracking plugin: %s', 'trackbridge' ),
				'<strong>' . esc_html( $provider->get_label() ) . '</strong>'
			);
		}

		$lines[] = sprintf(
			/* translators: %s: custom field name. */
			esc_html__( 'Field to add in the app: %s', 'trackbridge' ),
			'<code>' . esc_html( self::get_meta_key() ) . '</code>'
		);

		$lines[] = sprintf(
			/* translators: %s: enabled or disabled. */
			esc_html__( 'High-Performance Order Storage: %s', 'trackbridge' ),
			esc_html( self::is_hpos_enabled() ? __( 'enabled', 'trackbridge' ) : __( 'disabled', 'trackbridge' ) )
		);

		return implode( '<br />', $lines );
	}

	/**
	 * Returns the tracking plugin select options.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_provider_options() {
		$options = array(
			Trackbridge_Provider_Registry::AUTO => __( 'Automatic', 'trackbridge' ),
		);

		foreach ( $this->registry->get_all() as $id => $provider ) {
			$label = $provider->get_label();

			if ( ! $provider->is_available() ) {
				$label = sprintf(
					/* translators: %s: tracking plugin name. */
					__( '%s (not active)', 'trackbridge' ),
					$label
				);
			}

			$options[ $id ] = $label;
		}

		return $options;
	}

	/**
	 * Builds the carrier field, as a dropdown when carriers can be listed.
	 *
	 * @since 1.0.0
	 *
	 * @param array $carriers Known carriers, slug => label.
	 * @return array Settings field definition.
	 */
	private function get_carrier_field( array $carriers ) {
		$field = array(
			'name'     => __( 'Carrier', 'trackbridge' ),
			'desc_tip' => __( 'The carrier recorded for every tracking number.', 'trackbridge' ),
			'id'       => 'trackbridge_carrier',
			'default'  => self::DEFAULT_CARRIER,
		);

		if ( empty( $carriers ) ) {
			$field['type'] = 'text';
			$field['desc'] = __( 'Your tracking plugin does not expose a carrier list, so enter the carrier name exactly as that plugin spells it.', 'trackbridge' );

			return $field;
		}

		$current = self::get_carrier();

		// Never silently drop a saved carrier the current plugin does not list.
		if ( '' !== $current && ! isset( $carriers[ $current ] ) ) {
			$carriers = array( $current => $current ) + $carriers;
		}

		$field['type']    = 'select';
		$field['options'] = $carriers;

		return $field;
	}

	/**
	 * Validates the bridge field name before it is saved.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value     Sanitised value about to be saved.
	 * @param array $option    Option definition.
	 * @param mixed $raw_value Raw submitted value.
	 * @return string
	 */
	public function sanitize_meta_key( $value, $option, $raw_value ) {
		unset( $option );

		$submitted  = trim( (string) $raw_value );
		$normalized = self::normalize_meta_key( $submitted );

		if ( '' === $submitted ) {
			self::add_error( __( 'TrackBridge: the custom field name cannot be empty, so the previous value was kept.', 'trackbridge' ) );

			return self::get_meta_key();
		}

		if ( 0 === strpos( $submitted, '_' ) ) {
			self::add_error(
				__( 'TrackBridge: the custom field name cannot start with an underscore. The WooCommerce mobile apps refuse to create those fields and hide them from the custom fields list, so the value was corrected.', 'trackbridge' )
			);
		} elseif ( $normalized !== $submitted ) {
			self::add_error(
				__( 'TrackBridge: the custom field name may only contain letters, numbers, dashes and underscores, so it was corrected.', 'trackbridge' )
			);
		}

		if ( '' === $normalized ) {
			self::add_error( __( 'TrackBridge: the custom field name was unusable, so the default was restored.', 'trackbridge' ) );

			return self::DEFAULT_META_KEY;
		}

		return $normalized;
	}

	/**
	 * Surfaces a settings error when WooCommerce's helper is available.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message to show.
	 * @return void
	 */
	private static function add_error( $message ) {
		if ( class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error( $message );
		}
	}

	/**
	 * Strips everything that cannot appear in a usable bridge field name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Candidate field name.
	 * @return string Normalised name, or an empty string when nothing usable remains.
	 */
	public static function normalize_meta_key( $key ) {
		$key = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $key );
		$key = is_string( $key ) ? ltrim( $key, '_' ) : '';

		return $key;
	}

	/**
	 * Returns the bridge field name.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_meta_key() {
		$key = self::normalize_meta_key( get_option( 'trackbridge_meta_key', self::DEFAULT_META_KEY ) );

		return '' === $key ? self::DEFAULT_META_KEY : $key;
	}

	/**
	 * Returns the configured tracking plugin preference.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_provider_preference() {
		return (string) get_option( 'trackbridge_provider', Trackbridge_Provider_Registry::AUTO );
	}

	/**
	 * Returns the configured carrier.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_carrier() {
		return trim( (string) get_option( 'trackbridge_carrier', self::DEFAULT_CARRIER ) );
	}

	/**
	 * Whether the order should be completed after syncing.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function marks_completed() {
		return 'yes' === get_option( 'trackbridge_mark_completed', 'yes' );
	}

	/**
	 * Whether the customer should be emailed after syncing.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function sends_email() {
		return 'yes' === get_option( 'trackbridge_send_email', 'yes' );
	}

	/**
	 * Whether the bridge field is removed after a successful sync.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function deletes_after_sync() {
		return 'yes' === get_option( 'trackbridge_delete_after_sync', 'yes' );
	}

	/**
	 * Whether a `Carrier: number` prefix may change the carrier.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function allows_carrier_override() {
		return 'yes' === get_option( 'trackbridge_allow_carrier_override', 'no' );
	}

	/**
	 * Whether commas and semicolons separate multiple parcels.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function splits_multiple() {
		return 'yes' === get_option( 'trackbridge_split_multiple', 'yes' );
	}

	/**
	 * Whether successful syncs are logged.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function logging_enabled() {
		return 'yes' === get_option( 'trackbridge_logging', 'no' );
	}

	/**
	 * Whether WooCommerce is using High-Performance Order Storage.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_hpos_enabled() {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
