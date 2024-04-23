<?php
/**
 * WooCommerce Pre-Orders
 *
 * @package     WC_Pre_Orders/Order
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Pre-Orders Order class
 *
 * Mirrors the  WC_Order class to provide pre-orders specific functionality
 *
 * @since 1.0
 */
class WC_Pre_Orders_Order {

	/**
	 * Add hooks / filters
	 *
	 * @since 1.0
	 * @return \WC_Pre_Orders_Order
	 */
	public function __construct() {

		add_action( 'init', array( $this, 'register_order_status' ) );

		// Support coupons and pre-ordered status
		add_action( 'init', array( $this, 'register_order_status_change_coupons_support' ) );

		add_filter( 'wc_order_statuses', array( $this, 'order_statuses' ) );

		// automatically update the pre-order status when the order's status changes
		add_action( 'woocommerce_order_status_changed', array( $this, 'auto_update_pre_order_status' ), 10, 3 );

		// automatically cancel a pre-order when it's parent order is trashed
		add_action( 'wp_trash_post', array( $this, 'maybe_cancel_trashed_pre_order' ) );

		// get formatted order total when viewing order on my account page
		add_filter( 'woocommerce_get_formatted_order_total', array( $this, 'get_formatted_order_total' ), 10, 2 );

		// When we attempt to pay for this order, make sure it is in stock
		// since we already reduced stock when they pre-ordered.
		add_filter( 'woocommerce_pay_order_product_in_stock', array( $this, 'product_in_stock' ), 10, 3 );

		add_filter( 'woocommerce_reports_order_statuses', array( $this, 'add_pre_orders_to_report_statuses' ) );
	}

	/**
	 * Add support for coupons and pre-ordered status
	 *
	 * @return void
	 */
	public function register_order_status_change_coupons_support() {
		// Increase coupon usage for pre-ordered status.
		add_action( 'woocommerce_order_status_pre-ordered', 'wc_update_coupon_usage_counts' );
	}

	/**
	 * New order status for WooCommerce 2.2 or later
	 *
	 * @return void
	 */
	public function register_order_status() {
		register_post_status(
			'wc-pre-ordered',
			array(
				'label'                     => _x( 'Pre-ordered', 'Order status', 'woocommerce-pre-orders' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of pre-orders */
				'label_count'               => _n_noop( 'Pre-ordered <span class="count">(%s)</span>', 'Pre-ordered <span class="count">(%s)</span>', 'woocommerce-pre-orders' ),
			)
		);
	}

	/**
	 * Set wc-pre-ordered in WooCommerce order statuses.
	 *
	 * @param  array $order_statuses
	 * @return array
	 */
	public function order_statuses( $order_statuses ) {
		$order_statuses['wc-pre-ordered'] = _x( 'Pre-ordered', 'Order status', 'woocommerce-pre-orders' );

		return $order_statuses;
	}

	/**
	 * Add "pre-ordered" order status to WC Reports for tracking order revenue.
	 *
	 * @param array|bool $order_statuses
	 * @return array
	 */
	public function add_pre_orders_to_report_statuses( $order_statuses ) {
		return is_array( $order_statuses ) ? array_merge( $order_statuses, array( 'pre-ordered' ) ) : $order_statuses;
	}

	/**
	 * Get the order total formatted to show when the order will be (or was) charged
	 *
	 * @since 1.0
	 * @param string $formatted_total price string ( note: this is already formatted by woocommerce_price() )
	 * @param object $order the WC_Order object
	 * @return string the formatted order total price string
	 */
	public function get_formatted_order_total( $formatted_total, $order ) {
		$product = self::get_pre_order_product( $order );

		if ( ! empty( $product ) ) {
			// only modify the order total on the frontend when the order contains an active pre-order
			if ( ! is_admin() && 'active' !== $this->get_pre_order_status( $order ) ) {
				$formatted_total = WC_Pre_Orders_Manager::get_formatted_pre_order_total( $formatted_total, $product );
			}
		}

		return $formatted_total;
	}

	/**
	 * Checks if an order contains a pre-order
	 *
	 * @since 1.0
	 * @param object|int $order Preferably the order object, or order ID if
	 *                          object is inconvenient to provide.
	 * @return bool true if the order contains a pre-order, false otherwise
	 */
	public static function order_contains_pre_order( $order ) {
		$order = wc_get_order( $order );
		if ( ! is_object( $order ) ) {
			return false;
		}

		return (bool) $order->get_meta( '_wc_pre_orders_is_pre_order', true );
	}

	/**
	 * Checks if an order will be charged upon release
	 *
	 * @since 1.0
	 * @param object|int $order preferably the order object, or order ID if object is inconvenient to provide
	 * @return bool true if the order will be charged upon , false otherwise
	 */
	public static function order_will_be_charged_upon_release( $order ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$orders_when_charged = $order->get_meta( '_wc_pre_orders_when_charged', true );

		if ( ! empty( $orders_when_charged ) ) {
			return 'upon_release' === $orders_when_charged;
		}

		return WC_Pre_Orders_Product::product_is_charged_upon_release( self::get_pre_order_product( $order ) );
	}

	/**
	 * Checks if an order requires payment tokenization. For a pre-order charged upon release, a customer has the option
	 * to use the 'pay later' gateway, and then return and pay for the pre-order with a supported gateway. Because the
	 * pre-order is still marked as being charged upon release, this helps the supported gateway know how to process the
	 * payment.
	 *
	 * @since 1.0
	 * @param object|int $order preferably the order object, or order ID if object is inconvenient to provide
	 * @return bool true if the order requires payment tokenization , false otherwise
	 */
	public static function order_requires_payment_tokenization( $order ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		// if order already has a payment token, tokenization is not required
		if ( self::order_has_payment_token( $order ) ) {
			return false;
		}

		$order_id = $order->get_id();

		// if the order is charged upon release and no payment token exists then it requires payment tokenization
		return ( self::order_will_be_charged_upon_release( $order ) && ! WC_Pre_Orders_Manager::is_order_pay_later( $order_id ) );
	}

	/**
	 * Checks if an order has an existing payment token that can be used by the original gateway to charge the pre-order
	 * upon release
	 *
	 * @since 1.0
	 * @param object|int $order preferably the order object, or order ID if object is inconvenient to provide
	 * @return bool true if the order contains a payment token , false otherwise
	 */
	public static function order_has_payment_token( $order ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		return (bool) $order->get_meta( '_wc_pre_orders_has_payment_token', true );
	}

	/**
	 * Changes the status for an unpaid, but payment-tokenized order to pre-ordered and adds meta to indicate the order
	 * has a payment token. Should be used by supported gateways when processing a pre-order charged upon release, instead of calling
	 * $order->payment_complete(), this will be used. Note that if the order used pay later, this does not apply.
	 *
	 * @since 1.0
	 * @param object|int $order preferably the order object, or order ID if object is inconvenient to provide
	 */
	public static function mark_order_as_pre_ordered( $order ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$order_id = $order->get_id();

		if ( WC_Pre_Orders_Manager::is_order_pay_later( $order_id ) ) {
			return;
		}

		// mark as having a payment token, which will be used upon release to charge pre-order total amount
		$order->update_meta_data( '_wc_pre_orders_has_payment_token', 1 );

		// update status
		$order->update_status( 'pre-ordered' );

		// Save order.
		$order->save();

		// reduce order stock
		WC_Pre_Orders_Manager::reduce_stock_level( $order );
	}

	/**
	 * Since an order may only contain a single pre-ordered item, this returns
	 * the pre-ordered item array.  This method assumes that $order is a pre-order
	 *
	 * @since    1.0
	 * @version  1.5.3
	 * @param    object|int $order the order object or order ID
	 * @return   object|bool the pre-ordered order item array
	 */
	public static function get_pre_order_item( $order ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		foreach ( $order->get_items( 'line_item' ) as $order_item ) {

			if ( ! empty( $order_item['product_id'] ) ) {
				// Avoid running heavy queries via WC_Pre_Orders_Product::product_has_active_pre_orders() that check if any order exists with an active pre-order status to this product if we know the order provided is active.
				if ( 'active' === self::get_pre_order_status( $order ) ) {
					return $order_item;
				} elseif ( WC_Pre_Orders_Product::product_can_be_pre_ordered( $order_item['product_id'] ) || WC_Pre_Orders_Product::product_has_active_pre_orders( $order_item['product_id'] ) ) {
					return $order_item;
				}
			}
		}

		return null;
	}

	/**
	 * Since an order may only contain a single pre-ordered product, this returns the pre-ordered product object
	 *
	 * @since    1.0
	 * @version  1.5.3
	 * @param    object|int $order preferably the order object, or order ID if object is inconvenient to provide
	 * @return   object|bool the pre-ordered product object, or false if the cart does not contain a pre-order
	 */
	public static function get_pre_order_product( $order ) {

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		if ( ! self::order_contains_pre_order( $order ) ) {
			return null;
		}

		foreach ( $order->get_items( 'line_item' ) as $order_item ) {
			if ( ! empty( $order_item['product_id'] ) ) {
				// Avoid running heavy queries via WC_Pre_Orders_Product::product_has_active_pre_orders() that check if any order exists with an active pre-order status to this product if we know the order provided is active.
				if ( 'active' === self::get_pre_order_status( $order ) ) {
					return $order_item->get_product();
				} elseif ( WC_Pre_Orders_Product::product_can_be_pre_ordered( $order_item['product_id'] ) || WC_Pre_Orders_Product::product_has_active_pre_orders( $order_item['product_id'] ) ) {
					// return the product object
					return $order_item->get_product();
				}
			}
		}

		return null;
	}

	/**
	 * Get the pre-order status for an order
	 * - Active = awaiting release
	 * - Completed = availability date was reached or admin manually completed
	 * - Cancelled = order and/or pre-order was cancelled
	 *
	 * @since 1.0
	 * @param object|int $order Preferably the order object, or order ID if
	 *                          object is inconvenient to provide.
	 * @return bool|string The pre-order status or false if order is not valid.
	 */
	public static function get_pre_order_status( $order ) {
		$order = wc_get_order( $order );
		return is_object( $order ) ? $order->get_meta( '_wc_pre_orders_status', true ) : false;
	}

	/**
	 * Returns a pre-order status to display
	 *
	 * @since 1.0
	 * @param object|int $order preferably the order object, or order ID if object is inconvenient to provide
	 * @return string the pre-order status for display
	 */
	public static function get_pre_order_status_to_display( $order ) {

		$status = self::get_pre_order_status( $order );

		switch ( $status ) {
			case 'active':
				$status_string = __( 'Active', 'woocommerce-pre-orders' );
				break;
			case 'completed':
				$status_string = __( 'Completed', 'woocommerce-pre-orders' );
				break;
			case 'cancelled':
				$status_string = __( 'Cancelled', 'woocommerce-pre-orders' );
				break;
			default:
				$status_string = apply_filters( 'wc_pre_orders_custom_status_string', ucfirst( $status ), $order );
				break;
		}

		return apply_filters( 'wc_pre_orders_status_string', $status_string, $status, $order );
	}

	/**
	 * Automatically change the pre-order status when the order status changes.
	 *
	 * @since 1.0
	 * @param int    $order_id post ID of the order
	 * @param string $old_order_status the prior order status
	 * @param string $new_order_status the new order status
	 */
	public function auto_update_pre_order_status( $order_id, $old_order_status, $new_order_status ) {

		$order = wc_get_order( $order_id );

		if ( 'pre-ordered' === $new_order_status && $this->order_contains_pre_order( $order_id ) ) {
			$this->update_pre_order_status( $order_id, 'active' );
		}

		if ( 'on-hold' === $new_order_status && $this->order_contains_pre_order( $order_id ) ) {
			$this->update_pre_order_status( $order_id, 'active' );
		}

		if ( 'completed' === $new_order_status && $this->order_contains_pre_order( $order_id ) ) {
			// Get message to send it to customer email.
			$transient_key = 'wc_pre_orders_pre_order_completed_message_' . $order_id;
			$message       = get_transient( $transient_key );
			if ( ! empty( $message ) ) {
				delete_transient( $transient_key );
			} else {
				$message = '';
			}
			$this->update_pre_order_status( $order_id, 'completed', $message );
		}

		// change to 'cancelled' when changing order status to 'cancelled', except when the pre-order status is already cancelled. this prevents sending double emails when bulk-cancelling pre-orders
		if ( 'cancelled' === $new_order_status && self::order_contains_pre_order( $order_id ) && 'cancelled' !== $order->get_meta( '_wc_pre_orders_status', true ) ) {
			$this->update_pre_order_status( $order_id, 'cancelled' );
		}
	}

	/**
	 * Update the pre-order status for an order
	 *
	 * @since 1.0
	 * @param object|int $order preferably the order object, or order ID if object is inconvenient to provide
	 * @param string     $new_status the new pre-order status
	 * @param string     $message an optional message to include in the email to customer
	 */
	public static function update_pre_order_status( $order, $new_status, $message = '' ) {
		if ( ! $new_status ) {
			return;
		}

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		$order_id = $order->get_id();

		$old_status = $order->get_meta( '_wc_pre_orders_status', true );

		if ( $old_status === $new_status ) {
			return;
		}

		if ( ! $old_status ) {
			$old_status = 'new';
		}

		$order->update_meta_data( '_wc_pre_orders_status', $new_status );

		// actions for status changes
		do_action( 'wc_pre_order_status_' . $new_status, $order_id, $message );
		do_action( 'wc_pre_order_status_' . $old_status . '_to_' . $new_status, $order_id, $message );
		do_action( 'wc_pre_order_status_changed', $order_id, $old_status, $new_status, $message );

		// Make sure message ends with punctuation and concatenates with status
		// transition string.
		$message = rtrim( $message );
		if ( ! empty( $message ) ) {
			$message .= ! in_array( substr( $message, -1 ), array( '!', '?', '.', ';', ':' ) ) ? '.' : '';
			$message .= ' ';
		}

		// add order note
		/* translators: %1$s: old pre-order status %2$s: new pre-order status */
		$order->add_order_note( $message . sprintf( __( 'Pre-order status changed from %1$s to %2$s.', 'woocommerce-pre-orders' ), $old_status, $new_status ) );

		// Save order data
		$order->save();
	}

	/**
	 * Automatically cancel a pre-order if it's parent order is moved to the trash. Note that un-trashing the order does
	 * not change the pre-order back to it's original status
	 *
	 * @since 1.0
	 * @param int $order_id the order post ID.
	 */
	public function maybe_cancel_trashed_pre_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) ) {
			return;
		}

		if ( $this->order_contains_pre_order( $order ) && WC_Pre_Orders_Manager::can_pre_order_be_changed_to( 'cancelled', $order ) ) {
			$this->update_pre_order_status( $order, 'cancelled' );
		}
	}

	/**
	 * Product is in stock for orders which are pre-orders (stock reduced during pre-order)
	 *
	 * @param bool       $in_stock
	 * @param WC_Product $product
	 * @param WC_Order   $order
	 *
	 * @return bool
	 */
	public static function product_in_stock( $in_stock, $product, $order ) {
		if ( self::order_contains_pre_order( $order ) ) {
			$in_stock = true;
		}

		return $in_stock;
	}

} // end \WC_Pre_Orders_Order class
