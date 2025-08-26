<?php
/**
 * Plugin Name: Gravity Forms Braintree
 * Description: Braintree integration for Gravity Forms (one-time & subscriptions).
 * Version: 1.1.4
 * Author: Your Name
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define a reliable reference to the main plugin file for URL/path building.
if ( ! defined( 'GF_BRAINTREE_PLUGIN_FILE' ) ) {
	define( 'GF_BRAINTREE_PLUGIN_FILE', __FILE__ );
}

add_action(
	'gform_loaded',
	static function () {

		if ( ! class_exists( 'GFForms' ) ) {
			return;
		}

		if ( method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			GFForms::include_payment_addon_framework();
		}

		require_once __DIR__ . '/includes/class-gf-braintree.php';

		if ( function_exists( 'gf_braintree' ) ) {
			gf_braintree();
		}
	},
	20
);