<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dependency checker (single fixed path: includes/lib/autoload.php).
 * You are responsible for providing the SDK autoload file at that location.
 */
class GFBraintree_Dependency_Check {

	private const SDK_AUTOLOAD_RELATIVE = 'includes/lib/autoload.php';

	public static function init(): void {
		add_action( 'plugins_loaded', [ __CLASS__, 'load_sdk' ], 1 );
		add_action( 'admin_notices', [ __CLASS__, 'maybe_notice' ] );
	}

	private static function log( string $message, array $context = [] ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				'[GF Braintree][SDK] ' . $message .
				( $context ? ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '' )
			); // phpcs:ignore
		}
	}

	public static function load_sdk(): void {
		$file = GF_BRAINTREE_PLUGIN_DIR . self::SDK_AUTOLOAD_RELATIVE;

		if ( class_exists( '\Braintree\Gateway' ) ) {
			if ( ! defined( 'GF_BRAINTREE_SDK_READY' ) ) {
				define( 'GF_BRAINTREE_SDK_READY', true );
				self::log( 'Gateway class already present.' );
			}
			return;
		}

		if ( ! file_exists( $file ) ) {
			self::log( 'Autoload file missing', [ 'expected' => $file ] );
			if ( ! defined( 'GF_BRAINTREE_SDK_READY' ) ) {
				define( 'GF_BRAINTREE_SDK_READY', false );
			}
		 return;
		}
		if ( ! is_readable( $file ) ) {
			self::log( 'Autoload file not readable', [ 'path' => $file ] );
			define( 'GF_BRAINTREE_SDK_READY', false );
			return;
		}

		self::log(
			'Including autoload',
			[
				'path'     => $file,
				'filesize' => @filesize( $file ),
				'mtime'    => @date( 'c', @filemtime( $file ) ),
			]
		);

		try {
			require_once $file;
		} catch ( \Throwable $e ) {
			self::log( 'Exception including autoload', [ 'error' => $e->getMessage() ] );
			define( 'GF_BRAINTREE_SDK_READY', false );
			return;
		}

		if ( class_exists( '\Braintree\Gateway' ) ) {
			define( 'GF_BRAINTREE_SDK_READY', true );
			self::log( 'Gateway class found after include.' );
		} else {
			define( 'GF_BRAINTREE_SDK_READY', false );
			self::log( 'Gateway class NOT found after include.' );
		}
	}

	public static function maybe_notice(): void {
		if ( defined( 'GF_BRAINTREE_SDK_READY' ) && GF_BRAINTREE_SDK_READY ) {
			return;
		}
		if ( class_exists( '\Braintree\Gateway' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$file = GF_BRAINTREE_PLUGIN_DIR . self::SDK_AUTOLOAD_RELATIVE;
		echo '<div class="notice notice-error"><p><strong>' .
		     esc_html__( 'Gravity Forms Braintree:', 'gravity-forms-braintree' ) .
		     '</strong> ' .
		     esc_html__( 'Braintree PHP SDK not found or failed to initialize. Place a working autoload file at', 'gravity-forms-braintree' ) .
		     ' <code>includes/lib/autoload.php</code>. ' .
		     esc_html__( 'After loading, class \\Braintree\\Gateway must exist.', 'gravity-forms-braintree' ) .
		     '</p><p><code>' . esc_html( str_replace( ABSPATH, '/', $file ) ) . '</code></p></div>';
	}
}

GFBraintree_Dependency_Check::init();