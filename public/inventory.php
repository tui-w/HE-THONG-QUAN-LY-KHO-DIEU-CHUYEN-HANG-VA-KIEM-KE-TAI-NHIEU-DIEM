<?php
declare(strict_types=1);

$pageTitle = 'Đợt kiểm kê & Điều chỉnh tồn kho';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../src/InventoryService.php';
require_once __DIR__ . '/../src/StockService.php';

use App\InventoryService;
use App\StockService;

// Xử lý tạo đợt kiểm kê mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_count') {
    try {
        $warehouseId = (int)$_POST['warehouse_id'];
        $title = trim((string)$_POST['title']);
        $countDate = (string)$_POST['count_date'];
        $note = trim((string)($_POST['note'] ?? ''));

        $itemIds = $_POST['item_id'] ?? [];
        $actuals = $_POST['actual_quantity'] ?? [];
        $reasons = $_POST['adjustment_reason'] ?? [];

        $lines = [];
        for ($i = 0; $i < count($itemIds); $i++) {
            if (!empty($itemIds[$i])) {
                $lines[] = [
                    'item_id' => (int)$itemIds[$i],
                    'actual_quantity' => (int)($actuals[$i] ?? 0),
                    'adjustment_reason' => trim((string)($reasons[$i] ?? ''))
                ];
            }
        }

        if (empty($lines)) {
            throw new \RuntimeException("Vui lòng nhập ít nhất một mặt hàng kiểm kê!");
        }

        $countId = InventoryService::createStockCount($pdo, $warehouseId, $title, $countDate, (int)$currentUser['id'], $lines, $note);

        // Nếu người dùng chọn tự động lập phiếu điều chỉnh ngay
        if (!empty($_POST['auto_adjust'])) {
            $adj = InventoryService::confirmAndApplyAdjustments($pdo, $countId, (int)$currentUser['id']);
            $_SESSION['flash_success'] = "Tạo đợt kiểm kê và TỰ ĐỘNG LẬP CHỨNG TỪ ĐIỀU CHỈNH thành công! Đã tạo " . count($adj['adjustment_vouchers']) . " phiếu điều chỉnh khớp với sổ phát sinh.";
        } else {
            $_SESSION['flash_success'] = "Tạo đợt kiểm kê thành công ở trạng thái nháp. Bạn có thể xem bảng chênh lệch và xác nhận điều chỉnh!";
        }

        header("Location: inventory_detail.php?id={$countId}");
        exit;
    } catch (\Exception $e) {
        $_SESSION['flash_error'] = "LỖI KIỂM KÊ: " . $e->getMessage();
        header('Location: inventory.php');
        exit;
    }
}

// Xử lý xác nhận điều chỉnh chênh lệch từ danh sách
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_adjustment') {
    try {
        $countId = (int)$_POST['count_id'];
        $adj = InventoryService::confirmAndApplyAdjustments($pdo, $countId, (int)$currentUser['id']);
        $_SESSION['flash_success'] = "Đã xác nhận chênh lệch và lập chứng từ điều chỉnh thành công! Số tồn mới đã cập nhật khớp sổ cái.";
    } catch (\Exception $e) {
        $_SESSION['flash_error'] = "LỖI ĐIỀU CHỈNH: " . $e->getMessage();
    }
    header('Location: inventory.php');
    exit;
}

// Bộ lọc tìm kiếm
$search = trim((string)($_GET['q'] ?? ''));
$whFilter = isset($_GET['wh']) && $_GET['wh'] !== '' ? (int)$_GET['wh'] : null;
$statusFilter = trim((string)($_GET['status'] ?? ''));
$diffFilter = trim((string)($_GET['diff'] ?? ''));

// Danh sách các đợt kiểm kê kèm bộ lọc
$sql = "
    SELECT 
        sc.*,
        w.name AS warehouse_name,
        w.code AS warehouse_code,
        uCreate.full_name AS creator_name,
        uComplete.full_name AS completer_name,
        COUNT(scl.id) AS lines_count,
        SUM(CASE WHEN scl.difference != 0 THEN 1 ELSE 0 END) AS discrepancy_count
    FROM stock_counts sc
    JOIN warehouses w ON sc.warehouse_id = w.id
    LEFT JOIN users uCreate ON sc.created_by = uCreate.id
    LEFT JOIN users uComplete ON sc.completed_by = uComplete.id
    LEFT JOIN stock_count_lines scl ON scl.stock_count_id = sc.id
    WHERE 1=1
";
$params = [];
if ($search !== '') {
    $sql .= " AND (sc.code LIKE :q OR sc.title LIKE :q OR uCreate.full_name LIKE :q OR w.name LIKE :q)";
    $params[':q'] = "%{$search}%";
}
if ($whFilter !== null) {
    $sql .= " AND sc.warehouse_id = :wh";
    $params[':wh'] = $whFilter;
}
if ($statusFilter !== '') {
    $sql .= " AND sc.status = :status";
    $params[':status'] = $statusFilter;
}
$sql .= " GROUP BY sc.id";
if ($diffFilter === 'yes') {
    $sql .= " HAVING discrepancy_count > 0";
} elseif ($diffFilter === 'no') {
    $sql .= " HAVING discrepancy_count = 0";
}
$sql .= " ORDER BY sc.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stockCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Danh sách mặt hàng phục vụ modal tạo đợt kiểm kê
$items = $pdo->query("SELECT id, code, name, unit FROM items ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);

// Thống kê đợt kiểm kê
$totalCounts = count($stockCounts);
$discrepancyCount = 0;
$adjustedCount = 0;
foreach ($stockCounts as $sc) {
    if ((int)($sc['discrepancy_count'] ?? 0) > 0) $discrepancyCount++;
    if (($sc['status'] ?? '') === 'ADJUSTED') $adjustedCount++;
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold text-slate-900 mb-0" style="letter-spacing: -0.02em;">Kiểm kê & Xử lý lệch tồn</h4>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn-clean btn-clean-secondary btn-sm" onclick="exportInventoryToCSV()">
            <i class="bi bi-download"></i>
            <span>Xuất CSV</span>
        </button>
        <button type="button" class="btn-clean btn-clean-primary btn-sm shadow-xs" data-bs-toggle="modal" data-bs-target="#createCountModal">
            <i class="bi bi-plus-lg"></i>
            <span>Tạo đợt kiểm kê</span>
        </button>
    </div>
</div>

<!-- Dải chỉ số KPI Kiểm kê -->
<div class="row g-2.5 mb-3">
    <div class="col-md-4">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Tổng đợt kiểm kê</div>
                    <div class="metric-val"><?= number_format((float)$totalCounts) ?></div>
                </div>
                <div class="metric-icon-box bg-primary-light text-primary">
                    <i class="bi bi-clipboard-check"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Phát hiện lệch tồn</div>
                    <div class="metric-val text-danger"><?= number_format((float)$discrepancyCount) ?></div>
                </div>
                <div class="metric-icon-box bg-danger-subtle text-danger">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Đã cân bằng sổ cái</div>
                    <div class="metric-val text-success"><?= number_format((float)$adjustedCount) ?></div>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-check2-all"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MAIN CHÍNH: Danh sách các đợt kiểm kê & Bộ lọc tìm kiếm -->
<div class="clean-card main-primary-card mb-4 overflow-hidden">
    <!-- Tab Lọc Nhanh 1-Click -->
    <div class="clean-card-header d-flex flex-wrap gap-1.5 align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold text-slate-900 small">Danh sách đợt kiểm kê</span>
            <div class="btn-group btn-group-sm ms-1" role="group">
                <button type="button" class="btn btn-outline-primary active py-0 px-2" id="btnIViewTable" onclick="switchInvView('table')" style="font-size:0.72rem;"><i class="bi bi-table me-1"></i>Bảng</button>
                <button type="button" class="btn btn-outline-primary py-0 px-2" id="btnIViewKanban" onclick="switchInvView('kanban')" style="font-size:0.72rem;"><i class="bi bi-kanban me-1"></i>Kanban</button>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-1.5 align-items-center">
            <a href="inventory.php" class="btn btn-sm <?= empty($_GET) ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Tất cả (<?= $totalCounts ?>)
            </a>
            <a href="inventory.php?diff=yes" class="btn btn-sm <?= $diffFilter === 'yes' ? 'btn-danger fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Có lệch tồn (<?= $discrepancyCount ?>)
            </a>
            <a href="inventory.php?status=ADJUSTED" class="btn btn-sm <?= $statusFilter === 'ADJUSTED' ? 'btn-success fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Đã cân bằng (<?= $adjustedCount ?>)
            </a>
            <a href="inventory.php?status=DRAFT" class="btn btn-sm <?= $statusFilter === 'DRAFT' ? 'btn-warning fw-bold text-dark' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Bản nháp
            </a>
        </div>
    </div>

    <!-- Thanh Tìm Kiếm & Lọc Nâng Cao -->
    <div class="bg-slate-50 border-bottom px-3 py-2">
        <form method="GET" action="inventory.php" class="row g-2 align-items-center w-100 m-0">
            <div class="col-md-5 ps-0">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0 text-slate-400"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="form-control border-start-0 ps-0" placeholder="Tìm theo mã kiểm kê, kế hoạch, người phụ trách...">
                </div>
            </div>

            <div class="col-md-3">
                <select name="wh" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Tất cả điểm kho</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>" <?= $whFilter === (int)$wh['id'] ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Trạng thái: Tất cả</option>
                    <option value="DRAFT" <?= $statusFilter === 'DRAFT' ? 'selected' : '' ?>>Chờ xử lý</option>
                    <option value="ADJUSTED" <?= $statusFilter === 'ADJUSTED' ? 'selected' : '' ?>>Đã cân bằng</option>
                </select>
            </div>

            <div class="col-md-2 pe-0 d-flex gap-1.5">
                <button type="submit" class="btn-clean btn-clean-primary btn-sm w-100 py-1 justify-content-center">
                    <i class="bi bi-funnel"></i> Lọc
                </button>
                <?php if ($search !== '' || $whFilter !== null || $statusFilter !== '' || $diffFilter !== ''): ?>
                    <a href="inventory.php" class="btn btn-sm btn-outline-secondary py-1 px-2 rounded-2" title="Xóa bộ lọc">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Chế độ Kanban Kiểm kê Kéo & Thả -->
    <div id="inventoryKanban" class="nx-kanban-board d-none">
        <?php
        $iCols = [
            'DRAFT' => ['title' => 'Mới lập (DRAFT)', 'badge' => 'bg-secondary text-white'],
            'IN_PROGRESS' => ['title' => 'Đang kiểm (IN_PROGRESS)', 'badge' => 'bg-info text-white'],
            'COMPLETED' => ['title' => 'Chờ duyệt (COMPLETED)', 'badge' => 'bg-warning text-dark'],
            'ADJUSTED' => ['title' => 'Đã cân bằng (ADJUSTED)', 'badge' => 'bg-success text-white'],
        ];
        foreach ($iCols as $stKey => $stMeta):
            $colItems = array_filter($stockCounts, fn($s) => ($s['status'] ?? '') === $stKey);
        ?>
            <div class="nx-kanban-col" data-status="<?= $stKey ?>" ondragover="event.preventDefault(); this.classList.add('nx-drag-over')" ondragleave="this.classList.remove('nx-drag-over')" ondrop="dropInvCard(event, this)">
                <div class="nx-kanban-col-head">
                    <span><?= $stMeta['title'] ?></span>
                    <span class="badge <?= $stMeta['badge'] ?> rounded-pill"><?= count($colItems) ?></span>
                </div>
                <div class="nx-kanban-cards">
                    <?php foreach ($colItems as $sc): ?>
                        <div class="nx-kanban-card" draggable="true" data-cid="<?= $sc['id'] ?>" data-status="<?= $stKey ?>" ondragstart="window._dragICard = this; this.classList.add('nx-dragging')" ondragend="this.classList.remove('nx-dragging')">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <a href="inventory_detail.php?id=<?= $sc['id'] ?>" class="fw-bold font-monospace text-primary text-decoration-none small"><?= htmlspecialchars($sc['code']) ?></a>
                                <span class="badge bg-primary-subtle text-primary" style="font-size:0.65rem;"><?= htmlspecialchars($sc['warehouse_code'] ?? '') ?></span>
                            </div>
                            <div class="fw-semibold small mb-1 text-truncate" title="<?= htmlspecialchars($sc['title']) ?>"><?= htmlspecialchars($sc['title']) ?></div>
                            <div class="d-flex justify-content-between align-items-center" style="font-size:0.7rem;">
                                <span class="text-muted"><?= htmlspecialchars($sc['count_date']) ?></span>
                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill" style="font-size:0.66rem;" onclick="window.openSlideDrawer && openSlideDrawer('inventory_detail.php?id=<?= $sc['id'] ?>', 'Kiểm kê <?= htmlspecialchars($sc['code']) ?>')"><i class="bi bi-eye me-1"></i>Xem nhanh</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <script>
        function switchInvView(mode) {
            const tbl = document.getElementById('inventoryTable')?.closest('.table-responsive');
            const ftr = tbl?.nextElementSibling?.classList.contains('table-footer-bar') ? tbl.nextElementSibling : null;
            const kb = document.getElementById('inventoryKanban');
            document.getElementById('btnIViewTable')?.classList.toggle('active', mode === 'table');
            document.getElementById('btnIViewKanban')?.classList.toggle('active', mode === 'kanban');
            if (tbl) tbl.classList.toggle('d-none', mode === 'kanban');
            if (ftr) ftr.classList.toggle('d-none', mode === 'kanban');
            if (kb) kb.classList.toggle('d-none', mode !== 'kanban');
        }
        async function dropInvCard(e, col) {
            e.preventDefault();
            col.classList.remove('nx-drag-over');
            const card = window._dragICard;
            window._dragICard = null;
            if (!card) return;
            const targetSt = col.dataset.status;
            if (card.dataset.status === targetSt) return;
            const fd = new FormData();
            fd.append('count_id', card.dataset.cid);
            fd.append('status', targetSt);
            const res = await fetch('api.php?action=update_count_status', { method: 'POST', body: fd });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                if (window.showNxToast) window.showNxToast(data.error || 'Không thể cập nhật trạng thái', 'warning');
                return;
            }
            card.dataset.status = targetSt;
            col.querySelector('.nx-kanban-cards')?.prepend(card);
            if (targetSt === 'ADJUSTED' && window.triggerConfetti) window.triggerConfetti();
            if (window.showNxToast) window.showNxToast(data.message, 'success');
        }
    </script>

    <div class="table-responsive">
        <table class="table-clean align-middle mb-0" id="inventoryTable">
            <thead>
                <tr>
                    <th class="ps-4">Mã kiểm kê</th>
                    <th>Tiêu đề kế hoạch</th>
                    <th>Kho</th>
                    <th>Ngày kiểm</th>
                    <th class="text-center">Số món</th>
                    <th class="text-center">Chênh lệch</th>
                    <th>Trạng thái</th>
                    <th>Phụ trách</th>
                    <th class="text-end pe-4">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stockCounts)): ?>
                    <tr><td colspan="9" class="text-center py-5 text-slate-400">Chưa có đợt kiểm kê nào.</td></tr>
                <?php else: ?>
                    <?php foreach ($stockCounts as $sc): ?>
                        <tr>
                            <td class="ps-4">
                                <a href="inventory_detail.php?id=<?= $sc['id'] ?>" class="fw-bold text-decoration-none font-monospace text-primary">
                                    <?= htmlspecialchars($sc['code']) ?>
                                </a>
                            </td>
                            <td>
                                <div class="fw-semibold text-slate-900"><?= htmlspecialchars($sc['title']) ?></div>
                            </td>
                            <td>
                                <span class="chip chip-slate" title="<?= htmlspecialchars($sc['warehouse_name']) ?>"><?= htmlspecialchars($sc['warehouse_code']) ?></span>
                            </td>
                            <td class="small text-slate-500"><?= date('d/m/Y', strtotime($sc['count_date'])) ?></td>
                            <td class="text-center fw-semibold text-slate-700"><?= $sc['lines_count'] ?></td>
                            <td class="text-center">
                                <?php if ($sc['discrepancy_count'] > 0): ?>
                                    <span class="chip chip-danger"><i class="bi bi-exclamation-circle-fill"></i> Lệch <?= $sc['discrepancy_count'] ?></span>
                                <?php else: ?>
                                    <span class="chip chip-success"><i class="bi bi-check-circle-fill"></i> Khớp</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sc['status'] === 'ADJUSTED'): ?>
                                    <span class="chip chip-success"><i class="bi bi-check-all"></i> Đã cân bằng</span>
                                <?php else: ?>
                                    <span class="chip chip-warning"><i class="bi bi-hourglass-split"></i> Bản nháp</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-slate-700 fw-medium">
                                <?= htmlspecialchars($sc['creator_name'] ?? 'Hệ thống') ?>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-inline-flex gap-1">
                                    <a href="inventory_detail.php?id=<?= $sc['id'] ?>" class="btn btn-sm btn-white border px-2.5 py-1 text-slate-700 rounded-2" title="Xem bảng so sánh chênh lệch">
                                        <i class="bi bi-table me-1"></i> Đối chiếu
                                    </a>

                                    <?php if ($sc['status'] !== 'ADJUSTED'): ?>
                                        <form method="POST" action="inventory.php" class="d-inline" onsubmit="return confirm('Xác nhận lập chứng từ điều chỉnh tồn kho cho đợt kiểm kê <?= htmlspecialchars($sc['code']) ?>?')">
                                            <input type="hidden" name="action" value="apply_adjustment">
                                            <input type="hidden" name="count_id" value="<?= $sc['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-clean-primary px-2.5 py-1 rounded-2" title="Tự động lập chứng từ điều chỉnh">
                                                <i class="bi bi-magic me-1"></i> Cân bằng
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal tạo đợt kiểm kê mới -->
<div class="modal fade" id="createCountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content overflow-hidden">
            <form method="POST" action="inventory.php">
                <input type="hidden" name="action" value="create_count">
                <div class="modal-header bg-white px-4 py-3 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-clipboard-plus text-primary fs-5"></i>
                        <h6 class="modal-title fw-bold text-slate-900 mb-0">Tạo đợt kiểm kê thực tế tại kho</h6>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" style="max-height: 70vh; overflow-y:auto;">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-slate-700">Kho kiểm kê <span class="text-danger">*</span></label>
                            <select name="warehouse_id" class="form-select" required>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>">[<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-slate-700">Ngày kiểm kê <span class="text-danger">*</span></label>
                            <input type="date" name="count_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Tiêu đề đợt kiểm kê <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="Ví dụ: Kiểm kê định kỳ tháng 10/2026..." required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Ghi chú phạm vi</label>
                        <input type="text" name="note" class="form-control" placeholder="Kiểm kê đột xuất khu vực thiết bị linh kiện...">
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2 mt-4 pt-2 border-top">
                      <h6 class="fw-bold text-slate-900 mb-0" style="font-size: 0.9rem;">
                        <i class="bi bi-pencil-square text-primary me-1"></i>Nhập số đếm thực tế & Lý do chênh lệch
                      </h6>
                      <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#countLinesAccordion" aria-expanded="true" aria-controls="countLinesAccordion">
                        <i class="bi bi-arrows-expand"></i> Thu gọn/Dãn rộng
                      </button>
                    </div>
                    <div class="collapse show" id="countLinesAccordion" style="max-height: 400px; overflow-y:auto;">
                      <div class="mb-3">
                        <div id="countLinesContainer">
                        <?php for ($idx = 0; $idx < 4; $idx++): 
                            $sampleItem = $items[$idx] ?? null;
                            if (!$sampleItem) continue;
                        ?>
                            <div class="row g-2 align-items-center mb-2 p-2 border rounded-3 bg-slate-50">
                                <div class="col-md-4">
                                    <select name="item_id[]" class="form-select form-select-sm">
                                        <?php foreach ($items as $it): ?>
                                            <option value="<?= $it['id'] ?>" <?= $it['id'] === $sampleItem['id'] ? 'selected' : '' ?>>
                                                [<?= htmlspecialchars($it['code']) ?>] <?= htmlspecialchars($it['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <input type="number" name="actual_quantity[]" class="form-control form-control-sm" placeholder="SL thực đếm" value="<?= $idx === 0 ? 32 : ($idx === 1 ? 18 : 25) ?>" required>
                                </div>
                                <div class="col-md-5">
                                    <input type="text" name="adjustment_reason[]" class="form-control form-control-sm" placeholder="Lý do chênh lệch..." value="<?= $idx === 0 ? 'Thừa do nhà cung cấp bàn giao dư' : ($idx === 1 ? 'Hao hụt vận chuyển nội bộ' : '') ?>">
                                </div>
                            </div>
                        <?php endfor; ?>
                        </div>
                      </div>
                    </div>

                    <div class="form-check mt-3 pt-2">
                        <input class="form-check-input" type="checkbox" name="auto_adjust" value="1" id="autoAdjustCheck" checked>
                        <label class="form-check-label small fw-semibold text-slate-700" for="autoAdjustCheck">
                            Tự động lập chứng từ điều chỉnh (ADJUST_IN / ADJUST_OUT) và cân bằng số tồn ngay
                        </label>
                    </div>
                </div>

                <div class="modal-footer bg-slate-50 px-4 py-3 border-top">
                    <button type="button" class="btn-clean btn-clean-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn-clean btn-clean-primary px-4 fw-bold">
                        <i class="bi bi-check2-circle"></i>
                        <span>Lưu đợt kiểm kê</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function exportInventoryToCSV() {
    nxExportTableToCSV('#inventoryTable', 'Bao_cao_kiem_ke_' + new Date().toISOString().slice(0, 10) + '.csv', { skipLastCol: true });
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
