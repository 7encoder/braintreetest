<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBraintree_API {

	/** @var Braintree\Gateway */
	protected $gateway;

	public function __construct( array $settings ) {
		$env = $settings['environment'] ?? 'sandbox';

		require_once __DIR__ . '/lib/autoload.php';

		$this->gateway = new Braintree\Gateway(
			[
				'environment' => $env,
				'merchantId'  => $settings['merchantId'] ?? '',
				'publicKey'   => $settings['publicKey'] ?? '',
				'privateKey'  => $settings['privateKey'] ?? '',
			]
		);
	}

	public function get_gateway(): Braintree\Gateway {
		return $this->gateway;
	}

	public function generate_client_token(): ?string {
		try {
			return $this->gateway->clientToken()->generate();
		} catch ( Throwable $e ) {
			GFBraintree_Logger::error( 'Client token generation failed', [ 'error' => $e->getMessage() ] );
			return null;
		}
	}
}