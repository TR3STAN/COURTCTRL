<?php
session_start();
require_once __DIR__ . '/config/database.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$tournaments=$pdo->query("SELECT tournament_id,tournament_name,number_of_days,start_date,end_date,number_of_courts,status FROM tournaments ORDER BY tournament_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$id=(int)($_GET['tournament_id']??$_POST['tournament_id']??($_SESSION['active_tournament_id']??0));
if(!$id && $tournaments) $id=(int)$tournaments[0]['tournament_id'];
$tournament=null;
foreach($tournaments as $t){if((int)$t['tournament_id']===$id){$tournament=$t;break;}}
if($tournament){$_SESSION['active_tournament_id']=$id;}
$counts=['categories'=>0,'entries'=>0,'matches'=>0,'scheduled'=>0,'completed'=>0,'ongoing'=>0];
if($tournament){
 $q=$pdo->prepare("SELECT COUNT(*) FROM categories WHERE tournament_id=?");$q->execute([$id]);$counts['categories']=(int)$q->fetchColumn();
 $q=$pdo->prepare("SELECT COUNT(*) FROM tournament_entries te JOIN categories c ON c.category_id=te.category_id WHERE c.tournament_id=?");$q->execute([$id]);$counts['entries']=(int)$q->fetchColumn();
 $q=$pdo->prepare("SELECT COUNT(*) FROM matches m JOIN categories c ON c.category_id=m.category_id WHERE c.tournament_id=?");$q->execute([$id]);$counts['matches']=(int)$q->fetchColumn();
 $q=$pdo->prepare("SELECT COUNT(*) FROM match_schedules ms JOIN matches m ON m.match_id=ms.match_id JOIN categories c ON c.category_id=m.category_id WHERE c.tournament_id=?");$q->execute([$id]);$counts['scheduled']=(int)$q->fetchColumn();
 $q=$pdo->prepare("SELECT COUNT(*) FROM matches m JOIN categories c ON c.category_id=m.category_id WHERE c.tournament_id=? AND m.match_status='Completed'");$q->execute([$id]);$counts['completed']=(int)$q->fetchColumn();
 $q=$pdo->prepare("SELECT COUNT(*) FROM matches m JOIN categories c ON c.category_id=m.category_id WHERE c.tournament_id=? AND m.match_status='Ongoing'");$q->execute([$id]);$counts['ongoing']=(int)$q->fetchColumn();
}
$modules=[
 ['players.php','Players & Teams','Register singles players and doubles pairs','users'],
 ['brackets.php','Brackets','Generate and manage category brackets','brackets'],
 ['schedule.php','Schedule & Courts','Assign match days and courts','calendar'],
 ['live_feed.php','Live Feed','Display current and upcoming court matches','activity'],
 ['scoring.php','Scoring & Standings','Record results and track standings','trophy']
];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tournament Workspace | COURTCTRL</title><link rel="stylesheet" href="assets/css/style.css"></head><body class="page-workspace"><svg class="svg-sprite" aria-hidden="true" focusable="false">
<symbol id="icon-dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/></symbol>
<symbol id="icon-workspace" viewBox="0 0 24 24"><path d="M3 8h18M5 8V5h14v3M5 8v11h14V8M9 12h6M9 15h6"/></symbol>
<symbol id="icon-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.5"/><path d="m19.4 15 .1.1 1.1.9-1.5 2.6-1.4-.5a7.8 7.8 0 0 1-1.6.9l-.3 1.5h-3l-.3-1.5a7.8 7.8 0 0 1-1.6-.9l-1.4.5L8 16l1.1-.9a7.2 7.2 0 0 1 0-1.9L8 12.3l1.5-2.6 1.4.5a7.8 7.8 0 0 1 1.6-.9l.3-1.5h3l.3 1.5a7.8 7.8 0 0 1 1.6.9l1.4-.5 1.5 2.6-1.1.9a7.2 7.2 0 0 1-.1 1.8Z"/></symbol>
<symbol id="icon-users" viewBox="0 0 24 24"><path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20"/><circle cx="10" cy="7.5" r="3.5"/><path d="M16 4.3a3.5 3.5 0 0 1 0 6.8M17 15h1.5a3.5 3.5 0 0 1 3.5 3.5V20"/></symbol>
<symbol id="icon-brackets" viewBox="0 0 24 24"><path d="M5 4h5v5H5zM14 15h5v5h-5zM5 15h5v5H5zM10 6.5h4a2 2 0 0 1 2 2V15M10 17.5h4"/></symbol>
<symbol id="icon-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18M7 14h3M14 14h3M7 17h3"/></symbol>
<symbol id="icon-activity" viewBox="0 0 24 24"><path d="M3 12h4l3-8 4 16 3-8h4"/></symbol>
<symbol id="icon-trophy" viewBox="0 0 24 24"><path d="M8 4h8v5a4 4 0 0 1-8 0V4ZM8 6H4v2a4 4 0 0 0 4 4M16 6h4v2a4 4 0 0 1-4 4M12 13v4M8 21h8M9 17h6v4H9z"/></symbol>
<symbol id="icon-arrow-right" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
<symbol id="icon-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.7 9a2.4 2.4 0 1 1 4.4 1.3c-.8 1-2.1 1.2-2.1 2.7M12 16.5h.01"/></symbol>
</svg><div class="app-shell">
<aside class="sidebar"><div class="brand"><div class="brand-mark">P</div><div><strong>COURT<span>CTRL</span></strong><small>TOURNAMENT ORGANIZER</small></div></div><div class="nav-label">WORKSPACE</div><a class="nav-item" href="index.php"><svg class="cc-icon" aria-hidden="true"><use href="#icon-dashboard"></use></svg> Dashboard</a><a class="nav-item active" href="workspace.php?tournament_id=<?= (int)$id ?>"><svg class="cc-icon" aria-hidden="true"><use href="#icon-workspace"></use></svg> Tournament Workspace</a><a class="nav-item" href="tournaments.php?id=<?= (int)$id ?>"><svg class="cc-icon" aria-hidden="true"><use href="#icon-settings"></use></svg> Tournament Setup</a><a class="nav-item" href="players.php?tournament_id=<?= (int)$id ?>"><svg class="cc-icon" aria-hidden="true"><use href="#icon-users"></use></svg> Players &amp; Teams</a><a class="nav-item" href="brackets.php?tournament_id=<?= (int)$id ?>"><svg class="cc-icon" aria-hidden="true"><use href="#icon-brackets"></use></svg> Brackets</a><a class="nav-item" href="schedule.php?tournament_id=<?= (int)$id ?>"><svg class="cc-icon" aria-hidden="true"><use href="#icon-calendar"></use></svg> Schedule &amp; Courts</a><a class="nav-item" href="live_feed.php?tournament_id=<?= (int)$id ?>"><svg class="cc-icon" aria-hidden="true"><use href="#icon-activity"></use></svg> Live Feed</a><a class="nav-item" href="scoring.php?tournament_id=<?= (int)$id ?>"><svg class="cc-icon" aria-hidden="true"><use href="#icon-trophy"></use></svg> Scoring &amp; Standings</a><div class="sidebar-bottom"><svg class="cc-icon help-icon" aria-hidden="true"><use href="#icon-help"></use></svg><div><strong>Organizer workspace</strong><small>COURTCTRL tournament control</small></div></div></aside>
<main class="main-content"><header class="topbar"><div class="crumb"><a href="index.php">Dashboard</a> <span>/</span> Tournament Workspace</div><div class="profile"><div class="avatar">A</div><div><strong>Administrator</strong><small>Tournament organizer</small></div></div></header>
<div class="workspace-wrap">
<?php if(!$tournaments):?><section class="workspace-empty"><h2>Create your first tournament</h2><p>Once created, its registration, brackets, schedule, live feed and scoring tools will appear here.</p><a class="btn btn-primary" href="index.php">Go to dashboard</a></section>
<?php elseif(!$tournament):?><section class="workspace-empty"><h2>Tournament not found</h2><p>Please choose an available tournament.</p><a class="btn btn-primary" href="index.php">Return to tournaments</a></section>
<?php else:?>
<form class="workspace-switch" method="get"><label for="tournament_id">ACTIVE TOURNAMENT</label><select id="tournament_id" name="tournament_id" onchange="this.form.submit()"><?php foreach($tournaments as $t):?><option value="<?=e($t['tournament_id'])?>" <?=$id===(int)$t['tournament_id']?'selected':''?>><?=e($t['tournament_name'])?></option><?php endforeach;?></select><a class="btn btn-outline btn-small" href="tournaments.php?id=<?=$id?>">Edit tournament setup</a></form>
<section class="workspace-hero"><div><p class="eyebrow">TOURNAMENT WORKSPACE</p><h1><?=e($tournament['tournament_name'])?></h1><p><?=e($tournament['status'])?> · <?=e($tournament['number_of_days'])?> day(s) · <?=e($tournament['number_of_courts'])?> courts<?= $tournament['start_date']?' · '.e(date('M j, Y',strtotime($tournament['start_date']))):'' ?></p></div><div class="workspace-actions"><a class="btn" href="tournaments.php?id=<?=$id?>">Tournament settings</a></div></section>
<section class="workspace-stats">
<div class="workspace-stat"><small>Categories</small><strong><?=$counts['categories']?></strong></div>
<div class="workspace-stat"><small>Entries</small><strong><?=$counts['entries']?></strong></div>
<div class="workspace-stat"><small>Matches</small><strong><?=$counts['matches']?></strong></div>
<div class="workspace-stat"><small>Scheduled</small><strong><?=$counts['scheduled']?></strong></div>
<div class="workspace-stat"><small>Completed</small><strong><?=$counts['completed']?></strong></div>
</section>
<div class="workspace-section-heading"><div><h2>Tournament modules</h2><p>Choose a module. All pages automatically use this tournament.</p></div><span class="status-pill"><?= $counts['ongoing']?> ongoing</span></div>
<section class="module-grid"><?php foreach($modules as $module):?><a class="module-card" href="<?=e($module[0])?>?tournament_id=<?=$id?>"><div class="module-icon"><svg class="cc-icon" aria-hidden="true"><use href="#icon-<?=e($module[3])?>"></use></svg></div><div><h3><?=e($module[1])?></h3><p><?=e($module[2])?></p></div><span class="module-arrow" aria-hidden="true"><svg class="cc-icon"><use href="#icon-arrow-right"></use></svg></span></a><?php endforeach;?></section>
<?php endif;?>
</div></main></div><script src="assets/js/app.js" defer></script></body></html>
