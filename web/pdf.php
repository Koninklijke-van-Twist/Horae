<?php
require_once __DIR__ . '/bsn.php';
bsn_private_headers();
require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';
bsn_private_headers();
require __DIR__ . '/pdf_common.php';

try {
    $reportsByProject = pdf_load_reports($base, $auth, $_GET);
    foreach ($reportsByProject as &$bsnReport) {
        bsn_enrich_report($bsnReport);
    }
    unset($bsnReport);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit($e->getMessage());
}

foreach ($reportsByProject as $report) {
    echo pdf_render_report_html($report, false);
}
