<?php
require_once __DIR__.'/config/database.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$tournaments=$pdo->query("SELECT tournament_id,tournament_name FROM tournaments ORDER BY tournament_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$tournamentId=(int)($_GET['tournament_id']??$_POST['tournament_id']??($tournaments[0]['tournament_id']??0));
$assignmentMessage=''; $assignmentError='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['assign_next_match'])) {
 $courtId=(int)($_POST['court_id']??0); $matchId=(int)($_POST['match_id']??0);
 try {
  $check=$pdo->prepare("SELECT ms.schedule_id FROM match_schedules ms
    JOIN matches m ON m.match_id=ms.match_id
    JOIN categories cat ON cat.category_id=m.category_id
    JOIN courts c ON c.court_id=? AND c.tournament_id=cat.tournament_id
    WHERE ms.match_id=? AND cat.tournament_id=? AND m.match_status IN ('Pending','Ready','Scheduled')");
  $check->execute([$courtId,$matchId]);
  if (!$check->fetchColumn()) throw new RuntimeException('Choose a scheduled match and a court from this tournament.');
  // Clear the previous manual "next" marker on this court so the newly
  // selected match is the one shown first in its queue.
  $clear=$pdo->prepare("UPDATE match_schedules ms JOIN matches m ON m.match_id=ms.match_id
    SET ms.is_manual_override=0 WHERE ms.court_id=? AND m.match_status IN ('Pending','Ready','Scheduled')");
  $clear->execute([$courtId]);
  $move=$pdo->prepare("UPDATE match_schedules SET court_id=?, is_manual_override=1 WHERE match_id=?");
  $move->execute([$courtId,$matchId]);
  $assignmentMessage='Next game assignment updated.';
 } catch(Throwable $e) { $assignmentError=$e->getMessage(); }
}
$courts=[];$now=[];$next=[];$upcomingChoices=[];
if($tournamentId){
 $q=$pdo->prepare("SELECT m.match_id,m.round_name,m.match_number,cat.category_name,b.bracket_order,
   e1.entry_name entry1_name,e2.entry_name entry2_name,c.court_name
   FROM matches m JOIN match_schedules ms ON ms.match_id=m.match_id
   JOIN categories cat ON cat.category_id=m.category_id
   LEFT JOIN brackets b ON b.bracket_id=m.bracket_id
   LEFT JOIN courts c ON c.court_id=ms.court_id
   LEFT JOIN tournament_entries e1 ON e1.entry_id=m.entry1_id
   LEFT JOIN tournament_entries e2 ON e2.entry_id=m.entry2_id
   WHERE cat.tournament_id=? AND m.match_status IN ('Pending','Ready','Scheduled')
   ORDER BY ms.is_manual_override DESC,ms.schedule_order,m.match_id");
 $q->execute([$tournamentId]);$upcomingChoices=$q->fetchAll(PDO::FETCH_ASSOC);
 $q=$pdo->prepare("SELECT court_id,court_name FROM courts WHERE tournament_id=? AND is_active=1 ORDER BY court_name");$q->execute([$tournamentId]);$courts=$q->fetchAll(PDO::FETCH_ASSOC);
 foreach($courts as $court){
  $q=$pdo->prepare("SELECT m.match_id,m.match_status,m.round_name,m.match_number,e1.entry_name entry1_name,e2.entry_name entry2_name,ms.schedule_order,
   cat.category_name,b.bracket_order
   FROM matches m JOIN match_schedules ms ON ms.match_id=m.match_id
   LEFT JOIN categories cat ON cat.category_id=m.category_id LEFT JOIN brackets b ON b.bracket_id=m.bracket_id
   LEFT JOIN tournament_entries e1 ON e1.entry_id=m.entry1_id LEFT JOIN tournament_entries e2 ON e2.entry_id=m.entry2_id
   WHERE ms.court_id=? AND m.match_status='Ongoing' ORDER BY ms.schedule_order LIMIT 1");$q->execute([$court['court_id']]);$now[$court['court_id']]=$q->fetch(PDO::FETCH_ASSOC);
  $q=$pdo->prepare("SELECT m.match_id,m.match_status,m.round_name,m.match_number,e1.entry_name entry1_name,e2.entry_name entry2_name,ms.schedule_order,
   cat.category_name,b.bracket_order
   FROM matches m JOIN match_schedules ms ON ms.match_id=m.match_id
   LEFT JOIN categories cat ON cat.category_id=m.category_id LEFT JOIN brackets b ON b.bracket_id=m.bracket_id
   LEFT JOIN tournament_entries e1 ON e1.entry_id=m.entry1_id LEFT JOIN tournament_entries e2 ON e2.entry_id=m.entry2_id
   WHERE ms.court_id=? AND m.match_status IN ('Ready','Scheduled','Pending') ORDER BY ms.is_manual_override DESC,ms.schedule_order LIMIT 1");$q->execute([$court['court_id']]);$next[$court['court_id']]=$q->fetch(PDO::FETCH_ASSOC);
 }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>COURTCTRL Display</title>
<link rel="stylesheet" href="assets/css/style.css"></head><body class="page-display-screen">
<header class="display-head"><h1>COURTCTRL · NOW PLAYING</h1><div class="head-actions"><span class="tv-refresh-status" data-refresh-indicator role="status" aria-live="polite">Updated --:--:--</span><button type="button" class="screen-btn" onclick="toggleFullscreen()">⛶ FULL SCREEN</button><form><select name="tournament_id" onchange="this.form.submit()"><?php foreach($tournaments as $t):?><option value="<?=e($t['tournament_id'])?>" <?=((int)$t['tournament_id']===$tournamentId?'selected':'')?>><?=e($t['tournament_name'])?></option><?php endforeach;?></select></form></div></header>
<?php if($assignmentMessage):?><div class="assignment-message"><?=e($assignmentMessage)?></div><?php endif;?>
<?php if($assignmentError):?><div class="assignment-error"><?=e($assignmentError)?></div><?php endif;?>
<details class="assignment-tools"><summary>MANAGE NEXT GAME ASSIGNMENTS</summary><div class="assignment-grid">
<?php foreach($courts as $court):?>
<form method="post" class="assignment-form">
<input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>">
<input type="hidden" name="court_id" value="<?=e($court['court_id'])?>">
<strong><?=e($court['court_name'])?> · NEXT GAME</strong>
<select name="match_id" required><option value="">Select a match...</option>
<?php foreach($upcomingChoices as $choice):?>
<option value="<?=e($choice['match_id'])?>"><?=e($choice['category_name'])?> · Bracket <?=e(chr(64+max(1,(int)$choice['bracket_order'])))?> · <?=e($choice['entry1_name']??'TBD')?> vs <?=e($choice['entry2_name']??'TBD')?> (currently <?=e($choice['court_name']??'Unassigned')?>)</option>
<?php endforeach;?></select><button name="assign_next_match" value="1">Assign next game</button>
</form><?php endforeach;?></div></details>
<div class="boards" data-refresh-container data-refresh-interval="3000"><?php foreach($courts as $court):$a=$now[$court['court_id']]??null;$b=$next[$court['court_id']]??null;?>
<section class="court-board"><div class="court-title"><?=e($court['court_name'])?></div><div class="board-content">
<div class="panel"><h2>NOW PLAYING</h2><small>COURT #<?=e($court['court_name'])?></small><?php if($a):?><div class="team"><?=e($a['entry1_name']??'TBD')?></div><div class="versus">VS</div><div class="team"><?=e($a['entry2_name']??'TBD')?></div><span class="status">ONGOING</span><div class="game-meta"><?=e($a['category_name']??'')?> · Bracket <?=e(chr(64+(int)($a['bracket_order']??1)))?> · <?=e($a['round_name'])?> · Match <?=e($a['match_number'])?></div><?php else:?><div class="empty">COURT AVAILABLE</div><?php endif;?></div>
<div class="panel next"><h2>NEXT GAME</h2><small>ON <?=e($court['court_name'])?></small><?php if($b):?><div class="team"><?=e($b['entry1_name']??'TBD')?></div><div class="versus">VS</div><div class="team"><?=e($b['entry2_name']??'TBD')?></div><span class="status">UP NEXT</span><div class="game-meta"><?=e($b['category_name']??'')?> · Bracket <?=e(chr(64+(int)($b['bracket_order']??1)))?> · <?=e($b['round_name'])?> · Match <?=e($b['match_number'])?></div><?php else:?><div class="empty">NO UPCOMING MATCH</div><?php endif;?></div>
</div></section><?php endforeach;?></div><script>
function toggleFullscreen(){
 const target=document.documentElement;
 if(!document.fullscreenElement){if(target.requestFullscreen)target.requestFullscreen();}
 else if(document.exitFullscreen){document.exitFullscreen();}
}

</script><script src="assets/js/app.js" defer></script></body></html>
