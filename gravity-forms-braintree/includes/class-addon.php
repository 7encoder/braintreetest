<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBraintreeAddOn extends GFPaymentAddOn {

	use GFBraintree_AddOn_Processing_Trait;
	use GFBraintree_AddOn_Subscription_Trait;
	use GFBraintree_AddOn_Plan_Cache_Trait;
	use GFBraintree_AddOn_Settings_Trait;

	protected $_version                  = GF_BRAINTREE_VERSION;
	protected $_min_gravityforms_version = GF_BRAINTREE_MIN_GF_VERSION;
	protected $_slug                     = 'gravity-forms-braintree';
	protected $_path                     = 'gravity-forms-braintree/gravity-forms-braintree.php';
	protected $_full_path                = GF_BRAINTREE_PLUGIN_FILE;
	protected $_title                    = 'Gravity Forms Braintree';
	protected $_short_title              = 'Braintree';

	private static ?self $_instance = null;
	private ?GFBraintree_API $api   = null;

	private const BT_JS_VERSION = '3.97.0';

	public static function get_instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/* -------------------------------------------------
	 * INIT
	 * ------------------------------------------------- */
	public function init() {
		parent::init();

		$this->maybe_migrate_legacy_feed_meta();

		add_action( 'gform_validation', [ $this, 'maybe_validate_nonce' ], 10, 2 );
		add_filter( 'gform_payment_feed_column_value', [ $this, 'feed_column_value' ], 10, 4 );
		add_filter( 'gform_confirmation', [ $this, 'inject_friendly_error_confirmation' ], 8, 4 );
		add_action( 'gform_post_payment_addon_settings_save', [ $this, 'maybe_flush_after_plugin_save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_feed_scripts' ] );

		// Front-end injection.
		add_filter( 'gform_pre_render', [ $this, 'prepare_form_fields' ], 20 );
		add_filter( 'gform_pre_submission_filter', [ $this, 'prepare_form_fields' ], 20 );
		add_action( 'gform_enqueue_scripts', [ $this, 'enqueue_frontend_scripts' ], 10, 2 );
		add_action( 'wp_ajax_gf_braintree_client_token', [ $this, 'ajax_client_token' ] );
		add_action( 'wp_ajax_nopriv_gf_braintree_client_token', [ $this, 'ajax_client_token' ] );
		add_action( 'gform_entry_post_save', [ $this, 'store_nonce_post_save' ], 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_notices', [ $this, 'maybe_admin_warn_native_cc_global' ] );
			add_action( 'wp_ajax_gf_braintree_fetch_plans', [ $this, 'ajax_fetch_plans' ] );
		}
	}

	/* -------------------------------------------------
	 * FEED SETTINGS UI (Hardening)
	 * ------------------------------------------------- */
	public function feed_settings_fields() {

		// Transaction Type choices.
		$type_choices = [
			[
				'label' => esc_html__( 'One-Time Payment', 'gravity-forms-braintree' ),
				'value' => 'product',
			],
			[
				'label' => esc_html__( 'Subscription', 'gravity-forms-braintree' ),
				'value' => 'subscription',
			],
		];

		$plan_source_choices = [
			[
				'label' => esc_html__( 'Fixed Plan (select below)', 'gravity-forms-braintree' ),
				'value' => 'fixed',
			],
			[
				'label' => esc_html__( 'From Form Field (dynamic)', 'gravity-forms-braintree' ),
				'value' => 'field',
			],
		];

		$fields = [
			[
				// Group Title
				'title'  => esc_html__( 'Braintree Feed Settings', 'gravity-forms-braintree' ),
				'fields' => [
					[
						'label'   => esc_html__( 'Feed Name', 'gravity-forms-braintree' ),
						'type'    => 'text',
						'name'    => 'feed_name',
						'class'   => 'medium',
						'required'=> true,
						'tooltip' => esc_html__( 'Internal label for this feed.', 'gravity-forms-braintree' ),
						'default_value' => 'Braintree Feed',
					],
					[
						'label'   => esc_html__( 'Transaction Type', 'gravity-forms-braintree' ),
						'type'    => 'select',
						'name'    => 'transactionType', // canonical key
						'choices' => $type_choices,
						'required'=> true,
						'tooltip' => esc_html__( 'Choose one-time or subscription.', 'gravity-forms-braintree' ),
						'class'   => 'gf-braintree-transaction-type',
						'after_input' => '<div class="gf-braintree-inline-hint" data-gf-braintree-role="txn-hint"></div>',
					],
					[
						'label'        => esc_html__( 'Plan Source', 'gravity-forms-braintree' ),
						'type'         => 'select',
						'name'         => 'subscription_plan_source',
						'choices'      => $plan_source_choices,
						'dependency'   => [ 'field' => 'transactionType', 'values' => [ 'subscription' ] ],
						'tooltip'      => esc_html__( 'Fixed: pick a plan here. Field: supply a form field that returns a plan ID.', 'gravity-forms-braintree' ),
						'class'        => 'gf-braintree-plan-source',
					],
					[
						'label'       => esc_html__( 'Fixed Plan', 'gravity-forms-braintree' ),
						'type'        => 'select',
						'name'        => 'plan_id',
						'choices'     => [
							[ 'label' => esc_html__( 'Loading plans...', 'gravity-forms-braintree' ), 'value' => '' ],
						],
						'dependency'  => [
							'field'  => 'subscription_plan_source',
							'values' => [ 'fixed' ],
						],
						'tooltip'     => esc_html__( 'Select the Braintree plan for subscriptions.', 'gravity-forms-braintree' ),
						'class'       => 'gf-braintree-plan-select',
						'after_input' => '<button type="button" class="button small" data-gf-braintree-role="refresh-plans" style="margin-left:6px;">' . esc_html__( 'Reload Plans', 'gravity-forms-braintree' ) . '</button><span class="gf-braintree-plan-status" data-gf-braintree-role="plan-status" style="margin-left:8px;"></span>',
					],
					[
						'label'      => esc_html__( 'Plan Field', 'gravity-forms-braintree' ),
						'type'       => 'select',
						'name'       => 'plan_field',
						'choices'    => $this->get_form_plan_field_choices(),
						'dependency' => [
							'field'  => 'subscription_plan_source',
							'values' => [ 'field' ],
						],
						'tooltip'    => esc_html__( 'Select a form field whose submitted value equals a Braintree plan ID.', 'gravity-forms-braintree' ),
						'class'      => 'gf-braintree-plan-field',
					],
					[
						'label'       => esc_html__( 'Customer Email Field', 'gravity-forms-braintree' ),
						'type'        => 'select',
						'name'        => 'email_field',
						'choices'     => $this->get_form_email_field_choices(),
						'tooltip'     => esc_html__( 'Used for customer lookup / vault.', 'gravity-forms-braintree' ),
						'class'       => 'gf-braintree-email-field',
						'required'    => true,
					],
					[
						'label'       => esc_html__( 'Enable Conditional Logic', 'gravity-forms-braintree' ),
						'type'        => 'feed_condition',
						'name'        => 'feed_condition',
						'tooltip'     => esc_html__( 'Process this feed only if conditions match.', 'gravity-forms-braintree' ),
					],
				],
			],
		];

		return $fields;
	}

	private function get_form_plan_field_choices(): array {
		$form  = $this->get_current_form();
		$choices = [
			[ 'label' => esc_html__( '— Select Field —', 'gravity-forms-braintree' ), 'value' => '' ],
		];
		if ( $form ) {
			foreach ( $form['fields'] as $field ) {
				if ( ! empty( $field->id ) && ! empty( $field->label ) ) {
					$choices[] = [
						'label' => $field->label . ' (#' . $field->id . ')',
						'value' => (string) $field->id,
					];
				}
			}
		}
		return $choices;
	}

	private function get_form_email_field_choices(): array {
		$form  = $this->get_current_form();
		$choices = [
			[ 'label' => esc_html__( '— Select Email Field —', 'gravity-forms-braintree' ), 'value' => '' ],
		];
		if ( $form ) {
			foreach ( $form['fields'] as $field ) {
				if ( isset( $field->type ) && $field->type === 'email' ) {
					$choices[] = [
						'label' => $field->label . ' (#' . $field->id . ')',
						'value' => (string) $field->id,
					];
				}
			}
		}
		return $choices;
	}

	/* -------------------------------------------------
	 * Feed Save Normalization / Validation
	 * ------------------------------------------------- */
	public function save_feed_settings( $feed_id, $form_id, $settings ) {

		// Normalize transactionType meta (legacy key might appear).
		if ( empty( $settings['transactionType'] ) && ! empty( $settings['transaction_type'] ) ) {
			$settings['transactionType'] = $settings['transaction_type'];
			unset( $settings['transaction_type'] );
		}

		// Validate subscription requirements.
		if ( ( $settings['transactionType'] ?? '' ) === 'subscription' ) {
			$source = $settings['subscription_plan_source'] ?? 'fixed';
			if ( $source === 'fixed' ) {
				if ( empty( $settings['plan_id'] ) ) {
					return new WP_Error( 'missing_plan', __( 'Please select a Plan for this subscription feed.', 'gravity-forms-braintree' ) );
				}
			} elseif ( $source === 'field' ) {
				if ( empty( $settings['plan_field'] ) ) {
					return new WP_Error( 'missing_plan_field', __( 'Please choose a plan field (dynamic plan source).', 'gravity-forms-braintree' ) );
				}
			}
		}

		$res = parent::save_feed_settings( $feed_id, $form_id, $settings );

		// Purge plan cache when settings saved (so next reload fetches fresh list).
		delete_transient( $this->plan_cache_key() );

		return $res;
	}

	/* -------------------------------------------------
	 * Admin Script Enqueue
	 * ------------------------------------------------- */
	public function enqueue_admin_feed_scripts( $hook ) {
		// Only on feed edit page for this add-on.
		$is_feed_page = isset( $_GET['page'], $_GET['view'], $_GET['subview'] ) // phpcs:ignore
			&& $_GET['page'] === 'gf_edit_forms' // phpcs:ignore
			&& $_GET['view'] === 'settings'      // phpcs:ignore
			&& $_GET['subview'] === $this->_slug; // phpcs:ignore

		if ( ! $is_feed_page ) {
			return;
		}

		wp_enqueue_script(
			'gf-braintree-admin-feed',
			GF_BRAINTREE_PLUGIN_URL . 'includes/js/admin-feed.js',
			[ 'jquery' ],
			GF_BRAINTREE_VERSION,
			true
		);

		wp_localize_script(
			'gf-braintree-admin-feed',
			'gfBraintreeFeed',
			[
				'ajax'           => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'gf_braintree_admin' ),
				'i18n'           => [
					'loading'     => __( 'Loading plans…', 'gravity-forms-braintree' ),
					'failed'      => __( 'Failed to load plans. Retry?', 'gravity-forms-braintree' ),
					'empty'       => __( 'No plans found.', 'gravity-forms-braintree' ),
					'selectPlan'  => __( '— Select Plan —', 'gravity-forms-braintree' ),
					'invalidTx'   => __( 'Select a transaction type.', 'gravity-forms-braintree' ),
					'needPlan'    => __( 'Please select a plan before saving.', 'gravity-forms-braintree' ),
					'needPlanField'=> __( 'Please choose a plan field before saving.', 'gravity-forms-braintree' ),
				],
				'hasCreds'       => $this->credentials_complete(),
				'currentPlan'    => rgars( $_POST, "feed//meta/plan_id" ), // Just a fallback; GF repopulates automatically.
			]
		);

		$inline_css = '.gf-braintree-plan-status{font-size:12px;color:#555;}'
			. '.gf-braintree-plan-status.loading:before{content:"⏳ ";}'
			. '.gf-braintree-plan-status.error{color:#a40000;}'
			. '.gf-braintree-plan-status.ok{color:#2d6a2d;}';
		wp_add_inline_style( 'wp-admin', $inline_css );
	}

	/* -------------------------------------------------
	 * AJAX: Fetch Plans
	 * ------------------------------------------------- */
	public function ajax_fetch_plans() {
		check_ajax_referer( 'gf_braintree_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		if ( ! $this->credentials_complete() ) {
			wp_send_json_error( [ 'message' => 'missing_credentials' ], 400 );
		}

		try {
			$plans = $this->get_api()->get_plans();
			if ( empty( $plans ) ) {
				wp_send_json_success( [ 'plans' => [], 'empty' => true ] );
			}
			$out = array_map(
				static function ( $p ) {
					return [
						'id'    => $p['id'],
						'name'  => $p['name'] . ( $p['price'] !== null ? ' (' . $p['price'] . ')' : '' ),
						'price' => $p['price'],
					];
				},
				$plans
			);
			wp_send_json_success( [ 'plans' => $out ] );
		} catch ( Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
		}
	}

	/* -------------------------------------------------
	 * Existing methods below (unchanged except where necessary)
	 * ------------------------------------------------- */

	private function maybe_migrate_legacy_feed_meta(): void {
		$flag = get_option( 'gf_braintree_txn_key_migrated_v2', '0' );
		if ( '1' === $flag || ! class_exists( 'GFAPI' ) ) {
		 return;
		}
		$feeds = GFAPI::get_feeds( null, null, $this->_slug );
		if ( is_wp_error( $feeds ) ) {
		 return;
		}
		foreach ( $feeds as $feed ) {
			$meta = $feed['meta'] ?? [];
			if ( isset( $meta['transaction_type'] ) && empty( $meta['transactionType'] ) ) {
				$meta['transactionType'] = $meta['transaction_type'];
				GFAPI::update_feed( (int) $feed['id'], (int) $feed['form_id'], $meta, $this->_slug );
			}
		}
		update_option( 'gf_braintree_txn_key_migrated_v2', '1', false );
	}

	public function prepare_form_fields( $form ) {
		if ( ! $this->form_has_active_braintree_feed( (int) $form['id'] ) ) {
			return $form;
		}

		$needed = [
			'braintree_payment_method_nonce',
			'braintree_card_type',
			'braintree_card_last4',
		];
		$present = [];
		foreach ( $form['fields'] as $field ) {
			if ( isset( $field->inputName ) && in_array( $field->inputName, $needed, true ) ) {
				$present[] = $field->inputName;
			}
		}
		foreach ( $needed as $name ) {
			if ( ! in_array( $name, $present, true ) ) {
				$f            = new GF_Field_Hidden();
				$f->label     = ucfirst( str_replace( '_', ' ', $name ) );
				$f->inputName = $name;
				$f->id        = $this->next_field_id( $form );
				$form['fields'][] = $f;
			}
		}

		$has = false;
		foreach ( $form['fields'] as $field ) {
			if ( $field instanceof GF_Field_HTML && strpos( (string) $field->content, 'gf-braintree-hosted-fields' ) !== false ) {
				$has = true;
				break;
			}
		}
		if ( ! $has ) {
			$html          = new GF_Field_HTML();
			$html->id      = $this->next_field_id( $form );
			$html->label   = 'Payment Details';
			$html->content = $this->hosted_fields_markup( (int) $form['id'] );
			$form['fields'][] = $html;
		}

		return $form;
	}

	private function hosted_fields_markup( int $form_id ): string {
		$include_postal = (bool) $this->get_plugin_setting( 'enable_hosted_postal' );
		$postal_html    = $include_postal
			? '<div class="gf-braintree-row gf-braintree-postal">
					<label>' . esc_html__( 'Postal Code', 'gravity-forms-braintree' ) . '</label>
					<div id="bt-postal-' . esc_attr( $form_id ) . '" class="bt-input"></div>
			   </div>'
			: '';
		return '
<div class="gf-braintree-hosted-fields" data-form-id="' . esc_attr( $form_id ) . '" data-postal="' . ( $include_postal ? '1' : '0' ) . '">
	<div class="gf-braintree-fieldset">
		<div class="gf-braintree-row">
			<label>' . esc_html__( 'Card Number', 'gravity-forms-braintree' ) . '</label>
			<div id="bt-card-number-' . esc_attr( $form_id ) . '" class="bt-input"></div>
		</div>
		<div class="gf-braintree-row gf-braintree-flex">
			<div class="gf-braintree-sub">
				<label>' . esc_html__( 'Expiration Date', 'gravity-forms-braintree' ) . '</label>
				<div id="bt-expiration-date-' . esc_attr( $form_id ) . '" class="bt-input"></div>
			</div>
			<div class="gf-braintree-sub">
				<label>' . esc_html__( 'CVV', 'gravity-forms-braintree' ) . '</label>
				<div id="bt-cvv-' . esc_attr( $form_id ) . '" class="bt-input"></div>
			</div>
		</div>
		' . $postal_html . '
		<div class="gf-braintree-errors" aria-live="polite"></div>
	</div>
</div>';
	}

	private function next_field_id( $form ): int {
		$max = 0;
		foreach ( $form['fields'] as $f ) {
			$max = max( $max, (int) $f->id );
		}
		return $max + 1;
	}

	private function form_has_active_braintree_feed( int $form_id ): bool {
		if ( ! class_exists( 'GFAPI' ) ) {
			return false;
		}
		$feeds = GFAPI::get_feeds( $form_id, null, $this->_slug );
		if ( is_wp_error( $feeds ) || empty( $feeds ) ) {
			return false;
		}
		foreach ( $feeds as $feed ) {
			if ( ! empty( $feed['is_active'] ) ) {
				return true;
			}
		}
		return false;
	}

	public function enqueue_frontend_scripts( $form, $is_ajax ) {
		if ( ! $this->form_has_active_braintree_feed( (int) $form['id'] ) ) {
			return;
		}
		wp_enqueue_script(
			'braintree-client',
			'https://js.braintreegateway.com/web/' . self::BT_JS_VERSION . '/js/client.min.js',
			[],
			self::BT_JS_VERSION,
			true
		);
		wp_enqueue_script(
			'braintree-hosted-fields',
			'https://js.braintreegateway.com/web/' . self::BT_JS_VERSION . '/js/hosted-fields.min.js',
			[ 'braintree-client' ],
			self::BT_JS_VERSION,
			true
		);
		wp_enqueue_style(
			'gf-braintree-frontend',
			GF_BRAINTREE_PLUGIN_URL . 'includes/css/frontend.css',
			[],
			GF_BRAINTREE_VERSION
		);
		wp_enqueue_script(
			'gf-braintree-frontend',
			GF_BRAINTREE_PLUGIN_URL . 'includes/js/frontend.js',
			[ 'jquery', 'braintree-client', 'braintree-hosted-fields' ],
			GF_BRAINTREE_VERSION,
			true
		);
		wp_localize_script(
			'gf-braintree-frontend',
			'gfBraintreeFront',
			[
				'ajax' => admin_url( 'admin-ajax.php' ),
				'i18n' => [
					'card_error' => __( 'Card processing error. Please verify your details.', 'gravity-forms-braintree' ),
				],
			]
		);
		$include_postal = (bool) $this->get_plugin_setting( 'enable_hosted_postal' );
		$config         = [
			'formId' => (int) $form['id'],
			'postal' => $include_postal,
		];
		wp_add_inline_script(
			'gf-braintree-frontend',
			'window.gfBraintreeForms = window.gfBraintreeForms || {}; window.gfBraintreeForms[' . (int) $form['id'] . '] = ' . wp_json_encode( $config ) . ';',
			'after'
		);
	}

	public function ajax_client_token() {
		if ( ! $this->credentials_complete() ) {
			wp_send_json_error( [ 'message' => 'credentials' ], 400 );
		}
		try {
			$token = $this->get_api()->generate_client_token();
			wp_send_json_success( [ 'token' => $token ] );
		} catch ( Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
		}
	}

	public function store_nonce_post_save( $entry, $form ) {
		if ( empty( $entry['braintree_payment_method_nonce'] ) && isset( $_POST['braintree_payment_method_nonce'] ) ) { // phpcs:ignore
			$entry['braintree_payment_method_nonce'] = sanitize_text_field( wp_unslash( $_POST['braintree_payment_method_nonce'] ) ); // phpcs:ignore
			GFAPI::update_entry( $entry );
		}
		return $entry;
	}

	public function feed_column_value( $value, $column, $feed, $form_id ) {
		if ( 'transactionType' === $column ) {
			return esc_html(
				rgar(
					$feed['meta'],
					'transactionType',
					rgar( $feed['meta'], 'transaction_type', 'product' )
				)
			);
		}
		return $value;
	}

	public function inject_friendly_error_confirmation( $confirmation, $form, $entry, $is_ajax ) {
		if ( ! is_array( $entry ) ) {
		 return $confirmation;
		}
		if ( ! $this->form_has_active_braintree_feed( (int) $form['id'] ) ) {
		 return $confirmation;
		}
		if ( 'Failed' === rgar( $entry, 'payment_status' ) ) {
			return '<div class="gform_confirmation_wrapper gform_confirmation_wrapper_error">'
				. '<div class="gform_confirmation_message gform_confirmation_error" style="color:#b00020;">'
				. esc_html__( 'We could not process your payment. Please verify your card details and try again.', 'gravity-forms-braintree' )
				. '</div></div>';
		}
		return $confirmation;
	}

	public function maybe_admin_warn_native_cc_global() {
		if ( ! isset( $_GET['page'] ) || 'gf_edit_forms' !== $_GET['page'] ) { // phpcs:ignore
			return;
		}
		if ( ! isset( $_GET['id'] ) ) { // phpcs:ignore
			return;
		}
		$form = GFAPI::get_form( (int) $_GET['id'] ); // phpcs:ignore
		if ( ! $form ) {
			return;
		}
		foreach ( $form['fields'] as $f ) {
			if ( isset( $f->type ) && $f->type === 'creditcard' ) {
				echo '<div class="notice notice-warning"><p>'
					. esc_html__( 'Gravity Forms native Credit Card field detected. Remove it; the Braintree add-on injects Hosted Fields automatically.', 'gravity-forms-braintree' )
					. '</p></div>';
				break;
			}
		}
	}

	public function get_api(): GFBraintree_API {
		if ( $this->api ) {
		 return $this->api;
		}
		$this->api = new GFBraintree_API(
			[
				'environment'  => $this->get_plugin_setting( 'environment' ),
				'merchant_id'  => $this->get_plugin_setting( 'merchant_id' ),
				'public_key'   => $this->get_plugin_setting( 'public_key' ),
				'private_key'  => $this->get_plugin_setting( 'private_key' ),
			]
		);
		return $this->api;
	}

	protected function has_payment_gateway() {
		return true;
	}

	public function maybe_validate_nonce( $validation_result, $form ) {
		return $validation_result;
	}

	public function toggle_feed_active() {}

	public function maybe_flush_after_plugin_save( $settings, $addon ) {
		if ( $addon !== $this ) {
			return;
		}
		delete_transient( $this->plan_cache_key() );
	}

	public function fail_entry( &$entry, string $message ) {
		$entry['payment_status'] = 'Failed';
		GFAPI::update_entry( $entry );
		$this->add_note( $entry['id'], 'Braintree failure: ' . esc_html( $message ) );
	}

	public function handle_gateway_failure( &$entry, $result, string $context ) {
		$msg = $result->message ?? 'Unknown error';
		$this->fail_entry( $entry, $msg );
		GFBraintree_Logger::error( 'Gateway failure', [ 'context' => $context, 'message' => $msg ] );
	}

	public function filter_user_friendly_message( string $raw ): string {
		return $raw;
	}
}