<?php
require __DIR__ . '/../web/pdf_common.php';

function check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function rejects(callable $call): void {
    try { $call(); } catch (RuntimeException | InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected rejection');
}
$config = array_fill_keys(['TENANT_ID', 'CLIENT_ID', 'CLIENT_SECRET', 'SITE_ID', 'LIST_ID'], 'test');
$config += ['EMPLOYEE_FIELD' => 'EmployeeID', 'BSN_FIELD' => 'BSN'];
$calls = [];
$http = function ($url, $headers, $form = null) use (&$calls) {
    $calls[] = [$url, $headers, $form];
    if ($form !== null) { return ['access_token' => 'test-token']; }
    return ['value' => [['fields' => ['EmployeeID' => "00'12", 'BSN' => '000000001']]]];
};
check(bsn_lookup([], $config, $http) === [] && !$calls, 'Empty selection must not contact Graph');
check(bsn_lookup(["00'12", "00'12"], $config, $http) === ["00'12" => '000000001'], 'Preserve text identifiers and leading zeros');
check(count($calls) === 2, 'One token and one request per distinct resource');
parse_str(parse_url($calls[1][0], PHP_URL_QUERY), $query);
check($query['$filter'] === "fields/EmployeeID eq '00''12'", 'Escape OData quotes');
check($query['$expand'] === 'fields($select=EmployeeID,BSN)', 'Request only needed fields');
check($calls[0][2]['scope'] === 'https://graph.microsoft.com/.default', 'Graph scope');
foreach ([
    ['value' => [[], []]],
    ['value' => [], '@odata.nextLink' => 'https://example.invalid'],
    ['value' => [['fields' => ['EmployeeID' => 'other', 'BSN' => '000000001']]]],
    ['value' => [['fields' => ['EmployeeID' => 'A', 'BSN' => 123456789]]]],
    ['value' => [['fields' => ['EmployeeID' => 'A', 'BSN' => '<script>']]]],
    ['error' => 'invalid'],
] as $response) {
    rejects(fn() => bsn_lookup(['A'], $config, fn($u, $h, $f = null) => $f !== null ? ['access_token' => 'test'] : $response));
}
check(bsn_lookup(['missing'], $config, fn($u, $h, $f = null) => $f !== null ? ['access_token' => 'test'] : ['value' => []]) === ['missing' => 'Onbekend'], 'Missing employee stays unknown');

$grid = build_grid_from_planning_lines([
    ['Job_No' => 'P', 'No' => 'A-01', 'Planning_Date' => '2026-09-07', 'Quantity' => 8],
    ['Job_No' => 'P', 'No' => 'not-used', 'Planning_Date' => '2026-09-07', 'Quantity' => 0],
], [], [], ['P']);
$people = $grid['projects']['P']['people'];
check(count($people) === 1 && $people[0]['resourceNo'] === 'A-01', 'Keep explicit Resource.No, including hyphens');
$report = ['gridProject' => ['people' => array_merge($people, [
    $people[0],
    ['key' => 'deleted', 'resourceNo' => 'B', 'isDeleted' => true, 'bsn' => 'stale'],
    ['key' => 'manual', 'isAdded' => true, 'resourceNo' => 'SHOULD-NOT', 'bsn' => 'stale'],
])]];
bsn_enrich_report($report, function ($nos) {
    check($nos === ['A-01'], 'Do not fetch deleted, manual, zero-hour or duplicate employees');
    return ['A-01' => '000000001'];
});
check(array_column($report['gridProject']['people'], 'bsn') === ['000000001', '000000001', '', 'Onbekend'], 'Enrich only matched rows');
check(!preg_grep('/\.bsn$/', array_keys(overrides_collect_original_values($report))), 'No BSNs in originals');
rejects(fn() => overrides_set_value('P', 37, 'people.test.bsn', '000000001', 2026));
rejects(fn() => overrides_set_value('P', 37, 'people.test.resourceNo', 'B', 2026));
$key = $people[0]['key'];
overrides_apply_key($report, 'people.' . $key . '.resourceNo', 'B');
overrides_apply_key($report, 'people.' . $key . '.bsn', 'bad');
check($report['gridProject']['people'][0]['resourceNo'] === 'A-01' && $report['gridProject']['people'][0]['bsn'] === '000000001', 'Legacy overrides cannot alter identity or BSN');

$report += ['weekInfo' => ['week' => 37], 'contractor' => [], 'project' => ['No' => 'P'],
    'totals' => ['days' => array_fill(0, 7, 8), 'all' => 56], 'projectDisplay' => [],
    'overrideKeys' => [], 'originals' => [], 'projectNo' => 'P'];
$report['gridProject']['multiYear'] = false;
// Only complete grid rows are needed for template tests.
$report['gridProject']['people'] = [$report['gridProject']['people'][0]];
foreach ([in_array('--export', $argv, true)] as $export) {
    $html = pdf_render_report_html($report, $export);
    check(str_contains($html, '<span class="bsn-value">000000001</span>'), 'BSN appears in page and export HTML');
    check(!str_contains($html, 'data-override-key="people.' . $key . '.bsn"'), 'BSN is read-only');
    check(str_contains($html, 'assets/bsn-privacy.js') === !$export, 'Privacy script only on interactive page');
}
$addedProject = 'ZZBSN2943';
$addedWeek = 41;
$addedYear = 2026;
try {
    $added = overrides_row_add($addedProject, $addedWeek, $addedYear);
    $addedKey = $added['personKey'];
    check(str_starts_with($addedKey, 'horae-add-'), 'Added row uses a Horae key');
    rejects(fn() => overrides_set_value($addedProject, $addedWeek, 'people.horae-add-deadbeef.bsn', '111111111', $addedYear));
    rejects(fn() => overrides_set_value($addedProject, $addedWeek, 'people.' . $addedKey . '.bsn', '123', $addedYear));
    rejects(fn() => overrides_set_value($addedProject, $addedWeek, 'people.' . $addedKey . '.resourceNo', 'B', $addedYear));
    overrides_set_value($addedProject, $addedWeek, 'people.' . $addedKey . '.bsn', '000000001', $addedYear);
    $stored = overrides_read($addedProject, $addedWeek, $addedYear);
    check(($stored['overrides']['people.' . $addedKey . '.bsn'] ?? '') === '000000001', 'Manual BSN keeps leading zeros');
    overrides_set_value($addedProject, $addedWeek, 'people.' . $addedKey . '.bsn', 'Onbekend', $addedYear);
    $cleared = overrides_read($addedProject, $addedWeek, $addedYear);
    check(!isset($cleared['overrides']['people.' . $addedKey . '.bsn']), 'Onbekend clears a manual BSN');
    overrides_set_value($addedProject, $addedWeek, 'people.' . $addedKey . '.bsn', '111111111', $addedYear);

    $manualReport = [
        'projectNo' => $addedProject,
        'projectNos' => [$addedProject],
        'weekNo' => $addedWeek,
        'year' => $addedYear,
        'isHoraeOnly' => true,
        'weekInfo' => ['week' => $addedWeek, 'start' => '2026-10-05', 'end' => '2026-10-11'],
        'contractor' => [],
        'serviceLocation' => [],
        'project' => ['No' => $addedProject],
        'projectDisplay' => [],
        'locations' => [],
        'gridProject' => ['people' => [], 'multiYear' => false],
        'totals' => ['days' => array_fill(0, 7, 0.0), 'all' => 0.0],
        'signatures' => ['hoofdaannemer' => '', 'onderaannemer' => '', 'uitvoerder' => ''],
        'headerConflicts' => [],
        'documentStatus' => '',
    ];
    pdf_finalize_report($manualReport, $addedProject, ['HORAE-' . $addedProject . '-Y' . $addedYear . '-W' . $addedWeek]);
    bsn_enrich_report($manualReport, function ($nos) {
        check($nos === [], 'Manual BSN must not contact Graph');
        return [];
    });
    $manualPeople = $manualReport['gridProject']['people'];
    check(count($manualPeople) === 1 && $manualPeople[0]['bsn'] === '111111111' && !empty($manualPeople[0]['isAdded']), 'Finalize keeps manual BSN on an added row');
    check(!preg_grep('/\.bsn$/', array_keys($manualReport['originals'])), 'Manual BSN stays out of originals');
    $manualExport = in_array('--export', $argv ?? [], true);
    $manualHtml = pdf_render_report_html($manualReport, $manualExport);
    check(str_contains($manualHtml, '<span class="bsn-value">111111111</span>'), 'Manual BSN is shown');
    if ($manualExport) {
        check(!str_contains($manualHtml, 'data-override-key="people.' . $addedKey . '.bsn"'), 'Export does not edit BSN');
    } else {
        check(preg_match('/data-override-key="people\.' . preg_quote($addedKey, '/') . '\.bsn"[^>]*data-original="Onbekend"/', $manualHtml) === 1, 'Added row BSN is editable and resets to Onbekend');
        check(str_contains($manualHtml, 'data-field-type="bsn"'), 'BSN cell is marked for the editor');
    }

    $foreign = overrides_default_payload($addedProject, 42, null, $addedYear);
    $foreign['overrides'] = [
        'rows.added' => json_encode([$addedKey]),
        'people.' . $addedKey . '.bsn' => '222222222',
        'people.bc-row.bsn' => '333333333',
        'people.' . $addedKey . '.resourceNo' => 'NOPE',
        'people.' . $addedKey . '.name' => 'Test',
    ];
    overrides_write($foreign);
    $foreignRead = overrides_read($addedProject, 42, $addedYear);
    check(($foreignRead['overrides']['people.' . $addedKey . '.bsn'] ?? '') === '222222222', 'Valid added BSN survives write');
    check(!isset($foreignRead['overrides']['people.bc-row.bsn']), 'BC BSN stripped on write');
    check(!isset($foreignRead['overrides']['people.' . $addedKey . '.resourceNo']), 'resourceNo stripped on write');
    check(($foreignRead['overrides']['people.' . $addedKey . '.name'] ?? '') === 'Test', 'Other override fields kept');
} finally {
    overrides_delete_week($addedProject, $addedWeek, $addedYear);
    overrides_delete_week($addedProject, 42, $addedYear);
}

$token = pdf_store_export_html('<html><head></head><body>test</body></html>', 'http://127.0.0.1');
$path = sys_get_temp_dir() . '/horae_export_' . $token . '.html';
try {
    check((fileperms($path) & 0777) === 0600, 'Temporary export HTML is owner-only');
} finally { unlink($path); }
echo "BSN tests passed\n";
