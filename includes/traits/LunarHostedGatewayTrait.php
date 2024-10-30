<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait LunarHostedGatewayTrait {

	use LunarGlobalTrait;





	/**
	 * Process the payment
	 *
	 * @param int $order_id Reference.
	 *
	 * @return array|void
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$this->create_payment_intent( $order_id );


		// Return thank you page redirect.
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);

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

	public function return_handler() {
		@ob_clean();
		header( 'HTTP/1.1 200 OK' );
		try {
			$order_id = absint( $_REQUEST['order_id'] );
			$order = wc_get_order( $order_id );

			if ( $order->get_total() > 0 ) {
				$this->handle_payment( $order );
			}

			wp_redirect( $this->get_return_url( $order ) );
			exit();

		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			wp_redirect( get_permalink( wc_get_page_id( 'checkout' ) ) );
			exit();
		}
		wp_redirect( get_permalink( wc_get_page_id( 'cart' ) ) );
		exit();
	}

	protected function handle_payment( $order, $amount = false ) {
		$order_id = get_woo_id( $order );
		WC_Lunar::log( '------------- Start payment --------------' . PHP_EOL . "Info: Begin processing payment for order $order_id for the amount of {$this->get_order_amount($order)}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
		if ( false == $this->capture ) {
			try {
				$result = $this->lunar_client->payments()->fetch( $this->get_payment_intent( $order_id ) );
				$this->handle_authorize_result( $result, $order, $amount );
			} catch ( \Lunar\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Authorization Failed!' );
			}
		} else {
			$data = array(
				'amount' => [
					'decimal'  => (string) $this->get_order_amount( $order ),
					'currency' => dk_get_order_currency( $order )
				]
			);
			WC_Lunar::log( "Info: Starting to capture {$data['amount']['decimal']} in {$data['amount']['currency']}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			try {
				$result = $this->lunar_client->payments()->capture( $this->get_payment_intent( $order_id ), $data );
				$this->handle_capture_result( $order, $result, $this->get_payment_intent( $order_id ), $data, true );
			} catch ( \Lunar\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Capture Failed!' );
			}
		}

		return $result;
	}

	function handle_authorize_result( $result, $order, $amount = 0 ) {
		$transaction = $result;
		$result = $this->parse_api_transaction_response( $result, $order, $amount );
		if ( is_wp_error( $result ) ) {
			WC_Lunar::log( 'Issue: Authorize has failed, the result from the verification threw an wp error:' . $result->get_error_message() . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			$order->add_order_note(
				__( 'Unable to verify transaction!', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
				$result->get_error_message()
			);

		} else {
			$order->add_order_note(
				$this->get_transaction_authorization_details( $result )
			);
			$this->save_transaction_id( $result, $order );
			WC_Lunar::log( 'Info: Authorize was successful' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			$order->update_meta_data( '_lunar_transaction_captured', 'no' );
			$order->save_meta_data();
			$order->payment_complete();
		}
	}

	public function capture_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$data = array(
			'amount' => [
				'decimal'  => (string) $this->get_order_amount( $order ),
				'currency' => dk_get_order_currency( $order )
			]
		);
		$this->save_transaction_id( ['id'=>$this->get_payment_intent( $order_id )], $order );
		WC_Lunar::log( "Info: Starting to capture {$data['amount']['decimal']} in {$data['amount']['currency']}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
		try {
			$result = $this->lunar_client->payments()->capture( $this->get_payment_intent( $order_id ), $data );
			$this->handle_capture_result( $order, $result, $this->get_payment_intent( $order_id ), $data );
		} catch ( \Lunar\Exception\ApiException $exception ) {
			WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Capture Failed!' );
		}
	}

	public function handle_capture_result( $order, $result, $transaction_id, $data, $complete_order = false ) {

		if ( 'completed' == $result['captureState'] ) {
			$order->add_order_note(
				__( 'Lunar capture complete.', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
				__( 'Transaction ID: ', 'lunar-payments-for-woocommerce' ) . $transaction_id . PHP_EOL .
				__( 'Payment Amount: ', 'lunar-payments-for-woocommerce' ) . $this->real_amount( $data['amount']['decimal'], $data['amount']['currency'] )
			);
			WC_Lunar::log( 'Info: Capture was successful' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			$order->update_meta_data( '_lunar_transaction_id', $transaction_id );
			$order->update_meta_data( '_lunar_transaction_captured', 'yes' );
			$order->save_meta_data();
			if ( $complete_order ) {
				$order->payment_complete();
			}
		} else {

			WC_Lunar::log( 'Issue: Capture has failed there has been an issue with the transaction.' . json_encode( $result . ' - ' . $transaction_id ) . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			$error_message = $result['declinedMessage'];
			if(is_array($error_message)) {
				$error_message = implode(', ', $error_message);
			}
			$order->add_order_note(
				__( 'Unable to capture transaction!', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
				__( 'Error :', 'lunar-payments-for-woocommerce' ) . $error_message
			);
		}

	}

	public function cancel_payment( $order_id ) {
		$order = wc_get_order($order_id);
		$captured = $order->get_meta('_lunar_transaction_captured', true );
		$data = array(
			'amount' => [
				'decimal'  => (string) $this->get_order_amount( $order ),
				'currency' => dk_get_order_currency( $order )
			]
		);
		$currency = dk_get_order_currency( $order );
		if ( 'yes' == $captured ) {
			WC_Lunar::log( "Info: Starting to refund {$data['amount']['decimal']} in {$data['amount']['currency']}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			try {
				$result = $this->lunar_client->payments()->refund( $this->get_payment_intent( $order_id ), $data );
				$this->handle_refund_result( $order, $result, $this->get_payment_intent( $order_id ), $data );
			} catch ( \Paylike\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Automatic Refund has failed!' );
			}
		} else {
			WC_Lunar::log( "Info: Starting to void {$data['amount']['decimal']} in {$data['amount']['currency']}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			try {
				$result = $this->lunar_client->payments()->cancel( $this->get_payment_intent( $order_id ), $data );
				$this->handle_cancel_result( $order, $result, $this->get_payment_intent( $order_id ), $data );
			} catch ( \Paylike\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Automatic Void has failed!' );
			}
		}
	}

	/**
	 * Parses api transaction response to for errors
	 *
	 * @param array      $transaction The transaction returned by the api wrapper.
	 * @param WC_Order   $order The order object.
	 * @param bool|float $amount The amount in the transaction.
	 *
	 * @return WP_Error
	 */
	protected function parse_api_transaction_response( $transaction, $order = null, $amount = false ) {
		if ( ! $this->is_transaction_successful( $transaction, $order, $amount ) ) {
			$error_message = WC_Gateway_Lunar::get_response_error( $transaction );
			WC_Lunar::log( 'Transaction with error:' . json_encode( $transaction ) . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );

			return new WP_Error( 'lunar_error', __( 'Error: ', 'lunar-payments-for-woocommerce' ) . $error_message );
		}

		return $transaction;
	}

	/**
	 * Checks if the transaction was successful and
	 * the data was not tempered with.
	 *
	 * @param array      $transaction The transaction returned by the api wrapper.
	 * @param WC_Order   $order The order object.
	 * @param bool|false $amount Overwrite the amount, when we don't pay the full order.
	 *
	 * @return bool
	 */
	protected function is_transaction_successful( $transaction, $order = null, $amount = false ) {
		// if we don't have the order, we only check the successful status.
		if ( ! $order ) {
			return true == $transaction['authorisationCreated'];
		}
		// we need to overwrite the amount in the case of a subscription.
		if ( ! $amount ) {
			$amount = $order->get_total();
		}
		$match_currency = dk_get_order_currency( $order ) == $transaction['amount']['currency'];
		$match_amount = $amount == $transaction['amount']['decimal'];

		return ( true == $transaction['authorisationCreated'] && $match_currency && $match_amount );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		$transaction_id = $order->get_meta('_lunar_transaction_id', true );
		$captured = $order->get_meta('_lunar_transaction_captured', true );
		$captured = 'yes';
		if ( ! $order || ! $transaction_id ) {
			return false;
		}
		$data = array();
		$currency = dk_get_order_currency( $order );
		if ( ! is_null( $amount ) ) {
			$data['amount'] = convert_float_to_iso_lunar_amount( $amount, $currency );
		}

		$data = array(
			'amount' => [
				'decimal'  => (string) $amount,
				'currency' => dk_get_order_currency( $order )
			]
		);

		if ( 'yes' == $captured ) {
			WC_Lunar::log( "Info: Starting to refund {$data['amount']['decimal']} in {$data['amount']['currency']}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			try {
				$result = $this->lunar_client->payments()->refund( $this->get_payment_intent( $order_id ), $data );
				$this->handle_refund_result( $order, $result, $this->get_payment_intent( $order_id ), $data );
			} catch ( \Lunar\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Automatic Refund has failed!' );
				$error_message = WC_Gateway_Lunar::get_response_error( $exception->getJsonBody() );
				if ( ! $error_message ) {
					$error_message = 'There has been an problem with the refund. Refresh the order to see more details';
				}

				return new WP_Error( 'lunar_error', __( 'Error: ', 'lunar-payments-for-woocommerce' ) . $error_message );
			}
		} else {
			WC_Lunar::log( "Info: Starting to void {$data['amount']['decimal']} in {$data['amount']['currency']}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			try {
				$result = $this->lunar_client->payments()->cancel( $this->get_payment_intent( $order_id ), $data );
				$this->handle_cancel_result( $order, $result, $this->get_payment_intent( $order_id ), $data );
			} catch ( \Lunar\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Automatic Void has failed!' );
				$error_message = WC_Gateway_Lunar::get_response_error( $exception->getJsonBody() );
				if ( ! $error_message ) {
					$error_message = 'There has been an problem with the refund. Refresh the order to see more details';
				}

				return new WP_Error( 'lunar_error', __( 'Error: ', 'lunar-payments-for-woocommerce' ) . $error_message );
			}
		}

		return true;

	}

	function handle_refund_result( $order, $result, $transaction_id, $data ) {
		if ( 'completed' == $result['refundState'] ) {
			$order->add_order_note(
				__( 'Lunar refund complete.', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
				__( 'Transaction ID: ', 'lunar-payments-for-woocommerce' ) . $transaction_id . PHP_EOL .
				__( 'Refunded Amount: ', 'lunar-payments-for-woocommerce' ) . $this->real_amount( $data['amount']['decimal'], $data['amount']['currency'] )
			);
			WC_Lunar::log( 'Info: Refund was successful' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			$order->delete_meta_data('_lunar_transaction_captured' );
		} else {
			$error_message = $result['declinedReason'];
			$error_message = $error_message['error'];
			WC_Lunar::log( 'Issue: Refund has failed there has been an issue with the transaction.' . json_encode( $result ) . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			$order->add_order_note(
				__( 'Unable to refund transaction!', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
				__( 'Error :', 'lunar-payments-for-woocommerce' ) . $error_message
			);
		}
	}

	function handle_cancel_result( $order, $result, $transaction_id, $data ) {
		if ( 'completed' == $result['cancelState'] ) {
			$order->add_order_note(
				__( 'Lunar void complete.', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
				__( 'Transaction ID: ', 'lunar-payments-for-woocommerce' ) . $transaction_id . PHP_EOL .
				__( 'Voided Amount: ', 'lunar-payments-for-woocommerce' ) . $this->real_amount( $data['amount']['decimal'], $data['amount']['currency'] )
			);
			WC_Lunar::log( 'Info: Void was successful' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			$order->delete_meta_data('_lunar_transaction_captured' );
		} else {
			$error_message = $result['declinedReason'];
			$error_message = $error_message['error'];

			WC_Lunar::log( 'Issue: Void has failed there has been an issue with the transaction.' . json_encode( $result ) . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			$order->add_order_note(
				__( 'Unable to void transaction!', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
				__( 'Error :', 'lunar-payments-for-woocommerce' ) . $error_message
			);
		}
	}

	protected function get_transaction_authorization_details( $transaction ) {
		return __( 'Lunar authorization complete.', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
			__( 'Transaction ID: ', 'lunar-payments-for-woocommerce' ) . $transaction['id'] . PHP_EOL .
			__( 'Payment Amount: ', 'lunar-payments-for-woocommerce' ) . $this->real_amount( $transaction['amount']['decimal'], $transaction['amount']['currency'] );
	}

	function real_amount( $amount, $currency = '' ) {
		return strip_tags( wc_price( $amount, array(
			'ex_tax_label' => false,
			'currency'     => $currency,
		) ) );
	}

	public function receipt_page( $order_id ) {
		global $wp_version;

		$payment_intent = $this->get_payment_intent( $order_id );

		$redirect_url = "https://pay.lunar.money?id=$payment_intent";
		if($this->testmode){
			$redirect_url = "https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=$payment_intent";
		}
		wp_redirect( $redirect_url );
		die();
	}

	public function get_payment_intent( $order_id ) {
		$order = wc_get_order($order_id);
		return $order->get_meta('_lunar_intent_id', true );
	}

	public function get_payment_intent_selected_payment_method( $order_id ) {
		$order = wc_get_order($order_id);
		return $order->get_meta('_lunar_previous_payment_method', true );
	}


	public function create_payment_intent( $order_id ) {
		global $wp_version;
		$order = wc_get_order( $order_id );
		$currency = dk_get_order_currency( $order );
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
		$amount = $this->amount_for_api_from_float( $order->get_total() );

		$test = $this->get_test_args($currency);

		$args = $this->get_args( $currency, $amount, $order, $products, $wp_version );


		$existing_payment_intent = $this->get_payment_intent( $order_id );
		$previous_payment_method = $this->get_payment_intent_selected_payment_method( $order_id );
		if( $this->payment_args_unchanged( $existing_payment_intent, $args, $previous_payment_method, $amount ) ){
			WC_Lunar::log( 'Found existing payment intent:'.$existing_payment_intent. PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			return $existing_payment_intent;
		}

		if ( $this->testmode ) {
			$args['test'] = $test;
		}

		if ( $this->mobilepay_configuration_id ) {
			$args['mobilePayConfiguration'] = array(
				'configurationID' => $this->mobilepay_configuration_id,
				'logo'            => $this->logo,
			);
		}else{
			// try to get them from the mobilepay gateway
			$mobilepay_gateway = new WC_Gateway_Lunar_Hosted_MobilePay();
			$settings = $mobilepay_gateway->settings;
			if(!empty($settings['mobilepay_configuration_id'])) {
				$args['mobilePayConfiguration'] = array(
					'configurationID' => $settings['mobilepay_configuration_id'],
					'logo'            => $settings['logo'],
				);
			}
		}

		WC_Lunar::log( 'Creating payment_intent:' . $order->get_order_number() . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__.json_encode($args) );

		if( $this->only_method_args_unchanged( $existing_payment_intent, $args, $previous_payment_method, $amount ) ){
			$payment = $this->lunar_client->payments()->updatePreferredPaymentMethod( $existing_payment_intent, $args['preferredPaymentMethod'] );

			$payment_id = $payment['paymentId'];

			WC_Lunar::log( 'Created payment_intent for' . $order->get_order_number() . '--Intent id: ' . $payment_id . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
		}else {
			$payment_id = $this->lunar_client->payments()->create( $args );

			WC_Lunar::log( 'Created payment_intent for' . $order->get_order_number() . '--Intent id: ' . $payment_id . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
		}


		$order->update_meta_data( '_lunar_intent_id', $payment_id );
		$order->update_meta_data( '_lunar_previous_payment_method', $args['preferredPaymentMethod'] );
		$order->save_meta_data();


		if ( $existing_payment_intent ) {
//			try {
//				$transaction = $this->lunar_client->payments()->cancel( $existing_payment_intent, [
//					'amount' => $args['amount']
//				] );
//			}catch (Exception $exception){
//				WC_Lunar::log( 'Issue: Canceling existing payment intent failed:'.$existing_payment_intent. $exception->getMessage() . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
//			}
		}

		return $payment_id;
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
					$value = $this->get_field_value( $key, $field, $post_data );

					if ( is_string($value) ) {
						$value = trim( $value );
					}

					$this->settings[ $key ] = $value;
				} catch ( Exception $e ) {
					$this->add_error( $e->getMessage() );
				}
			}
		}

		return update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ) );
	}



	private function get_test_args($currency): array {
		$test = [
			"card"        => [
				"scheme"  => "supported",
				"code"    => "valid",
				"status"  => "valid",
				"limit"   => [
					"decimal"  => "39900.95",
					"currency" => $currency
				],
				"balance" => [
					"decimal"  => "39900.95",
					"currency" => $currency
				]
			],
			"fingerprint" => "success",
			"tds"         => array(
				"fingerprint" => "success",
				"challenge"   => true,
				"status"      => "authenticated"
			),
		];

		return $test;
	}

	private function getRedirectUrl( $order_id ): string {
		$return_url = WC()->api_request_url( get_class( $this ) ) . '?order_id=' . $order_id;

		if(str_contains($return_url, '/?wc-api')){
			$return_url = str_replace('?order_id=' . $order_id, '&order_id=' . $order_id, $return_url);
		}

		return $return_url;
	}

	public function amount_for_api_from_float( $total ) {
		return str_replace( ',', '.', $total );
	}

	public function validate_secret_key_field( $key, $value ) {
		if ( !$value ) {
			$error = __( 'The secret key is required', 'lunar-payments-for-woocommerce' );
			WC_Admin_Settings::add_error( $error );
			throw new Exception( $error );
		}

		return $value;
	}

	public function validate_public_key_field( $key, $value ) {
		if ( !$value ) {
			$error = __( 'The secret key is required', 'lunar-payments-for-woocommerce' );
			WC_Admin_Settings::add_error( $error );
			throw new Exception( $error );
		}

		return $value;
	}

	public function get_legacy_secret_key() {{
			$options = get_option( 'woocommerce_lunar_settings' );
			return isset( $options['secret_key'] );
		}

		return $this->secret_key;
	}

	/**
	 * @return string
	 * Get the stored secret key depending on the type of payment sent.
	 */
	public function get_legacy_mobilepay_secret_key() {
		$options = get_option( 'woocommerce_lunar_mobilepay_settings' );
		return isset( $options['secret_key'] );
	}

	public function check_payment($order){

		try {
			$result = $this->lunar_client->payments()->fetch( $this->get_payment_intent( $order->id ) );
		}
		catch (Exception $exception){
			WC_Lunar::log( 'Polling for order did not succeed:' . $exception->getMessage() . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			return;
		}

		if ( !$this->is_transaction_successful( $result, $order ) ) {
			WC_Lunar::log( 'Polling for order did not succeed:' . $result['id'] . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			$order->add_order_note(
				__( 'Polling for order did not succeed:', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
				$result['id']
			);

		} else {
			WC_Lunar::log( 'Polling succeded: '.$this->get_payment_intent( $order->id ) );
			$order->add_order_note(
				__( 'Polling for order worked, proceeding', 'lunar-payments-for-woocommerce' ) . PHP_EOL
			);
			if ( $order->get_total() > 0 ) {
				$this->handle_payment( $order );
			}
		}
	}

	private function payment_args_unchanged( $existing_payment_intent, $args, $previous_payment_method, $amount ) {
		return $existing_payment_intent && $previous_payment_method == $args['preferredPaymentMethod'] && $args['amount']['decimal'] == $amount;
	}

	private function only_method_args_unchanged( $existing_payment_intent, $args, $previous_payment_method, $amount ) {
		return $existing_payment_intent && $previous_payment_method != $args['preferredPaymentMethod'] && $args['amount']['decimal'] == $amount;
	}

}
