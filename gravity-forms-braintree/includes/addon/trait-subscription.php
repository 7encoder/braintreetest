<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscription-related helpers for Braintree Add-On.
 */
trait GFBraintree_AddOn_Subscription_Trait {

	public const META_SUBSCRIPTION_ID        = 'gf_braintree_subscription_id';
	public const META_PLAN_ID                = 'gf_braintree_plan_id';
	public const META_INITIAL_TRANSACTION_ID = 'gf_braintree_initial_transaction_id';

	protected function is_valid_plan_id( string $plan_id ): bool {
		foreach ( $this->get_plan_choices() as $plan ) {
			if ( isset( $plan['id'] ) && (string) $plan['id'] === $plan_id ) {
				return true;
			}
		}
		return false;
	}

	protected function create_braintree_subscription( $feed, $entry, $form ): void {
		// Your earlier, fully working implementation goes here.
		// Ensure ALL of it is inside PHP and inside this method.
	}

	protected function process_subscription_with_dynamic_plan( $feed, $entry, $form ) {
		$this->create_braintree_subscription( $feed, $entry, $form );
		return $entry;
	}

	private function flatten_braintree_errors( $result ): array {
		if ( ! isset( $result->errors ) ) {
			return [];
		}
		$all = $result->errors->deepAll();
		$out = [];
		foreach ( $all as $err ) {
			$out[] = [
				'code'    => $err->code,
				'message' => $err->message,
				'attr'    => $err->attribute,
			];
		}
		return $out;
	}

	/**
	 * Placeholder: replace with actual plan list retrieval.
	 */
	protected function get_plan_choices(): array {
		return [];
	}
}