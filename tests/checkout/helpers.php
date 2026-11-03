<?php

class UVBTestGateway extends WC_Payment_Gateway {
    public int $attempts = 0;
    public int $validations = 0;
    public bool $rejectFields = false;

    public function __construct($id) {
        $this->id = $id;
        $this->enabled = 'yes';
        $this->title = 'Synthetic test gateway';
    }

    public function process_payment($orderId) {
        $this->attempts++;
        return ['result' => 'success', 'redirect' => '/synthetic-payment/'];
    }

    public function validate_fields() {
        $this->validations++;
        if ($this->rejectFields) {
            wc_add_notice('Synthetic gateway field error', 'error');
            return false;
        }
        return true;
    }
}

function uvbTestPublicHooks() {
    foreach ($GLOBALS['wp_filter'] as $hook => $filter) {
        foreach ($filter->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if (is_array($callback['function']) && $callback['function'][0] instanceof UVBConnectorWooCommerce_Public) {
                    remove_filter($hook, $callback['function'], $priority);
                }
            }
        }
    }
    $reflection = new ReflectionClass(UVBConnectorWooCommerce::class);
    $plugin = $reflection->newInstanceWithoutConstructor();
    foreach (['loader' => new UVBConnectorWooCommerce_Loader(), 'plugin_name' => 'uvb-connector-woocommerce', 'version' => 'test'] as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($plugin, $value);
    }
    $method = $reflection->getMethod('definePublicHooks');
    $method->setAccessible(true);
    $method->invoke($plugin);
    $plugin->run();
}

function uvbTestAddress(): array {
    return [
        'first_name' => 'Synthetic',
        'last_name' => 'Checkout test',
        'company' => '',
        'address_1' => 'Test street 1',
        'address_2' => '',
        'city' => 'Budapest',
        'state' => 'BU',
        'postcode' => '1111',
        'country' => 'HU',
        'email' => 'checkout-regression@example.invalid',
        'phone' => '',
    ];
}

function uvbTestSetCustomerAddresses(array $billing): void {
    foreach ($billing as $field => $value) {
        WC()->customer->{'set_billing_' . $field}($value);
        if (is_callable([WC()->customer, 'set_shipping_' . $field])) {
            WC()->customer->{'set_shipping_' . $field}($value);
        }
    }
}

function uvbTestResult(?array $checkedData = null, bool $blocked = true, string $reason = 'Historical reason'): array {
    return UVBConnectorWooCommerce_Order_Result::fromResponse((object) [
        'request_id' => 'synthetic-original-response',
        'result' => (object) ['blocked' => $blocked, 'reason' => $reason],
    ], $checkedData ?? UVBConnectorWooCommerce_Customer_Data::fromAddresses([], uvbTestAddress()));
}

function uvbTestCheckoutData(array $billing, string $method = 'cod'): array {
    $data = ['ship_to_different_address' => false, 'payment_method' => $method, 'terms' => 1, 'createaccount' => false];
    foreach ($billing as $key => $value) { $data['billing_' . $key] = $value; }
    return $data;
}

function uvbTestValidateCheckout(array $data): WP_Error {
    $errors = new WP_Error();
    wc_clear_notices();
    $method = new ReflectionMethod(WC_Checkout::class, 'validate_checkout');
    $method->setAccessible(true);
    $method->invokeArgs(WC()->checkout(), [&$data, &$errors]);
    return $errors;
}

function uvbTestSeedResult(array $billing, bool $blocked): void {
    $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => $blocked, 'reason' => 'Synthetic cached decision']];
    $data = UVBConnectorWooCommerce_Customer_Data::fromAddresses([], $billing);
    (new UVBConnectorWooCommerce_Result_Resolver(new UVBConnectorWooCommerce_Api(), new UVBConnectorWooCommerce_Checkout_Result_Cache()))
        ->checkCheckoutCustomer(null, $data);
}

function uvbTestOrder(bool $withResult = true) {
    $order = new WC_Order();
    $order->set_created_via('store-api');
    $order->set_status('pending');
    $order->set_address(uvbTestAddress(), 'billing');
    $order->set_address(uvbTestAddress(), 'shipping');
    $fee = new WC_Order_Item_Fee();
    $fee->set_name('Synthetic test fee');
    $fee->set_amount(10);
    $fee->set_total(10);
    $order->add_item($fee);
    $order->calculate_totals();
    if ($withResult) { UVBConnectorWooCommerce_Order_Result::write($order, uvbTestResult()); }
    $order->save();
    $GLOBALS['uvbTestOrders'][] = $order->get_id();
    return $order;
}

function uvbTestResumableOrder(string $status, string $createdVia = 'store-api'): WC_Order {
    $order = (new \Automattic\WooCommerce\StoreApi\Utilities\OrderController())->create_order_from_cart();
    $GLOBALS['uvbTestOrders'][] = $order->get_id();
    $order->set_created_via($createdVia);
    $order->set_status($status);

    return $order;
}

function uvbTestClearCheckoutCache(array $transientKeys): void {
    foreach (array_keys($transientKeys) as $key) {
        delete_transient($key);
    }
}

function uvbTestResetCheckoutState(array $transientKeys): void {
    WC()->session->set('order_awaiting_payment', null);
    WC()->session->set('store_api_draft_order', null);
    uvbTestClearCheckoutCache($transientKeys);
}

function uvbTestRetry($order, string $paymentMethod, bool $authorized = true, ?array $billing = null, ?array $shipping = null) {
    uvbTestPublicHooks();
    $request = new WP_REST_Request('POST', '/wc/store/v1/checkout/' . $order->get_id());
    $request->set_header('Nonce', wp_create_nonce('wc_store_api'));
    $request->set_body_params([
        'key' => $authorized ? $order->get_order_key() : 'invalid-test-key',
        'billing_email' => $order->get_billing_email(),
        'billing_address' => $billing ?? $order->get_address('billing'),
        'shipping_address' => $shipping ?? $order->get_address('shipping'),
        'payment_method' => $paymentMethod,
        'payment_data' => [],
        'customer_note' => '',
    ]);
    return rest_do_request($request);
}

function uvbTestStoreSessionRetry($order, string $paymentMethod, ?array $billing = null, ?array $shipping = null) {
    WC()->session->set('store_api_draft_order', $order->get_id());
    return uvbTestStoreCheckout($paymentMethod, $billing ?? $order->get_address('billing'), $shipping ?? $order->get_address('shipping'));
}

function uvbTestStoreCheckout(string $paymentMethod, array $billing, array $shipping) {
    uvbTestPublicHooks();
    $request = new WP_REST_Request('POST', '/wc/store/v1/checkout');
    $request->set_header('Nonce', wp_create_nonce('wc_store_api'));
    $request->set_body_params([
        'billing_address' => $billing,
        'shipping_address' => $shipping,
        'payment_method' => $paymentMethod,
        'payment_data' => [],
        'customer_note' => '',
    ]);
    $routes = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get(\Automattic\WooCommerce\StoreApi\RoutesController::class);
    try {
        return $routes->get('checkout')->get_response($request);
    } finally {
        // A failed checkout can leave a draft before woocommerce_new_order fires.
        $orderId = absint(WC()->session->get('store_api_draft_order'));
        if ($orderId && !in_array($orderId, $GLOBALS['uvbTestOrders'], true)) {
            $GLOBALS['uvbTestOrders'][] = $orderId;
        }
    }
}

function uvbTestClassicRefresh(array $data): array {
    if (!defined('DOING_AJAX')) { define('DOING_AJAX', true); }
    $previousPost = $_POST;
    $previousRequest = $_REQUEST;
    $dieHandler = function() {
        return function() { throw new RuntimeException('Synthetic AJAX response complete'); };
    };
    add_filter('wp_die_handler', $dieHandler);
    add_filter('wp_die_ajax_handler', $dieHandler);
    $_POST = [
        'security' => wp_create_nonce('update-order-review'),
        'post_data' => wp_slash(http_build_query($data)),
        'payment_method' => $data['payment_method'],
    ];
    foreach (['country', 'state', 'postcode', 'city', 'address_1', 'address_2'] as $field) {
        $key = $field === 'address_1' ? 'address' : $field;
        $_POST[$key] = $data['billing_' . $field] ?? '';
        $_POST['s_' . $key] = !empty($data['ship_to_different_address'])
            ? ($data['shipping_' . $field] ?? '') : $_POST[$key];
    }
    $_REQUEST = $_POST;
    ob_start();
    try {
        WC_AJAX::update_order_review();
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'Synthetic AJAX response complete') { throw $exception; }
    } finally {
        $output = ob_get_clean();
        $_POST = $previousPost;
        $_REQUEST = $previousRequest;
        remove_filter('wp_die_handler', $dieHandler);
        remove_filter('wp_die_ajax_handler', $dieHandler);
    }
    $response = json_decode($output, true);
    if (!is_array($response)) { throw new RuntimeException('Invalid synthetic checkout refresh response: ' . substr($output, 0, 300)); }
    return $response;
}
function uvbTestFrontendCheck(string $route, array $data): void {
    uvbTestPublicHooks();
    if ($route === 'blocks') {
        // The extension callback and gateway filter share one plugin instance in Store API requests.
        foreach ($GLOBALS['wp_filter']['woocommerce_available_payment_gateways']->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                if (is_array($callback['function']) && $callback['function'][0] instanceof UVBConnectorWooCommerce_Public) {
                    $callback['function'][0]->checkBlocksCustomer($data);
                    return;
                }
            }
        }
        throw new RuntimeException('Missing checkout gateway filter');
    }

    $previousPost = $_POST;
    $dieHandler = fn() => function() { throw new RuntimeException('Synthetic reputation response complete'); };
    add_filter('wp_die_handler', $dieHandler);
    add_filter('wp_die_ajax_handler', $dieHandler);
    $_POST = wp_slash($data);
    try {
        do_action('wp_ajax_nopriv_check_if_email_is_flagged');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'Synthetic reputation response complete') { throw $exception; }
    } finally {
        $_POST = $previousPost;
        remove_filter('wp_die_handler', $dieHandler);
        remove_filter('wp_die_ajax_handler', $dieHandler);
    }
}
