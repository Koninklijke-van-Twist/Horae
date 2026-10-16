<?php
/**
 * odata.php-acties zijn niet anoniem, cache_status lekt geen voorbeeldrijen,
 * en een ingelogde sessiecookie (zoals de Horae-UI meestuurt) houdt de acties bruikbaar.
 * Run: php tests/odata_action_access_test.php
 */

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_same($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fail($label . ' verwacht ' . var_export($expected, true) . ' kreeg ' . var_export($actual, true));
    }
}

function assert_contains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        fail($label . ' mist "' . $needle . '"');
    }
}

function assert_not_contains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) !== false) {
        fail($label . ' bevat "' . $needle . '"');
    }
}

$root = dirname(__DIR__);
$odataSource = (string) file_get_contents($root . '/web/odata.php');
$dispatch = substr($odataSource, (int) strrpos($odataSource, '$odataAction = '));
$requireAt = strpos($dispatch, 'odata_require_direct_action_access()');
$guarded = [
    'cache_status',
    'cache_delete',
    'cache_clear',
    'projects_batch',
    'projects_search',
    'override_get',
    'override_save',
    'override_create_week',
    'override_delete_week',
    'override_reset_all',
    'override_row_add',
    'override_row_delete',
    'override_row_restore',
];
if ($requireAt === false) {
    fail('actie-dispatch mist login');
}
foreach ($guarded as $action) {
    $at = strpos($dispatch, $action);
    if ($at === false || $requireAt > $at) {
        fail('logincheck moet vóór ' . $action);
    }
}
$gateStart = strpos($odataSource, 'function odata_require_direct_action_access');
$gateEnd = strpos($odataSource, 'function odata_release_session_lock');
if ($gateStart === false || $gateEnd === false || $gateEnd < $gateStart) {
    fail('login-gate of sessie-vrijgave ontbreekt');
}
$gate = substr($odataSource, $gateStart, $gateEnd - $gateStart);
$emailAt = strpos($gate, 'odata_email_is_allowed');
$releaseAt = strpos($gate, 'odata_release_session_lock()');
if ($emailAt === false || $releaseAt === false || $releaseAt < $emailAt) {
    fail('sessielock moet vrij na de logincheck, vóór het lezen van de cache');
}
if (strpos($gate, 'session_write_close()') !== false) {
    fail('session_write_close hoort in odata_release_session_lock, aangeroepen na de check');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['horae_access_probe'] = 1;
odata_release_session_lock();
if (session_status() === PHP_SESSION_ACTIVE) {
    fail('actieve sessie blijft gelockt');
}

assert_contains($odataSource, 'php_sapi_name()', 'cli-check');
assert_contains($odataSource, "=== 'cli'", 'cli-vergelijking');
assert_contains($odataSource, 'session_write_close()', 'sessie dicht na de check');
assert_not_contains($odataSource, 'is_trusted_requester', 'geen localhost-bypass');
assert_not_contains($odataSource, 'odata_cache_extract_attributes_from_payload', 'geen voorbeeldrij-extractor');
assert_not_contains($odataSource, 'entry.attributes', 'widget toont geen voorbeeldrij');

$indexUi = (string) file_get_contents($root . '/web/index.php');
$timesheetUi = (string) file_get_contents($root . '/web/templates/timesheet.php');
assert_contains($indexUi, 'odata.php?action=projects_batch', 'projectselectie');
assert_contains($indexUi, 'odata.php?action=projects_search', 'projectzoeken');
assert_contains($indexUi, "credentials: 'same-origin'", 'projectselectie stuurt de sessiecookie mee');
assert_contains($timesheetUi, 'odata.php?action=', 'urenstaat-overrides');
assert_contains($timesheetUi, "credentials: 'same-origin'", 'urenstaat stuurt de sessiecookie mee');

$allowed = [
    'analyst@kvt.nl',
    'planner@kvt.nl' => [15, 40],
];
assert_same(true, odata_email_is_allowed('Analyst@kvt.nl', $allowed), 'lijst-gebruiker');
assert_same(true, odata_email_is_allowed('planner@kvt.nl', $allowed), 'map-gebruiker');
assert_same(false, odata_email_is_allowed('andere@kvt.nl', $allowed), 'niet op de lijst');
assert_same(false, odata_email_is_allowed('', $allowed), 'lege sessie');
assert_same(false, odata_email_is_allowed('analyst@kvt.nl', null), 'geen whitelist');

assert_same(true, odata_direct_action_skips_login('cli', '', ''), 'cli zonder geheim');
assert_same(false, odata_direct_action_skips_login('fpm-fcgi', '', ''), 'web anoniem');
assert_same(false, odata_direct_action_skips_login('fpm-fcgi', '', 'server-secret'), 'web zonder header');
assert_same(false, odata_direct_action_skips_login('fpm-fcgi', 'server-secret', ''), 'leeg servergeheim');
assert_same(false, odata_direct_action_skips_login('fpm-fcgi', 'nope', 'server-secret'), 'fout geheim');
assert_same(true, odata_direct_action_skips_login('cgi-fcgi', 'server-secret', 'server-secret'), 'bestaand servergeheim');
assert_same(false, odata_direct_action_skips_login('apache2handler', 'server-secret', 'ander-geheim'), 'geheim komt niet overeen');

$savedHeader = $_SERVER['HTTP_X_HORAE_SECRET'] ?? null;
$savedGetSecret = $_GET['secret'] ?? null;
$_SERVER['HTTP_X_HORAE_SECRET'] = '  uit-de-header  ';
$_GET['secret'] = 'uit-de-query';
assert_same('uit-de-header', odata_presented_runner_secret(), 'header, geen query');
if ($savedHeader === null) {
    unset($_SERVER['HTTP_X_HORAE_SECRET']);
} else {
    $_SERVER['HTTP_X_HORAE_SECRET'] = $savedHeader;
}
if ($savedGetSecret === null) {
    unset($_GET['secret']);
} else {
    $_GET['secret'] = $savedGetSecret;
}

$authPath = sys_get_temp_dir() . '/horae-auth-runner-secret.php';
file_put_contents($authPath, "<?php\n\$horaeRunnerSecret = \"auth-php-geheim\";\n\$allowedUsers = ['nacht@kvt.nl'];\n");
$previousAuthPath = $GLOBALS['HORAE_AUTH_PHP_PATH'] ?? null;
$previousRunner = $GLOBALS['horaeRunnerSecret'] ?? null;
unset($GLOBALS['horaeRunnerSecret']);
$previousEnv = getenv('HORAE_RUNNER_SECRET');
putenv('HORAE_RUNNER_SECRET');
$GLOBALS['HORAE_AUTH_PHP_PATH'] = $authPath;
$fromFile = odata_read_auth_file_vars();
assert_same('auth-php-geheim', odata_configured_runner_secret($fromFile), 'geheim uit auth.php');
assert_same(true, odata_email_is_allowed('nacht@kvt.nl', $fromFile['allowedUsers'] ?? null), 'whitelist uit auth.php');
putenv('HORAE_RUNNER_SECRET=env-geheim');
assert_same('auth-php-geheim', odata_configured_runner_secret($fromFile), 'auth.php wint van een lege global');
$GLOBALS['horaeRunnerSecret'] = 'global-geheim';
assert_same('global-geheim', odata_configured_runner_secret($fromFile), 'global wint');
unset($GLOBALS['horaeRunnerSecret']);
putenv('HORAE_RUNNER_SECRET');
if ($previousAuthPath === null) {
    unset($GLOBALS['HORAE_AUTH_PHP_PATH']);
} else {
    $GLOBALS['HORAE_AUTH_PHP_PATH'] = $previousAuthPath;
}
if ($previousRunner !== null) {
    $GLOBALS['horaeRunnerSecret'] = $previousRunner;
}
if ($previousEnv !== false && $previousEnv !== '') {
    putenv('HORAE_RUNNER_SECRET=' . $previousEnv);
}
@unlink($authPath);

$projectNo = 'PRJ-GATE-44021';
$customerName = 'Poorttest Klant Jansen';
$employeeName = 'Medewerker De Vries';
$sourceUrl = "https://bc.example:7148/ui_gate_test/ODataV4/Company('KVT')/Job_Planning_Lines";
$overrideSecret = 'Overridegeheim Jansen';

$GLOBALS['environment'] = 'ui_gate_test';
$cacheDir = cache_base_dir();
$fixtureName = 'horae-access-test-' . bin2hex(random_bytes(8)) . '.json';
$fixturePath = $cacheDir . '/' . $fixtureName;
$now = time();
$fixture = [
    '_meta' => [
        'cached_at' => $now,
        'expires_at' => $now + 86400,
        'source_url' => $sourceUrl,
        'fetched' => true,
    ],
    'data' => [[
        'Job_No' => $projectNo,
        'Resource_Name' => $employeeName,
        'Description' => $customerName,
        'Amount' => 12345.67,
        '@odata.etag' => 'W/"example"',
    ]],
];
file_put_contents($fixturePath, json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$indexPath = projects_nightly_index_path();
$fatPath = projects_nightly_cache_path();
$projectPayload = [
    '_meta' => [
        'cached_at' => $now,
        'expires_at' => $now + 86400,
        'source_url' => $sourceUrl,
        'kind' => 'projects_index',
    ],
    'data' => [[
        'No' => $projectNo,
        'Description' => $customerName,
    ]],
];
file_put_contents($indexPath, json_encode($projectPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($fatPath, json_encode($projectPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$overridePath = $root . '/web/cache/overrides/overrides-' . $projectNo . '-12.json';
if (!is_dir(dirname($overridePath))) {
    mkdir(dirname($overridePath), 0777, true);
}
file_put_contents($overridePath, json_encode([
    'projectNo' => $projectNo,
    'weekNo' => 12,
    'note' => $overrideSecret,
], JSON_UNESCAPED_UNICODE));

$loginDir = $root . '/login';
$sessionDir = sys_get_temp_dir() . '/horae-sess-' . bin2hex(random_bytes(4));
$sessionMarker = sys_get_temp_dir() . '/horae-session-marker-' . bin2hex(random_bytes(4));
$cgiAuthPath = sys_get_temp_dir() . '/horae-cgi-auth-' . bin2hex(random_bytes(4)) . '.php';
mkdir($sessionDir, 0700, true);

$cleanup = static function () use ($fixturePath, $indexPath, $fatPath, $overridePath, $loginDir, $sessionDir, $sessionMarker, $cgiAuthPath): void {
    @unlink($fixturePath);
    @unlink($indexPath);
    @unlink($fatPath);
    @unlink($overridePath);
    @unlink($loginDir . '/lib.php');
    @unlink($loginDir . '/403.php');
    @unlink($sessionMarker);
    @unlink($cgiAuthPath);
    if (is_dir($sessionDir)) {
        foreach (glob($sessionDir . '/sess_*') ?: [] as $sessionFile) {
            @unlink($sessionFile);
        }
        @rmdir($sessionDir);
    }
    if (is_dir($loginDir)) {
        @rmdir($loginDir);
    }
};
register_shutdown_function($cleanup);

try {
    $payload = odata_cache_status_payload();
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        fail('cache_status payload is geen JSON');
    }
    assert_not_contains($encoded, $projectNo, 'cache_status');
    assert_not_contains($encoded, $customerName, 'cache_status');
    assert_not_contains($encoded, $employeeName, 'cache_status');
    assert_not_contains($encoded, '12345.67', 'cache_status');
    assert_not_contains($encoded, 'attributes', 'cache_status');
    assert_not_contains($encoded, 'Resource_Name', 'cache_status');

    $match = null;
    foreach ($payload['entries'] as $entry) {
        if (($entry['id'] ?? '') === $fixtureName) {
            $match = $entry;
            break;
        }
    }
    if (!is_array($match)) {
        fail('fixture ontbreekt in cache_status');
    }
    if (array_key_exists('attributes', $match) || array_key_exists('data', $match)) {
        fail('cache_status-entry bevat rijdata');
    }
    assert_same($sourceUrl, $match['url'] ?? '', 'bron-url voor ingelogde widget');
    if ((int) ($match['size_bytes'] ?? 0) <= 0) {
        fail('cache_status mist bestandsgrootte');
    }
} catch (Throwable $e) {
    $cleanup();
    throw $e;
}

$widget = injectTimerHtml([
    'statusUrl' => 'odata.php?action=cache_status',
]);
assert_not_contains($widget, 'attributes', 'widget-html');
assert_contains($widget, 'odata.php?action=cache_status', 'widget houdt status-url');
assert_contains($widget, "credentials: 'same-origin'", 'widget stuurt de sessiecookie mee');

foreach ([
    $root . '/web/cache/.htaccess',
    $root . '/web/cache/odata/.htaccess',
    $root . '/web/cache/overrides/.htaccess',
    $root . '/web/data/.htaccess',
] as $htaccess) {
    $rules = (string) file_get_contents($htaccess);
    assert_contains($rules, 'Options -Indexes', $htaccess);
    assert_contains($rules, 'Require all denied', $htaccess);
    assert_contains($rules, 'Deny from all', $htaccess);
    assert_contains($rules, '<IfModule mod_authz_core.c>', $htaccess);
    assert_contains($rules, '<IfModule !mod_authz_core.c>', $htaccess);
}
foreach ([
    $root . '/web/cache/index.html',
    $root . '/web/cache/index.php',
    $root . '/web/cache/odata/index.html',
    $root . '/web/cache/odata/index.php',
    $root . '/web/cache/overrides/index.html',
    $root . '/web/cache/overrides/index.php',
    $root . '/web/data/index.html',
    $root . '/web/data/index.php',
] as $indexFile) {
    if (!is_file($indexFile)) {
        fail('ontbrekend indexbestand ' . $indexFile);
    }
    $indexBody = (string) file_get_contents($indexFile);
    if (strlen($indexBody) > 16) {
        fail('indexbestand is niet leeg: ' . $indexFile);
    }
    assert_not_contains($indexBody, 'Customer', $indexFile);
}

$workflow = (string) file_get_contents($root . '/.github/workflows/deploy-ftp.yml');
$includeAt = strpos($workflow, '--include-glob cache/.htaccess');
$includeDataAt = strpos($workflow, '--include-glob data/.htaccess');
$excludeCacheAt = strpos($workflow, '--exclude-glob cache/**');
$excludeOdataAt = strpos($workflow, '--exclude-glob cache/odata/**');
$excludeDataAt = strpos($workflow, '--exclude-glob data/**');
if ($includeAt === false || $includeDataAt === false || $excludeCacheAt === false || $excludeOdataAt === false || $excludeDataAt === false) {
    fail('ftp-deploy mist cache- of data-patronen');
}
if ($includeAt < $excludeCacheAt || $includeAt < $excludeOdataAt || $includeDataAt < $excludeDataAt) {
    fail('htaccess-includes moeten ná de excludes staan, anders uploadt lftp ze niet');
}
foreach ([
    '--include-glob cache/index.html',
    '--include-glob cache/index.php',
    '--include-glob cache/odata/.htaccess',
    '--include-glob cache/odata/index.html',
    '--include-glob cache/odata/index.php',
    '--include-glob cache/overrides/.htaccess',
    '--include-glob cache/overrides/index.html',
    '--include-glob cache/overrides/index.php',
    '--include-glob data/index.html',
    '--include-glob data/index.php',
    '--exclude-glob auth.php',
    '--exclude-glob .htaccess',
] as $needle) {
    assert_contains($workflow, $needle, 'ftp-deploy');
}

file_put_contents($cgiAuthPath, "<?php\n"
    . "\$horaeRunnerSecret = 'server-secret';\n"
    . "\$environment = 'ui_gate_test';\n"
    . "\$baseUrl = 'https://bc.example:7148/';\n"
    . "\$auth = ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'];\n"
    . "\$auth_list = ['ui_gate_test' => \$auth];\n"
    . "\$base = \"https://bc.example:7148/ui_gate_test/ODataV4/Company('KVT')/\";\n"
    . "\$allowedUsers = ['analyst@kvt.nl', 'planner@kvt.nl' => [15, 40]];\n");

function horae_split_cgi(string $stdout): array
{
    $parts = preg_split("/\r?\n\r?\n/", $stdout, 2);
    return [
        is_string($parts[0] ?? null) ? $parts[0] : '',
        is_string($parts[1] ?? null) ? $parts[1] : $stdout,
    ];
}

function horae_run_cgi(string $root, string $action, array $get, array $env): array
{
    $phpCgi = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));
    if ($phpCgi === '') {
        fail('php-cgi ontbreekt');
    }
    $odataFile = $root . '/web/odata.php';
    $script = sys_get_temp_dir() . '/horae-odata-cgi-' . bin2hex(random_bytes(4)) . '.php';
    $lines = ["<?php"];
    $lines[] = '$_SERVER["SCRIPT_FILENAME"] = ' . var_export($odataFile, true) . ';';
    $lines[] = '$_SERVER["SCRIPT_NAME"] = "/horae/odata.php";';
    $lines[] = '$_SERVER["PHP_SELF"] = "/horae/odata.php";';
    $lines[] = '$_SERVER["REMOTE_ADDR"] = "203.0.113.50";';
    $lines[] = '$_SERVER["SERVER_ADDR"] = "198.51.100.10";';
    $lines[] = '$_GET["action"] = ' . var_export($action, true) . ';';
    if (isset($env['HORAE_AUTH_PHP_PATH']) && $env['HORAE_AUTH_PHP_PATH'] !== '') {
        $lines[] = '$GLOBALS["HORAE_AUTH_PHP_PATH"] = ' . var_export($env['HORAE_AUTH_PHP_PATH'], true) . ';';
    }
    foreach ($get as $key => $value) {
        $lines[] = '$_GET[' . var_export((string) $key, true) . '] = ' . var_export($value, true) . ';';
    }
    $lines[] = 'require ' . var_export($odataFile, true) . ';';
    file_put_contents($script, implode("\n", $lines) . "\n");

    $previous = [];
    foreach ($env as $key => $value) {
        $previous[$key] = getenv($key);
        putenv($key . '=' . $value);
    }
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        [$phpCgi, '-d', 'cgi.force_redirect=0', '-d', 'display_errors=0', $script],
        $descriptor,
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        @unlink($script);
        fail('php-cgi startte niet');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($script);
    foreach ($previous as $key => $old) {
        if ($old === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $old);
        }
    }
    $stdout = is_string($stdout) ? $stdout : '';
    $stderr = is_string($stderr) ? $stderr : '';
    if ($exitCode !== 0 && strpos($stdout, 'Geen toegang') === false && strpos($stdout, '"ok"') === false) {
        fail('cgi-actie ' . $action . ' exit ' . $exitCode . ' ' . $stderr . ' ' . $stdout);
    }
    [$headers, $body] = horae_split_cgi($stdout);
    return ['headers' => $headers, 'body' => $body, 'raw' => $stdout];
}

function horae_assert_denied(array $response, string $label, array $secrets): void
{
    $blob = $response['headers'] . "\n" . $response['body'];
    assert_contains($blob, 'Geen toegang', $label);
    assert_not_contains($response['body'], 'Onbekende actie', $label);
    foreach ($secrets as $secret) {
        assert_not_contains($blob, $secret, $label);
    }
}

$secrets = [$projectNo, $customerName, $employeeName, $overrideSecret, 'Job_Planning_Lines', '12345.67'];
$cgiEnv = [
    'HORAE_AUTH_PHP_PATH' => $cgiAuthPath,
];

if (!is_file($fixturePath)) {
    fail('anonieme clear/delete-test mist fixture');
}
$deniedBatch = horae_run_cgi($root, 'projects_batch', [], $cgiEnv);
horae_assert_denied($deniedBatch, 'anonieme projects_batch', $secrets);
$deniedSearch = horae_run_cgi($root, 'projects_search', ['q' => 'Poorttest'], $cgiEnv);
horae_assert_denied($deniedSearch, 'anonieme projects_search', $secrets);
$deniedOverride = horae_run_cgi($root, 'override_get', ['projectNo' => $projectNo, 'weekNo' => '12'], $cgiEnv);
horae_assert_denied($deniedOverride, 'anonieme override_get', $secrets);
$deniedStatus = horae_run_cgi($root, 'cache_status', [], $cgiEnv);
horae_assert_denied($deniedStatus, 'anonieme cache_status', $secrets);
$deniedUnknown = horae_run_cgi($root, 'niet_bestaand', [], $cgiEnv);
horae_assert_denied($deniedUnknown, 'anonieme onbekende actie', $secrets);
horae_run_cgi($root, 'cache_delete', ['id' => $fixtureName], $cgiEnv);
horae_run_cgi($root, 'cache_clear', [], $cgiEnv);
if (!is_file($fixturePath)) {
    fail('anonieme cache_delete of cache_clear heeft een cachebestand verwijderd');
}

if (!is_dir($loginDir) && !mkdir($loginDir, 0700, true) && !is_dir($loginDir)) {
    fail('login-stubmap ontbreekt');
}
file_put_contents($loginDir . '/403.php', "<?php\nhttp_response_code(403);\necho 'Geen toegang';\nexit;\n");
file_put_contents($loginDir . '/lib.php', "<?php\n"
    . 'session_save_path(' . var_export($sessionDir, true) . ");\n"
    . 'register_shutdown_function(static function (): void {' . "\n"
    . '    file_put_contents(' . var_export($sessionMarker, true) . ', session_status() === PHP_SESSION_ACTIVE ? "active" : "closed");' . "\n"
    . "});\n"
    . "if (session_status() !== PHP_SESSION_ACTIVE) {\n"
    . "    session_start();\n"
    . "}\n");

function horae_session_id(string $sessionDir, string $email): string
{
    $starter = sys_get_temp_dir() . '/horae-session-start-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($starter, "<?php\n"
        . 'session_save_path(' . var_export($sessionDir, true) . ");\n"
        . "session_start();\n"
        . '$_SESSION["user"]["email"] = ' . var_export($email, true) . ";\n"
        . "session_write_close();\n"
        . "echo session_id();\n");
    $id = trim((string) shell_exec('php ' . escapeshellarg($starter)));
    @unlink($starter);
    if ($id === '') {
        fail('sessie voor ' . $email . ' startte niet');
    }
    return $id;
}

$plannerCookie = horae_session_id($sessionDir, 'planner@kvt.nl');
$strangerCookie = horae_session_id($sessionDir, 'andere@kvt.nl');
@unlink($sessionMarker);

$allowedEnv = $cgiEnv;
$allowedEnv['HTTP_COOKIE'] = 'PHPSESSID=' . $plannerCookie;
$batch = horae_run_cgi($root, 'projects_batch', [], $allowedEnv);
assert_contains($batch['body'], $projectNo, 'ingelogde projects_batch');
assert_contains($batch['body'], $customerName, 'ingelogde projects_batch');
assert_not_contains($batch['body'], 'Geen toegang', 'ingelogde projects_batch');
assert_same('closed', trim((string) @file_get_contents($sessionMarker)), 'sessie dicht tijdens projects_batch');

$search = horae_run_cgi($root, 'projects_search', ['q' => 'Poorttest'], $allowedEnv);
assert_contains($search['body'], $projectNo, 'ingelogde projects_search');
assert_contains($search['body'], $customerName, 'ingelogde projects_search');

$override = horae_run_cgi($root, 'override_get', ['projectNo' => $projectNo, 'weekNo' => '12'], $allowedEnv);
assert_contains($override['body'], $overrideSecret, 'ingelogde override_get');
assert_contains($override['body'], '"ok":true', 'ingelogde override_get');

$status = horae_run_cgi($root, 'cache_status', [], $allowedEnv);
assert_contains($status['body'], 'Job_Planning_Lines', 'ingelogde cache_status toont de bron-url');
assert_not_contains($status['body'], $employeeName, 'ingelogde cache_status');
assert_not_contains($status['body'], $customerName, 'ingelogde cache_status');
assert_not_contains($status['body'], 'attributes', 'ingelogde cache_status');
assert_not_contains($status['body'], '12345.67', 'ingelogde cache_status');

$unknown = horae_run_cgi($root, 'niet_bestaand', [], $allowedEnv);
assert_contains($unknown['headers'] . $unknown['body'], '404', 'ingelogde onbekende actie');
assert_contains($unknown['body'], 'Onbekende actie', 'ingelogde onbekende actie');
assert_not_contains($unknown['body'], $projectNo, 'ingelogde onbekende actie');

$strangerEnv = $cgiEnv;
$strangerEnv['HTTP_COOKIE'] = 'PHPSESSID=' . $strangerCookie;
$stranger = horae_run_cgi($root, 'projects_batch', [], $strangerEnv);
horae_assert_denied($stranger, 'sessie buiten allowedUsers', $secrets);

$loggedOut = horae_run_cgi($root, 'projects_batch', [], $cgiEnv);
horae_assert_denied($loggedOut, 'geen sessiecookie', $secrets);

$secretEnv = $cgiEnv;
$secretEnv['HTTP_X_HORAE_SECRET'] = 'server-secret';
$secretBatch = horae_run_cgi($root, 'projects_batch', [], $secretEnv);
assert_contains($secretBatch['body'], $projectNo, 'runnersecret projects_batch');
assert_not_contains($secretBatch['body'], 'Geen toegang', 'runnersecret projects_batch');

$badSecretEnv = $cgiEnv;
$badSecretEnv['HTTP_X_HORAE_SECRET'] = 'nope';
$badSecret = horae_run_cgi($root, 'projects_search', ['q' => 'Poorttest'], $badSecretEnv);
horae_assert_denied($badSecret, 'fout runnersecret', $secrets);

$querySecret = horae_run_cgi($root, 'override_get', ['projectNo' => $projectNo, 'weekNo' => '12', 'secret' => 'server-secret'], $cgiEnv);
horae_assert_denied($querySecret, 'geheim in de query', $secrets);

if (!is_file($fixturePath) || !is_file($overridePath)) {
    fail('toegelaten acties hebben de fixture verwijderd');
}

echo "OK odata action access\n";
