<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrateToHostedCheckout {

	public static $instance;

	private $args;

	public static function start( $args ) {

		if ( ! MigrateToHostedCheckout::$instance ) {
			self::$instance = new MigrateToHostedCheckout( $args );
		}

		return self::$instance->migrate();
	}

	private function __construct( $args ) {
		$this->args = $args;
	}

	public function migrate() {
		$lunar_migrated = get_option( 'lunar_migrated', false );
		if($lunar_migrated) {
			return false;
		}
		$this->migrateLunar();
		$this->migrateMobilePay();
		update_option( 'lunar_migrated', true );
	}

	private function migrateLunar() {
		$gateway = new WC_Gateway_Lunar();
		$new_gateway = new WC_Gateway_Lunar_Hosted();
		$options = $gateway->settings;
		$options_hosted = $new_gateway->settings;

		$hosted_checkout_options = $options;
		$hosted_checkout_options['enabled'] = 'yes';
		$hosted_checkout_options = $this->renameSetting( $hosted_checkout_options,'store_title','popup_title' );
		$options['enabled'] = 'no';


		if($this->isGatewayInactive( $gateway ) ) {
			return false;
		}

		if($new_gateway->secret_key) {
			return false;
		}

		$this->update_settings($hosted_checkout_options, $new_gateway);
		$this->update_settings($options, $gateway);
	}

	private function migrateMobilePay() {
		$gateway = new WC_Gateway_Lunar_MobilePay();
		$new_gateway = new WC_Gateway_Lunar_Hosted_MobilePay();
		$hosted_checkout_card = new WC_Gateway_Lunar_Hosted();
		$options = $gateway->settings;
		$options_hosted = $new_gateway->settings;

		$store_title = get_bloginfo( 'name' );
		if($hosted_checkout_card->settings['store_title']){
			$store_title = $hosted_checkout_card->settings['store_title'];
		}

		$hosted_checkout_options = $options;
		$hosted_checkout_options['enabled'] = 'yes';
		$hosted_checkout_options['store_title'] = $store_title;
		$hosted_checkout_options['mobilepay_logo'] = $hosted_checkout_options['logo'];
		$options['enabled'] = 'no';


		if($this->isGatewayInactive( $gateway ) ) {
			return false;
		}

		if($new_gateway->secret_key) {
			return false;
		}

		$this->update_settings($hosted_checkout_options, $new_gateway);
		$this->update_settings($options, $gateway);
	}



	private function update_settings($settings, $gateway){

		return update_option( $gateway->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $gateway->id, $settings) );
	}

	public static function reset() {
		if ( self::$instance ) {
			self::$instance = null;
		}
	}

	private function renameSetting( $hosted_checkout_options, $new, $old ) {
		$hosted_checkout_options[$new] = $hosted_checkout_options[$old];
		unset( $hosted_checkout_options[$old] );
		return $hosted_checkout_options;
	}

	private function isGatewayInactive( $gateway ): bool {
		return ! $gateway->secret_key || $gateway->enabled == 'no';
	}

}
