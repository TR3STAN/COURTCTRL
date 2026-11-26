<?php
session_start();
require_once __DIR__.'/config/database.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$errors=[];$success='';
$tournaments=$pdo->query("SELECT tournament_id,tournament_name FROM tournaments ORDER BY created_at DESC")->fetchAll();
$tid=(int)($_GET['tournament_id']??$_POST['tournament_id']??($_SESSION['active_tournament_id']??($tournaments[0]['tournament_id']??0))); if(!in_array($tid,array_map('intval',array_column($tournaments,'tournament_id')),true)){$tid=(int)($tournaments[0]['tournament_id']??0);} if($tid){$_SESSION['active_tournament_id']=$tid;}
$activeTournamentName=''; foreach($tournaments as $tour){if((int)$tour['tournament_id']===$tid){$activeTournamentName=$tour['tournament_name'];break;}}
$categories=[];
if($tid){$q=$pdo->prepare('SELECT category_id,category_name,category_type,game_format,teams_per_bracket,qualifying_teams FROM categories WHERE tournament_id=? ORDER BY category_name');$q->execute([$tid]);$categories=$q->fetchAll();}
$cid=(int)($_GET['category_id']??$_POST['category_id']??0);
$cat=null;foreach($categories as $c)if((int)$c['category_id']===$cid)$cat=$c;
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])){
 try{
  if($_POST['action']==='generate'){
   if(!$cat)throw new RuntimeException('Select a valid category.');
   $q=$pdo->prepare("SELECT entry_id,entry_name FROM tournament_entries WHERE category_id=? AND registration_status='Registered' ORDER BY entry_id");$q->execute([$cid]);$entries=$q->fetchAll();
   if(count($entries)<2)throw new RuntimeException('At least 2 registered entries are required.');
   $q=$pdo->prepare('SELECT COUNT(*) FROM matches WHERE category_id=?');$q->execute([$cid]);
   if((int)$q->fetchColumn()>0)throw new RuntimeException('Matches already exist for this category. Use Regenerate only if no scores or match progress have been recorded.');
   $bracketCount=(int)($_POST['bracket_count']??1);
   if($bracketCount<1 || $bracketCount>count($entries)) throw new RuntimeException('Bracket count must be between 1 and the number of registered entries.');
   $format=$cat['game_format'];
   if($format==='Single Elimination' && $bracketCount>1) throw new RuntimeException('Single Elimination currently uses one bracket. Choose Round Robin or Hybrid to divide entries into multiple groups.');
   shuffle($entries);
   $groups=array_fill(0,$bracketCount,[]);
   foreach($entries as $idx=>$entry){$groups[$idx % $bracketCount][]=$entry;}
   $pdo->beginTransaction();$n=0;
   if($format==='Round Robin' || $format==='Round Robin + Elimination'){
    foreach($groups as $gidx=>$groupEntries){
      $bname=($format==='Round Robin'?'Bracket ':'Group ').chr(65+$gidx);
      $q=$pdo->prepare('INSERT INTO brackets(category_id,bracket_name,bracket_order) VALUES(?,?,?)');
      $q->execute([$cid,$bname,$gidx+1]);$bid=(int)$pdo->lastInsertId();
      for($i=0;$i<count($groupEntries);$i++)for($j=$i+1;$j<count($groupEntries);$j++){
        $n++;$ins=$pdo->prepare("INSERT INTO matches(category_id,bracket_id,round_name,match_number,entry1_id,entry2_id,match_status) VALUES(?,?,?,?,?,?,'Pending')");
        $ins->execute([$cid,$bid,'Round Robin',$n,$groupEntries[$i]['entry_id'],$groupEntries[$j]['entry_id']]);
      }
    }
   }else{
    $size=1;while($size<count($entries))$size*=2;
    $rounds=(int)log($size,2);$bracketIds=[];
    for($r=1;$r<=$rounds;$r++){ $q=$pdo->prepare('INSERT INTO brackets(category_id,bracket_name,bracket_order) VALUES(?,?,?)');$q->execute([$cid,$r===$rounds?'Final':($r===1?'Round of '.$size:'Round '.$r),$r]);$bracketIds[]=(int)$pdo->lastInsertId();}
    $matchIdsByRound=[];
    for($r=1;$r<=$rounds;$r++){
      $matchesThisRound=$size/(2**$r);$roundIds=[];
      for($m=1;$m<=$matchesThisRound;$m++){
        $n++;$roundName=$r===$rounds?'Final':($r===$rounds-1?'Semifinal':($r===1?'Round of '.$size:'Round '.$r));
        $e1=null;$e2=null;
        if($r===1){$idx=($m-1)*2;if(isset($entries[$idx]))$e1=$entries[$idx]['entry_id'];if(isset($entries[$idx+1]))$e2=$entries[$idx+1]['entry_id'];}
        $ins=$pdo->prepare("INSERT INTO matches(category_id,bracket_id,round_name,match_number,entry1_id,entry2_id,match_status) VALUES(?,?,?,?,?,?, 'Pending')");
        $ins->execute([$cid,$bracketIds[$r-1],$roundName,$n,$e1,$e2]);$roundIds[]=(int)$pdo->lastInsertId();
      }
      $matchIdsByRound[]=$roundIds;
    }
    for($r=0;$r<count($matchIdsByRound)-1;$r++)foreach($matchIdsByRound[$r] as $idx=>$mid){
      $next=$matchIdsByRound[$r+1][intdiv($idx,2)];$slot=$idx%2===0?'Entry1':'Entry2';
      $pdo->prepare('UPDATE matches SET next_match_id=?,winner_slot=? WHERE match_id=?')->execute([$next,$slot,$mid]);
    }
   }
   $pdo->commit();header('Location: brackets.php?tournament_id='.$tid.'&category_id='.$cid.'&generated=1');exit;
  }elseif($_POST['action']==='regenerate'){
   if(!$cat)throw new RuntimeException('Select a valid category.');
   $q=$pdo->prepare('SELECT COUNT(*) FROM match_scores ms JOIN matches m ON m.match_id=ms.match_id WHERE m.category_id=?');$q->execute([$cid]);
   if((int)$q->fetchColumn()>0)throw new RuntimeException('Cannot regenerate: scores already exist.');
   $q=$pdo->prepare("SELECT COUNT(*) FROM matches WHERE category_id=? AND match_status NOT IN ('Pending')");$q->execute([$cid]);
   if((int)$q->fetchColumn()>0)throw new RuntimeException('Cannot regenerate: some matches have progressed beyond Pending.');
   $pdo->beginTransaction();$pdo->prepare('DELETE FROM matches WHERE category_id=?')->execute([$cid]);$pdo->prepare('DELETE FROM brackets WHERE category_id=?')->execute([$cid]);$pdo->commit();
   header('Location: brackets.php?tournament_id='.$tid.'&category_id='.$cid.'&cleared=1');exit;
  }
 }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();$errors[]=$ex instanceof RuntimeException?$ex->getMessage():'Bracket action failed. Check database configuration.';}
}
if(isset($_GET['generated']))$success='Matches generated successfully.';
if(isset($_GET['cleared']))$success='Existing bracket cleared. You can now generate it again.';
$matches=[];$entryNames=[];
if($cat){
 $q=$pdo->prepare("SELECT m.*,b.bracket_name,b.bracket_order FROM matches m LEFT JOIN brackets b ON b.bracket_id=m.bracket_id WHERE m.category_id=? ORDER BY m.match_number");$q->execute([$cid]);$matches=$q->fetchAll();
 foreach($matches as $m)foreach(['entry1_id','entry2_id','winner_entry_id'] as $key)if($m[$key])$entryNames[(int)$m[$key]]='';
 if($entryNames){$ids=array_keys($entryNames);$in=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare("SELECT entry_id,entry_name FROM tournament_entries WHERE entry_id IN ($in)");$q->execute($ids);foreach($q->fetchAll() as $row)$entryNames[(int)$row['entry_id']]=$row['entry_name'];}
}
$currentPage=basename($_SERVER['PHP_SELF']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Brackets | CourtCtrl</title><link rel="stylesheet" href="assets/css/style.css"></head><body class="page-brackets"><div class="app-shell"><aside class="sidebar"><div class="brand"><div class="brand-mark">P</div><div><strong>COURT<span>CTRL</span></strong><small>TOURNAMENT ORGANIZER</small></div></div><div class="nav-label">WORKSPACE</div><a class="nav-item" href="index.php"><span>▦</span> Dashboard</a><a class="nav-item" href="workspace.php?tournament_id=<?= (int)$tid ?>"><span>♜</span> Tournament Workspace</a><a class="nav-item" href="tournaments.php?id=<?= (int)$tid ?>"><span>⚙</span> Tournament Setup</a><a class="nav-item" href="players.php?tournament_id=<?= (int)$tid ?>"><span>♙</span> Players &amp; Teams</a><a class="nav-item active" href="brackets.php?tournament_id=<?= (int)$tid ?>"><span>⌘</span> Brackets</a><a class="nav-item" href="schedule.php?tournament_id=<?= (int)$tid ?>"><span>▦</span> Schedule &amp; Courts</a><a class="nav-item" href="live_feed.php?tournament_id=<?= (int)$tid ?>"><span>◉</span> Live Feed</a><a class="nav-item" href="scoring.php?tournament_id=<?= (int)$tid ?>"><span>☷</span> Scoring &amp; Standings</a><div class="sidebar-bottom"><div class="help-dot">?</div><div><strong>Organizer workspace</strong><small>COURTCTRL tournament control</small></div></div></aside>
<main class="main-content"><header class="topbar"><div class="crumb">Workspace <span>/</span> Brackets</div><span class="context-label">ACTIVE TOURNAMENT</span><strong><?=e($activeTournamentName ?: 'No tournament selected')?></strong></span><div class="profile"><div class="avatar">A</div><div><strong>Administrator</strong><small>Tournament organizer</small></div></div></header><div class="active-tournament-strip"><span>ACTIVE TOURNAMENT</span><strong><?=e($activeTournamentName ?: 'No tournament selected')?></strong><a href="workspace.php?tournament_id=<?= (int)($_SESSION["active_tournament_id"] ?? 0) ?>">Switch</a></div>
<section class="page-heading"><div><p class="eyebrow">MATCH GENERATION</p><h1>Brackets</h1><p class="subheading">Generate and organize bracket groups by tournament category. · <strong class="selected-tournament-inline"><?=e($activeTournamentName ?: 'No tournament selected')?></strong></p></div><a class="btn btn-outline" href="workspace.php?tournament_id=<?= (int)$tid ?>">Tournament Workspace</a></section>
<?php if($success):?><div class="notice"><?=e($success)?></div><?php endif;?><?php foreach($errors as $err):?><div class="alert error"><?=e($err)?></div><?php endforeach;?>
<section class="panel"><div class="panel-heading"><div><h2>Select category</h2><p>Tournament is selected in the Tournament Workspace. Choose a category to manage its bracket.</p></div></div><div class="panel-body"><form method="get" class="form-row"><input type="hidden" name="tournament_id" value="<?=$tid?>"><label class="field">Category<select name="category_id" onchange="this.form.submit()"><option value="" disabled <?=$cid===0?'selected':''?>>Select a category...</option><?php foreach($categories as $c):?><option value="<?=$c['category_id']?>" <?=$cid===$c['category_id']?'selected':''?>><?=e($c['category_name'])?> · <?=e($c['game_format'])?></option><?php endforeach;?></select></label></form>
<?php if($cat):?>
<form method="post" class="form-row" style="margin-top:8px">
<input type="hidden" name="action" value="generate"><input type="hidden" name="tournament_id" value="<?=$tid?>"><input type="hidden" name="category_id" value="<?=$cid?>">
<label class="field">Number of brackets / groups<select name="bracket_count">
<?php $registeredCountQuery=$pdo->prepare("SELECT COUNT(*) FROM tournament_entries WHERE category_id=? AND registration_status='Registered'");$registeredCountQuery->execute([$cid]);$registeredCount=(int)$registeredCountQuery->fetchColumn();for($bc=1;$bc<=max(1,min(20,$registeredCount));$bc++):?><option value="<?=$bc?>" <?=$bc===1?'selected':''?>><?=$bc?> bracket(s)</option><?php endfor;?>
</select></label><div class="field" style="justify-content:end"><button class="btn btn-primary" type="submit" data-confirm="Randomly distribute registered entries into brackets?">＋ Generate brackets</button></div></form><div class="actions"><?php if($matches):?><form method="post"><input type="hidden" name="action" value="regenerate"><input type="hidden" name="tournament_id" value="<?=$tid?>"><input type="hidden" name="category_id" value="<?=$cid?>"><button class="btn btn-outline btn-small" type="submit" data-confirm="Clear this bracket? Only pending matches without scores can be cleared.">Clear / Regenerate</button></form><?php endif;?></div><p class="muted" style="margin-top:12px">Format: <strong><?=e($cat['game_format'])?></strong> · Registered entries: <strong><?php $q=$pdo->prepare("SELECT COUNT(*) FROM tournament_entries WHERE category_id=? AND registration_status='Registered'");$q->execute([$cid]);echo (int)$q->fetchColumn();?></strong></p><?php endif;?></div></section>
<?php if(!$cat):?><section class="panel" style="margin-top:16px"><div class="empty">No categories available. Create a tournament and category, then register entries.</div></section><?php elseif(!$matches):?><section class="panel" style="margin-top:16px"><div class="empty">No brackets generated yet. Use Generate brackets to create groups. Matchups will be handled in Schedule &amp; Courts.</div></section><?php else:
// The group-bracket overview must contain Round Robin groups only.
// Elimination matches have their own bracket rows and must not be rendered as
// additional Round Robin groups after the knockout stage is generated.
$groupMatches=array_values(array_filter($matches,static function($match){
    return strcasecmp(trim((string)($match['round_name']??'')),'Round Robin')===0;
}));
$eliminationMatchCount=count($matches)-count($groupMatches);
$bracketSummary=[];
$bracketPalette=['#2563EB','#EA580C','#16A34A','#9333EA','#E11D48','#0891B2','#CA8A04','#4F46E5','#0F766E','#C2410C','#7C3AED','#BE185D','#0369A1','#65A30D','#A21CAF','#B45309','#0D9488','#DB2777','#475569','#4D7C0F'];
foreach($groupMatches as $match){
    $key=(int)($match['bracket_id']??0);
    if(!isset($bracketSummary[$key]))$bracketSummary[$key]=['name'=>$match['bracket_name']?:'Group','order'=>(int)($match['bracket_order']??1),'entries'=>[],'matches'=>0];
    $bracketSummary[$key]['matches']++;
    foreach(['entry1_id','entry2_id'] as $entryKey){
        $entryId=(int)($match[$entryKey]??0);
        if($entryId>0)$bracketSummary[$key]['entries'][$entryId]=$entryNames[$entryId]??'Entry';
    }
}
?>
<section class="panel" style="margin-top:16px"><div class="panel-heading"><div><h2>Round Robin groups</h2><p>Only group-stage brackets appear here. Elimination rounds are managed separately in Scoring &amp; Standings.</p></div><span class="count-pill"><?=count($bracketSummary)?> groups</span></div>
<div class="panel-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
<a class="btn btn-outline" href="export_brackets.php?tournament_id=<?=$tid?>&amp;category_id=<?=$cid?>">↓ Export brackets to Excel</a>
<a class="btn btn-primary" href="schedule.php?tournament_id=<?=$tid?>&amp;category_id=<?=$cid?>">Manage schedule &amp; courts</a>
<span class="muted" style="font-size:11px"><?= (int)$eliminationMatchCount ?> elimination match(es) are kept separate<?= $eliminationMatchCount ? ' and included in the Excel export' : '' ?>.</span>
</div>
<?php if(!$bracketSummary):?><div class="empty">No Round Robin groups found. Generate the group stage first.</div><?php else:?><div class="bracket-list">
<?php foreach($bracketSummary as $summary):$groupColor=$bracketPalette[(max(1,(int)$summary['order'])-1)%count($bracketPalette)];?>
<article class="round-card" style="--bracket-color:<?=$groupColor?>;border-top:4px solid <?=$groupColor?>"><div class="round-title" style="background:<?=$groupColor?>18;color:<?=$groupColor?>"><?=e($summary['name'])?></div><div class="match-card"><div class="match-label"><?=count($summary['entries'])?> entries · <?=e($summary['matches'])?> generated matchups</div><?php foreach($summary['entries'] as $entryName):?><div class="teamline"><strong><?=e($entryName)?></strong></div><?php endforeach;?></div></article>
<?php endforeach;?></div><?php endif;?></section><?php endif;?>
<footer class="footer">COURTCTRL <span>·</span> TOURNAMENT ORGANIZER</footer></main></div>
<script>
(function () {
  const scrollKey = 'courtctrl-scroll:' + window.location.pathname;
  document.addEventListener('submit', function (event) {
    const form = event.target;
    if (form && form.method && form.method.toLowerCase() === 'post') {
      try {
        sessionStorage.setItem(scrollKey, String(window.scrollY || window.pageYOffset || 0));
      } catch (e) {}
    }
  }, true);

  window.addEventListener('DOMContentLoaded', function () {
    try {
      const savedY = sessionStorage.getItem(scrollKey);
      if (savedY !== null) {
        sessionStorage.removeItem(scrollKey);
        window.scrollTo(0, parseInt(savedY, 10) || 0);
      }
    } catch (e) {}

    const feedback = document.querySelectorAll(
      '#action-feedback, .alert.success, .alert-success, .alert.error, .alert-error, .notice'
    );
    feedback.forEach(function (item, index) {
      if (index > 0 && item.classList.contains('toastify')) return;
      item.classList.add('courtctrl-toast');
      item.setAttribute('role', item.classList.contains('error') || item.classList.contains('alert-error') ? 'alert' : 'status');
      if (!item.querySelector('.courtctrl-toast-close')) {
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'courtctrl-toast-close';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.innerHTML = '&times;';
        close.addEventListener('click', function () { item.remove(); });
        item.appendChild(close);
      }
      if (!item.classList.contains('error') && !item.classList.contains('alert-error') &&
          !item.classList.contains('alert') || item.classList.contains('success') || item.classList.contains('alert-success')) {
        window.setTimeout(function () {
          item.classList.add('courtctrl-toast-hide');
          window.setTimeout(function () { if (item.parentNode) item.remove(); }, 300);
        }, 5000);
      }
    });
  });
})();
</script>


<script src="assets/js/app.js" defer></script>
</body></html>
