<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

use App\Database;

$pdo = Database::getConnection();

$tables = [
    'users',
    'warehouses',
    'user_warehouses',
    'item_categories',
    'items',
    'batches',
    'vouchers',
    'voucher_lines',
    'stock_movements',
    'stock_counts',
    'stock_count_lines'
];

// 1. Tạo file MySQL hoàn chỉnh (schema_mysql.sql + dữ liệu)
$mysqlSchema = file_get_contents(__DIR__ . '/../database/schema_mysql.sql');
$mysqlDump = $mysqlSchema . "\n\n";
$mysqlDump .= "-- ========================================================\n";
$mysqlDump .= "-- DỮ LIỆU MẪU ĐÃ GHI SỔ PHÁT SINH (>200 DÒNG)\n";
$mysqlDump .= "-- ========================================================\n\n";

$mysqlDump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($tables as $t) {
    $rows = $pdo->query("SELECT * FROM {$t}")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        $mysqlDump .= "-- Bảng: {$t} (" . count($rows) . " bản ghi)\n";
        foreach ($rows as $r) {
            $cols = array_map(fn($c) => "`{$c}`", array_keys($r));
            $vals = array_map(function ($v) {
                if ($v === null) {
                    return 'NULL';
                }
                return "'" . str_replace("'", "''", (string)$v) . "'";
            }, array_values($r));
            $mysqlDump .= "INSERT INTO `{$t}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
        }
        $mysqlDump .= "\n";
    }
}

$mysqlDump .= "SET FOREIGN_KEY_CHECKS = 1;\n";

$mysqlFile = __DIR__ . '/../database/mysql_full_import.sql';
file_put_contents($mysqlFile, $mysqlDump);
echo "Tạo thành công file MySQL: " . $mysqlFile . " (" . strlen($mysqlDump) . " bytes)\n";

// 2. Tự động nạp trực tiếp vào XAMPP MySQL đang chạy
try {
    $pdoMy = new PDO("mysql:host=127.0.0.1;port=3306;charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true
    ]);
    $pdoMy->exec($mysqlDump);
    echo "NẠP THÀNH CÔNG VÀO XAMPP MYSQL DATABASE: `warehouse_db` (127.0.0.1:3306)!\n";
} catch (Exception $e) {
    echo "Lỗi nạp MySQL: " . $e->getMessage() . "\n";
}
