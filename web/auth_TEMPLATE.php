<?php
/**
 * Auth template for Horae.
 *
 * Prefer Mímir, and keep the BC block as automatic fallback when Mímir is down:
 *   $mimirApi  = 'mimir_…';  // required to activate Mímir
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optional
 *
 * With $mimirApi set, fetches try Mímir first and fall back to the BC vars below
 * ($auth_list / $environment / $auth / $base). Those credentials must stay in
 * this file next to $mimirApi. If they are absent, the original Mímir error is raised.
 * Keep $base with Company('…') so entity URLs stay parseable (or set $mimirCompany
 * and omit $base — odata_mimir_ensure_globals builds a synthetic mimir.invalid URL;
 * fallback still needs a real $base or $baseUrl).
 * Set $baseUrl to the BC root that must survive URL rebuild: on-prem
 * https://host:7148/ or SaaS https://api.businesscentral.dynamics.com/v2.0/{tenant}/.
 * Do not leave $baseUrl empty if $base is only a company URL; the fallback keeps
 * /v2.0/{tenant}/ from $baseUrl instead of stripping to the host.
 *
 * Without $mimirApi, only the BC block is used.
 *
 * Tim must set $mimirApi (and optional $mimirBase) in auth.php locally / on server,
 * and leave the BC credentials in place for the direct fallback.
 * Never commit web/auth.php.
 */

// --- Mímir (recommended) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';
// $mimirCompany = 'Koninklijke van Twist'; // optional if $base is omitted

// --- Business Central (direct path, and fallback when Mímir fails) ---
// On-prem host root, or SaaS root through the tenant (keep /v2.0/{tenant}/).
$baseUrl = "https://DOMAIN.com:7148/";
// $baseUrl = "https://api.businesscentral.dynamics.com/v2.0/TENANT_ID/";
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
