<?php
session_start();
require_once __DIR__ . '/config/database.php';

function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function bracketLetter($order) {
    $order = max(1, (int)$order); $label = '';
    while ($order > 0) { $order--; $label = chr(65 + ($order % 26)) . $label; $order = intdiv($order, 26); }
    return $label;
}

$tournaments = $pdo->query("SELECT tournament_id,tournament_name,start_date FROM tournaments ORDER BY start_date DESC,tournament_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$requestedTournamentId=(int)($_GET['tournament_id']??0); $tournamentId=$requestedTournamentId>0?$requestedTournamentId:(int)($_SESSION['active_tournament_id']??0); if($tournamentId<=0||!in_array($tournamentId,array_map('intval',array_column($tournaments,'tournament_id')),true)){$tournamentId=(int)($tournaments[0]['tournament_id']??0);} if($tournamentId){$_SESSION['active_tournament_id']=$tournamentId;}
$activeTournamentName=''; foreach($tournaments as $tour){if((int)$tour['tournament_id']===$tournamentId){$activeTournamentName=$tour['tournament_name'];break;}}
$categories = [];
if ($tournamentId) {
    $st=$pdo->prepare("SELECT category_id,category_name FROM categories WHERE tournament_id=? ORDER BY category_name");
    $st->execute([$tournamentId]); $categories=$st->fetchAll(PDO::FETCH_ASSOC);
}
$categoryId=(int)($_GET['category_id'] ?? 0);
$gameDate=(string)($_GET['game_date'] ?? '');
if (!$gameDate) {
    // Default to the selected category's latest scheduled day. A tournament-wide
    // first date can hide a category whose games are scheduled on another day.
    if ($categoryId > 0) {
        $st=$pdo->prepare("SELECT MAX(ms.tournament_day)
            FROM match_schedules ms
            INNER JOIN matches m ON m.match_id=ms.match_id
            INNER JOIN categories cat ON cat.category_id=m.category_id
            WHERE cat.tournament_id=? AND cat.category_id=?");
        $st->execute([$tournamentId,$categoryId]);
    } else {
        $st=$pdo->prepare("SELECT MAX(ms.tournament_day)
            FROM match_schedules ms
            INNER JOIN matches m ON m.match_id=ms.match_id
            INNER JOIN categories cat ON cat.category_id=m.category_id
            WHERE cat.tournament_id=?");
        $st->execute([$tournamentId]);
    }
    $gameDate=(string)($st->fetchColumn() ?: date('Y-m-d'));
}
$courts=[]; $matches=[];
if ($tournamentId) {
    $st=$pdo->prepare("SELECT court_id,court_name FROM courts WHERE tournament_id=? AND is_active=1 ORDER BY court_name");
    $st->execute([$tournamentId]); $courts=$st->fetchAll(PDO::FETCH_ASSOC);
    if ($categoryId > 0) {
    $sql="SELECT ms.tournament_day,ms.schedule_order,c.court_id,c.court_name,cat.category_id,cat.category_name,
                 m.match_id,m.bracket_id,b.bracket_order,m.round_name,m.match_number,m.match_status,
                 e1.entry_name entry1_name,e2.entry_name entry2_name
          FROM match_schedules ms
          INNER JOIN matches m ON m.match_id=ms.match_id
          INNER JOIN categories cat ON cat.category_id=m.category_id
          LEFT JOIN brackets b ON b.bracket_id=m.bracket_id
          INNER JOIN courts c ON c.court_id=ms.court_id
          LEFT JOIN tournament_entries e1 ON e1.entry_id=m.entry1_id
          LEFT JOIN tournament_entries e2 ON e2.entry_id=m.entry2_id
          WHERE cat.tournament_id=? AND ms.tournament_day=?";
    $params=[$tournamentId,$gameDate];
    if ($categoryId>0) { $sql.=" AND cat.category_id=?"; $params[]=$categoryId; }
    $sql.=" ORDER BY c.court_name,ms.schedule_order,m.match_number";
    $st=$pdo->prepare($sql); $st->execute($params); $matches=$st->fetchAll(PDO::FETCH_ASSOC);
    }
}
$byCourt=[];
foreach ($courts as $court) $byCourt[(int)$court['court_id']]=['court'=>$court,'matches'=>[]];
foreach ($matches as $match) {
    $cid=(int)$match['court_id'];
    if (!isset($byCourt[$cid])) $byCourt[$cid]=['court'=>['court_name'=>$match['court_name']],'matches'=>[]];
    $byCourt[$cid]['matches'][]=$match;
}
$colors=['#2563EB','#EA580C','#16A34A','#9333EA','#E11D48','#0891B2','#CA8A04','#4F46E5','#0F766E','#C2410C','#7C3AED','#BE185D','#0369A1','#65A30D','#A21CAF','#B45309','#0D9488','#DB2777','#475569','#4D7C0F'];
function statusClass($status) {
    $s=strtolower((string)$status);
    if ($s==='ongoing') return 'ongoing';
    if ($s==='completed') return 'completed';
    if ($s==='ready') return 'ready';
    if ($s==='cancelled'||$s==='skipped') return 'cancelled';
    return 'scheduled';
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Live Feed | COURTCTRL</title><link rel="stylesheet" href="assets/css/style.css">
</head><body class="page-live-feed"><div class="app-shell">
<aside class="sidebar"><div class="brand"><div class="brand-mark">P</div><div><strong>COURT<span>CTRL</span></strong><small>TOURNAMENT ORGANIZER</small></div></div><div class="nav-label">WORKSPACE</div><a class="nav-item" href="index.php"><span>▦</span> Dashboard</a><a class="nav-item" href="workspace.php?tournament_id=<?= (int)$tournamentId ?>"><span>♜</span> Tournament Workspace</a><a class="nav-item" href="tournaments.php?id=<?= (int)$tournamentId ?>"><span>⚙</span> Tournament Setup</a><a class="nav-item" href="players.php?tournament_id=<?= (int)$tournamentId ?>"><span>♙</span> Players &amp; Teams</a><a class="nav-item" href="brackets.php?tournament_id=<?= (int)$tournamentId ?>"><span>⌘</span> Brackets</a><a class="nav-item" href="schedule.php?tournament_id=<?= (int)$tournamentId ?>"><span>▦</span> Schedule &amp; Courts</a><a class="nav-item active" href="live_feed.php?tournament_id=<?= (int)$tournamentId ?>"><span>◉</span> Live Feed</a><a class="nav-item" href="scoring.php?tournament_id=<?= (int)$tournamentId ?>"><span>☷</span> Scoring &amp; Standings</a><div class="sidebar-bottom"><div class="help-dot">?</div><div><strong>Organizer workspace</strong><small>COURTCTRL tournament control</small></div></div></aside>
<main class="main-content"><header class="topbar"><div><h1>Live Feed</h1><p>Live court-by-court match display</p></div><div class="topbar-actions"><span class="status-pill">COURTCTRL LIVE</span></div></header><div class="active-tournament-strip"><span>ACTIVE TOURNAMENT</span><strong><?=e($activeTournamentName ?: 'No tournament selected')?></strong><a href="workspace.php?tournament_id=<?= (int)$tournamentId ?>">Switch</a></div>
<div class="content-area live-wrap">
<form class="live-toolbar" method="get">
<input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>">
<div class="field"><label for="category_id">Category</label><select id="category_id" name="category_id" onchange="document.getElementById('game_date').value='';this.form.submit()"><option value="0" disabled <?= $categoryId===0 ? "selected" : "" ?>>Select a category...</option><?php foreach($categories as $cat): ?><option value="<?=e($cat['category_id'])?>" <?=((int)$cat['category_id']===$categoryId?'selected':'')?>><?=e($cat['category_name'])?></option><?php endforeach; ?></select></div>
<div class="field"><label for="game_date">Game date</label><input type="date" id="game_date" name="game_date" value="<?=e($gameDate)?>" onchange="this.form.submit()"></div>

<button class="btn btn-outline" type="button" onclick="window.CourtCtrl?.refresh?.refreshNow?.()">Refresh</button>
</form>
<section class="score-sheet-tools">
  <div class="score-sheet-tools-copy">
    <span class="tools-kicker">MATCH DOCUMENTS</span>
    <h2>Printable score sheets</h2>
    <p>Generate official paper score sheets for the selected game date. Blank elimination sheets are available for matches whose players are still TBA.</p>
  </div>
  <form method="get" action="print_score_sheets.php" target="_blank" class="score-sheet-options">
    <input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>">
    <input type="hidden" name="category_id" value="<?=e($categoryId)?>">
    <input type="hidden" name="game_date" value="<?=e($gameDate)?>">
    <label><input type="checkbox" name="scheduled" value="1" checked> Scheduled matches for this date</label>
    <label><input type="checkbox" name="blank_elimination" value="1"> Blank elimination-round sheets</label>
    <button class="btn btn-primary" type="submit">Generate score sheets</button>
  </form>
  <div class="score-sheet-paper-note"><strong>Suggested paper:</strong> Legal bond paper · Portrait · 6 sheets per page · 2 inches high</div>
</section>
<div class="live-refresh-status" data-refresh-indicator role="status" aria-live="polite">Updated --:--:--</div>
<div class="live-refresh-content" data-refresh-container data-refresh-interval="15000">
<div class="live-meta"><div><h2 style="margin:0 0 5px">Courts</h2><div style="color:#64748b;font-size:13px"><?=e(date('l, F j, Y',strtotime($gameDate)))?> · <?=count($matches)?> scheduled match(es)</div></div>
<div class="legend"><span>Ongoing</span><span>Ready</span><span>Scheduled</span><span>Completed</span></div></div>
<?php if(!$courts): ?><div class="live-empty">No active courts are configured for this tournament.</div>
<?php elseif(!$matches): ?><div class="live-empty">No scheduled matches for this date/category. Assign matches in Schedule &amp; Courts.</div>
<?php else: ?><div class="live-grid">
<?php foreach($byCourt as $courtGroup): $courtMatches=$courtGroup['matches']; ?>
<article class="court-card"><div class="court-head"><h2><?=e($courtGroup['court']['court_name'])?></h2><span><?=count($courtMatches)?> MATCH<?=count($courtMatches)===1?'':'ES'?></span></div>
<?php if(!$courtMatches): ?><div class="empty-court">No matches assigned</div><?php else: ?>
<?php foreach($courtMatches as $index=>$m): $color=$colors[((int)($m['bracket_order']??1)-1)%count($colors)]; ?>
<div class="court-match"><div class="match-top"><div class="match-tags"><span class="tag" style="background:<?=$color?>"><?=e($m['category_name'])?></span><span class="tag" style="background:#334155">BRACKET <?=e(chr(64+max(1,(int)($m['bracket_order']??1))))?></span></div><span class="match-state <?=statusClass($m['match_status'])?>"><?=e($m['match_status'])?></span></div>
<div class="teams"><?=e($m['entry1_name']??'TBD / BYE')?></div><div class="versus">VS</div><div class="teams"><?=e($m['entry2_name']??'TBD / BYE')?></div>
<div class="match-sub"><span><?=e($m['round_name'])?></span></div></div>
<?php endforeach; endif; ?></article>
<?php endforeach; ?></div><?php endif; ?>
</div>
</div></main></div>
<script src="assets/js/app.js" defer></script>
</body></html>
