<?php
/**
 * Plugin Name: Gravity Forms Braintree Gateway
 * Description: Braintree payments integration (Hosted Fields + Subscriptions) for Gravity Forms.
 * Version: 1.0.0
 * Author: Your Company
 * Author URI: https://example.com
 * Text Domain: gravity-forms-braintree
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GF_BRAINTREE_PLUGIN_FILE', __FILE__ );
define( 'GF_BRAINTREE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GF_BRAINTREE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Delay loading the Add-On until Gravity Forms has loaded.
 */
add_action( 'gform_loaded', 'gf_braintree_register_addon', 20 );

function gf_braintree_register_addon() {
	if ( ! class_exists( 'GFForms' ) ) {
		return;
	}

	// Include trait(s) first.
	require_once GF_BRAINTREE_PLUGIN_DIR . 'includes/addon/trait-subscription.php';

	// Include the main add-on class.
	require_once GF_BRAINTREE_PLUGIN_DIR . 'includes/class-addon.php';

	if ( class_exists( 'GFAddOn' ) && class_exists( 'GFBraintreeAddOn' ) ) {
		GFAddOn::register( 'GFBraintreeAddOn' );
	}
}

/**
 * Graceful admin notice if Gravity Forms is missing.
 */
add_action( 'admin_notices', function () {
	if ( ! is_admin() ) {
		return;
	}
	// If GF not active and user can activate plugins show notice.
	if ( ! class_exists( 'GFForms' ) && current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Gravity Forms Braintree Gateway requires Gravity Forms to be installed and active.', 'gravity-forms-braintree' );
		echo '</p></div>';
	}
} );

/**
 * Helper accessor (optional).
 */
function gf_braintree() {
	if ( class_exists( 'GFBraintreeAddOn' ) ) {
		return GFBraintreeAddOn::get_instance();
	}
	return null;
}