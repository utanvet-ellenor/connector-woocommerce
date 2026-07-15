<?php

class UVBConnectorWooCommerce_Api_Logger {
    private const SOURCE = 'uvb-connector-woocommerce';
    private const LOG_THROTTLE_SECONDS = 300;

    public static function logFailure($operation, $reason, $exception = null) {
        $failureOption = self::getFailureOptionName($operation);
        if (get_option($failureOption, false) === false) {
            update_option($failureOption, time(), false);
        }

        $throttleKey = self::getThrottleKey($operation, $reason);
        if (get_transient($throttleKey)) {
            return;
        }

        set_transient($throttleKey, true, self::LOG_THROTTLE_SECONDS);

        if (!function_exists('wc_get_logger')) {
            return;
        }

        $context = [
            'source' => self::SOURCE,
            'operation' => sanitize_key($operation),
            'reason' => sanitize_key($reason),
        ];

        if ($exception instanceof \Throwable) {
            $context['exception_class'] = get_class($exception);
        }

        wc_get_logger()->warning(
            'Utánvét Ellenőr API request failed.',
            $context
        );
    }

    public static function logRecovery($operation) {
        $failureOption = self::getFailureOptionName($operation);
        if (get_option($failureOption, false) === false) {
            return;
        }

        delete_option($failureOption);

        if (!function_exists('wc_get_logger')) {
            return;
        }

        wc_get_logger()->info(
            'Utánvét Ellenőr API connection recovered.',
            [
                'source' => self::SOURCE,
                'operation' => sanitize_key($operation),
            ]
        );
    }

    private static function getFailureOptionName($operation) {
        return 'uvb_connector_api_failure_' . sanitize_key($operation);
    }

    private static function getThrottleKey($operation, $reason) {
        return 'uvb_connector_api_log_' . hash('sha256', $operation . ':' . $reason);
    }
}
