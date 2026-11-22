<?php
/**
 * WordPress integration tests for BMLT Minutes by Month, with the real BMLT Minutes plugin whose minutes it lists
 * (bin/install-bmlt-minutes.sh puts it in tests/dependencies/).
 *
 *   WP_TESTS_DIR=/path/to/tests-lib vendor/bin/phpunit -c tests/wordpress/phpunit.xml.dist
 *   (WP_MULTISITE=1 runs the same tests on a multisite network.)
 */

$bmbm_root  = dirname( __DIR__, 2 );
$bmbm_tests = getenv( 'WP_TESTS_DIR' );
if ( ! $bmbm_tests || ! file_exists( $bmbm_tests . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Set WP_TESTS_DIR to a WordPress test library (bin/install-wp-tests.sh).\n" );
	exit( 1 );
}
$bmbm_minutes = $bmbm_root . '/tests/dependencies/bmlt-minutes/minutes.php';
if ( ! file_exists( $bmbm_minutes ) ) {
	fwrite( STDERR, "BMLT Minutes is missing: run bin/install-bmlt-minutes.sh.\n" );
	exit( 1 );
}

require_once $bmbm_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $bmbm_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $bmbm_minutes, $bmbm_root ) {
		require $bmbm_minutes;
		require $bmbm_root . '/bmlt-minutes-by-month/bmlt-minutes-by-month.php';
	}
);

require $bmbm_tests . '/includes/bootstrap.php';
