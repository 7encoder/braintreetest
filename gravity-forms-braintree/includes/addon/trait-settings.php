<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait GFBraintree_AddOn_Settings_Trait {

	public function plugin_settings_fields() {
		return [
			[
				'title'  => esc_html__( 'Braintree Credentials', 'gravity-forms-braintree' ),
				'fields' => [
					[
						'label' => esc_html__( 'Environment', 'gravity-forms-braintree' ),
						'type'  => 'select',
						'name'  => 'environment',
						'choices' => [
							[ 'label' => 'Sandbox', 'value' => 'sandbox' ],
							[ 'label' => 'Production', 'value' => 'production' ],
						],
						'required' => true,
					],
					[
						'label' => esc_html__( 'Merchant ID', 'gravity-forms-braintree' ),
						'type'  => 'text',
						'name'  => 'merchant_id',
						'required' => true,
					],
					[
						'label' => esc_html__( 'Public Key', 'gravity-forms-braintree' ),
						'type'  => 'text',
						'name'  => 'public_key',
						'required' => true,
					],
					[
						'label' => esc_html__( 'Private Key', 'gravity-forms-braintree' ),
						'type'  => 'text',
						'name'  => 'private_key',
						'required' => true,
					],
					[
						'label' => esc_html__( 'Merchant Account ID (Optional)', 'gravity-forms-braintree' ),
						'type'  => 'text',
						'name'  => 'merchant_account_id',
					],
					[
						'label' => esc_html__( 'Enable Vault (Store Customer)', 'gravity-forms-braintree' ),
						'type'  => 'checkbox',
						'name'  => 'enable_vault',
						'choices' => [
							[
								'label' => esc_html__( 'Yes', 'gravity-forms-braintree' ),
								'name'  => 'enable_vault',
							],
						],
					],
					[
						'label' => esc_html__( 'Enable Postal Code Field', 'gravity-forms-braintree' ),
						'type'  => 'checkbox',
						'name'  => 'enable_hosted_postal',
						'choices' => [
							[
								'label' => esc_html__( 'Add Postal Code Hosted Field', 'gravity-forms-braintree' ),
								'name'  => 'enable_hosted_postal',
							],
						],
					],
					[
						'label' => esc_html__( 'Verbose Entry Notes', 'gravity-forms-braintree' ),
						'type'  => 'checkbox',
						'name'  => 'verbose_entry_notes',
						'choices' => [
							[
								'label' => esc_html__( 'Add detailed transaction notes to entries', 'gravity-forms-braintree' ),
								'name'  => 'verbose_entry_notes',
							],
						],
					],
					[
						'label' => esc_html__( 'Enable Logging', 'gravity-forms-braintree' ),
						'type'  => 'checkbox',
						'name'  => 'enable_logging',
						'choices' => [
							[
								'label' => esc_html__( 'Write debug information to log', 'gravity-forms-braintree' ),
								'name'  => 'enable_logging',
							],
						],
					],
				],
			],
		];
	}

	protected function credentials_complete(): bool {
		return (bool) ( $this->get_plugin_setting( 'merchant_id' ) && $this->get_plugin_setting( 'public_key' ) && $this->get_plugin_setting( 'private_key' ) );
	}
}