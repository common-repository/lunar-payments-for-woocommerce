<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Lunar_MobilePay class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Lunar_MobilePay extends WC_Payment_Gateway {

	use LunarGatewayTrait;

	/**
	 * Is test mode active?
	 *
	 * @var bool
	 */
	public $testmode;

	/**
	 * API access secret key
	 *
	 * @var string
	 */
	public $secret_key;

	/**
	 * Api access public key
	 *
	 * @var string
	 */
	public $public_key;

	/**
	 * Used to validate the public key.
	 *
	 * @var array
	 */
	public $validation_test_public_keys = array();

	/**
	 * Used to validate the live public key.
	 *
	 * @var array
	 */
	public $validation_live_public_keys = array();

	public $logo;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id = 'lunar_mobilepay';
		$this->method_title = __( 'Lunar MobilePay', 'lunar-payments-for-woocommerce' );
		$this->method_description = __( 'Let your customers pay with MobilePay. Send an email to onlinepayments@lunar.app to get started.', 'lunar-payments-for-woocommerce' );
		$this->supports = array(
			'products',
			'refunds',
		);

		// Load the form fields.
		$this->init_form_fields();
		// Load the settings.
		$this->init_settings();

		$this->testmode = false;

		if ( isset( $_COOKIE['lunar_mobilepay_testmode'] ) ) {
			$this->testmode = true;
		}

		$this->title = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled = $this->get_option( 'enabled' );
		$this->capture = 'instant' === $this->get_option( 'capture', 'instant' );
		$this->checkout_mode = 'before_order' === $this->get_option( 'checkout_mode', 'before_order' );

		$this->secret_key = $this->get_option( 'secret_key' );
		$this->secret_key = apply_filters( 'lunar_mobilepay_secret_key', $this->secret_key );
		$this->public_key = $this->get_option( 'public_key' );
		$this->public_key = apply_filters( 'lunar_mobilepay_public_key', $this->public_key );

		$this->logo = $this->get_option( 'logo' );

		if ( '' !== $this->secret_key ) {
			$this->lunar_client = new Paylike\Paylike( $this->secret_key );
		}

		// Hooks.
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'template_redirect', array( $this, 'receipt_page_server' ), 9999 );
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'return_handler' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options',
		) );
	}

	public function is_available() {
		if ( is_add_payment_method_page() && ! $this->store_payment_method ) {
			return false;
		}

		if ( 'yes' === $this->enabled ) {
			if ( ! $this->testmode && is_checkout() && ! is_ssl() ) {
				return false;
			}
			if ( ! $this->secret_key || ! $this->public_key ) {
				return false;
			}

			if(! $this->logo){
				return false;
			}

			$current_currency = get_woocommerce_currency();
			$supported = get_lunar_currency( $current_currency );
			if ( ! $supported ) {
				return false;
			}

			return true;
		}

		return false;
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = include( 'settings-lunar-mobilepay.php' );
	}

	/**
	 * Process options save hook.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$this->init_settings();

		$post_data = $this->get_post_data();

		foreach ( $this->get_form_fields() as $key => $field ) {
			if ( 'title' !== $this->get_field_type( $field ) ) {
				try {
					$this->settings[ $key ] = $this->get_field_value( $key, $field, $post_data );
				} catch ( Exception $e ) {
					$this->add_error( $e->getMessage() );
				}
			}
		}

		return update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ) );
	}

	/**
	 * Get_icon function.
	 *
	 * @access public
	 * @return string
	 */
	public function get_icon() {
		$icon = '<img style="max-height:20px" src="' . esc_url( plugins_url( '../assets/images/mobilepay-logo.png', __FILE__ ) ) . '" alt="Lunar Mobilepay Gateway" />';

		return apply_filters( 'woocommerce_lunar_icon', $icon, $this->id );
	}

	/**
	 * Process the payment
	 *
	 * @param int $order_id Reference.
	 *
	 * @return array|void
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );


		if ( ! $this->checkout_mode ) {

			// Return thank you page redirect.
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true ),
			);
		} else {
			$order = $this->process_default_payment( $order );

			// Return thank you page redirect.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
	}

	/**
	 * Enque the payment scripts.
	 */
	public function payment_scripts() {
		global $wp_version;
		if ( ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
			return;
		}

		if ( isset( $_GET['key'] ) ) {
			$order_id = wc_get_order_id_by_order_key( urldecode( $_GET['key'] ) );
			$order = wc_get_order( $order_id );

			if ( $order->get_payment_method() !== $this->id ) {
				return;
			}
		}


		// If Lunar MobilePay is not enabled bail.
		if ( 'no' === $this->enabled ) {
			return;
		}

		wp_enqueue_script( 'woocommerce_lunar_utils', plugins_url( 'assets/js/lunar_checkout_utils.js', WC_LUNAR_MAIN_FILE ), '', '', true );
		wp_enqueue_script( 'woocommerce_lunar_mobilepay', plugins_url( 'assets/js/lunar_mobilepay_checkout.js', WC_LUNAR_MAIN_FILE ), array( 'woocommerce_lunar_utils' ), WC_LUNAR_VERSION, true );

		$lunar_mobilepay_params = array(
			'customer_IP'       => dk_get_client_ip(),
			'products'          => $this->get_products_for_custom_parameter(),
			'platform_version'  => $wp_version,
			'ecommerce_version' => WC()->version,
			'before_order'      => $this->checkout_mode,
			'version'           => WC_LUNAR_VERSION,
			'ajax_url'          => admin_url( 'admin-ajax.php' ),
		);
		wp_localize_script( 'woocommerce_lunar_mobilepay', 'wc_lunar_mobilepay_params', apply_filters( 'wc_lunar_mobilepay_params', $lunar_mobilepay_params ) );
	}

	/**
	 * Payment form on checkout page
	 */
	public function payment_fields() {
		if ( 'before_order' == $this->checkout_mode ) {
			$user = wp_get_current_user();
			if ( $user->ID ) {
				$user_email = get_user_meta( $user->ID, 'billing_email', true );
				$user_email = $user_email ? $user_email : $user->user_email;
			} else {
				$user_email = '';
			}
			$user_name = '';
			$user_address = '';
			$user_phone = '';
			$token = '';


			/**
			 * If we are on the failed payment page we need to use the order instead of the cart.
			 *
			 */
			if ( ! isset( $_GET['pay_for_order'] ) ) {
				$order_id = 'Could not be determined at this point';
				$amount = WC()->cart->total;
				$amount_tax = WC()->cart->tax_total;
				$amount_shipping = WC()->cart->shipping_total;
				$currency = get_woocommerce_currency();
			} else {
				$order_id = wc_get_order_id_by_order_key( urldecode( $_GET['key'] ) );
				$order = wc_get_order( $order_id );
				$currency = dk_get_order_currency( $order );
				$amount = $order->get_total();
				$amount_tax = $order->get_total_tax();
				$amount_shipping = dk_get_order_shipping_total( $order );
				$user_email = dk_get_order_data( $order, 'get_billing_email' );
				$user_name = dk_get_order_data( $order, 'get_billing_first_name' ) . ' ' . dk_get_order_data( $order, 'get_billing_last_name' );
				$user_address = dk_get_order_data( $order, 'get_billing_address_1' ) . ' ' . dk_get_order_data( $order, 'get_billing_address_2' );
				$user_phone = dk_get_order_data( $order, 'get_billing_phone' );
			}


			echo '<div
			id="lunar-mobilepay-payment-data"' . '"
			data-email="' . esc_attr( $user_email ) . '"
			data-name="' . esc_attr( $user_name ) . '"
			data-phone="' . esc_attr( $user_phone ) . '"
			data-test="' . esc_attr( $this->testmode ) . '"
			data-address="' . esc_attr( $user_address ) . '"
			data-locale="' . esc_attr( dk_get_locale() ) . '"
			data-order_id="' . esc_attr( $order_id ) . '"
			data-amount="' . esc_attr( convert_wocoomerce_float_to_lunar_amount( $amount ) ) . '"
			data-decimals="' . esc_attr( wc_get_price_decimals() ) . '"
			data-totalTax="' . esc_attr( convert_wocoomerce_float_to_lunar_amount( $amount_tax ) ) . '"
			data-totalShipping="' . esc_attr( convert_wocoomerce_float_to_lunar_amount( $amount_shipping ) ) . '"
			data-customerIP="' . esc_attr( dk_get_client_ip() ) . '"
			data-currency="' . esc_attr( $currency ) . '"
			">';
			echo $token; // WPCS: XSS ok.
			echo '</div>';
		}

		if ( $this->description ) {
			echo wpautop( wp_kses_post( apply_filters( 'wc_lunar_mobilepay_description', $this->description ) ) );
		}


	}


	/**
	 * Display the pay button on the receipt page.
	 *
	 * @param int $order_id The order reference id.
	 */
	public function receipt_page( $order_id ) {
		global $wp_version;
		$order = wc_get_order( $order_id );
		$order_hints = $order->get_meta('_lunar_mobilepay_hints', true );
		$currency = dk_get_order_currency( $order );
		$decimals = wc_get_price_decimals();
		$amount = $order->get_total();
		$amount_tax = $order->get_total_tax();
		$amount_shipping = dk_get_order_shipping_total( $order );
		$user_email = dk_get_order_data( $order, 'get_billing_email' );
		$user_name = dk_get_order_data( $order, 'get_billing_first_name' ) . ' ' . dk_get_order_data( $order, 'get_billing_last_name' );
		$user_address = dk_get_order_data( $order, 'get_billing_address_1' ) . ' ' . dk_get_order_data( $order, 'get_billing_address_2' );
		$user_phone = dk_get_order_data( $order, 'get_billing_phone' );


		if ( $theme_template = locate_template( 'lunar/receipt-mobilepay.php' ) ) {
			require $theme_template;
		} else {
			$plugin_template = WC_LUNAR_PLUGIN_TEMPLATE_DIR . '/receipt-mobilepay.php';
			if ( file_exists( $plugin_template ) ) {
				require $plugin_template;
			}
		}
		$version = get_option( 'lunar_sdk_version', WC_LUNAR_CURRENT_SDK );

		echo '<div
			id="lunar-mobilepay-payment-data"' . '"
			data-email="' . esc_attr( $user_email ) . '"
			data-name="' . esc_attr( $user_name ) . '"
			data-phone="' . esc_attr( $user_phone ) . '"
			data-address="' . esc_attr( $user_address ) . '"
			data-locale="' . esc_attr( dk_get_locale() ) . '"
			data-order_id="' . esc_attr( $order_id ) . '"
			data-amount="' . esc_attr( convert_wocoomerce_float_to_lunar_amount( $amount ) ) . '"
			data-decimals="' . esc_attr( wc_get_price_decimals() ) . '"
			data-totalTax="' . esc_attr( convert_wocoomerce_float_to_lunar_amount( $amount_tax ) ) . '"
			data-totalShipping="' . esc_attr( convert_wocoomerce_float_to_lunar_amount( $amount_shipping ) ) . '"
			data-customerIP="' . esc_attr( dk_get_client_ip() ) . '"
			data-currency="' . esc_attr( $currency ) . '"
			">';
		echo '</div>';
		?>
		<form style="display: inline-block" id="mobilepay_complete_order" method="POST"
				action="<?php echo esc_url( WC()->api_request_url( get_class( $this ) ) ); ?>">
			<input type="hidden" name="reference" value="<?php echo esc_attr( $order_id ); ?>"/>
			<input type="hidden" name="amount" value="<?php echo esc_attr( $this->get_order_total() ); ?>"/>
			<input type="hidden" name="signature"
					value="<?php echo esc_attr( $this->get_signature( $order_id ) ); ?>"/>
			<button id="lunar-mobilepay-payment-button">
				<?php
				if ( $order_hints ) {
					_e( 'Finalize payment', 'lunar-payments-for-woocommerce' );
				} else {
					_e( 'Pay Now', 'lunar-payments-for-woocommerce' );
				}
				?>
			</button>
		</form>
		<?php
	}

	/**
	 * Display the pay button on the receipt page.
	 *
	 * @param int $order_id The order reference id.
	 */
	public function receipt_page_server() {
		global $wp_version;

		if(!is_checkout_pay_page()) {
			return;
		}else{
			$order_id = absint( get_query_var( 'order-pay' ) );
		}

		$order = wc_get_order( $order_id );
		$payment_method = $order->get_payment_method();
		if($payment_method != $this->id) {
			return;
		}

		$resolved = $this->attemptServerResolve( $this->get_mobilepay_args( $order ),$order
		);


	}

	public function get_mobilepay_args($order){
		global $wp_version;

		$order_hints = $order->get_meta('_lunar_mobilepay_hints', true );
		$currency = dk_get_order_currency( $order );
		$decimals = wc_get_price_decimals();
		$amount = $order->get_total();
		$amount_tax = $order->get_total_tax();
		$amount_shipping = dk_get_order_shipping_total( $order );
		$user_email = dk_get_order_data( $order, 'get_billing_email' );
		$user_name = dk_get_order_data( $order, 'get_billing_first_name' ) . ' ' . dk_get_order_data( $order, 'get_billing_last_name' );
		$user_address = dk_get_order_data( $order, 'get_billing_address_1' ) . ' ' . dk_get_order_data( $order, 'get_billing_address_2' );
		$user_phone = dk_get_order_data( $order, 'get_billing_phone' );

		return array(
			'amount' => array(
				'value'   => convert_wocoomerce_float_to_lunar_amount( $amount ),
				'currency' => $currency,
				'exponent' => $decimals,
			),
			'custom' => array(
				'email'              => $user_email,
				'orderId'            => $order->get_id(),
				'products'           => $this->get_products_for_custom_parameter(),
				'customer'           => array(
					'name'    => $user_name,
					'email'   => $user_email,
					'phoneNo' => $user_phone,
					'address' => $user_address,
					'IP'      => dk_get_client_ip(),
				),
				'platform'           => array(
					'name'    => 'WordPress',
					'version' => $wp_version,
				),
				'ecommerce'          => array(
					'name'    => 'WooCommerce',
					'version' => WC()->version,
				),
				'lunarPluginVersion' => WC_LUNAR_VERSION
			)
		);
	}

	public function attemptServerResolve( $args, $order ) {
		global $wp;
		// the plan is to avoid showing the payment script unless we need to show the iframe challenge or errors from the server

		if(!isset($args['custom']['orderId'])){
			$args['custom']['orderId'] = $order->get_id();
		}
		$mobilePayChallengeHandler = new WC_Lunar_MobilePay_Challenges( $args, false, home_url(add_query_arg($wp->query_vars, $wp->request)) );

		$response = $mobilePayChallengeHandler->handle( false );
		if ( isset( $response['error'] ) ) {
			return false; // have the user take over
		}

		if(isset($response['data']['authorizationId'])) {
			$transaction_id = $response['data']['authorizationId'];{

				if ( $order->get_total() > 0 && $args['amount']['value'] != 0 ) {
					$this->handle_payment( $transaction_id, $order );
				} else {
					// used for trials, and changing payment method.
					if ( $transaction_id ) {
						$this->save_transaction_id( [ 'id' => $transaction_id ], $order );
					}
					$order->payment_complete();
				}
				wp_redirect( $this->get_return_url( $order ) );
				exit;
			}
		}

		if ( isset($response['data']['type']) && ($response['data']['type']) === 'redirect' ) {
			wp_redirect( $response['data']['url'] );
			exit;
		}





	}

	/**
	 * Validate the secret key.
	 *
	 * @param string $key the name of the attribute.
	 * @param string $value the value of the input.
	 *
	 * @return string
	 * @throws Exception
	 */
	public function validate_secret_key_field( $key, $value ) {

		if ( $this->testmode ) {
			return $value;
		}

		if ( ! $value ) {
			return $value;
		}
		$api_exception = null;
		$lunar_client = new \Paylike\Paylike( $value );
		try {
			$identity = $lunar_client->apps()->fetch();
		} catch ( \Paylike\Exception\ApiException $exception ) {
			$error = __( "The App key doesn't seem to be valid", 'lunar-payments-for-woocommerce' );
			$error = WC_Lunar::handle_exceptions( null, $exception, $error );
			WC_Admin_Settings::add_error( $error );
			throw new Exception( $error );
		}
		try {
			$merchants = $lunar_client->merchants()->find( $identity['id'] );
			if ( $merchants ) {
				foreach ( $merchants as $merchant ) {
					if ( ! $merchant['test'] ) {
						$this->validation_live_public_keys[] = $merchant['key'];
					}
				}
			}
		} catch ( \Paylike\Exception\ApiException $exception ) {
			// we handle in the following statement
			$api_exception = $exception;
		}
		if ( empty( $this->validation_live_public_keys ) ) {
			$error = __( 'The App key is not valid.', 'lunar-payments-for-woocommerce' );
			if ( $api_exception ) {
				$error = WC_Lunar::handle_exceptions( null, $api_exception, $error );
			}
			WC_Admin_Settings::add_error( $error );
			throw new Exception( $error );
		}

		return $value;
	}

	/**
	 * Validate the public key.
	 *
	 * @param string $key the name of the attribute.
	 * @param string $value the value of the input.
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function validate_public_key_field( $key, $value ) {

		if ( $this->testmode ) {
			return $value;
		}

		if ( $value ) {
			if ( ! empty( $this->validation_live_public_keys ) ) {
				if ( ! in_array( $value, $this->validation_live_public_keys ) ) {
					$error = __( 'The Public key doesn\'t seem to be valid', 'lunar-payments-for-woocommerce' );
					WC_Admin_Settings::add_error( $error );
					throw new Exception( $error );
				}
			}
		}

		return $value;
	}

	/**
	 * Validate mobile pay config
	 *
	 * @param string $key the name of the attribute.
	 * @param string $value the value of the input.
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function validate_mobilepay_configuration_id_field( $key, $value ) {

		if ( $this->testmode ) {
			return $value;
		}

		if ( $value ) {
			if ( strlen( $value ) > 32 ) {
				$error = __( 'The Mobile Pay config id key doesn\'t seem to be valid. It should not have more than 32 characters. Current count:', 'lunar-payments-for-woocommerce' );
				$error .= ' ' . strlen( $value );
				WC_Admin_Settings::add_error( $error );
				throw new Exception( $error );
			}
		}

		return $value;
	}

	/** Check payment via polling in case the redirect failed */
	public function check_payment($order){
		global $wp;
		$mobilePayChallengeHandler = new WC_Lunar_MobilePay_Challenges( $this->get_mobilepay_args($order), false, home_url(add_query_arg($_GET, $wp->request)) );

		$response = $mobilePayChallengeHandler->handle( false );
		if ( isset( $response['error'] ) ) {
			$order->add_order_note(
				__( 'Server returned an error durring polling', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
				$response['error']
			);
			return false; // have the user take over
		}

		if(isset($response['data']['authorizationId'])) {
			$transaction_id = $response['data']['authorizationId'];{
				$order->add_order_note(
					__( 'Polling returned a transaction id', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
					__( 'Authorization ID: ', 'lunar-payments-for-woocommerce' ) . $result['id'] . PHP_EOL .
					$response['data']['authorizationId']
				);
				if ( $order->get_total() > 0 && $args['amount']['value'] != 0 ) {
					$this->handle_payment( $transaction_id, $order );
				} else {
					// used for trials, and changing payment method.
					if ( $transaction_id ) {
						$this->save_transaction_id( [ 'id' => $transaction_id ], $order );
					}
					$order->payment_complete();
				}
			}
		}
	}


}
