<?php
/*
 * Plugin Name: Lunar Online Payments for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/lunar-payments-for-woocommerce/
 * Description: Accept payments with Visa and MasterCard instantly. Lunar Payments is the modern full-stack payment platform combining banking and payments into one.
 * Author: Lunar
 * Author URI: https://lunar.app/
 * Version: 4.2.1
 * Text Domain: lunar-payments-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 3.0
 * WC tested up to: 8.6.1
 *
 * Copyright (c) 2016 Derikon Development
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.xdebu
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Required minimums and constants
 */
define( 'WC_LUNAR_VERSION', '4.2.1' );
define( 'WC_LUNAR_MIN_PHP_VER', '5.3.0' );
define( 'WC_LUNAR_MIN_WC_VER', '3.0.0' );
define( 'WC_LUNAR_CURRENT_SDK', '10' );
define( 'WC_LUNAR_BETA_SDK', '10' );
define( 'WC_LUNAR_MAIN_FILE', __FILE__ );
define( 'WC_LUNAR_PLUGIN_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );
define( 'WC_LUNAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_LUNAR_PLUGIN_TEMPLATE_DIR', plugin_dir_path( __FILE__ ) . '/templates' );
if ( ! class_exists( 'WC_Lunar' ) ) {
	class WC_Lunar {

		/**
		 * @var Singleton The reference the *Singleton* instance of this class
		 */
		private static $instance;

		/**
		 * @var Reference to logging class.
		 */
		private static $log;

		/**
		 * Returns the *Singleton* instance of this class.
		 *
		 * @return Singleton The *Singleton* instance.
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Flag to indicate whether or not we need to load code for / support subscriptions.
		 *
		 * @var bool
		 */
		private $subscription_support_enabled = false;

		/**
		 * Secret API Key.
		 * @var string
		 */
		private $secret_key = '';

		/**
		 * Capture mode, is this instant or delayed
		 * @var string
		 */
		private $capture_mode = 'instant';

		/**
		 * Notices (array)
		 * @var array
		 */
		public $notices = array();

		/**
		 * Protected constructor to prevent creating a new instance of the
		 * *Singleton* via the `new` operator from outside of this class.
		 */
		protected function __construct() {
			add_action( 'admin_init', array( $this, 'check_environment' ) );
			add_action( 'admin_notices', array( $this, 'admin_notices' ), 25 );
			add_action( 'plugins_loaded', array( $this, 'init' ) );
			// add cronjob to check orders every 20 minutes
			add_filter( 'cron_schedules', array( $this, 'check_payment_every_20_minutes' ) );

			add_action( 'wp',  array( $this, 'lunar_cron_job' ) );
			add_action( 'plugins_loaded',  array( $this, 'maybe_migrate' ) );

			add_action( 'lunar_check_unpaid_orders', array( $this, 'poll_for_order_payments' ) );

		}

		/**
		 * Holds an instance of the Lunar client
		 *
		 * @var $lunar_client \Paylike\Paylike
		 */
		public $lunar_client;

		/**
		 * Init the plugin after plugins_loaded so environment variables are set.
		 */
		public function init() {
			// Don't hook anything else in the plugin if we're in an incompatible environment.
			if ( self::get_environment_warning() ) {
				return;
			}
			include_once( 'vendor/autoload.php' );
			// Init the gateway itself.
			$this->init_gateways();
			$this->db_update();
			// make sure client is set
			$secret = $this->get_secret_key();
			add_action( 'wp_ajax_lunar_log_transaction_data', array( $this, 'log_transaction_data' ) );
			add_action( 'wp_ajax_nopriv_lunar_log_transaction_data', array( $this, 'log_transaction_data' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
			add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ) );
			add_action( 'woocommerce_order_status_on-hold_to_cancelled', array( $this, 'cancel_payment' ) );
			add_action( 'woocommerce_order_status_on-hold_to_refunded', array( $this, 'cancel_payment' ) );
			add_action( 'woocommerce_thankyou', array( $this, 'capture_virtual_downloadable_order' ) );


			add_action( 'wp_ajax_lunar_mobilepay_initiate_payment', array( $this, 'initiate_mobilepay_payment' ) );
			add_action( 'wp_ajax_nopriv_lunar_mobilepay_initiate_payment', array(
				$this,
				'initiate_mobilepay_payment'
			) );



		}

		function check_payment_every_20_minutes( $schedules ) {
			$schedules['every_20_minutes'] = array(
				'interval' => 60*20,
				'display'  => __( 'Every 20 minutes' ),
			);
			return $schedules;
		}

		function lunar_cron_job() {
			if ( ! wp_next_scheduled( 'lunar_check_unpaid_orders' ) ) {
				wp_schedule_event( time(), 'every_20_minutes', 'lunar_check_unpaid_orders' );
			}
		}

		function maybe_migrate(){
			if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
				return;
			}
			MigrateToHostedCheckout::start([]);
		}

		function poll_for_order_payments() {
			$range = 25*120; // 1 day in minutes // order placed 1 day ago are too old, so we don't check them. We check so far because this is not a true cron, so it depends on a user visiting the site.
			$pending_payment_orders = $this->get_payment_pending_orders_in_interval( strtotime( '-' . absint( $range ) . ' MINUTES', current_time( 'timestamp' ) ), current_time( 'timestamp' ) );
			if ( $pending_payment_orders ) {
				foreach ( $pending_payment_orders as $order_id ) {
					$order = wc_get_order( $order_id );
					$payment_method = $order->get_payment_method();
					if(!in_array($payment_method, ['lunar_mobilepay','lunar_hosted','lunar_hosted_mobilepay']) ){
						return false;
					}
					$captured = $order->get_meta('_lunar_transaction_captured', true );
					if ( 'yes' == $captured ) {
						return false;
					}
					$order->add_order_note(
						__( 'Polling payment.', 'lunar-payments-for-woocommerce' ) . PHP_EOL
					);

					switch ($payment_method){
						case 'lunar_mobilepay':
							$gateway = new WC_Gateway_Lunar_Mobilepay();
							break;
						case 'lunar_hosted':
							$gateway = new WC_Gateway_Lunar_Hosted();
							break;
						case 'lunar_hosted_mobilepay':
							$gateway = new WC_Gateway_Lunar_Hosted_Mobilepay();
							break;
					}

					$gateway->check_payment( $order );

				}
			}
		}

		function get_payment_pending_orders_in_interval( $date_one, $date_two ) {

			$orders = wc_get_orders( array(
				'return' => 'ids',
				'status'=> array ('wc-pending'),
				'date_created' => date( 'Y-m-d', absint( $date_one )).'...'.
					date( 'Y-m-d', absint( $date_two ) ),
			) );

			return $orders;

		}

		public function initiate_mobilepay_payment( $args ) {

			$args = $_POST['args'];

			$mobilePayChallengeHandler = new WC_Lunar_MobilePay_Challenges( $args );

			return $mobilePayChallengeHandler->handle( $this->get_mobilepay_checkout_mode() );

		}

		/**
		 * Set secret API Key.
		 *
		 * @param string $secret_key
		 */
		public function set_secret_key( $secret_key ) {
			$this->secret_key = $secret_key;
			$this->secret_key = apply_filters( 'lunar_secret_key', $this->secret_key );
			if ( ! class_exists( 'Paylike\Paylike' ) ) {
				include_once( 'vendor/autoload.php' );
			}
			if ( '' != $this->secret_key ) {
				$this->lunar_client = new Paylike\Paylike( $this->secret_key );
			}
		}

		/**
		 * Set secret API Key.
		 *
		 * @param string $secret_key
		 */
		public function set_mobilepay_secret_key( $secret_key ) {
			$this->secret_key = $secret_key;
			$this->secret_key = apply_filters( 'lunar_secret_key', $this->secret_key );
			if ( ! class_exists( 'Paylike\Paylike' ) ) {
				include_once( 'vendor/autoload.php' );
			}
			if ( '' != $this->secret_key ) {
				$this->lunar_client = new Paylike\Paylike( $this->secret_key );
			}
		}

		/**
		 * Allow this class and other classes to add slug keyed notices (to avoid duplication)
		 */
		public function add_admin_notice( $slug, $class, $message ) {
			$this->notices[ $slug ] = array(
				'class'   => $class,
				'message' => $message,
			);
		}

		/**
		 * The backup sanity check, in case the plugin is activated in a weird way,
		 * or the environment changes after activation.
		 */
		public function check_environment() {
			$environment_warning = self::get_environment_warning();
			if ( $environment_warning && is_plugin_active( plugin_basename( __FILE__ ) ) ) {
				$this->add_admin_notice( 'lunar_bad_environment', 'error', $environment_warning );
			}
			// Check if secret key present. Otherwise prompt, via notice, to go to
			// setting.
			if ( ! class_exists( 'Paylike\Paylike' ) ) {
				include_once( plugin_basename( 'vendor/autoload.php' ) );
			}


		}

		/**
		 * @return string
		 * Get the stored secret key depending on the type of payment sent.
		 */
		public function get_secret_key($set = true) {
			if ( ! $this->secret_key ) {
				$options = get_option( 'woocommerce_lunar_settings' );
				if ( isset( $options['secret_key'] ) && $set ) {
					$this->set_secret_key( $options['secret_key'] );
				}
			}

			return $this->secret_key;
		}

		/**
		 * @return string
		 * Get the stored secret key depending on the type of payment sent.
		 */
		public function get_mobilepay_secret_key($set = true) {
			$options = get_option( 'woocommerce_lunar_mobilepay_settings' );
			if ( isset( $options['secret_key'] ) && $set ) {
				$this->set_mobilepay_secret_key( $options['secret_key'] );
			}

			return $this->secret_key;
		}

		public function get_mobilepay_logo() {
			$options = get_option( 'woocommerce_lunar_mobilepay_settings' );
			if ( isset( $options['logo'] ) ) {
				return $options['logo'];
			}

			return '';
		}

		public function get_mobilepay_checkout_mode() {
			$options = get_option( 'woocommerce_lunar_mobilepay_settings' );
			if ( isset( $options['checkout_mode'] ) ) {
				return $options['checkout_mode'] === 'before_order';
			}

			return false;
		}


		/**
		 * Check if the capture mode is instant or delayed
		 */
		public function get_capture_mode() {
			$options = get_option( 'woocommerce_lunar_settings' );
			if ( isset( $options['capture'] ) ) {
				$this->capture_mode = ( 'instant' === $options['capture'] ? $options['capture'] : 0 );
			} else {
				$this->capture_mode = 0;
			}

			return $this->capture_mode;
		}

		/**
		 * Checks the environment for compatibility problems.  Returns a string with the first incompatibility
		 * found or false if the environment has no problems.
		 */
		static function get_environment_warning() {
			if ( version_compare( phpversion(), WC_LUNAR_MIN_PHP_VER, '<' ) ) {
				/* translators: %1$s is replaced with the php version %2$s is replaced with the current php version */
				$message = __( 'WooCommerce Lunar - The minimum PHP version required for this plugin is %1$s. You are running %2$s.', 'lunar-payments-for-woocommerce' );

				return sprintf( $message, WC_LUNAR_MIN_PHP_VER, phpversion() );
			}
			if ( ! defined( 'WC_VERSION' ) ) {
				return __( 'WooCommerce Lunar requires WooCommerce to be activated to work.', 'lunar-payments-for-woocommerce' );
			}
			if ( version_compare( WC_VERSION, WC_LUNAR_MIN_WC_VER, '<' ) ) {
				/* translators: %1$s is replaced with the woocommerce version %2$s is replaced with the current woocommerce version */
				$message = __( 'WooCommerce Lunar - The minimum WooCommerce version required for this plugin is %1$s. You are running %2$s.', 'lunar-payments-for-woocommerce' );

				return sprintf( $message, WC_LUNAR_MIN_WC_VER, WC_VERSION );
			}
			if ( ! function_exists( 'curl_init' ) ) {
				return __( 'WooCommerce Lunar - cURL is not installed.', 'lunar-payments-for-woocommerce' );
			}

			return false;
		}

		/**
		 * Adds plugin action links
		 *
		 * @since 1.0.0
		 */
		public function plugin_action_links( $links ) {
			$setting_link = $this->get_setting_link();
			$plugin_links = array(
				'<a href="' . esc_url( $setting_link ) . '">' . __( 'Settings', 'lunar-payments-for-woocommerce' ) . '</a>',
			);

			return array_merge( $plugin_links, $links );
		}

		/**
		 * Get setting link.
		 *
		 * @return string Setting link
		 * @since 1.0.0
		 *
		 */
		public function get_setting_link() {
			if ( function_exists( 'WC' ) ) {
				$use_id_as_section = version_compare( WC()->version, '2.6', '>=' );
			} else {
				$use_id_as_section = false;
			}
			$section_slug = $use_id_as_section ? 'lunar_hosted' : strtolower( 'WC_Gateway_Lunar_Hosted' );

			return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $section_slug );
		}

		/**
		 * Display any notices we've collected thus far (e.g. for connection, disconnection)
		 */
		public function admin_notices() {
			foreach ( (array) $this->notices as $notice_key => $notice ) {
				echo "<div class='" . esc_attr( $notice['class'] ) . "'><p>";
				echo wp_kses( $notice['message'], array(
					'a' => array(
						'href' => array(),
					),
				) );
				echo '</p></div>';
			}
		}

		/**
		 * Initialize the gateway. Called very early - in the context of the plugins_loaded action
		 *
		 * @since 1.0.0
		 */
		public function init_gateways() {
			if ( class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' ) ) {
				$this->subscription_support_enabled = true;
			}
			if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
				return;
			}
			include_once( plugin_basename( 'includes/helpers.php' ) );
			include_once( plugin_basename( 'includes/legacy.php' ) );
			include_once( plugin_basename( 'includes/currencies.php' ) );

			include_once( plugin_basename( 'includes/traits/LunarGlobalTrait.php' ) );
			include_once( plugin_basename( 'includes/traits/LunarGatewayTrait.php' ) );
			include_once( plugin_basename( 'includes/traits/LunarHostedGatewayTrait.php' ) );

			include_once( plugin_basename( 'includes/class-subscription-plan.php' ) );
			include_once( plugin_basename( 'includes/class-migrate-to-hosted-checkout.php' ) );
			include_once( plugin_basename( 'includes/class-wc-lunar-payment-tokens.php' ) );
			include_once( plugin_basename( 'includes/class-wc-lunar-payment-token.php' ) );
			include_once( plugin_basename( 'includes/class-wc-gateway-lunar.php' ) );
			include_once( plugin_basename( 'includes/class-wc-gateway-lunar-mobilepay.php' ) );
			include_once( plugin_basename( 'includes/class-wc-gateway-lunar-hosted.php' ) );
			include_once( plugin_basename( 'includes/class-wc-gateway-lunar-hosted-mobilepay.php' ) );
			include_once( plugin_basename( 'includes/class-wc-lunar-mobilepay-challenges.php' ) );


			load_plugin_textdomain( 'lunar-payments-for-woocommerce', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
			if ( $this->subscription_support_enabled ) {
				require_once( plugin_basename( 'includes/class-wc-gateway-lunar-addons.php' ) );
			}
		}

		function capture_virtual_downloadable_order( $order_id ) {
			if ( ! $order_id ) {
				return;
			}

			// Get an instance of the WC_Product object
			$order = wc_get_order( $order_id );
			if ( $order->needs_processing() ) {
				return false;
			}

			return $this->capture_payment( $order_id );
		}

		/**
		 *  Perform database updates when changing structure
		 */
		public function db_update() {
			$current_db_version = get_option( 'lunar_db_version', 1 );
			$current_sdk_version = get_option( 'lunar_sdk_version', 0 );
			$beta_sdk_version = get_option( 'lunar_beta_version', 0 );

			$options = get_option( 'woocommerce_lunar_settings', [] );
			if ( 1 == $current_db_version ) {

				if ( 'yes' === $options['capture'] ) {
					$options['capture'] = 'instant';
				} else {
					$options['capture'] = 'delayed';
				}
				$current_db_version ++;
			}
			if ( 2 == $current_db_version ) {

				if ( 'yes' === $options['direct_checkout'] ) {
					$options['checkout_mode'] = 'before_order';
				} else {
					$options['checkout_mode'] = 'after_order';
				}
				$current_db_version ++;
			}


			if ( $current_sdk_version < WC_LUNAR_CURRENT_SDK ) {
				//reset beta checkbox
				$options['use_beta_sdk'] = 'no';

				update_option( 'lunar_sdk_version', WC_LUNAR_CURRENT_SDK );
				update_option( 'lunar_beta_version', WC_LUNAR_BETA_SDK );
			}

			update_option( 'woocommerce_lunar_settings', apply_filters( 'woocommerce_settings_api_sanitized_fields_lunar', $options ) );
			update_option( 'lunar_db_version', $current_db_version );
		}

		/**
		 * Add the gateways to WooCommerce
		 *
		 * @since 1.0.0
		 */
		public function add_gateways( $methods ) {
			if($this->get_secret_key(false)) { // only add this if the settings are stored for legacy systems
				if ( $this->subscription_support_enabled ) {
					$methods[] = 'WC_Gateway_Lunar_Addons';
				} else {
					$methods[] = 'WC_Gateway_Lunar';
				}
			}

			if($this->get_mobilepay_secret_key(false)) {
				$methods[] = 'WC_Gateway_Lunar_MobilePay';
			}

			$methods[] = 'WC_Gateway_Lunar_Hosted';

			$methods[] = 'WC_Gateway_Lunar_Hosted_MobilePay';

			return $methods;
		}

		/**
		 * Return order that can be captured, check for partial void or refund
		 *
		 * @param WC_Order $order
		 *
		 * @return mixed
		 */
		protected function get_order_amount( $order ) {
			return $order->get_total() - $order->get_total_refunded();
		}

		/**
		 * Capture payment when the order is changed from on-hold to complete or processing
		 *
		 * @param $order_id int
		 */
		public function capture_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( 'lunar' != $order->get_payment_method() && 'lunar_mobilepay' != $order->get_payment_method() && 'lunar_hosted' != $order->get_payment_method() && 'lunar_hosted_mobilepay' != $order->get_payment_method() ) {
				return false;
			}

			$this->checkIfMobilePay( $order );

			$transaction_id = $order->get_meta('_lunar_transaction_id', true );
			$captured = $order->get_meta('_lunar_transaction_captured', true );
			if ( ! ( $transaction_id && 'no' === $captured ) ) {
				return false;
			}

			if ( 'lunar_hosted' == $order->get_payment_method() ) {
				$gateway = new WC_Gateway_Lunar_Hosted();
				return $gateway->capture_payment( $order_id );
			}

			if ( 'lunar_hosted_mobilepay' == $order->get_payment_method() ) {
				$gateway = new WC_Gateway_Lunar_Hosted_MobilePay();
				return $gateway->capture_payment( $order_id );
			}

			$data = array(
				'amount'   => convert_float_to_iso_lunar_amount( $this->get_order_amount( $order ), dk_get_order_currency( $order ) ),
				'currency' => dk_get_order_currency( $order ),
			);
			WC_Lunar::log( "Info: Starting to capture {$data['amount']} in {$data['currency']}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			try {
				$result = $this->lunar_client->transactions()->capture( $transaction_id, $data );
				$this->handle_capture_result( $order, $result );
			} catch ( \Paylike\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Capture has failed!' );
			}
		}



		/**
		 * @param WC_Order $order
		 * @param array    $result // array result returned by the api wrapper.
		 */
		public function handle_capture_result( $order, $result ) {

			if ( 1 == $result['successful'] ) {
				$order->add_order_note(
					__( 'Lunar capture complete.', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
					__( 'Transaction ID: ', 'lunar-payments-for-woocommerce' ) . $result['id'] . PHP_EOL .
					__( 'Payment Amount: ', 'lunar-payments-for-woocommerce' ) . $this->real_amount( $result['capturedAmount'], $result['currency'] ) . PHP_EOL .
					__( 'Transaction authorized at: ', 'lunar-payments-for-woocommerce' ) . $result['created']
				);
				WC_Lunar::log( 'Info: Capture was successful' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
				$order->update_meta_data( '_lunar_transaction_id', $result['id'] );
				$order->update_meta_data( '_lunar_transaction_captured', 'yes' );
				$order->save_meta_data();
			} else {
				$error = array();
				foreach ( $result as $field_error ) {
					$error[] = $field_error['field'] . ':' . $field_error['message'];
				}
				WC_Lunar::log( 'Issue: Capture has failed there has been an issue with the transaction.' . json_encode( $result ) . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
				$error_message = implode( ' ', $error );
				$order->add_order_note(
					__( 'Unable to capture transaction!', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
					__( 'Error :', 'lunar-payments-for-woocommerce' ) . $error_message
				);
			}

		}




		/**
		 * Convert the cents amount into the full readable amount
		 *
		 * @param        $amount_in_cents
		 * @param string $currency
		 *
		 * @return string
		 */
		public function real_amount( $amount_in_cents, $currency = '' ) {
			return strip_tags( wc_price( $amount_in_cents / get_lunar_currency_multiplier( $currency ), array(
				'ex_tax_label' => false,
				'currency'     => $currency,
			) ) );
		}


		/**
		 * Cancel pre-auth on refund/cancellation
		 *
		 * @param int $order_id
		 */
		public function cancel_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( 'lunar' != $order->get_payment_method() && 'lunar_mobilepay' != $order->get_payment_method() && 'lunar_hosted' != $order->get_payment_method() && 'lunar_hosted_mobilepay' != $order->get_payment_method() ) {
				return false;
			}

			$this->checkIfMobilePay( $order );

			$transaction_id = $order->get_meta('_lunar_transaction_id', true );
			$captured = $order->get_meta('_lunar_transaction_captured', true );
			if ( ! $transaction_id ) {
				return false;
			}

			if ( 'lunar_hosted' == $order->get_payment_method() ) {
				$gateway = new WC_Gateway_Lunar_Hosted();
				return $gateway->cancel_payment( $order_id );
			}

			if ( 'lunar_hosted_mobilepay' == $order->get_payment_method() ) {
				$gateway = new WC_Gateway_Lunar_Hosted_MobilePay();
				return $gateway->cancel_payment( $order_id );
			}

			$data = array(
				'amount' => convert_float_to_iso_lunar_amount( $this->get_order_amount( $order ), dk_get_order_currency( $order ) ),
			);
			$currency = dk_get_order_currency( $order );
			if ( 'yes' == $captured ) {
				WC_Lunar::log( "Info: Starting to refund {$data['amount']} in {$currency}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
				try {
					$result = $this->lunar_client->transactions()->refund( $transaction_id, $data );
					$this->handle_refund_result( $order, $result );
				} catch ( \Lunar\Exception\ApiException $exception ) {
					WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Automatic Refund has failed!' );
				}
			} else {
				WC_Lunar::log( "Info: Starting to void {$data['amount']} in {$currency}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
				try {
					$result = $this->lunar_client->transactions()->void( $transaction_id, $data );
					$this->handle_refund_result( $order, $result );
				} catch ( \Lunar\Exception\ApiException $exception ) {
					WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Automatic Void has failed!' );
				}
			}

		}



		/**
		 * @param WC_Order $order
		 * @param          $result // array result returned by the api wrapper
		 */
		function handle_refund_result( $order, $result ) {
			if ( 1 == $result['successful'] ) {
				$order->add_order_note(
					__( 'Lunar refund complete.', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
					__( 'Transaction ID: ', 'lunar-payments-for-woocommerce' ) . $result['id'] . PHP_EOL .
					__( 'Refund amount: ', 'lunar-payments-for-woocommerce' ) . $this->real_amount( $result['refundedAmount'], $result['currency'] ) . PHP_EOL .
					__( 'Transaction authorized at: ', 'lunar-payments-for-woocommerce' ) . $result['created']
				);
				WC_Lunar::log( 'Info: Refund was successful' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
				$order->delete_meta_data('_lunar_transaction_captured' );
			} else {
				$error = array();
				foreach ( $result as $field_error ) {
					$error[] = $field_error['field'] . ':' . $field_error['message'];
				}
				$error_message = implode( ' ', $error );
				WC_Lunar::log( 'Issue: Capture has failed there has been an issue with the transaction.' . json_encode( $result ) . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
				$order->add_order_note(
					__( 'Unable to refund transaction!', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
					__( 'Error :', 'lunar-payments-for-woocommerce' ) . $error_message
				);
			}
		}



		/**
		 * @param WC_Order $order
		 * @param          $result // array result returned by the api wrapper
		 */
		function handle_void_result( $order, $result ) {
			if ( 1 == $result['successful'] ) {
				$order->add_order_note(
					__( 'Lunar void complete.', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
					__( 'Transaction ID: ', 'lunar-payments-for-woocommerce' ) . $result['id'] . PHP_EOL .
					__( 'Voided amount: ', 'lunar-payments-for-woocommerce' ) . $this->real_amount( $result['voidedAmount'], $result['currency'] ) . PHP_EOL .
					__( 'Transaction authorized at: ', 'lunar-payments-for-woocommerce' ) . $result['created']
				);
				WC_Lunar::log( 'Info: Void was successful' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
				$order->delete_meta_data('_lunar_transaction_captured' );
			} else {
				$error = array();
				foreach ( $result as $field_error ) {
					$error[] = $field_error['field'] . ':' . $field_error['message'];
				}
				$error_message = implode( ' ', $error );
				WC_Lunar::log( 'Issue: Void has failed there has been an issue with the transaction.' . json_encode( $result ) . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
				$order->add_order_note(
					__( 'Unable to void transaction!', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
					__( 'Error :', 'lunar-payments-for-woocommerce' ) . $error_message
				);

			}
		}

		/**
		 * Log exceptions.
		 *
		 * @param WC_Order                        $order
		 * @param \Paylike\Exception\ApiException $exception
		 * @param string                          $context
		 */
		public static function handle_exceptions( $order, $exception, $context = '' ) {
			if ( ! $exception ) {
				return false;
			}
			$exception_type = get_class( $exception );
			$message = '';
			switch ( $exception_type ) {
				case 'Paylike\\Exception\\NotFound':
					$message = __( 'Transaction not found! Check the transaction key used for the operation.', 'lunar-payments-for-woocommerce' );
					break;
				case 'Paylike\\Exception\\InvalidRequest':
					$message = __( 'The request is not valid! Check if there is any validation bellow this message and adjust if possible, if not, and the problem persists, contact the developer.', 'lunar-payments-for-woocommerce' );
					break;
				case 'Paylike\\Exception\\Forbidden':
					$message = __( 'The action is not allowed! You do not have the rights to perform the action. Reach out to support@lunar.app to get help.', 'lunar-payments-for-woocommerce' );
					break;
				case 'Paylike\\Exception\\Unauthorized':
					$message = __( 'The operation is not properly authorized! Check the credentials set in settings for Lunar.', 'lunar-payments-for-woocommerce' );
					break;
				case 'Paylike\\Exception\\Conflict':
					$message = __( 'The operation leads to a conflict! The same transaction is being requested for modification at the same time. Try again later.', 'lunar-payments-for-woocommerce' );
					break;
				case 'Paylike\\Exception\\ApiConnection':
					$message = __( 'Network issues! Check your connection and try again.', 'lunar-payments-for-woocommerce' );
					break;
				case 'Paylike\\Exception\\ApiException':
					$message = __( 'There has been a server issue! If this problem persists contact the developer.', 'lunar-payments-for-woocommerce' );
					break;
			}
			$message = __( 'Error: ', 'lunar-payments-for-woocommerce' ) . $message;
			$error_message = WC_Gateway_Lunar::get_response_error( $exception->getJsonBody() );
			if ( $context ) {
				$message = $context . PHP_EOL . $message;
			}
			if ( $error_message ) {
				$message = $message . PHP_EOL . 'Validation:' . PHP_EOL . $error_message;
			}

			if ( $order ) {
				$order->add_order_note( $message );
			}
			WC_Lunar::log( $message . PHP_EOL . json_encode( $exception->getJsonBody() ) );

			return $message;
		}

		/**
		 *  Ajax handler used to log details of the response of the popup
		 */
		public function log_transaction_data() {
			$err = sanitize_text_field( $_POST['err'] );
			$res = sanitize_text_field( $_POST['res'] );
			WC_Lunar::log( 'Info: Popup transaction data: err -> ' . json_encode( $err ) . ' - res:' . json_encode( $res ) . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			die();
		}


		/**
		 * Log function
		 */
		public static function log( $message ) {
			if ( empty( self::$log ) ) {
				self::$log = new WC_Logger();
			}
//			if ( defined( 'WP_ENABLE_LUNAR_LOGGING' ) && WP_ENABLE_LUNAR_LOGGING ) {
				self::$log->debug( $message, array( 'source' => 'lunar-payments-for-woocommerce' ) );
//			}
		}

		/**
		 * @param $order
		 *
		 * @return void
		 */
		protected function checkIfMobilePay( $order ): void {
			if ( $order->get_payment_method() === 'lunar_mobilepay' ) {
				$this->set_mobilepay_secret_key( $this->get_mobilepay_secret_key() );
			}
		}


	}

	$GLOBALS['wc_lunar'] = WC_Lunar::get_instance();
}


add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
