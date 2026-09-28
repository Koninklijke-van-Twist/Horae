<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/horae-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$base = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/";
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$throwBrokenEnv = false;
$GLOBALS['HORAE_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl, int $curlTimeout = 120) use (&$calls, &$throwBrokenEnv): array {
    if ($throwBrokenEnv && preg_match('#/Broken/ODataV4/#', $url) === 1) {
        throw new Exception('Broken environment down bc-secret should-not-leak');
    }
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
        'curlTimeout' => $curlTimeout,
    ];
    if (preg_match('#/([^/]+)/ODataV4/Company(?:\\?|$)#', $url, $envMatch) === 1) {
        if (strcasecmp($envMatch[1], 'Sandbox') === 0) {
            return [
                ['Name' => 'Tweede BV'],
            ];
        }
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Horae] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser' || $calls[0]['ttl'] !== 300) {
    fail('company-fallback gebruikte niet de BC-credentials of TTL: ' . json_encode($calls[0]));
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120 || $entityCall['curlTimeout'] !== 120) {
    fail('entity-fallback URL/auth/ttl/timeout klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag gelogd worden, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Horae] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = null;
for ($i = $beforeQuery; $i < count($calls); $i++) {
    if (strpos($calls[$i]['url'], '/AppResource?') !== false) {
        $queryCall = $calls[$i];
    }
}
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}
if ($queryCall['ttl'] !== 60 || $queryCall['user'] !== 'bcuser') {
    fail('query-fallback ttl/auth klopt niet: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

$beforeNightly = count($calls);
$nightlyRows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/Job_Planning_Lines?\$filter=Type%20eq%20'Resource'",
    $auth,
    14400,
    180
);
$nightlyCall = $calls[$beforeNightly] ?? null;
if (($nightlyRows[0]['No'] ?? '') !== 'WO-1' || !is_array($nightlyCall) || $nightlyCall['curlTimeout'] !== 180 || $nightlyCall['ttl'] !== 14400) {
    fail('nightly-timeout/ttl kwam niet aan op de directe fetch: ' . json_encode($nightlyCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$base = "https://mimir.invalid/mimir/ODataV4/Company('X')/";
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all("https://mimir.invalid/mimir/ODataV4/Company('X')/AppWerkorders", ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable || strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . ($rethrown instanceof Throwable ? $rethrown->getMessage() : 'geen exception'));
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$base = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/";
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No";
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl || $directCall['ttl'] !== 45) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$base = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/";
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$authSandbox = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$auth_list = [
    'Production' => $auth,
    'Sandbox' => $authSandbox,
];
odata_mimir_circuit_reset();
$logsBeforeParse = fallback_count();
$callsBeforeParse = count($calls);
try {
    odata_mimir_fetch_all('https://bc.example/not-odata', 10);
    fail('onvertaalbare URL moet een fout geven');
} catch (Throwable $exception) {
    if (odata_mimir_circuit_open()) {
        fail('een niet-Mímir-fout mag het circuit niet openen');
    }
    if (fallback_count() !== $logsBeforeParse || count($calls) !== $callsBeforeParse) {
        fail('een niet-Mímir-fout mag niet naar BC uitwijken of loggen');
    }
}

odata_mimir_circuit_reset();
$beforeSecond = count($calls);
$secondRows = odata_mimir_query('Tweede BV', 'AppResource', ['$select' => 'No'], 30);
$secondCall = null;
for ($i = $beforeSecond; $i < count($calls); $i++) {
    if (strpos($calls[$i]['url'], '/AppResource?') !== false) {
        $secondCall = $calls[$i];
    }
}
if (($secondRows[0]['No'] ?? '') !== 'WO-1' || !is_array($secondCall)) {
    fail('tweede environment gaf geen directe rij: ' . json_encode($secondCall));
}
if (strpos($secondCall['url'], "https://bc.example:7148/Sandbox/ODataV4/Company('Tweede%20BV')/AppResource?") !== 0 || $secondCall['user'] !== 'sandbox-user') {
    fail('bedrijf in Sandbox gebruikte niet die environment/auth: ' . json_encode($secondCall));
}
$sawSandboxCompanies = false;
for ($i = $beforeSecond; $i < count($calls); $i++) {
    if (strpos($calls[$i]['url'], 'https://bc.example:7148/Sandbox/ODataV4/Company') === 0 && strpos($calls[$i]['url'], 'AppResource') === false) {
        $sawSandboxCompanies = $calls[$i]['user'] === 'sandbox-user';
    }
}
if (!$sawSandboxCompanies) {
    fail('companylijst voor Sandbox ontbreekt of gebruikte de verkeerde auth: ' . json_encode(array_slice($calls, $beforeSecond)));
}

$logsBeforeCircuit = fallback_count();
$passedPrimary = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$segmentRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Tweede%20BV')/AppResource?\$select=No",
    $passedPrimary,
    12
);
$segmentCall = $calls[count($calls) - 1] ?? null;
if (($segmentRows[0]['No'] ?? '') !== 'WO-1' || !is_array($segmentCall) || $segmentCall['user'] !== 'sandbox-user') {
    fail('URL-segment Sandbox moet die auth gebruiken: ' . json_encode($segmentCall));
}
if (strpos($segmentCall['url'], 'https://bc.example:7148/Sandbox/ODataV4/Company(') !== 0) {
    fail('URL-segment werd niet behouden: ' . json_encode($segmentCall));
}
if (fallback_count() !== $logsBeforeCircuit) {
    fail('open circuit mag niet opnieuw loggen');
}

$lookupUrl = odata_bc_url_from_odata_url("https://mimir.invalid/mimir/ODataV4/Company('Tweede%20BV')/AppResource?\$select=No");
if (strpos($lookupUrl, "https://bc.example:7148/Sandbox/ODataV4/Company('Tweede%20BV')/AppResource?") !== 0) {
    fail('company-map moet Sandbox kiezen als het URL-segment mimir is: ' . $lookupUrl);
}
$unknownUrl = odata_bc_url_from_odata_url("https://mimir.invalid/mimir/ODataV4/Company('Onbekend')/AppResource");
if (strpos($unknownUrl, 'https://bc.example:7148/Production/ODataV4/Company(') !== 0) {
    fail('onbekend bedrijf moet op de primaire environment terugvallen: ' . $unknownUrl);
}
$encodedUrl = odata_bc_url_from_odata_url("https://mimir.invalid/Sand%20Box/ODataV4/Company('X')/T");
if ($encodedUrl !== 'https://bc.example:7148/Sand%20Box/ODataV4/Company(\'X\')/T') {
    fail('env-segment mag maar één keer geëncodeerd worden: ' . $encodedUrl);
}

$throwBrokenEnv = true;
$auth_list['Broken'] = ['mode' => 'basic', 'user' => 'broken-user', 'pass' => 'broken-secret'];
$brokenLogsBefore = substr_count(fallback_log(), '[Horae] companylijst voor environment Broken mislukt');
$brokenLookup = odata_bc_url_from_odata_url("https://mimir.invalid/mimir/ODataV4/Company('Tweede%20BV')/AppResource?\$select=No");
if (strpos($brokenLookup, "https://bc.example:7148/Sandbox/ODataV4/Company('Tweede%20BV')/AppResource?") !== 0) {
    fail('een falende environment mag de company-map van gezonde environments niet wissen: ' . $brokenLookup);
}
if (substr_count(fallback_log(), '[Horae] companylijst voor environment Broken mislukt') !== $brokenLogsBefore + 1) {
    fail('een falende environment moet geïsoleerd gelogd worden, log=' . fallback_log());
}
if (strpos(fallback_log(), 'should-not-leak') !== false || strpos(fallback_log(), 'broken-secret') !== false) {
    fail('environment-foutlog bevat een geheim');
}

$environment = 'mimir';
$cacheKey = build_cache_key("https://bc.example:7148/Sandbox/ODataV4/Company('Tweede%20BV')/AppResource", $authSandbox);
if (substr($cacheKey, -strlen('|sandbox-user|Sandbox')) !== '|sandbox-user|Sandbox') {
    fail('cache-key moet de echte BC-environment gebruiken: ' . $cacheKey);
}
if (strpos($cacheKey, '|mimir') !== false) {
    fail('cache-key bevat de mimir-placeholder: ' . $cacheKey);
}
$environment = 'Production';

odata_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$base = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/";
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
unset($auth_list);

$beforeOnlyAuth = count($calls);
$onlyAuthNames = odata_mimir_list_companies(null);
if ($onlyAuthNames !== ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas']) {
    fail('companylijst zonder $auth_list gaf ' . json_encode($onlyAuthNames));
}
$onlyAuthCompanyCall = $calls[$beforeOnlyAuth] ?? null;
if (!is_array($onlyAuthCompanyCall)
    || strpos($onlyAuthCompanyCall['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0
    || $onlyAuthCompanyCall['user'] !== 'bcuser') {
    fail('companylijst zonder $auth_list gebruikte niet $auth: ' . json_encode($onlyAuthCompanyCall));
}

$beforeOnlyFilter = count($calls);
$onlyFilterNames = odata_mimir_list_companies('Production');
$onlyFilterCall = $calls[$beforeOnlyFilter] ?? null;
if ($onlyFilterNames !== $onlyAuthNames || !is_array($onlyFilterCall) || $onlyFilterCall['user'] !== 'bcuser'
    || strpos($onlyFilterCall['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('gefilterde companylijst zonder $auth_list gebruikte niet $auth: ' . json_encode($onlyFilterCall));
}

$beforeOnlyQuery = count($calls);
$onlyQueryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No'], 30);
$onlyQueryCall = null;
for ($i = $beforeOnlyQuery; $i < count($calls); $i++) {
    if (strpos($calls[$i]['url'], '/AppResource?') !== false) {
        $onlyQueryCall = $calls[$i];
    }
    if ($calls[$i]['user'] !== 'bcuser') {
        fail('query-pad zonder $auth_list gebruikte andere credentials: ' . json_encode($calls[$i]));
    }
}
if (($onlyQueryRows[0]['No'] ?? '') !== 'WO-1' || !is_array($onlyQueryCall)
    || strpos($onlyQueryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0
    || $onlyQueryCall['user'] !== 'bcuser') {
    fail('query zonder $auth_list gebruikte niet $auth: ' . json_encode($onlyQueryCall));
}

$beforeOnlyFetch = count($calls);
$onlyFetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Tweede%20BV')/AppResource?\$select=No",
    15
);
$onlyFetchCall = $calls[$beforeOnlyFetch] ?? null;
if (($onlyFetchRows[0]['No'] ?? '') !== 'WO-1' || !is_array($onlyFetchCall)
    || strpos($onlyFetchCall['url'], "https://bc.example:7148/Sandbox/ODataV4/Company('Tweede%20BV')/AppResource?") !== 0
    || $onlyFetchCall['user'] !== 'bcuser') {
    fail('URL-fetch zonder $auth_list gebruikte niet $auth: ' . json_encode($onlyFetchCall));
}

odata_mimir_circuit_reset();
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = [
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'],
];
$beforeUnmapped = count($calls);
$unmappedRows = odata_mimir_query('Onbekend BV', 'AppResource', ['$select' => 'No'], 25);
$unmappedCall = null;
for ($i = $beforeUnmapped; $i < count($calls); $i++) {
    if (strpos($calls[$i]['url'], '/AppResource?') !== false) {
        $unmappedCall = $calls[$i];
    }
}
if (($unmappedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($unmappedCall)
    || strpos($unmappedCall['url'], "https://bc.example:7148/Production/ODataV4/Company('Onbekend%20BV')/AppResource?") !== 0
    || $unmappedCall['user'] !== 'bcuser') {
    fail('onbekend bedrijf moet via $auth op Production: ' . json_encode($unmappedCall));
}

$beforePrimaryFilter = count($calls);
$primaryFilterNames = odata_mimir_list_companies('Production');
$primaryFilterCall = $calls[$beforePrimaryFilter] ?? null;
if ($primaryFilterNames !== ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas']
    || !is_array($primaryFilterCall)
    || $primaryFilterCall['user'] !== 'bcuser'
    || strpos($primaryFilterCall['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('filter op primaire environment zonder list-entry gebruikte niet $auth: ' . json_encode($primaryFilterCall));
}

odata_mimir_circuit_reset();
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = [
    'Production' => $auth,
];
$callsBeforeSandboxRefuse = count($calls);
try {
    odata_get_all(
        "https://mimir.invalid/Sandbox/ODataV4/Company('Tweede%20BV')/AppResource?\$select=No",
        $auth,
        12
    );
    fail('Sandbox-URL zonder eigen entry moet de Mímir-fout teruggeven');
} catch (Throwable $exception) {
    if (strpos($exception->getMessage(), 'Mímir') === false) {
        fail('Sandbox-URL weigering is niet de Mímir-fout: ' . $exception->getMessage());
    }
}
if (count($calls) !== $callsBeforeSandboxRefuse) {
    fail('Sandbox-URL zonder entry mag geen BC-call doen: ' . json_encode(array_slice($calls, $callsBeforeSandboxRefuse)));
}

$authPath = dirname(__DIR__) . '/web/auth.php';
if (is_file($authPath)) {
    // Bestaande credentials blijven onaangeroerd.
} else {
    register_shutdown_function(static function () use ($authPath): void {
        if (is_file($authPath)) {
            @unlink($authPath);
        }
    });
    file_put_contents($authPath, <<<'PHP'
<?php
function horae_test_auth_include_marker(): int
{
    return 1;
}
$baseUrl = 'https://from-file.example:7148/';
$base = "https://from-file.example:7148/Sandbox/ODataV4/Company('X')/";
$environment = 'Sandbox';
$auth = ['mode' => 'basic', 'user' => 'file-user', 'pass' => 'file-secret'];
$auth_list = [
    'Sandbox' => ['mode' => 'basic', 'user' => 'file-user', 'pass' => 'file-secret'],
];
PHP);
    $environment = 'KeepMe';
    $auth = ['mode' => 'basic', 'user' => 'preset-user', 'pass' => 'preset-secret'];
    unset($auth_list);
    unset($base);
    unset($GLOBALS['baseUrl']);
    odata_ensure_bc_auth_loaded();
    odata_ensure_bc_auth_loaded();
    if ($environment !== 'KeepMe' || ($auth['user'] ?? '') !== 'preset-user') {
        fail('gezette BC-globals werden overschreven: env=' . $environment . ' user=' . (string) ($auth['user'] ?? ''));
    }
    if (($GLOBALS['baseUrl'] ?? '') !== 'https://from-file.example:7148/') {
        fail('baseUrl werd niet naar $GLOBALS gekopieerd: ' . json_encode($GLOBALS['baseUrl'] ?? null));
    }
    if (($GLOBALS['base'] ?? '') !== "https://from-file.example:7148/Sandbox/ODataV4/Company('X')/") {
        fail('base werd niet naar $GLOBALS gekopieerd: ' . json_encode($GLOBALS['base'] ?? null));
    }
    if (($GLOBALS['auth_list']['Sandbox']['user'] ?? '') !== 'file-user') {
        fail('auth_list werd niet naar $GLOBALS gekopieerd: ' . json_encode($GLOBALS['auth_list'] ?? null));
    }
    if (horae_test_auth_include_marker() !== 1) {
        fail('auth.php werd niet geladen');
    }
    if (strpos(fallback_log(), 'file-secret') !== false || strpos(fallback_log(), 'sandbox-secret') !== false || strpos(fallback_log(), 'bc-secret') !== false || strpos(fallback_log(), 'mimir_test_key_should_not_leak') !== false) {
        fail('log bevat een geheim');
    }
    @unlink($authPath);
}

echo "OK\n";
