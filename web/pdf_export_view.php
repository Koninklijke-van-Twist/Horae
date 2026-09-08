<?php

require __DIR__ . '/pdf_common.php';
bsn_private_headers();

$token = (string) ($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/D', $token)) {
    http_response_code(404);
    exit('Export niet gevonden');
}

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'horae_export_' . $token . '.html';
if (!is_file($path) || filemtime($path) < time() - 120) {
    http_response_code(404);
    exit('Export niet gevonden');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
readfile($path);

@unlink($path);
