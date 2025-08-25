<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format amount to string with 2 decimals (Braintree safe).
 */
function gf_braintree_format_amount( $amount ): string {
	return number_format( (float) $amount, 2, '.', '' );
}

/**
 * Simple option-based map email -> customer_id (demo storage; replace with custom table if needed).
 */
function gf_braintree_get_customer_id_by_email( string $email ): ?string {
	$map = get_option( 'gf_braintree_email_customer_map', [] );
	return $map[ strtolower( $email ) ] ?? null;
}

function gf_braintree_set_customer_id_for_email( string $email, string $customer_id ): void {
	$map                           = get_option( 'gf_braintree_email_customer_map', [] );
	$map[ strtolower( $email ) ]   = $customer_id;
	update_option( 'gf_braintree_email_customer_map', $map, false );
}