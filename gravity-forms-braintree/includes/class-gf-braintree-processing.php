<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait GFBraintree_Processing_Trait {
	public const META_TRANSACTION_ID   = 'gf_braintree_txn_id';
	public const META_CAPTURED_AMOUNT  = 'gf_braintree_captured_amount';
	public const META_CUSTOMER_ID      = 'gf_braintree_customer_id';
	public const META_DEVICE_DATA      = 'gf_braintree_device_data';
	public const META_SUBSCRIPTION_ID  = 'gf_braintree_subscription_id';
	public const META_PLAN_ID          = 'gf_braintree_plan_id';

	public function process_feed( $feed, $entry, $form ) {
		$type = rgar( $feed, 'meta/transactionType' );
		if ( 'subscription' === $type ) {
			return $this->process_subscription_with_dynamic_plan( $feed, $entry, $form );
		}
		return $this->process_one_time( $feed, $entry, $form );
	}

	protected function ensure_entry_array( $entry ): ?array {
		if ( is_array( $entry ) ) {
			return $entry;
		}
		if ( ( is_string( $entry ) || is_int( $entry ) ) && class_exists( 'GFAPI' ) ) {
			$loaded = GFAPI::get_entry( (int) $entry );
			return is_array( $loaded ) ? $loaded : null;
		}
		return null;
	}

	protected function safe_fail_payment( $entry, string $message ): void {
		$entry_arr = $this->ensure_entry_array( $entry );
		if ( ! $entry_arr ) {
			GFBraintree_Logger::error(
				'safe_fail_payment abort (no entry array)',
				[
					'message'    => $message,
					'entry_type' => gettype( $entry ),
					'entry_val'  => is_scalar( $entry ) ? (string) $entry : '(non-scalar)',
				]
			);
			if ( class_exists( 'GFAPI' ) && is_numeric( $entry ) ) {
				GFAPI::add_note( (int) $entry, 0, 'Braintree', '[Braintree] ' . $message );
			}
			return;
		}
		GFBraintree_Logger::debug(
			'Calling fail_payment with entry array',
			[
				'entry_id' => $entry_arr['id'] ?? null,
				'message'  => $message,
			]
		);
		parent::fail_payment( $entry_arr, $message );
	}

	protected function process_one_time( $feed, $entry, $form ) {
		$entry_arr = $this->ensure_entry_array( $entry );
		if ( ! $entry_arr ) {
			$this->safe_fail_payment( $entry, __( 'Entry not found.', 'gravity-forms-braintree' ) );
			return $entry;
		}

		$api = $this->get_api();
		if ( ! $api ) {
			$this->safe_fail_payment( $entry_arr, __( 'Gateway not configured.', 'gravity-forms-braintree' ) );
			return $entry_arr;
		}

		$nonce = sanitize_text_field( (string) rgpost( 'gf_braintree_nonce' ) );
		if ( ! $nonce ) {
			$this->safe_fail_payment( $entry_arr, __( 'Payment authorization missing (no nonce).', 'gravity-forms-braintree' ) );
			return $entry_arr;
		}

		$amount_raw = $this->get_product_transaction_amount( $form, $entry_arr );
		if ( ! is_numeric( $amount_raw ) || $amount_raw <= 0 ) {
			$this->safe_fail_payment( $entry_arr, __( 'Invalid payment total.', 'gravity-forms-braintree' ) );
			return $entry_arr;
		}

		$amount_formatted = gf_braintree_format_amount( $amount_raw );
		if ( $amount_formatted <= 0 ) {
			$this->safe_fail_payment( $entry_arr, __( 'Invalid formatted amount.', 'gravity-forms-braintree' ) );
			return $entry_arr;
		}

		$params = [
			'amount'             => $amount_formatted,
			'paymentMethodNonce' => $nonce,
			'options'            => [ 'submitForSettlement' => true ],
		];

		$device_data = sanitize_text_field( (string) rgpost( 'gf_braintree_device_data' ) );
		if ( $device_data ) {
			$params['deviceData'] = $device_data;
		}

		$this->maybe_add_postal_code( $feed, $entry_arr, $params );

		$merchant_account_id = $this->get_plugin_setting( 'merchant_account_id' );
		if ( $merchant_account_id ) {
			$params['merchantAccountId'] = $merchant_account_id;
		}

		$params = apply_filters( 'gf_braintree_transaction_params', $params, $feed, $entry_arr, $form );

		try {
			$result = $api->get_gateway()->transaction()->sale( $params );
			if ( ! $result->success ) {
				$error_msg = $this->human_readable_bt_error( $result );
				GFBraintree_Logger::error(
					'Transaction failed',
					[
						'message'  => $error_msg,
						'params'   => $params,
						'entry_id' => $entry_arr['id'] ?? null,
					]
				);
				$this->safe_fail_payment( $entry_arr, $error_msg );
				return $entry_arr;
			}

			$txn = $result->transaction;
			gform_update_meta( $entry_arr['id'], self::META_TRANSACTION_ID, $txn->id );
			gform_update_meta( $entry_arr['id'], self::META_CAPTURED_AMOUNT, $txn->amount );

			$this->complete_payment(
				$entry_arr,
				[
					'payment_status' => 'Paid',
					'payment_amount' => $txn->amount,
					'transaction_id' => $txn->id,
					'note'           => 'Braintree payment successful.',
				]
			);

			do_action( 'gf_braintree_after_successful_transaction', $entry_arr, $feed, $form, $txn );

		} catch ( Throwable $e ) {
			GFBraintree_Logger::error(
				'Transaction exception',
				[
					'error'   => $e->getMessage(),
					'params'  => $params,
					'entry_id'=> $entry_arr['id'] ?? null,
				]
			);
			$this->safe_fail_payment( $entry_arr, __( 'Payment processing error.', 'gravity-forms-braintree' ) );
		}

		return $entry_arr;
	}

	protected function maybe_add_postal_code( $feed, $entry_arr, array &$params ): void {
		if ( isset( $params['billing']['postalCode'] ) ) {
		 return;
		}
		$postal_field_id = rgar( $feed, 'meta/billing_address_field_map_postal' );
		$postal_val      = $postal_field_id ? rgar( $entry_arr, (string) $postal_field_id ) : '';
		if ( $postal_val ) {
			$clean = substr( preg_replace( '/[^A-Za-z0-9 \-]/', '', (string) $postal_val ), 0, 20 );
			if ( $clean !== '' ) {
				$params['billing']['postalCode'] = $clean;
				GFBraintree_Logger::debug(
					'Postal code injected into transaction params',
					[ 'postalCode' => $clean, 'entry_id' => $entry_arr['id'] ?? null ]
				);
			}
		} else {
			GFBraintree_Logger::debug(
				'Postal code not injected (no mapped value)',
				[
					'has_field_mapping' => (bool) $postal_field_id,
					'field_id'          => $postal_field_id,
					'entry_id'          => $entry_arr['id'] ?? null,
				]
			);
		}
	}

	protected function human_readable_bt_error( $result ): string {
		if ( ! $result ) {
			return __( 'Payment failed.', 'gravity-forms-braintree' );
		}
		$messages = [];
		if ( isset( $result->errors ) && method_exists( $result->errors, 'deepAll' ) ) {
			foreach ( (array) $result->errors->deepAll() as $err ) {
				if ( isset( $err->message ) ) {
					$messages[] = $err->message;
				}
			}
		}
		if ( isset( $result->message ) && $result->message ) {
			$messages[] = $result->message;
		}
		$messages = array_unique( array_filter( array_map( 'trim', $messages ) ) );
		if ( empty( $messages ) ) {
			return __( 'Payment failed.', 'gravity-forms-braintree' );
		}
		foreach ( $messages as &$m ) {
			if ( stripos( $m, 'postal code' ) !== false ) {
				$m = __( 'Postal code is required or invalid.', 'gravity-forms-braintree' );
			}
			if ( stripos( $m, 'cvv' ) !== false ) {
				$m = __( 'Security code (CVV) is invalid.', 'gravity-forms-braintree' );
			}
		}
		return implode( ' ', array_unique( $messages ) );
	}

	protected function get_product_transaction_amount( $form, $entry ): float {
		if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'get_order_total' ) ) {
			$total = GFCommon::get_order_total( $form, $entry );
			if ( is_numeric( $total ) ) {
				return (float) $total;
			}
		}
		$total = 0.0;
		if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'get_product_fields' ) ) {
			$products = GFCommon::get_product_fields( $form, $entry );
			if ( is_array( $products ) && isset( $products['products'] ) ) {
				foreach ( $products['products'] as $product ) {
					$qty   = isset( $product['quantity'] ) ? (float) $product['quantity'] : 1;
					$price = isset( $product['price'] ) ? (float) $product['price'] : 0;
					$line  = $price * $qty;
					if ( ! empty( $product['options'] ) ) {
						foreach ( $product['options'] as $opt ) {
							$opt_price = isset( $opt['price'] ) ? (float) $opt['price'] : 0;
							$line += $opt_price * $qty;
						}
					}
					$total += $line;
				}
			}
			if ( isset( $products['shipping']['price'] ) ) {
				$total += (float) $products['shipping']['price'];
			}
		}
		return $total;
	}
}