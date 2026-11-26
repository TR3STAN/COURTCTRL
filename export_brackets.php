<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function xlsxSafe($value): string {
    return (string)($value ?? '');
}

$tournaments = $pdo->query("SELECT tournament_id,tournament_name FROM tournaments ORDER BY tournament_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$tournamentId = (int)($_GET['tournament_id'] ?? ($_SESSION['active_tournament_id'] ?? 0));
$categoryId = (int)($_GET['category_id'] ?? 0);
if ($tournamentId <= 0 || !in_array($tournamentId, array_map('intval', array_column($tournaments, 'tournament_id')), true)) {
    $tournamentId = (int)($tournaments[0]['tournament_id'] ?? 0);
}
if ($tournamentId > 0) $_SESSION['active_tournament_id'] = $tournamentId;

$catQuery = $pdo->prepare("SELECT c.category_id,c.category_name,c.game_format,t.tournament_name
    FROM categories c JOIN tournaments t ON t.tournament_id=c.tournament_id
    WHERE c.category_id=? AND c.tournament_id=?");
$catQuery->execute([$categoryId, $tournamentId]);
$category = $catQuery->fetch(PDO::FETCH_ASSOC);
if (!$category) {
    http_response_code(404);
    exit('Category not found in the selected tournament.');
}

$sql = "SELECT m.match_id,m.bracket_id,m.round_name,m.match_number,m.winner_entry_id,m.match_status,
        b.bracket_name,b.bracket_order,e1.entry_name AS entry1_name,e2.entry_name AS entry2_name,
        w.entry_name AS winner_name,COALESCE(sc.entry1_total,0) AS entry1_score,COALESCE(sc.entry2_total,0) AS entry2_score
    FROM matches m
    LEFT JOIN brackets b ON b.bracket_id=m.bracket_id
    LEFT JOIN tournament_entries e1 ON e1.entry_id=m.entry1_id
    LEFT JOIN tournament_entries e2 ON e2.entry_id=m.entry2_id
    LEFT JOIN tournament_entries w ON w.entry_id=m.winner_entry_id
    LEFT JOIN (
        SELECT match_id,SUM(entry1_score) AS entry1_total,SUM(entry2_score) AS entry2_total
        FROM match_scores GROUP BY match_id
    ) sc ON sc.match_id=m.match_id
    WHERE m.category_id=?
    ORDER BY COALESCE(b.bracket_order,999999),m.match_number,m.match_id";
$q = $pdo->prepare($sql);
$q->execute([$categoryId]);
$matches = $q->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
$rrMatches = [];
$elimMatches = [];
foreach ($matches as $match) {
    if (strcasecmp(trim((string)$match['round_name']), 'Round Robin') === 0) {
        $rrMatches[] = $match;
        $bid = (int)($match['bracket_id'] ?? 0);
        $groupName = $match['bracket_name'] ?: 'Unassigned group';
        if (!isset($groups[$bid])) $groups[$bid] = ['name'=>$groupName, 'entries'=>[]];
        foreach (['entry1_name','entry2_name'] as $key) {
            $name = trim((string)($match[$key] ?? ''));
            if ($name !== '') $groups[$bid]['entries'][$name] = true;
        }
    } else {
        $elimMatches[] = $match;
    }
}

$book = new Spreadsheet();
$book->getProperties()->setCreator('COURTCTRL')->setTitle('Tournament Brackets - '.$category['category_name']);
$headerStyle = [
    'font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],
    'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'17366D']],
    'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER]
];
$subHeaderStyle = ['font'=>['bold'=>true,'color'=>['rgb'=>'17366D']]];
function prepareSheet($sheet, string $title, array $headers, array $headerStyle): void {
    $sheet->setTitle($title);
    $sheet->fromArray([$headers], null, 'A1');
    $last = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle("A1:{$last}1")->applyFromArray($headerStyle);
    $sheet->freezePane('A2');
    $sheet->setAutoFilter("A1:{$last}1");
    $sheet->getRowDimension(1)->setRowHeight(24);
}
function autosize($sheet, int $columns): void {
    for ($i=1; $i<=$columns; $i++) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
}

// Sheet 1: true Round Robin groups and their registered entries only.
$sheet = $book->getActiveSheet();
prepareSheet($sheet, 'Group Brackets', ['Group / Bracket','Entry'], $headerStyle);
$row = 2;
foreach ($groups as $group) {
    $entries = array_keys($group['entries']);
    sort($entries, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($entries as $entryName) {
        $sheet->fromArray([[$group['name'], $entryName]], null, 'A'.$row++);
    }
}
if ($row === 2) $sheet->setCellValue('A2', 'No Round Robin groups generated.');
autosize($sheet, 2);

// Sheet 2: Round Robin fixtures, results, and statuses.
$rrSheet = $book->createSheet();
prepareSheet($rrSheet, 'Round Robin Matches', ['Group / Bracket','Team / Entry 1','Team / Entry 2','Score 1','Score 2','Winner','Status'], $headerStyle);
$row = 2;
foreach ($rrMatches as $m) {
    $rrSheet->fromArray([[
        xlsxSafe($m['bracket_name'] ?: 'Unassigned group'),
        xlsxSafe($m['entry1_name'] ?: 'TBD'),
        xlsxSafe($m['entry2_name'] ?: 'TBD'),
        $m['entry1_score'], $m['entry2_score'],
        xlsxSafe($m['winner_name']), xlsxSafe($m['match_status'])
    ]], null, 'A'.$row++);
}
if ($row === 2) $rrSheet->setCellValue('A2', 'No Round Robin matches generated.');
autosize($rrSheet, 7);

// Sheet 3: elimination rounds are deliberately separated from group brackets.
$elimSheet = $book->createSheet();
prepareSheet($elimSheet, 'Elimination Bracket', ['Round','Team / Entry 1','Team / Entry 2','Score 1','Score 2','Winner','Status'], $headerStyle);
$row = 2;
foreach ($elimMatches as $m) {
    $elimSheet->fromArray([[
        xlsxSafe($m['round_name']),
        xlsxSafe($m['entry1_name'] ?: 'TBD'),
        xlsxSafe($m['entry2_name'] ?: 'TBD'),
        $m['entry1_score'], $m['entry2_score'],
        xlsxSafe($m['winner_name']), xlsxSafe($m['match_status'])
    ]], null, 'A'.$row++);
}
if ($row === 2) $elimSheet->setCellValue('A2', 'No elimination matches generated yet.');
autosize($elimSheet, 7);

// Tournament/category context on each worksheet.
foreach ($book->getAllSheets() as $ws) {
    $ws->getHeaderFooter()->setOddHeader('&BCOURTCTRL');
    $ws->getHeaderFooter()->setOddFooter('&L'.$category['tournament_name'].' · '.$category['category_name'].'&RPage &P of &N');
    
}

$filename = 'COURTCTRL_Brackets_'.preg_replace('/[^A-Za-z0-9_-]+/', '_', $category['category_name']).'_'.date('Ymd_His').'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($book);
$writer->save('php://output');
exit;
