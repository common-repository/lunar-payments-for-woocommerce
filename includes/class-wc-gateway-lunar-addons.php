<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Gateway_Lunar_Addons
 *
 * The addons class, used for subscriptions.
 */
class WC_Gateway_Lunar_Addons extends WC_Gateway_Lunar {


	/**
	 * WC_Gateway_Lunar_Addons constructor.
	 */
	public function __construct() {
		parent::__construct();
		if ( class_exists( 'WC_Subscriptions_Order' ) ) {
			add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array(
				$this,
				'scheduled_subscription_payment',
			), 10, 2 );
			add_action( 'wcs_resubscribe_order_created', array( $this, 'delete_resubscribe_meta' ), 10 );
			add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $this->id, array(
				$this,
				'update_failing_payment_method',
			), 10, 2 );
			// display the credit card used for a subscription in the "My Subscriptions" table.
			add_filter( 'woocommerce_my_subscriptions_payment_method', array(
				$this,
				'maybe_render_subscription_payment_method',
			), 10, 2 );
			// allow store managers to manually set Lunar as the payment method on a subscription.
			add_filter( 'woocommerce_subscription_payment_meta', array(
				$this,
				'add_subscription_payment_meta',
			), 10, 2 );
			add_filter( 'woocommerce_subscription_validate_payment_meta', array(
				$this,
				'validate_subscription_payment_meta',
			), 10, 2 );

			add_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', array(
				$this,
				'maybe_dont_update_payment_method',
			), 10, 3 );
		}
	}


	/**
	 * Trigger scheduled subscription payment.
	 *
	 * @param float    $amount_to_charge The amount to charge.
	 * @param WC_Order $renewal_order An order object created to record the renewal payment.
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
		$result = $this->process_subscription_payment( $renewal_order, $amount_to_charge );
		if ( is_wp_error( $result ) ) {
			/* translators: %1$s is replaced with the error message */
			$renewal_order->update_status( 'failed', sprintf( __( 'Lunar Transaction Failed (%s)', 'lunar-payments-for-woocommerce' ), $result->get_error_message() ) );
		}
	}


	/**
	 * Process payment for subscription
	 * we mimic recurring payments, using the last
	 * transaction id - see @https://github.com/lunar/api-docs#recurring-payments
	 *
	 * @param WC_Order $order the renewal order created from the initial order only containing the subscription product we are renewing.
	 * @param int      $amount The amount for the subscription.
	 *
	 * @return bool|int|mixed|null|WP_Error
	 */
	public function process_subscription_payment( $order = null, $amount = 0 ) {
		if ( 0 == $amount ) {
			$order->payment_complete();

			return true;
		}
		// get last transaction id used.
		$last_transaction_id = $this->get_transaction_id( $order );
		$last_card_id = $this->get_card_id( $order );
		$new_transaction = null;
		if ( ! $last_transaction_id && ! $last_card_id ) {
			if ( ! $last_transaction_id ) {
				return new WP_Error( 'lunar_error', __( 'Neither Transaction ID nor Card ID was found', 'lunar-payments-for-woocommerce' ) );
			}
		}
		$order_id = get_woo_id( $order );
		WC_Lunar::log( "Info: Begin processing subscription payment for order {$order_id} for the amount of {$amount}" );
		// create a new transaction from a previous one, or a card.
		if ( $last_card_id ) {
			// card can be added after a subscription, should get checked first.
			$new_transaction = $this->create_new_transaction( $last_card_id, $order, $amount, $type = 'card' );
		} else {
			$new_transaction = $this->create_new_transaction( $last_transaction_id, $order, $amount );
		}
		if ( is_wp_error( $new_transaction ) ) {
			return $new_transaction;
		}

		return $this->handle_payment( $new_transaction, $order, $amount );
	}


	/**
	 * Gets merchant id from transaction or card
	 *
	 * @param int    $entity_id card id / transaction id.
	 * @param string $type 'card' or 'transaction'.
	 *
	 * @return bool|int|mixed|null|WP_Error
	 */
	private function get_merchant_id( $entity_id, $type = 'transaction' ) {
		if ( 'card' == $type ) {
			// try to get the card.
			WC_Lunar::log( "Info: Attempting to fetch the card {$entity_id}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			try {
				$entity = $this->lunar_client->cards()->fetch( $entity_id );
			} catch ( \Paylike\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( null, $exception, 'Fetching card entity failed' );

				return new WP_Error( 'lunar_error', __( 'The card could not be fetched.', 'lunar-payments-for-woocommerce' ) );
			}
		} else {
			// try to get the transaction.
			WC_Lunar::log( "Info: Attempting to fetch the transaction {$entity_id}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			try {
				$entity = $this->lunar_client->transactions()->fetch( $entity_id );
			} catch ( \Paylike\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( null, $exception, 'Fetching transaction entity failed' );

				return new WP_Error( 'lunar_error', __( 'The transaction could not be fetched.', 'lunar-payments-for-woocommerce' ) );
			}
			$entity = $entity['transaction'];
		}
		if ( ! $entity['merchantId'] ) {
			WC_Lunar::log( 'Issue: The merchant id is missing:' . json_encode( $entity ) . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );

			return new WP_Error( 'lunar_error', __( 'Merchant ID not found', 'lunar-payments-for-woocommerce' ) );
		}
		WC_Lunar::log( 'Success: The merchant was found' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );

		return $entity['merchantId'];
	}

	/**
	 * Update the lunar_transaction_id for a subscription after using Lunar to complete a payment to make up for
	 * an automatic renewal payment which previously failed.
	 *
	 * @param WC_Subscription $subscription The subscription for which the failing payment method relates.
	 * @param WC_Order        $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	 *
	 * @return void
	 */
	public function update_failing_payment_method( $subscription, $renewal_order ) {

		$subscription->update_meta_data( '_lunar_transaction_id', $renewal_order->get_meta( '_lunar_transaction_id', true ) );
		$subscription->update_meta_data( '_lunar_card_id', $renewal_order->get_meta( '_lunar_card_id', true ) );
		$subscription->save_meta_data();
	}

	/**
	 * Don't transfer Lunar transaction id meta to resubscribe orders.
	 *
	 * @param WC_Order $resubscribe_order The order created for the customer to resubscribe to the old expired/cancelled subscription.
	 */
	public function delete_resubscribe_meta( $resubscribe_order ) {
		$resubscribe_order->delete_meta_data('_lunar_transaction_id' );
		$resubscribe_order->delete_meta_data('_lunar_card_id' );
	}

	/**
	 * Render the payment method used for a subscription in the "My Subscriptions" table
	 *
	 * @param string          $payment_method_to_display the default payment method text to display.
	 * @param WC_Subscription $subscription the subscription details.
	 *
	 * @return string the subscription payment method
	 */
	public function maybe_render_subscription_payment_method( $payment_method_to_display, $subscription ) {
		// bail for other payment methods.
		if ( $this->id !== $subscription->get_payment_method() || ! $subscription->get_user() ) {
			return $payment_method_to_display;
		}
		$transaction_id = $subscription->get_meta( '_lunar_transaction_id', true );
		// add more details, if we can get the card.
		try {
			$transaction = $this->lunar_client->transactions()->fetch( $transaction_id );
			if ( 1 == $transaction['successful'] && $transaction['card'] ) {
				$card = $transaction['card'];
				/* translators: %1$s is replaced with card type, %2$s is replaced with last4 digits and %3$s is replaced with the card id */
				$payment_method_to_display = sprintf( __( 'Via %s card ending in %s (%s)', 'lunar-payments-for-woocommerce' ), ucfirst( $card['scheme'] ), $card['last4'], ucfirst( $this->id ) );
			}
		} catch ( \Paylike\Exception\ApiException $exception ) {
			WC_Lunar::handle_exceptions( null, $exception, 'Fetching transaction entity failed' );
		}


		return $payment_method_to_display;
	}

	/**
	 * Include the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscriptions screen in 2.0+.
	 *
	 * @param array           $payment_meta associative array of meta data required for automatic payments.
	 * @param WC_Subscription $subscription An instance of a subscription object.
	 *
	 * @return array
	 */
	public function add_subscription_payment_meta( $payment_meta, $subscription ) {
		$payment_meta[ $this->id ] = array(
			'post_meta' => array(
				'_lunar_transaction_id' => array(
					'value' => $subscription->get_meta( '_lunar_transaction_id', true ),
					'label' => 'A previous transaction ID',
				),
				'lunar_card_id'         => array(
					'value' => $subscription->get_meta( '_lunar_card_id', true ),
					'label' => 'A previous card ID',
				),
			),
		);

		return $payment_meta;
	}


	/**
	 *
	 * In delayed mode update after the redirect page
	 *
	 * @param $update
	 * @param $new_payment_method
	 * @param $subscription
	 *
	 * @return false|mixed
	 */
	public function maybe_dont_update_payment_method( $update, $new_payment_method, $subscription ) {

		if ( $this->id == $new_payment_method && $this->checkout_mode != 'before_order' ) {
			$update = false;
		}

		return $update;
	}

	/**
	 * Validate the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscriptions screen in 2.0+.
	 *
	 * @param $payment_method_id The ID of the payment method to validate.
	 * @param $payment_meta Associative array of meta data required for automatic payments.
	 *
	 * @throws Exception
	 */
	public function validate_subscription_payment_meta( $payment_method_id, $payment_meta ) {
		if ( $this->id === $payment_method_id ) {
			if ( ! isset( $payment_meta['post_meta']['_lunar_transaction_id']['value'] ) || empty( $payment_meta['post_meta']['_lunar_transaction_id']['value'] ) ) {
				if ( ( ! isset( $payment_meta['post_meta']['_lunar_card_id']['value'] ) || empty( $payment_meta['post_meta']['_lunar_card_id']['value'] ) ) && ( ! isset( $payment_meta['post_meta']['lunar_card_id']['value'] ) || empty( $payment_meta['post_meta']['lunar_card_id']['value'] ) ) ) {
					throw new Exception( 'A "_lunar_transaction_id" value is required.' );
				}
			}
		}
	}

	/**
	 * Saves the transaction id on the order and subscription.
	 *
	 * @param array    $result The result returned by the api wrapper.
	 * @param WC_Order $order The order asociated with the order.
	 */
	protected function save_transaction_id( $result, $order ) {
		parent::save_transaction_id( $result, $order );
		// Also store it on the subscriptions being purchased or paid for in the order.
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( get_woo_id( $order ) ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( get_woo_id( $order ) );
		} elseif ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( get_woo_id( $order ) ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( get_woo_id( $order ) );
		} elseif ( function_exists( 'wcs_get_subscription' ) && wcs_get_subscription( get_woo_id( $order ) ) ) {
			$subscriptions = [ wcs_get_subscription( get_woo_id( $order ) ) ];
		} else {
			$subscriptions = array();
		}
		foreach ( $subscriptions as $subscription ) {
			$subscription->update_meta_data('_lunar_transaction_id', $result['id'] );
			$subscription->save_meta_data();
		}
	}

	/**
	 * Saves the card id on the order and subscription.
	 *
	 * @param int      $card_id The card reference.
	 * @param WC_Order $order The order reference.
	 */
	protected function save_card_id( $card_id, $order ) {
		parent::save_card_id( $card_id, $order );
		// Also store it on the subscriptions being purchased or paid for in the order.
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( get_woo_id( $order ) ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( get_woo_id( $order ) );
		} elseif ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( get_woo_id( $order ) ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( get_woo_id( $order ) );
		} else {
			$subscriptions = array();
		}
		foreach ( $subscriptions as $subscription ) {
			$subscription->update_meta_data('_lunar_card_id', $card_id );
			$subscription->save_meta_data();
		}
	}
}
