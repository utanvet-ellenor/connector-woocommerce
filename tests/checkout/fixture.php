<?php

class UVBCheckoutTestFixture {
    public array $checks = [];
    public array $transientKeys = [];
    public ?WC_Product_Simple $product = null;
    public UVBTestGateway $bacs;
    public UVBTestGateway $cod;
    private array $originalHooks;
    private array $originalFailureStates = [];
    private array $originalQueryVars;
    private array $originalRequest;
    private $originalPost;
    private $originalCustomer;
    private array $originalGateways;

    public function __construct(private string $scenario) {
        $this->originalHooks = array_map(fn($hook) => clone $hook, $GLOBALS['wp_filter']);
        $this->originalQueryVars = [$GLOBALS['wp']->query_vars, $GLOBALS['wp_query']->query_vars];
        $this->originalRequest = [$_GET, $_POST, $_REQUEST];
        $this->originalPost = $GLOBALS['post'] ?? null;
        $this->originalCustomer = WC()->customer;
        $this->originalGateways = WC()->payment_gateways()->payment_gateways;
        foreach (['request', 'order_flag', 'signal'] as $operation) {
            $this->originalFailureStates[$operation] = get_option('uvb_connector_api_failure_' . $operation, false);
        }
    }

    public function setUp(bool $withCart): void {
        $GLOBALS['uvbTestApiCalls'] = 0;
        $GLOBALS['uvbTestApiResponse'] = null;
        $GLOBALS['uvbTestLastApiRequest'] = null;
        $GLOBALS['uvbTestLastApiUrl'] = null;
        $GLOBALS['uvbTestOrders'] = [];
        WC()->cart->empty_cart();
        $this->clearSession();
        WC()->customer = new WC_Customer(0, true);
        uvbTestSetCustomerAddresses(uvbTestAddress());
        wc_clear_notices();
        WC()->session->set_customer_session_cookie(true);
        add_action('set_transient', [$this, 'trackTransient']);
        uvbTestPublicHooks();
        $this->bacs = new UVBTestGateway('bacs');
        $this->cod = new UVBTestGateway('cod');
        WC()->payment_gateways()->payment_gateways = [$this->bacs, $this->cod, new UVBTestGateway('card')];

        if (!$withCart) {
            return;
        }

        // A virtual product exercises native checkout without configuring shipping methods.
        $this->product = new WC_Product_Simple();
        $this->product->set_name('Synthetic checkout regression');
        $this->product->set_status('publish');
        $this->product->set_catalog_visibility('hidden');
        $this->product->set_virtual(true);
        $this->product->set_regular_price('10');
        $this->product->save();
        WC()->cart->add_to_cart($this->product->get_id());
        WC()->cart->calculate_totals();
    }

    public function record(string $name, bool $passed, $details = null): void {
        $this->checks[] = ['scenario' => $this->scenario, 'check' => $name, 'passed' => $passed, 'details' => $details];
    }

    public function trackTransient(string $key): void {
        if (strpos($key, UVBConnectorWooCommerce_Checkout_Result_Cache::TRANSIENT_PREFIX) !== 0) {
            return;
        }

        $this->transientKeys[$key] = true;
    }

    public function tearDown(): void {
        try {
            WC()->cart->empty_cart();
            foreach ($GLOBALS['uvbTestOrders'] as $orderId) {
                $order = wc_get_order($orderId);
                if (!$order) {
                    continue;
                }

                wc_maybe_increase_stock_levels($orderId);
                $order->delete(true);
            }
            if ($this->product) {
                $this->product->delete(true);
            }
        } finally {
            uvbTestClearCheckoutCache($this->transientKeys);
            foreach ($this->originalFailureStates as $operation => $state) {
                if ($state === false) {
                    delete_option('uvb_connector_api_failure_' . $operation);
                } else {
                    update_option('uvb_connector_api_failure_' . $operation, $state, false);
                }
            }
            $this->clearSession();
            WC()->customer = $this->originalCustomer;
            WC()->payment_gateways()->payment_gateways = $this->originalGateways;
            [$GLOBALS['wp']->query_vars, $GLOBALS['wp_query']->query_vars] = $this->originalQueryVars;
            [$_GET, $_POST, $_REQUEST] = $this->originalRequest;
            $GLOBALS['post'] = $this->originalPost;
            $GLOBALS['wp_filter'] = $this->originalHooks;
        }
    }

    private function clearSession(): void {
        foreach (array_keys(WC()->session->get_session_data()) as $key) {
            WC()->session->__unset($key);
        }
    }
}
