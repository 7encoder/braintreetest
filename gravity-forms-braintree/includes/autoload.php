<?php
/**
 * Thin wrapper autoload file. Replace or modify to include the real Braintree SDK.
 * For example if you copied a Composer vendor directory to includes/vendor:
 *
 * require_once __DIR__ . '/vendor/autoload.php';
 *
 * Ensure class \Braintree\Gateway exists after this require.
 */
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}