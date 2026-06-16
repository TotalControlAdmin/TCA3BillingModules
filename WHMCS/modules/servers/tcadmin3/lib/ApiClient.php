<?php

namespace WHMCS\Module\Server\TCAdmin3;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

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
        $action = $action ?: $endpoint;
        $responseLog = 'No response received.';

        try {
            $response = $this->client->request($method, $endpoint, $options);
            $body = (string)$response->getBody();
            $responseLog = $body;
            return json_decode($body);
        } catch (RequestException $e) {
            // Log TCAdmin's actual response (status + body) — the useful diagnostic. Read the
            // body once here so formatError() and the log share it (the stream isn't re-readable).
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            $responseLog = $status ? "HTTP {$status}: {$body}" : $e->getMessage();
            // Re-throw a clean, single-line message for the WHMCS UI.
            throw new Exception($this->formatError($status, $body, $e->getMessage()), $e->getCode(), $e);
        } catch (Exception $e) {
            $responseLog = $e->getMessage();
            throw $e;
        } finally {
            // Mask the API key and service password if they appear in the request/response.
            $hidden = array_filter(
                [$this->params['password'] ?? '', $this->params['serveraccesshash'] ?? ''],
                fn($v) => $v !== ''
            );
            logModuleCall(
                'tcadmin3',
                $action,
                array_merge(['endpoint' => $endpoint, 'method' => $method], $options),
                self::scrubUtf8($responseLog),
                '',
                $hidden
            );
        }
    }

    /**
     * Build a one-line message from an API error response (validation errors,
     * problem-details, or a bare string body).
     */
    private function formatError(int $status, string $body, string $fallback): string
    {
        if ($status === 0) {
            return $fallback;
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded) && !empty($decoded['errors']) && is_array($decoded['errors'])) {
            $parts = [];
            foreach ($decoded['errors'] as $field => $messages) {
                $parts[] = $field . ': ' . implode(' ', (array)$messages);
            }
            return "TCAdmin API error ($status): " . implode(' | ', $parts);
        }

        if (is_array($decoded)) {
            foreach (['detail', 'title', 'message', 'error'] as $key) {
                if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                    return "TCAdmin API error ($status): " . $decoded[$key];
                }
            }
        }

        if (is_string($decoded) && $decoded !== '') {
            return "TCAdmin API error ($status): " . $decoded;
        }

        return "TCAdmin API error ($status): " . ($body !== '' ? $body : $fallback);
    }

    /**
     * Strip invalid UTF-8 so logged data can't break WHMCS's JSON admin responses.
     */
    private static function scrubUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return function_exists('mb_scrub')
            ? mb_scrub($value, 'UTF-8')
            : (string)@iconv('UTF-8', 'UTF-8//IGNORE', $value);
    }

    /**
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }
}
