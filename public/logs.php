<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AuditLogger.php';

use App\Database;
use App\Auth;
use App\AuditLogger;

Auth::startSession();
$currentUser = Auth::getCurrentUser();
if (!$currentUser) {
    header('Location: login.php');
    exit;
}

$role = $currentUser['role'] ?? '';
if (!in_array($role, ['admin', 'manager', 'auditor'], true)) {
    $_SESSION['flash_error'] = 'Bạn không có quyền truy cập trang Nhật ký Hoạt động!';
    header('Location: index.php');
    exit;
}

$pdo = Database::getConnection();

$actionFilter = trim((string)($_GET['action'] ?? ''));
$searchQuery = trim((string)($_GET['q'] ?? ''));
$limit = isset($_GET['limit']) ? max(20, min(500, (int)$_GET['limit'])) : 100;

// Xuất file CSV (Chạy trước header.php để gửi HTTP headers chuẩn tải file)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $logs = AuditLogger::getRecentLogs($pdo, 1000, $actionFilter ?: null, $searchQuery ?: null);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=nhat_ky_hoat_dong_' . date('Ymd_His') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM cho Excel
    
    fputcsv($output, ['ID', 'Thời gian', 'Tài khoản', 'Hành động', 'Loại đối tượng', 'Mã / ID đối tượng', 'Chi tiết thao tác', 'Địa chỉ IP']);
    foreach ($logs as $l) {
        fputcsv($output, [
            $l['id'],
            $l['created_at'],
            $l['username'],
            $l['action'],
            $l['target_type'] ?? '',
            $l['target_id'] ?? '',
            $l['details'] ?? '',
            $l['ip_address'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

$pageTitle = 'Nhật ký Hoạt động & Giám sát Hệ thống';
require_once __DIR__ . '/../templates/header.php';

$logs = AuditLogger::getRecentLogs($pdo, $limit, $actionFilter ?: null, $searchQuery ?: null);

// Thống kê nhanh
$totalLogsCount = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
$loginCount = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'LOGIN'")->fetchColumn();
$approvalCount = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action IN ('APPROVE_VOUCHER', 'CONFIRM_VOUCHER')")->fetchColumn();
$adjustCount = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action IN ('ADJUST_STOCK', 'COUNT_STOCK')")->fetchColumn();
?>

<!-- Top Header Section Gọn Gàng -->
<div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold text-dark mb-0">Nhật ký Hoạt động Hệ thống</h4>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="logs.php?export=csv<?= $actionFilter ? '&action=' . urlencode($actionFilter) : '' ?><?= $searchQuery ? '&q=' . urlencode($searchQuery) : '' ?>" class="btn-clean btn-clean-secondary btn-sm">
            <i class="bi bi-download"></i> Xuất CSV
        </a>
        <a href="logs.php" class="btn-clean btn-clean-secondary btn-sm" title="Tải lại dữ liệu">
            <i class="bi bi-arrow-clockwise"></i> Làm mới
        </a>
    </div>
</div>

<!-- KPI Metric Widgets -->
<div class="row g-2.5 mb-3">
    <div class="col-6 col-xl-3">
        <div class="metric-box">
            <div>
                <div class="metric-label">TỔNG NHẬT KÝ</div>
                <div class="metric-val text-primary"><?= number_format((float)$totalLogsCount) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="metric-box">
            <div>
                <div class="metric-label">PHIÊN ĐĂNG NHẬP</div>
                <div class="metric-val text-dark"><?= number_format((float)$loginCount) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="metric-box">
            <div>
                <div class="metric-label">DUYỆT CHỨNG TỪ</div>
                <div class="metric-val text-success"><?= number_format((float)$approvalCount) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="metric-box">
            <div>
                <div class="metric-label">KIỂM KÊ & Đ.CHỈNH</div>
                <div class="metric-val text-warning"><?= number_format((float)$adjustCount) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Logs Table Card Tích Hợp Tìm Kiếm & Lọc Nhanh -->
<div class="clean-card main-primary-card overflow-hidden mb-4">
    <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
            <h6 class="fw-bold text-dark mb-0">Danh sách bản ghi kiểm toán</h6>
            <span class="badge bg-light text-secondary border"><?= count($logs) ?> dòng</span>
        </div>
        <div class="d-flex flex-wrap gap-1.5 align-items-center">
            <a href="logs.php" class="btn btn-sm <?= $actionFilter === '' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">Tất cả</a>
            <a href="logs.php?action=LOGIN" class="btn btn-sm <?= $actionFilter === 'LOGIN' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">Đăng nhập</a>
            <a href="logs.php?action=CREATE_VOUCHER" class="btn btn-sm <?= $actionFilter === 'CREATE_VOUCHER' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">Tạo phiếu</a>
            <a href="logs.php?action=APPROVE_VOUCHER" class="btn btn-sm <?= $actionFilter === 'APPROVE_VOUCHER' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">Duyệt phiếu</a>
            <a href="logs.php?action=TRANSFER_STOCK" class="btn btn-sm <?= $actionFilter === 'TRANSFER_STOCK' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">Điều chuyển</a>
        </div>
    </div>

    <div class="bg-slate-50 border-bottom px-3 py-2">
        <form method="GET" action="logs.php" class="row g-2 align-items-center m-0">
            <div class="col-md-5 ps-0">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control border-start-0 ps-0" placeholder="Tìm theo username, mã phiếu, chi tiết..." value="<?= htmlspecialchars($searchQuery) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="action" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Hành động: Tất cả</option>
                    <option value="LOGIN" <?= $actionFilter === 'LOGIN' ? 'selected' : '' ?>>Đăng nhập (LOGIN)</option>
                    <option value="LOGOUT" <?= $actionFilter === 'LOGOUT' ? 'selected' : '' ?>>Đăng xuất (LOGOUT)</option>
                    <option value="CREATE_VOUCHER" <?= $actionFilter === 'CREATE_VOUCHER' ? 'selected' : '' ?>>Tạo chứng từ (CREATE_VOUCHER)</option>
                    <option value="APPROVE_VOUCHER" <?= $actionFilter === 'APPROVE_VOUCHER' ? 'selected' : '' ?>>Duyệt chứng từ (APPROVE_VOUCHER)</option>
                    <option value="TRANSFER_STOCK" <?= $actionFilter === 'TRANSFER_STOCK' ? 'selected' : '' ?>>Điều chuyển kho (TRANSFER_STOCK)</option>
                    <option value="COUNT_STOCK" <?= $actionFilter === 'COUNT_STOCK' ? 'selected' : '' ?>>Kiểm kê (COUNT_STOCK)</option>
                    <option value="ADJUST_STOCK" <?= $actionFilter === 'ADJUST_STOCK' ? 'selected' : '' ?>>Điều chỉnh tồn (ADJUST_STOCK)</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="limit" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50 dòng</option>
                    <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100 dòng</option>
                    <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200 dòng</option>
                    <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500 dòng</option>
                </select>
            </div>
            <div class="col-md-2 pe-0 d-flex gap-1.5">
                <button type="submit" class="btn-clean btn-clean-primary btn-sm flex-grow-1 py-1 justify-content-center">
                    <i class="bi bi-funnel"></i> Lọc
                </button>
                <?php if ($actionFilter || $searchQuery): ?>
                    <a href="logs.php" class="btn btn-sm btn-outline-secondary py-1 px-2"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table modern-table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 70px;">ID</th>
                    <th style="width: 170px;">Thời gian</th>
                    <th style="width: 150px;">Người thực hiện</th>
                    <th style="width: 160px;">Hành động</th>
                    <th style="width: 150px;">Đối tượng</th>
                    <th>Nội dung chi tiết</th>
                    <th style="width: 120px;" class="text-end">Địa chỉ IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-journal-x fs-2 d-block mb-2 opacity-50"></i>
                            Không tìm thấy bản ghi nhật ký nào phù hợp với bộ lọc.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <?php
                        $action = $l['action'];
                        $badgeClass = match($action) {
                            'LOGIN' => 'bg-info-subtle text-info border border-info-subtle',
                            'LOGOUT' => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                            'CREATE_VOUCHER' => 'bg-primary-subtle text-primary border border-primary-subtle',
                            'APPROVE_VOUCHER', 'CONFIRM_VOUCHER' => 'bg-success-subtle text-success border border-success-subtle',
                            'CANCEL_VOUCHER' => 'bg-danger-subtle text-danger border border-danger-subtle',
                            'TRANSFER_STOCK' => 'bg-purple-subtle text-purple border border-purple-subtle',
                            'COUNT_STOCK' => 'bg-warning-subtle text-warning border border-warning-subtle',
                            'ADJUST_STOCK' => 'bg-warning-subtle text-warning border border-warning-subtle',
                            'SYSTEM_INIT' => 'bg-dark-subtle text-dark border border-dark-subtle',
                            default => 'bg-light text-dark border'
                        };

                        $icon = match($action) {
                            'LOGIN' => 'bi-box-arrow-in-right',
                            'LOGOUT' => 'bi-box-arrow-right',
                            'CREATE_VOUCHER' => 'bi-file-earmark-plus',
                            'APPROVE_VOUCHER', 'CONFIRM_VOUCHER' => 'bi-check-circle',
                            'CANCEL_VOUCHER' => 'bi-x-circle',
                            'TRANSFER_STOCK' => 'bi-arrow-left-right',
                            'COUNT_STOCK' => 'bi-clipboard-check',
                            'ADJUST_STOCK' => 'bi-sliders',
                            'UPDATE_USER' => 'bi-person-gear',
                            'SYSTEM_INIT' => 'bi-gear-wide-connected',
                            default => 'bi-activity'
                        };
                        ?>
                        <tr>
                            <td class="font-monospace text-muted small">#<?= $l['id'] ?></td>
                            <td class="text-nowrap small text-secondary">
                                <i class="bi bi-clock me-1"></i><?= htmlspecialchars($l['created_at']) ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-1.5">
                                    <div class="user-avatar-sm">
                                        <?= mb_strtoupper(mb_substr($l['username'], 0, 1)) ?>
                                    </div>
                                    <strong class="text-dark small"><?= htmlspecialchars($l['username']) ?></strong>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $badgeClass ?> px-2 py-1 small">
                                    <i class="bi <?= $icon ?> me-1"></i><?= htmlspecialchars($l['action']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($l['target_id']): ?>
                                    <span class="badge bg-light text-dark border font-monospace small">
                                        <?= htmlspecialchars($l['target_type'] ? $l['target_type'] . ':' : '') ?><?= htmlspecialchars($l['target_id']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-dark">
                                <?= htmlspecialchars($l['details'] ?? '') ?>
                            </td>
                            <td class="text-end font-monospace text-muted small">
                                <?= htmlspecialchars($l['ip_address'] ?? '127.0.0.1') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
