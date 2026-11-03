<?php

class UVBConnectorWooCommerce_Activator {

	public static function activate() {
	    $options = get_option('uvb_connector_woocommerce_options');
        if (!is_array($options)) {
            $options = [];
        }

        if (!empty($options)) {
            return;
        }

        $options['payment_methods_to_hide'] = ['cod'];
        $options['flag_orders'] = true;
        $options['reputation_threshold'] = 0.5;

        update_option('uvb_connector_woocommerce_options', $options);
	}
}
