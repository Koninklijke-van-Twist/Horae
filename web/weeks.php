<?php
/**
 * Weekselectie is vervallen: uren komen uit Job Planning Lines.
 * Oude links met projectNo[] worden doorgestuurd naar het rapport.
 */
require __DIR__ . '/auth.php';
require __DIR__ . '/logincheck.php';

$projectNos = $_GET['projectNo'] ?? [];
if (!is_array($projectNos)) {
    $projectNos = [$projectNos];
}
$projectNos = array_values(array_filter(array_map('trim', $projectNos), fn($x) => $x !== ''));

if (count($projectNos) === 0) {
    header('Location: index.php');
    exit;
}

$query = http_build_query(['projectNo' => $projectNos]);
header('Location: pdf.php?' . $query);
exit;
