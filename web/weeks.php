<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";

$projectNo = $_GET['projectNo'] ?? '';
if ($projectNo === '') die("projectNo ontbreekt");

// 1) Urenstaten binnen dit project (headers)
$filter = rawurlencode("Job_No_Filter eq '$projectNo'");
$url = $base . "Urenstaten?\$select=No,Starting_Date,Ending_Date,Description,Job_No_Filter&\$filter={$filter}&\$format=json";
$timesheets = odata_get_all($url, $auth);

// 2) Welke Time_Sheet_No's hebben regels binnen dit project?
$rulesFilter = rawurlencode("Job_No eq '$projectNo'");
$rulesUrl = $base . "Urenstaatregels?\$select=Time_Sheet_No&\$filter={$rulesFilter}&\$format=json";
$rules = odata_get_all($rulesUrl, $auth);

$hasRulesForTs = [];
foreach ($rules as $r) {
    $tsNo = (string)($r['Time_Sheet_No'] ?? '');
    if ($tsNo !== '') $hasRulesForTs[$tsNo] = true;
}

// 3) Filter urenstaten zonder regels weg
$timesheets = array_values(array_filter($timesheets, function($t) use ($hasRulesForTs) {
    $no = (string)($t['No'] ?? '');
    return $no !== '' && isset($hasRulesForTs[$no]);
}));

// 4) Weeknummer uit Description
$items = [];
foreach ($timesheets as $t) {
    $desc = $t['Description'] ?? '';
    if (preg_match('/\bWeek\s*(\d+)\b/i', $desc, $m)) {
        $w = (int)$m[1];
        $items[] = [
            'week' => $w,
            'tsNo' => $t['No'],
            'start' => $t['Starting_Date'] ?? null,
            'end' => $t['Ending_Date'] ?? null,
            'desc' => $desc,
        ];
    }
}

usort($items, fn($a,$b) => $a['week'] <=> $b['week']);
?>
<form method="get" action="pdf.php">
  <input type="hidden" name="projectNo" value="<?= htmlspecialchars($projectNo) ?>">

  <div style="margin-bottom:8px;">
    <b>Week(en):</b><br>
    <small>Vink één of meerdere weken aan.</small>
  </div>

  <div style="border:1px solid #ccc; padding:10px; max-width:650px;">
    <?php foreach ($items as $it): ?>
      <label style="display:block; margin:6px 0;">
        <input type="checkbox" name="tsNo[]" value="<?= htmlspecialchars($it['tsNo']) ?>">
        Week <?= (int)$it['week'] ?>
        (<?= htmlspecialchars($it['start'] ?? '') ?> – <?= htmlspecialchars($it['end'] ?? '') ?>)
      </label>
    <?php endforeach; ?>
  </div>

  <div style="margin-top:10px;">
    <button type="button" onclick="toggleAll(true)">Alles selecteren</button>
    <button type="button" onclick="toggleAll(false)">Alles deselecteren</button>
  </div>

  <div style="margin-top:10px;">
    <button type="submit">Download PDF</button>
  </div>
</form>

<script>
function toggleAll(on) {
  document.querySelectorAll('input[type="checkbox"][name="tsNo[]"]').forEach(cb => cb.checked = on);
}
</script>
