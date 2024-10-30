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
			'default'     => __( 'Mobilepay', 'lunar-payments-for-woocommerce' ),
			'desc_tip'    => true,
		),
		'store_title'          => array(
			'title'       => __( 'Store title', 'lunar-payments-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'The text shown on the payment page where the customer pays.', 'lunar-payments-for-woocommerce' ),
			'default'     => get_bloginfo( 'name' ),
			'desc_tip'    => true,
		),
		'description'          => array(
			'title'       => __( 'Payment method description', 'lunar-payments-for-woocommerce' ),
			'type'        => 'textarea',
			'description' => __( 'This controls the description which the user sees during checkout.', 'lunar-payments-for-woocommerce' ),
			'default'     => __( 'Secure payment with credit card via &copy; <a href="https://lunar.app" target="_blank">Lunar</a>', 'lunar-payments-for-woocommerce' ),
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
		'logo' => array(
			'title'       => __( 'Logo URL', 'lunar-payments-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'URL must start with "https", the image must be in PNG or JPG format, and 250x250 px', 'lunar-payments-for-woocommerce' ),
			'default'     => '',
			'desc_tip'    => true,
		),
		'mobilepay_configuration_id' => array(
			'title'       => __( 'MobilePay config id', 'lunar-payments-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'Email onlinepayments@lunar.app to get it', 'lunar-payments-for-woocommerce' ),
			'default'     => '',
			'desc_tip'    => true,
		),

		'mobilepay_logo' => array(
			'title'       => __( 'Mobilepay Logo URL', 'lunar-payments-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'URL must start with "https", the image must be in PNG or JPG format, and 250x250 px. If not set, the previous logo field will be used', 'lunar-payments-for-woocommerce' ),
			'default'     => '',
			'desc_tip'    => true,
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
		)
	);

if ( WC_LUNAR_BETA_SDK === WC_LUNAR_CURRENT_SDK ) {
	unset( $settings['use_beta_sdk'] );
}

return apply_filters( 'wc_lunar_settings', $settings );
