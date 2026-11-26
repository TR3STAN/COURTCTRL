<?php
session_start();
require_once __DIR__ . '/config/database.php';

/**
 * Escape values before writing them into HTML.
 */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Format a stored SQL DATE as a game date without timezone shifting. */
function gameDateLabel($date)
{
    if (!$date) return 'Game date not set';

    $rawDate = trim((string) $date);
    $datePart = substr($rawDate, 0, 10);

    // Prefer strict ISO parsing, then fall back to PHP date parsing.
    $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $datePart);
    if (!$dateObject) {
        try {
            $dateObject = new DateTimeImmutable($rawDate);
        } catch (Throwable $exception) {
            return htmlspecialchars($rawDate, ENT_QUOTES, 'UTF-8');
        }
    }

    return $dateObject->format('l, F j, Y');
}

/** Convert bracket order to spreadsheet-style labels: A, B, ..., Z, AA. */
function bracketLetter($order)
{
    $order = max(1, (int) $order);
    $label = '';
    while ($order > 0) {
        $order--;
        $label = chr(65 + ($order % 26)) . $label;
        $order = intdiv($order, 26);
    }
    return $label;
}

/**
 * Load tournament and category choices.
 */
$errors = [];
$success = '';

$tournaments = $pdo->query(
    "SELECT tournament_id, tournament_name, number_of_days, start_date
     FROM tournaments
     ORDER BY start_date DESC, tournament_id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$requestedTournamentId = (int)($_GET['tournament_id'] ?? $_POST['tournament_id'] ?? 0);
$tournamentId = $requestedTournamentId > 0
    ? $requestedTournamentId
    : (int)($_SESSION['active_tournament_id'] ?? 0);
$validTournamentIds = array_map('intval', array_column($tournaments, 'tournament_id'));
if ($tournamentId <= 0 || !in_array($tournamentId, $validTournamentIds, true)) {
    $tournamentId = (int)($tournaments[0]['tournament_id'] ?? 0);
}
if ($tournamentId) { $_SESSION['active_tournament_id'] = $tournamentId; }

$tournament = null;
foreach ($tournaments as $item) {
    if ((int) $item['tournament_id'] === $tournamentId) {
        $tournament = $item;
        break;
    }
}

$activeTournamentName = $tournament['tournament_name'] ?? '';
$categories = [];
if ($tournamentId > 0) {
    $statement = $pdo->prepare(
        "SELECT category_id, category_name, game_format
         FROM categories
         WHERE tournament_id = ?
         ORDER BY category_name"
    );
    $statement->execute([$tournamentId]);
    $categories = $statement->fetchAll(PDO::FETCH_ASSOC);
}

$categoryId = (int) (
    $_GET['category_id']
    ?? $_POST['category_id']
    ?? 0
);

$category = null;
foreach ($categories as $item) {
    if ((int) $item['category_id'] === $categoryId) {
        $category = $item;
        break;
    }
}

$activeCourts = [];
if ($tournamentId > 0) {
    $statement = $pdo->prepare(
        "SELECT court_id, court_name
         FROM courts
         WHERE tournament_id = ? AND is_active = 1
         ORDER BY court_name"
    );
    $statement->execute([$tournamentId]);
    $activeCourts = $statement->fetchAll(PDO::FETCH_ASSOC);
}

$numberOfDays = max(1, (int) ($tournament['number_of_days'] ?? 1));
$playDay = (int) ($_POST['play_day'] ?? $_GET['play_day'] ?? 1);
$playDay = max(1, min($numberOfDays, $playDay));

// The organizer chooses the actual game date on the main scheduling form.
$gameDate = (string) ($_POST['game_date'] ?? $_GET['game_date'] ?? ($tournament['start_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gameDate)) {
    $gameDate = $tournament['start_date'] ?? date('Y-m-d');
}


$courtCount = (int) (
    $_POST['court_count']
    ?? $_GET['court_count']
    ?? count($activeCourts)
);
$courtCount = max(1, min(max(1, count($activeCourts)), $courtCount));

// Load bracket choices for this category, including only brackets with matches.
$bracketOptions = [];
if ($categoryId > 0) {
    $statement = $pdo->prepare(
        "SELECT DISTINCT m.bracket_id, b.bracket_name, b.bracket_order
         FROM matches AS m
         LEFT JOIN brackets AS b ON b.bracket_id = m.bracket_id
         WHERE m.category_id = ?
         ORDER BY COALESCE(b.bracket_order, m.bracket_id), m.bracket_id"
    );
    $statement->execute([$categoryId]);
    $bracketOptions = $statement->fetchAll(PDO::FETCH_ASSOC);
}

// Each bracket may be assigned to one or multiple courts.
$bracketCourtAssignments = [];
foreach ($bracketOptions as $index => $bracketOption) {
    $bracketId = (int) ($bracketOption['bracket_id'] ?? 0);
    $postedCourts = $_POST['bracket_courts'][$bracketId] ?? null;
    $bracketCourtAssignments[$bracketId] = (is_array($postedCourts) && $postedCourts)
        ? array_values(array_unique(array_map('intval', $postedCourts)))
        : [(int) ($activeCourts[$index % max(1, count($activeCourts))]['court_id'] ?? 0)];
}



/**
 * Rebuild schedule_order as court-time waves. Matches sharing a player are
 * never placed in the same wave, even when they belong to different brackets.
 * A court can also host only one match in each wave.
 */
function rebuildCategoryScheduleWaves(PDO $pdo, int $categoryId): void {
    $tournamentStmt = $pdo->prepare("SELECT tournament_id FROM categories WHERE category_id = ?");
    $tournamentStmt->execute([$categoryId]);
    $tournamentIdForWaves = (int)$tournamentStmt->fetchColumn();
    if ($tournamentIdForWaves < 1) return;

    $sql = "SELECT ms.schedule_id, ms.tournament_day, ms.court_id, ms.schedule_order,
                   m.entry1_id, m.entry2_id
            FROM match_schedules ms
            INNER JOIN matches m ON m.match_id = ms.match_id
            INNER JOIN categories cat ON cat.category_id = m.category_id
            WHERE cat.tournament_id = ?
            ORDER BY ms.tournament_day, ms.schedule_order, ms.schedule_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tournamentIdForWaves]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return;

    $entryIds = [];
    foreach ($rows as $row) {
        foreach (['entry1_id', 'entry2_id'] as $key) {
            if (!empty($row[$key])) $entryIds[(int)$row[$key]] = true;
        }
    }

    $participantsByEntry = [];
    if ($entryIds) {
        $ids = array_keys($entryIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $entryStmt = $pdo->prepare(
            "SELECT te.entry_id, te.entry_type, te.player_id, te.team_id, tm.player_id AS member_player_id
             FROM tournament_entries te
             LEFT JOIN team_members tm ON tm.team_id = te.team_id
             WHERE te.entry_id IN ($placeholders)"
        );
        $entryStmt->execute($ids);
        foreach ($entryStmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
            $entryId = (int)$entry['entry_id'];
            if (!isset($participantsByEntry[$entryId])) $participantsByEntry[$entryId] = [];
            $playerId = ($entry['entry_type'] ?? '') === 'Team'
                ? (int)($entry['member_player_id'] ?? 0)
                : (int)($entry['player_id'] ?? 0);
            if ($playerId > 0) $participantsByEntry[$entryId][$playerId] = true;
        }
    }

    $byDay = [];
    foreach ($rows as $row) $byDay[(string)$row['tournament_day']][] = $row;
    $update = $pdo->prepare("UPDATE match_schedules SET schedule_order = ? WHERE schedule_id = ?");

    foreach ($byDay as $dayRows) {
        $waves = [];
        foreach ($dayRows as $row) {
            $players = [];
            foreach (['entry1_id', 'entry2_id'] as $key) {
                $entryId = (int)($row[$key] ?? 0);
                foreach (array_keys($participantsByEntry[$entryId] ?? []) as $playerId) {
                    $players[(int)$playerId] = true;
                }
            }
            $courtId = (int)$row['court_id'];
            $waveIndex = 0;
            while (true) {
                $wave = $waves[$waveIndex] ?? ['players' => [], 'courts' => []];
                $playerConflict = (bool)array_intersect_key($players, $wave['players']);
                $courtConflict = isset($wave['courts'][$courtId]);
                if (!$playerConflict && !$courtConflict) break;
                $waveIndex++;
            }
            if (!isset($waves[$waveIndex])) $waves[$waveIndex] = ['players' => [], 'courts' => []];
            $waves[$waveIndex]['players'] += $players;
            $waves[$waveIndex]['courts'][$courtId] = true;
            $update->execute([$waveIndex + 1, (int)$row['schedule_id']]);
        }
    }
}

/**
 * Schedule all unscheduled matches for the selected category.
 * Matches are ordered bracket-by-bracket, then round-by-round.
 * Time columns are retained in the database for compatibility but are not
 * used or displayed by this module.
 */
// Reshuffle matchups for a bracket and optionally change its court.
// This action preserves the bracket's existing game date; dates are set on
// the main scheduling form. Refuse to reshuffle once scores exist.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_bracket_courts') {
    try {
        $bracketId = (int) ($_POST['bracket_id'] ?? 0);
        $selectedCourtIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['bracket_courts'] ?? [])))));
        $validCourtIds = array_map(static fn($court) => (int)$court['court_id'], $activeCourts);
        if (!$category || !$bracketId || !$selectedCourtIds) throw new RuntimeException('Select at least one active court for this bracket.');
        foreach ($selectedCourtIds as $courtId) if (!in_array($courtId, $validCourtIds, true)) throw new RuntimeException('One or more selected courts are not active.');
        $q = $pdo->prepare("SELECT m.match_id FROM matches m WHERE m.category_id=? AND m.bracket_id=? ORDER BY CASE WHEN LOWER(m.round_name) LIKE '%round robin%' THEN 1 ELSE 2 END,m.match_number,m.match_id");
        $q->execute([$categoryId,$bracketId]); $matchIds=$q->fetchAll(PDO::FETCH_COLUMN);
        if (!$matchIds) throw new RuntimeException('The selected bracket has no matches.');
        $pdo->beginTransaction();
        $find=$pdo->prepare("SELECT schedule_id FROM match_schedules WHERE match_id=?");
        $update=$pdo->prepare("UPDATE match_schedules SET court_id=?,is_manual_override=1 WHERE match_id=?");
        $insert=$pdo->prepare("INSERT INTO match_schedules(match_id,tournament_day,scheduled_start,scheduled_end,court_id,schedule_order,is_manual_override) VALUES(?,?,'00:00:00','00:00:00',?,?,1)");
        $markScheduled=$pdo->prepare("UPDATE matches SET match_status='Scheduled' WHERE match_id=? AND match_status IN ('Pending','Ready')");
        $day=(string)($tournament['start_date']??date('Y-m-d'));
        foreach($matchIds as $i=>$matchId){
            $courtId=$selectedCourtIds[$i % count($selectedCourtIds)];
            $find->execute([(int)$matchId]);
            if($find->fetchColumn()) $update->execute([$courtId,(int)$matchId]);
            else $insert->execute([(int)$matchId,$day,$courtId,$i+1]);
            $markScheduled->execute([(int)$matchId]);
        }
        rebuildCategoryScheduleWaves($pdo, $categoryId);
        $pdo->commit();
        $success=count($matchIds).' match(es) in this bracket distributed across '.count($selectedCourtIds).' selected court(s). Matchups and scores were preserved.';
    } catch(Throwable $exception) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $errors[]=$exception->getMessage();
    }
}

// Remove schedule assignments only; keep generated matches and scores intact.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_bracket_schedule') {
    try {
        $bracketId = (int) ($_POST['bracket_id'] ?? 0);
        $check = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE category_id = ? AND bracket_id = ?');
        $check->execute([$categoryId, $bracketId]);
        if ((int) $check->fetchColumn() === 0) throw new RuntimeException('The selected bracket was not found.');
        $pdo->beginTransaction();
        $delete = $pdo->prepare("DELETE ms FROM match_schedules ms JOIN matches m ON m.match_id = ms.match_id WHERE m.category_id = ? AND m.bracket_id = ? AND m.match_status IN ('Pending', 'Ready', 'Scheduled')");
        $delete->execute([$categoryId, $bracketId]);
        $reset = $pdo->prepare("UPDATE matches SET match_status = 'Pending' WHERE category_id = ? AND bracket_id = ? AND match_status IN ('Ready', 'Scheduled')");
        $reset->execute([$categoryId, $bracketId]);
        $pdo->commit();
        $success = 'Bracket schedule assignments removed. The generated matches remain available to schedule again.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $exception->getMessage();
    }
}

// Apply the selected game date to all existing schedule rows in this category.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_schedule_date') {
    try {
        $postedDate = (string) ($_POST['game_date'] ?? '');
        $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $postedDate);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $postedDate) {
            throw new RuntimeException('Choose a valid game date.');
        }

        $updateDate = $pdo->prepare(
            "UPDATE match_schedules ms
             INNER JOIN matches m ON m.match_id = ms.match_id
             SET ms.tournament_day = ?
             WHERE m.category_id = ?"
        );
        $updateDate->execute([$postedDate, $categoryId]);
        $success = 'Game date updated for ' . $updateDate->rowCount() . ' scheduled match(es).';
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

// Delete all schedule assignments for the selected category, preserving matches.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_all_schedule') {
    try {
        $pdo->beginTransaction();
        $delete = $pdo->prepare(
            "DELETE ms FROM match_schedules ms
             INNER JOIN matches m ON m.match_id = ms.match_id
             WHERE m.category_id = ? AND m.match_status IN ('Pending','Ready','Scheduled')"
        );
        $delete->execute([$categoryId]);

        $reset = $pdo->prepare(
            "UPDATE matches SET match_status = 'Pending'
             WHERE category_id = ? AND match_status IN ('Ready','Scheduled')"
        );
        $reset->execute([$categoryId]);
        $pdo->commit();
        $success = 'All schedule assignments for this category were removed. Generated matchups remain.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $exception->getMessage();
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'auto_schedule'
) {
    if (!$tournament || !$category) {
        $errors[] = 'Please select a valid tournament and category.';
    } elseif (count($activeCourts) === 0) {
        $errors[] = 'No active courts are configured for this tournament.';
    } elseif ($courtCount < 1 || $courtCount > count($activeCourts)) {
        $errors[] = 'Choose a valid number of active courts.';
    } elseif (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $gameDate)) {
        $errors[] = 'Please choose a valid game date.';
    } else {
        try {
            $pdo->beginTransaction();

            // Sort by bracket first, then round and match number.
            $statement = $pdo->prepare(
                "SELECT m.match_id, m.bracket_id, m.round_name, m.match_number, m.entry1_id, m.entry2_id
                 FROM matches AS m
                 LEFT JOIN match_schedules AS ms ON ms.match_id = m.match_id
                 WHERE m.category_id = ?
                   AND m.match_status IN ('Pending', 'Ready', 'Scheduled')
                   AND ms.schedule_id IS NULL
                 ORDER BY
                   COALESCE(m.bracket_id, 0),
                   CASE
                     WHEN LOWER(m.round_name) LIKE '%round robin%' THEN 1
                     WHEN LOWER(m.round_name) LIKE '%quarter%' THEN 2
                     WHEN LOWER(m.round_name) LIKE '%semi%' THEN 3
                     WHEN LOWER(m.round_name) LIKE '%final%' THEN 4
                     ELSE 5
                   END,
                   m.match_number,
                   m.match_id"
            );
            $statement->execute([$categoryId]);
            $matches = $statement->fetchAll(PDO::FETCH_ASSOC);

            // Arrange matches bracket-by-bracket and use a rest-aware
            // greedy order within each bracket. Avoid a team appearing in
            // more than two consecutive scheduled matches where possible.
            $matchesByBracket = [];
            foreach ($matches as $match) {
                $matchesByBracket[(int) ($match['bracket_id'] ?? 0)][] = $match;
            }

            $orderedMatches = [];
            foreach ($matchesByBracket as $bracketMatches) {
                $remaining = array_values($bracketMatches);
                $recentParticipants = []; // recent match participant pairs
                while ($remaining) {
                    $chosenIndex = null;

                    foreach ($remaining as $candidateIndex => $candidate) {
                        $participants = array_filter([
                            (string) ($candidate['entry1_id'] ?? ''),
                            (string) ($candidate['entry2_id'] ?? '')
                        ], static fn($id) => $id !== '');

                        $wouldCauseConsecutiveMatch = false;
                        if ($recentParticipants) {
                            $previousMatchParticipants = $recentParticipants[count($recentParticipants) - 1];
                            foreach ($participants as $teamId) {
                                if (in_array($teamId, $previousMatchParticipants, true)) {
                                    $wouldCauseConsecutiveMatch = true;
                                    break;
                                }
                            }
                        }

                        $wouldBeThirdConsecutive = false;
                        if (count($recentParticipants) >= 2) {
                            foreach ($participants as $teamId) {
                                $appearedLastTwo = true;
                                foreach (array_slice($recentParticipants, -2) as $recentMatch) {
                                    if (!in_array($teamId, $recentMatch, true)) {
                                        $appearedLastTwo = false;
                                        break;
                                    }
                                }
                                if ($appearedLastTwo) {
                                    $wouldBeThirdConsecutive = true;
                                    break;
                                }
                            }
                        }

                        if (!$wouldCauseConsecutiveMatch && !$wouldBeThirdConsecutive) {
                            $chosenIndex = $candidateIndex;
                            break;
                        }
                    }

                    // A valid alternative may not exist in a small bracket.
                    if ($chosenIndex === null) {
                        $chosenIndex = 0;
                    }

                    $chosen = $remaining[$chosenIndex];
                    array_splice($remaining, $chosenIndex, 1);
                    $participants = array_values(array_filter([
                        (string) ($chosen['entry1_id'] ?? ''),
                        (string) ($chosen['entry2_id'] ?? '')
                    ], static fn($id) => $id !== ''));

                    $recentParticipants[] = $participants;
                    if (count($recentParticipants) > 2) {
                        array_shift($recentParticipants);
                    }
                    $orderedMatches[] = $chosen;
                }
            }
            $matches = $orderedMatches;

            // Validate one-or-more active courts for each bracket.
            $activeCourtIds = array_map(static fn($court) => (int)$court['court_id'], $activeCourts);
            foreach ($bracketOptions as $bracketOption) {
                $bracketId = (int)($bracketOption['bracket_id'] ?? 0);
                $assignedCourts = $bracketCourtAssignments[$bracketId] ?? [];
                if (!$assignedCourts) throw new RuntimeException('Select at least one court for every bracket.');
                foreach ($assignedCourts as $assignedCourt) if (!in_array((int)$assignedCourt,$activeCourtIds,true)) throw new RuntimeException('One or more selected courts are not active.');
            }
            $courtByBracket = $bracketCourtAssignments;
            $bracketCourtCounters = [];
            $dayDate = $gameDate;

            // One schedule row per match. Courts rotate in order.
            // No time slots are assigned or shown.
            $insertSchedule = $pdo->prepare(
                "INSERT INTO match_schedules
                    (match_id, tournament_day, scheduled_start, scheduled_end,
                     court_id, schedule_order, is_manual_override)
                 VALUES (?, ?, '00:00:00', '00:00:00', ?, ?, 0)"
            );

            $updateMatch = $pdo->prepare(
                "UPDATE matches SET match_status = 'Scheduled' WHERE match_id = ?"
            );

            foreach ($matches as $index => $match) {
                $bracketId = (int) ($match['bracket_id'] ?? 0);
                $availableBracketCourts = $courtByBracket[$bracketId] ?? [];
                if (!$availableBracketCourts) throw new RuntimeException('No court selected for a bracket.');
                $courtCounter = $bracketCourtCounters[$bracketId] ?? 0;
                $assignedCourtId = (int)$availableBracketCourts[$courtCounter % count($availableBracketCourts)];
                $bracketCourtCounters[$bracketId] = $courtCounter + 1;

                $insertSchedule->execute([
                    $match['match_id'],
                    $dayDate,
                    $assignedCourtId,
                    $index + 1
                ]);

                $updateMatch->execute([$match['match_id']]);
            }

            rebuildCategoryScheduleWaves($pdo, $categoryId);
            $pdo->commit();

            $success = count($matches)
                . " match(es) scheduled for " . gameDateLabel($gameDate) . " with multi-court bracket assignments and player-conflict protection. "
                . 'Matches are grouped by bracket; existing schedules were preserved.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception->getMessage();
        }
    }
}

/**
 * Retrieve schedule rows for the selected tournament/category.
 */
$schedules = [];
if ($tournamentId > 0) {
    $sql = "
        SELECT
            ms.schedule_id,
            ms.tournament_day,
            ms.schedule_order,
            ms.scheduled_start,
            ms.scheduled_end,
            ms.court_id,
            c.court_name,
            cat.category_name,
            m.match_id,
            m.bracket_id,
            b.bracket_name,
            b.bracket_order,
            m.round_name,
            m.match_number,
            m.match_status,
            m.entry1_id,
            m.entry2_id,
            e1.entry_name AS entry1_name,
            e2.entry_name AS entry2_name
        FROM match_schedules AS ms
        INNER JOIN matches AS m ON m.match_id = ms.match_id
        INNER JOIN categories AS cat ON cat.category_id = m.category_id
        LEFT JOIN brackets AS b ON b.bracket_id = m.bracket_id
        INNER JOIN courts AS c ON c.court_id = ms.court_id
        LEFT JOIN tournament_entries AS e1 ON e1.entry_id = m.entry1_id
        LEFT JOIN tournament_entries AS e2 ON e2.entry_id = m.entry2_id
        WHERE cat.tournament_id = ?
    ";

    $parameters = [$tournamentId];
    if ($categoryId > 0) {
        $sql .= ' AND m.category_id = ?';
        $parameters[] = $categoryId;
    }

    $sql .= "
        ORDER BY
            ms.tournament_day,
            COALESCE(m.bracket_id, 0),
            ms.scheduled_start,
            ms.schedule_order,
            c.court_name
    ";

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $schedules = $statement->fetchAll(PDO::FETCH_ASSOC);
}

$bracketLetters = [];
foreach ($bracketOptions as $index => $option) {
    $bracketLetters[(int) ($option['bracket_id'] ?? 0)] = bracketLetter($option['bracket_order'] ?? ($index + 1));
}

$bracketColors = ['#2563EB','#EA580C','#16A34A','#9333EA','#E11D48','#0891B2','#CA8A04','#4F46E5','#0F766E','#C2410C','#7C3AED','#BE185D','#0369A1','#65A30D','#A21CAF','#B45309','#0D9488','#DB2777','#475569','#4D7C0F'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schedule &amp; Courts | COURTCTRL</title>
    <link rel="stylesheet" href="assets/css/style.css">
    
</head>
<body class="page-schedule">
<div class="app-shell">
    <aside class="sidebar"><div class="brand"><div class="brand-mark">P</div><div><strong>COURT<span>CTRL</span></strong><small>TOURNAMENT ORGANIZER</small></div></div><div class="nav-label">WORKSPACE</div><a class="nav-item" href="index.php"><span>▦</span> Dashboard</a><a class="nav-item" href="workspace.php?tournament_id=<?= (int)$tournamentId ?>"><span>♜</span> Tournament Workspace</a><a class="nav-item" href="tournaments.php?id=<?= (int)$tournamentId ?>"><span>⚙</span> Tournament Setup</a><a class="nav-item" href="players.php?tournament_id=<?= (int)$tournamentId ?>"><span>♙</span> Players &amp; Teams</a><a class="nav-item" href="brackets.php?tournament_id=<?= (int)$tournamentId ?>"><span>⌘</span> Brackets</a><a class="nav-item active" href="schedule.php?tournament_id=<?= (int)$tournamentId ?>"><span>▦</span> Schedule &amp; Courts</a><a class="nav-item" href="live_feed.php?tournament_id=<?= (int)$tournamentId ?>"><span>◉</span> Live Feed</a><a class="nav-item" href="scoring.php?tournament_id=<?= (int)$tournamentId ?>"><span>☷</span> Scoring &amp; Standings</a><div class="sidebar-bottom"><div class="help-dot">?</div><div><strong>Organizer workspace</strong><small>COURTCTRL tournament control</small></div></div></aside>

    <main class="main-content">
        <header class="topbar">
            <div class="crumb">Workspace <span>/</span> Schedule &amp; Courts</div>
            <div class="profile">
                <div class="avatar">A</div>
                <div><strong>Administrator</strong><small>Tournament organizer</small></div>
            </div>
        </header><div class="active-tournament-strip"><span>ACTIVE TOURNAMENT</span><strong><?=e($tournament['tournament_name'] ?? 'No tournament selected')?></strong><a href="workspace.php?tournament_id=<?= (int)$tournamentId ?>">Switch</a></div>

        <section class="page-heading">
            <div>
                <p class="eyebrow">COURT ALLOCATION</p>
                <h1>Schedule &amp; Courts</h1>
                <p class="subheading">Assign matches to a tournament day and selected courts. · </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-outline" href="workspace.php?tournament_id=<?= (int)$tournamentId ?>">Tournament Workspace</a></div>
        </section>

        <?php if ($success): ?>
            <div class="notice"><?= e($success) ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <section class="content-card" style="margin-bottom:18px">
            <div class="card-head">
                <h2>Category selection</h2>
                <p>Choose a category in the active tournament. Switch tournaments from the Tournament Workspace.</p>
            </div>
            <div class="card-body">
                <form method="get" class="toolbar">
                    <input type="hidden" name="tournament_id" value="<?= e($tournamentId) ?>">
                    <div class="field">
                        <label for="category_id">Category</label>
                        <select name="category_id" id="category_id" onchange="this.form.submit()"><option value="" disabled <?= $categoryId === 0 ? 'selected' : '' ?>>Select a category...</option>
                            <?php foreach ($categories as $item): ?>
                                <option value="<?= e($item['category_id']) ?>" <?= $categoryId === (int) $item['category_id'] ? 'selected' : '' ?>>
                                    <?= e($item['category_name']) ?> · <?= e($item['game_format']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </section>

        <?php if (!$tournament): ?>
            <section class="content-card"><div class="empty-note">Create a tournament first.</div></section>
        <?php elseif (!$category): ?>
            <section class="content-card"><div class="empty-note">Add a category and generate its matches first.</div></section>
        <?php else: ?>
            <section class="content-card" style="margin-bottom:18px">
                <div class="card-head">
                    <h2>Assign matches</h2>
                    <p>Choose the game date and select one or more courts for each bracket. Matches are distributed across the selected courts.</p>
                </div>
                <div class="card-body">
                    <form method="post" class="schedule-form">
                        <input type="hidden" name="action" value="auto_schedule">
                        <input type="hidden" name="tournament_id" value="<?= e($tournamentId) ?>">
                        <input type="hidden" name="category_id" value="<?= e($categoryId) ?>">

                        <div class="field">
                            <label for="game_date">Game date</label>
                            <input type="date" id="game_date" name="game_date" value="<?= e($gameDate) ?>" required>
                        </div>

                        <?php foreach ($bracketOptions as $bracketOption): ?>
                            <?php $bracketId = (int) ($bracketOption['bracket_id'] ?? 0); ?>
                            <fieldset class="bracket-court-picker">
                                <legend><?= e($bracketOption['bracket_name'] ?: ('Bracket ' . $bracketId)) ?> — select courts</legend>
                                <div class="court-checkbox-grid">
                                    <?php foreach ($activeCourts as $court): ?>
                                        <label class="court-check">
                                            <input type="checkbox" name="bracket_courts[<?= $bracketId ?>][]" value="<?= e($court['court_id']) ?>"
                                                <?= in_array((int)$court['court_id'], $bracketCourtAssignments[$bracketId] ?? [], true) ? 'checked' : '' ?>>
                                            <span><?= e($court['court_name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>
                        <?php endforeach; ?>

                        <div class="field">
                            <button class="btn btn-primary" type="submit"
                                data-confirm="Assign all unscheduled matches using the selected bracket courts?">
                                Assign schedule
                            </button>
                        </div>
                    </form>
                    <p class="helper" style="margin-top:12px">
                        Active courts available: <?= count($activeCourts) ?> · Tournament days: <?= $numberOfDays ?>.
                        No match times are assigned or displayed. Each bracket can use multiple courts; its matches rotate across the selected courts.
                    </p>
                </div>
            </section>

            <section class="content-card">
                <div class="card-head">
                    <h2>Match schedule</h2>
                    <p><?= e($category['category_name']) ?> · Grouped by tournament day, bracket, and court.</p>
                    <span class="count-pill"><?= count($schedules) ?> scheduled match(es)</span>
                    <a class="btn btn-outline" href="schedule_export.php?tournament_id=<?= e($tournamentId) ?>&amp;category_id=<?= e($categoryId) ?>">Export Excel</a>
                    <form id="applyScheduleDateForm" method="post" style="display:inline" onsubmit="return applySelectedGameDate(this)">
                        <input type="hidden" name="action" value="update_schedule_date">
                        <input type="hidden" name="tournament_id" value="<?= e($tournamentId) ?>">
                        <input type="hidden" name="category_id" value="<?= e($categoryId) ?>">
                        <input type="hidden" name="game_date" value="<?= e($gameDate) ?>">
                        <!-- <button class="btn btn-primary btn-small" type="submit">Apply date to schedule</button> -->
                    </form>
                    <form method="post" style="display:inline" data-confirm="Delete ALL schedule assignments for this category? Generated matchups will remain.">
                        <input type="hidden" name="action" value="delete_all_schedule">
                        <input type="hidden" name="tournament_id" value="<?= e($tournamentId) ?>">
                        <input type="hidden" name="category_id" value="<?= e($categoryId) ?>">
                        <button class="btn btn-delete btn-small" type="submit">Delete all schedule</button>
                    </form>
                </div>

                <?php if (!$schedules): ?>
                    <div class="empty-note">No matches scheduled yet. Generate brackets first, then assign the schedule.</div>
                <?php else: ?>
                    <?php
                    $bracketsForDisplay = [];
                    foreach ($schedules as $row) {
                        $bracketKey = (int) ($row['bracket_id'] ?? 0);
                        $bracketsForDisplay[$bracketKey][] = $row;
                    }
                    ?>
                    <div class="bracket-cards">
                        <?php foreach ($bracketsForDisplay as $bracketId => $bracketRows): ?>
                            <?php $cardColor = $bracketColorMap[(int)$bracketId] ?? $bracketColors[0]; ?>
                            <article class="bracket-card" style="--bracket-color:<?= e($cardColor) ?>">
                                <div class="bracket-card-header">
                                    <div>
                                        <span class="bracket-kicker">MATCH GROUP</span>
                                        <h3><?= $bracketId ? 'Bracket ' . e($bracketLetters[$bracketId] ?? 'A') : 'Main bracket' ?></h3>
                                    </div>
                                    <span class="bracket-count"><?= count($bracketRows) ?> matches</span>
                                </div>
                                <div class="bracket-actions">
                                    <form method="post" class="bracket-reschedule-form">
                                        <input type="hidden" name="action" value="assign_bracket_courts">
                                        <input type="hidden" name="tournament_id" value="<?= e($tournamentId) ?>">
                                        <input type="hidden" name="category_id" value="<?= e($categoryId) ?>">
                                        <input type="hidden" name="bracket_id" value="<?= e($bracketId) ?>">
                                        <div class="bracket-court-picker compact">
                                            <strong>Assign this bracket to:</strong>
                                            <div class="court-checkbox-grid">
                                                <?php $currentlyAssigned = array_values(array_unique(array_map(static fn($scheduledRow)=>(int)$scheduledRow['court_id'], $bracketRows))); ?>
                                                <?php foreach ($activeCourts as $court): ?>
                                                    <label class="court-check">
                                                        <input type="checkbox" name="bracket_courts[]" value="<?= e($court['court_id']) ?>" <?= in_array((int)$court['court_id'],$currentlyAssigned,true)?'checked':'' ?>>
                                                        <span><?= e($court['court_name']) ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <button class="btn btn-reschedule btn-small" type="submit">Distribute across selected courts</button>
                                    </form>
                                    <form method="post" data-confirm="Remove this bracket’s schedule assignments? The matchups will not be deleted.">
                                        <input type="hidden" name="action" value="delete_bracket_schedule">
                                        <input type="hidden" name="tournament_id" value="<?= e($tournamentId) ?>">
                                        <input type="hidden" name="category_id" value="<?= e($categoryId) ?>">
                                        <input type="hidden" name="bracket_id" value="<?= e($bracketId) ?>">
                                        <button class="btn btn-delete btn-small" type="submit">Delete schedule</button>
                                    </form>
                                </div>
                                <?php
                                $rowsByDay = [];
                                foreach ($bracketRows as $row) {
                                    $rowsByDay[$row['tournament_day']][] = $row;
                                }
                                foreach ($rowsByDay as $dayDate => $dayRows):
                                ?>
                                    <h4 class="bracket-day" data-game-date="<?= e(substr((string) $dayDate, 0, 10)) ?>">
                                        <span class="bracket-date-label">Game date:</span>
                                        <?= e(gameDateLabel($gameDate)) ?>
                                    </h4>
                                    <div class="table-wrap">
                                        <table class="schedule-table">
                                            <thead>
                                            <tr>
                                                <th>TIME</th>
                                                <th>ORDER</th>
                                                <th>COURT</th>
                                                <th>MATCHUP</th>
                                                <th>ROUND</th>
                                                <th>STATUS</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($dayRows as $row): ?>
                                                <tr>
                                                    <td><?= e(($row['scheduled_start'] && $row['scheduled_start'] !== '00:00:00') ? date('g:i A', strtotime($row['scheduled_start'])) : '—') ?></td>
                                                    <td><?= e($row['schedule_order']) ?></td>
                                                    <td><?= e($row['court_name']) ?></td>
                                                    <td>
                                                        <strong><?= e($row['entry1_name'] ?? 'TBD / BYE') ?></strong>
                                                        <br>vs<br>
                                                        <strong><?= e($row['entry2_name'] ?? 'TBD / BYE') ?></strong>
                                                    </td>
                                                    <td><?= e($row['round_name']) ?></td>
                                                    <td><span class="status-pill"><?= e($row['match_status']) ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endforeach; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <footer class="footer">COURTCTRL <span>·</span> TOURNAMENT ORGANIZER</footer>
    </main>
</div>

<script>
function applySelectedGameDate(form) {
    const dateInput = document.getElementById('game_date');
    const hiddenDate = document.getElementById('apply_game_date');
    if (!dateInput || !dateInput.value) {
        alert('Please select a game date first.');
        return false;
    }
    hiddenDate.value = dateInput.value;
    return confirm('Apply ' + dateInput.value + ' to all currently scheduled matches in this category?');
}
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
</body>
</html>
