<?php
/**
 * WooCommerce Pre-Orders
 *
 * @package   WC_Pre_Orders/Admin
 * @author    WooThemes
 * @copyright Copyright (c) 2015, WooThemes
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pre-Orders Admin Products class.
 */
class WC_Pre_Orders_Admin_Products {

	/**
	 * Initialize the admin products actions.
	 */
	public function __construct() {
		// Add 'Pre-Orders' product writepanel tab.
		add_action( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_data_tab' ) );

		// Add 'Pre-Orders' tab content
		add_action( 'woocommerce_product_data_panels', array( $this, 'add_product_tab_options' ), 11 );

		// Save 'Pre-Orders' product options.
		$product_types = WC_Pre_Orders::get_supported_product_types();
		foreach ( $product_types as $product_type ) {
			add_action( 'woocommerce_process_product_meta_' . $product_type, array( $this, 'save_product_tab_options' ) );
		}
	}

	/**
	 * Add 'Pre-Orders' tab to product writepanel.
	 */
	public function add_product_tab() {
		_deprecated_function( __METHOD__ . '()', '1.5.9', __CLASS__ . '::product_data_tab()' );
	}

	/**
	 * Add 'Pre-Orders' tab to product write panel.
	 *
	 * @since 2.0.2 Hide pre-order options for unsupported product types.
	 *
	 * @param array $tabs Product tabs.
	 *
	 * @return array Product tabs.
	 */
	public static function product_data_tab( $tabs ) {
		$supported_types    = WC_Pre_Orders::get_supported_product_types();
		$un_supported_types = array_diff( array_keys( wc_get_product_types() ), $supported_types );

		$classes = array( 'wc_pre_orders_tab', 'wc_pre_orders_options' );

		foreach ( $supported_types as $product_type ) {
			$classes[] = 'show_if_' . $product_type;
		}

		if ( $un_supported_types ) {
			foreach ( $un_supported_types as $product_type ) {
				// Skip variable-subscription as it's not supported by pre-order.
				// We hide pre-order option with javascript custom code because woocommerce-subscriptions-core/assets/js/admin/admin.js:showHideVariableSubscriptionMeta function cause of a conflict.
				// This function toggle display product type class and after that handle only specific product type which means pre-order options will be shown for other product types.
				// We can remove this condition if issue will be fixed in next version of woocommerce-subscriptions-core.
				if ( 'variable-subscription' === $product_type ) {
					continue;
				}

				$classes[] = 'hide_if_' . $product_type;
			}
		}

		$tabs['pre_orders'] = array(
			'label'  => __( 'Pre-orders', 'woocommerce-pre-orders' ),
			'target' => 'wc_pre_orders_data',
			'class'  => $classes,
		);

		return $tabs;
	}

	/**
	 * Add pre-orders options to product writepanel.
	 */
	public function add_product_tab_options() {
		include 'views/html-product-tab-options.php';
	}

	/**
	 * Save pre-order options.
	 *
	 * @param int $post_id The ID of the product being saved.
	 */
	public function save_product_tab_options( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		// Don't save any settings if there are active pre-orders.
		if ( WC_Pre_Orders_Product::product_has_active_pre_orders( $post_id ) ) {
			return;
		}

		// pre-orders enabled
		$is_enabled = isset( $_POST['_wc_pre_orders_enabled'] ) && 'yes' === $_POST['_wc_pre_orders_enabled'];
		update_post_meta( $post_id, '_wc_pre_orders_enabled', ( $is_enabled ? 'yes' : 'no' ) );

		if ( ! empty( $_POST['_wc_pre_orders_availability_datetime'] ) ) {
			self::save_availability_date_time(
				$post_id,
				sanitize_text_field(
					wp_unslash(
						$_POST['_wc_pre_orders_availability_datetime']
					)
				)
			);
		} else {
			delete_post_meta( $post_id, '_wc_pre_orders_availability_datetime' );
		}

		// Pre-order fee.
		if ( isset( $_POST['_wc_pre_orders_fee'] ) && is_numeric( $_POST['_wc_pre_orders_fee'] ) ) {
			update_post_meta(
				$post_id,
				'_wc_pre_orders_fee',
				floatval(
					sanitize_text_field(
						wp_unslash(
							$_POST['_wc_pre_orders_fee']
						)
					)
				)
			);
		} else {
			update_post_meta( $post_id, '_wc_pre_orders_fee', '' );
		}

		// When to charge pre-order amount.
		if ( isset( $_POST['_wc_pre_orders_when_to_charge'] ) && isset( $_POST['_wc_pre_orders_enabled'] ) && 'yes' === $_POST['_wc_pre_orders_enabled'] ) {
			update_post_meta( $post_id, '_wc_pre_orders_when_to_charge', ( 'upon_release' === $_POST['_wc_pre_orders_when_to_charge'] ) ? 'upon_release' : 'upfront' );
		}

		do_action( 'wc_pre_orders_save_product_options', $post_id );
	}

	/**
	 * Save the availability date/time.
	 *
	 * The date/time a pre-order is released is saved as a unix timestamp adjusted for the site's timezone. For example,
	 * when an admin sets a pre-order to be released on 2013-06-25 12pm EST (UTC-4), it is saved as a timestamp equivalent
	 * to 2013-12-25 4pm UTC. This makes the pre-order release check much easier, as it's a simple timestamp comparison,
	 * because the release datetime and the current time are both in UTC.
	 *
	 * @param int    $post_id The ID of the product being saved.
	 * @param string $value   The value of the availability date/time.
	 */
	public static function save_availability_date_time( $post_id, $value ) {
		try {
			// Get datetime object from site timezone.
			$datetime = new DateTime( wp_unslash( $value ), new DateTimeZone( wc_timezone_string() ) );

			// Get the unix timestamp (adjusted for the site's timezone already).
			$timestamp = $datetime->format( 'U' );

			// Don't allow availability dates in the past.
			if ( $timestamp <= time() ) {
				$timestamp = '';
			}

			// Set the availability datetime.
			update_post_meta( $post_id, '_wc_pre_orders_availability_datetime', $timestamp );

		} catch ( Exception $e ) {
			global $wc_pre_orders;

			$wc_pre_orders->log( $e->getMessage() );
		}
	}
}

new WC_Pre_Orders_Admin_Products();
