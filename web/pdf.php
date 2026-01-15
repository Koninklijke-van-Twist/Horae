<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/grid.php"; // waar build_timesheet_grid_from_fields staat
require __DIR__ . "/auth.php";

$projectNo = $_GET['projectNo'] ?? '';
$tsNos = $_GET['tsNo'] ?? '';
if ($projectNo === '') die("projectNo/tsNo ontbreekt");

// ---- 1) Project ophalen
$baseApp = $base;

$projFilter = rawurlencode("No eq '$projectNo'");
$projUrl = $baseApp . "AppProjecten?\$select=No,LVS_Bill_to_Name,Description,LVS_Job_Location&\$filter={$projFilter}&\$format=json";
$projRows = odata_get_all($projUrl, $auth);
$project = $projRows[0] ?? ['No'=>$projectNo,'Description'=>''];

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
$tsRows = odata_get_all($tsUrl, $auth);
$ts = $tsRows[0] ?? null;
if (!$ts) die("Urenstaat niet gevonden");

// ---- 3) Regels ophalen voor timesheet
$baseRules = "https://kvtmd365.kvt.nl:7148/$environment/ODataV4/Company('Koninklijke%20van%20Twist')/workflowWebhookSubscriptions/";

$lineParts = array_map(
    fn($no) => "Time_Sheet_No eq '" . str_replace("'", "''", $no) . "'",
    $tsNos
);

$lineFilter = rawurlencode(implode(" or ", $lineParts));

$linesUrl = $baseApp . "Urenstaatregels?\$select=Time_Sheet_No,Header_Resource_No,Field1,Field2,Field3,Field4,Field5,Field6,Field7,Total_Quantity&\$filter={$lineFilter}&\$format=json";
$lines = odata_get_all($linesUrl, $auth);

//AppLocations
$locationsUrl = $baseApp . "JobCard?\$select=Sell_to_Address,No,Sell_to_Post_Code,Sell_to_City,Ship_to_City,Ship_to_Post_Code&\$filter={$projFilter}&\$format=json";
$locations = odata_get_all($locationsUrl, $auth)[0];

$weeks = [];

// ---- 4) Resources ophalen (light: alleen benodigde No’s)
$needed = [];
foreach ($lines as &$l) {
    foreach($tsRows as $tsr)
    {
        if($tsr["No"] == $l["Time_Sheet_No"])
        {
            $l["Week"] =  $tsr["Description"];
            array_push($weeks, $tsr["Description"]);
        }
    }

    $no = (string)($l['Header_Resource_No'] ?? '');
    if ($no !== '') $needed[$no] = true;
}
$neededNos = array_keys($needed);

$resourcesByNo = [];
if (count($neededNos) > 0) {
    // OData filter met OR: No eq 'A' or No eq 'B' ...
    // Let op: bij veel resources kan de URL lang worden; dan is "alles ophalen" beter.
    $parts = array_map(fn($n) => "No eq '$n'", $neededNos);
    $resFilter = rawurlencode(implode(" or ", $parts));
    $resUrl = $baseApp . "AppResources?\$select=No,Name,LVS_No_2&\$filter={$resFilter}&\$format=json";
    $resRows = odata_get_all($resUrl, $auth);

    foreach ($resRows as $r) {
        $resourcesByNo[(string)$r['No']] = $r;
    }
}

// ---- 5) Grid bouwen
$grid = build_timesheet_grid_from_fields($lines, $resourcesByNo);

// ---- 6) weekInfo uit header
$weekInfo = [
  'start' => $ts['Starting_Date'] ?? null,
  'end' => $ts['Ending_Date'] ?? null,
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
die;

// ---- 9) PDF render (wkhtmltopdf)
$tmpHtml = tempnam(sys_get_temp_dir(), "ts_") . ".html";
$tmpPdf  = tempnam(sys_get_temp_dir(), "ts_") . ".pdf";
file_put_contents($tmpHtml, $html);

$cmd = "wkhtmltopdf --encoding utf-8 " . escapeshellarg($tmpHtml) . " " . escapeshellarg($tmpPdf);
exec($cmd, $out, $code);

if ($code !== 0 || !file_exists($tmpPdf)) {
    @unlink($tmpHtml);
    throw new Exception("wkhtmltopdf faalde (exit $code). Output:\n" . implode("\n", $out));
}

header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=\"urenstaat_{$projectNo}_{$tsNo}.pdf\"");
readfile($tmpPdf);

@unlink($tmpHtml);
@unlink($tmpPdf);
