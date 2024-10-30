jQuery( function( $ ) {
	/**
	 * Object to handle Lunar payment forms.
	 */
	var wc_lunar_form = {

			/**
			 * Initialize e handlers and UI state.
			 */
			init: function( form ) {
				this.form = form;
				this.lunar_submit = false;

				$( this.form )
					.on( 'click', '#place_order', this.onSubmit )

					// WooCommerce lets us return a false on checkout_place_order_{gateway} to keep the form from submitting
					.on( 'submit checkout_place_order_lunar' );
			},

			isLunarChosen: function() {
				return $( '#payment_method_lunar' ).is( ':checked' );
			},

			isAddPaymentMethod: function() {
				return $( '#add_payment_method' ).length === 1;
			},

			isCardSavedForFuturePurchases: function() {
				return $( '#wc-lunar-new-payment-method' ).is( ':checked' );
			},

			isManualPaymentMethod: function() {
				return $( 'body.woocommerce-order-pay' ).length === 1;
			},

			isLunarModalNeeded: function() {

				// Don't affect submission if modal is not needed.
				if ( ! wc_lunar_form.isLunarChosen() ) {
					return false;
				}

				var token = wc_lunar_form.form.find( 'input.lunar_token' ).length,
					savedToken = wc_lunar_form.form.find( 'input#wc-lunar-payment-token-new' ).length,
					card = wc_lunar_form.form.find( 'input.lunar_card_id' ).length,
					$required_inputs;

				// token is used
				if ( savedToken ) {
					if ( ! wc_lunar_form.form.find( 'input#wc-lunar-payment-token-new' ).is( ':checked' ) )
						if ( $( '.wc-saved-payment-methods' ).length > 0 ) {
							if ( $( '.wc-saved-payment-methods' ).data( 'count' ) > 0 ) {
								if ( wc_lunar_form.form.find( 'input[name="wc-lunar-payment-token"]:checked' ).length > 0 )
									return false;
							}
						}
				}

				// If this is a lunar submission (after modal) and token exists, allow submit.
				if ( wc_lunar_form.lunar_submit && token ) {
					if ( wc_lunar_form.form.find( 'input.lunar_token' ).val() !== '' )
						return false;
				}

				// If this is a lunar submission (after modal) and card exists, allow submit.
				if ( wc_lunar_form.lunar_submit && card ) {
					if ( wc_lunar_form.form.find( 'input.lunar_card_id' ).val() !== '' )
						return false;
				}

				// Don't open modal if required fields are not complete
				if ( $( 'input#legal' ).length === 1 && $( 'input#legal:checked' ).length === 0 ) {
					return false;
				}

				if ( $( 'input#data-download' ).length === 1 && $( 'input#data-download:checked' ).length === 0 ) {
					return false;
				}

				if ( $( 'input#terms' ).length === 1 && $( 'input#terms:checked' ).length === 0 ) {
					return false;
				}


				if ( ! wc_lunar_form.validateShipmondo() ) return false;

				return true;
			},

			block: function() {
				wc_lunar_form.form.block( {
					message: null,
					overlayCSS: {
						background: '#fff',
						opacity: 0.6
					}
				} );
			},

			unblock: function() {
				wc_lunar_form.form.unblock();
			},
			logTransactionResponsePopup: function( err, res ) {
				$.ajax( {
					type: "POST",
					dataType: "json",
					url: wc_lunar_params.ajax_url,
					data: {
						action: 'lunar_log_transaction_data',
						err: err,
						res: res
					}
				} );
			},
			onSubmit: function( e ) {

				// Don't affect submission if modal is not needed.
				if ( ! wc_lunar_form.isLunarModalNeeded() ) {
					return true;
				}
				// on add card page skip validation
				if ( wc_lunar_form.isAddPaymentMethod() || wc_lunar_form.isManualPaymentMethod() ) {
					return wc_lunar_form.showPopup( e );
				}

				// Get checkout form data
				var formData = wc_lunar_form.form.serializeArray();

				// Modify form to make sure its just a validation check
				formData.push( { name: "woocommerce_checkout_update_totals", value: true } );

				// Show loading indicator
				wc_lunar_form.form.addClass( 'processing' );
				wc_lunar_form.block();

				// Make request to validate checkout form
				$.ajax( {
					type: 'POST',
					url: wc_checkout_params.checkout_url,
					data: $.param( formData ),
					dataType: 'json',
					success: function( result ) {
						if ( result.messages ) {
							wc_lunar_form.submit_error( result.messages );
							return false;
						} else {
							wc_lunar_form.form.removeClass( 'processing' ).unblock();
							wc_lunar_form.showPopup( e );
							return true;
						}
					},
					error: function( jqXHR, textStatus, errorThrown ) {
						wc_lunar_form.submit_error( '<div class="woocommerce-error">' + errorThrown + '</div>' );
					}
				} );

				return false;
			},
			showPopup: function( e ) {
				if ( wc_lunar_form.isLunarModalNeeded() ) {
					e.preventDefault();

					// Capture submit and open lunar modal
					var $form = wc_lunar_form.form,
						$lunar_payment = $( '#lunar-payment-data' ),
						token = $form.find( 'input.lunar_token' );

					token.val( '' );

					var name = wc_lunar_checkout.getName( $lunar_payment );
					var phoneNo = wc_lunar_checkout.getPhoneNo( $lunar_payment );
					var address = wc_lunar_checkout.getAddress( $lunar_payment );
					var lunar = Paylike( { key: wc_lunar_params.key } );
					var $billing_email = $( "[name='billing_email']" );
					var args = {
						title: $lunar_payment.data( 'title' ),
						test: !! $lunar_payment.data( 'test' ),
						amount: {
							currency: $lunar_payment.data( 'currency' ),
							exponent: $lunar_payment.data( 'decimals' ),
							value: $lunar_payment.data( 'amount' ),
						},
						locale: $lunar_payment.data( 'locale' ),
						custom: {
							email: $billing_email.val(),
							orderId: $lunar_payment.data( 'order_id' ),
							products: [ wc_lunar_params.products
							],
							customer: {
								name: name,
								email: $billing_email.val(),
								phoneNo: phoneNo,
								address: address,
								IP: wc_lunar_params.customer_IP
							},
							platform: {
								name: 'WordPress',
								version: wc_lunar_params.platform_version
							},
							ecommerce: {
								name: 'WooCommerce',
								version: wc_lunar_params.ecommerce_version
							},
							lunarPluginVersion: wc_lunar_params.version
						}
					};

					if ( wc_lunar_params.plan_arguments ) {
						for ( var attrname in wc_lunar_params.plan_arguments ) {
							args[ attrname ] = wc_lunar_params.plan_arguments[ attrname ];
						}

						if(args.plan && args.plan.repeat && args.plan.repeat.first){
							args.plan.repeat.first = new Date(args.plan.repeat.first);
						}
						if(args.plan) {
							args.plan = [ args.plan ];
						}
					}
					// used for cases like trial,
					// change payment method
					// see @https://github.com/lunar/sdk#popup-to-save-tokenize-a-card-for-later-use
					if ( args.amount.value === 0 ) {
						delete args.amount;
					}


					// if card is reused mark unplanned for customer but also for merchant since merchant will be able to reuse for subscriptions
					if ( this.isCardSavedForFuturePurchases() || this.isAddPaymentMethod() ) {
						args[ 'unplanned' ] = {
							customer: true,
							merchant: true
						}
					}
					console.log(args);

					lunar.pay( args,
						function( err, res ) {
							// log this for debugging purposes
							wc_lunar_form.logTransactionResponsePopup( err, res );
							if ( err ) {
								return err
							}

							if ( res.transaction ) {
								var trxid = res.transaction.id;
								$form.find( 'input.lunar_token' ).remove();
								$lunar_payment.append( '<input type="hidden" class="lunar_token" name="lunar_token" value="' + trxid + '"/>' );
							} else {
								var cardid = res.card.id;
								$form.find( 'input.lunar_card_id' ).remove();
								$form.append( '<input type="hidden" class="lunar_card_id" name="lunar_card_id" value="' + cardid + '"/>' );
							}

							wc_lunar_form.lunar_submit = true;
							$form.submit();
						}
					);

					return false;
				}
			},
			validateShipmondo: function() {
				var selectedShipping = $( '#shipping_method input:checked' ).val();
				if ( ! selectedShipping ) {
					return true;
				}
				// Check if Shipmondo (Pakkelabels.dk) shipping option is selected
				if ( selectedShipping.indexOf( "pakkelabels" ) >= 0 ) {
					// Business shipping, but no business name
					var shipmondoBusinessTypes = [
						"pakkelabels_shipping_gls_business",
						"pakkelabels_shipping_postnord_business",
						"pakkelabels_shipping_bring_business"
					];

					if ( shipmondoBusinessTypes.includes( $( '#shipping_method input:checked' ).val() ) && $( "#billing_company" ).val() == '' ) {
						return false;
					}

					// Pickup point shipping, but no pickup point selected
					var shipmondoPickupPointTypes = [
						"pakkelabels_shipping_gls",
						"pakkelabels_shipping_pdk",
						"pakkelabels_shipping_dao",
						"pakkelabels_shipping_bring"
					];

					// Check if pickup point shipping is selected
					if ( shipmondoPickupPointTypes.includes( $( '#shipping_method input:checked' ).val() ) ) {
						// Check if a shopID exists
						if ( $( "#hidden_chosen_shop input[name='shop_ID']" ).val() == '' ) {
							return false;
						}
					}
				}
				return true;

			},
			submit_error:

				function( error_message ) {
					$( '.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message' ).remove();
					wc_lunar_form.form.prepend( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + error_message + '</div>' ); // eslint-disable-line max-len
					wc_lunar_form.form.removeClass( 'processing' ).unblock();
					wc_lunar_form.form.find( '.input-text, select, input:checkbox' ).trigger( 'validate' ).blur();
					wc_lunar_checkout.scroll_to_notices();
					$( document.body ).trigger( 'checkout_error', [ error_message ] );
				}

		}
	;

	wc_lunar_form.init( $( "form.checkout, form#order_review, form#add_payment_method" ) );
} )
;
