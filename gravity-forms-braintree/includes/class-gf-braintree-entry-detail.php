<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBraintree_Entry_Detail {

	public static function bootstrap(): void {
		add_action( 'gform_entry_info', [ __CLASS__, 'refund_button' ], 10, 2 );
		add_action( 'wp_ajax_gf_braintree_refund', [ __CLASS__, 'process_refund' ] );
	}

	public static function refund_button( $form_id, $entry ): void {
		if ( ! current_user_can( 'gravityforms_edit_entries' ) ) {
			return;
		}
		$txn_id = gform_get_meta( $entry['id'], GFBraintree::META_TRANSACTION_ID );
		if ( ! $txn_id ) {
			return;
		}
		$nonce = wp_create_nonce( 'gf_braintree_refund_' . $entry['id'] );
		echo '<div class="misc-pub-section">';
		echo '<label>' . esc_html__( 'Refund Amount', 'gravity-forms-braintree' ) . ' ';
		echo '<input type="text" size="7" id="gf-braintree-refund-amount" value="' . esc_attr( rgar( $entry, 'payment_amount' ) ) . '" /></label> ';
		echo '<button type="button" class="button" id="gf-braintree-refund" data-entry="' . esc_attr( $entry['id'] ) . '" data-nonce="' . esc_attr( $nonce ) . '">' .
		     esc_html__( 'Refund', 'gravity-forms-braintree' ) . '</button>';
		echo '</div>';
		?>
		<script>
			(function($){
				$('#gf-braintree-refund').on('click', function(){
					if(!confirm('<?php echo esc_js( __( 'Issue refund?', 'gravity-forms-braintree' ) ); ?>')) {
						return;
					}
					const btn = $(this);
					btn.prop('disabled', true);
					$.post(ajaxurl,{
						action: 'gf_braintree_refund',
						entry_id: btn.data('entry'),
						nonce: btn.data('nonce'),
						amount: $('#gf-braintree-refund-amount').val()
					}).done(function(resp){
						alert(resp.data && resp.data.message ? resp.data.message : 'Done');
						location.reload();
					}).fail(function(xhr){
						alert((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Refund failed.');
						btn.prop('disabled', false);
					});
				});
			})(jQuery);
		</script>
		<?php
	}

	public static function process_refund(): void {
		$entry_id = absint( $_POST['entry_id'] ?? 0 );
		$nonce    = $_POST['nonce'] ?? '';
		$amount   = (float) ( $_POST['amount'] ?? 0 );

		if ( ! $entry_id || ! wp_verify_nonce( $nonce, 'gf_braintree_refund_' . $entry_id ) ) {
			wp_send_json_error( [ 'message' => 'Bad nonce.' ], 400 );
		}
		if ( ! current_user_can( 'gravityforms_edit_entries' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}
		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			wp_send_json_error( [ 'message' => 'Entry not found.' ], 404 );
		}

		$txn_id = gform_get_meta( $entry_id, GFBraintree::META_TRANSACTION_ID );
		if ( ! $txn_id ) {
			wp_send_json_error( [ 'message' => 'Transaction ID missing.' ], 400 );
		}

		if ( $amount <= 0 ) {
			wp_send_json_error( [ 'message' => 'Amount invalid.' ], 400 );
		}

		$addon = gf_braintree();
		if ( ! $addon || ! $addon->get_api() ) {
			wp_send_json_error( [ 'message' => 'API unavailable.' ], 500 );
		}

		try {
			$result = $addon->get_api()->get_gateway()->transaction()->refund(
				$txn_id,
				number_format( $amount, 2, '.', '' )
			);
			if ( ! $result->success ) {
				wp_send_json_error( [ 'message' => 'Refund failed: ' . $result->message ], 500 );
			}
			GFAPI::add_note(
				$entry_id,
				get_current_user_id(),
				'Braintree',
				'Refunded ' . $amount . ' (Refund Txn ID: ' . $result->transaction->id . ')'
			);
			wp_send_json_success( [ 'message' => 'Refund processed.' ] );
		} catch ( Throwable $e ) {
			wp_send_json_error( [ 'message' => 'Exception: ' . $e->getMessage() ], 500 );
		}
	}
}