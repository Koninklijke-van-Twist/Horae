<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/grid.php"; // waar build_timesheet_grid_from_fields staat
require __DIR__ . "/auth.php";

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
    // toon 0 als leeg; anders met komma
    $f = (float) $n;
    if (abs($f) < 0.00001)
        return '';
    // maximaal 2 decimalen, met komma
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

$tsNos = $_GET['tsNo'] ?? '';

// ---- 1) Project ophalen
$baseApp = $base;

$projectNos = $_GET['projectNo'] ?? [];
if (!is_array($projectNos))
    $projectNos = [$projectNos];
$projectNos = array_values(array_filter(array_map('trim', $projectNos), fn($x) => $x !== ''));

// ---- 2) Timesheet header ophalen
if (!is_array($tsNos) || count($tsNos) === 0) {
    die("Geen weken geselecteerd");
}

// basic sanitization (No is Edm.String)
$tsNos = array_values(array_filter($tsNos, fn($v) => is_string($v) && $v !== ''));
$parts = array_map(
    fn($no) => "No eq '" . str_replace("'", "''", $no) . "'",
    $tsNos
);

$tsFilter = rawurlencode(implode(" or ", $parts));
$tsUrl = $baseApp . "Urenstaten?\$select=No,Starting_Date,Ending_Date,Description,Resource_No,Resource_Name,Job_No_Filter&\$filter={$tsFilter}&\$format=json";
$tsRows = odata_get_all($tsUrl, $auth, 60);
$ts = $tsRows[0] ?? null;
if (!$ts)
    die("Urenstaat niet gevonden");

// ---- 3) Regels ophalen voor timesheet
$baseRules = "https://kvtmd365.kvt.nl:7148/$environment/ODataV4/Company('Koninklijke%20van%20Twist')/workflowWebhookSubscriptions/";

$lineParts = array_map(
    fn($no) => "Time_Sheet_No eq '" . str_replace("'", "''", $no) . "'",
    $tsNos
);

$lineFilter = rawurlencode(implode(" or ", $lineParts));

$linesUrl = $baseApp . "Urenstaatregels?\$select=Time_Sheet_No,Job_No,Work_Type_Code,Header_Resource_No,Field1,Field2,Field3,Field4,Field5,Field6,Field7,Total_Quantity&\$filter={$lineFilter}&\$format=json";
$lines = odata_get_all($linesUrl, $auth, 60);

//AppLocations


$weeks = [];

// ---- 4) Resources ophalen (light: alleen benodigde No’s)
$needed = [];
foreach ($lines as &$l) {
    foreach ($tsRows as $tsr) {
        if ($tsr["No"] == $l["Time_Sheet_No"]) {
            $l["Week"] = $tsr["Description"];
            array_push($weeks, $tsr["Description"]);
        }
    }

    $no = (string) ($l['Header_Resource_No'] ?? '');
    if ($no !== '')
        $needed[$no] = true;
}
$neededNos = array_keys($needed);

$resourcesByNo = [];
if (count($neededNos) > 0) {
    // OData filter met OR: No eq 'A' or No eq 'B' ...
    // Let op: bij veel resources kan de URL lang worden; dan is "alles ophalen" beter.
    $parts = array_map(fn($n) => "No eq '$n'", $neededNos);
    $resFilter = rawurlencode(implode(" or ", $parts));
    $resUrl = $baseApp . "AppResource?\$select=No,Name,LVS_No_2,Social_Security_No&\$filter={$resFilter}&\$format=json";
    $resRows = odata_get_all($resUrl, $auth);

    foreach ($resRows as $r) {
        $resourcesByNo[(string) $r['No']] = $r;
    }
}

// ---- 5) Grid bouwen
$grid = build_timesheet_grid_from_fields($lines, $resourcesByNo, $projectNos, $tsRows);

foreach ($grid['projects'] as $gridProject) {

    $projectNo = $gridProject['projectNo'] ?? '';
    $projFilter = rawurlencode("No eq '$projectNo'");
    $projUrl = $baseApp . "AppProjecten?\$select=No,Your_Reference,LVS_Bill_to_Name,Description,LVS_Job_Location&\$filter={$projFilter}&\$format=json";
    $projRows = odata_get_all($projUrl, $auth);
    $project = $projRows[0] ?? ['No' => $projectNo, 'Description' => ''];

    $locationsUrl = $baseApp . "JobCard?\$select=Sell_to_Address,No,Sell_to_Post_Code,Sell_to_City,Ship_to_City,Ship_to_Post_Code&\$filter={$projFilter}&\$format=json";
    $locations = odata_get_all($locationsUrl, $auth)[0];

    $startDate = "onbekend";
    $endDate = "onbekend";

    $wL = 999999999999999999;
    $wH = -1;
    foreach ($gridProject['people'] as $person) {
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

    // ---- 6) weekInfo uit header
    $weekInfo = [
        'start' => $startDate,
        'end' => $endDate,
    ];

    // ---- 7) contractor placeholders (later invullen uit project)
    $contractor = [
        'Naam' => $project['LVS_Bill_to_Name'] ?? '', // voorbeeld, als je dat wil
        'Adres' => $locations['Sell_to_Address'],
        'Postcode' => $locations['Sell_to_Post_Code'],
        'Woonplaats' => $locations['Sell_to_City'],
    ];

    // ---- 8) HTML render

    ob_start();
    include __DIR__ . "/templates/timesheet.php";
    $html = ob_get_clean();

    echo $html;
}