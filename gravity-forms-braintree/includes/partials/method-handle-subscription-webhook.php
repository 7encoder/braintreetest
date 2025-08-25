/**
 * Translate Braintree subscription webhooks into Gravity Forms subscription actions.
 */
private function handle_subscription_webhook( string $subscription_id, string $kind, $notification ): void {
	$entry_id = gf_braintree_get_entry_id_by_subscription( $subscription_id );
	if ( ! $entry_id ) {
		GFBraintree_Logger::error( 'Webhook subscription entry not found', [ 'subscription_id' => $subscription_id, 'kind' => $kind ] );
		return;
	}
	$entry = GFAPI::get_entry( $entry_id );
	if ( is_wp_error( $entry ) ) {
		GFBraintree_Logger::error( 'Webhook failed to load entry', [ 'subscription_id' => $subscription_id ] );
		return;
	}
	switch ( $kind ) {
		case 'SubscriptionWentActive':
			$entry = $this->start_subscription( $entry, [
				'type'            => 'start_subscription',
				'subscription_id' => $subscription_id,
				'transaction_id'  => $subscription_id,
				'payment_status'  => 'Active',
				'note'            => 'Subscription activated via webhook.',
			] );
			break;
		case 'SubscriptionCanceled':
			$this->cancel_subscription( $entry, [
				'type'            => 'cancel_subscription',
				'subscription_id' => $subscription_id,
				'note'            => 'Subscription canceled via webhook.',
			] );
			break;
		case 'SubscriptionExpired':
			$this->expire_subscription( $entry, [
				'type'            => 'expire_subscription',
				'subscription_id' => $subscription_id,
				'note'            => 'Subscription expired via webhook.',
			] );
			break;
		case 'SubscriptionChargedSuccessfully':
			$txn    = $notification->subscription->transactions[0] ?? null;
			$amount = $txn ? $txn->amount : null;
			$this->add_subscription_payment( $entry, [
				'type'             => 'add_subscription_payment',
				'subscription_id'  => $subscription_id,
				'transaction_id'   => $txn ? $txn->id : '',
				'amount'           => $amount,
				'payment_status'   => 'Paid',
				'note'             => 'Recurring charge succeeded.',
				'payment_method'   => 'braintree',
			] );
			break;
		case 'SubscriptionChargedUnsuccessfully':
			$this->fail_subscription_payment( $entry, [
				'type'            => 'fail_subscription_payment',
				'subscription_id' => $subscription_id,
				'note'            => 'Recurring charge failed.',
			] );
			break;
		default:
			$this->add_note( $entry_id, '[Webhook] Unhandled subscription event: ' . esc_html( $kind ) );
			break;
	}
}