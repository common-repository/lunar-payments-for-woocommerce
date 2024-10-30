<?php
if ( ! function_exists( 'get_woo_id' ) ) {
	/**
	 *
	 * Fix to prevent protected attribute access
	 *
	 * @param $object
	 *
	 * @return mixed
	 */
	function get_woo_id( $object ) {
		if ( method_exists( $object, 'get_id' ) ) {
			return $object->get_id();
		} else {
			return $object->id;
		}
	}
}
if ( ! function_exists( 'dk_get_order_currency' ) ) {
	/**
	 * @param WC_Order $order
	 *
	 * @return mixed
	 */
	function dk_get_order_currency( $order ) {
		if ( method_exists( $order, 'get_currency' ) ) {
			return $order->get_currency();
		} else {
			return $order->get_order_currency();
		}
	}
}

if ( ! function_exists( 'dk_get_product_name' ) ) {
	/**
	 * @param WC_Product $product
	 *
	 * @return mixed
	 */
	function dk_get_product_name( $product ) {
		if ( method_exists( $product, 'get_name' ) ) {
			return $product->get_name();
		} else {
			return $product->get_title();
		}
	}
}

if ( ! function_exists( 'dk_get_order_data' ) ) {
	/**
	 * @param WC_Order $order
	 *
	 * @return mixed
	 */
	function dk_get_order_data( $order, $method ) {
		if ( method_exists( $order, $method ) ) {
			return $order->$method();
		} else {
			$attribute = str_replace( 'get_', '', $method );

			return $order->$attribute;
		}
	}
}

if ( ! function_exists( 'dk_get_order_shipping_total' ) ) {
	/**
	 * @param WC_Order $order
	 *
	 * @return mixed
	 */
	function dk_get_order_shipping_total( $order ) {
		if ( method_exists( $order, 'get_shipping_total' ) ) {
			return $order->get_shipping_total();
		} else {
			return $order->get_total_shipping();
		}
	}
}
