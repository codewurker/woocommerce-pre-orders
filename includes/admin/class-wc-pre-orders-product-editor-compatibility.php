<?php

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Admin\Features\ProductBlockEditor\BlockRegistry;
use Automattic\WooCommerce\Internal\Admin\Features\ProductBlockEditor\ProductTemplates\SimpleProductTemplate;
use Automattic\WooCommerce\Internal\Admin\Features\ProductBlockEditor\ProductTemplates;
use Automattic\WooCommerce\Admin\Features\ProductBlockEditor\ProductTemplates\ProductFormTemplateInterface;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pre-Orders Product Editor Compatibility
 *
 * Add pre-orders support to product editor.
 *
 * @since 2.1.0
 */
class WC_Pre_Orders_Product_Editor_Compatibility {
	/**
	 * Constructor
	 */
	public function __construct() {
		if ( ! FeaturesUtil::feature_is_enabled( 'product_block_editor' ) ) {
			return;
		}

		add_action(
			'init',
			array( $this, 'register_custom_blocks' )
		);

		add_action(
			'woocommerce_block_template_area_product-form_after_add_block_variations',
			array( $this, 'add_pre_orders_section' )
		);

		add_filter(
			'woocommerce_rest_prepare_product_object',
			array( $this, 'add_meta_to_response' ),
			10,
			2
		);

		add_action(
			'woocommerce_rest_insert_product_object',
			array( $this, 'save_custom_data_to_product_metadata' ),
			10,
			2
		);
	}

	/**
	 * Registers the custom product field blocks.
	 */
	public function register_custom_blocks() {
		if ( isset( $_GET['page'] ) && 'wc-admin' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			BlockRegistry::get_instance()->register_block_type_from_metadata( WC_PRE_ORDERS_PLUGIN_PATH . '/build/admin/blocks/select-control' );
			BlockRegistry::get_instance()->register_block_type_from_metadata( WC_PRE_ORDERS_PLUGIN_PATH . '/build/admin/blocks/date-time-picker' );
			BlockRegistry::get_instance()->register_block_type_from_metadata( WC_PRE_ORDERS_PLUGIN_PATH . '/build/admin/blocks/message-control' );
		}
	}

	/**
	 * Adds pre-orders meta to the product response.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WC_Product       $product  The product object.
	 *
	 * @return WP_REST_Response
	 */
	public function add_meta_to_response( $response, $product ) {
		$data                   = $response->get_data();
		$has_orders             = WC_Pre_Orders_Product::product_has_active_pre_orders( $product->get_id() );
		$availability_timestamp = WC_Pre_Orders_Product::get_localized_availability_datetime_timestamp( $product->get_id() );
		$availability_timestamp = esc_attr( ( 0 === $availability_timestamp ) ? '' : gmdate( 'Y-m-d H:i', $availability_timestamp ) );

		$data['pre_order_enabled']     = get_post_meta( $product->get_id(), '_wc_pre_orders_enabled', true );
		$data['has_active_orders']     = $has_orders;
		$data['availability_datetime'] = $availability_timestamp;

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Saves custom data to product metadata.
	 *
	 * @param WC_Product      $product The product object.
	 * @param WP_REST_Request $request The request object.
	 */
	public function save_custom_data_to_product_metadata( $product, $request ) {
		$product_id = $product->get_id();
		$is_enabled = $request->get_param( 'pre_order_enabled' );
		$datetime   = $request->get_param( 'availability_datetime' );

		require_once 'class-wc-pre-orders-admin-products.php';

		if ( ! empty( $datetime ) ) {
			WC_Pre_Orders_Admin_Products::save_availability_date_time( $product_id, $datetime );
		} elseif ( ! is_null( $datetime ) ) {
			delete_post_meta( $product_id, '_wc_pre_orders_availability_datetime' );
		}

		if ( $is_enabled ) {
			update_post_meta( $product_id, '_wc_pre_orders_enabled', 'yes' );
		} elseif ( ! is_null( $is_enabled ) && false === $is_enabled ) {
			update_post_meta( $product_id, '_wc_pre_orders_enabled', '' );
		}
	}

	/**
	 * Adds a Pre-orders section to the product editor under the 'General' group.
	 *
	 * @since 2.1.0
	 *
	 * @param ProductTemplates\Group $variation_group The group instance.
	 */
	public function add_pre_orders_section( $variation_group ) {
		$template          = $variation_group->get_root_template();
		$is_simple_product = $this->is_template_valid( $template, 'simple-product' );

		if ( ! $is_simple_product ) {
			return;
		}

		/**
		 * Template instance.
		 *
		 * @var ProductFormTemplateInterface $parent
		 */
		$parent = $variation_group->get_parent();
		$group  = $parent->add_group(
			array(
				'id'         => 'woocommerce-pre-orders-group-tab',
				'attributes' => array(
					'title' => __( 'Pre-orders', 'woocommerce-pre-orders' ),
				),
			)
		);

		$section = $group->add_section(
			array(
				'id'         => 'woo-pre-orders-section',
				'attributes' => array(
					'title' => __( 'Pre-orders', 'woocommerce-pre-orders' ),
				),
			)
		);

		$section->add_block(
			array(
				'id'             => 'wc_pre_orders_active_pre_orders_message',
				'blockName'      => 'woocommerce-pre-orders/message-control-block',
				'attributes'     => array(
					'content' => sprintf(
						/* translators: %s Actions menu page URL */
						esc_html__(
							'There are active pre-orders for this product. To change the release date, <a href="%s">use the Actions menu</a>.',
							'woocommerce-pre-orders'
						),
						esc_url( admin_url( 'admin.php?page=wc_pre_orders&tab=actions&section=change-date&action_default_product=postIdPlaceholder' ) )
					),
				),
				'hideConditions' => array(
					array(
						'expression' => '!editedProduct.has_active_orders',
					),
				),
			)
		);

		$section->add_block(
			array(
				'id'             => 'wc_pre_orders_change_settings_message',
				'blockName'      => 'woocommerce-pre-orders/message-control-block',
				'attributes'     => array(
					'content' => sprintf(
						/* translators: %s Pre orders admin page URL */
						esc_html__(
							'To change other settings, please <a href="%s">complete or cancel the active pre-orders</a> first.',
							'woocommerce-pre-orders'
						),
						esc_url( admin_url( 'admin.php?page=wc_pre_orders' ) )
					),
				),
				'hideConditions' => array(
					array(
						'expression' => '!editedProduct.has_active_orders',
					),
				),
			)
		);

		$section->add_block(
			array(
				'id'                => 'wc_pre_orders_enabled',
				'blockName'         => 'woocommerce/product-checkbox-field',
				'attributes'        => array(
					'title'        => __( 'Enable pre-orders', 'woocommerce-deposits' ),
					'label'        => sprintf(
						__( 'Enable pre-orders for this product.', 'woocommerce-deposits' ),
					),
					'property'     => 'pre_order_enabled',
					'checkedValue' => 'yes',
				),
				'disableConditions' => array(
					array(
						'expression' => 'editedProduct.has_active_orders',
					),
				),
			)
		);

		$section->add_block(
			array(
				'id'                => 'wc_pre_orders_fee',
				'blockName'         => 'woocommerce/product-pricing-field',
				'attributes'        => array(
					'property' => 'meta_data._wc_pre_orders_fee',
					'label'    => __( 'Pre-order Fee', 'woocommerce-pre-orders' ),
					'help'     => __( 'Set a fee to be charged when a pre-order is placed. Leave blank to not charge a pre-order fee.', 'woocommerce-pre-orders' ),
				),
				'disableConditions' => array(
					array(
						'expression' => 'editedProduct.has_active_orders',
					),
				),
			)
		);

		$section->add_block(
			array(
				'id'                => 'wc_pre_orders_availability_datetime',
				'blockName'         => 'woocommerce-pre-orders/date-time-picker',
				'attributes'        => array(
					'property' => 'availability_datetime',
					'title'    => __( 'Availability date/time', 'woocommerce-pre-orders' ),
					'help'     => __( 'Set the date & time that this pre-order will be available. The product will behave as a normal product when this date/time is reached.', 'woocommerce-pre-orders' ),
				),
				'disableConditions' => array(
					array(
						'expression' => 'editedProduct.has_active_orders',
					),
				),
			)
		);

		$section->add_block(
			array(
				'id'                => 'wc_pre_orders_when_to_charge',
				'blockName'         => 'woocommerce-pre-orders/select-control-block',
				'attributes'        => array(
					'property' => 'meta_data._wc_pre_orders_when_to_charge',
					'title'    => __( 'When to Charge', 'woocommerce-pre-orders' ),
					'help'     => __( 'Select "Upon Release" to charge the entire pre-order amount (the product price + pre-order fee if applicable) when the pre-order becomes available. Select "Upfront" to charge the pre-order amount during the initial checkout.', 'woocommerce-pre-orders' ),
					'options'  => array(
						array(
							'value' => 'upfront',
							'label' => __( 'Upfront', 'woocommerce-pre-orders' ),
						),
						array(
							'value' => 'upon_release',
							'label' => __( 'Upon Release', 'woocommerce-pre-orders' ),
						),
					),
				),
				'disableConditions' => array(
					array(
						'expression' => 'editedProduct.has_active_orders',
					),
				),
			)
		);
	}

	/**
	 * Returns true if the template is valid.
	 *
	 * @param SimpleProductTemplate|ProductVariationTemplate $template    The template object.
	 * @param string                                         $template_id The template ID.
	 *
	 * @return bool
	 */
	private function is_template_valid( $template, $template_id ) {
		return $template_id === $template->get_id();
	}
}

new WC_Pre_Orders_Product_Editor_Compatibility();
