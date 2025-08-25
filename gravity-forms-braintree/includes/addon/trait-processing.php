<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait GFBraintree_AddOn_Processing_Trait {

	public const META_TRANSACTION_ID      = 'gf_braintree_txn_id';
	public const META_CAPTURED_AMOUNT     = 'gf_braintree_captured_amount';
	public const META_CUSTOMER_ID         = 'gf_braintree_customer_id';
	public const META_AUTHORIZED          = 'gf_braintree_authorized';
	public const META_DEVICE_DATA         = 'gf_braintree_device_data';
	public const META_BILLING_ADDRESS_SET = 'gf_braintree_billing_address_used';

	protected function add_verbose_entry_note( $entry_id, array $context ): void {
		if ( ! $this->get_plugin_setting( 'verbose_entry_notes' ) ) {
			return;
		}
		$lines = [ 'Braintree Transaction Summary' ];
		foreach ( $context as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$label   = ucwords( str_replace( '_', ' ', (string) $k ) );
				$san_val = sanitize_text_field( (string) $v );
				$lines[] = "{$label}: " . ( $san_val === '' ? '-' : $san_val );
			}
		}
		$lines = apply_filters( 'gf_braintree_verbose_entry_note_lines', $lines, $context );
		$this->add_note( $entry_id, implode( "\n", $lines ) );
	}

	protected function resolve_transaction_type( $feed, $entry, $form ): string {
		$base = rgar( $feed['meta'], 'transactionType' ) ?: rgar( $feed['meta'], 'transaction_type' );
		return in_array( $base, [ 'product', 'subscription' ], true ) ? $base : 'product';
	}

	protected function resolve_plan_id( $feed, $entry, $form ): ?string {
		if ( $this->resolve_transaction_type( $feed, $entry, $form ) !== 'subscription' ) {
			return null;
		}
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

	protected function get_mapped_value( $form, $entry, array $meta, string $sub_key ): string {
		$field_id = rgar( $meta, $sub_key );
		if ( ! $field_id ) {
			return '';
		}
		$value = $this->get_field_value( $form, $entry, $field_id );
		if ( is_array( $value ) ) {
			$value = implode( ' ', array_filter( $value ) );
		}
		return trim( (string) $value );
	}

	protected function build_customer_data( $feed, $form, $entry ): array {
		$meta     = $feed['meta'];
		$customer = [];

		$email_raw = $this->get_mapped_value( $form, $entry, $meta, 'customer_field_map_email' );
		$email     = strtolower( sanitize_email( $email_raw ) );
		if ( $email ) {
			$customer['email'] = $email;
		}

		$first = $this->get_mapped_value( $form, $entry, $meta, 'customer_field_map_first_name' );
		if ( $first ) {
			$customer['firstName'] = sanitize_text_field( $first );
		}

		$last = $this->get_mapped_value( $form, $entry, $meta, 'customer_field_map_last_name' );
		if ( $last ) {
			$customer['lastName'] = sanitize_text_field( $last );
		}

		$company = $this->get_mapped_value( $form, $entry, $meta, 'customer_field_map_company' );
		if ( $company ) {
			$customer['company'] = sanitize_text_field( $company );
		}

		$phone = $this->get_mapped_value( $form, $entry, $meta, 'customer_field_map_phone' );
		if ( $phone ) {
			$customer['phone'] = sanitize_text_field( $phone );
		}

		return $customer;
	}

	protected function sanitize_country_alpha2( string $input ): ?string {
		if ( $input === '' ) {
			return null;
		}
		$in = trim( $input );
		if ( strlen( $in ) === 2 ) {
			return strtoupper( $in );
		}
		$map = [
			'united states' => 'US',
			'usa'           => 'US',
			'united kingdom'=> 'GB',
			'great britain' => 'GB',
			'canada'        => 'CA',
			'australia'     => 'AU',
			'germany'       => 'DE',
			'france'        => 'FR',
			'spain'         => 'ES',
			'mexico'        => 'MX',
			'italy'         => 'IT',
		];
		$key = strtolower( $in );
		return $map[ $key ] ?? null;
	}

	protected function build_billing_address( $feed, $form, $entry ): array {
		$meta  = $feed['meta'];
		$addr  = [];

		$street  = $this->get_mapped_value( $form, $entry, $meta, 'billing_address_field_map_street' );
		$street2 = $this->get_mapped_value( $form, $entry, $meta, 'billing_address_field_map_street2' );
		$city    = $this->get_mapped_value( $form, $entry, $meta, 'billing_address_field_map_city' );
		$state   = $this->get_mapped_value( $form, $entry, $meta, 'billing_address_field_map_state' );
		$postal  = $this->get_mapped_value( $form, $entry, $meta, 'billing_address_field_map_postal' );
		$country = $this->get_mapped_value( $form, $entry, $meta, 'billing_address_field_map_country' );

		if ( $street ) {
			$addr['streetAddress'] = sanitize_text_field( $street );
		}
		if ( $street2 ) {
			$addr['extendedAddress'] = sanitize_text_field( $street2 );
		}
		if ( $city ) {
			$addr['locality'] = sanitize_text_field( $city );
		}
		if ( $state ) {
			$addr['region'] = sanitize_text_field( $state );
		}
		if ( $postal ) {
			$addr['postalCode'] = sanitize_text_field( $postal );
		}
		if ( $country ) {
			$alpha2 = $this->sanitize_country_alpha2( $country );
			if ( $alpha2 ) {
				$addr['countryCodeAlpha2'] = $alpha2;
			}
		}

		return $addr;
	}

	protected function build_transaction_params( $feed, $form, $entry, string $nonce, string $amount ): array {
		$customer    = $this->build_customer_data( $feed, $form, $entry );
		$billing     = $this->build_billing_address( $feed, $form, $entry );
		$device_data = sanitize_text_field( (string) rgpost( 'gf_braintree_device_data' ) );

		$params = [
			'amount'             => $amount,
			'paymentMethodNonce' => $nonce,
			'options'            => [
				'submitForSettlement' => true,
			],
		];

		if ( ! empty( $customer ) ) {
			$params['customer'] = $customer;
		}
		if ( ! empty( $billing ) ) {
			$params['billing'] = $billing;
		}
		if ( $device_data ) {
			$params['deviceData'] = $device_data;
		}

		$merchant_account = $this->get_plugin_setting( 'merchant_account_id' );
		if ( $merchant_account ) {
			$params['merchantAccountId'] = $merchant_account;
		}

		return apply_filters( 'gf_braintree_transaction_params', $params, $feed, $form, $entry );
	}

	protected function process_one_time( $feed, $entry, $form ) {
		$nonce = sanitize_text_field( (string) rgpost( 'gf_braintree_nonce' ) );
		if ( ! $nonce ) {
			$this->fail_payment( $entry, esc_html__( 'Payment nonce missing.', 'gravity-forms-braintree' ) );
			return $entry;
		}

		$amount = gf_braintree_format_amount( $this->get_product_transaction_amount( $form, $entry ) );

		try {
			$params = $this->build_transaction_params( $feed, $form, $entry, $nonce, $amount );
			$result = $this->get_api()->get_gateway()->transaction()->sale( $params );
			if ( ! $result->success ) {
				GFBraintree_Logger::error( 'Transaction failed', [ 'message' => $result->message ] );
				$this->fail_payment( $entry, __( 'Transaction failed. Please try again.', 'gravity-forms-braintree' ) );
				return $entry;
			}
			$txn = $result->transaction;
			gform_update_meta( $entry['id'], self::META_TRANSACTION_ID, $txn->id );
			gform_update_meta( $entry['id'], self::META_CAPTURED_AMOUNT, $txn->amount );

			if ( $device = sanitize_text_field( (string) rgpost( 'gf_braintree_device_data' ) ) ) {
				gform_update_meta( $entry['id'], self::META_DEVICE_DATA, $device );
			}

			$this->complete_payment(
				$entry,
				[
					'transaction_id' => $txn->id,
					'amount'         => $txn->amount,
					'payment_method' => $txn->paymentInstrumentType,
				]
			);

			$this->add_verbose_entry_note(
				$entry['id'],
				[
					'transaction_id' => $txn->id,
					'amount'         => $txn->amount,
					'status'         => $txn->status,
					'type'           => 'one-time',
				]
			);
			do_action( 'gf_braintree_after_successful_transaction', $entry, $feed, $form );
		} catch ( \Throwable $e ) {
			GFBraintree_Logger::error( 'Exception processing transaction', [ 'error' => $e->getMessage() ] );
			$this->fail_payment( $entry, __( 'Payment processing error.', 'gravity-forms-braintree' ) );
		}

		return $entry;
	}

	public function process_feed( $feed, $entry, $form ) {
		GFBraintree_Logger::debug(
			'process_feed start',
			[
				'feed_id'         => rgar( $feed, 'id' ),
				'entry_id'        => rgar( $entry, 'id' ),
				'transactionType' => rgar( $feed['meta'], 'transactionType' ),
			]
		);

		$type = $this->resolve_transaction_type( $feed, $entry, $form );

		if ( 'subscription' === $type ) {
			$this->process_subscription_with_dynamic_plan( $feed, $entry, $form );
		} else {
			$this->process_one_time( $feed, $entry, $form );
		}

		GFBraintree_Logger::debug(
			'process_feed end',
			[
				'feed_id'        => rgar( $feed, 'id' ),
				'entry_id'       => rgar( $entry, 'id' ),
				'payment_status' => rgar( $entry, 'payment_status' ),
			]
		);
	}
}