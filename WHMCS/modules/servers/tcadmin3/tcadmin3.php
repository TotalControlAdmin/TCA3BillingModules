<?php

use WHMCS\Module\Server\TCAdmin3\Handler;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Meta Data
 */
function tcadmin3_MetaData(): array
{
    return [
        'DisplayName' => 'TCAdmin3',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '31000',
        'DefaultSSLPort' => '31001',
        'ServiceSingleSignOnLabel' => 'Login to TCAdmin as User',
    ];
}

/**
 * Config Options
 */
function tcadmin3_ConfigOptions($params): array
{
    return [
        'LocationType' => [
            'FriendlyName' => 'Location Type',
            'Type' => 'dropdown',
            'Options' => 'Region,Datacenter,Server,Virtual Server',
            'Default' => 'Datacenter',
            'Description' => 'How Location ID is interpreted. Region and Datacenter let TCAdmin auto-select a server in that location; Server and Virtual Server pin the service to an exact target. The module sends only the one target you select.',
            'SimpleMode' => true
        ],
        'LocationID' => [
            'FriendlyName' => 'Location ID',
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Numeric TCAdmin ID of the location target selected in Location Type (the region, datacenter, server, or virtual server ID). May also map to a Custom Field / Configurable Option.',
            'Loader' => 'tcadmin3_LoadCustomFieldsAndConfigOptions',
            'SimpleMode' => true
        ],
        'GameID' => [
            'FriendlyName' => 'Game ID',
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Game ID to provision',
            'Loader' => 'tcadmin3_LoadGames',
            'SimpleMode' => true
        ],
        'ConfigFile' => [
            'FriendlyName' => 'Config File',
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Optional PHP file in the module\'s configs/ folder that supplies advanced API values not exposed as fields (service variables, affinity, memory/disk limits, etc.). Copy configs/default.php, edit your copy, and put its filename here. Blank uses default.php.',
            'Default' => 'default.php',
            'SimpleMode' => true,
        ],
        'Slots' => [
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Number of player slots for the server',
            'Loader' => 'tcadmin3_LoadCustomFieldsAndConfigOptions',
            'SimpleMode' => true
        ],
        'Hostname' => [
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'The service hostname',
            'Loader' => 'tcadmin3_LoadCustomFieldsAndConfigOptions',
            'SimpleMode' => true
        ],
        'Branded' => [
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Whether the server should be branded or not',
            'Loader' => 'tcadmin3_LoadCustomFieldsAndConfigOptions',
            'SimpleMode' => true
        ],
        'RConPassword' => [
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'The RCon password for the service',
            'Loader' => 'tcadmin3_LoadCustomFieldsAndConfigOptions',
            'SimpleMode' => true
        ],
        'Private' => [
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Whether the server should be private or not',
            'Loader' => 'tcadmin3_LoadCustomFieldsAndConfigOptions',
            'SimpleMode' => true
        ],
        'PrivatePassword' => [
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'The private password for the service',
            'Loader' => 'tcadmin3_LoadCustomFieldsAndConfigOptions',
            'SimpleMode' => true
        ],
    ];
}

/**
 * Test Connection
 */
function tcadmin3_TestConnection(array $params): array
{
    return (new Handler($params))->testConnection();
}

/**
 * Run a provisioning op: return 'success', or log the failure and return its message.
 */
function tcadmin3_runOperation(array $params, string $function, callable $operation): string
{
    try {
        return $operation(new Handler($params));
    } catch (Throwable $e) {
        // Mask the API key and service password where they appear in the logged params.
        $hidden = array_filter(
            [$params['password'] ?? '', $params['serveraccesshash'] ?? ''],
            fn($v) => $v !== ''
        );
        logModuleCall('tcadmin3', $function, $params, $e->getMessage(), '', $hidden);
        return $e->getMessage();
    }
}

/**
 * Create Account
 */
function tcadmin3_CreateAccount(array $params): string
{
    return tcadmin3_runOperation($params, __FUNCTION__, fn(Handler $h) => $h->createAccount());
}

/**
 * Suspend Account
 */
function tcadmin3_SuspendAccount(array $params): string
{
    return tcadmin3_runOperation($params, __FUNCTION__, fn(Handler $h) => $h->suspendAccount());
}

/**
 * Unsuspend Account
 */
function tcadmin3_UnsuspendAccount(array $params): string
{
    return tcadmin3_runOperation($params, __FUNCTION__, fn(Handler $h) => $h->unsuspendAccount());
}

/**
 * Terminate Account
 */
function tcadmin3_TerminateAccount(array $params): string
{
    return tcadmin3_runOperation($params, __FUNCTION__, fn(Handler $h) => $h->terminateAccount());
}

/**
 * Change Password
 */
function tcadmin3_ChangePassword(array $params): string
{
    return tcadmin3_runOperation($params, __FUNCTION__, fn(Handler $h) => $h->changePassword());
}

/**
 * Change Package
 */
function tcadmin3_ChangePackage(array $params): string
{
    return tcadmin3_runOperation($params, __FUNCTION__, fn(Handler $h) => $h->changePackage());
}

/**
 * Service Single Sign-On — the admin "Login to TCAdmin as User" button.
 */
function tcadmin3_ServiceSingleSignOn(array $params): array
{
    return (new Handler($params))->singleSignOn();
}

/**
 * Client Area
 */
function tcadmin3_ClientArea(array $params): array
{
    $handler = new Handler($params);

    if (isset($_POST['ajaxAction'])) {
        header('Content-Type: application/json');

        if ($_POST['ajaxAction'] == 'getStatus') {
            echo json_encode($handler->getServiceStatus());
        } elseif ($_POST['ajaxAction'] == 'performAction') {
            echo json_encode($handler->performAction($_POST['serviceAction']));
        } elseif ($_POST['ajaxAction'] == 'singleSignOn') {
            echo json_encode($handler->singleSignOn());
        }
        exit;
    }

    return array(
        'templatefile' => 'clientarea',
        'vars' => array(
            'serviceid' => $params['serviceid'],
        ),
    );
}

/**
 * Load Games Dropdown
 */
function tcadmin3_LoadGames($params)
{
    try {
        return (new Handler($params))->loadGames();
    } catch (Exception $e) {
        return ['' => 'Error: ' . $e->getMessage()];
    }
}

/**
 * Load Fields for Mapping
 */
function tcadmin3_LoadCustomFieldsAndConfigOptions($params)
{
    $productId = (int)($_REQUEST['id'] ?? 0);
    return Handler::loadCustomFieldsAndConfigOptions($productId);
}
