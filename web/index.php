<?php
require __DIR__ . "/odata.php";
require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";
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

    .item-sub.manual-note {
      color: #b45309;
      font-weight: 600;
    }

    .manual-add {
      margin-top: 10px;
      padding: 12px;
      border: 1px dashed var(--border);
      border-radius: 12px;
      background: #fffbeb;
    }

    .manual-add button {
      margin-top: 8px;
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

    .btn:disabled,
    .btn-primary:disabled {
      opacity: 0.55;
      cursor: default;
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

    .progress-wrap {
      margin: 0 0 14px;
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

    .progress-bar-fill.indeterminate {
      width: 35% !important;
      animation: progress-indeterminate 1.2s ease-in-out infinite;
    }

    @keyframes progress-indeterminate {
      0% { margin-left: 0%; }
      50% { margin-left: 65%; }
      100% { margin-left: 0%; }
    }

    .progress-label {
      margin-top: 6px;
      font-size: 12px;
      color: var(--muted);
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
  <?= injectTimerHtml([
    'statusUrl' => 'odata.php?action=cache_status',
    'title' => 'Cachebestanden',
    'label' => 'Cache',
  ]) ?>
  <div class="page">
    <div class="card">
      <div class="logo-wrap">
        <img class="logo" src="images/kvtlogo_full.png" alt="Logo">
      </div>

      <h1>Projectselectie</h1>
      <p class="subtitle">Vink één of meerdere projecten aan. Staat een project (nog) niet in de lijst? Zoek op nummer of voeg het handmatig toe.</p>

      <div class="progress-wrap" id="loadProgressWrap">
        <div class="progress-bar">
          <div class="progress-bar-fill" id="loadProgressFill"></div>
        </div>
        <div class="progress-label" id="loadProgressLabel">Projecten laden…</div>
      </div>

      <form method="get" action="weeks.php" onsubmit="return validateProjects()" id="projectForm">
        <div class="toolbar">
          <input class="search" id="projectSearch" type="text" placeholder="Zoek op projectnummer of omschrijving…"
            oninput="filterProjects()" disabled>
          <button class="btn" type="button" onclick="toggleAllProjects(true)" disabled id="btnAll">Alles</button>
          <button class="btn" type="button" onclick="toggleAllProjects(false)" disabled id="btnNone">Geen</button>
        </div>

        <div class="list" id="projectList">
          <div class="hint" id="projectLoadingHint">Projecten worden geladen…</div>
        </div>

        <div class="footer">
          <div class="hint" id="projCountHint"></div>
          <button class="btn-primary" type="submit" disabled id="btnNext">Volgende</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    const BATCH_SIZE = 200;
    let allProjects = [];
    let serverSearchResults = null;
    let serverSearchQuery = '';
    let serverSearchLoading = false;
    let searchDebounceTimer = null;
    let manualProjects = {};
    let checkedProjects = new Set();

    function projectMatchesQuery (project, queryLower)
    {
      const no = String(project.No || '').toLowerCase();
      const desc = String(project.Description || '').toLowerCase();
      return no.includes(queryLower) || desc.includes(queryLower);
    }

    function captureCheckedProjects ()
    {
      document.querySelectorAll('input[name="projectNo[]"]:checked').forEach(cb =>
      {
        if (cb.value) checkedProjects.add(cb.value);
      });
    }

    function buildProjectItemHtml (project, manual)
    {
      const no = String(project.No || '');
      const desc = String(project.Description || '');
      const searchBlob = (no + ' ' + desc + (manual ? ' handmatig' : '')).toLowerCase();
      const checked = checkedProjects.has(no) ? ' checked' : '';
      const manualNote = manual
        ? '<div class="item-sub manual-note">Handmatig toegevoegd · nog geen BC-weken nodig voor selectie</div>'
        : '<div class="item-sub">' + escapeHtml(desc) + '</div>';

      return '<label class="item" data-search="' + escapeHtml(searchBlob) + '">'
        + '<input type="checkbox" name="projectNo[]" value="' + escapeHtml(no) + '"' + checked + '>'
        + '<div>'
        + '<div class="item-title">' + escapeHtml(no) + (manual ? ' · handmatig' : '') + '</div>'
        + manualNote
        + '</div>'
        + '</label>';
    }

    function collectVisibleProjects ()
    {
      const q = (document.getElementById('projectSearch').value || '').trim();
      const qLower = q.toLowerCase();
      let rows = [];

      if (q.length >= 1 && serverSearchResults !== null)
      {
        rows = serverSearchResults.slice();
      }
      else if (qLower === '')
      {
        rows = allProjects.slice();
      }
      else
      {
        rows = allProjects.filter(p => projectMatchesQuery(p, qLower));
      }

      const seen = new Set(rows.map(p => String(p.No || '')));
      Object.values(manualProjects).forEach(p =>
      {
        const no = String(p.No || '');
        if (no === '' || seen.has(no)) return;
        if (qLower === '' || projectMatchesQuery(p, qLower)) {
          rows.push(p);
          seen.add(no);
        }
      });

      rows.sort((a, b) => String(a.No || '').localeCompare(String(b.No || ''), undefined, { numeric: true }));
      return { rows, q, qLower };
    }

    function renderProjectList ()
    {
      captureCheckedProjects();
      const list = document.getElementById('projectList');
      const { rows, q, qLower } = collectVisibleProjects();

      if (serverSearchLoading)
      {
        list.innerHTML = '<div class="hint">Zoeken in Business Central…</div>';
        updateProjectCount();
        return;
      }

      if (allProjects.length === 0 && q === '')
      {
        list.innerHTML = '<div class="hint">Geen projecten gevonden.</div>';
        updateProjectCount();
        return;
      }

      let html = '';
      if (rows.length === 0)
      {
        html += '<div class="hint">Geen project in Business Central gevonden voor "' + escapeHtml(q) + '".</div>';
        html += '<div class="manual-add">'
          + '<div class="hint">Staat het project wél in BC onder een ander nummer? Probeer exact dat nummer. Anders kun je het projectnummer handmatig kiezen voor een Horae-week.</div>'
          + '<button class="btn" type="button" onclick="addManualProject()">Project "' + escapeHtml(q) + '" toevoegen</button>'
          + '</div>';
      }
      else
      {
        for (const p of rows)
        {
          const no = String(p.No || '');
          html += buildProjectItemHtml(p, !!manualProjects[no]);
        }
      }

      list.innerHTML = html;
      updateProjectCount();
    }

    function renderProjects ()
    {
      renderProjectList();
    }

    function addManualProject (value)
    {
      const no = String(value ?? document.getElementById('projectSearch').value ?? '').trim();
      if (no === '')
      {
        alert('Voer eerst een projectnummer in.');
        return;
      }
      manualProjects[no] = { No: no, Description: '', manual: true };
      checkedProjects.add(no);
      serverSearchResults = serverSearchResults || [];
      if (!serverSearchResults.some(p => String(p.No || '') === no))
      {
        serverSearchResults.unshift(manualProjects[no]);
      }
      renderProjectList();
    }

    async function runServerProjectSearch (query)
    {
      serverSearchLoading = true;
      serverSearchQuery = query;
      renderProjectList();

      try
      {
        const response = await fetch('odata.php?action=projects_search&q=' + encodeURIComponent(query), {
          headers: { 'Accept': 'application/json' },
          credentials: 'same-origin',
          cache: 'no-store'
        });
        const raw = await response.text();
        const payload = JSON.parse(raw);
        if (!response.ok || !payload.ok)
        {
          throw new Error((payload && payload.error) || 'Zoeken mislukt');
        }

        if ((document.getElementById('projectSearch').value || '').trim() !== query)
        {
          return;
        }

        serverSearchResults = Array.isArray(payload.rows) ? payload.rows : [];
      }
      catch (error)
      {
        console.error(error);
        serverSearchResults = [];
        if ((document.getElementById('projectSearch').value || '').trim() === query)
        {
          const list = document.getElementById('projectList');
          list.innerHTML = '<div class="hint">Zoeken mislukt: ' + escapeHtml(error.message || 'onbekende fout') + '</div>';
        }
      }
      finally
      {
        serverSearchLoading = false;
        if ((document.getElementById('projectSearch').value || '').trim() === query)
        {
          renderProjectList();
        }
      }
    }

    function escapeHtml (value)
    {
      return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function setLoadingProgress (loaded, total, done, waiting)
    {
      const fill = document.getElementById('loadProgressFill');
      const label = document.getElementById('loadProgressLabel');
      fill.classList.toggle('indeterminate', !!waiting && loaded === 0);

      let pct = 0;
      if (total && total > 0)
      {
        pct = Math.min(100, Math.round((loaded / total) * 100));
      }
      else if (done)
      {
        pct = 100;
      }
      else if (loaded > 0)
      {
        pct = Math.min(95, Math.max(8, Math.round(loaded / 5)));
      }
      else if (waiting)
      {
        pct = 0;
      }
      else
      {
        pct = 8;
      }

      if (!waiting || loaded > 0)
      {
        fill.classList.remove('indeterminate');
        fill.style.width = pct + '%';
      }

      if (done)
      {
        label.textContent = loaded + ' projecten geladen';
      }
      else if (waiting && loaded === 0)
      {
        label.textContent = 'Eerste batch ophalen uit Business Central…';
      }
      else if (total)
      {
        label.textContent = 'Projecten laden… ' + loaded + ' / ' + total;
      }
      else
      {
        label.textContent = 'Projecten laden… ' + loaded + ' opgehaald';
      }
    }

    function setInteractiveEnabled (enabled)
    {
      document.getElementById('projectSearch').disabled = !enabled;
      document.getElementById('btnAll').disabled = !enabled;
      document.getElementById('btnNone').disabled = !enabled;
      document.getElementById('btnNext').disabled = !enabled;
    }

    async function loadProjectsBatched ()
    {
      let skip = 0;
      let done = false;
      let total = null;
      allProjects = [];

      setInteractiveEnabled(false);
      setLoadingProgress(0, null, false, true);

      try
      {
        while (!done)
        {
          const url = 'odata.php?action=projects_batch&skip=' + skip + '&top=' + BATCH_SIZE + '&_t=' + Date.now();
          const response = await fetch(url, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
          });

          let payload = null;
          const raw = await response.text();
          try
          {
            payload = JSON.parse(raw);
          }
          catch (parseError)
          {
            throw new Error('Geen geldige JSON van server (HTTP ' + response.status + ').');
          }

          if (!response.ok || !payload.ok)
          {
            throw new Error((payload && payload.error) || ('Projecten laden mislukt (HTTP ' + response.status + ').'));
          }

          const rows = Array.isArray(payload.rows) ? payload.rows : [];
          allProjects = allProjects.concat(rows);
          skip = Number(payload.loaded || (skip + rows.length));
          if (payload.total !== null && payload.total !== undefined)
          {
            total = Number(payload.total);
          }
          done = !!payload.done || rows.length === 0;
          setLoadingProgress(allProjects.length, total, done, false);
        }

        renderProjects();
        setInteractiveEnabled(true);
        document.getElementById('loadProgressWrap').style.opacity = '0.85';
      }
      catch (error)
      {
        console.error(error);
        document.getElementById('projectLoadingHint').textContent = error.message || 'Projecten laden mislukt.';
        setLoadingProgress(allProjects.length, total, false, false);
      }
    }

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
      const q = (document.getElementById('projectSearch').value || '').trim();
      clearTimeout(searchDebounceTimer);

      if (q.length >= 1)
      {
        searchDebounceTimer = setTimeout(() =>
        {
          serverSearchResults = null;
          runServerProjectSearch(q);
        }, 250);
        return;
      }

      serverSearchResults = null;
      serverSearchLoading = false;
      renderProjectList();
    }
    function updateProjectCount ()
    {
      const boxes = [...document.querySelectorAll('input[name="projectNo[]"]')];
      const checked = boxes.filter(b => b.checked).length;
      const visible = boxes.length;
      document.getElementById('projCountHint').textContent = `${checked} geselecteerd · ${visible} zichtbaar`;
    }
    document.addEventListener('change', (e) =>
    {
      if (e.target && e.target.matches('input[name="projectNo[]"]')) updateProjectCount();
    });

    loadProjectsBatched();
  </script>
</body>

</html>
