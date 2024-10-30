<?php

if ( ! function_exists( 'dk_get_locale' ) ) {

	function dk_get_locale() {
		$locale = get_locale();
		$norwegian_compatibility = false;
		if ( defined( 'LUNAR_NORWEGIAN_BOKMAL_COMPATIBILITY' ) ) {
			$norwegian_compatibility = LUNAR_NORWEGIAN_BOKMAL_COMPATIBILITY;
		}
		if ( in_array( $locale, array( 'nb_NO' ) ) && $norwegian_compatibility ) {
			$locale = 'no_NO';
		}

		return $locale;
	}
}

if ( ! function_exists( 'dk_get_client_ip' ) ) {
	/**
	 * Retrieve client ip.
	 *
	 * @return string
	 */
	function dk_get_client_ip() {
		if ( getenv( 'HTTP_CLIENT_IP' ) ) {
			$ip_address = getenv( 'HTTP_CLIENT_IP' );
		} elseif ( getenv( 'HTTP_X_FORWARDED_FOR' ) ) {
			$ip_address = getenv( 'HTTP_X_FORWARDED_FOR' );
		} elseif ( getenv( 'HTTP_X_FORWARDED' ) ) {
			$ip_address = getenv( 'HTTP_X_FORWARDED' );
		} elseif ( getenv( 'HTTP_FORWARDED_FOR' ) ) {
			$ip_address = getenv( 'HTTP_FORWARDED_FOR' );
		} elseif ( getenv( 'HTTP_FORWARDED' ) ) {
			$ip_address = getenv( 'HTTP_FORWARDED' );
		} elseif ( getenv( 'REMOTE_ADDR' ) ) {
			$ip_address = getenv( 'REMOTE_ADDR' );
		} else {
			$ip_address = '0.0.0.0';
		}

		return $ip_address;
	}
}
