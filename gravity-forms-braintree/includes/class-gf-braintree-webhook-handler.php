<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBraintree_Webhook_Handler {

	public static function bootstrap(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			'gf-braintree/v1',
			'/webhook',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'verify_challenge' ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'handle_event' ],
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	public static function verify_challenge( WP_REST_Request $request ): WP_REST_Response {
		$challenge = sanitize_text_field( $request->get_param( 'bt_challenge' ) );
		$addon     = gf_braintree();
		if ( ! $challenge || ! $addon || ! $addon->get_api() ) {
			return new WP_REST_Response( [ 'error' => 'Invalid challenge.' ], 400 );
		}
		try {
			$gateway      = $addon->get_api()->get_gateway();
			$verification = $gateway->webhookNotification()->verify( $challenge );
			return new WP_REST_Response( $verification, 200 );
		} catch ( Throwable $e ) {
			GFBraintree_Logger::error( 'Webhook verify error', [ 'error' => $e->getMessage() ] );
			return new WP_REST_Response( [ 'error' => 'Verification failed.' ], 500 );
		}
	}

	public static function handle_event( WP_REST_Request $request ): WP_REST_Response {
		$signature = $request->get_param( 'bt_signature' );
		$payload   = $request->get_param( 'bt_payload' );

		$addon = gf_braintree();
		if ( ! $addon || ! $addon->get_api() ) {
		 return new WP_REST_Response( [ 'error' => 'API not ready.' ], 500 );
		}
		try {
			$gateway      = $addon->get_api()->get_gateway();
			$notification = $gateway->webhookNotification()->parse( $signature, $payload );
		} catch ( Throwable $e ) {
			GFBraintree_Logger::error( 'Webhook parse error', [ 'error' => $e->getMessage() ] );
			return new WP_REST_Response( [ 'error' => 'Parse failed.' ], 400 );
		}

		$kind = $notification->kind;

		if ( isset( $notification->subscription->id ) ) {
			$addon->handle_subscription_webhook(
				$notification->subscription->id,
				$kind,
				$notification
			);
		}

		do_action( 'gf_braintree_webhook_received', $kind, $notification );

		return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}
}