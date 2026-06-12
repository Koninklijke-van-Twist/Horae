<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/overrides.php";
require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";

$projectNos = $_GET['projectNo'] ?? [];
if (!is_array($projectNos))
  $projectNos = [$projectNos];
$projectNos = array_values(array_filter(array_map('trim', $projectNos), fn($x) => $x !== ''));

if (count($projectNos) === 0)
  die("projectNo ontbreekt");

// helper: OData OR filter maken voor string field
function odata_or_filter(string $field, array $values): string
{
  $parts = array_map(function ($v) use ($field) {
    $v = str_replace("'", "''", $v);
    return "$field eq '$v'";
  }, $values);
  return rawurlencode(implode(" or ", $parts));
}

// 1) Urenstaten binnen deze projecten (headers)
$tsFilter = odata_or_filter("Job_No_Filter", $projectNos);
$url = $base . "Urenstaten?\$select=No,Starting_Date,Ending_Date,Description,Job_No_Filter&\$filter={$tsFilter}&\$format=json";
$timesheets = odata_get_all($url, $auth);

// 2) Welke Time_Sheet_No's hebben regels binnen deze projecten?
$rulesFilter = odata_or_filter("Job_No", $projectNos);
// Work_Type_Code ne 'KM' erachter (niet encoden, want filter is al encoded -> dus combineren vóór rawurlencode)
$rulesFilterDecoded = implode(" or ", array_map(fn($p) => "Job_No eq '" . str_replace("'", "''", $p) . "'", $projectNos));
$rulesFilterDecoded = "(" . $rulesFilterDecoded . ") and Work_Type_Code ne 'KM'";
$rulesFilter = rawurlencode($rulesFilterDecoded);

$rulesUrl = $base . "Urenstaatregels?\$select=Time_Sheet_No,Job_No&\$filter={$rulesFilter}&\$format=json";
$rules = [];

try {
  $rules = odata_get_all($rulesUrl, $auth);
} catch (\Throwable $th) {
  //throw $th;
}

$hasRulesForTs = [];
$tsToProject = []; // Time_Sheet_No -> Job_No (handig voor later)
foreach ($rules as $k => $r) {
  $tsNo = (string) ($r['Time_Sheet_No'] ?? '');
  $jno = (string) ($r['Job_No'] ?? '');

  if (!in_array($jno, $projectNos)) {
    unset($rules[$k]);
    continue;
  }
  if ($tsNo !== '') {
    $hasRulesForTs[$tsNo] = true;
    if ($jno !== '' && !isset($tsToProject[$tsNo]))
      $tsToProject[$tsNo] = $jno;
  }
}

// 3) Filter urenstaten zonder regels weg
$timesheets = array_values(array_filter($timesheets, function ($t) use ($hasRulesForTs) {
  $no = (string) ($t['No'] ?? '');
  return $no !== '' && isset($hasRulesForTs[$no]);
}));

// 4) Weeknummer uit Description
$items = [];
foreach ($timesheets as $t) {
  $desc = $t['Description'] ?? '';
  if (preg_match('/\bWeek\s*(\d+)\b/i', $desc, $m)) {
    $w = (int) $m[1];
    $tsNo = (string) ($t['No'] ?? '');
    $end = (string) ($t['Ending_Date'] ?? '');
    $year = 0;
    if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $end, $m)) {
      $year = (int) $m[1];
    }
    $items[] = [
      'week' => $w,
      'year' => $year,
      'tsNo' => $tsNo,
      'start' => $t['Starting_Date'] ?? null,
      'end' => $end,
      'desc' => $desc,
      'projectNo' => (string) ($tsToProject[$tsNo] ?? ''),
    ];
  }
}

$overrideItems = overrides_list_for_projects($projectNos);
$existingKeys = [];
foreach ($items as $it) {
  $existingKeys[(string) ($it['projectNo'] ?? '') . '|' . (int) ($it['year'] ?? 0) . '|' . (int) ($it['week'] ?? 0)] = true;
}

foreach ($overrideItems as $overrideItem) {
  $key = (string) ($overrideItem['projectNo'] ?? '') . '|' . (int) ($overrideItem['year'] ?? 0) . '|' . (int) ($overrideItem['week'] ?? 0);
  if (isset($existingKeys[$key])) {
    continue;
  }
  $items[] = $overrideItem;
}

// Groepeer op jaar (uit einddatum) en sorteer per jaar week desc
$groups = [];

foreach ($items as $it) {
  $year = (int) ($it['year'] ?? 0);
  if ($year === 0) {
    $end = (string) ($it['end'] ?? '');
    if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $end, $m)) {
      $year = (int) $m[1];
    }
  }

  $groups[$year][] = $it;
}

// Jaar-groepen sorteren: 2026, 2025, ...
krsort($groups);

// Binnen elke groep: weeknummer hoog->laag
foreach ($groups as $year => &$list) {
  usort($list, fn($a, $b) => ((int) $b['week']) <=> ((int) $a['week']));
}
unset($list);

usort($items, fn($a, $b) => ($a['week'] <=> $b['week']) ?: strcmp($a['projectNo'], $b['projectNo']));
?>
<!doctype html>
<html lang="nl">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Weekselectie</title>
  <!-- je kunt hier exact dezelfde CSS houden als je huidige weekselectie -->
  <style>
    :root {
      --bg: #f6f7fb;
      --card: #fff;
      --text: #0f172a;
      --muted: #64748b;
      --border: #e2e8f0;
      --shadow: 0 10px 30px rgba(2, 6, 23, .08);
      --radius: 16px;
    }

    * {
      box-sizing: border-box;
    }

    html,
    body {
      height: 100%;
    }

    body {
      margin: 0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
      color: var(--text);
      background: radial-gradient(900px 400px at 50% 0%, #eef2ff 0%, var(--bg) 50%);
    }

    .page {
      min-height: 100%;
      display: grid;
      place-items: center;
      padding: 24px;
    }

    .card {
      width: min(760px, 100%);
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 28px;
    }

    .logo-wrap {
      display: flex;
      justify-content: center;
      margin: 6px 0 18px;
    }

    .logo {
      width: 980px;
      max-width: 80%;
      height: auto;
      object-fit: contain;
    }

    h1 {
      margin: 0 0 6px;
      font-size: 22px;
      letter-spacing: .2px;
    }

    .subtitle {
      margin: 0 0 18px;
      color: var(--muted);
      font-size: 14px;
      line-height: 1.4;
    }

    .toolbar {
      display: grid;
      grid-template-columns: 1fr repeat(3, auto);
      gap: 10px;
      align-items: center;
      margin: 14px 0 12px;
    }

    .search {
      width: 100%;
      min-height: 44px;
      padding: 12px;
      border-radius: 12px;
      border: 1px solid var(--border);
      font-size: 14px;
      outline: none;
    }

    .search:focus {
      border-color: #818cf8;
      box-shadow: 0 0 0 4px rgba(129, 140, 248, .25);
    }

    .btn {
      min-height: 44px;
      padding: 0 14px;
      border: 1px solid var(--border);
      border-radius: 12px;
      font-weight: 700;
      font-size: 13px;
      color: var(--text);
      background: #fff;
      cursor: pointer;
      white-space: nowrap;
    }

    .btn:hover {
      filter: brightness(.98);
    }

    .btn:active {
      transform: translateY(1px);
    }

    .btn-primary {
      border: 0;
      color: #fff;
      background: linear-gradient(180deg, #4f46e5 0%, #4338ca 100%);
      box-shadow: 0 10px 20px rgba(79, 70, 229, .2);
    }

    .list {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 10px;
      max-height: 420px;
      overflow: auto;
      background: #fff;
    }

    .item {
      display: flex;
      gap: 10px;
      align-items: flex-start;
      padding: 10px;
      border-radius: 12px;
      cursor: pointer;
    }

    .item:hover {
      background: #f8fafc;
    }

    .item input {
      margin-top: 3px;
      transform: scale(1.1);
    }

    .item-title {
      font-weight: 700;
      font-size: 14px;
      line-height: 1.2;
    }

    .item-sub {
      color: var(--muted);
      font-size: 13px;
      margin-top: 2px;
    }

    .footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-top: 14px;
      padding-top: 12px;
      border-top: 1px dashed var(--border);
      flex-wrap: wrap;
    }

    .hint {
      color: var(--muted);
      font-size: 13px;
    }

    @media (max-width:620px) {
      .card {
        padding: 18px;
      }

      .toolbar {
        grid-template-columns: 1fr;
      }

      .btn,
      .btn-primary {
        width: 100%;
      }

      .footer {
        flex-direction: column;
        align-items: stretch;
      }
    }

    .list hr {
      border: none;
      border-top: 1px solid var(--border);
      margin: 12px 6px;
    }

    .badge-horae {
      display: inline-block;
      margin-left: 6px;
      padding: 1px 6px;
      border-radius: 999px;
      background: #fff7ed;
      color: #c2410c;
      font-size: 11px;
      font-weight: 700;
    }

    .progress-wrap {
      margin: 0 0 14px;
      display: none;
    }

    .progress-wrap.active {
      display: block;
    }

    .progress-bar {
      height: 10px;
      border-radius: 999px;
      background: #e2e8f0;
      overflow: hidden;
    }

    .progress-bar-fill {
      height: 100%;
      width: 0%;
      background: linear-gradient(90deg, #4f46e5, #818cf8);
      transition: width 200ms ease;
    }

    .progress-label {
      margin-top: 6px;
      font-size: 12px;
      color: var(--muted);
    }

    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.45);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 40;
      padding: 20px;
    }

    .modal-backdrop.open {
      display: flex;
    }

    .modal-card {
      width: min(420px, 100%);
      background: #fff;
      border-radius: 16px;
      padding: 22px;
      box-shadow: var(--shadow);
    }

    .modal-card h2 {
      margin: 0 0 8px;
      font-size: 18px;
    }

    .modal-card p {
      margin: 0 0 14px;
      color: var(--muted);
      font-size: 13px;
    }

    .modal-card input,
    .modal-card select {
      width: 100%;
      min-height: 44px;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 10px 12px;
      font-size: 14px;
      margin-bottom: 12px;
    }

    .modal-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }
  </style>

  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="manifest" href="site.webmanifest">
</head>

<body>
  <div class="page">
    <div class="card">
      <div class="logo-wrap"><img class="logo" src="images/kvtlogo_full.png" alt="Logo"></div>

      <h1>Weekselectie</h1>
      <p class="subtitle">
        Geselecteerde projecten: <b><?= htmlspecialchars((string) count($projectNos)) ?></b><br>
        Vink één of meerdere weken aan.
      </p>

      <form method="get" action="pdf.php" onsubmit="return validateWeeks()">
        <?php foreach ($projectNos as $pno): ?>
          <input type="hidden" name="projectNo[]" value="<?= htmlspecialchars($pno) ?>">
        <?php endforeach; ?>

        <div class="toolbar">
          <input class="search" id="weekSearch" type="text" placeholder="Zoek op weeknummer, datum of project…"
            oninput="filterWeeks()">
          <button class="btn" type="button" onclick="toggleAll(true)">Alles</button>
          <button class="btn" type="button" onclick="toggleAll(false)">Geen</button>
          <button class="btn" type="button" onclick="openNewWeekModal()">Nieuwe week</button>
        </div>

        <div class="list" id="weekList">
          <?php if (count($items) === 0): ?>
            <div class="hint">Geen BC-weken gevonden voor de geselecteerde projecten. Klik op <b>Nieuwe week</b> om een Horae-week aan te maken.</div>
          <?php endif; ?>

          <?php $first = true; ?>
          <?php foreach ($groups as $year => $list): ?>
            <?php if (!$first): ?>
              <hr>
            <?php endif; ?>
            <?php $first = false; ?>

            <div class="hint" style="font-weight:700; margin:6px 4px 10px;">
              <?= $year ? htmlspecialchars((string) $year) : "Onbekend jaar" ?>
            </div>

            <?php foreach ($list as $it): ?>
              <?php
              $label = "Week " . (int) $it['week'];
              $sub = trim((string) ($it['start'] ?? '')) . " – " . trim((string) ($it['end'] ?? ''));
              $proj = (string) ($it['projectNo'] ?? '');
              $isHoraeOnly = !empty($it['isOverrideOnly']);
              $searchBlob = strtolower($label . " " . $sub . " " . $proj . " " . ($it['desc'] ?? '') . " " . ($it['tsNo'] ?? '') . " horae");
              ?>
              <label class="item" data-search="<?= htmlspecialchars($searchBlob) ?>">
                <input type="checkbox" name="tsNo[]" value="<?= htmlspecialchars($it['tsNo']) ?>">
                <div>
                  <div class="item-title"><?= htmlspecialchars($label) ?> · <?= htmlspecialchars($proj) ?><?php if ($isHoraeOnly): ?><span class="badge-horae">Horae</span><?php endif; ?></div>
                  <div class="item-sub">(<?= htmlspecialchars($sub) ?>)</div>
                </div>
              </label>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>


        <div class="footer">
          <div class="hint" id="countHint"></div>
          <button class="btn-primary" type="submit">Download PDF</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-backdrop" id="newWeekModal" aria-hidden="true">
    <div class="modal-card">
      <h2>Nieuwe week toevoegen</h2>
      <p>Maak een Horae-rapport aan voor een week die (nog) niet in BC staat.</p>
      <?php if (count($projectNos) > 1): ?>
        <select id="newWeekProject">
          <?php foreach ($projectNos as $pno): ?>
            <option value="<?= htmlspecialchars($pno) ?>"><?= htmlspecialchars($pno) ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="hidden" id="newWeekProject" value="<?= htmlspecialchars($projectNos[0] ?? '') ?>">
      <?php endif; ?>
      <input type="number" id="newWeekYear" min="2000" max="2100" placeholder="Jaar (bijv. <?= (int) date('Y') ?>)" value="<?= (int) date('Y') ?>">
      <input type="number" id="newWeekNumber" min="1" max="53" placeholder="Weeknummer (1-53)">
      <div class="modal-actions">
        <button class="btn" type="button" onclick="closeNewWeekModal()">Annuleren</button>
        <button class="btn-primary btn" type="button" onclick="createNewWeek()">Aanmaken</button>
      </div>
    </div>
  </div>

  <script>
    const selectedProjects = <?= json_encode(array_values($projectNos), JSON_UNESCAPED_UNICODE) ?>;
    function toggleAll (on)
    {
      document.querySelectorAll('input[type="checkbox"][name="tsNo[]"]').forEach(cb => cb.checked = on);
      updateCount();
    }
    function validateWeeks ()
    {
      const any = [...document.querySelectorAll('input[name="tsNo[]"]')].some(x => x.checked);
      if (!any) { alert("Selecteer minstens één week."); return false; }
      return true;
    }
    function filterWeeks ()
    {
      const q = (document.getElementById('weekSearch').value || '').trim().toLowerCase();
      document.querySelectorAll('#weekList .item').forEach(el =>
      {
        const blob = (el.dataset.search || '');
        el.style.display = (q === '' || blob.includes(q)) ? '' : 'none';
      });
      updateCount();
    }
    function updateCount ()
    {
      const boxes = [...document.querySelectorAll('input[name="tsNo[]"]')];
      const checked = boxes.filter(b => b.checked).length;
      const visible = [...document.querySelectorAll('#weekList .item')].filter(el => el.style.display !== 'none').length;
      document.getElementById('countHint').textContent = `${checked} geselecteerd · ${visible} zichtbaar`;
    }
    document.addEventListener('change', (e) =>
    {
      if (e.target && e.target.matches('input[name="tsNo[]"]')) updateCount();
    });
    updateCount();

    function openNewWeekModal ()
    {
      document.getElementById('newWeekModal').classList.add('open');
      document.getElementById('newWeekModal').setAttribute('aria-hidden', 'false');
      document.getElementById('newWeekNumber').focus();
    }
    function closeNewWeekModal ()
    {
      document.getElementById('newWeekModal').classList.remove('open');
      document.getElementById('newWeekModal').setAttribute('aria-hidden', 'true');
    }
    async function createNewWeek ()
    {
      const projectNo = (document.getElementById('newWeekProject').value || '').trim();
      const year = Number(document.getElementById('newWeekYear').value || 0);
      const weekNo = Number(document.getElementById('newWeekNumber').value || 0);
      if (!projectNo) { alert('Kies een project.'); return; }
      if (!Number.isInteger(year) || year < 2000 || year > 2100) { alert('Voer een geldig jaar in (2000-2100).'); return; }
      if (!Number.isInteger(weekNo) || weekNo < 1 || weekNo > 53) { alert('Voer een geldig weeknummer in (1-53).'); return; }

      const response = await fetch('odata.php?action=override_create_week', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ projectNo, weekNo, year })
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok)
      {
        alert((payload && payload.error) || 'Week aanmaken mislukt.');
        return;
      }

      window.location.reload();
    }
  </script>
</body>

</html>