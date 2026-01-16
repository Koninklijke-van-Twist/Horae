<?php
function dow_nl_index(string $dateYmd): int
{
    // 0=Ma,1=Di,...6=Zo
    $ts = strtotime($dateYmd);
    $w = (int) date('N', $ts); // 1..7 (Mon..Sun)
    return $w - 1;
}

function build_timesheet_grid_from_fields(array $lines, array $resourcesByNo, array $allowedProjects, array $timesheets): array
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
            $bsn = $res['Social_Security_No'] ?? '';
            $name = $res['Name'] ?? $resourceNo;

            $dayTotals[$line['Job_No']] = array_fill(0, 7, 0.0);

            $days = [];
            for ($i = 1; $i <= 7; $i++) {
                $days[$i - 1] = (float) ($line["Field{$i}"] ?? 0);
            }

            $timesheet = array_find($timesheets, function ($val) use ($timesheetNo) {
                return $val['No'] == $timesheetNo;
            });

            $end = (string) ($timesheet['Ending_Date'] ?? '');
            $year = 0;

            // verwacht YYYY-MM-DD
            if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $end, $m)) {
                $year = (int) $m[1];
            } else {
                $year = 0; // fallback
            }

            if (!isset($peopleByKey[$key])) {
                $peopleByKey[$key] = [
                    'project' => $line['Job_No'],
                    'startDate' => $timesheet['Starting_Date'],
                    'endDate' => $timesheet['Ending_Date'],
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

    // Maak er nu een lijst van om te sorteren (keys hoeven niet mee)
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
        if (!isset($byProject[$person['project']]))
            $byProject[$person['project']] = [
                'projectNo' => $person['project'],
                'people' => [$person],
                'multiYear' => $person['multiYear'],
                'totals' => [
                    'days' => $dayTotals,
                    'all' => array_sum($dayTotals),
                ],
            ];
        else
            array_push($byProject[$person['project']]['people'], $person);

        if ($person['multiYear'])
            $byProject[$person['project']]['multiYear'] = true;
    }

    return [
        'projects' => $byProject,
        'multiYear' => false
    ];
}
