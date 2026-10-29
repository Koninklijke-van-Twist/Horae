<?php
require_once __DIR__ . '/kvt_protect_dirs.php';
require_once __DIR__ . '/entra.php';

/**
 * Privacy: op het register nooit de volledige naam, alleen "Achternaam, V.L.".
 * Gebruik deze helper overal waar een medewerkersnaam getoond of geëxporteerd wordt.
 */
function horae_display_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    $name = preg_replace(['/\s+-\s.*$/u', '/\s*\(.*\)$/u', '/^Inlener\s+/iu'], '', $name);
    $tv = ['van', 'de', 'der', 'den', 'het', "'t", 'ter', 'te', 'ten', 'in', 'op', 'la', 'le', 'du', 'di', 'da', 'von', 'vd', 'v.d.'];
    $isTv = fn($t) => in_array(mb_strtolower($t), $tv, true);
    $isIni = fn($t) => preg_match('/^((\p{L}\.)+|\p{Lu})$/u', $t) === 1;
    $parts = $name === '' ? [] : explode(' ', $name);
    if (strpos($name, ',') !== false) {
        [$last, $first] = array_map('trim', explode(',', $name, 2));
        $parts = $first === '' ? [] : explode(' ', $first);
        $surname = $last === '' ? [] : explode(' ', $last);
        $given = array_filter($parts, fn($t) => !$isTv($t));
        $surname = array_merge($surname, array_filter($parts, $isTv));
    } elseif (count($parts) < 2) {
        return $name;
    } elseif ($ini = array_filter($parts, $isIni)) {
        $given = $ini;
        $surname = array_diff_key($parts, $ini);
    } else {
        $i = count($parts) - 1;
        foreach (array_slice($parts, 1, -1, true) as $k => $t) {
            if ($isTv($t)) {
                $i = $k;
                break;
            }
        }
        $given = array_slice($parts, 0, $i);
        $surname = array_slice($parts, $i);
    }
    $surname = implode(' ', array_merge(array_filter($surname, fn($t) => !$isTv($t)), array_filter($surname, $isTv)));
    $initials = '';
    foreach ($given as $t) {
        $initials .= $isIni($t) ? mb_strtoupper(rtrim($t, '.')) . '.' : mb_strtoupper(mb_substr($t, 0, 1)) . '.';
    }
    return $initials === '' ? $surname : $surname . ', ' . $initials;
}

/** Alleen echte medewerkers (numeriek No, geen LOAD BANKS-groep) krijgen initialen; overige resources ongewijzigd. */
function horae_resource_is_person(string $resourceNo, array $res): bool
{
    return ctype_digit(trim($resourceNo)) && strcasecmp(trim((string) ($res['Resource_Group_No'] ?? '')), 'LOAD BANKS') !== 0;
}

function horae_resource_name(string $resourceNo, array $res): string
{
    $name = trim((string) ($res['Name'] ?? '')) ?: $resourceNo;
    if (!horae_resource_is_person($resourceNo, $res)) {
        return $name;
    }
    $entra = horae_entra_lookup($res);
    return horae_display_name($entra ? $entra['surname'] . ', ' . $entra['givenName'] : $name);
}

/** KM-regels (Work_Type_Code of Unit_of_Measure_Code = KM) tellen niet mee als uren. */
function horae_is_km_line(array $line): bool
{
    return strcasecmp(trim((string) ($line['Work_Type_Code'] ?? '')), 'KM') === 0
        || strcasecmp(trim((string) ($line['Unit_of_Measure_Code'] ?? '')), 'KM') === 0;
}
function dow_nl_index(string $dateYmd): int
{
    // 0=Ma,1=Di,...6=Zo
    $ts = strtotime($dateYmd);
    $w = (int) date('N', $ts); // 1..7 (Mon..Sun)
    return $w - 1;
}

/**
 * Bouwt urengrid vanuit Job Planning Lines (Type=Resource).
 * Planning_Date = dag, Quantity/Amount = uren, No = resource/personeelsnr, Description = naam.
 */
function build_grid_from_planning_lines(array $lines, array $resourcesByNo, array $employeesByNo, array $allowedProjects): array
{
    $peopleByKey = [];
    $dayTotals = [];
    $yearsSeen = [];

    foreach ($lines as $line) {
        $projectNo = trim((string) ($line['Job_No'] ?? ''));
        if ($projectNo === '' || !in_array($projectNo, $allowedProjects, true)) {
            continue;
        }

        $resourceNo = trim((string) ($line['No'] ?? ''));
        if ($resourceNo === '' || horae_is_km_line($line)) {
            continue;
        }

        $date = substr((string) ($line['Planning_Date'] ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            continue;
        }

        $hours = (float) ($line['Quantity'] ?? $line['Amount'] ?? 0);
        if (abs($hours) < 0.00001) {
            continue;
        }

        $ts = strtotime($date . ' 12:00:00');
        if ($ts === false) {
            continue;
        }

        $isoYear = (int) date('o', $ts);
        $isoWeek = (int) date('W', $ts);
        $dow = dow_nl_index($date); // 0=Ma..6=Zo
        $yearsSeen[$isoYear] = true;

        $mondayTs = strtotime($isoYear . 'W' . str_pad((string) $isoWeek, 2, '0', STR_PAD_LEFT));
        $weekStart = $mondayTs ? date('Y-m-d', $mondayTs) : $date;
        $weekEnd = $mondayTs ? date('Y-m-d', $mondayTs + 6 * 86400) : $date;

        $key = $resourceNo . '-Y' . $isoYear . '-W' . $isoWeek . '-' . $projectNo;

        $res = $resourcesByNo[$resourceNo] ?? [];
        $emp = $employeesByNo[$resourceNo] ?? [];
        $bsn = '';
        $name = horae_resource_name($resourceNo, $res);

        if (!isset($dayTotals[$projectNo])) {
            $dayTotals[$projectNo] = array_fill(0, 7, 0.0);
        }

        if (!isset($peopleByKey[$key])) {
            $peopleByKey[$key] = [
                'project' => $projectNo,
                'startDate' => $weekStart,
                'endDate' => $weekEnd,
                'key' => $key,
                'resourceNo' => $resourceNo,
                'bsn' => $bsn,
                'name' => $name,
                'isPerson' => horae_resource_is_person($resourceNo, $res),
                'week' => $isoWeek,
                'days' => array_fill(0, 7, 0.0),
                'total' => 0.0,
                'sortYear' => $isoYear,
                'multiYear' => false,
            ];
        }

        $peopleByKey[$key]['days'][$dow] += $hours;
        $peopleByKey[$key]['total'] += $hours;
        $dayTotals[$projectNo][$dow] += $hours;
    }

    $multiYearReport = count($yearsSeen) > 1;
    if ($multiYearReport) {
        foreach ($peopleByKey as &$person) {
            $person['multiYear'] = true;
        }
        unset($person);
    }

    $people = array_values($peopleByKey);
    usort($people, function ($a, $b) {
        $cmp = $a['sortYear'] <=> $b['sortYear'];
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = $a['week'] <=> $b['week'];
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string) $a['name'], (string) $b['name']);
    });

    $byProject = [];
    foreach ($people as $person) {
        if (!isset($byProject[$person['project']])) {
            $projectDayTotals = $dayTotals[$person['project']] ?? array_fill(0, 7, 0.0);
            $byProject[$person['project']] = [
                'projectNo' => $person['project'],
                'people' => [$person],
                'multiYear' => $person['multiYear'],
                'totals' => [
                    'days' => $projectDayTotals,
                    'all' => array_sum($projectDayTotals),
                ],
            ];
        } else {
            $byProject[$person['project']]['people'][] = $person;
        }

        if ($person['multiYear']) {
            $byProject[$person['project']]['multiYear'] = true;
        }
    }

    return [
        'projects' => $byProject,
        'multiYear' => $multiYearReport,
    ];
}

/** @deprecated Oude urenstaat-pad; behouden voor eventuele fallback */
function build_timesheet_grid_from_fields(array $lines, array $resourcesByNo, array $employeesByNo, array $allowedProjects, array $timesheets): array
{
    $peopleByKey = [];
    $pYear = -1;
    $dayTotals = [];
    foreach ($lines as $line) {
        if (horae_is_km_line($line))
            continue;

        if (in_array($line['Job_No'], $allowedProjects)) {
            $resourceNo = (string) ($line['Header_Resource_No'] ?? '');
            $timesheetNo = (string) ($line['Time_Sheet_No'] ?? '');
            $key = $resourceNo . '-' . $timesheetNo . "-" . $line['Job_No'];

            $res = $resourcesByNo[$resourceNo] ?? [];
            $emp = $employeesByNo[$resourceNo] ?? [];
            $bsn = '';
            $name = horae_resource_name($resourceNo, $res);

            if (!isset($dayTotals[$line['Job_No']])) {
                $dayTotals[$line['Job_No']] = array_fill(0, 7, 0.0);
            }

            $days = [];
            for ($i = 1; $i <= 7; $i++) {
                $days[$i - 1] = (float) ($line["Field{$i}"] ?? 0);
            }

            $timesheet = array_find($timesheets, function ($val) use ($timesheetNo) {
                return $val['No'] == $timesheetNo;
            });

            $end = (string) ($timesheet['Ending_Date'] ?? '');
            $year = 0;

            if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $end, $m)) {
                $year = (int) $m[1];
            } else {
                $year = 0;
            }

            if (!isset($peopleByKey[$key])) {
                $peopleByKey[$key] = [
                    'project' => $line['Job_No'],
                    'startDate' => $timesheet['Starting_Date'] ?? null,
                    'endDate' => $timesheet['Ending_Date'] ?? null,
                    'key' => $key,
                    'resourceNo' => $resourceNo,
                'bsn' => $bsn,
                    'name' => $name,
                    'isPerson' => horae_resource_is_person($resourceNo, $res),
                    'week' => (int) substr((string) ($line['Week'] ?? ''), 4, 5),
                    'days' => array_fill(0, 7, 0.0),
                    'total' => 0.0,
                    'sortYear' => $year,
                    'multiYear' => false
                ];
            }

            if ($pYear < 0) {
                $pYear = $year;
            } else {
                if ($year !== $pYear) {
                    $pYear = $year;
                    $peopleByKey[$key]['multiYear'] = true;
                }
            }

            for ($d = 0; $d < 7; $d++) {
                $peopleByKey[$key]['days'][$d] += $days[$d];
                $dayTotals[$line['Job_No']][$d] += $days[$d];
            }

            $peopleByKey[$key]['total'] += array_sum($days);
        }
    }

    $people = array_values($peopleByKey);

    usort($people, function ($a, $b) {
        $cmp = $a['sortYear'] <=> $b['sortYear'];
        if ($cmp !== 0)
            return $cmp;

        $cmp = $a['week'] <=> $b['week'];
        return $cmp;
    });

    $byProject = [];

    foreach ($people as $person) {
        if (!isset($byProject[$person['project']])) {
            $projectDayTotals = $dayTotals[$person['project']] ?? array_fill(0, 7, 0.0);
            $byProject[$person['project']] = [
                'projectNo' => $person['project'],
                'people' => [$person],
                'multiYear' => $person['multiYear'],
                'totals' => [
                    'days' => $projectDayTotals,
                    'all' => array_sum($projectDayTotals),
                ],
            ];
        } else {
            array_push($byProject[$person['project']]['people'], $person);
        }

        if ($person['multiYear'])
            $byProject[$person['project']]['multiYear'] = true;
    }

    return [
        'projects' => $byProject,
        'multiYear' => false
    ];
}
