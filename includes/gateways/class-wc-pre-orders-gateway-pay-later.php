<?php
/**
 * WooCommerce Pre-Orders
 *
 * @package     WC_Pre_Orders/Pay-Later-Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Pre-Orders "Pay Later" Payment Gateway class
 *
 * Extends the WC_Payment_Gateway class to provide a generic "Pay Later" payment gateway for pre-orders
 *
 * @since 1.0
 * @extends \WC_Payment_Gateway
 */
class WC_Pre_Orders_Gateway_Pay_Later extends WC_Payment_Gateway {

	/**
	 * Loads settings and hooks for saving
	 *
	 * @since 1.0
	 * @return \WC_Pre_Orders_Gateway_Pay_Later
	 */
	public function __construct() {

		// Load defaults
		$this->id                 = 'pre_orders_pay_later';
		$this->method_title       = __( 'pay later', 'woocommerce-pre-orders' );
		$this->method_description = __( 'This payment method replaces all other methods that do not support pre-orders when the pre-order is charged upon release.', 'woocommerce-pre-orders' );
		$this->icon               = apply_filters( 'wc_pre_orders_pay_later_icon', '' );
		$this->has_fields         = false;

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled', 'yes' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		// Support pre-orders
		$this->supports = array( 'products', 'pre-orders' );

		// Save settings
		if ( is_admin() ) {
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		// pay page fallback
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
	}


	/**
	 * Disables the gateway under any of these conditions:
	 * 1) If the cart does not contain a pre-order
	 * 2) If the pre-order amount is charged upfront
	 * 3) On the pay page
	 *
	 * @since 1.0
	 * @return bool
	 */
	public function is_available() {

		$is_available = true;

		// Backwards compatibility checking for payment page
		if ( function_exists( 'is_checkout_pay_page' ) ) {
			$pay_page = is_checkout_pay_page();
		} else {
			$pay_page = is_page( wc_get_page_id( 'pay' ) );
		}

		// On checkout page
		if ( ! $pay_page || ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) ) {

			// Not available if the cart does not contain a pre-order
			if ( WC_Pre_Orders_Cart::cart_contains_pre_order() ) {

				// Not available when the pre-order amount is charged upfront
				if ( WC_Pre_Orders_Product::product_is_charged_upfront( WC_Pre_Orders_Cart::get_pre_order_product() ) ) {
					$is_available = false;
				}
			} else {

				$is_available = false;
			}
		} else {

			// Not available on the pay page (for now)
			$is_available = false;
		}

		return $is_available;
	}


	/**
	 * Setup gateway form fields
	 *
	 * @since 1.0
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-pre-orders' ),
				'label'       => __( 'Enable pay later', 'woocommerce-pre-orders' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'yes',
			),
			'title'       => array(
				'title'       => __( 'Title', 'woocommerce-pre-orders' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-pre-orders' ),
				'default'     => __( 'Pay later', 'woocommerce-pre-orders' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Customer message', 'woocommerce-pre-orders' ),
				'type'        => 'textarea',
				'description' => __( 'Let the customer know how they will be able to pay for their pre-order.', 'woocommerce-pre-orders' ),
				'default'     => __( 'You will receive an email when the pre-order is available along with instructions on how to complete your order.', 'woocommerce-pre-orders' ),
			),
		);
	}


	/**
	 * Process the payment and return the result
	 *
	 * @since  1.0
	 *
	 * @param  int $order_id
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = new WC_Order( $order_id );

		// Remove cart
		WC()->cart->empty_cart();

		// Update status
		$order->update_status( 'pre-ordered' );

		// Add a flag the order used pay later.
		$order->update_meta_data( '_wc_pre_orders_is_pay_later', 'yes' );
		$order->save();

		WC_Pre_Orders_Manager::reduce_stock_level( $order );

		// Redirect to thank you page
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Receipt page.
	 *
	 * @param  WC_Order $order
	 *
	 * @return string
	 */
	public function receipt_page( $order ) {
		echo '<p>' . esc_html__( 'Thank you for your order.', 'woocommerce-pre-orders' ) . '</p>';
	}

} // end \WC_Pre_Orders_Gateway_Pay_Later class
