<?php
function dow_nl_index(string $dateYmd): int
{
    // 0=Ma,1=Di,...6=Zo
    $ts = strtotime($dateYmd);
    $w = (int)date('N', $ts); // 1..7 (Mon..Sun)
    return $w - 1;
}

function build_timesheet_grid(array $rows): array
{
    // result:
    // [
    //   'people' => [
    //      '223941694' => ['bsn'=>'..','name'=>'..','days'=>[0=>..,1=>..,..], 'total'=>..],
    //   ],
    //   'totals' => ['days'=>[0=>..], 'mon_fri'=>.., 'all'=>..]
    // ]
    $people = [];
    $dayTotals = array_fill(0, 7, 0.0);

    foreach ($rows as $r) {
        $bsn  = (string)($r['Bsn'] ?? $r['BSN'] ?? $r['Sofi'] ?? '');     // <-- aanpassen
        $name = (string)($r['EmployeeName'] ?? $r['Name'] ?? '');         // <-- aanpassen
        $date = (string)($r['WorkDate'] ?? $r['Date'] ?? '');             // <-- aanpassen
        $hrs  = (float)($r['Hours'] ?? $r['Quantity'] ?? 0);              // <-- aanpassen

        if ($bsn === '' || $date === '') continue;

        $d = dow_nl_index($date);

        if (!isset($people[$bsn])) {
            $people[$bsn] = [
                'bsn' => $bsn,
                'name' => $name,
                'days' => array_fill(0, 7, 0.0),
                'total' => 0.0,
            ];
        }

        $people[$bsn]['days'][$d] += $hrs;
        $people[$bsn]['total']    += $hrs;
        $dayTotals[$d]            += $hrs;
    }

    // totalen
    $monFri = 0.0;
    for ($i = 0; $i < 5; $i++) $monFri += $dayTotals[$i];

    return [
        'people' => $people,
        'totals' => [
            'days' => $dayTotals,
            'mon_fri' => $monFri,
            'all' => array_sum($dayTotals),
        ]
    ];
}

function build_timesheet_grid_from_fields(array $lines, array $resourcesByNo): array
{
    $people = [];
    $dayTotals = array_fill(0, 7, 0.0); // Ma..Zo

    foreach ($lines as $line) {
        $resourceNo = (string)($line['Header_Resource_No'] ?? '');
        if ($resourceNo === '') {
            continue;
        }

        // Resource lookup
        $res = $resourcesByNo[$resourceNo] ?? null;

        $bsn  = $res['LVS_No_2'] ?? '';
        $name = $res['Name'] ?? $resourceNo;

        // Field1..Field7 = Ma..Zo
        $days = [];
        for ($i = 1; $i <= 7; $i++) {
            $days[$i - 1] = (float)($line["Field{$i}"] ?? 0);
        }

        // Init werknemer
        if (!isset($people[$resourceNo])) {
            $people[$resourceNo] = [
                'bsn'   => $bsn,
                'name'  => $name,
                'days'  => array_fill(0, 7, 0.0),
                'total' => 0.0,
            ];
        }

        // Optellen
        for ($d = 0; $d < 7; $d++) {
            $people[$resourceNo]['days'][$d] += $days[$d];
            $dayTotals[$d] += $days[$d];
        }

        $people[$resourceNo]['total'] += array_sum($days);
    }

    // Ma–Vr totaal
    $monFri = 0.0;
    for ($i = 0; $i < 5; $i++) {
        $monFri += $dayTotals[$i];
    }

    return [
        'people' => $people,
        'totals' => [
            'days'    => $dayTotals,
            'mon_fri' => $monFri,
            'all'     => array_sum($dayTotals),
        ],
    ];
}