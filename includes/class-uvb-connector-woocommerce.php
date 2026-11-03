<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://utanvet-ellenor.hu
 * @since      1.0.0
 *
 * @package    UVBConnectorWooCommerce
 * @subpackage UVBConnectorWooCommerce/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    UVBConnectorWooCommerce
 * @subpackage UVBConnectorWooCommerce/includes
 * @author     ProteCOD <hello@protecod.com>
 */
class UVBConnectorWooCommerce {

    private ?UVBConnectorWooCommerce_Api $api = null;

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      UVBConnectorWooCommerce_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'UVB_CONNECTOR_WOOCOMMERCE_VERSION' ) ) {
			$this->version = UVB_CONNECTOR_WOOCOMMERCE_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'uvb-connector-woocommerce';

		$this->loadDependencies();
		$this->setLocale();
		$this->defineUvbFlaggedEmailHooks();
		$this->defineAdminHooks();
		$this->definePublicHooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - UVBConnectorWooCommerce_Loader. Orchestrates the hooks of the plugin.
	 * - UVBConnectorWooCommerce_i18n. Defines internationalization functionality.
	 * - UVBConnectorWooCommerce_Admin. Defines all hooks for the admin area.
	 * - UVBConnectorWooCommerce_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function loadDependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-loader.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-api-logger.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-check-result.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-api.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-checkout-result-cache.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-order-result.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-customer-data.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-result-resolver.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-uvb-connector-woocommerce-admin.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-uvb-connector-woocommerce-settings.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'public/class-uvb-connector-woocommerce-public.php';

		$this->loader = new UVBConnectorWooCommerce_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the UVBConnectorWooCommerce_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function setLocale() {
		$plugin_i18n = new UVBConnectorWooCommerce_i18n();

		$this->loader->addAction( 'plugins_loaded', $plugin_i18n, 'loadPluginTextdomain' );
	}

	/**
	 * Register the hooks required by the WooCommerce email system.
	 *
	 * @since 4.3.0
	 */
	private function defineUvbFlaggedEmailHooks() {
		$this->loader->addFilter( 'woocommerce_email_actions', $this, 'addUvbFlaggedEmailAction' );
		$this->loader->addFilter( 'woocommerce_email_classes', $this, 'addUvbFlaggedEmail' );
	}

	/**
	 * Make the custom order status a transactional email action.
	 *
	 * @since 4.3.0
	 * @param array $email_actions WooCommerce transactional email actions.
	 * @return array
	 */
	public function addUvbFlaggedEmailAction( $email_actions ) {
		$email_actions[] = 'woocommerce_order_status_uvb_flagged';

		return $email_actions;
	}

	/**
	 * Add the customer notification to WooCommerce emails.
	 *
	 * @since 4.3.0
	 * @param array $email_classes Registered WooCommerce email instances.
	 * @return array
	 */
	public function addUvbFlaggedEmail( $email_classes ) {
		if ( ! class_exists( 'WC_Email' ) ) {
			return $email_classes;
		}

		require_once plugin_dir_path( __FILE__ ) . 'emails/class-uvb-connector-woocommerce-email-customer-uvb-flagged.php';

		$email_classes['UVBConnectorWooCommerce_Email_Customer_UVB_Flagged'] = new UVBConnectorWooCommerce_Email_Customer_UVB_Flagged();

		return $email_classes;
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function defineAdminHooks() {
		$plugin_admin = new UVBConnectorWooCommerce_Admin( $this->getPluginName(), $this->getVersion(), $this->getApi() );
		$plugin_settings = new UVBConnectorWooCommerce_Settings();

		$this->loader->addAction( 'admin_enqueue_scripts', $plugin_admin, 'enqueueStyles' );

		// Register order status for WooCommerce
		$this->loader->addAction( 'init', $plugin_admin, 'registerOrderStatusFlagged');
		$this->loader->addAction( 'wc_order_statuses', $plugin_admin, 'addOrderStatusFlagged');

		// Add hooks to `completed` and `uvb_flagged` order statuses
		$this->loader->addAction( 'woocommerce_order_status_completed', $plugin_admin, 'sendPlusToUVBService');
		$this->loader->addAction( 'woocommerce_order_status_uvb_flagged', $plugin_admin, 'sendMinusToUVBService');

		// Add hooks needed for order flagging.
		$this->loader->addAction( 'woocommerce_new_order', $plugin_admin, 'flagOrder');
		$this->loader->addAction( 'manage_edit-shop_order_columns', $plugin_admin, 'addColumnFlagged');
		$this->loader->addAction( 'manage_woocommerce_page_wc-orders_columns', $plugin_admin, 'addColumnFlagged');
		$this->loader->addAction( 'manage_shop_order_posts_custom_column', $plugin_admin, 'showFlagNoticeInColumn', 5, 2);
		$this->loader->addAction( 'manage_woocommerce_page_wc-orders_custom_column', $plugin_admin, 'showFlagNoticeInColumn', 5, 2);

		// Add hooks for bulk order status changes
		$this->loader->addFilter('bulk_actions-edit-shop_order', $plugin_admin, 'addUvbActionsToBulkMenu');
		$this->loader->addFilter('bulk_actions-woocommerce_page_wc-orders', $plugin_admin, 'addUvbActionsToBulkMenu');
		$this->loader->addAction('handle_bulk_actions-edit-shop_order', $plugin_admin, 'catchUvbActionFromBulkMenu', 10, 3);
		$this->loader->addAction('handle_bulk_actions-woocommerce_page_wc-orders', $plugin_admin, 'catchUvbActionFromBulkMenu', 10 , 3);

		$this->loader->addAction( 'admin_menu', $plugin_settings, 'addPluginPage');
		$this->loader->addAction( 'admin_init', $plugin_settings, 'pageInit');
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function definePublicHooks() {
		$plugin_public = new UVBConnectorWooCommerce_Public( $this->getPluginName(), $this->getVersion(), $this->getApi() );

		$this->loader->addAction( 'wp_enqueue_scripts', $plugin_public, 'enqueueScripts' );
		$this->loader->addAction( 'woocommerce_blocks_loaded', $plugin_public, 'registerStoreApiUpdateCallback' );

		$this->loader->addAction( 'wp_ajax_check_if_email_is_flagged', $plugin_public, 'checkIfEmailIsFlagged' );
		$this->loader->addAction( 'wp_ajax_nopriv_check_if_email_is_flagged', $plugin_public, 'checkIfEmailIsFlagged' );

		$this->loader->addAction( 'woocommerce_available_payment_gateways', $plugin_public, 'updateAvailablePaymentOptions' );
		$this->loader->addAction( 'woocommerce_checkout_update_order_review', $plugin_public, 'captureCheckoutData' );
		$this->loader->addAction( 'woocommerce_after_checkout_validation', $plugin_public, 'validateSelectedPaymentMethod', 10, 2 );
		$this->loader->addAction( 'woocommerce_checkout_create_order', $plugin_public, 'saveCheckoutReason', 10, 2 );
		$this->loader->addAction( 'woocommerce_store_api_cart_update_customer_from_request', $plugin_public, 'checkIfEmailIsFlaggedStoreApi', 10, 2 );
		$this->loader->addAction( 'woocommerce_store_api_checkout_update_customer_from_request', $plugin_public, 'checkIfEmailIsFlaggedStoreApi', 10, 2 );
		$this->loader->addAction( 'woocommerce_store_api_checkout_update_order_from_request', $plugin_public, 'checkIfEmailIsFlaggedStoreApi', 10, 2 );
	}

    private function getApi(): UVBConnectorWooCommerce_Api {
        return $this->api ??= new UVBConnectorWooCommerce_Api();
    }

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function getPluginName() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    UVBConnectorWooCommerce_Loader    Orchestrates the hooks of the plugin.
	 */
	public function getLoader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function getVersion() {
		return $this->version;
	}

}
