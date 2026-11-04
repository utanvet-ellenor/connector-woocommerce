<?php

/**
 * Plugin Name:       Utánvét Ellenőr | ProteCOD
 * Plugin URI:        https://utanvet-ellenor.hu
 * Description:       🚨 Kiszállításokat szűrünk és védünk.
 * Version:           5.0.1
 * Author:            ProteCOD
 * Author URI:        https://utanvet-ellenor.hu
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       uvb-connector-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'UVB_CONNECTOR_WOOCOMMERCE_VERSION', '5.0.1' );

define( 'UVB_CONNECTOR_VENDOR_AUTOLOAD_PATH', plugin_dir_path(__FILE__) . 'vendor/autoload.php');

function activate_uvb_connector_woocommerce() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-uvb-connector-woocommerce-activator.php';
	UVBConnectorWooCommerce_Activator::activate();
}

function deactivate_uvb_connector_woocommerce() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-uvb-connector-woocommerce-deactivator.php';
	UVBConnectorWooCommerce_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_uvb_connector_woocommerce' );
register_deactivation_hook( __FILE__, 'deactivate_uvb_connector_woocommerce' );

require plugin_dir_path( __FILE__ ) . 'includes/class-uvb-connector-woocommerce.php';

function uvb_connector_woocommerce_add_plugin_page_settings_link( $links ) {
    $links[] = '<a href="' .
        admin_url( 'options-general.php?page=uvb-connector-woocommerce.php' ) .
        '">' . __('Settings') . '</a>';
    return $links;
}
add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'uvb_connector_woocommerce_add_plugin_page_settings_link');

add_action( 'before_woocommerce_init', function() {
    if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        return;
    }

    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
} );

function run_uvb_connector_woocommerce() {

	$plugin = new UVBConnectorWooCommerce();
	$plugin->run();

}
run_uvb_connector_woocommerce();
