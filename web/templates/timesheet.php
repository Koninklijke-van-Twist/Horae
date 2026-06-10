<?php
// Verwacht variabelen:
// $project, $weekInfo, $gridProject, $contractor, $locations, $totals, $documentStatus
// $report['projectNo'], $report['weekNo'], $report['originals'], $report['overrideKeys']

$signSize = 3;

$contractor = $contractor ?? [
  'Naam' => '',
  'Adres' => '',
  'Postcode' => '',
  'Woonplaats' => '',
];

$projectNo = $project['No'] ?? ($report['projectNo'] ?? '');
$projectRef = $projectDisplay['Opdrachtnummer'] ?? ($project['Your_Reference'] ?? '');
$projectDesc = $projectDisplay['Project'] ?? ($project['Description'] ?? '');
$projPostcode = $projectDisplay['Postcode'] ?? ($locations['Ship_to_Post_Code'] ?? $project['Post_Code'] ?? '');
$projWoonplaats = $projectDisplay['Woonplaats'] ?? ($locations['Ship_to_City'] ?? $project['City'] ?? '');

$weekNo = (int) ($weekInfo['week'] ?? ($report['weekNo'] ?? 0));
$reportYear = (int) ($report['year'] ?? 0);
$isHoraeOnly = !empty($report['isHoraeOnly']);
$start = $weekInfo['start'] ?? null;
$end = $weekInfo['end'] ?? null;
$documentStatus = $documentStatus ?? ($report['documentStatus'] ?? '');
$signatures = $report['signatures'] ?? [
  'hoofdaannemer' => '',
  'onderaannemer' => '',
  'uitvoerder' => '',
];

$originals = $report['originals'] ?? [];
$overrideKeys = $report['overrideKeys'] ?? [];
$overrideSet = array_fill_keys($overrideKeys, true);

function ts_cell_class(string $key, array $overrideSet): string
{
  return isset($overrideSet[$key]) ? 'editable-cell has-override' : 'editable-cell';
}

function ts_td_attrs(string $key, string $label, string $value, array $originals, array $overrideSet, ?string $rowLabel = null, ?int $saveWeek = null, ?string $extraClass = null, ?int $saveYear = null): string
{
  $original = $originals[$key] ?? $value;
  $rowPart = $rowLabel ? ' data-row-label="' . h($rowLabel) . '"' : '';
  $weekPart = ($saveWeek !== null && $saveWeek > 0) ? ' data-save-week="' . h((string) $saveWeek) . '"' : '';
  $yearPart = ($saveYear !== null && $saveYear > 0) ? ' data-save-year="' . h((string) $saveYear) . '"' : '';
  $class = ts_cell_class($key, $overrideSet);
  if ($extraClass) {
    $class .= ' ' . trim($extraClass);
  }
  return 'class="' . $class . '" data-override-key="' . h($key) . '" data-label="' . h($label) . '"' . $rowPart . $weekPart . $yearPart . ' data-original="' . h($original) . '" tabindex="0" role="button"';
}

function ts_render_value(string $value, bool $bold = false): string
{
  $display = h($value);
  if ($display === '') {
    $display = '&nbsp;';
  }
  return $bold ? '<b>' . $display . '</b>' : $display;
}

$dayNames = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];
$timesheetDomId = preg_replace('/[^A-Za-z0-9_-]+/', '_', $projectNo . '_Y' . $reportYear . '_W' . $weekNo);
?>
<!doctype html>
<html lang="nl">

<head>
  <meta charset="utf-8">
  <title>Urenstaat <?= h($projectNo) ?> - Week <?= h($weekNo) ?></title>
  <style>
    @page {
      size: A4 landscape;
      margin: 8mm;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 8.5pt;
      color: #000;
    }

    timesheet {
      display: block;
    }

    .ts-report-shell {
      position: relative;
      width: min(100%, 297mm);
      margin: 0 auto;
      overflow: visible;
    }

    .ts-report-shell .ts-print-viewport {
      width: 100%;
      margin: 0;
    }

    .ts-print-viewport {
      width: min(100%, 297mm);
      aspect-ratio: 297 / 210;
      margin: 0 auto;
      overflow: visible;
      box-sizing: border-box;
    }

    .ts-print-content {
      display: block;
      width: 100%;
      transform-origin: top left;
      box-sizing: border-box;
    }

    .small {
      font-size: 6.5pt;
    }

    .tight {
      line-height: 1.15;
    }

    table {
      border-collapse: collapse;
      margin: 0px;
    }

    .box {
      border: 2px solid #000;
    }

    .box th,
    .box td {
      border: 2px solid #000;
      padding: 4px 6px;
      vertical-align: top;
    }

    .box th {
      font-weight: bold;
      text-align: left;
      background-color: #0099cc;
    }

    .nohead td {
      border: 2px solid #000;
      padding: 4px 6px;
    }

    .row {
      width: 100%;
      display: table;
      table-layout: fixed;
    }

    .cell {
      display: table-cell;
      vertical-align: top;
    }

    .gap {
      height: 8px;
    }

    .top-mini {
      width: 10%;
      float: right;
      margin-bottom: 8px;
      margin-top: 0px;
    }

    .top-mini td {
      border: 2px solid #000;
    }

    .header-layout,
    .header-layout > tbody > tr > td {
      border: none;
    }

    .top3 {
      width: 100%;
    }

    .w-left {
      width: 33%;
    }

    .w-mid {
      width: 33%;
    }

    .w-right {
      width: 35%;
    }

    .hours {
      width: 100%;
      margin-top: 10px;
      font-size: 6.8pt;
      table-layout: fixed;
    }

    .hours col.col-actions-col {
      width: 12px;
    }

    .hours col.col-w-bsn {
      width: 16%;
    }

    .hours col.col-w-name {
      width: 20%;
    }

    .hours col.col-w-day {
      width: 5.2%;
    }

    .hours col.col-w-total {
      width: 6.8%;
    }

    .hours tbody td {
      background-color: #fff;
    }

    .hours thead th.col-bsn,
    .hours thead th.col-name,
    .hours thead th.col-day,
    .hours thead th.col-total {
      background-color: #0099cc;
    }

    .hours th,
    .hours td {
      border: 2px solid #000;
      padding: 4px 5px;
    }

    .hours th {
      text-align: center;
      font-weight: bold;
      white-space: nowrap;
    }

    .hours td {
      vertical-align: middle;
    }

    .hours td.num {
      text-align: right;
    }

    .hours td.center {
      text-align: center;
    }

    .hours td.name {
      white-space: nowrap;
    }

    .hours td.editable-cell {
      cursor: pointer;
      min-height: 24px;
      min-width: 24px;
      position: relative;
    }

    .hours td.editable-cell.has-override,
    td.editable-cell.has-override,
    span.editable-cell.has-override {
      box-shadow:
        inset 0 0 10px 2px rgba(22, 163, 74, 0.42),
        inset 0 0 18px 6px rgba(74, 222, 128, 0.18);
    }

    td.editable-cell,
    span.editable-cell {
      cursor: pointer;
      min-height: 20px;
      position: relative;
    }

    .sign-name-value.editable-cell {
      display: inline-block;
      min-width: 70%;
      padding: 2px 4px;
      margin-top: 2px;
    }

    td.editable-cell {
      min-height: 20px;
    }

    .col-actions {
      width: 12px !important;
      min-width: 12px !important;
      max-width: 12px !important;
      padding: 0 !important;
      border: 2px solid #000;
      vertical-align: middle;
      text-align: center;
      overflow: hidden;
      white-space: nowrap;
      box-sizing: border-box;
      background-color: #fff;
    }

    .hours th.col-actions,
    .hours td.col-actions {
      padding: 0 !important;
    }

    .hours tbody tr.person-row .row-delete-btn {
      opacity: 0;
      pointer-events: none;
      border: 0;
      background: transparent;
      color: #b91c1c;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 12px;
      height: 12px;
      min-width: 12px;
      padding: 0;
      margin: 0 auto;
      line-height: 1;
      transition: opacity 120ms ease;
    }

    .hours tbody tr.person-row .row-delete-btn svg {
      display: block;
      width: 10px;
      height: 10px;
    }

    .hours tbody tr.person-row {
      position: relative;
    }

    .hours tbody tr.person-row:hover .row-delete-btn {
      opacity: 1;
      pointer-events: auto;
    }

    .hours tbody tr.person-row.row-added td {
      background-color: #f0fdf4;
      box-shadow:
        inset 0 0 10px 2px rgba(22, 163, 74, 0.42),
        inset 0 0 18px 6px rgba(74, 222, 128, 0.18);
    }

    tr.row-deleted td {
      background: #fecaca !important;
      color: #7f1d1d !important;
    }

    tr.row-deleted td.editable-cell.has-override {
      box-shadow:
        inset 0 0 10px 2px rgba(127, 29, 29, 0.35),
        inset 0 0 16px 5px rgba(185, 28, 28, 0.2);
    }

    tr.row-deleted {
      cursor: pointer;
    }

    .hours-block__add {
      position: absolute;
      left: calc(-8px - 36px);
      width: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 2;
    }

    .row-add-btn {
      border: 1px dashed #94a3b8;
      background: #f8fafc;
      color: #334155;
      border-radius: 8px;
      min-width: 36px;
      min-height: 28px;
      font-size: 18px;
      font-weight: 700;
      cursor: pointer;
      line-height: 1;
    }

    .row-add-btn:hover {
      background: #eef2ff;
      border-color: #818cf8;
    }

    .totals-line {
      width: 100%;
      margin-top: 6px;
    }

    .totals-line td {
      border: 2px solid #000;
      padding: 6px;
    }

    .sign-row {
      width: 100%;
      display: table;
      table-layout: fixed;
    }

    .sign {
      border: 2px solid #000;
      vertical-align: top;
      padding-top: 2px;
    }

    .sign+.sign {
      border-top: none;
    }

    .blue {
      background-color: #0099cc;
    }

    .fullw {
      width: 100%;
      margin: 0px;
    }

    .sign-title {
      font-weight: bold;
      background-color: #0099cc;
      margin-top: 2px;
      padding: 2px;
    }

    .sign-name {
      font-weight: bold;
      background-color: #0099cc;
      margin-top: 2px;
      padding: 2px;
    }

    .declarations {
      border: 2px solid #000;
      padding: 6px 8px;
      font-size: 9.2pt;
    }

    .declarations ol {
      margin: 0 0 0 18px;
      padding: 0;
    }

    .declarations li {
      margin: 2px 0;
    }

    .logo-wrap {
      display: table-cell;
      justify-content: center;
      margin: 6px 0 18px;
    }

    .logo {
      height: 80px;
      width: auto;
      object-fit: contain;
    }

    .titletext {
      font-size: 30pt;
      padding-bottom: 5pt;
    }

    .underline {
      border-bottom: 1px solid black;
    }

    .subtle {
      color: #888;
    }

    .override-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.45);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      padding: 20px;
    }

    .override-modal-backdrop.open {
      display: flex;
    }

    .override-modal {
      width: min(520px, 100%);
      background: #fff;
      border-radius: 14px;
      box-shadow: 0 20px 50px rgba(2, 6, 23, 0.25);
      padding: 20px;
    }

    .override-modal h2 {
      margin: 0 0 8px;
      font-size: 18px;
    }

    .override-modal p {
      margin: 0 0 14px;
      color: #64748b;
      font-size: 13px;
      line-height: 1.45;
    }

    .override-modal input {
      width: 90%;
      min-height: 44px;
      border: 1px solid #cbd5e1;
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 14px;
      margin-bottom: 14px;
    }

    .override-modal-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }

    .override-btn {
      min-height: 40px;
      padding: 0 14px;
      border-radius: 10px;
      border: 1px solid #cbd5e1;
      background: #fff;
      font-weight: 700;
      cursor: pointer;
    }

    .override-btn-primary {
      border: 0;
      color: #fff;
      background: linear-gradient(180deg, #4f46e5 0%, #4338ca 100%);
    }

    .override-btn-danger {
      color: #b91c1c;
      border-color: #fecaca;
      background: #fff5f5;
    }

    .btn-delete-week {
      border: 1px solid #fecaca;
      background: #fff1f2;
      color: #991b1b;
      font-weight: 700;
      cursor: pointer;
      min-height: 32px;
      padding: 0 12px;
    }

    .btn-delete-week:hover {
      background: #ffe4e6;
    }

    .delete-week-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(40, 0, 0, 0.55);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1100;
      padding: 20px;
    }

    .delete-week-backdrop.open {
      display: flex;
      animation: delete-week-pulse-backdrop 1s ease-in-out infinite;
    }

    @keyframes delete-week-pulse-backdrop {
      0%, 100% { box-shadow: inset 0 0 0 0 rgba(220, 38, 38, 0); }
      50% { box-shadow: inset 0 0 120px 20px rgba(220, 38, 38, 0.35); }
    }

    .delete-week-modal {
      width: min(540px, 100%);
      background: #fff;
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 20px 60px rgba(127, 29, 29, 0.45);
      animation: delete-week-pulse-modal 1s ease-in-out infinite;
    }

    @keyframes delete-week-pulse-modal {
      0%, 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.35), 0 20px 60px rgba(127, 29, 29, 0.45); }
      50% { box-shadow: 0 0 28px 8px rgba(220, 38, 38, 0.55), 0 20px 60px rgba(127, 29, 29, 0.55); }
    }

    .delete-week-stripes {
      height: 14px;
      background: repeating-linear-gradient(
        -45deg,
        #111 0 12px,
        #facc15 12px 24px
      );
      background-size: 24px 24px;
      animation: delete-week-stripes-scroll 0.8s linear infinite;
    }

    @keyframes delete-week-stripes-scroll {
      from { background-position: 0 0; }
      to { background-position: 24px 0; }
    }

    .delete-week-body {
      padding: 22px;
    }

    .delete-week-body h2 {
      margin: 0 0 10px;
      color: #7f1d1d;
      font-size: 20px;
    }

    .delete-week-body p {
      margin: 0 0 16px;
      color: #444;
      line-height: 1.5;
      font-size: 14px;
    }

    .delete-week-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }

    .delete-week-confirm {
      border: 0;
      background: #dc2626;
      color: #fff;
      font-weight: 700;
      min-height: 40px;
      padding: 0 14px;
      border-radius: 10px;
      cursor: pointer;
    }

    @media print {
      html,
      body {
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden;
      }

      .no-print {
        display: none !important;
      }

      tr.row-deleted,
      .col-actions,
      col.col-actions-col {
        display: none !important;
      }

      timesheet.is-printing {
        display: block !important;
        page-break-after: avoid !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }

      timesheet.is-printing .ts-report-shell {
        width: 100%;
        max-width: none;
      }

      timesheet.print-fit-active .ts-report-shell {
        width: var(--print-scaled-width) !important;
        max-width: var(--print-scaled-width) !important;
      }

      timesheet.is-printing .ts-print-viewport {
        width: 100%;
        max-width: none;
        aspect-ratio: unset;
        margin: 0;
        overflow: visible;
      }

      timesheet.is-printing:not(.print-fit-active) .ts-print-content {
        width: 100% !important;
        transform: none !important;
      }

      timesheet.is-printing.print-fit-active .ts-print-content {
        width: var(--print-width, auto) !important;
      }

      timesheet.is-printing .ts-print-content table {
        page-break-inside: avoid;
        break-inside: avoid;
      }

      timesheet.is-printing .header-layout td,
      timesheet.is-printing .header-layout th {
        border: none !important;
      }

      timesheet.is-printing .box,
      timesheet.is-printing .box td,
      timesheet.is-printing .box th,
      timesheet.is-printing .nohead td,
      timesheet.is-printing .hours td,
      timesheet.is-printing .hours th,
      timesheet.is-printing .top-mini td,
      timesheet.is-printing .totals-line td,
      timesheet.is-printing .sign,
      timesheet.is-printing .declarations {
        border-color: #000 !important;
        border-style: solid !important;
        border-width: 2px !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      timesheet.is-printing .sign + .sign {
        border-top: none !important;
      }

      timesheet.is-printing .ts-print-content td[style*="border:0"],
      timesheet.is-printing .ts-print-content td[style*="border: 0"] {
        border: 0 !important;
      }

      timesheet.print-fit-active {
        display: block !important;
        box-sizing: border-box;
        width: var(--print-scaled-width) !important;
        height: var(--print-scaled-height) !important;
        max-width: var(--print-scaled-width) !important;
        max-height: var(--print-scaled-height) !important;
        overflow: hidden !important;
        page-break-after: avoid !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }

      timesheet.print-fit-active .ts-print-viewport {
        width: var(--print-target-width) !important;
        aspect-ratio: unset;
        height: var(--print-scaled-height) !important;
        overflow: hidden !important;
      }

      timesheet.print-fit-active .ts-print-content {
        width: var(--print-width, auto) !important;
        transform-origin: top left;
        page-break-inside: avoid;
        break-inside: avoid;
      }

      timesheet.print-fit-active .ts-print-content table {
        page-break-inside: avoid;
        break-inside: avoid;
      }

      timesheet.print-fit-active .header-layout td,
      timesheet.print-fit-active .header-layout th {
        border: none !important;
      }

      /* Compenseer lijndikte voor transform: scale() — anders worden borders onzichtbaar dun */
      timesheet.print-fit-active .box,
      timesheet.print-fit-active .box td,
      timesheet.print-fit-active .box th,
      timesheet.print-fit-active .nohead td,
      timesheet.print-fit-active .hours td,
      timesheet.print-fit-active .hours th,
      timesheet.print-fit-active .top-mini td,
      timesheet.print-fit-active .totals-line td,
      timesheet.print-fit-active .sign,
      timesheet.print-fit-active .declarations {
        border-color: #000 !important;
        border-style: solid !important;
        border-width: max(2px, calc(2px / var(--print-scale, 1))) !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      timesheet.print-fit-active .sign + .sign {
        border-top: none !important;
      }

      timesheet.print-fit-active .ts-print-content td[style*="border:0"],
      timesheet.print-fit-active .ts-print-content td[style*="border: 0"] {
        border: 0 !important;
      }

      td.editable-cell.has-override,
      .hours td.editable-cell.has-override,
      span.editable-cell.has-override,
      .hours tbody tr.person-row.row-added td {
        box-shadow: none !important;
        background-color: transparent !important;
      }
    }
  </style>

  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
  <link rel="manifest" href="site.webmanifest">
</head>

<timesheet class="tight no-print" id="<?= h($timesheetDomId) ?>" data-project-no="<?= h($projectNo) ?>" data-week-no="<?= h((string) $weekNo) ?>" data-year-no="<?= h((string) $reportYear) ?>" data-horae-only="<?= $isHoraeOnly ? '1' : '0' ?>">
  <script>
    // Print autoscale: schaal naar A4-printgebied (breedte én hoogte).
    const PRINT_AUTOSCALE_ENABLED = true;

    function getPrintableSizePx ()
    {
      const mmToPx = 96 / 25.4;
      const marginMm = 8;
      return {
        width: (297 - marginMm * 2) * mmToPx,
        height: (210 - marginMm * 2) * mmToPx
      };
    }

    function measureTimesheetForPrint (contentEl, rootEl, layoutWidthPx)
    {
      const root = rootEl || contentEl.closest('timesheet') || contentEl;
      const hiddenNodes = root.querySelectorAll('.no-print');
      const previousDisplay = [];
      const previousTransform = contentEl.style.transform;
      const previousWidth = contentEl.style.width;

      contentEl.style.transform = 'none';
      contentEl.style.width = layoutWidthPx ? (layoutWidthPx + 'px') : '100%';

      hiddenNodes.forEach(function (node, index)
      {
        previousDisplay[index] = node.style.display;
        node.style.display = 'none';
      });

      void contentEl.offsetHeight;

      const width = Math.ceil(contentEl.scrollWidth);
      let height = Math.ceil(contentEl.scrollHeight);
      const children = contentEl.children;
      if (children.length > 0) {
        const first = children[0];
        const last = children[children.length - 1];
        const measuredHeight = Math.ceil(
          (last.offsetTop - first.offsetTop) + last.offsetHeight
        );
        if (measuredHeight > height) {
          height = measuredHeight;
        }
      }

      hiddenNodes.forEach(function (node, index)
      {
        node.style.display = previousDisplay[index];
      });

      contentEl.style.transform = previousTransform;
      contentEl.style.width = previousWidth;

      return { width, height };
    }

    function applyContentScale (contentEl, size, scale)
    {
      contentEl.style.width = size.width + 'px';
      contentEl.style.removeProperty('transform');
      contentEl.style.removeProperty('transform-origin');
      contentEl.style.removeProperty('margin-bottom');
      contentEl.style.removeProperty('margin-right');
      contentEl.style.removeProperty('zoom');

      if (scale >= 0.999) return;

      const scaleText = scale.toFixed(4);
      if (typeof contentEl.style.zoom === 'string') {
        contentEl.style.zoom = scaleText;
        return;
      }

      contentEl.style.transformOrigin = 'top left';
      contentEl.style.transform = 'scale(' + scaleText + ')';
      contentEl.style.marginBottom = (-size.height * (1 - scale)).toFixed(2) + 'px';
      contentEl.style.marginRight = (-size.width * (1 - scale)).toFixed(2) + 'px';
    }

    function applyPrintFit (el)
    {
      if (!PRINT_AUTOSCALE_ENABLED) return;

      const content = el.querySelector('.ts-print-content');
      const viewport = el.querySelector('.ts-print-viewport');
      if (!content || !viewport) return;

      el.classList.remove('print-fit-active');
      el.style.removeProperty('--print-scale');
      el.style.removeProperty('--print-width');
      el.style.removeProperty('--print-target-width');
      el.style.removeProperty('--print-scaled-width');
      el.style.removeProperty('--print-scaled-height');
      viewport.style.removeProperty('width');
      viewport.style.removeProperty('height');
      content.style.removeProperty('transform');
      content.style.removeProperty('transform-origin');
      content.style.removeProperty('margin-bottom');
      content.style.removeProperty('margin-right');
      content.style.removeProperty('zoom');
      content.style.removeProperty('width');

      const printable = getPrintableSizePx();
      const size = measureTimesheetForPrint(content, el, printable.width);
      const safety = 0.97;
      const scaleW = (printable.width / size.width) * safety;
      const scaleH = (printable.height / size.height) * safety;
      const scale = Math.min(1, scaleW, scaleH);
      const scaledWidth = Math.ceil(size.width * scale);
      const scaledHeight = Math.ceil(size.height * scale);

      el.style.setProperty('--print-scale', scale.toFixed(4));
      el.style.setProperty('--print-width', size.width + 'px');
      el.style.setProperty('--print-target-width', scaledWidth + 'px');
      el.style.setProperty('--print-scaled-width', scaledWidth + 'px');
      el.style.setProperty('--print-scaled-height', scaledHeight + 'px');
      el.classList.add('print-fit-active');

      applyContentScale(content, size, scale);
    }

    function resetPrintFit (el)
    {
      const content = el.querySelector('.ts-print-content');
      const viewport = el.querySelector('.ts-print-viewport');

      el.classList.remove('print-fit-active');
      el.style.removeProperty('--print-scale');
      el.style.removeProperty('--print-width');
      el.style.removeProperty('--print-target-width');
      el.style.removeProperty('--print-scaled-width');
      el.style.removeProperty('--print-scaled-height');

      if (viewport) {
        viewport.style.removeProperty('width');
        viewport.style.removeProperty('height');
      }

      if (content) {
        content.style.removeProperty('transform');
        content.style.removeProperty('transform-origin');
        content.style.removeProperty('margin-bottom');
        content.style.removeProperty('margin-right');
        content.style.removeProperty('zoom');
        content.style.removeProperty('width');
      }
    }

    function printOnly (id)
    {
      const el = document.getElementById(id);
      if (!el) return;

      el.classList.remove('no-print');
      el.classList.add('is-printing');
      document.title = "Mandagenregister <?= h($projectNo) ?>";

      const onBeforePrint = function ()
      {
        applyPrintFit(el);
      };

      const cleanup = function ()
      {
        resetPrintFit(el);
        el.classList.remove('is-printing');
        el.classList.add('no-print');
        window.removeEventListener('beforeprint', onBeforePrint);
        window.removeEventListener('afterprint', cleanup);
      };

      window.addEventListener('afterprint', cleanup);

      if (PRINT_AUTOSCALE_ENABLED) {
        applyPrintFit(el);
        window.addEventListener('beforeprint', onBeforePrint);
        requestAnimationFrame(function ()
        {
          requestAnimationFrame(function ()
          {
            applyPrintFit(el);
            window.print();
          });
        });
      } else {
        window.print();
      }
    }
  </script>

  <div class="no-print" style="margin-bottom:10px;">
    <button onclick="printOnly('<?= h($timesheetDomId) ?>')">Print / Opslaan als PDF</button>
    <a href="."><button type="button">Terug naar beginscherm</button></a>
    <?php if ($isHoraeOnly): ?>
      <button type="button" class="btn-delete-week" id="deleteWeekBtn-<?= h($timesheetDomId) ?>">Horae-week verwijderen</button>
    <?php endif; ?>
  </div>

  <div class="ts-report-shell">
  <div class="ts-print-viewport">
  <div class="ts-print-content">
  <table class="header-layout" style="width:100%;">
    <tr>
      <td>
        <div class=" logo-wrap">
          <img class="logo" src="images/kvtlogo_full.png" alt="Logo">
          <span class="titletext">&nbsp;&nbsp;&nbsp;&nbsp;Mandagenregister</span>
        </div>
      </td>
      <td>
        <table class="top-mini" style="width:auto; min-width:280px;">
          <tr class="fullw">
            <td <?= ts_td_attrs('documentStatus', 'Documentstatus', $documentStatus, $originals, $overrideSet) ?> style="width:42%; vertical-align:top;">
              <p class="blue fullw"><b>Documentstatus</b></p><?= ts_render_value($documentStatus) ?>
            </td>
            <td class="fullw" style="vertical-align:top; padding:0; border:0;">
              <table style="width:100%; border-collapse:collapse;">
                <tr>
                  <td <?= ts_td_attrs('weekInfo.start', 'Startdatum', fmtDateNL($start), $originals, $overrideSet) ?> style="border:2px solid #000;">
                    <p class="blue fullw"><b>Startdatum</b></p><?= ts_render_value(fmtDateNL($start)) ?>
                  </td>
                </tr>
                <tr>
                  <td <?= ts_td_attrs('weekInfo.end', 'Einddatum', fmtDateNL($end), $originals, $overrideSet) ?> style="border:2px solid #000;">
                    <p class="blue fullw"><b>Einddatum</b></p><?= ts_render_value(fmtDateNL($end)) ?>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <div class="row top3">
    <div class="cell w-left">
      <table class="box" style="width:100%;">
        <tr>
          <th colspan="2">Onderaannemer</th>
        </tr>
        <tr>
          <td class="blue" style="width:38%;">Naam<br />&nbsp;</td>
          <td>Koninklijke van Twist</td>
        </tr>
        <tr>
          <td class="blue">Adres<br />&nbsp;</td>
          <td>Keerweer 62</td>
        </tr>
        <tr>
          <td class="blue">Postcode<br />&nbsp;</td>
          <td>Postbus 156 3300 AD </td>
        </tr>
        <tr>
          <td class="blue">Woonplaats<br />&nbsp;</td>
          <td>Dordrecht</td>
        </tr>
      </table>
    </div>

    <div class="cell w-mid">
      <table class="box" style="width:100%;">
        <tr>
          <th colspan="2">Hoofdaannemer</th>
        </tr>
        <tr>
          <td class="blue" style="width:38%;">Naam<br />&nbsp;</td>
          <td <?= ts_td_attrs('contractor.Naam', 'Naam hoofdaannemer', $contractor['Naam'] ?? '', $originals, $overrideSet) ?>><?= ts_render_value($contractor['Naam'] ?? '') ?></td>
        </tr>
        <tr>
          <td class="blue">Adres<br />&nbsp;</td>
          <td <?= ts_td_attrs('contractor.Adres', 'Adres hoofdaannemer', $contractor['Adres'] ?? '', $originals, $overrideSet) ?>><?= ts_render_value($contractor['Adres'] ?? '') ?></td>
        </tr>
        <tr>
          <td class="blue">Postcode<br />&nbsp;</td>
          <td <?= ts_td_attrs('contractor.Postcode', 'Postcode hoofdaannemer', $contractor['Postcode'] ?? '', $originals, $overrideSet) ?>><?= ts_render_value($contractor['Postcode'] ?? '') ?></td>
        </tr>
        <tr>
          <td class="blue">Woonplaats<br />&nbsp;</td>
          <td <?= ts_td_attrs('contractor.Woonplaats', 'Woonplaats hoofdaannemer', $contractor['Woonplaats'] ?? '', $originals, $overrideSet) ?>><?= ts_render_value($contractor['Woonplaats'] ?? '') ?></td>
        </tr>
      </table>
    </div>

    <div class="cell w-right">
      <table class="box" style="width:100%;">
        <tr>
          <th colspan="2">Projectgegevens</th>
        </tr>
        <tr>
          <td class="blue" style="width:42%;">Opdrachtnummer<br />&nbsp;</td>
          <td <?= ts_td_attrs('project.Opdrachtnummer', 'Opdrachtnummer', $projectRef, $originals, $overrideSet) ?>><?= ts_render_value($projectRef) ?></td>
        </tr>
        <tr>
          <td class="blue">Project<br />&nbsp;</td>
          <td <?= ts_td_attrs('project.Project', 'Project', $projectDesc, $originals, $overrideSet) ?>><?= ts_render_value($projectDesc) ?></td>
        </tr>
        <tr>
          <td class="blue">Postcode<br />&nbsp;</td>
          <td <?= ts_td_attrs('project.Postcode', 'Postcode project', $projPostcode, $originals, $overrideSet) ?>><?= ts_render_value($projPostcode) ?></td>
        </tr>
        <tr>
          <td class="blue">Woonplaats<br />&nbsp;</td>
          <td <?= ts_td_attrs('project.Woonplaats', 'Woonplaats project', $projWoonplaats, $originals, $overrideSet) ?>><?= ts_render_value($projWoonplaats) ?></td>
        </tr>
      </table>
    </div>
  </div>

  <table class="hours">
    <colgroup>
      <col class="col-actions-col no-print">
      <col class="col-w-bsn">
      <col class="col-w-name">
      <col class="col-w-day">
      <col class="col-w-day">
      <col class="col-w-day">
      <col class="col-w-day">
      <col class="col-w-day">
      <col class="col-w-day">
      <col class="col-w-day">
      <col class="col-w-total">
    </colgroup>
    <thead>
      <tr>
        <th class="col-actions no-print"></th>
        <th class="col-bsn">BSN/Sofinummer</th>
        <th class="col-name">Naam en voorletters werknemer</th>
        <th class="col-day">Week</th>
        <?php foreach ($dayNames as $dn): ?>
          <th class="col-day"><?= h($dn) ?></th>
        <?php endforeach; ?>
        <th class="col-total">Totaal</th>
      </tr>
    </thead>
    <tbody id="peopleBody-<?= h($timesheetDomId) ?>">
      <?php $rowNum = 0; ?>
      <?php foreach ($gridProject['people'] as $p): ?>
        <?php
        $rowNum++;
        $personKey = (string) ($p['key'] ?? '');
        $rowLabel = 'rij: ' . $rowNum;
        $bsn = $p['bsn'] ?? '';
        $name = $p['name'] ?? '';
        $weekVal = $p['week'] ?? '';
        $days = $p['days'] ?? array_fill(0, 7, 0.0);
        $rowTotal = $p['total'] ?? 0.0;
        $saveYear = (int) ($p['sortYear'] ?? $reportYear);
        $weekDisplay = ($gridProject['multiYear'] ? '(' . h($p['sortYear']) . ') ' : '') . h($weekVal);
        $isDeleted = !empty($p['isDeleted']);
        $isAdded = !empty($p['isAdded']);
        $rowClasses = 'person-row'
          . ($isDeleted ? ' row-deleted' : '')
          . ($isAdded && !$isDeleted ? ' row-added' : '');
        ?>
        <tr class="<?= h($rowClasses) ?>" data-person-key="<?= h($personKey) ?>" data-is-added="<?= $isAdded ? '1' : '0' ?>" data-is-deleted="<?= $isDeleted ? '1' : '0' ?>">
          <td class="col-actions no-print">
            <?php if (!$isDeleted): ?>
              <button type="button" class="row-delete-btn" title="Rij verwijderen" aria-label="Rij verwijderen">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                  <path fill="currentColor" d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v9h-2V9zm4 0h2v9h-2V9zM7 9h2v9H7V9z"/>
                </svg>
              </button>
            <?php endif; ?>
          </td>
          <td <?= ts_td_attrs('people.' . $personKey . '.bsn', 'BSN/Sofinummer', $bsn, $originals, $overrideSet, $rowLabel, (int) $weekVal, null, $saveYear) ?>><?= ts_render_value($bsn) ?></td>
          <td <?= ts_td_attrs('people.' . $personKey . '.name', 'Naam en voorletters werknemer', $name, $originals, $overrideSet, $rowLabel, (int) $weekVal, 'name', $saveYear) ?>><?= ts_render_value($name) ?></td>
          <td <?= ts_td_attrs('people.' . $personKey . '.week', 'Week', (string) $weekVal, $originals, $overrideSet, $rowLabel, (int) $weekVal, 'num', $saveYear) ?>><?= $weekDisplay !== '' ? $weekDisplay : '&nbsp;' ?></td>
          <?php for ($i = 0; $i < 7; $i++): ?>
            <td <?= ts_td_attrs('people.' . $personKey . '.days.' . $i, $dayNames[$i], fmtHours($days[$i] ?? 0), $originals, $overrideSet, $rowLabel, (int) $weekVal, 'num', $saveYear) ?> data-field-type="hours"><?= ts_render_value(fmtHours($days[$i] ?? 0)) ?></td>
          <?php endfor; ?>
          <td class="num person-total" data-person-key="<?= h($personKey) ?>"><?= ts_render_value(fmtHours($rowTotal), true) ?></td>
        </tr>
      <?php endforeach; ?>

      <?php
      $workdayTotals = 0.0;
      for ($i = 0; $i < 7; $i++)
        $workdayTotals += (float) ($totals['days'][$i] ?? 0);
      ?>
      <tr class="totals-row">
        <td class="no-print col-actions"></td>
        <td class="blue" colspan="3" style="text-align:right;"><b>Totaal</b></td>
        <?php for ($i = 0; $i < 7; $i++): ?>
          <td class="blue num totals-day" data-total-day="<?= $i ?>"><?= ts_render_value(fmtHours($totals['days'][$i] ?? 0), true) ?></td>
        <?php endfor; ?>
        <td class="blue num totals-all" data-total-all="1"><?= ts_render_value(fmtHours($workdayTotals), true) ?></td>
      </tr>
    </tbody>
  </table>

  <table>
    <tr>
      <td style="width: 30%; max-width:300px;vertical-align:top;">
        <div class="declarations">
          <ol>
            <li>voor de bovenvermelde werknemers de administratie wordt gevoerd.</li>
            <li>voor deze werknemers verschuldigde premies en belastingen worden afgedragen.</li>
            <li>door geen andere werknemers van de onder-aannemer ingeleend personeel in deze periode op het project is gewerkt.</li>
          </ol>
        </div>
      </td>
      <td>
        <div class="sign-row">
          <div class="sign">
            <span class="sign-name">Naam hoofdaannemer:</span><br>
            <span <?= ts_td_attrs('signatures.hoofdaannemer', 'Naam hoofdaannemer', $signatures['hoofdaannemer'] ?? '', $originals, $overrideSet, null, null, 'sign-name-value') ?>><?= ts_render_value($signatures['hoofdaannemer'] ?? '') ?></span>
            <div class="sign-title underline">Handtekening hoofdaannemer</div>
            <?php for ($i = 0; $i < $signSize; $i++): ?><br />&nbsp;<?php endfor; ?>
          </div>
          <div class="sign">
            <span class="sign-name">Naam onderaannemer:</span><br>
            <span <?= ts_td_attrs('signatures.onderaannemer', 'Naam onderaannemer', $signatures['onderaannemer'] ?? '', $originals, $overrideSet, null, null, 'sign-name-value') ?>><?= ts_render_value($signatures['onderaannemer'] ?? '') ?></span>
            <div class="sign-title underline">Handtekening onderaannemer</div>
            <?php for ($i = 0; $i < $signSize; $i++): ?><br />&nbsp;<?php endfor; ?>
          </div>
          <div class="sign">
            <span class="sign-name">Naam uitvoerder:</span><br>
            <span <?= ts_td_attrs('signatures.uitvoerder', 'Naam uitvoerder', $signatures['uitvoerder'] ?? '', $originals, $overrideSet, null, null, 'sign-name-value') ?>><?= ts_render_value($signatures['uitvoerder'] ?? '') ?></span>
            <div class="sign-title underline">Handtekening uitvoerder</div>
            <?php for ($i = 0; $i < $signSize; $i++): ?><br />&nbsp;<?php endfor; ?>
          </div>
        </div>
      </td>
    </tr>
  </table>
  </div>
  </div>
  <div class="hours-block__add no-print">
    <button type="button" class="row-add-btn" title="Nieuwe regel toevoegen" aria-label="Nieuwe regel toevoegen">+</button>
  </div>
  </div>

  <?php if ($isHoraeOnly): ?>
  <div class="delete-week-backdrop no-print" id="deleteWeekModal-<?= h($timesheetDomId) ?>" aria-hidden="true">
    <div class="delete-week-modal" role="dialog" aria-modal="true">
      <div class="delete-week-stripes"></div>
      <div class="delete-week-body">
        <h2>Horae-week definitief verwijderen</h2>
        <p>
          Deze week bestaat niet in Business Central en is alleen als Horae-rapport aangemaakt.
          Het verwijderen van week <?= h((string) $weekNo) ?><?= $reportYear > 0 ? ' (' . h((string) $reportYear) . ')' : '' ?>
          voor project <?= h($projectNo) ?> is <b>onherroepelijk</b>. Alle overschrijvingen gaan verloren.
        </p>
        <div class="delete-week-actions">
          <button type="button" class="override-btn" id="deleteWeekCancel-<?= h($timesheetDomId) ?>">Annuleren</button>
          <button type="button" class="delete-week-confirm" id="deleteWeekConfirm-<?= h($timesheetDomId) ?>">Definitief verwijderen</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="override-modal-backdrop no-print" id="overrideModal-<?= h($timesheetDomId) ?>" aria-hidden="true">
    <div class="override-modal" role="dialog" aria-modal="true">
      <h2 id="overrideModalTitle-<?= h($timesheetDomId) ?>">Overschrijving</h2>
      <p id="overrideModalHint-<?= h($timesheetDomId) ?>">Let op: Deze waarde wordt niet in BC ingevoerd, en bestaat alleen op Horae.</p>
      <input type="text" id="overrideModalInput-<?= h($timesheetDomId) ?>">
      <div class="override-modal-actions">
        <button type="button" class="override-btn override-btn-danger" id="overrideModalReset-<?= h($timesheetDomId) ?>">Reset</button>
        <button type="button" class="override-btn" id="overrideModalCancel-<?= h($timesheetDomId) ?>">Annuleren</button>
        <button type="button" class="override-btn override-btn-primary" id="overrideModalSave-<?= h($timesheetDomId) ?>">Opslaan</button>
      </div>
    </div>
  </div>

  <div class="override-modal-backdrop no-print" id="confirmModal-<?= h($timesheetDomId) ?>" aria-hidden="true">
    <div class="override-modal" role="dialog" aria-modal="true">
      <h2 id="confirmModalTitle-<?= h($timesheetDomId) ?>">Bevestigen</h2>
      <p id="confirmModalMessage-<?= h($timesheetDomId) ?>"></p>
      <div class="override-modal-actions">
        <button type="button" class="override-btn" id="confirmModalCancel-<?= h($timesheetDomId) ?>">Annuleren</button>
        <button type="button" class="override-btn override-btn-primary" id="confirmModalConfirm-<?= h($timesheetDomId) ?>">Bevestigen</button>
      </div>
    </div>
  </div>

  <script>
    (function ()
    {
      const root = document.getElementById(<?= json_encode($timesheetDomId) ?>);
      if (!root) return;

      const projectNo = root.dataset.projectNo || '';
      const weekNo = Number(root.dataset.weekNo || 0);
      const yearNo = Number(root.dataset.yearNo || 0);
      const isHoraeOnly = root.dataset.horaeOnly === '1';
      const modal = document.getElementById('overrideModal-<?= h($timesheetDomId) ?>');
      const modalTitle = document.getElementById('overrideModalTitle-<?= h($timesheetDomId) ?>');
      const modalHint = document.getElementById('overrideModalHint-<?= h($timesheetDomId) ?>');
      const modalInput = document.getElementById('overrideModalInput-<?= h($timesheetDomId) ?>');
      const btnSave = document.getElementById('overrideModalSave-<?= h($timesheetDomId) ?>');
      const btnReset = document.getElementById('overrideModalReset-<?= h($timesheetDomId) ?>');
      const btnCancel = document.getElementById('overrideModalCancel-<?= h($timesheetDomId) ?>');
      const deleteWeekModal = document.getElementById('deleteWeekModal-<?= h($timesheetDomId) ?>');
      const deleteWeekBtn = document.getElementById('deleteWeekBtn-<?= h($timesheetDomId) ?>');
      const deleteWeekCancel = document.getElementById('deleteWeekCancel-<?= h($timesheetDomId) ?>');
      const deleteWeekConfirm = document.getElementById('deleteWeekConfirm-<?= h($timesheetDomId) ?>');
      const confirmModal = document.getElementById('confirmModal-<?= h($timesheetDomId) ?>');
      const confirmModalTitle = document.getElementById('confirmModalTitle-<?= h($timesheetDomId) ?>');
      const confirmModalMessage = document.getElementById('confirmModalMessage-<?= h($timesheetDomId) ?>');
      const confirmModalConfirm = document.getElementById('confirmModalConfirm-<?= h($timesheetDomId) ?>');
      const confirmModalCancel = document.getElementById('confirmModalCancel-<?= h($timesheetDomId) ?>');

      let activeCell = null;
      let pendingConfirmAction = null;

      function resolveSaveYear (cell)
      {
        return Number(cell.dataset.saveYear || root.dataset.yearNo || 0);
      }

      function getCellDisplayValue (cell)
      {
        const clone = cell.cloneNode(true);
        clone.querySelectorAll('p.blue').forEach(function (node) { node.remove(); });
        return clone.textContent.replace(/\u00a0/g, ' ').trim();
      }

      function setCellDisplayValue (cell, value)
      {
        const label = cell.querySelector('p.blue');
        const isBold = cell.querySelector('b') !== null || (cell.dataset.overrideKey || '').endsWith('.total');
        cell.textContent = '';
        if (label) {
          cell.appendChild(label.cloneNode(true));
        }
        const display = value || '';
        if (isBold) {
          const b = document.createElement('b');
          b.textContent = display;
          cell.appendChild(b);
        } else if (display === '') {
          cell.innerHTML += '&nbsp;';
        } else {
          cell.appendChild(document.createTextNode(display));
        }
      }

      function isHoursDayCell (cell)
      {
        return cell && cell.dataset.fieldType === 'hours';
      }

      function parseHoursInput (value)
      {
        const normalized = String(value || '').trim().replace(',', '.');
        if (normalized === '') return 0;
        const n = Number(normalized);
        return Number.isFinite(n) ? n : NaN;
      }

      function isValidHoursInput (value)
      {
        const trimmed = String(value || '').trim();
        if (trimmed === '') return true;
        return /^[0-9]+([,.][0-9]+)?$/.test(trimmed);
      }

      function formatHoursDisplay (value)
      {
        const n = parseHoursInput(value);
        if (!Number.isFinite(n) || Math.abs(n) < 0.00001) return '';
        return String(n.toFixed(2)).replace(/\.?0+$/, '').replace('.', ',');
      }

      function getCellHoursValue (cell)
      {
        return parseHoursInput(getCellDisplayValue(cell));
      }

      function setBoldCellValue (cell, value)
      {
        cell.textContent = '';
        const display = value || '';
        if (display === '') {
          cell.innerHTML = '&nbsp;';
          return;
        }
        const b = document.createElement('b');
        b.textContent = display;
        cell.appendChild(b);
      }

      function recalcTotalsFromGrid ()
      {
        const dayTotals = [0, 0, 0, 0, 0, 0, 0];
        let grandTotal = 0;

        root.querySelectorAll('tr.person-row:not(.row-deleted)').forEach(function (row)
        {
          let rowSum = 0;
          for (let i = 0; i < 7; i++) {
            const dayCell = row.querySelector('[data-override-key$=".days.' + i + '"]');
            const dayVal = dayCell ? getCellHoursValue(dayCell) : 0;
            if (!Number.isFinite(dayVal)) continue;
            rowSum += dayVal;
            dayTotals[i] += dayVal;
          }
          grandTotal += rowSum;
          const totalCell = row.querySelector('.person-total');
          if (totalCell) {
            setBoldCellValue(totalCell, formatHoursDisplay(rowSum));
          }
        });

        for (let i = 0; i < 7; i++) {
          const footerCell = root.querySelector('.totals-day[data-total-day="' + i + '"]');
          if (footerCell) {
            setBoldCellValue(footerCell, formatHoursDisplay(dayTotals[i]));
          }
        }

        const allCell = root.querySelector('.totals-all');
        if (allCell) {
          setBoldCellValue(allCell, formatHoursDisplay(grandTotal));
        }
      }

      function openModal (cell)
      {
        activeCell = cell;
        const label = cell.dataset.label || 'veld';
        const rowLabel = cell.dataset.rowLabel ? ' ' + cell.dataset.rowLabel : '';
        modalTitle.textContent = 'Geef een overschrijving op voor ' + label + rowLabel;
        if (isHoursDayCell(cell)) {
          modalHint.textContent = 'Voer uren in (alleen getallen, bijv. 8 of 8,5).';
          modalInput.inputMode = 'decimal';
          modalInput.setAttribute('inputmode', 'decimal');
          modalInput.setAttribute('pattern', '[0-9]+([,.][0-9]+)?');
        } else {
          modalHint.textContent = 'Let op: Deze waarde wordt niet in BC ingevoerd, en bestaat alleen op Horae.';
          modalInput.inputMode = 'text';
          modalInput.setAttribute('inputmode', 'text');
          modalInput.removeAttribute('pattern');
        }
        modalInput.value = getCellDisplayValue(cell);
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        modalInput.focus();
        modalInput.select();
      }

      function closeModal ()
      {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        activeCell = null;
      }

      function setOverrideGlow (cell, enabled)
      {
        if (!cell) return;
        cell.classList.toggle('has-override', !!enabled);
      }

      async function postOverrideAction (action, body)
      {
        const response = await fetch('odata.php?action=' + action, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          credentials: 'same-origin',
          body: JSON.stringify(body)
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          throw new Error((payload && payload.error) || 'Actie mislukt');
        }
        return payload;
      }

      async function persistOverride (reset)
      {
        if (!activeCell) return;

        const key = activeCell.dataset.overrideKey || '';
        const saveWeek = Number(activeCell.dataset.saveWeek || root.dataset.weekNo || 0);
        const saveYear = resolveSaveYear(activeCell);
        const value = reset ? null : modalInput.value;

        if (!reset && isHoursDayCell(activeCell) && !isValidHoursInput(modalInput.value)) {
          alert('Alleen getallen toegestaan (bijv. 8 of 8,5).');
          return;
        }

        try {
          await postOverrideAction('override_save', {
            projectNo,
            weekNo: saveWeek,
            year: saveYear,
            key,
            value,
            reset: !!reset
          });
        } catch (error) {
          alert(error.message || 'Opslaan mislukt');
          return;
        }

        const displayValue = reset
          ? (activeCell.dataset.original || '')
          : (isHoursDayCell(activeCell) ? formatHoursDisplay(modalInput.value) : modalInput.value);
        setCellDisplayValue(activeCell, displayValue);
        setOverrideGlow(activeCell, !reset);
        if (isHoursDayCell(activeCell)) {
          recalcTotalsFromGrid();
        }
        closeModal();
      }

      async function addRow ()
      {
        try {
          await postOverrideAction('override_row_add', { projectNo, weekNo, year: yearNo });
          window.location.reload();
        } catch (error) {
          alert(error.message || 'Rij toevoegen mislukt');
        }
      }

      function openConfirmModal (options)
      {
        if (!confirmModal) return;
        confirmModalTitle.textContent = options.title || 'Bevestigen';
        confirmModalMessage.textContent = options.message || '';
        confirmModalConfirm.textContent = options.confirmLabel || 'Bevestigen';
        confirmModalConfirm.className = 'override-btn ' + (options.confirmClass || 'override-btn-primary');
        pendingConfirmAction = options.onConfirm || null;
        confirmModal.classList.add('open');
        confirmModal.setAttribute('aria-hidden', 'false');
        confirmModalConfirm.focus();
      }

      function closeConfirmModal ()
      {
        if (!confirmModal) return;
        confirmModal.classList.remove('open');
        confirmModal.setAttribute('aria-hidden', 'true');
        pendingConfirmAction = null;
      }

      async function runPendingConfirmAction ()
      {
        if (!pendingConfirmAction) return;
        const action = pendingConfirmAction;
        closeConfirmModal();
        await action();
      }

      async function deleteRow (personKey, saveWeek, saveYear)
      {
        try {
          await postOverrideAction('override_row_delete', {
            projectNo,
            weekNo: saveWeek || weekNo,
            year: saveYear || yearNo,
            personKey
          });
          window.location.reload();
        } catch (error) {
          alert(error.message || 'Rij verwijderen mislukt');
        }
      }

      async function restoreRow (personKey, saveWeek, saveYear)
      {
        try {
          await postOverrideAction('override_row_restore', {
            projectNo,
            weekNo: saveWeek || weekNo,
            year: saveYear || yearNo,
            personKey
          });
          window.location.reload();
        } catch (error) {
          alert(error.message || 'Rij herstellen mislukt');
        }
      }

      function confirmDeleteRow (personKey, saveWeek, saveYear)
      {
        openConfirmModal({
          title: 'Rij verwijderen',
          message: 'Weet u zeker dat u deze rij wilt verwijderen?',
          confirmLabel: 'Verwijderen',
          confirmClass: 'override-btn-danger',
          onConfirm: function () { return deleteRow(personKey, saveWeek, saveYear); }
        });
      }

      function confirmRestoreRow (personKey, saveWeek, saveYear)
      {
        openConfirmModal({
          title: 'Rij herstellen',
          message: 'Wilt u deze rij herstellen?',
          confirmLabel: 'Herstellen',
          confirmClass: 'override-btn-primary',
          onConfirm: function () { return restoreRow(personKey, saveWeek, saveYear); }
        });
      }

      function openDeleteWeekModal ()
      {
        if (!deleteWeekModal) return;
        deleteWeekModal.classList.add('open');
        deleteWeekModal.setAttribute('aria-hidden', 'false');
      }

      function closeDeleteWeekModal ()
      {
        if (!deleteWeekModal) return;
        deleteWeekModal.classList.remove('open');
        deleteWeekModal.setAttribute('aria-hidden', 'true');
      }

      async function deleteHoraeWeek ()
      {
        try {
          await postOverrideAction('override_delete_week', {
            projectNo,
            weekNo,
            year: yearNo
          });
          window.location.href = 'weeks.php?projectNo[]=' + encodeURIComponent(projectNo);
        } catch (error) {
          alert(error.message || 'Week verwijderen mislukt');
        }
      }

      if (deleteWeekBtn) {
        deleteWeekBtn.addEventListener('click', openDeleteWeekModal);
      }
      if (deleteWeekCancel) {
        deleteWeekCancel.addEventListener('click', closeDeleteWeekModal);
      }
      if (deleteWeekConfirm) {
        deleteWeekConfirm.addEventListener('click', deleteHoraeWeek);
      }
      if (deleteWeekModal) {
        deleteWeekModal.addEventListener('click', function (event) {
          if (event.target === deleteWeekModal) closeDeleteWeekModal();
        });
      }

      function positionRowAddBtn ()
      {
        const shell = root.querySelector('.ts-report-shell');
        const addSlot = root.querySelector('.hours-block__add');
        const totalsRow = root.querySelector('.hours tbody .totals-row');
        if (!shell || !addSlot || !totalsRow) return;

        const shellRect = shell.getBoundingClientRect();
        const totalsRect = totalsRow.getBoundingClientRect();
        const top = totalsRect.top - shellRect.top + (totalsRect.height - addSlot.offsetHeight) / 2;
        addSlot.style.top = top + 'px';
      }

      positionRowAddBtn();
      window.addEventListener('resize', positionRowAddBtn);
      window.addEventListener('load', positionRowAddBtn);
      if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(positionRowAddBtn);
      }

      root.addEventListener('click', function (event)
      {
        const target = event.target;
        if (target instanceof Element && target.closest('.row-add-btn')) {
          event.preventDefault();
          addRow();
          return;
        }

        if (target instanceof Element && target.closest('.row-delete-btn')) {
          event.preventDefault();
          event.stopPropagation();
          const row = target.closest('tr.person-row');
          if (!row) return;
          const personKey = row.dataset.personKey || '';
          const weekCell = row.querySelector('[data-override-key$=".week"]');
          const saveWeek = Number(weekCell && weekCell.dataset.saveWeek ? weekCell.dataset.saveWeek : weekNo);
          const saveYear = weekCell ? resolveSaveYear(weekCell) : yearNo;
          confirmDeleteRow(personKey, saveWeek, saveYear);
          return;
        }

        const deletedRow = target instanceof Element ? target.closest('tr.row-deleted') : null;
        if (deletedRow instanceof HTMLElement && root.contains(deletedRow)) {
          event.preventDefault();
          const personKey = deletedRow.dataset.personKey || '';
          const weekCell = deletedRow.querySelector('[data-override-key$=".week"]');
          const saveWeek = Number(weekCell && weekCell.dataset.saveWeek ? weekCell.dataset.saveWeek : weekNo);
          const saveYear = weekCell ? resolveSaveYear(weekCell) : yearNo;
          confirmRestoreRow(personKey, saveWeek, saveYear);
          return;
        }

        const cell = target instanceof Element ? target.closest('.editable-cell') : null;
        if (!(cell instanceof HTMLElement) || !root.contains(cell)) return;
        if (cell.closest('tr.row-deleted')) return;
        event.preventDefault();
        openModal(cell);
      });

      root.addEventListener('keydown', function (event)
      {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const cell = event.target.closest('.editable-cell');
        if (!(cell instanceof HTMLElement) || !root.contains(cell)) return;
        if (cell.closest('tr.row-deleted')) return;
        event.preventDefault();
        openModal(cell);
      });

      btnSave.addEventListener('click', function () { persistOverride(false); });
      btnReset.addEventListener('click', function () { persistOverride(true); });
      btnCancel.addEventListener('click', closeModal);
      modal.addEventListener('click', function (event)
      {
        if (event.target === modal) closeModal();
      });

      if (confirmModalConfirm) {
        confirmModalConfirm.addEventListener('click', runPendingConfirmAction);
      }
      if (confirmModalCancel) {
        confirmModalCancel.addEventListener('click', closeConfirmModal);
      }
      if (confirmModal) {
        confirmModal.addEventListener('click', function (event) {
          if (event.target === confirmModal) closeConfirmModal();
        });
      }

      document.addEventListener('keydown', function (event)
      {
        if (event.key !== 'Escape') return;
        if (confirmModal && confirmModal.classList.contains('open')) {
          closeConfirmModal();
          return;
        }
        if (modal.classList.contains('open')) closeModal();
      });
    })();
  </script>
</timesheet>
<br class="no-print" />
<hr class="no-print" />
<br class="no-print" />

</html>
