<?php
/**
 * Main Add-On class for Braintree + Gravity Forms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Hard guard: if GFPaymentAddOn still isn't loaded, bail silently (we are inside gform_loaded, so normally it will be).
if ( ! class_exists( 'GFPaymentAddOn' ) ) {
	return;
}

class GFBraintreeAddOn extends GFPaymentAddOn {

	/* -----------------------------------------------------------------
	 * Core properties
	 * ----------------------------------------------------------------- */
	protected $_version                   = '1.0.0';
	protected $_min_gravityforms_version  = '2.7';
	protected $_slug                      = 'gravity-forms-braintree';
	protected $_path                      = 'gravity-forms-braintree/gravity-forms-braintree.php';
	protected $_full_path                 = __FILE__;
	protected $_title                     = 'Gravity Forms Braintree Add-On';
	protected $_short_title               = 'Braintree';
	protected $_supports_callbacks        = true; // If using webhooks.
	protected $is_payment_gateway         = true;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Use subscription trait (make sure file included before this class).
	 */
	use GFBraintree_AddOn_Subscription_Trait;

	/**
	 * Get singleton instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* -----------------------------------------------------------------
	 * Init Hooks
	 * ----------------------------------------------------------------- */
	public function pre_init() {
		parent::pre_init();
	}

	public function init(): void {
		parent::init();

		add_filter( 'gform_validation', [ $this, 'maybe_validate_nonce' ], 999, 1 );
		add_filter( 'gform_validation', [ $this, 'maybe_validate_subscription_plan' ], 998, 2 );
	}

	public function init_admin() {
		parent::init_admin();
	}

	/* -----------------------------------------------------------------
	 * Validation Helpers
	 * ----------------------------------------------------------------- */
	public function maybe_validate_nonce( $validation_result ) {
		// If you require a Braintree nonce presence, implement here.
		return $validation_result;
	}

	public function maybe_validate_subscription_plan( $validation_result, $form ) {
		if ( empty( $validation_result['is_valid'] ) ) {
			return $validation_result;
		}
		$form_id = (int) rgar( $form, 'id' );
		if ( ! $this->form_has_active_feed( $form_id ) ) {
			return $validation_result;
		}
		$feeds = $this->get_active_feeds( $form_id );
		foreach ( $feeds as $feed ) {
			if ( rgar( $feed['meta'], 'transactionType' ) === 'subscription' ) {
				$plan = $this->resolve_plan_id( $feed, [], $form );
				if ( ! $plan ) {
					$validation_result['is_valid']                   = false;
					$validation_result['form']['failed_validation']  = true;
					$validation_result['form']['validation_message'] = esc_html__( 'Please select a subscription plan.', 'gravity-forms-braintree' );
				}
				break;
			}
		}
		return $validation_result;
	}

	/* -----------------------------------------------------------------
	 * Stubs / Replace With Real Logic
	 * ----------------------------------------------------------------- */
	protected function resolve_plan_id( $feed, $entry, $form ) {
		return rgar( $feed['meta'], 'braintree_plan_id' );
	}

	protected function build_customer_data( $feed, $form, $entry ): array {
		return [
			'firstName' => rgar( $entry, '1.3' ) ?: '',
			'lastName'  => rgar( $entry, '1.6' ) ?: '',
			'email'     => rgar( $entry, '2' ) ?: '',
		];
	}

	protected function build_billing_address( $feed, $form, $entry ): array {
		return [];
	}

	public function form_has_active_feed( int $form_id ): bool {
		return ! empty( $this->get_active_feeds( $form_id ) );
	}

	public function get_active_feeds( int $form_id ): array {
		// Replace with GF API for feeds used by your plugin.
		if ( method_exists( 'GFAPI', 'get_feeds' ) ) {
			$feeds = GFAPI::get_feeds( null, $form_id, $this->_slug );
			return is_array( $feeds ) ? $feeds : [];
		}
		return [];
	}

	public function get_api() {
		// Return your API wrapper instance if needed.
		return null;
	}

	protected function add_verbose_entry_note( $entry_id, array $context ): void {
		$parts = [];
		foreach ( $context as $k => $v ) {
			$parts[] = $k . '=' . ( is_scalar( $v ) ? $v : wp_json_encode( $v ) );
		}
		$this->add_note( $entry_id, '[Braintree] ' . implode( ' | ', $parts ) );
	}

	/* -----------------------------------------------------------------
	 * Webhook Handling
	 * ----------------------------------------------------------------- */
	private function handle_subscription_webhook( string $subscription_id, string $kind, $notification ): void {
		$entry_id = gf_braintree_get_entry_id_by_subscription( $subscription_id );
		if ( ! $entry_id ) {
			GFBraintree_Logger::error(
				'Webhook subscription entry not found',
				[ 'subscription_id' => $subscription_id, 'kind' => $kind ]
			);
			return;
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			GFBraintree_Logger::error(
				'Webhook failed to load entry',
				[ 'subscription_id' => $subscription_id ]
			);
			return;
		}

		switch ( $kind ) {
			case 'SubscriptionWentActive':
				$action = [
					'type'            => 'start_subscription',
					'subscription_id' => $subscription_id,
					'transaction_id'  => $subscription_id,
					'payment_status'  => 'Active',
					'note'            => 'Subscription activated via webhook.',
				];
				$entry = $this->start_subscription( $entry, $action );
				break;

			case 'SubscriptionCanceled':
				$this->cancel_subscription(
					$entry,
					[
						'type'            => 'cancel_subscription',
						'subscription_id' => $subscription_id,
						'note'            => 'Subscription canceled via webhook.',
					]
				);
				break;

			case 'SubscriptionExpired':
				$this->expire_subscription(
					$entry,
					[
						'type'            => 'expire_subscription',
						'subscription_id' => $subscription_id,
						'note'            => 'Subscription expired via webhook.',
					]
				);
				break;

			case 'SubscriptionChargedSuccessfully':
				$txn    = $notification->subscription->transactions[0] ?? null;
				$amount = $txn ? $txn->amount : null;
				$this->add_subscription_payment(
					$entry,
					[
						'type'             => 'add_subscription_payment',
						'subscription_id'  => $subscription_id,
						'transaction_id'   => $txn ? $txn->id : '',
						'amount'           => $amount,
						'payment_status'   => 'Paid',
						'note'             => 'Recurring charge succeeded.',
						'payment_method'   => 'braintree',
					]
				);
				break;

			case 'SubscriptionChargedUnsuccessfully':
				$this->fail_subscription_payment(
					$entry,
					[
						'type'            => 'fail_subscription_payment',
						'subscription_id' => $subscription_id,
						'note'            => 'Recurring charge failed.',
					]
				);
				break;

			default:
				$this->add_note(
					$entry_id,
					'[Webhook] Unhandled subscription event: ' . esc_html( $kind )
				);
				break;
		}
	}

}