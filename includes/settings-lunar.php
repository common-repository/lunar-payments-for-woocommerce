<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings =
	array(
		'enabled'              => array(
			'title'       => __( 'Enable/Disable', 'lunar-payments-for-woocommerce' ),
			'label'       => __( 'Enable Lunar', 'lunar-payments-for-woocommerce' ),
			'type'        => 'checkbox',
			'description' => '',
			'default'     => 'no',
		),
		'title'                => array(
			'title'       => __( 'Payment method title', 'lunar-payments-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'This controls the title which the user sees during checkout.', 'lunar-payments-for-woocommerce' ),
			'default'     => __( 'Cards', 'lunar-payments-for-woocommerce' ),
			'desc_tip'    => true,
		),
		'description'          => array(
			'title'       => __( 'Payment method description', 'lunar-payments-for-woocommerce' ),
			'type'        => 'textarea',
			'description' => __( 'This controls the description which the user sees during checkout.', 'lunar-payments-for-woocommerce' ),
			'default'     => __( 'Secure payment with credit card via &copy; <a href="https://lunar.app" target="_blank">Lunar</a>', 'lunar-payments-for-woocommerce' ),
			'desc_tip'    => true,
		),
		'popup_title'          => array(
			'title'       => __( 'Payment popup title', 'lunar-payments-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'The text shown in the popup where the customer inserts the card details.', 'lunar-payments-for-woocommerce' ),
			'default'     => get_bloginfo( 'name' ),
			'desc_tip'    => true,
		),
		'secret_key'           => array(
			'title'       => __( 'App key', 'lunar-payments-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'Get it from your Lunar dashboard', 'lunar-payments-for-woocommerce' ),
			'default'     => '',
			'desc_tip'    => true,
		),
		'public_key'           => array(
			'title'       => __( 'Public key', 'lunar-payments-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'Get it from your Lunar dashboard', 'lunar-payments-for-woocommerce' ),
			'default'     => '',
			'desc_tip'    => true,
		),
		'checkout_mode'        => array(
			'title'    => __( 'Checkout mode', 'lunar-payments-for-woocommerce' ),
			'type'     => 'select',
			'options'  => array(
				'after_order'  => __( 'Redirect to payment page after order created', 'lunar-payments-for-woocommerce' ),
				'before_order' => __( 'Payment before order created', 'lunar-payments-for-woocommerce' ),
			),
			'default'  => 'after_order',
			'desc_tip' => true,
		),
		'capture'              => array(
			'title'       => __( 'Capture mode', 'lunar-payments-for-woocommerce' ),
			'type'        => 'select',
			'options'     => array(
				'instant' => __( 'Instant', 'lunar-payments-for-woocommerce' ),
				'delayed' => __( 'Delayed', 'lunar-payments-for-woocommerce' ),
			),
			'description' => __( 'If you deliver your product instantly (e.g. a digital product), choose Instant mode. If not, use Delayed. In Delayed mode, you can capture the order when moving from on hold to complete or from on hold to processing.', 'lunar-payments-for-woocommerce' ),
			'default'     => 'delayed',
			'desc_tip'    => true,
		),
		'store_payment_method' => array(
			'title'       => __( 'Store Payment Method', 'lunar-payments-for-woocommerce' ),
			'label'       => __( 'Allow users to reuse their card via Lunar', 'lunar-payments-for-woocommerce' ),
			'type'        => 'checkbox',
			'description' => __( 'When this is checked users are allowed to save their payment to be used as a source for future payments without the need to go trough the payment process again', 'lunar-payments-for-woocommerce' ),
			'default'     => 'no', // has to be yes/no to work
			'desc_tip'    => true,
		),
		'card_types'           => array(
			'title'    => __( 'Accepted Cards', 'lunar-payments-for-woocommerce' ),
			'type'     => 'multiselect',
			'class'    => 'chosen_select',
			'css'      => 'width: 350px;',
			'desc_tip' => __( 'Select the card types to accept.', 'lunar-payments-for-woocommerce' ),
			'options'  => array(
				'mastercard'   => 'MasterCard',
				'maestro'      => 'Maestro',
				'visa'         => 'Visa',
				'visaelectron' => 'Visa Electron',
			),
			'default'  => array( 'mastercard', 'maestro', 'visa', 'visaelectron' ),
		),
		'use_beta_sdk'         => array(
			'title'       => __( 'Use Beta', 'lunar-payments-for-woocommerce' ),
			'label'       => __( 'Use the Beta SDK(only use if instructed)', 'lunar-payments-for-woocommerce' ),
			'type'        => 'checkbox',
			'description' => __( 'When this is checked the beta version of the sdk is being used', 'lunar-payments-for-woocommerce' ),
			'default'     => 'no', // has to be yes/no to work
			'desc_tip'    => true,
		),
	);

if ( WC_LUNAR_BETA_SDK === WC_LUNAR_CURRENT_SDK ) {
	unset( $settings['use_beta_sdk'] );
}

return apply_filters( 'wc_lunar_settings', $settings );
