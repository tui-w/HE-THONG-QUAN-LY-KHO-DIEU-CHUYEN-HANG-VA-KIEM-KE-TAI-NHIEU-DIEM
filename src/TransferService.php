<?php

declare(strict_types=1);

namespace App;

require_once __DIR__ . '/AuditLogger.php';

use PDO;
use Exception;
use RuntimeException;

class TransferService
{
    /**
     * Thực hiện điều chuyển hàng giữa hai kho trong CÙNG MỘT GIAO DỊCH DATABASE (ACID Transaction)
     * Thỏa mãn Yêu cầu Kỹ thuật 2 & Phép thử bắt buộc số 2:
     * - Trừ kho xuất và cộng kho nhập đồng thời trong một giao dịch
     * - Nếu xảy ra lỗi giữa chừng (ví dụ: mô phỏng ngắt kết nối/crash), giao dịch ROLLBACK hoàn toàn
     * - Không làm mất hoặc nhân đôi hàng, số tồn luôn khớp chính xác với sổ phát sinh
     */
    public static function executeTransfer(
        PDO $pdo,
        int $sourceWhId,
        int $destWhId,
        array $lines,
        int $userId,
        string $note = '',
        bool $simulateErrorBetweenSteps = false
    ): array {
        if ($sourceWhId === $destWhId) {
            throw new RuntimeException("INVALID_TRANSFER: Kho xuất và kho nhập không được trùng nhau");
        }

        if (empty($lines)) {
            throw new RuntimeException("TRANSFER_EMPTY_LINES: Danh sách hàng điều chuyển không được rỗng");
        }

        // Kiểm tra quyền kho xuất (Phép thử 4)
        if (!Auth::checkWarehousePermission($pdo, $userId, $sourceWhId, 'create')) {
            throw new RuntimeException("PERMISSION_DENIED: Bạn không có quyền lập phiếu xuất điều chuyển tại kho ID {$sourceWhId}");
        }

        $code = sprintf('DC-%s-%s', date('Ymd-His'), bin2hex(random_bytes(3)));

        $pdo->beginTransaction();

        try {
            // 1. Kiểm tra tồn kho xuất cho tất cả các mặt hàng trước khi ghi nhận
            foreach ($lines as $line) {
                $itemId = (int)$line['item_id'];
                $batchId = !empty($line['batch_id']) ? (int)$line['batch_id'] : null;
                $qty = (int)$line['quantity'];

                if ($qty <= 0) {
                    throw new RuntimeException("INVALID_QUANTITY: Số lượng chuyển phải > 0");
                }

                $available = StockService::getCurrentStock($pdo, $sourceWhId, $itemId, $batchId);
                if ($available < $qty) {
                    throw new RuntimeException(
                        "INSUFFICIENT_STOCK: Kho nguồn (ID {$sourceWhId}) không đủ tồn kho cho sản phẩm ID {$itemId}. Hiện có {$available}, yêu cầu chuyển {$qty}!"
                    );
                }
            }

            // 2. Tạo chứng từ điều chuyển
            $stmtV = $pdo->prepare("
                INSERT INTO vouchers (code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at)
                VALUES (?, 'TRANSFER', ?, ?, 'CONFIRMED', ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmtV->execute([$code, $sourceWhId, $destWhId, $userId, $userId, $note]);
            $voucherId = (int)$pdo->lastInsertId();

            $sourceMovements = [];
            $destMovements = [];

            // 3. BƯỚC 1: Trừ kho xuất (TRANSFER_OUT)
            foreach ($lines as $line) {
                $itemId = (int)$line['item_id'];
                $batchId = !empty($line['batch_id']) ? (int)$line['batch_id'] : null;
                $qty = (int)$line['quantity'];
                $price = (float)($line['unit_price'] ?? 0);

                // Ghi voucher line
                $stmtVL = $pdo->prepare("
                    INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmtVL->execute([$voucherId, $itemId, $batchId, $qty, $price, "Điều chuyển sang kho ID {$destWhId}"]);
                $lineId = (int)$pdo->lastInsertId();

                $currBalSource = StockService::getCurrentStock($pdo, $sourceWhId, $itemId, $batchId);
                $balAfterSource = $currBalSource - $qty;

                $mCodeOut = sprintf('MV-%s-%04d', date('YmdHis'), random_int(100, 999));
                $stmtMOut = $pdo->prepare("
                    INSERT INTO stock_movements (
                        movement_code, voucher_id, voucher_line_id, warehouse_id,
                        item_id, batch_id, movement_type, quantity_in, quantity_out,
                        balance_after, note, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 'TRANSFER_OUT', 0, ?, ?, ?, CURRENT_TIMESTAMP)
                ");
                $stmtMOut->execute([
                    $mCodeOut,
                    $voucherId,
                    $lineId,
                    $sourceWhId,
                    $itemId,
                    $batchId,
                    $qty,
                    $balAfterSource,
                    "Xuất điều chuyển {$code} sang kho ID {$destWhId}"
                ]);
                $sourceMovements[] = (int)$pdo->lastInsertId();
            }

            // 4. KIỂM THỬ ĐẶC BIỆT: MÔ PHỎNG LỖI GIỮA HAI THAO TÁC (Phép thử bắt buộc số 2)
            if ($simulateErrorBetweenSteps) {
                throw new RuntimeException("SIMULATED_TRANSFER_CRASH_MIDWAY: Lỗi mô phỏng ngắt kết nối giữa lúc trừ kho A và cộng kho B!");
            }

            // 5. BƯỚC 2: Cộng kho nhập (TRANSFER_IN)
            foreach ($lines as $idx => $line) {
                $itemId = (int)$line['item_id'];
                $batchId = !empty($line['batch_id']) ? (int)$line['batch_id'] : null;
                $qty = (int)$line['quantity'];

                $currBalDest = StockService::getCurrentStock($pdo, $destWhId, $itemId, $batchId);
                $balAfterDest = $currBalDest + $qty;

                $mCodeIn = sprintf('MV-%s-%04d', date('YmdHis'), random_int(100, 999));
                $stmtMIn = $pdo->prepare("
                    INSERT INTO stock_movements (
                        movement_code, voucher_id, voucher_line_id, warehouse_id,
                        item_id, batch_id, movement_type, quantity_in, quantity_out,
                        balance_after, note, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 'TRANSFER_IN', ?, 0, ?, ?, CURRENT_TIMESTAMP)
                ");
                $stmtMIn->execute([
                    $mCodeIn,
                    $voucherId,
                    null,
                    $destWhId,
                    $itemId,
                    $batchId,
                    $qty,
                    $balAfterDest,
                    "Nhập điều chuyển {$code} từ kho ID {$sourceWhId}"
                ]);
                $destMovements[] = (int)$pdo->lastInsertId();
            }

            // Hoàn tất cả 2 đầu kho trong cùng transaction
            $pdo->commit();
            AuditLogger::log($pdo, 'TRANSFER_STOCK', 'vouchers', $code, "Điều chuyển hàng từ kho ID {$sourceWhId} sang kho ID {$destWhId} thành công");

            return [
                'voucher_id' => $voucherId,
                'code' => $code,
                'status' => 'CONFIRMED',
                'source_movements' => $sourceMovements,
                'dest_movements' => $destMovements,
            ];
        } catch (Exception $e) {
            // ROLLBACK nếu có bất kỳ lỗi nào xảy ra ở bất kỳ bước nào
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
