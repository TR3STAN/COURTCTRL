<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function norm($v) { return strtolower(preg_replace('/[^a-z0-9]+/i', '', trim((string)$v))); }
function timeValue($v) {
    if ($v instanceof DateTimeInterface) return $v->format('H:i:s');
    if (is_numeric($v)) return gmdate('H:i:s', (int)round(((float)$v) * 86400));
    $v = trim((string)$v);
    foreach (['g:i A','g:i a','H:i:s','H:i'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $v);
        if ($d) return $d->format('H:i:s');
    }
    return null;
}
$tournaments = $pdo->query("SELECT tournament_id,tournament_name,start_date FROM tournaments ORDER BY start_date DESC,tournament_id DESC")->fetchAll();
$tournamentId = (int)($_POST['tournament_id'] ?? $_GET['tournament_id'] ?? $_SESSION['active_tournament_id'] ?? 0);
if (!$tournamentId && $tournaments) $tournamentId=(int)$tournaments[0]['tournament_id'];
$errors=[]; $success=''; $previewCount=0;
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['import_fixed_schedule'])) {
    try {
        if (!isset($_FILES['schedule_file']) || $_FILES['schedule_file']['error']!==UPLOAD_ERR_OK) throw new RuntimeException('Choose a valid Excel schedule file.');
        $date=$_POST['schedule_date'] ?? '';
        $d=DateTime::createFromFormat('!Y-m-d',$date);
        if (!$d || $d->format('Y-m-d')!==$date) throw new RuntimeException('Choose a valid schedule date.');
        $courtQ=$pdo->prepare("SELECT court_id,court_name FROM courts WHERE tournament_id=? AND is_active=1 ORDER BY court_name");
        $courtQ->execute([$tournamentId]); $courts=$courtQ->fetchAll();
        if (count($courts)<8) throw new RuntimeException('This Excel schedule has 8 courts. Add/activate at least 8 courts in Tournament Setup before importing.');
        $spreadsheet=IOFactory::load($_FILES['schedule_file']['tmp_name']);
        $sheet=$spreadsheet->getActiveSheet();
        $rows=$sheet->toArray(null,true,true,false);
        $catQ=$pdo->prepare("SELECT category_id,category_name FROM categories WHERE tournament_id=?");
        $catQ->execute([$tournamentId]); $categories=$catQ->fetchAll();
        $aliases=[
          'lonmd'=>"Low Novice Men's Doubles",'low novicedoublesmen'=>"Low Novice Men's Doubles",
          'hncoed'=>"High Novice Coed Doubles",'lownwd'=>"Low Novice Women's Doubles",
          'bmd'=>"Beginners Men's Doubles",'bwd'=>"Beginners Women's Doubles",
          'bmxd'=>"Beginners Mixed Doubles",'lownmxd'=>"Low Novice Mixed Doubles",
          '40up'=>"40 UP"
        ];
        $catByAlias=[];
        foreach($categories as $cat) {
            $catByAlias[norm($cat['category_name'])]=$cat;
            foreach($aliases as $alias=>$name) if(norm($name)===norm($cat['category_name'])) $catByAlias[norm($alias)]=$cat;
        }
        $bracketQ=$pdo->prepare("SELECT bracket_id,bracket_name,bracket_order FROM brackets WHERE category_id=? ORDER BY bracket_order,bracket_id");
        // Match numbers in COURTCTRL are generated sequentially across the whole
        // category, not restarted at Game 1 for each bracket. Build a per-bracket
        // Game 1, Game 2... lookup using the order shown in the bracket screen.
        $rrMatchQ=$pdo->prepare("SELECT match_id,match_number FROM matches WHERE category_id=? AND bracket_id=? AND LOWER(TRIM(round_name))='round robin' ORDER BY match_number,match_id");
        // Elimination match_number is category-wide too. Build ordinal positions
        // within each round so Excel QF 1/SF 1 maps to the first match of that round.
        $elimMatchQ=$pdo->prepare("SELECT match_id,round_name,match_number FROM matches WHERE category_id=? AND LOWER(TRIM(round_name))<>'round robin' ORDER BY match_number,match_id");
        $matchByKey=[]; $unmatched=[]; $scheduleRows=[];
        for($r=3;$r<count($rows);$r++) {
            $row=$rows[$r];
            $start=timeValue($row[0]??null); if(!$start) continue;
            for($c=0;$c<8;$c++) {
                $label=trim((string)($row[1+$c*2]??'')); $game=trim((string)($row[2+$c*2]??''));
                if($label==='' || stripos($label,'OFFICIALS LUNCH')!==false) continue;
                $base=preg_replace('/\s*-\s*[A-D]\s*$/i','',$label);
                $letter=null;
                if(preg_match('/-\s*([A-D])\s*$/i',$label,$m)) $letter=strtoupper($m[1]);
                $cat=$catByAlias[norm($base)]??$catByAlias[norm($label)]??null;
                if(!$cat) { $unmatched[]="Row ".($r+1).": category '$label'"; continue; }
                $cid=(int)$cat['category_id'];
                if(!isset($matchByKey[$cid])) {
                    $bracketQ->execute([$cid]); $brackets=$bracketQ->fetchAll();
                    $elimMatchQ->execute([$cid]);
                    $elimByRound=[];
                    foreach($elimMatchQ->fetchAll(PDO::FETCH_ASSOC) as $elimRow) {
                        $roundKey=norm($elimRow['round_name']);
                        if(!isset($elimByRound[$roundKey])) $elimByRound[$roundKey]=[];
                        $elimByRound[$roundKey][]=(int)$elimRow['match_id'];
                    }
                    $matchByKey[$cid]=['brackets'=>$brackets,'matches'=>[],'elimination'=>$elimByRound];
                    foreach($brackets as $bracketRow) {
                        $bid=(int)$bracketRow['bracket_id'];
                        $rrMatchQ->execute([$cid,$bid]);
                        $orderedMatches=$rrMatchQ->fetchAll();
                        $matchByKey[$cid]['matches'][$bid]=[];
                        foreach($orderedMatches as $idx=>$matchRow) {
                            // Excel's Game N is the Nth round-robin matchup in that bracket.
                            $matchByKey[$cid]['matches'][$bid][$idx+1]=(int)$matchRow['match_id'];
                        }
                    }
                }
                $bracketId=null;
                if($letter!==null) {
                    foreach($matchByKey[$cid]['brackets'] as $b) {
                        $expected=chr(64+(int)$b['bracket_order']);
                        if(strcasecmp((string)$b['bracket_name'],'Bracket '.$letter)===0 || $expected===$letter) {$bracketId=(int)$b['bracket_id'];break;}
                    }
                }
                $detail=strtoupper(preg_replace('/\s+/','',$game));
                $round='Round Robin'; $matchNo=0;
                if(preg_match('/^GAME(\d+)$/',$detail,$m)) {
                    $matchNo=(int)$m[1];
                    // If the workbook omits a bracket letter and this category has
                    // exactly one group, safely map its games to that only bracket.
                    if ($bracketId === null && count($matchByKey[$cid]['brackets']) === 1) {
                        $bracketId=(int)$matchByKey[$cid]['brackets'][0]['bracket_id'];
                    }
                }
                else {
                    if(preg_match('/^(QF|SF)(\\d+)$/',$detail,$m)) {
                        $round=$m[1]==='QF'?'Quarterfinals':'Semifinals';
                        $matchNo=(int)$m[2];
                    }
                    elseif(in_array($detail,['GOLD','BRONZE'],true)) {
                        $round=$detail==='GOLD'?'Final':'Bronze'; $matchNo=1;
                    }
                    else { $unmatched[]="Row ".($r+1).": unsupported match label '$game'"; continue; }
                }
                $mid=0;
                if($round==='Round Robin' && $bracketId) {
                    // Important: don't compare Excel Game N to matches.match_number.
                    // That DB number is category-wide (e.g. Bracket B Game 1 may
                    // have match_number 16). Use its ordinal within the bracket.
                    $mid=(int)($matchByKey[$cid]['matches'][$bracketId][$matchNo]??0);
                } else {
                    $roundAliases=[
                        'Quarterfinals'=>['quarterfinal','quarterfinals'],
                        'Semifinals'=>['semifinal','semifinals'],
                        'Final'=>['final'],
                        'Bronze'=>['bronze','third place']
                    ];
                    $mid=0;
                    foreach(($roundAliases[$round]??[$round]) as $roundAlias) {
                        $roundKey=norm($roundAlias);
                        $mid=(int)($matchByKey[$cid]['elimination'][$roundKey][$matchNo-1]??0);
                        if($mid) break;
                    }
                }
                if(!$mid) { $unmatched[]="Row ".($r+1).": no database match for ".$cat['category_name']." / ".($letter?'Bracket '.$letter:'Elimination')." / ".$game; continue; }
                $scheduleRows[]=['match_id'=>$mid,'court_id'=>(int)$courts[$c]['court_id'],'start'=>$start,'order'=>$r*8+$c+1];
            }
        }
        if(!$scheduleRows) throw new RuntimeException('No matches could be mapped from this workbook. Check category and bracket names.');
        if($unmatched) throw new RuntimeException("Import stopped because some Excel rows could not be matched. First issues: ".implode(' | ',array_slice(array_unique($unmatched),0,8)));
        $pdo->beginTransaction();
        if(empty($_POST['replace_existing'])) {
            $check=$pdo->prepare("SELECT COUNT(*) FROM match_schedules ms JOIN matches m ON m.match_id=ms.match_id JOIN categories c ON c.category_id=m.category_id WHERE c.tournament_id=?");
            $check->execute([$tournamentId]);
            if((int)$check->fetchColumn()>0) throw new RuntimeException('This tournament already has scheduled matches. Tick Replace existing schedules only if you intend to overwrite them.');
        } else {
            $del=$pdo->prepare("DELETE ms FROM match_schedules ms JOIN matches m ON m.match_id=ms.match_id JOIN categories c ON c.category_id=m.category_id WHERE c.tournament_id=?");
            $del->execute([$tournamentId]);
        }
        $ins=$pdo->prepare("INSERT INTO match_schedules(match_id,tournament_day,scheduled_start,scheduled_end,court_id,schedule_order,is_manual_override) VALUES(?,?,?,?,?,?,1)");
        $upd=$pdo->prepare("UPDATE matches SET match_status='Scheduled' WHERE match_id=?");
        foreach($scheduleRows as $item) {
            $end=(new DateTime($item['start']))->modify('+15 minutes')->format('H:i:s');
            $ins->execute([$item['match_id'],$date,$item['start'],$end,$item['court_id'],$item['order']]);
            $upd->execute([$item['match_id']]);
        }
        $pdo->commit();
        $success=count($scheduleRows)." match schedule assignments imported successfully. Multiple courts per bracket are supported.";
        $_SESSION['active_tournament_id']=$tournamentId;
    } catch(Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $errors[]=$e->getMessage();
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fixed / Multi-Court Schedule | COURTCTRL</title><link rel="stylesheet" href="assets/css/style.css">
</head><body class="page-fixed-schedule"><main class="page">
<a href="schedule.php?tournament_id=<?= (int)$tournamentId ?>">← Back to Schedule &amp; Courts</a>
<h1>Fixed Schedule / Multi-Court Import</h1>
<p class="muted">Import an Excel timetable where the same bracket can use several courts at the same time. Each Excel court column becomes its own match assignment and time slot.</p>
<?php foreach($errors as $error): ?><div class="notice error" role="alert" aria-live="polite"><?=h($error)?></div><?php endforeach; ?>
<?php if($success): ?><div class="notice success" role="status" aria-live="polite"><?=h($success)?><br><a href="schedule.php?tournament_id=<?= (int)$tournamentId ?>">Open Schedule &amp; Courts</a></div><?php endif; ?>
<section class="panel"><h2>Import Excel timetable</h2>
<form method="post" enctype="multipart/form-data">
<div class="row"><label class="field">Tournament<select name="tournament_id" required><?php foreach($tournaments as $t): ?><option value="<?= (int)$t['tournament_id'] ?>" <?=$tournamentId===(int)$t['tournament_id']?'selected':''?>><?=h($t['tournament_name'])?></option><?php endforeach; ?></select></label>
<label class="field">Schedule date<input type="date" name="schedule_date" value="<?=h($_POST['schedule_date']??($tournaments[0]['start_date']??date('Y-m-d')))?>" required></label></div>
<label class="field">Excel file (.xlsx / .xls)<input type="file" name="schedule_file" accept=".xlsx,.xls" required></label>
<label><input type="checkbox" name="replace_existing" value="1"> Replace existing schedules for this tournament (use only if you want to overwrite them)</label>
<p class="muted">Expected layout: column A contains time; columns B/C are Court 1's category and game; D/E Court 2; continuing through Court 8. Each row is one time slot. Match duration is currently set to 15 minutes.</p>
<button class="btn" type="submit" name="import_fixed_schedule" value="1" data-confirm="Import this timetable into the selected tournament?">Import Fixed Schedule</button>
</form></section>
<section class="panel"><h3>Important</h3><ul><li>At least 8 active courts must exist in the selected tournament.</li><li>Category abbreviations in the workbook must correspond to existing category names (BMD, BWD, BMxD, Lo NMD, Low NWD, Low NMxD, HN Coed, 40 Up).</li><li>Every Game/QF/SF/Gold/Bronze label must match an existing match in the database. The import stops rather than silently skipping unmatched games.</li><li>Back up your database before replacing an existing schedule.</li></ul></section>
</main><script src="assets/js/app.js" defer></script></body></html>
