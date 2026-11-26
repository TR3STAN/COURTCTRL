<?php
session_start();
require_once __DIR__ . '/config/database.php';

function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_tournament') {
    $deleteId = filter_var($_POST['tournament_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$deleteId) {
        $errors[] = 'Invalid tournament selected for deletion.';
    } else {
        try {
            $pdo->beginTransaction();
            $check = $pdo->prepare('SELECT tournament_id FROM tournaments WHERE tournament_id=?');
            $check->execute([$deleteId]);
            if (!$check->fetchColumn()) throw new RuntimeException('Tournament was not found.');

            // Collect team IDs before removing entries, so orphaned team records can be cleaned up.
            $teamQuery = $pdo->prepare('SELECT DISTINCT te.team_id FROM tournament_entries te JOIN categories c ON c.category_id=te.category_id WHERE c.tournament_id=? AND te.team_id IS NOT NULL');
            $teamQuery->execute([$deleteId]);
            $teamIds = $teamQuery->fetchAll(PDO::FETCH_COLUMN);

            $pdo->prepare('DELETE ms FROM match_scores ms JOIN matches m ON m.match_id=ms.match_id JOIN categories c ON c.category_id=m.category_id WHERE c.tournament_id=?')->execute([$deleteId]);
            $pdo->prepare('DELETE sch FROM match_schedules sch JOIN matches m ON m.match_id=sch.match_id JOIN categories c ON c.category_id=m.category_id WHERE c.tournament_id=?')->execute([$deleteId]);
            $pdo->prepare('DELETE m FROM matches m JOIN categories c ON c.category_id=m.category_id WHERE c.tournament_id=?')->execute([$deleteId]);
            $pdo->prepare('DELETE st FROM standings st JOIN brackets b ON b.bracket_id=st.bracket_id JOIN categories c ON c.category_id=b.category_id WHERE c.tournament_id=?')->execute([$deleteId]);
            $pdo->prepare('DELETE b FROM brackets b JOIN categories c ON c.category_id=b.category_id WHERE c.tournament_id=?')->execute([$deleteId]);
            $pdo->prepare('DELETE te FROM tournament_entries te JOIN categories c ON c.category_id=te.category_id WHERE c.tournament_id=?')->execute([$deleteId]);
            $pdo->prepare('DELETE FROM categories WHERE tournament_id=?')->execute([$deleteId]);
            $pdo->prepare('DELETE FROM courts WHERE tournament_id=?')->execute([$deleteId]);
            if ($teamIds) {
                $in = implode(',', array_fill(0, count($teamIds), '?'));
                $pdo->prepare("DELETE tm FROM team_members tm WHERE tm.team_id IN ($in) AND NOT EXISTS (SELECT 1 FROM tournament_entries te WHERE te.team_id=tm.team_id)")->execute($teamIds);
                $pdo->prepare("DELETE t FROM teams t WHERE t.team_id IN ($in) AND NOT EXISTS (SELECT 1 FROM tournament_entries te WHERE te.team_id=t.team_id)")->execute($teamIds);
            }
            $pdo->prepare('DELETE FROM tournaments WHERE tournament_id=?')->execute([$deleteId]);
            $pdo->commit();
            header('Location: index.php?deleted=1'); exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Could not delete this tournament. Check database relationships and try again.';
        }
    }
}
if (isset($_GET['deleted'])) $success = 'Tournament and its related tournament data were deleted.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_tournament') {
    $name = trim($_POST['tournament_name'] ?? '');
    $days = filter_var($_POST['number_of_days'] ?? null, FILTER_VALIDATE_INT);
    $courts = filter_var($_POST['number_of_courts'] ?? null, FILTER_VALIDATE_INT);
    $startDate = trim($_POST['start_date'] ?? '');
    $categoryNames = $_POST['categories'] ?? [];
    $categoryTypes = $_POST['category_types'] ?? [];
    $formats = $_POST['game_formats'] ?? [];

    if ($name === '') $errors[] = 'Tournament name is required.';
    if (!$days || $days < 1 || $days > 30) $errors[] = 'Number of days must be between 1 and 30.';
    if (!$courts || $courts < 1 || $courts > 100) $errors[] = 'Number of courts must be between 1 and 100.';
    if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $errors[] = 'Please provide a valid start date.';

    $validCategories = [];
    foreach ($categoryNames as $i => $categoryName) {
        $categoryName = trim($categoryName);
        if ($categoryName === '') continue;
        $type = $categoryTypes[$i] ?? 'Doubles';
        $format = $formats[$i] ?? 'Round Robin + Elimination';
        if (!in_array($type, ['Singles', 'Doubles'], true)) $type = 'Doubles';
        if (!in_array($format, ['Round Robin', 'Single Elimination', 'Round Robin + Elimination'], true)) $format = 'Round Robin + Elimination';
        $validCategories[] = ['name' => $categoryName, 'type' => $type, 'format' => $format];
    }
    if (!$validCategories) $errors[] = 'Add at least one category.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $endDate = null;
            if ($startDate !== '') {
                $end = new DateTime($startDate);
                $end->modify('+' . ($days - 1) . ' days');
                $endDate = $end->format('Y-m-d');
            }
            $stmt = $pdo->prepare('INSERT INTO tournaments (tournament_name, number_of_days, start_date, end_date, number_of_courts, status) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $days, $startDate ?: null, $endDate, $courts, 'Planning']);
            $tournamentId = (int)$pdo->lastInsertId();

            $catStmt = $pdo->prepare('INSERT INTO categories (tournament_id, category_name, category_type, game_format) VALUES (?, ?, ?, ?)');
            foreach ($validCategories as $category) {
                $catStmt->execute([$tournamentId, $category['name'], $category['type'], $category['format']]);
            }

            $courtStmt = $pdo->prepare('INSERT INTO courts (tournament_id, court_name) VALUES (?, ?)');
            for ($i = 1; $i <= $courts; $i++) $courtStmt->execute([$tournamentId, 'Court ' . $i]);

            $pdo->commit();
            $success = 'Tournament created successfully.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Could not save the tournament. Check that the database tables match the setup script.';
        }
    }
}

$tournaments = $pdo->query("SELECT t.*, (SELECT COUNT(*) FROM categories c WHERE c.tournament_id=t.tournament_id) AS category_count FROM tournaments t ORDER BY t.created_at DESC")->fetchAll();
$totalTournaments = count($tournaments);
$activeTournaments = 0;
$plannedTournaments = 0;
foreach ($tournaments as $t) {
    if ($t['status'] === 'Ongoing') $activeTournaments++;
    if ($t['status'] === 'Planning' || $t['status'] === 'Registration') $plannedTournaments++;
}
?>
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pickleball Organizer | Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="page-dashboard">
<!-- Shared inline SVG icon sprite; no external icon font or network required. -->
<svg xmlns="http://www.w3.org/2000/svg" class="svg-sprite" aria-hidden="true" focusable="false">
  <symbol id="cc-icon-dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/></symbol>
  <symbol id="cc-icon-workspace" viewBox="0 0 24 24"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="M4.5 7.8 12 12l7.5-4.2M12 12v9M8 5.2l8 4.5"/></symbol>
  <symbol id="cc-icon-settings" viewBox="0 0 24 24"><path d="M12 3.2 14 4l.7 2.1 2 .9 2.1-.6 1.4 2.4-1.4 1.7v2.3l1.4 1.7-1.4 2.4-2.1-.6-2 .9L14 19.3l-2 .7-2-.7-.7-2.1-2-.9-2.1.6-1.4-2.4 1.4-1.7v-2.3L3.8 8.8l1.4-2.4 2.1.6 2-.9L10 4z"/><circle cx="12" cy="11.8" r="3"/></symbol>
  <symbol id="cc-icon-players" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3.5 20v-1.4a5.5 5.5 0 0 1 11 0V20zM16 5.5a3 3 0 0 1 0 5.8M17 14a4.5 4.5 0 0 1 3.5 4.4V20h-3"/></symbol>
  <symbol id="cc-icon-brackets" viewBox="0 0 24 24"><path d="M6 4v5a3 3 0 0 0 3 3h6a3 3 0 0 1 3 3v5M18 4v5a3 3 0 0 1-3 3M6 4h.01M18 4h.01M6 20h.01M18 20h.01"/></symbol>
  <symbol id="cc-icon-courts" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="1.5"/><path d="M12 3v18M3 12h18M8 3v18M16 3v18"/></symbol>
  <symbol id="cc-icon-live" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><path d="M12 3v2M21 12h-2M12 21v-2M3 12h2"/></symbol>
  <symbol id="cc-icon-scoring" viewBox="0 0 24 24"><path d="M8 5h13M8 12h13M8 19h13"/><circle cx="4" cy="5" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="19" r="1"/></symbol>
  <symbol id="cc-icon-trophy" viewBox="0 0 24 24"><path d="M8 4h8v4a4 4 0 0 1-8 0zM8 6H4v2a4 4 0 0 0 4 4M16 6h4v2a4 4 0 0 1-4 4M12 12v5M8 21h8M10 17h4v4h-4z"/></symbol>
  <symbol id="cc-icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
  <symbol id="cc-icon-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
  <symbol id="cc-icon-arrow-right" viewBox="0 0 24 24"><path d="M4 12h15M13 5l7 7-7 7"/></symbol>
  <symbol id="cc-icon-close" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></symbol>
  <symbol id="cc-icon-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.7 9a2.4 2.4 0 1 1 4.1 1.7c-1 .9-1.8 1.2-1.8 2.8M12 17h.01"/></symbol>
</svg>
<div class="app-shell">


<aside class="sidebar">
    <div class="brand">
        <div class="brand-mark">P</div>
        <div><strong>COURT<span>CTRL</span></strong><small>TOURNAMENT ORGANIZER</small></div>
    </div>
    <div class="nav-label">WORKSPACE</div>
    <a class="nav-item active" href="index.php" aria-current="page"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-dashboard"></use></svg>Dashboard</a>
    <a class="nav-item" href="workspace.php?tournament_id=<?= (int)($_SESSION['active_tournament_id']??0) ?>"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-workspace"></use></svg>Tournament Workspace</a>
    <a class="nav-item" href="tournaments.php?id=<?= (int)($_SESSION['active_tournament_id']??0) ?>"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-settings"></use></svg>Tournament Setup</a>
    <a class="nav-item" href="players.php?tournament_id=<?= (int)($_SESSION['active_tournament_id']??0) ?>"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-players"></use></svg>Players &amp; Teams</a>
    <a class="nav-item" href="brackets.php?tournament_id=<?= (int)($_SESSION['active_tournament_id']??0) ?>"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-brackets"></use></svg>Brackets</a>
    <a class="nav-item" href="schedule.php?tournament_id=<?= (int)($_SESSION['active_tournament_id']??0) ?>"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-courts"></use></svg>Schedule &amp; Courts</a>
    <a class="nav-item" href="live_feed.php?tournament_id=<?= (int)($_SESSION['active_tournament_id']??0) ?>"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-live"></use></svg>Live Feed</a>
    <a class="nav-item" href="scoring.php?tournament_id=<?= (int)($_SESSION['active_tournament_id']??0) ?>"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-scoring"></use></svg>Scoring &amp; Standings</a>
    <div class="sidebar-bottom"><svg class="cc-icon help-icon" aria-hidden="true"><use href="#cc-icon-help"></use></svg><div><strong>Organizer workspace</strong><small>COURTCTRL tournament control</small></div></div>
</aside>

    <main class="main-content">
        <header class="topbar"><div class="crumb">Workspace <span>/</span> Dashboard</div><div class="profile"><div class="avatar">A</div><div><strong>Administrator</strong><small>Tournament organizer</small></div></div></header>
        <section class="page-heading"><div><p class="eyebrow">TOURNAMENT CONTROL CENTER</p><h1>Dashboard</h1><p class="subheading">Set up events, organize categories, and prepare your courts for competition.</p></div><button class="btn btn-primary" id="openModal" type="button"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-plus"></use></svg>Create tournament</button></section>

        <?php if ($success): ?><div class="alert success" role="status" aria-live="polite"><?=e($success)?></div><?php endif; ?>
        <?php if ($errors): ?><div class="alert error" role="alert" aria-live="polite"><strong>Please review:</strong><ul><?php foreach ($errors as $error): ?><li><?=e($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <section class="stats-grid">
            <article class="stat-card"><div class="stat-top"><span class="stat-icon purple"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-trophy"></use></svg></span><span class="stat-caption">ALL TOURNAMENTS</span></div><div class="stat-value"><?=$totalTournaments?></div><div class="stat-foot">Created in this workspace</div></article>
            <article class="stat-card"><div class="stat-top"><span class="stat-icon green"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-live"></use></svg></span><span class="stat-caption">ONGOING</span></div><div class="stat-value"><?=$activeTournaments?></div><div class="stat-foot">Currently in progress</div></article>
            <article class="stat-card"><div class="stat-top"><span class="stat-icon orange"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-clock"></use></svg></span><span class="stat-caption">UPCOMING / PLANNING</span></div><div class="stat-value"><?=$plannedTournaments?></div><div class="stat-foot">Ready for setup</div></article>
        </section>

        <section class="panel tournament-panel">
            <div class="panel-heading"><div><h2>Your tournaments</h2><p>Manage tournament setup and preparation.</p></div><span class="count-pill"><?=$totalTournaments?> total</span></div>
            <?php if (!$tournaments): ?>
                <div class="empty-state"><div class="empty-icon"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-trophy"></use></svg></div><h3>No tournaments yet</h3><p>Create your first tournament to start configuring categories, days, and courts.</p><button class="btn btn-primary" id="emptyCreate" type="button"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-plus"></use></svg>Create your first tournament</button></div>
            <?php else: ?>
                <div class="table-wrap"><table><thead><tr><th scope="col">TOURNAMENT</th><th scope="col">DATES / DURATION</th><th scope="col">COURTS</th><th scope="col">CATEGORIES</th><th scope="col">STATUS</th><th scope="col">ACTIONS</th></tr></thead><tbody>
                <?php foreach ($tournaments as $t): ?><tr><td><div class="tournament-name"><div class="mini-trophy"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-trophy"></use></svg></div><div><strong><?=e($t['tournament_name'])?></strong><small>Created <?=e(date('M j, Y', strtotime($t['created_at'])))?></small></div></div></td><td><?= $t['start_date'] ? e(date('M j, Y', strtotime($t['start_date']))) . ' – ' . e(date('M j, Y', strtotime($t['end_date']))) : e($t['number_of_days'] . ' day' . ($t['number_of_days'] == 1 ? '' : 's')) ?><small class="cell-sub"><?=e($t['number_of_days'])?> day(s)</small></td><td><?=e($t['number_of_courts'])?> courts</td><td><?=e($t['category_count'])?> categories</td><td><span class="status <?=strtolower(e($t['status']))?>"><?=e($t['status'])?></span></td><td><a class="btn btn-primary btn-small" href="workspace.php?tournament_id=<?=e($t['tournament_id'])?>">Open workspace</a> <a class="btn btn-outline btn-small" href="tournaments.php?id=<?=e($t['tournament_id'])?>">Edit</a> <form method="post" class="dashboard-delete-form" data-confirm="Delete this tournament and all its categories, registrations, brackets, schedules, and results? This cannot be undone."><input type="hidden" name="action" value="delete_tournament"><input type="hidden" name="tournament_id" value="<?=e($t['tournament_id'])?>"><button type="submit" class="btn btn-danger btn-small">Delete</button></form></td></tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
        <footer class="footer">COURTCTRL <span>•</span> Pickleball Tournament Organizer</footer>
    </main>
</div>

<div class="modal-backdrop" id="modalBackdrop" aria-hidden="true">
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" tabindex="-1">
        <div class="modal-header"><div><p class="eyebrow">TOURNAMENT SETUP</p><h2 id="modalTitle">Create tournament</h2><p>Start with the event details and competition categories.</p></div><button class="icon-btn" id="closeModal" type="button" aria-label="Close create tournament dialog"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-close"></use></svg></button></div>
        <form method="post" id="tournamentForm">
            <input type="hidden" name="action" value="create_tournament">
            <div class="form-section"><h3>Basic information</h3><div class="form-grid">
                <label class="field full">Tournament name <span>*</span><input type="text" name="tournament_name" placeholder="e.g. Tupi Open Pickleball Championship" required maxlength="150"></label>
                <label class="field">Start date<input type="date" name="start_date" id="startDate"></label>
                <label class="field">Number of days <span>*</span><input type="number" name="number_of_days" id="numberOfDays" min="1" max="30" value="1" required></label>
                <label class="field">Number of courts <span>*</span><input type="number" name="number_of_courts" min="1" max="100" value="4" required></label>
                <div class="field date-preview"><span>Estimated end date</span><strong id="endDatePreview">Select start date</strong></div>
            </div></div>
            <div class="form-section"><div class="section-title-row"><div><h3>Competition categories</h3><p>Add the divisions included in this tournament.</p></div><button type="button" class="btn btn-outline btn-small" id="addCategory"><svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-plus"></use></svg>Add category</button></div>
                <div id="categoryList"></div>
            </div>
            <div class="modal-footer"><span class="required-note">* Required fields</span><button type="button" class="btn btn-outline" id="cancelModal">Cancel</button><button type="submit" class="btn btn-primary">Create tournament <svg class="cc-icon" aria-hidden="true"><use href="#cc-icon-arrow-right"></use></svg></button></div>
        </form>
    </section>
</div>
<script src="assets/js/app.js"></script>

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


</body>
</html>
