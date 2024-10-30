<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Lunar_MobilePay_Challenges {

	public $args;

	public $before_order = false;

	public $http = true;

	public $referer = '';

	public function __construct( $args, $http = true, $referer = '' ) {
		$this->args = $args;
		$this->order_id = $args['custom']['orderId'];
		$this->http = $http;
		$this->referer = $referer;
	}

	public function handle( $before_order = false ) {
		$this->before_order = $before_order;

		$this->getHintsFromOrder();

		$this->setArgs();

		return $this->mobilePayPayment();
	}

	public function get_setting( $name ) {
		$options = get_option( 'woocommerce_lunar_mobilepay_settings' );
		if ( isset( $options[ $name ] ) ) {
			return $options[ $name ];
		}

		return null;
	}

	private function mobilePayPayment() {
		$response = $this->request( '/payments' );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();

			return $this->error( $error_message );
		}

		$data = json_decode( $response['body'], true );

		if ( isset( $data['authorizationId'] ) ) {
			return $this->success( $data );
		}


		if ( ! isset( $data['challenges'] ) ) {
			return $this->error( __( 'Payment failed.' ) );
		}

		$response = $this->handleFirstChallenge( $data['challenges'] );

		if ( ! $response ) {
			return $this->mobilePayPayment();
		}

		$response['hints'] = $this->args['hints'];

		return $this->success( $response );

	}

	private function error( $message ) {
		if(!$this->http) {
			return [
				'error' => $message,
			];
		}
		status_header( 400 );
		echo json_encode( [
			'error' => $message,
		] );
		wp_die();
	}

	private function success( $data ) {
		if(!$this->http) {
			return [
				'data' => $data,
			];
		}
		status_header( 200 );
		echo json_encode( [
			'data' => $data,
		] );
		wp_die();
	}


	/**
	 * @return string
	 * Get the stored secret key depending on the type of payment sent.
	 */
	private function get_mobilepay_secret_key() {
		$options = get_option( 'woocommerce_lunar_mobilepay_settings' );

		return $options['secret_key'];
	}

	private function get_mobilepay_public_key() {
		$options = get_option( 'woocommerce_lunar_mobilepay_settings' );

		return $options['public_key'];
	}

	protected function handleFirstChallenge( $challenges ) {
		$challenge = $challenges[0]; // we prioritize the first one always

		if ( count( $challenges ) > 1 ) {
			if ( $this->before_order ) {
				$challenge = $this->searchForChallenge( $challenges, 'iframe' );
			} else {
				$challenge = $this->searchForChallenge( $challenges, 'redirect' );
			}
			if ( ! $challenge ) {
				$challenge = $challenges[0];
			}
		}

		$response = $this->request( $challenge['path'] );


		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();

			return $this->error( $error_message );
		}

		$data = json_decode( $response['body'], true );
		if ( isset( $data['code'] ) && isset( $data['message'] ) ) {
			return $this->error( $data['message'] );
		}

		if ( ! isset( $data['hints'] ) && isset( $data['notBefore'] ) ) {
			$notBefore = DateTime::createFromFormat('Y-m-d\TH:i:s+', $data['notBefore']);
			$now = new DateTime();
			$timeDiff = ($notBefore->getTimestamp() - $now->getTimestamp()) + 1; // add 1 second to account for miliseconds loss
			if ( $timeDiff > 0 ) {
				sleep( $timeDiff );
			}

			return $this->handleFirstChallenge( $challenges, $response );

		}

		$this->args['hints'] = array_merge( $this->args['hints'], $data['hints'] );
		$this->saveHintsOnOrder();

		switch ( $challenge['type'] ) {
			case 'fetch':
			case 'poll':
				return [];
				break;
			case 'redirect':
				$data['type'] = $challenge['type'];
				// store hints for this order for 30 minutes


				return $data;
				break;
			case 'iframe':
			case 'background-iframe':
				$data['type'] = $challenge['type'];

				return $data;
				break;
			default:
				return $this->error( 'Unknown challenge type: ' . $challenge['type'] );
		}


		return $response;
	}


	protected function searchForChallenge( $challenges, $type ) {
		WC_Lunar::log(print_r($challenges,true));
		foreach ( $challenges as $challenge ) {
			if ( $challenge['type'] === $type ) {
				return $challenge;
			}
		}

		return false;
	}

	/**
	 * @param $path
	 *
	 * @return mixed
	 */
	protected function request( $path ) {
		WC_Lunar::log("Calling $path with hints: " . print_r($this->args['hints'], true));
		$response = wp_remote_post( 'https://b.paylike.io' . $path, array(
				'method'      => 'POST',
				'timeout'     => 45,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type'   => "application/json",
					'Accept-Version' => 4
				),
				'body'        => json_encode( $this->args ),
				'cookies'     => array()
			)
		);
		WC_Lunar::log("Response: " . print_r($response['body'], true));

		return $response;
	}

	/**
	 * @return void
	 */
	private function setArgs(): void {
		$this->args['integration'] = [
			'key' => $this->get_mobilepay_public_key(),
		];


		if ( isset( $_COOKIE['lunar_mobilepay_testmode'] ) ) {
			$this->args['test'] = new stdClass();
		}

		$this->args['mobilepay'] = [
			'configurationId' => $this->get_setting( 'mobilepay_configuration_id' ),
			'logo'            => $this->get_setting( 'logo' ),
		];

		if($this->referer){
			$return_url = $this->referer;
		}else {
			$return_url = wp_get_referer();
		}
		if ( $return_url && ! $this->before_order ) {
			$this->args['mobilepay']['returnUrl'] = $return_url;
		}

		if ( ! isset( $this->args['hints'] ) ) {
			$this->args['hints'] = [];
		}

		$this->args['amount']['exponent'] = (int) $this->args['amount']['exponent'];
	}

	private function getHintsFromOrder() {
		// we use meta because transients are not always available
		if ( ! $this->order_id || $this->order_id === 'Could not be determined at this point' ) {
			return false;
		}
		$order = wc_get_order( $this->order_id );
		$order_hints = $order->get_meta('_lunar_mobilepay_hints', true );


		if ( $order_hints ) {
			$this->args['hints'] = $order_hints;
		}
	}

	private function saveHintsOnOrder() {
		if ( ! $this->order_id || $this->order_id === 'Could not be determined at this point' ) {
			return false;
		}
		WC_Lunar::log("Storing hints: " . print_r($this->args['hints'], true));
		$order = wc_get_order( $this->order_id );
		$order->update_meta_data('_lunar_mobilepay_hints', $this->args['hints'] );
		$order->save_meta_data();
	}


}
