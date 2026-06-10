<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';
require __DIR__ . '/pdf_common.php';

try {
    $reportsByProject = pdf_load_reports($base, $auth, $_GET);
} catch (Throwable $e) {
    die($e->getMessage());
}

foreach ($reportsByProject as $report) {
    echo pdf_render_report_html($report, false);
}
