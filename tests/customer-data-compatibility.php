<?php

require $argv[1] ?? __DIR__ . '/../includes/class-uvb-connector-woocommerce-customer-data.php';

// This fixture deliberately has no WC_Order::needs_shipping() method.
class UVBTestLegacyCustomerOrder {
    private array $shipping;
    private array $billing;

    public function __construct(array $shipping, array $billing) {
        $this->shipping = $shipping;
        $this->billing = $billing;
    }

    public function get_address(string $type): array {
        return $type === 'shipping' ? $this->shipping : $this->billing;
    }

    public function get_meta($key, $single = true): string {
        return '';
    }

    public function get_created_via(): string {
        return 'checkout';
    }
}

class UVBTestModernCustomerOrder extends UVBTestLegacyCustomerOrder {
    public function needs_shipping(): bool {
        throw new RuntimeException('Address selection must not inspect shipping requirements.');
    }
}

$billing = ['email' => 'buyer@example.invalid', 'country' => 'HU', 'postcode' => '1111', 'phone' => '+3611111111', 'address_1' => 'Billing street', 'address_2' => ''];
$shipping = ['country' => 'HU', 'postcode' => '2222', 'phone' => '+3622222222', 'address_1' => 'Shipping street', 'address_2' => ''];
$shippingWithoutPhone = $shipping;
$shippingWithoutPhone['phone'] = '';
$partialShipping = ['country' => 'AT', 'postcode' => null, 'phone' => null, 'address_1' => null];
$cases = [
    ['Order without needs_shipping keeps shipping data', new UVBTestLegacyCustomerOrder($shipping, $billing), '2222', '+3622222222'],
    ['Modern order does not inspect shipping requirements', new UVBTestModernCustomerOrder($shipping, $billing), '2222', '+3622222222'],
    ['Missing shipping fields fall back independently', new UVBTestLegacyCustomerOrder($partialShipping, $billing), '1111', '+3611111111'],
    ['Default empty classic shipping phone uses billing', new UVBTestLegacyCustomerOrder($shippingWithoutPhone, $billing), '2222', '+3611111111'],
    ['Empty shipping record uses billing data', new UVBTestLegacyCustomerOrder([], $billing), '1111', '+3611111111'],
];

$failures = 0;
foreach ($cases as [$name, $order, $postcode, $phone]) {
    try {
        $data = UVBConnectorWooCommerce_Customer_Data::fromOrder($order);
        $passed = $data['postal_code'] === $postcode && $data['phone_number'] === $phone && $data['email'] === $billing['email'];
        $details = $passed ? '' : ' — unexpected customer data';
    } catch (Throwable $exception) {
        $passed = false;
        $details = ' — ' . $exception->getMessage();
    }
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . $details . PHP_EOL;
    if (!$passed) {
        $failures++;
    }
}

exit($failures ? 1 : 0);
