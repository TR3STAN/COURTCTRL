<?php
// Export the selected tournament/category schedule to an Excel workbook.
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$tournamentId = (int) ($_GET['tournament_id'] ?? 0);
$categoryId = (int) ($_GET['category_id'] ?? 0);

$sql = "
    SELECT
        cat.category_name,
        ms.tournament_day,
        t.start_date,
        ms.schedule_order,
        c.court_name,
        m.bracket_id,
        b.bracket_order,
        m.match_number,
        m.round_name,
        e1.entry_name AS entry1_name,
        e2.entry_name AS entry2_name,
        m.match_status
    FROM match_schedules AS ms
    INNER JOIN matches AS m ON m.match_id = ms.match_id
    INNER JOIN categories AS cat ON cat.category_id = m.category_id
    LEFT JOIN brackets AS b ON b.bracket_id = m.bracket_id
    INNER JOIN tournaments AS t ON t.tournament_id = cat.tournament_id
    INNER JOIN courts AS c ON c.court_id = ms.court_id
    LEFT JOIN tournament_entries AS e1 ON e1.entry_id = m.entry1_id
    LEFT JOIN tournament_entries AS e2 ON e2.entry_id = m.entry2_id
    WHERE cat.tournament_id = ?
";
$params = [$tournamentId];
if ($categoryId > 0) {
    $sql .= " AND m.category_id = ?";
    $params[] = $categoryId;
}
$sql .= " ORDER BY ms.tournament_day, COALESCE(m.bracket_id,0), ms.schedule_order";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$workbook = new Spreadsheet();
$sheet = $workbook->getActiveSheet();
$sheet->setTitle('Match Schedule');

$headers = [
    'Category', 'Play Date', 'Schedule Order', 'Court',
    'Bracket', 'Match #', 'Matchup', 'Round', 'Status'
];
$sheet->fromArray($headers, null, 'A1');

$bracketFills = ['FFB3E5FC','FFFFD180','FFC8E6C9','FFE1BEE7','FFFFCDD2','FFFFF59D'];
$bracketColorMap = [];
$nextColor = 0;

$rowNumber = 2;
foreach ($rows as $row) {
    $bracketId = (int) ($row['bracket_id'] ?? 0);
    if (!array_key_exists($bracketId, $bracketColorMap)) {
        $bracketColorMap[$bracketId] = $bracketFills[$nextColor % count($bracketFills)];
        $nextColor++;
    }

    $matchup = ($row['entry1_name'] ?: 'TBD / BYE')
        . ' vs '
        . ($row['entry2_name'] ?: 'TBD / BYE');

    // tournament_day may contain a full date or a day index. Normalize it for Excel.
    $rawDay = trim((string) ($row['tournament_day'] ?? ''));
    $playDate = null;
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $rawDay)) {
        $playDate = $rawDay;
    } elseif (preg_match('/^\\d{4}$/', $rawDay)) {
        $playDate = !empty($row['start_date']) ? date('Y-m-d', strtotime($row['start_date'])) : $rawDay . '-01-01';
    } elseif (is_numeric($rawDay) && !empty($row['start_date'])) {
        $dayOffset = max(0, (int)$rawDay - 1);
        $playDate = date('Y-m-d', strtotime($row['start_date'] . ' +' . $dayOffset . ' days'));
    } elseif (!empty($row['start_date'])) {
        $playDate = date('Y-m-d', strtotime($row['start_date']));
    }
    $playDate = $playDate ?: $rawDay;
    $matchNo = 'Match ' . (int) ($row['match_number'] ?? $row['schedule_order'] ?? 0);

    $sheet->fromArray([
        $row['category_name'],
        $playDate,
        $row['schedule_order'],
        $row['court_name'],
        $bracketId ? 'Bracket ' . chr(64 + max(1, (int) ($row['bracket_order'] ?? $bracketId))) : 'Main bracket',
        $matchNo,
        $matchup,
        $row['round_name'],
        $row['match_status']
    ], null, 'A' . $rowNumber);

    if ($playDate && strtotime($playDate)) {
        $sheet->setCellValue('B' . $rowNumber, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(strtotime($playDate)));
        $sheet->getStyle('B' . $rowNumber)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    }
    $sheet->getStyle('A' . $rowNumber . ':I' . $rowNumber)
        ->getFill()->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setARGB($bracketColorMap[$bracketId]);

    $rowNumber++;
}

$sheet->getStyle('A1:I1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF1565C0');
$sheet->getStyle('A1:I1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
$sheet->freezePane('A2');
$sheet->setAutoFilter('A1:I1');

foreach (range('A', 'I') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}
$sheet->getColumnDimension('G')->setWidth(55);
$sheet->getStyle('G:G')->getAlignment()->setWrapText(true);

$filename = 'courtctrl_schedule_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($workbook);
$writer->save('php://output');
exit;
