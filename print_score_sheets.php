<?php
session_start();
require_once __DIR__ . '/config/database.php';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function bracketLetterForSheet($order): string {
    $order = max(1, (int)$order);
    $label = '';
    while ($order > 0) {
        $order--;
        $label = chr(65 + ($order % 26)) . $label;
        $order = intdiv($order, 26);
    }
    return $label;
}
function sheetBracketColor($order): string {
    $colors = ['#2563EB','#EA580C','#16A34A','#9333EA','#E11D48','#0891B2','#CA8A04','#4F46E5','#0F766E','#C2410C','#7C3AED','#BE185D','#0369A1','#65A30D','#A21CAF','#B45309','#0D9488','#DB2777','#475569','#4D7C0F'];
    return $colors[(max(1, (int)$order) - 1) % count($colors)];
}

$tournamentId = (int)($_GET['tournament_id'] ?? 0);
$categoryId = (int)($_GET['category_id'] ?? 0);
$gameDate = trim((string)($_GET['game_date'] ?? date('Y-m-d')));
$includeScheduled = isset($_GET['scheduled']) ? (int)$_GET['scheduled'] === 1 : true;
$includeBlankElimination = (int)($_GET['blank_elimination'] ?? 0) === 1;

if (!$tournamentId) {
    http_response_code(400);
    exit('Please select a tournament from the Live Feed page.');
}

$baseSql = "SELECT m.match_id,m.category_id,m.bracket_id,m.round_name,
                   cat.category_name,b.bracket_name,b.bracket_order,
                   e1.entry_name AS entry1_name,e2.entry_name AS entry2_name,
                   ms.tournament_day,ms.court_id,c.court_name,ms.schedule_order
            FROM matches m
            INNER JOIN categories cat ON cat.category_id=m.category_id
            LEFT JOIN brackets b ON b.bracket_id=m.bracket_id
            LEFT JOIN tournament_entries e1 ON e1.entry_id=m.entry1_id
            LEFT JOIN tournament_entries e2 ON e2.entry_id=m.entry2_id
            LEFT JOIN match_schedules ms ON ms.match_id=m.match_id
            LEFT JOIN courts c ON c.court_id=ms.court_id
            WHERE cat.tournament_id=?";
$params = [$tournamentId];

if ($categoryId > 0) {
    $baseSql .= " AND cat.category_id=?";
    $params[] = $categoryId;
}

$matchesById = [];
if ($includeScheduled) {
    $sql = $baseSql . " AND ms.match_id IS NOT NULL AND ms.tournament_day=? ORDER BY ms.schedule_order,c.court_name,m.match_id";
    $q = $pdo->prepare($sql);
    $q->execute(array_merge($params, [$gameDate]));
    foreach ($q->fetchAll() as $row) {
        $row['blank_names'] = false;
        $matchesById[(int)$row['match_id']] = $row;
    }
}

if ($includeBlankElimination) {
    $sql = $baseSql . " AND LOWER(m.round_name) NOT IN ('round robin','group stage') ORDER BY cat.category_name,b.bracket_order,m.match_id";
    $q = $pdo->prepare($sql);
    $q->execute($params);
    foreach ($q->fetchAll() as $row) {
        $row['blank_names'] = true;
        // Keep the scheduled court when one exists, but intentionally leave player names blank.
        $matchesById[(int)$row['match_id']] = $row;
    }
}

$matches = array_values($matchesById);

// schedule_order represents a simultaneous scheduling wave, so several
// courts can legitimately share it. Printable Game / Order numbers must
// instead be unique across all sheets in this print run.
usort($matches, static function (array $a, array $b): int {
    $waveCompare = ((int)($a['schedule_order'] ?? 0)) <=> ((int)($b['schedule_order'] ?? 0));
    if ($waveCompare !== 0) return $waveCompare;
    $courtCompare = strcasecmp((string)($a['court_name'] ?? ''), (string)($b['court_name'] ?? ''));
    if ($courtCompare !== 0) return $courtCompare;
    return ((int)$a['match_id']) <=> ((int)$b['match_id']);
});

$sheetRows = [];
$nextBlankOrder = 1;
foreach ($matches as $match) {
    // Sequential unique game number; do not reuse the scheduling wave number.
    $orderNumber = $nextBlankOrder++;
    $sheetRows[] = [
        'match' => $match,
        'order_number' => $orderNumber,
        'bracket_order' => (int)($match['bracket_order'] ?? 1),
        'blank_names' => !empty($match['blank_names'])
    ];
}
$tournamentNameQuery = $pdo->prepare("SELECT tournament_name FROM tournaments WHERE tournament_id=?");
$tournamentNameQuery->execute([$tournamentId]);
$tournamentName = (string)($tournamentNameQuery->fetchColumn() ?: 'Tournament');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>COURTCTRL_Score_Sheets_<?=h(preg_replace('/[^A-Za-z0-9_-]+/', '_', $tournamentName))?>_<?=h($gameDate)?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page-print-score-sheets">
<div class="toolbar">
  <div><strong>COURTCTRL · PRINTABLE SCORE SHEETS</strong><small><?=h($tournamentName)?> · <?=h($gameDate)?> · <?=count($sheetRows)?> sheet(s)</small></div>
  <div class="actions"><a class="back-btn" href="live_feed.php?tournament_id=<?=h($tournamentId)?>&category_id=<?=h($categoryId)?>&game_date=<?=h($gameDate)?>">Back to Live Feed</a><button class="print-btn" onclick="downloadScoreSheetsPdf()">Download PDF</button></div>
</div>
<div class="page-note">PDF download: click “Download PDF”, then choose “Save as PDF” as the printer destination and click Save. Paper: Legal (8.5 × 14 inches), portrait. Layout: 6 score sheets per page, each 2 inches high. Use 100% / Actual size.</div>
<?php if (!$sheetRows): ?>
<div class="empty-note">No score sheets matched your selected options. Return to Live Feed and choose scheduled matches or blank elimination sheets.</div>
<?php else: ?>
<?php foreach (array_chunk($sheetRows, 6) as $pageSheets): ?>
<section class="sheet-page">
<?php foreach ($pageSheets as $sheet):
    $m = $sheet['match'];
    $order = $sheet['bracket_order'];
    $orderNumber = (int)$sheet['order_number'];
    $color = sheetBracketColor($order);
    $letter = bracketLetterForSheet($order);
    $bracketName = trim((string)($m['bracket_name'] ?? ''));
    if ($bracketName === '') $bracketName = 'Bracket ' . $letter;
    $player1 = $sheet['blank_names'] ? '' : (string)($m['entry1_name'] ?? '');
    $player2 = $sheet['blank_names'] ? '' : (string)($m['entry2_name'] ?? '');
    $categoryBracket = trim((string)$m['category_name']) . ' · ' . $bracketName;
?>
<article class="score-sheet">
  <div class="sheet-header">
    <div class="meta-label umpire-label">UMPIRE</div><div class="meta-value umpire-value"></div>
    <div class="circle-cell"><div class="bracket-circle" style="background:<?=$color?>22;border-color:<?=$color?>;"><?=h($orderNumber)?></div></div>
    <div class="meta-label bracket-label">CATEGORY &amp; BRACKET #</div><div class="meta-value bracket-value"><?=h($categoryBracket)?></div>
    <div class="meta-label game-label">GAME / ORDER NO.</div><div class="meta-value game-value"><?=h($orderNumber)?></div>
    <div class="winner-area">SIGNATURE<br>OF WINNER</div>
    <div class="meta-label court-label">COURT</div><div class="meta-value court-value"><?=h($m['court_name'] ?: '________')?></div>
  </div>
  <div class="score-grid">
    <div class="grid-cell header">NAMES OF PLAYERS</div>
    <?php for($point=1;$point<=15;$point++): ?><div class="grid-cell header"><?=$point?></div><?php endfor; ?>
    <div class="grid-cell player"><?=h($player1)?></div>
    <?php for($point=1;$point<=15;$point++): ?><div class="grid-cell point"></div><?php endfor; ?>
    <div class="grid-cell player"><?=h($player2)?></div>
    <?php for($point=1;$point<=15;$point++): ?><div class="grid-cell point"></div><?php endfor; ?>
  </div>
  <div class="sheet-footer"><span><?=h($m['round_name'])?></span><span>COURTCTRL OFFICIAL MATCH SCORE SHEET</span></div>
</article>
<?php endforeach; ?>

</section>
<?php endforeach; ?>
<?php endif; ?>
<script>
function downloadScoreSheetsPdf() {
    // Opens the browser's built-in PDF exporter; works offline and preserves
    // the Legal paper size and six-sheets-per-page layout.
    window.print();
}
</script>
</body></html>
