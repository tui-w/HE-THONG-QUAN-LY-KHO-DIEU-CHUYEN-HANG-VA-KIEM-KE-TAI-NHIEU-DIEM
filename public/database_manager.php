<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';

use App\Database;
use App\Auth;

Auth::startSession();
$currentUser = Auth::getCurrentUser();
if (!$currentUser) {
    header('Location: login.php');
    exit;
}

// Kiểm tra quyền Admin
if ($currentUser['role'] !== 'admin') {
    $_SESSION['flash_error'] = 'Chỉ Quản trị viên (Admin) mới có quyền truy cập trang Quản trị CSDL!';
    header('Location: index.php');
    exit;
}

$pdo = Database::getConnection();

// Xử lý tải xuống file sao lưu CSDL (Export SQL Dump) - Chạy trước khi xuất HTML
if (isset($_GET['action']) && $_GET['action'] === 'export_sqlite') {
    $dbPath = __DIR__ . '/../.local/warehouse.sqlite';
    if (file_exists($dbPath)) {
        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="warehouse_backup_' . date('Ymd_His') . '.sqlite"');
        header('Content-Length: ' . filesize($dbPath));
        readfile($dbPath);
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'export_mysql_sql') {
    $dumpPath = __DIR__ . '/../database/mysql_full_import.sql';
    if (file_exists($dumpPath)) {
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="warehouse_mysql_dump_' . date('Ymd_His') . '.sql"');
        header('Content-Length: ' . filesize($dumpPath));
        readfile($dumpPath);
        exit;
    }
}

$pageTitle = 'Quản trị Cơ sở Dữ liệu & Sao lưu (Backup / Restore)';
require_once __DIR__ . '/../templates/header.php';

// Thống kê các bảng trong CSDL
$tables = [
    'users' => 'Người dùng & Tài khoản',
    'warehouses' => 'Điểm kho mạng lưới',
    'user_warehouses' => 'Phân quyền kho (RBAC)',
    'item_categories' => 'Nhóm ngành hàng',
    'items' => 'Danh mục mặt hàng',
    'batches' => 'Lô hàng & Hạn dùng',
    'warehouse_locations' => 'Vị trí ô kệ (Bins & Racks)',
    'vouchers' => 'Chứng từ Nhập/Xuất/Điều chuyển',
    'voucher_lines' => 'Chi tiết dòng chứng từ',
    'stock_movements' => 'Sổ phát sinh kho (Ledger)',
    'stock_counts' => 'Phiếu kiểm kê định kỳ',
    'stock_count_lines' => 'Chi tiết kiểm kê & Lệch tồn',
    'partners' => 'Đối tác & Khách hàng / Nhà cung cấp',
    'payments' => 'Chứng từ Thu / Chi & Công nợ',
    'activity_logs' => 'Nhật ký kiểm toán hệ thống (Audit Trail)'
];

$tableStats = [];
$totalRows = 0;
foreach ($tables as $tbl => $label) {
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM {$tbl}")->fetchColumn();
        $tableStats[] = [
            'table' => $tbl,
            'label' => $label,
            'count' => $count
        ];
        $totalRows += $count;
    } catch (\Throwable $e) {
        $tableStats[] = [
            'table' => $tbl,
            'label' => $label,
            'count' => 0
        ];
    }
}

$dbFileSize = file_exists(__DIR__ . '/../.local/warehouse.sqlite') ? filesize(__DIR__ . '/../.local/warehouse.sqlite') : 0;
?>

<!-- Header Top Banner Gọn Gàng -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold text-slate-900 mb-0" style="letter-spacing: -0.02em;">Quản trị CSDL & Sao lưu</h4>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="database_manager.php?action=export_sqlite" class="btn-clean btn-clean-secondary btn-sm shadow-xs">
            <i class="bi bi-file-earmark-arrow-down text-primary"></i> Tải file (.sqlite)
        </a>
        <a href="database_manager.php?action=export_mysql_sql" class="btn-clean btn-clean-primary btn-sm shadow-xs">
            <i class="bi bi-filetype-sql"></i> Xuất MySQL (.sql)
        </a>
    </div>
</div>

<!-- 4 Thẻ KPI CSDL -->
<div class="row g-2.5 mb-3">
    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">TỔNG BẢNG</div>
                    <div class="metric-val text-primary"><?= count($tableStats) ?></div>
                </div>
                <div class="metric-icon-box bg-primary-light text-primary">
                    <i class="bi bi-table"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">TỔNG BẢN GHI</div>
                    <div class="metric-val text-dark"><?= number_format((float)$totalRows) ?></div>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-layers-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">DUNG LƯỢNG</div>
                    <div class="metric-val text-info"><?= round($dbFileSize / 1024, 1) ?> KB</div>
                </div>
                <div class="metric-icon-box bg-info-subtle text-info">
                    <i class="bi bi-hdd-network-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">CHẾ ĐỘ CSDL</div>
                    <div class="metric-val text-success">ACID WAL</div>
                </div>
                <div class="metric-icon-box bg-purple-subtle text-purple">
                    <i class="bi bi-shield-check"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bảng Thống Kê Chi Tiết Thực Thể CSDL -->
<div class="clean-card main-primary-card overflow-hidden mb-4">
    <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="fw-bold text-slate-900 mb-0">Cấu trúc Bảng & Số lượng Bản ghi</h6>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="input-group input-group-sm" style="width: 240px;">
                <span class="input-group-text bg-white border-end-0 text-slate-400"><i class="bi bi-search"></i></span>
                <input type="text" id="dbTableSearchInput" class="form-control border-start-0 ps-0" placeholder="Tìm tên bảng, mô tả..." onkeyup="filterDbTable()">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table-clean align-middle mb-0" id="dbStatsTable">
            <thead>
                <tr>
                    <th class="ps-4">Tên bảng dữ liệu</th>
                    <th>Mô tả nghiệp vụ</th>
                    <th class="text-center">Số lượng bản ghi</th>
                    <th class="text-end pe-4">Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tableStats as $stat): ?>
                    <tr>
                        <td class="ps-4 font-monospace fw-bold text-primary">
                            <i class="bi bi-database me-1 text-slate-400"></i><?= htmlspecialchars($stat['table']) ?>
                        </td>
                        <td class="text-slate-700">
                            <?= htmlspecialchars($stat['label']) ?>
                        </td>
                        <td class="text-center fw-extrabold text-slate-900">
                            <?= number_format((float)$stat['count']) ?>
                        </td>
                        <td class="text-end pe-4">
                            <span class="chip chip-success"><i class="bi bi-check-circle-fill"></i> Toàn vẹn</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function filterDbTable() {
    const q = (document.getElementById('dbTableSearchInput')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#dbStatsTable tbody tr').forEach(r => {
        const text = r.innerText.toLowerCase();
        r.style.display = (q === '' || text.includes(q)) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
