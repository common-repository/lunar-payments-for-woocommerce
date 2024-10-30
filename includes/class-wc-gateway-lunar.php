<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Lunar class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Lunar extends WC_Payment_Gateway {

	use LunarGatewayTrait;

	/**
	 * Should we capture Credit cards
	 *
	 * @var bool
	 */
	public $capture;

	/**
	 * Show payment popup on the checkout action.
	 *
	 * @var bool
	 */
	public $checkout_mode;

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
	 * Is test mode active?
	 *
	 * @var bool
	 */
	public $testmode;

	/**
	 * Logging enabled?
	 *
	 * @var bool
	 */
	public $logging;

	/**
	 * Allowed card types
	 *
	 * @var bool
	 */
	public $card_types;

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

	/**
	 * Holds an instance of the Lunar client
	 *
	 * @var $lunar_client \Paylike\Paylike
	 */
	public $lunar_client;

	/**
	 * Store payment method
	 *
	 * @var bool
	 */
	public $store_payment_method;

	/**
	 * Use Beta SDK
	 *
	 * @var bool
	 */
	public $use_beta_sdk;


	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id = 'lunar';
		$this->method_title = __( 'Lunar', 'lunar-payments-for-woocommerce' );
		$this->method_description = __( 'Let your customers pay with debit and credit cards, including Visa, Mastercard, Maestro, Visa Electron.', 'lunar-payments-for-woocommerce' );
		$this->supports = array(
			'products',
			'refunds',
			'tokenization',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change', // Subs 1.n compatibility.
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
		);
		// Load the form fields.
		$this->init_form_fields();
		// Load the settings.
		$this->init_settings();
		// Get setting values.
		$this->title = $this->get_option( 'title' );
		$this->popup_title = $this->get_option( 'popup_title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled = $this->get_option( 'enabled' );
		$this->testmode = false;
		$this->capture = 'instant' === $this->get_option( 'capture', 'instant' );
		$this->checkout_mode = 'before_order' === $this->get_option( 'checkout_mode', 'before_order' );
		$this->store_payment_method = 'yes' === $this->get_option( 'store_payment_method' );
		$this->use_beta_sdk = 'yes' === $this->get_option( 'use_beta_sdk' );

		$this->secret_key = $this->testmode ? $this->get_option( 'secret_key' ) : $this->get_option( 'secret_key' );
		$this->secret_key = apply_filters( 'lunar_secret_key', $this->secret_key );
		$this->public_key = $this->testmode ? $this->get_option( 'public_key' ) : $this->get_option( 'public_key' );
		$this->public_key = apply_filters( 'lunar_public_key', $this->public_key );

		$this->logging = 'yes' === $this->get_option( 'logging' );
		$this->card_types = $this->get_option( 'card_types' );
		$this->order_button_text = __( 'Continue to payment', 'lunar-payments-for-woocommerce' );
		if ( $this->testmode ) {
			/* translators: %s is replaced with the documentation link */
			$this->description .= PHP_EOL . sprintf( __( 'TEST MODE ENABLED. In test mode, you can use the card number 4100 0000 0000 0000 with any CVC and a valid expiration date. "<a href="%s">See Documentation</a>".', 'lunar-payments-for-woocommerce' ), 'https://github.com/lunar/sdk' );
			$this->description = trim( $this->description );
		}
		if ( '' !== $this->secret_key ) {
			$this->lunar_client = new Paylike\Paylike( $this->secret_key );
		}
		// Hooks.
		if ( 'before_order' == $this->checkout_mode ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
			$this->has_fields = true;
		}
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'return_handler' ) );
//		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options',
		) );
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = include( 'settings-lunar.php' );
	}

	/**
	 * @param $key
	 * @param $value
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function validate_capture_field( $key, $value ) {
		if ( $value == 'delayed' ) {
			return $value;
		}
		// value is instant so we need to check if the user is allowed to capture
		$can_capture = $this->can_user_capture();
		if ( is_wp_error( $can_capture ) ) {
			$error = __( 'The Lunar account used is not allowed to capture. Instant mode is not available.', 'lunar-payments-for-woocommerce' );
			WC_Admin_Settings::add_error( $error );
			throw new Exception( $error );
		}

		return $value;
	}

	/**
	 * Validate the secret test key.
	 *
	 * @param string $key the name of the attribute.
	 * @param string $value the value of the input.
	 *
	 * @return string
	 * @throws Exception Thrown when there is a problem so that the value is not saved.
	 */
	public function validate_test_secret_key_field( $key, $value ) {

		if ( ! $value ) {
			return $value;
		}
		$lunar_client = new \Paylike\Paylike( $value );
		try {
			$identity = $lunar_client->apps()->fetch();
		} catch ( \Paylike\Exception\ApiException $exception ) {
			$error = __( "The app key doesn't seem to be valid", 'lunar-payments-for-woocommerce' );
			$error = WC_Lunar::handle_exceptions( null, $exception, $error );
			WC_Admin_Settings::add_error( $error );
			throw new Exception( $error );
		}
		try {
			$merchants = $lunar_client->merchants()->find( $identity['id'] );
			if ( $merchants ) {
				foreach ( $merchants as $merchant ) {
					if ( $merchant['test'] ) {
						$this->validation_test_public_keys[] = $merchant['key'];
					}
				}
			}
		} catch ( \Paylike\Exception\ApiException $exception ) {
			// we handle in the following statement
		}
		if ( empty( $this->validation_test_public_keys ) ) {
			$error = __( 'The test app key is not valid.', 'lunar-payments-for-woocommerce' );
			WC_Admin_Settings::add_error( $error );
			throw new Exception( $error );
		}

		return $value;
	}

	/**
	 * Validate the test public key.
	 *
	 * @param string $key the name of the attribute.
	 * @param string $value the value of the input.
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function validate_test_public_key_field( $key, $value ) {

		if ( ! $value ) {
			return $value;
		}
		if ( empty( $this->validation_test_public_keys ) ) {
			return $value;
		}
		if ( ! in_array( $value, $this->validation_test_public_keys ) ) {
			$error = __( 'The test public key doesn\'t seem to be valid', 'lunar-payments-for-woocommerce' );
			WC_Admin_Settings::add_error( $error );
			throw new Exception( $error );
		}

		return $value;
	}

	/**
	 * Validate the secret live key.
	 *
	 * @param string $key the name of the attribute.
	 * @param string $value the value of the input.
	 *
	 * @return string
	 * @throws Exception
	 */
	public function validate_secret_key_field( $key, $value ) {

		if ( ! $value ) {
			return $value;
		}
		$api_exception = null;
		$lunar_client = new \Paylike\Paylike( $value );
		try {
			$identity = $lunar_client->apps()->fetch();
		} catch ( \Paylike\Exception\ApiException $exception ) {
			$error = __( "The live app key doesn't seem to be valid", 'lunar-payments-for-woocommerce' );
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
			$error = __( 'The live app key is not valid.', 'lunar-payments-for-woocommerce' );
			if ( $api_exception ) {
				$error = WC_Lunar::handle_exceptions( null, $api_exception, $error );
			}
			WC_Admin_Settings::add_error( $error );
			throw new Exception( $error );
		}

		return $value;
	}

	/**
	 * Validate the test public key.
	 *
	 * @param string $key the name of the attribute.
	 * @param string $value the value of the input.
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function validate_public_key_field( $key, $value ) {

		if ( $value ) {
			if ( ! empty( $this->validation_live_public_keys ) ) {
				if ( ! in_array( $value, $this->validation_live_public_keys ) ) {
					$error = __( 'The live public key doesn\'t seem to be valid', 'lunar-payments-for-woocommerce' );
					WC_Admin_Settings::add_error( $error );
					throw new Exception( $error );
				}
			}
		}

		return $value;
	}

	/**
	 * Get_icon function.
	 *
	 * @access public
	 * @return string
	 */
	public function get_icon() {
		$icon = '';
		$url = null;
		if ( is_array( $this->card_types ) ) {
			foreach ( $this->card_types as $card_type ) {
				$url = $this->get_active_card_logo_url( $card_type );
				if ( $url ) {
					$icon .= '<img width="45" src="' . esc_url( $url ) . '" alt="' . esc_attr( strtolower( $card_type ) ) . '" />';
				}
			}
		} else {
			$icon .= '<img  src="' . esc_url( plugins_url( '../assets/images/lunar.png', __FILE__ ) ) . '" alt="Lunar Gateway" />';
		}

		return apply_filters( 'woocommerce_lunar_icon', $icon, $this->id );
	}

	/**
	 * Get logo url.
	 *
	 * @param string $type The name of the logo.
	 *
	 * @return string
	 */
	public function get_active_card_logo_url( $type ) {
		$image_type = strtolower( $type );

		$url = WC_HTTPS::force_https_url( plugins_url( '../assets/images/' . $image_type . '.svg', __FILE__ ) );

		return apply_filters( 'woocommerce_lunar_card_icon', $url, $type );
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
			WC()->cart->empty_cart();

			// Return thank you page redirect.
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true ),
			);
		} else {

			if ( isset( $_POST['wc-lunar-payment-token'] ) && 'new' !== sanitize_text_field($_POST['wc-lunar-payment-token']) ) {
				$order = $this->process_token_payment( $order );
			} else {
				$order = $this->process_default_payment( $order );
			}


			// Remove cart.
			WC()->cart->empty_cart();

			// Return thank you page redirect.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);

		}
	}



	/**
	 * Process payment with saved payment token
	 *
	 * @param WC_Order $order
	 */
	public function process_token_payment( $order ) {
		$token_id = wc_clean( $_POST['wc-lunar-payment-token'] );
		$token = WC_Payment_Tokens::get( $token_id );
		$transaction_id = $this->create_new_transaction( $token->get_token_id(), $order, $order->get_total( 'edit' ), $token->get_token_source() );
		$amount = $this->get_order_amount( $order );
		if ( $amount != 0 ) {
			$this->handle_payment( $transaction_id, $order );
		} elseif ( $transaction_id ) {
			$this->save_transaction_id( [ 'id' => $transaction_id ], $order );
			$order->payment_complete();
		}

		return $order;
	}

	/**
	 * Creates a new transaction based on a previous one
	 * used to simulate recurring payments
	 * see @https://github.com/lunar/api-docs#recurring-payments
	 *
	 * @param int      $entity_id The reference id.
	 * @param WC_Order $order The order that is used for billing details and amount.
	 * @param int      $amount The amount for which the transaction is created.
	 * @param string   $type The type for which the transaction needs to be created.
	 *
	 * @return int|mixed|null
	 */
	public function create_new_transaction( $entity_id, $order, $amount, $type = 'transaction' ) {
		$merchant_id = $this->get_global_merchant_id();
		if ( is_wp_error( $merchant_id ) ) {
			return $merchant_id;
		}
		// create a new transaction by card or transaction.
		$data = array(
			'amount'   => convert_float_to_iso_lunar_amount( $amount, dk_get_order_currency( $order ) ),
			'currency' => dk_get_order_currency( $order ),
			'custom'   => array(
				'email' => $order->get_billing_email(),
			),
		);
		if ( 'card' === $type ) {
			$data['cardId'] = $entity_id;
		} else {
			$data['transactionId'] = $entity_id;
		}
		WC_Lunar::log( "Info: Starting to create a transaction {$data['amount']} in {$data['currency']} for {$merchant_id}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
		try {
			// when reusing a token for a trial subscription
			if ( $data['amount'] == 0 ) {
				return $data['transactionId'];
			}
			$new_transaction = $this->lunar_client->transactions()->create( $merchant_id, $data );
		} catch ( \Paylike\Exception\ApiException $exception ) {
			WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Creating the transaction failed!' );

			return new WP_Error( 'lunar_error', __( 'There was a problem creating the transaction!.', 'lunar-payments-for-woocommerce' ) );
		}

		return $new_transaction;
	}



	/**
	 * Gets errors from a failed api request
	 *
	 * @param array $result The result returned by the api wrapper.
	 *
	 * @return string
	 */
	public static function get_response_error( $result ) {
		$error = array();
		// if this is just one error
		if ( isset( $result['text'] ) ) {
			return $result['text'];
		}

		if(isset($result['code']) && isset($result['error'])){
			return $result['code'].'-'.$result['error'];
		}

		// otherwise this is a multi field error
		if ( $result ) {
			foreach ( $result as $field_error ) {
				$error[] = $field_error['field'] . ':' . $field_error['message'];
			}
		}
		$error_message = implode( ' ', $error );

		return $error_message;
	}



	/**
	 * Saves the card id
	 * used for trials, and changing payment option
	 *
	 * @param int      $card_id The card reference.
	 * @param WC_Order $order The order object related to the transaction.
	 */
	protected function save_card_id( $card_id, $order ) {
		$order->update_meta_data( '_lunar_card_id', $card_id );
		$order->save_meta_data();
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
			/* This may be in ajax, so we need to check if the total has changed */
			if ( isset( $_POST['post_data'] ) ) {
				$post_data = array();
				parse_str( sanitize_text_field($_POST['post_data']), $post_data );
				if ( isset( $post_data['lunar_token'] ) ) {
					$transaction_id = $post_data['lunar_token'];
					try {
						$transaction = $this->lunar_client->transactions()->fetch( $transaction_id );
					} catch ( \Paylike\Exception\ApiException $exception ) {
						// we are handling this later
					}
					$amount = WC()->cart->total;
					$currency = get_woocommerce_currency();

					if ( ! ( $transaction && $transaction['successful'] &&
						$currency == $transaction['currency'] &&
						convert_float_to_iso_lunar_amount( $amount, $currency ) == $transaction['amount']
					) ) {
						$data = array(
							'amount' => $transaction['amount'],
						);
						WC_Lunar::log( 'Voiding the transaction as it was not succesfull or it had different amount.' . json_encode( $result ) . '--' . $currency . '--' . $amount . '--' . convert_float_to_iso_lunar_amount( $amount, $currency ) . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
						try {
							$transaction = $this->lunar_client->transactions()->void( $transaction_id );
						} catch ( \Paylike\Exception\ApiException $exception ) {
							WC_Lunar::handle_exceptions( null, $exception, 'Voiding the orphan transaction failed!' );
						}
					} else {
						// all good everything is still valid.
						$token = '<input type="hidden" class="lunar_token" name="lunar_token" value="' . esc_attr($transaction_id) . '">';
					}
				}
			}

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

			if ( is_add_payment_method_page() ) {
				$amount = 0;
			}

			echo '<div
			id="lunar-payment-data"' . '"
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
			data-title="' . esc_attr( $this->popup_title ) . '"
			data-currency="' . esc_attr( $currency ) . '"
			">';
			echo $token; // WPCS: XSS ok.
			echo '</div>';
		}

		if ( $this->description ) {
			echo wpautop( wp_kses_post( apply_filters( 'wc_lunar_description', $this->description ) ) );
		}

		if ( $this->store_payment_method && ! is_add_payment_method_page() ) {
			$this->saved_payment_methods();
		}

		if ( apply_filters( 'wc_lunar_display_save_payment_method_checkbox', $this->store_payment_method ) && ! is_add_payment_method_page() && ! isset( $_GET['change_payment_method'] ) ) {
			$this->save_payment_method_checkbox();
		}
	}

	/**
	 * Displays the save to account checkbox.
	 *
	 */
	public function save_payment_method_checkbox() {
		printf(
			'<p class="form-row woocommerce-SavedPaymentMethods-saveNew">
				<input id="wc-%1$s-new-payment-method" name="wc-%1$s-new-payment-method" type="checkbox" value="true" style="width:auto;" />
				<label for="wc-%1$s-new-payment-method" style="display:inline;">%2$s</label>
			</p>',
			esc_attr( $this->id ),
			esc_html( apply_filters( 'wc_lunar_save_to_account_text', __( 'Save payment information to my account for future purchases.', 'lunar-payments-for-woocommerce' ) ) )
		);
	}


	/**
	 * Check if SSL is enabled and notify the user
	 */
	public function admin_notices() {
		if ( 'no' === $this->enabled ) {
			return;
		}
		// Show message if enabled and FORCE SSL is disabled and WordpressHTTPS plugin is not detected.
		if ( ( function_exists( 'wc_site_is_https' ) && ! wc_site_is_https() ) && ( 'no' === get_option( 'woocommerce_force_ssl_checkout' ) && ! class_exists( 'WordPressHTTPS' ) ) ) {
			/* translators: %1$s is replaced with the admin url */
			echo '<div class="error lunar-ssl-message"><p>' . sprintf( __( 'Lunar: <a href="%s">Force SSL</a> is disabled; your checkout page may not be secure! Unless you have a valid SSL certificate and force the checkout pages to be secure, only test mode will be allowed.', 'lunar-payments-for-woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) . '</p></div>';
		}
	}

	/**
	 * Enque the payment scripts.
	 */
	public function payment_scripts() {
		global $wp_version;
		if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_add_payment_method_page() && ! is_order_received_page() ) {
			return;
		}

		// If Lunar is not enabled bail.
		if ( 'no' === $this->enabled ) {
			return;
		}

		$version = get_option( 'lunar_sdk_version', WC_LUNAR_CURRENT_SDK );
		$beta_sdk_version = get_option( 'lunar_beta_version', WC_LUNAR_BETA_SDK );
		if ( $this->use_beta_sdk ) {
			$version = $beta_sdk_version;
		}
//		wp_enqueue_script( 'lunar', esc_url('https://sdk.paylike.io/' . $version . '.js'), '', $version . '.0', true );
		wp_enqueue_script( 'lunar', esc_url('https://sdk.paylike.io/a.js'), '', $version . '.0', true );
		wp_enqueue_script( 'woocommerce_lunar_utils', plugins_url( 'assets/js/lunar_checkout_utils.js', WC_LUNAR_MAIN_FILE ), '', '', true );
		wp_enqueue_script( 'woocommerce_lunar', plugins_url( 'assets/js/lunar_checkout.js', WC_LUNAR_MAIN_FILE ), array( 'lunar','woocommerce_lunar_utils' ), WC_LUNAR_VERSION, true );

		$lunar_params = array(
			'key'               => $this->public_key,
			'customer_IP'       => dk_get_client_ip(),
			'products'          => $this->get_products_for_custom_parameter(),
			'platform_version'  => $wp_version,
			'ecommerce_version' => WC()->version,
			'plan_arguments'    => LunarSubscriptionHelper::append_plan_argument( [], false ),
			'version'           => WC_LUNAR_VERSION,
			'ajax_url'          => admin_url( 'admin-ajax.php' ),
		);
		wp_localize_script( 'woocommerce_lunar', 'wc_lunar_params', apply_filters( 'wc_lunar_params', $lunar_params ) );
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
	 * Display the pay button on the receipt page.
	 *
	 * @param int $order_id The order reference id.
	 */
	public function receipt_page( $order_id ) {
		global $wp_version;
		$order = wc_get_order( $order_id );
		$currency = dk_get_order_currency($order);
		$decimals = wc_get_price_decimals();
		$amount = convert_wocoomerce_float_to_lunar_amount( $order->get_total() );
		$products = array();
		$items = $order->get_items();
		$pf = new WC_Product_Factory();

		$i = 0;
		foreach ( $items as $item => $values ) {
			$_product = $pf->get_product( $values['product_id'] );
			$product = array(
				'ID'       => $_product->get_id(),
				'name'     => $_product->get_title(),
				'quantity' => isset( $values['quantity'] ) ? $values['quantity'] : $values['qty'],
			);
			$products[] = $product;
			$i ++;
			// custom popup allows at most 100 keys in all. Custom has 15 keys and each product has 3+1. 85/3 is 20
			if ( $i >= 20 ) {
				break;
			}
		}
		if ( $theme_template = locate_template( 'lunar/receipt.php' ) ) {
			require $theme_template;
		} else {
			$plugin_template = WC_LUNAR_PLUGIN_TEMPLATE_DIR . '/receipt.php';
			if ( file_exists( $plugin_template ) ) {
				require $plugin_template;
			}
		}
		$version = get_option( 'lunar_sdk_version', WC_LUNAR_CURRENT_SDK );
		$beta_sdk_version = get_option( 'lunar_beta_version', WC_LUNAR_BETA_SDK );
		if ( $this->use_beta_sdk ) {
			$version = $beta_sdk_version;
		}

		$plan_arguments = LunarSubscriptionHelper::append_plan_argument( [], false, $order )

		?>
		<script data-no-optimize="1" src="<?php echo esc_url('https://sdk.paylike.io/a.js'); ?>"></script>
		<script>
		var lunar = Paylike( { key: '<?php echo esc_js($this->public_key);?>' } );
		var $button = document.getElementById( "lunar-payment-button" );
		$button.addEventListener( 'click', startPaymentPopup );

		var args = {
			title: '<?php echo addslashes( esc_attr( $this->popup_title ) ); ?>',
			test: false,
			<?php if($amount != 0) { ?>
			amount: {
				currency: '<?php echo esc_js($currency); ?>',
				exponent: <?php echo esc_js($decimals); ?>,
				value: <?php echo esc_js($amount); ?>,
			},
			<?php } ?>
			locale: '<?php echo esc_js(dk_get_locale()); ?>',
			custom: {
				orderId: '<?php echo esc_js($order->get_order_number()); ?>',
				products: [<?php echo json_encode( $products ); ?>],
				customer: {
					name: '<?php echo esc_js(addslashes( dk_get_order_data( $order, 'get_billing_first_name' ) ) . ' ' . addslashes( dk_get_order_data( $order, 'get_billing_last_name' ) )); ?>',
					email: '<?php echo esc_js(addslashes( dk_get_order_data( $order, 'get_billing_email' ) )); ?>',
					phoneNo: '<?php echo esc_js(addslashes( dk_get_order_data( $order, 'get_billing_phone' ) )); ?>',
					address: '<?php echo esc_js(addslashes( dk_get_order_data( $order, 'get_billing_address_1' ) . ' ' . dk_get_order_data( $order, 'get_billing_address_2' ) . ' ' . dk_get_order_data( $order, 'get_billing_city' ) . dk_get_order_data( $order, 'get_billing_state' ) . ' ' . dk_get_order_data( $order, 'get_billing_postcode' ) )); ?>',
					IP: '<?php echo esc_js(dk_get_client_ip()); ?>'
				},
				platform: {
					name: 'WordPress',
					version: '<?php echo esc_js($wp_version); ?>'
				},
				ecommerce: {
					name: 'WooCommerce',
					version: '<?php echo esc_js(WC()->version); ?>'
				},
				lunarPluginVersion: '<?php echo esc_js(WC_LUNAR_VERSION); ?>'
			}
		}

		<?php
		if ( $plan_arguments ) {
		echo 'var plan_arguments=' . json_encode( $plan_arguments ) . ';' . PHP_EOL;
		?>
		if ( plan_arguments ) {
			for ( var attrname in plan_arguments[ 0 ] ) {
				args[ attrname ] = plan_arguments[ 0 ][ attrname ];
			}
			if(args.plan && args.plan.repeat && args.plan.repeat.first){
				args.plan.repeat.first = new Date(args.plan.repeat.first);
			}
			if(args.plan) {
				args.plan = [ args.plan ];
			}
		}
		<?php
		}
		?>

		function startPaymentPopup( e ) {
			e.preventDefault();
			pay();
		}

		function pay() {
			lunar.pay( args,
				function( err, res ) {
					if ( err )
						return console.warn( err );
					var $form = jQuery( "#complete_order" );
					if ( res.transaction ) {
						var trxid = res.transaction.id;
						$form.find( 'input.lunar_token' ).remove();
						$form.append( '<input type="hidden" class="lunar_token" name="lunar_token" value="' + trxid + '"/>' );
					} else {
						var cardid = res.card.id;
						$form.find( 'input.lunar_card_id' ).remove();
						$form.append( '<input type="hidden" class="lunar_card_id" name="lunar_card_id" value="' + cardid + '"/>' );
					}
					jQuery( '#lunar-payment-button' ).attr( 'disabled', 'disabled' );
					document.getElementById( "complete_order" ).submit();
				}
			);
		}

		// start automatically on page load
		pay();
		</script>
		<form id="complete_order" method="POST" action="<?php echo esc_url(WC()->api_request_url( get_class( $this ) )); ?>">
			<input type="hidden" name="reference" value="<?php echo esc_attr($order_id); ?>"/>
			<input type="hidden" name="amount" value="<?php echo esc_attr($this->get_order_total()); ?>"/>
			<input type="hidden" name="signature"
					value="<?php echo esc_attr($this->get_signature( $order_id )); ?>"/>
		</form>
		<?php
	}





	/**
	 * Sends the failed order email to admin
	 *
	 * @param int $order_id
	 *
	 * @since 3.1.0
	 *
	 * @version 3.1.0
	 */
	public function send_failed_order_email( $order_id ) {
		$emails = WC()->mailer()->get_emails();
		if ( ! empty( $emails ) && ! empty( $order_id ) ) {
			$emails['WC_Email_Failed_Order']->trigger( $order_id );
		}
	}


	/**
	 * @param $order
	 *
	 * @return mixed
	 */
	protected function get_transaction_id( $order ) {
		$transaction_id = $order->get_meta( '_lunar_transaction_id', true );
		if ( $transaction_id ) {
			return $transaction_id;
		}

		// we continue our search on the subscriptions
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( get_woo_id( $order ) ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( get_woo_id( $order ) );
		} elseif ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( get_woo_id( $order ) ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( get_woo_id( $order ) );
		} else {
			$subscriptions = array();
		}
		foreach ( $subscriptions as $subscription ) {
			$transaction_id = $subscription->get_meta( '_lunar_transaction_id', true );
			if ( $transaction_id ) {
				return $transaction_id;
			}
		}

		return false;
	}

	/**
	 * @param $order
	 *
	 * @return mixed
	 */
	protected function get_card_id( $order ) {
		$card_id = $order->get_meta( '_lunar_card_id', true );
		if ( $card_id ) {
			return $card_id;
		}

		// we continue our search on the subscriptions
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( get_woo_id( $order ) ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( get_woo_id( $order ) );
		} elseif ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( get_woo_id( $order ) ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( get_woo_id( $order ) );
		} else {
			$subscriptions = array();
		}
		foreach ( $subscriptions as $subscription ) {
			$card_id = $subscription->get_meta( '_lunar_card_id', true );
			if ( $card_id ) {
				return $card_id;
			}
		}

		return false;
	}

	/**
	 * @return WP_Error
	 */
	protected function can_user_capture() {

		$merchant_id = $this->get_global_merchant_id();
		if ( is_wp_error( $merchant_id ) ) {
			return $merchant_id;
		}
		WC_Lunar::log( 'Info: Attempting to fetch the merchant data' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
		try {
			$merchant = $this->lunar_client->merchants()->fetch( $merchant_id );
		} catch ( \Paylike\Exception\ApiException $exception ) {
			$error = __( "The merchant couldn't be found", 'lunar-payments-for-woocommerce' );

			return new WP_Error( 'lunar_error', $error );
		}

		if ( true == $merchant['claim']['canCapture'] ) {
			return true;
		}

		$error = __( "The merchant is not allowed to capture", 'lunar-payments-for-woocommerce' );

		return new WP_Error( 'lunar_error', $error );
	}

	/**
	 * Gets global merchant id.
	 *
	 * @param int    $entity_id Transaction or card id reference.
	 * @param string $type The type of the transaction.
	 *
	 * @return bool|int|mixed|null|WP_Error
	 */
	public function get_global_merchant_id() {
		WC_Lunar::log( 'Info: Attempting to fetch the global merchant id ' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
		if ( ! $this->lunar_client ) {
			if ( '' !== $this->secret_key ) {
				$this->lunar_client = new Paylike\Paylike( $this->secret_key );
			} else {
				$error = __( "The app key doesn't seem to be valid", 'lunar-payments-for-woocommerce' );

				return new WP_Error( 'lunar_error', $error );
			}
		}
		try {
			$identity = $this->lunar_client->apps()->fetch();
		} catch ( \Paylike\Exception\ApiException $exception ) {
			$error = __( "The app key doesn't seem to be valid", 'lunar-payments-for-woocommerce' );

			return new WP_Error( 'lunar_error', $error );
		}
		try {
			$merchants = $this->lunar_client->merchants()->find( $identity['id'] );
			if ( $merchants ) {
				foreach ( $merchants as $merchant ) {
					if ( $this->testmode == 'yes' && $merchant['test'] && $merchant['key'] == $this->public_key ) {
						return $merchant['id'];
					}
					if ( ! $merchant['test'] && $this->testmode != 'yes' && $merchant['key'] == $this->public_key ) {
						return $merchant['id'];
					}
				}
			}
		} catch ( \Paylike\Exception\ApiException $exception ) {
			$error = __( 'No valid merchant id was found', 'lunar-payments-for-woocommerce' );

			return new WP_Error( 'lunar_error', $error );
		}


	}

	/**
	 * @return WP_Error
	 */
	protected function can_user_save_card() {
		$merchant_id = $this->get_global_merchant_id();
		if ( is_wp_error( $merchant_id ) ) {
			return $merchant_id;
		}
		WC_Lunar::log( 'Info: Attempting to fetch the merchant data' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
		try {
			$merchant = $this->lunar_client->merchants()->fetch( $merchant_id );
		} catch ( \Paylike\Exception\ApiException $exception ) {
			$error = __( "The merchant couldn't be found", 'lunar-payments-for-woocommerce' );

			return new WP_Error( 'lunar_error', $error );
		}

		if ( true == $merchant['claim']['canSaveCard'] ) {
			return true;
		}

		$error = __( "The merchant is not allowed to save cards", 'lunar-payments-for-woocommerce' );

		return new WP_Error( 'lunar_error', $error );
	}

	/**
	 * @param $key
	 * @param $value
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function validate_store_payment_method_field( $key, $value ) {
		if ( ! $value ) {
			return "no";
		}

		if ( wc_clean( $_POST['woocommerce_lunar_checkout_mode'] ) === 'after_order' ) {
			$error = __( "Tokenization is only compatible with the before order payment mode.", 'lunar-payments-for-woocommerce' );

			WC_Admin_Settings::add_error( $error );
			throw new Exception( $error );
		}

		// value is yes so we need to check if the user is allowed to save cards
		$can_save_card = $this->can_user_save_card();
		if ( is_wp_error( $can_save_card ) ) {
			$error = __( 'The Lunar account used is not allowed to save cards. Storing the payment method is not available.', 'lunar-payments-for-woocommerce' );
			WC_Admin_Settings::add_error( $error );
			throw new Exception( $error );
		}

		return "yes";
	}

	/**
	 * Add payment method via account screen
	 */
	public function add_payment_method() {

		$token = new WC_Payment_Token_Lunar();
		$token->set_gateway_id( $this->id );
		$token->set_user_id( get_current_user_id() );
		$token->set_token( 'transaction-' . sanitize_text_field($_POST['lunar_token']) );
		$transaction = $this->lunar_client->transactions()->fetch( sanitize_text_field($_POST['lunar_token']) );
		$token->set_last4( $transaction['card']['last4'] );
		$token->set_brand( ucfirst( $transaction['card']['scheme'] ) );
		$saved = $token->save();

		if ( ! $saved ) {
			wc_add_notice( __( 'There was a problem adding the payment method.', 'lunar-payments-for-woocommerce' ), 'error' );

			return;
		}

		return array(
			'result'   => 'success',
			'redirect' => wc_get_endpoint_url( 'payment-methods' ),
		);
	}


}
