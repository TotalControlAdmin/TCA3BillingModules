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
        'AdminSingleSignOnLabel' => 'Login to TCAdmin as Admin',
    ];
}

/**
 * Config Options
 */
function tcadmin3_ConfigOptions($params): array
{
    return [
        'ConfigFile' => [
            'FriendlyName' => 'Config File',
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Path to advanced configuration options',
            'Default' => 'default.php',
        ],
        'GameID' => [
            'FriendlyName' => 'Game ID',
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Game ID to provision',
            'Loader' => 'tcadmin3_LoadGames',
            'SimpleMode' => true
        ],
        'Slots' => [
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Number of player slots for the server',
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
        'Branded' => [
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Whether the server should be branded or not',
            'Loader' => 'tcadmin3_LoadCustomFieldsAndConfigOptions',
            'SimpleMode' => true
        ],
        'Datacenter' => [
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'The datacenter to provision the service in',
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
        'PrivatePassword' => [
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'The private password for the service',
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
 * Create Account
 */
function tcadmin3_CreateAccount(array $params): string
{
    return (new Handler($params))->createAccount();
}

/**
 * Suspend Account
 */
function tcadmin3_SuspendAccount(array $params): string
{
    return (new Handler($params))->suspendAccount();
}

/**
 * Unsuspend Account
 */
function tcadmin3_UnsuspendAccount(array $params): string
{
    return (new Handler($params))->unsuspendAccount();
}

/**
 * Terminate Account
 */
function tcadmin3_TerminateAccount(array $params): string
{
    return (new Handler($params))->terminateAccount();
}

/**
 * Change Password
 */
function tcadmin3_ChangePassword(array $params): string
{
    return (new Handler($params))->changePassword();
}

/**
 * Change Package
 */
function tcadmin3_ChangePackage(array $params): string
{
    return (new Handler($params))->changePackage();
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
