<?php

class UVBConnectorWooCommerce_Order_Result {
    public const META_KEY = '_uvb_connector_woocommerce_check_result';
    public const LEGACY_META_KEY = '_uvb_connector_woocommerce_flag';

    // Compatibility entry points; result processing belongs to Check_Result.
    public static function fromResponse($response, ?array $checkedData = null): ?array {
        return UVBConnectorWooCommerce_Check_Result::fromResponse($response, $checkedData);
    }

    public static function matchesCustomerData(array $result, array $data): bool {
        return UVBConnectorWooCommerce_Check_Result::matchesCustomerData($result, $data);
    }

    public static function matchesOrderData(array $result, array $data): bool {
        return UVBConnectorWooCommerce_Check_Result::matchesOrderData($result, $data);
    }

    public static function canReuseSavedResult($order): bool {
        // Draft decisions follow checkout cache expiry until the order is placed.
        return $order && $order->get_id() && !$order->has_status('checkout-draft');
    }

    public static function readMatching($order, array $data): ?array {
        if (!self::canReuseSavedResult($order)) {
            return null;
        }

        $result = self::read($order);

        return $result !== null && UVBConnectorWooCommerce_Check_Result::matchesCustomerData($result, $data) ? $result : null;
    }

    public static function writeCheckoutResult($order, ?array $result, array $checkedData): bool {
        if ($result === null) {
            if (!$order->has_status('checkout-draft')) {
                return false;
            }
            // A draft's obsolete result must not become the placed order's decision on API failure.
            self::write($order, null);
            return true;
        }

        $orderData = UVBConnectorWooCommerce_Customer_Data::fromOrder($order);
        $savedResult = self::readMatching($order, $checkedData);
        if ($savedResult !== null) {
            if (UVBConnectorWooCommerce_Check_Result::matchesOrderData($savedResult, $orderData)) {
                return false;
            }
            $result = $savedResult;
        }

        $result['customer_fingerprint'] = UVBConnectorWooCommerce_Customer_Data::getFingerprint($checkedData);
        // WooCommerce may persist billing as shipping for a virtual cart. Bind that
        // order representation separately without changing which data was checked.
        $result['order_fingerprint'] = UVBConnectorWooCommerce_Customer_Data::getFingerprint($orderData);
        self::write($order, $result, self::canReuseSavedResult($order));

        return true;
    }

    public static function write($order, ?array $result, bool $preserveLegacyReason = false): void {
        $legacyReason = $order->get_meta(self::LEGACY_META_KEY, true);
        $keepLegacyReason = $preserveLegacyReason && is_string($legacyReason) && $legacyReason !== '';

        $order->delete_meta_data(self::META_KEY);
        if (!$keepLegacyReason) {
            $order->delete_meta_data(self::LEGACY_META_KEY);
        }
        if ($result === null) {
            return;
        }

        $order->update_meta_data(self::META_KEY, [
            'schema_version' => 1,
            'blocked' => (bool) $result['blocked'],
            'reason' => $result['reason'] ?? null,
            'checked_at' => $result['checked_at'] ?? null,
            // Older cache entries contain only the decision and reason.
            'api_response' => $result['api_response'] ?? null,
            // Missing fingerprints remain readable, but cannot authorize a payment retry.
            'customer_fingerprint' => $result['customer_fingerprint'] ?? null,
            'order_fingerprint' => $result['order_fingerprint'] ?? null,
        ]);

        $reason = $result['reason'] ?? null;
        if (!$keepLegacyReason && is_string($reason) && $reason !== '') {
            self::writeLegacyReason($order, $reason);
        }
    }

    public static function writeLegacyReason($order, $reason): void {
        $order->update_meta_data(self::LEGACY_META_KEY, $reason);
    }

    public static function getReason($order) {
        $legacyReason = $order->get_meta(self::LEGACY_META_KEY, true);
        if ($legacyReason) {
            return $legacyReason;
        }

        $result = self::read($order);

        return $result['reason'] ?? $legacyReason;
    }

    public static function read($order): ?array {
        $result = $order->get_meta(self::META_KEY, true);
        if (!is_array($result) || ($result['schema_version'] ?? null) !== 1 || !is_bool($result['blocked'] ?? null)) {
            return null;
        }

        return $result;
    }
}
