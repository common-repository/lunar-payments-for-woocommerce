jQuery( function( $ ) {
	/**
	 * Object to handle Lunar Mobilepay payment.
	 */
	var wc_lunar_mobilepay = {

			lastIframeId: 0,
			paymentButtonId: null,

			isMobilePayChosen: function() {
				return ( $( '#payment_method_lunar_mobilepay' ).is( ':checked' ) || $( '#lunar-mobilepay-payment-button' ).length > 0 );
			},
			isMobilePayCallNeeded: function() {

				// Don't affect submission if modal is not needed.
				if ( ! wc_lunar_mobilepay.isMobilePayChosen() ) {
					return false;
				}

				var token = wc_lunar_mobilepay.form.find( 'input.lunar_mobilepay_token' ).length;


				// If this is a lunar submission (after modal) and token exists, allow submit.
				if ( wc_lunar_mobilepay.lunar_mobilepay_submit && token ) {
					if ( wc_lunar_mobilepay.form.find( 'input.lunar_mobilepay_token' ).val() !== '' )
						return false;
				}

				// Don't send payment request if required fields are not complete
				if ( $( 'input#legal' ).length === 1 && $( 'input#legal:checked' ).length === 0 ) {
					return false;
				}

				if ( $( 'input#data-download' ).length === 1 && $( 'input#data-download:checked' ).length === 0 ) {
					return false;
				}

				if ( $( 'input#terms' ).length === 1 && $( 'input#terms:checked' ).length === 0 ) {
					return false;
				}

				return true;
			},
			onSubmit: function( e ) {
				// Don't affect submission if modal is not needed.
				if ( ! wc_lunar_mobilepay.isMobilePayCallNeeded() ) {
					return true;
				}

				if ( ! wc_lunar_mobilepay.before_order ) {
					wc_lunar_mobilepay.initiatePayment( e );
					return true;
				}
				// Get checkout form data
				var formData = wc_lunar_mobilepay.form.serializeArray();

				// Modify form to make sure its just a validation check
				formData.push( { name: "woocommerce_checkout_update_totals", value: true } );

				// Show loading indicator
				wc_lunar_mobilepay.form.addClass( 'processing' );
				wc_lunar_mobilepay.block();

				// Make request to validate checkout form
				$.ajax( {
					type: 'POST',
					url: wc_checkout_params.checkout_url,
					data: $.param( formData ),
					dataType: 'json',
					success: function( result ) {
						if ( result.messages ) {
							wc_lunar_mobilepay.submit_error( result.messages );
							return false;
						} else {
							wc_lunar_mobilepay.form.removeClass( 'processing' ).unblock();
							wc_lunar_mobilepay.initiatePayment( e );
							return true;
						}
					},
					error: function( jqXHR, textStatus, errorThrown ) {
						wc_lunar_mobilepay.submit_error( '<div class="woocommerce-error">' + errorThrown + '</div>' );
					}
				} );

				return false;
			},


			/**
			 * Initialize e handlers and UI state.
			 */
			init: function( form ) {
				var $paymentButton = $( '#lunar-mobilepay-payment-button' )
				this.paymentButtonId = '#lunar-mobilepay-payment-button';
				if ( $paymentButton.length > 0 ) {
					this.before_order = false;
				} else {
					$paymentButton = $( '#place_order' );
					this.paymentButtonId = '#place_order';
					this.before_order = true;
				}

				if ( ! wc_lunar_mobilepay_params.before_order && this.before_order ) {
					// we will handle the payment after the checkout
					return;
				}


				if ( ! form[ 0 ] ) {
					return;
				}
				this.form = form;
				this.iframeChallenges = [];
				this.hints = [];
				this.lunar_mobilepay_submit = false;

				this.handleIframeMessage();
				$( this.form )
					.on( 'click', this.paymentButtonId, this.onSubmit )

					// WooCommerce lets us return a false on checkout_place_order_{gateway} to keep the form from submitting
					.on( 'submit checkout_place_order_lunar_mobilepay' );

			},

			handleIframeMessage: function() {
				window.addEventListener( 'message', function( e ) {
					for ( var key in wc_lunar_mobilepay.iframeChallenges ) {
						var challenge = wc_lunar_mobilepay.iframeChallenges[ key ];
						if ( challenge.iframe[ 0 ].contentWindow !== e.source ) continue
						if ( typeof e.data !== 'object' || e.data === null || ! e.data.hints ) {
							continue
						}
						challenge.resolve( e.data )
						wc_lunar_mobilepay.resetIframe(challenge);
					}
				} )
			},
			initiatePaymentServerCall: function( args, success ) {
				args.hints = this.hints;
				if(this.paymentButtonId === '#lunar-mobilepay-payment-button') {
					wc_lunar_mobilepay.block();
				}
				var t = this;
				$.ajax( {
					type: "POST",
					dataType: "json",
					url: wc_lunar_mobilepay_params.ajax_url,
					data: {
						action: 'lunar_mobilepay_initiate_payment',
						args: args,
					},
					success: function( data ) {
						success( data )
					},
					error: function( jqXHR, textStatus, errorThrown ) {
						wc_lunar_mobilepay.submit_error( '<div class="woocommerce-error">' + errorThrown + '</div>' );
					},
					always: function() {
						if(t.paymentButtonId === '#lunar-mobilepay-payment-button') {
							wc_lunar_mobilepay.block();
						}
					}
				} );
			},
			initiatePayment: function( e, loading ) {
				if ( ! this.isMobilePayCallNeeded() ) {
					return true;
				}
				if ( e ) {
					e.preventDefault();
				}

				// Capture submit and open lunar modal
				var $form = this.form,
					$lunar_mobilepay_payment = $( '#lunar-mobilepay-payment-data' ),
					token = $form.find( 'input.lunar_token' );

				token.val( '' );

				var name = wc_lunar_checkout.getName( $lunar_mobilepay_payment );
				var phoneNo = wc_lunar_checkout.getPhoneNo( $lunar_mobilepay_payment );
				var address = wc_lunar_checkout.getAddress( $lunar_mobilepay_payment );
				var $billing_email = $( "[name='billing_email']" );
				var args = {

					amount: {
						currency: $lunar_mobilepay_payment.data( 'currency' ),
						exponent: $lunar_mobilepay_payment.data( 'decimals' ),
						value: $lunar_mobilepay_payment.data( 'amount' ),
					},
					custom: {
						email: $billing_email.val(),
						orderId: $lunar_mobilepay_payment.data( 'order_id' ),
						products: [ wc_lunar_mobilepay_params.products
						],
						customer: {
							name: name,
							email: $billing_email.val(),
							phoneNo: phoneNo,
							address: address,
							IP: wc_lunar_mobilepay_params.customer_IP
						},
						platform: {
							name: 'WordPress',
							version: wc_lunar_mobilepay_params.platform_version
						},
						ecommerce: {
							name: 'WooCommerce',
							version: wc_lunar_mobilepay_params.ecommerce_version
						},
						lunarPluginVersion: wc_lunar_mobilepay_params.version
					}
				};

				this.initiatePaymentServerCall( args,
					function( response ) {
						var $form = wc_lunar_mobilepay.form;
						if(response.error){
							wc_lunar_mobilepay.submit_error( response.error );
							return false;
						}
						if ( response.data.authorizationId ) {
							$form.find( 'input.lunar_mobilepay_token' ).remove();
							$form.append( '<input type="hidden" class="lunar_mobilepay_token" name="lunar_token" value="' + response.data.authorizationId + '"/>' );
							wc_lunar_mobilepay.lunar_mobilepay_submit = true;
							$form.submit();
							return false;
						}

						wc_lunar_mobilepay.hints = response.data.hints;
						if ( response.data.type === 'iframe' || response.data.type === 'background-iframe' ) {
							wc_lunar_mobilepay.showMobilePayIframe( response.data );
							return false;
						}

						if ( response.data.type === 'redirect' ) {
							wc_lunar_mobilepay.hints = response.data.hints;
							location.href = response.data.url;
							return false;
						}


					} );

				return false;

			},
			showMobilePayIframe: function( response ) {
				var width = response.width ?? 1;
				var height = response.height ?? 1;
				var method = response.method ?? 'GET';
				var action = response.action ?? undefined;
				var fields = response.fields ?? [];
				var name = 'challenge-iframe';
				var display = response.type === 'background-iframe' ? 'none' : 'block';
				var src = method === 'GET' ? response.url : undefined;
				var style = 'border: none; width: ' + width + 'px;height: ' + height + 'px;maxWidth:100%' + ';display:' + display + ';';
				var $iframe = $( '<iframe name="' + name + '" class="lunar-mobilepay-iframe" src="' + src + '" style="' + style + '" frameborder="0" allowfullscreen></iframe>' );
				var $cancel = $( '<button class="lunar-mobilepay-cancel-button" style="margin:5px 0;" type="button">Cancel</button>' );
				var iframeChallenge = {
					iframe: $iframe, resolve: function( data ) {
						wc_lunar_mobilepay.hints = wc_lunar_mobilepay.hints.concat( data.hints );
						wc_lunar_mobilepay.initiatePayment();
					},
					cancelButton: $cancel,
					timeout: response.timeout ?? 1000 * 60 * 35,
					id: ++wc_lunar_mobilepay.lastIframeId,
				}
				this.iframeChallenges.push( iframeChallenge );
				this.disablePaymentButton();
				wc_lunar_mobilepay.timer = setTimeout( function() {
					wc_lunar_mobilepay.resetIframe(iframeChallenge );
					// on timeout we try again
					wc_lunar_mobilepay.initiatePayment();
				}, iframeChallenge.timeout )
				$( '#lunar-mobilepay-payment-data' ).append( $iframe );
				if ( display === 'block' ) {
					$( '#lunar-mobilepay-payment-data' ).append( $cancel );
					$cancel.on( 'click', function( e ) {
						e.preventDefault();
						wc_lunar_mobilepay.resetIframe( iframeChallenge );
					} );
				}

				if ( method === 'POST' ) {
					wc_lunar_mobilepay.handleIframePost( name, action, fields );
				}

			},
			handleIframePost: function( name, action, fields ) {
				var $form = $( '<form method="POST" action="' + action + '" target="' + name + '"></form>' );
				Object.entries( fields ).map( function( field ) {
					$form.append( '<input type="hidden" name="' + field.name + '" value="' + field.value + '"/>' );
				} );
				$( document.body ).append( $form );
				$form.submit()
				$form.remove();
			},
			resetIframe: function( iframeChallenge ) {
				clearTimeout( wc_lunar_mobilepay.timer );
				wc_lunar_mobilepay.removeIframeChallenge( iframeChallenge );
			},
			removeIframeChallenge: function( iframeChallenge ) {
				wc_lunar_mobilepay.iframeChallenges = wc_lunar_mobilepay.iframeChallenges.filter( function( object ) {
					object.id !== iframeChallenge.id
				} );
				iframeChallenge.iframe.remove();
				iframeChallenge.cancelButton.remove();
				wc_lunar_mobilepay.enablePaymentButton();
			},
			submit_error: function( error_message ) {
				$( '.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message' ).remove();
				this.form.prepend( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + error_message + '</div>' ); // eslint-disable-line max-len
				this.form.removeClass( 'processing' ).unblock();
				this.form.find( '.input-text, select, input:checkbox' ).trigger( 'validate' ).blur();
				wc_lunar_checkout.scroll_to_notices();
				$( document.body ).trigger( 'checkout_error', [ error_message ] );
			},
			block: function() {
				this.form.block( {
					message: null,
					overlayCSS: {
						background: '#fff',
						opacity: 0.6
					}
				} );
			},
			unblock: function() {
				this.form.unblock();
			},

			disablePaymentButton: function() {
				$( this.paymentButtonId ).prop( 'disabled', true );
			},
			enablePaymentButton: function() {
				$( this.paymentButtonId ).prop( 'disabled', false );
			},

		}
	;

	wc_lunar_mobilepay.init( $( "form.checkout, form#order_review, form#add_payment_method, form#mobilepay_complete_order" ) );

	if($('#lunar-mobilepay-payment-button').length > 0){
		wc_lunar_mobilepay.initiatePayment();
	}

} )
;
