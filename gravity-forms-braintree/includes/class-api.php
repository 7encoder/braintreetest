<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight wrapper for Braintree Gateway (PHP SDK 6.28.0 compatible).
 */
class GFBraintree_API {

	private ?\Braintree\Gateway $gateway = null;
	private array $config;

	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function get_gateway(): \Braintree\Gateway {
		if ( $this->gateway instanceof \Braintree\Gateway ) {
			return $this->gateway;
		}

		$env = ( $this->config['environment'] ?? 'sandbox' ) === 'production' ? 'production' : 'sandbox';

		$this->gateway = new \Braintree\Gateway(
			[
				'environment' => $env,
				'merchantId'  => $this->config['merchant_id'] ?? '',
				'publicKey'   => $this->config['public_key'] ?? '',
				'privateKey'  => $this->config['private_key'] ?? '',
			]
		);

		return $this->gateway;
	}

	/**
	 * Generate a client token.
	 */
	public function generate_client_token(): string {
		return $this->get_gateway()->clientToken()->generate();
	}

	/**
	 * Fetch plans (subset of reduced fields).
	 */
	public function get_plans(): array {
		$out   = [];
		$plans = $this->get_gateway()->plan()->all();
		foreach ( $plans as $plan ) {
			$out[] = [
				'id'   => $plan->id,
				'name' => $plan->name ?: $plan->id,
			];
		}
		return $out;
	}
}