<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class GFBraintree_Webhook_Handler {
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}
	public static function routes(): void {
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
	public static function handle( WP_REST_Request $request ) {
		return new WP_REST_Response( [ 'received' => true ], 200 );
	}
}
GFBraintree_Webhook_Handler::init();