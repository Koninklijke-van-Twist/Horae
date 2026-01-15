<?php
function dow_nl_index(string $dateYmd): int
{
    // 0=Ma,1=Di,...6=Zo
    $ts = strtotime($dateYmd);
    $w = (int) date('N', $ts); // 1..7 (Mon..Sun)
    return $w - 1;
}

function build_timesheet_grid_from_fields(array $lines, array $resourcesByNo): array
{
    $people = [];
    $dayTotals = array_fill(0, 7, 0.0); // Ma..Zo

    $i = 0;
    foreach ($lines as $line) {
        if ($line['Work_Type_Code'] == "KM") {
            continue;
        }
        $i++;
        $resourceNo = (string) ($line['Header_Resource_No'] ?? '');

        // Resource lookup
        $res = $resourcesByNo[$resourceNo] ?? null;

        $bsn = $res['Social_Security_No'] ?? '';
        $name = $res['Name'] ?? $resourceNo;

        // Field1..Field7 = Ma..Zo
        $days = [];
        for ($i = 1; $i <= 7; $i++) {
            $days[$i - 1] = (float) ($line["Field{$i}"] ?? 0);
        }

        // Init werknemer
        $person = [
            'bsn' => $bsn,
            'name' => $name,
            'week' => substr((string) ($line['Week'] ?? ''), 4, 5),
            'days' => array_fill(0, 7, 0.0),
            'total' => 0.0,
            'workType' => $line['Work_Type_Code'] ?? '', //test
        ];

        // Optellen
        for ($d = 0; $d < 7; $d++) {
            $person['days'][$d] += $days[$d];
            $dayTotals[$d] += $days[$d];
        }

        $person['total'] += array_sum($days);

        array_push($people, $person);

        usort($people, function ($a, $b) {
            $cmp = $a['week'] <=> $b['week'];
            if ($cmp !== 0) return $cmp;

            return strcmp($a['name'], $b['name']);
        });


    }

    // Ma–Vr totaal
    $monFri = 0.0;
    for ($i = 0; $i < 5; $i++) {
        $monFri += $dayTotals[$i];
    }

    return [
        'people' => $people,
        'totals' => [
            'days' => $dayTotals,
            'mon_fri' => $monFri,
            'all' => array_sum($dayTotals),
        ],
    ];
}