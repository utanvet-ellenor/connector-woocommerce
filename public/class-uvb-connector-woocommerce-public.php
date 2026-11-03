<?php

require UVB_CONNECTOR_VENDOR_AUTOLOAD_PATH;

class UVBConnectorWooCommerce_Public {

	private $plugin_name;

	private $version;

    private $api;
    private $checkoutResultCache;
    private $resultResolver;
    private $paymentOrder = null;
    private $checkoutDecision = null;
    private $checkoutData = null;
    private $storeApiRequest = false;

	public function __construct( $plugin_name, $version, ?UVBConnectorWooCommerce_Api $api = null ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

        $this->api = $api ?? new UVBConnectorWooCommerce_Api();
        $this->checkoutResultCache = new UVBConnectorWooCommerce_Checkout_Result_Cache($this->api->getConfigurationSnapshot());
        $this->resultResolver = new UVBConnectorWooCommerce_Result_Resolver($this->api, $this->checkoutResultCache);
	}

	public function enqueueScripts() {
        if (!function_exists( 'is_woocommerce')) {
            return;
        }

        if (!is_cart() && !is_checkout()) {
            return;
        }

        if (is_wc_endpoint_url() || (WC()->cart && WC()->cart->is_empty())) {
            return;
        }

        $usesBlocks = has_block('woocommerce/checkout') || has_block('woocommerce/cart');
        $dependencies = ['jquery'];
        if ($usesBlocks) {
            $dependencies = array_merge($dependencies, ['wp-data', 'wc-blocks-data-store', 'wc-blocks-checkout']);
        }

        wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/uvb-connector-woocommerce-public.js', $dependencies, $this->version, true );
        wp_localize_script( $this->plugin_name, 'ajax_object', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'uses_blocks' => $usesBlocks,
            'blocks_namespace' => $this->plugin_name,
            'checking_message' => __('[ProteCOD] Checking...', 'uvb-connector-woocommerce'),
            'checked_message' => __('[ProteCOD] Done!', 'uvb-connector-woocommerce')
        ) );
	}

    public function registerStoreApiUpdateCallback(): void {
        if (!function_exists('woocommerce_store_api_register_update_callback')) {
            return;
        }

        woocommerce_store_api_register_update_callback([
            'namespace' => $this->plugin_name,
            'callback' => [$this, 'checkBlocksCustomer'],
        ]);
    }

    public function checkBlocksCustomer($data): void {
        if (!is_array($data) || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }

        $this->storeApiRequest = true;
        $this->checkCheckoutCustomer($this->getResumableCheckoutOrder(), $data, '', true);
    }

    public function captureCheckoutData($postedData): void {
        if (!is_string($postedData)) {
            return;
        }

        // update_order_review does not copy the submitted email or phone to WC_Customer.
        $data = [];
        wp_parse_str($postedData, $data);
        $this->checkoutData = UVBConnectorWooCommerce_Customer_Data::fromCheckout($data);
        $this->checkoutDecision = null;
    }

	public function checkIfEmailIsFlagged() {
        $this->checkCheckoutCustomer($this->getResumableCheckoutOrder(), wp_unslash($_POST));

        wp_die();
    }

    /**
     * @param WC_Customer|WC_Order $object
     * @param WP_REST_Request $request
     */
    public function checkIfEmailIsFlaggedStoreApi($object, $request) {
        if (!$request || !is_object($request) || !method_exists($request, 'get_param')) {
            return;
        }
        $this->storeApiRequest = true;

        if (method_exists($request, 'get_route')
            && preg_match('#^/wc/store(?:/v[0-9]+)?/checkout/[0-9]+/?$#', $request->get_route())) {
            $this->checkStoreApiPaymentRetry($object, $request);
            return;
        }

        $this->checkStoreApiCheckout($object, $request);
    }

    private function checkStoreApiPaymentRetry($object, $request): void {
        // CheckoutOrder authorizes the request before this customer hook and validates gateways afterwards.
        $order = $object instanceof WC_Order ? $object : wc_get_order(absint($request->get_param('id')));
        if (!$order instanceof WC_Order) {
            return;
        }

        $updatingOrder = $object instanceof WC_Order;
        $shipping = $request->get_param('shipping_address') ?? $request->get_param('billing_address');
        if (!$updatingOrder) {
            // CheckoutOrder saves these addresses after the customer hook. Apply them only in memory here.
            $billing = $request->get_param('billing_address');
            if (is_array($billing)) {
                $order->set_billing_address($billing);
            }
            if (is_array($shipping)) {
                $order->set_shipping_address($shipping);
            }
        }
        if (isset($shipping['phone'])) {
            $this->recordShippingPhonePresence($order, true);
        }

        $this->paymentOrder = $order;
        $result = $this->getPayOrderResult($order, $updatingOrder);
        $this->validateStoreApiPaymentMethod(
            (string) $request->get_param('payment_method'),
            $result === null || $result['blocked']
        );
    }

    private function checkStoreApiCheckout($object, $request): void {
        if (!($object instanceof WC_Order) && WC()->cart && WC()->cart->is_empty()) {
            return;
        }

        $billing = $request->get_param('billing_address');
        $shipping = $request->get_param('shipping_address');

        $shipping = is_array($shipping) ? $shipping : [];
        $billing = is_array($billing) ? $billing : [];
        if (!$shipping && !$billing) {
            return;
        }
        if ($object instanceof WC_Order) {
            $this->recordShippingPhonePresence($object, isset($shipping['phone']));
        }

        $data = $this->getStoreApiCheckoutData($object, $request, $shipping, $billing);
        $cartToken = method_exists($request, 'get_header') ? $request->get_header('cart-token') : '';
        $resumedOrder = $object instanceof WC_Order ? $object : $this->getResumableCheckoutOrder();
        $result = $this->checkCheckoutCustomer($resumedOrder, $data, $cartToken, true);
        if ($object instanceof WC_Order) {
            UVBConnectorWooCommerce_Order_Result::writeCheckoutResult($object, $result, $data);
        }

        $this->validateStoreApiPaymentMethod(
            sanitize_text_field((string) $request->get_param('payment_method')),
            $this->checkoutDecision === true
        );
    }

    private function getStoreApiCheckoutData($object, $request, array $shipping, array $billing): array {
        // Checkout checks and persistence must use the same submitted fields, even when
        // WooCommerce replaces a virtual cart's shipping record with billing data.
        // Cart address updates are partial patches; their customer object contains the merged data.
        $isCheckoutRequest = method_exists($request, 'get_route')
            && preg_match('#^/wc/store(?:/v[0-9]+)?/checkout/?$#', $request->get_route());
        if ($isCheckoutRequest) {
            return UVBConnectorWooCommerce_Customer_Data::fromAddresses($shipping, $billing);
        }

        return UVBConnectorWooCommerce_Customer_Data::fromAddresses($object->get_shipping(), $object->get_billing());
    }

    private function recordShippingPhonePresence($order, bool $provided): void {
        $order->update_meta_data(UVBConnectorWooCommerce_Customer_Data::SHIPPING_PHONE_PROVIDED_META_KEY, $provided ? 'yes' : 'no');
    }

    public function validateSelectedPaymentMethod($data, $errors) {
        $paymentAvailabilityError = $errors->get_error_messages('payment') === [__('Invalid payment method.', 'woocommerce')];
        if ($errors->has_errors() && (!$paymentAvailabilityError || array_diff($errors->get_error_codes(), ['payment']))) {
            return;
        }

        $paymentMethod = sanitize_text_field($data['payment_method'] ?? '');
        $this->checkCheckoutCustomer(
            $this->getResumableCheckoutOrder(),
            UVBConnectorWooCommerce_Customer_Data::fromCheckout($data)
        );

        // The submitted data can make a previously available fallback unavailable, or restore a hidden gateway.
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        if ($paymentAvailabilityError && isset($gateways[$paymentMethod])) {
            $errors->remove('payment');
            $gateways[$paymentMethod]->validate_fields();
        }
        if (($this->checkoutDecision === true && $this->isProtectedPaymentMethod($paymentMethod))
            || (WC()->cart->needs_payment() && !isset($gateways[$paymentMethod]))) {
            $errors->add(
                'uvb_payment_method_unavailable',
                $this->getPaymentUnavailableMessage()
            );
        }
    }

    public function saveCheckoutReason($order, $checkoutData = null): void {
        if ($checkoutData) {
            $shippingPhoneProvided = !empty($checkoutData['ship_to_different_address']) && isset($checkoutData['shipping_phone']);
            $this->recordShippingPhonePresence($order, $shippingPhoneProvided);
        }
        $data = $checkoutData
            ? UVBConnectorWooCommerce_Customer_Data::fromCheckout($checkoutData)
            : UVBConnectorWooCommerce_Customer_Data::fromOrder($order);
        if (!$data['email']) {
            return;
        }

        UVBConnectorWooCommerce_Order_Result::writeCheckoutResult($order, $this->resultResolver->findCheckoutResult($order, $data), $data);
    }

    private function checkCheckoutCustomer($order, array $data, $cartToken = '', bool $allowRequestCartToken = false): ?array {
        $this->checkoutData = UVBConnectorWooCommerce_Customer_Data::normalize($data);
        $result = $this->resultResolver->checkCheckoutCustomer($order, $data, $cartToken, $allowRequestCartToken);
        $this->checkoutDecision = $this->resultResolver->getBlockedDecision($result, $order);

        return $result;
    }

    private function validateStoreApiPaymentMethod(string $paymentMethod, bool $blocked): void {
        if ($blocked && $this->isProtectedPaymentMethod($paymentMethod)) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'uvb_payment_method_unavailable',
                $this->getPaymentUnavailableMessage(),
                400
            );
        }
    }

    private function getPaymentUnavailableMessage(): string {
        return __('The selected payment method is not available. Please choose another one.', 'uvb-connector-woocommerce');
    }

    private function isProtectedPaymentMethod($paymentMethod): bool {
        return $paymentMethod && in_array($paymentMethod, $this->getPaymentMethods('payment_methods_to_hide'), true);
    }

    private function getPaymentMethods(string $optionName): array {
        $options = get_option('uvb_connector_woocommerce_options');
        $options = is_array($options) ? $options : [];
        $paymentMethods = $options[$optionName] ?? [];
        if (!is_array($paymentMethods)) {
            return [];
        }

        return $paymentMethods;
    }

    public function checkInUVBService($email, $countryCode = '', $postalCode = '', $phoneNumber = '', $addressLine = '') {
        return $this->api->request([
            'email' => $email,
            'country_code' => $countryCode,
            'postal_code' => $postalCode,
            'phone_number' => $phoneNumber,
            'address_line' => $addressLine,
        ]);
    }

    public function removePaymentMethods($available_gateways) : array {
        return $this->excludePaymentMethods($available_gateways, 'payment_methods_to_hide');
    }

    public function hideFallbackPaymentMethod($available_gateways) : array {
        return $this->excludePaymentMethods($available_gateways, 'fallback_payment_methods');
    }

    private function excludePaymentMethods(array $gateways, string $optionName): array {
        $paymentMethods = $this->getPaymentMethods($optionName);
        foreach (array_keys($gateways) as $paymentMethod) {
            if (!in_array($paymentMethod, $paymentMethods)) {
                continue;
            }

            unset($gateways[$paymentMethod]);
        }

        return $gateways;
    }

    public function getSessionBlockedStateKey($cartToken = null, $allowRequestCartToken = true) {
        return $this->checkoutResultCache->getSessionKey($cartToken, $allowRequestCartToken);
    }

    public function updateAvailablePaymentOptions($available_gateways) {
        if ( is_admin() ) {
            return $this->hideFallbackPaymentMethod($available_gateways);
        }

        $payOrder = $this->paymentOrder ?: $this->getPayOrder();
        if ($payOrder) {
            // Store API persists the decision in its order-update hook, after accepting the addresses.
            $result = $this->getPayOrderResult($payOrder, $this->paymentOrder === null);
            // An unknown legacy result must not expose a protected payment method.
            if ($result === null || $result['blocked'] !== false) {
                return $this->removePaymentMethods($available_gateways);
            }

            return $this->hideFallbackPaymentMethod($available_gateways);
        }

        $resumedOrder = $this->getResumableCheckoutOrder();
        $savedResult = $resumedOrder ? UVBConnectorWooCommerce_Order_Result::read($resumedOrder) : null;
        if ($this->checkoutData !== null || $savedResult !== null) {
            // Another tab's current result must not override this order's saved restriction.
            $data = $this->checkoutData
                ?? UVBConnectorWooCommerce_Customer_Data::fromAddresses(WC()->customer->get_shipping(), WC()->customer->get_billing());
            $result = $this->resultResolver->findCheckoutResult($resumedOrder, $data);
        } else {
            $result = $this->checkoutResultCache->getCurrentResult($this->getSessionBlockedStateKey());
        }
        $blocked = $this->checkoutDecision ?? $this->resultResolver->getBlockedDecision($result, $resumedOrder);
        if ($blocked) {
            return $this->removePaymentMethods($available_gateways);
        }

        return $this->hideFallbackPaymentMethod($available_gateways);
    }

    private function getResumableCheckoutOrder() {
        if (!WC()->session || !WC()->cart || WC()->cart->is_empty()) {
            return null;
        }

        $isStoreApi = $this->storeApiRequest || (is_callable([WC(), 'is_store_api_request']) && WC()->is_store_api_request());
        // Each checkout resumes only its own session order; the other key may refer to an unrelated order.
        $orderId = absint(WC()->session->get($isStoreApi ? 'store_api_draft_order' : 'order_awaiting_payment'));
        $order = $orderId ? wc_get_order($orderId) : null;
        if (!$order || !$order->has_status(['pending', 'failed']) || !$order->has_cart_hash(WC()->cart->get_cart_hash())
            || !current_user_can('pay_for_order', $orderId)) {
            return null;
        }
        if ($isStoreApi && ($order->get_created_via() !== 'store-api' || !$order->needs_payment())) {
            return null;
        }

        return $order;
    }

    private function getPayOrder() {
        if (!is_checkout_pay_page()) {
            return null;
        }

        $order = wc_get_order(absint(get_query_var('order-pay')));
        $orderKey = isset($_GET['key']) && is_string($_GET['key']) ? wp_unslash($_GET['key']) : '';
        if (!$order || !$order->needs_payment() || !hash_equals($order->get_order_key(), $orderKey)
            || !current_user_can('pay_for_order', $order->get_id())) {
            return null;
        }

        return $order;
    }

    private function getPayOrderResult($order, bool $persistResult = true): ?array {
        $result = $this->resultResolver->checkOrderPayment($order);
        if (!$persistResult || $result === UVBConnectorWooCommerce_Order_Result::read($order)) {
            return $result;
        }

        $data = UVBConnectorWooCommerce_Customer_Data::fromOrder($order);
        if (!UVBConnectorWooCommerce_Order_Result::writeCheckoutResult($order, $result, $data)) {
            return $result;
        }

        $order->save_meta_data();

        return $result;
    }

}
