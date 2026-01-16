<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";

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
$rules = odata_get_all($rulesUrl, $auth);

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
    $items[] = [
      'week' => $w,
      'tsNo' => $tsNo,
      'start' => $t['Starting_Date'] ?? null,
      'end' => $t['Ending_Date'] ?? null,
      'desc' => $desc,
      'projectNo' => (string) ($tsToProject[$tsNo] ?? ''),
    ];
  }
}

// Groepeer op jaar (uit einddatum) en sorteer per jaar week desc
$groups = [];

foreach ($items as $it) {
  $end = (string) ($it['end'] ?? '');
  $year = 0;

  // verwacht YYYY-MM-DD
  if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $end, $m)) {
    $year = (int) $m[1];
  } else {
    $year = 0; // fallback
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
      grid-template-columns: 1fr auto auto;
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
        </div>

        <div class="list" id="weekList">
                  <?php if (count($items) === 0): ?>
            <div class="hint">Geen weken gevonden voor de geselecteerde projecten.</div>
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
              $searchBlob = strtolower($label . " " . $sub . " " . $proj . " " . ($it['desc'] ?? '') . " " . ($it['tsNo'] ?? ''));
              ?>
              <label class="item" data-search="<?= htmlspecialchars($searchBlob) ?>">
                <input type="checkbox" name="tsNo[]" value="<?= htmlspecialchars($it['tsNo']) ?>">
                <div>
                  <div class="item-title"><?= htmlspecialchars($label) ?> · <?= htmlspecialchars($proj) ?></div>
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

  <script>
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
  </script>
</body>

</html>