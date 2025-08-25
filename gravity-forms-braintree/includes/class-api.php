<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBraintree_API {

	private array $creds;
	private ?\Braintree\Gateway $gateway = null;

	public function __construct( array $creds ) {
		$this->creds = $creds;
	}

	protected function creds(): array {
		return [
			'environment' => $this->creds['environment'] ?? 'sandbox',
			'merchantId'  => $this->creds['merchant_id'] ?? '',
			'publicKey'   => $this->creds['public_key'] ?? '',
			'privateKey'  => $this->creds['private_key'] ?? '',
		];
	}

	public function get_gateway(): \Braintree\Gateway {
		if ( $this->gateway ) {
			return $this->gateway;
		}
		if ( ! class_exists( '\Braintree\Gateway' ) ) {
			throw new RuntimeException( 'Braintree SDK not loaded.' );
		}
		$this->gateway = new \Braintree\Gateway( $this->creds() );
		return $this->gateway;
	}

	public function get_plans(): array {
		$list = $this->get_gateway()->plan()->all();
		$out  = [];
		foreach ( $list as $plan ) {
			$out[] = [
				'id'          => $plan->id,
				'name'        => $plan->name,
				'price'       => $plan->price,
				'trialPeriod' => (bool) $plan->trialPeriod,
			];
		}
		return $out;
	}

	public function sale_transaction( array $params ) {
		return $this->get_gateway()->transaction()->sale( $params );
	}

	public function submit_for_settlement( string $txn_id, ?string $amount = null ) {
		return $amount
			? $this->get_gateway()->transaction()->submitForSettlement( $txn_id, $amount )
			: $this->get_gateway()->transaction()->submitForSettlement( $txn_id );
	}

	public function refund_transaction( string $txn_id, ?string $amount = null ) {
		return $amount
			? $this->get_gateway()->transaction()->refund( $txn_id, $amount )
			: $this->get_gateway()->transaction()->refund( $txn_id );
	}

	public function void_transaction( string $txn_id ) {
		return $this->get_gateway()->transaction()->void( $txn_id );
	}

	public function create_subscription( array $params ) {
		return $this->get_gateway()->subscription()->create( $params );
	}

	public function cancel_subscription( string $subscription_id ) {
		return $this->get_gateway()->subscription()->cancel( $subscription_id );
	}

	public function generate_client_token(): string {
		return $this->get_gateway()->clientToken()->generate();
	}
}