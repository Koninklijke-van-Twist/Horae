<?php
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
        if ($resourceNo === '') {
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
        $bsn = $emp['Social_Security_No'] ?? ($res['Social_Security_No'] ?? 'Onbekend');
        $name = trim((string) ($line['Description'] ?? ''));
        if ($name === '') {
            $name = (string) ($res['Name'] ?? $resourceNo);
        }

        if (!isset($dayTotals[$projectNo])) {
            $dayTotals[$projectNo] = array_fill(0, 7, 0.0);
        }

        if (!isset($peopleByKey[$key])) {
            $peopleByKey[$key] = [
                'project' => $projectNo,
                'startDate' => $weekStart,
                'endDate' => $weekEnd,
                'key' => $key,
                'bsn' => $bsn,
                'name' => $name,
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
        if (($line['Work_Type_Code'] ?? '') === "KM")
            continue;

        if (in_array($line['Job_No'], $allowedProjects)) {
            $resourceNo = (string) ($line['Header_Resource_No'] ?? '');
            $timesheetNo = (string) ($line['Time_Sheet_No'] ?? '');
            $key = $resourceNo . '-' . $timesheetNo . "-" . $line['Job_No'];

            $res = $resourcesByNo[$resourceNo] ?? [];
            $emp = $employeesByNo[$resourceNo] ?? [];
            $bsn = $emp['Social_Security_No'] ?? 'Onbekend';
            $name = $res['Name'] ?? $resourceNo;

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
                    'bsn' => $bsn,
                    'name' => $name,
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
