<?php

/**
 * Customer UVB flagged email.
 *
 * @link       https://utanvet-ellenor.hu
 * @since      4.3.0
 *
 * @package    UVBConnectorWooCommerce
 * @subpackage UVBConnectorWooCommerce/includes/emails
 */

defined( 'ABSPATH' ) || exit;

/**
 * Notify the customer when an order enters the UVB flagged status.
 *
 * @since 4.3.0
 */
class UVBConnectorWooCommerce_Email_Customer_UVB_Flagged extends WC_Email {

	/**
	 * Set up the email.
	 */
	public function __construct() {
		$this->id             = 'customer_uvb_flagged';
		$this->customer_email = true;
		$this->title          = __( 'Refused delivery', 'uvb-connector-woocommerce' );
		$this->description    = __( 'This email is sent to customers when a delivery is marked as refused.', 'uvb-connector-woocommerce' );
		$this->template_html  = 'emails/customer-uvb-flagged.php';
		$this->template_plain = 'emails/plain/customer-uvb-flagged.php';
		$this->template_base  = dirname( dirname( __DIR__ ) ) . '/templates/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		if ( property_exists( $this, 'email_group' ) ) {
			$this->email_group = 'order-changes';
		}

		add_action( 'woocommerce_order_status_uvb_flagged_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * Initialise the email settings.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		$this->form_fields['enabled']['default'] = 'no';
	}

	/**
	 * Get the default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( '[{site_title}]: Delivery for order #{order_number} was refused', 'uvb-connector-woocommerce' );
	}

	/**
	 * Get the default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Refused delivery: #{order_number}', 'uvb-connector-woocommerce' );
	}

	/**
	 * Get the default additional content.
	 *
	 * @return string
	 */
	public function get_default_additional_content() {
		return __( 'Please contact us to resolve this matter.', 'uvb-connector-woocommerce' );
	}

	/**
	 * Trigger the email.
	 *
	 * @param int            $order_id Order ID.
	 * @param WC_Order|false $order    Order instance.
	 * @return void
	 */
	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! is_a( $order, 'WC_Order' ) ) {
			$this->restore_locale();
			return;
		}

		$this->object                         = $order;
		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();

		if ( method_exists( $this, 'send_notification' ) ) {
			$this->send_notification();
		} elseif ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send(
				$this->get_recipient(),
				$this->get_subject(),
				$this->get_content(),
				$this->get_headers(),
				$this->get_attachments()
			);
		}

		$this->restore_locale();
	}

	/**
	 * Get the HTML content.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Get the plain-text content.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}
}
