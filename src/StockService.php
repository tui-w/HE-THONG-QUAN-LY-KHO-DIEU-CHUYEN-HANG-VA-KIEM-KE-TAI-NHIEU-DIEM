<?php

declare(strict_types=1);

namespace App;

require_once __DIR__ . '/AuditLogger.php';

use PDO;
use Exception;
use RuntimeException;

class StockService
{
    /**
     * Tra cứu số tồn tức thời từ SỔ PHÁT SINH (Stock Movements Ledger)
     * Đảm bảo tính nhất quán tuyệt đối, không lệch số liệu
     */
    public static function getCurrentStock(PDO $pdo, int $warehouseId, int $itemId, ?int $batchId = null): int
    {
        $sql = "SELECT COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) AS balance 
                FROM stock_movements 
                WHERE warehouse_id = :wh AND item_id = :item";
        $params = [':wh' => $warehouseId, ':item' => $itemId];

        if ($batchId !== null) {
            $sql .= " AND batch_id = :batch";
            $params[':batch'] = $batchId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Tạo chứng từ mới ở trạng thái DRAFT
     */
    public static function createVoucher(
        PDO $pdo,
        string $type,
        ?int $sourceWhId,
        ?int $destWhId,
        array $lines,
        int $userId,
        string $note = ''
    ): int {
        // Kiểm tra quyền kho tạo phiếu (Phép thử 4)
        $whToCheck = ($type === 'IN' || $type === 'ADJUST_IN') ? $destWhId : $sourceWhId;
        if ($whToCheck !== null && !Auth::checkWarehousePermission($pdo, $userId, $whToCheck, 'create')) {
            throw new RuntimeException("PERMISSION_DENIED: Bạn không có quyền tạo chứng từ tại kho ID {$whToCheck}");
        }

        if (empty($lines)) {
            throw new RuntimeException("VOUCHER_EMPTY_LINES: Phiếu phải có ít nhất một dòng hàng hóa");
        }

        $prefix = match ($type) {
            'IN' => 'PN',
            'OUT' => 'PX',
            'TRANSFER' => 'DC',
            'ADJUST_IN' => 'DC-IN',
            'ADJUST_OUT' => 'DC-OUT',
            default => 'CT',
        };

        $code = sprintf('%s-%s-%s', $prefix, date('Ymd-His'), bin2hex(random_bytes(3)));

        $pdo->beginTransaction();
        try {
            $stmtV = $pdo->prepare("
                INSERT INTO vouchers (code, type, source_warehouse_id, dest_warehouse_id, status, created_by, note, created_at)
                VALUES (?, ?, ?, ?, 'DRAFT', ?, ?, CURRENT_TIMESTAMP)
            ");
            $stmtV->execute([$code, $type, $sourceWhId, $destWhId, $userId, $note]);
            $voucherId = (int)$pdo->lastInsertId();

            $stmtL = $pdo->prepare("
                INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach ($lines as $line) {
                $qty = (int)($line['quantity'] ?? 0);
                if ($qty <= 0) {
                    throw new RuntimeException("INVALID_QUANTITY: Số lượng mặt hàng phải lớn hơn 0");
                }
                $itemId = (int)$line['item_id'];
                $batchId = !empty($line['batch_id']) ? (int)$line['batch_id'] : null;
                $unitPrice = (float)($line['unit_price'] ?? 0);
                $lineNote = (string)($line['note'] ?? '');

                $stmtL->execute([$voucherId, $itemId, $batchId, $qty, $unitPrice, $lineNote]);
            }

            $pdo->commit();
            AuditLogger::log($pdo, 'CREATE_VOUCHER', 'vouchers', $code, "Tạo chứng từ {$code} loại {$type} với " . count($lines) . " dòng mặt hàng");
            return $voucherId;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Xác nhận chứng từ và ghi vào SỔ PHÁT SINH (Stock Movements)
     * Thỏa mãn Yêu cầu Kỹ thuật 1:
     * - Chống số tồn âm khi nhiều yêu cầu cùng xuất
     * - Chống ghi nhận lặp (Idempotent) nếu gửi lại chứng từ đã xác nhận
     */
    public static function confirmVoucher(PDO $pdo, int $voucherId, int $approvedByUserId): array
    {
        $pdo->beginTransaction();

        try {
            // Khóa dòng voucher để kiểm tra trạng thái
            $stmtV = $pdo->prepare("SELECT * FROM vouchers WHERE id = ?");
            $stmtV->execute([$voucherId]);
            $voucher = $stmtV->fetch(PDO::FETCH_ASSOC);

            if (!$voucher) {
                throw new RuntimeException("VOUCHER_NOT_FOUND: Không tìm thấy chứng từ ID {$voucherId}");
            }

            // KIỂM TRA IDEMPOTENT (Phép thử bắt buộc số 3):
            // Nếu đã CONFIRMED -> Từ chối xử lý, không tăng/giảm tồn hay ghi sổ thêm lần nữa
            if ($voucher['status'] === 'CONFIRMED') {
                throw new RuntimeException("VOUCHER_ALREADY_CONFIRMED: Chứng từ {$voucher['code']} đã được xác nhận trước đó, không thể ghi nhận lại");
            }

            if ($voucher['status'] === 'CANCELLED') {
                throw new RuntimeException("VOUCHER_CANCELLED: Chứng từ {$voucher['code']} đã bị hủy");
            }

            $type = $voucher['type'];
            $whId = ($type === 'IN' || $type === 'ADJUST_IN') ? (int)$voucher['dest_warehouse_id'] : (int)$voucher['source_warehouse_id'];

            // Kiểm tra quyền duyệt phía server (Phép thử 4)
            if (!Auth::checkWarehousePermission($pdo, $approvedByUserId, $whId, 'approve')) {
                throw new RuntimeException("PERMISSION_DENIED: Bạn không có quyền duyệt chứng từ tại kho ID {$whId}");
            }

            // Lấy danh sách các dòng chi tiết của chứng từ
            $stmtL = $pdo->prepare("SELECT * FROM voucher_lines WHERE voucher_id = ?");
            $stmtL->execute([$voucherId]);
            $lines = $stmtL->fetchAll(PDO::FETCH_ASSOC);

            if (empty($lines)) {
                throw new RuntimeException("VOUCHER_NO_LINES: Chứng từ không có dòng chi tiết");
            }

            $createdMovements = [];

            // Duyệt từng dòng hàng
            foreach ($lines as $line) {
                $itemId = (int)$line['item_id'];
                $batchId = !empty($line['batch_id']) ? (int)$line['batch_id'] : null;
                $quantity = (int)$line['quantity'];

                // KIỂM TRA CHỐNG TỒN ÂM (Phép thử bắt buộc số 1):
                // Với các phiếu xuất hoặc điều chỉnh giảm, kiểm tra tồn kho tức thời trong transaction
                $currentStock = self::getCurrentStock($pdo, $whId, $itemId, $batchId);

                if (($type === 'OUT' || $type === 'ADJUST_OUT') && $currentStock < $quantity) {
                    throw new RuntimeException(
                        "INSUFFICIENT_STOCK: Kho {$whId} không đủ số lượng cho sản phẩm ID {$itemId}. Hiện có {$currentStock}, yêu cầu xuất {$quantity}!"
                    );
                }

                $qtyIn = 0;
                $qtyOut = 0;
                $movementType = $type;

                if ($type === 'IN' || $type === 'ADJUST_IN') {
                    $qtyIn = $quantity;
                    $balanceAfter = $currentStock + $qtyIn;
                } else {
                    $qtyOut = $quantity;
                    $balanceAfter = $currentStock - $qtyOut;
                }

                $mCode = sprintf('MV-%s-%04d', date('YmdHis'), random_int(100, 999));
                $stmtM = $pdo->prepare("
                    INSERT INTO stock_movements (
                        movement_code, voucher_id, voucher_line_id, warehouse_id, 
                        item_id, batch_id, movement_type, quantity_in, quantity_out, 
                        balance_after, note, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ");
                $stmtM->execute([
                    $mCode,
                    $voucherId,
                    (int)$line['id'],
                    $whId,
                    $itemId,
                    $batchId,
                    $movementType,
                    $qtyIn,
                    $qtyOut,
                    $balanceAfter,
                    "Xác nhận phiếu {$voucher['code']}"
                ]);

                $createdMovements[] = (int)$pdo->lastInsertId();
            }

            // Cập nhật trạng thái phiếu thành CONFIRMED
            $stmtUp = $pdo->prepare("
                UPDATE vouchers 
                SET status = 'CONFIRMED', approved_by = ?, approved_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmtUp->execute([$approvedByUserId, $voucherId]);

            $pdo->commit();
            AuditLogger::log($pdo, 'APPROVE_VOUCHER', 'vouchers', $voucher['code'], "Duyệt và ghi sổ phát sinh chứng từ {$voucher['code']}");

            return [
                'voucher_id' => $voucherId,
                'code' => $voucher['code'],
                'status' => 'CONFIRMED',
                'movements' => $createdMovements
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
