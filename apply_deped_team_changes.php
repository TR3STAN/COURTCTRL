<?php
session_start();
require_once __DIR__ . '/config/database.php';
function h($v){return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');}

$tournamentName = 'DEPED TOURNAMENT';
$changes = [
    [
        'category' => 'High Novice Coed Doubles',
        'bracket_order' => 2,
        'old_tokens' => ['Bonilla'],
        'new_players' => [['Erik John', 'Sales'], ['Francis Jay', 'Agtas']],
    ],
    [
        'category' => 'Low Novice Mixed Doubles',
        'bracket_order' => 2,
        'old_tokens' => ['Ulanday'],
        'new_players' => [['Christine', 'Souribio'], ['Justin Miko', 'Benedicto']],
    ],
];

function findTargetEntry(PDO $pdo, string $tournament, array $change): array {
    $sql = "SELECT DISTINCT te.entry_id, te.team_id, te.entry_name, te.registration_status,
                   t.team_name, c.category_name, b.bracket_name, b.bracket_order
            FROM tournament_entries te
            JOIN categories c ON c.category_id=te.category_id
            JOIN tournaments tr ON tr.tournament_id=c.tournament_id
            JOIN teams t ON t.team_id=te.team_id
            JOIN matches m ON (m.entry1_id=te.entry_id OR m.entry2_id=te.entry_id)
            JOIN brackets b ON b.bracket_id=m.bracket_id
            WHERE tr.tournament_name=? AND c.category_name=? AND b.bracket_order=?
              AND LOWER(m.round_name) LIKE '%round robin%'
              AND te.entry_type='Team'";
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$tournament,$change['category'],$change['bracket_order']]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $matches=[];
    foreach($rows as $row){
        $name=strtolower((string)$row['entry_name'].' '.(string)$row['team_name']);
        $ok=true;
        foreach($change['old_tokens'] as $token) if(strpos($name,strtolower($token))===false) $ok=false;
        if($ok) $matches[$row['entry_id']]=$row;
    }
    if(count($matches)!==1) {
        throw new RuntimeException(
            'Expected exactly one matching team in '.$change['category'].' Bracket B, but found '.count($matches).
            '. No changes were made for this team. Check the current team name/bracket.'
        );
    }
    return array_values($matches)[0];
}

function getOrCreatePlayer(PDO $pdo, string $first, string $last): int {
    $find=$pdo->prepare("SELECT player_id FROM players
                         WHERE LOWER(TRIM(first_name))=LOWER(?) AND LOWER(TRIM(last_name))=LOWER(?)
                         ORDER BY player_id LIMIT 1");
    $find->execute([$first,$last]);
    $id=$find->fetchColumn();
    if($id) return (int)$id;
    $insert=$pdo->prepare("INSERT INTO players(first_name,last_name,gender,contact_number) VALUES(?,?,NULL,NULL)");
    $insert->execute([$first,$last]);
    return (int)$pdo->lastInsertId();
}

$message=''; $error=''; $preview=[];
try {
    foreach($changes as $change){
        $row=findTargetEntry($pdo,$tournamentName,$change);
        $members=$pdo->prepare("SELECT p.first_name,p.last_name FROM team_members tm
                                JOIN players p ON p.player_id=tm.player_id WHERE tm.team_id=? ORDER BY p.player_id");
        $members->execute([(int)$row['team_id']]);
        $row['current_members']=$members->fetchAll(PDO::FETCH_ASSOC);
        $row['new_name']=$change['new_players'][0][0].' '.$change['new_players'][0][1].' / '.
                         $change['new_players'][1][0].' '.$change['new_players'][1][1];
        $preview[]=['row'=>$row,'change'=>$change];
    }
} catch(Throwable $e){$error=$e->getMessage();}

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['confirm_apply']) && !$error){
    try{
        $pdo->beginTransaction();
        foreach($changes as $change){
            $row=findTargetEntry($pdo,$tournamentName,$change);
            $playerIds=[];
            foreach($change['new_players'] as [$first,$last]){
                $playerIds[]=getOrCreatePlayer($pdo,$first,$last);
            }
            if($playerIds[0]===$playerIds[1]) throw new RuntimeException('The replacement pair must contain two different players.');
            // Prevent registering a replacement player twice in this category.
            $duplicate=$pdo->prepare("SELECT COUNT(*) FROM tournament_entries te
                JOIN team_members tm ON tm.team_id=te.team_id
                WHERE te.category_id=(SELECT category_id FROM categories WHERE category_name=? AND tournament_id=
                    (SELECT tournament_id FROM tournaments WHERE tournament_name=? LIMIT 1))
                  AND te.entry_id<>? AND te.registration_status='Registered'
                  AND tm.player_id IN (?,?)");
            $duplicate->execute([$change['category'],$tournamentName,(int)$row['entry_id'],$playerIds[0],$playerIds[1]]);
            if((int)$duplicate->fetchColumn()>0){
                throw new RuntimeException('One of the replacement players is already registered in '.$change['category'].'. No changes were committed.');
            }
            $delete=$pdo->prepare("DELETE FROM team_members WHERE team_id=?");
            $delete->execute([(int)$row['team_id']]);
            $add=$pdo->prepare("INSERT INTO team_members(team_id,player_id) VALUES(?,?)");
            foreach($playerIds as $pid) $add->execute([(int)$row['team_id'],$pid]);
            $newName=$change['new_players'][0][0].' '.$change['new_players'][0][1].' / '.
                     $change['new_players'][1][0].' '.$change['new_players'][1][1];
            $pdo->prepare("UPDATE tournament_entries SET entry_name=? WHERE entry_id=?")->execute([$newName,(int)$row['entry_id']]);
            $pdo->prepare("UPDATE teams SET team_name=? WHERE team_id=?")->execute([$newName,(int)$row['team_id']]);
        }
        $pdo->commit();
        $message='Both DEPED Tournament teams were successfully updated. Existing matchups, match IDs, scores, and schedules were preserved.';
        $preview=[];
    }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        $error=$e->getMessage();
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DEPED Team Update | COURTCTRL</title>
<style>
body{font-family:Arial,sans-serif;background:#f1f5f9;color:#172554;margin:0;padding:24px}
main{max-width:850px;margin:auto;background:#fff;padding:24px;border-radius:14px;box-shadow:0 8px 28px #0f172a18}
h1{margin-top:0}.item{padding:16px;border:1px solid #dbe4f0;border-radius:10px;margin:12px 0}
.old{color:#64748b}.new{color:#047857;font-weight:800}.alert{padding:12px;border-radius:8px;margin:14px 0;background:#fee2e2;color:#991b1b}
.success{background:#dcfce7;color:#166534}.btn{background:#1d4ed8;color:white;border:0;padding:12px 18px;border-radius:8px;font-weight:800;cursor:pointer}
.note{color:#64748b;font-size:13px;line-height:1.5}
</style></head><body><main>
<h1>DEPED Tournament — Team Updates</h1>
<p class="note">Review the two target teams below. The update changes the player membership and displayed team name while keeping the existing tournament entries and match records.</p>
<?php if($error):?><div class="alert"><?=h($error)?></div><?php endif;?>
<?php if($message):?><div class="success alert"><?=h($message)?></div><?php endif;?>
<?php foreach($preview as $item):$r=$item['row'];?>
<section class="item"><strong><?=h($r['category_name'])?> · Bracket B</strong>
<p class="old">Current: <?=h($r['entry_name'])?><br>Members: <?php foreach($r['current_members'] as $m) echo h($m['first_name'].' '.$m['last_name']).' ';?></p>
<p class="new">Replace with: <?=h($r['new_name'])?></p></section>
<?php endforeach;?>
<?php if(!$error && $preview):?><form method="post" onsubmit="return confirm('Apply both team changes to the DEPED TOURNAMENT database?');">
<button class="btn" type="submit" name="confirm_apply" value="1">Confirm and Apply Both Changes</button></form><?php endif;?>
<p class="note">After success, remove this file from the project folder. Back up the database before applying.</p>
</main></body></html>
