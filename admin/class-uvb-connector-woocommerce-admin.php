<?php

require UVB_CONNECTOR_VENDOR_AUTOLOAD_PATH;

use UtanvetEllenor\Reasons;

class UVBConnectorWooCommerce_Admin {

    private $plugin_name;

    private $version;

    private $api;

    public function __construct( $plugin_name, $version, ?UVBConnectorWooCommerce_Api $api = null ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        $this->api = $api ?? new UVBConnectorWooCommerce_Api();
    }

    public function enqueueStyles() {
        wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/uvb-connector-woocommerce-admin.css', array(), $this->version, 'all' );
    }

    public function registerOrderStatusFlagged() {
        register_post_status( 'wc-uvb_flagged', array(
            'label'                     => 'Rendelést nem vette át',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Rendelést nem vette át <span class="count">(%s)</span>', 'Rendelést nem vette át <span class="count">(%s)</span>' )
        ) );
    }

    public function addOrderStatusFlagged($order_statuses)
    {
        $order_statuses['wc-uvb_flagged'] = 'Rendelést nem vette át';

        return $order_statuses;
    }

    public function addColumnFlagged($columns)
    {
        $columns['uvb_status'] = __('ProteCOD status', 'uvb-connector-woocommerce');

        return $columns;
    }

    public function flagOrder($orderId)
    {
        $order = wc_get_order($orderId);
        if (!$order || $order->has_status('checkout-draft')) {
            return;
        }

        // Checkout attaches its result before saving the order, including on retries.
        if (in_array($order->get_created_via(), ['checkout', 'store-api'], true)) {
            return;
        }

        $data = UVBConnectorWooCommerce_Customer_Data::fromOrder($order);
        if (!$data['email']) {
            return;
        }

        $result = $this->api->check($data, 'order_flag');
        if ($result === null) {
            return;
        }

        UVBConnectorWooCommerce_Order_Result::write($order, $result);
        $order->save_meta_data();
    }

    public static function writeFlagToDb($orderId, $flagValue)
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        UVBConnectorWooCommerce_Order_Result::writeLegacyReason($order, $flagValue);
        $order->save_meta_data();
    }

    public static function getFlagFromDb($orderId)
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return '';
        }

        return UVBConnectorWooCommerce_Order_Result::getReason($order);
    }

    public static function getFlagLevel(string $flag) : string
    {
        switch ($flag) {
            case Reasons::EXCEPTION_FOUND:
            case Reasons::NOT_FOUND:
            case Reasons::TEST_HASH:
                return 'notice';

            case Reasons::OUT_OF_QUOTA:
            case 'warning':
                return 'warning';

            case Reasons::MAILBOX_NON_EXISTENT:
            case Reasons::THRESHOLD_NOT_MET:
            case Reasons::TEMP_EMAIL:
            case 'error':
                return 'error';

            case Reasons::PASSED:
                return 'success';

            default:
                return 'success';
        }
    }

    public static function getFlagLabel(string $flag) : string
    {
        if ($flag === 'error') {
            return 'Figyelem!';
        }

        if ($flag === 'warning') {
            return 'Bizonytalan eredmény.';
        }

        return __($flag, 'uvb-connector-woocommerce');
    }

    public function showFlagNoticeInColumn($column, $order)
    {
        if ($column !== 'uvb_status') {
            return;
        }

        $flag = self::getFlagFromDb(self::isHposActive() ? json_decode($order)->id : $order);
        if (!$flag) {
            return;
        }

        $label = self::getFlagLabel($flag);

        $appearances = [
            'error' => [
                'color' => '#db3535',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" style="width: 1.25rem; height: 1.25rem; margin-right: 0.75rem; flex: none;" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25Zm-1.72 6.97a.75.75 0 1 0-1.06 1.06L10.94 12l-1.72 1.72a.75.75 0 1 0 1.06 1.06L12 13.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L13.06 12l1.72-1.72a.75.75 0 1 0-1.06-1.06L12 10.94l-1.72-1.72Z" clip-rule="evenodd" /></svg>',
            ],
            'warning' => [
                'color' => '#c68c02',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" style="width: 1.25rem; height: 1.25rem; margin-right: 0.75rem; flex: none;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>',
            ],
            'success' => [
                'color' => '#2c9188',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" style="width: 1.25rem; height: 1.25rem; margin-right: 0.75rem; flex: none;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>',
            ],
            'notice' => [
                'color' => '#777',
                'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" style="width: 1.25rem; height: 1.25rem; margin-right: 0.75rem; flex: none;" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>',
            ],
        ];
        $appearance = $appearances[self::getFlagLevel($flag)] ?? $appearances['notice'];

        echo '<div style="display: flex; align-items: start; font-weight: bold; font-size: 12px; line-height: 1.5; color: ' . $appearance['color'] . ';"'
            . ' title="' . esc_attr($label) . '">' . $appearance['icon'] . esc_html($label) . '</div>';
    }

    public function sendMinusToUVBService($order_id)
    {
        return $this->sendSignalToUVBService($order_id, -1);
    }

    public function sendPlusToUVBService($order_id)
    {
        return $this->sendSignalToUVBService($order_id, 1);
    }

    public function sendSignalToUVBService($order_id, $outcome)
    {
        $order = wc_get_order($order_id);

        if ($outcome === -1) {
            wc_maybe_increase_stock_levels($order_id);
        }

        $data = UVBConnectorWooCommerce_Customer_Data::fromOrder($order);
        if (!$data['email']) {
            return null;
        }

        return $this->api->sendSignal($data, $order_id, $outcome);
    }

    public function addUvbActionsToBulkMenu($actions)
    {
        $actions['set-uvb_flagged'] = 'Change status to Rendelést nem vette át';

        return $actions;
    }

    public function catchUvbActionFromBulkMenu($redirect, $doaction, $postIds)
    {
        if (!wp_verify_nonce( $_GET['_wpnonce'], 'bulk-posts') && !wp_verify_nonce( $_GET['_wpnonce'], 'bulk-orders')) {
            return $redirect;
        }

        if (!self::isOrdersListPage()) {
            return $redirect;
        }

        $action = $doaction ?? null;
        if (!$action) {
            return $redirect;
        }

        $status = substr($action, 4, strlen($action) - 4);
        if ($status !== 'uvb_flagged') {
            return $redirect;
        }

        foreach ($postIds as $postId) {
            self::updateOrderStatus($postId, $status);
        }

        return $redirect;
    }

    public static function isHposActive()
    {
        return class_exists(Automattic\WooCommerce\Utilities\OrderUtil::class) && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    public static function isOrdersListPage()
    {
        if (!self::isHposActive()) {
            $postType = $_GET['post_type'] ?? null;

            return $postType == 'shop_order';
        }

        $page = $_GET['page'] ?? null;

        return $page == 'wc-orders';
    }

    public static function updateOrderStatus($orderId, $status)
    {
        if (!is_numeric($orderId)) {
            return;
        }

        $order = wc_get_order($orderId);
        $order->update_status($status);
    }
}
