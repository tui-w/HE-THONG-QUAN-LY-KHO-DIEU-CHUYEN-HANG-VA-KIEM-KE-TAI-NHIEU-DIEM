<?php
declare(strict_types=1);

$pageTitle = 'Dự báo Nhu cầu & Đề xuất Đặt hàng';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../src/ForecastService.php';
require_once __DIR__ . '/../src/StockService.php';

use App\ForecastService;
use App\StockService;

$whFilter = isset($_GET['wh']) && $_GET['wh'] !== '' ? (int)$_GET['wh'] : null;
$leadTime = max(1, min(60, (int)($_GET['lead_time'] ?? 7)));
$safetyDays = max(1, min(30, (int)($_GET['safety_days'] ?? 5)));
$filterUrgency = trim((string)($_GET['urgency'] ?? ''));

// Xử lý tạo Phiếu Nhập kho dự thảo (Draft Inbound Voucher) tự động từ các mặt hàng được chọn
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_auto_reorder_voucher') {
    try {
        $targetWh = (int)($_POST['target_warehouse_id'] ?? 1);
        $selectedItems = $_POST['selected_items'] ?? [];
        $orderQtys = $_POST['order_qty'] ?? [];

        if (empty($selectedItems)) {
            throw new \RuntimeException('Vui lòng chọn ít nhất một mặt hàng để tạo phiếu đề xuất nhập kho!');
        }

        $lines = [];
        foreach ($selectedItems as $itemIdStr) {
            $itemId = (int)$itemIdStr;
            $qty = (int)($orderQtys[$itemId] ?? 0);
            if ($qty > 0) {
                // Lấy lô mặc định hoặc null
                $stmtBatch = $pdo->prepare("SELECT id FROM batches WHERE item_id = ? ORDER BY id ASC LIMIT 1");
                $stmtBatch->execute([$itemId]);
                $batchId = $stmtBatch->fetchColumn() ?: null;

                $lines[] = [
                    'item_id' => $itemId,
                    'batch_id' => $batchId ? (int)$batchId : null,
                    'quantity' => $qty,
                    'unit_price' => 0,
                    'note' => 'Đề xuất tự động từ hệ thống Dự báo nhu cầu tồn kho'
                ];
            }
        }

        if (empty($lines)) {
            throw new \RuntimeException('Tổng số lượng đặt hàng đề xuất phải lớn hơn 0!');
        }

        $newVoucherId = StockService::createVoucher(
            $pdo,
            'IN',
            null,
            $targetWh,
            $lines,
            (int)$currentUser['id'],
            "Đơn đề xuất nhập bổ sung kho tự động (Auto-Reorder Forecast)"
        );

        $_SESSION['flash_success'] = "Tạo thành công phiếu nhập dự thảo (DRAFT) từ đề xuất thông minh! Mã chứng từ đã được chuyển sang danh sách chờ duyệt.";
        header("Location: voucher_detail.php?id={$newVoucherId}");
        exit;
    } catch (\Exception $e) {
        $_SESSION['flash_error'] = "LỖI TẠO PHIẾU ĐỀ XUẤT: " . $e->getMessage();
        header("Location: forecast.php?wh={$whFilter}");
        exit;
    }
}

// Lấy kết quả phân tích dự báo
$forecastList = ForecastService::getForecastAnalysis($pdo, $whFilter, $leadTime, $safetyDays);

// Thống kê nhanh
$totalItems = count($forecastList);
$criticalCount = 0;
$reorderCount = 0;
$safeCount = 0;
$totalSuggestedOrderQty = 0;

foreach ($forecastList as $f) {
    if ($f['urgency'] === 'OUT_OF_STOCK' || $f['urgency'] === 'CRITICAL') {
        $criticalCount++;
    } elseif ($f['urgency'] === 'REORDER') {
        $reorderCount++;
    } else {
        $safeCount++;
    }
    $totalSuggestedOrderQty += (int)$f['suggested_reorder_qty'];
}

// Lọc hiển thị nếu có
if ($filterUrgency !== '') {
    $forecastList = array_filter($forecastList, fn($f) => $f['urgency'] === $filterUrgency);
}
?>

<!-- Header Top Banner Gọn Gàng -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold text-slate-900 mb-0" style="letter-spacing: -0.02em;">Dự báo & Đề xuất đặt hàng</h4>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn-clean btn-clean-secondary btn-sm" onclick="exportForecastToCSV()">
            <i class="bi bi-download"></i> Xuất CSV
        </button>
        <button type="button" class="btn-clean btn-clean-primary btn-sm shadow-xs" onclick="document.getElementById('reorderForm').submit()">
            <i class="bi bi-cart-plus-fill"></i> Lập phiếu nhập đề xuất
        </button>
    </div>
</div>

<!-- 4 KPI Boxes Tinh Gọn -->
<div class="row g-2.5 mb-3">
    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">CẦN ĐẶT GẤP</div>
                    <div class="metric-val text-danger"><?= number_format((float)$criticalCount) ?></div>
                </div>
                <div class="metric-icon-box bg-danger-subtle text-danger">
                    <i class="bi bi-exclamation-octagon-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">CHẠM NGƯỠNG ROP</div>
                    <div class="metric-val text-warning"><?= number_format((float)$reorderCount) ?></div>
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
                    <div class="metric-label">TỒN AN TOÀN</div>
                    <div class="metric-val text-success"><?= number_format((float)$safeCount) ?></div>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-shield-check"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">TỔNG SL ĐỀ XUẤT</div>
                    <div class="metric-val text-primary"><?= number_format((float)$totalSuggestedOrderQty) ?></div>
                </div>
                <div class="metric-icon-box bg-primary-light text-primary">
                    <i class="bi bi-cart-check-fill"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bảng Danh Sách Phân Tích & Đề Xuất Đặt Hàng Tích Hợp Tìm Kiếm & Lọc -->
<div class="clean-card main-primary-card overflow-hidden mb-4">
    <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
            <h6 class="fw-bold text-slate-900 mb-0">Phân tích nhu cầu & Điểm đặt hàng lại (ROP)</h6>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-1.5">
            <a href="forecast.php?wh=<?= $whFilter ?>&lead_time=<?= $leadTime ?>&safety_days=<?= $safetyDays ?>" class="btn btn-sm <?= $filterUrgency === '' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Tất cả (<?= $totalItems ?>)
            </a>
            <a href="forecast.php?wh=<?= $whFilter ?>&lead_time=<?= $leadTime ?>&safety_days=<?= $safetyDays ?>&urgency=CRITICAL" class="btn btn-sm <?= $filterUrgency === 'CRITICAL' ? 'btn-danger fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Cần gấp (<?= $criticalCount ?>)
            </a>
            <a href="forecast.php?wh=<?= $whFilter ?>&lead_time=<?= $leadTime ?>&safety_days=<?= $safetyDays ?>&urgency=REORDER" class="btn btn-sm <?= $filterUrgency === 'REORDER' ? 'btn-warning fw-bold text-dark' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Chạm ngưỡng (<?= $reorderCount ?>)
            </a>
            <a href="forecast.php?wh=<?= $whFilter ?>&lead_time=<?= $leadTime ?>&safety_days=<?= $safetyDays ?>&urgency=SAFE" class="btn btn-sm <?= $filterUrgency === 'SAFE' ? 'btn-success fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                An toàn (<?= $safeCount ?>)
            </a>
        </div>
    </div>

    <!-- Thanh Tìm kiếm & Tham số gọn trên 1 hàng -->
    <div class="bg-slate-50 border-bottom px-3 py-2">
        <form method="GET" action="forecast.php" class="row g-2 align-items-center m-0">
            <div class="col-md-4 ps-0">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0 text-slate-400"><i class="bi bi-search"></i></span>
                    <input type="text" id="forecastSearchInput" class="form-control border-start-0 ps-0" placeholder="Tìm mã hàng, tên sản phẩm, nhóm..." onkeyup="filterForecastTable()">
                </div>
            </div>
            <div class="col-md-3">
                <select name="wh" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Kho phân tích: Toàn mạng lưới</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>" <?= $whFilter === (int)$wh['id'] ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white text-muted">Lead</span>
                    <input type="number" name="lead_time" class="form-control text-center" value="<?= $leadTime ?>" min="1" max="60">
                    <span class="input-group-text bg-light text-muted">ngày</span>
                </div>
            </div>
            <div class="col-md-2">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white text-muted">Dự phòng</span>
                    <input type="number" name="safety_days" class="form-control text-center" value="<?= $safetyDays ?>" min="1" max="30">
                </div>
            </div>
            <div class="col-md-1 pe-0">
                <button type="submit" class="btn-clean btn-clean-primary btn-sm w-100 py-1 justify-content-center" title="Tính toán lại">
                    <i class="bi bi-calculator"></i>
                </button>
            </div>
        </form>
    </div>

    <form method="POST" action="forecast.php" id="reorderForm">
        <input type="hidden" name="action" value="create_auto_reorder_voucher">
        <input type="hidden" name="target_warehouse_id" value="<?= $whFilter ?: 1 ?>">

        <div class="table-responsive">
            <table class="table-clean align-middle mb-0" id="forecastTable">
                <thead>
                    <tr>
                        <th style="width: 40px;" class="ps-3">
                            <input type="checkbox" class="form-check-input" id="checkMaster" onclick="toggleAllCheckboxes(this)">
                        </th>
                        <th>Mặt hàng</th>
                        <th>Nhóm ngành</th>
                        <th class="text-center">Tồn hiện tại</th>
                        <th class="text-center">Tốc độ xuất/ngày</th>
                        <th class="text-center">Số ngày đủ bán</th>
                        <th class="text-center">Điểm ROP</th>
                        <th>Mức độ cấp thiết</th>
                        <th class="text-end pe-4" style="width: 170px;">Đề xuất đặt (SL)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($forecastList)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-slate-400">
                                <i class="bi bi-check2-circle fs-2 d-block mb-2 text-success"></i>
                                Tồn kho hiện tại đang ở trạng thái an toàn, không có mặt hàng nào cần đặt gấp!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($forecastList as $item): 
                            $urgencyBadge = match ($item['urgency']) {
                                'OUT_OF_STOCK' => '<span class="chip chip-danger"><i class="bi bi-x-circle-fill"></i> Hết hàng</span>',
                                'CRITICAL' => '<span class="chip chip-danger"><i class="bi bi-exclamation-triangle-fill"></i> Cực kỳ cấp bách</span>',
                                'REORDER' => '<span class="chip chip-warning"><i class="bi bi-arrow-repeat"></i> Nên đặt ngay</span>',
                                default => '<span class="chip chip-success"><i class="bi bi-shield-check"></i> An toàn</span>',
                            };

                            $daysColor = match(true) {
                                $item['days_remaining'] <= $leadTime => 'text-danger fw-bold',
                                $item['days_remaining'] <= ($leadTime + $safetyDays) => 'text-warning fw-bold',
                                default => 'text-success fw-semibold'
                            };

                            $shouldCheck = ($item['urgency'] === 'OUT_OF_STOCK' || $item['urgency'] === 'CRITICAL' || $item['urgency'] === 'REORDER');
                        ?>
                            <tr>
                                <td class="ps-3">
                                    <input type="checkbox" name="selected_items[]" value="<?= $item['item_id'] ?>" class="form-check-input item-check" <?= $shouldCheck ? 'checked' : '' ?>>
                                </td>
                                <td>
                                    <div class="fw-bold text-slate-900"><?= htmlspecialchars($item['name']) ?></div>
                                    <span class="font-monospace text-slate-500 small">[<?= htmlspecialchars($item['code']) ?>]</span>
                                </td>
                                <td><span class="chip chip-slate"><?= htmlspecialchars($item['category_name']) ?></span></td>
                                <td class="text-center fw-bold <?= $item['current_stock'] <= $item['min_stock'] ? 'text-danger' : 'text-slate-800' ?>">
                                    <?= number_format((float)$item['current_stock']) ?> <small class="text-muted fw-normal"><?= $item['unit'] ?></small>
                                </td>
                                <td class="text-center text-slate-700">
                                    <strong><?= $item['avg_daily_demand'] ?></strong> <small class="text-muted"><?= $item['unit'] ?>/ngày</small>
                                </td>
                                <td class="text-center <?= $daysColor ?>">
                                    <?= $item['days_remaining'] > 365 ? '> 1 năm' : $item['days_remaining'] . ' ngày' ?>
                                </td>
                                <td class="text-center text-slate-600 font-monospace">
                                    <?= $item['reorder_point'] ?> <?= $item['unit'] ?>
                                </td>
                                <td><?= $urgencyBadge ?></td>
                                <td class="text-end pe-4">
                                    <div class="input-group input-group-sm ms-auto" style="max-width: 130px;">
                                        <input type="number" name="order_qty[<?= $item['item_id'] ?>]" class="form-control text-end fw-bold text-primary" value="<?= $item['suggested_reorder_qty'] ?>" min="0" step="5">
                                        <span class="input-group-text bg-light text-muted"><?= $item['unit'] ?></span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="p-3 border-top bg-white d-flex justify-content-between align-items-center">
            <div class="small text-slate-500">
                <i class="bi bi-info-circle me-1"></i> Thuật toán Reorder Point: <code>ROP = (Nhu cầu TB × LeadTime) + SafetyStock</code>.
            </div>
            <button type="submit" class="btn-clean btn-clean-primary shadow-xs">
                <i class="bi bi-cart-plus-fill"></i> Tạo phiếu nhập dự thảo ngay
            </button>
        </div>
    </form>
</div>

<script>
function filterForecastTable() {
    const q = (document.getElementById('forecastSearchInput')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#forecastTable tbody tr').forEach(r => {
        const text = r.innerText.toLowerCase();
        r.style.display = (q === '' || text.includes(q)) ? '' : 'none';
    });
}

function toggleAllCheckboxes(master) {
    document.querySelectorAll('.item-check').forEach(cb => {
        cb.checked = master.checked;
    });
}

function toggleSelectAll(btn) {
    const checkboxes = document.querySelectorAll('.item-check');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    checkboxes.forEach(cb => cb.checked = !allChecked);
    const master = document.getElementById('checkMaster');
    if (master) master.checked = !allChecked;
}

function exportForecastToCSV() {
    nxExportTableToCSV('#forecastTable', 'du_bao_ton_kho_' + new Date().toISOString().slice(0, 10) + '.csv', { startCol: 1 });
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
