<?php
/**
 * Startdatum zonder BC-week: ISO-week en meenemen van override-only weken.
 * Run: php tests/override_week_test.php
 */

require dirname(__DIR__) . '/web/pdf_common.php';

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

$cases = [
    '2026-03-16' => ['weekNo' => 12, 'year' => 2026],
    '16-03-2026' => ['weekNo' => 12, 'year' => 2026],
    '16/03/2026' => ['weekNo' => 12, 'year' => 2026],
    '30-12-2024' => ['weekNo' => 1, 'year' => 2025],
    '01-01-2021' => ['weekNo' => 53, 'year' => 2020],
];
foreach ($cases as $input => $expected) {
    assert_same($expected, overrides_iso_week_from_date($input), 'ISO-week voor ' . $input);
}
if (overrides_iso_week_from_date('onbekend') !== null || overrides_iso_week_from_date('31-02-2026') !== null) {
    fail('ongeldige datum mag geen week opleveren');
}

$project = 'ZZTEST86B2';
$other = 'ZZOTHER86B2';
$listed = [
    ['projectNo' => $project, 'year' => 2026, 'week' => 12, 'tsNo' => 'HORAE-' . $project . '-Y2026-W12'],
    ['projectNo' => $project, 'year' => 2025, 'week' => 1, 'tsNo' => 'HORAE-' . $project . '-Y2025-W1'],
    ['projectNo' => $other, 'year' => 2026, 'week' => 12, 'tsNo' => 'HORAE-' . $other . '-Y2026-W12'],
    ['projectNo' => $project, 'year' => 0, 'week' => 9, 'tsNo' => 'HORAE-' . $project . '-W9'],
];
$extra = pdf_extra_override_week_requests(
    [$project],
    [['projectNo' => $project, 'weekNo' => 12, 'year' => 2026]],
    $listed,
    [['projectNo' => $project, 'year' => 2026, 'week' => 9]]
);
$extraKeys = array_map(static fn(array $row): string => $row['projectNo'] . '|' . $row['year'] . '|' . $row['weekNo'], $extra);
sort($extraKeys);
assert_same([$project . '|2025|1'], $extraKeys, 'alleen de ontbrekende override-week');

$shell = [
    'projectNo' => $project,
    'weekNo' => 0,
    'year' => 0,
    'isHoraeOnly' => false,
    'weekInfo' => ['week' => 0, 'start' => 'onbekend', 'end' => 'onbekend'],
    'contractor' => ['Naam' => 'Hoofd'],
    'serviceLocation' => [],
    'projectDisplay' => ['Project' => 'Leeg'],
    'project' => ['No' => $project],
    'gridProject' => ['people' => []],
    'signatures' => [],
];
$horae = [
    'projectNo' => $project,
    'weekNo' => 12,
    'year' => 2026,
    'isHoraeOnly' => true,
    'weekInfo' => ['week' => 12, 'start' => '2026-03-16', 'end' => '2026-03-22'],
    'contractor' => ['Naam' => 'Hoofd'],
    'serviceLocation' => [],
    'projectDisplay' => ['Project' => 'Leeg'],
    'project' => ['No' => $project],
    'gridProject' => ['people' => []],
    'signatures' => [],
];
$merged = pdf_merge_reports([$shell, $horae], [$project]);
assert_same(12, (int) $merged['weekNo'], 'samengevoegde week');
assert_same(true, (bool) $merged['isHoraeOnly'], 'lege planning mag Horae-week niet verbergen');
assert_same('2026-03-16', (string) $merged['weekInfo']['start'], 'startdatum van de Horae-week');
$slotKeys = array_map(
    static fn(array $slot): string => $slot['projectNo'] . '|' . $slot['year'] . '|' . $slot['week'],
    $merged['overrideWeekSlots'] ?? []
);
assert_same([$project . '|2026|12'], $slotKeys, 'weekslot voor overrides');

$onlyShell = pdf_merge_reports([$shell, $shell], [$project]);
assert_same(false, (bool) $onlyShell['isHoraeOnly'], 'alleen lege rapporten zijn geen Horae-week');

$bc = $horae;
$bc['weekNo'] = 10;
$bc['year'] = 2026;
$bc['isHoraeOnly'] = false;
$bc['gridProject'] = ['people' => [['week' => 10, 'sortYear' => 2026, 'project' => $project]]];
$withBc = pdf_merge_reports([$bc, $horae], [$project]);
assert_same(false, (bool) $withBc['isHoraeOnly'], 'BC-week blijft leidend');
$withBcKeys = array_map(
    static fn(array $slot): string => $slot['year'] . '|' . $slot['week'],
    $withBc['overrideWeekSlots'] ?? []
);
sort($withBcKeys);
assert_same(['2026|10', '2026|12'], $withBcKeys, 'BC-week en Horae-week blijven beide beschikbaar');

$dir = dirname(__DIR__) . '/web/cache/overrides';
$written = [];
try {
    $payload = overrides_default_payload($project, 12, null, 2026);
    $payload['overrides'] = ['weekInfo.start' => '16-03-2026'];
    overrides_write($payload);
    $written[] = overrides_file_path($project, 12, 2026);
    $fromDisk = overrides_list_for_projects([$project]);
    $found = false;
    foreach ($fromDisk as $item) {
        if (($item['projectNo'] ?? '') === $project && (int) ($item['week'] ?? 0) === 12 && (int) ($item['year'] ?? 0) === 2026) {
            $found = true;
            assert_same('HORAE-' . $project . '-Y2026-W12', (string) ($item['tsNo'] ?? ''), 'synthetisch tsNo');
        }
    }
    if (!$found) {
        fail('overrides_list_for_projects ziet de week niet');
    }
    $fromDiskExtra = pdf_extra_override_week_requests([$project], [], $fromDisk, []);
    if (count($fromDiskExtra) !== 1 || $fromDiskExtra[0]['tsNo'] !== 'HORAE-' . $project . '-Y2026-W12') {
        fail('schijfweek wordt niet automatisch meegenomen: ' . json_encode($fromDiskExtra));
    }
} finally {
    foreach ($written as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

echo "OK\n";
