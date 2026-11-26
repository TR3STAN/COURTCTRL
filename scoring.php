<?php
session_start();
// Keep PHP file paths/line numbers out of user-facing notifications.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/config/database.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function bracketLabel($n){$n=max(1,(int)$n);$s='';while($n){$n--; $s=chr(65+$n%26).$s;$n=intdiv($n,26);}return $s;}
function advanceWinner(PDO $pdo,int $matchId,int $winnerId):void{
 $q=$pdo->prepare("SELECT next_match_id,winner_slot FROM matches WHERE match_id=?");$q->execute([$matchId]);$m=$q->fetch(PDO::FETCH_ASSOC);
 if(!$m||empty($m['next_match_id'])||empty($m['winner_slot']))return;
 $column=$m['winner_slot']==='Entry1'?'entry1_id':'entry2_id';
 $up=$pdo->prepare("UPDATE matches SET {$column}=?,match_status=IF(match_status='Pending','Ready',match_status) WHERE match_id=?");
 $up->execute([$winnerId,(int)$m['next_match_id']]);
}


function makeDemoGameScores(int $target,int $winByTwo):array{
 $margin=$winByTwo?random_int(2,5):random_int(1,3);
 if($winByTwo){$winnerScore=$target+random_int(0,3);$loserScore=max(0,$winnerScore-$margin);}
 else{$winnerScore=$target;$loserScore=random_int(0,max(0,$target-1));}
 return [$winnerScore,$loserScore];
}
function autoCompleteStage(PDO $pdo,int $categoryId,string $stage):array{
 $where=$stage==='rr'?"LOWER(m.round_name)='round robin'":"LOWER(m.round_name)<>'round robin'";
 $sql="SELECT m.match_id,m.round_name FROM matches m LEFT JOIN brackets b ON b.bracket_id=m.bracket_id WHERE m.category_id=? AND {$where} ORDER BY COALESCE(b.bracket_order,0),m.match_number,m.match_id";
 $q=$pdo->prepare($sql);$q->execute([$categoryId]);$matches=$q->fetchAll(PDO::FETCH_ASSOC);
 $completed=0;$skipped=0;$pending=0;
 foreach($matches as $match){
  $matchId=(int)$match['match_id'];
  $fresh=$pdo->prepare("SELECT entry1_id,entry2_id,winner_entry_id,match_status FROM matches WHERE match_id=? AND category_id=?");
  $fresh->execute([$matchId,$categoryId]);$live=$fresh->fetch(PDO::FETCH_ASSOC);
  if(!$live){$skipped++;continue;}
  if(in_array($live['match_status'],['Cancelled','Skipped'],true)){$skipped++;continue;}
  if($live['match_status']==='Completed'&&!empty($live['winner_entry_id'])){$skipped++;continue;}
  if(empty($live['entry1_id'])||empty($live['entry2_id'])){$pending++;continue;}
  $existing=$pdo->prepare("SELECT COUNT(*) FROM match_scores WHERE match_id=?");$existing->execute([$matchId]);
  if((int)$existing->fetchColumn()>0){$skipped++;continue;}
  $settings=roundScoring($pdo,$categoryId,(string)$match['round_name'],['games_per_match'=>1,'points_to_win'=>11,'win_by_two'=>1]);
  $games=max(1,min(3,(int)$settings['games_per_match']));$target=max(1,(int)$settings['points_to_win']);$winByTwo=(int)$settings['win_by_two'];$gamesNeeded=(int)ceil($games/2);
  $matchWinner=random_int(0,1)===0?(int)$live['entry1_id']:(int)$live['entry2_id'];
  $insert=$pdo->prepare("INSERT INTO match_scores(match_id,game_number,entry1_score,entry2_score) VALUES(?,?,?,?)");
  for($gameNumber=1;$gameNumber<=$gamesNeeded;$gameNumber++){
   [$winnerScore,$loserScore]=makeDemoGameScores($target,$winByTwo);
   if($matchWinner===(int)$live['entry1_id'])$insert->execute([$matchId,$gameNumber,$winnerScore,$loserScore]);
   else $insert->execute([$matchId,$gameNumber,$loserScore,$winnerScore]);
  }
  $update=$pdo->prepare("UPDATE matches SET winner_entry_id=?,match_status='Completed' WHERE match_id=?");$update->execute([$matchWinner,$matchId]);
  advanceWinner($pdo,$matchId,$matchWinner);$demoLoser=$matchWinner===(int)$live['entry1_id']?(int)$live['entry2_id']:(int)$live['entry1_id'];advanceLoser($pdo,$matchId,$demoLoser);$completed++;
 }
 return ['completed'=>$completed,'skipped'=>$skipped,'pending'=>$pending];
}

$tournaments=$pdo->query("SELECT tournament_id,tournament_name FROM tournaments ORDER BY tournament_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$requestedTournamentId=(int)($_GET['tournament_id']??$_POST['tournament_id']??0); $tournamentId=$requestedTournamentId>0?$requestedTournamentId:(int)($_SESSION['active_tournament_id']??0); if($tournamentId<=0||!in_array($tournamentId,array_map('intval',array_column($tournaments,'tournament_id')),true)){$tournamentId=(int)($tournaments[0]['tournament_id']??0);} if($tournamentId){$_SESSION['active_tournament_id']=$tournamentId;}
$activeTournamentName=''; foreach($tournaments as $tour){if((int)$tour['tournament_id']===$tournamentId){$activeTournamentName=$tour['tournament_name'];break;}}
$categories=[];if($tournamentId){$q=$pdo->prepare("SELECT category_id,category_name FROM categories WHERE tournament_id=? ORDER BY category_name");$q->execute([$tournamentId]);$categories=$q->fetchAll(PDO::FETCH_ASSOC);}
$categoryId=(int)($_GET['category_id']??$_POST['category_id']??0);
$activeCourts=[];if($tournamentId){$cq=$pdo->prepare("SELECT court_id,court_name FROM courts WHERE tournament_id=? AND is_active=1 ORDER BY court_name");$cq->execute([$tournamentId]);$activeCourts=$cq->fetchAll(PDO::FETCH_ASSOC);}
$pdo->exec("CREATE TABLE IF NOT EXISTS category_scoring_settings (category_id INT NOT NULL PRIMARY KEY,games_per_match TINYINT NOT NULL DEFAULT 1,points_to_win TINYINT NOT NULL DEFAULT 11,win_by_two TINYINT NOT NULL DEFAULT 1,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS category_round_scoring_settings (setting_id INT AUTO_INCREMENT PRIMARY KEY,category_id INT NOT NULL,round_name VARCHAR(80) NOT NULL,games_per_match TINYINT NOT NULL DEFAULT 1,points_to_win TINYINT NOT NULL DEFAULT 11,win_by_two TINYINT NOT NULL DEFAULT 1,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_category_round (category_id,round_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try{$pdo->exec("ALTER TABLE matches ADD COLUMN loser_next_match_id INT NULL, ADD COLUMN loser_slot VARCHAR(10) NULL");}catch(Throwable $ignored){}
function advanceLoser(PDO $pdo,int $matchId,int $loserId):void{$q=$pdo->prepare("SELECT loser_next_match_id,loser_slot FROM matches WHERE match_id=?");$q->execute([$matchId]);$m=$q->fetch(PDO::FETCH_ASSOC);if(!$m||empty($m['loser_next_match_id'])||empty($m['loser_slot']))return;$column=$m['loser_slot']==='Entry1'?'entry1_id':'entry2_id';$pdo->prepare("UPDATE matches SET {$column}=?,match_status=IF(match_status='Pending','Ready',match_status) WHERE match_id=?")->execute([$loserId,(int)$m['loser_next_match_id']]);}

function buildEliminationPlacements(array $matches,array $scoresByMatch):array{
 $byRound=[];foreach($matches as $m){$rn=trim((string)($m['round_name']??''));if(strcasecmp($rn,'Round Robin')!==0)$byRound[strtolower($rn)][]=$m;}
 $limit=isset($byRound['round of 16'])?16:(isset($byRound['quarterfinals'])?8:(isset($byRound['semifinals'])?4:0));
 $ranked=[];$podium=[1=>null,2=>null,3=>null];$fourth=null;$roundComplete=[];
 $losersFor=function(string $round)use($byRound,$scoresByMatch,&$roundComplete):array{
  $rows=$byRound[strtolower($round)]??[];$losers=[];$complete=(bool)$rows;
  foreach($rows as $m){if(empty($m['winner_entry_id'])||empty($m['entry1_id'])||empty($m['entry2_id'])){$complete=false;continue;}
   $winner=(int)$m['winner_entry_id'];$loser=$winner===(int)$m['entry1_id']?(int)$m['entry2_id']:(int)$m['entry1_id'];$scored=0;$conceded=0;
   foreach($scoresByMatch[(int)$m['match_id']]??[] as $s){$a=(int)$s['entry1_score'];$b=(int)$s['entry2_score'];if($loser===(int)$m['entry1_id']){$scored+=$a;$conceded+=$b;}else{$scored+=$b;$conceded+=$a;}}
   $losers[]=['id'=>$loser,'name'=>$loser===(int)$m['entry1_id']?($m['entry1_name']??'Unknown'):( $m['entry2_name']??'Unknown'),'scored'=>$scored,'conceded'=>$conceded,'diff'=>$scored-$conceded,'match_id'=>(int)$m['match_id']];
  }
  $roundComplete[strtolower($round)]=$complete;
  usort($losers,function($a,$b){return [$b['scored'],$b['diff'],$b['conceded']]<=>[$a['scored'],$a['diff'],$a['conceded']];});
  return ['complete'=>$complete,'losers'=>$losers,'count'=>count($rows)];
 };
 $finals=$byRound['final']??($byRound['finals']??[]);foreach($finals as $m){if(!empty($m['winner_entry_id'])&&!empty($m['entry1_id'])&&!empty($m['entry2_id'])){$winner=(int)$m['winner_entry_id'];$loser=$winner===(int)$m['entry1_id']?(int)$m['entry2_id']:(int)$m['entry1_id'];$podium[1]=['id'=>$winner,'name'=>$winner===(int)$m['entry1_id']?($m['entry1_name']??'Unknown'):($m['entry2_name']??'Unknown')];$podium[2]=['id'=>$loser,'name'=>$loser===(int)$m['entry1_id']?($m['entry1_name']??'Unknown'):($m['entry2_name']??'Unknown')];break;}}
 $bronze=$byRound['bronze match']??[];foreach($bronze as $m){if(!empty($m['winner_entry_id'])&&!empty($m['entry1_id'])&&!empty($m['entry2_id'])){$winner=(int)$m['winner_entry_id'];$loser=$winner===(int)$m['entry1_id']?(int)$m['entry2_id']:(int)$m['entry1_id'];$podium[3]=['id'=>$winner,'name'=>$winner===(int)$m['entry1_id']?($m['entry1_name']??'Unknown'):($m['entry2_name']??'Unknown')];$fourth=['id'=>$loser,'name'=>$loser===(int)$m['entry1_id']?($m['entry1_name']??'Unknown'):($m['entry2_name']??'Unknown')];break;}}
 foreach([1,2,3] as $r){if($podium[$r])$ranked[$r]=$podium[$r];}if($fourth)$ranked[4]=$fourth;
 if($limit>=8){$qf=$losersFor('Quarterfinals');if($qf['complete']&&count($qf['losers'])===4){foreach($qf['losers'] as $i=>$row)$ranked[5+$i]=$row;}}
 if($limit>=16){$r16=$losersFor('Round of 16');if($r16['complete']&&count($r16['losers'])===8){foreach($r16['losers'] as $i=>$row)$ranked[9+$i]=$row;}}
 return ['limit'=>$limit,'ranked'=>$ranked,'podium'=>$podium,'fourth'=>$fourth,'round_complete'=>$roundComplete];
}
$scoringSettings=['games_per_match'=>1,'points_to_win'=>11,'win_by_two'=>1];
$categoryFormatSaved=false;
if($categoryId){$savedCheck=$pdo->prepare('SELECT COUNT(*) FROM category_scoring_settings WHERE category_id=?');$savedCheck->execute([$categoryId]);$categoryFormatSaved=(int)$savedCheck->fetchColumn()>0;}
if($categoryId){$q=$pdo->prepare("SELECT games_per_match,points_to_win,win_by_two FROM category_scoring_settings WHERE category_id=?");$q->execute([$categoryId]);$saved=$q->fetch(PDO::FETCH_ASSOC);if($saved)$scoringSettings=array_merge($scoringSettings,$saved);}
function roundScoring(PDO $pdo,int $categoryId,string $roundName,array $fallback):array{
 $q=$pdo->prepare("SELECT games_per_match,points_to_win,win_by_two FROM category_round_scoring_settings WHERE category_id=? AND round_name=?");
 $q->execute([$categoryId,$roundName]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r?array_merge($fallback,$r):$fallback;
}

$notice='';$error='';
$activeStage = (($_GET['active_stage'] ?? '') === 'elim') ? 'elim' : 'rr';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $action=$_POST['action']??'';
$activeStage=(($_POST['active_stage']??'')==='elim')?'elim':'rr';$matchId=(int)($_POST['match_id']??0);
  if($action==='save_scoring_settings'){
   $games=max(1,min(3,(int)($_POST['games_per_match']??1)));$points=max(1,min(99,(int)($_POST['points_to_win']??11)));$winByTwo=isset($_POST['win_by_two'])?1:0;
   $q=$pdo->prepare("INSERT INTO category_scoring_settings(category_id,games_per_match,points_to_win,win_by_two) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE games_per_match=VALUES(games_per_match),points_to_win=VALUES(points_to_win),win_by_two=VALUES(win_by_two)");
   $q->execute([$categoryId,$games,$points,$winByTwo]);$scoringSettings=['games_per_match'=>$games,'points_to_win'=>$points,'win_by_two'=>$winByTwo];$categoryFormatSaved=true;$notice='Scoring format saved for this category.';
  }elseif($action==='save_round_scoring_settings'){
   $roundName=trim((string)($_POST['round_name']??''));
   if($roundName==='')throw new RuntimeException('Choose a round.');
   $games=max(1,min(3,(int)($_POST['games_per_match']??1)));$points=max(1,min(99,(int)($_POST['points_to_win']??11)));$winByTwo=isset($_POST['win_by_two'])?1:0;
   $q=$pdo->prepare("INSERT INTO category_round_scoring_settings(category_id,round_name,games_per_match,points_to_win,win_by_two) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE games_per_match=VALUES(games_per_match),points_to_win=VALUES(points_to_win),win_by_two=VALUES(win_by_two)");
   $q->execute([$categoryId,$roundName,$games,$points,$winByTwo]);$notice="Scoring format saved for {$roundName}.";
  }elseif($action==='auto_complete_round_robin' || $action==='auto_complete_elimination'){
   if(!$categoryId)throw new RuntimeException('Select a category first.');
   $validCategory=$pdo->prepare('SELECT category_id FROM categories WHERE category_id=? AND tournament_id=?');
   $validCategory->execute([$categoryId,$tournamentId]);
   if(!$validCategory->fetchColumn())throw new RuntimeException('The selected category does not belong to the active tournament.');
   $stage=$action==='auto_complete_round_robin'?'rr':'elim';
   $pdo->beginTransaction();
   $result=autoCompleteStage($pdo,$categoryId,$stage);
   $pdo->commit();
   $stageLabel=$stage==='rr'?'Round Robin':'elimination';
   $notice="Demo scoring finished: {$result['completed']} {$stageLabel} match(es) completed, {$result['skipped']} existing/scored match(es) preserved, {$result['pending']} match(es) left pending because an entry is still TBD.";
  }elseif($action==='generate_elimination'){
   $qualify=max(1,min(4,(int)($_POST['qualify_per_bracket']??2)));
   $q=$pdo->prepare("SELECT COUNT(*) FROM matches WHERE category_id=? AND LOWER(round_name)='round robin' AND match_status<>'Completed'");$q->execute([$categoryId]);
   $unfinished=(int)$q->fetchColumn();
   if($unfinished>0)throw new RuntimeException("There are {$unfinished} unfinished Round Robin matches. Complete them first, then generate the elimination stage.");
   $q=$pdo->prepare("SELECT COUNT(*) FROM matches WHERE category_id=? AND LOWER(round_name)<>'round robin'");$q->execute([$categoryId]);
   $existingElimination=(int)$q->fetchColumn();
   if($existingElimination>0)throw new RuntimeException("This category already has {$existingElimination} elimination match(es). Delete the existing elimination matches/brackets before generating again.");
   $q=$pdo->prepare("SELECT m.bracket_id,b.bracket_name,b.bracket_order,m.entry1_id,m.entry2_id,m.winner_entry_id,SUM(ms.entry1_score) entry1_score,SUM(ms.entry2_score) entry2_score,e1.entry_name entry1_name,e2.entry_name entry2_name FROM matches m JOIN brackets b ON b.bracket_id=m.bracket_id JOIN match_scores ms ON ms.match_id=m.match_id LEFT JOIN tournament_entries e1 ON e1.entry_id=m.entry1_id LEFT JOIN tournament_entries e2 ON e2.entry_id=m.entry2_id WHERE m.category_id=? AND LOWER(m.round_name)='round robin' AND m.match_status='Completed' GROUP BY m.match_id,b.bracket_name,b.bracket_order,m.entry1_id,m.entry2_id,m.winner_entry_id,e1.entry_name,e2.entry_name ORDER BY b.bracket_order,m.match_number");
   $q->execute([$categoryId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);if(!$rows)throw new RuntimeException('No completed Round Robin scores were found. Save scores and mark each Round Robin match Completed first.');
   $tables=[];
   foreach($rows as $r){$bid=(int)$r['bracket_id'];foreach([(int)$r['entry1_id'],(int)$r['entry2_id']] as $eid){if(!$eid)continue;if(!isset($tables[$bid][$eid]))$tables[$bid][$eid]=['id'=>$eid,'name'=>($eid===(int)$r['entry1_id']?$r['entry1_name']:$r['entry2_name']),'wins'=>0,'pf'=>0,'pa'=>0,'bracket_name'=>$r['bracket_name'],'order'=>(int)$r['bracket_order']];}
    $a=(int)$r['entry1_id'];$b=(int)$r['entry2_id'];if(isset($tables[$bid][$a])&&isset($tables[$bid][$b])){$tables[$bid][$a]['pf']+=(int)$r['entry1_score'];$tables[$bid][$a]['pa']+=(int)$r['entry2_score'];$tables[$bid][$b]['pf']+=(int)$r['entry2_score'];$tables[$bid][$b]['pa']+=(int)$r['entry1_score'];}
    if($r['winner_entry_id']&&isset($tables[$bid][(int)$r['winner_entry_id']]))$tables[$bid][(int)$r['winner_entry_id']]['wins']++;
   }
   $groupRows=[];foreach($tables as $entries){usort($entries,function($x,$y){return [$y['wins'],$y['pf']-$y['pa'],$y['pf']]<=>[$x['wins'],$x['pf']-$x['pa'],$x['pf']];});$groupRows[]=$entries;}
   $qualified=[];for($rank=0;$rank<$qualify;$rank++)foreach($groupRows as $group)if(isset($group[$rank]))$qualified[]=$group[$rank];
   $count=count($qualified);if($count<2)throw new RuntimeException("Only {$count} entry/entries qualified. Choose more qualifiers per bracket.");
   $size=1;while($size<$count)$size*=2;if($size!==$count)throw new RuntimeException('Qualified entries must total a power of 2 (4, 8, 16, 32, etc.). Adjust qualifiers per bracket.');
   $roundNames=[];$remaining=$size;while($remaining>1){$roundNames[]=$remaining===2?'Final':($remaining===4?'Semifinals':($remaining===8?'Quarterfinals':($remaining===16?'Round of 16':'Round of '.$remaining)));$remaining=intdiv($remaining,2);}
   $pdo->beginTransaction();$bracketIds=[];$matchRounds=[];$matchNo=0;
   // Read occupied orders while inside the transaction, then explicitly choose
   // unused values. This avoids collisions with existing Group A-D rows.
   // Determine next order from the database itself (not from a PHP-side
   // cached/list value), and verify each candidate is unused before insert.
   // Fetch existing orders without relying on aggregate locking behavior.
   $q=$pdo->prepare("SELECT bracket_order FROM brackets WHERE category_id=? ORDER BY bracket_order");
   $q->execute([$categoryId]);
   $existingOrders=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
   // Start beyond the highest order found, with a safe high fallback.
   $nextBracketOrder=($existingOrders ? max($existingOrders) : 0)+1;
   foreach($roundNames as $ri=>$roundName){
    $inserted=false;
    for($attempt=0;$attempt<100;$attempt++){
     $check=$pdo->prepare("SELECT COUNT(*) FROM brackets WHERE category_id=? AND bracket_order=?");
     $check->execute([$categoryId,$nextBracketOrder]);
     if((int)$check->fetchColumn()>0){$nextBracketOrder++;continue;}
     try{
      $ins=$pdo->prepare("INSERT INTO brackets(category_id,bracket_name,bracket_order) VALUES(?,?,?)");
      $ins->execute([$categoryId,$roundName,$nextBracketOrder]);
      $bracketIds[]=(int)$pdo->lastInsertId();
      $nextBracketOrder++;$inserted=true;break;
     }catch(PDOException $ex){
      // If another insert occupied this order, retry with the next one.
      if(stripos($ex->getMessage(),'Duplicate entry')!==false){
       // Include the exact attempted values and existing orders for diagnosis.
       $debugOrders=$pdo->prepare("SELECT bracket_order FROM brackets WHERE category_id=? ORDER BY bracket_order");
       $debugOrders->execute([$categoryId]);
       $seenOrders=array_map('intval',$debugOrders->fetchAll(PDO::FETCH_COLUMN));
       throw new RuntimeException("Bracket insert collision. Category={$categoryId}; bracket={$roundName}; attempted_order={$nextBracketOrder}; existing_orders=".implode(',', $seenOrders)."; database_error=".$ex->getMessage(),0,$ex);
      }
      throw $ex;
     }
    }
    if(!$inserted)throw new RuntimeException("Could not allocate a unique bracket order for {$roundName}. Check the brackets table indexes and selected database.");
   }
   $firstRound=[];for($i=0;$i<$size/2;$i++){$left=$qualified[$i];$right=$qualified[$size-1-$i];$matchNo++;$q=$pdo->prepare("INSERT INTO matches(category_id,bracket_id,round_name,match_number,entry1_id,entry2_id,match_status) VALUES(?,?,?,?,?,?, 'Ready')");$q->execute([$categoryId,$bracketIds[0],$roundNames[0],$matchNo,$left['id'],$right['id']]);$firstRound[]=(int)$pdo->lastInsertId();}
   $matchRounds[]=$firstRound;
   for($ri=1;$ri<count($roundNames);$ri++){$previous=$matchRounds[$ri-1];$current=[];for($j=0;$j<count($previous)/2;$j++){$matchNo++;$q=$pdo->prepare("INSERT INTO matches(category_id,bracket_id,round_name,match_number,entry1_id,entry2_id,match_status) VALUES(?,?,?,?,NULL,NULL,'Pending')");$q->execute([$categoryId,$bracketIds[$ri],$roundNames[$ri],$matchNo]);$current[]=(int)$pdo->lastInsertId();}
    foreach($previous as $idx=>$mid){$next=$current[intdiv($idx,2)];$slot=$idx%2===0?'Entry1':'Entry2';$pdo->prepare("UPDATE matches SET next_match_id=?,winner_slot=? WHERE match_id=?")->execute([$next,$slot,$mid]);}$matchRounds[]=$current;}
   if($size>=4){$semis=$matchRounds[count($matchRounds)-2]??[];if(count($semis)===2){$bi=$pdo->prepare("INSERT INTO brackets(category_id,bracket_name,bracket_order) VALUES(?,?,?)");$bi->execute([$categoryId,'Bronze Match',$nextBracketOrder]);$bronzeBracketId=(int)$pdo->lastInsertId();$matchNo++;$bm=$pdo->prepare("INSERT INTO matches(category_id,bracket_id,round_name,match_number,entry1_id,entry2_id,match_status) VALUES(?,?,'Bronze Match',?,NULL,NULL,'Pending')");$bm->execute([$categoryId,$bronzeBracketId,$matchNo]);$bronzeId=(int)$pdo->lastInsertId();foreach($semis as $idx=>$semiId)$pdo->prepare("UPDATE matches SET loser_next_match_id=?,loser_slot=? WHERE match_id=?")->execute([$bronzeId,$idx===0?'Entry1':'Entry2',$semiId]);}}
   $pdo->commit();$notice='Elimination stage and Bronze Match generated from Round Robin standings.';
  }elseif($action==='assign_bracket_court'){
    $bracketId=(int)($_POST['bracket_id']??0);$courtId=(int)($_POST['court_id']??0);
    $valid=$pdo->prepare("SELECT court_id FROM courts WHERE court_id=? AND tournament_id=? AND is_active=1");$valid->execute([$courtId,$tournamentId]);if(!$valid->fetchColumn())throw new RuntimeException('Choose an active court for this tournament.');
    $ins=$pdo->prepare("INSERT INTO match_schedules(match_id,tournament_day,scheduled_start,scheduled_end,court_id,schedule_order,is_manual_override) SELECT m.match_id,1,NULL,NULL,?,m.match_id,1 FROM matches m LEFT JOIN match_schedules ms ON ms.match_id=m.match_id WHERE m.category_id=? AND m.bracket_id=? AND LOWER(m.round_name)='round robin' AND ms.match_id IS NULL");$ins->execute([$courtId,$categoryId,$bracketId]);
    $pdo->prepare("UPDATE match_schedules ms JOIN matches m ON m.match_id=ms.match_id SET ms.court_id=?,ms.is_manual_override=1 WHERE m.category_id=? AND m.bracket_id=? AND LOWER(m.round_name)='round robin'")->execute([$courtId,$categoryId,$bracketId]);$notice='Court assigned to the entire bracket. Scores and results were preserved.';
  }elseif($action==='change_match_court'){
    $courtId=(int)($_POST['court_id']??0);
    $valid=$pdo->prepare("SELECT court_id FROM courts WHERE court_id=? AND tournament_id=? AND is_active=1");$valid->execute([$courtId,$tournamentId]);
    if(!$valid->fetchColumn())throw new RuntimeException('Choose an active court for this tournament.');
    $check=$pdo->prepare("SELECT match_id FROM matches WHERE match_id=? AND category_id=?");$check->execute([$matchId,$categoryId]);if(!$check->fetchColumn())throw new RuntimeException('Match not found.');
    $ss=$pdo->prepare("SELECT schedule_id FROM match_schedules WHERE match_id=?");$ss->execute([$matchId]);$scheduleId=(int)$ss->fetchColumn();
    if($scheduleId){$up=$pdo->prepare("UPDATE match_schedules SET court_id=?,is_manual_override=1 WHERE match_id=?");$up->execute([$courtId,$matchId]);}
    else{$ins=$pdo->prepare("INSERT INTO match_schedules(match_id,tournament_day,scheduled_start,scheduled_end,court_id,schedule_order,is_manual_override) VALUES(?,1,NULL,NULL,?,?,1)");$ins->execute([$matchId,$courtId,$matchId]);}
    $notice='Court assignment updated. The match can be played on the selected court.';
  }elseif($action==='start_match'){
   $q=$pdo->prepare("UPDATE matches SET match_status='Ongoing' WHERE match_id=? AND match_status IN ('Scheduled','Ready','Pending')");$q->execute([$matchId]);$notice='Match started.';
  }elseif($action==='save_scores'||$action==='save_bronze_scores'){
    $q=$pdo->prepare("SELECT entry1_id,entry2_id FROM matches WHERE match_id=? AND category_id=?");$q->execute([$matchId,$categoryId]);$m=$q->fetch(PDO::FETCH_ASSOC);
    if(!$m)throw new RuntimeException('Match not found.');
    if(empty($m['entry1_id'])||empty($m['entry2_id']))throw new RuntimeException('The Bronze Match cannot be scored until both semifinal losers have been assigned.');
    if($action==='save_bronze_scores'){
      $bq=$pdo->prepare("SELECT round_name FROM matches WHERE match_id=? AND category_id=?");
      $bq->execute([$matchId,$categoryId]);
      if(strcasecmp((string)$bq->fetchColumn(),'Bronze Match')!==0)throw new RuntimeException('This scoring form is not a Bronze Match.');
    }
    $rq=$pdo->prepare("SELECT round_name FROM matches WHERE match_id=?");$rq->execute([$matchId]);$matchRound=(string)$rq->fetchColumn();
    $matchScoring=roundScoring($pdo,$categoryId,$matchRound,$scoringSettings);
    $scores1=$_POST['score1']??[];$scores2=$_POST['score2']??[];$pdo->beginTransaction();
    $del=$pdo->prepare("DELETE FROM match_scores WHERE match_id=?");$del->execute([$matchId]);
    $wins1=0;$wins2=0;$game=0;$target=(int)$matchScoring['points_to_win'];$winByTwo=(int)$matchScoring['win_by_two'];$gamesNeeded=(int)ceil((int)$matchScoring['games_per_match']/2);
    for($i=0;$i<(int)$matchScoring['games_per_match'];$i++){
     $a=trim((string)($scores1[$i]??''));$b=trim((string)($scores2[$i]??''));
     if($a===''&&$b==='')continue;
     if($a===''||$b==='')throw new RuntimeException('Enter both team scores for each game, or leave both blank.');
     if(!is_numeric($a)||!is_numeric($b)||$a<0||$b<0||floor((float)$a)!=(float)$a||floor((float)$b)!=(float)$b||$a===$b)throw new RuntimeException('Scores must be whole numbers, non-negative, and cannot be tied.');
     $game++;$a=(int)$a;$b=(int)$b;$high=max($a,$b);$low=min($a,$b);
     if($high<$target||($winByTwo&&$high-$low<2))throw new RuntimeException("Game {$game}: winner must reach {$target}".($winByTwo?' points and win by 2.': ' points.'));
     if($high>$target&&!$winByTwo)throw new RuntimeException("Game {$game}: score cannot exceed {$target} when win-by-two is disabled.");
     if($wins1>=$gamesNeeded||$wins2>=$gamesNeeded)throw new RuntimeException('Scores include games after the match was already decided.');
     $ins=$pdo->prepare("INSERT INTO match_scores(match_id,game_number,entry1_score,entry2_score) VALUES(?,?,?,?)");$ins->execute([$matchId,$game,$a,$b]);
     if($a>$b)$wins1++;else $wins2++;
    }
    if($game===0)throw new RuntimeException('Enter scores before saving or finishing the match.');
    $winner=$wins1>=$gamesNeeded?(int)$m['entry1_id']:($wins2>=$gamesNeeded?(int)$m['entry2_id']:0);
    $status=$winner?'Completed':'Ongoing';$up=$pdo->prepare("UPDATE matches SET winner_entry_id=?,match_status=? WHERE match_id=? AND category_id=?");$up->execute([$winner?:null,$status,$matchId,$categoryId]);if($up->rowCount()===0){$verify=$pdo->prepare("SELECT winner_entry_id,match_status FROM matches WHERE match_id=? AND category_id=?");$verify->execute([$matchId,$categoryId]);$saved=$verify->fetch(PDO::FETCH_ASSOC);if(!$saved||((int)($saved['winner_entry_id']??0)!==$winner)||strcasecmp((string)$saved['match_status'],$status)!==0)throw new RuntimeException('The Bronze Match result could not be saved to the match record. Please retry.');}
    $pdo->commit();if($winner){advanceWinner($pdo,$matchId,$winner);$loser=((int)$m['entry1_id']===$winner)?(int)$m['entry2_id']:(int)$m['entry1_id'];if($loser)advanceLoser($pdo,$matchId,$loser);}$notice=$winner?'Scores saved and match completed. Winner advanced when a next-round slot is configured.':'Scores saved. Match remains ongoing.';
  }elseif($action==='finish_match'){
   $winner=(int)($_POST['winner_entry_id']??0);
   $q=$pdo->prepare("SELECT entry1_id,entry2_id FROM matches WHERE match_id=? AND category_id=?");$q->execute([$matchId,$categoryId]);$m=$q->fetch(PDO::FETCH_ASSOC);
   if(!$m||!$winner||!in_array($winner,[(int)$m['entry1_id'],(int)$m['entry2_id']],true))throw new RuntimeException('Select a valid winner.');
   $q=$pdo->prepare("UPDATE matches SET winner_entry_id=?,match_status='Completed' WHERE match_id=?");$q->execute([$winner,$matchId]);advanceWinner($pdo,$matchId,$winner);$loser=((int)$m['entry1_id']===$winner)?(int)$m['entry2_id']:(int)$m['entry1_id'];if($loser)advanceLoser($pdo,$matchId,$loser);$notice='Winner saved and match completed.';
  }
  }catch(Throwable $ex){
   if($pdo->inTransaction())$pdo->rollBack();
   $error=$ex->getMessage();
  }
}
// Ensure older elimination brackets also receive a Bronze Match without regenerating or losing scores.
if($categoryId){
 $semiQ=$pdo->prepare("SELECT match_id,entry1_id,entry2_id,winner_entry_id,match_status FROM matches WHERE category_id=? AND LOWER(round_name)='semifinals' ORDER BY match_number,match_id");$semiQ->execute([$categoryId]);$semiRows=$semiQ->fetchAll(PDO::FETCH_ASSOC);
 $bronzeQ=$pdo->prepare("SELECT m.match_id,m.bracket_id FROM matches m WHERE m.category_id=? AND LOWER(m.round_name)='bronze match' ORDER BY m.match_id LIMIT 1");$bronzeQ->execute([$categoryId]);$bronze=$bronzeQ->fetch(PDO::FETCH_ASSOC);
 if(count($semiRows)===2){
  if(!$bronze){$ordQ=$pdo->prepare("SELECT COALESCE(MAX(bracket_order),0)+1 FROM brackets WHERE category_id=?");$ordQ->execute([$categoryId]);$ord=(int)$ordQ->fetchColumn();$maxQ=$pdo->prepare("SELECT COALESCE(MAX(match_number),0)+1 FROM matches WHERE category_id=?");$maxQ->execute([$categoryId]);$num=(int)$maxQ->fetchColumn();$insB=$pdo->prepare("INSERT INTO brackets(category_id,bracket_name,bracket_order) VALUES(?,?,?)");$insB->execute([$categoryId,'Bronze Match',$ord]);$bid=(int)$pdo->lastInsertId();$insM=$pdo->prepare("INSERT INTO matches(category_id,bracket_id,round_name,match_number,entry1_id,entry2_id,match_status) VALUES(?,?,'Bronze Match',?,NULL,NULL,'Pending')");$insM->execute([$categoryId,$bid,$num]);$bronze=['match_id'=>(int)$pdo->lastInsertId(),'bracket_id'=>$bid];}
  foreach($semiRows as $i=>$semi){$slot=$i===0?'Entry1':'Entry2';$pdo->prepare("UPDATE matches SET loser_next_match_id=?,loser_slot=? WHERE match_id=?")->execute([(int)$bronze['match_id'],$slot,(int)$semi['match_id']]);if(($semi['match_status']??'')==='Completed'&&!empty($semi['winner_entry_id'])){$loser=(int)$semi['winner_entry_id']===(int)$semi['entry1_id']?(int)$semi['entry2_id']:(int)$semi['entry1_id'];$col=$slot==='Entry1'?'entry1_id':'entry2_id';$pdo->prepare("UPDATE matches SET {$col}=?,match_status=IF(entry1_id IS NOT NULL AND entry2_id IS NOT NULL,'Ready',match_status) WHERE match_id=?")->execute([$loser,(int)$bronze['match_id']]);}}
 }
}
$matches=[];
if($categoryId){
 $q=$pdo->prepare("SELECT m.*,b.bracket_order,b.bracket_name,e1.entry_name entry1_name,e2.entry_name entry2_name,
  c.court_name,ms.court_id,ms.schedule_order,ms.tournament_day,cat.category_name
  FROM matches m LEFT JOIN categories cat ON cat.category_id=m.category_id LEFT JOIN brackets b ON b.bracket_id=m.bracket_id
  LEFT JOIN tournament_entries e1 ON e1.entry_id=m.entry1_id LEFT JOIN tournament_entries e2 ON e2.entry_id=m.entry2_id
  LEFT JOIN match_schedules ms ON ms.match_id=m.match_id LEFT JOIN courts c ON c.court_id=ms.court_id
  WHERE m.category_id=? ORDER BY COALESCE(ms.schedule_order,999999),b.bracket_order,m.match_number");
 $q->execute([$categoryId]);$matches=$q->fetchAll(PDO::FETCH_ASSOC);
}
$scoresByMatch=[];
if($matches){$ids=array_column($matches,'match_id');$in=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare("SELECT * FROM match_scores WHERE match_id IN ($in) ORDER BY game_number");$q->execute($ids);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $s)$scoresByMatch[$s['match_id']][]=$s;}
$standings=[];
foreach($matches as $m){
 if(strcasecmp($m['round_name'],'Round Robin')!==0 || empty($m['winner_entry_id']))continue;
 $bid=(int)($m['bracket_id']??0);foreach([(int)$m['entry1_id'],(int)$m['entry2_id']] as $eid){if(!$eid)continue;if(!isset($standings[$bid][$eid]))$standings[$bid][$eid]=['name'=>($eid==(int)$m['entry1_id']?$m['entry1_name']:$m['entry2_name']),'played'=>0,'wins'=>0,'losses'=>0,'scored'=>0,'conceded'=>0];}
 $a=(int)$m['entry1_id'];$b=(int)$m['entry2_id'];$sa=$sb=0;foreach($scoresByMatch[$m['match_id']]??[] as $s){$sa+=(int)$s['entry1_score'];$sb+=(int)$s['entry2_score'];}
 foreach([[$a,$b,$sa,$sb],[$b,$a,$sb,$sa]] as [$eid,$opp,$sc,$co]){if(!isset($standings[$bid][$eid]))continue;$standings[$bid][$eid]['played']++;$standings[$bid][$eid]['scored']+=$sc;$standings[$bid][$eid]['conceded']+=$co;if($eid==(int)$m['winner_entry_id'])$standings[$bid][$eid]['wins']++;else $standings[$bid][$eid]['losses']++;}
}
foreach($standings as &$rows){uasort($rows,function($a,$b){return [$b['wins'],$b['scored']-$b['conceded'],$b['scored']]<=>[$a['wins'],$a['scored']-$a['conceded'],$a['scored']];});}unset($rows);
$bracketNames=[];$hasElimination=false;foreach($matches as $match){if(!empty($match['bracket_id']))$bracketNames[(int)$match['bracket_id']]=$match['bracket_name']??'Bracket';if(strcasecmp($match['round_name'],'Round Robin')!==0)$hasElimination=true;}
$roundNamesPresent=[];$roundRobinMatches=[];$eliminationRounds=[];
foreach($matches as $match){$rn=(string)$match['round_name'];if(strcasecmp($rn,'Round Robin')===0)$roundRobinMatches[]=$match;else{$roundNamesPresent[$rn]=true;$eliminationRounds[$rn][]=$match;}}
$roundNamesPresent=array_keys($roundNamesPresent);
$eliminationPlacements=buildEliminationPlacements($matches,$scoresByMatch);


// Group Round Robin matches by bracket; one court is assigned per bracket.
$roundRobinByBracket=[];foreach($roundRobinMatches as $rrMatch){$bid=(int)($rrMatch['bracket_id']??0);if(!isset($roundRobinByBracket[$bid]))$roundRobinByBracket[$bid]=['id'=>$bid,'name'=>(string)($rrMatch['bracket_name']??'Bracket'),'court_id'=>(int)($rrMatch['court_id']??0),'court_name'=>(string)($rrMatch['court_name']??''),'matches'=>[]];$roundRobinByBracket[$bid]['matches'][]=$rrMatch;}
$roundColorMap=['Bronze Match'=>'#b45309','Round of 32'=>'#0891b2','Round of 16'=>'#2563eb','Quarterfinals'=>'#7c3aed','Semifinals'=>'#db2777','Final'=>'#d97706','Finals'=>'#d97706'];

?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Scoring & Standings | COURTCTRL</title><link rel="stylesheet" href="assets/css/style.css">
</head><body class="page-scoring"><div class="app-shell"><aside class="sidebar"><div class="brand"><div class="brand-mark">P</div><div><strong>COURT<span>CTRL</span></strong><small>TOURNAMENT ORGANIZER</small></div></div><div class="nav-label">WORKSPACE</div><a class="nav-item" href="index.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span> Dashboard</a><a class="nav-item" href="workspace.php?tournament_id=<?= (int)$tournamentId ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 21h14M7 21V8h10v13M9 8V4h6v4M4 8h16"/></svg></span> Tournament Workspace</a><a class="nav-item" href="tournaments.php?id=<?= (int)$tournamentId ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3M5 5l2 2m10 10 2 2M19 5l-2 2M7 17l-2 2"/></svg></span> Tournament Setup</a><a class="nav-item" href="players.php?tournament_id=<?= (int)$tournamentId ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.2 2.5-5 6-5s6 1.8 6 5M17 5a3 3 0 0 1 0 6M18 15c2 .5 3 2 3 4"/></svg></span> Players &amp; Teams</a><a class="nav-item" href="brackets.php?tournament_id=<?= (int)$tournamentId ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4v5h6v6h8M5 20v-5h6M17 12h2v6h-2zM3 2h4v4H3z"/></svg></span> Brackets</a><a class="nav-item" href="schedule.php?tournament_id=<?= (int)$tournamentId ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/></svg></span> Schedule &amp; Courts</a><a class="nav-item" href="live_feed.php?tournament_id=<?= (int)$tournamentId ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/></svg></span> Live Feed</a><a class="nav-item active" href="scoring.php?tournament_id=<?= (int)$tournamentId ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4h14M5 9h14M5 14h9M5 19h9"/></svg></span> Scoring &amp; Standings</a><div class="sidebar-bottom"><div class="help-dot">?</div><div><strong>Organizer workspace</strong><small>COURTCTRL tournament control</small></div></div></aside>
<main class="main-content"><header class="topbar"><div><h1>Scoring &amp; Standings</h1><p>Record game scores, finish matches, and track bracket standings.</p></div><div class="topbar-actions"><a class="btn btn-outline" target="_blank" href="display_screen.php?tournament_id=<?=e($tournamentId)?>">Open display screen</a><a class="btn btn-primary" target="_blank" rel="noopener" href="display_standings.php?tournament_id=<?=e($tournamentId)?>&amp;category_id=<?=e($categoryId)?>">Open standings display</a></div></header><div class="active-tournament-strip"><span>ACTIVE TOURNAMENT</span><strong><?=e($activeTournamentName ?: 'No tournament selected')?></strong><a href="workspace.php?tournament_id=<?= (int)$tournamentId ?>">Switch</a></div><div class="content-area score-wrap">
<?php if($notice):?><div id="action-feedback" class="alert alert-success" role="status" aria-live="polite"><?=e($notice)?></div><?php endif;?><?php if($error):?><div id="action-feedback" class="alert alert-error" role="alert" aria-live="polite"><strong>Could not generate elimination stage</strong><div><?=e($error)?></div></div><?php endif;?>
<nav class="score-page-tabs" role="tablist" aria-label="Scoring page sections">
<button type="button" class="score-page-tab active" role="tab" aria-selected="true" aria-controls="score-panel-settings" data-score-tab="settings">Settings</button>
<button type="button" class="score-page-tab" role="tab" aria-selected="false" aria-controls="stage-rr" data-score-tab="rr">Round Robin</button>
<button type="button" class="score-page-tab" role="tab" aria-selected="false" aria-controls="stage-elim" data-score-tab="elim">Elimination</button>
<button type="button" class="score-page-tab" role="tab" aria-selected="false" aria-controls="score-panel-standings" data-score-tab="standings">Standings</button>
</nav>
<section class="score-tab-panel" id="score-panel-settings" data-score-panel="settings"><form class="score-filter" method="get"><input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>"><div class="field"><label>Category</label><select name="category_id" onchange="this.form.submit()"><option value="" disabled <?=$categoryId===0?'selected':''?>>Select a category...</option><?php foreach($categories as $cat):?><option value="<?=e($cat['category_id'])?>" <?=((int)$cat['category_id']===$categoryId?'selected':'')?>><?=e($cat['category_name'])?></option><?php endforeach;?></select></div></form>
<section class="format-card"><div><h2>Scoring format</h2><p>Set the scoring rules for this category. These rules control the score fields below.</p></div>
<form method="post" class="format-controls"><input type="hidden" name="active_stage" value="<?=e($activeStage)?>"><input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>"><input type="hidden" name="category_id" value="<?=e($categoryId)?>"><input type="hidden" name="action" value="save_scoring_settings">
<label>Games / sets<select name="games_per_match"><option value="1" <?=((int)$scoringSettings['games_per_match']===1?'selected':'')?>>1 game</option><option value="3" <?=((int)$scoringSettings['games_per_match']===3?'selected':'')?>>Best of 3</option></select></label>
<label>Points to win<input type="number" name="points_to_win" min="1" max="99" value="<?=e($scoringSettings['points_to_win'])?>"></label>
<label class="checkbox-rule"><input type="checkbox" name="win_by_two" value="1" <?=((int)$scoringSettings['win_by_two']?'checked':'')?>> Win by 2</label>
<button class="btn <?= $categoryFormatSaved ? 'btn-success' : 'btn-primary' ?>" type="submit"><?= $categoryFormatSaved ? 'Saved · Edit format' : 'Save format' ?></button></form>
<div class="format-summary">Current format: <strong><?=e($scoringSettings['games_per_match'])?> game(s) · <?=e($scoringSettings['points_to_win'])?> points<?=((int)$scoringSettings['win_by_two']?' · win by 2':'')?></strong></div></section>
<?php if($categoryId):?>
<details class="demo-tools-disclosure">
 <summary>Show optional test scoring tools <span>For testing only · hidden by default</span></summary>
 <section class="demo-tools-card">
 <div class="demo-tools-copy"><span class="demo-tools-kicker">OPTIONAL TEST MODE · NOT AUTOMATIC</span><h2>Auto-generate test scores</h2><p>Instead of typing scores into every match, click one of the buttons below. COURTCTRL will generate valid random scores using this category's saved scoring rules, finish eligible matches, update standings, and advance elimination winners. This only runs when you click a button.</p></div>
 <div class="demo-tools-actions">
  <form method="post" data-confirm="Automatically score all unfinished Round Robin matches in this category? Existing scores will not be overwritten.">
   <input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>"><input type="hidden" name="category_id" value="<?=e($categoryId)?>"><input type="hidden" name="active_stage" value="rr"><input type="hidden" name="action" value="auto_complete_round_robin">
   <button class="btn btn-primary" type="submit">Generate Round Robin test scores</button>
  </form>
  <form method="post" data-confirm="Automatically score all playable unfinished elimination matches in this category? Existing scores will not be overwritten.">
   <input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>"><input type="hidden" name="category_id" value="<?=e($categoryId)?>"><input type="hidden" name="active_stage" value="elim"><input type="hidden" name="action" value="auto_complete_elimination">
   <button class="btn btn-success" type="submit">Generate Elimination test scores</button>
  </form>
 </div>
 <small><strong>Recommended sequence:</strong> 1) Generate Round Robin test scores. 2) Click the separate <em>Generate elimination</em> action after all group matches are completed. 3) Generate Elimination test scores. Matches with TBD players, existing scores, or completed results are skipped. Generated scores are saved as real results in the database.</small>
</section>
</details>
<?php endif;?>
</section>
<div class="score-layout"><section class="matches-panel">
<div class="stage-tabs" role="tablist" aria-label="Tournament stages">
<button type="button" class="stage-tab <?=($activeStage==='rr'?'active':'')?>" data-stage="rr">Round Robin <span><?=count($roundRobinMatches)?></span></button>
<button type="button" class="stage-tab <?=($activeStage==='elim'?'active':'')?>" data-stage="elim">Elimination Bracket <span><?=count($matches)-count($roundRobinMatches)?></span></button>
</div>
<div class="stage-pane <?=($activeStage==='rr'?'active':'')?>" id="stage-rr">
<h2>Round Robin Matches</h2>
<?php if(!$roundRobinMatches):?><div class="empty-stage">No Round Robin matches found for this category.</div><?php endif;?>
<?php foreach($roundRobinByBracket as $bracketGroup):?>
<section class="rr-court-group rr-bracket-group">
<header class="rr-court-heading"><div><span class="rr-court-kicker">ROUND ROBIN BRACKET</span><h3><?=e($bracketGroup['name'])?></h3><small>Assigned court: <?=e($bracketGroup['court_name']?:'Not assigned')?></small></div><span class="rr-court-count"><?=count($bracketGroup['matches'])?> matches</span></header>
<form method="post" class="bracket-court-assignment"><input type="hidden" name="active_stage" value="rr"><input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>"><input type="hidden" name="category_id" value="<?=e($categoryId)?>"><input type="hidden" name="bracket_id" value="<?=e($bracketGroup['id'])?>"><input type="hidden" name="action" value="assign_bracket_court"><label>Assign court<select name="court_id" required><option value="">Choose court</option><?php foreach($activeCourts as $court):?><option value="<?=e($court['court_id'])?>" <?=($bracketGroup['court_id']===(int)$court['court_id']?'selected':'')?>><?=e($court['court_name'])?></option><?php endforeach;?></select></label><button class="btn btn-outline btn-small" type="submit">Assign bracket court</button></form>
<div class="rr-court-matches">
<?php foreach($bracketGroup['matches'] as $m):?>
<?php $completed=(strcasecmp((string)$m['match_status'],'Completed')===0 || !empty($m['winner_entry_id']));$ms=roundScoring($pdo,$categoryId,(string)$m['round_name'],$scoringSettings);?>
<article class="match-card rr-card <?=($completed?'is-completed':'')?>">
<?php if($completed):?><details class="completed-match"><summary><span class="summary-title"><?=e($m['category_name']??'Category')?> · Bracket <?=e(bracketLabel($m['bracket_order']??1))?> · Match <?=e($m['match_number'])?></span><span class="summary-score"><?=e($m['entry1_name']??'TBD')?> <b><?=e(($scoresByMatch[$m['match_id']][0]['entry1_score']??'—'))?>–<?=e(($scoresByMatch[$m['match_id']][0]['entry2_score']??'—'))?></b> <?=e($m['entry2_name']??'TBD')?></span><span class="status-pill Completed">Completed · Edit</span></summary><?php else:?><div class="match-head"><div class="rr-match-labels"><span class="rr-category-label"><?=e($m['category_name']??'Category')?></span><span class="rr-bracket-label">BRACKET <?=e(bracketLabel($m['bracket_order']??1))?></span><strong>Match <?=e($m['match_number'])?></strong></div><span class="status-pill <?=e($m['match_status'])?>"><?=e($m['match_status'])?></span></div><?php endif;?>
<div class="match-body"><div class="teams-score"><div class="team-name <?=($completed && (int)$m['winner_entry_id']===(int)$m['entry1_id']?'rr-winner':'')?>"><?=e($m['entry1_name']??'TBD')?> <?=($completed && (int)$m['winner_entry_id']===(int)$m['entry1_id']?'🏆 WINNER':'')?></div><span>VS</span><div class="team-name <?=($completed && (int)$m['winner_entry_id']===(int)$m['entry2_id']?'rr-winner':'')?>"><?=e($m['entry2_name']??'TBD')?> <?=($completed && (int)$m['winner_entry_id']===(int)$m['entry2_id']?'🏆 WINNER':'')?></div></div>
<?php if(isset($scoresByMatch[$m['match_id']])):?><div class="score-note">Saved games: <?php foreach($scoresByMatch[$m['match_id']] as $s):?>Game <?=e($s['game_number'])?>: <?=e($s['entry1_score'])?>–<?=e($s['entry2_score'])?> &nbsp;<?php endforeach;?></div><?php endif;?>
<details class="court-reassign-option"><summary>Reassign court (optional)</summary><form method="post" class="court-move-form"><input type="hidden" name="active_stage" value="<?=e($activeStage)?>"><input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>"><input type="hidden" name="category_id" value="<?=e($categoryId)?>"><input type="hidden" name="match_id" value="<?=e($m['match_id'])?>"><input type="hidden" name="action" value="change_match_court"><label>Move match to court<select name="court_id" required><option value="">Choose court</option><?php foreach($activeCourts as $court):?><option value="<?=e($court['court_id'])?>" <?=((int)($m['court_id']??0)===(int)$court['court_id']?'selected':'')?>><?=e($court['court_name'])?></option><?php endforeach;?></select></label><button class="btn btn-outline btn-small" type="submit">Assign court</button></form></details><form method="post"><input type="hidden" name="active_stage" value="<?=e($activeStage)?>"><input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>"><input type="hidden" name="category_id" value="<?=e($categoryId)?>"><input type="hidden" name="match_id" value="<?=e($m['match_id'])?>"><div class="match-actions"><button class="btn btn-outline" name="action" value="start_match" type="submit">Start match</button></div><div class="score-inputs"><?php for($g=0;$g<(int)$ms['games_per_match'];$g++):?><div class="score-set"><strong class="score-game-title">Game <?=$g+1?></strong><label class="score-team-label"><?=e($m['entry1_name']??'Team 1')?></label><input type="number" inputmode="numeric" min="0" name="score1[]" aria-label="<?=e(($m['entry1_name']??'Team 1').' score, game '.($g+1))?>" placeholder="Score" value="<?=e($scoresByMatch[$m['match_id']][$g]['entry1_score']??'')?>"><label class="score-team-label"><?=e($m['entry2_name']??'Team 2')?></label><input type="number" inputmode="numeric" min="0" name="score2[]" aria-label="<?=e(($m['entry2_name']??'Team 2').' score, game '.($g+1))?>" placeholder="Score" value="<?=e($scoresByMatch[$m['match_id']][$g]['entry2_score']??'')?>"></div><?php endfor;?></div><div class="match-actions"><button class="btn btn-primary" name="action" value="save_scores" type="submit">Save scores / Finish</button></div></form>
</div><?php if($completed):?></details><?php endif;?></article><?php endforeach;?>
</div></section><?php endforeach;?>
</div><!-- close Round Robin stage pane -->
<div class="stage-pane <?=($activeStage==='elim'?'active':'')?>" id="stage-elim">
<h2>Elimination Bracket</h2>
<?php if(!$eliminationRounds):?><div class="empty-stage">Elimination games will appear here after the stage is generated from Round Robin standings.</div><?php else:?>
<div class="bracket-board">
<?php foreach($eliminationRounds as $roundName=>$roundMatches):$roundColor=$roundColorMap[$roundName]??'#0f766e';$roundSettings=roundScoring($pdo,$categoryId,$roundName,$scoringSettings);?>
<section class="bracket-round" style="--round-color:<?=$roundColor?>">
<header class="round-heading"><div><span class="round-kicker">ELIMINATION</span><h3><?=e($roundName)?></h3><small><?=count($roundMatches)?> match<?=count($roundMatches)!==1?'es':''?></small></div><span class="round-chip"><?=e($roundName)?></span></header>
<form method="post" class="round-format-form"><input type="hidden" name="active_stage" value="<?=e($activeStage)?>"><input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>"><input type="hidden" name="category_id" value="<?=e($categoryId)?>"><input type="hidden" name="action" value="save_round_scoring_settings"><input type="hidden" name="round_name" value="<?=e($roundName)?>"><label>Games<select name="games_per_match"><option value="1" <?=((int)$roundSettings['games_per_match']===1?'selected':'')?>>1 game</option><option value="3" <?=((int)$roundSettings['games_per_match']===3?'selected':'')?>>Best of 3</option></select></label><label>Points<input type="number" name="points_to_win" min="1" max="99" value="<?=e($roundSettings['points_to_win'])?>"></label><label class="win-two"><input type="checkbox" name="win_by_two" value="1" <?=((int)$roundSettings['win_by_two']?'checked':'')?>> Win by 2</label><button class="btn btn-small btn-outline" type="submit">Save format</button></form>
<?php foreach($roundMatches as $m):$completed=(strcasecmp((string)$m['match_status'],'Completed')===0 || !empty($m['winner_entry_id']));$ms=roundScoring($pdo,$categoryId,(string)$m['round_name'],$scoringSettings);?>
<article class="bracket-match <?=($completed?'is-completed':'')?>"><?php if($completed):?><details class="completed-match"><summary><span class="match-number">Match <?=e($m['match_number'])?></span><span class="status-pill Completed">Completed · Edit</span></summary><?php else:?><div class="bracket-match-top"><span>Match <?=e($m['match_number'])?></span><span class="status-pill <?=e($m['match_status'])?>"><?=e($m['match_status'])?></span></div><?php endif;?>
<div class="bracket-entries"><div class="bracket-entry <?=((int)$m['winner_entry_id']===(int)$m['entry1_id']?'winner':'')?>"><span><?=e($m['entry1_name']??'TBD')?></span><?php if((int)$m['winner_entry_id']===(int)$m['entry1_id']):?><b>W</b><?php endif;?></div><div class="bracket-entry <?=((int)$m['winner_entry_id']===(int)$m['entry2_id']?'winner':'')?>"><span><?=e($m['entry2_name']??'TBD')?></span><?php if((int)$m['winner_entry_id']===(int)$m['entry2_id']):?><b>W</b><?php endif;?></div></div>
<div class="bracket-score-edit"><form method="post" class="court-move-form"><input type="hidden" name="active_stage" value="<?=e($activeStage)?>"><input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>"><input type="hidden" name="category_id" value="<?=e($categoryId)?>"><input type="hidden" name="match_id" value="<?=e($m['match_id'])?>"><input type="hidden" name="action" value="change_match_court"><label>Move match to court<select name="court_id" required><option value="">Choose court</option><?php foreach($activeCourts as $court):?><option value="<?=e($court['court_id'])?>" <?=((int)($m['court_id']??0)===(int)$court['court_id']?'selected':'')?>><?=e($court['court_name'])?></option><?php endforeach;?></select></label><button class="btn btn-outline btn-small" type="submit">Assign court</button></form><form method="post"><input type="hidden" name="active_stage" value="<?=e($activeStage)?>"><input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>"><input type="hidden" name="category_id" value="<?=e($categoryId)?>"><input type="hidden" name="match_id" value="<?=e($m['match_id'])?>"><div class="match-actions"><button class="btn btn-outline" name="action" value="start_match" type="submit">Start match</button></div><div class="score-inputs"><?php for($g=0;$g<(int)$ms['games_per_match'];$g++):?><div class="score-set"><strong class="score-game-title">Game <?=$g+1?></strong><label class="score-team-label"><?=e($m['entry1_name']??'Team 1')?></label><input type="number" inputmode="numeric" min="0" name="score1[]" aria-label="<?=e(($m['entry1_name']??'Team 1').' score, game '.($g+1))?>" placeholder="Score" value="<?=e($scoresByMatch[$m['match_id']][$g]['entry1_score']??'')?>"><label class="score-team-label"><?=e($m['entry2_name']??'Team 2')?></label><input type="number" inputmode="numeric" min="0" name="score2[]" aria-label="<?=e(($m['entry2_name']??'Team 2').' score, game '.($g+1))?>" placeholder="Score" value="<?=e($scoresByMatch[$m['match_id']][$g]['entry2_score']??'')?>"></div><?php endfor;?></div><div class="match-actions"><button class="btn btn-primary" name="action" value="<?=strcasecmp((string)$m['round_name'],'Bronze Match')===0?'save_bronze_scores':'save_scores'?>" type="submit">Save / Finish</button></div></form></div><?php if($completed):?></details><?php endif;?></article><?php endforeach;?>
</section><?php endforeach;?></div><?php endif;?></div>
</section><aside id="score-panel-standings" class="score-tab-panel" data-score-panel="standings"><h2>Standings</h2>
<?php if($hasElimination && !empty($eliminationPlacements['limit'])):?>
<div class="standing-card elimination-podium-card"><h3>🏆 Top 3 · Podium</h3><div class="podium-row">
<?php foreach([2,1,3] as $place):$person=$eliminationPlacements['podium'][$place]??null;?><div class="podium-place podium-<?=$place?>"><span class="podium-medal"><?=$place===1?'🥇':($place===2?'🥈':'🥉')?></span><strong><?=e($person['name']??'TBD')?></strong><small><?=$place===1?'Champion':($place===2?'Runner-up':'Bronze')?></small><b><?=$place?></b></div><?php endforeach;?>
</div><div class="placement-note">Podium updates after the Championship and Bronze matches are completed.</div></div>
<div class="standing-card elimination-ranking-card"><h3>Final Rankings · Top <?=e($eliminationPlacements['limit'])?></h3><table><th scope="col"ead><tr><th scope="col">#</th><th scope="col">Team / Entry</th><th scope="col">Placement</th></tr></thead><tbody><?php for($r=1;$r<=$eliminationPlacements['limit'];$r++):$person=$eliminationPlacements['ranked'][$r]??null;$label=$r===1?'Champion':($r===2?'Runner-up':($r===3?'Third Place':($r===4?'Fourth Place':($r<=8?'Quarterfinalist':'Round of 16'))));?><tr class="<?=($r<=3?'rank-first':($r===4?'rank-second':''))?>"><td><span class="rank-badge"><?=$r?></span></td><td><?=e($person['name']??'— Not determined —')?></td><td><?=e($person?$label:'Pending')?></td></tr><?php endfor;?></tbody></table><small>Quarterfinal losers are ranked 5–8 by points scored, then point differential. Round-of-16 losers are ranked 9–16 using the same tiebreaker.</small></div>
<?php endif;?>
<?php if($standings && !$hasElimination):?><div class="standing-card qualify-card"><h3>Next stage · Elimination</h3><p>Choose how many entries qualify from each Round Robin bracket. Winners advance through the knockout rounds.</p><form method="post"><input type="hidden" name="active_stage" value="<?=e($activeStage)?>"><input type="hidden" name="tournament_id" value="<?=e($tournamentId)?>"><input type="hidden" name="category_id" value="<?=e($categoryId)?>"><input type="hidden" name="action" value="generate_elimination"><label>Qualifiers per bracket<select name="qualify_per_bracket"><option value="1">Top 1</option><option value="2" selected>Top 2</option><option value="3">Top 3</option><option value="4">Top 4</option></select></label><button class="btn btn-primary" type="submit">Generate elimination stage</button></form><small>Progression: Round of 16 → Quarterfinals → Semifinals → Final, as applicable.</small></div><?php endif;?><?php if(!$standings):?><div class="standing-card"><div style="padding:14px;color:#64748b">Standings appear after Round Robin matches are completed.</div></div><?php endif;?><?php foreach($standings as $bid=>$rows):?><div class="standing-card"><h3><?=e($bracketNames[$bid]??'Bracket')?></h3><table><th scope="col"ead><tr><th scope="col">#</th><th scope="col">Entry</th><th scope="col">W</th><th scope="col">L</th><th scope="col">PF</th><th scope="col">PA</th></tr></thead><tbody><?php $rank=1;foreach($rows as $row):?><tr class="<?=($rank===1?'rank-first':($rank===2?'rank-second':''))?>"><td><span class="rank-badge"><?=$rank?></span><?php $rank++;?></td><td><?=e($row['name'])?></td><td><?=e($row['wins'])?></td><td><?=e($row['losses'])?></td><td><?=e($row['scored'])?></td><td><?=e($row['conceded'])?></td></tr><?php endforeach;?></tbody></table></div><?php endforeach;?></aside></div></div></main></div><?php if($error||$notice):?><script>window.addEventListener('DOMContentLoaded',function(){const feedback=document.getElementById('action-feedback');if(feedback)feedback.scrollIntoView({behavior:'smooth',block:'center'});});</script><?php endif;?><script>
function setActiveStage(stage){
 document.querySelectorAll('.stage-tab').forEach(t=>t.classList.toggle('active',t.dataset.stage===stage));
 document.querySelectorAll('.stage-pane').forEach(p=>p.classList.toggle('active',p.id==='stage-'+stage));
 document.querySelectorAll('input[name="active_stage"]').forEach(input=>input.value=stage);
}
document.querySelectorAll('.stage-tab').forEach(function(tab){tab.addEventListener('click',function(){setActiveStage(tab.dataset.stage);});});
setActiveStage(<?=json_encode($activeStage)?>);
document.querySelectorAll('form[method="post"]').forEach(function(form){
 form.addEventListener('submit',function(){
  const active=document.querySelector('.stage-tab.active');
  const input=form.querySelector('input[name="active_stage"]');
  if(input&&active)input.value=active.dataset.stage;
 });
});
</script>
<script>

(function(){
 const tabs=[...document.querySelectorAll('[data-score-tab]')];
 const panels={
  settings:document.getElementById('score-panel-settings'),
  rr:document.getElementById('stage-rr'),
  elim:document.getElementById('stage-elim'),
  standings:document.getElementById('score-panel-standings')
 };
 function activate(name,updateHash){
  if(!panels[name])name='settings';
  tabs.forEach(t=>{const active=t.dataset.scoreTab===name;t.classList.toggle('active',active);t.setAttribute('aria-selected',active?'true':'false');});
  Object.entries(panels).forEach(([key,panel])=>{if(panel)panel.classList.toggle('score-tab-hidden',key!==name);});
  const layout=document.querySelector('.score-layout');
  if(layout){layout.classList.toggle('score-show-standings',name==='standings');layout.classList.toggle('score-show-matches',name==='rr'||name==='elim');}
  if(name==='rr'||name==='elim')setActiveStage(name);
  if(updateHash)history.replaceState(null,'','#'+name);
 }
 tabs.forEach((tab,index)=>tab.addEventListener('click',()=>activate(tab.dataset.scoreTab,true)));
 const initial=(location.hash||'').slice(1);
 activate(panels[initial]?initial:'settings',false);
 window.addEventListener('hashchange',()=>activate((location.hash||'').slice(1),false));
})();

</script>
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
