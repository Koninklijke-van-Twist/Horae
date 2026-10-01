<?php
/**
 * Projectselectie-batch: slanke rijen en één response vanuit een vette nightly-fixture.
 * Run: php tests/projects_batch_test.php
 */

$environment = 'zz_batch_test';
$GLOBALS['environment'] = $environment;
$GLOBALS['base'] = 'https://bc.example/test/';
$GLOBALS['auth'] = ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'];

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ' expected ' . json_encode($expected) . ' got ' . json_encode($actual));
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

$fatPath = projects_nightly_cache_path();
$indexPath = projects_nightly_index_path();

$cleanup = static function () use ($fatPath, $indexPath): void {
    foreach ([$fatPath, $indexPath, $fatPath . '.tmp', $indexPath . '.tmp'] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
};
register_shutdown_function($cleanup);
$cleanup();

$rows = [
    [
        'No' => 'Z-last',
        'Description' => 'Zed',
        'Your_Reference' => 'REF-Z',
        'LVS_Bill_to_Name' => 'Bill Z',
        'contractor' => ['Naam' => 'Fat Z', 'Adres' => 'Straat'],
        'serviceLocation' => ['No' => 'SZ', 'Naam' => 'Loc Z'],
        'hoursStart' => '2026-01-01',
        'hoursEnd' => '2026-02-01',
    ],
    [
        'No' => 'A-first',
        'Description' => 'Aaa',
        'Your_Reference' => 'REF-A',
        'LVS_Bill_to_Name' => 'Bill A',
        'contractor' => ['Naam' => 'Fat A', 'Adres' => 'Straat'],
        'serviceLocation' => ['No' => 'SA', 'Naam' => 'Loc A'],
        'hoursStart' => '2026-03-01',
        'hoursEnd' => '2026-04-01',
    ],
];
for ($i = 3; $i <= 1001; $i++) {
    $rows[] = [
        'No' => sprintf('P%04d', $i),
        'Description' => 'Project ' . $i,
        'Your_Reference' => 'REF' . $i,
        'contractor' => ['Naam' => 'Fat ' . $i],
        'hoursStart' => '2026-01-01',
        'hoursEnd' => '2026-06-01',
    ];
}
$rows[] = [
    'No' => 'A-first',
    'Description' => 'duplicate-should-drop',
    'contractor' => ['Naam' => 'Fat duplicate'],
    'hoursStart' => '2026-05-01',
];

write_cache_json($fatPath, $rows, 86400, 'fixture://projects-nightly');
assert_true(is_file($fatPath), 'fixture nightly ontbreekt');
assert_true(!is_file($indexPath), 'index mag nog niet bestaan voor de eerste batch');

$payload = projects_batch_response(0, 1000);
assert_true($payload['ok'] === true, 'batch moet ok zijn');
assert_same('nightly', $payload['source'], 'bron');
assert_same(1001, $payload['total'], 'unieke projecten');
assert_same(1001, $payload['loaded'], 'loaded dekt het totaal');
assert_same(true, $payload['done'], 'skip=0 is klaar in één response');
assert_same(1001, count($payload['rows']), 'top=1000 mag de nightly-lijst niet afkappen');
assert_same(1001, $payload['top'], 'response-top is het aantal teruggegeven rijen');
assert_same('Z-last', $payload['rows'][0]['No'], 'batch sorteert de fixture niet opnieuw');
assert_same('Zed', $payload['rows'][0]['Description'], 'omschrijving van de eerste rij');
assert_same('A-first', $payload['rows'][1]['No'], 'tweede rij blijft de eerste A-first');
assert_same('Aaa', $payload['rows'][1]['Description'], 'duplicaat mag de eerste omschrijving niet overschrijven');

$seen = [];
foreach ($payload['rows'] as $row) {
    $keys = array_keys($row);
    sort($keys);
    assert_same(['Description', 'No'], $keys, 'rij mag alleen No en Description hebben');
    $no = $row['No'];
    assert_true(!isset($seen[$no]), 'dubbele No in de batch: ' . $no);
    $seen[$no] = true;
}
assert_true(!isset($seen['duplicate-should-drop']), 'duplicaat-omschrijving hoort geen rij te zijn');

$encoded = json_encode($payload);
assert_true(is_string($encoded), 'batch JSON');
assert_true(strpos($encoded, 'contractor') === false, 'batch lekt contractor');
assert_true(strpos($encoded, 'hoursStart') === false, 'batch lekt hoursStart');
assert_true(strpos($encoded, 'serviceLocation') === false, 'batch lekt serviceLocation');
assert_true(strpos($encoded, 'LVS_Bill_to_Name') === false, 'batch lekt LVS_Bill_to_Name');

assert_true(is_file($indexPath), 'eerste batch schrijft de slanke index');
$indexRaw = file_get_contents($indexPath);
assert_true(is_string($indexRaw) && $indexRaw !== '', 'indexbestand leeg');
assert_true(strpos($indexRaw, 'contractor') === false, 'indexbestand bevat vette velden');
assert_true(strpos($indexRaw, 'hoursStart') === false, 'indexbestand bevat hoursStart');
assert_true(strlen($indexRaw) < filesize($fatPath), 'index is kleiner dan de vette nightly');

$fat = projects_nightly_read(true);
assert_true($fat['valid'] === true, 'vette nightly blijft leesbaar');
assert_same('A-first', $fat['rows'][0]['No'], 'PDF-pad sorteert nog wel');
assert_same('Z-last', $fat['rows'][count($fat['rows']) - 1]['No'], 'PDF-pad eindigt op Z-last');
$detail = projects_nightly_get('Z-last');
assert_true(is_array($detail), 'detailrij ontbreekt');
assert_same('Fat Z', $detail['contractor']['Naam'] ?? null, 'detail houdt contractor');
assert_same('2026-01-01', $detail['hoursStart'] ?? null, 'detail houdt hoursStart');
assert_same('Aaa', projects_nightly_get('A-first')['Description'] ?? null, 'detail houdt de eerste omschrijving');

$indexMtime = filemtime($indexPath);
assert_true($indexMtime !== false, 'index mtime');
file_put_contents($fatPath, json_encode([
    '_meta' => [
        'cached_at' => time(),
        'expires_at' => time() + 86400,
        'source_url' => 'poison',
    ],
    'data' => [[
        'No' => 'SHOULD-NOT-APPEAR',
        'Description' => 'poison',
        'contractor' => ['Naam' => 'LEAK'],
        'hoursStart' => '1999-01-01',
    ]],
]));
touch($fatPath, $indexMtime - 5);
clearstatcache();

$cached = projects_batch_response(0, 1000);
assert_same(1001, count($cached['rows']), 'oudere vette nightly mag de index niet verdringen');
assert_same('Z-last', $cached['rows'][0]['No'], 'tweede batch blijft de index gebruiken');
assert_same(true, $cached['done'], 'tweede skip=0 blijft één response');
$cachedJson = json_encode($cached);
assert_true(is_string($cachedJson) && strpos($cachedJson, 'SHOULD-NOT-APPEAR') === false, 'tweede batch las de vette nightly opnieuw');
assert_true(strpos($cachedJson, 'LEAK') === false, 'tweede batch lekt het vergiftigde contractor-veld');

$page = projects_batch_response(1, 1);
assert_same(1, count($page['rows']), 'vervolgpagina uit de index');
assert_same('A-first', $page['rows'][0]['No'], 'vervolgpagina is de tweede slanke rij');
assert_same(false, $page['done'], 'een tussenpagina is nog niet klaar');
assert_same(1001, $page['total'], 'totaal blijft het volledige aantal');
assert_same(['No', 'Description'], array_keys($page['rows'][0]), 'vervolgrij is slank');

touch($fatPath, time() + 5);
clearstatcache();
$rebuilt = projects_batch_response(0, 1000);
assert_same(true, $rebuilt['done'], 'nieuwere nightly is weer één response');
assert_same(1, $rebuilt['total'], 'nieuwere nightly vervangt de index');
assert_same('SHOULD-NOT-APPEAR', $rebuilt['rows'][0]['No'], 'herbouwde batch gebruikt de nieuwe nightly');
assert_same(['No', 'Description'], array_keys($rebuilt['rows'][0]), 'herbouwde rij blijft slank');
$rebuiltJson = json_encode($rebuilt);
assert_true(is_string($rebuiltJson) && strpos($rebuiltJson, 'contractor') === false, 'herbouw lekt contractor');
assert_true(strpos($rebuiltJson, 'hoursStart') === false, 'herbouw lekt hoursStart');

echo "OK\n";
