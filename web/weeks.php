<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";

$projectNo = $_GET['projectNo'] ?? '';
if ($projectNo === '')
  die("projectNo ontbreekt");

// 1) Urenstaten binnen dit project (headers)
$filter = rawurlencode("Job_No_Filter eq '$projectNo'");
$url = $base . "Urenstaten?\$select=No,Starting_Date,Ending_Date,Description,Job_No_Filter&\$filter={$filter}&\$format=json";
$timesheets = odata_get_all($url, $auth);

// 2) Welke Time_Sheet_No's hebben regels binnen dit project?
$rulesFilter = rawurlencode("Job_No eq '$projectNo' and Work_Type_Code ne 'KM'");
$rulesUrl = $base . "Urenstaatregels?\$select=Time_Sheet_No&\$filter={$rulesFilter}&\$format=json";
$rules = odata_get_all($rulesUrl, $auth);

$hasRulesForTs = [];
foreach ($rules as $r) {
  $tsNo = (string) ($r['Time_Sheet_No'] ?? '');
  if ($tsNo !== '')
    $hasRulesForTs[$tsNo] = true;
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
    $items[] = [
      'week' => $w,
      'tsNo' => $t['No'],
      'start' => $t['Starting_Date'] ?? null,
      'end' => $t['Ending_Date'] ?? null,
      'desc' => $desc,
    ];
  }
}

usort($items, fn($a, $b) => $a['week'] <=> $b['week']);
?>
<!doctype html>
<html lang="nl">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Weekselectie</title>

  <style>
    :root {
      --bg: #f6f7fb;
      --card: #ffffff;
      --text: #0f172a;
      --muted: #64748b;
      --border: #e2e8f0;
      --shadow: 0 10px 30px rgba(2, 6, 23, 0.08);
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
      letter-spacing: 0.2px;
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
      padding: 12px 12px;
      border-radius: 12px;
      border: 1px solid var(--border);
      font-size: 14px;
      outline: none;
    }

    .search:focus {
      border-color: #818cf8;
      box-shadow: 0 0 0 4px rgba(129, 140, 248, 0.25);
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
      transition: transform 0.05s ease, filter 0.15s ease;
      white-space: nowrap;
    }

    .btn:hover {
      filter: brightness(0.98);
    }

    .btn:active {
      transform: translateY(1px);
    }

    .btn-primary {
      border: 0;
      color: #fff;
      background: linear-gradient(180deg, #4f46e5 0%, #4338ca 100%);
      box-shadow: 0 10px 20px rgba(79, 70, 229, 0.2);
    }

    .list {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 10px;
      max-height: 420px;
      /* belangrijk bij veel weken */
      overflow: auto;
      background: #fff;
    }

    .item {
      display: flex;
      gap: 10px;
      align-items: flex-start;
      padding: 10px 10px;
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

    @media (max-width: 620px) {
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
  </style>

  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="manifest" href="site.webmanifest">
</head>

<body>
  <div class="page">
    <div class="card">
      <div class="logo-wrap">
        <img class="logo" src="images/kvtlogo_full.png" alt="Logo">
      </div>

      <h1>Weekselectie</h1>
      <p class="subtitle">
        Project: <b><?= htmlspecialchars($projectNo) ?></b><br>
        Vink één of meerdere weken aan.
      </p>

      <form method="get" action="pdf.php" onsubmit="return validateWeeks()">
        <input type="hidden" name="projectNo" value="<?= htmlspecialchars($projectNo) ?>">

        <div class="toolbar">
          <input class="search" id="weekSearch" type="text"
            placeholder="Zoek op weeknummer of datum (bijv. 51 of 2025-12)…" oninput="filterWeeks()">

          <button class="btn" type="button" onclick="toggleAll(true)">Alles</button>
          <button class="btn" type="button" onclick="toggleAll(false)">Geen</button>
        </div>

        <div class="list" id="weekList">
          <?php if (count($items) === 0): ?>
            <div class="hint">Geen weken gevonden voor dit project.</div>
          <?php endif; ?>

          <?php foreach ($items as $it): ?>
            <?php
            $label = "Week " . (int) $it['week'];
            $sub = trim((string) ($it['start'] ?? '')) . " – " . trim((string) ($it['end'] ?? ''));
            $searchBlob = strtolower($label . " " . $sub . " " . ($it['desc'] ?? '') . " " . ($it['tsNo'] ?? ''));
            ?>
            <label class="item" data-search="<?= htmlspecialchars($searchBlob) ?>">
              <input type="checkbox" name="tsNo[]" value="<?= htmlspecialchars($it['tsNo']) ?>">
              <div>
                <div class="item-title"><?= htmlspecialchars($label) ?></div>
                <div class="item-sub">(<?= htmlspecialchars($sub) ?>)</div>
              </div>
            </label>
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
      if (!any)
      {
        alert("Selecteer minstens één week.");
        return false;
      }
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

      // zichtbare items tellen
      const visible = [...document.querySelectorAll('#weekList .item')]
        .filter(el => el.style.display !== 'none').length;

      const hint = document.getElementById('countHint');
      if (!hint) return;

      hint.textContent = `${checked} geselecteerd · ${visible} zichtbaar`;
    }

    document.addEventListener('change', (e) =>
    {
      if (e.target && e.target.matches('input[name="tsNo[]"]')) updateCount();
    });

    updateCount();
  </script>
</body>

</html>