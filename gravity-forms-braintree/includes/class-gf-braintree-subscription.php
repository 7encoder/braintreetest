<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait GFBraintree_Subscription_Trait {

	protected function resolve_plan_id( $feed, $entry, $form ): ?string {
		$source = rgar( $feed, 'meta/subscription_plan_source' );
		if ( 'field' === $source ) {
			$field_id = rgar( $feed, 'meta/plan_field' );
			if ( $field_id ) {
				$value = rgar( $entry, (string) $field_id );
				return $value ? (string) $value : null;
			}
			return null;
		}
		return rgar( $feed, 'meta/plan_id' ) ?: null;
	}

	protected function build_customer_data( $feed, $form, $entry ): array {
		$first = rgar( $entry, rgar( $feed, 'meta/customer_field_map_first_name' ) );
		$last  = rgar( $entry, rgar( $feed, 'meta/customer_field_map_last_name' ) );
		$email = rgar( $entry, rgar( $feed, 'meta/customer_field_map_email' ) );
		$data  = [];

		if ( $first || $last ) {
			$data['firstName'] = $first ?: '';
			$data['lastName']  = $last ?: '';
		}
		if ( $email ) {
			$data['email'] = $email;
		}
		$company = rgar( $entry, rgar( $feed, 'meta/customer_field_map_company' ) );
		if ( $company ) {
			$data['company'] = $company;
		}
		$phone = rgar( $entry, rgar( $feed, 'meta/customer_field_map_phone' ) );
		if ( $phone ) {
			$data['phone'] = $phone;
		}
		return $data;
	}

	protected function build_billing_address( $feed, $form, $entry ): array {
		$map = [
			'streetAddress'   => 'billing_address_field_map_street',
			'extendedAddress' => 'billing_address_field_map_street2',
			'locality'        => 'billing_address_field_map_city',
			'region'          => 'billing_address_field_map_state',
			'postalCode'      => 'billing_address_field_map_postal',
			'countryName'     => 'billing_address_field_map_country',
		];
		$out = [];
		foreach ( $map as $target => $meta_key ) {
			$field_id = rgar( $feed, 'meta/' . $meta_key );
			if ( $field_id ) {
				$value = rgar( $entry, (string) $field_id );
				if ( $value ) {
					$out[ $target ] = $value;
				}
			}
		}
		return $out;
	}

	protected function create_braintree_subscription( $feed, $entry, $form ): void {
		$entry_arr = $this->ensure_entry_array( $entry );
		if ( ! $entry_arr ) {
			$this->safe_fail_payment( $entry, __( 'Entry reference error.', 'gravity-forms-braintree' ) );
		 return;
		}

		$nonce = sanitize_text_field( (string) rgpost( 'gf_braintree_nonce' ) );
		if ( ! $nonce ) {
			$this->safe_fail_payment( $entry_arr, __( 'Subscription payment nonce missing.', 'gravity-forms-braintree' ) );
			return;
		}

		$plan_id = $this->resolve_plan_id( $feed, $entry_arr, $form );
		if ( ! $plan_id ) {
			$this->safe_fail_payment( $entry_arr, __( 'Subscription plan not resolved.', 'gravity-forms-braintree' ) );
			return;
		}

		$api = $this->get_api();
		if ( ! $api ) {
			$this->safe_fail_payment( $entry_arr, __( 'Gateway not configured.', 'gravity-forms-braintree' ) );
			return;
		}

		$gateway       = $api->get_gateway();
		$customer_data = $this->build_customer_data( $feed, $form, $entry_arr );
		$billing       = $this->build_billing_address( $feed, $form, $entry_arr );
		$enable_vault  = (bool) $this->get_plugin_setting( 'enable_vault' );
		$email         = $customer_data['email'] ?? '';

		$customer_id   = null;
		$payment_token = null;

		try {
			if ( $enable_vault && $email ) {
				$existing = gf_braintree_get_customer_id_by_email( $email );
				if ( $existing ) {
					$customer_id = $existing;
					$pResult     = $gateway->paymentMethod()->create(
						[
							'customerId'         => $customer_id,
							'paymentMethodNonce' => $nonce,
							'options'            => [ 'makeDefault' => true ],
						]
					);
					if ( ! $pResult->success ) {
						$this->safe_fail_payment( $entry_arr, __( 'Unable to attach payment method.', 'gravity-forms-braintree' ) );
						return;
					}
					$payment_token = $pResult->paymentMethod->token;
				} else {
					$cParams = $customer_data;
					$cParams['paymentMethodNonce'] = $nonce;
					if ( ! empty( $billing ) ) {
						$cParams['creditCard'] = [ 'billingAddress' => $billing ];
					}
					$cResult = $gateway->customer()->create( $cParams );
					if ( ! $cResult->success ) {
						$this->safe_fail_payment( $entry_arr, __( 'Unable to create customer.', 'gravity-forms-braintree' ) );
						return;
					}
					$customer_id   = $cResult->customer->id;
					$payment_token = $cResult->customer->paymentMethods[0]->token ?? null;
					if ( $customer_id ) {
						gf_braintree_set_customer_id_for_email( $email, $customer_id );
					}
				}
			} else {
				$cParams = $customer_data;
				$cParams['paymentMethodNonce'] = $nonce;
				if ( ! empty( $billing ) ) {
					$cParams['creditCard'] = [ 'billingAddress' => $billing ];
				}
				$cResult = $gateway->customer()->create( $cParams );
				if ( ! $cResult->success ) {
					$this->safe_fail_payment( $entry_arr, __( 'Unable to create customer.', 'gravity-forms-braintree' ) );
					return;
				}
				$customer_id   = $cResult->customer->id;
				$payment_token = $cResult->customer->paymentMethods[0]->token ?? null;
			}

			if ( ! $payment_token ) {
				$this->safe_fail_payment( $entry_arr, __( 'Payment method token missing.', 'gravity-forms-braintree' ) );
				return;
			}

			$sub_params = [
				'planId'             => $plan_id,
				'paymentMethodToken' => $payment_token,
			];

			$merchant_account_id = $this->get_plugin_setting( 'merchant_account_id' );
			if ( $merchant_account_id ) {
				$sub_params['merchantAccountId'] = $merchant_account_id;
			}

			$sub_params = apply_filters( 'gf_braintree_subscription_params', $sub_params, $feed, $entry_arr, $form );

			$subResult = $gateway->subscription()->create( $sub_params );
			if ( ! $subResult->success ) {
				$this->safe_fail_payment( $entry_arr, __( 'Subscription creation failed.', 'gravity-forms-braintree' ) );
				return;
			}

			$subscription_id = $subResult->subscription->id;
			gform_update_meta( $entry_arr['id'], self::META_SUBSCRIPTION_ID, $subscription_id );
			gform_update_meta( $entry_arr['id'], self::META_PLAN_ID, $plan_id );
			if ( $customer_id ) {
				gform_update_meta( $entry_arr['id'], self::META_CUSTOMER_ID, $customer_id );
			}
			gf_braintree_map_subscription_entry( $subscription_id, (int) $entry_arr['id'] );

			$txn         = $subResult->subscription->transactions[0] ?? null;
			$initial_id  = $txn ? $txn->id : $subscription_id;
			$initial_amt = $txn ? $txn->amount : $this->get_product_transaction_amount( $form, $entry_arr );

			if ( $txn ) {
				gform_update_meta( $entry_arr['id'], self::META_TRANSACTION_ID, $txn->id );
			}

			$this->start_subscription(
				$entry_arr,
				[
					'payment_status'  => 'Active',
					'subscription_id' => $subscription_id,
					'transaction_id'  => $initial_id,
					'amount'          => $initial_amt,
					'note'            => 'Subscription started.',
				]
			);

			do_action( 'gf_braintree_after_subscription_start', $entry_arr, $feed, $form, $subscription_id );

		} catch ( Throwable $e ) {
			GFBraintree_Logger::error( 'Subscription exception', [ 'error' => $e->getMessage() ] );
			$this->safe_fail_payment( $entry_arr, __( 'Subscription processing error.', 'gravity-forms-braintree' ) );
		}
	}

	protected function process_subscription_with_dynamic_plan( $feed, $entry, $form ) {
		$entry_arr = $this->ensure_entry_array( $entry );
		if ( ! $entry_arr ) {
			$this->safe_fail_payment( $entry, __( 'Entry reference error.', 'gravity-forms-braintree' ) );
			return $entry;
		}
		$this->create_braintree_subscription( $feed, $entry_arr, $form );
		return $entry_arr;
	}
}