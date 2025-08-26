<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function gf_braintree_format_amount( $amount ): string {
	return number_format( (float) $amount, 2, '.', '' );
}

function gf_braintree_get_customer_id_by_email( string $email ): ?string {
	$email = trim( strtolower( $email ) );
	if ( $email === '' ) {
		return null;
	}
	$map = get_option( 'gf_braintree_email_customer_map', [] );
	return $map[ $email ] ?? null;
}

function gf_braintree_set_customer_id_for_email( string $email, string $customer_id ): void {
	$email = trim( strtolower( $email ) );
	if ( $email === '' || $customer_id === '' ) {
		return;
	}
	$map           = get_option( 'gf_braintree_email_customer_map', [] );
	$map[ $email ] = $customer_id;
	update_option( 'gf_braintree_email_customer_map', $map, false );
}

function gf_braintree_purge_customer_map(): void {
	delete_option( 'gf_braintree_email_customer_map' );
}

function gf_braintree_map_subscription_entry( string $subscription_id, int $entry_id ): void {
	if ( '' === $subscription_id ) {
		return;
	}
	$map = get_option( 'gf_braintree_subscription_entry_map', [] );
	$map[ $subscription_id ] = $entry_id;
	update_option( 'gf_braintree_subscription_entry_map', $map, false );
}

function gf_braintree_get_entry_id_by_subscription( string $subscription_id ): ?int {
	$map = get_option( 'gf_braintree_subscription_entry_map', [] );
	if ( isset( $map[ $subscription_id ] ) ) {
		return (int) $map[ $subscription_id ];
	}
	return null;
}

function gf_braintree_unmap_subscription( string $subscription_id ): void {
	$map = get_option( 'gf_braintree_subscription_entry_map', [] );
	if ( isset( $map[ $subscription_id ] ) ) {
		unset( $map[ $subscription_id ] );
		update_option( 'gf_braintree_subscription_entry_map', $map, false );
	}
}