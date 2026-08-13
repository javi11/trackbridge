<?php
/**
 * TrackBridge for WooCommerce
 *
 * @package   TrackBridge
 * @author    javi11
 * @license   GPL-2.0-or-later
 *
 * Plugin Name:          TrackBridge for WooCommerce
 * Plugin URI:           https://github.com/javi11/trackbridge
 * Description:          Add shipment tracking from the official WooCommerce mobile app. Type a tracking number into a plain order custom field and TrackBridge forwards it to Advanced Shipment Tracking or WooCommerce Shipment Tracking.
 * Version:              1.1.0
 * Requires at least:    5.9
 * Requires PHP:         7.4
 * Author:               javi11
 * Author URI:           https://github.com/javi11
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          trackbridge
 * Domain Path:          /languages
 * WC requires at least: 6.0
 * WC tested up to:      11.0
 */

defined( 'ABSPATH' ) || exit;

define( 'TRACKBRIDGE_VERSION', '1.1.0' );
define( 'TRACKBRIDGE_FILE', __FILE__ );
define( 'TRACKBRIDGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TRACKBRIDGE_MIN_WC_VERSION', '6.0' );

require_once TRACKBRIDGE_PATH . 'includes/class-trackbridge-logger.php';
require_once TRACKBRIDGE_PATH . 'includes/class-trackbridge-value-parser.php';
require_once TRACKBRIDGE_PATH . 'includes/class-trackbridge-sync-decision.php';
require_once TRACKBRIDGE_PATH . 'includes/providers/interface-trackbridge-provider.php';
require_once TRACKBRIDGE_PATH . 'includes/providers/class-trackbridge-abstract-provider.php';
require_once TRACKBRIDGE_PATH . 'includes/providers/class-trackbridge-provider-ast.php';
require_once TRACKBRIDGE_PATH . 'includes/providers/class-trackbridge-provider-wcst.php';
require_once TRACKBRIDGE_PATH . 'includes/class-trackbridge-provider-registry.php';
require_once TRACKBRIDGE_PATH . 'includes/class-trackbridge-settings.php';
require_once TRACKBRIDGE_PATH . 'includes/class-trackbridge-sync.php';
require_once TRACKBRIDGE_PATH . 'includes/class-trackbridge-plugin.php';

/**
 * Declares compatibility with WooCommerce High-Performance Order Storage.
 *
 * Must run on `before_woocommerce_init`, before WooCommerce evaluates plugin
 * compatibility. TrackBridge only ever touches orders through the `WC_Order`
 * API, so it is safe under both storage engines.
 *
 * @since 1.0.0
 * @return void
 */
function trackbridge_declare_hpos_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			TRACKBRIDGE_FILE,
			true
		);
	}
}
add_action( 'before_woocommerce_init', 'trackbridge_declare_hpos_compatibility' );

/**
 * Boots the plugin once WooCommerce is known to be loaded.
 *
 * When WooCommerce is missing or too old the plugin stays completely inert and
 * only surfaces an admin notice, rather than fataling on missing classes.
 *
 * @since 1.0.0
 * @return void
 */
function trackbridge_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'trackbridge_render_missing_woocommerce_notice' );
		return;
	}

	if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, TRACKBRIDGE_MIN_WC_VERSION, '<' ) ) {
		add_action( 'admin_notices', 'trackbridge_render_outdated_woocommerce_notice' );
		return;
	}

	Trackbridge_Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'trackbridge_bootstrap', 20 );

/**
 * Renders the admin notice shown when WooCommerce is not active.
 *
 * @since 1.0.0
 * @return void
 */
function trackbridge_render_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'TrackBridge for WooCommerce requires WooCommerce to be installed and active.', 'trackbridge' )
	);
}

/**
 * Renders the admin notice shown when WooCommerce is older than the minimum.
 *
 * @since 1.0.0
 * @return void
 */
function trackbridge_render_outdated_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: minimum supported WooCommerce version. */
				__( 'TrackBridge for WooCommerce requires WooCommerce %s or newer.', 'trackbridge' ),
				TRACKBRIDGE_MIN_WC_VERSION
			)
		)
	);
}
