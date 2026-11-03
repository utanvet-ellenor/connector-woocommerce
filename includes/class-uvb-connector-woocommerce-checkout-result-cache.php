<?php

class UVBConnectorWooCommerce_Checkout_Result_Cache {
    public const TRANSIENT_PREFIX = 'utanvet_ellenor_request_is_blocked';
    public const REQUEST_CACHE_TTL = 30 * 60;
    private const SESSION_KEY = 'uvb_connector_checkout_session_key';
    private readonly array $configuration;

    public function __construct(?array $configuration = null) {
        $this->configuration = $configuration ?? UVBConnectorWooCommerce_Api::getConfiguration();
    }

    public function getSessionKey($cartToken = null, $allowRequestCartToken = true): ?string {
        if (!$cartToken && $allowRequestCartToken && !empty($_SERVER['HTTP_CART_TOKEN'])) {
            $cartToken = sanitize_text_field(wp_unslash($_SERVER['HTTP_CART_TOKEN']));
        }
        if ($cartToken) {
            return implode('_', [self::TRANSIENT_PREFIX, hash('sha256', $cartToken)]);
        }

        $session = WC()->session ?? null;
        if (!$session) {
            return null;
        }

        // Keep the checkout scope when a guest creates an account before payment.
        $checkoutSessionKey = $session->get(self::SESSION_KEY);
        if (is_string($checkoutSessionKey) && $checkoutSessionKey !== '') {
            return $checkoutSessionKey;
        }

        $customerUniqueId = null;
        if (method_exists($session, 'get_customer_unique_id')) {
            $customerUniqueId = $session->get_customer_unique_id();
        } elseif (method_exists($session, 'get_customer_id')) {
            $customerUniqueId = $session->get_customer_id();
        } elseif (method_exists($session, 'get_session_id')) {
            $customerUniqueId = $session->get_session_id();
        }

        if (!$customerUniqueId) {
            return null;
        }

        return implode('_', [self::TRANSIENT_PREFIX, $customerUniqueId]);
    }

    public function getRequestKey($sessionKey, $email, $countryCode, $postalCode, $phoneNumber, $addressLine): string {
        return $this->getCustomerRequestKey($sessionKey, [
            'email' => $email,
            'country_code' => $countryCode,
            'postal_code' => $postalCode,
            'phone_number' => $phoneNumber,
            'address_line' => $addressLine,
        ]);
    }

    public function getCustomerRequestKey($sessionKey, array $data): string {
        $payload = wp_json_encode(array_merge(
            UVBConnectorWooCommerce_Customer_Data::normalize($data),
            $this->configuration
        ));

        return implode('_', [
            self::TRANSIENT_PREFIX,
            'request',
            hash('sha256', $sessionKey . "\0" . $payload),
        ]);
    }

    public function getCustomerResult($sessionKey, array $data): ?array {
        return $this->readCustomerResult($sessionKey, $this->getCustomerRequestKey($sessionKey, $data), $data);
    }

    public function rememberCustomerResult($sessionKey, array $data, callable $checkCustomer): ?array {
        $requestKey = $this->getCustomerRequestKey($sessionKey, $data);
        $result = $this->readCustomerResult($sessionKey, $requestKey, $data);
        if ($result !== null) {
            $this->storeResult($sessionKey, $requestKey, $result);
            return $result;
        }

        $this->clearResult($sessionKey);
        $result = $checkCustomer($data);
        if ($result === null) {
            return null;
        }

        return $this->storeRequestResult($sessionKey, $requestKey, $result);
    }

    private function readCustomerResult($sessionKey, $requestKey, array $data): ?array {
        $result = $this->getResult($sessionKey, $requestKey);
        if ($result === null) {
            return null;
        }

        // The exact request key also binds cache entries created before fingerprints existed.
        $result['customer_fingerprint'] = UVBConnectorWooCommerce_Customer_Data::getFingerprint($data);

        return $result;
    }

    public function storeRequestResult($sessionKey, $requestKey, array $result): array {
        $result['expires_at'] = $result['checked_at'] + self::REQUEST_CACHE_TTL;
        set_transient($requestKey, $result, self::REQUEST_CACHE_TTL);
        $this->storeResult($sessionKey, $requestKey, $result);

        return $result;
    }

    public function storeResult($sessionKey, $requestKey, array $result): void {
        if (!$sessionKey) {
            return;
        }

        if ($sessionKey === $this->getSessionKey(null, false)) {
            WC()->session->set(self::SESSION_KEY, $sessionKey);
        }

        $remainingLifetime = max(0, ($result['expires_at'] ?? (time() + self::REQUEST_CACHE_TTL)) - time());
        if ($remainingLifetime === 0) {
            return;
        }

        // Cache hits must not extend the lifetime of the original API response.
        set_transient($sessionKey . '_result', [
            'request_key' => $requestKey,
        ], $remainingLifetime);
    }

    public function getResult($sessionKey, $requestKey): ?array {
        if (!$sessionKey) {
            return null;
        }

        $result = get_transient($requestKey);
        if (!is_array($result) || !array_key_exists('blocked', $result)) {
            return null;
        }

        if (isset($result['expires_at']) && $result['expires_at'] <= time()) {
            return null;
        }

        return $result;
    }

    public function getCurrentResult($sessionKey): ?array {
        $snapshot = $sessionKey ? get_transient($sessionKey . '_result') : false;
        if (!is_array($snapshot) || empty($snapshot['request_key'])) {
            return null;
        }

        // This also limits old 24-hour snapshots to their request cache's lifetime.
        return $this->getResult($sessionKey, $snapshot['request_key']);
    }

    public function clearResult($sessionKey): void {
        if (!$sessionKey) {
            return;
        }

        delete_transient($sessionKey . '_result');
        delete_transient($sessionKey);
    }
}
