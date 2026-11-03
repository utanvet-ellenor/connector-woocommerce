<?php

class UVBConnectorWooCommerce_Customer_Data {
    public const SHIPPING_PHONE_PROVIDED_META_KEY = '_uvb_connector_woocommerce_shipping_phone_provided';

    public static function sanitize(array $data): array {
        return [
            'email' => sanitize_email($data['email'] ?? ''),
            'country_code' => sanitize_text_field($data['country_code'] ?? ''),
            'postal_code' => sanitize_text_field($data['postal_code'] ?? ''),
            'phone_number' => sanitize_text_field($data['phone_number'] ?? ''),
            'address_line' => sanitize_text_field($data['address_line'] ?? ''),
        ];
    }

    public static function normalize(array $data): array {
        $data = self::sanitize($data);
        $data['postal_code'] = wc_format_postcode($data['postal_code'], $data['country_code']);

        return $data;
    }

    public static function getFingerprint(array $data): string {
        return hash('sha256', wp_json_encode(self::normalize($data)));
    }

    public static function fromAddresses(array $shipping, array $billing): array {
        return [
            'email' => $shipping['email'] ?? $billing['email'] ?? '',
            'country_code' => $shipping['country'] ?? $billing['country'] ?? '',
            'postal_code' => $shipping['postcode'] ?? $billing['postcode'] ?? '',
            'phone_number' => $shipping['phone'] ?? $billing['phone'] ?? '',
            'address_line' => implode(' ', array_filter([
                $shipping['address_1'] ?? $billing['address_1'] ?? '',
                $shipping['address_2'] ?? $billing['address_2'] ?? '',
            ])),
        ];
    }

    public static function fromCheckout(array $data): array {
        $shipping = [];
        $billing = [];
        foreach (['email', 'country', 'postcode', 'phone', 'address_1', 'address_2'] as $field) {
            $billing[$field] = $data['billing_' . $field] ?? null;
            if (!empty($data['ship_to_different_address'])) {
                $shipping[$field] = $data['shipping_' . $field] ?? null;
            }
        }

        return self::fromAddresses($shipping, $billing);
    }

    public static function fromOrder($order): array {
        // WooCommerce represents an unused shipping address with an entirely empty record.
        $shipping = $order->get_address('shipping');
        if (!array_filter($shipping)) {
            $shipping = [];
        }

        $shippingPhoneProvided = $order->get_meta(self::SHIPPING_PHONE_PROVIDED_META_KEY, true);
        // Classic checkout has no shipping phone field by default, but WC_Order fills it with ''.
        // Older Blocks orders already received an explicit phone value in their shipping payload.
        $useBillingPhone = $shippingPhoneProvided === 'no'
            || ($shippingPhoneProvided === '' && $order->get_created_via() !== 'store-api');
        if (($shipping['phone'] ?? '') === '' && $useBillingPhone) {
            unset($shipping['phone']);
        }

        return self::fromAddresses($shipping, $order->get_address('billing'));
    }

}
