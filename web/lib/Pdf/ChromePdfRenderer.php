<?php

namespace Horae\Pdf;

use RuntimeException;

class ChromePdfRenderer
{
    public static function findChromium(): ?string
    {
        $envPath = getenv('HORAE_CHROMIUM_PATH');
        if (is_string($envPath) && $envPath !== '' && self::isUsableChromium($envPath)) {
            return $envPath;
        }

        $candidates = [
            '/opt/google/chrome/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            'C:\\Program Files\\BraveSoftware\\Brave-Browser\\Application\\brave.exe',
            'C:\\Program Files (x86)\\BraveSoftware\\Brave-Browser\\Application\\brave.exe',
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        ];

        foreach ($candidates as $path) {
            if (self::isUsableChromium($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function renderHtmlToPdf(string $html, string $webRoot, ?string $baseUrl = null): string
    {
        if (!function_exists('pdf_store_export_html')) {
            throw new RuntimeException('pdf_store_export_html() ontbreekt');
        }

        $tmpDir = sys_get_temp_dir();
        $pdfFile = tempnam($tmpDir, 'horae_pdf_');
        if ($pdfFile === false) {
            throw new RuntimeException('Tijdelijk PDF-bestand aanmaken mislukt');
        }
        $pdfFile .= '.pdf';

        $chrome = self::findChromium();
        if ($chrome === null) {
            @unlink($pdfFile);
            throw new RuntimeException(
                'Chromium/Chrome niet gevonden of niet bruikbaar vanuit Apache. '
                . 'Installeer Google Chrome of zet HORAE_CHROMIUM_PATH naar een werkend headless binary.'
            );
        }

        $cleanupHtml = null;
        $fileFlags = '';

        if (is_string($baseUrl) && $baseUrl !== '') {
            $token = pdf_store_export_html($html, $baseUrl);
            $uri = rtrim($baseUrl, '/') . '/pdf_export_view.php?token=' . rawurlencode($token);
            $cleanupHtml = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'horae_export_' . $token . '.html';
        } else {
            if (!function_exists('pdf_prepare_export_html')) {
                throw new RuntimeException('pdf_prepare_export_html() ontbreekt');
            }
            $html = pdf_prepare_export_html($html, $webRoot);
            $htmlFile = tempnam($tmpDir, 'horae_html_');
            if ($htmlFile === false) {
                @unlink($pdfFile);
                throw new RuntimeException('Tijdelijk HTML-bestand aanmaken mislukt');
            }
            $htmlFile .= '.html';
            if (file_put_contents($htmlFile, $html) === false) {
                @unlink($htmlFile);
                @unlink($pdfFile);
                throw new RuntimeException('HTML schrijven mislukt');
            }
            $htmlPath = str_replace('\\', '/', (string) realpath($htmlFile));
            $uri = 'file:///' . $htmlPath;
            $cleanupHtml = $htmlFile;
            $fileFlags = ' --allow-file-access-from-files';
        }

        $cmd = sprintf(
            '%s --headless --disable-gpu --no-sandbox --disable-dev-shm-usage%s --run-all-compositor-stages-before-draw --virtual-time-budget=15000 --print-to-pdf-no-header --no-pdf-header-footer --print-to-pdf=%s %s 2>&1',
            escapeshellarg($chrome),
            $fileFlags,
            escapeshellarg($pdfFile),
            escapeshellarg($uri)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if (is_string($cleanupHtml) && is_file($cleanupHtml)) {
            @unlink($cleanupHtml);
        }

        if ($exitCode !== 0 || !is_file($pdfFile) || filesize($pdfFile) < 128) {
            $message = trim(implode("\n", $output));
            @unlink($pdfFile);
            if (self::looksLikeSnapError($message)) {
                throw new RuntimeException(
                    'PDF-generatie via Chromium mislukt: snap-chromium werkt niet vanuit Apache/www-data. '
                    . 'Installeer Google Chrome (.deb) of zet HORAE_CHROMIUM_PATH.'
                );
            }
            throw new RuntimeException('PDF-generatie via Chromium mislukt' . ($message !== '' ? ': ' . $message : ''));
        }

        return $pdfFile;
    }

    private static function isUsableChromium(string $path): bool
    {
        if ($path === '' || !is_executable($path) || self::isSnapChromium($path)) {
            return false;
        }

        $cmd = sprintf(
            '%s --headless --disable-gpu --no-sandbox --version 2>&1',
            escapeshellarg($path)
        );
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $message = implode("\n", $output);

        if ($exitCode !== 0 || self::looksLikeSnapError($message)) {
            return false;
        }

        return trim($message) !== '';
    }

    private static function isSnapChromium(string $path): bool
    {
        if (str_contains($path, '/snap/')) {
            return true;
        }

        $real = realpath($path);
        if ($real !== false && str_contains($real, '/snap/')) {
            return true;
        }

        return false;
    }

    private static function looksLikeSnapError(string $message): bool
    {
        return stripos($message, 'snap cgroup') !== false
            || stripos($message, 'snap.chromium') !== false;
    }
}
