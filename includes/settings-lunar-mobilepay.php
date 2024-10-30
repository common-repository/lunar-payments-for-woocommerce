<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings =
	array(
		'enabled'                    => array(
			'title'       => __( 'Enable/Disable', 'lunar-payments-for-woocommerce' ),
			'label'       => __( 'Enable Lunar Mobilepay', 'lunar-payments-for-woocommerce' ),
			'type'        => 'checkbox',
			'description' => '',
			'default'     => 'no',
		),
		'title'                      => array(
			'title'       => __( 'Payment method title', 'lunar-payments-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'This controls the title which the user sees during checkout.', 'lunar-payments-for-woocommerce' ),
			'default'     => __( 'MobilePay', 'lunar-payments-for-woocommerce' ),
			'desc_tip'    => true,
		),
		'description'                => array(
			'title'       => __( 'Payment method description', 'lunar-payments-for-woocommerce' ),
			'type'        => 'textarea',
			'description' => __( 'This controls the description which the user sees during checkout.', 'lunar-payments-for-woocommerce' ),
			'default'     => __( 'Secure payment with MobilePay via &copy; <a href="https://lunar.app" target="_blank">Lunar</a>', 'lunar-payments-for-woocommerce' ),
			'desc_tip'    => true,
		),
		'secret_key'                 => array(
			'title'       => __( 'App key', 'lunar-payments-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'Get it from your Lunar dashboard', 'lunar-payments-for-woocommerce' ),
			'default'     => '',
			'desc_tip'    => true,
		),
		'public_key'                 => array(
			'title'       => __( 'Public key', 'lunar-payments-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'Get it from your Lunar dashboard', 'lunar-payments-for-woocommerce' ),
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
		'logo' => array(
			'title'       => __( 'Logo URL', 'lunar-payments-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'URL must start with "https", the image must be in PNG or JPG format, and 250x250 px', 'lunar-payments-for-woocommerce' ),
			'default'     => '',
			'desc_tip'    => true,
		),
		'checkout_mode'              => array(
			'title'    => __( 'Checkout mode', 'lunar-payments-for-woocommerce' ),
			'type'     => 'select',
			'options'  => array(
				'after_order'  => __( 'Redirect to payment page after order created', 'lunar-payments-for-woocommerce' ),
				'before_order' => __( 'Payment before order created', 'lunar-payments-for-woocommerce' ),
			),
			'default'  => 'after_order',
			'desc_tip' => true,
		),
		'capture'                    => array(
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

	);


return apply_filters( 'wc_lunar_mobilepay_settings', $settings );
