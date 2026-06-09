<?php

namespace WHMCS\Module\Server\TCAdmin3;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ApiClient
{
    protected Client $client;
    protected array $params;

    public function __construct(array $params)
    {
        $this->params = $params;
        $this->client = new Client([
            'base_uri' => "https://{$params['serverhostname']}/api/",
            'verify' => false,
            'headers' => [
                'Authorization' => "ApiKey {$params['serveraccesshash']}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]
        ]);
    }

    /**
     * Centralized request handler with integrated logging
     *
     * @param string $method
     * @param string $endpoint
     * @param array $options
     * @param string $action
     * @return mixed
     * @throws Exception|GuzzleException
     */
    public function request(string $method, string $endpoint, array $options = [], string $action = ''): mixed
    {
        $response = null;
        $exception = null;
        $action = $action ?: $endpoint;

        try {
            $response = $this->client->request($method, $endpoint, $options);
            $body = $response->getBody()->getContents();
            return json_decode($body);
        } catch (Exception $e) {
            $exception = $e;
            throw $e;
        } finally {
            logModuleCall(
                'tcadmin3',
                $action,
                array_merge(['endpoint' => $endpoint, 'method' => $method], $options),
                $response ? (string)$response->getBody() : ($exception ? $exception->getMessage() : 'Unknown Error'),
                $response ? null : ($exception?->getTraceAsString())
            );
        }
    }

    /**
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }
}
