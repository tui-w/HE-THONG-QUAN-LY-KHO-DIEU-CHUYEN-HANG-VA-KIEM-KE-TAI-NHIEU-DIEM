<?php
declare(strict_types=1);

/**
 * Script Khởi tạo CSDL MySQL (Setup & Seed) cho XAMPP
 * Chạy trực tiếp từ dòng lệnh hoặc giao diện:
 * e:\xampp\php\php.exe database/setup_mysql.php
 */

echo "========================================================\n";
echo "   KHỞI TẠO CƠ SỞ DỮ LIỆU MYSQL / PHPMYADMIN (XAMPP)\n";
echo "   Hệ thống Quản lý kho, Điều chuyển và Kiểm kê đa điểm\n";
echo "========================================================\n\n";

$host = '127.0.0.1';
$port = 3306;
$user = 'root';
$pass = '';

try {
    echo "[1/4] Kết nối máy chủ MySQL/MariaDB tại {$host}:{$port}...\n";
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "      -> Kết nối MySQL thành công!\n";
} catch (PDOException $e) {
    echo "      -> [CHƯA BẬT MYSQL] Không thể kết nối tới MySQL XAMPP ({$e->getMessage()}).\n";
    echo "      -> Hướng dẫn: Mở 'XAMPP Control Panel' và nhấn 'Start' ở module MySQL (hoặc chạy e:\\xampp\\mysql_start.bat).\n\n";
    exit(1);
}

// 1. Tạo Database qlkho_nhom10 cho thư mục BTL
try {
    echo "[2/4] Khởi tạo Database 'qlkho_nhom10' (dành cho thư mục BTL)...\n";
    $schemaBtl = file_get_contents(__DIR__ . '/../../BTL/database/schema.sql');
    $seedBtl = file_get_contents(__DIR__ . '/../../BTL/database/seed.sql');
    
    $pdo->exec($schemaBtl);
    $pdo->exec("USE `qlkho_nhom10`;");
    $pdo->exec($seedBtl);
    
    $cntBtlItems = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
    $cntBtlWh = $pdo->query("SELECT COUNT(*) FROM warehouses")->fetchColumn();
    echo "      -> Đã tạo database `qlkho_nhom10`: {$cntBtlWh} kho, {$cntBtlItems} mặt hàng.\n";
} catch (Throwable $e) {
    echo "      -> Cảnh báo khi import BTL: " . $e->getMessage() . "\n";
}

// 2. Tạo Database warehouse_db cho BTL_QuanLyKho
try {
    echo "[3/4] Khởi tạo Database 'warehouse_db' (dành cho BTL_QuanLyKho)...\n";
    $sqlDump = file_get_contents(__DIR__ . '/mysql_full_import.sql');
    $pdo->exec($sqlDump);
    $pdo->exec("USE `warehouse_db`;");
    
    $cntWh = $pdo->query("SELECT COUNT(*) FROM warehouses")->fetchColumn();
    $cntItems = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
    $cntMovements = $pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
    echo "      -> Đã tạo database `warehouse_db`: {$cntWh} kho, {$cntItems} mặt hàng, {$cntMovements} dòng phát sinh sổ cái.\n";
} catch (Throwable $e) {
    echo "      -> Cảnh báo khi import warehouse_db: " . $e->getMessage() . "\n";
}

echo "[4/4] Hoàn tất toàn bộ quy trình thiết lập Cơ sở dữ liệu MySQL!\n";
echo "--------------------------------------------------------\n";
echo "THÀNH CÔNG: CSDL đã sẵn sàng trên phpMyAdmin (http://localhost/phpmyadmin)!\n";
