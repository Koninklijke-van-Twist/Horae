<?php

require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';
require __DIR__ . '/pdf_common.php';

require __DIR__ . '/vendor/autoload.php';

use Horae\Pdf\ChromePdfRenderer;
use Horae\Pdf\SignatureFieldsAppender;

try {
    $reportsByProject = pdf_load_reports($base, $auth, $_GET);
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}

$exportKey = trim((string) ($_GET['exportKey'] ?? ''));
if ($exportKey === '' || !isset($reportsByProject[$exportKey])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Exportrapport niet gevonden';
    exit;
}

$report = $reportsByProject[$exportKey];
$html = pdf_render_report_html($report, true);

$flatPdf = null;
$signedPdf = null;

try {
    $flatPdf = ChromePdfRenderer::renderHtmlToPdf($html, __DIR__, pdf_resolve_export_base_url());
    $signedPdf = tempnam(sys_get_temp_dir(), 'horae_signed_');
    if ($signedPdf === false) {
        throw new RuntimeException('Tijdelijk PDF-bestand aanmaken mislukt');
    }
    $signedPdf .= '.pdf';

    SignatureFieldsAppender::append($flatPdf, $signedPdf);

    $filename = pdf_build_export_filename($report);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($signedPdf));
    readfile($signedPdf);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PDF export mislukt: ' . $e->getMessage();
} finally {
    if (is_string($flatPdf) && is_file($flatPdf)) {
        @unlink($flatPdf);
    }
    if (is_string($signedPdf) && is_file($signedPdf)) {
        @unlink($signedPdf);
    }
}
