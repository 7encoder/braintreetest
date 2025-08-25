<?php
/**
 * Plugin Name: Gravity Forms Braintree (Custom)
 * Description: Custom Braintree integration using Hosted Fields.
 * Version: 0.1.4
 * Author: Your Team
 * Requires Plugins: gravityforms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------
 * Constants
 * ------------------------------------------------- */
define( 'GF_BRAINTREE_VERSION', '0.1.4' );
define( 'GF_BRAINTREE_MIN_GF_VERSION', '2.8' ); // Safe even if class no longer uses it.
define( 'GF_BRAINTREE_PLUGIN_FILE', __FILE__ );
define( 'GF_BRAINTREE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GF_BRAINTREE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* -------------------------------------------------
 * Lightweight includes (no GFPaymentAddOn dependency)
 * ------------------------------------------------- */
require_once __DIR__ . '/includes/functions-helpers.php';
require_once __DIR__ . '/includes/class-logger.php';
require_once __DIR__ . '/includes/class-api.php';

/**
 * Internal boot logging helper.
 *
 * @param string $msg Log message.
 */
function gf_braintree_log( $msg ) {
	error_log( '[GF Braintree BOOT] ' . $msg );
}

/**
 * Ensure Gravity Forms payment add-on framework is available.
 *
 * @return bool
 */
function gf_braintree_ensure_payment_framework() : bool {

	if ( class_exists( 'GFPaymentAddOn' ) ) {
		return true;
	}

	if ( ! class_exists( 'GFForms' ) ) {
		gf_braintree_log( 'GFForms not loaded yet.' );
		return false;
	}

	// Standard include.
	if ( method_exists( 'GFForms', 'include_addon_framework' ) ) {
		GFForms::include_addon_framework();
	}

	// Newer explicit payment loader (future-proof).
	if ( ! class_exists( 'GFPaymentAddOn' ) && method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
		GFForms::include_payment_addon_framework();
	}

	// Manual fallback include if still not loaded.
	if ( ! class_exists( 'GFPaymentAddOn' ) && class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'get_base_path' ) ) {
		$base         = GFCommon::get_base_path();
		$addon_file   = $base . '/includes/addon/class-gf-addon.php';
		$payment_file = $base . '/includes/addon/class-gf-payment-addon.php';

		if ( file_exists( $addon_file ) ) {
			require_once $addon_file;
		} else {
			gf_braintree_log( 'Missing file: class-gf-addon.php at ' . $addon_file );
		}

		if ( file_exists( $payment_file ) ) {
			require_once $payment_file;
		} else {
			gf_braintree_log( 'Missing file: class-gf-payment-addon.php at ' . $payment_file );
		}
	}

	if ( class_exists( 'GFPaymentAddOn' ) ) {
		gf_braintree_log( 'GFPaymentAddOn now available.' );
		return true;
	}

	gf_braintree_log( 'GFPaymentAddOn still unavailable after attempts.' );
	return false;
}

/**
 * Load and register the add-on (idempotent).
 */
function gf_braintree_load_addon() {

	static $done = false;
	if ( $done ) {
		return;
	}

	if ( ! gf_braintree_ensure_payment_framework() ) {
		return;
	}

	$files = [
		'includes/addon/trait-plan-cache.php',
		'includes/addon/trait-settings.php',
		'includes/addon/trait-processing.php',
		'includes/addon/trait-subscription.php',
		'includes/class-addon.php',
	];

	foreach ( $files as $rel ) {
		$full = GF_BRAINTREE_PLUGIN_DIR . $rel;
		if ( file_exists( $full ) ) {
			require_once $full;
		} else {
			gf_braintree_log( 'Missing expected add-on file: ' . $rel );
			return; // Abort; partial load is unsafe.
		}
	}

	if ( class_exists( 'GFAddOn' ) && class_exists( 'GFBraintreeAddOn' ) ) {
		GFAddOn::register( 'GFBraintreeAddOn' );
		gf_braintree_log( 'Add-on registered successfully.' );
		$done = true;
	} else {
		gf_braintree_log( 'Add-on classes not found after includes.' );
	}
}

/* -------------------------------------------------
 * Hooks
 * ------------------------------------------------- */

// Primary: after GF declares it's loaded (later priority for safety).
add_action(
	'gform_loaded',
	static function() {
		gf_braintree_log( 'gform_loaded fired.' );
		gf_braintree_load_addon();
	},
	50
);

// Fallback: after all plugins.
add_action(
	'plugins_loaded',
	static function() {
		if ( ! class_exists( 'GFBraintreeAddOn' ) ) {
			gf_braintree_log( 'plugins_loaded fallback executing.' );
			gf_braintree_load_addon();
		}
	},
	100
);

// Final early init fallback (covers unusual load orders or race conditions).
add_action(
	'init',
	static function() {
		if ( ! class_exists( 'GFBraintreeAddOn' ) ) {
			gf_braintree_log( 'init fallback executing.' );
			gf_braintree_load_addon();
		}
	},
	5
);

// Admin notice if still not loaded.
add_action(
	'admin_notices',
	static function() {
		if ( class_exists( 'GFBraintreeAddOn' ) ) {
			return;
		}
		if ( ! class_exists( 'GFForms' ) ) {
			echo '<div class="notice notice-error"><p>Gravity Forms Braintree: Gravity Forms is not active.</p></div>';
			return;
		}
		if ( ! class_exists( 'GFPaymentAddOn' ) ) {
			echo '<div class="notice notice-warning"><p>Gravity Forms Braintree: Unable to load the Gravity Forms payment add-on framework. Reinstall Gravity Forms if this persists.</p></div>';
		}
	}
);

/**
 * Helper accessor.
 *
 * @return GFBraintreeAddOn|null
 */
function gf_braintree() {
	return class_exists( 'GFBraintreeAddOn' ) ? GFBraintreeAddOn::get_instance() : null;
}