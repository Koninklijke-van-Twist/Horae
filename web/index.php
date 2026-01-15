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

// sorteer op No
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
      width: min(720px, 100%);
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

    label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 8px;
    }

    .select-row {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 12px;
      align-items: center;
    }

    select {
      width: 100%;
      padding: 12px 12px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: #fff;
      font-size: 14px;
      color: var(--text);
      outline: none;
      min-height: 44px;
    }

    select:focus {
      border-color: #818cf8;
      box-shadow: 0 0 0 4px rgba(129, 140, 248, 0.25);
    }

    button {
      min-height: 44px;
      padding: 0 16px;
      border: 0;
      border-radius: 12px;
      font-weight: 700;
      font-size: 14px;
      color: #fff;
      background: linear-gradient(180deg, #4f46e5 0%, #4338ca 100%);
      cursor: pointer;
      box-shadow: 0 10px 20px rgba(79, 70, 229, 0.2);
      transition: transform 0.05s ease, filter 0.15s ease;
      white-space: nowrap;
    }

    button:hover {
      filter: brightness(1.05);
    }

    button:active {
      transform: translateY(1px);
    }

    .help {
      margin-top: 14px;
      padding-top: 12px;
      border-top: 1px dashed var(--border);
      color: var(--muted);
      font-size: 13px;
    }

    @media (max-width: 520px) {
      .card {
        padding: 18px;
      }

      .select-row {
        grid-template-columns: 1fr;
      }

      button {
        width: 100%;
      }
    }
  </style>

  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="manifest" href="site.webmanifest">
  </body>
</head>

<body>
  <div class="page">
    <div class="card">
      <div class="logo-wrap">
        <img class="logo" src="images/kvtlogo_full.png" alt="Logo">
      </div>

      <h1>Projectselectie</h1>
      <p class="subtitle">Kies een project om beschikbare weken te bekijken.</p>

      <form method="get" action="weeks.php">
        <label for="projectNo">Project</label>

        <div class="select-row">
          <select id="projectNo" name="projectNo" required>
            <option value="" disabled selected>Kies een project…</option>

            <?php foreach ($projects as $p): ?>
              <option value="<?= htmlspecialchars($p['No'] ?? '') ?>">
                <?= htmlspecialchars(($p['No'] ?? '') . " - " . ($p['Description'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <button type="submit">Volgende</button>
        </div>
      </form>

      <div class="help">
        Tip: je kunt in de lijst typen om snel te springen (bijv. “407”).
      </div>
    </div>
  </div>
</body>

</html>