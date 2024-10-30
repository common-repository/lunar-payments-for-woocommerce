<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait LunarGlobalTrait{
	public function get_products_for_custom_parameter() {
		$products = array();

		$pf = new WC_Product_Factory();
		$order = null;
		if ( ! isset( $_GET['pay_for_order'] ) ) {
			$items = WC()->cart->get_cart();
		} else {
			$order_id = wc_get_order_id_by_order_key( urldecode( $_GET['key'] ) );
			$order = wc_get_order( $order_id );
			$items = $order->get_items();
		}

		$products = array();
		$i = 0;
		foreach ( $items as $item => $values ) {

			if ( $values['variation_id'] ) {
				$_product = $pf->get_product( $values['variation_id'] );
			} else {
				$_product = $pf->get_product( $values['product_id'] );
			}
			$product = array(
				'ID'       => $_product->get_id(),
				'name'     => dk_get_product_name( $_product ),
				'quantity' => isset( $values['quantity'] ) ? $values['quantity'] : $values['qty'],
			);
			$products[] = $product;
			$i ++;
			// custom popup allows at most 100 keys in all. Custom has 15 keys and each product has 3+1. 85/3 is 20
			if ( $i >= 20 ) {
				break;
			}
		}
		return $products;
	}





	/**
	 * Get transaction signature
	 *
	 * @param $order_id
	 *
	 * @return string
	 */
	function get_signature( $order_id ) {
		return strtoupper( md5( $this->get_order_total() . $order_id . $this->public_key ) );
	}

	protected function get_order_amount( $order ) {
		return $order->get_total() - $order->get_total_refunded();
	}

	/**
	 * Store the transaction id.
	 *
	 * @param array    $transaction The transaction returned by the api wrapper.
	 * @param WC_Order $order The order object related to the transaction.
	 */
	protected function save_transaction_id( $transaction, $order ) {
		$order->update_meta_data( '_transaction_id', $transaction['id'] );
		$order->update_meta_data( '_lunar_transaction_id', $transaction['id'] );
		$order->save_meta_data();
	}


}
