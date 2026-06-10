<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/grid.php";
require __DIR__ . "/overrides.php";
require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function fmtDateNL($ymd): string
{
    if (!$ymd)
        return '';
    $ts = strtotime($ymd);
    if (!$ts)
        return h($ymd);
    return date('d-m-Y', $ts);
}

function fmtHours($n): string
{
    $f = (float) $n;
    if (abs($f) < 0.00001)
        return '';
    return str_replace('.', ',', rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.'));
}

if (\PHP_VERSION_ID >= 80000 && !function_exists("array_find")) {
    function array_find(array $array, callable $callback): mixed
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return null;
    }
}

function pdf_extract_week_no(array $timesheet): int
{
    $desc = (string) ($timesheet['Description'] ?? '');
    if (preg_match('/\bWeek\s*(\d+)\b/i', $desc, $m)) {
        return (int) $m[1];
    }
    return 0;
}

function pdf_build_people_from_project_lines(array $lines, array $resourcesByNo, array $employeesByNo, string $projectNo, int $weekNo, int $year = 0): array
{
    $seen = [];
    $people = [];

    foreach ($lines as $line) {
        if (($line['Work_Type_Code'] ?? '') === 'KM') {
            continue;
        }
        if ((string) ($line['Job_No'] ?? '') !== $projectNo) {
            continue;
        }

        $resourceNo = (string) ($line['Header_Resource_No'] ?? '');
        if ($resourceNo === '' || isset($seen[$resourceNo])) {
            continue;
        }
        $seen[$resourceNo] = true;

        $res = $resourcesByNo[$resourceNo] ?? [];
        $emp = $employeesByNo[$resourceNo] ?? [];
        $syntheticTsNo = overrides_synthetic_ts_no($projectNo, $weekNo, $year);

        $people[] = [
            'project' => $projectNo,
            'startDate' => null,
            'endDate' => null,
            'key' => $resourceNo . '-' . $syntheticTsNo . '-' . $projectNo,
            'bsn' => $emp['Social_Security_No'] ?? 'Onbekend',
            'name' => $res['Name'] ?? $resourceNo,
            'week' => $weekNo,
            'days' => array_fill(0, 7, 0.0),
            'total' => 0.0,
            'sortYear' => $year > 0 ? $year : (int) date('Y'),
            'multiYear' => false,
        ];
    }

    usort($people, fn($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
    return $people;
}

function pdf_load_resources_for_lines(array $lines, string $baseApp, array $auth): array
{
    $needed = [];
    foreach ($lines as $l) {
        $no = (string) ($l['Header_Resource_No'] ?? '');
        if ($no !== '') {
            $needed[$no] = true;
        }
    }

    $neededNos = array_keys($needed);
    $resourcesByNo = [];
    $employeesByNo = [];

    if (count($neededNos) === 0) {
        return [$resourcesByNo, $employeesByNo];
    }

    $parts = array_map(fn($n) => "No eq '$n'", $neededNos);
    $resFilter = rawurlencode(implode(" or ", $parts));
    $resUrl = $baseApp . "AppResource?\$select=No,Name,LVS_No_2,Social_Security_No&\$filter={$resFilter}&\$format=json";
    $resRows = odata_get_all($resUrl, $auth);

    foreach ($resRows as $r) {
        $resourcesByNo[(string) $r['No']] = $r;
    }

    $empUrl = $baseApp . "Werknemer?\$select=No,Resource_No,Social_Security_No&\$filter={$resFilter}&\$format=json";
    $empRows = odata_get_all($empUrl, $auth);

    foreach ($empRows as $e) {
        $employeesByNo[(string) ($e['Resource_No'] ?? $e['No'] ?? '')] = $e;
    }

    return [$resourcesByNo, $employeesByNo];
}

function pdf_build_report_for_project(string $projectNo, array $tsRows, array $lines, array $projectNos, string $baseApp, array $auth): array
{
    foreach ($lines as &$l) {
        foreach ($tsRows as $tsr) {
            if ($tsr["No"] == $l["Time_Sheet_No"]) {
                $l["Week"] = $tsr["Description"];
            }
        }
    }
    unset($l);

    [$resourcesByNo, $employeesByNo] = pdf_load_resources_for_lines($lines, $baseApp, $auth);
    $grid = build_timesheet_grid_from_fields($lines, $resourcesByNo, $employeesByNo, $projectNos, $tsRows);
    $gridProject = $grid['projects'][$projectNo] ?? [
        'projectNo' => $projectNo,
        'people' => [],
        'multiYear' => false,
        'totals' => ['days' => array_fill(0, 7, 0.0), 'all' => 0.0],
    ];

    $projFilter = rawurlencode("No eq '" . str_replace("'", "''", $projectNo) . "'");
    $projUrl = $baseApp . "AppProjecten?\$select=No,Your_Reference,LVS_Bill_to_Name,Description,LVS_Job_Location,LVS_Document_Status&\$filter={$projFilter}&\$format=json";
    $projRows = odata_get_all($projUrl, $auth);
    $project = $projRows[0] ?? ['No' => $projectNo, 'Description' => ''];

    $locationsUrl = $baseApp . "JobCard?\$select=Sell_to_Address,No,Sell_to_Post_Code,Sell_to_City,Ship_to_City,Ship_to_Post_Code&\$filter={$projFilter}&\$format=json";
    $locationsRows = odata_get_all($locationsUrl, $auth);
    $locations = $locationsRows[0] ?? [];

    $startDate = "onbekend";
    $endDate = "onbekend";
    $weekNo = 0;

    $wL = 999999999999999999;
    $wH = -1;
    foreach ($gridProject['people'] as $person) {
        if ($weekNo === 0) {
            $weekNo = (int) ($person['week'] ?? 0);
        }
        $week = $person['week'] + (52 * $person['sortYear']);
        if ($week > $wH) {
            $wH = $week;
            $endDate = $person['endDate'];
        }
        if ($week < $wL) {
            $wL = $week;
            $startDate = $person['startDate'];
        }
    }

    if ($weekNo === 0 && count($tsRows) > 0) {
        $weekNo = pdf_extract_week_no($tsRows[0]);
    }

    $contractor = [
        'Naam' => $project['LVS_Bill_to_Name'] ?? '',
        'Adres' => $locations['Sell_to_Address'] ?? '',
        'Postcode' => $locations['Sell_to_Post_Code'] ?? '',
        'Woonplaats' => $locations['Sell_to_City'] ?? '',
    ];

    $projectDisplay = [
        'Opdrachtnummer' => $project['Your_Reference'] ?? '',
        'Project' => $project['Description'] ?? '',
        'Postcode' => $locations['Ship_to_Post_Code'] ?? '',
        'Woonplaats' => $locations['Ship_to_City'] ?? '',
    ];

    $totals = ['days' => array_fill(0, 7, 0.0), 'all' => 0.0];
    foreach ($gridProject['people'] as $p) {
        for ($i = 0; $i < 7; $i++) {
            $totals['days'][$i] += $p['days'][$i];
            $totals['all'] += $p['days'][$i];
        }
    }

    return [
        'projectNo' => $projectNo,
        'weekNo' => $weekNo,
        'documentStatus' => (string) ($project['LVS_Document_Status'] ?? ''),
        'weekInfo' => [
            'week' => $weekNo,
            'start' => $startDate,
            'end' => $endDate,
        ],
        'contractor' => $contractor,
        'project' => $project,
        'projectDisplay' => $projectDisplay,
        'locations' => $locations,
        'gridProject' => $gridProject,
        'totals' => $totals,
        'signatures' => [
            'hoofdaannemer' => '',
            'onderaannemer' => '',
            'uitvoerder' => '',
        ],
    ];
}

function pdf_build_synthetic_report(string $projectNo, int $weekNo, int $year, array $projectNos, string $baseApp, array $auth): array
{
    $syntheticTsNo = overrides_synthetic_ts_no($projectNo, $weekNo, $year);
    $fakeTs = [
        'No' => $syntheticTsNo,
        'Description' => 'Week ' . $weekNo,
        'Starting_Date' => null,
        'Ending_Date' => null,
    ];

    $rulesFilter = rawurlencode("Job_No eq '" . str_replace("'", "''", $projectNo) . "' and Work_Type_Code ne 'KM'");
    $linesUrl = $baseApp . "Urenstaatregels?\$select=Time_Sheet_No,Job_No,Work_Type_Code,Header_Resource_No,Field1,Field2,Field3,Field4,Field5,Field6,Field7,Total_Quantity&\$filter={$rulesFilter}&\$format=json";
    $lines = odata_get_all($linesUrl, $auth, 60);

    [$resourcesByNo, $employeesByNo] = pdf_load_resources_for_lines($lines, $baseApp, $auth);
    $people = pdf_build_people_from_project_lines($lines, $resourcesByNo, $employeesByNo, $projectNo, $weekNo, $year);

    $gridProject = [
        'projectNo' => $projectNo,
        'people' => $people,
        'multiYear' => false,
        'totals' => ['days' => array_fill(0, 7, 0.0), 'all' => 0.0],
    ];

    $projFilter = rawurlencode("No eq '" . str_replace("'", "''", $projectNo) . "'");
    $projUrl = $baseApp . "AppProjecten?\$select=No,Your_Reference,LVS_Bill_to_Name,Description,LVS_Job_Location,LVS_Document_Status&\$filter={$projFilter}&\$format=json";
    $projRows = odata_get_all($projUrl, $auth);
    $project = $projRows[0] ?? ['No' => $projectNo, 'Description' => ''];

    $locationsUrl = $baseApp . "JobCard?\$select=Sell_to_Address,No,Sell_to_Post_Code,Sell_to_City,Ship_to_City,Ship_to_Post_Code&\$filter={$projFilter}&\$format=json";
    $locationsRows = odata_get_all($locationsUrl, $auth);
    $locations = $locationsRows[0] ?? [];

    $contractor = [
        'Naam' => $project['LVS_Bill_to_Name'] ?? '',
        'Adres' => $locations['Sell_to_Address'] ?? '',
        'Postcode' => $locations['Sell_to_Post_Code'] ?? '',
        'Woonplaats' => $locations['Sell_to_City'] ?? '',
    ];

    $projectDisplay = [
        'Opdrachtnummer' => $project['Your_Reference'] ?? '',
        'Project' => $project['Description'] ?? '',
        'Postcode' => $locations['Ship_to_Post_Code'] ?? '',
        'Woonplaats' => $locations['Ship_to_City'] ?? '',
    ];

    return [
        'projectNo' => $projectNo,
        'weekNo' => $weekNo,
        'year' => $year,
        'isHoraeOnly' => true,
        'documentStatus' => (string) ($project['LVS_Document_Status'] ?? ''),
        'weekInfo' => [
            'week' => $weekNo,
            'start' => 'onbekend',
            'end' => 'onbekend',
        ],
        'contractor' => $contractor,
        'project' => $project,
        'projectDisplay' => $projectDisplay,
        'locations' => $locations,
        'gridProject' => $gridProject,
        'totals' => ['days' => array_fill(0, 7, 0.0), 'all' => 0.0],
        'signatures' => [
            'hoofdaannemer' => '',
            'onderaannemer' => '',
            'uitvoerder' => '',
        ],
    ];
}

function pdf_recalc_totals(array &$report): void
{
    $totals = ['days' => array_fill(0, 7, 0.0), 'all' => 0.0];
    foreach ($report['gridProject']['people'] ?? [] as $person) {
        if (!empty($person['isDeleted'])) {
            continue;
        }
        for ($i = 0; $i < 7; $i++) {
            $totals['days'][$i] += (float) ($person['days'][$i] ?? 0);
            $totals['all'] += (float) ($person['days'][$i] ?? 0);
        }
    }
    $report['totals'] = $totals;
}

function pdf_reapply_total_overrides(array &$report, array $overrideMap): void
{
    foreach ($overrideMap as $key => $value) {
        if (str_starts_with((string) $key, 'totals.')) {
            overrides_apply_key($report, (string) $key, (string) $value);
        }
    }
}

$tsNos = $_GET['tsNo'] ?? '';
$baseApp = $base;

$projectNos = $_GET['projectNo'] ?? [];
if (!is_array($projectNos))
    $projectNos = [$projectNos];
$projectNos = array_values(array_filter(array_map('trim', $projectNos), fn($x) => $x !== ''));

if (!is_array($tsNos) || count($tsNos) === 0) {
    die("Geen weken geselecteerd");
}

$tsNos = array_values(array_filter($tsNos, fn($v) => is_string($v) && $v !== ''));

$syntheticRequests = [];
$bcTsNos = [];

foreach ($tsNos as $tsNo) {
    $parsed = overrides_parse_synthetic_ts_no($tsNo);
    if ($parsed !== null) {
        $syntheticRequests[] = $parsed;
    } else {
        $bcTsNos[] = $tsNo;
    }
}

$tsRows = [];
$lines = [];

if (count($bcTsNos) > 0) {
    $parts = array_map(
        fn($no) => "No eq '" . str_replace("'", "''", $no) . "'",
        $bcTsNos
    );
    $tsFilter = rawurlencode(implode(" or ", $parts));
    $tsUrl = $baseApp . "Urenstaten?\$select=No,Starting_Date,Ending_Date,Description,Resource_No,Resource_Name,Job_No_Filter&\$filter={$tsFilter}&\$format=json";
    $tsRows = odata_get_all($tsUrl, $auth, 60);

    $lineParts = array_map(
        fn($no) => "Time_Sheet_No eq '" . str_replace("'", "''", $no) . "'",
        $bcTsNos
    );
    $lineFilter = rawurlencode(implode(" or ", $lineParts));
    $linesUrl = $baseApp . "Urenstaatregels?\$select=Time_Sheet_No,Job_No,Work_Type_Code,Header_Resource_No,Field1,Field2,Field3,Field4,Field5,Field6,Field7,Total_Quantity&\$filter={$lineFilter}&\$format=json";
    $lines = odata_get_all($linesUrl, $auth, 60);
}

$reportsByProject = [];

foreach ($projectNos as $projectNo) {
    $projectTsRows = array_values(array_filter($tsRows, function ($row) use ($projectNo, $lines) {
        $no = (string) ($row['No'] ?? '');
        foreach ($lines as $line) {
            if ((string) ($line['Time_Sheet_No'] ?? '') === $no && (string) ($line['Job_No'] ?? '') === $projectNo) {
                return true;
            }
        }
        return (string) ($row['Job_No_Filter'] ?? '') === $projectNo;
    }));

    if (count($projectTsRows) > 0 || count(array_filter($lines, fn($l) => ($l['Job_No'] ?? '') === $projectNo)) > 0) {
        $reportsByProject[$projectNo] = pdf_build_report_for_project($projectNo, $projectTsRows, $lines, $projectNos, $baseApp, $auth);
    }
}

foreach ($syntheticRequests as $req) {
    $projectNo = $req['projectNo'];
    $weekNo = $req['weekNo'];
    $year = (int) ($req['year'] ?? 0);
    if (!in_array($projectNo, $projectNos, true)) {
        $projectNos[] = $projectNo;
    }
    $reportKey = $projectNo . '::Y' . $year . '::W' . $weekNo;
    $reportsByProject[$reportKey] = pdf_build_synthetic_report($projectNo, $weekNo, $year, $projectNos, $baseApp, $auth);
}

if (count($reportsByProject) === 0 && count($bcTsNos) > 0) {
    die("Urenstaat niet gevonden");
}

foreach ($reportsByProject as $reportKey => &$report) {
    $weekNo = (int) ($report['weekNo'] ?? 0);
    $projectNo = (string) ($report['projectNo'] ?? '');
    $reportYear = (int) ($report['year'] ?? 0);
    $report['isHoraeOnly'] = !empty($report['isHoraeOnly']);

    $weekSlots = [];
    if ($weekNo > 0) {
        $weekSlots[$reportYear . '|' . $weekNo] = ['year' => $reportYear, 'week' => $weekNo];
    }
    foreach ($report['gridProject']['people'] ?? [] as $person) {
        $personWeek = (int) ($person['week'] ?? 0);
        $personYear = (int) ($person['sortYear'] ?? $reportYear);
        if ($personWeek > 0) {
            $weekSlots[$personYear . '|' . $personWeek] = ['year' => $personYear, 'week' => $personWeek];
        }
    }

    $overrideMap = [];
    foreach ($weekSlots as $slot) {
        $overridePayload = overrides_read($projectNo, (int) $slot['week'], (int) $slot['year']);
        if (is_array($overridePayload['overrides'] ?? null)) {
            $overrideMap = array_merge($overrideMap, $overridePayload['overrides']);
        }
        if ($reportYear <= 0 && (int) ($overridePayload['year'] ?? 0) > 0) {
            $reportYear = (int) $overridePayload['year'];
            $report['year'] = $reportYear;
        }
    }

    $report['originals'] = overrides_collect_original_values($report);
    $report['originals']['weekInfo.start'] = fmtDateNL($report['weekInfo']['start'] ?? '');
    $report['originals']['weekInfo.end'] = fmtDateNL($report['weekInfo']['end'] ?? '');

    overrides_apply_to_report($report, $overrideMap);
    overrides_apply_row_state($report, $overrideMap);
    pdf_recalc_totals($report);

    $report['overrideKeys'] = array_keys($overrideMap);
    $overrideSet = array_fill_keys($report['overrideKeys'], true);

    $weekInfo = $report['weekInfo'];
    $contractor = $report['contractor'];
    $project = $report['project'];
    $locations = $report['locations'];
    $gridProject = $report['gridProject'];
    $totals = $report['totals'];
    $documentStatus = $report['documentStatus'];
    $projectDisplay = $report['projectDisplay'];

    ob_start();
    include __DIR__ . "/templates/timesheet.php";
    $html = ob_get_clean();

    echo $html;
}
unset($report);
