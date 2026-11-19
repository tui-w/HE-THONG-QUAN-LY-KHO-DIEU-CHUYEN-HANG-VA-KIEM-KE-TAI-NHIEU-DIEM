<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/StockService.php';

use App\Database;
use App\Auth;
use App\StockService;

Auth::requireLogin();
$pdo = Database::getConnection();
$currentUser = Auth::getCurrentUser();
$userId = (int)$currentUser['id'];

$allWh = $pdo->query("SELECT * FROM warehouses ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$whId = isset($_GET['wh']) ? (int)$_GET['wh'] : (int)($allWh[0]['id'] ?? 1);
$curWh = null;
foreach ($allWh as $w) {
    if ((int)$w['id'] === $whId) { $curWh = $w; break; }
}
if (!$curWh && !empty($allWh)) {
    $curWh = $allWh[0];
    $whId = (int)$curWh['id'];
}

$dispatchMsg = '';
$dispatchErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hub_dispatch') {
    try {
        $dstWh = (int)($_POST['dest_wh'] ?? 0);
        $itemId = (int)($_POST['item_id'] ?? 0);
        $qty = (int)($_POST['quantity'] ?? 0);
        $note = trim((string)($_POST['note'] ?? 'Điều phối từ Trung tâm Kho'));
        if ($dstWh <= 0 || $dstWh === $whId || $itemId <= 0 || $qty <= 0) {
            throw new RuntimeException('Thông tin điều phối không hợp lệ.');
        }
        $vId = StockService::createVoucher($pdo, 'TRANSFER', $whId, $dstWh, [[
            'item_id' => $itemId, 'batch_id' => null, 'quantity' => $qty, 'unit_price' => 0, 'note' => $note
        ]], $userId, $note);
        if (Auth::checkWarehousePermission($pdo, $userId, $whId, 'approve')) {
            StockService::confirmVoucher($pdo, $vId, $userId);
            $dispatchMsg = "Đã điều phối xuất chuyển thành công chứng từ #{$vId}!";
        } else {
            $dispatchMsg = "Đã lập lệnh điều phối nháp #{$vId}, chờ duyệt!";
        }
    } catch (\Throwable $e) {
        $dispatchErr = $e->getMessage();
    }
}

// 1. Tồn kho chi tiết tại kho này
$stItems = $pdo->prepare("
    SELECT i.id, i.code, i.name, i.unit, i.min_stock, COALESCE(c.name, 'Chung') AS category_name,
           COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS qty
    FROM items i
    LEFT JOIN item_categories c ON i.category_id = c.id
    LEFT JOIN stock_movements sm ON sm.item_id = i.id AND sm.warehouse_id = ?
    GROUP BY i.id
    HAVING qty > 0 OR i.min_stock > 0
    ORDER BY qty DESC, i.name ASC
");
$stItems->execute([$whId]);
$hubItems = $stItems->fetchAll(PDO::FETCH_ASSOC);

$totalHubStock = 0;
$lowStockCount = 0;
$skuActive = 0;
foreach ($hubItems as $hi) {
    $q = (float)$hi['qty'];
    $totalHubStock += $q;
    if ($q > 0) $skuActive++;
    if ($q <= (float)$hi['min_stock']) $lowStockCount++;
}

// 2. Thống kê sức chứa 4 khu (A, B, C, D)
$stZones = $pdo->prepare("
    SELECT zone_code,
           COUNT(*) AS total_bins,
           SUM(CASE WHEN item_id IS NOT NULL THEN 1 ELSE 0 END) AS filled_bins
    FROM warehouse_locations
    WHERE warehouse_id = ?
    GROUP BY zone_code
    ORDER BY zone_code ASC
");
$stZones->execute([$whId]);
$zoneStats = $stZones->fetchAll(PDO::FETCH_ASSOC);

// 3. Giao dịch gần nhất tại kho này
$stMvs = $pdo->prepare("
    SELECT sm.movement_code, sm.movement_type, sm.quantity_in, sm.quantity_out, sm.balance_after, sm.created_at,
           i.code AS item_code, i.name AS item_name
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.id
    WHERE sm.warehouse_id = ?
    ORDER BY sm.id DESC LIMIT 25
");
$stMvs->execute([$whId]);
$hubMovements = $stMvs->fetchAll(PDO::FETCH_ASSOC);

// 4. Lô hàng FEFO tại kho này
$stBatches = $pdo->prepare("
    SELECT b.batch_number, b.expiry_date, i.code AS item_code, i.name AS item_name,
           COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS qty
    FROM batches b
    JOIN items i ON b.item_id = i.id
    JOIN stock_movements sm ON sm.batch_id = b.id AND sm.warehouse_id = ?
    GROUP BY b.id
    HAVING qty > 0
    ORDER BY b.expiry_date ASC LIMIT 20
");
$stBatches->execute([$whId]);
$hubBatches = $stBatches->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = ($curWh['code'] ?? 'Hub') . ' — Trung tâm Điều hành Kho';
$activePage = 'digital_twin';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <div class="d-flex align-items-center gap-2">
            <h4 class="fw-bold mb-0"><?= htmlspecialchars($curWh['name'] ?? '') ?></h4>
            <span class="badge bg-primary rounded-pill font-monospace"><?= htmlspecialchars($curWh['code'] ?? '') ?></span>
        </div>
        <div class="small text-muted mt-0.5"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($curWh['address'] ?? '') ?> &bull; <i class="bi bi-telephone ms-2 me-1"></i><?= htmlspecialchars($curWh['phone'] ?? '') ?></div>
    </div>
    <div class="d-flex flex-wrap gap-1.5 align-items-center">
        <div class="btn-group btn-group-sm">
            <?php foreach ($allWh as $idx => $w): ?>
                <a href="warehouse_hub.php?wh=<?= (int)$w['id'] ?>" class="btn <?= (int)$w['id'] === $whId ? 'btn-primary fw-bold' : 'btn-outline-primary' ?>">
                    <?= $idx + 1 ?> &bull; <?= htmlspecialchars($w['code']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="window.print()"><i class="bi bi-printer me-1"></i>In báo cáo kho</button>
        <a href="digital_twin.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="bi bi-arrow-left me-1"></i>Về Bản đồ 3D</a>
    </div>
</div>

<?php if ($dispatchMsg): ?>
    <div class="alert alert-success border-0 shadow-xs mb-3"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($dispatchMsg) ?></div>
<?php endif; ?>
<?php if ($dispatchErr): ?>
    <div class="alert alert-danger border-0 shadow-xs mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($dispatchErr) ?></div>
<?php endif; ?>

<!-- 4 KPI của riêng Điểm kho này -->
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div><div class="metric-label">TỔNG TỒN TẠI KHO</div><div class="metric-val"><?= number_format($totalHubStock) ?></div></div>
                <div class="metric-icon-box"><i class="bi bi-box-seam-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div><div class="metric-label">MẶT HÀNG CÓ TỒN</div><div class="metric-val"><?= $skuActive ?></div></div>
                <div class="metric-icon-box"><i class="bi bi-layers-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div><div class="metric-label">CẢNH BÁO TỒN THẤP</div><div class="metric-val"><?= $lowStockCount ?></div></div>
                <div class="metric-icon-box"><i class="bi bi-exclamation-triangle-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div><div class="metric-label">LÔ HÀNG THEO DÕI</div><div class="metric-val"><?= count($hubBatches) ?></div></div>
                <div class="metric-icon-box"><i class="bi bi-calendar2-check-fill"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs Chức năng Riêng của Điểm Kho -->
<div class="clean-card main-primary-card overflow-hidden">
    <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <ul class="nav nav-pills gap-1.5" id="hubTabs" role="tablist">
            <li class="nav-item"><button class="nav-link active py-1 px-3 small fw-semibold" data-bs-toggle="pill" data-bs-target="#tabHubStock" type="button"><i class="bi bi-boxes me-1"></i>Hàng tồn tại kho</button></li>
            <li class="nav-item"><button class="nav-link py-1 px-3 small fw-semibold" data-bs-toggle="pill" data-bs-target="#tabHubDispatch" type="button"><i class="bi bi-truck me-1"></i>Điều phối đi kho khác</button></li>
            <li class="nav-item"><button class="nav-link py-1 px-3 small fw-semibold" data-bs-toggle="pill" data-bs-target="#tabHubZones" type="button"><i class="bi bi-grid-1x2-fill me-1"></i>Sức chứa 4 Khu & FEFO</button></li>
            <li class="nav-item"><button class="nav-link py-1 px-3 small fw-semibold" data-bs-toggle="pill" data-bs-target="#tabHubLedger" type="button"><i class="bi bi-clock-history me-1"></i>Nhật ký xuất/nhập kho</button></li>
        </ul>
        <a href="locations.php?wh=<?= $whId ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3" style="font-size:0.74rem;"><i class="bi bi-grid-3x3-gap me-1"></i>Sơ đồ Ô kệ <?= htmlspecialchars($curWh['code'] ?? '') ?></a>
    </div>

    <div class="tab-content p-3">
        <!-- Tab 1: Hàng tồn -->
        <div class="tab-pane fade show active" id="tabHubStock">
            <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
                <input type="text" id="hubStockSearch" class="form-control form-control-sm" style="max-width:320px;" placeholder="Tìm nhanh mã SKU, tên sản phẩm..." oninput="filterHubStock()">
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="nxExportTableToCSV && nxExportTableToCSV('#hubStockTable', 'ton_kho_<?= htmlspecialchars($curWh['code'] ?? 'hub') ?>.csv')"><i class="bi bi-download me-1"></i>Xuất CSV kho này</button>
            </div>
            <div class="table-responsive">
                <table class="table-clean align-middle mb-0" id="hubStockTable">
                    <thead><tr><th>Mã SKU</th><th>Tên mặt hàng</th><th>Nhóm</th><th class="text-end">Tồn hiện tại</th><th class="text-end">Định mức</th><th>Trạng thái</th></tr></thead>
                    <tbody>
                        <?php foreach ($hubItems as $hi):
                            $isLow = ((float)$hi['qty'] <= (float)$hi['min_stock']);
                        ?>
                            <tr>
                                <td class="font-monospace fw-bold text-primary"><?= htmlspecialchars($hi['code']) ?></td>
                                <td class="fw-semibold"><?= htmlspecialchars($hi['name']) ?></td>
                                <td><span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($hi['category_name']) ?></span></td>
                                <td class="text-end fw-bold"><?= number_format((float)$hi['qty']) ?> <?= htmlspecialchars($hi['unit']) ?></td>
                                <td class="text-end text-muted"><?= (int)$hi['min_stock'] ?></td>
                                <td><span class="badge <?= $isLow ? 'bg-danger' : 'bg-success' ?>"><?= $isLow ? 'Cần nhập thêm' : 'Ổn định' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 2: Điều phối nhanh từ kho này -->
        <div class="tab-pane fade" id="tabHubDispatch">
            <form method="POST" class="row g-3 p-2">
                <input type="hidden" name="action" value="hub_dispatch">
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Kho nhận hàng (Đích)</label>
                    <select name="dest_wh" class="form-select">
                        <?php foreach ($allWh as $w): if ((int)$w['id'] === $whId) continue; ?>
                            <option value="<?= $w['id'] ?>">[<?= htmlspecialchars($w['code']) ?>] <?= htmlspecialchars($w['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-bold">Chọn mặt hàng đang có tồn tại <?= htmlspecialchars($curWh['code'] ?? '') ?></label>
                    <select name="item_id" class="form-select">
                        <?php foreach ($hubItems as $hi): if ((float)$hi['qty'] <= 0) continue; ?>
                            <option value="<?= $hi['id'] ?>">[<?= htmlspecialchars($hi['code']) ?>] <?= htmlspecialchars($hi['name']) ?> (Tồn: <?= (int)$hi['qty'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Số lượng điều phối</label>
                    <input type="number" name="quantity" class="form-control" min="1" value="5" required>
                </div>
                <div class="col-md-9">
                    <label class="form-label small fw-bold">Ghi chú vận chuyển</label>
                    <input type="text" name="note" class="form-control" placeholder="VD: Điều phối cân bằng tồn kho liên vùng...">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-send-fill me-1"></i>Xuất điều phối ngay</button>
                </div>
            </form>
        </div>

        <!-- Tab 3: Sức chứa 4 Khu & Lô FEFO -->
        <div class="tab-pane fade" id="tabHubZones">
            <div class="row g-3">
                <div class="col-md-5">
                    <h6 class="fw-bold small mb-2">Tỷ lệ lấp đầy 4 phân khu tại <?= htmlspecialchars($curWh['code'] ?? '') ?></h6>
                    <div class="d-flex flex-column gap-2.5">
                        <?php
                        $zCols = ['A' => '#6366f1', 'B' => '#06b6d4', 'C' => '#ec4899', 'D' => '#10b981'];
                        foreach ($zoneStats as $zs):
                            $pct = $zs['total_bins'] > 0 ? round(($zs['filled_bins'] / $zs['total_bins']) * 100) : 0;
                            $col = $zCols[$zs['zone_code']] ?? '#6366f1';
                        ?>
                            <div class="p-2.5 rounded-3 border bg-light">
                                <div class="d-flex justify-content-between small fw-bold mb-1">
                                    <span style="color:<?= $col ?>;">Khu <?= htmlspecialchars($zs['zone_code']) ?></span>
                                    <span><?= $zs['filled_bins'] ?>/<?= $zs['total_bins'] ?> ô (<?= $pct ?>%)</span>
                                </div>
                                <div class="progress" style="height:8px;"><div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $col ?>;"></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md-7">
                    <h6 class="fw-bold small mb-2">Lô hàng ưu tiên xuất theo hạn dùng (FEFO)</h6>
                    <div class="table-responsive border rounded-3">
                        <table class="table table-sm mb-0 align-middle">
                            <thead><tr><th>Mã lô</th><th>Sản phẩm</th><th>Hạn dùng</th><th class="text-end">SL</th></tr></thead>
                            <tbody>
                                <?php foreach ($hubBatches as $b): ?>
                                    <tr>
                                        <td class="font-monospace fw-bold"><?= htmlspecialchars($b['batch_number']) ?></td>
                                        <td class="small"><?= htmlspecialchars($b['item_name']) ?></td>
                                        <td class="small"><?= htmlspecialchars($b['expiry_date'] ?? 'N/A') ?></td>
                                        <td class="text-end fw-bold text-primary"><?= number_format((float)$b['qty']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 4: Nhật ký xuất nhập kho này -->
        <div class="tab-pane fade" id="tabHubLedger">
            <div class="table-responsive">
                <table class="table-clean align-middle mb-0">
                    <thead><tr><th>Mã GD</th><th>Thời gian</th><th>Loại</th><th>Sản phẩm</th><th class="text-end">Biến động</th><th class="text-end">Tồn sau</th></tr></thead>
                    <tbody>
                        <?php foreach ($hubMovements as $m): ?>
                            <tr>
                                <td class="font-monospace small"><?= htmlspecialchars($m['movement_code']) ?></td>
                                <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
                                <td><span class="badge bg-primary-subtle text-primary"><?= htmlspecialchars($m['movement_type']) ?></span></td>
                                <td class="fw-semibold small"><?= htmlspecialchars($m['item_name']) ?></td>
                                <td class="text-end fw-bold <?= $m['quantity_in'] > 0 ? 'text-success' : 'text-danger' ?>"><?= $m['quantity_in'] > 0 ? '+' . $m['quantity_in'] : '-' . $m['quantity_out'] ?></td>
                                <td class="text-end fw-bold"><?= number_format((float)$m['balance_after']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function filterHubStock() {
    const q = (document.getElementById('hubStockSearch')?.value || '').toLowerCase();
    document.querySelectorAll('#hubStockTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
