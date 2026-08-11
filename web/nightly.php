<?php
/**
 * Nightly job: haalt AppProjecten + servicelocatie + Job Planning Lines (Resource) op en schrijft 24u-cache.
 * Wordt via GET aangeroepen door het bestaande nightly-script (geen UI).
 *
 * Voorbeeld: GET /horae/web/nightly.php
 */

require __DIR__ . '/odata.php';
require __DIR__ . '/auth.php';

@set_time_limit(0);
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '0');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

try {
    $result = projects_nightly_refresh($base, $auth);
    echo "OK\n";
    echo 'projects=' . (int) $result['count'] . "\n";
    echo 'planningLines=' . (int) ($result['planningLines'] ?? 0) . "\n";
    echo 'cached_at=' . date('c', (int) $result['cached_at']) . "\n";
    echo 'expires_at=' . date('c', (int) $result['expires_at']) . "\n";
    echo 'path=' . (string) $result['path'] . "\n";
    echo "includes=servicelocation,contractor,hoursStart,hoursEnd,planningLines\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "FAIL\n";
    echo $e->getMessage() . "\n";
}
