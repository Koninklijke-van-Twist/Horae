<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";

// 1) Bepaal welke projecten regels hebben (distinct Job_No uit Urenstaatregels)
$rulesUrl = $base . "Urenstaatregels?\$select=Job_No,Work_Type_Code&\$format=json&\$filter=Work_Type_Code%20ne%20'KM'";
$rules = odata_get_all($rulesUrl, $auth);

$projectsWithRules = [];
foreach ($rules as $r) {
  $jno = trim((string) ($r['Job_No'] ?? ''));
  if ($jno !== '')
    $projectsWithRules[$jno] = true;
}

// 2) Haal alle projecten op en filter lokaal
$projUrl = $base . "AppProjecten?\$select=No,Description&\$format=json";
$projects = odata_get_all($projUrl, $auth);

$projects = array_values(array_filter($projects, function ($p) use ($projectsWithRules) {
  $no = (string) ($p['No'] ?? '');
  return $no !== '' && isset($projectsWithRules[$no]);
}));

// (optioneel) sorteer
usort($projects, fn($a, $b) => strcmp((string) $a['No'], (string) $b['No']));
?>
<form method="get" action="weeks.php">
  <label>Project:</label>
  <select name="projectNo" required>
    <?php foreach ($projects as $p): ?>
      <option value="<?= htmlspecialchars($p['No'] ?? '') ?>">
        <?= htmlspecialchars(($p['No'] ?? '') . " - " . ($p['Description'] ?? '')) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button type="submit">Volgende</button>
</form>