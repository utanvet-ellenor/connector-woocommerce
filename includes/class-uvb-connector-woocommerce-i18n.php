<?php

class UVBConnectorWooCommerce_i18n {

	public function loadPluginTextdomain() {

		load_plugin_textdomain(
			'uvb-connector-woocommerce',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}
}
