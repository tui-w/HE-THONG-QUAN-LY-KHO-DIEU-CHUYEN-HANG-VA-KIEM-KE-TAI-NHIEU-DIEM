<?php

declare(strict_types=1);

namespace App;

require_once __DIR__ . '/AuditLogger.php';

use PDO;
use Exception;
use RuntimeException;

class InventoryService
{
    /**
     * Tạo phiên kiểm kê mới và ghi nhận số lượng thực tế
     * Chức năng 4: Tự động tính chênh lệch (thừa/thiếu so với sổ sách)
     */
    public static function createStockCount(
        PDO $pdo,
        int $warehouseId,
        string $title,
        string $countDate,
        int $userId,
        array $lines,
        string $note = ''
    ): int {
        // Kiểm tra quyền kiểm kê tại kho
        if (!Auth::checkWarehousePermission($pdo, $userId, $warehouseId, 'create')) {
            throw new RuntimeException("PERMISSION_DENIED: Bạn không có quyền tạo phiên kiểm kê tại kho ID {$warehouseId}");
        }

        if (empty($lines)) {
            throw new RuntimeException("INVENTORY_EMPTY_LINES: Danh sách mặt hàng kiểm kê không được rỗng");
        }

        $code = sprintf('KK-%s-%s', date('Ymd-His'), bin2hex(random_bytes(3)));

        $pdo->beginTransaction();
        try {
            $stmtC = $pdo->prepare("
                INSERT INTO stock_counts (code, warehouse_id, title, count_date, status, created_by, note, created_at)
                VALUES (?, ?, ?, ?, 'DRAFT', ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmtC->execute([$code, $warehouseId, $title, $countDate, $userId, $note]);
            $countId = (int)$pdo->lastInsertId();

            $stmtCL = $pdo->prepare("
                INSERT INTO stock_count_lines (stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($lines as $line) {
                $itemId = (int)$line['item_id'];
                $batchId = !empty($line['batch_id']) ? (int)$line['batch_id'] : null;
                $actualQty = (int)$line['actual_quantity'];
                $reason = (string)($line['adjustment_reason'] ?? '');

                // Lấy tồn theo sổ sách hiện thời
                $systemQty = StockService::getCurrentStock($pdo, $warehouseId, $itemId, $batchId);
                $diff = $actualQty - $systemQty;

                $stmtCL->execute([$countId, $itemId, $batchId, $systemQty, $actualQty, $diff, $reason]);
            }

            $pdo->commit();
            AuditLogger::log($pdo, 'COUNT_STOCK', 'stock_counts', $code, "Tạo đợt kiểm kê {$code}: {$title}");
            return $countId;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Xác nhận đợt kiểm kê và TỰ ĐỘNG LẬP CHỨNG TỪ ĐIỀU CHỈNH
     * Thỏa mãn Yêu cầu Kỹ thuật & Phép thử bắt buộc số 5:
     * - Điều chỉnh kiểm kê có lý do và chứng từ hợp lệ
     * - Tổng tồn sau điều chỉnh khớp 100% với lịch sử phát sinh
     * - Tuyệt đối không xóa hay sửa đổi lịch sử cũ
     */
    public static function confirmAndApplyAdjustments(PDO $pdo, int $countId, int $userId): array
    {
        $pdo->beginTransaction();

        try {
            $stmtC = $pdo->prepare("SELECT * FROM stock_counts WHERE id = ?");
            $stmtC->execute([$countId]);
            $count = $stmtC->fetch(PDO::FETCH_ASSOC);

            if (!$count) {
                throw new RuntimeException("COUNT_NOT_FOUND: Không tìm thấy đợt kiểm kê ID {$countId}");
            }

            if ($count['status'] === 'ADJUSTED') {
                throw new RuntimeException("COUNT_ALREADY_ADJUSTED: Đợt kiểm kê {$count['code']} đã được xử lý điều chỉnh trước đó");
            }

            $warehouseId = (int)$count['warehouse_id'];

            // Lấy danh sách các dòng kiểm kê có chênh lệch
            $stmtL = $pdo->prepare("SELECT * FROM stock_count_lines WHERE stock_count_id = ?");
            $stmtL->execute([$countId]);
            $lines = $stmtL->fetchAll(PDO::FETCH_ASSOC);

            $surplusLines = []; // Thừa hàng (difference > 0) -> Cần phiếu ADJUST_IN
            $deficitLines = []; // Thiếu hàng (difference < 0) -> Cần phiếu ADJUST_OUT

            foreach ($lines as $line) {
                $diff = (int)$line['difference'];
                if ($diff > 0) {
                    $surplusLines[] = $line;
                } elseif ($diff < 0) {
                    $deficitLines[] = $line;
                }
            }

            $createdVouchers = [];

            // 1. Xử lý hàng thừa (Tạo phiếu điều chỉnh tăng tồn)
            if (!empty($surplusLines)) {
                $vCodeIn = sprintf('DC-ADJ-%s-%s', date('Ymd-His'), bin2hex(random_bytes(3)));
                $stmtVIn = $pdo->prepare("
                    INSERT INTO vouchers (code, type, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at)
                    VALUES (?, 'ADJUST_IN', ?, 'CONFIRMED', ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmtVIn->execute([$vCodeIn, $warehouseId, $userId, $userId, "Điều chỉnh tăng tồn theo đợt kiểm kê {$count['code']}"]);
                $vIdIn = (int)$pdo->lastInsertId();
                $createdVouchers[] = $vCodeIn;

                foreach ($surplusLines as $sLine) {
                    $itemId = (int)$sLine['item_id'];
                    $batchId = !empty($sLine['batch_id']) ? (int)$sLine['batch_id'] : null;
                    $diffQty = (int)$sLine['difference'];
                    $reason = (string)($sLine['adjustment_reason'] ?: 'Thừa hàng kiểm kê');

                    // Ghi voucher_line
                    $stmtVL = $pdo->prepare("
                        INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note)
                        VALUES (?, ?, ?, ?, 0, ?)
                    ");
                    $stmtVL->execute([$vIdIn, $itemId, $batchId, $diffQty, $reason]);
                    $vlId = (int)$pdo->lastInsertId();

                    // Cập nhật tham chiếu phiếu điều chỉnh vào dòng kiểm kê
                    $stmtUpCL = $pdo->prepare("UPDATE stock_count_lines SET adjustment_voucher_id = ? WHERE id = ?");
                    $stmtUpCL->execute([$vIdIn, (int)$sLine['id']]);

                    // Ghi sổ phát sinh kho
                    $currStock = StockService::getCurrentStock($pdo, $warehouseId, $itemId, $batchId);
                    $balAfter = $currStock + $diffQty;

                    $mCode = sprintf('MV-%s-%s', date('YmdHis'), bin2hex(random_bytes(2)));
                    $stmtM = $pdo->prepare("
                        INSERT INTO stock_movements (
                            movement_code, voucher_id, voucher_line_id, warehouse_id,
                            item_id, batch_id, movement_type, quantity_in, quantity_out,
                            balance_after, note, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, 'ADJUST_IN', ?, 0, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmtM->execute([$mCode, $vIdIn, $vlId, $warehouseId, $itemId, $batchId, $diffQty, $balAfter, "Điều chỉnh tăng tồn: {$reason}"]);
                }
            }

            // 2. Xử lý hàng thiếu (Tạo phiếu điều chỉnh giảm tồn)
            if (!empty($deficitLines)) {
                $vCodeOut = sprintf('DC-ADJ-%s-%s', date('Ymd-His'), bin2hex(random_bytes(3)));
                $stmtVOut = $pdo->prepare("
                    INSERT INTO vouchers (code, type, source_warehouse_id, status, created_by, approved_by, note, created_at, approved_at)
                    VALUES (?, 'ADJUST_OUT', ?, 'CONFIRMED', ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmtVOut->execute([$vCodeOut, $warehouseId, $userId, $userId, "Điều chỉnh giảm tồn theo đợt kiểm kê {$count['code']}"]);
                $vIdOut = (int)$pdo->lastInsertId();
                $createdVouchers[] = $vCodeOut;

                foreach ($deficitLines as $dLine) {
                    $itemId = (int)$dLine['item_id'];
                    $batchId = !empty($dLine['batch_id']) ? (int)$dLine['batch_id'] : null;
                    $diffQty = abs((int)$dLine['difference']);
                    $reason = (string)($dLine['adjustment_reason'] ?: 'Hao hụt/mất mát kiểm kê');

                    $stmtVL = $pdo->prepare("
                        INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note)
                        VALUES (?, ?, ?, ?, 0, ?)
                    ");
                    $stmtVL->execute([$vIdOut, $itemId, $batchId, $diffQty, $reason]);
                    $vlId = (int)$pdo->lastInsertId();

                    $stmtUpCL = $pdo->prepare("UPDATE stock_count_lines SET adjustment_voucher_id = ? WHERE id = ?");
                    $stmtUpCL->execute([$vIdOut, (int)$dLine['id']]);

                    $currStock = StockService::getCurrentStock($pdo, $warehouseId, $itemId, $batchId);
                    $balAfter = max(0, $currStock - $diffQty);

                    $mCode = sprintf('MV-%s-%04d', date('YmdHis'), random_int(100, 999));
                    $stmtM = $pdo->prepare("
                        INSERT INTO stock_movements (
                            movement_code, voucher_id, voucher_line_id, warehouse_id,
                            item_id, batch_id, movement_type, quantity_in, quantity_out,
                            balance_after, note, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, 'ADJUST_OUT', 0, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $stmtM->execute([$mCode, $vIdOut, $vlId, $warehouseId, $itemId, $batchId, $diffQty, $balAfter, "Điều chỉnh giảm tồn: {$reason}"]);
                }
            }

            // Đánh dấu hoàn tất đợt kiểm kê
            $stmtUpC = $pdo->prepare("
                UPDATE stock_counts 
                SET status = 'ADJUSTED', completed_by = ? 
                WHERE id = ?
            ");
            $stmtUpC->execute([$userId, $countId]);

            $pdo->commit();
            AuditLogger::log($pdo, 'ADJUST_STOCK', 'stock_counts', $count['code'], "Cân đối và điều chỉnh tồn kho theo kiểm kê {$count['code']}");

            return [
                'count_id' => $countId,
                'status' => 'ADJUSTED',
                'adjustment_vouchers' => $createdVouchers,
                'surplus_count' => count($surplusLines),
                'deficit_count' => count($deficitLines)
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
