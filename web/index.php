<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";

$hour = 3600;
$day = $hour * 24;

// 1) Bepaal welke projecten regels hebben (distinct Job_No uit Urenstaatregels)
$rulesUrl = $base . "Urenstaatregels?\$select=Job_No,Work_Type_Code&\$format=json&\$filter=Work_Type_Code%20ne%20'KM'";
$rules = odata_get_all($rulesUrl, $auth, $day);

$projectsWithRules = [];
foreach ($rules as $r) {
  $jno = trim((string) ($r['Job_No'] ?? ''));
  if ($jno !== '')
    $projectsWithRules[$jno] = true;
}

// 2) Haal alle projecten op en filter lokaal
$projUrl = $base . "AppProjecten?\$select=No,Description&\$format=json";
$projects = odata_get_all($projUrl, $auth, $day);

$projects = array_values(array_filter($projects, function ($p) use ($projectsWithRules) {
  $no = (string) ($p['No'] ?? '');
  return $no !== '' && isset($projectsWithRules[$no]);
}));

usort($projects, fn($a, $b) => strcmp((string) $a['No'], (string) $b['No']));
?>
<!doctype html>
<html lang="nl">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Projectselectie</title>

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

      <h1>Projectselectie</h1>
      <p class="subtitle">Vink één of meerdere projecten aan om beschikbare weken te bekijken.</p>

      <form method="get" action="weeks.php" onsubmit="return validateProjects()">
        <div class="toolbar">
          <input class="search" id="projectSearch" type="text" placeholder="Zoek op projectnummer of omschrijving…"
            oninput="filterProjects()">
          <button class="btn" type="button" onclick="toggleAllProjects(true)">Alles</button>
          <button class="btn" type="button" onclick="toggleAllProjects(false)">Geen</button>
        </div>

        <div class="list" id="projectList">
          <?php if (count($projects) === 0): ?>
            <div class="hint">Geen projecten gevonden.</div>
          <?php endif; ?>

          <?php foreach ($projects as $p): ?>
            <?php
            $no = (string) ($p['No'] ?? '');
            $desc = (string) ($p['Description'] ?? '');
            $searchBlob = strtolower($no . " " . $desc);
            ?>
            <label class="item" data-search="<?= htmlspecialchars($searchBlob) ?>">
              <input type="checkbox" name="projectNo[]" value="<?= htmlspecialchars($no) ?>">
              <div>
                <div class="item-title"><?= htmlspecialchars($no) ?></div>
                <div class="item-sub"><?= htmlspecialchars($desc) ?></div>
              </div>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="footer">
          <div class="hint" id="projCountHint"></div>
          <button class="btn-primary" type="submit">Volgende</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function toggleAllProjects (on)
    {
      document.querySelectorAll('input[type="checkbox"][name="projectNo[]"]').forEach(cb => cb.checked = on);
      updateProjectCount();
    }
    function validateProjects ()
    {
      const any = [...document.querySelectorAll('input[name="projectNo[]"]')].some(x => x.checked);
      if (!any) { alert("Selecteer minstens één project."); return false; }
      return true;
    }
    function filterProjects ()
    {
      const q = (document.getElementById('projectSearch').value || '').trim().toLowerCase();
      document.querySelectorAll('#projectList .item').forEach(el =>
      {
        const blob = (el.dataset.search || '');
        el.style.display = (q === '' || blob.includes(q)) ? '' : 'none';
      });
      updateProjectCount();
    }
    function updateProjectCount ()
    {
      const boxes = [...document.querySelectorAll('input[name="projectNo[]"]')];
      const checked = boxes.filter(b => b.checked).length;
      const visible = [...document.querySelectorAll('#projectList .item')].filter(el => el.style.display !== 'none').length;
      document.getElementById('projCountHint').textContent = `${checked} geselecteerd · ${visible} zichtbaar`;
    }
    document.addEventListener('change', (e) =>
    {
      if (e.target && e.target.matches('input[name="projectNo[]"]')) updateProjectCount();
    });
    updateProjectCount();
  </script>
</body>

</html>