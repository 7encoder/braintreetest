<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central logger that respects plugin setting 'enable_logging'
 * and integrates with GF logging system if present.
 */
class GFBraintree_Logger {

	private static function can_log(): bool {
		$addon = function_exists( 'gf_braintree' ) ? gf_braintree() : null;
		if ( ! $addon ) {
			return ( defined( 'WP_DEBUG' ) && WP_DEBUG ); // bootstrap fallback
		}
		return (bool) $addon->get_plugin_setting( 'enable_logging' );
	}

	private static function gf_log( string $level, string $message, array $context = [] ): void {
		$prefix = '[' . strtoupper( $level ) . '] ' . $message;
		if ( ! empty( $context ) ) {
			$prefix .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		if ( class_exists( 'GFLogging' ) ) {
			GFLogging::log_message( 'gravity-forms-braintree', $prefix );
			return;
		}
		if ( function_exists( 'gf_braintree_log' ) ) {
			gf_braintree_log( $prefix );
			return;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[GF Braintree] ' . $prefix ); // phpcs:ignore
		}
	}

	public static function debug( string $message, array $context = [] ): void {
		if ( ! self::can_log() ) {
			return;
		}
		self::gf_log( 'debug', $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::gf_log( 'error', $message, $context );
	}
}