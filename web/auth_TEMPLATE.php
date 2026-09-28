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
 * fallback still needs a real $base).
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
