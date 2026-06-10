<?php

require __DIR__ . '/pdf_common.php';

$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(404);
    exit('Export niet gevonden');
}

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'horae_export_' . $token . '.html';
if (!is_file($path)) {
    http_response_code(404);
    exit('Export niet gevonden');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
readfile($path);

@unlink($path);
