<?php
/**
 * Privacy (alleen achternaam + voorletters) en KM-uitsluiting in het Mandagenregister.
 * Run: php tests/display_name_km_test.php
 */

require dirname(__DIR__) . '/web/grid.php';

function check($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: $message expected " . json_encode($expected) . ' got ' . json_encode($actual) . "\n");
        exit(1);
    }
}

$names = [
    "Holwerda, Dirk" => "Holwerda, D.",
    "Holwerda, D.H.K." => "Holwerda, D.H.K.",
    "Holwerda" => "Holwerda",
    "Dirk Holwerda" => "Holwerda, D.",
    "Dirk Hendrik Klaas Holwerda" => "Holwerda, D.H.K.",
    "Jan van Dijk" => "Dijk van, J.",
    "Jan Piet van Dijk" => "Dijk van, J.P.",
    "Xavi de Jong" => "Jong de, X.",
    "Michael van der Pijl" => "Pijl van der, M.",
    "Noordermeer J.C." => "Noordermeer, J.C.",
    "Prenter de R.A.M." => "Prenter de, R.A.M.",
    "Herk H. van" => "Herk van, H.",
    "Linden van der M." => "Linden van der, M.",
    "Hubregtse M." => "Hubregtse, M.",
    "Prenter, Ronald de" => "Prenter de, R.",
    "Sant, Bob van 't" => "Sant van 't, B.",
    "Veld, Erwin in 't" => "Veld in 't, E.",
    "Linden, Mark van der" => "Linden van der, M.",
    "Mierlo, Ricardo van" => "Mierlo van, R.",
    "Rooij, Rob van" => "Rooij van, R.",
    "Donker A.L.B. - NIET GEBRUIKEN" => "Donker, A.L.B.",
    "Versteeg A. - extern" => "Versteeg, A.",
    "Wiel, E.J. van der - NHMR" => "Wiel van der, E.J.",
    "Engelenburg S. (foutief, zie 1225)" => "Engelenburg, S.",
    "Inlener M. de Glopper" => "Glopper de, M.",
    "Jamie Bellaard" => "Bellaard, J.",
    "E. Iems" => "Iems, E.",
    "T. Schouwenburg" => "Schouwenburg, T.",
    "van Koppel H.A." => "Koppel van, H.A.",
    "den Ridder C.J.M." => "Ridder den, C.J.M.",
    "Wolst M" => "Wolst, M.",
    "Driesprong" => "Driesprong",
];
foreach ($names as $in => $out) {
    check($out, horae_display_name($in), 'naam ' . $in);
}
check('Load Bank 500 kW', horae_resource_name('LB500', ['Name' => 'Load Bank 500 kW', 'Resource_Group_No' => 'LOAD BANKS']), 'loadbank ongewijzigd');
check('Kachel Jan', horae_resource_name('4001', ['Name' => 'Kachel Jan', 'Resource_Group_No' => 'load banks ']), 'LOAD BANKS-groep ongewijzigd');
check('Noordermeer, J.C.', horae_resource_name('1606', ['Name' => 'Noordermeer J.C.', 'Resource_Group_No' => '']), 'medewerker opgemaakt');
check('1606', horae_resource_name('1606', []), 'zonder naam het No');

$line = fn(string $wt, float $q) => ['Job_No' => 'P1', 'No' => '1606', 'Description' => 'RTS, Urenregel', 'Planning_Date' => '2026-10-12', 'Quantity' => $q, 'Work_Type_Code' => $wt];
$grid = build_grid_from_planning_lines([$line('', 8.0), $line('KM', 59.72), $line('km', 1.0)], ['1606' => ['Name' => 'Holwerda, Dirk']], [], ['P1']);
$p = $grid['projects']['P1'];
check(8.0, $p['people'][0]['total'], 'KM telt niet mee in rijtotaal');
check(8.0, $p['totals']['all'], 'KM telt niet mee in totaal');
check('Holwerda, D.', $p['people'][0]['name'], 'grid toont alleen voorletters');

$tpl = file_get_contents(dirname(__DIR__) . '/web/templates/timesheet.php');
check(1, preg_match("/horae_display_name\\(/", $tpl), 'template gebruikt horae_display_name');

$uom = fn(string $wt, string $u, float $q) => $line($wt, $q) + ['Unit_of_Measure_Code' => $u];
$grid = build_grid_from_planning_lines([$uom('', 'KM', 62.0), $uom('', ' km ', 3.0), $uom('SNT', 'HR', 5.0)], [], [], ['P1']);
check(5.0, $grid['projects']['P1']['totals']['all'], 'UoM KM met lege werksoort telt niet mee');

check('9999', build_grid_from_planning_lines([['No' => '9999', 'Description' => 'RTS, U'] + $line('', 1.0)], [], [], ['P1'])['projects']['P1']['people'][0]['name'], 'zonder AppResource het No, nooit Description');
$grid = build_grid_from_planning_lines([$uom('KM', 'KM', 62.0), $uom('SOT125', 'HR', 0.71667), $uom('SNT', 'HR', 5.0)], [], [], ['P1']);
check(5.72, round($grid['projects']['P1']['people'][0]['total'], 2), 'Holwerda 12-10: 5,72 uur');

echo "OK display_name_km_test\n";
