<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBraintree_AJAX {

	public static function bootstrap(): void {
		add_action( 'wp_ajax_gf_braintree_client_token', [ __CLASS__, 'client_token' ] );
		add_action( 'wp_ajax_nopriv_gf_braintree_client_token', [ __CLASS__, 'client_token' ] );
		add_action( 'wp_ajax_gf_braintree_fetch_plans', [ __CLASS__, 'fetch_plans' ] );
	}

	public static function client_token(): void {
		check_ajax_referer( 'gf_braintree_front', '_wpnonce' );
		$addon = gf_braintree();
		if ( ! $addon || ! $addon->get_api() ) {
			wp_send_json_error( [ 'message' => 'API unavailable' ], 500 );
		}
		try {
			$token = $addon->get_api()->generate_client_token();
			wp_send_json_success( [ 'token' => $token ] );
		} catch ( Throwable $e ) {
			wp_send_json_error( [ 'message' => 'Token generation failed' ], 500 );
		}
	}

	public static function fetch_plans(): void {
		check_ajax_referer( 'gf_braintree_feed', 'nonce' );
		$addon = gf_braintree();
		if ( ! $addon || ! $addon->get_api() ) {
			wp_send_json_error( [ 'message' => 'API unavailable' ], 500 );
		}
		if ( isset( $_POST['manual'] ) && (int) $_POST['manual'] === 1 ) {
			$addon->flush_plan_cache();
		}
		$plans = $addon->get_plan_choices();
		wp_send_json_success(
			[
				'empty' => empty( $plans ),
				'plans' => $plans,
			]
		);
	}
}