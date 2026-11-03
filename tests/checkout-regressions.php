<?php
/**
 * Integration checks for a local WordPress installation with WooCommerce and this plugin active.
 * Load wp-load.php before this file and set UVB_RUN_CHECKOUT_TESTS=1 explicitly.
 * External API calls and email delivery are replaced in this PHP process only.
 */
namespace UtanvetEllenor {
    function curl_init() { $GLOBALS['uvbTestApiCalls']++; return new \stdClass(); }
    function curl_setopt_array($handle, $options) { $GLOBALS['uvbTestLastApiRequest'] = json_decode($options[CURLOPT_POSTFIELDS], true); }
    function curl_exec($handle) { return json_encode($GLOBALS['uvbTestApiResponse']); }
    function curl_error($handle) { return ''; }
    function curl_close($handle) {}
}

namespace {
    if (PHP_SAPI !== 'cli' || getenv('UVB_RUN_CHECKOUT_TESTS') !== '1' || !function_exists('WC')) {
        fwrite(STDERR, "Load WordPress in CLI and set UVB_RUN_CHECKOUT_TESTS=1 to run these integration checks.\n");
        exit(1);
    }

    require __DIR__ . '/checkout/helpers.php';
    require __DIR__ . '/checkout/fixture.php';
    require __DIR__ . '/checkout/scenarios.php';

    add_filter('pre_wp_mail', '__return_true');
    add_filter('pre_transient_uvb_connector_api_log_' . hash('sha256', 'request:empty_response'), '__return_true');
    foreach (['empty_response', 'invalid_response'] as $reason) {
        add_filter('pre_transient_uvb_connector_api_log_' . hash('sha256', 'order_flag:' . $reason), '__return_true');
    }
    add_filter('woocommerce_logger_log_message', function($message, $level, $context) {
        return ($context['source'] ?? '') === 'uvb-connector-woocommerce' ? null : $message;
    }, 10, 3);
    add_filter('option_uvb_connector_woocommerce_options', function($options) {
        $options['payment_methods_to_hide'] = ['cod'];
        $options['fallback_payment_methods'] = ['bacs'];
        return $options;
    });

    $scenarios = [
        'ExplicitPaymentRetries' => false,
        'SavedResultPreservation' => false,
        'AddressSelection' => false,
        'ClassicSavedRestrictions' => true,
        'StaleGatewayErrors' => true,
        'ClassicGatewayRevalidation' => true,
        'ValidatedResultPersistence' => true,
        'SubmittedAddresses' => true,
        'ShippingPhonePersistence' => true,
        'StoreSessionOrderSelection' => true,
        'CheckoutTypeSwitch' => true,
        'SessionSavedDecisions' => true,
        'DraftCheckoutDecisions' => true,
        'ChangedSessionData' => true,
        'ClassicSavedDecisions' => true,
        'LegacyCheckoutMigration' => true,
        'MutableOrderAddresses' => true,
        'LegacyFingerprintMigration' => true,
        'PostcodeNormalization' => true,
        'ClassicChangedAddress' => true,
        'BlocksScriptLoading' => true,
        'LegacyExplicitMigration' => false,
        'OrderPaymentPage' => false,
        'VirtualOrderPersistence' => true,
        'ClassicAddressRefresh' => true,
        'AdminApi' => false,
        'CacheLifetime' => true,
        'FrontendRetryDecisions' => true,
        'ReadOnlyResultLookup' => true,
        'CacheStorage' => true,
        'SettingsHooks' => false,
        'ConfigurationSnapshot' => true,
        'ResultProcessingCompatibility' => false,
        'OrderReasonStorage' => false,
    ];
    // Set UVB_CHECKOUT_SCENARIO to run one scenario without running its predecessors.
    $selectedScenario = getenv('UVB_CHECKOUT_SCENARIO');
    if ($selectedScenario) {
        if (!array_key_exists($selectedScenario, $scenarios)) {
            fwrite(STDERR, 'Unknown checkout scenario: ' . $selectedScenario . PHP_EOL);
            exit(1);
        }
        $scenarios = [$selectedScenario => $scenarios[$selectedScenario]];
    }
    if (getenv('UVB_CHECKOUT_REVERSE') === '1') {
        $scenarios = array_reverse($scenarios, true);
    }

    wc_load_cart();
    // Register native REST payment handlers before fixtures snapshot and restore the hooks.
    rest_get_server();
    $checks = [];
    foreach ($scenarios as $name => $withCart) {
        $fixture = new UVBCheckoutTestFixture($name);
        try {
            $fixture->setUp($withCart);
            ('uvbTest' . $name)($fixture);
        } catch (Throwable $exception) {
            $fixture->record('Scenario execution', false, $exception->getMessage());
        } finally {
            try {
                $fixture->tearDown();
            } catch (Throwable $exception) {
                $fixture->record('Scenario cleanup', false, $exception->getMessage());
            }
            array_push($checks, ...$fixture->checks);
        }
    }
    WC()->session->destroy_session();
    $failed = array_filter($checks, fn($check) => !$check['passed']);
    echo wp_json_encode(['result' => $failed ? 'FAIL' : 'PASS', 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($failed ? 1 : 0);
}
