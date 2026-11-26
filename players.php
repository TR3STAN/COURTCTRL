<?php
session_start();
require_once __DIR__ . '/config/database.php';
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$errors=[]; $success='';
$tournaments=$pdo->query("SELECT tournament_id,tournament_name FROM tournaments ORDER BY created_at DESC")->fetchAll();
$selectedTournament=(int)($_GET['tournament_id'] ?? $_POST['tournament_id'] ?? ($_SESSION['active_tournament_id'] ?? ($tournaments[0]['tournament_id'] ?? 0))); if(!in_array($selectedTournament,array_map('intval',array_column($tournaments,'tournament_id')),true)){$selectedTournament=(int)($tournaments[0]['tournament_id']??0);} if($selectedTournament){$_SESSION['active_tournament_id']=$selectedTournament;}
$activeTournamentName=''; foreach($tournaments as $tour){if((int)$tour['tournament_id']===$selectedTournament){$activeTournamentName=$tour['tournament_name'];break;}}
$categories=[];
if($selectedTournament){
  $st=$pdo->prepare('SELECT category_id,category_name,category_type FROM categories WHERE tournament_id=? ORDER BY category_name');
  $st->execute([$selectedTournament]); $categories=$st->fetchAll();
}
$selectedCategory=(int)($_GET['category_id'] ?? $_POST['category_id'] ?? 0);
$selectedCategoryRow=null;
foreach($categories as $c){if((int)$c['category_id']===$selectedCategory){$selectedCategoryRow=$c;break;}}
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='import_entries'){
  if(!$selectedCategoryRow){$errors[]='Select a valid category before importing.';}
  elseif(!isset($_FILES['excel_file']) || $_FILES['excel_file']['error']!==UPLOAD_ERR_OK){$errors[]='Choose a valid Excel file to upload.';}
  else{
    $file=$_FILES['excel_file'];
    $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    if($ext!=='xlsx')$errors[]='Please upload an .xlsx Excel workbook.';
    elseif($file['size']>5*1024*1024)$errors[]='File is too large. Maximum size is 5 MB.';
    elseif(!is_file(__DIR__.'/vendor/autoload.php'))$errors[]='Excel import dependency is missing. Run composer install in the project folder.';
    else{
      try{
        require_once __DIR__.'/vendor/autoload.php';
        $sheet=\PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name'])->getActiveSheet();
        $rows=$sheet->toArray(null,true,true,true);
        if(count($rows)<2)throw new RuntimeException('The workbook has no participant rows.');
        $headers=array_map(fn($v)=>strtolower(trim((string)$v)),array_values($rows[1]??[]));
        $expected=($selectedCategoryRow['category_type']==='Doubles')
          ? ['player 1 first name','player 1 last name','player 1 gender','player 2 first name','player 2 last name','player 2 gender']
          : ['first name','last name','gender','contact number'];
        if($headers!==$expected)throw new RuntimeException('The Excel columns do not match the template for this category. Download the correct template and do not change its header row.');
        $dataRows=array_slice($rows,1,null,true);
        if(count($dataRows)>500)throw new RuntimeException('A maximum of 500 entries can be imported at once.');
        $pdo->beginTransaction();
        $findPlayer=$pdo->prepare('SELECT player_id FROM players WHERE LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) LIMIT 1');
        $createPlayer=$pdo->prepare('INSERT INTO players(first_name,last_name,gender,contact_number) VALUES(?,?,?,?)');
        $getPlayer=function($first,$last,$gender=null,$contact=null)use($findPlayer,$createPlayer,$pdo){
          $findPlayer->execute([$first,$last]);$id=$findPlayer->fetchColumn();
          if($id)return (int)$id;
          $createPlayer->execute([$first,$last,$gender?:null,$contact?:null]);return (int)$pdo->lastInsertId();
        };
        $imported=0;$rowNum=1;
        foreach($dataRows as $row){$rowNum++;$values=array_values($row);$values=array_pad($values,count($expected),'');$values=array_map(fn($v)=>trim((string)$v),$values);if(count(array_filter($values,fn($v)=>$v!==''))===0)continue;
          if($selectedCategoryRow['category_type']==='Doubles'){
            [$p1First,$p1Last,$p1Gender,$p2First,$p2Last,$p2Gender]=$values;
            $teamName=$p1First.' '.$p1Last.' / '.$p2First.' '.$p2Last;
            if(!$p1First||!$p1Last||!$p2First||!$p2Last)throw new RuntimeException("Row {$rowNum}: both players need first and last names.");
            if(strcasecmp($p1First.' '.$p1Last,$p2First.' '.$p2Last)===0)throw new RuntimeException("Row {$rowNum}: a pair must contain two different players.");
            $p1=$getPlayer($p1First,$p1Last,$p1Gender);$p2=$getPlayer($p2First,$p2Last,$p2Gender);
            $check=$pdo->prepare('SELECT COUNT(*) FROM tournament_entries te JOIN team_members tm ON tm.team_id=te.team_id WHERE te.category_id=? AND tm.player_id IN (?,?) AND te.registration_status="Registered"');$check->execute([$selectedCategory,$p1,$p2]);
            if((int)$check->fetchColumn()>0)throw new RuntimeException("Row {$rowNum}: one or both players are already registered in this category.");
            if(!$teamName)$teamName=$p1First.' '.$p1Last.' & '.$p2First.' '.$p2Last;
            $team=$pdo->prepare('INSERT INTO teams(team_name) VALUES(?)');$team->execute([$teamName]);$teamId=(int)$pdo->lastInsertId();
            $member=$pdo->prepare('INSERT INTO team_members(team_id,player_id) VALUES(?,?)');$member->execute([$teamId,$p1]);$member->execute([$teamId,$p2]);
            $entryName=$p1First.' '.$p1Last.' / '.$p2First.' '.$p2Last;
            $entry=$pdo->prepare('INSERT INTO tournament_entries(category_id,entry_type,team_id,entry_name) VALUES(?,?,?,?)');$entry->execute([$selectedCategory,'Team',$teamId,$entryName]);
          }else{
            [$first,$last,$gender,$contact]=$values;
            if(!$first||!$last)throw new RuntimeException("Row {$rowNum}: first and last name are required.");
            $playerId=$getPlayer($first,$last,$gender,$contact);
            $check=$pdo->prepare('SELECT COUNT(*) FROM tournament_entries WHERE category_id=? AND player_id=? AND registration_status="Registered"');$check->execute([$selectedCategory,$playerId]);
            if((int)$check->fetchColumn()>0)throw new RuntimeException("Row {$rowNum}: player is already registered in this category.");
            $entry=$pdo->prepare('INSERT INTO tournament_entries(category_id,entry_type,player_id,entry_name) VALUES(?,?,?,?)');$entry->execute([$selectedCategory,'Player',$playerId,$first.' '.$last]);
          }
          $imported++;
        }
        if($imported===0)throw new RuntimeException('No valid participant rows were found in the workbook.');
        $pdo->commit();
        header('Location: players.php?tournament_id='.$selectedTournament.'&category_id='.$selectedCategory.'&imported='.$imported);exit;
      }catch(Throwable $ex){
    if($pdo->inTransaction()) $pdo->rollBack();
    $errors[] = 'Excel import failed: ' . $ex->getMessage();
}
    }
  }
}
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='register_entry'){
  if(!$selectedCategoryRow){$errors[]='Select a valid category.';}
  $type=$selectedCategoryRow['category_type']??'';
  if($type==='Doubles'){
    $p1First=trim($_POST['p1_first']??''); $p1Last=trim($_POST['p1_last']??'');
    $p2First=trim($_POST['p2_first']??''); $p2Last=trim($_POST['p2_last']??'');
    // Team names are generated automatically from the two registered players.
    $teamName=trim($p1First.' '.$p1Last.' / '.$p2First.' '.$p2Last);
    if(!$p1First||!$p1Last||!$p2First||!$p2Last)$errors[]='Enter the full names of both players.';
    if(strcasecmp($p1First.' '.$p1Last,$p2First.' '.$p2Last)===0)$errors[]='A doubles team must contain two different players.';
  }elseif($type==='Singles'){
    $first=trim($_POST['first_name']??'');$last=trim($_POST['last_name']??'');
    if(!$first||!$last)$errors[]='Player first and last name are required.';
  }
  if(!$errors){
    try{
      $pdo->beginTransaction();
      $findPlayer=$pdo->prepare('SELECT player_id FROM players WHERE LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) LIMIT 1');
      $createPlayer=$pdo->prepare('INSERT INTO players(first_name,last_name,gender,contact_number) VALUES(?,?,?,?)');
      $getPlayer=function($first,$last,$gender=null,$contact=null)use($findPlayer,$createPlayer){
        $findPlayer->execute([$first,$last]);$id=$findPlayer->fetchColumn();
        if($id)return (int)$id;
        $createPlayer->execute([$first,$last,$gender,$contact]);return (int)$GLOBALS['pdo']->lastInsertId();
      };
      if($type==='Doubles'){
        $player1=$getPlayer($p1First,$p1Last,$_POST['p1_gender']??null,null);
        $player2=$getPlayer($p2First,$p2Last,$_POST['p2_gender']??null,null);
        $check=$pdo->prepare('SELECT COUNT(*) FROM tournament_entries te JOIN team_members tm ON tm.team_id=te.team_id WHERE te.category_id=? AND tm.player_id IN (?,?) AND te.registration_status="Registered"');
        $check->execute([$selectedCategory,$player1,$player2]);
        if((int)$check->fetchColumn()>0)throw new RuntimeException('One or both players are already registered in this category.');
        $team=$pdo->prepare('INSERT INTO teams(team_name) VALUES(?)');$team->execute([$teamName]);$teamId=(int)$pdo->lastInsertId();
        $member=$pdo->prepare('INSERT INTO team_members(team_id,player_id) VALUES(?,?)');$member->execute([$teamId,$player1]);$member->execute([$teamId,$player2]);
        $entryName=$p1First.' '.$p1Last.' / '.$p2First.' '.$p2Last;
        $entry=$pdo->prepare('INSERT INTO tournament_entries(category_id,entry_type,team_id,entry_name) VALUES(?,?,?,?)');$entry->execute([$selectedCategory,'Team',$teamId,$entryName]);
      }else{
        $playerId=$getPlayer($first,$last,$_POST['gender']??null,$_POST['contact_number']??null);
        $check=$pdo->prepare('SELECT COUNT(*) FROM tournament_entries WHERE category_id=? AND player_id=? AND registration_status="Registered"');$check->execute([$selectedCategory,$playerId]);
        if((int)$check->fetchColumn()>0)throw new RuntimeException('This player is already registered in the selected category.');
        $entryName=$first.' '.$last;
        $entry=$pdo->prepare('INSERT INTO tournament_entries(category_id,entry_type,player_id,entry_name) VALUES(?,?,?,?)');$entry->execute([$selectedCategory,'Player',$playerId,$entryName]);
      }
      $pdo->commit();$success='Registration added successfully.';
      // Keep the selected tournament/category in the URL after POST.
      header('Location: players.php?tournament_id='.$selectedTournament.'&category_id='.$selectedCategory.'&success=1');exit;
    }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();$errors[]=$ex instanceof RuntimeException?$ex->getMessage():'Registration could not be saved. Verify that the database tables match the setup script.';}
  }
}
if(isset($_GET['success']))$success='Registration added successfully.';
if(isset($_GET['imported']))$success=(int)$_GET['imported'].' entries imported successfully.';
if(isset($_GET['deleted']))$success=(int)$_GET['deleted'].' registration entr'.((int)$_GET['deleted']===1?'y':'ies').' deleted. Player records were retained.';

// Bulk-delete selected registration entries. Player records are retained unless an unused team container becomes orphaned.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_entries') {
    try {
        $entryIds = array_values(array_unique(array_filter(array_map('intval', $_POST['entry_ids'] ?? []), fn($id) => $id > 0)));
        if (!$entryIds) throw new RuntimeException('Select at least one registrant to delete.');
        $pdo->beginTransaction();
        $verify = $pdo->prepare('SELECT entry_id, entry_type, team_id FROM tournament_entries WHERE entry_id = ? AND category_id = ?');
        $matchCheck = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE entry1_id = ? OR entry2_id = ?');
        $deleteEntry = $pdo->prepare('DELETE FROM tournament_entries WHERE entry_id = ?');
        $teamUse = $pdo->prepare('SELECT COUNT(*) FROM tournament_entries WHERE team_id = ?');
        $deleted = 0;
        foreach ($entryIds as $entryId) {
            $verify->execute([$entryId, $selectedCategory]);
            $entry = $verify->fetch(PDO::FETCH_ASSOC);
            if (!$entry) continue;
            $matchCheck->execute([$entryId, $entryId]);
            if ((int)$matchCheck->fetchColumn() > 0) throw new RuntimeException('One or more selected entries are already in generated matches. Remove/regenerate the bracket before deleting them.');
            $deleteEntry->execute([$entryId]); $deleted++;
            if ($entry['entry_type'] === 'Team' && !empty($entry['team_id'])) {
                $teamUse->execute([(int)$entry['team_id']]);
                if ((int)$teamUse->fetchColumn() === 0) {
                    $pdo->prepare('DELETE FROM team_members WHERE team_id = ?')->execute([(int)$entry['team_id']]);
                    $pdo->prepare('DELETE FROM teams WHERE team_id = ?')->execute([(int)$entry['team_id']]);
                }
            }
        }
        $pdo->commit();
        header('Location: players.php?tournament_id='.$selectedTournament.'&category_id='.$selectedCategory.'&deleted='.$deleted); exit;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $exception->getMessage();
    }
}

// Delete a registration entry while retaining the underlying player records.
// Entries already used in generated matches must be removed from brackets first.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_entry') {
    try {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $verify = $pdo->prepare('SELECT entry_id, entry_type, team_id, player_id FROM tournament_entries WHERE entry_id = ? AND category_id = ?');
        $verify->execute([$entryId, $selectedCategory]);
        $entryToDelete = $verify->fetch(PDO::FETCH_ASSOC);
        if (!$entryToDelete) {
            throw new RuntimeException('Registration entry not found in the selected category.');
        }

        $matchCheck = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE entry1_id = ? OR entry2_id = ?');
        $matchCheck->execute([$entryId, $entryId]);
        if ((int) $matchCheck->fetchColumn() > 0) {
            throw new RuntimeException('This entry is already included in generated matches. Remove/regenerate its bracket first, then delete the registration.');
        }

        $pdo->beginTransaction();
        $deleteEntry = $pdo->prepare('DELETE FROM tournament_entries WHERE entry_id = ?');
        $deleteEntry->execute([$entryId]);

        // Remove the team container only when no tournament entry references it.
        if ($entryToDelete['entry_type'] === 'Team' && !empty($entryToDelete['team_id'])) {
            $teamId = (int) $entryToDelete['team_id'];
            $teamUse = $pdo->prepare('SELECT COUNT(*) FROM tournament_entries WHERE team_id = ?');
            $teamUse->execute([$teamId]);
            if ((int) $teamUse->fetchColumn() === 0) {
                $pdo->prepare('DELETE FROM team_members WHERE team_id = ?')->execute([$teamId]);
                $pdo->prepare('DELETE FROM teams WHERE team_id = ?')->execute([$teamId]);
            }
        }
        $pdo->commit();
        header('Location: players.php?tournament_id=' . $selectedTournament . '&category_id=' . $selectedCategory . '&deleted=1');
        exit;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $exception->getMessage();
    }
}

$entries=[];
if($selectedCategoryRow){
 $st=$pdo->prepare("SELECT te.entry_id,te.entry_name,te.entry_type,te.registered_at FROM tournament_entries te WHERE te.category_id=? AND te.registration_status='Registered' ORDER BY te.registered_at DESC");$st->execute([$selectedCategory]);$entries=$st->fetchAll();
}
$playerCount=0;$teamCount=0;foreach($entries as $entry){if($entry['entry_type']==='Team')$teamCount++;else $playerCount++;}
?>
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Players & Teams | CourtCtrl</title><link rel="stylesheet" href="assets/css/style.css"></head><body class="page-players"><div class="app-shell">
<aside class="sidebar"><div class="brand"><div class="brand-mark">P</div><div><strong>COURT<span>CTRL</span></strong><small>TOURNAMENT ORGANIZER</small></div></div><div class="nav-label">WORKSPACE</div><a class="nav-item" href="index.php"><span>▦</span> Dashboard</a><a class="nav-item" href="workspace.php?tournament_id=<?= (int)$selectedTournament ?>"><span>♜</span> Tournament Workspace</a><a class="nav-item" href="tournaments.php?id=<?= (int)$selectedTournament ?>"><span>⚙</span> Tournament Setup</a><a class="nav-item active" href="players.php?tournament_id=<?= (int)$selectedTournament ?>"><span>♙</span> Players &amp; Teams</a><a class="nav-item" href="brackets.php?tournament_id=<?= (int)$selectedTournament ?>"><span>⌘</span> Brackets</a><a class="nav-item" href="schedule.php?tournament_id=<?= (int)$selectedTournament ?>"><span>▦</span> Schedule &amp; Courts</a><a class="nav-item" href="live_feed.php?tournament_id=<?= (int)$selectedTournament ?>"><span>◉</span> Live Feed</a><a class="nav-item" href="scoring.php?tournament_id=<?= (int)$selectedTournament ?>"><span>☷</span> Scoring &amp; Standings</a><div class="sidebar-bottom"><div class="help-dot">?</div><div><strong>Organizer workspace</strong><small>COURTCTRL tournament control</small></div></div></aside>
<main class="main-content"><header class="topbar"><div class="crumb">Workspace <span>/</span> Players &amp; Teams</div><span class="context-label">ACTIVE TOURNAMENT</span><strong><?=e($activeTournamentName ?: 'No tournament selected')?></strong></span><div class="profile"><div class="avatar">A</div><div><strong>Administrator</strong><small>Tournament organizer</small></div></div></header><div class="active-tournament-strip"><span>ACTIVE TOURNAMENT</span><strong><?=e($activeTournamentName ?: 'No tournament selected')?></strong><a href="workspace.php?tournament_id=<?= (int)($_SESSION["active_tournament_id"] ?? 0) ?>">Switch</a></div>
<section class="page-heading"><div><p class="eyebrow">REGISTRATION MANAGEMENT</p><h1>Players & Teams</h1><p class="subheading">Register doubles pairs or singles players into tournament categories. <strong class="selected-tournament-inline"><?=e($activeTournamentName ?: 'No tournament selected')?></strong></p></div><a class="btn btn-outline" href="workspace.php?tournament_id=<?= (int)$selectedTournament ?>">← Tournament Workspace</a></section>
<?php if($success):?><div class="alert success" role="status" aria-live="polite"><?=e($success)?></div><?php endif;?><?php if($errors):?><div class="alert error" role="alert" aria-live="polite"><strong>Please review:</strong><ul><?php foreach($errors as $err):?><li><?=e($err)?></li><?php endforeach;?></ul></div><?php endif;?>
<?php if(!$tournaments):?><section class="panel"><div class="empty-state"><div class="empty-icon">♜</div><h3>Create a tournament first</h3><p>Player registration requires a tournament and at least one category.</p><a class="btn btn-primary" href="index.php">Go to dashboard</a></div></section>
<?php elseif(!$categories):?><section class="panel"><div class="empty-state"><h3>No categories found</h3><p>Add categories to this tournament before registering participants.</p><a class="btn btn-primary" href="index.php">Manage tournaments</a></div></section>
<?php else:?>
<section class="content-card" style="margin-bottom:18px"><form class="toolbar" method="get"><input type="hidden" name="tournament_id" value="<?=e($selectedTournament)?>"><div class="field"><label for="category_id">Category</label><select name="category_id" id="category_id" onchange="this.form.submit()"><option value="" disabled <?=$selectedCategory===0?'selected':''?>>Select a category...</option><?php foreach($categories as $c):?><option value="<?=e($c['category_id'])?>" <?=$selectedCategory==(int)$c['category_id']?'selected':''?>><?=e($c['category_name'])?> — <?=e($c['category_type'])?></option><?php endforeach;?></select></div></form></section>
<section class="content-card" style="margin-bottom:18px"><div class="card-head"><h2>Import from Excel</h2><p>Optional bulk registration using the category-specific .xlsx template.</p></div><div class="card-body"><div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end"><div style="flex:1;min-width:240px"><a class="btn btn-outline" href="download_template.php?category_id=<?=e($selectedCategory)?>">↓ Download Excel template</a><p class="helper" style="margin:10px 0 0">Fill one row per pair for doubles or one row per player for singles. Keep the header row unchanged.</p></div><form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap"><input type="hidden" name="action" value="import_entries"><input type="hidden" name="tournament_id" value="<?=e($selectedTournament)?>"><input type="hidden" name="category_id" value="<?=e($selectedCategory)?>"><div class="field" style="margin:0;min-width:220px"><label for="excel_file">Excel workbook (.xlsx)</label><input type="file" id="excel_file" name="excel_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div><button class="btn btn-primary" type="submit">Import entries</button></form></div></div></section>
<div class="content-grid"><section class="content-card"><div class="card-head"><h2>Register entry</h2><p><?=e($selectedCategoryRow['category_name']??'')?> · <?=e($selectedCategoryRow['category_type']??'')?></p></div><div class="card-body"><form method="post"><input type="hidden" name="action" value="register_entry"><input type="hidden" name="tournament_id" value="<?=e($selectedTournament)?>"><input type="hidden" name="category_id" value="<?=e($selectedCategory)?>">
<?php if(($selectedCategoryRow['category_type']??'')==='Doubles'):?>
<div class="registration-pair">
<div class="player-block"><div class="player-block-title"><span class="player-number">1</span><h4>Player 1</h4></div>
<div class="field-row"><div class="field"><label>First name *</label><input name="p1_first" required maxlength="80" autocomplete="given-name" placeholder="First name"></div><div class="field"><label>Last name *</label><input name="p1_last" required maxlength="80" autocomplete="family-name" placeholder="Last name"></div></div>
<div class="field"><label>Gender</label><select name="p1_gender"><option value="">Select (optional)</option><option>Male</option><option>Female</option><option>Other</option></select></div></div>
<div class="pair-divider"><span>+</span></div>
<div class="player-block"><div class="player-block-title"><span class="player-number">2</span><h4>Player 2</h4></div>
<div class="field-row"><div class="field"><label>First name *</label><input name="p2_first" required maxlength="80" autocomplete="given-name" placeholder="First name"></div><div class="field"><label>Last name *</label><input name="p2_last" required maxlength="80" autocomplete="family-name" placeholder="Last name"></div></div>
<div class="field"><label>Gender</label><select name="p2_gender"><option value="">Select (optional)</option><option>Male</option><option>Female</option><option>Other</option></select></div></div>
</div><p class="helper">Both players will be registered as one pair. The pair name is automatically generated from their names.</p>
<?php else:?><div class="field-row"><div class="field"><label>First name *</label><input name="first_name" required maxlength="80"></div><div class="field"><label>Last name *</label><input name="last_name" required maxlength="80"></div></div><div class="field"><label>Gender</label><select name="gender"><option value="">Select (optional)</option><option>Male</option><option>Female</option><option>Other</option></select></div><div class="field"><label>Contact number</label><input name="contact_number" maxlength="30" placeholder="Optional"></div><p class="helper">Each singles player is registered as an individual entry.</p><?php endif;?>
<button type="submit" class="btn btn-primary">＋ Register <?=($selectedCategoryRow['category_type']??'')==='Doubles'?'pair':'player'?></button></form></div></section>
<section class="content-card"><div class="card-head"><h2>Registered entries</h2><p>Participants in the selected category.</p><div class="entry-counts" style="margin-top:12px"><span class="count-pill"><?=count($entries)?> total entries</span><span class="count-pill"><?=$teamCount?> pairs</span><span class="count-pill"><?=$playerCount?> singles</span></div></div><?php if(!$entries):?><div class="empty-note">No participants registered in this category yet.</div><?php else:?><form method="post" id="bulkDeleteForm" onsubmit="return confirm('Delete all selected registration entries?')"><input type="hidden" name="action" value="delete_entries"><input type="hidden" name="tournament_id" value="<?=e($selectedTournament)?>"><input type="hidden" name="category_id" value="<?=e($selectedCategory)?>"><div class="bulk-toolbar"><label><input type="checkbox" id="selectAllEntries"> Select all</label><button class="btn btn-danger" type="submit">Delete selected</button></div><div class="table-wrap"><table><th scope="col"ead><tr><th scope="col">SELECT</th><th scope="col">ENTRY</th><th scope="col">TYPE</th><th scope="col">REGISTERED</th><th scope="col">ACTION</th></tr></thead><tbody><?php foreach($entries as $entry):?><tr><td><input type="checkbox" class="entry-select" name="entry_ids[]" value="<?=e($entry['entry_id'])?>"></td><td><div class="entry-title"><?=e($entry['entry_name'])?></div><div class="entry-meta">Entry #<?=e($entry['entry_id'])?></div></td><td><?=e($entry['entry_type'])?></td><td><?=e(date('M j, Y',strtotime($entry['registered_at'])))?></td><td><form method="post" onsubmit="return confirm('Delete this registration entry? The player record will be kept.')"><input type="hidden" name="action" value="delete_entry"><input type="hidden" name="tournament_id" value="<?=e($selectedTournament)?>"><input type="hidden" name="category_id" value="<?=e($selectedCategory)?>"><input type="hidden" name="entry_id" value="<?=e($entry['entry_id'])?>"><button class="btn btn-delete-entry" type="submit">Delete</button></form></td></tr><?php endforeach;?></tbody></table></div></form><?php endif;?></section></div>
<?php endif;?>
<footer class="footer">COURTCTRL <span>•</span> Pickleball Tournament Organizer</footer></main></div><script>document.getElementById("selectAllEntries")?.addEventListener("change",function(){document.querySelectorAll(".entry-select").forEach(cb=>cb.checked=this.checked);});</script>
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
