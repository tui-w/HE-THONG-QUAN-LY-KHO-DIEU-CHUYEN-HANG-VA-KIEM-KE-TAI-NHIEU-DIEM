<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(?string $customDbPath = null): PDO
    {
        if (self::$instance !== null && $customDbPath === null) {
            return self::$instance;
        }

        $configPath = __DIR__ . '/../.local/db.json';
        $config = [];
        if (is_file($configPath)) {
            $decoded = json_decode((string)file_get_contents($configPath), true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        $driver = $config['DB_DRIVER'] ?? getenv('DB_DRIVER') ?: 'sqlite';

        if ($driver === 'mysql') {
            $host = $config['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
            $port = $config['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
            $dbname = $config['DB_NAME'] ?? getenv('DB_NAME') ?: 'warehouse_db';
            $user = $config['DB_USER'] ?? getenv('DB_USER') ?: 'root';
            $pass = $config['DB_PASS'] ?? getenv('DB_PASS') ?: '';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            try {
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                throw new RuntimeException("Lỗi kết nối MySQL: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        } else {
            // Mặc định dùng SQLite cục bộ - Hoàn toàn không cần cài đặt dịch vụ ngoài, hoạt động mượt mà trên cả Ubuntu WSL và Windows
            $dbFile = $customDbPath ?: ($config['SQLITE_PATH'] ?? __DIR__ . '/../.local/warehouse.sqlite');
            $dir = dirname($dbFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            $isNew = !file_exists($dbFile);
            try {
                $pdo = new PDO("sqlite:{$dbFile}", null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $pdo->exec("PRAGMA foreign_keys = ON;");
                $pdo->exec("PRAGMA journal_mode = WAL;");
                $pdo->exec("PRAGMA busy_timeout = 5000;");
            } catch (PDOException $e) {
                throw new RuntimeException("Lỗi mở SQLite: " . $e->getMessage(), (int)$e->getCode(), $e);
            }

            if ($isNew) {
                self::initSchema($pdo);
            }
        }

        self::ensureExtendedColumns($pdo);

        if ($customDbPath === null) {
            self::$instance = $pdo;
        }

        return $pdo;
    }

    public static function setInstance(PDO $pdo): void
    {
        self::$instance = $pdo;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public static function initSchema(PDO $pdo): void
    {
        $schemaFile = __DIR__ . '/../database/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $pdo->exec($sql);
        }
        self::ensureExtendedColumns($pdo);
    }

    public static function ensureExtendedColumns(PDO $pdo): void
    {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(100) DEFAULT NULL");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
        } catch (\Throwable $e) {}
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS activity_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    username VARCHAR(50),
                    action VARCHAR(50) NOT NULL,
                    target_type VARCHAR(50),
                    target_id VARCHAR(50),
                    details TEXT,
                    ip_address VARCHAR(50),
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_activity_logs_action ON activity_logs(action);
                CREATE INDEX IF NOT EXISTS idx_activity_logs_created ON activity_logs(created_at);
            ");
        } catch (\Throwable $e) {}

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS warehouse_locations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    warehouse_id INTEGER NOT NULL,
                    zone_code VARCHAR(20) NOT NULL,     -- Khu vực (A: Hàng khô, B: Điện tử, C: Máy lạnh)
                    rack_code VARCHAR(20) NOT NULL,     -- Dãy kệ (R01, R02...)
                    level_code VARCHAR(10) NOT NULL,    -- Tầng (T1, T2, T3)
                    bin_code VARCHAR(20) NOT NULL,      -- Ô lưu trữ (O01, O02...)
                    location_barcode VARCHAR(50) UNIQUE NOT NULL, -- Mã vạch vị trí (VD: K1-A-R01-T1-O01)
                    item_id INTEGER DEFAULT NULL,       -- Mặt hàng gán vị trí
                    max_capacity INTEGER DEFAULT 500,   -- Sức chứa tối đa của ô
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE,
                    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL
                );
                CREATE INDEX IF NOT EXISTS idx_wh_locations_wh ON warehouse_locations(warehouse_id);
                CREATE INDEX IF NOT EXISTS idx_wh_locations_barcode ON warehouse_locations(location_barcode);
            ");

            // Khởi tạo các vị trí mẫu nếu bảng chưa có dữ liệu
            $cntLoc = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_locations")->fetchColumn();
            if ($cntLoc === 0) {
                $whs = [1, 2, 3];
                $zones = ['A' => 'Điện tử', 'B' => 'Linh kiện', 'C' => 'Phụ kiện'];
                $ins = $pdo->prepare("
                    INSERT INTO warehouse_locations (warehouse_id, zone_code, rack_code, level_code, bin_code, location_barcode, item_id, max_capacity)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $sampleItemId = 1;
                foreach ($whs as $w) {
                    foreach (['A', 'B', 'C'] as $z) {
                        for ($r = 1; $r <= 2; $r++) {
                            for ($lvl = 1; $lvl <= 2; $lvl++) {
                                for ($b = 1; $b <= 2; $b++) {
                                    $rack = sprintf('R%02d', $r);
                                    $level = sprintf('T%d', $lvl);
                                    $bin = sprintf('O%02d', $b);
                                    $barcode = sprintf('K%d-%s-%s-%s-%s', $w, $z, $rack, $level, $bin);
                                    $it = ($sampleItemId <= 30) ? $sampleItemId++ : null;
                                    $ins->execute([$w, $z, $rack, $level, $bin, $barcode, $it, 200]);
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {}

        // Bảng Đối tác & Nhà cung cấp / Khách hàng (Partners)
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS partners (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    code VARCHAR(30) UNIQUE NOT NULL,
                    name VARCHAR(150) NOT NULL,
                    type VARCHAR(20) NOT NULL, -- 'SUPPLIER' hoặc 'CUSTOMER'
                    phone VARCHAR(30),
                    email VARCHAR(100),
                    address TEXT,
                    tax_code VARCHAR(30),
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS payments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    payment_code VARCHAR(50) UNIQUE NOT NULL,
                    voucher_id INTEGER DEFAULT NULL,
                    partner_id INTEGER NOT NULL,
                    type VARCHAR(20) NOT NULL, -- 'PAY' (Chi tiền NCC) hoặc 'RECEIVE' (Thu tiền KH)
                    amount DOUBLE NOT NULL,
                    payment_method VARCHAR(30) DEFAULT 'BANK_TRANSFER', -- 'CASH', 'BANK_TRANSFER'
                    status VARCHAR(20) DEFAULT 'PAID', -- 'PENDING', 'PAID'
                    created_by INTEGER NOT NULL,
                    note TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE SET NULL,
                    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE,
                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
                );
                CREATE INDEX IF NOT EXISTS idx_payments_voucher ON payments(voucher_id);
                CREATE INDEX IF NOT EXISTS idx_payments_partner ON payments(partner_id);
            ");

            // Khởi tạo đối tác mẫu nếu chưa có
            $cntP = (int)$pdo->query("SELECT COUNT(*) FROM partners")->fetchColumn();
            if ($cntP === 0) {
                $samplePartners = [
                    ['NCC-DELL', 'Công ty TNHH Dell Technologies Việt Nam', 'SUPPLIER', '024-3768-9999', 'support@dell.vn', 'Tòa nhà Landmark 72, Cầu Giấy, Hà Nội', '0101234567'],
                    ['NCC-LOGI', 'Nhà phân phối Logitech Synnex FPT', 'SUPPLIER', '028-7300-8888', 'contact@synnexfpt.com.vn', 'Khu công nghệ cao, TP. Thủ Đức, TP.HCM', '0309876543'],
                    ['NCC-SAM', 'Công ty Điện tử Samsung Vina', 'SUPPLIER', '028-3915-7777', 'b2b.vn@samsung.com', 'Tòa nhà Bitexco, Quận 1, TP.HCM', '0301122334'],
                    ['KH-FPT', 'Tập đoàn Công nghệ FPT (Chi nhánh Hà Nội)', 'CUSTOMER', '024-7300-7300', 'procurement@fpt.com.vn', 'Phố Duy Tân, Dịch Vọng Hậu, Cầu Giấy, Hà Nội', '0101248141'],
                    ['KH-VNG', 'Công ty Cổ phần VNG (VNG Campus)', 'CUSTOMER', '028-3962-3888', 'contact@vng.com.vn', 'Khu chế xuất Tân Thuận, Quận 7, TP.HCM', '0303886515'],
                    ['KH-VIETIN', 'Ngân hàng TMCP Công thương Việt Nam (VietinBank)', 'CUSTOMER', '024-3942-1030', 'support@vietinbank.vn', '108 Trần Hưng Đạo, Hoàn Kiếm, Hà Nội', '0100111948']
                ];

                $insP = $pdo->prepare("INSERT INTO partners (code, name, type, phone, email, address, tax_code) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($samplePartners as $sp) {
                    $insP->execute($sp);
                }

                // Tạo một số giao dịch mẫu
                $insPay = $pdo->prepare("
                    INSERT INTO payments (payment_code, voucher_id, partner_id, type, amount, payment_method, status, created_by, note)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insPay->execute(['PAY-20261020-001', 1, 1, 'PAY', 45000000, 'BANK_TRANSFER', 'PAID', 1, 'Thanh toán tiền nhập lô máy tính Dell đợt 1']);
                $insPay->execute(['PAY-20261021-002', 2, 2, 'PAY', 18500000, 'BANK_TRANSFER', 'PAID', 1, 'Thanh toán nhập bàn phím chuột Logitech']);
                $insPay->execute(['REC-20261022-001', 3, 4, 'RECEIVE', 32000000, 'BANK_TRANSFER', 'PAID', 1, 'Thu tiền cung cấp thiết bị IT cho FPT']);
                $insPay->execute(['REC-20261023-002', null, 5, 'RECEIVE', 28000000, 'BANK_TRANSFER', 'PAID', 1, 'Tạm ứng hợp đồng trang thiết bị VP với VNG']);
            }
        } catch (\Throwable $e) {}
    }
}
