<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait GFBraintree_AddOn_Settings_Trait {

	public function plugin_settings_fields(): array {

		$webhook_url = esc_url( rest_url( 'gf-braintree/v1/webhook' ) );

		return [
			[
				'title'  => esc_html__( 'Braintree Credentials', 'gravity-forms-braintree' ),
				'fields' => [
					[
						'label'    => esc_html__( 'Environment', 'gravity-forms-braintree' ),
						'type'     => 'select',
						'name'     => 'environment',
						'choices'  => [
							[ 'label' => esc_html__( 'Sandbox', 'gravity-forms-braintree' ), 'value' => 'sandbox' ],
							[ 'label' => esc_html__( 'Production', 'gravity-forms-braintree' ), 'value' => 'production' ],
						],
						'required' => true,
					],
					[
						'label'    => esc_html__( 'Merchant ID', 'gravity-forms-braintree' ),
						'type'     => 'text',
						'name'     => 'merchant_id',
						'required' => true,
					],
					[
						'label'    => esc_html__( 'Public Key', 'gravity-forms-braintree' ),
						'type'     => 'text',
						'name'     => 'public_key',
						'required' => true,
					],
					[
						'label'    => esc_html__( 'Private Key', 'gravity-forms-braintree' ),
						'type'     => 'text',
						'name'     => 'private_key',
						'required' => true,
						'feedback_callback' => static function( $value ) {
							return ! empty( $value );
						},
					],
					[
						'label' => esc_html__( 'Merchant Account ID (Optional)', 'gravity-forms-braintree' ),
						'type'  => 'text',
						'name'  => 'merchant_account_id',
					],
					[
						'label'   => esc_html__( 'Enable Vault (Store Customer)', 'gravity-forms-braintree' ),
						'type'    => 'checkbox',
						'name'    => 'enable_vault',
						'choices' => [
							[
								'label' => esc_html__( 'Yes', 'gravity-forms-braintree' ),
								'name'  => 'enable_vault',
							],
						],
					],
					[
						'label'   => esc_html__( 'Enable Postal Code Field', 'gravity-forms-braintree' ),
						'type'    => 'checkbox',
						'name'    => 'enable_hosted_postal',
						'choices' => [
							[
								'label' => esc_html__( 'Add Postal Code Hosted Field', 'gravity-forms-braintree' ),
								'name'  => 'enable_hosted_postal',
							],
						],
					],
					[
						'label'   => esc_html__( 'Verbose Entry Notes', 'gravity-forms-braintree' ),
						'type'    => 'checkbox',
						'name'    => 'verbose_entry_notes',
						'choices' => [
							[
								'label' => esc_html__( 'Add detailed transaction notes to entries', 'gravity-forms-braintree' ),
								'name'  => 'verbose_entry_notes',
							],
						],
					],
					[
						'label'   => esc_html__( 'Enable Logging', 'gravity-forms-braintree' ),
						'type'    => 'checkbox',
						'name'    => 'enable_logging',
						'choices' => [
							[
								'label' => esc_html__( 'Enable debug logging', 'gravity-forms-braintree' ),
								'name'  => 'enable_logging',
							],
						],
					],
					[
						'label' => esc_html__( 'Webhook Endpoint URL', 'gravity-forms-braintree' ),
						'type'  => 'html',
						'name'  => 'webhook_url_display',
						'html'  => '<code style="user-select:all;">' . $webhook_url . '</code><p class="description">' .
							esc_html__( 'Configure this URL in your Braintree Control Panel for subscription status webhooks.', 'gravity-forms-braintree' ) .
							'</p>',
					],
				],
			],
		];
	}
}