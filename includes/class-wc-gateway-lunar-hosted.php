<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Lunar class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Lunar_Hosted extends WC_Payment_Gateway {

	use LunarHostedGatewayTrait;

	/**
	 * Should we capture Credit cards
	 *
	 * @var bool
	 */
	public $capture;

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
	 * Holds an instance of the Lunar client
	 *
	 * @var $lunar_client \Lunar\Lunar
	 */
	public $lunar_client;

	/**
	 * Store payment method
	 *
	 * @var bool
	 */
	public $store_payment_method;


	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id = 'lunar_hosted';
		$this->method_title = __( 'Lunar Cards', 'lunar-payments-for-woocommerce' );
		$this->method_description = __( 'Let your customers pay with debit and credit cards, including Visa, Mastercard, Maestro, Visa Electron.', 'lunar-payments-for-woocommerce' );
		$this->supports = array(
			'products',
			'refunds',
		);
		// Load the form fields.
		$this->init_form_fields();
		// Load the settings.
		$this->init_settings();
		// Get setting values.
		$this->title = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled = $this->get_option( 'enabled' );
		$this->store_title = $this->get_option( 'store_title' );

		$this->testmode = false;
		if ( isset( $_COOKIE['lunar_testmode'] ) ) {
			$this->testmode = true;
		}

		$this->capture = 'instant' === $this->get_option( 'capture', 'instant' );

		$this->secret_key = $this->get_option( 'secret_key' );
		$this->secret_key = apply_filters( 'lunar_hosted_secret_key', $this->secret_key );
		$this->public_key = $this->get_option( 'public_key' );
		$this->public_key = apply_filters( 'lunar_hosted_public_key', $this->public_key );
		$this->mobilepay_configuration_id = $this->get_option( 'mobilepay_configuration_id' );
		$this->mobilepay_logo = $this->get_option( 'mobilepay_logo' );
		$this->logo = $this->get_option( 'logo' );

		$this->logging = 'yes' === $this->get_option( 'logging' );

		$this->card_types = $this->get_option( 'card_types' );

		if ( '' !== $this->secret_key ) {
			$this->lunar_client = new Lunar\Lunar( $this->secret_key, null, $this->testmode );
		}
		// Hooks.
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'return_handler' ) );
		// URL =  echo esc_url(WC()->api_request_url( get_class( $this ) ));
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options',
		) );
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = include( 'settings-lunar-hosted.php' );
	}

	private function get_args( $currency, $amount, $order, array $products, $wp_version ): array {
		$args = array(
			'integration' => [
				'key'  => $this->public_key,
				'name' => $this->store_title,
				'logo' => $this->logo,
			],
			'amount'      => array(
				'currency' => $currency,
				'decimal'  => $amount
			),
			'custom'      => array(
				'orderId'            => $order->get_order_number(),
				'products'           => $products,
				'customer'           =>
					array(
						'name'    => dk_get_order_data( $order, 'get_billing_first_name' ) . ' ' . dk_get_order_data( $order, 'get_billing_last_name' ),
						'email'   => dk_get_order_data( $order, 'get_billing_email' ),
						'phoneNo' => dk_get_order_data( $order, 'get_billing_phone' ),
						'address' => dk_get_order_data( $order, 'get_billing_address_1' ) . ' ' . dk_get_order_data( $order, 'get_billing_address_2' ) . ' ' . dk_get_order_data( $order, 'get_billing_city' ) . dk_get_order_data( $order, 'get_billing_state' ) . ' ' . dk_get_order_data( $order, 'get_billing_postcode' ),
						'IP'      => dk_get_client_ip()
					),
				'platform'           => array(
					'name'    => 'WordPress',
					'version' => $wp_version
				),
				'ecommerce'          => array(
					'name'    => 'WooCommerce',
					'version' => WC()->version
				),
				'lunarPluginVersion' => WC_LUNAR_VERSION,
			),
			'redirectUrl' => $this->getRedirectUrl( $order->id ),
			'preferredPaymentMethod'=>'card'
		);

		return $args;
	}

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
			$icon .= '<img  src="' . esc_url( plugins_url( WC_LUNAR_PLUGIN_DIR.'/assets/images/lunar.png', __FILE__ ) ) . '" alt="Lunar Gateway" />';
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
	 * Check if this gateway is enabled
	 */
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
			$current_currency = get_woocommerce_currency();
			$supported = get_lunar_currency( $current_currency );
			if ( ! $supported ) {
				return false;
			}

			return true;
		}

		return false;
	}

}
