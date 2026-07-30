<?php

/**
 * Customer UVB flagged email.
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/emails/customer-uvb-flagged.php.
 *
 * @package UVBConnectorWooCommerce/Templates/Emails
 * @version 4.3.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * @hooked WC_Emails::email_header() Outputs the email header.
 */
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php
	printf(
		/* translators: %s: Order number. */
		esc_html__( 'The delivery of order #%s was recorded as refused in the ProteCOD system.', 'uvb-connector-woocommerce' ),
		esc_html( $order->get_order_number() )
	);
	?>
</p>

<?php
/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details.
 * @hooked WC_Emails::email_addresses() Shows email addresses.
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/*
 * @hooked WC_Emails::email_footer() Outputs the email footer.
 */
do_action( 'woocommerce_email_footer', $email );
