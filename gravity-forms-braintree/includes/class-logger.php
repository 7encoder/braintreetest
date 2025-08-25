<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBraintree_Logger {

	protected static function can_use_gf(): bool {
		return class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'log_debug' );
	}

	public static function debug( $message, array $context = [] ) {
		$line = '[GF Braintree] ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' );
		if ( self::can_use_gf() ) {
			GFCommon::log_debug( $line );
		} else {
			error_log( $line );
		}
	}

	public static function error( $message, array $context = [] ) {
		$line = '[GF Braintree ERROR] ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' );
		if ( self::can_use_gf() ) {
			GFCommon::log_error( $line );
		} else {
			error_log( $line );
		}
	}
}