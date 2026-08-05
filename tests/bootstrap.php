<?php
/**
 * PHPUnit bootstrap for the unit test suite.
 *
 * The unit suite exercises pure logic only, so WordPress is never loaded.
 * ABSPATH is defined because plugin files guard on it.
 *
 * @package TrackBridge
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'TRACKBRIDGE_VERSION', 'test' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-trackbridge-value-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-trackbridge-sync-decision.php';
