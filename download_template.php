<?php
require_once __DIR__ . '/config/database.php';
$categoryId = filter_var($_GET['category_id'] ?? null, FILTER_VALIDATE_INT);
if (!$categoryId) { http_response_code(400); exit('Select a category first.'); }
if (!is_file(__DIR__ . '/vendor/autoload.php')) { http_response_code(500); exit('Excel dependency missing. Run composer install in the project folder.'); }
require_once __DIR__ . '/vendor/autoload.php';
$stmt = $pdo->prepare('SELECT category_name, category_type FROM categories WHERE category_id=?');
$stmt->execute([$categoryId]);
$category = $stmt->fetch();
if (!$category) { http_response_code(404); exit('Category not found.'); }
$headers = $category['category_type'] === 'Doubles'
    ? ['Player 1 First Name','Player 1 Last Name','Player 1 Gender','Player 2 First Name','Player 2 Last Name','Player 2 Gender']
    : ['First Name','Last Name','Gender','Contact Number'];
$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Registration');
$sheet->fromArray($headers, null, 'A1');
$sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
$sheet->freezePane('A2');
foreach (range('A', $sheet->getHighestColumn()) as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
$sheet->setCellValue('A2', '');
$filename = 'registration_template_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $category['category_name']) . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save('php://output');
