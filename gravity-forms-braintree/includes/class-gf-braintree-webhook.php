<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal webhook handler (extend as needed).
 */
class GFBraintree_Webhook {

	public static function register(): void {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'gf-braintree/v1',
					'/webhook',
					[
						'methods'             => 'POST',
						'callback'            => [ __CLASS__, 'handle' ],
						'permission_callback' => '__return_true',
					]
				);
			}
		);
	}

	public static function handle( WP_REST_Request $request ) {
		// NOTE: In production verify Braintree signature & payload.
		$body = $request->get_body();
		GFBraintree_Logger::debug( 'Webhook received raw', [ 'body' => $body ] );

		// You would parse $body as per Braintree requirements.
		// Example placeholder (no-op):
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}
}