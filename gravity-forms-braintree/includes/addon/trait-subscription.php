<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait GFBraintree_AddOn_Subscription_Trait {

	public const META_SUBSCRIPTION_ID = 'gf_braintree_subscription_id';
	public const META_PLAN_ID         = 'gf_braintree_plan_id';
	public const META_TRANSACTION_ID  = 'gf_braintree_transaction_id';
	public const META_CUSTOMER_ID     = 'gf_braintree_customer_id';

	protected function create_braintree_subscription( $feed, $entry, $form ) {

		$plan_id = $this->resolve_plan_id( $feed, $entry, $form );
		if ( is_array( $plan_id ) ) {
			$plan_id = reset( $plan_id );
		}
		$plan_id = $plan_id ? trim( (string) $plan_id ) : '';

		$nonce = rgar( $entry, 'braintree_payment_method_nonce' );
		if ( ! $nonce && isset( $_POST['braintree_payment_method_nonce'] ) ) { // phpcs:ignore
			$nonce = sanitize_text_field( wp_unslash( $_POST['braintree_payment_method_nonce'] ) ); // phpcs:ignore
		}

		if ( ! $plan_id || ! $nonce ) {
			$which = ( ! $plan_id && ! $nonce ) ? 'plan and nonce' : ( ! $plan_id ? 'plan' : 'nonce' );
			$this->fail_entry( $entry, sprintf( __( 'Subscription creation failed: missing %s.', 'gravity-forms-braintree' ), $which ) );
			GFBraintree_Logger::error( 'Subscription prerequisites missing', [
				'plan_id' => $plan_id,
				'nonce'   => $nonce ? 'present' : 'missing',
				'entry_id'=> $entry['id'],
			] );
			return $entry;
		}

		$entry[ self::META_PLAN_ID ] = $plan_id;
		GFAPI::update_entry( $entry );

		$customer_data = $this->build_customer_data( $feed, $form, $entry );
		$email         = $customer_data['email'] ?? '';
		$vault_enabled = (bool) $this->get_plugin_setting( 'enable_vault' );

		$existing_customer_id = $vault_enabled && $email ? gf_braintree_get_customer_id_by_email( $email ) : null;
		$customer_id          = null;
		$payment_token        = null;

		try {
			if ( $existing_customer_id ) {
				$params    = [
					'customerId'         => $existing_customer_id,
					'paymentMethodNonce' => $nonce,
					'options'            => [ 'makeDefault' => true ],
				];
				$pm_result = $this->get_api()->get_gateway()->paymentMethod()->create( $params );
				if ( ! $pm_result->success ) {
					throw new RuntimeException( 'Payment method create failed: ' . $pm_result->message );
				}
				$payment_token = $pm_result->paymentMethod->token;
				$customer_id   = $existing_customer_id;
			} else {
				$customer_params = $customer_data;
				$customer_params['paymentMethodNonce'] = $nonce;
				$c_result = $this->get_api()->get_gateway()->customer()->create( $customer_params );
				if ( ! $c_result->success ) {
					throw new RuntimeException( 'Customer create failed: ' . $c_result->message );
				}
				$customer_id = $c_result->customer->id;
				if ( ! empty( $c_result->customer->paymentMethods ) ) {
					$payment_token = $c_result->customer->paymentMethods[0]->token;
				}
				if ( $vault_enabled && $email ) {
					gf_braintree_set_customer_id_for_email( $email, $customer_id );
				}
			}

			if ( ! $payment_token ) {
				throw new RuntimeException( 'No payment method token obtained from nonce.' );
			}

			$sub_params = [
				'paymentMethodToken' => $payment_token,
				'planId'             => $plan_id,
			];
			$sub_params = apply_filters( 'gf_braintree_subscription_params', $sub_params, $entry, $feed, $form );

			$s_result = $this->get_api()->create_subscription( $sub_params );
			if ( ! $s_result->success ) {
				throw new RuntimeException( 'Subscription create failed: ' . $s_result->message );
			}

			$subscription    = $s_result->subscription;
			$subscription_id = $subscription->id;

			$entry[ self::META_SUBSCRIPTION_ID ] = $subscription_id;
			$entry['payment_status']             = 'Active';
			$entry['payment_amount']             = gf_braintree_format_amount( $subscription->price );
			$entry['payment_date']               = gmdate( 'Y-m-d H:i:s' );
			$entry['currency']                   = GFCommon::get_currency();
			if ( $customer_id ) {
				$entry[ self::META_CUSTOMER_ID ] = $customer_id;
			}
			GFAPI::update_entry( $entry );

			$this->add_note(
				$entry['id'],
				sprintf(
					'Braintree subscription created. ID: %s, Plan: %s, Price: %s',
					esc_html( $subscription_id ),
					esc_html( $plan_id ),
					esc_html( gf_braintree_format_amount( $subscription->price ) )
				)
			);

			$this->add_verbose_entry_note( $entry['id'], [
				'type'            => 'Subscription',
				'subscription_id' => $subscription_id,
				'plan_id'         => $plan_id,
				'price'           => gf_braintree_format_amount( $subscription->price ),
				'customer_id'     => $customer_id,
				'card_type'       => $_POST['braintree_card_type'] ?? '',   // phpcs:ignore
				'card_last4'      => $_POST['braintree_card_last4'] ?? '', // phpcs:ignore
			] );

		} catch ( Throwable $e ) {
			GFBraintree_Logger::error( 'Subscription exception', [
				'error'    => $e->getMessage(),
				'plan_id'  => $plan_id,
				'has_nonce'=> (bool) $nonce,
			] );
			$this->fail_entry(
				$entry,
				__( 'Subscription creation failed: ', 'gravity-forms-braintree' ) . $this->filter_user_friendly_message( $e->getMessage() )
			);
			$this->add_note( $entry['id'], 'Subscription error: ' . esc_html( $e->getMessage() ) );
		}

		return $entry;
	}

	public function cancel_subscription( $entry, $feed, $note = null ) {
		$sub_id = rgar( $entry, self::META_SUBSCRIPTION_ID );
		if ( ! $sub_id ) {
			return new WP_Error( 'no_subscription', __( 'No subscription ID on entry.', 'gravity-forms-braintree' ) );
		}
		try {
			$res = $this->get_api()->cancel_subscription( $sub_id );
			if ( ! $res->success ) {
				return new WP_Error( 'cancel_failed', $res->message );
			}
			$msg = 'Subscription cancelled at Braintree: ' . esc_html( $sub_id );
			if ( $note ) {
				$msg .= ' (' . sanitize_text_field( $note ) . ')';
			}
			$this->add_note( $entry['id'], $msg );
			return true;
		} catch ( Throwable $e ) {
			return new WP_Error( 'cancel_exception', $e->getMessage() );
		}
	}
}