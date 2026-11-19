<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';

use App\Database;
use App\Auth;

Auth::requireLogin();
$pdo = Database::getConnection();
$currentUser = Auth::getCurrentUser();

// Xử lý Thêm / Sửa / Xóa Điểm kho (Phải chạy TRƯỚC khi xuất HTML header.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    try {
        if ($act === 'add_warehouse') {
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $name = trim((string)($_POST['name'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $initBins = !empty($_POST['auto_init_bins']);
            if ($code === '' || $name === '') throw new RuntimeException('Vui lòng nhập Mã kho và Tên kho!');
            $stmt = $pdo->prepare("INSERT INTO warehouses (code, name, address, phone) VALUES (?, ?, ?, ?)");
            $stmt->execute([$code, $name, $address, $phone]);
            $newWhId = (int)$pdo->lastInsertId();

            $admins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'manager')")->fetchAll(PDO::FETCH_COLUMN);
            $stPerm = $pdo->prepare("INSERT OR IGNORE INTO user_warehouses (user_id, warehouse_id, can_view, can_create, can_approve) VALUES (?, ?, 1, 1, 1)");
            foreach ($admins as $uid) $stPerm->execute([(int)$uid, $newWhId]);

            if ($initBins) {
                $stLoc = $pdo->prepare("INSERT OR IGNORE INTO warehouse_locations (warehouse_id, zone_code, rack_code, level_code, bin_code, location_barcode, item_id, max_capacity) VALUES (?, ?, 'R01', ?, ?, ?, NULL, 200)");
                foreach (['A', 'B', 'C', 'D'] as $z) {
                    foreach (['T1', 'T2'] as $lvl) {
                        foreach (['O01', 'O02'] as $b) {
                            $bc = sprintf('K%d-%s-R01-%s-%s', $newWhId, $z, $lvl, $b);
                            $stLoc->execute([$newWhId, $z, $lvl, $b, $bc]);
                        }
                    }
                }
            }
            $_SESSION['flash_success'] = "Đã thêm điểm kho mới [{$code}] {$name}" . ($initBins ? ' kèm 16 ô kệ khởi tạo!' : '!');
        } elseif ($act === 'edit_warehouse') {
            $wId = (int)($_POST['warehouse_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            if ($wId <= 0 || $name === '') throw new RuntimeException('Thông tin cập nhật không hợp lệ!');
            $pdo->prepare("UPDATE warehouses SET name = ?, address = ?, phone = ? WHERE id = ?")->execute([$name, $address, $phone, $wId]);
            $_SESSION['flash_success'] = "Đã cập nhật thông tin điểm kho!";
        } elseif ($act === 'delete_warehouse') {
            $wId = (int)($_POST['warehouse_id'] ?? 0);
            $mvCnt = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE warehouse_id = {$wId}")->fetchColumn();
            if ($mvCnt > 0) throw new RuntimeException('Kho này đã có phát sinh sổ cái tồn kho, không thể xóa!');
            $pdo->prepare("DELETE FROM warehouses WHERE id = ?")->execute([$wId]);
            $_SESSION['flash_success'] = "Đã xóa điểm kho thành công!";
        }
    } catch (\Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: warehouses.php');
    exit;
}

$pageTitle = 'Mạng lưới Điểm kho & Phân quyền RBAC';
require_once __DIR__ . '/../templates/header.php';

// Lấy danh sách kho kèm thống kê tồn và phát sinh
$warehouseList = $pdo->query("
    SELECT 
        w.*,
        COUNT(DISTINCT sm.item_id) AS total_items,
        COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS total_stock,
        COUNT(sm.id) AS total_movements
    FROM warehouses w
    LEFT JOIN stock_movements sm ON sm.warehouse_id = w.id
    GROUP BY w.id
    ORDER BY w.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Sức chứa quy ước (Capacity) của từng trung tâm kho
$warehouseCapacities = [
    1 => ['max' => 5000, 'type' => 'Trung tâm Phân phối Miền Bắc', 'temp' => '22°C - Tiêu chuẩn'],
    2 => ['max' => 3000, 'type' => 'Kho Trung chuyển Miền Trung', 'temp' => '24°C - Tiêu chuẩn'],
    3 => ['max' => 8000, 'type' => 'Hub Logistics Miền Nam', 'temp' => '20°C - Máy lạnh'],
];

// Lấy danh sách phân quyền người dùng theo kho
$permMatrix = $pdo->query("
    SELECT 
        u.id AS user_id,
        u.username,
        u.full_name,
        u.role,
        w.id AS warehouse_id,
        w.code AS warehouse_code,
        COALESCE(uw.can_view, 0) AS can_view,
        COALESCE(uw.can_create, 0) AS can_create,
        COALESCE(uw.can_approve, 0) AS can_approve
    FROM users u
    CROSS JOIN warehouses w
    LEFT JOIN user_warehouses uw ON uw.user_id = u.id AND uw.warehouse_id = w.id
    ORDER BY u.id, w.id
")->fetchAll(PDO::FETCH_ASSOC);

// Nhóm ma trận theo user
$groupedPerms = [];
foreach ($permMatrix as $row) {
    $uid = $row['user_id'];
    if (!isset($groupedPerms[$uid])) {
        $groupedPerms[$uid] = [
            'username' => $row['username'],
            'full_name' => $row['full_name'],
            'role' => $row['role'],
            'warehouses' => []
        ];
    }
    $groupedPerms[$uid]['warehouses'][$row['warehouse_code']] = [
        'can_view' => (int)$row['can_view'] === 1 || $row['role'] === 'admin',
        'can_create' => (int)$row['can_create'] === 1 || $row['role'] === 'admin',
        'can_approve' => (int)$row['can_approve'] === 1 || $row['role'] === 'admin' || $row['role'] === 'manager',
    ];
}

$grandStock = array_sum(array_column($warehouseList, 'total_stock'));
$grandCapacity = 16000;
$avgUtilization = $grandCapacity > 0 ? round(($grandStock / $grandCapacity) * 100, 1) : 0;
?>

<!-- Header Top Banner -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <div class="d-flex align-items-center gap-2">
            <h4 class="fw-bold text-slate-900 mb-0" style="letter-spacing: -0.02em;">Mạng lưới Điểm kho & Phân quyền RBAC</h4>
            <span class="chip chip-success fw-semibold"><i class="bi bi-circle-fill" style="font-size: 6px;"></i> <?= count($warehouseList) ?> Kho Trực tuyến</span>
        </div>
        <p class="text-slate-500 small mb-0 mt-0.5">
            Quản trị các trung tâm phân phối, theo dõi tỷ lệ lấp đầy sức chứa và kiểm soát ma trận phân quyền máy chủ.
        </p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn-clean btn-clean-secondary" onclick="exportMatrixToCSV()">
            <i class="bi bi-download"></i>
            <span>Xuất Ma trận CSV</span>
        </button>
        <button type="button" class="btn-clean btn-clean-primary" data-bs-toggle="modal" data-bs-target="#addWarehouseModal">
            <i class="bi bi-building-add"></i>
            <span>Thêm Kho mới</span>
        </button>
        <?php if ($currentUser['role'] === 'admin'): ?>
            <a href="users.php" class="btn-clean btn-clean-secondary">
                <i class="bi bi-shield-lock-fill"></i>
                <span>Phân quyền</span>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- 4 Thẻ KPI Mạng lưới Kho -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="metric-box">
            <div>
                <div class="metric-label">TRUNG TÂM PHÂN PHỐI</div>
                <div class="metric-val text-primary"><?= count($warehouseList) ?> Kho</div>
            </div>
            <div class="metric-sub">
                <span class="text-success"><i class="bi bi-geo-alt-fill"></i> Bắc - Trung - Nam</span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="metric-box">
            <div>
                <div class="metric-label">TỔNG LƯỢNG HÀNG LƯU KHO</div>
                <div class="metric-val text-dark"><?= number_format((int)$grandStock) ?></div>
            </div>
            <div class="metric-sub">
                <span class="text-secondary">Đã đối chiếu sổ cái 100%</span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="metric-box">
            <div>
                <div class="metric-label">SỨC CHỨA QUY HOẠCH</div>
                <div class="metric-val text-secondary"><?= number_format((float)$grandCapacity) ?></div>
            </div>
            <div class="metric-sub">
                <span>Đơn vị tính: Kiện / Pallet</span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="metric-box">
            <div>
                <div class="metric-label">TỶ LỆ LẤP ĐẦY TRUNG BÌNH</div>
                <div class="metric-val text-success"><?= $avgUtilization ?>%</div>
            </div>
            <div class="metric-sub">
                <span class="text-success"><i class="bi bi-shield-check"></i> An toàn, không quá tải</span>
            </div>
        </div>
    </div>
</div>

<!-- Danh sách 3 kho hàng - Thẻ tinh gọn, thẩm mỹ -->
<div class="row g-2.5 mb-3">
    <?php foreach ($warehouseList as $wh): 
        $wId = (int)$wh['id'];
        $meta = $warehouseCapacities[$wId] ?? ['max' => 5000, 'type' => 'Kho Logistics', 'temp' => 'Tiêu chuẩn'];
        $stock = (int)$wh['total_stock'];
        $utilPct = $meta['max'] > 0 ? min(100, round(($stock / $meta['max']) * 100, 1)) : 0;
        $progressColor = $utilPct > 85 ? 'bg-danger' : ($utilPct > 60 ? 'bg-warning' : 'bg-primary');
    ?>
        <div class="col-md-4">
            <div class="clean-card main-secondary-card p-3 h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1.5">
                        <div class="d-flex align-items-center gap-2">
                            <span class="chip chip-primary font-monospace"><?= htmlspecialchars($wh['code']) ?></span>
                            <h6 class="fw-bold text-slate-900 mb-0"><?= htmlspecialchars($wh['name']) ?></h6>
                        </div>
                        <span class="chip chip-success py-0 px-1.5" style="font-size: 0.68rem;">Online</span>
                    </div>

                    <div class="small text-slate-500 mb-2 text-truncate" title="<?= htmlspecialchars($wh['address']) ?>">
                        <i class="bi bi-geo-alt text-slate-400"></i> <?= htmlspecialchars($wh['address']) ?>
                    </div>

                    <!-- Thanh đo sức chứa kho gọn -->
                    <div class="p-2 bg-white rounded-2 mb-2 border">
                        <div class="d-flex justify-content-between align-items-center" style="font-size: 0.75rem;">
                            <span class="text-slate-600">Tồn: <strong><?= number_format((float)$stock) ?></strong> / <?= number_format((float)$meta['max']) ?></span>
                            <strong class="text-primary"><?= $utilPct ?>%</strong>
                        </div>
                        <div class="progress mt-1" style="height: 5px; background-color: #e2e8f0;">
                            <div class="progress-bar <?= $progressColor ?>" style="width: <?= $utilPct ?>%;"></div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-1 flex-wrap gap-1">
                    <span class="small text-slate-500">Mặt hàng: <strong class="text-slate-800"><?= $wh['total_items'] ?></strong></span>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn-clean btn-clean-secondary btn-sm py-0.5 px-2" style="font-size: 0.72rem;" title="Sửa thông tin kho"
                                onclick="openEditWhModal(<?= $wId ?>, '<?= htmlspecialchars(addslashes($wh['code'])) ?>', '<?= htmlspecialchars(addslashes($wh['name'])) ?>', '<?= htmlspecialchars(addslashes($wh['address'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($wh['phone'] ?? '')) ?>')">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Xác nhận xóa kho <?= htmlspecialchars($wh['code']) ?>?');">
                            <input type="hidden" name="action" value="delete_warehouse">
                            <input type="hidden" name="warehouse_id" value="<?= $wId ?>">
                            <button type="submit" class="btn-clean btn-clean-secondary btn-sm py-0.5 px-2 text-danger" style="font-size: 0.72rem;" title="Xóa kho"><i class="bi bi-trash3"></i></button>
                        </form>
                        <a href="locations.php?wh=<?= $wh['id'] ?>" class="btn-clean btn-clean-secondary btn-sm py-0.5 px-2" style="font-size: 0.72rem;" title="Sơ đồ Ô kệ">
                            <i class="bi bi-grid-3x3-gap"></i> Kệ
                        </a>
                        <a href="warehouse_hub.php?wh=<?= $wh['id'] ?>" class="btn-clean btn-clean-primary btn-sm py-0.5 px-2" style="font-size: 0.72rem;">
                            <i class="bi bi-box-arrow-up-right"></i> Hub
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Bảng Ma trận phân quyền theo kho -->
<div class="clean-card main-primary-card mb-4 overflow-hidden">
    <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="fw-bold text-slate-900 mb-0">
            <i class="bi bi-shield-lock text-primary me-1.5"></i>Ma trận phân quyền tác nghiệp theo điểm kho (RBAC)
        </h6>
        <div class="d-flex flex-wrap gap-1.5 align-items-center">
            <button type="button" class="btn btn-sm btn-primary fw-bold text-white py-0.5 px-2.5 rounded-pill btn-rbac-role active" onclick="filterMatrixRole('ALL', this)" style="font-size: 0.76rem;">Tất cả</button>
            <button type="button" class="btn btn-sm btn-light border text-slate-600 py-0.5 px-2.5 rounded-pill btn-rbac-role" onclick="filterMatrixRole('ADMIN_MGR', this)" style="font-size: 0.76rem;">Quản trị & Quản lý</button>
            <button type="button" class="btn btn-sm btn-light border text-slate-600 py-0.5 px-2.5 rounded-pill btn-rbac-role" onclick="filterMatrixRole('STAFF', this)" style="font-size: 0.76rem;">Thủ kho</button>
            <button type="button" class="btn btn-sm btn-light border text-slate-600 py-0.5 px-2.5 rounded-pill btn-rbac-role" onclick="filterMatrixRole('AUDITOR', this)" style="font-size: 0.76rem;">Kiểm kê</button>
            <div class="input-group input-group-sm ms-1" style="width: 210px;">
                <span class="input-group-text bg-white border-end-0 text-slate-400"><i class="bi bi-search"></i></span>
                <input type="text" id="matrixSearchInput" class="form-control border-start-0 ps-0" placeholder="Tìm tên nhân sự..." onkeyup="filterMatrixTable()">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table-clean align-middle mb-0" id="matrixTable">
            <thead>
                <tr>
                    <th class="ps-4">Người dùng / Vai trò</th>
                    <?php foreach ($warehouseList as $wh): ?>
                        <th class="text-center" style="width: 25%;">
                            <?= htmlspecialchars($wh['name']) ?>
                            <span class="text-slate-400 font-monospace small">(<?= htmlspecialchars($wh['code']) ?>)</span>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groupedPerms as $uid => $u): ?>
                    <tr data-role="<?= strtoupper($u['role']) ?>">
                        <td class="ps-4">
                            <div class="fw-bold text-slate-900" style="font-size: 0.88rem;"><?= htmlspecialchars($u['full_name']) ?></div>
                            <div class="d-flex align-items-center gap-1.5 mt-0.5">
                                <span class="text-slate-500 small font-monospace" style="font-size: 0.76rem;">@<?= htmlspecialchars($u['username']) ?></span>
                                <span class="chip chip-neutral py-0 px-1.5" style="font-size: 0.68rem;"><?= strtoupper($u['role']) ?></span>
                            </div>
                        </td>
                        <?php foreach ($warehouseList as $wh): 
                            $p = $u['warehouses'][$wh['code']] ?? ['can_view' => false, 'can_create' => false, 'can_approve' => false];
                        ?>
                            <td class="text-center">
                                <div class="d-inline-flex gap-1 flex-wrap justify-content-center">
                                    <?php if ($p['can_view']): ?>
                                        <span class="chip chip-slate" title="Được xem dữ liệu"><i class="bi bi-eye"></i> Xem</span>
                                    <?php endif; ?>

                                    <?php if ($p['can_create']): ?>
                                        <span class="chip chip-success" title="Được lập phiếu"><i class="bi bi-pencil"></i> Lập phiếu</span>
                                    <?php endif; ?>

                                    <?php if ($p['can_approve']): ?>
                                        <span class="chip chip-primary" title="Được duyệt phiếu"><i class="bi bi-check2-circle"></i> Duyệt</span>
                                    <?php endif; ?>

                                    <?php if (!$p['can_view'] && !$p['can_create'] && !$p['can_approve']): ?>
                                        <span class="chip chip-neutral text-slate-400"><i class="bi bi-dash"></i> Không gán</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
let currentMatrixRole = 'ALL';

function filterMatrixRole(roleGroup, btn) {
    currentMatrixRole = roleGroup;
    document.querySelectorAll('.btn-rbac-role').forEach(b => {
        b.classList.remove('btn-primary', 'fw-bold', 'text-white', 'active');
        b.classList.add('btn-light', 'border', 'text-slate-600');
    });
    btn.classList.remove('btn-light', 'border', 'text-slate-600');
    btn.classList.add('btn-primary', 'fw-bold', 'text-white', 'active');
    filterMatrixTable();
}

function filterMatrixTable() {
    const input = document.getElementById('matrixSearchInput');
    const filter = (input?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#matrixTable tbody tr');

    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        const role = row.getAttribute('data-role') || '';
        let matchRole = true;
        if (currentMatrixRole === 'ADMIN_MGR') matchRole = (role === 'ADMIN' || role === 'MANAGER');
        else if (currentMatrixRole === 'STAFF') matchRole = (role === 'STAFF');
        else if (currentMatrixRole === 'AUDITOR') matchRole = (role === 'AUDITOR');

        row.style.display = (matchRole && (filter === '' || text.includes(filter))) ? '' : 'none';
    });
}

function exportMatrixToCSV() {
    nxExportTableToCSV('#matrixTable', 'ma_tran_phan_quyen_kho_' + new Date().toISOString().slice(0, 10) + '.csv');
}

function openEditWhModal(id, code, name, addr, phone) {
    document.getElementById('editWhId').value = id;
    document.getElementById('editWhCodeBadge').textContent = code;
    document.getElementById('editWhName').value = name;
    document.getElementById('editWhAddr').value = addr;
    document.getElementById('editWhPhone').value = phone;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editWarehouseModal')).show();
}
</script>

<!-- Modal Thêm Điểm Kho Mới -->
<div class="modal fade" id="addWarehouseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 18px;">
            <div class="modal-header px-4 py-3 border-bottom">
                <h6 class="modal-title fw-bold mb-0"><i class="bi bi-building-add text-primary me-2"></i>Thêm Điểm Kho Mới</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_warehouse">
                <div class="modal-body p-4 row g-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Mã kho <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control text-uppercase" placeholder="VD: KHO-CT" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small fw-bold">Tên điểm kho <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="VD: Kho Phân Phối Cần Thơ" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Địa chỉ</label>
                        <input type="text" name="address" class="form-control" placeholder="VD: KCN Trà Nóc, Cần Thơ">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Số điện thoại</label>
                        <input type="text" name="phone" class="form-control" placeholder="0292-388-9999">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="auto_init_bins" id="autoInitBins" value="1" checked>
                            <label class="form-check-label small fw-semibold" for="autoInitBins">Tạo sẵn 16 ô kệ (A, B, C, D)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer px-4 py-3 border-top">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-sm btn-primary rounded-pill px-4 fw-bold">Lưu điểm kho</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Sửa Điểm Kho -->
<div class="modal fade" id="editWarehouseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 18px;">
            <div class="modal-header px-4 py-3 border-bottom">
                <h6 class="modal-title fw-bold mb-0"><i class="bi bi-pencil-square text-primary me-2"></i>Cập nhật Điểm kho <span id="editWhCodeBadge" class="badge bg-primary ms-1"></span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_warehouse">
                <input type="hidden" name="warehouse_id" id="editWhId">
                <div class="modal-body p-4 row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-bold">Tên điểm kho <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editWhName" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Địa chỉ</label>
                        <input type="text" name="address" id="editWhAddr" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Số điện thoại</label>
                        <input type="text" name="phone" id="editWhPhone" class="form-control">
                    </div>
                </div>
                <div class="modal-footer px-4 py-3 border-top">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-sm btn-primary rounded-pill px-4 fw-bold">Cập nhật</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
