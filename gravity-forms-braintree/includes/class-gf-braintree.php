<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$gf_bt_includes = [
	'class-gf-braintree-logger.php',
	'class-gf-braintree-processing.php',
	'class-gf-braintree-subscription.php',
	'class-gf-braintree-settings.php',
	'class-gf-braintree-api.php',
	'class-gf-braintree-helpers.php',
	'class-gf-braintree-webhook.php',
];

foreach ( $gf_bt_includes as $rel ) {
	$path = __DIR__ . '/' . $rel;
	if ( file_exists( $path ) ) {
		require_once $path;
	} else {
		error_log( '[GF Braintree][BOOT] Missing include: ' . $path );
	}
}

if ( ! class_exists( 'GFBraintree' ) && class_exists( 'GFPaymentAddOn' ) ) {

	class GFBraintree extends GFPaymentAddOn {

		use GFBraintree_Settings_Trait;
		use GFBraintree_Processing_Trait;
		use GFBraintree_Subscription_Trait;

		const VERSION = '1.1.5';
		const SLUG    = 'gravity-forms-braintree';

		protected $_version                  = self::VERSION;
		protected $_slug                     = self::SLUG;
		protected $_path                     = 'gravity-forms-braintree/gravity-forms-braintree.php';
		protected $_full_path                = GF_BRAINTREE_PLUGIN_FILE;
		protected $_title                    = 'Gravity Forms Braintree';
		protected $_short_title              = 'Braintree';
		protected $_min_gravityforms_version = '2.7';
		protected $_supports_callbacks       = true;

		protected $api = null;

		public static function get_instance() {
			static $instance;
			if ( ! $instance ) {
				$instance = new self();
			}
			return $instance;
		}

		private function __construct() {
			parent::__construct();
		}

		public function init() {
			parent::init();

			if ( class_exists( 'GFBraintree_Webhook' ) ) {
				GFBraintree_Webhook::register();
			}

			add_action( 'gform_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ], 10, 2 );
			add_action( 'gform_after_fields', [ $this, 'maybe_output_hosted_fields_markup' ], 20, 2 );
		}

		protected function form_has_active_feed( $form_id ): bool {
			$feeds = $this->get_active_feeds( $form_id );
			return ! empty( $feeds );
		}

		public function enqueue_frontend_assets( $form, $is_ajax ) {
			if ( ! $this->form_has_active_feed( $form['id'] ) ) {
				return;
			}

			$token = gf_braintree_generate_client_token();
			if ( ! $token ) {
				GFBraintree_Logger::debug( 'No client token generated; skipping frontend enqueue.' );
				return;
			}

			wp_enqueue_style(
				'gf-braintree-frontend',
				plugins_url( 'assets/css/frontend.css', GF_BRAINTREE_PLUGIN_FILE ),
				[],
				self::VERSION
			);

			wp_enqueue_script(
				'braintree-client',
				'https://js.braintreegateway.com/web/3.103.0/js/client.min.js',
				[],
				null,
				true
			);
			wp_enqueue_script(
				'braintree-hosted-fields',
				'https://js.braintreegateway.com/web/3.103.0/js/hosted-fields.min.js',
				[ 'braintree-client' ],
				null,
				true
			);

			if ( $this->get_plugin_setting( 'collect_device_data' ) ) {
				wp_enqueue_script(
					'braintree-data-collector',
					'https://js.braintreegateway.com/web/3.103.0/js/data-collector.min.js',
					[ 'braintree-client' ],
					null,
					true
				);
			}

			$frontend_js_url = plugins_url( 'assets/js/frontend.js', GF_BRAINTREE_PLUGIN_FILE );

			wp_register_script(
				'gf-braintree-frontend-js',
				$frontend_js_url,
				[ 'jquery', 'braintree-client', 'braintree-hosted-fields' ],
				self::VERSION,
				true
			);

			$selectors = [
				'number'     => '#gf-braintree-card-number',
				'cvv'        => '#gf-braintree-card-cvv',
				'expiration' => '#gf-braintree-card-exp',
			];
			if ( $this->get_plugin_setting( 'enable_hosted_postal' ) ) {
				$selectors['postalCode'] = '#gf-braintree-postal';
			}

			wp_localize_script(
				'gf-braintree-frontend-js',
				'GFBraintreeData',
				[
					'clientToken'       => $token,
					'collectDeviceData' => (bool) $this->get_plugin_setting( 'collect_device_data' ),
					'selectors'         => $selectors,
					'nonceFieldName'    => 'gf_braintree_nonce',
					'deviceDataField'   => 'gf_braintree_device_data',
					'messages'          => [
						'cardError' => __( 'There was a problem validating your card. Please check the details and try again.', 'gravity-forms-braintree' ),
						'initError' => __( 'Payment fields could not be initialized.', 'gravity-forms-braintree' ),
						'noNonce'   => __( 'Payment authorization failed (no nonce).', 'gravity-forms-braintree' ),
					],
				]
			);

			wp_enqueue_script( 'gf-braintree-frontend-js' );
		}

		public function maybe_output_hosted_fields_markup( $form, $current_page ) {
			if ( empty( $form['id'] ) ) {
			 return;
			}
			if ( ! $this->form_has_active_feed( $form['id'] ) ) {
				return;
			}
			if ( ! apply_filters( 'gf_braintree_auto_inject', true, $form ) ) {
				return;
			}
			if ( $this->form_already_has_hosted_fields( $form ) ) {
				return;
			}
			$include_postal = (bool) $this->get_plugin_setting( 'enable_hosted_postal' );
			?>
			<div class="gf-braintree-wrapper gf-braintree-style--monospace" data-form-id="<?php echo esc_attr( $form['id'] ); ?>">
				<div class="gf-braintree-field gf-braintree-card-number">
					<label for="gf-braintree-card-number"><?php esc_html_e( 'Card Number', 'gravity-forms-braintree' ); ?></label>
					<span id="gf-braintree-card-number" class="bt-hosted-field" aria-label="<?php esc_attr_e( 'Card Number Field', 'gravity-forms-braintree' ); ?>"></span>
					<span class="gf-braintree-brand-badge" aria-hidden="true"></span>
				</div>
				<div class="gf-braintree-row">
					<div class="gf-braintree-field gf-braintree-card-exp">
						<label for="gf-braintree-card-exp"><?php esc_html_e( 'Expiration', 'gravity-forms-braintree' ); ?></label>
						<span id="gf-braintree-card-exp" class="bt-hosted-field" aria-label="<?php esc_attr_e( 'Expiration Field', 'gravity-forms-braintree' ); ?>"></span>
					</div>
					<div class="gf-braintree-field gf-braintree-card-cvv">
						<label for="gf-braintree-card-cvv"><?php esc_html_e( 'CVV', 'gravity-forms-braintree' ); ?></label>
						<span id="gf-braintree-card-cvv" class="bt-hosted-field" aria-label="<?php esc_attr_e( 'CVV Field', 'gravity-forms-braintree' ); ?>"></span>
					</div>
					<?php if ( $include_postal ) : ?>
						<div class="gf-braintree-field gf-braintree-card-postal">
							<label for="gf-braintree-postal"><?php esc_html_e( 'Postal Code', 'gravity-forms-braintree' ); ?></label>
							<span id="gf-braintree-postal" class="bt-hosted-field" aria-label="<?php esc_attr_e( 'Postal Code Field', 'gravity-forms-braintree' ); ?>"></span>
						</div>
					<?php endif; ?>
				</div>
				<div class="gf-braintree-messages" aria-live="polite"></div>
			</div>
			<?php
		}

		protected function form_already_has_hosted_fields( $form ): bool {
			if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
				return false;
			}
			foreach ( $form['fields'] as $field ) {
				if ( isset( $field->content ) && is_string( $field->content ) && strpos( $field->content, 'gf-braintree-card-number' ) !== false ) {
					return true;
				}
				if ( isset( $field->cssClass ) && strpos( (string) $field->cssClass, 'gf-braintree-wrapper' ) !== false ) {
					return true;
				}
			}
			return false;
		}

		public function get_api() {
			if ( ! $this->is_valid_key() ) {
			 return null;
			}
			if ( ! $this->api ) {
				$this->api = new GFBraintree_API(
					[
						'environment' => $this->get_plugin_setting( 'environment' ),
						'merchantId'  => $this->get_plugin_setting( 'merchant_id' ),
						'publicKey'   => $this->get_plugin_setting( 'public_key' ),
						'privateKey'  => $this->get_plugin_setting( 'private_key' ),
					]
				);
			}
			return $this->api;
		}

		public function is_valid_key(): bool {
			return (bool) ( $this->get_plugin_setting( 'merchant_id' )
				&& $this->get_plugin_setting( 'public_key' )
				&& $this->get_plugin_setting( 'private_key' ) );
		}
	}
}

if ( ! function_exists( 'gf_braintree' ) && class_exists( 'GFPaymentAddOn' ) ) {
	function gf_braintree() {
		return GFBraintree::get_instance();
	}
}