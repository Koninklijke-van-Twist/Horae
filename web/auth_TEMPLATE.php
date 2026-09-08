<?php
$auth_list = 
[
    "ENV_1" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
    "ENV_2" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
    "ENV_3" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD']
];
$environment = "ENV_2";
$auth = $auth_list[$environment];
$base = "https://DOMAIN.com:7148/$environment/ODataV4/Company('COMPANY')/";

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
