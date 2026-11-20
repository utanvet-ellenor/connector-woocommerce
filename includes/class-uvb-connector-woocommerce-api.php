<?php

use UtanvetEllenor\Client;

class UVBConnectorWooCommerce_Api {
    private readonly array $configuration;

    public function __construct(?array $configuration = null) {
        $this->configuration = $configuration ?? self::getConfiguration();
    }

    public function getConfigurationSnapshot(): array {
        return $this->configuration;
    }

    public static function getConfiguration(): array {
        $options = get_option('uvb_connector_woocommerce_options');
        $options = is_array($options) ? $options : [];

        return [
            'threshold' => (float) ($options['reputation_threshold'] ?? 0.5),
            'sandbox' => isset($options['sandbox_mode']),
            'public_key' => $options['public_api_key'] ?? '',
            'private_key' => $options['private_api_key'] ?? '',
        ];
    }

    private function createClient(array $data): Client {
        $client = new Client($this->configuration['public_key'], $this->configuration['private_key']);
        $client->sandbox = $this->configuration['sandbox'];
        $client->email = $data['email'];
        $client->countryCode = $data['country_code'];
        $client->postalCode = $data['postal_code'];
        $client->phoneNumber = $data['phone_number'];
        $client->addressLine = $data['address_line'];

        return $client;
    }

    public function request(array $data) {
        $client = $this->createClient($data);
        $client->threshold = $this->configuration['threshold'];

        return $client->sendRequest();
    }

    public function check(array $data, string $operation = 'request'): ?array {
        try {
            $response = $this->request($data);
        } catch (\Throwable $exception) {
            UVBConnectorWooCommerce_Api_Logger::logFailure($operation, 'exception', $exception);
            return null;
        }

        $result = UVBConnectorWooCommerce_Check_Result::fromResponse($response, $data);
        if ($result === null) {
            UVBConnectorWooCommerce_Api_Logger::logFailure($operation, $response === null ? 'empty_response' : 'invalid_response');
            return null;
        }

        UVBConnectorWooCommerce_Api_Logger::logRecovery($operation);

        return $result;
    }

    public function sendSignal(array $data, $orderId, $outcome) {
        $client = $this->createClient($data);
        $client->orderId = $orderId;
        $client->outcome = $outcome;

        try {
            $response = $client->sendSignal();
        } catch (\Throwable $exception) {
            UVBConnectorWooCommerce_Api_Logger::logFailure('signal', 'exception', $exception);
            return null;
        }

        if (!is_object($response)) {
            UVBConnectorWooCommerce_Api_Logger::logFailure('signal', 'empty_response');
            return null;
        }

        UVBConnectorWooCommerce_Api_Logger::logRecovery('signal');

        return $response;
    }
}
