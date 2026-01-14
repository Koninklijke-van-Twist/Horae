<?php
function build_timesheet_grid_from_fields(array $lines, array $resourcesByNo): array
{
    // $resourcesByNo["R123"] = ['No'=>..., 'Name'=>..., 'LVS_No_2'=>...]
    $people = [];
    $dayTotals = array_fill(0, 7, 0.0);

    foreach ($lines as $l) {
        $resNo = (string)($l['Header_Resource_No'] ?? '');
        if ($resNo === '') continue;

        $res = $resourcesByNo[$resNo] ?? null;

        $bsn = $res['LVS_No_2'] ?? '';   // BSN/Sofi
        $name = $res['Name'] ?? $resNo;  // fallback

        $f = [];
        for ($i=1; $i<=7; $i++) {
            $f[$i-1] = (float)($l["Field{$i}"] ?? 0);
        }

        if (!isset($people[$resNo])) {
            $people[$resNo] = [
                'bsn' => $bsn,
                'name' => $name,
                'days' => array_fill(0, 7, 0.0),
                'total' => 0.0,
            ];
        }

        // Tel op (voor het geval er meerdere regels per resource zijn)
        for ($d=0; $d<7; $d++) {
            $people[$resNo]['days'][$d] += $f[$d];
            $dayTotals[$d] += $f[$d];
        }
        $people[$resNo]['total'] += array_sum($f);
    }

    $monFri = 0.0;
    for ($i=0; $i<5; $i++) $monFri += $dayTotals[$i];

    return [
        'people' => $people,
        'totals' => [
            'days' => $dayTotals,
            'mon_fri' => $monFri,
            'all' => array_sum($dayTotals),
        ]
    ];
}
