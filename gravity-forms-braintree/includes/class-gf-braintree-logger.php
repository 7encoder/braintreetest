<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBraintree_Logger {

	protected static function enabled(): bool {
		if ( ! function_exists( 'gf_braintree' ) ) {
			return false;
		}
		$instance = gf_braintree();
		if ( ! $instance || ! method_exists( $instance, 'get_plugin_setting' ) ) {
			return false;
		}
		return (bool) $instance->get_plugin_setting( 'enable_logging' );
	}

	protected static function write( string $level, string $message, array $context = [] ): void {
		$prefix = '[GF Braintree]';
		$line   = $prefix . ' ' . $level . ' ' . $message;
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	public static function debug( string $message, array $context = [] ): void {
		if ( ! self::enabled() ) {
			return;
		}
		self::write( 'DEBUG', $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::write( 'ERROR', $message, $context );
	}
}