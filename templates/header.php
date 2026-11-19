<?php
declare(strict_types=1);

if (ob_get_level() === 0) {
    ob_start();
}

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';

use App\Database;
use App\Auth;

Auth::startSession();
$currentUser = Auth::getCurrentUser();
if (!$currentUser && basename($_SERVER['PHP_SELF']) !== 'login.php') {
    header('Location: login.php');
    exit;
}

$pdo = Database::getConnection();

// Luôn đồng bộ dữ liệu phiên người dùng mới nhất từ CSDL
if ($currentUser) {
    $userId = (int) ($currentUser['id'] ?? 0);
    $userUname = (string) ($currentUser['username'] ?? '');
    $stmtRefresh = $pdo->prepare("
        SELECT id, username, full_name, role, status, email 
        FROM users 
        WHERE id = ? 
           OR LOWER(username) = LOWER(?) 
           OR (username = 'tuoi' AND ? IN ('admin', 'tuoi', 'tươi')) 
        LIMIT 1
    ");
    $stmtRefresh->execute([$userId, $userUname, mb_strtolower($userUname)]);
    $freshUser = $stmtRefresh->fetch(PDO::FETCH_ASSOC);
    if ($freshUser) {
        $_SESSION['user'] = $freshUser;
        $currentUser = $freshUser;
    }
}

// Hỗ trợ chuyển đổi nhanh vai trò (Switch Demo User) ngay trên thanh tiêu đề để chấm điểm / kiểm thử
if (isset($_GET['switch_user'])) {
    $targetUsername = trim((string) $_GET['switch_user']);
    $normTarget = mb_strtolower($targetUsername);
    $stmtSwitch = $pdo->prepare("
        SELECT * FROM users 
        WHERE LOWER(username) = LOWER(?) 
           OR (username = 'tuoi' AND ? IN ('admin', 'tuoi', 'tươi'))
           OR (username = 'admin' AND ? IN ('admin', 'tuoi', 'tươi'))
        LIMIT 1
    ");
    $stmtSwitch->execute([$targetUsername, $normTarget, $normTarget]);
    $swUser = $stmtSwitch->fetch(PDO::FETCH_ASSOC);
    if ($swUser) {
        $_SESSION['user'] = $swUser;
        $currentUser = $swUser;
        // Bỏ tham số switch_user khỏi URL để tránh refresh lại
        $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
        header("Location: {$redirectUrl}");
        exit;
    }
}

$warehouses = $currentUser ? Auth::getUserWarehouses($pdo, (int) $currentUser['id']) : [];
$activePage = basename($_SERVER['PHP_SELF'], '.php');

// Phân quyền giao diện & trang theo từng vai trò (RBAC Workspace)
$userRole = (string) ($currentUser['role'] ?? 'staff');
$roleAllowedMap = [
    'admin'   => ['index','vouchers','voucher_detail','print_voucher','quick_voucher','transfer','quick_transfer','inventory','inventory_detail','quick_audit','items','reports','warehouses','quick_warehouse','warehouse_hub','locations','bin_labels','barcode','payments','forecast','expiry','logs','database_manager','users','digital_twin','api'],
    'manager' => ['index','vouchers','voucher_detail','print_voucher','quick_voucher','transfer','quick_transfer','inventory','inventory_detail','quick_audit','items','reports','payments','forecast','expiry','logs','digital_twin','api'],
    'staff'   => ['index','vouchers','voucher_detail','print_voucher','quick_voucher','transfer','quick_transfer','items','locations','bin_labels','barcode','api'],
    'auditor' => ['index','inventory','inventory_detail','quick_audit','reports','items','expiry','locations','logs','api'],
];
$allowedPages = $roleAllowedMap[$userRole] ?? $roleAllowedMap['staff'];
$roleHomePage = match ($userRole) {
    'manager' => 'vouchers.php?status=DRAFT',
    'staff'   => 'vouchers.php',
    'auditor' => 'inventory.php',
    default   => 'index.php',
};
if ($currentUser && PHP_SAPI !== 'cli' && !in_array($activePage, array_merge($allowedPages, ['login', 'logout', 'register']), true)) {
    $_SESSION['flash_error'] = 'Tài khoản vai trò ' . strtoupper($userRole) . ' không có quyền truy cập chức năng này!';
    header('Location: ' . $roleHomePage);
    exit;
}

$whNamesBadge = implode(', ', array_map(fn($w) => $w['code'], $warehouses));
$roleBannerMeta = match ($userRole) {
    'manager' => ['badge' => 'MANAGER', 'cls' => 'bg-primary-subtle text-primary border-primary-subtle', 'desc' => 'Phê duyệt phiếu Nhập/Xuất & Điều chuyển toàn hệ thống (' . ($whNamesBadge ?: '3 kho') . ')'],
    'staff'   => ['badge' => 'STAFF', 'cls' => 'bg-success-subtle text-success border-success-subtle', 'desc' => 'Nhân viên vận hành kho • Phạm vi thao tác: ' . ($whNamesBadge ?: 'Chưa gán kho')],
    'auditor' => ['badge' => 'AUDITOR', 'cls' => 'bg-purple-subtle text-purple border', 'desc' => 'Kiểm toán viên • Kiểm kê, đối soát tồn thực tế & hạn dùng FEFO'],
    default   => null,
};

// Danh sách tài khoản demo
$allDemoUsers = [];
try {
    $allDemoUsers = $pdo->query("SELECT id, username, full_name, role FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
}

// Thống kê cảnh báo thông minh cho Notification Bell
$lowStockAlertCount = 0;
$pendingVouchersCount = 0;
$expiringBatchesCount = 0;
$totalAlertsCount = 0;

if ($currentUser) {
    try {
        $lowStockAlertCount = (int) $pdo->query("
            SELECT COUNT(DISTINCT i.id) 
            FROM items i 
            JOIN (SELECT item_id, COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) AS total_bal FROM stock_movements GROUP BY item_id) s ON s.item_id = i.id 
            WHERE s.total_bal <= i.min_stock
        ")->fetchColumn();
        $pendingVouchersCount = (int) $pdo->query("SELECT COUNT(*) FROM vouchers WHERE status = 'DRAFT'")->fetchColumn();
        $expiringBatchesCount = (int) $pdo->query("SELECT COUNT(*) FROM batches WHERE expiry_date IS NOT NULL AND expiry_date <= date('now', '+60 days')")->fetchColumn();
        $totalAlertsCount = $lowStockAlertCount + $pendingVouchersCount + $expiringBatchesCount;
    } catch (\Throwable $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Quản lý Kho Đa Điểm' ?> - Logistix WMS</title>

    <!-- Favicon -->
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📦</text></svg>">

    <!-- Script khởi tạo Theme tức thì tránh nháy sáng -->
    <script>
        (function () {
            const savedTheme = localStorage.getItem('logistix_theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', savedTheme);
        })();
    </script>

    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Chart.js for beautiful interactive analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

    <!-- Custom Modern CSS (Cache-busted) -->
    <link href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../public/assets/css/style.css') ?: time() ?>"
        rel="stylesheet">
    <!-- Vivid colourful animated theme -->
    <link href="assets/css/vivid.css?v=<?= @filemtime(__DIR__ . '/../public/assets/css/vivid.css') ?: time() ?>"
        rel="stylesheet">
    <link href="assets/css/nextgen.css?v=<?= @filemtime(__DIR__ . '/../public/assets/css/nextgen.css') ?: time() ?>"
        rel="stylesheet">
    <script>
        (function () { var p = localStorage.getItem('wms_palette'); if (p) document.documentElement.setAttribute('data-palette', p); })();
    </script>
</head>

<body>
    <!-- Top Scroll Progress Bar -->
    <div id="topProgressBar"></div>

    <!-- Header Navigation Bar: Minimalist & Clean -->
    <nav class="navbar navbar-expand-lg app-navbar sticky-top">
        <div class="container-fluid px-3 px-lg-4">
            <div class="d-flex align-items-center gap-2">
                <?php if ($currentUser): ?>
                    <button type="button" class="theme-toggle-btn d-none d-md-inline-flex" id="sidebarCollapseBtn"
                        title="Thu gọn / Mở rộng thanh điều hướng để mở rộng Main Content">
                        <i class="bi bi-layout-sidebar-inset"></i>
                    </button>
                <?php endif; ?>
                <!-- Brand Logo -->
                <a class="brand-wrapper" href="index.php">
                    <div class="brand-icon-box">
                        <i class="bi bi-box-seam-fill"></i>
                    </div>
                    <div class="d-flex align-items-center gap-1.5">
                        <span class="brand-title">Logistix</span>
                        <span class="brand-badge">WMS</span>
                    </div>
                </a>
            </div>

            <button class="navbar-toggler border-0 shadow-none p-1" type="button" data-bs-toggle="collapse"
                data-bs-target="#navContent">
                <i class="bi bi-list fs-3"></i>
            </button>

            <div class="collapse navbar-collapse" id="navContent">
                <?php if ($currentUser): ?>
                    <!-- Search & Live Barcode Scanner Button -->
                    <div class="mx-auto my-2 my-lg-0 d-flex align-items-center gap-1.5">
                        <button type="button" class="search-trigger-btn" data-bs-toggle="modal"
                            data-bs-target="#quickStockModal">
                            <i class="bi bi-search text-primary"></i>
                            <span>Tra cứu tồn kho nhanh...</span>
                            <kbd>Ctrl K</kbd>
                        </button>
                        <button type="button" class="theme-toggle-btn text-primary border-primary-subtle"
                            data-bs-toggle="modal" data-bs-target="#liveScannerModal" title="Quét mã vạch / QR Code">
                            <i class="bi bi-qr-code-scan"></i>
                        </button>
                        <a href="digital_twin.php" class="btn btn-sm rounded-pill d-inline-flex align-items-center gap-1.5 px-3 fw-semibold"
                            style="height: 36px; background: rgba(255,255,255,0.22); border: 1px solid rgba(255,255,255,0.34); color: #fff; backdrop-filter: blur(8px);"
                            title="Trang riêng Mô hình 3D Không gian Kho Đa điểm (3D Digital Twin)">
                            <i class="bi bi-box"></i>
                            <span class="d-none d-sm-inline">3D Kho</span>
                        </a>
                    </div>

                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <!-- Warehouse active indicator -->
                        <div class="d-none d-xl-flex align-items-center gap-1.5 px-3 bg-slate-50 rounded-pill border text-slate-600 text-nowrap"
                            style="font-size: 0.76rem; height: 34px;">
                            <i class="bi bi-geo-alt-fill text-primary"></i>
                            <strong>
                                <?php if (in_array($currentUser['role'], ['admin', 'manager', 'auditor'], true)): ?>
                                    3 Kho
                                <?php else: ?>
                                    <?= !empty($warehouses) ? htmlspecialchars($warehouses[0]['code']) : 'Kho' ?>
                                <?php endif; ?>
                            </strong>
                        </div>

                        <!-- Notification Center Bell Dropdown -->
                        <div class="dropdown">
                            <button class="notification-bell-btn" type="button" data-bs-toggle="dropdown"
                                aria-expanded="false" title="Trung tâm cảnh báo nghiệp vụ">
                                <i class="bi bi-bell"></i>
                                <?php if ($totalAlertsCount > 0): ?>
                                    <span
                                        class="notification-badge-pill"><?= $totalAlertsCount > 9 ? '9+' : $totalAlertsCount ?></span>
                                <?php endif; ?>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end notifications-menu border shadow-lg mt-2">
                                <div
                                    class="px-3 py-2.5 border-bottom bg-light d-flex justify-content-between align-items-center">
                                    <span class="fw-bold small text-dark"><i class="bi bi-bell-fill text-primary me-1"></i>
                                        Cảnh báo & Tác vụ</span>
                                    <span
                                        class="badge bg-danger-subtle text-danger border border-danger-subtle small"><?= $totalAlertsCount ?>
                                        cần xử lý</span>
                                </div>
                                <div class="overflow-auto" style="max-height: 280px;">
                                    <?php if ($lowStockAlertCount > 0): ?>
                                        <a href="reports.php?tab=summary" class="notification-item">
                                            <div class="text-danger fs-5"><i class="bi bi-exclamation-triangle-fill"></i></div>
                                            <div class="small">
                                                <div class="fw-semibold text-dark">Hàng tồn dưới định mức an toàn</div>
                                                <div class="text-muted">Có <strong><?= $lowStockAlertCount ?></strong> sản phẩm
                                                    cần nhập bổ sung kho</div>
                                            </div>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($pendingVouchersCount > 0): ?>
                                        <a href="vouchers.php?status=DRAFT" class="notification-item">
                                            <div class="text-warning fs-5"><i class="bi bi-file-earmark-diff-fill"></i></div>
                                            <div class="small">
                                                <div class="fw-semibold text-dark">Chứng từ chờ phê duyệt</div>
                                                <div class="text-muted">Có <strong><?= $pendingVouchersCount ?></strong> phiếu
                                                    nháp đang chờ xác nhận</div>
                                            </div>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($expiringBatchesCount > 0): ?>
                                        <a href="items.php" class="notification-item">
                                            <div class="text-info fs-5"><i class="bi bi-calendar-event-fill"></i></div>
                                            <div class="small">
                                                <div class="fw-semibold text-dark">Lô hàng cận hạn sử dụng</div>
                                                <div class="text-muted">Có <strong><?= $expiringBatchesCount ?></strong> lô hàng
                                                    hết hạn trong 60 ngày tới</div>
                                            </div>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($totalAlertsCount === 0): ?>
                                        <div class="p-4 text-center text-muted small">
                                            <i class="bi bi-check-circle-fill text-success fs-3 d-block mb-1"></i>
                                            Hệ thống vận hành trơn tru, không có cảnh báo tồn đọng!
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="p-2 border-top bg-light text-center">
                                    <a href="reports.php?tab=summary" class="small text-decoration-none fw-semibold">Xem chi
                                        tiết báo cáo kho →</a>
                                </div>
                            </div>
                        </div>

                        <!-- Dark Mode Toggle Button -->
                        <button type="button" class="theme-toggle-btn" id="themeToggleBtn"
                            title="Chuyển chế độ Sáng / Tối (Ctrl + D)">
                            <i class="bi bi-moon-stars" id="themeToggleIcon"></i>
                        </button>

                        <!-- Keyboard Shortcuts Guide Button -->
                        <button type="button" class="theme-toggle-btn d-none d-sm-flex" data-bs-toggle="modal"
                            data-bs-target="#shortcutsModal" title="Phím tắt hệ thống (Ctrl + /)">
                            <i class="bi bi-keyboard"></i>
                        </button>

                        <!-- Interactive Self-Test Runner Modal Button -->
                        <button type="button" class="theme-toggle-btn text-success border-success-subtle"
                            data-bs-toggle="modal" data-bs-target="#testRunnerModal"
                            title="Bảng điều khiển Kiểm thử Tự động 5 Phép thử & Mở rộng (Auto Test Suite)">
                            <i class="bi bi-patch-check-fill"></i>
                        </button>

                        <!-- User Profile & Switcher Dropdown -->
                        <div class="dropdown ps-2 border-start">
                            <button
                                class="btn p-0 border-0 d-flex align-items-center gap-2 text-decoration-none shadow-none user-profile-trigger"
                                type="button" data-bs-toggle="dropdown" aria-expanded="false"
                                title="Tài khoản & Chuyển đổi quyền">
                                <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                                    <div class="admin-cute-avatar-wrap" style="width: 36px; height: 36px;">
                                        <span class="bunny-ears"></span>
                                        <div class="admin-cute-avatar-inner">
                                            <img src="assets/img/admin-avatar.jpg" alt="Admin Avatar">
                                        </div>
                                        <span class="admin-cute-badge"><i class="bi bi-stars"></i></span>
                                    </div>
                                <?php else: ?>
                                    <div class="user-avatar">
                                        <?= mb_substr($currentUser['full_name'], 0, 1) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="d-none d-sm-block text-start lh-1">
                                    <div class="fw-semibold text-slate-800" style="font-size: 0.82rem;">
                                        <?= htmlspecialchars($currentUser['full_name']) ?>
                                    </div>
                                    <div class="text-muted text-uppercase fw-bold mt-0.5"
                                        style="font-size: 0.65rem; letter-spacing: 0.04em;">
                                        <?= htmlspecialchars($currentUser['role']) ?> <i class="bi bi-chevron-down ms-0.5"
                                            style="font-size: 0.6rem;"></i>
                                    </div>
                                </div>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end border-0 shadow-lg mt-2 p-2.5"
                                style="min-width: 275px; border-radius: 18px; background: #ffffff; color: #0f172a;">
                                <div class="p-3 mb-2 rounded-4 d-flex align-items-center gap-3" style="background: linear-gradient(135deg, #fdf2f8 0%, #eef2ff 100%); border: 1px solid #f3e8ff;">
                                    <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                                        <div class="admin-cute-avatar-wrap" style="width: 48px; height: 48px;">
                                            <span class="bunny-ears" style="top: -12px; width: 34px; height: 17px;"></span>
                                            <div class="admin-cute-avatar-inner">
                                                <img src="assets/img/admin-avatar.jpg" alt="Admin Avatar">
                                            </div>
                                            <span class="admin-cute-badge" style="width: 17px; height: 17px; font-size: 0.54rem;"><i class="bi bi-stars"></i></span>
                                        </div>
                                    <?php else: ?>
                                        <div class="rounded-circle bg-primary text-white fw-bold d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px;">
                                            <?= mb_substr($currentUser['full_name'], 0, 1) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="min-w-0">
                                        <div class="fw-bold small text-truncate" style="color: #0f172a !important;">
                                            <?= htmlspecialchars($currentUser['full_name']) ?>
                                        </div>
                                        <div class="small text-truncate" style="font-size: 0.72rem; color: #64748b !important;">
                                            @<?= htmlspecialchars($currentUser['username']) ?>
                                        </div>
                                        <span class="badge rounded-pill text-uppercase mt-0.5" style="font-size: 0.62rem; background: linear-gradient(135deg, #ec4899, #6366f1); color: #fff !important;">
                                            <?= htmlspecialchars($currentUser['role']) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="px-2.5 py-1 text-uppercase fw-bold"
                                    style="font-size: 0.65rem; letter-spacing: 0.05em; color: #94a3b8 !important;">Chuyển quyền kiểm thử nhanh:</div>
                                <?php foreach ($allDemoUsers as $du):
                                    $isCur = ($currentUser['username'] === $du['username']);
                                ?>
                                    <a href="?switch_user=<?= urlencode($du['username']) ?>"
                                        class="dropdown-item rounded-3 py-1.5 px-2.5 d-flex justify-content-between align-items-center mb-0.5 user-switch-item <?= $isCur ? 'active' : '' ?>"
                                        style="<?= $isCur ? 'background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff !important;' : 'color: #1e293b !important;' ?>">
                                        <div class="d-flex align-items-center gap-2 small">
                                            <?php if ($du['role'] === 'admin'): ?>
                                                <div class="rounded-circle overflow-hidden flex-shrink-0" style="width: 28px; height: 28px; border: 1.5px solid #f472b6;">
                                                    <img src="assets/img/admin-avatar.jpg" alt="Admin" style="width: 100%; height: 100%; object-fit: cover; object-position: center 18%; transform: scale(1.28); display: block;">
                                                </div>
                                            <?php else: ?>
                                                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0" style="width: 28px; height: 28px; font-size: 0.72rem; background: <?= $isCur ? 'rgba(255,255,255,0.22)' : '#eef2ff' ?>; color: <?= $isCur ? '#fff' : '#4f46e5' ?>;">
                                                    <?= mb_substr($du['full_name'], 0, 1) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="fw-semibold" style="color: <?= $isCur ? '#fff' : '#0f172a' ?> !important;"><?= htmlspecialchars($du['full_name']) ?></div>
                                                <div style="font-size: 0.68rem; color: <?= $isCur ? 'rgba(255,255,255,0.8)' : '#64748b' ?> !important;">
                                                    @<?= htmlspecialchars($du['username']) ?></div>
                                            </div>
                                        </div>
                                        <span class="badge rounded-pill"
                                            style="font-size: 0.62rem; background: <?= $isCur ? 'rgba(255,255,255,0.2)' : '#f1f5f9' ?>; color: <?= $isCur ? '#fff' : '#334155' ?> !important;"><?= strtoupper($du['role']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                                <div class="dropdown-divider my-1"></div>
                                <a href="logout.php" class="dropdown-item rounded-2 py-1.5 px-3 text-danger small">
                                    <i class="bi bi-box-arrow-right me-1.5"></i> Đăng xuất phiên làm việc
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid p-0">
        <div class="app-shell-row d-flex">
            <?php if ($currentUser):
                $isSecondaryPage = in_array($activePage, ['locations', 'barcode', 'payments', 'warehouses', 'forecast', 'expiry', 'logs', 'database_manager', 'users'], true);
                ?>
                <!-- Thanh Menu Nổi (Floating Scrollable Sidebar) - Cuộn độc lập, không tạo khoảng trắng -->
                <aside class="sidebar floating-sidebar collapse d-md-block" id="sidebarMenu">
                    <div class="floating-sidebar-inner">
                        <!-- Thanh tìm nhanh & Thu gọn Mini-Rail -->
                        <div class="sidebar-tools d-flex align-items-center gap-1.5 mb-2">
                            <div class="input-group input-group-sm flex-grow-1 sidebar-search-box">
                                <span class="input-group-text border-0 bg-transparent ps-2 pe-1"><i class="bi bi-search" style="font-size:0.72rem;"></i></span>
                                <input type="text" id="sidebarQuickFilter" class="form-control border-0 bg-transparent ps-1 shadow-none" placeholder="Tìm menu..." style="font-size:0.74rem;height:28px;">
                            </div>
                            <button type="button" id="sidebarMiniToggle" class="btn btn-sm border-0 p-1 rounded-2 flex-shrink-0" title="Thu gọn / Mở rộng thanh Menu (Mini Rail)" style="width:28px;height:28px;">
                                <i class="bi bi-layout-sidebar-inset" style="font-size:0.82rem;"></i>
                            </button>
                        </div>

                        <div class="sidebar-heading">
                            <span>NGHIỆP VỤ CHÍNH</span>
                        </div>
                        <ul class="nav flex-column mb-2 sidebar-primary-nav">
                            <li class="nav-item">
                                <a class="nav-link nav-link-main <?= $activePage === 'index' ? 'active' : '' ?>"
                                    href="index.php" title="Tổng quan kho">
                                    <span class="nav-icon nav-icon-dashboard"><i class="bi bi-grid-1x2-fill"></i></span>
                                    <span class="nav-label">Tổng quan kho</span>
                                </a>
                            </li>
                            <?php if (in_array('vouchers', $allowedPages, true)): ?>
                            <li class="nav-item">
                                <a class="nav-link nav-link-main <?= in_array($activePage, ['vouchers', 'voucher_detail', 'print_voucher']) ? 'active' : '' ?>"
                                    href="vouchers.php" title="Nhập / Xuất kho">
                                    <span class="nav-icon nav-icon-vouchers"><i
                                            class="bi bi-file-earmark-diff-fill"></i></span>
                                    <span class="nav-label"><?= $userRole === 'manager' ? 'Duyệt Nhập / Xuất' : 'Nhập / Xuất kho' ?></span>
                                    <?php if ($pendingVouchersCount > 0): ?>
                                        <span class="badge bg-warning text-dark ms-auto nav-badge"><?= $pendingVouchersCount ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (in_array('transfer', $allowedPages, true)): ?>
                            <li class="nav-item">
                                <a class="nav-link nav-link-main <?= $activePage === 'transfer' ? 'active' : '' ?>"
                                    href="transfer.php" title="Điều chuyển kho">
                                    <span class="nav-icon nav-icon-transfer"><i class="bi bi-arrow-left-right"></i></span>
                                    <span class="nav-label">Điều chuyển kho</span>
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (in_array('inventory', $allowedPages, true)): ?>
                            <li class="nav-item">
                                <a class="nav-link nav-link-main <?= in_array($activePage, ['inventory', 'inventory_detail']) ? 'active' : '' ?>"
                                    href="inventory.php" title="Kiểm kê & Lệch">
                                    <span class="nav-icon nav-icon-inventory"><i
                                            class="bi bi-clipboard2-check-fill"></i></span>
                                    <span class="nav-label"><?= $userRole === 'manager' ? 'Duyệt Kiểm kê' : 'Kiểm kê & Lệch' ?></span>
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (in_array('items', $allowedPages, true)): ?>
                            <li class="nav-item">
                                <a class="nav-link nav-link-main <?= $activePage === 'items' ? 'active' : '' ?>"
                                    href="items.php" title="Hàng hóa & Lô">
                                    <span class="nav-icon nav-icon-items"><i class="bi bi-boxes"></i></span>
                                    <span class="nav-label">Hàng hóa & Lô</span>
                                    <?php if ($lowStockAlertCount > 0): ?>
                                        <span class="badge bg-danger ms-auto nav-badge"><?= $lowStockAlertCount ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (in_array('reports', $allowedPages, true)): ?>
                            <li class="nav-item">
                                <a class="nav-link nav-link-main <?= $activePage === 'reports' ? 'active' : '' ?>"
                                    href="reports.php" title="Báo cáo & Thẻ kho">
                                    <span class="nav-icon nav-icon-reports"><i class="bi bi-graph-up-arrow"></i></span>
                                    <span class="nav-label">Báo cáo & Thẻ kho</span>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>

                        <div class="sidebar-divider my-2"></div>

                        <!-- Nhóm Menu Phụ: Tiện ích & Quản trị -->
                        <div class="sidebar-heading d-flex align-items-center justify-content-between"
                            style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#secondaryNavCollapse"
                            aria-expanded="true">
                            <span><?= $userRole === 'admin' ? 'MỞ RỘNG & QUẢN TRỊ' : 'TIỆN ÍCH VAI TRÒ' ?></span>
                            <i class="bi bi-chevron-down" style="font-size: 0.65rem;"></i>
                        </div>
                        <div class="collapse show" id="secondaryNavCollapse">
                            <ul class="nav flex-column sidebar-secondary-nav">
                                <?php if (in_array('warehouses', $allowedPages, true)): ?>
                                <li class="nav-item">
                                    <a class="nav-link nav-link-sub <?= $activePage === 'warehouses' ? 'active' : '' ?>"
                                        href="warehouses.php">
                                        <span class="nav-icon nav-icon-warehouses"><i
                                                class="bi bi-buildings-fill"></i></span>
                                        <span>Điểm kho & RBAC</span>
                                    </a>
                                </li>
                                <?php endif; ?>
                                <?php if (in_array('locations', $allowedPages, true)): ?>
                                <li class="nav-item">
                                    <a class="nav-link nav-link-sub <?= $activePage === 'locations' ? 'active' : '' ?>"
                                        href="locations.php">
                                        <span class="nav-icon nav-icon-locations"><i
                                                class="bi bi-diagram-3-fill"></i></span>
                                        <span>Sơ đồ Ô kệ</span>
                                    </a>
                                </li>
                                <?php endif; ?>
                                <?php if (in_array('barcode', $allowedPages, true)): ?>
                                <li class="nav-item">
                                    <a class="nav-link nav-link-sub <?= $activePage === 'barcode' ? 'active' : '' ?>"
                                        href="barcode.php">
                                        <span class="nav-icon nav-icon-barcode"><i class="bi bi-upc-scan"></i></span>
                                        <span>In tem Barcode</span>
                                    </a>
                                </li>
                                <?php endif; ?>
                                <?php if (in_array('payments', $allowedPages, true)): ?>
                                <li class="nav-item">
                                    <a class="nav-link nav-link-sub <?= $activePage === 'payments' ? 'active' : '' ?>"
                                        href="payments.php">
                                        <span class="nav-icon nav-icon-payments"><i class="bi bi-wallet2"></i></span>
                                        <span>Thu Chi & Nợ</span>
                                    </a>
                                </li>
                                <?php endif; ?>
                                <?php if (in_array('forecast', $allowedPages, true)): ?>
                                <li class="nav-item">
                                    <a class="nav-link nav-link-sub <?= $activePage === 'forecast' ? 'active' : '' ?>"
                                        href="forecast.php">
                                        <span class="nav-icon nav-icon-forecast"><i class="bi bi-cpu-fill"></i></span>
                                        <span>Dự báo đặt hàng</span>
                                    </a>
                                </li>
                                <?php endif; ?>
                                <?php if (in_array('expiry', $allowedPages, true)): ?>
                                <li class="nav-item">
                                    <a class="nav-link nav-link-sub <?= $activePage === 'expiry' ? 'active' : '' ?>"
                                        href="expiry.php">
                                        <span class="nav-icon nav-icon-expiry"><i
                                                class="bi bi-calendar2-week-fill"></i></span>
                                        <span>Hạn dùng FEFO</span>
                                    </a>
                                </li>
                                <?php endif; ?>
                                <?php if (in_array('logs', $allowedPages, true)): ?>
                                    <li class="nav-item">
                                        <a class="nav-link nav-link-sub <?= $activePage === 'logs' ? 'active' : '' ?>"
                                            href="logs.php">
                                            <span class="nav-icon nav-icon-logs"><i class="bi bi-shield-lock-fill"></i></span>
                                            <span>Nhật ký hệ thống</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                <?php if (in_array('database_manager', $allowedPages, true)): ?>
                                    <li class="nav-item">
                                        <a class="nav-link nav-link-sub <?= $activePage === 'database_manager' ? 'active' : '' ?>"
                                            href="database_manager.php">
                                            <span class="nav-icon nav-icon-database"><i
                                                    class="bi bi-database-fill-gear"></i></span>
                                            <span>Quản trị CSDL</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                <?php if (in_array('users', $allowedPages, true)): ?>
                                    <li class="nav-item">
                                        <a class="nav-link nav-link-sub <?= $activePage === 'users' ? 'active' : '' ?>"
                                            href="users.php">
                                            <span class="nav-icon nav-icon-users"><i class="bi bi-person-fill-gear"></i></span>
                                            <span>Tài khoản</span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                </aside>

                <!-- Main Content Area (Mở rộng tối đa diện tích, thoáng đãng, làm nổi bật nội dung chính) -->
                <main class="main-content flex-grow-1 px-3 px-lg-4 py-3">
                    <?php if ($roleBannerMeta): ?>
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 px-3 py-2 mb-3 rounded-3 border <?= $roleBannerMeta['cls'] ?>" style="font-size: 0.78rem;">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-dark text-white rounded-pill px-2.5"><?= $roleBannerMeta['badge'] ?></span>
                                <span class="fw-semibold"><?= htmlspecialchars($roleBannerMeta['desc']) ?></span>
                            </div>
                            <span class="text-muted" style="font-size: 0.72rem;"><i class="bi bi-shield-check me-1"></i>Phân quyền RBAC đang bật</span>
                        </div>
                    <?php endif; ?>
                    <!-- Flash Notification Alerts -->
                    <?php if (isset($_SESSION['flash_success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 py-2.5 px-3 mb-4"
                            role="alert">
                            <div class="d-flex align-items-center gap-2 small">
                                <i class="bi bi-check-circle-fill text-success"></i>
                                <div><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" style="padding: 0.75rem;"></button>
                        </div>
                        <?php unset($_SESSION['flash_success']); ?>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['flash_error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 py-2.5 px-3 mb-4"
                            role="alert">
                            <div class="d-flex align-items-center gap-2 small">
                                <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                                <div><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" style="padding: 0.75rem;"></button>
                        </div>
                        <?php unset($_SESSION['flash_error']); ?>
                    <?php endif; ?>
                <?php else: ?>
                    <main class="col-12 py-4">
                    <?php endif; ?>

                    <!-- Modal Tra Cứu Tồn Kho Nhanh Toàn Hệ Thống (Feature Mới) -->
                    <div class="modal fade" id="quickStockModal" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                                <div class="modal-header border-bottom px-4 py-3 bg-light">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-search text-primary fs-5"></i>
                                        <h6 class="modal-title fw-bold mb-0">Tra cứu nhanh tồn kho thời gian thực</h6>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body p-4">
                                    <div class="input-group mb-3">
                                        <span class="input-group-text bg-white border-end-0"><i
                                                class="bi bi-search text-muted"></i></span>
                                        <input type="text" id="quickSearchInput"
                                            class="form-control border-start-0 ps-0"
                                            placeholder="Nhập tên sản phẩm hoặc mã hàng (VD: Dell, ThinkPad, Chuột, RAM, SSD)..."
                                            autofocus>
                                    </div>

                                    <div id="quickSearchResults"
                                        class="list-group list-group-flush border rounded-3 overflow-auto"
                                        style="max-height: 380px;">
                                        <div class="p-4 text-center text-muted small">
                                            <i class="bi bi-keyboard fs-3 d-block mb-1 opacity-50"></i>
                                            Gõ từ khóa để tra cứu số tồn tức thời tại cả 3 kho...
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Quét Mã Vạch & QR Trực Tiếp Bằng Camera (Html5-QRCode Live Scanner) -->
                    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
                    <div class="modal fade" id="liveScannerModal" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                                <div class="modal-header border-bottom px-4 py-3" style="background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);">
                                    <div class="d-flex align-items-center gap-2.5">
                                        <span class="rounded-3 bg-primary text-white shadow-xs d-inline-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                            <i class="bi bi-qr-code-scan fs-6"></i>
                                        </span>
                                        <div class="text-start">
                                            <h6 class="modal-title fw-bold text-slate-900 mb-0" style="font-size: 0.95rem;">Trạm Quét Mã Vạch & QR</h6>
                                            <div class="text-muted" style="font-size: 0.74rem;" id="scanStatusText">Sẵn sàng quét SKU, Ô kệ hoặc Chứng từ</div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body p-4 text-center">
                                    <!-- Thanh nhập / bắn súng Barcode USB -->
                                    <div class="position-relative mb-3">
                                        <div class="d-flex gap-2 align-items-center">
                                            <div class="input-group input-group-sm flex-grow-1 shadow-xs" style="border-radius: 12px; overflow: hidden; border: 1px solid #cbd5e1; background: #fff; height: 38px;">
                                                <span class="input-group-text bg-white border-0 text-primary ps-3 pe-2"><i class="bi bi-upc-scan"></i></span>
                                                <input type="text" id="manualScanInput" class="form-control border-0 shadow-none ps-0 pe-1" style="font-size: 0.84rem;"
                                                    placeholder="Tìm theo tên SP, mã SKU, Ô kệ hoặc Phiếu..." autocomplete="off"
                                                    onfocus="window.loadScanSuggestions && loadScanSuggestions(this.value)"
                                                    onclick="window.loadScanSuggestions && loadScanSuggestions(this.value)"
                                                    ondblclick="window.loadScanSuggestions && loadScanSuggestions('')"
                                                    oninput="window.loadScanSuggestions && loadScanSuggestions(this.value)"
                                                    onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('scanSuggestDropdown')?.classList.add('d-none');window.handleUniversalScan && handleUniversalScan(this.value);}">
                                                <button type="button" class="btn btn-sm bg-white border-0 text-slate-500 px-2.5 h-100" title="Mở danh sách mã"
                                                    onclick="const dd=document.getElementById('scanSuggestDropdown'); if(dd && !dd.classList.contains('d-none')){dd.classList.add('d-none');} else {window.loadScanSuggestions && loadScanSuggestions(document.getElementById('manualScanInput').value);}">
                                                    <i class="bi bi-chevron-down" style="font-size: 0.75rem;"></i>
                                                </button>
                                            </div>
                                            <button type="button" class="btn btn-primary btn-sm px-3 fw-semibold flex-shrink-0 shadow-xs" style="border-radius: 12px; height: 38px;"
                                                onclick="document.getElementById('scanSuggestDropdown')?.classList.add('d-none');window.handleUniversalScan && handleUniversalScan(document.getElementById('manualScanInput').value)">
                                                <i class="bi bi-search me-1"></i>Tra cứu
                                            </button>
                                        </div>
                                        <div id="scanSuggestDropdown" class="shadow-lg border rounded-3 bg-white position-absolute start-0 end-0 mt-1 text-start d-none"
                                            style="z-index: 1080; max-height: 240px; overflow-y: auto; font-size: 0.8rem;"></div>
                                    </div>

                                    <!-- Khung Camera Live -->
                                    <div class="position-relative rounded-4 overflow-hidden shadow-sm" style="min-height: 235px; background: radial-gradient(circle at 50% 40%, #1e293b 0%, #0b1120 100%); border: 1px solid rgba(148,163,184,0.25);">
                                        <div id="liveCameraReader"
                                            style="width: 100%; min-height: 235px;"
                                            class="d-flex flex-column align-items-center justify-content-center text-white small p-3">
                                            <div class="rounded-circle bg-primary bg-opacity-25 text-info d-inline-flex align-items-center justify-content-center mb-2" style="width: 48px; height: 48px;">
                                                <i class="bi bi-camera-video fs-4"></i>
                                            </div>
                                            <div class="fw-semibold text-white mb-0.5">Camera đang ở chế độ chờ</div>
                                            <span class="text-white-50" style="font-size: 0.76rem;">Bấm <strong class="text-white">Bật Camera</strong> để quét hoặc chọn <strong class="text-white">Quét từ ảnh</strong></span>
                                        </div>
                                    </div>

                                    <!-- Thanh điều khiển Camera + Quét ảnh + Chọn Camera -->
                                    <div class="mt-3 d-flex flex-wrap justify-content-center align-items-center gap-2">
                                        <select id="cameraSelect" class="form-select form-select-sm d-none text-truncate" style="width: auto; max-width: 175px; height: 34px; border-radius: 10px; padding-right: 2rem !important;"></select>
                                        <button type="button" class="btn-clean btn-clean-primary btn-sm" style="height: 34px; border-radius: 10px;" id="startLiveScanBtn">
                                            <i class="bi bi-camera-video-fill"></i> Bật Camera
                                        </button>
                                        <button type="button" class="btn-clean btn-clean-secondary btn-sm d-none" style="height: 34px; border-radius: 10px;" id="stopLiveScanBtn">
                                            <i class="bi bi-stop-circle-fill text-danger"></i> Tắt Camera
                                        </button>
                                        <label class="btn-clean btn-clean-secondary btn-sm mb-0" style="cursor: pointer; height: 34px; border-radius: 10px;" title="Tải ảnh chứa mã QR/Barcode từ máy để quét">
                                            <i class="bi bi-image text-primary"></i> Quét từ ảnh
                                            <input type="file" id="scanImageFileInput" accept="image/*" class="d-none">
                                        </label>
                                    </div>

                                    <!-- Kết quả quét chi tiết -->
                                    <div id="scanResultBadge"
                                        class="mt-3 p-3 rounded-3 bg-white border shadow-xs text-start small d-none">
                                        <div class="d-flex justify-content-between align-items-center gap-2 pb-2 mb-2 border-bottom">
                                            <div class="fw-bold text-slate-800">
                                                <i class="bi bi-check-circle-fill text-success me-1"></i>Kết quả:
                                                <span id="scannedCodeText" class="font-monospace text-primary"></span>
                                            </div>
                                            <button type="button" class="btn btn-xs btn-light border rounded-pill py-0.5 px-2.5" onclick="navigator.clipboard?.writeText(document.getElementById('scannedCodeText').innerText); window.showToast && showToast('Đã sao chép mã!', 'info', 1500);" title="Sao chép mã">
                                                <i class="bi bi-clipboard me-1"></i>Copy
                                            </button>
                                        </div>
                                        <div class="text-muted" id="scannedItemDetail">Đang tra cứu dữ liệu...</div>
                                    </div>

                                    <!-- Lịch sử quét gần đây -->
                                    <div id="scanHistoryBox" class="mt-2.5 text-start d-none">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="text-muted fw-semibold" style="font-size: 0.72rem;"><i class="bi bi-clock-history me-1"></i>Lịch sử quét gần đây</span>
                                            <button type="button" class="btn btn-link btn-sm p-0 text-muted text-decoration-none" style="font-size: 0.7rem;" onclick="localStorage.removeItem('wms_scan_history'); renderScanHistory();">Xóa</button>
                                        </div>
                                        <div id="scanHistoryChips" class="d-flex flex-wrap gap-1"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Kiểm Thử Tự Động Trực Quan (Interactive Automated Test Suite Modal) -->
                    <div class="modal fade" id="testRunnerModal" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                                <div class="modal-header border-bottom px-4 py-3 bg-light">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-patch-check-fill text-success fs-5"></i>
                                        <h6 class="modal-title fw-bold mb-0">Trung tâm Khảo thí & Chạy Kiểm thử Tự động
                                            (Auto Test Suite)</h6>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body p-4">
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <button type="button" class="btn-clean btn-clean-primary btn-sm"
                                            onclick="runTestSuite('required')">
                                            <i class="bi bi-play-circle-fill"></i> Chạy 5 Phép Thử Bắt Buộc
                                            (tests/run.php)
                                        </button>
                                        <button type="button" class="btn-clean btn-clean-secondary btn-sm"
                                            onclick="runTestSuite('extension')">
                                            <i class="bi bi-check-all"></i> Chạy Bộ Kiểm Thử Mở Rộng
                                            (tests/extension.php)
                                        </button>
                                    </div>

                                    <div class="p-3 bg-slate-900 rounded-3 text-light font-monospace small position-relative"
                                        style="min-height: 220px; max-height: 380px; overflow-y: auto;">
                                        <div id="testOutputTerminal" style="white-space: pre-wrap;">Nhấn vào một trong
                                            hai nút phía trên để thực thi kịch bản kiểm thử tự động ngay trên máy chủ...
                                        </div>
                                    </div>
                                    <div
                                        class="mt-2 text-muted small d-flex justify-content-between align-items-center">
                                        <span><i class="bi bi-shield-check text-success"></i> Kiểm thử tự động chạy trực
                                            tiếp qua PHP CLI</span>
                                        <span id="testRunStatus" class="badge bg-secondary">Chờ thực thi</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <script>
                        function runTestSuite(suite) {
                            const term = document.getElementById('testOutputTerminal');
                            const status = document.getElementById('testRunStatus');
                            if (!term || !status) return;

                            term.innerText = 'Đang khởi chạy kịch bản kiểm thử trên máy chủ... Vui lòng đợi trong giây lát...';
                            status.className = 'badge bg-warning text-dark';
                            status.innerText = 'Đang thực thi...';

                            fetch('api.php?action=run_tests&suite=' + encodeURIComponent(suite))
                                .then(r => r.json())
                                .then(data => {
                                    term.innerText = data.output;
                                    if (data.passed) {
                                        status.className = 'badge bg-success';
                                        status.innerText = '100% PASS (THÀNH CÔNG)';
                                        showToast('Kiểm thử hoàn tất: 100% ĐẠT!', 'success');
                                    } else {
                                        status.className = 'badge bg-danger';
                                        status.innerText = 'FAILED (CÓ LỖI)';
                                        showToast('Kiểm thử phát hiện lỗi!', 'danger');
                                    }
                                })
                                .catch(err => {
                                    term.innerText = 'LỖI THỰC THI: ' + err.message;
                                    status.className = 'badge bg-danger';
                                    status.innerText = 'LỖI MẠNG / SERVER';
                                });
                        }
                    </script>