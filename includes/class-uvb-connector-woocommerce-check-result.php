<?php

class UVBConnectorWooCommerce_Check_Result {
    public static function fromResponse($response, ?array $checkedData = null): ?array {
        if (!is_object($response) || !isset($response->result->blocked)) {
            return null;
        }

        return [
            'blocked' => (bool) $response->result->blocked,
            'reason' => $response->result->reason ?? null,
            'checked_at' => time(),
            'api_response' => json_decode(wp_json_encode($response), true),
            'customer_fingerprint' => $checkedData !== null ? UVBConnectorWooCommerce_Customer_Data::getFingerprint($checkedData) : null,
        ];
    }

    public static function matchesCustomerData(array $result, array $data): bool {
        $fingerprint = $result['customer_fingerprint'] ?? null;

        return is_string($fingerprint) && hash_equals($fingerprint, UVBConnectorWooCommerce_Customer_Data::getFingerprint($data));
    }

    public static function matchesOrderData(array $result, array $data): bool {
        $fingerprint = $result['order_fingerprint'] ?? null;

        return self::matchesCustomerData($result, $data)
            || (is_string($fingerprint) && hash_equals($fingerprint, UVBConnectorWooCommerce_Customer_Data::getFingerprint($data)));
    }

}
