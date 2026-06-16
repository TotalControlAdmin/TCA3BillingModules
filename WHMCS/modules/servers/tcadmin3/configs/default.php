<?php

/**
 * TCAdmin3 — advanced configuration file (optional).
 *
 * Point a product's "Config File" module setting at a copy of this file to add API values that
 * aren't exposed as module fields. The module loads it from this configs/ folder while building
 * the GameService/CreateGameService (and Change Package) request body.
 *
 * In scope:
 *   $params    — the WHMCS module params (clientsdetails, customfields, configoptions, configoptionN, ...)
 *   $apivalues — the request body, passed BY REFERENCE. Add or override keys here. It already holds
 *                the values mapped from the product fields (gameId, slots, name, the location field,
 *                variables.RconPassword, billingId, startAfterCreation, ...).
 *
 * Values may be literals or "CustomField:<name>" / "ConfigOption:<name>" references; the references
 * are resolved against the order after this file runs (including inside variables).
 *
 * Everything below is commented out — by default this file changes nothing. Copy it (e.g.
 * configs/reseller.php) before editing so upgrades don't overwrite your changes.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// --- Extra CreateGameServiceInfoDto fields -------------------------------------------------
// $apivalues['affinity']    = 0;           // CPU core affinity mask
// $apivalues['startup']     = 'Automatic'; // Automatic | Manual | Disabled
// $apivalues['priority']    = 'Normal';    // process priority class
// $apivalues['cpuLimit']    = 50;          // percent
// $apivalues['memoryLimit'] = 2147483648;  // bytes (2 GB)
// $apivalues['diskSpace']   = 10737418240; // bytes (10 GB)

// --- Custom service variables (substituted as ${VarName} in the game config) ---------------
// $apivalues['variables']['MaxPlayers'] = $apivalues['slots'] ?? 16;
// $apivalues['variables']['Map']        = 'CustomField:Starting Map';
// $apivalues['variables']['Region']     = 'ConfigOption:Region';

// --- Conditional example -------------------------------------------------------------------
// if ((int)($apivalues['gameId'] ?? 0) === 730) {            // e.g. a specific blueprint
//     $apivalues['variables']['GameType'] = '0';
// }
