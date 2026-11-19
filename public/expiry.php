<?php
declare(strict_types=1);

$pageTitle = 'Ma trận Quản trị Hạn dùng & FEFO/FIFO';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../src/StockService.php';

use App\StockService;

$whFilter = isset($_GET['wh']) && $_GET['wh'] !== '' ? (int)$_GET['wh'] : null;
$shelfLifeFilter = trim((string)($_GET['status'] ?? ''));

// Truy vấn danh sách các lô hàng kèm tồn kho thực tế và hạn sử dụng
$sql = "
    SELECT 
        b.id AS batch_id,
        b.batch_number,
        b.manufacture_date,
        b.expiry_date,
        i.id AS item_id,
        i.code AS item_code,
        i.name AS item_name,
        i.unit,
        COALESCE(c.name, 'Chung') AS category_name,
        w.id AS warehouse_id,
        w.code AS warehouse_code,
        w.name AS warehouse_name,
        COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS batch_stock,
        CAST((julianday(b.expiry_date) - julianday('now')) AS INTEGER) AS days_to_expiry
    FROM batches b
    JOIN items i ON b.item_id = i.id
    LEFT JOIN item_categories c ON i.category_id = c.id
    CROSS JOIN warehouses w
    LEFT JOIN stock_movements sm ON sm.item_id = i.id AND sm.batch_id = b.id AND sm.warehouse_id = w.id
    WHERE 1=1
";

$params = [];
if ($whFilter !== null) {
    $sql .= " AND w.id = :wh";
    $params[':wh'] = $whFilter;
}

$sql .= "
    GROUP BY b.id, w.id
    HAVING batch_stock > 0
    ORDER BY b.expiry_date ASC, b.id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rawBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Phân loại trạng thái hạn sử dụng (Shelf Life Health Status)
$batches = [];
$statExpired = 0;
$statUrgent = 0;   // <= 30 ngày
$statWarning = 0;  // 31 - 90 ngày
$statSafe = 0;     // > 90 ngày

foreach ($rawBatches as $b) {
    $days = (int)$b['days_to_expiry'];
    if ($days < 0) {
        $status = 'EXPIRED';
        $statExpired++;
    } elseif ($days <= 30) {
        $status = 'CRITICAL';
        $statUrgent++;
    } elseif ($days <= 90) {
        $status = 'WARNING';
        $statWarning++;
    } else {
        $status = 'GOOD';
        $statSafe++;
    }

    $b['shelf_status'] = $status;
    if ($shelfLifeFilter === '' || $shelfLifeFilter === $status) {
        $batches[] = $b;
    }
}
?>

<!-- Header Top Banner Gọn Gàng -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold text-slate-900 mb-0" style="letter-spacing: -0.02em;">Quản trị Lô hàng & Hạn dùng (FEFO)</h4>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn-clean btn-clean-secondary btn-sm" onclick="exportExpiryToCSV()">
            <i class="bi bi-download"></i> Xuất CSV
        </button>
        <a href="barcode.php" class="btn-clean btn-clean-secondary btn-sm">
            <i class="bi bi-upc-scan text-primary"></i> In tem lô
        </a>
        <a href="vouchers.php" class="btn-clean btn-clean-primary btn-sm shadow-xs">
            <i class="bi bi-box-arrow-up-right"></i> Lập phiếu xuất FEFO
        </a>
    </div>
</div>

<!-- 4 Thẻ KPI Phân loại hạn dùng -->
<div class="row g-2.5 mb-3">
    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">QUÁ HẠN</div>
                    <div class="metric-val text-danger"><?= number_format((float)$statExpired) ?></div>
                </div>
                <div class="metric-icon-box bg-danger-subtle text-danger">
                    <i class="bi bi-x-octagon-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">CẬN HẠN (≤ 30 NGÀY)</div>
                    <div class="metric-val text-warning"><?= number_format((float)$statUrgent) ?></div>
                </div>
                <div class="metric-icon-box bg-warning-subtle text-warning">
                    <i class="bi bi-hourglass-bottom"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">LƯU TÂM (31-90 NGÀY)</div>
                    <div class="metric-val text-info"><?= number_format((float)$statWarning) ?></div>
                </div>
                <div class="metric-icon-box bg-info-subtle text-info">
                    <i class="bi bi-calendar2-week-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">AN TOÀN (> 90 NGÀY)</div>
                    <div class="metric-val text-success"><?= number_format((float)$statSafe) ?></div>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-shield-check"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bảng Ma Trận FEFO Tích Hợp Tìm Kiếm & Lọc Nhanh -->
<div class="clean-card main-primary-card overflow-hidden mb-4">
    <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="fw-bold text-slate-900 mb-0">Danh sách lô hàng ưu tiên xuất (FEFO)</h6>
        <div class="d-flex flex-wrap gap-1.5 align-items-center">
            <a href="expiry.php?wh=<?= $whFilter ?>" class="btn btn-sm <?= $shelfLifeFilter === '' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">Tất cả</a>
            <a href="expiry.php?wh=<?= $whFilter ?>&status=EXPIRED" class="btn btn-sm <?= $shelfLifeFilter === 'EXPIRED' ? 'btn-danger fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">Quá hạn (<?= $statExpired ?>)</a>
            <a href="expiry.php?wh=<?= $whFilter ?>&status=CRITICAL" class="btn btn-sm <?= $shelfLifeFilter === 'CRITICAL' ? 'btn-danger fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">≤ 30 ngày (<?= $statUrgent ?>)</a>
            <a href="expiry.php?wh=<?= $whFilter ?>&status=WARNING" class="btn btn-sm <?= $shelfLifeFilter === 'WARNING' ? 'btn-warning fw-bold text-dark' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">31-90 ngày (<?= $statWarning ?>)</a>
            <a href="expiry.php?wh=<?= $whFilter ?>&status=GOOD" class="btn btn-sm <?= $shelfLifeFilter === 'GOOD' ? 'btn-success fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">> 90 ngày (<?= $statSafe ?>)</a>
        </div>
    </div>

    <div class="bg-slate-50 border-bottom px-3 py-2">
        <form method="GET" action="expiry.php" class="row g-2 align-items-center m-0">
            <input type="hidden" name="status" value="<?= htmlspecialchars($shelfLifeFilter) ?>">
            <div class="col-md-6 ps-0">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0 text-slate-400"><i class="bi bi-search"></i></span>
                    <input type="text" id="expirySearchInput" class="form-control border-start-0 ps-0" placeholder="Tìm nhanh số lô, mã hàng, tên sản phẩm..." onkeyup="filterExpiryTable()">
                </div>
            </div>
            <div class="col-md-4">
                <select name="wh" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Điểm kho: Toàn mạng lưới</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>" <?= $whFilter === (int)$wh['id'] ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 pe-0">
                <a href="expiry.php" class="btn-clean btn-clean-secondary btn-sm w-100 py-1 justify-content-center">
                    <i class="bi bi-arrow-clockwise"></i> Đặt lại
                </a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-clean align-middle mb-0" id="expiryTable">
            <thead>
                <tr>
                    <th class="ps-4">Thứ tự FEFO</th>
                    <th>Lô hàng</th>
                    <th>Sản phẩm</th>
                    <th>Điểm kho</th>
                    <th class="text-center">Ngày sản xuất</th>
                    <th class="text-center">Hạn sử dụng</th>
                    <th class="text-center">Thời gian còn lại</th>
                    <th class="text-center">Tồn kho lô</th>
                    <th class="text-end pe-4">Trạng thái FEFO</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($batches)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-slate-400">
                            <i class="bi bi-check2-circle fs-2 d-block mb-2 text-success"></i>
                            Không có lô hàng nào phù hợp với bộ lọc được chọn.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $rank = 1;
                    foreach ($batches as $b): 
                        $days = (int)$b['days_to_expiry'];
                        $statusBadge = match ($b['shelf_status']) {
                            'EXPIRED' => '<span class="chip chip-danger"><i class="bi bi-x-circle-fill"></i> ĐÃ HẾT HẠN</span>',
                            'CRITICAL' => '<span class="chip chip-danger"><i class="bi bi-exclamation-triangle-fill"></i> ƯU TIÊN SỐ 1</span>',
                            'WARNING' => '<span class="chip chip-warning"><i class="bi bi-hourglass-split"></i> ƯU TIÊN SỐ 2</span>',
                            default => '<span class="chip chip-success"><i class="bi bi-shield-check"></i> TIÊU CHUẨN</span>',
                        };

                        $daysText = match (true) {
                            $days < 0 => '<span class="text-danger fw-bold">Quá hạn ' . abs($days) . ' ngày</span>',
                            $days === 0 => '<span class="text-danger fw-bold">Hết hạn hôm nay!</span>',
                            $days <= 30 => '<span class="text-danger fw-bold">' . $days . ' ngày tới</span>',
                            $days <= 90 => '<span class="text-warning fw-semibold">' . $days . ' ngày</span>',
                            default => '<span class="text-success">' . $days . ' ngày</span>',
                        };
                    ?>
                        <tr>
                            <td class="ps-4">
                                <span class="badge rounded-circle <?= $rank <= 3 ? 'bg-danger text-white' : 'bg-light text-secondary border' ?>" style="width: 26px; height: 26px; line-height: 20px; font-size: 0.75rem;">
                                    <?= $rank++ ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-bold font-monospace text-primary"><?= htmlspecialchars($b['batch_number']) ?></div>
                                <div class="text-muted" style="font-size: 0.7rem;">Batch #<?= $b['batch_id'] ?></div>
                            </td>
                            <td>
                                <div class="fw-bold text-slate-900"><?= htmlspecialchars($b['item_name']) ?></div>
                                <div class="font-monospace text-slate-500 small">[<?= htmlspecialchars($b['item_code']) ?>] • <?= htmlspecialchars($b['category_name']) ?></div>
                            </td>
                            <td>
                                <span class="chip chip-slate"><?= htmlspecialchars($b['warehouse_code']) ?></span>
                            </td>
                            <td class="text-center font-monospace text-slate-600 small">
                                <?= $b['manufacture_date'] ? date('d/m/Y', strtotime($b['manufacture_date'])) : '—' ?>
                            </td>
                            <td class="text-center font-monospace fw-bold <?= $days <= 30 ? 'text-danger' : 'text-slate-800' ?>">
                                <?= date('d/m/Y', strtotime($b['expiry_date'])) ?>
                            </td>
                            <td class="text-center font-monospace small">
                                <?= $daysText ?>
                            </td>
                            <td class="text-center fw-extrabold text-slate-900">
                                <?= number_format((int)$b['batch_stock']) ?> <small class="text-muted fw-normal"><?= $b['unit'] ?></small>
                            </td>
                            <td class="text-end pe-4">
                                <?= $statusBadge ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function filterExpiryTable() {
    const q = (document.getElementById('expirySearchInput')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#expiryTable tbody tr').forEach(r => {
        const text = r.innerText.toLowerCase();
        r.style.display = (q === '' || text.includes(q)) ? '' : 'none';
    });
}

function exportExpiryToCSV() {
    nxExportTableToCSV('#expiryTable', 'bao_cao_han_dung_fefo_' + new Date().toISOString().slice(0, 10) + '.csv');
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
