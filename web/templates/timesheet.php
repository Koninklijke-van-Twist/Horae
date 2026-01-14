<?php
// Verwacht variabelen:
// $project   : array (minimaal ['No' => ..., 'Description' => ...] + evt. postcode/woonplaats placeholders)
// $weekInfo  : array ['week'=>int, 'start'=>YYYY-MM-DD|null, 'end'=>YYYY-MM-DD|null]
// $grid      : array zoals uit build_timesheet_grid(): ['people'=>..., 'totals'=>...]
// (optioneel) $contractor : array met NAW hoofdaannemer; mag leeg zijn

$signSize = 3;

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fmtDateNL($ymd): string
{
    if (!$ymd) return '';
    $ts = strtotime($ymd);
    if (!$ts) return h($ymd);
    return date('d-m-Y', $ts);
}

function fmtHours($n): string
{
    // toon 0 als leeg; anders met komma
    $f = (float)$n;
    if (abs($f) < 0.00001) return '';
    // maximaal 2 decimalen, met komma
    return str_replace('.', ',', rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.'));
}

$contractor = $contractor ?? [
    'Naam' => '',
    'Adres' => '',
    'Postcode' => '',
    'Woonplaats' => '',
];

$projectNo = $project['No'] ?? '';
$projectDesc = $project['Description'] ?? '';

$projPostcode = $locations['Ship_to_Post_Code'] ?? $project['Post_Code'] ?? '';
$projWoonplaats = $locations['Ship_to_City'] ?? $project['City'] ?? '';

$week = (int)($weekInfo['week'] ?? 0);
$start = $weekInfo['start'] ?? null;
$end = $weekInfo['end'] ?? null;

// people sorteer op naam (stabiel)
$people = $grid['people'] ?? [];
uasort($people, function($a, $b) {
    return strcmp(($a['name'] ?? ''), ($b['name'] ?? ''));
});

$totals = $grid['totals'] ?? ['days'=>array_fill(0,7,0.0), 'all'=>0.0];

$dayNames = ['Ma','Di','Wo','Do','Vr','Za','Zo'];
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>Urenstaat <?= h($projectNo) ?> - Week <?= h($week) ?></title>
  <style>
    /* wkhtmltopdf-friendly: vermijd flex voor kritieke layout, gebruik tabellen en inline-block */
    @page { 
      size: A4 landscape; 
      -webkit-print-color-adjust: exact;
    print-color-adjust: exact; 
  }

    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 8.5pt;
      color: #000;
    }

    .small { font-size: 6.5pt; }
    .tight { line-height: 1.15; }

    table { border-collapse: collapse; margin: 0px;}
    .box {
      border: 1px solid #000;
    }
    .box th, .box td {
      border: 1px solid #000;
      padding: 4px 6px;
      vertical-align: top;
    }
    .box th {
      font-weight: bold;
      text-align: left;
      background-color: #0099cc;
    }

    .nohead td { border: 1px solid #000; padding: 4px 6px; }

    .row {
      width: 100%;
      display: table;
      table-layout: fixed;
    }
    .cell {
      display: table-cell;
      vertical-align: top;
    }

    .gap { height: 8px; }

    .top-mini {
      width: 10%;
      margin-left:90%;
      margin-bottom: 8px;
      margin-top: 0px;
    }
    .top-mini td {
      border: 1px solid #000;
    }

    .top3 {
      width: 100%;
    }

    /* breedtes 3 tabellen boven */
    .w-left { width: 33%; }
    .w-mid  { width: 33%; }
    .w-right{ width: 35%; }

    .hours {
      width: 100%;
      margin-top: 10px;
      font-size: 6.8pt;
    }
    .hours th, .hours td {
      border: 1px solid #000;
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
    .hours td.num { text-align: right; }
    .hours td.center { text-align: center; }
    .hours td.name { white-space: nowrap; }

    /* kolombreedtes uren-tabel (pas aan indien nodig) */
    .col-bsn { width: 16%; background-color: #0099cc;}
    .col-name { width: 26%; background-color: #0099cc;}
    .col-day { width: 5.2%; background-color: #0099cc;}
    .col-total { width: 6.8%; background-color: #0099cc;}

    .totals-line {
      width: 100%;
      margin-top: 6px;
    }
    .totals-line td {
      border: 1px solid #000;
      padding: 6px;
    }

    .sign-row {
      width: 100%;
      display: table;
      table-layout: fixed;
    }
    .sign {
      border: 1px solid #000;
      vertical-align: top;
      padding-top: 2px;
    }
    .sign + .sign { border-top: none;}
    .blue {background-color: #0099cc;}
    .fullw { width 100%; margin: 0px;}
    .sign-title { font-weight: bold; background-color: #0099cc; margin-top: 2px; padding:2px;}

    .declarations {
      border: 1px solid #000;
      padding: 6px 8px;
      font-size: 9.2pt;
    }
    .declarations ol { margin: 0 0 0 18px; padding: 0; }
    .declarations li { margin: 2px 0; }
  </style>
</head>

<body class="tight">
<div class="no-print" style="margin-bottom:10px;">
  <button onclick="window.print()">Print / Opslaan als PDF</button>
  <a href="."><button>Terug naar beginscherm</button></a>
</div>

<style>
@media print {
  .no-print { display: none !important; }
}
</style>

  <!-- Kleine tabel boven projectgegevens -->
  <table class="top-mini">
    <tr class="fullw"><td class="fullw"><p class="blue fullw"><b >Weeknummer</b></p> <?= h($week) ?></td></tr>
    <tr class="fullw"><td class="fullw"><p class="blue fullw"><b >Startdatum</b></p> <?= h(fmtDateNL($start)) ?></td></tr>
    <tr class="fullw"><td class="fullw"><p class="blue fullw"><b >Einddatum</b></p> <?= h(fmtDateNL($end)) ?></td></tr>
  </table>

  <!-- 3 tabellen naast elkaar -->
  <div class="row top3">
    <!-- Links: vaste gegevens (hardcoded) -->
    <div class="cell w-left">
      <table class="box" style="width:100%;">
        <tr><th colspan="2">Onderaannemer</th></tr>
        <tr><td class="blue" style="width:38%;">Naam<br/>&nbsp;</td><td><!-- hardcoded -->Koninklijke van Twist</td></tr>
        <tr><td class="blue">Adres<br/>&nbsp;</td><td><!-- hardcoded -->Keerweer 62</td></tr>
        <tr><td class="blue">Postcode<br/>&nbsp;</td><td><!-- hardcoded -->Postbus 156 3300 AD </td></tr>
        <tr><td class="blue">Woonplaats<br/>&nbsp;</td><td><!-- hardcoded -->Dordrecht</td></tr>
      </table>
    </div>

    <!-- Midden: Hoofdaannemer (later invullen vanuit Project) -->
    <div class="cell w-mid">
      <table class="box" style="width:100%;">
        <tr><th colspan="2">Hoofdaannemer</th></tr>
        <tr><td class="blue" style="width:38%;">Naam<br/>&nbsp;</td><td><?= h($contractor['Naam'] ?? '') ?></td></tr>
        <tr><td class="blue">Adres<br/>&nbsp;</td><td><?= h($contractor['Adres'] ?? '') ?></td></tr>
        <tr><td class="blue">Postcode<br/>&nbsp;</td><td><?= h($contractor['Postcode'] ?? '') ?></td></tr>
        <tr><td class="blue">Woonplaats<br/>&nbsp;</td><td><?= h($contractor['Woonplaats'] ?? '') ?></td></tr>
      </table>
    </div>

    <!-- Rechts: Projectgegevens -->
    <div class="cell w-right">
      <table class="box" style="width:100%;">
        <tr><th colspan="2">Projectgegevens</th></tr>
        <tr><td class="blue" style="width:42%;">Projectnummer<br/>&nbsp;</td><td><?= h($projectNo) ?></td></tr>
        <tr><td class="blue">Project<br/>&nbsp;</td><td><?= h($projectDesc) ?></td></tr>
        <tr><td class="blue">Postcode<br/>&nbsp;</td><td><?= h($projPostcode) ?></td></tr>
        <tr><td class="blue">Woonplaats<br/>&nbsp;</td><td><?= h($projWoonplaats) ?></td></tr>
      </table>
    </div>
  </div>

  <!-- Grote uren-tabel -->
  <table class="hours">
    <thead>
      <tr>
        <th class="col-bsn">BSN/Sofinummer</th>
        <th class="col-name">Naam en voorletters werknemer</th>
        <?php foreach ($dayNames as $dn): ?>
          <th class="col-day"><?= h($dn) ?></th>
        <?php endforeach; ?>
        <th class="col-total">Totaal</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($people as $p): ?>
        <?php
          $bsn = $p['bsn'] ?? '';
          $name = $p['name'] ?? '';
          $days = $p['days'] ?? array_fill(0,7,0.0);
          $rowTotal = $p['total'] ?? 0.0;
        ?>
        <tr>
          <td><?= h($bsn) ?></td>
          <td class="name"><?= h($name) ?></td>
          <?php for ($i=0; $i<7; $i++): ?>
            <td class="num"><?= h(fmtHours($days[$i] ?? 0)) ?></td>
          <?php endfor; ?>
          <td class="num"><b><?= h(fmtHours($rowTotal)) ?></b></td>
        </tr>
      <?php endforeach; ?>

      <!-- Totalenregel (Ma-Vr) onderaan tabel: jij zei specifiek Ma-Vr -->
      <?php
        $workdayTotals = 0.0;
        for ($i=0; $i<7; $i++) $workdayTotals += (float)($totals['days'][$i] ?? 0);
      ?>
      <tr>
        <td class="blue" colspan="2" style="text-align:right;"><b>Totaal</b></td>
        <?php for ($i=0; $i<7; $i++): ?>
            <td class="blue" class="num"><b><?= h(fmtHours($totals['days'][$i] ?? 0)) ?></b></td>
        <?php endfor; ?>
        <td class="blue" class="num"><b><?= h(fmtHours($workdayTotals)) ?></b></td>
      </tr>
    </tbody>
  </table>

  <!-- Handtekeningen -->

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
            <span class="sign-title">Naam hoofdaannemer:</span>
            <div class="sign-title">Handtekening hoofdaannemer</div><?php for($i = 0; $i < $signSize; $i++):?><br/>&nbsp;<?php endfor; ?>
          </div>
          <div class="sign">
            <span class="sign-title">Naam onderaannemer:</span>
            <div class="sign-title">Handtekening onderaannemer</div><?php for($i = 0; $i < $signSize; $i++):?><br/>&nbsp;<?php endfor; ?>
          </div>
          <div class="sign">
            <span class="sign-title">Naam uitvoerder:</span>
            <div class="sign-title">Handtekening uitvoerder</div><?php for($i = 0; $i < $signSize; $i++):?><br/>&nbsp;<?php endfor; ?>
          </div>
        </div>
      </td>
    </tr>
  </table>

  

  <!-- Verklaringenblok -->
  

</body>
</html>
