<?php

declare(strict_types=1);

namespace App;

use PDO;

class ReportService
{
    /**
     * Báo cáo tồn kho tổng hợp và chi tiết theo kho, mặt hàng và lô
     */
    public static function getStockSummary(PDO $pdo, ?int $warehouseId = null): array
    {
        $sql = "
            SELECT 
                w.id AS warehouse_id,
                w.code AS warehouse_code,
                w.name AS warehouse_name,
                i.id AS item_id,
                i.code AS item_code,
                i.name AS item_name,
                i.unit,
                i.min_stock,
                c.name AS category_name,
                b.id AS batch_id,
                b.batch_number,
                b.expiry_date,
                COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS current_stock
            FROM items i
            LEFT JOIN item_categories c ON i.category_id = c.id
            CROSS JOIN warehouses w
            LEFT JOIN batches b ON b.item_id = i.id
            LEFT JOIN stock_movements sm ON sm.warehouse_id = w.id 
                                        AND sm.item_id = i.id 
                                        AND (sm.batch_id = b.id OR (sm.batch_id IS NULL AND b.id IS NULL))
        ";

        $params = [];
        if ($warehouseId !== null) {
            $sql .= " WHERE w.id = :wh";
            $params[':wh'] = $warehouseId;
        }

        $sql .= "
            GROUP BY w.id, i.id, b.id
            HAVING current_stock > 0 OR i.min_stock > 0
            ORDER BY w.code, i.code, b.batch_number
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tra cứu SỔ THẺ KHO (Stock Card) cho một mặt hàng tại một kho
     * Phục vụ đối chiếu chi tiết từng phát sinh nhập, xuất, điều chuyển, kiểm kê
     */
    public static function getStockCard(PDO $pdo, int $warehouseId, int $itemId, ?int $batchId = null): array
    {
        $sql = "
            SELECT 
                sm.id,
                sm.movement_code,
                sm.voucher_id,
                v.code AS voucher_code,
                v.type AS voucher_type,
                sm.movement_type,
                sm.quantity_in,
                sm.quantity_out,
                sm.balance_after,
                sm.note,
                sm.created_at,
                u.full_name AS approved_by_name
            FROM stock_movements sm
            JOIN vouchers v ON sm.voucher_id = v.id
            LEFT JOIN users u ON v.approved_by = u.id
            WHERE sm.warehouse_id = :wh AND sm.item_id = :item
        ";
        $params = [':wh' => $warehouseId, ':item' => $itemId];

        if ($batchId !== null) {
            $sql .= " AND sm.batch_id = :batch";
            $params[':batch'] = $batchId;
        }

        $sql .= " ORDER BY sm.id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tra cứu toàn bộ sổ phát sinh có lọc theo tiêu chí
     */
    public static function getLedgerMovements(PDO $pdo, array $filters = []): array
    {
        $sql = "
            SELECT 
                sm.*,
                w.code AS warehouse_code,
                w.name AS warehouse_name,
                i.code AS item_code,
                i.name AS item_name,
                i.unit,
                b.batch_number,
                v.code AS voucher_code
            FROM stock_movements sm
            JOIN warehouses w ON sm.warehouse_id = w.id
            JOIN items i ON sm.item_id = i.id
            LEFT JOIN batches b ON sm.batch_id = b.id
            JOIN vouchers v ON sm.voucher_id = v.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND sm.warehouse_id = :wh";
            $params[':wh'] = (int)$filters['warehouse_id'];
        }
        if (!empty($filters['item_id'])) {
            $sql .= " AND sm.item_id = :item";
            $params[':item'] = (int)$filters['item_id'];
        }
        if (!empty($filters['movement_type'])) {
            $sql .= " AND sm.movement_type = :type";
            $params[':type'] = $filters['movement_type'];
        }

        $limit = (int)($filters['limit'] ?? 100);
        $sql .= " ORDER BY sm.id DESC LIMIT {$limit}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Xuất dữ liệu bảng biểu ra file CSV có hỗ trợ UTF-8 BOM hiển thị chuẩn tiếng Việt trong Excel
     */
    public static function exportCsv(array $data, array $headers, string $filename = 'bao_cao.csv'): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');
        // Thêm UTF-8 BOM
        fputs($output, "\xEF\xBB\xBF");

        fputcsv($output, $headers);
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
}
