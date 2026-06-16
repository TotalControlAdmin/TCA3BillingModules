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
                'username' => $this->generateUsername(),
                'password' => $this->params['password'],
                'userStatus' => 'Active',
                'userType' => 'User'
            ];
            $newUser = $this->api->request('POST', 'User', ['json' => $userData], 'CreateUser');
            $userId = $newUser->userId;
            $username = $newUser->username;

            // POST /User assigns no role; grant the built-in User role (new users only).
            $this->api->request('PUT', "User/{$userId}/SetRoleType", [
                'query' => ['roleType' => 'User']
            ], 'SetUserRoleType');
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
    }

    /**
     * Suspend Account
     */
    public function suspendAccount(): string
    {
        $this->api->request('POST', "Billing/Suspend", [
            'query' => ['billingId' => $this->params['serviceid']]
        ], 'SuspendAccount');

        return 'success';
    }

    /**
     * Unsuspend Account
     */
    public function unsuspendAccount(): string
    {
        $this->api->request('POST', "Billing/Unsuspend", [
            'query' => ['billingId' => $this->params['serviceid']]
        ], 'UnsuspendAccount');

        return 'success';
    }

    /**
     * Terminate Account
     */
    public function terminateAccount(): string
    {
        // Blocks until the service is deleted (waitUntilComplete defaults to true).
        $this->api->request('POST', "Billing/Terminate", [
            'query' => ['billingId' => $this->params['serviceid']]
        ], 'TerminateAccount');

        // Best-effort: service is already gone, so cleanup must not fail termination.
        try {
            $this->deleteOwnerIfEmpty();
        } catch (Throwable) {
        }

        return 'success';
    }

    /**
     * Delete the owner's user when no game/docker services, servers or virtual servers remain.
     */
    protected function deleteOwnerIfEmpty(): void
    {
        $userResponse = $this->api->request('GET', 'User/Search', [
            'query' => ['Filter' => "billingId={$this->params['userid']}"]
        ], 'FindOwner');

        if (($userResponse->count ?? 0) !== 1) {
            return;
        }
        $ownerId = $userResponse->data[0]->userId;

        $remaining = 0;
        foreach (['GameService', 'DockerService', 'Server', 'VirtualServer'] as $type) {
            $resp = $this->api->request('GET', "$type/Search", [
                'query' => ['Filter' => "ownerId={$ownerId}"]
            ], "CountOwned-$type");
            $remaining += (int)($resp->count ?? 0);
        }

        if ($remaining === 0) {
            $this->api->request('DELETE', "User/{$ownerId}", [], 'DeleteEmptyUser');
        }
    }

    /**
     * Change Password
     */
    public function changePassword(): string
    {
        $serviceData = $this->getServiceByBillingId();
        $this->api->request('POST', "User/$serviceData->ownerId/ChangePassword", [
            'json' => [
                'newPassword' => $this->params['password'],
                'confirmNewPassword' => $this->params['password']
            ]
        ], 'ChangePassword');

        return 'success';
    }

    /**
     * Change Package (Upgrade/Downgrade)
     */
    public function changePackage(): string
    {
        $service = $this->getServiceByBillingId();

        // Get the current full model
        $currentValues = $this->api->request('GET', "GameService/$service->serviceId", [], 'GetGameServiceDetails');

        $newParams = $this->buildProvisioningParams();
        $apivalues = array_merge((array)$currentValues, $newParams);

        $this->api->request('PUT', "GameService/$service->serviceId/UpdateGameService", [
            'json' => $apivalues
        ], 'UpdateGameService');

        return 'success';
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
     * Mint a TCAdmin SSO token (docs/SSO.md) and return a WHMCS redirect.
     * Requires the Access Hash API key to hold the Sso.Create permission.
     */
    public function singleSignOn(): array
    {
        try {
            $payload = ['billingId' => (string)$this->params['userid']];

            // Best-effort deep-link to /Managers/{CategorySlug}/{ServiceId}; falls back to the dashboard.
            try {
                $service = $this->getServiceByBillingId();
                $category = $this->api->request('GET', "ServiceCategory/{$service->categoryId}", [], 'GetCategoryForSso');
                if (!empty($category->slug)) {
                    $payload['returnUrl'] = "/Managers/{$category->slug}/{$service->serviceId}";
                }
            } catch (Throwable) {
            }

            $response = $this->api->request('POST', 'Sso/Token', ['json' => $payload], 'SsoToken');

            $token = $response->token ?? null;
            if (!$token) {
                throw new Exception('TCAdmin did not return an SSO token.');
            }

            return [
                'success' => true,
                'redirectTo' => "https://{$this->params['serverhostname']}/Auth/Sso?token=" . urlencode($token),
            ];
        } catch (Exception|GuzzleException $e) {
            return ['success' => false, 'errorMsg' => $e->getMessage()];
        }
    }

    /**
     * Helper to find TCAdmin service by WHMCS billing ID
     */
    protected function getServiceByBillingId(): mixed
    {
        $response = $this->api->request('GET', 'Service/Search', [
            'query' => ['Filter' => "billingId={$this->params['serviceid']}"]
        ], 'FindService');

        if ($response->count !== 1) {
            throw new Exception('Service not found in TCAdmin');
        }

        return $response->data[0];
    }

    /**
     * Build a safe, ASCII-only TCAdmin username from the client's name.
     */
    protected function generateUsername(): string
    {
        $firstName = $this->params['clientsdetails']['firstname'] ?? '';
        $lastName = $this->params['clientsdetails']['lastname'] ?? '';

        $username = strtolower(preg_replace('/[^a-z0-9]/i', '', $firstName . mb_substr($lastName, 0, 1)));

        return $username !== '' ? $username : 'user' . ($this->params['userid'] ?? '');
    }

    /**
     * Build parameters for TCAdmin API
     */
    protected function buildProvisioningParams(): array
    {
        $mappings = [
            'gameId' => 'configoption3',
            'slots' => 'configoption5',
            'name' => 'configoption6',
            'rconpassword' => 'configoption8',
            'privatepassword' => 'configoption10',
        ];

        $apivalues = [];
        foreach ($mappings as $apiKey => $whmcsKey) {
            $apivalues[$apiKey] = $this->resolveConfigValue($this->params[$whmcsKey] ?? null);
        }

        // RConPassword is a service variable, not a DTO field; send it via Variables.
        $rconPassword = $apivalues['rconpassword'] ?? null;
        unset($apivalues['rconpassword']);
        if ($rconPassword !== null && $rconPassword !== '') {
            $apivalues['variables']['RconPassword'] = (string)$rconPassword;
        }

        $apivalues['gameId'] = (int)($apivalues['gameId'] ?: $this->params['configoption3']);

        // The API rejects "" for int fields; cast when present.
        if ($apivalues['slots'] !== null && $apivalues['slots'] !== '') {
            $apivalues['slots'] = (int)$apivalues['slots'];
        }

        $this->applyLocation($apivalues);

        // Drop blanks so optional fields fall back to TCAdmin defaults.
        $apivalues = array_filter($apivalues, fn($v) => $v !== null && $v !== '');

        $apivalues['billingId'] = (string)$this->params['serviceid'];
        $apivalues['startAfterCreation'] = true;

        $this->applyConfigFile($apivalues);

        return $apivalues;
    }

    /**
     * Set the single location field the API requires: regionId/datacenterId (auto-select a
     * server) or serverId/virtualServerId (pin one). The target type comes from Location Type
     * (configoption1); a bare ID with no type maps to datacenterId.
     */
    protected function applyLocation(array &$apivalues): void
    {
        $id = $this->resolveConfigValue($this->params['configoption2'] ?? null);
        if (!is_numeric($id) || (int)$id <= 0) {
            return;
        }

        $field = match (strtolower(trim((string)($this->params['configoption1'] ?? '')))) {
            'server' => 'serverId',
            'region' => 'regionId',
            'virtual server', 'virtualserver' => 'virtualServerId',
            default => 'datacenterId',
        };

        $apivalues[$field] = (int)$id;
    }

    /**
     * Resolve a config value, expanding CustomField:/ConfigOption: references to the order's value.
     */
    protected function resolveConfigValue(mixed $value): mixed
    {
        if (is_string($value)) {
            if (str_starts_with($value, 'CustomField:')) {
                return $this->resolveMappedValue('customfields', substr($value, strlen('CustomField:')));
            }
            if (str_starts_with($value, 'ConfigOption:')) {
                return $this->resolveMappedValue('configoptions', substr($value, strlen('ConfigOption:')));
            }
        }
        return $value;
    }

    /**
     * Merge advanced API values from the product's optional "Config File" (configoption4): a PHP
     * script in configs/ that reads $params and adjusts $apivalues — e.g. custom service variables,
     * affinity, or memory/disk limits not exposed as fields. Mirrors the v2 config-file mechanism.
     */
    protected function applyConfigFile(array &$apivalues): void
    {
        $name = trim((string)($this->params['configoption4'] ?? ''));
        $explicit = $name !== '';
        if (!$explicit) {
            $name = 'default.php';
        }

        $dir = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'configs');
        $path = $dir !== false ? realpath($dir . DIRECTORY_SEPARATOR . $name) : false;

        // Basename only, .php only, resolved path must stay inside configs/ (no traversal).
        $valid = $dir !== false
            && $path !== false
            && basename($name) === $name
            && str_ends_with(strtolower($name), '.php')
            && str_starts_with($path, $dir . DIRECTORY_SEPARATOR);

        if (!$valid) {
            if ($explicit) {
                throw new Exception("Config file '{$name}' was not found in the module's configs directory.");
            }
            return;
        }

        // Isolated scope: the file sees only $apivalues (by ref) and $params, never $this.
        $includer = static function (string $__file, array &$apivalues, array $params): void {
            include $__file;
        };
        $includer($path, $apivalues, $this->params);

        foreach ($apivalues as $key => $value) {
            $apivalues[$key] = $this->resolveConfigValueDeep($value);
        }
    }

    /**
     * Resolve CustomField:/ConfigOption: references in a scalar or, recursively, an array.
     */
    protected function resolveConfigValueDeep(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->resolveConfigValueDeep($v);
            }
            return $value;
        }
        return $this->resolveConfigValue($value);
    }

    /**
     * Resolve a CustomField:/ConfigOption: reference to its value. Names may carry a
     * "|Display Label" suffix while $params is keyed by the bare name, so strip it.
     */
    protected function resolveMappedValue(string $paramKey, string $name)
    {
        $source = $this->params[$paramKey] ?? [];
        $bareName = trim(explode('|', $name)[0]);

        if (array_key_exists($bareName, $source)) {
            return $source[$bareName];
        }
        if (array_key_exists($name, $source)) {
            return $source[$name];
        }
        return null;
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
