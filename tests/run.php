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
use App\Auth;
use App\StockService;
use App\TransferService;
use App\InventoryService;
use App\ReportService;
use App\Seeder;

echo "========================================================\n";
echo "   KIỂM THỬ TỰ ĐỘNG BẮT BUỘC - ĐỀ TÀI 10 (FIT-DNU)\n";
echo "   Hệ thống Quản lý kho, Điều chuyển và Kiểm kê đa điểm\n";
echo "========================================================\n\n";

// Sử dụng database test độc lập trong .local/test_warehouse.sqlite
$testDbFile = __DIR__ . '/../.local/test_warehouse.sqlite';
if (file_exists($testDbFile)) {
    unlink($testDbFile);
}

$pdo = Database::getConnection($testDbFile);
Database::initSchema($pdo);
$seedResult = Seeder::run($pdo);

echo "[CHUẨN BỊ] Khởi tạo CSDL kiểm thử thành công: " . count($pdo->query("SELECT id FROM warehouses")->fetchAll()) . " kho, " . count($pdo->query("SELECT id FROM items")->fetchAll()) . " mặt hàng, {$seedResult['movements']} dòng phát sinh.\n\n";

$passedTests = 0;
$failedTests = 0;

function assertTest(bool $condition, string $testName, string $detail = ''): void {
    global $passedTests, $failedTests;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$testName}\n";
        if ($detail) {
            echo "         -> {$detail}\n";
        }
    } else {
        $failedTests++;
        echo "  [FAIL] {$testName}\n";
        if ($detail) {
            echo "         -> LỖI: {$detail}\n";
        }
    }
}

// Lấy user IDs và Warehouse IDs mẫu
$users = $pdo->query("SELECT id, username FROM users")->fetchAll(PDO::FETCH_KEY_PAIR);
$userMap = array_flip($users);
if (isset($userMap['tuoi']) && !isset($userMap['admin'])) {
    $userMap['admin'] = $userMap['tuoi'];
}
$whs = $pdo->query("SELECT code, id FROM warehouses")->fetchAll(PDO::FETCH_KEY_PAIR);

$whHN = (int)$whs['KHO-HN'];
$whDN = (int)$whs['KHO-DN'];
$whHCM = (int)$whs['KHO-HCM'];

// =========================================================================
// PHÉP THỬ 1: Kho còn 5 sản phẩm, hai yêu cầu cùng xuất 4:
//              chỉ một phiếu được ghi nhận, số tồn không âm.
// =========================================================================
echo "PHÉP THỬ 1: Chống tồn âm & Xử lý cạnh tranh khi xuất hàng...\n";
try {
    // 1. Tạo 1 mặt hàng kiểm thử riêng biệt để kiểm soát tuyệt đối số lượng
    $stmtNewItem = $pdo->prepare("INSERT INTO items (code, name, category_id, unit, min_stock) VALUES ('TEST-ITEM-1', 'Sản phẩm Test Concurrency', 1, 'Cái', 0)");
    $stmtNewItem->execute();
    $testItemId = (int)$pdo->lastInsertId();

    // 2. Nhập chính xác 5 sản phẩm vào Kho Hà Nội
    $initVoucherId = StockService::createVoucher($pdo, 'IN', null, $whHN, [
        ['item_id' => $testItemId, 'quantity' => 5, 'unit_price' => 100000]
    ], $userMap['admin'], 'Nhập kho đúng 5 sản phẩm phục vụ Test 1');
    StockService::confirmVoucher($pdo, $initVoucherId, $userMap['manager']);

    $stockBefore = StockService::getCurrentStock($pdo, $whHN, $testItemId);
    assertTest($stockBefore === 5, "Thiết lập tồn ban đầu = 5", "Tồn hiện tại: {$stockBefore}");

    // 3. Tạo 2 phiếu xuất (mỗi phiếu yêu cầu 4 sản phẩm)
    $v1 = StockService::createVoucher($pdo, 'OUT', $whHN, null, [
        ['item_id' => $testItemId, 'quantity' => 4, 'unit_price' => 120000]
    ], $userMap['kho_hn'], 'Yêu cầu xuất 4 lần thứ nhất');

    $v2 = StockService::createVoucher($pdo, 'OUT', $whHN, null, [
        ['item_id' => $testItemId, 'quantity' => 4, 'unit_price' => 120000]
    ], $userMap['kho_hn'], 'Yêu cầu xuất 4 lần thứ hai');

    // 4. Xác nhận phiếu 1 -> Phải thành công, tồn còn 1
    $v1Res = StockService::confirmVoucher($pdo, $v1, $userMap['manager']);
    $stockAfterV1 = StockService::getCurrentStock($pdo, $whHN, $testItemId);
    assertTest($stockAfterV1 === 1, "Phiếu thứ nhất xuất 4 thành công", "Tồn giảm từ 5 xuống {$stockAfterV1}");

    // 5. Xác nhận phiếu 2 -> Phải thất bại với ngoại lệ INSUFFICIENT_STOCK
    $v2FailedWithException = false;
    $v2ErrorMsg = '';
    try {
        StockService::confirmVoucher($pdo, $v2, $userMap['manager']);
    } catch (\RuntimeException $e) {
        $v2FailedWithException = true;
        $v2ErrorMsg = $e->getMessage();
    }

    $stockAfterV2 = StockService::getCurrentStock($pdo, $whHN, $testItemId);
    assertTest(
        $v2FailedWithException && str_contains($v2ErrorMsg, 'INSUFFICIENT_STOCK') && $stockAfterV2 === 1,
        "Phiếu thứ hai bị từ chối, tồn kho không bao giờ âm",
        "Tồn kho cuối cùng = {$stockAfterV2} (Không thể âm -3). Bắt lỗi: {$v2ErrorMsg}"
    );
} catch (\Exception $e) {
    assertTest(false, "Phép thử 1 bị crash bất thường", $e->getMessage());
}
echo "\n";

// =========================================================================
// PHÉP THỬ 2: Gây lỗi giữa lúc trừ kho A và cộng kho B:
//              không có thay đổi dở dang (Rollback hoàn toàn).
// =========================================================================
echo "PHÉP THỬ 2: Điều chuyển nguyên tử (ACID Transaction) & Rollback khi lỗi...\n";
try {
    // Mặt hàng test
    $stmtNewItem2 = $pdo->prepare("INSERT INTO items (code, name, category_id, unit, min_stock) VALUES ('TEST-ITEM-2', 'Sản phẩm Test Transfer Crash', 1, 'Cái', 0)");
    $stmtNewItem2->execute();
    $testItemId2 = (int)$pdo->lastInsertId();

    // Nhập vào Kho HN 20 cái, Kho DN 10 cái
    $vInHN = StockService::createVoucher($pdo, 'IN', null, $whHN, [['item_id' => $testItemId2, 'quantity' => 20]], $userMap['admin']);
    StockService::confirmVoucher($pdo, $vInHN, $userMap['manager']);

    $vInDN = StockService::createVoucher($pdo, 'IN', null, $whDN, [['item_id' => $testItemId2, 'quantity' => 10]], $userMap['admin']);
    StockService::confirmVoucher($pdo, $vInDN, $userMap['manager']);

    $stockHNBefore = StockService::getCurrentStock($pdo, $whHN, $testItemId2);
    $stockDNBefore = StockService::getCurrentStock($pdo, $whDN, $testItemId2);
    $movementsBeforeCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

    // Điều chuyển 7 cái từ HN -> DN kèm cờ MÔ PHỎNG LỖI GIỮA CHỪNG
    $crashCaught = false;
    try {
        TransferService::executeTransfer(
            $pdo,
            $whHN,
            $whDN,
            [['item_id' => $testItemId2, 'quantity' => 7]],
            $userMap['manager'],
            'Test mô phỏng lỗi sập nguồn giữa chừng',
            true // simulateErrorBetweenSteps = true
        );
    } catch (\RuntimeException $e) {
        $crashCaught = true;
    }

    $stockHNAfter = StockService::getCurrentStock($pdo, $whHN, $testItemId2);
    $stockDNAfter = StockService::getCurrentStock($pdo, $whDN, $testItemId2);
    $movementsAfterCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

    assertTest(
        $crashCaught && $stockHNAfter === $stockHNBefore && $stockDNAfter === $stockDNBefore && $movementsAfterCount === $movementsBeforeCount,
        "Không có thay đổi dở dang khi xảy ra sự cố giữa hai thao tác",
        "Kho HN giữ nguyên: {$stockHNAfter}/{$stockHNBefore}, Kho DN giữ nguyên: {$stockDNAfter}/{$stockDNBefore}, Số dòng phát sinh không đổi: {$movementsAfterCount}"
    );
} catch (\Exception $e) {
    assertTest(false, "Phép thử 2 bị crash", $e->getMessage());
}
echo "\n";

// =========================================================================
// PHÉP THỬ 3: Gửi lại chứng từ đã xác nhận (Idempotent):
//              số tồn và sổ phát sinh không tăng thêm.
// =========================================================================
echo "PHÉP THỬ 3: Tính Idempotent - Gửi lại chứng từ đã xác nhận...\n";
try {
    // Lấy phiếu v1 đã xác nhận từ Test 1
    $stockBeforeResubmit = StockService::getCurrentStock($pdo, $whHN, $testItemId);
    $movementsBeforeResubmit = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE voucher_id = {$v1}")->fetchColumn();

    $reSubmitCaught = false;
    $reSubmitErrorMsg = '';
    try {
        StockService::confirmVoucher($pdo, $v1, $userMap['manager']);
    } catch (\RuntimeException $e) {
        $reSubmitCaught = true;
        $reSubmitErrorMsg = $e->getMessage();
    }

    $stockAfterResubmit = StockService::getCurrentStock($pdo, $whHN, $testItemId);
    $movementsAfterResubmit = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE voucher_id = {$v1}")->fetchColumn();

    assertTest(
        $reSubmitCaught && str_contains($reSubmitErrorMsg, 'VOUCHER_ALREADY_CONFIRMED') &&
        $stockAfterResubmit === $stockBeforeResubmit &&
        $movementsAfterResubmit === $movementsBeforeResubmit,
        "Gửi lại chứng từ đã xác nhận không làm tăng/giảm tồn hoặc nhân bản phát sinh",
        "Số tồn giữ nguyên: {$stockAfterResubmit}, số dòng phát sinh phiếu: {$movementsAfterResubmit}. Bắt lỗi: {$reSubmitErrorMsg}"
    );
} catch (\Exception $e) {
    assertTest(false, "Phép thử 3 bị crash", $e->getMessage());
}
echo "\n";

// =========================================================================
// PHÉP THỬ 4: Tài khoản chỉ phụ trách kho A không được lập phiếu xuất kho B
//              bằng yêu cầu trực tiếp (Server-side RBAC).
// =========================================================================
echo "PHÉP THỬ 4: Kiểm tra phân quyền theo kho phía máy chủ (Server-side RBAC)...\n";
try {
    // Tài khoản 'kho_hn' chỉ có quyền tại Kho HN ($whHN), KHÔNG có quyền tại Kho DN ($whDN)
    $hasAccessDN = Auth::checkWarehousePermission($pdo, $userMap['kho_hn'], $whDN, 'create');
    assertTest(!$hasAccessDN, "Hệ thống xác định kho_hn không có quyền trên KHO-DN");

    // Giả lập tài khoản 'kho_hn' gửi request POST trực tiếp để tạo phiếu xuất tại Kho DN
    $permissionDeniedCaught = false;
    $deniedMessage = '';
    try {
        StockService::createVoucher($pdo, 'OUT', $whDN, null, [
            ['item_id' => $testItemId2, 'quantity' => 1]
        ], $userMap['kho_hn'], 'Cố tình can thiệp request xuất kho DN trái phép');
    } catch (\RuntimeException $e) {
        $permissionDeniedCaught = true;
        $deniedMessage = $e->getMessage();
    }

    assertTest(
        $permissionDeniedCaught && str_contains($deniedMessage, 'PERMISSION_DENIED'),
        "Server từ chối yêu cầu tạo phiếu trái quyền dù gửi request trực tiếp",
        "Bắt lỗi máy chủ: {$deniedMessage}"
    );
} catch (\Exception $e) {
    assertTest(false, "Phép thử 4 bị crash", $e->getMessage());
}
echo "\n";

// =========================================================================
// PHÉP THỬ 5: Điều chỉnh kiểm kê có lý do và chứng từ;
//              tổng tồn sau điều chỉnh khớp lịch sử phát sinh.
// =========================================================================
echo "PHÉP THỬ 5: Kiểm kê, tạo chứng từ điều chỉnh & Khớp số phát sinh...\n";
try {
    // Sản phẩm Test 5
    $stmtNewItem3 = $pdo->prepare("INSERT INTO items (code, name, category_id, unit, min_stock) VALUES ('TEST-ITEM-3', 'Sản phẩm Test Kiểm kê', 1, 'Hộp', 0)");
    $stmtNewItem3->execute();
    $testItemId3 = (int)$pdo->lastInsertId();

    // Nhập tồn sổ sách 10 hộp vào Kho HCM
    $vInHCM = StockService::createVoucher($pdo, 'IN', null, $whHCM, [['item_id' => $testItemId3, 'quantity' => 10]], $userMap['admin']);
    StockService::confirmVoucher($pdo, $vInHCM, $userMap['manager']);

    $sysStockBefore = StockService::getCurrentStock($pdo, $whHCM, $testItemId3);
    assertTest($sysStockBefore === 10, "Tồn sổ sách trước kiểm kê = 10");

    // Tạo đợt kiểm kê với số thực tế là 14 (Thừa 4 hộp do đối tác giao tặng kèm)
    $countId = InventoryService::createStockCount(
        $pdo,
        $whHCM,
        "Kiểm kê chuyên đề Test 5",
        date('Y-m-d'),
        $userMap['auditor'],
        [
            [
                'item_id' => $testItemId3,
                'actual_quantity' => 14,
                'adjustment_reason' => 'Thừa 4 hộp do hãng tặng thêm trong lô quà khuyến mại'
            ]
        ],
        'Ghi nhận chênh lệch kiểm kê thực tế'
    );

    // Xác nhận và lập chứng từ điều chỉnh tự động
    $adjRes = InventoryService::confirmAndApplyAdjustments($pdo, $countId, $userMap['manager']);

    $stockAfterAdj = StockService::getCurrentStock($pdo, $whHCM, $testItemId3);

    // Đối chiếu trực tiếp với SỔ PHÁT SINH (SUM IN - SUM OUT)
    $stmtLedgerSum = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) 
        FROM stock_movements 
        WHERE warehouse_id = ? AND item_id = ?
    ");
    $stmtLedgerSum->execute([$whHCM, $testItemId3]);
    $ledgerBalance = (int)$stmtLedgerSum->fetchColumn();

    // Kiểm tra chứng từ điều chỉnh có lưu lý do
    $stmtCheckReason = $pdo->prepare("
        SELECT sm.note, v.code 
        FROM stock_movements sm
        JOIN vouchers v ON sm.voucher_id = v.id
        WHERE sm.warehouse_id = ? AND sm.item_id = ? AND sm.movement_type = 'ADJUST_IN'
    ");
    $stmtCheckReason->execute([$whHCM, $testItemId3]);
    $reasonRow = $stmtCheckReason->fetch(PDO::FETCH_ASSOC);

    assertTest(
        $stockAfterAdj === 14 && $ledgerBalance === 14 && $reasonRow !== false && !empty($adjRes['adjustment_vouchers']),
        "Điều chỉnh kiểm kê tự động tạo chứng từ kèm lý do; số tồn khớp 100% sổ phát sinh",
        "Số tồn sau điều chỉnh = {$stockAfterAdj}, Tổng sổ phát sinh = {$ledgerBalance}. Chứng từ: {$reasonRow['code']} ({$reasonRow['note']})"
    );
} catch (\Exception $e) {
    assertTest(false, "Phép thử 5 bị crash", $e->getMessage());
}
echo "\n";

// =========================================================================
// KẾT LUẬN TOÀN BỘ BỘ TEST
// =========================================================================
echo "--------------------------------------------------------\n";
echo "TỔNG KẾT BỘ KIỂM THỬ: TESTS={$passedTests} FAILED={$failedTests}\n";
echo "--------------------------------------------------------\n";

if ($failedTests > 0) {
    exit(1);
} else {
    echo "CHÚC MỪNG: CẢ 5 PHÉP THỬ BẮT BUỘC CỦA ĐỀ TÀI 10 ĐÃ ĐẠT 100%!\n";
    exit(0);
}
