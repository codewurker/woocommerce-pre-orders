<?php
/**
 * WooCommerce Pre-Orders
 *
 * @package     WC_Pre_Orders/Email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * New Pre-Order Email
 *
 * An email sent to the admin when a new pre-order is received
 *
 * @since 1.0
 */
class WC_Pre_Orders_Email_New_Pre_Order extends WC_Email {

	/**
	 * Constructor
	 *
	 * @since 1.0
	 */
	public function __construct() {

		global $wc_pre_orders;

		$this->id          = 'wc_pre_orders_new_pre_order';
		$this->title       = __( 'New pre-order', 'woocommerce-pre-orders' );
		$this->description = __( 'New pre-order emails are sent when a pre-order is received.', 'woocommerce-pre-orders' );

		$this->heading = __( 'New pre-order: #{order_number}', 'woocommerce-pre-orders' );
		$this->subject = __( '[{site_title}] New customer pre-order ({order_number}) - {order_date}', 'woocommerce-pre-orders' );

		$this->template_base  = $wc_pre_orders->get_plugin_path() . '/templates/';
		$this->template_html  = 'emails/admin-new-pre-order.php';
		$this->template_plain = 'emails/plain/admin-new-pre-order.php';

		// Triggers for this email
		add_action( 'wc_pre_order_status_new_to_active_notification', array( $this, 'trigger' ) );

		// Call parent constructor
		parent::__construct();

		// Other settings
		$this->recipient = $this->get_option( 'recipient' );

		if ( ! $this->recipient ) {
			$this->recipient = get_option( 'admin_email' );
		}
	}


	/**
	 * Dispatch the email
	 *
	 * @since 1.0
	 */
	public function trigger( $order_id ) {
		if ( $order_id ) {
			$this->object = new WC_Order( $order_id );

			$this->placeholders = array_merge(
				array(
					'{order_date}'   => date_i18n(
						wc_date_format(),
						strtotime( (
						$this->object->get_date_created()
							? gmdate( 'Y-m-d H:i:s', $this->object->get_date_created()->getOffsetTimestamp() )
							: ''
						) )
					),
					'{order_number}' => $this->object->get_order_number()
				),
				$this->placeholders
			);
		}

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}


	/**
	 * Gets the email HTML content
	 *
	 * @since 1.0
	 * @return string the email HTML content
	 */
	public function get_content_html() {
		global $wc_pre_orders;
		ob_start();
		wc_get_template(
			$this->template_html,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'plain_text'    => false,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
		return ob_get_clean();
	}


	/**
	 * Gets the email plain content
	 *
	 * @since 1.0
	 * @return string the email plain content
	 */
	public function get_content_plain() {
		global $wc_pre_orders;
		ob_start();
		wc_get_template(
			$this->template_plain,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'plain_text'    => true,
				'sent_to_admin' => true,
			),
			'',
			$this->template_base
		);
		return ob_get_clean();
	}


	/**
	 * Initialise Settings Form Fields
	 *
	 * @since 1.0
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'    => array(
				'title'   => __( 'Enable/Disable', 'woocommerce-pre-orders' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'woocommerce-pre-orders' ),
				'default' => 'yes',
			),
			'recipient'  => array(
				'title'       => __( 'Recipient(s)', 'woocommerce-pre-orders' ),
				'type'        => 'text',
				/* translators: %s: admin email address */
				'description' => sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to <code>%s</code>.', 'woocommerce-pre-orders' ), esc_attr( get_option( 'admin_email' ) ) ),
				'placeholder' => '',
				'default'     => '',
			),
			'subject'    => array(
				'title'       => __( 'Subject', 'woocommerce-pre-orders' ),
				'type'        => 'text',
				/* translators: %s: email subject */
				'description' => sprintf( __( 'This controls the email subject line. Leave blank to use the default subject: <code>%s</code>.', 'woocommerce-pre-orders' ), $this->subject ),
				'placeholder' => '',
				'default'     => '',
			),
			'heading'    => array(
				'title'       => __( 'Email heading', 'woocommerce-pre-orders' ),
				'type'        => 'text',
				/* translators: %s: email heading */
				'description' => sprintf( __( 'This controls the main heading contained within the email notification. Leave blank to use the default heading: <code>%s</code>.', 'woocommerce-pre-orders' ), $this->heading ),
				'placeholder' => '',
				'default'     => '',
			),
			'email_type' => array(
				'title'       => __( 'Email type', 'woocommerce-pre-orders' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'woocommerce-pre-orders' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => array(
					'plain'     => __( 'Plain text', 'woocommerce-pre-orders' ),
					'html'      => __( 'HTML', 'woocommerce-pre-orders' ),
					'multipart' => __( 'Multipart', 'woocommerce-pre-orders' ),
				),
			),
		);
	}
}
