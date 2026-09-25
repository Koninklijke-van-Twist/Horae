<?php
/**
 * Auth template for Horae.
 *
 * Prefer Mímir (no BC credentials needed for OData fetches):
 *   $mimirApi  = 'mimir_…';  // required to activate Mímir
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optional
 *
 * With $mimirApi set, $auth_list / $auth are unused for Business Central fetches.
 * Keep $base with Company('…') so entity URLs stay parseable (or set $mimirCompany
 * and omit $base — odata_mimir_ensure_globals builds a synthetic mimir.invalid URL).
 *
 * Without $mimirApi, keep the BC block for the legacy OData path.
 *
 * Tim must set $mimirApi (and optional $mimirBase) in auth.php locally / on server.
 * Never commit web/auth.php.
 */

// --- Mímir (recommended) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';
// $mimirCompany = 'Koninklijke van Twist'; // optional if $base is omitted

// --- Legacy Business Central (only when $mimirApi is not set) ---
$auth_list =
[
    "ENV_1" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
    "ENV_2" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
    "ENV_3" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD']
];
$environment = "ENV_2";
$auth = $auth_list[$environment];
$base = "https://DOMAIN.com:7148/$environment/ODataV4/Company('COMPANY')/";

$allowedUsers = [
    // "user@domain.nl",
];

// SharePoint / Microsoft Graph: vul deze waarden alleen in auth.php in.
$bsnGraph = [
    'TENANT_ID' => '',
    'CLIENT_ID' => '',
    'CLIENT_SECRET' => '',
    'SITE_ID' => '',
    'LIST_ID' => '',
    'EMPLOYEE_FIELD' => '', // Interne kolomnaam; geindexeerde tekstkolom met Resource.No.
    'BSN_FIELD' => '', // Interne kolomnaam; tekstkolom (behoud voorloopnullen).
];
