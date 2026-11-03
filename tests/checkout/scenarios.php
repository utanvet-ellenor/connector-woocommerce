<?php

function uvbTestExplicitPaymentRetries(UVBCheckoutTestFixture $fixture): void {
    $order = uvbTestOrder();
    $response = uvbTestRetry($order, 'bacs');
    $fixture->record('Store API fallback retry succeeds with empty cart and no checkout cache',
        WC()->cart->is_empty() && $response->get_status() === 200 && $fixture->bacs->attempts === 1,
        ['status' => $response->get_status(), 'code' => $response->get_data()['code'] ?? null]);
    $response = uvbTestRetry($order, 'cod');
    $fixture->record('Store API protected retry is rejected', $response->get_status() === 400 && $fixture->cod->attempts === 0);
    $fixture->record('Saved retry decision requires no API request', $GLOBALS['uvbTestApiCalls'] === 0);
    $attemptsBefore = $fixture->bacs->attempts;
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    $response = uvbTestRetry($order, 'bacs', false);
    $fixture->record('Unauthorized retry never reaches the gateway or API', $response->get_status() >= 400 && $fixture->bacs->attempts === $attemptsBefore && $GLOBALS['uvbTestApiCalls'] === $callsBefore);

    foreach (['pending', 'failed'] as $status) {
        foreach (['billing_email', 'shipping_postcode'] as $changedField) {
            $retry = uvbTestOrder();
            $retry->set_status($status);
            $retry->save();
            $billing = $retry->get_address('billing');
            $shipping = $retry->get_address('shipping');
            if ($changedField === 'billing_email') {
                $billing['email'] = 'corrected-' . $status . '@example.invalid';
            } else {
                $shipping['postcode'] = '2222';
            }
            $checkedData = UVBConnectorWooCommerce_Customer_Data::fromAddresses($shipping, $billing);
            $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => false, 'reason' => 'Corrected retry address']];
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            $attemptsBefore = $fixture->cod->attempts;
            $response = uvbTestRetry($retry, 'cod', true, $billing, $shipping);
            $case = $status . '/' . $changedField;
            $fixture->record('Explicit retry checks corrected addresses before rejecting an old blocked decision: ' . $case,
                $response->get_status() === 200 && $fixture->cod->attempts === $attemptsBefore + 1
                && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1
                && $GLOBALS['uvbTestLastApiRequest']['email'] === $checkedData['email']
                && $GLOBALS['uvbTestLastApiRequest']['postalCode'] === $checkedData['postal_code'],
                ['status' => $response->get_status(), 'code' => $response->get_data()['code'] ?? null]);
            $reloaded = new WC_Order($retry->get_id());
            $saved = UVBConnectorWooCommerce_Order_Result::read($reloaded);
            $fixture->record('Explicit retry saves the checked result on the updated order: ' . $case,
                $saved !== null && $saved['blocked'] === false
                && UVBConnectorWooCommerce_Order_Result::matchesCustomerData($saved, $checkedData)
                && UVBConnectorWooCommerce_Order_Result::matchesCustomerData($saved, UVBConnectorWooCommerce_Customer_Data::fromOrder($reloaded))
                && $saved['api_response']['result']['reason'] === 'Corrected retry address'
                && $reloaded->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Historical reason');
            $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => true]];
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            $response = uvbTestRetry($reloaded, 'cod');
            $fixture->record('Explicit retry reuses the corrected saved decision without another check: ' . $case,
                $response->get_status() === 200 && $GLOBALS['uvbTestApiCalls'] === $callsBefore);
        }
    }

    foreach ([true, null] as $verdict) {
        $retry = uvbTestOrder(false);
        UVBConnectorWooCommerce_Order_Result::write($retry, uvbTestResult(null, false));
        $retry->save_meta_data();
        $before = UVBConnectorWooCommerce_Order_Result::read($retry);
        $billing = $retry->get_address('billing');
        $billing['email'] = 'restricted-corrected-' . (int) $verdict . '@example.invalid';
        $GLOBALS['uvbTestApiResponse'] = $verdict === null ? null : (object) ['result' => (object) ['blocked' => true, 'reason' => 'Changed customer blocked']];
        $callsBefore = $GLOBALS['uvbTestApiCalls'];
        $attemptsBefore = $fixture->cod->attempts;
        $response = uvbTestRetry($retry, 'cod', true, $billing);
        $reloaded = new WC_Order($retry->get_id());
        $fixture->record('Explicit retry checks changed data and rejects COD on a block or unavailable service: ' . (int) $verdict,
            $response->get_status() === 400 && $fixture->cod->attempts === $attemptsBefore && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1);
        $fixture->record('Rejected explicit retry preserves the previous order addresses and result: ' . (int) $verdict,
            UVBConnectorWooCommerce_Order_Result::read($reloaded) === $before
            && $reloaded->get_billing_email() === $retry->get_billing_email());
        if ($verdict === true) {
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            $response = uvbTestRetry($retry, 'bacs', true, $billing);
            $reloaded = new WC_Order($retry->get_id());
            $saved = UVBConnectorWooCommerce_Order_Result::read($reloaded);
            $fixture->record('Explicit retry accepts fallback for corrected blocked data using the preceding check',
                $response->get_status() === 200 && $GLOBALS['uvbTestApiCalls'] === $callsBefore
                && $saved !== null && $saved['blocked'] === true
                && UVBConnectorWooCommerce_Order_Result::matchesCustomerData($saved, UVBConnectorWooCommerce_Customer_Data::fromOrder($reloaded))
                && $reloaded->get_billing_email() === $billing['email']);
        }
    }

    $retry = uvbTestOrder();
    $billing = $retry->get_address('billing');
    $billing['email'] = 'cached-corrected-retry@example.invalid';
    $checkedData = UVBConnectorWooCommerce_Customer_Data::fromAddresses($retry->get_address('shipping'), $billing);
    $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => false, 'reason' => 'Cached corrected address']];
    (new UVBConnectorWooCommerce_Result_Resolver(new UVBConnectorWooCommerce_Api(), new UVBConnectorWooCommerce_Checkout_Result_Cache()))
        ->checkCheckoutCustomer(null, $checkedData);
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    $response = uvbTestRetry($retry, 'cod', true, $billing);
    $saved = UVBConnectorWooCommerce_Order_Result::read(new WC_Order($retry->get_id()));
    $fixture->record('Explicit retry reuses a matching checkout cache entry for the corrected data',
        $response->get_status() === 200 && $GLOBALS['uvbTestApiCalls'] === $callsBefore
        && $saved !== null && $saved['blocked'] === false);

    $retry = uvbTestOrder();
    $before = UVBConnectorWooCommerce_Order_Result::read($retry);
    $billing['email'] = 'unauthorized-corrected-retry@example.invalid';
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    $attemptsBefore = $fixture->cod->attempts;
    $response = uvbTestRetry($retry, 'cod', false, $billing);
    $fixture->record('Unauthorized corrected-address retry cannot check or update the order',
        $response->get_status() >= 400 && $GLOBALS['uvbTestApiCalls'] === $callsBefore && $fixture->cod->attempts === $attemptsBefore
        && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($retry->get_id())) === $before);

    $retry = uvbTestOrder(false);
    $retry->set_created_via('checkout');
    $retry->set_billing_phone('+3612345678');
    $retry->update_meta_data(UVBConnectorWooCommerce_Customer_Data::SHIPPING_PHONE_PROVIDED_META_KEY, 'no');
    UVBConnectorWooCommerce_Order_Result::write($retry, uvbTestResult(UVBConnectorWooCommerce_Customer_Data::fromOrder($retry)));
    $retry->save();
    $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => false, 'reason' => 'Explicit empty shipping phone']];
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    $response = uvbTestRetry($retry, 'cod');
    $reloaded = new WC_Order($retry->get_id());
    $saved = UVBConnectorWooCommerce_Order_Result::read($reloaded);
    $fixture->record('Explicit retry honors a submitted empty shipping phone on a classic order',
        $response->get_status() === 200 && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1
        && $GLOBALS['uvbTestLastApiRequest']['phoneNumber'] === ''
        && $reloaded->get_meta(UVBConnectorWooCommerce_Customer_Data::SHIPPING_PHONE_PROVIDED_META_KEY) === 'yes'
        && $saved !== null && UVBConnectorWooCommerce_Order_Result::matchesCustomerData($saved, UVBConnectorWooCommerce_Customer_Data::fromOrder($reloaded)));

    $shipping = $reloaded->get_address('shipping');
    unset($shipping['phone']);
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    $response = uvbTestRetry($reloaded, 'cod', true, null, $shipping);
    $fixture->record('Omitting a shipping field on an explicit retry retains its saved value and decision',
        $response->get_status() === 200 && $GLOBALS['uvbTestApiCalls'] === $callsBefore);
    $GLOBALS['uvbTestApiResponse'] = null;
}

function uvbTestSavedResultPreservation(UVBCheckoutTestFixture $fixture): void {
    foreach (['pending', 'failed'] as $status) {
        foreach (['checkout', 'store-api'] as $route) {
            uvbTestPublicHooks();
            $resumed = uvbTestOrder();
            $resumed->set_status($status);
            $resumed->save();
            $before = UVBConnectorWooCommerce_Order_Result::read($resumed);
            if ($route === 'checkout') {
                do_action('woocommerce_checkout_create_order', $resumed, []);
            } else {
                $request = new WP_REST_Request('POST', '/wc/store/v1/checkout');
                $request->set_body_params(['billing_address' => uvbTestAddress(), 'shipping_address' => uvbTestAddress(), 'payment_method' => 'card']);
                do_action('woocommerce_store_api_checkout_update_order_from_request', $resumed, $request);
            }
            $resumed->save();
            $reloaded = new WC_Order($resumed->get_id());
            $fixture->record($route . ' preserves saved results after a failed refresh on a ' . $status . ' order',
                UVBConnectorWooCommerce_Order_Result::read($reloaded) === $before
                && $reloaded->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Historical reason');
        }
    }
}

function uvbTestAddressSelection(UVBCheckoutTestFixture $fixture): void {
    uvbTestPublicHooks();
    $GLOBALS['uvbTestApiResponse'] = (object) ['request_id' => 'synthetic-hk-response', 'result' => (object) ['blocked' => false, 'reason' => 'Hong Kong check']];
    $hkOrder = uvbTestOrder(false);
    $shipping = uvbTestAddress();
    $shipping['country'] = 'HK';
    $shipping['postcode'] = '';
    $shipping['address_1'] = 'Test Hong Kong street';
    $shipping['state'] = '';
    $hkOrder->set_address($shipping, 'shipping');
    $data = ['ship_to_different_address' => true, 'payment_method' => 'card'];
    foreach (uvbTestAddress() as $key => $value) { $data['billing_' . $key] = $value; }
    foreach ($shipping as $key => $value) { $data['shipping_' . $key] = $value; }
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    do_action('woocommerce_after_checkout_validation', $data, new WP_Error());
    do_action('woocommerce_checkout_create_order', $hkOrder, $data);
    $hkOrder->save();
    $saved = UVBConnectorWooCommerce_Order_Result::read(new WC_Order($hkOrder->get_id()));
    $fixture->record('Empty shipping postcode matches validation and persists the response',
        ($saved['api_response']['request_id'] ?? null) === 'synthetic-hk-response' && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1);

    $billing = uvbTestAddress();
    $billing['phone'] = '+3612345678';
    $shipping = ['country' => 'AT', 'postcode' => null, 'address_1' => 'Shipping street', 'address_2' => null, 'phone' => ''];
    $billing['address_2'] = 'Billing floor';
    $mixed = UVBConnectorWooCommerce_Customer_Data::fromAddresses($shipping, $billing);
    $fixture->record('Address components independently prefer shipping and preserve empty values',
        $mixed === ['email' => $billing['email'], 'country_code' => 'AT', 'postal_code' => '1111', 'phone_number' => '', 'address_line' => 'Shipping street Billing floor']);
}

function uvbTestClassicSavedRestrictions(UVBCheckoutTestFixture $fixture): void {
    $billing = uvbTestAddress();
    $billing['phone'] = '+3612345678';
    $billing['address_2'] = 'Billing floor';
    foreach ($billing as $field => $value) { WC()->customer->{'set_billing_' . $field}($value); }
    WC()->cart->calculate_totals();

    foreach (['pending', 'failed'] as $status) {
        $resumed = uvbTestOrder();
        $resumed->set_status($status);
        $resumed->set_address($billing, 'billing');
        $resumed->set_address(array_fill_keys(array_keys(uvbTestAddress()), ''), 'shipping');
        UVBConnectorWooCommerce_Order_Result::write($resumed, uvbTestResult(UVBConnectorWooCommerce_Customer_Data::fromAddresses([], $billing)));
        $resumed->set_cart_hash(WC()->cart->get_cart_hash());
        $resumed->save();
        WC()->session->set('order_awaiting_payment', $resumed->get_id());
        uvbTestClearCheckoutCache($fixture->transientKeys);
        $GLOBALS['uvbTestApiResponse'] = null;
        uvbTestPublicHooks();
        $callsBefore = $GLOBALS['uvbTestApiCalls'];
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $fixture->record('Normal checkout exposes fallback for saved blocked ' . $status . ' order after cache expiry',
            isset($gateways['bacs']) && !isset($gateways['cod']));
        $errors = uvbTestValidateCheckout(uvbTestCheckoutData($billing));
        $fixture->record('Normal checkout rejects COD using the saved ' . $status . ' decision without another API call',
            $errors->get_error_message('uvb_payment_method_unavailable') !== '' && $GLOBALS['uvbTestApiCalls'] === $callsBefore,
            $errors->get_error_codes());
        uvbTestPublicHooks();
        $errors = uvbTestValidateCheckout(uvbTestCheckoutData($billing, 'bacs'));
        $fixture->record('Normal checkout permits fallback retry for a ' . $status . ' order', !$errors->has_errors(), $errors->get_error_codes());

        $other = $billing;
        $other['email'] = 'unrelated-allowed-' . $status . '@example.invalid';
        uvbTestSeedResult($other, false);
        uvbTestPublicHooks();
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $fixture->record('Another tab cannot lift the saved ' . $status . ' restriction', isset($gateways['bacs']) && !isset($gateways['cod']));
        $GLOBALS['uvbTestApiResponse'] = null;

        uvbTestPublicHooks();
        $changed = $billing;
        $changed['email'] = 'changed-' . $status . '@example.invalid';
        $callsBefore = $GLOBALS['uvbTestApiCalls'];
        $errors = uvbTestValidateCheckout(uvbTestCheckoutData($changed));
        $fixture->record('Failed refresh with changed customer data retains the saved ' . $status . ' restriction',
            $errors->get_error_message('uvb_payment_method_unavailable') !== '' && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1);
    }

    $resumed->set_cart_hash('different-cart-hash');
    $resumed->save();
    uvbTestPublicHooks();
    $gateways = WC()->payment_gateways()->get_available_payment_gateways();
    $fixture->record('A different cart does not inherit the pending order restriction', isset($gateways['cod']) && !isset($gateways['bacs']));
    $resumed->set_cart_hash(WC()->cart->get_cart_hash());
    $resumed->set_status('cancelled');
    $resumed->save();
    uvbTestPublicHooks();
    $gateways = WC()->payment_gateways()->get_available_payment_gateways();
    $fixture->record('An order that WooCommerce cannot resume does not restrict this checkout', isset($gateways['cod']) && !isset($gateways['bacs']));
    WC()->session->set('order_awaiting_payment', null);
}

function uvbTestStaleGatewayErrors(UVBCheckoutTestFixture $fixture): void {
    $billing = uvbTestAddress();
    foreach ([false, true] as $rejectFields) {
        uvbTestPublicHooks();
        $other = $billing;
        $other['email'] = 'another-tab-' . (int) $rejectFields . '@example.invalid';
        uvbTestSeedResult($other, true);
        $current = $billing;
        $current['email'] = 'current-tab-' . (int) $rejectFields . '@example.invalid';
        $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => false, 'reason' => 'Current buyer allowed']];
        $fixture->cod->rejectFields = $rejectFields;
        $validationsBefore = $fixture->cod->validations;
        $callsBefore = $GLOBALS['uvbTestApiCalls'];
        $errors = uvbTestValidateCheckout(uvbTestCheckoutData($current));
        $fixture->record('Stale gateway error revalidates current data and runs gateway field validation: ' . (int) $rejectFields,
            !$errors->has_errors() && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1 && $fixture->cod->validations === $validationsBefore + 1
            && (wc_notice_count('error') > 0) === $rejectFields, $errors->get_error_codes());
    }
    $fixture->cod->rejectFields = false;
    uvbTestPublicHooks();
    $other['email'] = 'another-tab-unrelated@example.invalid';
    uvbTestSeedResult($other, true);
    $invalid = uvbTestCheckoutData($billing);
    $invalid['billing_first_name'] = '';
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    $errors = uvbTestValidateCheckout($invalid);
    $fixture->record('Unrelated validation errors are preserved without checking incomplete customer data',
        $errors->get_error_message('billing_first_name_required') !== '' && $errors->get_error_message('payment') !== ''
        && $GLOBALS['uvbTestApiCalls'] === $callsBefore);
}

function uvbTestClassicGatewayRevalidation(UVBCheckoutTestFixture $fixture): void {
    $other = uvbTestAddress();
    $other['email'] = 'blocked-other-tab@example.invalid';
    uvbTestSeedResult($other, true);
    $billing = uvbTestAddress();
    $billing['email'] = 'allowed-current-tab@example.invalid';
    $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => false, 'reason' => 'Current customer allowed']];
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    $validationsBefore = $fixture->bacs->validations;
    $errors = uvbTestValidateCheckout(uvbTestCheckoutData($billing, 'bacs'));
    $fixture->record('A fallback that becomes unavailable is rejected before order creation',
        $errors->get_error_message('uvb_payment_method_unavailable') !== ''
        && $fixture->bacs->validations === $validationsBefore + 1,
        $errors->get_error_codes());
    $gateways = WC()->payment_gateways()->get_available_payment_gateways();
    $fixture->record('Gateway revalidation uses the submitted customer decision',
        isset($gateways['cod']) && !isset($gateways['bacs']) && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1);

    uvbTestPublicHooks();
    $validationsBefore = $fixture->cod->validations;
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    $errors = uvbTestValidateCheckout(uvbTestCheckoutData($billing, 'cod'));
    $fixture->record('Selecting the available method succeeds without duplicate field validation or API calls',
        !$errors->has_errors() && $fixture->cod->validations === $validationsBefore + 1
        && $GLOBALS['uvbTestApiCalls'] === $callsBefore);

    add_filter('woocommerce_cart_needs_payment', '__return_false');
    try {
        $errors = uvbTestValidateCheckout(uvbTestCheckoutData($billing, ''));
        $fixture->record('A checkout that needs no payment does not require a gateway', !$errors->has_errors(), $errors->get_error_codes());
    } finally {
        remove_filter('woocommerce_cart_needs_payment', '__return_false');
    }
}

function uvbTestValidatedResultPersistence(UVBCheckoutTestFixture $fixture): void {
    foreach ([false, true] as $blocked) {
        uvbTestResetCheckoutState($fixture->transientKeys);
        uvbTestPublicHooks();
        $billing = uvbTestAddress();
        $billing['email'] = 'expires-after-validation-' . (int) $blocked . '@example.invalid';
        $checkoutData = uvbTestCheckoutData($billing, $blocked ? 'bacs' : 'cod');
        $data = UVBConnectorWooCommerce_Customer_Data::fromCheckout($checkoutData);
        $cache = new UVBConnectorWooCommerce_Checkout_Result_Cache();
        $sessionKey = $cache->getSessionKey();
        $requestKey = $cache->getCustomerRequestKey($sessionKey, $data);
        $result = uvbTestResult($data, $blocked, 'Validated before expiry');
        $result['checked_at'] -= UVBConnectorWooCommerce_Checkout_Result_Cache::REQUEST_CACHE_TTL - 60;
        $cache->storeRequestResult($sessionKey, $requestKey, $result);
        $callsBefore = $GLOBALS['uvbTestApiCalls'];
        $errors = uvbTestValidateCheckout($checkoutData);
        $fixture->record('Classic validation reuses a result near its expiry: ' . (int) $blocked,
            !$errors->has_errors() && $GLOBALS['uvbTestApiCalls'] === $callsBefore);

        // Expire the shared entry between validation and persistence without delaying the suite.
        $expired = get_transient($requestKey);
        $expired['expires_at'] = time() - 1;
        set_transient($requestKey, $expired, 60);
        $createdId = WC()->checkout()->create_order($checkoutData);
        if (is_wp_error($createdId)) { throw new RuntimeException($createdId->get_error_message()); }
        $GLOBALS['uvbTestOrders'][] = $createdId;
        $order = new WC_Order($createdId);
        $saved = UVBConnectorWooCommerce_Order_Result::read($order);
        $fixture->record('The validated result and reason survive cache expiry before order persistence: ' . (int) $blocked,
            $saved !== null && $saved['blocked'] === $blocked && $saved['checked_at'] === $result['checked_at']
            && $saved['api_response'] === $result['api_response']
            && UVBConnectorWooCommerce_Check_Result::matchesCustomerData($saved, $data)
            && $order->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Validated before expiry'
            && $GLOBALS['uvbTestApiCalls'] === $callsBefore);
        $fixture->record('Retaining the validated result does not extend the shared cache lifetime: ' . (int) $blocked,
            $cache->getCustomerResult($sessionKey, $data) === null);

        $changedData = $checkoutData;
        $changedData['billing_postcode'] = '2222';
        $changedOrder = new WC_Order();
        do_action('woocommerce_checkout_create_order', $changedOrder, $changedData);
        $fixture->record('A retained result cannot annotate changed customer data: ' . (int) $blocked,
            UVBConnectorWooCommerce_Order_Result::read($changedOrder) === null && $GLOBALS['uvbTestApiCalls'] === $callsBefore);

        uvbTestPublicHooks();
        $unvalidatedOrder = new WC_Order();
        do_action('woocommerce_checkout_create_order', $unvalidatedOrder, $checkoutData);
        $fixture->record('Retained results do not survive into a new request or trigger persistence-time checks: ' . (int) $blocked,
            UVBConnectorWooCommerce_Order_Result::read($unvalidatedOrder) === null && $GLOBALS['uvbTestApiCalls'] === $callsBefore);
        $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => $blocked, 'reason' => 'Rechecked after expiry']];
        $errors = uvbTestValidateCheckout($checkoutData);
        $fixture->record('A subsequent checkout validates again after shared cache expiry: ' . (int) $blocked,
            !$errors->has_errors() && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1);
    }
}

function uvbTestSubmittedAddresses(UVBCheckoutTestFixture $fixture): void {
    $billing = uvbTestAddress();
    $billing['phone'] = '+3612345678';
    uvbTestPublicHooks();
    $billing['email'] = 'missing-shipping-phone@example.invalid';
    $shipping = uvbTestAddress();
    unset($shipping['phone'], $shipping['email']);
    $shipping['country'] = 'HK';
    $shipping['postcode'] = '';
    $data = uvbTestCheckoutData($billing, 'card');
    $data['ship_to_different_address'] = true;
    foreach ($shipping as $key => $value) { $data['shipping_' . $key] = $value; }
    $GLOBALS['uvbTestApiResponse'] = (object) ['request_id' => 'missing-shipping-phone', 'result' => (object) ['blocked' => false, 'reason' => 'Checked before saving']];
    do_action('woocommerce_after_checkout_validation', $data, new WP_Error());
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    $mixedOrder = uvbTestOrder(false);
    $mixedOrder->set_address($billing, 'billing');
    $mixedOrder->set_address($shipping, 'shipping');
    do_action('woocommerce_checkout_create_order', $mixedOrder, $data);
    $fixture->record('Classic persistence uses the validated payload despite order defaults for missing shipping fields',
        UVBConnectorWooCommerce_Order_Result::read($mixedOrder)['api_response']['request_id'] === 'missing-shipping-phone'
        && $GLOBALS['uvbTestLastApiRequest']['phoneNumber'] === $billing['phone']
        && $GLOBALS['uvbTestLastApiRequest']['postalCode'] === '' && $GLOBALS['uvbTestApiCalls'] === $callsBefore);

    uvbTestPublicHooks();
    $billing['email'] = 'virtual-buyer@example.invalid';
    $virtualShipping = uvbTestAddress();
    unset($virtualShipping['email']);
    $virtualShipping['postcode'] = '2222';
    $virtualShipping['address_1'] = 'Shipping street';
    $virtualShipping['phone'] = '+3622222222';
    $GLOBALS['uvbTestApiResponse'] = (object) ['request_id' => 'virtual-response', 'result' => (object) ['blocked' => false, 'reason' => 'Virtual buyer allowed']];
    $request = new WP_REST_Request('POST', '/wc/store/v1/checkout');
    $request->set_body_params(['billing_address' => $billing, 'shipping_address' => $virtualShipping, 'payment_method' => 'card']);
    $virtualOrder = uvbTestOrder(false);
    $virtualOrder->set_address($billing, 'billing');
    $virtualOrder->set_address($virtualShipping, 'shipping');
    foreach ($billing as $field => $value) { WC()->customer->{'set_billing_' . $field}($value); }
    foreach ($virtualShipping as $field => $value) { WC()->customer->{'set_shipping_' . $field}($value); }
    do_action('woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, $request);
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    do_action('woocommerce_store_api_checkout_update_order_from_request', $virtualOrder, $request);
    $fixture->record('Store API hooks prefer the effective shipping fields even for a virtual cart',
        UVBConnectorWooCommerce_Order_Result::read($virtualOrder)['api_response']['request_id'] === 'virtual-response'
        && $GLOBALS['uvbTestLastApiRequest']['postalCode'] === $virtualShipping['postcode']
        && $GLOBALS['uvbTestLastApiRequest']['addressLine'] === $virtualShipping['address_1']
        && $GLOBALS['uvbTestLastApiRequest']['phoneNumber'] === $virtualShipping['phone']
        && $GLOBALS['uvbTestApiCalls'] === $callsBefore);
}

function uvbTestShippingPhonePersistence(UVBCheckoutTestFixture $fixture): void {
    $phoneBilling = uvbTestAddress();
    $phoneBilling['phone'] = '+3612345678';
    $phoneShipping = uvbTestAddress();
    unset($phoneShipping['phone'], $phoneShipping['email']);
    $phoneData = uvbTestCheckoutData($phoneBilling, 'bacs');
    $phoneData['ship_to_different_address'] = true;
    foreach ($phoneShipping as $key => $value) { $phoneData['shipping_' . $key] = $value; }
    $phoneOrder = uvbTestOrder();
    $phoneOrder->set_created_via('checkout');
    $phoneOrder->set_address($phoneBilling, 'billing');
    $phoneOrder->set_address($phoneShipping, 'shipping');
    $phoneOrder->set_cart_hash(WC()->cart->get_cart_hash());
    UVBConnectorWooCommerce_Order_Result::write($phoneOrder, uvbTestResult(UVBConnectorWooCommerce_Customer_Data::fromCheckout($phoneData)));
    $phoneOrder->save();
    $fixture->record('Legacy classic order restores billing phone when WooCommerce supplies an empty shipping default',
        UVBConnectorWooCommerce_Customer_Data::fromOrder($phoneOrder) === UVBConnectorWooCommerce_Customer_Data::fromCheckout($phoneData));
    do_action('woocommerce_checkout_create_order', $phoneOrder, $phoneData);
    $phoneOrder->save();
    $phoneOrder = new WC_Order($phoneOrder->get_id());
    $fixture->record('New classic order preserves the missing shipping phone distinction after a database round trip',
        UVBConnectorWooCommerce_Customer_Data::fromOrder($phoneOrder) === UVBConnectorWooCommerce_Customer_Data::fromCheckout($phoneData));
    $GLOBALS['uvbTestApiResponse'] = (object) ['success' => true];
    (new UVBConnectorWooCommerce_Admin('uvb-connector-woocommerce', 'test'))->sendSignalToUVBService($phoneOrder->get_id(), 1);
    $fixture->record('Delivery signal retains the classic billing phone', $GLOBALS['uvbTestLastApiRequest']['phoneNumber'] === $phoneBilling['phone']);
    uvbTestClearCheckoutCache($fixture->transientKeys);
    WC()->session->set('order_awaiting_payment', $phoneOrder->get_id());
    $GLOBALS['uvbTestApiResponse'] = null;
    uvbTestPublicHooks();
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    $errors = new WP_Error();
    do_action('woocommerce_after_checkout_validation', $phoneData, $errors);
    $fixture->record('Unchanged classic retry with billing-only phone reuses the saved decision after cache expiry',
        !$errors->has_errors() && $GLOBALS['uvbTestApiCalls'] === $callsBefore);
    WC()->session->set('order_awaiting_payment', null);

    $phoneData['shipping_phone'] = '';
    do_action('woocommerce_checkout_create_order', $phoneOrder, $phoneData);
    $phoneOrder->save();
    $fixture->record('Explicitly empty classic shipping phone remains empty after saving',
        UVBConnectorWooCommerce_Customer_Data::fromOrder(new WC_Order($phoneOrder->get_id()))['phone_number'] === '');
    $phoneData['shipping_phone'] = '+3611111111';
    $phoneOrder->set_shipping_phone($phoneData['shipping_phone']);
    do_action('woocommerce_checkout_create_order', $phoneOrder, $phoneData);
    $fixture->record('Explicit shipping phone takes priority over billing',
        UVBConnectorWooCommerce_Customer_Data::fromOrder($phoneOrder)['phone_number'] === $phoneData['shipping_phone']);
    $phoneOrder->delete_meta_data('_uvb_connector_woocommerce_shipping_phone_provided');
    $phoneOrder->set_created_via('store-api');
    $phoneOrder->set_shipping_phone('');
    $fixture->record('Legacy Blocks order retains an explicitly empty shipping phone',
        UVBConnectorWooCommerce_Customer_Data::fromOrder($phoneOrder)['phone_number'] === '');
}

function uvbTestStoreSessionOrderSelection(UVBCheckoutTestFixture $fixture): void {
    foreach (['pending', 'failed'] as $status) {
        uvbTestClearCheckoutCache($fixture->transientKeys);
        $GLOBALS['uvbTestApiResponse'] = null;
        WC()->session->set('order_awaiting_payment', null);
        $storeOrder = uvbTestResumableOrder($status);
        UVBConnectorWooCommerce_Order_Result::write($storeOrder, uvbTestResult(UVBConnectorWooCommerce_Customer_Data::fromAddresses([], $storeOrder->get_address('billing'))));
        $storeOrder->save();
        $codAttempts = $fixture->cod->attempts;
        $response = uvbTestStoreSessionRetry($storeOrder, 'cod');
        $fixture->record('Store API session-only ' . $status . ' retry rejects COD after cache expiry and API failure',
            $response->get_status() === 400 && $fixture->cod->attempts === $codAttempts,
            ['status' => $response->get_status(), 'code' => $response->get_data()['code'] ?? null]);
        $bacsAttempts = $fixture->bacs->attempts;
        $response = uvbTestStoreSessionRetry($storeOrder, 'bacs');
        $fixture->record('Store API session-only ' . $status . ' retry accepts fallback before the order-update hook',
            $response->get_status() === 200 && $fixture->bacs->attempts === $bacsAttempts + 1
            && $response->get_data()['order_id'] === $storeOrder->get_id(),
            ['status' => $response->get_status(), 'attempts' => $fixture->bacs->attempts - $bacsAttempts, 'order_id' => $response->get_data()['order_id'] ?? null, 'expected' => $storeOrder->get_id()]);
    }
    $classicOrder = uvbTestOrder();
    $classicOrder->set_created_via('checkout');
    $classicOrder->set_cart_hash(WC()->cart->get_cart_hash());
    $classicOrder->set_address(WC()->customer->get_billing(), 'billing');
    UVBConnectorWooCommerce_Order_Result::write($classicOrder, uvbTestResult(UVBConnectorWooCommerce_Customer_Data::fromAddresses([], WC()->customer->get_billing()), false, 'Classic checkout allowed'));
    $classicOrder->save();
    WC()->session->set('order_awaiting_payment', $classicOrder->get_id());
    uvbTestPublicHooks();
    $gateways = WC()->payment_gateways()->get_available_payment_gateways();
    $fixture->record('Classic checkout prefers its own order when both session references exist', isset($gateways['cod']) && !isset($gateways['bacs']));
    $bacsAttempts = $fixture->bacs->attempts;
    $response = uvbTestStoreSessionRetry($storeOrder, 'bacs');
    $fixture->record('Store API prefers its own order when both session references exist',
        $response->get_status() === 200 && $fixture->bacs->attempts === $bacsAttempts + 1
        && $response->get_data()['order_id'] === $storeOrder->get_id());
    WC()->session->set('order_awaiting_payment', null);
    foreach (['wrong-cart', 'cancelled', 'permission'] as $invalidReason) {
        $storeOrder->set_status('pending');
        $storeOrder->set_cart_hash($invalidReason === 'wrong-cart' ? 'unrelated-cart' : WC()->cart->get_cart_hash());
        if ($invalidReason === 'cancelled') { $storeOrder->set_status('cancelled'); }
        $storeOrder->save();
        $denyPayment = function($caps, $required, $args) use ($storeOrder) {
            if ($args[0] === 'pay_for_order' && ($args[2] ?? 0) === $storeOrder->get_id()) { $caps['pay_for_order'] = false; }
            return $caps;
        };
        if ($invalidReason === 'permission') { add_filter('user_has_cap', $denyPayment, 99, 3); }
        $public = new UVBConnectorWooCommerce_Public('uvb-connector-woocommerce', 'test');
        $public->checkBlocksCustomer(UVBConnectorWooCommerce_Customer_Data::fromOrder($storeOrder));
        $gateways = $public->updateAvailablePaymentOptions(['cod' => $fixture->cod, 'bacs' => $fixture->bacs]);
        $fixture->record('Invalid Store API session order is ignored: ' . $invalidReason, isset($gateways['cod']) && !isset($gateways['bacs']));
        remove_filter('user_has_cap', $denyPayment, 99);
    }
    WC()->session->set('store_api_draft_order', null);
}

function uvbTestCheckoutTypeSwitch(UVBCheckoutTestFixture $fixture): void {
    $threshold = 0.25;
    add_filter('option_uvb_connector_woocommerce_options', function($options) use (&$threshold) {
        $options['reputation_threshold'] = $threshold;
        return $options;
    }, 99);
    $trackOrder = function($orderId) { $GLOBALS['uvbTestOrders'][] = $orderId; };

    foreach (['classic', 'blocks'] as $checkout) {
        foreach (['expired', 'settings-change', 'current-cache'] as $cacheState) {
            foreach ([false, true] as $blocked) {
                uvbTestResetCheckoutState($fixture->transientKeys);
                $threshold = 0.25;
                $oldOrder = uvbTestResumableOrder($blocked ? 'pending' : 'failed', $checkout === 'classic' ? 'store-api' : 'checkout');
                $data = UVBConnectorWooCommerce_Customer_Data::fromOrder($oldOrder);
                $oldResult = uvbTestResult($data, !$blocked, 'Other checkout order reason');
                if ($cacheState === 'expired') { $oldResult['checked_at'] -= DAY_IN_SECONDS; }
                UVBConnectorWooCommerce_Order_Result::write($oldOrder, $oldResult);
                $oldOrder->save();
                $oldResult = UVBConnectorWooCommerce_Order_Result::read($oldOrder);
                WC()->session->set($checkout === 'classic' ? 'store_api_draft_order' : 'order_awaiting_payment', $oldOrder->get_id());
                $cache = new UVBConnectorWooCommerce_Checkout_Result_Cache();
                $sessionKey = $cache->getSessionKey();
                $cache->storeRequestResult($sessionKey, $cache->getCustomerRequestKey($sessionKey, $data),
                    $cacheState === 'current-cache' ? uvbTestResult($data, $blocked, 'Current checkout reason') : $oldResult);
                if ($cacheState === 'settings-change') { $threshold = 0.75; }

                $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => $blocked, 'reason' => 'Current checkout reason']];
                $callsBefore = $GLOBALS['uvbTestApiCalls'];
                $paymentMethod = $blocked ? 'bacs' : 'cod';
                $case = $checkout . '/' . $cacheState . '/' . ($blocked ? 'blocked' : 'allowed');
                $createdId = 0;
                add_action('woocommerce_new_order', $trackOrder);
                try {
                    if ($checkout === 'classic') {
                        uvbTestPublicHooks();
                        $checkoutData = uvbTestCheckoutData($oldOrder->get_address('billing'), $paymentMethod);
                        $errors = uvbTestValidateCheckout($checkoutData);
                        $fixture->record('Switched checkout validates the current payment decision: ' . $case,
                            !$errors->has_errors(), $errors->get_error_codes());
                        if (!$errors->has_errors()) {
                            $createdId = WC()->checkout()->create_order($checkoutData);
                            if (is_wp_error($createdId)) { throw new RuntimeException($createdId->get_error_message()); }
                        }
                    } else {
                        $response = uvbTestStoreCheckout($paymentMethod, $oldOrder->get_address('billing'), $oldOrder->get_address('shipping'));
                        $fixture->record('Switched checkout validates the current payment decision: ' . $case,
                            $response->get_status() === 200, $response->get_data()['code'] ?? null);
                        $createdId = $response->get_data()['order_id'] ?? 0;
                    }
                } finally {
                    remove_action('woocommerce_new_order', $trackOrder);
                }
                $newOrder = $createdId ? wc_get_order($createdId) : null;
                $saved = $newOrder ? UVBConnectorWooCommerce_Order_Result::read($newOrder) : null;
                $fixture->record('Switched checkout saves its current decision on a new order: ' . $case,
                    $newOrder && $createdId !== $oldOrder->get_id() && $saved !== null && $saved['blocked'] === $blocked
                    && $saved['reason'] === 'Current checkout reason'
                    && $newOrder->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Current checkout reason');
                $fixture->record('Switched checkout respects shared cache expiry and settings: ' . $case,
                    $GLOBALS['uvbTestApiCalls'] === $callsBefore + ($cacheState === 'current-cache' ? 0 : 1),
                    $GLOBALS['uvbTestApiCalls'] - $callsBefore);
                $fixture->record('Switching checkout preserves the other order decision: ' . $case,
                    UVBConnectorWooCommerce_Order_Result::read(new WC_Order($oldOrder->get_id())) === $oldResult);
            }
        }
    }
}

function uvbTestSessionSavedDecisions(UVBCheckoutTestFixture $fixture): void {
    $retryAddress = uvbTestAddress();
    uvbTestSetCustomerAddresses($retryAddress);
    WC()->cart->calculate_totals();
    foreach (['pending', 'failed'] as $status) {
        foreach ([true, false] as $blocked) {
            foreach ([false, true] as $hasConflictingCache) {
                uvbTestResetCheckoutState($fixture->transientKeys);
                $retry = uvbTestResumableOrder($status);
                $originalResult = uvbTestResult();
                $originalResult['blocked'] = $blocked;
                $originalResult['api_response']['result']['blocked'] = $blocked;
                UVBConnectorWooCommerce_Order_Result::write($retry, $originalResult);
                $retry->save();
                $before = UVBConnectorWooCommerce_Order_Result::read($retry);
                if ($hasConflictingCache) { uvbTestSeedResult($retryAddress, !$blocked); }
                $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => !$blocked, 'reason' => 'Changed service verdict']];
                $callsBefore = $GLOBALS['uvbTestApiCalls'];
                $codResponse = uvbTestStoreSessionRetry($retry, 'cod');
                $bacsResponse = uvbTestStoreSessionRetry($retry, 'bacs');
                $case = $status . '/' . ($blocked ? 'blocked' : 'allowed') . '/' . ($hasConflictingCache ? 'conflicting cache' : 'expired cache');
                $fixture->record('Session retry retains payment restrictions: ' . $case,
                    $codResponse->get_status() === ($blocked ? 400 : 200)
                    && $bacsResponse->get_status() === ($blocked ? 200 : 400),
                    ['cod' => $codResponse->get_status(), 'bacs' => $bacsResponse->get_status()]);
                $reloaded = new WC_Order($retry->get_id());
                $fixture->record('Session retry preserves the full saved result without an API call: ' . $case,
                    $GLOBALS['uvbTestApiCalls'] === $callsBefore
                    && UVBConnectorWooCommerce_Order_Result::read($reloaded) === $before
                    && $reloaded->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Historical reason');
            }
        }
    }
}

function uvbTestDraftCheckoutDecisions(UVBCheckoutTestFixture $fixture): void {
    $threshold = 0.25;
    add_filter('option_uvb_connector_woocommerce_options', function($options) use (&$threshold) {
        $options['reputation_threshold'] = $threshold;
        return $options;
    }, 99);

    foreach (['expired', 'settings-change', 'current-cache'] as $cacheState) {
        foreach ([false, true] as $blocked) {
            uvbTestResetCheckoutState($fixture->transientKeys);
            $threshold = 0.25;
            $draft = uvbTestResumableOrder('checkout-draft');
            $data = UVBConnectorWooCommerce_Customer_Data::fromAddresses($draft->get_address('shipping'), $draft->get_address('billing'));
            $oldResult = uvbTestResult($data, !$blocked, 'Draft reason');
            if ($cacheState === 'expired') {
                $oldResult['checked_at'] -= UVBConnectorWooCommerce_Checkout_Result_Cache::REQUEST_CACHE_TTL + 1;
            }
            UVBConnectorWooCommerce_Order_Result::write($draft, $oldResult);
            $draft->save();
            $cache = new UVBConnectorWooCommerce_Checkout_Result_Cache();
            $sessionKey = $cache->getSessionKey();
            $cache->storeRequestResult($sessionKey, $cache->getCustomerRequestKey($sessionKey, $data),
                $cacheState === 'current-cache' ? uvbTestResult($data, $blocked, 'Current reason') : $oldResult);
            if ($cacheState === 'settings-change') {
                $threshold = 0.75;
            }
            $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => $blocked, 'reason' => 'Current reason']];
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            $gateway = $blocked ? $fixture->bacs : $fixture->cod;
            $attemptsBefore = $gateway->attempts;
            $response = uvbTestStoreSessionRetry($draft, $gateway->id);
            $case = $cacheState . '/' . ($blocked ? 'blocked' : 'allowed');
            $fixture->record('Both Store API hooks accept the current draft decision: ' . $case,
                $response->get_status() === 200 && $gateway->attempts === $attemptsBefore + 1
                && ($response->get_data()['order_id'] ?? null) === $draft->get_id(),
                ['status' => $response->get_status(), 'code' => $response->get_data()['code'] ?? null]);
            $fixture->record('Draft checkout shares one current check across hooks: ' . $case,
                $GLOBALS['uvbTestApiCalls'] === $callsBefore + ($cacheState === 'current-cache' ? 0 : 1));
            $reloaded = new WC_Order($draft->get_id());
            $saved = UVBConnectorWooCommerce_Order_Result::read($reloaded);
            $fixture->record('Draft metadata is replaced by the submitted checkout decision: ' . $case,
                $saved !== null && $saved['blocked'] === $blocked && $saved['reason'] === 'Current reason'
                && $saved['api_response']['result']['blocked'] === $blocked
                && $reloaded->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Current reason');

            uvbTestClearCheckoutCache($fixture->transientKeys);
            $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => !$blocked]];
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            $response = uvbTestStoreSessionRetry($reloaded, $gateway->id);
            $fixture->record('Placed order retains its finalized decision on the next retry: ' . $case,
                $response->get_status() === 200 && $GLOBALS['uvbTestApiCalls'] === $callsBefore
                && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($draft->get_id())) === $saved);
        }
    }

    foreach ([false, true] as $oldBlocked) {
        uvbTestResetCheckoutState($fixture->transientKeys);
        $draft = uvbTestResumableOrder('checkout-draft');
        UVBConnectorWooCommerce_Order_Result::write($draft, uvbTestResult(null, $oldBlocked, 'Obsolete draft reason'));
        $draft->save();
        $GLOBALS['uvbTestApiResponse'] = null;
        $response = uvbTestStoreSessionRetry($draft, 'cod');
        $fixture->record('Draft hooks use the same new-checkout policy when the API is unavailable: ' . (int) $oldBlocked,
            $response->get_status() === 200 && ($response->get_data()['order_id'] ?? null) === $draft->get_id());
        $reloaded = new WC_Order($draft->get_id());
        $fixture->record('An obsolete draft result cannot become a saved payment-retry decision after API failure: ' . (int) $oldBlocked,
            UVBConnectorWooCommerce_Order_Result::read($reloaded) === null
            && $reloaded->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === '');
    }
}

function uvbTestChangedSessionData(UVBCheckoutTestFixture $fixture): void {
    $retryAddress = uvbTestAddress();
    foreach ([true, false] as $blocked) {
        uvbTestResetCheckoutState($fixture->transientKeys);
        $retry = uvbTestResumableOrder('pending');
        UVBConnectorWooCommerce_Order_Result::write($retry, uvbTestResult(null, !$blocked, 'Original verdict'));
        $retry->save();
        $changedAddress = $retryAddress;
        $changedAddress['email'] = 'changed-session-retry-' . (int) $blocked . '@example.invalid';
        $GLOBALS['uvbTestApiResponse'] = (object) ['request_id' => 'changed-session-data', 'result' => (object) ['blocked' => $blocked, 'reason' => 'Changed customer verdict']];
        $callsBefore = $GLOBALS['uvbTestApiCalls'];
        $response = uvbTestStoreSessionRetry($retry, $blocked ? 'bacs' : 'cod', $changedAddress);
        $saved = UVBConnectorWooCommerce_Order_Result::read(new WC_Order($retry->get_id()));
        $fixture->record('Changed session retry data is checked once and saved: ' . (int) $blocked,
            $response->get_status() === 200 && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1
            && $saved['blocked'] === $blocked && $saved['api_response']['request_id'] === 'changed-session-data');
    }
}

function uvbTestClassicSavedDecisions(UVBCheckoutTestFixture $fixture): void {
    $retryAddress = uvbTestAddress();
    foreach (['pending', 'failed'] as $status) {
        foreach ([true, false] as $blocked) {
            uvbTestResetCheckoutState($fixture->transientKeys);
            uvbTestSetCustomerAddresses($retryAddress);
            $retry = uvbTestResumableOrder($status, 'checkout');
            UVBConnectorWooCommerce_Order_Result::write($retry, uvbTestResult(null, $blocked, 'Original classic verdict'));
            $retry->save();
            $before = UVBConnectorWooCommerce_Order_Result::read($retry);
            uvbTestSeedResult($retryAddress, !$blocked);
            WC()->session->set('order_awaiting_payment', $retry->get_id());
            uvbTestPublicHooks();
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            $checkoutData = uvbTestCheckoutData($retryAddress, $blocked ? 'bacs' : 'cod');
            $errors = uvbTestValidateCheckout($checkoutData);
            $createdId = WC()->checkout()->create_order($checkoutData);
            $reloaded = new WC_Order($retry->get_id());
            $fixture->record('Classic retry ignores conflicting cache and preserves its result: ' . $status . '/' . (int) $blocked,
                !$errors->has_errors() && $createdId === $retry->get_id() && $GLOBALS['uvbTestApiCalls'] === $callsBefore
                && UVBConnectorWooCommerce_Order_Result::read($reloaded) === $before
                && $reloaded->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Original classic verdict');
        }
    }
}

function uvbTestLegacyCheckoutMigration(UVBCheckoutTestFixture $fixture): void {
    $retryAddress = uvbTestAddress();
    foreach (['checkout', 'store-api'] as $route) {
        foreach (['pending', 'failed'] as $status) {
            foreach (['New reason', '', null] as $reason) {
                uvbTestResetCheckoutState($fixture->transientKeys);
                uvbTestSetCustomerAddresses($retryAddress);
                $legacy = uvbTestResumableOrder($status, $route);
                $legacy->update_meta_data(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY, 'Historical reason');
                $legacy->save();
                $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => true, 'reason' => $reason]];
                $callsBefore = $GLOBALS['uvbTestApiCalls'];
                if ($route === 'checkout') {
                    WC()->session->set('order_awaiting_payment', $legacy->get_id());
                    uvbTestPublicHooks();
                    $checkoutData = uvbTestCheckoutData($retryAddress, 'bacs');
                    $errors = uvbTestValidateCheckout($checkoutData);
                    $createdId = WC()->checkout()->create_order($checkoutData);
                    $succeeded = !$errors->has_errors() && $createdId === $legacy->get_id();
                } else {
                    $response = uvbTestStoreSessionRetry($legacy, 'bacs');
                    $succeeded = $response->get_status() === 200 && $response->get_data()['order_id'] === $legacy->get_id();
                }
                $reloaded = new WC_Order($legacy->get_id());
                $saved = UVBConnectorWooCommerce_Order_Result::read($reloaded);
                $fixture->record('Normal ' . $route . ' migration preserves legacy reason: ' . $status . '/' . var_export($reason, true),
                    $succeeded && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1
                    && $reloaded->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Historical reason'
                    && $saved !== null && $saved['api_response']['result']['reason'] === $reason);

                $changedBilling = $retryAddress;
                $changedBilling['email'] = 'second-migration-' . $legacy->get_id() . '@example.invalid';
                $callsBefore = $GLOBALS['uvbTestApiCalls'];
                if ($route === 'checkout') {
                    uvbTestPublicHooks();
                    $checkoutData = uvbTestCheckoutData($changedBilling, 'bacs');
                    $errors = uvbTestValidateCheckout($checkoutData);
                    $createdId = WC()->checkout()->create_order($checkoutData);
                    $succeeded = !$errors->has_errors() && $createdId === $legacy->get_id();
                } else {
                    $response = uvbTestStoreSessionRetry($reloaded, 'bacs', $changedBilling);
                    $succeeded = $response->get_status() === 200;
                }
                $reloaded = new WC_Order($legacy->get_id());
                $updated = UVBConnectorWooCommerce_Order_Result::read($reloaded);
                $fixture->record('Repeated ' . $route . ' revalidation preserves the original legacy reason: ' . $status . '/' . var_export($reason, true),
                    $succeeded && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1
                    && $reloaded->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Historical reason'
                    && $updated !== null && $updated['customer_fingerprint'] !== $saved['customer_fingerprint']
                    && $updated['api_response']['result']['reason'] === $reason);
            }
        }
    }
    uvbTestResetCheckoutState($fixture->transientKeys);
}

function uvbTestMutableOrderAddresses(UVBCheckoutTestFixture $fixture): void {
    $retryAddress = uvbTestAddress();
    foreach ([
        ['email', 'mutated-buyer@example.invalid', 'pending', true],
        ['country', 'AT', 'failed', true],
        ['postcode', '2222', 'pending', true],
        ['phone', '+3611111111', 'failed', true],
        ['address_1', 'Changed street 2', 'pending', true],
        ['address_2', 'Changed floor', 'failed', true],
        ['email', 'unavailable-pending@example.invalid', 'pending', null],
        ['email', 'unavailable-failed@example.invalid', 'failed', null],
    ] as [$field, $value, $status, $verdict]) {
        uvbTestResetCheckoutState($fixture->transientKeys);
        uvbTestSetCustomerAddresses($retryAddress);
        $retry = uvbTestResumableOrder($status);
        UVBConnectorWooCommerce_Order_Result::write($retry, uvbTestResult(null, false));
        $retry->save();
        $before = UVBConnectorWooCommerce_Order_Result::read($retry);
        WC()->session->set('store_api_draft_order', $retry->get_id());
        $fixture->record('Mutable-order fixture starts with a matching saved decision: ' . $field . '/' . (int) $verdict,
            UVBConnectorWooCommerce_Order_Result::matchesCustomerData($before, UVBConnectorWooCommerce_Customer_Data::fromOrder($retry)));
        WC()->customer->{'set_billing_' . $field}($value);
        if (is_callable([WC()->customer, 'set_shipping_' . $field])) {
            WC()->customer->{'set_shipping_' . $field}($value);
        }
        $GLOBALS['uvbTestApiResponse'] = $verdict === null ? null
            : (object) ['request_id' => 'mutated-address', 'result' => (object) ['blocked' => $verdict, 'reason' => 'Changed customer']];
        uvbTestPublicHooks();
        $callsBefore = $GLOBALS['uvbTestApiCalls'];
        $request = new WP_REST_Request('GET', '/wc/store/v1/checkout');
        $request->set_header('Nonce', wp_create_nonce('wc_store_api'));
        $routes = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get(\Automattic\WooCommerce\StoreApi\RoutesController::class);
        $response = $routes->get('checkout')->get_response($request);
        $retry = new WC_Order($retry->get_id());
        $case = $status . '/' . $field . '/' . ($verdict === null ? 'API failure' : 'blocked');
        $fixture->record('Checkout GET mutates the address without changing the saved check: ' . $case,
            $response->get_status() === 200 && $retry->{'get_billing_' . $field}() === $value
            && UVBConnectorWooCommerce_Order_Result::read($retry) === $before && $GLOBALS['uvbTestApiCalls'] === $callsBefore
            && !UVBConnectorWooCommerce_Order_Result::matchesCustomerData($before, UVBConnectorWooCommerce_Customer_Data::fromOrder($retry)));

        $attemptsBefore = $fixture->cod->attempts;
        $response = uvbTestStoreSessionRetry($retry, 'cod');
        $fixture->record('An allowed decision cannot authorize data changed by Checkout GET: ' . $case,
            $response->get_status() === 400 && $fixture->cod->attempts === $attemptsBefore && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1,
            ['status' => $response->get_status(), 'calls' => $GLOBALS['uvbTestApiCalls'] - $callsBefore]);

        $reloaded = new WC_Order($retry->get_id());
        if ($verdict === null) {
            $fixture->record('Failed revalidation retains the original stored response: ' . $case,
                UVBConnectorWooCommerce_Order_Result::read($reloaded) === $before);
            continue;
        }
        $response = uvbTestStoreSessionRetry($reloaded, 'bacs');
        $reloaded = new WC_Order($retry->get_id());
        $saved = UVBConnectorWooCommerce_Order_Result::read($reloaded);
        $fixture->record('Fallback saves the new decision and fingerprint from the existing cache: ' . $case,
            $response->get_status() === 200 && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1
            && ($saved['blocked'] ?? null) === true && !empty($saved['customer_fingerprint'])
            && $saved['customer_fingerprint'] !== ($before['customer_fingerprint'] ?? null));
        $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => false, 'reason' => 'Later verdict']];
        uvbTestClearCheckoutCache($fixture->transientKeys);
        $response = uvbTestStoreSessionRetry($reloaded, 'bacs');
        $explicitResponse = uvbTestRetry($reloaded, 'bacs');
        $fixture->record('Unchanged session and explicit retries reuse the new fingerprint after cache expiry: ' . $case,
            $response->get_status() === 200 && $explicitResponse->get_status() === 200
            && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1,
            ['session' => $response->get_status(), 'explicit' => $explicitResponse->get_status(), 'calls' => $GLOBALS['uvbTestApiCalls'] - $callsBefore]);
    }
}

function uvbTestLegacyFingerprintMigration(UVBCheckoutTestFixture $fixture): void {
    $retryAddress = uvbTestAddress();

    foreach (['checkout', 'store-api', 'order-pay'] as $route) {
        foreach ([false, true] as $hasCachedResult) {
            uvbTestResetCheckoutState($fixture->transientKeys);
            uvbTestSetCustomerAddresses($retryAddress);
            $legacy = uvbTestResumableOrder('pending', $route === 'checkout' ? 'checkout' : 'store-api');
            $oldResult = uvbTestResult(null, false);
            $oldResult['schema_version'] = 1;
            unset($oldResult['customer_fingerprint']);
            $legacy->update_meta_data(UVBConnectorWooCommerce_Order_Result::META_KEY, $oldResult);
            $legacy->update_meta_data(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY, 'Historical reason');
            $legacy->save();
            if ($hasCachedResult) {
                uvbTestSeedResult($retryAddress, true);
                foreach (array_keys($fixture->transientKeys) as $key) {
                    $cached = get_transient($key);
                    if (is_array($cached) && isset($cached['blocked'])) {
                        unset($cached['customer_fingerprint']);
                        set_transient($key, $cached, 1800);
                    }
                }
            }
            $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => true, 'reason' => 'Migrated decision']];
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            if ($route === 'checkout') {
                WC()->session->set('order_awaiting_payment', $legacy->get_id());
                uvbTestPublicHooks();
                $checkoutData = uvbTestCheckoutData($retryAddress, 'bacs');
                $errors = uvbTestValidateCheckout($checkoutData);
                $createdId = WC()->checkout()->create_order($checkoutData);
                $succeeded = !$errors->has_errors() && $createdId === $legacy->get_id();
            } elseif ($route === 'store-api') {
                $response = uvbTestStoreSessionRetry($legacy, 'bacs');
                $succeeded = $response->get_status() === 200;
            } else {
                $response = uvbTestRetry($legacy, 'bacs');
                $succeeded = $response->get_status() === 200;
            }
            $reloaded = new WC_Order($legacy->get_id());
            $saved = UVBConnectorWooCommerce_Order_Result::read($reloaded);
            $fixture->record('Unfingerprinted v1 result migrates with its historical reason: ' . $route . '/' . (int) $hasCachedResult,
                $succeeded && $GLOBALS['uvbTestApiCalls'] === $callsBefore + ($hasCachedResult ? 0 : 1)
                && ($saved['blocked'] ?? null) === true && !empty($saved['customer_fingerprint'])
                && $reloaded->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Historical reason');
        }
    }
    uvbTestResetCheckoutState($fixture->transientKeys);
}

function uvbTestPostcodeNormalization(UVBCheckoutTestFixture $fixture): void {
    $britishAddress = uvbTestAddress();
    $britishAddress['country'] = 'GB';
    $britishAddress['state'] = '';
    $britishAddress['postcode'] = 'sw1a1aa';
    $britishAddress['city'] = 'London';
    uvbTestSeedResult($britishAddress, true);
    $britishAddress['postcode'] = 'SW1A 1AA';
    $britishOrder = uvbTestOrder(false);
    $britishOrder->set_created_via('checkout');
    $britishOrder->set_address($britishAddress, 'billing');
    $britishOrder->set_address(array_fill_keys(array_keys(uvbTestAddress()), ''), 'shipping');
    $britishOrder->set_cart_hash(WC()->cart->get_cart_hash());
    $checkoutData = uvbTestCheckoutData($britishAddress, 'bacs');
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    do_action('woocommerce_checkout_create_order', $britishOrder, $checkoutData);
    $britishOrder->save();
    $fixture->record('Formatted postcode retrieves the original cache result and persists its fingerprint',
        !empty(UVBConnectorWooCommerce_Order_Result::read($britishOrder)['customer_fingerprint'])
        && $GLOBALS['uvbTestApiCalls'] === $callsBefore);
    uvbTestClearCheckoutCache($fixture->transientKeys);
    WC()->session->set('order_awaiting_payment', $britishOrder->get_id());
    uvbTestPublicHooks();
    $errors = uvbTestValidateCheckout($checkoutData);
    $fixture->record('Postcode normalization does not trigger another API check on an unchanged classic retry',
        !$errors->has_errors() && $GLOBALS['uvbTestApiCalls'] === $callsBefore, $errors->get_error_codes());
}

function uvbTestClassicChangedAddress(UVBCheckoutTestFixture $fixture): void {
    $classicChanged = uvbTestOrder();
    $classicChanged->set_created_via('checkout');
    $classicChanged->set_cart_hash(WC()->cart->get_cart_hash());
    UVBConnectorWooCommerce_Order_Result::write($classicChanged, uvbTestResult(null, false));
    $classicChanged->save();
    $before = UVBConnectorWooCommerce_Order_Result::read($classicChanged);
    WC()->session->set('order_awaiting_payment', $classicChanged->get_id());
    $changedAddress = uvbTestAddress();
    $changedAddress['email'] = 'classic-changed@example.invalid';
    $classicChanged->set_billing_email($changedAddress['email']);
    $classicChanged->save();
    $GLOBALS['uvbTestApiResponse'] = null;
    uvbTestPublicHooks();
    $errors = uvbTestValidateCheckout(uvbTestCheckoutData($changedAddress));
    $fixture->record('Classic retry cannot reuse an allowed decision after address mutation and API failure',
        $errors->get_error_message('uvb_payment_method_unavailable') !== ''
        && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($classicChanged->get_id())) === $before);
    WC()->session->set('order_awaiting_payment', null);
}

function uvbTestBlocksScriptLoading(UVBCheckoutTestFixture $fixture): void {
    uvbTestPublicHooks();
    $previousPost = $GLOBALS['post'] ?? null;
    $GLOBALS['post'] = get_post(wc_get_page_id('checkout'));
    add_filter('woocommerce_is_checkout', '__return_true');
    (new UVBConnectorWooCommerce_Public('uvb-connector-woocommerce', 'test'))->enqueueScripts();
    $fixture->record('Blocks script requests footer loading before WooCommerce adjusts script groups',
        wp_scripts()->get_data('uvb-connector-woocommerce', 'group') === 1);
    do_action('wp_enqueue_scripts');
    ob_start();
    wp_print_head_scripts();
    $headScripts = ob_get_clean();
    ob_start();
    do_action('wp_print_footer_scripts');
    $footerScripts = ob_get_clean();
    $settingsDataPosition = strpos($footerScripts, 'var wcSettings');
    $settingsScriptPosition = strpos($footerScripts, 'id="wc-settings-js"');
    $pluginScriptPosition = strpos($footerScripts, 'id="uvb-connector-woocommerce-js"');
    $fixture->record('Blocks dependencies stay out of the head',
        strpos($headScripts, 'id="wc-settings-js"') === false && strpos($headScripts, 'id="uvb-connector-woocommerce-js"') === false);
    $fixture->record('WooCommerce settings are printed before Blocks scripts in the footer',
        $settingsDataPosition !== false && $settingsScriptPosition !== false && $pluginScriptPosition !== false
        && $settingsDataPosition < $settingsScriptPosition && $settingsScriptPosition < $pluginScriptPosition);
    $GLOBALS['post'] = $previousPost;
    remove_filter('woocommerce_is_checkout', '__return_true');
}

function uvbTestLegacyExplicitMigration(UVBCheckoutTestFixture $fixture): void {
    foreach (['New reason', '', null] as $reason) {
        $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => true, 'reason' => $reason]];
        $legacy = uvbTestOrder(false);
        $legacy->update_meta_data(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY, 'Historical reason');
        $legacy->save_meta_data();
        $response = uvbTestRetry($legacy, 'bacs');
        $reloaded = new WC_Order($legacy->get_id());
        $saved = UVBConnectorWooCommerce_Order_Result::read($reloaded);
        $fixture->record('Legacy migration retains historical reason with response reason ' . var_export($reason, true),
            $response->get_status() === 200 && $reloaded->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Historical reason'
            && $saved !== null && $saved['api_response']['result']['reason'] === $reason);
        $callsBefore = $GLOBALS['uvbTestApiCalls'];
        uvbTestRetry($reloaded, 'bacs');
        $fixture->record('Migrated legacy decision is reused without another request', $GLOBALS['uvbTestApiCalls'] === $callsBefore);
    }
}

function uvbTestOrderPaymentPage(UVBCheckoutTestFixture $fixture): void {
    uvbTestPublicHooks();
    $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => true, 'reason' => 'New payment-page reason']];
    $legacy = uvbTestOrder(false);
    $legacy->update_meta_data(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY, 'Historical reason');
    $legacy->save_meta_data();
    add_filter('woocommerce_is_checkout', '__return_true');
    $GLOBALS['wp']->query_vars['order-pay'] = $legacy->get_id();
    $GLOBALS['wp_query']->set('order-pay', $legacy->get_id());
    $_GET['key'] = $legacy->get_order_key();
    $gateways = WC()->payment_gateways()->get_available_payment_gateways();
    $reloaded = new WC_Order($legacy->get_id());
    $fixture->record('Opening a legacy payment page preserves its historical reason',
        isset($gateways['bacs']) && !isset($gateways['cod'])
        && $reloaded->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Historical reason'
        && UVBConnectorWooCommerce_Order_Result::read($reloaded)['api_response']['result']['reason'] === 'New payment-page reason');

    foreach ([true, null] as $verdict) {
        $payOrder = uvbTestOrder(false);
        UVBConnectorWooCommerce_Order_Result::write($payOrder, uvbTestResult(null, false));
        $payOrder->set_billing_email('changed-payment-page-' . (int) $verdict . '@example.invalid');
        $payOrder->save();
        $before = UVBConnectorWooCommerce_Order_Result::read($payOrder);
        $GLOBALS['wp']->query_vars['order-pay'] = $payOrder->get_id();
        $GLOBALS['wp_query']->set('order-pay', $payOrder->get_id());
        $_GET['key'] = $payOrder->get_order_key();
        $GLOBALS['uvbTestApiResponse'] = $verdict === null ? null : (object) ['result' => (object) ['blocked' => true, 'reason' => 'Changed payment data']];
        $callsBefore = $GLOBALS['uvbTestApiCalls'];
        uvbTestPublicHooks();
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $saved = UVBConnectorWooCommerce_Order_Result::read(new WC_Order($payOrder->get_id()));
        $fixture->record('Payment page revalidates mutated data and cannot reuse the previous allowance: ' . (int) $verdict,
            isset($gateways['bacs']) && !isset($gateways['cod']) && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1
            && ($verdict === null ? $saved === $before : $saved['blocked'] === true && $saved['customer_fingerprint'] !== $before['customer_fingerprint']));
    }
    unset($_GET['key'], $GLOBALS['wp']->query_vars['order-pay']);
    $GLOBALS['wp_query']->set('order-pay', false);
    remove_filter('woocommerce_is_checkout', '__return_true');
}

function uvbTestVirtualOrderPersistence(UVBCheckoutTestFixture $fixture): void {
    foreach ([false, true] as $blocked) {
        foreach ([false, true] as $phoneProvided) {
            uvbTestClearCheckoutCache($fixture->transientKeys);
            WC()->session->set('store_api_draft_order', null);
            uvbTestPublicHooks();
            $billing = uvbTestAddress();
            $billing['email'] = 'native-virtual-' . (int) $blocked . '-' . (int) $phoneProvided . '@example.invalid';
            $billing['phone'] = '+3612345678';
            $billing['address_2'] = 'Billing floor';
            $shipping = uvbTestAddress();
            unset($shipping['email']);
            $shipping['country'] = 'AT';
            $shipping['state'] = '';
            $shipping['city'] = 'Vienna';
            $shipping['postcode'] = '1010';
            $shipping['address_1'] = 'Submitted shipping street';
            if (!$phoneProvided) { unset($shipping['phone']); }
            foreach ($billing as $field => $value) { WC()->customer->{'set_billing_' . $field}($value); }
            $retry = uvbTestResumableOrder('pending');
            $retry->save();
            $expected = UVBConnectorWooCommerce_Customer_Data::fromAddresses($shipping, $billing);
            $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => $blocked, 'reason' => 'Native virtual shipping decision']];
            (new UVBConnectorWooCommerce_Public('uvb-connector-woocommerce', 'test'))->checkBlocksCustomer($expected);
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            $response = uvbTestStoreSessionRetry($retry, $blocked ? 'bacs' : 'cod', $billing, $shipping);
            $reloaded = new WC_Order($retry->get_id());
            $saved = UVBConnectorWooCommerce_Order_Result::read($reloaded);
            $case = (int) $blocked . '/' . (int) $phoneProvided;
            $fixture->record('Native virtual checkout checks submitted shipping and saves the frontend result: ' . $case,
                $response->get_status() === 200 && !WC()->cart->needs_shipping()
                && WC()->customer->get_shipping_postcode() === $billing['postcode']
                && $GLOBALS['uvbTestApiCalls'] === $callsBefore
                && $saved !== null && $saved['blocked'] === $blocked
                && UVBConnectorWooCommerce_Order_Result::matchesCustomerData($saved, $expected),
                ['status' => $response->get_status(), 'extra_calls' => $GLOBALS['uvbTestApiCalls'] - $callsBefore]);
            uvbTestClearCheckoutCache($fixture->transientKeys);
            $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => !$blocked, 'reason' => 'Later opposite decision']];
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            $response = uvbTestStoreSessionRetry($reloaded, $blocked ? 'bacs' : 'cod', $billing, $shipping);
            $fixture->record('Native virtual retry reuses the submitted shipping fingerprint after cache expiry: ' . $case,
                $response->get_status() === 200 && $GLOBALS['uvbTestApiCalls'] === $callsBefore
                && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($retry->get_id())) === $saved);

            $reloaded = new WC_Order($retry->get_id());
            uvbTestClearCheckoutCache($fixture->transientKeys);
            $GLOBALS['uvbTestApiResponse'] = null;
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            uvbTestPublicHooks();
            add_filter('woocommerce_is_checkout', '__return_true');
            $GLOBALS['wp']->query_vars['order-pay'] = $reloaded->get_id();
            $GLOBALS['wp_query']->set('order-pay', $reloaded->get_id());
            $_GET['key'] = $reloaded->get_order_key();
            $gateways = WC()->payment_gateways()->get_available_payment_gateways();
            $fixture->record('Virtual order payment page retains the saved decision during an API outage: ' . $case,
                isset($gateways[$blocked ? 'bacs' : 'cod']) && !isset($gateways[$blocked ? 'cod' : 'bacs'])
                && $GLOBALS['uvbTestApiCalls'] === $callsBefore
                && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($retry->get_id())) === $saved);
            unset($_GET['key'], $GLOBALS['wp']->query_vars['order-pay']);
            $GLOBALS['wp_query']->set('order-pay', false);
            remove_filter('woocommerce_is_checkout', '__return_true');

            $response = uvbTestRetry($reloaded, $blocked ? 'bacs' : 'cod');
            $fixture->record('Virtual order explicit retry reuses its persisted addresses without an API call: ' . $case,
                $response->get_status() === 200 && $GLOBALS['uvbTestApiCalls'] === $callsBefore
                && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($retry->get_id())) === $saved,
                ['status' => $response->get_status(), 'extra_calls' => $GLOBALS['uvbTestApiCalls'] - $callsBefore]);
            $response = uvbTestRetry($reloaded, $blocked ? 'cod' : 'bacs');
            $fixture->record('Virtual order explicit retry still rejects the unavailable gateway: ' . $case,
                $response->get_status() === 400 && $GLOBALS['uvbTestApiCalls'] === $callsBefore);

            $changedShipping = $reloaded->get_address('shipping');
            $changedShipping['address_1'] = 'Corrected retry street';
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            $response = uvbTestRetry($reloaded, 'cod', true, null, $changedShipping);
            $fixture->record('Virtual order checks corrected retry addresses and preserves its result on failure: ' . $case,
                $response->get_status() === 400 && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1
                && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($retry->get_id())) === $saved);

            $changedBilling = $reloaded->get_address('billing');
            $changedBilling['email'] = 'mutated-virtual-' . $retry->get_id() . '@example.invalid';
            uvbTestSetCustomerAddresses($changedBilling);
            WC()->session->set('store_api_draft_order', $retry->get_id());
            uvbTestPublicHooks();
            $request = new WP_REST_Request('GET', '/wc/store/v1/checkout');
            $request->set_header('Nonce', wp_create_nonce('wc_store_api'));
            $routes = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get(\Automattic\WooCommerce\StoreApi\RoutesController::class);
            $routes->get('checkout')->get_response($request);
            $reloaded = new WC_Order($retry->get_id());
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            $response = uvbTestStoreSessionRetry($reloaded, 'cod');
            $fixture->record('Virtual order snapshot cannot authorize customer data changed by Checkout GET: ' . $case,
                $reloaded->get_billing_email() === $changedBilling['email']
                && $response->get_status() === 400 && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1
                && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($retry->get_id())) === $saved);
        }
    }
}

function uvbTestClassicAddressRefresh(UVBCheckoutTestFixture $fixture): void {
    foreach (['email', 'phone'] as $field) {
        foreach ([false, true] as $blocked) {
            uvbTestResetCheckoutState($fixture->transientKeys);
            uvbTestPublicHooks();
            $billing = uvbTestAddress();
            uvbTestSetCustomerAddresses($billing);
            WC()->cart->calculate_totals();
            $retry = uvbTestResumableOrder($blocked ? 'failed' : 'pending', 'checkout');
            UVBConnectorWooCommerce_Order_Result::write($retry, uvbTestResult(null, !$blocked));
            $retry->save();
            WC()->session->set('order_awaiting_payment', $retry->get_id());
            $changedBilling = $billing;
            $changedBilling[$field] = $field === 'email' ? 'classic-refresh-' . (int) $blocked . '@example.invalid' : '+3612345678';
            uvbTestSeedResult($changedBilling, $blocked);
            uvbTestPublicHooks();
            $checkoutData = uvbTestCheckoutData($changedBilling, $blocked ? 'bacs' : 'cod');
            $callsBefore = $GLOBALS['uvbTestApiCalls'];
            $response = uvbTestClassicRefresh($checkoutData);
            $paymentHtml = $response['fragments']['.woocommerce-checkout-payment'] ?? '';
            $fixture->record('Native classic refresh uses submitted ' . $field . ' despite the stale customer record: ' . (int) $blocked,
                WC()->customer->{'get_billing_' . $field}() === $billing[$field]
                && strpos($paymentHtml, 'payment_method_' . ($blocked ? 'bacs' : 'cod')) !== false
                && strpos($paymentHtml, 'payment_method_' . ($blocked ? 'cod' : 'bacs')) === false
                && $GLOBALS['uvbTestApiCalls'] === $callsBefore);
            uvbTestPublicHooks();
            $errors = uvbTestValidateCheckout($checkoutData);
            $createdId = WC()->checkout()->create_order($checkoutData);
            $saved = UVBConnectorWooCommerce_Order_Result::read(new WC_Order($retry->get_id()));
            $fixture->record('Classic refreshed payment choice validates and saves without another check: ' . $field . '/' . (int) $blocked,
                !$errors->has_errors() && $createdId === $retry->get_id() && $GLOBALS['uvbTestApiCalls'] === $callsBefore
                && $saved !== null && $saved['blocked'] === $blocked
                && UVBConnectorWooCommerce_Order_Result::matchesCustomerData($saved, UVBConnectorWooCommerce_Customer_Data::fromCheckout($checkoutData)),
                $errors->get_error_codes());
        }
    }
}

function uvbTestAdminApi(UVBCheckoutTestFixture $fixture): void {
    $adminOrder = uvbTestOrder(false);
    $adminOrder->set_created_via('rest-api');
    $adminOrder->save();
    $admin = new UVBConnectorWooCommerce_Admin('uvb-connector-woocommerce', 'test');
    $public = new UVBConnectorWooCommerce_Public('uvb-connector-woocommerce', 'test');
    $data = UVBConnectorWooCommerce_Customer_Data::fromOrder($adminOrder);
    $GLOBALS['uvbTestApiResponse'] = (object) [
        'request_id' => 'shared-api-regression',
        'result' => (object) ['blocked' => true, 'reason' => 'Synthetic shared API result'],
    ];
    $response = $public->checkInUVBService($data['email'], $data['country_code'], $data['postal_code'], $data['phone_number'], $data['address_line']);
    $publicPayload = $GLOBALS['uvbTestLastApiRequest'];
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    $admin->flagOrder($adminOrder->get_id());
    $saved = UVBConnectorWooCommerce_Order_Result::read(new WC_Order($adminOrder->get_id()));
    $fixture->record('Public and admin reputation requests send the same customer data and configuration',
        $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1 && $GLOBALS['uvbTestLastApiRequest'] === $publicPayload
        && $response->request_id === 'shared-api-regression');
    $fixture->record('Admin-created orders retain the complete API response and checked fingerprint',
        $saved !== null && $saved['blocked'] === true && $saved['api_response']['request_id'] === 'shared-api-regression'
        && UVBConnectorWooCommerce_Order_Result::matchesCustomerData($saved, $data)
        && UVBConnectorWooCommerce_Admin::getFlagFromDb($adminOrder->get_id()) === 'Synthetic shared API result');
    foreach ([null, (object) ['result' => (object) ['reason' => 'Missing decision']]] as $invalidResponse) {
        $GLOBALS['uvbTestApiResponse'] = $invalidResponse;
        $admin->flagOrder($adminOrder->get_id());
        $fixture->record('Failed admin checks preserve saved metadata and record their own failure: ' . ($invalidResponse === null ? 'empty' : 'invalid'),
            UVBConnectorWooCommerce_Order_Result::read(new WC_Order($adminOrder->get_id())) === $saved
            && get_option('uvb_connector_api_failure_order_flag', false) !== false);
    }
    $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => false, 'reason' => 'Synthetic recovery']];
    $admin->flagOrder($adminOrder->get_id());
    $fixture->record('A successful admin check clears its failure state and updates the decision',
        get_option('uvb_connector_api_failure_order_flag', false) === false
        && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($adminOrder->get_id()))['blocked'] === false);
}

function uvbTestCacheLifetime(UVBCheckoutTestFixture $fixture): void {
    $data = UVBConnectorWooCommerce_Customer_Data::fromAddresses([], uvbTestAddress());
    $public = new UVBConnectorWooCommerce_Public('uvb-connector-woocommerce', 'test');
    $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => false, 'reason' => 'Cached result']];
    $data['email'] = 'cache-lifetime-regression@example.invalid';
    $public->checkBlocksCustomer($data);
    $cache = new UVBConnectorWooCommerce_Checkout_Result_Cache();
    $sessionKey = $cache->getSessionKey();
    $requestKey = $cache->getCustomerRequestKey($sessionKey, $data);
    $cached = $cache->getResult($sessionKey, $requestKey);
    $fixture->record('New API results expire thirty minutes after the original check',
        $cached !== null && $cached['expires_at'] - $cached['checked_at'] === 1800);
    if ($cached !== null) {
        $cached['checked_at'] -= 600;
        $cached['expires_at'] -= 600;
        set_transient($requestKey, $cached, 1200);
        $callsBefore = $GLOBALS['uvbTestApiCalls'];
        $public->checkBlocksCustomer($data);
        $snapshot = $cache->getCurrentResult($sessionKey);
        $fixture->record('Reusing a ten-minute-old result does not call the service or extend its lifetime',
            $GLOBALS['uvbTestApiCalls'] === $callsBefore && $snapshot['expires_at'] === $cached['expires_at']
            && $snapshot['checked_at'] === $cached['checked_at']);
    }
}

function uvbTestFrontendRetryDecisions(UVBCheckoutTestFixture $fixture): void {
    foreach (['classic', 'blocks'] as $route) {
        foreach (['pending', 'failed'] as $status) {
            foreach ([false, true] as $blocked) {
                uvbTestResetCheckoutState($fixture->transientKeys);
                $billing = uvbTestAddress();
                uvbTestSetCustomerAddresses($billing);
                $data = UVBConnectorWooCommerce_Customer_Data::fromAddresses([], $billing);
                $order = uvbTestResumableOrder($status, $route === 'classic' ? 'checkout' : 'store-api');
                UVBConnectorWooCommerce_Order_Result::write($order, uvbTestResult($data, $blocked));
                $order->save();
                WC()->session->set($route === 'classic' ? 'order_awaiting_payment' : 'store_api_draft_order', $order->get_id());
                $before = UVBConnectorWooCommerce_Order_Result::read($order);
                $GLOBALS['uvbTestApiResponse'] = null;
                $callsBefore = $GLOBALS['uvbTestApiCalls'];
                $case = $route . '/' . $status . '/' . (int) $blocked;

                uvbTestFrontendCheck($route, $data);
                $gateways = WC()->payment_gateways()->get_available_payment_gateways();
                $fixture->record('Frontend uses the saved decision after cache expiry: ' . $case,
                    $GLOBALS['uvbTestApiCalls'] === $callsBefore
                    && isset($gateways[$blocked ? 'bacs' : 'cod']) && !isset($gateways[$blocked ? 'cod' : 'bacs']));

                $method = $blocked ? 'bacs' : 'cod';
                if ($route === 'classic') {
                    $errors = uvbTestValidateCheckout(uvbTestCheckoutData($billing, $method));
                    $succeeded = !$errors->has_errors();
                } else {
                    $response = uvbTestStoreSessionRetry($order, $method);
                    $succeeded = $response->get_status() === 200;
                }
                $fixture->record('Submission agrees with the frontend without another request: ' . $case,
                    $succeeded && $GLOBALS['uvbTestApiCalls'] === $callsBefore);
                $response = uvbTestRetry($order, $method);
                $fixture->record('Explicit payment retry keeps the same saved decision: ' . $case,
                    $response->get_status() === 200 && $GLOBALS['uvbTestApiCalls'] === $callsBefore
                    && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($order->get_id())) === $before);

                $billing['email'] = 'frontend-changed-' . $order->get_id() . '@example.invalid';
                $changed = UVBConnectorWooCommerce_Customer_Data::fromAddresses([], $billing);
                $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => !$blocked, 'reason' => 'Changed frontend data']];
                uvbTestFrontendCheck($route, $changed);
                $fixture->record('Frontend checks changed data without rewriting the order: ' . $case,
                    $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1
                    && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($order->get_id())) === $before);
                if ($route === 'classic') {
                    $errors = uvbTestValidateCheckout(uvbTestCheckoutData($billing, $blocked ? 'cod' : 'bacs'));
                    $succeeded = !$errors->has_errors();
                } else {
                    $response = uvbTestStoreSessionRetry($order, $blocked ? 'cod' : 'bacs', $billing);
                    $succeeded = $response->get_status() === 200;
                }
                $fixture->record('Submission reuses the changed frontend result: ' . $case,
                    $succeeded && $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1);
            }
        }
    }
}

function uvbTestReadOnlyResultLookup(UVBCheckoutTestFixture $fixture): void {
    $order = uvbTestResumableOrder('pending', 'checkout');
    $order->save();
    WC()->session->set('order_awaiting_payment', $order->get_id());
    $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => true]];
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    WC()->payment_gateways()->get_available_payment_gateways();
    do_action('woocommerce_checkout_create_order', $order, uvbTestCheckoutData(uvbTestAddress()));
    $fixture->record('Gateway filtering and order persistence do not start a check on a cache miss',
        $GLOBALS['uvbTestApiCalls'] === $callsBefore && UVBConnectorWooCommerce_Order_Result::read($order) === null);
}

function uvbTestCacheStorage(UVBCheckoutTestFixture $fixture): void {
    $data = UVBConnectorWooCommerce_Customer_Data::fromAddresses([], uvbTestAddress());
    uvbTestSeedResult(uvbTestAddress(), true);
    $cache = new UVBConnectorWooCommerce_Checkout_Result_Cache();
    $sessionKey = $cache->getSessionKey();
    $requestKey = $cache->getCustomerRequestKey($sessionKey, $data);
    $result = $cache->getCurrentResult($sessionKey);
    $fixture->record('The cache stores one response and one session pointer',
        count($fixture->transientKeys) === 2 && $result !== null
        && get_transient($sessionKey . '_result') === ['request_key' => $requestKey]
        && get_transient($sessionKey) === false);

    // Old snapshots remain readable, but their embedded decision cannot override the canonical response.
    set_transient($sessionKey . '_result', ['request_key' => $requestKey, 'result' => ['blocked' => false]], 86400);
    $fixture->record('Legacy session snapshots resolve the canonical response', $cache->getCurrentResult($sessionKey) === $result);
    $result['expires_at'] = time() - 1;
    set_transient($requestKey, $result, 60);
    $fixture->record('An old snapshot cannot extend the response lifetime', $cache->getCurrentResult($sessionKey) === null);
}

function uvbTestSettingsHooks(UVBCheckoutTestFixture $fixture): void {
    require_once ABSPATH . 'wp-admin/includes/template.php';
    $settings = new UVBConnectorWooCommerce_Settings();
    $fixture->record('Constructing settings does not register hooks',
        has_action('admin_menu', [$settings, 'addPluginPage']) === false
        && has_action('admin_init', [$settings, 'pageInit']) === false);

    $reflection = new ReflectionClass(UVBConnectorWooCommerce::class);
    $plugin = $reflection->newInstanceWithoutConstructor();
    $loader = new UVBConnectorWooCommerce_Loader();
    foreach (['loader' => $loader, 'plugin_name' => 'uvb-connector-woocommerce', 'version' => 'test'] as $name => $value) {
        $reflection->getProperty($name)->setValue($plugin, $value);
    }
    $reflection->getMethod('defineAdminHooks')->invoke($plugin);
    $plugin->run();
    $callbacks = [];
    foreach (['admin_menu', 'admin_init'] as $hook) {
        foreach ($GLOBALS['wp_filter'][$hook]->callbacks as $registered) {
            foreach ($registered as $callback) {
                if (is_array($callback['function']) && $callback['function'][0] instanceof UVBConnectorWooCommerce_Settings) {
                    $callbacks[$hook][] = $callback['function'];
                }
            }
        }
    }
    $fixture->record('The loader registers settings menu and fields on the same object',
        !empty($callbacks['admin_menu']) && !empty($callbacks['admin_init'])
        && end($callbacks['admin_menu'])[0] === end($callbacks['admin_init'])[0]);
    end($callbacks['admin_init'])();
    $fixture->record('The registered settings callback creates the existing fields',
        count($GLOBALS['wp_settings_fields']['uvb-connector-woocommerce-admin']['uvb_connector_woocommerce_section_id'] ?? []) === 6);
}

function uvbTestConfigurationSnapshot(UVBCheckoutTestFixture $fixture): void {
    $threshold = 0.25;
    $optionsFilter = function($options) use (&$threshold) {
        $options['reputation_threshold'] = $threshold;
        $options['public_api_key'] = 'synthetic-public';
        $options['private_api_key'] = 'synthetic-private';
        return $options;
    };
    add_filter('option_uvb_connector_woocommerce_options', $optionsFilter, 99);
    $api = new UVBConnectorWooCommerce_Api();
    $public = new UVBConnectorWooCommerce_Public('uvb-connector-woocommerce', 'test', $api);
    $cache = new UVBConnectorWooCommerce_Checkout_Result_Cache($api->getConfigurationSnapshot());
    $data = UVBConnectorWooCommerce_Customer_Data::fromAddresses([], uvbTestAddress());
    $sessionKey = $cache->getSessionKey();
    $originalKey = $cache->getCustomerRequestKey($sessionKey, $data);
    $threshold = 0.75;
    $GLOBALS['uvbTestApiResponse'] = (object) ['result' => (object) ['blocked' => false, 'reason' => 'Snapshot verdict']];
    $public->checkBlocksCustomer($data);
    $fixture->record('API and cache retain the same configuration after options change within a request',
        $GLOBALS['uvbTestLastApiRequest']['threshold'] === 0.25
        && $cache->getCustomerRequestKey($sessionKey, $data) === $originalKey
        && $cache->getResult($sessionKey, $originalKey) !== null);

    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    (new UVBConnectorWooCommerce_Public('uvb-connector-woocommerce', 'test', $api))->checkBlocksCustomer($data);
    $fixture->record('A new checkout adapter sharing the API snapshot reuses its cached result',
        $GLOBALS['uvbTestApiCalls'] === $callsBefore);

    $freshCache = new UVBConnectorWooCommerce_Checkout_Result_Cache();
    $freshKey = $freshCache->getCustomerRequestKey($sessionKey, $data);
    $fixture->record('New configuration does not read a result checked with the previous threshold',
        $freshKey !== $originalKey && $freshCache->getCustomerResult($sessionKey, $data) === null
        && UVBConnectorWooCommerce_Api::getConfiguration()['threshold'] === 0.75);
    (new UVBConnectorWooCommerce_Public('uvb-connector-woocommerce', 'test'))->checkBlocksCustomer($data);
    $fixture->record('A fresh API and cache use the new configuration together',
        $GLOBALS['uvbTestApiCalls'] === $callsBefore + 1 && $GLOBALS['uvbTestLastApiRequest']['threshold'] === 0.75
        && $freshCache->getResult($sessionKey, $freshKey) !== null);

    $order = uvbTestOrder(false);
    $order->set_created_via('rest-api');
    $order->save();
    (new UVBConnectorWooCommerce_Admin('uvb-connector-woocommerce', 'test', $api))->flagOrder($order->get_id());
    $fixture->record('The admin adapter uses the same injected API configuration',
        $GLOBALS['uvbTestLastApiRequest']['threshold'] === 0.25
        && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($order->get_id()))['blocked'] === false);

    $configuration = $api->getConfigurationSnapshot();
    $configuration['threshold'] = -1;
    $api->request($data);
    $fixture->record('Changing a returned configuration array cannot mutate the API snapshot',
        $GLOBALS['uvbTestLastApiRequest']['threshold'] === 0.25);
}

function uvbTestResultProcessingCompatibility(UVBCheckoutTestFixture $fixture): void {
    foreach ([null, false, [], (object) [], (object) ['result' => (object) ['reason' => 'Missing decision']]] as $response) {
        $fixture->record('Invalid API response remains a cache miss: ' . wp_json_encode($response),
            UVBConnectorWooCommerce_Check_Result::fromResponse($response) === null
            && UVBConnectorWooCommerce_Order_Result::fromResponse($response) === null);
    }
    $data = UVBConnectorWooCommerce_Customer_Data::fromAddresses([], uvbTestAddress());
    $response = (object) ['request_id' => 'processing-compatibility', 'result' => (object) ['blocked' => false, 'reason' => 'Allowed']];
    $result = UVBConnectorWooCommerce_Check_Result::fromResponse($response, $data);
    $legacyResult = UVBConnectorWooCommerce_Order_Result::fromResponse($response, $data);
    $fixture->record('Result processing retains the full response and the legacy return shape',
        $result['blocked'] === false && $result['reason'] === 'Allowed'
        && $result['api_response'] === json_decode(wp_json_encode($response), true)
        && is_int($result['checked_at']) && abs($result['checked_at'] - time()) <= 1
        && array_diff_key($result, $legacyResult) === []
        && $result['customer_fingerprint'] === $legacyResult['customer_fingerprint']);

    $orderData = $data;
    $orderData['address_line'] = 'Persisted virtual order address';
    $result['order_fingerprint'] = UVBConnectorWooCommerce_Customer_Data::getFingerprint($orderData);
    $fixture->record('Checked and persisted order fingerprints retain their different meanings',
        UVBConnectorWooCommerce_Check_Result::matchesCustomerData($result, $data)
        && !UVBConnectorWooCommerce_Check_Result::matchesCustomerData($result, $orderData)
        && UVBConnectorWooCommerce_Check_Result::matchesOrderData($result, $orderData)
        && UVBConnectorWooCommerce_Order_Result::matchesCustomerData($result, $data)
        && UVBConnectorWooCommerce_Order_Result::matchesOrderData($result, $orderData));
    $orderData['email'] = 'changed-result@example.invalid';
    $fixture->record('Neither fingerprint accepts changed customer data',
        !UVBConnectorWooCommerce_Check_Result::matchesOrderData($result, $orderData)
        && !UVBConnectorWooCommerce_Order_Result::matchesOrderData($result, $orderData));
}

function uvbTestOrderReasonStorage(UVBCheckoutTestFixture $fixture): void {
    $order = uvbTestOrder(false);
    $result = uvbTestResult(null, false, 'Structured reason');
    UVBConnectorWooCommerce_Order_Result::write($order, $result);
    $order->save_meta_data();
    $before = UVBConnectorWooCommerce_Order_Result::read(new WC_Order($order->get_id()));
    $callsBefore = $GLOBALS['uvbTestApiCalls'];
    UVBConnectorWooCommerce_Admin::writeFlagToDb($order->get_id(), 'Historical reason');
    $reloaded = new WC_Order($order->get_id());
    $fixture->record('Legacy flag writes persist without changing the structured result or checking the API',
        $reloaded->get_meta(UVBConnectorWooCommerce_Order_Result::LEGACY_META_KEY) === 'Historical reason'
        && UVBConnectorWooCommerce_Order_Result::read($reloaded) === $before
        && $GLOBALS['uvbTestApiCalls'] === $callsBefore);
    $fixture->record('Admin and storage readers prefer the historical reason',
        UVBConnectorWooCommerce_Order_Result::getReason($reloaded) === 'Historical reason'
        && UVBConnectorWooCommerce_Admin::getFlagFromDb($order->get_id()) === 'Historical reason');

    UVBConnectorWooCommerce_Order_Result::write($reloaded, uvbTestResult(null, true, 'Latest reason'), true);
    $reloaded->save_meta_data();
    $fixture->record('Saving a new decision preserves the legacy reason when requested',
        UVBConnectorWooCommerce_Admin::getFlagFromDb($order->get_id()) === 'Historical reason'
        && UVBConnectorWooCommerce_Order_Result::read(new WC_Order($order->get_id()))['reason'] === 'Latest reason');
    UVBConnectorWooCommerce_Admin::writeFlagToDb($order->get_id(), '');
    $fixture->record('Empty legacy reasons fall back to the structured reason',
        UVBConnectorWooCommerce_Admin::getFlagFromDb($order->get_id()) === 'Latest reason');

    $reloaded = new WC_Order($order->get_id());
    UVBConnectorWooCommerce_Order_Result::write($reloaded, ['blocked' => true, 'reason' => 'Older cache result']);
    $reloaded->save_meta_data();
    $saved = UVBConnectorWooCommerce_Order_Result::read(new WC_Order($order->get_id()));
    $fixture->record('Older results retain the version-one storage format without authorizing unchecked data',
        $saved['schema_version'] === 1 && $saved['blocked'] === true
        && $saved['api_response'] === null && $saved['customer_fingerprint'] === null && $saved['order_fingerprint'] === null
        && !UVBConnectorWooCommerce_Check_Result::matchesOrderData($saved, UVBConnectorWooCommerce_Customer_Data::fromOrder($order))
        && UVBConnectorWooCommerce_Admin::getFlagFromDb($order->get_id()) === 'Older cache result');

    UVBConnectorWooCommerce_Order_Result::write($reloaded, null, true);
    $reloaded->save_meta_data();
    $fixture->record('Clearing a structured result can retain the historical reason',
        UVBConnectorWooCommerce_Order_Result::read(new WC_Order($order->get_id())) === null
        && UVBConnectorWooCommerce_Admin::getFlagFromDb($order->get_id()) === 'Older cache result');
    UVBConnectorWooCommerce_Order_Result::write($reloaded, null);
    $reloaded->save_meta_data();
    $fixture->record('An explicit full clear removes both stored representations',
        UVBConnectorWooCommerce_Order_Result::read(new WC_Order($order->get_id())) === null
        && UVBConnectorWooCommerce_Admin::getFlagFromDb($order->get_id()) === '');
}
