<?php
session_start();
require_once __DIR__ . '/config/database.php';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$dataFile = __DIR__ . '/deped_tournament_entries.json';
if (!is_file($dataFile)) { http_response_code(500); exit('DEPED data file is missing.'); }
$categories = json_decode(file_get_contents($dataFile), true);
if (!is_array($categories) || !$categories) { http_response_code(500); exit('DEPED data file is invalid.'); }

$message = '';
$error = '';
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm_import'] ?? '') === 'YES') {
    try {
        // Prevent accidental duplicate tournament creation.
        $exists = $pdo->prepare("SELECT tournament_id FROM tournaments WHERE tournament_name=? LIMIT 1");
        $exists->execute(['DEPED TOURNAMENT']);
        if ($exists->fetchColumn()) {
            throw new RuntimeException('A tournament named DEPED TOURNAMENT already exists. Import stopped to prevent duplicate entries. Delete the previous test tournament from Tournament Setup only if you intentionally want to start over.');
        }

        $pdo->beginTransaction();
        $insertTournament = $pdo->prepare("INSERT INTO tournaments
            (tournament_name,number_of_days,start_date,end_date,number_of_courts,status)
            VALUES(?,1,'2026-11-22','2026-11-22',4,'Registration')");
        $insertTournament->execute(['DEPED TOURNAMENT']);
        $tournamentId = (int)$pdo->lastInsertId();
        $_SESSION['active_tournament_id'] = $tournamentId;

        $court = $pdo->prepare("INSERT INTO courts(tournament_id,court_name,is_active) VALUES(?,?,1)");
        for ($i=1; $i<=4; $i++) $court->execute([$tournamentId,'Court '.$i]);

        $findPlayer = $pdo->prepare("SELECT player_id FROM players WHERE first_name=? AND last_name=? AND ((gender IS NULL AND ? IS NULL) OR gender=?) LIMIT 1");
        $insertPlayer = $pdo->prepare("INSERT INTO players(first_name,last_name,gender) VALUES(?,?,?)");
        $insertTeam = $pdo->prepare("INSERT INTO teams(team_name) VALUES(?)");
        $insertMember = $pdo->prepare("INSERT INTO team_members(team_id,player_id) VALUES(?,?)");
        $insertEntry = $pdo->prepare("INSERT INTO tournament_entries(category_id,entry_type,team_id,entry_name,registration_status) VALUES(?,'Team',?,?, 'Registered')");
        $insertBracket = $pdo->prepare("INSERT INTO brackets(category_id,bracket_name,bracket_order) VALUES(?,?,?)");
        $insertMatch = $pdo->prepare("INSERT INTO matches(category_id,bracket_id,round_name,match_number,entry1_id,entry2_id,match_status) VALUES(?,?,'Round Robin',?,?,?,'Pending')");

        $categoryCount=0; $teamCount=0; $playerCount=0; $bracketCount=0; $matchCount=0;
        foreach ($categories as $categoryName => $categoryData) {
            $categoryType = $categoryData['type'] ?? 'Doubles';
            $cat = $pdo->prepare("INSERT INTO categories(tournament_id,category_name,category_type,game_format) VALUES(?,?,?,'Round Robin + Elimination')");
            $cat->execute([$tournamentId,$categoryName,$categoryType]);
            $categoryId=(int)$pdo->lastInsertId();
            $categoryCount++;

            $groupOrder=0; $matchNo=0;
            foreach (($categoryData['groups'] ?? []) as $groupLetter => $teams) {
                $groupOrder++;
                $insertBracket->execute([$categoryId,'Bracket '.$groupLetter,$groupOrder]);
                $bracketId=(int)$pdo->lastInsertId();
                $bracketCount++;
                $entryIds=[];

                foreach ($teams as $teamName) {
                    $parts=explode('/', $teamName, 2);
                    if (count($parts)!==2) throw new RuntimeException('Invalid doubles entry: '.$teamName);
                    $teamIdNames=[];
                    foreach ($parts as $fullName) {
                        $fullName=trim(preg_replace('/\s+/', ' ', $fullName));
                        $nameParts=explode(' ', $fullName);
                        $lastName=array_pop($nameParts);
                        $firstName=trim(implode(' ', $nameParts));
                        if ($firstName==='') { $firstName=$lastName; $lastName=''; }

                        $gender=null;
                        if (stripos($categoryName, "Men's")!==false) $gender='Male';
                        elseif (stripos($categoryName, "Women's")!==false) $gender='Female';

                        $findPlayer->execute([$firstName,$lastName,$gender,$gender]);
                        $playerId=(int)($findPlayer->fetchColumn() ?: 0);
                        if (!$playerId) {
                            $insertPlayer->execute([$firstName,$lastName,$gender]);
                            $playerId=(int)$pdo->lastInsertId();
                        }
                        $teamIdNames[]=$playerId;
                        $playerCount++;
                    }

                    $insertTeam->execute([$teamName]);
                    $teamId=(int)$pdo->lastInsertId();
                    $insertMember->execute([$teamId,$teamIdNames[0]]);
                    $insertMember->execute([$teamId,$teamIdNames[1]]);
                    $insertEntry->execute([$categoryId,$teamId,$teamName]);
                    $entryIds[]=(int)$pdo->lastInsertId();
                    $teamCount++;
                }

                // Round Robin fixtures: every pair in this exact supplied group plays once.
                $n=count($entryIds);
                for ($i=0; $i<$n; $i++) {
                    for ($j=$i+1; $j<$n; $j++) {
                        $matchNo++;
                        $insertMatch->execute([$categoryId,$bracketId,$matchNo,$entryIds[$i],$entryIds[$j]]);
                        $matchCount++;
                    }
                }
            }
        }

        $pdo->commit();
        $summary=['tournament_id'=>$tournamentId,'categories'=>$categoryCount,'groups'=>$bracketCount,
                  'teams'=>$teamCount,'players_created_or_reused'=>$playerCount,'round_robin_matches'=>$matchCount];
        $message='DEPED TOURNAMENT imported successfully.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error=$e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Import DEPED Tournament · COURTCTRL</title>
<style>
body{font-family:Arial,sans-serif;background:#f3f6fa;color:#172033;margin:0;padding:28px}
main{max-width:820px;margin:auto;background:#fff;border:1px solid #dce4ef;border-radius:16px;padding:26px;box-shadow:0 8px 30px #10294b12}
h1{margin:0 0 8px;color:#17345d}p{color:#5b6779;line-height:1.5}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:20px 0}
.tile{background:#f5f8fc;border:1px solid #e1e8f0;border-radius:10px;padding:14px}.tile b{display:block;font-size:24px;color:#17345d}
button{background:#176b45;color:white;border:0;border-radius:9px;padding:13px 18px;font-weight:bold;cursor:pointer} .warning{background:#fff7e8;border:1px solid #f2d49c;padding:14px;border-radius:10px;margin:18px 0}
.ok{background:#eaf8ef;border:1px solid #a9dfba;padding:14px;border-radius:10px}.err{background:#fff0f0;border:1px solid #efb2b2;padding:14px;border-radius:10px}
label{display:flex;gap:10px;align-items:flex-start;margin:15px 0}
</style></head><body><main>
<h1>DEPED TOURNAMENT · Data Import</h1>
<p>One-time import into the connected COURTCTRL database. This creates the tournament, eight doubles categories, supplied bracket groups, registered teams, players, and Round Robin fixtures.</p>
<div class="grid">
<div class="tile"><b>8</b>Categories</div><div class="tile"><b>24</b>Groups</div><div class="tile"><b>110</b>Doubles teams</div><div class="tile"><b>220</b>Player slots</div>
</div>
<div class="warning"><strong>Important:</strong> Import creates real database records. It does not generate elimination rounds or schedules yet. It uses 4 courts as a starting value. The date is November 22, 2026.</div>
<?php if($message):?><div class="ok"><strong><?=h($message)?></strong><pre><?=h(json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre><p><a href="workspace.php?tournament_id=<?=(int)$summary['tournament_id']?>">Open DEPED Tournament Workspace</a></p><p><strong>Security:</strong> Delete or rename <code>import_deped_tournament.php</code> and <code>deped_tournament_entries.json</code> after successful import.</p></div>
<?php elseif($error):?><div class="err"><strong>Import stopped:</strong> <?=h($error)?></div><?php endif;?>
<form method="post" onsubmit="return confirm('Import DEPED TOURNAMENT and create all categories, teams, players, brackets and Round Robin matches? This writes real records to your database.');">
<label><input type="checkbox" required> I have backed up my database and understand this creates real tournament records.</label>
<input type="hidden" name="confirm_import" value="YES"><button type="submit">Import DEPED Tournament</button>
</form>
</main></body></html>
