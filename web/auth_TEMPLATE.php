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