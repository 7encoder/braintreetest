<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait GFBraintree_Settings_Trait {

	public function get_plan_choices( bool $force = false ): array {

		$is_feed_save = isset( $_POST['gform-settings-save'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_type  = isset( $_POST['_gform_setting_transactionType'] )
			? sanitize_key( wp_unslash( $_POST['_gform_setting_transactionType'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		if ( $is_feed_save && 'subscription' === $posted_type ) {
			$force = true;
		}

		$merchant_id = (string) $this->get_plugin_setting( 'merchant_id' );
		$env         = (string) $this->get_plugin_setting( 'environment' );

		if ( ! $merchant_id || ! $env ) {
			return [];
		}

		$cache_key = 'gf_braintree_plans_' . md5( $env . '|' . $merchant_id );

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$api = $this->get_api();
		if ( ! $api ) {
			return [];
		}

		try {
			$plans   = $api->get_gateway()->plan()->all();
			$choices = [];

			if ( is_array( $plans ) ) {
				foreach ( $plans as $plan ) {
					$id = isset( $plan->id ) ? (string) $plan->id : '';
					if ( ! $id ) {
						continue;
					}
					$name = isset( $plan->name ) ? (string) $plan->name : $id;
					if ( isset( $plan->price ) ) {
						$name .= ' (' . $plan->price . ')';
					}
					$choices[] = [
						'id'   => $id,
						'name' => $name,
					];
				}
			}

			set_transient( $cache_key, $choices, 10 * MINUTE_IN_SECONDS );
			return $choices;
		} catch ( Throwable $e ) {
			GFBraintree_Logger::error( 'Plan fetch error', [ 'error' => $e->getMessage() ] );
			return [];
		}
	}

	public function plugin_settings_fields(): array {
		$webhook_url = esc_url( rest_url( 'gf-braintree/v1/webhook' ) );
		return [
			[
				'title'  => esc_html__( 'Braintree Credentials', 'gravity-forms-braintree' ),
				'fields' => [
					[
						'label'         => esc_html__( 'Environment', 'gravity-forms-braintree' ),
						'type'          => 'select',
						'name'          => 'environment',
						'choices'       => [
							[ 'label' => 'Sandbox', 'value' => 'sandbox' ],
							[ 'label' => 'Production', 'value' => 'production' ],
						],
						'default_value' => 'sandbox',
						'required'      => true,
					],
					[ 'label' => esc_html__( 'Merchant ID', 'gravity-forms-braintree' ), 'type' => 'text', 'name' => 'merchant_id', 'required' => true ],
					[ 'label' => esc_html__( 'Public Key', 'gravity-forms-braintree' ), 'type' => 'text', 'name' => 'public_key', 'required' => true ],
					[ 'label' => esc_html__( 'Private Key', 'gravity-forms-braintree' ), 'type' => 'text', 'name' => 'private_key', 'required' => true ],
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
							[ 'label' => esc_html__( 'Yes', 'gravity-forms-braintree' ), 'name' => 'enable_vault' ],
						],
					],
					[
						'label'   => esc_html__( 'Enable Postal Code Field', 'gravity-forms-braintree' ),
						'type'    => 'checkbox',
						'name'    => 'enable_hosted_postal',
						'choices' => [
							[ 'label' => esc_html__( 'Add Postal Code Hosted Field', 'gravity-forms-braintree' ), 'name' => 'enable_hosted_postal' ],
						],
					],
					[
						'label'   => esc_html__( 'Collect Device Data (Fraud / Kount)', 'gravity-forms-braintree' ),
						'type'    => 'checkbox',
						'name'    => 'collect_device_data',
						'choices' => [
							[ 'label' => esc_html__( 'Enable device data collection', 'gravity-forms-braintree' ), 'name' => 'collect_device_data' ],
						],
					],
					[
						'label'   => esc_html__( 'Verbose Entry Notes', 'gravity-forms-braintree' ),
						'type'    => 'checkbox',
						'name'    => 'verbose_entry_notes',
						'choices' => [
							[ 'label' => esc_html__( 'Add detailed transaction notes to entries', 'gravity-forms-braintree' ), 'name' => 'verbose_entry_notes' ],
						],
					],
					[
						'label'   => esc_html__( 'Enable Logging', 'gravity-forms-braintree' ),
						'type'    => 'checkbox',
						'name'    => 'enable_logging',
						'choices' => [
							[ 'label' => esc_html__( 'Enable debug logging', 'gravity-forms-braintree' ), 'name' => 'enable_logging' ],
						],
					],
					[
						'label' => esc_html__( 'Webhook Endpoint URL', 'gravity-forms-braintree' ),
						'type'  => 'html',
						'name'  => 'webhook_url_display',
						'html'  => '<code style="user-select:all;">' . $webhook_url . '</code><p class="description">' .
						            esc_html__( 'Configure this URL in your Braintree Control Panel.', 'gravity-forms-braintree' ) . '</p>',
					],
				],
			],
		];
	}

	public function feed_settings_fields(): array {

		$raw_plans = $this->get_plan_choices();
		$plan_choices = array_map(
			static fn( $p ) => [ 'label' => $p['name'], 'value' => $p['id'] ],
			$raw_plans
		);
		if ( empty( $plan_choices ) ) {
			$plan_choices[] = [
				'label' => esc_html__( 'No plans found. Save once after entering credentials (Subscription) to load plans.', 'gravity-forms-braintree' ),
				'value' => '',
			];
		}

		return [
			[
				'title'  => esc_html__( 'Transaction Settings', 'gravity-forms-braintree' ),
				'fields' => [
					[
						'name'         => 'feedName',
						'label'        => esc_html__( 'Feed Name', 'gravity-forms-braintree' ),
						'type'         => 'text',
						'required'     => true,
						'class'        => 'medium',
						'default_value'=> esc_html__( 'Braintree Feed', 'gravity-forms-braintree' ),
					],
					[
						'name'    => 'transactionType',
						'label'   => esc_html__( 'Transaction Type', 'gravity-forms-braintree' ),
						'type'    => 'select',
						'choices' => [
							[ 'label' => esc_html__( 'Products and Services (One-Time)', 'gravity-forms-braintree' ), 'value' => 'product' ],
							[ 'label' => esc_html__( 'Subscription', 'gravity-forms-braintree' ), 'value' => 'subscription' ],
						],
						'required' => true,
					],
					[
						'name'       => 'subscription_plan_source',
						'label'      => esc_html__( 'Plan Source', 'gravity-forms-braintree' ),
						'type'       => 'select',
						'choices'    => [
							[ 'label' => esc_html__( 'Static (select plan)', 'gravity-forms-braintree' ), 'value' => 'static' ],
							[ 'label' => esc_html__( 'Form Field (pass plan id)', 'gravity-forms-braintree' ), 'value' => 'field' ],
						],
						'dependency' => [ 'field' => 'transactionType', 'values' => [ 'subscription' ] ],
						'class'      => 'gf-braintree-subscription-control',
					],
					[
						'name'       => 'plan_id',
						'label'      => esc_html__( 'Subscription Plan', 'gravity-forms-braintree' ),
						'type'       => 'select',
						'choices'    => $plan_choices,
						'class'      => 'gf-braintree-plan-static',
						'dependency' => [ 'field' => 'transactionType', 'values' => [ 'subscription' ] ],
					],
					[
						'name'       => 'plan_field',
						'label'      => esc_html__( 'Plan Field (Drop Down / Hidden)', 'gravity-forms-braintree' ),
						'type'       => 'field_select',
						'class'      => 'gf-braintree-plan-field-select',
						'dependency' => [ 'field' => 'transactionType', 'values' => [ 'subscription' ] ],
						'tooltip'    => esc_html__( 'Field whose submitted value is a valid Braintree plan ID.', 'gravity-forms-braintree' ),
					],
					[
						'name'  => 'customer_fields_header',
						'label' => esc_html__( 'Customer Field Mapping', 'gravity-forms-braintree' ),
						'type'  => 'section',
					],
					[ 'name' => 'customer_field_map_first_name', 'label' => esc_html__( 'First Name Field', 'gravity-forms-braintree' ), 'type' => 'field_select' ],
					[ 'name' => 'customer_field_map_last_name',  'label' => esc_html__( 'Last Name Field', 'gravity-forms-braintree' ), 'type' => 'field_select' ],
					[ 'name' => 'customer_field_map_email',      'label' => esc_html__( 'Email Field', 'gravity-forms-braintree' ), 'type' => 'field_select' ],
					[ 'name' => 'customer_field_map_company',    'label' => esc_html__( 'Company Field', 'gravity-forms-braintree' ), 'type' => 'field_select' ],
					[ 'name' => 'customer_field_map_phone',      'label' => esc_html__( 'Phone Field', 'gravity-forms-braintree' ), 'type' => 'field_select' ],
					[
						'name'  => 'billing_fields_header',
						'label' => esc_html__( 'Billing Address Field Mapping', 'gravity-forms-braintree' ),
						'type'  => 'section',
					],
					[ 'name' => 'billing_address_field_map_street',  'label' => esc_html__( 'Street Address', 'gravity-forms-braintree' ), 'type' => 'field_select' ],
					[ 'name' => 'billing_address_field_map_street2', 'label' => esc_html__( 'Address Line 2', 'gravity-forms-braintree' ), 'type' => 'field_select' ],
					[ 'name' => 'billing_address_field_map_city',    'label' => esc_html__( 'City', 'gravity-forms-braintree' ), 'type' => 'field_select' ],
					[ 'name' => 'billing_address_field_map_state',   'label' => esc_html__( 'State / Region', 'gravity-forms-braintree' ), 'type' => 'field_select' ],
					[ 'name' => 'billing_address_field_map_postal',  'label' => esc_html__( 'Postal Code', 'gravity-forms-braintree' ), 'type' => 'field_select' ],
					[ 'name' => 'billing_address_field_map_country', 'label' => esc_html__( 'Country', 'gravity-forms-braintree' ), 'type' => 'field_select' ],
					[
						'name'           => 'conditionalLogic',
						'label'          => esc_html__( 'Conditional Logic', 'gravity-forms-braintree' ),
						'type'           => 'feed_condition',
						'checkbox_label' => esc_html__( 'Enable this feed if', 'gravity-forms-braintree' ),
						'instructions'   => esc_html__( 'Process payment only if the following conditions are met.', 'gravity-forms-braintree' ),
					],
				],
			],
		];
	}
}