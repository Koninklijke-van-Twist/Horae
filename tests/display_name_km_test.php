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

check('Holwerda, D.', horae_display_name('Holwerda, Dirk'), 'achternaam, voornaam');
check('Holwerda, D.H.K.', horae_display_name('Dirk Hendrik Klaas Holwerda'), 'voornamen achternaam');
check('Holwerda, D.H.K.', horae_display_name('Holwerda, D.H.K.'), 'al ingekort blijft gelijk');
check('Holwerda', horae_display_name('Holwerda'), 'alleen achternaam');

$line = fn(string $wt, float $q) => ['Job_No' => 'P1', 'No' => 'R1', 'Description' => 'Holwerda, Dirk', 'Planning_Date' => '2026-10-12', 'Quantity' => $q, 'Work_Type_Code' => $wt];
$grid = build_grid_from_planning_lines([$line('', 8.0), $line('KM', 59.72), $line('km', 1.0)], [], [], ['P1']);
$p = $grid['projects']['P1'];
check(8.0, $p['people'][0]['total'], 'KM telt niet mee in rijtotaal');
check(8.0, $p['totals']['all'], 'KM telt niet mee in totaal');
check('Holwerda, D.', $p['people'][0]['name'], 'grid toont alleen voorletters');

$tpl = file_get_contents(dirname(__DIR__) . '/web/templates/timesheet.php');
check(1, preg_match("/\\\$name = horae_display_name\\(/", $tpl), 'template gebruikt horae_display_name');

echo "OK display_name_km_test\n";
