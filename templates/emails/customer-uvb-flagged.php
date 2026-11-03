<?php

/**
 * Override in yourtheme/woocommerce/emails/customer-uvb-flagged.php.
 * @version 4.3.0
 */

defined( 'ABSPATH' ) || exit;

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

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
