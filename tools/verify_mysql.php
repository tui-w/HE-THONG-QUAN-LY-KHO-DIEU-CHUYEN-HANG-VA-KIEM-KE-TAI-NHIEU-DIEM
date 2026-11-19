<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=warehouse_db', 'root', '');
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "DANH SACH BANG VA SO BAN GHI TRONG XAMPP MYSQL (warehouse_db):\n";
foreach ($tables as $t) {
    $count = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    echo "  + {$t}: {$count} bản ghi\n";
}
