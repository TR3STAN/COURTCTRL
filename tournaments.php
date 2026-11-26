<?php
session_start();
require_once __DIR__ . '/config/database.php';
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$errors=[]; $success='';
$id=(int)($_GET['id']??$_POST['tournament_id']??($_SESSION['active_tournament_id']??0));
$statuses=['Planning','Registration','Ongoing','Completed'];

if($_SERVER['REQUEST_METHOD']==='POST'){
  $action=$_POST['action']??'';
  try{
    if($action==='update_tournament'){
      $name=trim($_POST['tournament_name']??'');
      $days=filter_var($_POST['number_of_days']??null,FILTER_VALIDATE_INT);
      $courts=filter_var($_POST['number_of_courts']??null,FILTER_VALIDATE_INT);
      $date=trim($_POST['start_date']??'');
      $status=$_POST['status']??'Planning';
      if($name==='') throw new RuntimeException('Tournament name is required.');
      if(!$days||$days<1||$days>30) throw new RuntimeException('Days must be between 1 and 30.');
      if(!$courts||$courts<1||$courts>100) throw new RuntimeException('Courts must be between 1 and 100.');
      if(!in_array($status,$statuses,true)) throw new RuntimeException('Choose a valid status.');
      if($date!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) throw new RuntimeException('Enter a valid start date.');
      $endDate=null;
      if($date!=='') $endDate=date('Y-m-d',strtotime($date.' +'.($days-1).' days'));
      $pdo->beginTransaction();
      $q=$pdo->prepare('UPDATE tournaments SET tournament_name=?,number_of_days=?,start_date=?,end_date=?,number_of_courts=?,status=? WHERE tournament_id=?');
      $q->execute([$name,$days,$date?:null,$endDate,$courts,$status,$id]);
      $q=$pdo->prepare('SELECT court_id,court_name FROM courts WHERE tournament_id=? ORDER BY court_id');$q->execute([$id]);$existing=$q->fetchAll();
      while(count($existing)<$courts){
        $n=count($existing)+1;$ins=$pdo->prepare('INSERT INTO courts(tournament_id,court_name,is_active) VALUES(?,?,1)');$ins->execute([$id,'Court '.$n]);$existing[]=['court_id'=>$pdo->lastInsertId(),'court_name'=>'Court '.$n];
      }
      if(count($existing)>$courts){
        $remove=array_slice($existing,$courts);
        foreach($remove as $court){
          $check=$pdo->prepare('SELECT COUNT(*) FROM match_schedules WHERE court_id=?');$check->execute([$court['court_id']]);
          if((int)$check->fetchColumn()>0) throw new RuntimeException('Cannot reduce courts while matches are assigned to the extra courts. Move those matches first.');
          $pdo->prepare('DELETE FROM courts WHERE court_id=?')->execute([$court['court_id']]);
        }
      }
      $pdo->commit();
      header('Location: tournaments.php?id='.$id.'&saved=1');exit;
    }elseif($action==='add_category'){
      $name=trim($_POST['category_name']??'');$type=$_POST['category_type']??'Doubles';$format=$_POST['game_format']??'Round Robin';
      if($name==='')throw new RuntimeException('Category name is required.');
      if(!in_array($type,['Singles','Doubles'],true))throw new RuntimeException('Invalid category type.');
      if(!in_array($format,['Round Robin','Single Elimination','Round Robin + Elimination'],true))throw new RuntimeException('Invalid game format.');
      $q=$pdo->prepare('INSERT INTO categories(tournament_id,category_name,category_type,game_format) VALUES(?,?,?,?)');$q->execute([$id,$name,$type,$format]);
      header('Location: tournaments.php?id='.$id.'&category_added=1');exit;
    }elseif($action==='delete_category'){
      $cat=(int)($_POST['category_id']??0);
      $q=$pdo->prepare('SELECT category_id FROM categories WHERE category_id=? AND tournament_id=?');$q->execute([$cat,$id]);
      if(!$q->fetchColumn())throw new RuntimeException('Category not found.');
      $q=$pdo->prepare('SELECT COUNT(*) FROM matches WHERE category_id=?');$q->execute([$cat]);
      if((int)$q->fetchColumn()>0)throw new RuntimeException('This category already has matches. Remove its matches before deleting it.');
      $pdo->prepare('DELETE FROM categories WHERE category_id=?')->execute([$cat]);
      header('Location: tournaments.php?id='.$id.'&category_deleted=1');exit;
    }
  }catch(Throwable $ex){
    if($pdo->inTransaction())$pdo->rollBack();
    $errors[]=$ex instanceof RuntimeException?$ex->getMessage():'The action could not be completed. Please check the database and try again.';
  }
}
if(isset($_GET['saved']))$success='Tournament details updated.';
if(isset($_GET['category_added']))$success='Category added.';
if(isset($_GET['category_deleted']))$success='Category deleted.';
$tournaments=$pdo->query("SELECT t.*, (SELECT COUNT(*) FROM categories c WHERE c.tournament_id=t.tournament_id) category_count, (SELECT COUNT(*) FROM tournament_entries te JOIN categories c ON c.category_id=te.category_id WHERE c.tournament_id=t.tournament_id AND te.registration_status='Registered') entry_count FROM tournaments t ORDER BY t.created_at DESC")->fetchAll();
$tournament=null;$categories=[];$courts=[];
if($id){
 $q=$pdo->prepare('SELECT * FROM tournaments WHERE tournament_id=?');$q->execute([$id]);$tournament=$q->fetch(); if($tournament){$_SESSION['active_tournament_id']=$id;}
 if($tournament){
  $q=$pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM tournament_entries te WHERE te.category_id=c.category_id AND te.registration_status='Registered') entry_count FROM categories c WHERE c.tournament_id=? ORDER BY c.category_name");$q->execute([$id]);$categories=$q->fetchAll();
  $q=$pdo->prepare('SELECT * FROM courts WHERE tournament_id=? ORDER BY court_id');$q->execute([$id]);$courts=$q->fetchAll();
 }
}
$currentPage=basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Tournaments | CourtCtrl</title><link rel="stylesheet" href="assets/css/style.css">
</head><body class="page-tournaments"><div class="app-shell">
<aside class="sidebar"><div class="brand"><div class="brand-mark">P</div><div><strong>COURT<span>CTRL</span></strong><small>TOURNAMENT ORGANIZER</small></div></div><div class="nav-label">WORKSPACE</div><a class="nav-item" href="index.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span> Dashboard</a><a class="nav-item" href="workspace.php?tournament_id=<?= (int)$id ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 21h14M7 21V8h10v13M9 8V4h6v4M4 8h16"/></svg></span> Tournament Workspace</a><a class="nav-item active" href="tournaments.php?id=<?= (int)$id ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-1.7 2.9-.2-.1a1.7 1.7 0 0 0-1.8.1l-.2.1h-3.4l-.1-.2a1.7 1.7 0 0 0-1.6-1l-.2.1-2.9-1.7.1-.2a1.7 1.7 0 0 0-.1-1.8l-.1-.2v-3.4l.2-.1a1.7 1.7 0 0 0 1-1.6l-.1-.2 1.7-2.9.2.1a1.7 1.7 0 0 0 1.8-.1l.2-.1h3.4l.1.2a1.7 1.7 0 0 0 1.6 1l.2-.1 2.9 1.7-.1.2a1.7 1.7 0 0 0 .1 1.8l.1.2V15z"/></svg></span> Tournament Setup</a><a class="nav-item" href="players.php?tournament_id=<?= (int)$id ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.2 2.5-5 6-5s6 1.8 6 5M17 5a3 3 0 0 1 0 6M18 15c2 .5 3 2 3 4"/></svg></span> Players &amp; Teams</a><a class="nav-item" href="brackets.php?tournament_id=<?= (int)$id ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4v5h6v6h8M5 20v-5h6M17 12h2v6h-2zM3 2h4v4H3z"/></svg></span> Brackets</a><a class="nav-item" href="schedule.php?tournament_id=<?= (int)$id ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18M7 14h3M14 14h3"/></svg></span> Schedule &amp; Courts</a><a class="nav-item" href="live_feed.php?tournament_id=<?= (int)$id ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 2v2M22 12h-2"/></svg></span> Live Feed</a><a class="nav-item" href="scoring.php?tournament_id=<?= (int)$id ?>"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4h14M5 9h14M5 14h9M5 19h9"/></svg></span> Scoring &amp; Standings</a><div class="sidebar-bottom"><div class="help-dot">?</div><div><strong>Organizer workspace</strong><small>COURTCTRL tournament control</small></div></div></aside>
<main class="main-content"><header class="topbar"><div class="crumb">Workspace <span>/</span> Tournament Setup</div><span class="context-label">ACTIVE TOURNAMENT</span><strong><?= $tournament ? e($tournament['tournament_name']) : 'Choose a tournament' ?></strong></span><div class="profile"><div class="avatar">A</div><div><strong>Administrator</strong><small>Tournament organizer</small></div></div></header><div class="active-tournament-strip"><span>ACTIVE TOURNAMENT</span><strong><?=e($tournament['tournament_name'] ?? 'Choose a tournament')?></strong><a href="workspace.php?tournament_id=<?= (int)($_SESSION["active_tournament_id"] ?? 0) ?>">Workspace</a></div>
<section class="page-heading"><div><p class="eyebrow">TOURNAMENT MANAGEMENT</p><h1><?= $tournament?e($tournament['tournament_name']):'Tournaments' ?></h1><p class="subheading"><?= $tournament?'Manage tournament details, categories, and courts.':'View and manage all tournaments in your workspace.' ?></p></div><a class="btn btn-outline" href="index.php">← Dashboard</a></section>
<?php if($success):?><div class="alert success" role="status" aria-live="polite"><?=e($success)?></div><?php endif;?><?php if($errors):?><div class="alert error" role="alert" aria-live="polite"><strong>Please review:</strong><ul><?php foreach($errors as $err):?><li><?=e($err)?></li><?php endforeach;?></ul></div><?php endif;?>
<?php if(!$tournament):?>
<section class="panel"><div class="panel-heading"><div><h2>All tournaments</h2><p>Select a tournament to view or edit its setup.</p></div><span class="count-pill"><?=count($tournaments)?> tournaments</span></div>
<?php if(!$tournaments):?><div class="empty-state"><div class="empty-icon">♜</div><h3>No tournaments yet</h3><p>Create your first tournament from the dashboard.</p><a class="btn btn-primary" href="index.php">Go to Dashboard</a></div><?php else:?>
<div class="table-wrap"><table><th scope="col"ead><tr><th scope="col">TOURNAMENT</th><th scope="col">DATES</th><th scope="col">CATEGORIES</th><th scope="col">ENTRIES</th><th scope="col">STATUS</th><th scope="col"></th></tr></thead><tbody>
<?php foreach($tournaments as $t):?><tr><td><div class="tournament-name"><div class="mini-trophy">♜</div><div><strong><?=e($t['tournament_name'])?></strong><small><?=$t['number_of_days']?> day(s) · <?=$t['number_of_courts']?> courts</small></div></div></td><td><?=e($t['start_date']?:'Not set')?><small><?=e($t['end_date']?:'')?></small></td><td><?=$t['category_count']?></td><td><?=$t['entry_count']?></td><td><span class="status <?=strtolower(e($t['status']))?>"><?=e($t['status'])?></span></td><td><a class="btn btn-outline btn-small" href="tournaments.php?id=<?=$t['tournament_id']?>">Manage →</a></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?></section>
<?php elseif($tournament):?>
<div class="manage-grid"><section class="panel"><div class="panel-heading"><div><h2>Tournament details</h2><p>Edit the basic setup and status.</p></div></div><div class="panel-body">
<form method="post"><input type="hidden" name="action" value="update_tournament"><input type="hidden" name="tournament_id" value="<?=$id?>">
<div class="form-grid"><label class="field full">Tournament name<input name="tournament_name" maxlength="150" required value="<?=e($tournament['tournament_name'])?>"></label>
<label class="field">Start date<input type="date" name="start_date" value="<?=e($tournament['start_date'])?>"></label>
<label class="field">Number of days<input type="number" name="number_of_days" min="1" max="30" required value="<?=$tournament['number_of_days']?>"></label>
<label class="field">Number of courts<input type="number" name="number_of_courts" min="1" max="100" required value="<?=$tournament['number_of_courts']?>"></label>
<label class="field">Status<select name="status"><?php foreach($statuses as $s):?><option value="<?=e($s)?>" <?=$tournament['status']===$s?'selected':''?>><?=e($s)?></option><?php endforeach;?></select></label></div>
<button class="btn btn-primary" type="submit">Save changes</button> <a class="btn btn-outline" href="workspace.php?tournament_id=<?=$id?>">Back to workspace</a></form></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Overview</h2><p>Current tournament setup.</p></div></div><div class="panel-body"><div class="detail-list"><div class="detail-box"><small>Categories</small><strong><?=count($categories)?></strong></div><div class="detail-box"><small>Courts</small><strong><?=count($courts)?></strong></div><div class="detail-box"><small>Days</small><strong><?=$tournament['number_of_days']?></strong></div><div class="detail-box"><small>Registered entries</small><strong><?=array_sum(array_column($categories,'entry_count'))?></strong></div></div><p class="muted" class="tournament-courts-label">Courts</p><div class="court-pills"><?php foreach($courts as $court):?><span class="court-pill"><?=e($court['court_name'])?></span><?php endforeach;?></div></div></section></div>
<section class="panel section-gap"><div class="panel-heading"><div><h2>Categories</h2><p>Categories belong to this tournament. Categories with matches cannot be deleted here.</p></div><span class="count-pill"><?=count($categories)?> categories</span></div>
<div class="panel-body"><form method="post" class="category-form"><input type="hidden" name="action" value="add_category"><input type="hidden" name="tournament_id" value="<?=$id?>">
<label class="field">Category name<input name="category_name" maxlength="100" required placeholder="e.g. Open Mixed Doubles"></label>
<label class="field">Type<select name="category_type"><option>Doubles</option><option>Singles</option></select></label>
<label class="field">Game format<select name="game_format"><option>Round Robin</option><option>Single Elimination</option><option>Round Robin + Elimination</option></select></label><div class="field category-submit-field"><button class="btn btn-primary" type="submit">+ Add category</button></div></form></div>
<?php if($categories):?><div class="table-wrap"><table><th scope="col"ead><tr><th scope="col">CATEGORY</th><th scope="col">TYPE</th><th scope="col">FORMAT</th><th scope="col">ENTRIES</th><th scope="col"></th></tr></thead><tbody><?php foreach($categories as $c):?><tr><td><span class="category-name"><?=e($c['category_name'])?></span></td><td><?=e($c['category_type'])?></td><td><?=e($c['game_format'])?></td><td><?=$c['entry_count']?></td><td><form method="post" data-confirm="Delete this category and its registrations? This cannot be undone."><input type="hidden" name="action" value="delete_category"><input type="hidden" name="tournament_id" value="<?=$id?>"><input type="hidden" name="category_id" value="<?=$c['category_id']?>"><button class="btn btn-danger btn-small" type="submit">Delete</button></form></td></tr><?php endforeach;?></tbody></table></div><?php else:?><div class="empty-note">No categories have been added.</div><?php endif;?></section>
<?php endif;?>
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


<script src="assets/js/app.js" defer></script>\n</body></html>
