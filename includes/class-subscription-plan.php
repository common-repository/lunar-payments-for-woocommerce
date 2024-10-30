<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LunarSubscriptionHelper {

	public static $instance;

	private $order;

	private $args;

	private $force_unplanned = false;

	public static function append_plan_argument( $args, $force_unplanned = false, $order = null ) {
		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return $args;
		}

		if ( ! LunarSubscriptionHelper::$instance ) {
			self::$instance = new LunarSubscriptionHelper( $args, $force_unplanned, $order );
		}


		if ( self::$instance->fromOrder() ) {
			return self::$instance->append_plan_from_order();
		}

		return self::$instance->append_plan_from_cart();
	}

	private function __construct( $args, $force_unplanned, $order ) {
		$this->order = $order;
		$this->args = $args;
		$this->force_unplanned = $force_unplanned;
	}

	private function fromOrder() {
		return $this->order !== null;
	}

	private function append_plan_from_order() {
		if ( ! $this->order_has_any_subscription() ) {
			return false;
		}

		if ( ! $this->order_has_one_subscription() || $this->force_unplanned ) {
			$this->set_unplanned_merchant();

			return $this->args;
		}

		$this->set_plan_from_order();

		return $this->args;
	}

	private function append_plan_from_cart() {
		if ( ! $this->cart_has_any_subscription() ) {
			return false;
		}

		if ( ! $this->cart_has_one_subscription() || $this->force_unplanned ) {
			$this->set_unplanned_merchant();

			return $this->args;
		}

		$this->set_plan_from_cart();

		return $this->args;
	}

	private function set_plan_from_cart() {
		$subscription = $this->get_first_subscription_from_cart();
		if ( ! $subscription ) {
			return false;
		}

		$this->set_plan_from_product( $subscription );

	}

	private function set_plan_from_order() {
		$subscription = $this->get_first_subscription_from_order();
		if ( ! $subscription ) {
			return false;
		}

		$this->set_plan_from_product( $subscription );

	}

	private function set_plan_from_product( $product ) {
		$this->set_amount_from_product( $product );
		$this->set_interval_from_product( $product );
	}

	private function set_interval_from_product( $product ) {
		$this->set_plan_key();
		if ( ! isset( $this->args['plan']['repeat'] ) ) {
			$this->args['plan']['repeat'] = array();
		}

		if ( ! isset( $this->args['plan']['repeat']['interval'] ) ) {
			$this->args['plan']['repeat']['interval'] = array();
		}

		$this->args['plan']['repeat']['interval']['unit'] = strtolower( WC_Subscriptions_Product::get_period( $product ) );
		$this->args['plan']['repeat']['interval']['value'] = (integer) WC_Subscriptions_Product::get_interval( $product );

		$trial_length = WC_Subscriptions_Product::get_trial_length( $product );
		if($trial_length>0){
			// search and replace to fix safari bug
			$this->args['plan']['repeat']['first'] = str_replace(" ","T",WC_Subscriptions_Product::get_first_renewal_payment_date( $product ));
		}

	}

	private function set_amount_from_product( $product ) {
		$this->set_plan_key();
		$this->args['plan']['amount'] = array(
			'currency' => get_woocommerce_currency(),
			'value'   => convert_wocoomerce_float_to_lunar_amount( WC_Subscriptions_Product::get_price( $product ) ),
			'exponent' => wc_get_price_decimals()
		);
	}

	private function set_plan_key() {
		if ( ! isset( $this->args['plan'] ) ) {
			$this->args['plan'] = array();
		}
	}

	private function set_unplanned_key() {
		if ( ! isset( $this->args['unplanned'] ) ) {
			$this->args['unplanned'] = array();
		}
	}

	private function get_first_subscription_from_cart() {
		$subscriptionProduct = false;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			if ( WC_Subscriptions_Product::is_subscription( $product ) ) {
				$subscriptionProduct = $product;
				break;
			}
		}

		if ( ! $subscriptionProduct ) {
			return false;
		}

		return $subscriptionProduct;
	}

	private function set_unplanned_merchant() {
		$this->set_unplanned_key();
		$this->args['unplanned']['merchant'] = true;
	}

	private function cart_subscription_count() {
		$count = 0;
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			if ( WC_Subscriptions_Product::is_subscription( $product ) ) {
				$count ++;
			}
		}

		return $count;
	}

	private function cart_has_one_subscription() {
		return $this->cart_subscription_count() === 1;
	}

	private function cart_has_any_subscription() {
		return $this->cart_subscription_count() > 0;
	}

	private function order_subscription_count() {
		$count = 0;
		foreach ( $this->order->get_items() as $item_id => $line_item ) {
			$product = $line_item->get_product();
			if ( WC_Subscriptions_Product::is_subscription( $product ) ) {
				$count ++;
			}
		}

		return $count;
	}

	private function order_has_one_subscription() {
		return ( $this->order_subscription_count() === 1 );
	}

	private function order_has_any_subscription() {
		return ( $this->order_subscription_count() > 0 );
	}

	private function get_first_subscription_from_order() {
		$subscriptionProduct = false;

		foreach ( $this->order->get_items() as $item_id => $line_item ) {
			$product = $line_item->get_product();
			if ( WC_Subscriptions_Product::is_subscription( $product ) ) {
				$subscriptionProduct = $product;
				break;
			}
		}

		if ( ! $subscriptionProduct ) {
			return false;
		}

		return $subscriptionProduct;
	}


	public static function reset() {
		if ( self::$instance ) {
			self::$instance = null;
		}
	}

}
