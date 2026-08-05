<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Runs inside wp-env, against a real WordPress and WooCommerce install, so the
 * actual `woocommerce_update_order` hook path is exercised rather than a mock.
 *
 * @package TrackBridge
 */

$trackbridge_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $trackbridge_tests_dir ) {
	$trackbridge_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $trackbridge_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite at {$trackbridge_tests_dir}.\n";
	echo "Run this suite through wp-env, for example:\n";
	echo "  npx wp-env run tests-cli --env-cwd=wp-content/plugins/\$(basename \"\$PWD\") vendor/bin/phpunit -c phpunit.integration.xml.dist\n";
	exit( 1 );
}

require_once $trackbridge_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		$plugin_dir   = dirname( __DIR__, 2 );
		$plugins_root = dirname( $plugin_dir );

		/*
		 * wp-env names the plugin directory after the source it was installed
		 * from, so a zip URL yields `woocommerce.latest-stable` rather than
		 * `woocommerce`. Locate the entry file instead of assuming a name.
		 */
		$woocommerce = '';

		foreach ( (array) glob( $plugins_root . '/woocommerce*/woocommerce.php' ) as $candidate ) {
			$woocommerce = $candidate;
			break;
		}

		if ( '' === $woocommerce ) {
			echo "Could not locate WooCommerce in {$plugins_root}.\n";
			exit( 1 );
		}

		require_once $woocommerce;

		/*
		 * AST refuses to load anything at all unless
		 * is_plugin_active( 'woocommerce/woocommerce.php' ) is true, and it checks
		 * that literal path. wp-env installs WooCommerce from a zip, so it lands in
		 * `woocommerce.latest-stable` and the option never contains that path.
		 * Report the canonical path so AST loads its includes and public functions.
		 */
		add_filter(
			'pre_option_active_plugins',
			function () {
				return array( 'woocommerce/woocommerce.php' );
			}
		);

		/*
		 * Load Advanced Shipment Tracking too. The adapter talks to AST's real
		 * API, so it has to be tested against the real plugin — a test double
		 * cannot catch the plugin renaming or moving the functions we call.
		 */
		foreach ( (array) glob( $plugins_root . '/woo-advanced-shipment-tracking*/woocommerce-advanced-shipment-tracking.php' ) as $candidate ) {
			require_once $candidate;
			break;
		}

		require_once $plugin_dir . '/trackbridge.php';
		require_once __DIR__ . '/class-stub-provider.php';

		// Swap in the test double before the registry is built on plugins_loaded.
		add_filter(
			'trackbridge_providers',
			function () {
				return array( Trackbridge_Stub_Provider::instance() );
			}
		);
	}
);

/*
 * Select the order storage engine before WooCommerce installs its schema, so
 * the suite can run against both High-Performance Order Storage and the legacy
 * post tables. CI runs it both ways.
 */
tests_add_filter(
	'setup_theme',
	function () {
		if ( '1' === getenv( 'TRACKBRIDGE_HPOS' ) ) {
			update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );
			update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		}

		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
		}

		/*
		 * Create AST's carrier table, which normally happens on activation.
		 *
		 * Only the table is created, not its contents: AST populates carriers from
		 * api.trackship.com through an Action Scheduler job, so the list is remote
		 * and asynchronous. Tests that need carriers seed rows themselves, which
		 * keeps them hermetic and still exercises AST's real schema and accessor.
		 */
		if ( class_exists( 'WC_Advanced_Shipment_Tracking_Install' ) && is_callable( array( 'WC_Advanced_Shipment_Tracking_Install', 'get_instance' ) ) ) {
			$trackbridge_ast_install = WC_Advanced_Shipment_Tracking_Install::get_instance();

			if ( is_object( $trackbridge_ast_install ) && method_exists( $trackbridge_ast_install, 'create_shippment_tracking_table' ) ) {
				$trackbridge_ast_install->create_shippment_tracking_table();
			}
		}
	}
);

/*
 * Instantiate WooCommerce's mailer during bootstrap.
 *
 * WC_Emails is normally created lazily, the first time a status transition
 * happens. If that first time is inside a test, the `*_notification` actions its
 * constructor registers fall outside WP_UnitTestCase's hook snapshot, so the
 * snapshot restore in tearDown strips them — and because the singleton already
 * exists it never re-registers. Every later test then transitions orders with no
 * email listeners attached. Creating it here puts those hooks in every snapshot.
 */
tests_add_filter(
	'init',
	function () {
		if ( function_exists( 'WC' ) ) {
			WC()->mailer();
		}
	},
	99
);

require $trackbridge_tests_dir . '/includes/bootstrap.php';
