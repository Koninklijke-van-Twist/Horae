<?php

require __DIR__ . '/odata.php';
require __DIR__ . '/grid.php';
require __DIR__ . '/overrides.php';

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function fmtDateNL($ymd): string
{
    if (!$ymd) {
        return '';
    }
    $ts = strtotime($ymd);
    if (!$ts) {
        return h($ymd);
    }
    return date('d-m-Y', $ts);
}

function fmtHours($n): string
{
    $f = (float) $n;
    if (abs($f) < 0.00001) {
        return '';
    }
    return str_replace('.', ',', rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.'));
}

if (\PHP_VERSION_ID >= 80000 && !function_exists('array_find')) {
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
        // Urenstaatregels: Header_Resource_No; Job Planning Lines: No
        $no = trim((string) ($l['Header_Resource_No'] ?? $l['No'] ?? ''));
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
    $resFilter = rawurlencode(implode(' or ', $parts));
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

function pdf_pick_first_non_blank(array $candidates): string
{
    foreach ($candidates as $value) {
        $text = trim((string) $value);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

/** @param list<array<string,mixed>> $maps
 * @return array{value:array<string,string>,conflicts:list<string>}
 */
function pdf_merge_field_maps(array $maps, array $keys): array
{
    $value = [];
    $conflicts = [];
    foreach ($keys as $key) {
        $seen = [];
        $picked = '';
        foreach ($maps as $map) {
            $v = trim((string) ($map[$key] ?? ''));
            if ($v === '') {
                continue;
            }
            if ($picked === '') {
                $picked = $v;
            }
            $seen[$v] = true;
        }
        $value[$key] = $picked;
        if (count($seen) > 1) {
            $conflicts[] = $key;
        }
    }
    return ['value' => $value, 'conflicts' => $conflicts];
}

function pdf_project_meta_from_cache(string $projectNo, string $baseApp, array $auth): array
{
    $cached = projects_nightly_get($projectNo);
    if (is_array($cached)) {
        $contractor = is_array($cached['contractor'] ?? null) ? $cached['contractor'] : [
            'Naam' => (string) ($cached['LVS_Bill_to_Name'] ?? ''),
            'Adres' => '',
            'Postcode' => '',
            'Woonplaats' => '',
        ];
        $service = is_array($cached['serviceLocation'] ?? null) ? $cached['serviceLocation'] : [
            'No' => '',
            'Naam' => '',
            'Adres' => '',
            'Postcode' => '',
            'Woonplaats' => '',
        ];
        return [
            'project' => [
                'No' => $projectNo,
                'Description' => (string) ($cached['Description'] ?? ''),
                'Your_Reference' => (string) ($cached['Your_Reference'] ?? ''),
                'LVS_Bill_to_Name' => (string) ($cached['LVS_Bill_to_Name'] ?? ''),
                'LVS_Main_Entity' => (string) ($cached['LVS_Main_Entity'] ?? ''),
            ],
            'contractor' => [
                'Naam' => (string) ($contractor['Naam'] ?? ''),
                'Adres' => (string) ($contractor['Adres'] ?? ''),
                'Postcode' => (string) ($contractor['Postcode'] ?? ''),
                'Woonplaats' => (string) ($contractor['Woonplaats'] ?? ''),
            ],
            'serviceLocation' => [
                'No' => (string) ($service['No'] ?? ''),
                'Naam' => (string) ($service['Naam'] ?? ''),
                'Adres' => (string) ($service['Adres'] ?? ''),
                'Postcode' => (string) ($service['Postcode'] ?? ''),
                'Woonplaats' => (string) ($service['Woonplaats'] ?? ''),
            ],
            'projectDisplay' => [
                'Opdrachtnummer' => (string) ($cached['Your_Reference'] ?? ''),
                'Project' => (string) ($cached['Description'] ?? ''),
                'Postcode' => (string) ($service['Postcode'] ?? ''),
                'Woonplaats' => (string) ($service['Woonplaats'] ?? ''),
            ],
            'hoursStart' => $cached['hoursStart'] ?? null,
            'hoursEnd' => $cached['hoursEnd'] ?? null,
        ];
    }

    // Fallback live BC als nightly-cache het project mist
    $projFilter = rawurlencode("No eq '" . str_replace("'", "''", $projectNo) . "'");
    $projUrl = $baseApp . "AppProjecten?\$select=No,Your_Reference,LVS_Bill_to_Name,Description,LVS_Main_Entity,LVS_Main_Entity_Description,LVS_Job_Location&\$filter={$projFilter}&\$format=json";
    $project = (odata_get_all($projUrl, $auth)[0] ?? ['No' => $projectNo, 'Description' => '']);
    $mainNo = trim((string) ($project['LVS_Main_Entity'] ?? ''));
    if ($mainNo === '') {
        $mainNo = trim((string) ($project['LVS_Job_Location'] ?? ''));
    }
    $entity = [];
    if ($mainNo !== '') {
        $entFilter = rawurlencode("No eq '" . str_replace("'", "''", $mainNo) . "'");
        $entUrl = $baseApp . "LVS_MainEntityCard?\$select=No,Description,KVT_Customer_Description,KVT_Address,KVT_Address_2,KVT_Post_Code,KVT_City&\$filter={$entFilter}&\$format=json";
        try {
            $entity = odata_get_all($entUrl, $auth)[0] ?? [];
        } catch (Throwable $e) {
            $entity = [];
        }
    }
    $locationsUrl = $baseApp . "JobCard?\$select=Sell_to_Address,No,Sell_to_Post_Code,Sell_to_City&\$filter={$projFilter}&\$format=json";
    $locations = odata_get_all($locationsUrl, $auth)[0] ?? [];
    $serviceName = trim((string) ($entity['KVT_Customer_Description'] ?? ''));
    if ($serviceName === '') {
        $serviceName = trim((string) ($entity['Description'] ?? $project['LVS_Main_Entity_Description'] ?? ''));
    }
    $service = [
        'No' => $mainNo,
        'Naam' => $serviceName,
        'Adres' => trim((string) ($entity['KVT_Address'] ?? '') . ' ' . (string) ($entity['KVT_Address_2'] ?? '')),
        'Postcode' => (string) ($entity['KVT_Post_Code'] ?? ''),
        'Woonplaats' => (string) ($entity['KVT_City'] ?? ''),
    ];
    return [
        'project' => $project,
        'contractor' => [
            'Naam' => (string) ($project['LVS_Bill_to_Name'] ?? ''),
            'Adres' => (string) ($locations['Sell_to_Address'] ?? ''),
            'Postcode' => (string) ($locations['Sell_to_Post_Code'] ?? ''),
            'Woonplaats' => (string) ($locations['Sell_to_City'] ?? ''),
        ],
        'serviceLocation' => $service,
        'projectDisplay' => [
            'Opdrachtnummer' => (string) ($project['Your_Reference'] ?? ''),
            'Project' => (string) ($project['Description'] ?? ''),
            'Postcode' => $service['Postcode'],
            'Woonplaats' => $service['Woonplaats'],
        ],
        'hoursStart' => null,
        'hoursEnd' => null,
        'locations' => $locations,
    ];
}

function pdf_build_report_for_project(string $projectNo, array $projectNos, string $baseApp, array $auth): array
{
    $lines = planning_lines_for_project($projectNo, $baseApp, $auth);
    [$resourcesByNo, $employeesByNo] = pdf_load_resources_for_lines($lines, $baseApp, $auth);
    $grid = build_grid_from_planning_lines($lines, $resourcesByNo, $employeesByNo, [$projectNo]);
    $gridProject = $grid['projects'][$projectNo] ?? [
        'projectNo' => $projectNo,
        'people' => [],
        'multiYear' => false,
        'totals' => ['days' => array_fill(0, 7, 0.0), 'all' => 0.0],
    ];

    $meta = pdf_project_meta_from_cache($projectNo, $baseApp, $auth);

    $weekSlots = [];
    $year = 0;
    foreach ($gridProject['people'] as $person) {
        $personWeek = (int) ($person['week'] ?? 0);
        $personYear = (int) ($person['sortYear'] ?? 0);
        if ($personWeek > 0) {
            $weekSlots[$personYear . '|' . $personWeek] = $personWeek;
        }
        if ($year === 0 && $personYear > 0) {
            $year = $personYear;
        }
    }

    $weekNo = count($weekSlots) === 1 ? (int) reset($weekSlots) : 0;

    // Projectstart/-eind = vroegste / laatste Planning_Date uit Resource-regels
    $dateRange = planning_lines_date_range($lines);
    $startDate = $dateRange['start'] ?? $meta['hoursStart'] ?? null;
    $endDate = $dateRange['end'] ?? $meta['hoursEnd'] ?? null;

    $totals = ['days' => array_fill(0, 7, 0.0), 'all' => 0.0];
    foreach ($gridProject['people'] as $p) {
        for ($i = 0; $i < 7; $i++) {
            $totals['days'][$i] += $p['days'][$i];
            $totals['all'] += $p['days'][$i];
        }
    }

    return [
        'projectNo' => $projectNo,
        'projectNos' => [$projectNo],
        'weekNo' => $weekNo,
        'year' => $year,
        'isHoraeOnly' => false,
        'weekInfo' => [
            'week' => $weekNo,
            'start' => $startDate ?: 'onbekend',
            'end' => $endDate ?: 'onbekend',
        ],
        'contractor' => $meta['contractor'],
        'serviceLocation' => $meta['serviceLocation'],
        'project' => $meta['project'],
        'projectDisplay' => $meta['projectDisplay'],
        'locations' => $meta['locations'] ?? [],
        'gridProject' => $gridProject,
        'totals' => $totals,
        'headerConflicts' => [],
        'signatures' => [
            'hoofdaannemer' => '',
            'onderaannemer' => '',
            'uitvoerder' => '',
        ],
    ];
}

function pdf_build_synthetic_report(string $projectNo, int $weekNo, int $year, array $projectNos, string $baseApp, array $auth): array
{
    // Horae-only week zonder BC-planning: lege grid; overrides vullen rijen later.
    $meta = pdf_project_meta_from_cache($projectNo, $baseApp, $auth);
    $mondayTs = strtotime($year . 'W' . str_pad((string) $weekNo, 2, '0', STR_PAD_LEFT));
    $weekStart = $mondayTs ? date('Y-m-d', $mondayTs) : ($meta['hoursStart'] ?? 'onbekend');
    $weekEnd = $mondayTs ? date('Y-m-d', $mondayTs + 6 * 86400) : ($meta['hoursEnd'] ?? 'onbekend');

    $gridProject = [
        'projectNo' => $projectNo,
        'people' => [],
        'multiYear' => false,
        'totals' => ['days' => array_fill(0, 7, 0.0), 'all' => 0.0],
    ];

    return [
        'projectNo' => $projectNo,
        'projectNos' => [$projectNo],
        'weekNo' => $weekNo,
        'year' => $year,
        'isHoraeOnly' => true,
        'weekInfo' => [
            'week' => $weekNo,
            'start' => $weekStart ?: 'onbekend',
            'end' => $weekEnd ?: 'onbekend',
        ],
        'contractor' => $meta['contractor'],
        'serviceLocation' => $meta['serviceLocation'],
        'project' => $meta['project'],
        'projectDisplay' => $meta['projectDisplay'],
        'locations' => $meta['locations'] ?? [],
        'gridProject' => $gridProject,
        'totals' => ['days' => array_fill(0, 7, 0.0), 'all' => 0.0],
        'headerConflicts' => [],
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

function pdf_resolve_export_ts_no(array $report, array $tsNos): string
{
    $projectNo = (string) ($report['projectNo'] ?? '');
    $weekNo = (int) ($report['weekNo'] ?? 0);
    $year = (int) ($report['year'] ?? 0);

    foreach ($tsNos as $tsNo) {
        if (overrides_parse_synthetic_ts_no($tsNo) !== null) {
            continue;
        }
        if (is_string($tsNo) && $tsNo !== '') {
            return $tsNo;
        }
    }

    // Alleen synthetisch tsNo als er een geldige week is (multi-week planningsrapport: weekNo=0)
    if ($weekNo >= 1 && $weekNo <= 53 && $projectNo !== '') {
        return overrides_synthetic_ts_no($projectNo, $weekNo, $year);
    }

    return '';
}

function pdf_finalize_report(array &$report, string $reportKey, array $tsNos): void
{
    $weekNo = (int) ($report['weekNo'] ?? 0);
    $projectNo = (string) ($report['projectNo'] ?? '');
    $projectNos = $report['projectNos'] ?? [$projectNo];
    if (!is_array($projectNos) || count($projectNos) === 0) {
        $projectNos = [$projectNo];
    }
    $reportYear = (int) ($report['year'] ?? 0);
    $report['isHoraeOnly'] = !empty($report['isHoraeOnly']);
    $report['exportKey'] = $reportKey;
    $report['exportTsNo'] = pdf_resolve_export_ts_no($report, $tsNos);
    $report['projectNos'] = array_values($projectNos);

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
    foreach ($projectNos as $pNo) {
        foreach ($weekSlots as $slot) {
            $overridePayload = overrides_read((string) $pNo, (int) $slot['week'], (int) $slot['year']);
            if (is_array($overridePayload['overrides'] ?? null)) {
                $overrideMap = array_merge($overrideMap, $overridePayload['overrides']);
            }
            if ($reportYear <= 0 && (int) ($overridePayload['year'] ?? 0) > 0) {
                $reportYear = (int) $overridePayload['year'];
                $report['year'] = $reportYear;
            }
        }
    }

    $report['originals'] = overrides_collect_original_values($report);
    $report['originals']['weekInfo.start'] = fmtDateNL($report['weekInfo']['start'] ?? '');
    $report['originals']['weekInfo.end'] = fmtDateNL($report['weekInfo']['end'] ?? '');

    overrides_apply_to_report($report, $overrideMap);
    overrides_apply_row_state($report, $overrideMap);
    pdf_recalc_totals($report);

    $report['overrideKeys'] = array_keys($overrideMap);
}

function pdf_merge_reports(array $reports, array $projectNos): array
{
    if (count($reports) === 0) {
        throw new RuntimeException('Geen rapporten om samen te voegen');
    }
    if (count($reports) === 1) {
        $only = array_values($reports)[0];
        $only['projectNos'] = $projectNos;
        return $only;
    }

    $people = [];
    $multiYear = false;
    $weekNo = 0;
    $year = 0;
    $isHoraeOnly = true;
    $contractorMaps = [];
    $serviceMaps = [];
    $displayMaps = [];
    $starts = [];
    $ends = [];

    foreach ($reports as $report) {
        foreach ($report['gridProject']['people'] ?? [] as $person) {
            $people[] = $person;
        }
        if (!empty($report['gridProject']['multiYear'])) {
            $multiYear = true;
        }
        if ($weekNo === 0) {
            $weekNo = (int) ($report['weekNo'] ?? 0);
        }
        if ($year === 0) {
            $year = (int) ($report['year'] ?? 0);
        }
        if (empty($report['isHoraeOnly'])) {
            $isHoraeOnly = false;
        }
        $contractorMaps[] = $report['contractor'] ?? [];
        $serviceMaps[] = $report['serviceLocation'] ?? [];
        $displayMaps[] = $report['projectDisplay'] ?? [];
        $starts[] = $report['weekInfo']['start'] ?? '';
        $ends[] = $report['weekInfo']['end'] ?? '';
    }

    $contractorMerge = pdf_merge_field_maps($contractorMaps, ['Naam', 'Adres', 'Postcode', 'Woonplaats']);
    $serviceMerge = pdf_merge_field_maps($serviceMaps, ['No', 'Naam', 'Adres', 'Postcode', 'Woonplaats']);
    $displayMerge = pdf_merge_field_maps($displayMaps, ['Opdrachtnummer', 'Project', 'Postcode', 'Woonplaats']);

    // Postcode/woonplaats in projectDisplay komen uit servicelocatie
    $displayMerge['value']['Postcode'] = pdf_pick_first_non_blank([
        $displayMerge['value']['Postcode'] ?? '',
        $serviceMerge['value']['Postcode'] ?? '',
    ]);
    $displayMerge['value']['Woonplaats'] = pdf_pick_first_non_blank([
        $displayMerge['value']['Woonplaats'] ?? '',
        $serviceMerge['value']['Woonplaats'] ?? '',
    ]);

    $conflicts = [];
    foreach ($contractorMerge['conflicts'] as $field) {
        $conflicts[] = 'hoofdaannemer.' . $field;
    }
    foreach ($serviceMerge['conflicts'] as $field) {
        $conflicts[] = 'servicelocatie.' . $field;
    }

    $primary = array_values($reports)[0];
    // Samengevoegd: vroegste startdatum / laatste einddatum over alle projecten
    $start = '';
    $end = '';
    foreach ($starts as $s) {
        $s = trim((string) $s);
        if ($s === '' || $s === 'onbekend') {
            continue;
        }
        if ($start === '' || $s < $start) {
            $start = $s;
        }
    }
    foreach ($ends as $e) {
        $e = trim((string) $e);
        if ($e === '' || $e === 'onbekend') {
            continue;
        }
        if ($end === '' || $e > $end) {
            $end = $e;
        }
    }

    // Alleen conflict markeren als datums echt verschillen (info; we nemen min/max)
    if (count(array_unique(array_filter(array_map('strval', $starts), fn($v) => $v !== '' && $v !== 'onbekend'))) > 1) {
        $conflicts[] = 'weekInfo.start';
    }
    if (count(array_unique(array_filter(array_map('strval', $ends), fn($v) => $v !== '' && $v !== 'onbekend'))) > 1) {
        $conflicts[] = 'weekInfo.end';
    }

    $primaryNo = (string) ($projectNos[0] ?? $primary['projectNo'] ?? '');

    return [
        'projectNo' => $primaryNo,
        'projectNos' => array_values($projectNos),
        'weekNo' => $weekNo,
        'year' => $year,
        'isHoraeOnly' => $isHoraeOnly,
        'weekInfo' => [
            'week' => $weekNo,
            'start' => $start !== '' ? $start : 'onbekend',
            'end' => $end !== '' ? $end : 'onbekend',
        ],
        'contractor' => $contractorMerge['value'],
        'serviceLocation' => $serviceMerge['value'],
        'project' => array_merge($primary['project'] ?? [], [
            'No' => $primaryNo,
            'Description' => $displayMerge['value']['Project'] ?? '',
            'Your_Reference' => $displayMerge['value']['Opdrachtnummer'] ?? '',
        ]),
        'projectDisplay' => $displayMerge['value'],
        'locations' => $primary['locations'] ?? [],
        'gridProject' => [
            'projectNo' => $primaryNo,
            'people' => $people,
            'multiYear' => $multiYear,
            'totals' => ['days' => array_fill(0, 7, 0.0), 'all' => 0.0],
        ],
        'totals' => ['days' => array_fill(0, 7, 0.0), 'all' => 0.0],
        'headerConflicts' => array_values(array_unique($conflicts)),
        'signatures' => $primary['signatures'] ?? [
            'hoofdaannemer' => '',
            'onderaannemer' => '',
            'uitvoerder' => '',
        ],
    ];
}

function pdf_load_reports(string $baseApp, array $auth, array $query): array
{
    $projectNos = $query['projectNo'] ?? [];
    if (!is_array($projectNos)) {
        $projectNos = [$projectNos];
    }
    $projectNos = array_values(array_filter(array_map('trim', $projectNos), fn($x) => $x !== ''));

    if (count($projectNos) === 0) {
        throw new InvalidArgumentException('Geen project geselecteerd');
    }

    // Optioneel: synthetische Horae-weken via tsNo (legacy / overrides)
    $tsNosRaw = $query['tsNo'] ?? [];
    if (!is_array($tsNosRaw)) {
        $tsNosRaw = $tsNosRaw !== '' && $tsNosRaw !== null ? [$tsNosRaw] : [];
    }
    $tsNos = array_values(array_filter($tsNosRaw, fn($v) => is_string($v) && $v !== ''));
    $syntheticRequests = [];
    foreach ($tsNos as $tsNo) {
        $parsed = overrides_parse_synthetic_ts_no($tsNo);
        if ($parsed !== null) {
            $syntheticRequests[] = $parsed;
        }
    }

    $partialReports = [];
    foreach ($projectNos as $projectNo) {
        $partialReports[] = pdf_build_report_for_project($projectNo, $projectNos, $baseApp, $auth);
    }

    foreach ($syntheticRequests as $req) {
        $projectNo = $req['projectNo'];
        $weekNo = $req['weekNo'];
        $year = (int) ($req['year'] ?? 0);
        if (!in_array($projectNo, $projectNos, true)) {
            $projectNos[] = $projectNo;
        }
        $partialReports[] = pdf_build_synthetic_report($projectNo, $weekNo, $year, $projectNos, $baseApp, $auth);
    }

    if (count($partialReports) === 0) {
        throw new RuntimeException('Geen rapport gevonden');
    }

    $merged = pdf_merge_reports($partialReports, $projectNos);
    $exportKey = count($projectNos) > 1
        ? ('merged::' . implode('+', $projectNos))
        : (string) ($projectNos[0] ?? $merged['projectNo'] ?? 'report');

    pdf_finalize_report($merged, $exportKey, $tsNos);
    $merged['selectedTsNos'] = $tsNos;

    return [$exportKey => $merged];
}

function pdf_render_report_html(array $report, bool $exportMode = false): string
{
    $pdfExportMode = $exportMode;
    $weekInfo = $report['weekInfo'];
    $contractor = $report['contractor'];
    $project = $report['project'];
    $locations = $report['locations'] ?? [];
    $gridProject = $report['gridProject'];
    $totals = $report['totals'];
    $projectDisplay = $report['projectDisplay'];
    $serviceLocation = $report['serviceLocation'] ?? [];
    $headerConflicts = $report['headerConflicts'] ?? [];
    $overrideKeys = $report['overrideKeys'];
    $originals = $report['originals'];

    ob_start();
    include __DIR__ . '/templates/timesheet.php';
    return (string) ob_get_clean();
}

function pdf_prepare_export_html(string $html, string $webRoot): string
{
    $webRoot = rtrim(str_replace('\\', '/', (string) realpath($webRoot)), '/');
    $fileRoot = 'file:///' . $webRoot;

    $html = preg_replace(
        '#\ssrc="images/([^"]+)"#',
        ' src="' . $fileRoot . '/images/$1"',
        $html
    ) ?? $html;

    return $html;
}

function pdf_build_export_filename(array $report): string
{
    $projectNos = $report['projectNos'] ?? [($report['projectNo'] ?? 'project')];
    if (!is_array($projectNos) || count($projectNos) === 0) {
        $projectNos = [(string) ($report['projectNo'] ?? 'project')];
    }
    $safeProjects = array_map(
        fn($p) => preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $p),
        $projectNos
    );
    $projectPart = count($safeProjects) > 1
        ? 'MULTI_' . count($safeProjects)
        : (string) $safeProjects[0];
    $weekNo = (int) ($report['weekNo'] ?? 0);
    $year = (int) ($report['year'] ?? 0);
    $parts = ['Mandagenregister', $projectPart];
    if ($year > 0) {
        $parts[] = 'Y' . $year;
    }
    if ($weekNo > 0) {
        $parts[] = 'W' . $weekNo;
    }
    return implode('_', $parts) . '.pdf';
}

function pdf_export_base_url(): string
{
    $envUrl = trim((string) getenv('HORAE_PDF_EXPORT_BASE_URL'));
    if ($envUrl !== '') {
        return rtrim($envUrl, '/');
    }

    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/Horae/web')), '/');
    if ($scriptDir === '') {
        $scriptDir = '/';
    }

    // Interne Chrome-fetch: plain HTTP op poort 80. Niet poort 443 (komt van HTTPS-requests).
    $port = (int) (getenv('HORAE_PDF_EXPORT_PORT') ?: 80);
    if ($port <= 0 || $port === 443) {
        $port = 80;
    }

    return 'http://127.0.0.1' . ($port !== 80 ? ':' . $port : '') . $scriptDir;
}

/** null = file:// (standaard op server, geen Apache/SSL). string = HTTP via pdf_export_view.php */
function pdf_resolve_export_base_url(): ?string
{
    $mode = strtolower(trim((string) getenv('HORAE_PDF_EXPORT_MODE')));
    if ($mode === 'http') {
        return pdf_export_base_url();
    }

    return null;
}

function pdf_inject_base_href(string $html, string $baseHref): string
{
    if (stripos($html, '<base ') !== false) {
        return $html;
    }

    $baseTag = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') . '">';
    $updated = preg_replace('/<head>/i', '<head>' . $baseTag, $html, 1);

    return is_string($updated) ? $updated : $html;
}

function pdf_store_export_html(string $html, string $baseUrl): string
{
    $token = bin2hex(random_bytes(16));
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'horae_export_' . $token . '.html';
    $prepared = pdf_inject_base_href($html, rtrim($baseUrl, '/') . '/');

    if (file_put_contents($path, $prepared) === false) {
        throw new RuntimeException('Export-HTML tijdelijk opslaan mislukt');
    }

    return $token;
}
