jQuery( function( $ ) {
	/**
	 * Util functions
	 */
	window.wc_lunar_checkout = {
			getName: function( $lunar_payment ) {
				var $name = $( "[name='billing_first_name']" );
				var name = '';
				if ( $name.length > 0 ) {
					name = $name.val() + ' ' + $( "[name='billing_last_name']" ).val();
				} else {
					name = $lunar_payment.data( 'name' );
				}
				return this.escapeQoutes( name );
			},
			getAddress: function( $lunar_payment ) {
				var $address = $( "[name='billing_address_1']" );
				var address = '';
				if ( $address.length > 0 ) {
					address = $address.val()
					var $address_2 = $( "[name='billing_address_2']" );
					if ( $address_2.length > 0 ) {
						address += ' ' + $address_2.val();
					}
					var $billing_city = $( "[name='billing_city']" );
					if ( $billing_city.length > 0 ) {
						address += ' ' + $billing_city.val();
					}
					var $billing_state = $( "[name='billing_state']" );
					if ( $billing_state.length > 0 ) {
						address += ' ' + $billing_state.find( ':selected' ).text();
					}
					var $billing_postcode = $( "[name='billing_postcode']" )
					if ( $billing_postcode.length > 0 ) {
						address += ' ' + $billing_postcode.val();
					}
				} else {
					address = $lunar_payment.data( 'address' );
				}
				return this.escapeQoutes( address );
			},
			getPhoneNo: function( $lunar_payment ) {

				var $phone = $( "[name='billing_phone']" );
				var phone = '';
				if ( $phone.length > 0 ) {
					phone = $phone.val()
				} else {
					phone = $lunar_payment.data( 'phone' );
				}
				return this.escapeQoutes( phone );
			},

			escapeQoutes: function( str ) {
				return str.toString().replace( /"/g, '\\"' );
			},

			scroll_to_notices: function() {
				var scrollElement = $( '.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout' );
				if ( ! scrollElement.length ) {
					scrollElement = $( '.form.checkout' );
				}
				$.scroll_to_notices( scrollElement );
			}
			,
		}
	;


} )
;
