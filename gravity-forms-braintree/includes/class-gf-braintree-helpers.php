<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Basic option/meta helpers. You can replace with a usermeta table or custom storage if needed.
 */

function gf_braintree_customer_email_key( string $email ): string {
	return 'gf_bt_cust_' . md5( strtolower( $email ) );
}

function gf_braintree_get_customer_id_by_email( string $email ): ?string {
	$key = gf_braintree_customer_email_key( $email );
	$val = get_option( $key );
	return $val ? (string) $val : null;
}

function gf_braintree_set_customer_id_for_email( string $email, string $customer_id ): void {
	update_option( gf_braintree_customer_email_key( $email ), $customer_id, false );
}

/**
 * Map subscription id to entry id.
 */
function gf_braintree_subscription_map_key( string $subscription_id ): string {
	return 'gf_bt_sub_' . md5( $subscription_id );
}

function gf_braintree_map_subscription_entry( string $subscription_id, int $entry_id ): void {
	update_option( gf_braintree_subscription_map_key( $subscription_id ), $entry_id, false );
}

function gf_braintree_get_entry_id_by_subscription( string $subscription_id ): ?int {
	$val = get_option( gf_braintree_subscription_map_key( $subscription_id ) );
	return $val ? (int) $val : null;
}

/**
 * Generate client token (helper wrapper).
 */
function gf_braintree_generate_client_token(): ?string {
	$api = gf_braintree()->get_api();
	return $api ? $api->generate_client_token() : null;
}

/**
 * Amount formatting (Gravity Forms gives numeric already; ensure string format for Braintree).
 */
function gf_braintree_format_amount( $amount ): string {
	return number_format( (float) $amount, 2, '.', '' );
}