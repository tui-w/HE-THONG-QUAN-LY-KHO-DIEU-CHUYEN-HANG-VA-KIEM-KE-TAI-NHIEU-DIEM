<?php

declare(strict_types=1);

namespace App;

require_once __DIR__ . '/AuditLogger.php';

use PDO;
use RuntimeException;

class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (ob_get_level() === 0) {
            ob_start();
        }
    }

    public static function login(PDO $pdo, string $username, string $password): ?array
    {
        $normalized = mb_strtolower(trim($username));
        $stmt = $pdo->prepare("
            SELECT id, username, password_hash, full_name, role, status, email 
            FROM users 
            WHERE LOWER(username) = LOWER(?) 
               OR (username = 'tuoi' AND ? IN ('admin', 'tuoi', 'tươi', 'daothituoi', 'tuoidt'))
               OR (username = 'admin' AND ? IN ('admin', 'tuoi', 'tươi', 'daothituoi', 'tuoidt'))
            LIMIT 1
        ");
        $stmt->execute([$username, $normalized, $normalized]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            if (isset($user['status']) && $user['status'] === 'inactive') {
                throw new RuntimeException("Tài khoản '{$username}' đang bị khóa bởi Quản trị viên hệ thống!");
            }
            self::startSession();
            unset($user['password_hash']);
            $_SESSION['user'] = $user;
            AuditLogger::log($pdo, 'LOGIN', 'users', $user['id'], "Đăng nhập thành công với vai trò " . $user['role'], $user);
            return $user;
        }

        return null;
    }

    public static function requireRole(array|string $roles): array
    {
        $user = self::requireLogin();
        $roles = (array)$roles;
        if (!in_array($user['role'], $roles, true)) {
            self::startSession();
            $_SESSION['flash_error'] = 'Truy cập bị từ chối: Khu vực này chỉ dành riêng cho Quản trị viên (ADMIN)!';
            header('Location: index.php');
            exit;
        }
        return $user;
    }

    public static function hasRole(array|string $roles): bool
    {
        $user = self::getCurrentUser();
        if (!$user) {
            return false;
        }
        $roles = (array)$roles;
        return in_array($user['role'], $roles, true);
    }

    public static function register(PDO $pdo, array $data): int
    {
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $fullName = trim((string)($data['full_name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $role = trim((string)($data['role'] ?? 'staff'));
        $warehouseId = !empty($data['warehouse_id']) ? (int)$data['warehouse_id'] : 1;

        if ($username === '' || $password === '' || $fullName === '') {
            throw new RuntimeException("Vui lòng điền đầy đủ Tên đăng nhập, Họ và tên và Mật khẩu!");
        }

        if (mb_strlen($password) < 6) {
            throw new RuntimeException("Mật khẩu phải có độ dài tối thiểu 6 ký tự!");
        }

        $validRoles = ['admin', 'manager', 'staff', 'auditor'];
        if (!in_array($role, $validRoles, true)) {
            $role = 'staff';
        }

        // Kiểm tra username trùng lặp
        $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmtCheck->execute([$username]);
        if ($stmtCheck->fetch()) {
            throw new RuntimeException("Tên đăng nhập '{$username}' đã có người sử dụng. Vui lòng chọn tên khác!");
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmtIns = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, email, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmtIns->execute([$username, $hash, $fullName, $email ?: null, $role]);
        $newUserId = (int)$pdo->lastInsertId();

        // Gán phân quyền kho theo vai trò
        $warehouses = $pdo->query("SELECT id FROM warehouses ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        $stmtUw = $pdo->prepare("INSERT INTO user_warehouses (user_id, warehouse_id, can_view, can_create, can_approve) VALUES (?, ?, ?, ?, ?)");

        if ($role === 'admin' || $role === 'manager') {
            $canApprove = 1;
            foreach ($warehouses as $wId) {
                $stmtUw->execute([$newUserId, (int)$wId, 1, 1, $canApprove]);
            }
        } elseif ($role === 'auditor') {
            foreach ($warehouses as $wId) {
                $stmtUw->execute([$newUserId, (int)$wId, 1, 0, 0]);
            }
        } else {
            // staff: Gán vào kho đã chọn
            $targetWh = in_array($warehouseId, $warehouses) ? $warehouseId : ($warehouses[0] ?? 1);
            $stmtUw->execute([$newUserId, (int)$targetWh, 1, 1, 0]);
        }

        return $newUserId;
    }

    public static function logout(): void
    {
        self::startSession();
        unset($_SESSION['user']);
        session_destroy();
    }

    public static function getCurrentUser(): ?array
    {
        self::startSession();
        return $_SESSION['user'] ?? null;
    }

    public static function requireLogin(): array
    {
        $user = self::getCurrentUser();
        if (!$user) {
            header('Location: login.php');
            exit;
        }
        return $user;
    }

    /**
     * Kiểm tra phân quyền truy cập và thao tác trên kho phía máy chủ (Server-side RBAC)
     * Ngăn chặn người dùng kho A gửi request sửa kho B (Phép thử bắt buộc số 4)
     */
    public static function checkWarehousePermission(PDO $pdo, int $userId, int $warehouseId, string $action = 'view'): bool
    {
        // 1. Lấy vai trò user
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();

        if ($role === false) {
            return false;
        }

        // Admin luôn có toàn quyền
        if ($role === 'admin') {
            return true;
        }

        // 2. Tra cứu quyền trong bảng user_warehouses
        $stmtPerm = $pdo->prepare("SELECT can_view, can_create, can_approve FROM user_warehouses WHERE user_id = ? AND warehouse_id = ?");
        $stmtPerm->execute([$userId, $warehouseId]);
        $perm = $stmtPerm->fetch(PDO::FETCH_ASSOC);

        if (!$perm) {
            // Không được phân công kho này
            return false;
        }

        return match ($action) {
            'view' => (int)$perm['can_view'] === 1,
            'create' => (int)$perm['can_create'] === 1,
            'approve' => (int)$perm['can_approve'] === 1 || $role === 'manager',
            default => false,
        };
    }

    public static function getUserWarehouses(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();

        if ($role === 'admin' || $role === 'manager' || $role === 'auditor') {
            return $pdo->query("SELECT * FROM warehouses ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmtW = $pdo->prepare("
            SELECT w.* 
            FROM warehouses w
            JOIN user_warehouses uw ON w.id = uw.warehouse_id
            WHERE uw.user_id = ? AND uw.can_view = 1
            ORDER BY w.id ASC
        ");
        $stmtW->execute([$userId]);
        return $stmtW->fetchAll(PDO::FETCH_ASSOC);
    }
}
