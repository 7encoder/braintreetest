<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait GFBraintree_AddOn_Processing_Trait {

	public const META_TRANSACTION_ID  = 'gf_braintree_transaction_id';
	public const META_CAPTURED_AMOUNT = 'gf_braintree_captured_amount';
	public const META_CUSTOMER_ID     = 'gf_braintree_customer_id';
	public const META_AUTHORIZED      = 'gf_braintree_authorized';

	protected function add_verbose_entry_note( $entry_id, array $context ) {
		if ( ! $this->get_plugin_setting( 'verbose_entry_notes' ) ) {
			return;
		}
		$lines = [ 'Braintree Transaction Summary' ];
		foreach ( $context as $k => $v ) {
			if ( is_array( $v ) ) {
				continue;
			}
			$lines[] = ucwords( str_replace( '_', ' ', $k ) ) . ': ' . ( $v === '' ? '-' : $v );
		}
		$this->add_note( $entry_id, implode( "\n", $lines ) );
	}

	protected function resolve_transaction_type( $feed, $entry, $form ): string {
		$base = rgar( $feed['meta'], 'transactionType' );
		if ( ! $base ) {
			$base = rgar( $feed['meta'], 'transaction_type' );
		}
		if ( ! in_array( $base, [ 'product', 'subscription' ], true ) ) {
			$base = 'product';
		}
		return $base;
	}

	protected function resolve_plan_id( $feed, $entry, $form ): ?string {
		$source = rgar( $feed['meta'], 'subscription_plan_source' );
		$val    = null;
		if ( 'field' === $source ) {
			$field_id = rgar( $feed['meta'], 'plan_field' );
			if ( $field_id ) {
				$val = $this->get_field_value( $form, $entry, $field_id );
			}
		} else {
			$val = rgar( $feed['meta'], 'plan_id' );
		}
		if ( is_array( $val ) ) {
			$val = reset( $val );
		}
		$val = $val ? trim( (string) $val ) : '';
		return $val ?: null;
	}

	public function process_feed( $feed, $entry, $form ) {
		GFBraintree_Logger::debug( 'process_feed start', [
			'feed_id'         => rgar( $feed, 'id' ),
			'entry_id'        => rgar( $entry, 'id' ),
			'transactionType' => rgar( $feed['meta'], 'transactionType' ),
		] );

		$type = $this->resolve_transaction_type( $feed, $entry, $form );

		$entry = ( 'subscription' === $type )
			? $this->process_subscription_with_dynamic_plan( $feed, $entry, $form )
			: $this->process_one_time( $feed, $entry, $form );

		GFBraintree_Logger::debug( 'process_feed end', [
			'feed_id'      => rgar( $feed, 'id' ),
			'entry_id'     => rgar( $entry, 'id' ),
			'payment_status' => rgar( $entry, 'payment_status' ),
		] );

		return $entry;
	}

	protected function process_subscription_with_dynamic_plan( $feed, $entry, $form ) {
		$plan_id = $this->resolve_plan_id( $feed, $entry, $form );
		if ( ! $plan_id ) {
			$this->fail_entry( $entry, __( 'No subscription plan selected.', 'gravity-forms-braintree' ) );
			return $entry;
		}
		$feed['meta']['plan_id'] = $plan_id;
		return $this->create_braintree_subscription( $feed, $entry, $form );
	}

	protected function process_one_time( $feed, $entry, $form ) {
		if ( ! class_exists( 'GFCommon' ) ) {
			$this->fail_entry( $entry, 'Gravity Forms helper missing.' );
			return $entry;
		}
		$amount = gf_braintree_format_amount( GFCommon::get_order_total( $form, $entry ) );
		$nonce  = rgar( $entry, 'braintree_payment_method_nonce' );
		if ( ! $nonce ) {
			GFBraintree_Logger::error( 'Missing payment method nonce', [
				'entry_id' => $entry['id'],
				'feed_id'  => rgar( $feed, 'id' ),
			] );
			$this->fail_entry( $entry, __( 'Payment token was not generated. Please try again.', 'gravity-forms-braintree' ) );
			return $entry;
		}

		$params = [
			'amount'             => $amount,
			'paymentMethodNonce' => $nonce,
			'options'            => [ 'submitForSettlement' => true ],
		];
		if ( $merchant_account_id = $this->get_plugin_setting( 'merchant_account_id' ) ) { // phpcs:ignore
			$params['merchantAccountId'] = $merchant_account_id;
		}

		try {
			$result = $this->get_api()->sale_transaction( $params );
			if ( $result->success ) {
				$txn = $result->transaction;
				$this->mark_success_one_time( $entry, $txn, $amount, false );
				$this->add_verbose_entry_note( $entry['id'], [
					'type'           => 'Payment',
					'amount'         => $amount,
					'transaction_id' => $txn->id,
					'card_type'      => $_POST['braintree_card_type'] ?? '', // phpcs:ignore
					'card_last4'     => $_POST['braintree_card_last4'] ?? '', // phpcs:ignore
				] );
			} else {
				$this->handle_gateway_failure( $entry, $result, 'sale' );
			}
		} catch ( Throwable $e ) {
			$this->fail_entry( $entry, __( 'Unexpected gateway error. Please try again.', 'gravity-forms-braintree' ) );
			$this->add_note( $entry['id'], 'Exception: ' . esc_html( $e->getMessage() ) );
			GFBraintree_Logger::error( 'Exception during sale transaction', [ 'error' => $e->getMessage() ] );
		}
		return $entry;
	}

	protected function mark_success_one_time( &$entry, $transaction, string $amount, bool $auth_only ) {
		$entry['transaction_id']            = $transaction->id;
		$entry[ self::META_TRANSACTION_ID ] = $transaction->id;
		$entry['payment_status']            = 'Paid';
		$entry['payment_amount']            = $amount;
		$entry['payment_date']              = gmdate( 'Y-m-d H:i:s' );
		$entry['currency']                  = GFCommon::get_currency();
		GFAPI::update_entry( $entry );
		$this->add_note( $entry['id'], sprintf( 'Braintree sale successful. ID: %s', esc_html( $transaction->id ) ) );
	}

	public function capture_authorization( $entry, $amount = null ) {
		return new WP_Error( 'not_supported', 'Auth-only not implemented in this cut.' );
	}
}