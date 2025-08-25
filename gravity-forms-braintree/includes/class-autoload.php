<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the Braintree PHP SDK from a single fixed path:
 *   includes/lib/autoload.php
 *
 * You MUST place an autoload file there that ensures class \Braintree\Gateway exists
 * (e.g. a Composer vendor autoload wrapper or manual requires).
 */
class GFBraintree_Dependency_Check {

	/**
	 * Relative path (inside plugin directory) to the SDK autoload file.
	 */
	private const SDK_AUTOLOAD_RELATIVE = 'includes/lib/autoload.php';

	public static function init(): void {
		add_action( 'plugins_loaded', [ __CLASS__, 'load_sdk' ], 1 );
		add_action( 'admin_notices', [ __CLASS__, 'maybe_notice' ] );
	}

	/**
	 * Attempt to load the SDK autoloader and verify presence of \Braintree\Gateway.
	 */
	public static function load_sdk(): void {
		$full = GF_BRAINTREE_PLUGIN_DIR . self::SDK_AUTOLOAD_RELATIVE;

		if ( ! file_exists( $full ) ) {
			error_log( '[GF Braintree] SDK autoload file missing at ' . $full );
			define( 'GF_BRAINTREE_SDK_READY', false );
			return;
		}

		try {
			require_once $full;
		} catch ( \Throwable $e ) {
			error_log( '[GF Braintree] Exception requiring autoload: ' . $e->getMessage() );
			define( 'GF_BRAINTREE_SDK_READY', false );
			return;
		}

		if ( class_exists( '\Braintree\Gateway' ) ) {
			define( 'GF_BRAINTREE_SDK_READY', true );
		} else {
			error_log( '[GF Braintree] Autoload file loaded but \\Braintree\\Gateway not found.' );
			define( 'GF_BRAINTREE_SDK_READY', false );
		}
	}

	public static function maybe_notice(): void {
		if ( defined( 'GF_BRAINTREE_SDK_READY' ) && GF_BRAINTREE_SDK_READY ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'Gravity Forms Braintree:', 'gravity-forms-braintree' ) . '</strong> '
			. esc_html__( 'Braintree PHP SDK not found or failed to initialize. Place a working autoload file at', 'gravity-forms-braintree' )
			. ' <code>includes/lib/autoload.php</code>. '
			. esc_html__( 'After loading, class \\Braintree\\Gateway must exist.', 'gravity-forms-braintree' )
			. '</p></div>';
	}
}

GFBraintree_Dependency_Check::init();