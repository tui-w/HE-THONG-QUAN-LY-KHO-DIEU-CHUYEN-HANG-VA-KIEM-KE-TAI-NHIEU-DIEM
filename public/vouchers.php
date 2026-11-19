<?php
declare(strict_types=1);

$pageTitle = 'Phiếu Nhập / Xuất kho';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../src/StockService.php';

use App\StockService;
use App\Auth;

$whFilter = isset($_GET['wh']) && $_GET['wh'] !== '' ? (int)$_GET['wh'] : null;
$typeFilter = trim((string)($_GET['type'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$fromDateFilter = trim((string)($_GET['from_date'] ?? ''));
$toDateFilter = trim((string)($_GET['to_date'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

// Xử lý tạo phiếu mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_voucher') {
    try {
        $type = (string)$_POST['type'];
        $warehouseId = (int)$_POST['warehouse_id'];
        $note = trim((string)($_POST['note'] ?? ''));
        $itemIds = $_POST['item_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $batchIds = $_POST['batch_id'] ?? [];
        $prices = $_POST['unit_price'] ?? [];

        $lines = [];
        for ($i = 0; $i < count($itemIds); $i++) {
            $qty = (int)($quantities[$i] ?? 0);
            if ($qty > 0 && !empty($itemIds[$i])) {
                $lines[] = [
                    'item_id' => (int)$itemIds[$i],
                    'batch_id' => !empty($batchIds[$i]) ? (int)$batchIds[$i] : null,
                    'quantity' => $qty,
                    'unit_price' => (float)($prices[$i] ?? 0),
                    'note' => $note
                ];
            }
        }

        if (empty($lines)) {
            throw new \RuntimeException('Vui lòng chọn ít nhất một mặt hàng và số lượng > 0');
        }

        $sourceWhId = ($type === 'OUT' || $type === 'ADJUST_OUT') ? $warehouseId : null;
        $destWhId = ($type === 'IN' || $type === 'ADJUST_IN') ? $warehouseId : null;

        $newVoucherId = StockService::createVoucher(
            $pdo,
            $type,
            $sourceWhId,
            $destWhId,
            $lines,
            (int)$currentUser['id'],
            $note
        );

        // Nếu người tạo có quyền duyệt và chọn xác nhận ngay
        if (!empty($_POST['auto_confirm']) && Auth::checkWarehousePermission($pdo, (int)$currentUser['id'], $warehouseId, 'approve')) {
            StockService::confirmVoucher($pdo, $newVoucherId, (int)$currentUser['id']);
            $_SESSION['flash_success'] = "Tạo và xác nhận ghi sổ chứng từ thành công!";
        } else {
            $_SESSION['flash_success'] = "Tạo phiếu mới ở trạng thái DRAFT thành công. Cần quản lý kho duyệt để ghi nhận phát sinh!";
        }

        header('Location: vouchers.php');
        exit;
    } catch (\Exception $e) {
        $_SESSION['flash_error'] = "LỖI: " . $e->getMessage();
        header('Location: vouchers.php');
        exit;
    }
}

// Xử lý xác nhận phiếu (Confirm Voucher)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_voucher') {
    try {
        $voucherId = (int)$_POST['voucher_id'];
        StockService::confirmVoucher($pdo, $voucherId, (int)$currentUser['id']);
        $_SESSION['flash_success'] = "Xác nhận chứng từ thành công! Đã ghi sổ phát sinh và cập nhật tồn kho.";
    } catch (\Exception $e) {
        $_SESSION['flash_error'] = "XÁC NHẬN THẤT BẠI: " . $e->getMessage();
    }
    header('Location: vouchers.php');
    exit;
}

// Danh sách chứng từ
$sql = "
    SELECT 
        v.*,
        wSrc.code AS source_code,
        wSrc.name AS source_name,
        wDest.code AS dest_code,
        wDest.name AS dest_name,
        uCreate.full_name AS creator_name,
        uApprove.full_name AS approver_name,
        COUNT(vl.id) AS total_lines,
        COALESCE(SUM(vl.quantity), 0) AS total_quantity
    FROM vouchers v
    LEFT JOIN warehouses wSrc ON v.source_warehouse_id = wSrc.id
    LEFT JOIN warehouses wDest ON v.dest_warehouse_id = wDest.id
    LEFT JOIN users uCreate ON v.created_by = uCreate.id
    LEFT JOIN users uApprove ON v.approved_by = uApprove.id
    LEFT JOIN voucher_lines vl ON vl.voucher_id = v.id
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (v.code LIKE :q OR v.note LIKE :q OR uCreate.full_name LIKE :q OR wSrc.name LIKE :q OR wDest.name LIKE :q)";
    $params[':q'] = "%{$search}%";
}
if ($whFilter !== null) {
    $sql .= " AND (v.source_warehouse_id = :wh OR v.dest_warehouse_id = :wh)";
    $params[':wh'] = $whFilter;
}
if ($typeFilter !== '') {
    $sql .= " AND v.type = :type";
    $params[':type'] = $typeFilter;
}
if ($statusFilter !== '') {
    $sql .= " AND v.status = :status";
    $params[':status'] = $statusFilter;
}
if ($fromDateFilter !== '') {
    $sql .= " AND DATE(v.created_at) >= :from_date";
    $params[':from_date'] = $fromDateFilter;
}
if ($toDateFilter !== '') {
    $sql .= " AND DATE(v.created_at) <= :to_date";
    $params[':to_date'] = $toDateFilter;
}

$sql .= " GROUP BY v.id ORDER BY v.id DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách mặt hàng phục vụ modal tạo phiếu mới
$items = $pdo->query("SELECT id, code, name, unit FROM items ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Tính toán chỉ số thống kê chứng từ
$totalCount = count($vouchers);
$draftCount = 0;
$confirmedCount = 0;
$totalQuantity = 0;
foreach ($vouchers as $v) {
    if ($v['status'] === 'DRAFT') $draftCount++;
    if ($v['status'] === 'CONFIRMED') $confirmedCount++;
    $totalQuantity += (int)$v['total_quantity'];
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold text-slate-900 mb-0" style="letter-spacing: -0.02em;">Phiếu Nhập / Xuất kho</h4>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn-clean btn-clean-secondary btn-sm" onclick="exportVouchersToCSV()">
            <i class="bi bi-download"></i>
            <span>Xuất CSV</span>
        </button>
        <button type="button" class="btn-clean btn-clean-primary btn-sm shadow-xs" data-bs-toggle="modal" data-bs-target="#createVoucherModal">
            <i class="bi bi-plus-lg"></i>
            <span>Lập chứng từ mới</span>
        </button>
    </div>
</div>

<!-- Dải chỉ số KPI Phiếu kho -->
<div class="row g-2.5 mb-3">
    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Tổng chứng từ</div>
                    <div class="metric-val"><?= number_format((float)$totalCount) ?></div>
                </div>
                <div class="metric-icon-box bg-primary-light text-primary">
                    <i class="bi bi-journal-text"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Chờ duyệt</div>
                    <div class="metric-val text-warning"><?= number_format((float)$draftCount) ?></div>
                </div>
                <div class="metric-icon-box bg-warning-subtle text-warning">
                    <i class="bi bi-hourglass-split"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Đã ghi sổ</div>
                    <div class="metric-val text-success"><?= number_format((float)$confirmedCount) ?></div>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-check2-circle"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Tổng SL hàng</div>
                    <div class="metric-val text-primary"><?= number_format((float)$totalQuantity) ?></div>
                </div>
                <div class="metric-icon-box bg-primary-light text-primary">
                    <i class="bi bi-boxes"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MAIN CHÍNH: Bộ lọc & Bảng danh sách chứng từ -->
<div class="clean-card main-primary-card mb-4 overflow-hidden">
    <!-- Tab Lọc Nhanh 1-Click -->
    <div class="clean-card-header d-flex flex-wrap gap-1.5 align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold text-slate-900 small">Danh sách chứng từ</span>
            <div class="btn-group btn-group-sm ms-1" role="group">
                <button type="button" class="btn btn-outline-primary active py-0 px-2" id="btnVViewTable" onclick="switchVoucherView('table')" style="font-size:0.72rem;"><i class="bi bi-table me-1"></i>Bảng</button>
                <button type="button" class="btn btn-outline-primary py-0 px-2" id="btnVViewKanban" onclick="switchVoucherView('kanban')" style="font-size:0.72rem;"><i class="bi bi-kanban me-1"></i>Kanban</button>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-1.5 align-items-center">
            <a href="vouchers.php" class="btn btn-sm <?= empty($_GET) || (count($_GET) === 1 && isset($_GET['wh']) && $_GET['wh'] === '') ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Tất cả (<?= $totalCount ?>)
            </a>
            <a href="vouchers.php?status=DRAFT" class="btn btn-sm <?= $statusFilter === 'DRAFT' ? 'btn-warning fw-bold text-dark' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Chờ duyệt (<?= $draftCount ?>)
            </a>
            <a href="vouchers.php?status=CONFIRMED" class="btn btn-sm <?= $statusFilter === 'CONFIRMED' ? 'btn-success fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Đã ghi sổ (<?= $confirmedCount ?>)
            </a>
            <a href="vouchers.php?type=IN" class="btn btn-sm <?= $typeFilter === 'IN' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Nhập (IN)
            </a>
            <a href="vouchers.php?type=OUT" class="btn btn-sm <?= $typeFilter === 'OUT' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Xuất (OUT)
            </a>
            <a href="vouchers.php?type=TRANSFER" class="btn btn-sm <?= $typeFilter === 'TRANSFER' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Điều chuyển
            </a>
        </div>
    </div>

    <!-- Thanh Tìm Kiếm & Bộ Lọc -->
    <div class="bg-slate-50 border-bottom px-3 py-2">
        <form method="GET" action="vouchers.php" class="row g-2 align-items-center w-100 m-0">
            <div class="col-md-4 ps-0">
                <div class="input-group input-group-sm" style="border-radius: 999px; overflow: hidden; border: 1px solid #cbd5e1; background: #fff;">
                    <span class="input-group-text bg-white border-0 text-slate-400 ps-3 pe-2"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="form-control border-0 shadow-none ps-0 pe-3" style="border-radius: 0 !important;" placeholder="Tìm mã chứng từ, người lập, ghi chú...">
                </div>
            </div>

            <div class="col-md-2">
                <select name="wh" class="form-select form-select-sm" style="padding-right: 2.2rem !important; text-overflow: ellipsis;" onchange="this.form.submit()">
                    <option value="">Tất cả kho</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>" <?= $whFilter === (int)$wh['id'] ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <select name="type" class="form-select form-select-sm" style="padding-right: 2.2rem !important; text-overflow: ellipsis;" onchange="this.form.submit()">
                    <option value="">Loại: Tất cả</option>
                    <option value="IN" <?= $typeFilter === 'IN' ? 'selected' : '' ?>>Nhập kho (IN)</option>
                    <option value="OUT" <?= $typeFilter === 'OUT' ? 'selected' : '' ?>>Xuất kho (OUT)</option>
                    <option value="TRANSFER" <?= $typeFilter === 'TRANSFER' ? 'selected' : '' ?>>Điều chuyển</option>
                    <option value="ADJUST_IN" <?= $typeFilter === 'ADJUST_IN' ? 'selected' : '' ?>>ĐC Tăng</option>
                    <option value="ADJUST_OUT" <?= $typeFilter === 'ADJUST_OUT' ? 'selected' : '' ?>>ĐC Giảm</option>
                </select>
            </div>

            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm" style="padding-right: 2.2rem !important; text-overflow: ellipsis;" onchange="this.form.submit()">
                    <option value="">TT: Tất cả</option>
                    <option value="DRAFT" <?= $statusFilter === 'DRAFT' ? 'selected' : '' ?>>Chờ duyệt</option>
                    <option value="CONFIRMED" <?= $statusFilter === 'CONFIRMED' ? 'selected' : '' ?>>Đã ghi sổ</option>
                </select>
            </div>

            <div class="col-md-2 pe-0 d-flex gap-1.5">
                <button type="submit" class="btn-clean btn-clean-primary btn-sm w-100 py-1 justify-content-center">
                    <i class="bi bi-funnel"></i> Lọc
                </button>
                <?php if ($search !== '' || $whFilter !== null || $typeFilter !== '' || $statusFilter !== '' || $fromDateFilter !== '' || $toDateFilter !== ''): ?>
                    <a href="vouchers.php" class="btn btn-sm btn-outline-secondary py-1 px-2 rounded-2" title="Xóa bộ lọc">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Chế độ Kanban Kéo & Thả -->
    <div id="vouchersKanban" class="nx-kanban-board d-none">
        <?php
        $vCols = [
            'DRAFT' => ['title' => 'Chờ duyệt (DRAFT)', 'badge' => 'bg-warning text-dark'],
            'CONFIRMED' => ['title' => 'Đã ghi sổ (CONFIRMED)', 'badge' => 'bg-success text-white'],
        ];
        foreach ($vCols as $stKey => $stMeta):
            $colItems = array_filter($vouchers, fn($v) => ($v['status'] ?? '') === $stKey);
        ?>
            <div class="nx-kanban-col" data-status="<?= $stKey ?>" ondragover="event.preventDefault(); this.classList.add('nx-drag-over')" ondragleave="this.classList.remove('nx-drag-over')" ondrop="dropVoucherCard(event, this)">
                <div class="nx-kanban-col-head">
                    <span><?= $stMeta['title'] ?></span>
                    <span class="badge <?= $stMeta['badge'] ?> rounded-pill"><?= count($colItems) ?></span>
                </div>
                <div class="nx-kanban-cards">
                    <?php foreach ($colItems as $v): ?>
                        <div class="nx-kanban-card" draggable="true" data-vid="<?= $v['id'] ?>" data-status="<?= $stKey ?>" ondragstart="window._dragVCard = this; this.classList.add('nx-dragging')" ondragend="this.classList.remove('nx-dragging')">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <a href="voucher_detail.php?id=<?= $v['id'] ?>" class="fw-bold font-monospace text-primary text-decoration-none small"><?= htmlspecialchars($v['code']) ?></a>
                                <span class="badge bg-primary-subtle text-primary" style="font-size:0.65rem;"><?= htmlspecialchars($v['type']) ?></span>
                            </div>
                            <div class="small text-muted mb-1 text-truncate"><?= htmlspecialchars($v['note'] ?: 'Chứng từ kho') ?></div>
                            <div class="d-flex justify-content-between align-items-center" style="font-size:0.7rem;">
                                <span class="text-muted"><i class="bi bi-person me-1"></i><?= htmlspecialchars($v['creator_name'] ?? 'Hệ thống') ?></span>
                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill" style="font-size:0.66rem;" onclick="window.openSlideDrawer && openSlideDrawer('voucher_detail.php?id=<?= $v['id'] ?>', 'Chi tiết <?= htmlspecialchars($v['code']) ?>')"><i class="bi bi-eye me-1"></i>Xem nhanh</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <script>
        function switchVoucherView(mode) {
            const tbl = document.getElementById('vouchersTable')?.closest('.table-responsive');
            const ftr = tbl?.nextElementSibling?.classList.contains('table-footer-bar') ? tbl.nextElementSibling : null;
            const kb = document.getElementById('vouchersKanban');
            document.getElementById('btnVViewTable')?.classList.toggle('active', mode === 'table');
            document.getElementById('btnVViewKanban')?.classList.toggle('active', mode === 'kanban');
            if (tbl) tbl.classList.toggle('d-none', mode === 'kanban');
            if (ftr) ftr.classList.toggle('d-none', mode === 'kanban');
            if (kb) kb.classList.toggle('d-none', mode !== 'kanban');
        }
        async function dropVoucherCard(e, col) {
            e.preventDefault();
            col.classList.remove('nx-drag-over');
            const card = window._dragVCard;
            window._dragVCard = null;
            if (!card) return;
            const targetSt = col.dataset.status;
            const curSt = card.dataset.status;
            if (targetSt === curSt) return;
            if (curSt === 'CONFIRMED') {
                if (window.showNxToast) window.showNxToast('Chứng từ đã ghi sổ không thể kéo về Nháp!', 'warning');
                return;
            }
            const fd = new FormData();
            fd.append('voucher_id', card.dataset.vid);
            const res = await fetch('api.php?action=confirm_voucher_kanban', { method: 'POST', body: fd });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                if (window.showNxToast) window.showNxToast(data.error || 'Không thể duyệt chứng từ', 'warning');
                return;
            }
            card.dataset.status = 'CONFIRMED';
            col.querySelector('.nx-kanban-cards')?.prepend(card);
            if (window.triggerConfetti) window.triggerConfetti();
            if (window.showNxToast) window.showNxToast(data.message, 'success');
        }
    </script>

    <div class="table-responsive">
        <table class="table-clean align-middle mb-0" id="vouchersTable">
            <thead>
                <tr>
                    <th class="ps-4">Số chứng từ</th>
                    <th>Ngày lập</th>
                    <th>Loại phiếu</th>
                    <th>Kho xuất</th>
                    <th>Kho nhập</th>
                    <th class="text-center">Dòng</th>
                    <th class="text-center">Tổng SL</th>
                    <th>Trạng thái</th>
                    <th>Phụ trách</th>
                    <th class="text-end pe-4">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vouchers)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-5 text-slate-400">
                            <i class="bi bi-inbox fs-2 d-block mb-2 text-slate-300"></i>
                            Không tìm thấy chứng từ nào phù hợp.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vouchers as $v): 
                        $typeChip = match ($v['type']) {
                            'IN' => '<span class="chip chip-success"><i class="bi bi-arrow-down-left"></i> Nhập</span>',
                            'OUT' => '<span class="chip chip-indigo"><i class="bi bi-arrow-up-right"></i> Xuất</span>',
                            'TRANSFER' => '<span class="chip chip-purple"><i class="bi bi-arrow-left-right"></i> Chuyển</span>',
                            default => '<span class="chip chip-warning"><i class="bi bi-sliders"></i> Đ.Chỉnh</span>',
                        };
                    ?>
                        <tr>
                            <td class="ps-3 text-nowrap">
                                <a href="voucher_detail.php?id=<?= $v['id'] ?>" class="fw-bold text-decoration-none font-monospace text-primary">
                                    <?= htmlspecialchars($v['code']) ?>
                                </a>
                            </td>
                            <td class="small text-slate-500 text-nowrap"><?= date('d/m/Y H:i', strtotime($v['created_at'])) ?></td>
                            <td><?= $typeChip ?></td>
                            <td>
                                <?= $v['source_code'] ? "<span class='chip chip-slate' title='" . htmlspecialchars((string)$v['source_name']) . "'>{$v['source_code']}</span>" : '<span class="text-slate-400">—</span>' ?>
                            </td>
                            <td>
                                <?= $v['dest_code'] ? "<span class='chip chip-slate' title='" . htmlspecialchars((string)$v['dest_name']) . "'>{$v['dest_code']}</span>" : '<span class="text-slate-400">—</span>' ?>
                            </td>
                            <td class="text-center text-slate-600"><?= $v['total_lines'] ?></td>
                            <td class="text-center fw-bold text-slate-900"><?= number_format((int)$v['total_quantity']) ?></td>
                            <td>
                                <?php if ($v['status'] === 'CONFIRMED'): ?>
                                    <span class="chip chip-success"><i class="bi bi-check-circle-fill"></i> Đã ghi sổ</span>
                                <?php elseif ($v['status'] === 'DRAFT'): ?>
                                    <span class="chip chip-warning"><i class="bi bi-hourglass-split"></i> Chờ duyệt</span>
                                <?php else: ?>
                                    <span class="chip chip-danger">Đã hủy</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-slate-700 fw-medium">
                                <?= htmlspecialchars($v['creator_name'] ?? 'Hệ thống') ?>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-inline-flex gap-1">
                                    <a href="voucher_detail.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-white border px-2 py-1 text-slate-700 rounded-2" title="Xem chi tiết chứng từ">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="print_voucher.php?id=<?= $v['id'] ?>" target="_blank" class="btn btn-sm btn-white border px-2 py-1 text-slate-700 rounded-2" title="In phiếu kho chuẩn A4">
                                        <i class="bi bi-printer"></i>
                                    </a>

                                    <?php if ($v['status'] === 'DRAFT'): 
                                        $whToCheck = ($v['type'] === 'IN' || $v['type'] === 'ADJUST_IN') ? (int)$v['dest_warehouse_id'] : (int)$v['source_warehouse_id'];
                                        $canApprove = Auth::checkWarehousePermission($pdo, (int)$currentUser['id'], $whToCheck, 'approve');
                                    ?>
                                        <?php if ($canApprove): ?>
                                            <form method="POST" action="vouchers.php" class="d-inline" onsubmit="return confirm('Xác nhận phê duyệt chứng từ <?= htmlspecialchars($v['code']) ?> vào Sổ phát sinh?')">
                                                <input type="hidden" name="action" value="confirm_voucher">
                                                <input type="hidden" name="voucher_id" value="<?= $v['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-clean-primary px-2.5 py-1 rounded-2" title="Phê duyệt ngay">
                                                    <i class="bi bi-check-lg"></i> Duyệt
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="chip chip-neutral" style="font-size: 0.7rem;">Chờ duyệt</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="table-footer-bar">
        <div>
            <i class="bi bi-journal-text me-1"></i>
            Hiển thị <strong data-tt-count><?= count($vouchers) ?></strong> / <?= count($vouchers) ?> chứng từ
            <?php if ($whFilter !== null || $typeFilter !== '' || $statusFilter !== '' || $fromDateFilter !== '' || $toDateFilter !== ''): ?>
                <span class="ms-1">(đang lọc)</span>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span><i class="bi bi-circle-fill text-success me-1" style="font-size:.5rem"></i>Đã ghi sổ: <?= $confirmedCount ?></span>
            <span><i class="bi bi-circle-fill text-warning me-1" style="font-size:.5rem"></i>Chờ duyệt: <?= $draftCount ?></span>
        </div>
    </div>
</div>

<!-- Modal Tạo Phiếu Nhập / Xuất -->
<div class="modal fade" id="createVoucherModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content overflow-hidden">
            <form method="POST" action="vouchers.php" id="voucherForm">
                <input type="hidden" name="action" value="create_voucher">
                <div class="modal-header bg-white px-4 py-3 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-file-earmark-plus text-primary fs-5"></i>
                        <h6 class="modal-title fw-bold text-slate-900 mb-0">Lập chứng từ Nhập / Xuất kho</h6>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-slate-700">Loại chứng từ <span class="text-danger">*</span></label>
                            <select name="type" class="form-select" id="voucherTypeSelect" required>
                                <option value="IN">Phiếu Nhập kho (Goods Receipt - IN)</option>
                                <option value="OUT">Phiếu Xuất kho (Goods Issue - OUT)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-slate-700">Điểm kho thao tác <span class="text-danger">*</span></label>
                            <select name="warehouse_id" class="form-select" id="warehouseSelect" required>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>">[<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Lý do / Diễn giải chứng từ</label>
                        <input type="text" name="note" class="form-control" placeholder="Nhập lý do xuất/nhập, số hóa đơn tham chiếu...">
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2 mt-4 pt-2 border-top">
                        <h6 class="fw-bold text-slate-900 mb-0" style="font-size: 0.9rem;">
                            <i class="bi bi-list-check text-primary me-1"></i>Chi tiết các dòng hàng
                        </h6>
                        <button type="button" class="btn btn-sm btn-white border text-primary fw-semibold rounded-2" onclick="addVoucherLine()">
                            <i class="bi bi-plus"></i> Thêm dòng hàng
                        </button>
                    </div>

                    <div id="voucherLinesContainer">
                        <div class="row g-2 align-items-center mb-2 voucher-row">
                            <div class="col-md-6">
                                <label class="form-label small text-slate-500 mb-1">Mặt hàng <span class="text-danger">*</span></label>
                                <select name="item_id[]" class="form-select form-select-sm" required>
                                    <option value="">-- Chọn mặt hàng --</option>
                                    <?php foreach ($items as $it): ?>
                                        <option value="<?= $it['id'] ?>">[<?= htmlspecialchars($it['code']) ?>] <?= htmlspecialchars($it['name']) ?> (<?= htmlspecialchars($it['unit']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-slate-500 mb-1">Số lượng <span class="text-danger">*</span></label>
                                <input type="number" name="quantity[]" class="form-control form-control-sm" placeholder="Số lượng" min="1" value="1" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small text-slate-500 mb-1">Đơn giá dự toán</label>
                                <input type="number" name="unit_price[]" class="form-control form-control-sm" placeholder="Đơn giá (VNĐ)" min="0" value="0">
                            </div>
                        </div>
                    </div>

                    <div class="form-check mt-3 pt-2">
                        <input class="form-check-input" type="checkbox" name="auto_confirm" value="1" id="autoConfirmCheck" checked>
                        <label class="form-check-label small fw-semibold text-slate-700" for="autoConfirmCheck">
                            Tự động xác nhận và ghi sổ phát sinh ngay (nếu tài khoản có quyền duyệt kho)
                        </label>
                    </div>
                </div>

                <div class="modal-footer bg-slate-50 px-4 py-3 border-top">
                    <button type="button" class="btn-clean btn-clean-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn-clean btn-clean-primary px-4 fw-bold">
                        <i class="bi bi-check-circle"></i>
                        <span>Lưu & Tạo chứng từ</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function addVoucherLine() {
    const container = document.getElementById('voucherLinesContainer');
    const firstRow = container.querySelector('.voucher-row');
    if (!firstRow) return;
    const newRow = firstRow.cloneNode(true);
    // Reset values
    const select = newRow.querySelector('select');
    if (select) select.selectedIndex = 0;
    const inputs = newRow.querySelectorAll('input');
    inputs.forEach(i => i.value = i.name.includes('quantity') ? 1 : 0);
    container.appendChild(newRow);
}

function exportVouchersToCSV() {
    nxExportTableToCSV('#vouchersTable', 'Danh_sach_chung_tu_' + new Date().toISOString().slice(0, 10) + '.csv', { skipLastCol: true });
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
