<?php
/**
 * AppProjecten No-filter: nooit een geplakte TSV/rij als projectnummer.
 * Run: php tests/project_no_filter_test.php
 */

$base = "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/";
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$calls = [];
$GLOBALS['HORAE_ODATA_BC_FETCH'] = static function (string $url) use (&$calls): array {
    $calls[] = $url;
    return [];
};

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

$tsv = "Nr.\tOmschrijving\tStatus\nPRJ2602980\tPompinstallatie\tOpen\nPRJ2608376\tTweede project\tOpen";
assert_same(
    ['PRJ2602980', 'PRJ2608376'],
    projects_nos_from_user_input($tsv),
    'TSV met kop Nr./Omschrijving levert alleen projectnummers'
);
assert_same(['PRJ2602980'], projects_nos_from_user_input('PRJ2602980'), 'los projectnummer blijft intact');
assert_same([], projects_nos_from_user_input('Omschrijving'), 'kopwoord is geen projectnummer');
assert_true(!projects_is_project_no($tsv), 'de plak zelf is geen projectnummer');
assert_true(strlen('PRJ2602980') <= 20, 'voorbeeldnummer past in BC Code 20');

$plan = projects_search_plan($tsv);
assert_true($plan !== [], 'plak levert wel filters voor de gevonden nummers');
foreach ($plan as $step) {
    $filter = (string) $step['filter'];
    assert_true(strpos($filter, "\t") === false, 'filter bevat een tab: ' . $filter);
    assert_true(stripos($filter, 'Omschrijving') === false, 'filter bevat kop Omschrijving: ' . $filter);
    assert_true(stripos($filter, 'Nr.') === false, 'filter bevat kop Nr.: ' . $filter);
    if (preg_match("/No eq '([^']*)'/", $filter, $match) === 1 || preg_match("/startswith\\(No,'([^']*)'\\)/", $filter, $match) === 1) {
        assert_true(strlen($match[1]) <= 20, 'No-waarde langer dan 20: ' . $match[1]);
        assert_true(projects_is_project_no($match[1]), 'No-waarde is geen projectnummer: ' . $match[1]);
    }
}
$noFilters = array_values(array_filter($plan, static function (array $step): bool {
    return $step['entity'] === 'AppProjecten' && strpos($step['filter'], "No eq '") === 0;
}));
$joined = implode("\n", array_column($noFilters, 'filter'));
assert_true(strpos($joined, "No eq 'PRJ2602980'") !== false, 'PRJ2602980 wordt exact gezocht');
assert_true(strpos($joined, "No eq 'PRJ2608376'") !== false, 'PRJ2608376 wordt exact gezocht');

$typed = projects_search_plan('PRJ26');
$typedFilters = implode("\n", array_column($typed, 'filter'));
assert_true(strpos($typedFilters, "startswith(No,'PRJ26')") !== false, 'getypt prefix zoekt startswith op No');
assert_true(strpos($typedFilters, "startswith(Description,'PRJ26')") !== false, 'getypte zoekterm zoekt ook omschrijving');

$description = projects_search_plan('nieuwe pompinstallatie op het dek');
foreach ($description as $step) {
    assert_true(strpos($step['filter'], 'No') === false, 'lange omschrijving mag niet op No: ' . $step['filter']);
}

$calls = [];
projects_search_rows($base, $auth, $tsv);
assert_true($calls !== [], 'live zoeken doet OData-calls voor de gevonden nummers');
foreach ($calls as $url) {
    $decoded = rawurldecode($url);
    assert_true(strpos($decoded, "\t") === false, 'URL-filter bevat een tab: ' . $decoded);
    assert_true(stripos($decoded, 'Omschrijving') === false, 'URL-filter bevat Omschrijving: ' . $decoded);
    assert_true(
        stripos($decoded, 'PRJ2602980') !== false || stripos($decoded, 'PRJ2608376') !== false,
        'URL mist projectnummer: ' . $decoded
    );
    if (preg_match("/No eq '([^']*)'/", $decoded, $match) === 1) {
        assert_true(strlen($match[1]) <= 20, 'live No-waarde langer dan 20: ' . $match[1]);
    }
}

$selectBlob = "Nr. | Omschrijving | PRJ2602980 | Pompinstallatie";
assert_same(['PRJ2602980'], projects_nos_from_user_input($selectBlob), 'selectie met pipes levert het projectnummer');

echo "OK\n";
