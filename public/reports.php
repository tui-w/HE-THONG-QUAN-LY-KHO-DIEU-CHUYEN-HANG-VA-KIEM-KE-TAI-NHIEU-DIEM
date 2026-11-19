<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/ReportService.php';

use App\Database;
use App\Auth;
use App\ReportService;

Auth::startSession();
$currentUser = Auth::getCurrentUser();
if (!$currentUser) {
    header('Location: login.php');
    exit;
}

$pdo = Database::getConnection();

$tab = $_GET['tab'] ?? 'summary';
$selectedWh = isset($_GET['wh']) && $_GET['wh'] !== '' ? (int)$_GET['wh'] : 1;
$selectedItem = isset($_GET['item_id']) && $_GET['item_id'] !== '' ? (int)$_GET['item_id'] : 1;
$movementTypeFilter = trim((string)($_GET['movement_type'] ?? ''));

// Xử lý xuất file CSV từ server nếu yêu cầu (Phải chạy TRƯỚC header.php để xuất HTTP headers chuẩn)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if ($tab === 'summary') {
        $data = ReportService::getStockSummary($pdo, $selectedWh ?: null);
        $csvRows = [];
        foreach ($data as $r) {
            $csvRows[] = [
                $r['warehouse_code'],
                $r['warehouse_name'],
                $r['item_code'],
                $r['item_name'],
                $r['category_name'],
                $r['batch_number'] ?? 'Không lô',
                $r['unit'],
                $r['min_stock'],
                $r['current_stock']
            ];
        }
        ReportService::exportCsv($csvRows, [
            'Mã kho', 'Tên kho', 'Mã sản phẩm', 'Tên sản phẩm', 'Nhóm hàng', 'Lô hàng', 'ĐVT', 'Tồn an toàn', 'Tồn hiện tại'
        ], 'bao_cao_ton_kho_' . date('Ymd_His') . '.csv');
    } elseif ($tab === 'card') {
        $card = ReportService::getStockCard($pdo, $selectedWh, $selectedItem);
        $csvRows = [];
        foreach ($card as $r) {
            $csvRows[] = [
                $r['movement_code'],
                $r['created_at'],
                $r['voucher_code'],
                $r['movement_type'],
                $r['quantity_in'],
                $r['quantity_out'],
                $r['balance_after'],
                $r['note'],
                $r['approved_by_name']
            ];
        }
        ReportService::exportCsv($csvRows, [
            'Mã phát sinh', 'Thời gian', 'Số chứng từ', 'Loại phát sinh', 'Nhập', 'Xuất', 'Tồn sau', 'Ghi chú', 'Người duyệt'
        ], 'the_kho_' . $selectedItem . '_' . date('Ymd_His') . '.csv');
    } elseif ($tab === 'ledger') {
        $ledger = ReportService::getLedgerMovements($pdo, [
            'warehouse_id' => $selectedWh ?: null,
            'movement_type' => $movementTypeFilter ?: null,
            'limit' => 500
        ]);
        $csvRows = [];
        foreach ($ledger as $m) {
            $csvRows[] = [
                $m['movement_code'],
                $m['created_at'],
                $m['warehouse_code'],
                $m['voucher_code'],
                $m['movement_type'],
                $m['item_code'],
                $m['item_name'],
                $m['batch_number'] ?? '',
                $m['quantity_in'],
                $m['quantity_out'],
                $m['balance_after']
            ];
        }
        ReportService::exportCsv($csvRows, [
            'Mã phát sinh', 'Thời gian', 'Kho', 'Số chứng từ', 'Loại GD', 'Mã SP', 'Tên SP', 'Lô hàng', 'SL Nhập', 'SL Xuất', 'Tồn sau'
        ], 'so_phat_sinh_' . date('Ymd_His') . '.csv');
    } elseif ($tab === 'alerts') {
        $alerts = $pdo->query("
            SELECT i.code AS item_code, i.name AS item_name, i.min_stock,
                   COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS current_stock
            FROM items i
            LEFT JOIN stock_movements sm ON sm.item_id = i.id
            GROUP BY i.id
            HAVING current_stock <= i.min_stock
            ORDER BY current_stock ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $csvRows = [];
        foreach ($alerts as $a) {
            $csvRows[] = [
                'Tất cả kho',
                $a['item_code'] ?? '',
                $a['item_name'] ?? '',
                $a['current_stock'] ?? 0,
                $a['min_stock'] ?? 0,
            ];
        }
        ReportService::exportCsv($csvRows, ['Kho', 'Mã SP', 'Tên sản phẩm', 'Tồn hiện tại', 'Định mức tối thiểu'], 'canh_bao_ton_thap_' . date('Ymd_His') . '.csv');
    }
}

$pageTitle = 'Sổ Thẻ kho & Báo cáo đối chiếu';
require_once __DIR__ . '/../templates/header.php';

// Dữ liệu cho từng Tab
$summaryData = ($tab === 'summary') ? ReportService::getStockSummary($pdo, $selectedWh ?: null) : [];
$stockCardData = ($tab === 'card') ? ReportService::getStockCard($pdo, $selectedWh, $selectedItem) : [];
$ledgerData = ($tab === 'ledger') ? ReportService::getLedgerMovements($pdo, [
    'warehouse_id' => $selectedWh ?: null,
    'movement_type' => $movementTypeFilter ?: null,
    'limit' => 200
]) : [];

// Danh sách sản phẩm cho dropdown
$itemList = $pdo->query("SELECT id, code, name FROM items ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);
$itemInfo = ($tab === 'card') ? $pdo->query("SELECT * FROM items WHERE id = {$selectedItem}")->fetch(PDO::FETCH_ASSOC) : null;
$whInfo = $pdo->query("SELECT * FROM warehouses WHERE id = {$selectedWh}")->fetch(PDO::FETCH_ASSOC);

// Tính toán KPI phụ thuộc theo Tab
$totalStockSum = 0;
$lowStockCount = 0;
$outStockCount = 0;
if ($tab === 'summary') {
    foreach ($summaryData as $row) {
        $cur = (int)$row['current_stock'];
        $min = (int)$row['min_stock'];
        $totalStockSum += $cur;
        if ($cur <= 0) {
            $outStockCount++;
        } elseif ($cur < $min) {
            $lowStockCount++;
        }
    }
}

$cardTotalIn = 0;
$cardTotalOut = 0;
$cardFinalBalance = 0;
if ($tab === 'card' && !empty($stockCardData)) {
    foreach ($stockCardData as $c) {
        $cardTotalIn += (int)$c['quantity_in'];
        $cardTotalOut += (int)$c['quantity_out'];
    }
    $lastEntry = end($stockCardData);
    $cardFinalBalance = (int)$lastEntry['balance_after'];
}

$ledgerTotalIn = 0;
$ledgerTotalOut = 0;
if ($tab === 'ledger') {
    foreach ($ledgerData as $m) {
        $ledgerTotalIn += (int)$m['quantity_in'];
        $ledgerTotalOut += (int)$m['quantity_out'];
    }
}
?>

<!-- Header Top Banner + Tab Navigation Gọn Gàng -->
<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <h4 class="fw-bold text-dark mb-0">Báo cáo & Thẻ kho</h4>
        <div class="d-flex flex-wrap gap-1.5 p-1 bg-white rounded-3 border">
            <a class="btn btn-sm <?= $tab === 'summary' ? 'btn-primary fw-bold text-white' : 'text-secondary border-0' ?> py-1 px-2.5" 
               href="reports.php?tab=summary&wh=<?= $selectedWh ?>">
                <i class="bi bi-pie-chart-fill me-1"></i> Tồn kho Tổng hợp
            </a>
            <a class="btn btn-sm <?= $tab === 'card' ? 'btn-primary fw-bold text-white' : 'text-secondary border-0' ?> py-1 px-2.5" 
               href="reports.php?tab=card&wh=<?= $selectedWh ?>&item_id=<?= $selectedItem ?>">
                <i class="bi bi-card-checklist me-1"></i> Thẻ kho (Stock Card)
            </a>
            <a class="btn btn-sm <?= $tab === 'ledger' ? 'btn-primary fw-bold text-white' : 'text-secondary border-0' ?> py-1 px-2.5" 
               href="reports.php?tab=ledger&wh=<?= $selectedWh ?>">
                <i class="bi bi-journal-bookmark me-1"></i> Sổ cái (Ledger)
            </a>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="reports.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-clean btn-clean-secondary btn-sm">
            <i class="bi bi-file-earmark-spreadsheet text-success"></i> Xuất Server CSV
        </a>
        <button type="button" class="btn-clean btn-clean-primary btn-sm" onclick="exportActiveTabToCSV()">
            <i class="bi bi-download"></i> Tải CSV
        </button>
    </div>
</div>

<!-- TAB 1: BÁO CÁO TỒN KHO TỔNG HỢP -->
<?php if ($tab === 'summary'): ?>
    <!-- 4 KPI Metric Boxes -->
    <div class="row g-2.5 mb-3">
        <div class="col-6 col-xl-3">
            <div class="metric-box">
                <div>
                    <div class="metric-label">TỔNG TỒN KHO</div>
                    <div class="metric-val text-primary"><?= number_format((float)$totalStockSum) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="metric-box">
                <div>
                    <div class="metric-label">SỐ MẶT HÀNG / LÔ</div>
                    <div class="metric-val"><?= count($summaryData) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="metric-box">
                <div>
                    <div class="metric-label">TỒN THẤP</div>
                    <div class="metric-val <?= $lowStockCount > 0 ? 'text-warning' : 'text-success' ?>"><?= $lowStockCount ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="metric-box">
                <div>
                    <div class="metric-label">HẾT HÀNG</div>
                    <div class="metric-val <?= $outStockCount > 0 ? 'text-danger' : 'text-success' ?>"><?= $outStockCount ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN CHÍNH: Main Table + Integrated Filter Bar -->
    <div class="clean-card main-primary-card overflow-hidden">
        <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-bold text-dark mb-0">Bảng số dư tồn kho chi tiết</h6>
                <span class="badge bg-light text-secondary border"><?= count($summaryData) ?> dòng</span>
            </div>
            <form method="GET" action="reports.php" class="d-flex flex-wrap gap-2 align-items-center m-0">
                <input type="hidden" name="tab" value="summary">
                <select name="wh" class="form-select form-select-sm" style="width: 200px;" onchange="this.form.submit()">
                    <option value="">Tất cả điểm kho</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>" <?= $selectedWh === (int)$wh['id'] ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="input-group input-group-sm" style="width: 240px;">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                    <input type="text" id="summarySearchInput" class="form-control border-start-0 ps-0" placeholder="Tìm mã hàng, tên SP, lô..." onkeyup="filterSummaryTable()">
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table-clean" id="summaryTable">
                <thead>
                    <tr>
                        <th>Điểm kho</th>
                        <th>Mã hàng</th>
                        <th>Tên sản phẩm</th>
                        <th>Nhóm hàng</th>
                        <th>Lô hàng</th>
                        <th>Hạn dùng</th>
                        <th>ĐVT</th>
                        <th class="text-center">Định mức</th>
                        <th class="text-center">Số lượng tồn</th>
                        <th class="text-center">Thẻ kho</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $grandTotal = 0;
                    foreach ($summaryData as $row): 
                        $stock = (int)$row['current_stock'];
                        $min = (int)$row['min_stock'];
                        $grandTotal += $stock;
                    ?>
                        <tr>
                            <td><span class="chip chip-neutral"><?= htmlspecialchars($row['warehouse_code']) ?></span></td>
                            <td><span class="text-secondary font-monospace small"><?= htmlspecialchars($row['item_code']) ?></span></td>
                            <td>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($row['item_name']) ?></div>
                            </td>
                            <td><span class="chip chip-slate"><?= htmlspecialchars($row['category_name']) ?></span></td>
                            <td>
                                <?= $row['batch_number'] ? "<span class='chip chip-indigo font-monospace'>{$row['batch_number']}</span>" : '<span class="text-muted small">-</span>' ?>
                            </td>
                            <td class="small text-secondary"><?= $row['expiry_date'] ?: '<span class="text-muted">-</span>' ?></td>
                            <td class="small text-secondary"><?= htmlspecialchars($row['unit']) ?></td>
                            <td class="text-center small text-secondary"><?= number_format((float)$min) ?></td>
                            <td class="text-center">
                                <?php if ($stock <= 0): ?>
                                    <span class="chip chip-danger">Hết hàng (0)</span>
                                <?php elseif ($stock < $min): ?>
                                    <span class="chip chip-warning">Tồn thấp: <?= number_format((float)$stock) ?></span>
                                <?php else: ?>
                                    <span class="chip chip-success"><?= number_format((float)$stock) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="reports.php?tab=card&wh=<?= $row['warehouse_id'] ?>&item_id=<?= $row['item_id'] ?>" 
                                   class="btn-clean btn-clean-secondary btn-sm p-1 px-2" title="Tra cứu chi tiết thẻ kho">
                                    <i class="bi bi-card-checklist text-primary"></i> Tra cứu
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-light">
                        <td colspan="8" class="text-end fw-bold py-3 text-secondary">TỔNG LƯỢNG HÀNG TỒN:</td>
                        <td class="text-center fw-bold fs-6 text-primary py-3"><?= number_format((float)$grandTotal) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- TAB 2: SỔ THẺ KHO CHI TIẾT (STOCK CARD) -->
<?php if ($tab === 'card'): ?>
    <!-- 4 KPI Metric Boxes for Stock Card -->
    <div class="row g-2.5 mb-3">
        <div class="col-6 col-xl-3">
            <div class="metric-box">
                <div>
                    <div class="metric-label">TỒN CUỐI KỲ</div>
                    <div class="metric-val text-primary"><?= number_format((float)$cardFinalBalance) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="metric-box">
                <div>
                    <div class="metric-label">TỔNG NHẬP (+)</div>
                    <div class="metric-val text-success">+<?= number_format((float)$cardTotalIn) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="metric-box">
                <div>
                    <div class="metric-label">TỔNG XUẤT (-)</div>
                    <div class="metric-val text-danger">-<?= number_format((float)$cardTotalOut) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="metric-box">
                <div>
                    <div class="metric-label">LƯỢT PHÁT SINH</div>
                    <div class="metric-val"><?= count($stockCardData) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN CHÍNH: Bảng chi tiết thẻ kho tích hợp thanh lọc -->
    <div class="clean-card main-primary-card mb-4 overflow-hidden">
        <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-bold text-dark mb-0">Sổ Thẻ kho: <span class="text-primary"><?= htmlspecialchars((string)($itemInfo['name'] ?? '')) ?></span></h6>
                <span class="chip chip-neutral"><?= htmlspecialchars((string)($whInfo['code'] ?? '')) ?></span>
            </div>
            <div class="input-group input-group-sm" style="width: 240px;">
                <span class="input-group-text bg-white border-end-0 text-slate-400"><i class="bi bi-search"></i></span>
                <input type="text" id="stockCardSearchInput" class="form-control border-start-0 ps-0" placeholder="Lọc chứng từ, diễn giải..." onkeyup="filterStockCardTable()">
            </div>
        </div>
        <div class="bg-slate-50 border-bottom px-3 py-2">
            <form method="GET" action="reports.php" class="row g-2 align-items-center m-0">
                <input type="hidden" name="tab" value="card">
                <div class="col-md-4 ps-0">
                    <select name="wh" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= $wh['id'] ?>" <?= $selectedWh === (int)$wh['id'] ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <select name="item_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($itemList as $it): ?>
                            <option value="<?= $it['id'] ?>" <?= $selectedItem === (int)$it['id'] ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($it['code']) ?>] <?= htmlspecialchars($it['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 pe-0">
                    <button type="submit" class="btn-clean btn-clean-primary btn-sm w-100 py-1 justify-content-center">
                        <i class="bi bi-search"></i> Tra cứu
                    </button>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table-clean" id="stockCardTable">
                <thead>
                    <tr>
                        <th>Mã phát sinh</th>
                        <th>Ngày giờ</th>
                        <th>Số chứng từ</th>
                        <th>Loại phát sinh</th>
                        <th>Diễn giải nội dung</th>
                        <th class="text-center">Nhập (+)</th>
                        <th class="text-center">Xuất (-)</th>
                        <th class="text-center">Tồn lũy kế</th>
                        <th>Người duyệt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockCardData)): ?>
                        <tr><td colspan="9" class="text-center py-5 text-muted">Chưa có phát sinh nào cho mặt hàng này tại kho đã chọn.</td></tr>
                    <?php else: ?>
                        <?php foreach ($stockCardData as $card): 
                            $badgeClass = match ($card['movement_type']) {
                                'IN' => 'chip-success',
                                'OUT' => 'chip-danger',
                                'TRANSFER_OUT', 'TRANSFER_IN' => 'chip-primary',
                                default => 'chip-warning',
                            };
                            $badgeText = match ($card['movement_type']) {
                                'IN' => 'Nhập kho',
                                'OUT' => 'Xuất kho',
                                'TRANSFER_OUT' => 'Xuất chuyển',
                                'TRANSFER_IN' => 'Nhập chuyển',
                                default => 'Điều chỉnh',
                            };
                        ?>
                            <tr>
                                <td><span class="text-secondary font-monospace small"><?= htmlspecialchars($card['movement_code']) ?></span></td>
                                <td class="small text-secondary"><?= date('d/m/Y H:i', strtotime($card['created_at'])) ?></td>
                                <td>
                                    <a href="voucher_detail.php?code=<?= urlencode($card['voucher_code']) ?>" class="fw-semibold text-primary text-decoration-none">
                                        <?= htmlspecialchars($card['voucher_code']) ?>
                                    </a>
                                </td>
                                <td><span class="chip <?= $badgeClass ?>"><?= $badgeText ?></span></td>
                                <td class="small text-dark"><?= htmlspecialchars($card['note']) ?></td>
                                <td class="text-center fw-bold text-success">
                                    <?= $card['quantity_in'] > 0 ? '+' . number_format((float)$card['quantity_in']) : '<span class="text-muted fw-normal">-</span>' ?>
                                </td>
                                <td class="text-center fw-bold text-danger">
                                    <?= $card['quantity_out'] > 0 ? '-' . number_format((float)$card['quantity_out']) : '<span class="text-muted fw-normal">-</span>' ?>
                                </td>
                                <td class="text-center fw-bold text-dark">
                                    <?= number_format((float)$card['balance_after']) ?>
                                </td>
                                <td class="small text-secondary"><?= htmlspecialchars((string)$card['approved_by_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- TAB 3: SỔ PHÁT SINH TOÀN HỆ THỐNG (LEDGER) -->
<?php if ($tab === 'ledger'): ?>
    <!-- 3 KPI Metric Boxes for Ledger -->
    <div class="row g-2.5 mb-3">
        <div class="col-sm-4">
            <div class="metric-box">
                <div>
                    <div class="metric-label">TỔNG PHÁT SINH</div>
                    <div class="metric-val"><?= count($ledgerData) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="metric-box">
                <div>
                    <div class="metric-label">TỔNG NHẬP (+)</div>
                    <div class="metric-val text-success">+<?= number_format((float)$ledgerTotalIn) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="metric-box">
                <div>
                    <div class="metric-label">TỔNG XUẤT (-)</div>
                    <div class="metric-val text-danger">-<?= number_format((float)$ledgerTotalOut) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN CHÍNH: Sổ cái phát sinh tích hợp thanh lọc -->
    <div class="clean-card main-primary-card mb-4 overflow-hidden">
        <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-bold text-dark mb-0">Sổ cái phát sinh toàn mạng lưới</h6>
                <span class="badge bg-light text-secondary border"><?= count($ledgerData) ?> giao dịch</span>
            </div>
            <div class="input-group input-group-sm" style="width: 250px;">
                <span class="input-group-text bg-white border-end-0 text-slate-400"><i class="bi bi-search"></i></span>
                <input type="text" id="ledgerSearchInput" class="form-control border-start-0 ps-0" placeholder="Lọc nhanh mã GD, chứng từ, SP..." onkeyup="filterLedgerTable()">
            </div>
        </div>
        <div class="bg-slate-50 border-bottom px-3 py-2">
            <form method="GET" action="reports.php" class="row g-2 align-items-center m-0">
                <input type="hidden" name="tab" value="ledger">
                <div class="col-md-5 ps-0">
                    <select name="wh" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Điểm kho: Toàn mạng lưới</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= $wh['id'] ?>" <?= $selectedWh === (int)$wh['id'] ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <select name="movement_type" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Loại phát sinh: Tất cả</option>
                        <option value="IN" <?= $movementTypeFilter === 'IN' ? 'selected' : '' ?>>Nhập kho (IN)</option>
                        <option value="OUT" <?= $movementTypeFilter === 'OUT' ? 'selected' : '' ?>>Xuất kho (OUT)</option>
                        <option value="TRANSFER_OUT" <?= $movementTypeFilter === 'TRANSFER_OUT' ? 'selected' : '' ?>>Xuất điều chuyển (TRANSFER_OUT)</option>
                        <option value="TRANSFER_IN" <?= $movementTypeFilter === 'TRANSFER_IN' ? 'selected' : '' ?>>Nhập điều chuyển (TRANSFER_IN)</option>
                        <option value="ADJUST_IN" <?= $movementTypeFilter === 'ADJUST_IN' ? 'selected' : '' ?>>Điều chỉnh tăng (ADJUST_IN)</option>
                        <option value="ADJUST_OUT" <?= $movementTypeFilter === 'ADJUST_OUT' ? 'selected' : '' ?>>Điều chỉnh giảm (ADJUST_OUT)</option>
                    </select>
                </div>
                <div class="col-md-2 pe-0">
                    <button type="submit" class="btn-clean btn-clean-primary btn-sm w-100 py-1 justify-content-center">
                        <i class="bi bi-funnel"></i> Lọc
                    </button>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table-clean" id="ledgerTable">
                <thead>
                    <tr>
                        <th>Mã phát sinh</th>
                        <th>Thời gian</th>
                        <th>Kho</th>
                        <th>Chứng từ</th>
                        <th>Loại phát sinh</th>
                        <th>Sản phẩm</th>
                        <th>Lô hàng</th>
                        <th class="text-center">Nhập (+)</th>
                        <th class="text-center">Xuất (-)</th>
                        <th class="text-center">Tồn sau</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ledgerData as $m): 
                        $badgeClass = match ($m['movement_type']) {
                            'IN' => 'chip-success',
                            'OUT' => 'chip-danger',
                            'TRANSFER_OUT', 'TRANSFER_IN' => 'chip-primary',
                            default => 'chip-warning',
                        };
                        $badgeText = match ($m['movement_type']) {
                            'IN' => 'Nhập kho',
                            'OUT' => 'Xuất kho',
                            'TRANSFER_OUT' => 'Xuất chuyển',
                            'TRANSFER_IN' => 'Nhập chuyển',
                            default => 'Điều chỉnh',
                        };
                    ?>
                        <tr>
                            <td><span class="text-secondary font-monospace small"><?= htmlspecialchars($m['movement_code']) ?></span></td>
                            <td class="small text-secondary"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
                            <td><span class="chip chip-neutral"><?= htmlspecialchars($m['warehouse_code']) ?></span></td>
                            <td>
                                <a href="voucher_detail.php?code=<?= urlencode($m['voucher_code']) ?>" class="fw-semibold text-primary text-decoration-none">
                                    <?= htmlspecialchars($m['voucher_code']) ?>
                                </a>
                            </td>
                            <td><span class="chip <?= $badgeClass ?>"><?= $badgeText ?></span></td>
                            <td>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($m['item_name']) ?></div>
                                <span class="text-secondary font-monospace small"><?= htmlspecialchars($m['item_code']) ?></span>
                            </td>
                            <td><?= $m['batch_number'] ? "<span class='chip chip-indigo font-monospace'>{$m['batch_number']}</span>" : '<span class="text-muted small">-</span>' ?></td>
                            <td class="text-center text-success fw-bold"><?= $m['quantity_in'] > 0 ? '+' . number_format((float)$m['quantity_in']) : '<span class="text-muted fw-normal">-</span>' ?></td>
                            <td class="text-center text-danger fw-bold"><?= $m['quantity_out'] > 0 ? '-' . number_format((float)$m['quantity_out']) : '<span class="text-muted fw-normal">-</span>' ?></td>
                            <td class="text-center fw-bold text-dark"><?= number_format((float)$m['balance_after']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Quick Search and Client-side CSV Script -->
<script>
function filterSummaryTable() {
    const input = document.getElementById('summarySearchInput');
    if (!input) return;
    const filter = input.value.toLowerCase();
    const rows = document.querySelectorAll('#summaryTable tbody tr');
    
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
}

function filterStockCardTable() {
    const input = document.getElementById('stockCardSearchInput');
    if (!input) return;
    const filter = input.value.toLowerCase();
    const rows = document.querySelectorAll('#stockCardTable tbody tr');
    
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
}

function filterLedgerTable() {
    const input = document.getElementById('ledgerSearchInput');
    if (!input) return;
    const filter = input.value.toLowerCase();
    const rows = document.querySelectorAll('#ledgerTable tbody tr');
    
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
}

function exportActiveTabToCSV() {
    let selector = null;
    let filename = 'report_export.csv';
    <?php if ($tab === 'summary'): ?>
        selector = '#summaryTable';
        filename = 'bao_cao_ton_kho_' + new Date().toISOString().slice(0, 10) + '.csv';
    <?php elseif ($tab === 'card'): ?>
        selector = '#stockCardTable';
        filename = 'the_kho_' + new Date().toISOString().slice(0, 10) + '.csv';
    <?php elseif ($tab === 'ledger'): ?>
        selector = '#ledgerTable';
        filename = 'so_phat_sinh_' + new Date().toISOString().slice(0, 10) + '.csv';
    <?php endif; ?>
    if (selector && window.nxExportTableToCSV) {
        nxExportTableToCSV(selector, filename);
    }
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
