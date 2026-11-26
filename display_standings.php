<?php
require_once __DIR__ . '/config/database.php';
function se($v){return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');}
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
 $finals=$byRound['final']??($byRound['finals']??[]);foreach($finals as $m){if(!empty($m['winner_entry_id'])){$winner=(int)$m['winner_entry_id'];$loser=$winner===(int)$m['entry1_id']?(int)$m['entry2_id']:(int)$m['entry1_id'];$podium[1]=['id'=>$winner,'name'=>$winner===(int)$m['entry1_id']?($m['entry1_name']??'Unknown'):($m['entry2_name']??'Unknown')];$podium[2]=['id'=>$loser,'name'=>$loser===(int)$m['entry1_id']?($m['entry1_name']??'Unknown'):($m['entry2_name']??'Unknown')];break;}}
 $bronze=$byRound['bronze match']??[];foreach($bronze as $m){if(!empty($m['winner_entry_id'])){$winner=(int)$m['winner_entry_id'];$loser=$winner===(int)$m['entry1_id']?(int)$m['entry2_id']:(int)$m['entry1_id'];$podium[3]=['id'=>$winner,'name'=>$winner===(int)$m['entry1_id']?($m['entry1_name']??'Unknown'):($m['entry2_name']??'Unknown')];$fourth=['id'=>$loser,'name'=>$loser===(int)$m['entry1_id']?($m['entry1_name']??'Unknown'):($m['entry2_name']??'Unknown')];break;}}
 foreach([1,2,3] as $r){if($podium[$r])$ranked[$r]=$podium[$r];}if($fourth)$ranked[4]=$fourth;
 if($limit>=8){$qf=$losersFor('Quarterfinals');if($qf['complete']&&count($qf['losers'])===4){foreach($qf['losers'] as $i=>$row)$ranked[5+$i]=$row;}}
 if($limit>=16){$r16=$losersFor('Round of 16');if($r16['complete']&&count($r16['losers'])===8){foreach($r16['losers'] as $i=>$row)$ranked[9+$i]=$row;}}
 return ['limit'=>$limit,'ranked'=>$ranked,'podium'=>$podium,'fourth'=>$fourth,'round_complete'=>$roundComplete];
}

$tournaments=$pdo->query("SELECT tournament_id,tournament_name FROM tournaments ORDER BY tournament_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$tournamentId=(int)($_GET['tournament_id']??($tournaments[0]['tournament_id']??0));
$categoryId=(int)($_GET['category_id']??0);
$categories=[];
if($tournamentId){
 $q=$pdo->prepare("SELECT category_id,category_name FROM categories WHERE tournament_id=? ORDER BY category_name");
 $q->execute([$tournamentId]);$categories=$q->fetchAll(PDO::FETCH_ASSOC);
}
if(!$categoryId && $categories)$categoryId=(int)$categories[0]['category_id'];
$tournamentName='Tournament'; foreach($tournaments as $tour){if((int)$tour['tournament_id']===$tournamentId){$tournamentName=$tour['tournament_name'];break;}}
$categoryName='';
foreach($categories as $cat){if((int)$cat['category_id']===$categoryId)$categoryName=$cat['category_name'];}
$brackets=[];
$matchCount=0;$completedCount=0;
if($categoryId){
 $q=$pdo->prepare("SELECT m.match_id,m.bracket_id,m.round_name,m.entry1_id,m.entry2_id,m.winner_entry_id,m.match_status,b.bracket_name,b.bracket_order,e1.entry_name entry1_name,e2.entry_name entry2_name
 FROM matches m LEFT JOIN brackets b ON b.bracket_id=m.bracket_id
 LEFT JOIN tournament_entries e1 ON e1.entry_id=m.entry1_id LEFT JOIN tournament_entries e2 ON e2.entry_id=m.entry2_id
 WHERE m.category_id=? AND LOWER(m.round_name)='round robin'
 ORDER BY COALESCE(b.bracket_order,0),m.match_number,m.match_id");
 $q->execute([$categoryId]);$matches=$q->fetchAll(PDO::FETCH_ASSOC);$matchCount=count($matches);
 $ids=array_values(array_filter(array_map('intval',array_column($matches,'match_id'))));
 $scoresByMatch=[];
 if($ids){$in=implode(',',array_fill(0,count($ids),'?'));$sq=$pdo->prepare("SELECT match_id,entry1_score,entry2_score FROM match_scores WHERE match_id IN ($in) ORDER BY game_number");$sq->execute($ids);foreach($sq->fetchAll(PDO::FETCH_ASSOC) as $score)$scoresByMatch[(int)$score['match_id']][]=$score;}
 foreach($matches as $m){
  if(!empty($m['winner_entry_id']))$completedCount++;
  $bid=(int)($m['bracket_id']??0);if(!isset($brackets[$bid]))$brackets[$bid]=['name'=>$m['bracket_name']?:'Bracket','rows'=>[]];
  if(empty($m['winner_entry_id']))continue;
  $a=(int)$m['entry1_id'];$b=(int)$m['entry2_id'];if(!$a||!$b)continue;
  foreach([$a,$b] as $eid){
   if(!isset($brackets[$bid]['rows'][$eid]))$brackets[$bid]['rows'][$eid]=['name'=>$eid===$a?$m['entry1_name']:$m['entry2_name'],'played'=>0,'wins'=>0,'losses'=>0,'scored'=>0,'conceded'=>0];
  }
  $sa=0;$sb=0;foreach($scoresByMatch[(int)$m['match_id']]??[] as $sc){$sa+=(int)$sc['entry1_score'];$sb+=(int)$sc['entry2_score'];}
  foreach([[$a,$sa,$sb],[$b,$sb,$sa]] as [$eid,$scored,$conceded]){
   $brackets[$bid]['rows'][$eid]['played']++;$brackets[$bid]['rows'][$eid]['scored']+=$scored;$brackets[$bid]['rows'][$eid]['conceded']+=$conceded;
   if($eid===(int)$m['winner_entry_id'])$brackets[$bid]['rows'][$eid]['wins']++;else $brackets[$bid]['rows'][$eid]['losses']++;
  }
 }
 foreach($brackets as &$bracket){
  uasort($bracket['rows'],function($a,$b){return [$b['wins'],$b['scored']-$b['conceded'],$b['scored']] <=> [$a['wins'],$a['scored']-$a['conceded'],$a['scored']];});
 }unset($bracket);

 $eq=$pdo->prepare("SELECT m.match_id,m.round_name,m.entry1_id,m.entry2_id,m.winner_entry_id,m.match_status,e1.entry_name entry1_name,e2.entry_name entry2_name FROM matches m LEFT JOIN tournament_entries e1 ON e1.entry_id=m.entry1_id LEFT JOIN tournament_entries e2 ON e2.entry_id=m.entry2_id WHERE m.category_id=? AND LOWER(m.round_name)<>'round robin' ORDER BY m.match_number,m.match_id");$eq->execute([$categoryId]);$elimMatches=$eq->fetchAll(PDO::FETCH_ASSOC);
 $eids=array_values(array_filter(array_map('intval',array_column($elimMatches,'match_id'))));$elimScores=[];if($eids){$in=implode(',',array_fill(0,count($eids),'?'));$es=$pdo->prepare("SELECT match_id,entry1_score,entry2_score FROM match_scores WHERE match_id IN ($in) ORDER BY game_number");$es->execute($eids);foreach($es->fetchAll(PDO::FETCH_ASSOC) as $row)$elimScores[(int)$row['match_id']][]=$row;}
 $eliminationPlacements=buildEliminationPlacements($elimMatches,$elimScores);
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Live Standings | COURTCTRL</title>
<link rel="stylesheet" href="assets/css/style.css"></head><body class="page-display-standings">
<header><div class="brand"><div class="mark">P</div><div><div class="eyebrow">COURTCTRL · TOURNAMENT ORGANIZER</div><h1>Live Tournament Standings & Podium</h1></div></div><div class="meta"><span class="pill"><?=se($tournamentName)?></span><span class="pill live">● LIVE DISPLAY</span></div></header>
<main data-refresh-container data-refresh-interval="5000"><div class="toolbar"><div><h2><?=se($categoryName?:'Select a category')?></h2><p>Round Robin brackets and elimination rankings · Automatically updates every 5 seconds</p></div><div class="standings-toolbar-actions"><form method="get"><input type="hidden" name="tournament_id" value="<?=se($tournamentId)?>"><select name="category_id" onchange="this.form.submit()"><?php foreach($categories as $cat):?><option value="<?=se($cat['category_id'])?>" <?=$categoryId===(int)$cat['category_id']?'selected':''?>><?=se($cat['category_name'])?></option><?php endforeach;?></select></form><button onclick="document.documentElement.requestFullscreen?.()">⛶ Full screen</button><button onclick="window.print()">Print</button></div></div>
<?php if(!empty($eliminationPlacements['limit'])):?><section class="card podium-card"><h3>🏆 Top 3 · Tournament Podium <span class="count">Elimination results</span></h3><div class="podium"><div class="podium-item silver"><span>🥈</span><strong><?=se($eliminationPlacements['podium'][2]['name']??'TBD')?></strong><small>Runner-up</small><b>2</b></div><div class="podium-item gold"><span>🥇</span><strong><?=se($eliminationPlacements['podium'][1]['name']??'TBD')?></strong><small>Champion</small><b>1</b></div><div class="podium-item bronze"><span>🥉</span><strong><?=se($eliminationPlacements['podium'][3]['name']??'TBD')?></strong><small>Third place</small><b>3</b></div></div></section>
<section class="card"><h3>Final Rankings · Top <?=se($eliminationPlacements['limit'])?><span class="count">Live elimination ranking</span></h3><div class="table-wrap"><table><th scope="col"ead><tr><th scope="col">#</th><th scope="col">Team / Entry</th><th scope="col">Placement</th></tr></thead><tbody><?php for($r=1;$r<=$eliminationPlacements['limit'];$r++):$person=$eliminationPlacements['ranked'][$r]??null;$label=$r===1?'Champion':($r===2?'Runner-up':($r===3?'Third Place':($r===4?'Fourth Place':($r<=8?'Quarterfinalist':'Round of 16'))));?><tr><td><span class="rank <?=$r===1?'first':($r===2?'second':'' )?>"><?=$r?></span></td><td class="entry"><?=se($person['name']??'— Not determined —')?></td><td><?=se($person?$label:'Pending')?></td></tr><?php endfor;?></tbody></table></div><div class="empty">Positions 5–8 populate after all quarterfinals are complete. Positions 9–16 populate after all Round-of-16 matches are complete. Ties are ordered by points scored, then point differential.</div></section><?php endif;?>
<?php if(!$brackets):?><div class="card"><div class="empty">No Round Robin standings are available yet. Completed match results will appear here automatically.</div></div><?php else:?><div class="grid"><?php foreach($brackets as $bracket):?><section class="card"><h3><?=se($bracket['name'])?><span class="count"><?=count($bracket['rows'])?> entries</span></h3><?php if(!$bracket['rows']):?><div class="empty">Standings will appear when matches are completed.</div><?php else:?><div class="table-wrap"><table><th scope="col"ead><tr><th scope="col">#</th><th scope="col">Team / Entry</th><th scope="col">W</th><th scope="col">L</th><th scope="col">PF</th><th scope="col">PA</th><th scope="col">DIFF</th></tr></thead><tbody><?php $rank=1;foreach($bracket['rows'] as $row):?><tr><td><span class="rank <?=$rank===1?'first':($rank===2?'second':'' )?>"><?=$rank?></span></td><td class="entry"><?=se($row['name'])?></td><td class="record"><?=$row['wins']?></td><td class="record"><?=$row['losses']?></td><td class="record"><?=$row['scored']?></td><td class="record"><?=$row['conceded']?></td><td class="record"><?=($row['scored']-$row['conceded'])>0?'+':''?><?=$row['scored']-$row['conceded']?></td></tr><?php $rank++;endforeach;?></tbody></table></div><?php endif;?></section><?php endforeach;?></div><?php endif;?>
<div class="footer"><span><?=$completedCount?> / <?=$matchCount?> Round Robin matches completed</span><span data-refresh-indicator role="status" aria-live="polite">Updated <strong id="refreshed">--:--:--</strong></span><span>COURTCTRL · Live standings screen</span></div></main>
<script>
(function(){const el=document.getElementById('refreshed');if(el)el.textContent=new Date().toLocaleTimeString();})();
</script><script src="assets/js/app.js" defer></script></body></html>
