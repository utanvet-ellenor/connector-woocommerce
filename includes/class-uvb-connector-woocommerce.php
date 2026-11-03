<?php

class UVBConnectorWooCommerce {

    private ?UVBConnectorWooCommerce_Api $api = null;

	/** @var UVBConnectorWooCommerce_Loader */
	protected $loader;

	protected $plugin_name;

	protected $version;

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

	private function loadDependencies() {

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-loader.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-api-logger.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-check-result.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-api.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-checkout-result-cache.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-order-result.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-customer-data.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-result-resolver.php';

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-uvb-connector-woocommerce-i18n.php';

		require_once plugin_dir_path( __DIR__ ) . 'admin/class-uvb-connector-woocommerce-admin.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-uvb-connector-woocommerce-settings.php';

		require_once plugin_dir_path( __DIR__ ) . 'public/class-uvb-connector-woocommerce-public.php';

		$this->loader = new UVBConnectorWooCommerce_Loader();

	}

	private function setLocale() {
		$plugin_i18n = new UVBConnectorWooCommerce_i18n();

		$this->loader->addAction( 'plugins_loaded', $plugin_i18n, 'loadPluginTextdomain' );
	}

	private function defineUvbFlaggedEmailHooks() {
		$this->loader->addFilter( 'woocommerce_email_actions', $this, 'addUvbFlaggedEmailAction' );
		$this->loader->addFilter( 'woocommerce_email_classes', $this, 'addUvbFlaggedEmail' );
	}

	public function addUvbFlaggedEmailAction( $email_actions ) {
		$email_actions[] = 'woocommerce_order_status_uvb_flagged';

		return $email_actions;
	}

	public function addUvbFlaggedEmail( $email_classes ) {
		if ( ! class_exists( 'WC_Email' ) ) {
			return $email_classes;
		}

		require_once plugin_dir_path( __FILE__ ) . 'emails/class-uvb-connector-woocommerce-email-customer-uvb-flagged.php';

		$email_classes['UVBConnectorWooCommerce_Email_Customer_UVB_Flagged'] = new UVBConnectorWooCommerce_Email_Customer_UVB_Flagged();

		return $email_classes;
	}

	private function defineAdminHooks() {
		$plugin_admin = new UVBConnectorWooCommerce_Admin( $this->getPluginName(), $this->getVersion(), $this->getApi() );
		$plugin_settings = new UVBConnectorWooCommerce_Settings();

		$this->loader->addAction( 'admin_enqueue_scripts', $plugin_admin, 'enqueueStyles' );

		$this->loader->addAction( 'init', $plugin_admin, 'registerOrderStatusFlagged');
		$this->loader->addAction( 'wc_order_statuses', $plugin_admin, 'addOrderStatusFlagged');

		$this->loader->addAction( 'woocommerce_order_status_completed', $plugin_admin, 'sendPlusToUVBService');
		$this->loader->addAction( 'woocommerce_order_status_uvb_flagged', $plugin_admin, 'sendMinusToUVBService');

		$this->loader->addAction( 'woocommerce_new_order', $plugin_admin, 'flagOrder');
		$this->loader->addAction( 'manage_edit-shop_order_columns', $plugin_admin, 'addColumnFlagged');
		$this->loader->addAction( 'manage_woocommerce_page_wc-orders_columns', $plugin_admin, 'addColumnFlagged');
		$this->loader->addAction( 'manage_shop_order_posts_custom_column', $plugin_admin, 'showFlagNoticeInColumn', 5, 2);
		$this->loader->addAction( 'manage_woocommerce_page_wc-orders_custom_column', $plugin_admin, 'showFlagNoticeInColumn', 5, 2);

		$this->loader->addFilter('bulk_actions-edit-shop_order', $plugin_admin, 'addUvbActionsToBulkMenu');
		$this->loader->addFilter('bulk_actions-woocommerce_page_wc-orders', $plugin_admin, 'addUvbActionsToBulkMenu');
		$this->loader->addAction('handle_bulk_actions-edit-shop_order', $plugin_admin, 'catchUvbActionFromBulkMenu', 10, 3);
		$this->loader->addAction('handle_bulk_actions-woocommerce_page_wc-orders', $plugin_admin, 'catchUvbActionFromBulkMenu', 10 , 3);

		$this->loader->addAction( 'admin_menu', $plugin_settings, 'addPluginPage');
		$this->loader->addAction( 'admin_init', $plugin_settings, 'pageInit');
	}

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

	public function run() {
		$this->loader->run();
	}

	public function getPluginName() {
		return $this->plugin_name;
	}

	/** @return UVBConnectorWooCommerce_Loader */
	public function getLoader() {
		return $this->loader;
	}

	public function getVersion() {
		return $this->version;
	}

}
