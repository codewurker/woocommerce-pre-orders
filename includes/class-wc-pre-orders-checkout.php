<?php
/**
 * WooCommerce Pre-Orders
 *
 * @package WC_Pre_Orders/Checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Pre-Orders Checkout class
 *
 * Override some functionality of the WC checkout process in order to support pre-orders
 *
 * @since 1.0
 */
class WC_Pre_Orders_Checkout {


	/**
	 * Add hooks / filters
	 *
	 * @since 1.0.0
	 * @version 1.5.4
	 * @return \WC_Pre_Orders_Checkout
	 */
	public function __construct() {

		// modify the 'Place Order' button on the checkout page
		add_filter( 'woocommerce_order_button_text', array( $this, 'modify_place_order_button_text' ) );

		// conditionally remove unsupported gateways
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'maybe_remove_unsupported_gateways' ), 10 );

		// add order meta
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'add_order_meta' ) );
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Package' ) && version_compare( \Automattic\WooCommerce\Blocks\Package::get_version(), '7.2.0', '>=' ) ) {
			add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'add_order_meta_by_order' ) );
		} else {
			add_action( 'woocommerce_blocks_checkout_update_order_meta', array( $this, 'add_order_meta_by_order' ) );
		}

		// change status to pre-ordered when payment is completed for a pre-order charged upfront
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'update_payment_complete_order_status' ), 10, 2 );

		// change status to pre-ordered when payment is completed for a pre-order charged upfront ( for gateways that do not call WC_Order::payment_complete() )
		add_action( 'woocommerce_order_status_on-hold_to_processing', array( $this, 'update_manual_payment_complete_order_status' ) );
		add_action( 'woocommerce_order_status_on-hold_to_completed', array( $this, 'update_manual_payment_complete_order_status' ) );
		add_action( 'woocommerce_order_status_pending_to_processing', array( $this, 'update_manual_payment_complete_order_status' ) );

		// change status to pre-ordered when payment is completed for a pre-order charged upfront that previously failed
		add_action( 'woocommerce_order_status_failed_to_processing', array( $this, 'update_manual_payment_complete_order_status' ) );
		add_action( 'woocommerce_order_status_failed_to_completed', array( $this, 'update_manual_payment_complete_order_status' ) );

		// Remove the "is_pre_order" flag when the order is charged upfront and fails.
		add_action( 'woocommerce_order_status_pending_to_failed', array( $this, 'remove_is_pre_order_on_failure' ) );

		// Filter the thank you page to add release date message.
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'add_release_date_message' ), 10, 2 );
	}

	/**
	 * Adds release date message to thank you page.
	 * Only shows for charge upon release.
	 *
	 * @since 1.5.4
	 * @version 1.5.4
	 * @param string $message Original message
	 * @param object $order
	 * @return string
	 */
	public function add_release_date_message( $message, $order ) {
		if ( ! WC_Pre_Orders_Order::get_pre_order_product( $order ) ) {
			return $message;
		}

		if ( ! WC_Pre_Orders_Order::order_will_be_charged_upon_release( $order ) ) {
			return $message;
		}

		$availability_date = WC_Pre_Orders_Product::get_localized_availability_date( WC_Pre_Orders_Order::get_pre_order_product( $order ) );

		/* translators: %s: availability date */
		$availability_date_text = ( ! empty( $availability_date ) ) ? sprintf( __( ' on %s.', 'woocommerce-pre-orders' ), $availability_date ) : '.';

		/* translators: 1: availability date */
		$message = sprintf( __( 'Your pre-order has been received. You will be prompted for payment for your order when your pre-order is released%s Your order details are shown below for your reference.', 'woocommerce-pre-orders' ), $availability_date_text ) . "\n\n";

		if ( WC_Pre_Orders_Order::order_has_payment_token( $order ) ) {
			/* translators: 1: availability date */
			$message = sprintf( __( 'Your pre-order has been received. You will be automatically charged for your order via your selected payment method when your pre-order is released%s Your order details are shown below for your reference.', 'woocommerce-pre-orders' ), $availability_date_text ) . "\n\n";
		}

		return $message;
	}

	/**
	 * Check if is a pre-order and charged upon release
	 *
	 * @return bool
	 */
	protected function is_pre_order_and_charged_upon_release() {
		return WC_Pre_Orders_Cart::cart_contains_pre_order() && WC_Pre_Orders_Product::product_is_charged_upon_release( WC_Pre_Orders_Cart::get_pre_order_product() );
	}

	/**
	 * Conditionally remove any gateways that don't support pre-orders on the checkout page when the pre-order is charged
	 * upon release. This is done because payment info is not required in this case so displaying gateways/payment fields
	 * is not needed.
	 *
	 * @since 1.0
	 *
	 * @param array $available_gateways
	 *
	 * @return array
	 */
	public function maybe_remove_unsupported_gateways( $available_gateways ) {

		// Backwards compatibility checking for payment page
		if ( function_exists( 'is_checkout_pay_page' ) ) {
			$pay_page = is_checkout_pay_page();
		} else {
			$pay_page = is_page( wc_get_page_id( 'pay' ) );
		}

		// On checkout page
		if (
			( $pay_page && $this->is_pre_order_and_charged_upon_release() ) ||
			( is_checkout() && $this->is_pre_order_and_charged_upon_release() )
		) {

			// Remove any non-supported payment gateways
			foreach ( $available_gateways as $gateway_id => $gateway ) {
				if ( ! method_exists( $gateway, 'supports' ) || false === $gateway->supports( 'pre-orders' ) || 'no' == $gateway->enabled ) {
					unset( $available_gateways[ $gateway_id ] );
				}
			}
		}

		return apply_filters( 'wc_pre_orders_remove_unsupported_gateways', $available_gateways, $this->is_pre_order_and_charged_upon_release() );
	}

	/**
	 * Modifies the 'Place Order' button text on the checkout page
	 *
	 * @since 1.0
	 * @param string $default_text default place order button text
	 * @return string
	 */
	public function modify_place_order_button_text( $default_text ) {

		// only modify button text if the cart contains a pre-order
		if ( ! WC_Pre_Orders_Cart::cart_contains_pre_order() ) {
			return $default_text;
		}

		// get custom text if set
		$text = get_option( 'wc_pre_orders_place_order_button_text' );

		if ( $text ) {
			return $text;
		} else {
			return $default_text;
		}
	}

	/**
	 * Adds order meta needed for pre-order functionality,
	 * when coming from an order using WooCommerce Blocks.
	 *
	 * @param WC_Order $order
	 */
	public function add_order_meta_by_order( $order ) {

		if ( is_a( $order, 'WC_Order' ) ) {
			$order_id = $order->get_id();
			$this->add_order_meta( $order_id );
		}
	}

	/**
	 * Add order meta needed for pre-order functionality
	 *
	 * @since 1.0
	 * @param int $order_id
	 */
	public function add_order_meta( $order_id ) {

		// don't add meta to orders that don't contain a pre-order
		// note the cart is checked here instead of the order since WC_Pre_Orders_Order::order_contains_pre_order() checks the meta that is about to be set here :)
		if ( ! WC_Pre_Orders_Cart::cart_contains_pre_order() ) {
			return;
		}

		// get pre-ordered product
		$product = WC_Pre_Orders_Cart::get_pre_order_product( $order_id );
		$order   = wc_get_order( $order_id );

		// indicate the order contains a pre-order
		$order->update_meta_data( '_wc_pre_orders_is_pre_order', 1 );

		// save when the pre-order amount was charged (either upfront or upon release)
		$order->update_meta_data( '_wc_pre_orders_when_charged', get_post_meta( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(), '_wc_pre_orders_when_to_charge', true ) );

		$order->save();
	}

	/**
	 * Update payment complete order status to pre-ordered for orders that are charged upfront. This handles gateways
	 * that call payment_complete() and prevents an awkward status change from pending->processing->pre-ordered, instead
	 * just showing a nice, clean pending->pre-ordered.
	 *
	 * @since 1.0.0
	 * @version 1.5.1
	 * @param string $new_status the status to change the order to.
	 * @param int $order_id the post ID of the order.
	 * @return string
	 */
	public function update_payment_complete_order_status( $new_status, $order_id ) {

		$order = wc_get_order( $order_id );
		if ( ! $order || ! WC_Pre_Orders_Order::order_contains_pre_order( $order ) ) {
			return $new_status;
		}

		$zero_cost_order = WC_Pre_Orders_Manager::is_zero_cost_order( $order );

		// Don't change status if pre-order will be charged upon release.
		if ( WC_Pre_Orders_Order::order_will_be_charged_upon_release( $order ) && ! $zero_cost_order ) {
			return $new_status;
		}

		return 'pre-ordered';
	}

	/**
	 * updates order status to pre-ordered for orders that are charged upfront. This handles gateways that don't call
	 * payment_complete(). Unfortunately status changes show like pending->processing/completed->pre-ordered
	 *
	 * @since 2.0.4 Check whether product can be pre-ordered when processing failed order.
	 * @since 1.9.1 Improve support for "Failed" orders.
	 * @since 1.9.0 Check whether the order item was pre-ordered to process failed pre-ordered order.
	 * @since 1.0
	 * @param int $order_id the post ID of the order
	 * @return string
	 */
	public function update_manual_payment_complete_order_status( $order_id ) {

		$order = new WC_Order( $order_id );
		$order_item = WC_Pre_Orders_Order::get_pre_order_item( $order );
		$product_id = $order_item ? wc_get_product( $order_item['product_id'] ) : null;

		// Failed order does not have "_wc_pre_orders_is_pre_order" meta key.
		// We remove this meta key to hide failed orders from the "Pre-orders" page.
		// For this reason, we need to check whether order has a pre-ordered item.
		$action_hooks = [
			'woocommerce_order_status_failed_to_processing',
			'woocommerce_order_status_failed_to_completed'
		];

		$is_failed_pre_order = in_array( current_action(), $action_hooks ) &&
			( WC_Pre_Orders_Order::get_pre_order_item( $order ) instanceof WC_Order_Item ) &&
			( $product_id && WC_Pre_Orders_Product::product_can_be_pre_ordered( $product_id ) );


		// don't update status for non pre-order orders
		if ( ! $is_failed_pre_order && ! WC_Pre_Orders_Order::order_contains_pre_order( $order ) ) {
			return;
		}

		// don't update if pre-order will be charged upon release
		if ( WC_Pre_Orders_Order::order_will_be_charged_upon_release( $order ) ) {
			return;
		}

		// indicate the order contains a pre-order
		$order->update_meta_data( '_wc_pre_orders_is_pre_order', 1 );

		// change order status to pre-ordered
		$order->update_status( 'pre-ordered' );

		// Save order.
		$order->save();
	}

	/**
	 * Removes the "_wc_pre_orders_is_pre_order" flag when charged ufront and the payment fails.
	 *
	 * This prevents the pre-order from showing up under the Pre-Orders
	 * table when the payment must be done upfront but fails.
	 *
	 * @since 1.5.31
	 * @param int $order_id the post ID of the order.
	 * @return void
	 */
	public function remove_is_pre_order_on_failure( $order_id ) {
		$order = wc_get_order( $order_id );

		// Don't update status for non pre-order orders.
		if ( ! WC_Pre_Orders_Order::order_contains_pre_order( $order ) ) {
			return;
		}

		// Don't update if pre-order will be charged upon release.
		if ( WC_Pre_Orders_Order::order_will_be_charged_upon_release( $order ) ) {
			return;
		}

		// Remove the pre-order flag on failure.
		$order->delete_meta_data( '_wc_pre_orders_is_pre_order' );
		$order->save();
	}


} // end \WC_Pre_Orders_Checkout class
