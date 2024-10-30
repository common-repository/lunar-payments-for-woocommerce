<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait LunarGatewayTrait{
	use LunarGlobalTrait;

	/**
	 * Process payment with transaction id/card id supplied by user directly
	 *
	 * @param $order
	 */
	public function process_default_payment( $order, $transaction_id = '' ) {
		if(!$transaction_id){
			$transaction_id = isset( $_POST['lunar_token'] ) ? sanitize_text_field($_POST['lunar_token']) : '';
		}
		if ( $order->get_total() > 0 ) {

			if ( empty( $transaction_id ) ) {
				wc_add_notice( __( 'The transaction id is missing, it seems that the authorization failed or the reference was not sent. Please try the payment again. The previous payment will not be captured.', 'lunar-payments-for-woocommerce' ), 'error' );

				return;
			}
			$save_transaction = isset( $_POST['wc-lunar-new-payment-method'] ) && ! empty( $_POST['wc-lunar-new-payment-method'] );
			if ( $save_transaction ) {
				$token = new WC_Payment_Token_Lunar();
				$token->set_gateway_id( $this->id );
				$token->set_user_id( get_current_user_id() );
				$token->set_token( 'transaction-' . $transaction_id );
				$transaction = $this->lunar_client->transactions()->fetch( $transaction_id );
				$token->set_last4( $transaction['card']['last4'] );
				$token->set_brand( ucfirst( $transaction['card']['scheme'] ) );
				$saved = $token->save();
			}

			$this->handle_payment( $transaction_id, $order );
		} else {
			if ( $transaction_id ) {
				$this->save_transaction_id( [ 'id' => $transaction_id ], $order );
			}
			$order->payment_complete();
		}

		return $order;
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

	/**
	 * Handles API interaction for the order
	 * by either only authorizing the payment
	 * or making the capture directly
	 *
	 * @param int           $transaction_id Reference.
	 * @param WC_Order      $order Order object.
	 * @param boolean|float $amount The total amount.
	 *
	 * @return bool|int|mixed
	 */
	protected function handle_payment( $transaction_id, $order, $amount = false ) {
		$order_id = get_woo_id( $order );
		WC_Lunar::log( '------------- Start payment --------------' . PHP_EOL . "Info: Begin processing payment for order $order_id for the amount of {$this->get_order_amount($order)}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
		if ( false == $this->capture ) {
			try {
				$result = $this->lunar_client->transactions()->fetch( $transaction_id );
				$this->handle_authorize_result( $result, $order, $amount );
			} catch ( \Paylike\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Authorization Failed!' );
			}
		} else {
			$data = array(
				'amount'   => convert_float_to_iso_lunar_amount( $this->get_order_amount( $order ), dk_get_order_currency( $order ) ),
				'currency' => dk_get_order_currency( $order ),
			);
			WC_Lunar::log( "Info: Starting to capture {$data['amount']} in {$data['currency']}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			try {
				$result = $this->lunar_client->transactions()->capture( $transaction_id, $data );
				$this->handle_capture_result( $result, $order, $amount );
			} catch ( \Paylike\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Capture Failed!' );
			}
		}

		return $result;
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
			return 1 == $transaction['successful'];
		}
		// we need to overwrite the amount in the case of a subscription.
		if ( ! $amount ) {
			$amount = $order->get_total();
		}
		$match_currency = dk_get_order_currency( $order ) == $transaction['currency'];
		$match_amount = convert_float_to_iso_lunar_amount( $amount, dk_get_order_currency( $order ) ) == $transaction['amount'];

		return ( 1 == $transaction['successful'] && $match_currency && $match_amount );
	}


	/**
	 * Handle authorization result.
	 *
	 * @param array     $result Array result returned by the api wrapper.
	 * @param WC_Order  $order The order object.
	 * @param int|float $amount The amount authorized/captured.
	 */
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

	/**
	 * Get the details from a transaction.
	 *
	 * @param array $transaction The transaction returned by the api wrapper.
	 *
	 * @return string
	 */
	protected function get_transaction_authorization_details( $transaction ) {
		return __( 'Lunar authorization complete.', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
			__( 'Transaction ID: ', 'lunar-payments-for-woocommerce' ) . $transaction['id'] . PHP_EOL .
			__( 'Payment Amount: ', 'lunar-payments-for-woocommerce' ) . $this->real_amount( $transaction['amount'], $transaction['currency'] ) . PHP_EOL .
			__( 'Transaction authorized at: ', 'lunar-payments-for-woocommerce' ) . $transaction['created'];
	}

	/**
	 * Convert the cents amount into the full readable amount
	 *
	 * @param float  $amount_in_cents The amount in cents.
	 * @param string $currency The currency for which this amount is formatted.
	 *
	 * @return string
	 */
	function real_amount( $amount_in_cents, $currency = '' ) {
		return strip_tags( wc_price( $amount_in_cents / get_lunar_currency_multiplier( $currency ), array(
			'ex_tax_label' => false,
			'currency'     => $currency,
		) ) );
	}



	/**
	 * Get the details from a captured transaction.
	 *
	 * @param array $transaction The transaction returned by the api wrapper.
	 *
	 * @return string
	 */
	protected function get_transaction_capture_details( $transaction ) {
		return __( 'Lunar capture complete.', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
			__( 'Transaction ID: ', 'lunar-payments-for-woocommerce' ) . $transaction['id'] . PHP_EOL .
			__( 'Authorized amount: ', 'lunar-payments-for-woocommerce' ) . $this->real_amount( $transaction['amount'], $transaction['currency'] ) . PHP_EOL .
			__( 'Captured amount: ', 'lunar-payments-for-woocommerce' ) . $this->real_amount( $transaction['capturedAmount'], $transaction['currency'] ) . PHP_EOL .
			__( 'Charge authorized at: ', 'lunar-payments-for-woocommerce' ) . $transaction['created'];
	}

	/**
	 * Refund a transaction process function.
	 *
	 * @param int    $order_id The id of the order related to the transaction.
	 * @param float  $amount The amount that is being refunded. Defaults to full amount.
	 * @param string $reason The reason, no longer used.
	 *
	 * @return bool
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		$transaction_id = $order->get_meta('_lunar_transaction_id', true );
		$captured = $order->get_meta('_lunar_transaction_captured', true );
		if ( ! $order || ! $transaction_id ) {
			return false;
		}
		$data = array();
		$currency = dk_get_order_currency( $order );
		if ( ! is_null( $amount ) ) {
			$data['amount'] = convert_float_to_iso_lunar_amount( $amount, $currency );
		}

		if ( 'yes' == $captured ) {
			WC_Lunar::log( "Info: Starting to refund {$data['amount']} in {$currency}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			try {
				$result = $this->lunar_client->transactions()->refund( $transaction_id, $data );
				$this->handle_refund_result( $order, $result, $captured );
			} catch ( \Paylike\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Refund has failed!' );
				$error_message = WC_Gateway_Lunar::get_response_error( $exception->getJsonBody() );
				if ( ! $error_message ) {
					$error_message = 'There has been an problem with the refund. Refresh the order to see more details';
				}

				return new WP_Error( 'lunar_error', __( 'Error: ', 'lunar-payments-for-woocommerce' ) . $error_message );
			}
		} else {
			WC_Lunar::log( "Info: Starting to void {$data['amount']} in {$currency}" . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			try {
				$result = $this->lunar_client->transactions()->void( $transaction_id, $data );
				$this->handle_refund_result( $order, $result, $captured );
			} catch ( \Paylike\Exception\ApiException $exception ) {
				WC_Lunar::handle_exceptions( $order, $exception, 'Issue: Void has failed!' );
				$error_message = WC_Gateway_Lunar::get_response_error( $exception->getJsonBody() );
				if ( ! $error_message ) {
					$error_message = 'There has been an problem with the refund. Refresh the order to see more details';
				}

				return new WP_Error( 'lunar_error', __( 'Error: ', 'lunar-payments-for-woocommerce' ) . $error_message );
			}
		}

		return true;

	}

	/**
	 * Refund handler.
	 *
	 * @param WC_Order $order The order object related to the transaction.
	 * @param array    $transaction The transaction returned by the api wrapper.
	 * @param boolean  $captured True if the order has been captured, false otherwise.
	 *
	 * @return bool
	 */
	function handle_refund_result( $order, $transaction, $captured ) {

		if ( 1 == $transaction['successful'] ) {
			if ( 'yes' == $captured ) {
				$refunded_amount = $transaction['refundedAmount'];
			} else {
				$refunded_amount = $transaction['voidedAmount'];
			}

			$refund_message = __( 'Lunar transaction refunded.', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
				__( 'Transaction ID: ', 'lunar-payments-for-woocommerce' ) . $transaction['id'] . PHP_EOL .
				__( 'Refund amount: ', 'lunar-payments-for-woocommerce' ) . $this->real_amount( $refunded_amount, $transaction['currency'] ) . PHP_EOL .
				__( 'Transaction authorized at: ', 'lunar-payments-for-woocommerce' ) . $transaction['created'];
			$order->add_order_note( $refund_message );
			WC_Lunar::log( 'Info: Refund was successful' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );

			return true;
		} else {
			$error_message = WC_Gateway_Lunar::get_response_error( $transaction );
			$order->add_order_note(
				__( 'Unable to refund transaction!', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
				__( 'Error: ', 'lunar-payments-for-woocommerce' ) . $error_message
			);
			WC_Lunar::log( 'Issue: Refund has failed there has been an issue with the transaction.' . $error_message . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );

			return false;
		}
	}


	/**
	 *  Handle capture event.
	 *
	 * @param array    $transaction The transaction returned by the api wrapper.
	 * @param WC_Order $order The order object related to the transaction.
	 * @param int      $amount The amount captured.
	 */
	function handle_capture_result( $transaction, $order, $amount = 0 ) {
		$result = $this->parse_api_transaction_response( $transaction, $order, $amount );
		if ( is_wp_error( $result ) ) {
			WC_Lunar::log( 'Issue: Capture has failed, the result from the verification threw an wp error:' . $result->get_error_message() . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			$order->add_order_note(
				__( 'Unable to capture transaction!', 'lunar-payments-for-woocommerce' ) . PHP_EOL .
				$result->get_error_message()
			);
		} else {
			$order->add_order_note(
				$this->get_transaction_capture_details( $result )
			);
			WC_Lunar::log( 'Info: Capture was successful' . PHP_EOL . ' -- ' . __FILE__ . ' - Line:' . __LINE__ );
			$this->save_transaction_id( $result, $order );
			$order->update_meta_data( '_lunar_transaction_captured', 'yes' );
			$order->save_meta_data();
			$order->payment_complete();
		}
	}

	/**
	 * Handle return call from lunar
	 */
	public function return_handler() {
		@ob_clean();
		header( 'HTTP/1.1 200 OK' );
		try {
			if ( isset( $_REQUEST['reference'] ) && isset( $_REQUEST['signature'] ) && isset( $_REQUEST['amount'] ) ) {
				$signature = strtoupper( md5( $_REQUEST['amount'] . $_REQUEST['reference'] . $this->public_key ) );
				$order_id = absint( $_REQUEST['reference'] );
				$order = wc_get_order( $order_id );
				$transaction_id = sanitize_text_field($_POST['lunar_token']);
				if ( $signature === sanitize_text_field($_REQUEST['signature']) ) {

					if ( $order->get_total() > 0 && sanitize_text_field($_REQUEST['amount'])!=0 ) {
						$this->handle_payment( $transaction_id, $order );
					} else {
						// used for trials, and changing payment method.
						$transaction_id = isset( $_POST['lunar_token'] ) ? sanitize_text_field($_POST['lunar_token']) : '';
						if ( $transaction_id ) {
							$this->save_transaction_id( [ 'id' => $transaction_id ], $order );
						}
						$order->payment_complete();
					}
					wp_redirect( $this->get_return_url( $order ) );
					exit();
				}
			}
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			wp_redirect( get_permalink( wc_get_page_id( 'checkout' ) ) );
			exit();
		}
		wp_redirect( get_permalink( wc_get_page_id( 'cart' ) ) );
		exit();
	}




}
