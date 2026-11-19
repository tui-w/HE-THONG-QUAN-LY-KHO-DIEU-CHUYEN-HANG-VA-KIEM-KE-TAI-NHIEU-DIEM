<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/StockService.php';
require_once __DIR__ . '/../src/TransferService.php';
require_once __DIR__ . '/../src/InventoryService.php';
require_once __DIR__ . '/../src/ReportService.php';
require_once __DIR__ . '/../src/Seeder.php';

use App\Database;
use App\StockService;
use App\TransferService;
use App\InventoryService;
use App\ReportService;
use App\Seeder;

echo "========================================================\n";
echo "   BỘ KIỂM THỬ MỞ RỘNG (EXTENDED TESTS) - ĐỀ TÀI 10\n";
echo "   Kiểm thử Chuyên sâu Nghiệp vụ Kho & Báo cáo Đối chiếu\n";
echo "========================================================\n\n";

$testDbFile = __DIR__ . '/../.local/test_extension.sqlite';
if (file_exists($testDbFile)) {
    unlink($testDbFile);
}

$pdo = Database::getConnection($testDbFile);
Database::initSchema($pdo);
$seedResult = Seeder::run($pdo);

$pass = 0;
$fail = 0;

function check(bool $cond, string $title, string $desc = ''): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] {$title}\n";
        if ($desc) echo "         -> {$desc}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$title}\n";
        if ($desc) echo "         -> {$desc}\n";
    }
}

// 1. Kiểm tra toàn vẹn dữ liệu mẫu ban đầu
check($seedResult['warehouses'] >= 3, "Có ít nhất 3 kho hàng", "Số kho: {$seedResult['warehouses']}");
check($seedResult['items'] >= 30, "Có ít nhất 30 mặt hàng trong danh mục", "Số mặt hàng: {$seedResult['items']}");
check($seedResult['movements'] >= 200, "Có trên 200 dòng phát sinh thực tế", "Số phát sinh: {$seedResult['movements']}");

// 2. Kiểm thử thẻ kho tính đúng số dư lũy kế theo lô hàng
$batches = $pdo->query("SELECT id, item_id, batch_number FROM batches WHERE item_id = 1")->fetchAll();
$firstBatchId = (int)$batches[0]['id'];
$stockCard = ReportService::getStockCard($pdo, 1, 1, $firstBatchId);
$cardValid = true;
$calculatedBalance = 0;
foreach ($stockCard as $row) {
    $calculatedBalance += ((int)$row['quantity_in'] - (int)$row['quantity_out']);
    if ($calculatedBalance !== (int)$row['balance_after']) {
        $cardValid = false;
        break;
    }
}
check($cardValid && count($stockCard) > 0, "Sổ thẻ kho tính đúng số dư lũy kế từng dòng theo lô", "Đã kiểm tra " . count($stockCard) . " giao dịch thẻ kho");

// 3. Kiểm thử phân bổ theo lô hàng (Batch Tracking)
$batches = $pdo->query("SELECT id, item_id, batch_number FROM batches WHERE item_id = 1")->fetchAll();
check(count($batches) >= 2, "Hỗ trợ theo dõi mặt hàng theo nhiều lô khác nhau", "Sản phẩm 1 có " . count($batches) . " lô");

// 4. Kiểm thử tổng tồn toàn hệ thống không thay đổi khi điều chuyển nội bộ
$totalStockBefore = (int)$pdo->query("SELECT COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) FROM stock_movements WHERE item_id = 1")->fetchColumn();
TransferService::executeTransfer($pdo, 1, 2, [['item_id' => 1, 'quantity' => 2]], 1, 'Chuyển kiểm thử bảo toàn tổng tồn');
$totalStockAfter = (int)$pdo->query("SELECT COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) FROM stock_movements WHERE item_id = 1")->fetchColumn();
check($totalStockBefore === $totalStockAfter, "Tổng tồn toàn hệ thống được bảo toàn khi điều chuyển nội bộ", "Tổng tồn trước: {$totalStockBefore}, sau: {$totalStockAfter}");

// 5. Kiểm thử báo cáo tổng hợp tồn kho
$summary = ReportService::getStockSummary($pdo, 1);
check(count($summary) >= 30, "Báo cáo tồn kho hiển thị đầy đủ danh mục", "Kho Hà Nội có " . count($summary) . " dòng tồn kho");

echo "\n--------------------------------------------------------\n";
echo "KẾT QUẢ MỞ RỘNG: TESTS={$pass} FAILED={$fail}\n";
echo "--------------------------------------------------------\n";

exit($fail > 0 ? 1 : 0);
