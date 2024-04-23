<?php
/**
 * WooCommerce Pre-Orders
 *
 * @package     WC_Pre_Orders/Templates/Email
 */

/**
 * Customer pre-order date changed notification email
 *
 * @since 1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( $availability_date ) :
	/* translators: %s: availability date */
	printf( esc_html__( 'Your pre-order release date has been changed. The new release date is %s. Your order details are shown below for your reference.', 'woocommerce-pre-orders' ), esc_html( $availability_date ) );
else :
	echo esc_html__( 'Your pre-order release date has been changed. Your order details are shown below for your reference.', 'woocommerce-pre-orders' );
endif;

echo "\n\n";

if ( $message ) :

	echo "----------\n\n";
	echo esc_html( wp_strip_all_tags( wptexturize( $message ) ) ) . "\n\n";
	echo "----------\n\n";

endif;

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n----------------------------------------\n\n";

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n\n----------------------------------------\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
