<?php

declare(strict_types=1);

namespace App;

use PDO;

class AuditLogger
{
    /**
     * Ghi lại một hoạt động vào nhật ký hệ thống
     *
     * @param PDO $pdo Kết nối database
     * @param string $action Loại hành động: LOGIN, LOGOUT, CREATE_VOUCHER, APPROVE_VOUCHER, CANCEL_VOUCHER, TRANSFER_STOCK, COUNT_STOCK, ADJUST_STOCK, UPDATE_USER, CREATE_ITEM, UPDATE_STOCK
     * @param string|null $targetType Loại đối tượng (vouchers, stock_counts, users, items, etc.)
     * @param string|int|null $targetId Mã hoặc ID đối tượng
     * @param string|null $details Chi tiết bổ sung
     * @param array|null $user Thông tin người dùng (mặc định lấy từ $_SESSION['user'])
     */
    public static function log(
        PDO $pdo,
        string $action,
        ?string $targetType = null,
        $targetId = null,
        ?string $details = null,
        ?array $user = null
    ): void {
        try {
            $userId = $user['id'] ?? ($_SESSION['user']['id'] ?? null);
            $username = $user['username'] ?? ($_SESSION['user']['username'] ?? 'system');
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            $stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, username, action, target_type, target_id, details, ip_address, created_at)
                VALUES (:user_id, :username, :action, :target_type, :target_id, :details, :ip_address, datetime('now', 'localtime'))
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':username' => $username,
                ':action' => strtoupper($action),
                ':target_type' => $targetType,
                ':target_id' => $targetId !== null ? (string)$targetId : null,
                ':details' => $details,
                ':ip_address' => $ip,
            ]);
        } catch (\Throwable $e) {
            // Nhật ký kiểm toán không được gây ngắt dòng xử lý nghiệp vụ chính
            error_log("[AuditLogger] Error: " . $e->getMessage());
        }
    }

    /**
     * Lấy danh sách nhật ký gần nhất kèm bộ lọc
     */
    public static function getRecentLogs(PDO $pdo, int $limit = 100, ?string $action = null, ?string $search = null): array
    {
        $sql = "SELECT * FROM activity_logs WHERE 1=1";
        $params = [];

        if (!empty($action)) {
            $sql .= " AND action = :action";
            $params[':action'] = $action;
        }

        if (!empty($search)) {
            $sql .= " AND (username LIKE :search OR details LIKE :search OR target_id LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY id DESC LIMIT " . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
