<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Seeder.php';

use App\Database;
use App\Seeder;

echo "========================================================\n";
echo "   NẠP CƠ SỞ DỮ LIỆU VÀO XAMPP MYSQL (PORT 3306)\n";
echo "========================================================\n\n";

try {
    // 1. Kết nối MySQL Server của XAMPP
    $host = '127.0.0.1';
    $port = 3306;
    $user = 'root';
    $pass = '';

    $pdoServer = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "[1/4] Kết nối thành công tới XAMPP MySQL (root@{$host}:{$port})\n";

    // 2. Tạo Database warehouse_db
    $dbName = 'warehouse_db';
    $pdoServer->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    echo "[2/4] Đã tạo / kiểm tra Database `{$dbName}` thành công!\n";

    // 3. Kết nối trực tiếp vào warehouse_db và nạp Schema MySQL
    $pdoMysql = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $schemaFile = __DIR__ . '/../database/schema_mysql.sql';
    if (file_exists($schemaFile)) {
        $sql = file_get_contents($schemaFile);
        $pdoMysql->exec($sql);
        echo "[3/4] Đã nạp cấu trúc 11 bảng từ `database/schema_mysql.sql` vào MySQL!\n";
    }

    // 4. Nạp toàn bộ dữ liệu mẫu (>200 dòng phát sinh, 3 kho, 30 mặt hàng) vào MySQL
    // Chuyển Seeder chạy trực tiếp trên MySQL
    Database::setInstance($pdoMysql);
    $res = Seeder::run($pdoMysql);

    echo "[4/4] ĐÃ NẠP DỮ LIỆU MẪU VÀO MYSQL THÀNH CÔNG:\n";
    echo "      + Điểm kho: {$res['warehouses']} kho\n";
    echo "      + Mặt hàng: {$res['items']} mặt hàng\n";
    echo "      + Sổ phát sinh: {$res['movements']} dòng phát sinh\n\n";

    // 5. Tạo file cấu hình .local/db.json để hệ thống chuyển sang dùng MySQL
    $configDir = __DIR__ . '/../.local';
    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    $dbConfig = [
        'DB_DRIVER' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_NAME' => 'warehouse_db',
        'DB_USER' => 'root',
        'DB_PASS' => ''
    ];
    file_put_contents($configDir . '/db.json', json_encode($dbConfig, JSON_PRETTY_PRINT));
    echo "[XONG] Đã cấu hình hệ thống kết nối trực tiếp với XAMPP MySQL!\n";

} catch (Exception $e) {
    echo "LỖI: " . $e->getMessage() . "\n";
    exit(1);
}
