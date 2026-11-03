<?php

class UVBConnectorWooCommerce_Result_Resolver {
    private array $checkoutSessionKeys = [];
    private array $payOrderResults = [];
    private ?array $checkoutResult = null;

    public function __construct(
        private UVBConnectorWooCommerce_Api $api,
        private UVBConnectorWooCommerce_Checkout_Result_Cache $cache
    ) {}

    public function findCheckoutResult($order, array $data): ?array {
        $result = UVBConnectorWooCommerce_Order_Result::readMatching($order, $data) ?? $this->checkoutResult;

        return $result !== null && UVBConnectorWooCommerce_Check_Result::matchesCustomerData($result, $data)
            ? $result : $this->findCachedResult($data);
    }

    public function checkCheckoutCustomer($order, array $data, $cartToken = '', bool $allowRequestCartToken = false): ?array {
        // Keep the validated result until persistence, even if its shared cache entry expires meanwhile.
        $this->checkoutResult = UVBConnectorWooCommerce_Order_Result::readMatching($order, $data)
            ?? $this->checkCustomer($data, $cartToken, $allowRequestCartToken);

        return $this->checkoutResult;
    }

    public function checkOrderPayment($order): ?array {
        $data = UVBConnectorWooCommerce_Customer_Data::fromOrder($order);
        $result = UVBConnectorWooCommerce_Order_Result::read($order);
        if ($result !== null && UVBConnectorWooCommerce_Check_Result::matchesOrderData($result, $data)) {
            return $result;
        }

        $resultKey = $order->get_id() . ':' . UVBConnectorWooCommerce_Customer_Data::getFingerprint($data);
        if (!array_key_exists($resultKey, $this->payOrderResults)) {
            // Remember failures within this request as well: gateway filtering may run repeatedly.
            $this->payOrderResults[$resultKey] = $this->findCachedResult($data)
                ?? $this->checkCustomer($data, 'order:' . $order->get_id(), true);
        }

        return $this->payOrderResults[$resultKey];
    }

    public function getBlockedDecision(?array $result, $order): ?bool {
        if ($result !== null) {
            return (bool) $result['blocked'];
        }

        // An earlier decision for unverified or different data cannot authorize this attempt.
        return UVBConnectorWooCommerce_Order_Result::canReuseSavedResult($order)
            && UVBConnectorWooCommerce_Order_Result::read($order) !== null ? true : null;
    }

    private function findCachedResult(array $data): ?array {
        $sessionKeys = array_keys($this->checkoutSessionKeys);
        $sessionKeys[] = $this->cache->getSessionKey();
        $sessionKeys[] = $this->cache->getSessionKey(null, false);
        foreach (array_unique(array_filter($sessionKeys)) as $sessionKey) {
            $result = $this->cache->getCustomerResult($sessionKey, $data);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private function checkCustomer(array $data, $cartToken, bool $allowRequestCartToken): ?array {
        $data = UVBConnectorWooCommerce_Customer_Data::sanitize($data);
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $sessionKey = $this->cache->getSessionKey($cartToken, $allowRequestCartToken);
        if (!$sessionKey) {
            return null;
        }
        // Account creation may replace the guest session before the order is saved.
        $this->checkoutSessionKeys[$sessionKey] = true;

        return $this->cache->rememberCustomerResult($sessionKey, $data, [$this->api, 'check']);
    }
}
