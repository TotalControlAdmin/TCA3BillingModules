<?php

namespace WHMCS\Module\Server\TCAdmin3;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Utils;
use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Service\Service;

class Handler
{
    protected array $params;
    protected ApiClient $api;

    public function __construct(array $params)
    {
        $this->params = $params;
        $this->api = new ApiClient($params);
    }

    /**
     * Test connection to TCAdmin
     */
    public function testConnection(): array
    {
        try {
            $this->api->request('GET', 'Game/Search', [], 'TestConnection');
            return ['success' => true];
        } catch (Exception|GuzzleException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create Account
     */
    public function createAccount(): string
    {
        try {
            // 1. Check if the service already exists
            $response = $this->api->request('GET', 'Service/Search', [
                'query' => ['Filter' => "billingId={$this->params['serviceid']}"]
            ], 'CheckExistingService');

            if ($response->count > 0) {
                throw new Exception("Service with billing id {$this->params['serviceid']} already exists.");
            }

            // 2. Find or Create User
            $userResponse = $this->api->request('GET', 'User/Search', [
                'query' => ['Filter' => "billingId={$this->params['userid']}"]
            ], 'SearchUser');

            if ($userResponse->count > 1) {
                throw new Exception("Multiple users found with {$this->params['userid']} as billingId");
            }

            if ($userResponse->count === 0) {
                // Create user
                $userData = [
                    'billingId' => (string)$this->params['userid'],
                    'email' => $this->params['clientsdetails']['email'],
                    'firstName' => $this->params['clientsdetails']['firstname'],
                    'lastName' => $this->params['clientsdetails']['lastname'],
                    'username' => strtolower(str_replace(' ', '', $this->params['clientsdetails']['firstname'] . $this->params['clientsdetails']['lastname'][0])),
                    'password' => $this->params['password'],
                    'userStatus' => 'Active',
                    'userType' => 'User'
                ];
                $newUser = $this->api->request('POST', 'User', ['json' => $userData], 'CreateUser');
                $userId = $newUser->userId;
                $username = $newUser->username;
            } else {
                $userId = $userResponse->data[0]->userId;
                $username = $userResponse->data[0]->username;
            }

            // 3. Create Game Service
            $apivalues = $this->buildProvisioningParams();
            $apivalues['ownerId'] = $userId;

            $this->api->request('POST', 'GameService/CreateGameService', [
                'json' => $apivalues
            ], 'CreateGameService');

            // 4. Update WHMCS Service
            if ($username) {
                $service = Service::find($this->params['serviceid']);
                if ($service) {
                    $service->username = $username;
                    $service->save();
                }
            }

            return 'success';
        } catch (Exception|GuzzleException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Suspend Account
     */
    public function suspendAccount(): string
    {
        try {
            $this->api->request('POST', "Billing/Suspend", [
                'query' => ['billingId' => $this->params['serviceid']]
            ], 'SuspendAccount');
            return 'success';
        } catch (Exception|GuzzleException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Unsuspend Account
     */
    public function unsuspendAccount(): string
    {
        try {
            $this->api->request('POST', "Billing/Unsuspend", [
                'query' => ['billingId' => $this->params['serviceid']]
            ], 'UnsuspendAccount');
            return 'success';
        } catch (Exception|GuzzleException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Terminate Account
     */
    public function terminateAccount(): string
    {
        try {
            $this->api->request('POST', "Billing/Terminate", [
                'query' => ['billingId' => $this->params['serviceid']]
            ], 'TerminateAccount');
            return 'success';
        } catch (Exception|GuzzleException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Change Password
     */
    public function changePassword(): string
    {
        try {
            $serviceData = $this->getServiceByBillingId();
            $this->api->request('POST', "User/$serviceData->ownerId/ChangePassword", [
                'json' => [
                    'newPassword' => $this->params['password'],
                    'confirmNewPassword' => $this->params['password']
                ]
            ], 'ChangePassword');
            return 'success';
        } catch (Exception|GuzzleException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Change Package (Upgrade/Downgrade)
     */
    public function changePackage(): string
    {
        try {
            $service = $this->getServiceByBillingId();
            
            // Get the current full model
            $currentValues = $this->api->request('GET', "GameService/$service->serviceId", [], 'GetGameServiceDetails');
            
            $newParams = $this->buildProvisioningParams();
            $apivalues = array_merge((array)$currentValues, $newParams);

            $this->api->request('PUT', "GameService/$service->serviceId/UpdateGameService", [
                'json' => $apivalues
            ], 'UpdateGameService');

            return 'success';
        } catch (Exception|GuzzleException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Get Service Status for Client Area
     */
    public function getServiceStatus(): array
    {
        try {
            $service = $this->getServiceByBillingId();
            return [
                'success' => true,
                'status' => $service->status ?? 'Unknown',
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Perform Service Action (Start, Stop, Restart, Kill)
     */
    public function performAction(string $action): array
    {
        $allowedActions = ['Start', 'Stop', 'Restart', 'Kill'];
        if (!in_array($action, $allowedActions)) {
            return ['success' => false, 'error' => 'Invalid action'];
        }

        try {
            $service = $this->getServiceByBillingId();
            $this->api->request('POST', "Service/$service->serviceId/$action", [], "Service$action");
            return ['success' => true];
        } catch (Exception|GuzzleException) {
            return ['success' => false, 'error' => 'An unexpected error occurred'];
        }
    }

    /**
     * Helper to find TCAdmin service by WHMCS billing ID
     */
    protected function getServiceByBillingId(): mixed
    {
        try {
            $response = $this->api->request('GET', 'Service/Search', [
                'query' => ['Filter' => "billingId={$this->params['serviceid']}"]
            ], 'FindService');
        } catch (GuzzleException|Exception $e) {
            throw new Exception($e->getMessage());
        }

        if ($response->count !== 1) {
            throw new Exception('Service not found in TCAdmin');
        }

        return $response->data[0];
    }

    /**
     * Build parameters for TCAdmin API
     */
    protected function buildProvisioningParams(): array
    {
        $mappings = [
            'gameId' => 'configoption2',
            'slots' => 'configoption3',
            'serverId' => 'configoption6',
            'name' => 'configoption7',
            'privatepassword' => 'configoption8',
            'rconpassword' => 'configoption9',
        ];

        $apivalues = [];
        foreach ($mappings as $apiKey => $whmcsKey) {
            $value = $this->params[$whmcsKey] ?? null;
            
            if (is_string($value)) {
                if (str_starts_with($value, 'CustomField:')) {
                    $fieldName = explode(':', $value)[1];
                    $apivalues[$apiKey] = $this->params['customfields'][$fieldName] ?? null;
                    continue;
                }
                if (str_starts_with($value, 'ConfigOption:')) {
                    $optionName = explode(':', $value)[1];
                    $apivalues[$apiKey] = $this->params['configoptions'][$optionName] ?? null;
                    continue;
                }
            }
            $apivalues[$apiKey] = $value;
        }

        // Conversions and forced values
        $apivalues['gameId'] = (int)($apivalues['gameId'] ?: $this->params['configoption2']);
        $apivalues['billingId'] = (string)$this->params['serviceid'];
        $apivalues['startAfterCreation'] = true;

        return $apivalues;
    }

    /**
     * Load games for config options dropdown
     */
    public function loadGames(): array
    {
        $client = $this->api->getClient();
        $list = ['' => 'Custom'];

        try {
            $promises = [
                'servicecategories' => $client->getAsync("ServiceCategory/Search"),
                'games' => $client->getAsync("Game/Search"),
                'dockerblueprint' => $client->getAsync("DockerBlueprint/Search"),
            ];

            $responses = Utils::unwrap($promises);
            $servicecategories = json_decode($responses['servicecategories']->getBody());
            $games = json_decode($responses['games']->getBody());
            $dockerblueprints = json_decode($responses['dockerblueprint']->getBody());

            $categoryMap = [];
            foreach ($servicecategories->data as $category) {
                $categoryMap[$category->categoryId] = str_replace(' Services', '', $category->name);
            }

            foreach ($games->data as $game) {
                $categoryName = $categoryMap[$game->categoryId] ?? 'Unknown';
                $list[$game->blueprintId] = "[$categoryName] $game->name ($game->operatingSystem)";
            }

            foreach ($dockerblueprints->data as $blueprint) {
                $list[$blueprint->blueprintId] = "[Docker] $blueprint->name";
            }

            asort($list);
        } catch (Throwable $e) {
            throw new Exception($e->getMessage());
        }

        return $list;
    }

    /**
     * Load custom fields and config options for mapping
     */
    public static function loadCustomFieldsAndConfigOptions($productId): array
    {
        $customFields = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', $productId)
            ->pluck('fieldname')
            ->map(fn($name) => 'CustomField:' . $name);

        $configOptions = Capsule::table('tblproductconfigoptions as A')
            ->join('tblproductconfiglinks as B', 'A.gid', '=', 'B.gid')
            ->where('B.pid', $productId)
            ->pluck('A.optionname')
            ->map(fn($name) => 'ConfigOption:' . $name);

        $results = $customFields->merge($configOptions)->toArray();
        
        $list = ['' => ''];
        foreach ($results as $result) {
            $list[$result] = $result;
        }
        return $list;
    }
}
