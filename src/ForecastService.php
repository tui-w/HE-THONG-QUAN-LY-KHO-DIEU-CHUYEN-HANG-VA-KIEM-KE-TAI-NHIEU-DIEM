<?php

declare(strict_types=1);

namespace App;

use PDO;

class ForecastService
{
    /**
     * Tính toán dự báo nhu cầu tiêu thụ và đề xuất số lượng đặt hàng tối ưu (Reorder Suggestion)
     * Dựa trên: Tồn thực tế, Định mức min_stock, Tốc độ xuất kho bình quân ngày (Burn Rate / Run Rate), 
     * và Thời gian giao hàng dự kiến (Lead Time).
     *
     * @param PDO $pdo
     * @param int|null $warehouseId (null = Toàn hệ thống)
     * @param int $leadTimeDays Số ngày đặt hàng từ NCC về kho (mặc định 7 ngày)
     * @param int $safetyDays Số ngày dự phòng an toàn (mặc định 5 ngày)
     * @return array
     */
    public static function getForecastAnalysis(
        PDO $pdo,
        ?int $warehouseId = null,
        int $leadTimeDays = 7,
        int $safetyDays = 5
    ): array {
        // 1. Lấy danh sách mặt hàng và tổng lượng xuất (OUT, TRANSFER_OUT)
        $whClauseMovements = '';
        $whClauseStock = '';
        $paramsMovements = [];
        $paramsStock = [];

        if ($warehouseId !== null) {
            $whClauseMovements = ' AND sm.warehouse_id = :wh_mv';
            $paramsMovements[':wh_mv'] = $warehouseId;
            $whClauseStock = ' AND sm.warehouse_id = :wh_stk';
            $paramsStock[':wh_stk'] = $warehouseId;
        }

        // Lấy số ngày hoạt động trong sổ phát sinh để tính burn rate chính xác
        $daysCountStmt = $pdo->query("SELECT MAX(1, CAST((julianday('now') - julianday(MIN(created_at))) AS INTEGER)) FROM stock_movements");
        $historyDays = max(14, (int)$daysCountStmt->fetchColumn());

        // Lấy tổng xuất từng item
        $sqlOut = "
            SELECT 
                item_id,
                COALESCE(SUM(quantity_out), 0) AS total_out,
                COUNT(DISTINCT DATE(created_at)) AS active_out_days
            FROM stock_movements sm
            WHERE sm.movement_type IN ('OUT', 'TRANSFER_OUT', 'ADJUST_OUT') {$whClauseMovements}
            GROUP BY item_id
        ";
        $stmtOut = $pdo->prepare($sqlOut);
        $stmtOut->execute($paramsMovements);
        $outData = [];
        foreach ($stmtOut->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $outData[(int)$row['item_id']] = (int)$row['total_out'];
        }

        // Lấy tồn kho hiện tại từng item
        $sqlStock = "
            SELECT 
                i.id AS item_id,
                i.code,
                i.name,
                i.unit,
                i.min_stock,
                COALESCE(c.name, 'Chung') AS category_name,
                COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS current_stock
            FROM items i
            LEFT JOIN item_categories c ON i.category_id = c.id
            LEFT JOIN stock_movements sm ON sm.item_id = i.id {$whClauseStock}
            GROUP BY i.id
            ORDER BY i.id ASC
        ";
        $stmtStock = $pdo->prepare($sqlStock);
        $stmtStock->execute($paramsStock);
        $items = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

        $results = [];

        foreach ($items as $item) {
            $itemId = (int)$item['item_id'];
            $curStock = (int)$item['current_stock'];
            $minStock = (int)$item['min_stock'];
            $totalOut = $outData[$itemId] ?? 0;

            // Tốc độ tiêu thụ bình quân ngày (Daily Consumption Rate)
            $avgDailyDemand = round($totalOut / $historyDays, 2);
            if ($avgDailyDemand <= 0.05) {
                // Nếu chưa có lịch sử xuất nhiều, giả định tối thiểu theo tỷ lệ min_stock
                $avgDailyDemand = round(max(0.2, $minStock / 30), 2);
            }

            // Điểm đặt hàng lại (Reorder Point = Nhu cầu trong LeadTime + Tồn an toàn Safety Stock)
            $leadTimeDemand = $avgDailyDemand * $leadTimeDays;
            $safetyStock = max($minStock, (int)ceil($avgDailyDemand * $safetyDays));
            $reorderPoint = (int)ceil($leadTimeDemand + $safetyStock);

            // Số ngày còn đủ hàng để bán (Days of Inventory Remaining)
            $daysRemaining = $avgDailyDemand > 0 ? (int)floor($curStock / $avgDailyDemand) : 999;

            // Xác định mức độ khẩn cấp (Stock Health Status)
            $urgency = 'SAFE'; // SAFE, REORDER, CRITICAL, OUT_OF_STOCK
            if ($curStock <= 0) {
                $urgency = 'OUT_OF_STOCK';
            } elseif ($curStock <= $minStock || $daysRemaining <= $leadTimeDays) {
                $urgency = 'CRITICAL';
            } elseif ($curStock <= $reorderPoint || $daysRemaining <= ($leadTimeDays + $safetyDays)) {
                $urgency = 'REORDER';
            }

            // Số lượng đề xuất nhập thêm (Suggested Order Quantity)
            // Mục tiêu: Bổ sung đủ tồn kho an toàn cho 30 ngày bán hàng tới
            $targetStock = (int)ceil($avgDailyDemand * 30) + $safetyStock;
            $suggestedQty = max(0, $targetStock - $curStock);

            // Làm tròn theo lô đóng gói thông thường (chẵn 5 hoặc 10)
            if ($suggestedQty > 0) {
                $suggestedQty = (int)ceil($suggestedQty / 5) * 5;
            }

            $results[] = [
                'item_id' => $itemId,
                'code' => $item['code'],
                'name' => $item['name'],
                'category_name' => $item['category_name'],
                'unit' => $item['unit'],
                'current_stock' => $curStock,
                'min_stock' => $minStock,
                'total_out_historical' => $totalOut,
                'avg_daily_demand' => $avgDailyDemand,
                'days_remaining' => $daysRemaining,
                'reorder_point' => $reorderPoint,
                'safety_stock' => $safetyStock,
                'suggested_reorder_qty' => $suggestedQty,
                'urgency' => $urgency,
            ];
        }

        // Sắp xếp: Ưu tiên mặt hàng cạn kiệt (OUT_OF_STOCK, CRITICAL, REORDER) lên đầu
        usort($results, function ($a, $b) {
            $urgencyWeight = [
                'OUT_OF_STOCK' => 1,
                'CRITICAL' => 2,
                'REORDER' => 3,
                'SAFE' => 4,
            ];
            $diff = $urgencyWeight[$a['urgency']] <=> $urgencyWeight[$b['urgency']];
            if ($diff !== 0) {
                return $diff;
            }
            return $a['days_remaining'] <=> $b['days_remaining'];
        });

        return $results;
    }
}
