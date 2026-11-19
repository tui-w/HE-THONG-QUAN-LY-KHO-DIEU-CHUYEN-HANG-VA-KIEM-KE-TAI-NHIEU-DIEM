<?php
declare(strict_types=1);

$pageTitle = 'Chi tiết đợt kiểm kê';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../src/InventoryService.php';

use App\InventoryService;

$countId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmtC = $pdo->prepare("
    SELECT 
        sc.*,
        w.name AS warehouse_name,
        w.code AS warehouse_code,
        uCreate.full_name AS creator_name,
        uComplete.full_name AS completer_name
    FROM stock_counts sc
    JOIN warehouses w ON sc.warehouse_id = w.id
    LEFT JOIN users uCreate ON sc.created_by = uCreate.id
    LEFT JOIN users uComplete ON sc.completed_by = uComplete.id
    WHERE sc.id = ?
");
$stmtC->execute([$countId]);
$stockCount = $stmtC->fetch(PDO::FETCH_ASSOC);

if (!$stockCount) {
    $_SESSION['flash_error'] = "Không tìm thấy đợt kiểm kê!";
    header('Location: inventory.php');
    exit;
}

// Xử lý xác nhận điều chỉnh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_adjustments') {
    try {
        $adj = InventoryService::confirmAndApplyAdjustments($pdo, $countId, (int)$currentUser['id']);
        $_SESSION['flash_success'] = "Đã xác nhận chênh lệch và tự động sinh " . count($adj['adjustment_vouchers']) . " phiếu điều chỉnh kho!";
        header("Location: inventory_detail.php?id={$countId}");
        exit;
    } catch (\Exception $e) {
        $_SESSION['flash_error'] = "LỖI: " . $e->getMessage();
    }
}

// Lấy danh sách các dòng kiểm kê
$stmtLines = $pdo->prepare("
    SELECT 
        scl.*,
        i.code AS item_code,
        i.name AS item_name,
        i.unit,
        b.batch_number,
        v.code AS adjustment_voucher_code
    FROM stock_count_lines scl
    JOIN items i ON scl.item_id = i.id
    LEFT JOIN batches b ON scl.batch_id = b.id
    LEFT JOIN vouchers v ON scl.adjustment_voucher_id = v.id
    WHERE scl.stock_count_id = ?
    ORDER BY scl.id ASC
");
$stmtLines->execute([$countId]);
$countLines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

// Thống kê sơ bộ
$discrepancyCount = 0;
$excessCount = 0;
$shortageCount = 0;
foreach ($countLines as $cl) {
    $diff = (int)$cl['difference'];
    if ($diff > 0) {
        $discrepancyCount++;
        $excessCount++;
    } elseif ($diff < 0) {
        $discrepancyCount++;
        $shortageCount++;
    }
}

$statusChip = $stockCount['status'] === 'ADJUSTED' ? 'chip-success' : 'chip-warning';
$statusText = $stockCount['status'] === 'ADJUSTED' ? 'Đã điều chỉnh cân đối kho' : 'Đang đối chiếu / Chờ cân đối';
?>

<!-- Header Top Banner -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1.5">
            <a href="inventory.php" class="text-secondary text-decoration-none small">
                <i class="bi bi-arrow-left"></i> Danh sách kiểm kê
            </a>
            <span class="text-muted small">/</span>
            <span class="chip <?= $statusChip ?>"><?= $statusText ?></span>
        </div>
        <h4 class="fw-bold text-dark mb-0">
            Đợt kiểm kê: <span class="font-monospace text-primary"><?= htmlspecialchars($stockCount['code']) ?></span>
            <span class="text-secondary fw-normal fs-6 ms-2">&mdash; <?= htmlspecialchars($stockCount['title']) ?></span>
        </h4>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <button type="button" class="btn-clean btn-clean-secondary" onclick="window.print()" title="In biên bản đối chiếu kiểm kê thực tế">
            <i class="bi bi-printer"></i> In biên bản
        </button>
        <?php if ($stockCount['status'] !== 'ADJUSTED'): ?>
            <form method="POST" action="inventory_detail.php?id=<?= $countId ?>" onsubmit="return confirm('Hệ thống sẽ tự động sinh các chứng từ ADJUST_IN / ADJUST_OUT để cân bằng số tồn trên hệ thống khớp với số thực đếm. Bạn có chắc chắn muốn thực hiện?');">
                <input type="hidden" name="action" value="confirm_adjustments">
                <button type="submit" class="btn-clean btn-clean-primary">
                    <i class="bi bi-magic"></i> Xác nhận & Tự động sinh phiếu điều chỉnh
                </button>
            </form>
        <?php else: ?>
            <span class="chip chip-success px-3 py-1.5">
                <i class="bi bi-check2-all me-1"></i> Đã hoàn tất điều chỉnh & ghi sổ cái
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- 4 KPI Metric Boxes -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="metric-box">
            <div>
                <div class="metric-label">MẶT HÀNG KIỂM ĐẾM</div>
                <div class="metric-val"><?= count($countLines) ?></div>
            </div>
            <div class="metric-sub">
                <span class="text-secondary">Điểm kho: <?= htmlspecialchars($stockCount['warehouse_code']) ?></span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="metric-box">
            <div>
                <div class="metric-label">TỔNG MẶT HÀNG BỊ LỆCH</div>
                <div class="metric-val <?= $discrepancyCount > 0 ? 'text-danger' : 'text-success' ?>"><?= $discrepancyCount ?></div>
            </div>
            <div class="metric-sub">
                <span class="<?= $discrepancyCount > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= $discrepancyCount > 0 ? 'Cần giải trình & xử lý' : 'Khớp 100% không lệch' ?>
                </span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="metric-box">
            <div>
                <div class="metric-label">HÀNG THỪA THỰC ĐẾM</div>
                <div class="metric-val text-success"><?= $excessCount ?></div>
            </div>
            <div class="metric-sub">
                <span class="text-success">Thực tế > Sổ sách (+)</span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="metric-box">
            <div>
                <div class="metric-label">HÀNG THIẾU THỰC ĐẾM</div>
                <div class="metric-val text-danger"><?= $shortageCount ?></div>
            </div>
            <div class="metric-sub">
                <span class="text-danger">Thực tế < Sổ sách (-)</span>
            </div>
        </div>
    </div>
</div>

<!-- MAIN PHỤ: Thanh thông tin chung gọn gàng 1 dòng -->
<div class="clean-card main-secondary-card mb-3">
    <div class="clean-card-body py-2.5 px-3">
        <div class="row g-2 align-items-center small">
            <div class="col-md-3">
                <span class="text-secondary">Điểm kho:</span>
                <strong class="text-dark ms-1"><?= htmlspecialchars($stockCount['warehouse_name']) ?></strong>
                <span class="chip chip-neutral py-0 px-1.5 ms-1"><?= htmlspecialchars($stockCount['warehouse_code']) ?></span>
            </div>
            <div class="col-md-2">
                <span class="text-secondary">Ngày kiểm:</span>
                <strong class="text-dark ms-1"><?= date('d/m/Y', strtotime($stockCount['count_date'])) ?></strong>
            </div>
            <div class="col-md-3">
                <span class="text-secondary">Kiểm đếm:</span>
                <strong class="text-dark ms-1"><?= htmlspecialchars((string)$stockCount['creator_name']) ?></strong>
            </div>
            <div class="col-md-4">
                <span class="text-secondary">Phê duyệt:</span>
                <?php if ($stockCount['completer_name']): ?>
                    <strong class="text-success ms-1"><i class="bi bi-check-circle me-0.5"></i><?= htmlspecialchars((string)$stockCount['completer_name']) ?></strong>
                <?php else: ?>
                    <strong class="text-warning ms-1"><i class="bi bi-clock me-0.5"></i>Đang chờ chốt</strong>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- MAIN CHÍNH: Bảng đối chiếu chênh lệch giữa Sổ sách và Thực tế -->
<div class="clean-card main-primary-card mb-4 overflow-hidden">
    <div class="clean-card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div class="d-flex flex-wrap gap-1.5 align-items-center">
            <button type="button" class="badge rounded-pill border px-3 py-1.5 line-filter-btn active bg-primary text-white" onclick="setLineFilter('all', this)">Tất cả (<?= count($countLines) ?>)</button>
            <button type="button" class="badge rounded-pill border px-3 py-1.5 line-filter-btn bg-white text-slate-700" onclick="setLineFilter('diff', this)">Có chênh lệch (<?= $discrepancyCount ?>)</button>
            <button type="button" class="badge rounded-pill border px-3 py-1.5 line-filter-btn bg-white text-slate-700" onclick="setLineFilter('excess', this)">Thừa (+) (<?= $excessCount ?>)</button>
            <button type="button" class="badge rounded-pill border px-3 py-1.5 line-filter-btn bg-white text-slate-700" onclick="setLineFilter('shortage', this)">Thiếu (-) (<?= $shortageCount ?>)</button>
            <button type="button" class="badge rounded-pill border px-3 py-1.5 line-filter-btn bg-white text-slate-700" onclick="setLineFilter('match', this)">Khớp tồn (<?= count($countLines) - $discrepancyCount ?>)</button>
        </div>
        <div style="min-width: 240px;">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white border-end-0 text-slate-400"><i class="bi bi-search"></i></span>
                <input type="text" id="lineSearchInput" class="form-control border-start-0 ps-0" placeholder="Tìm theo mã hàng, tên, số lô..." onkeyup="filterCountLines()">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table-clean" id="countLinesTable">
            <thead>
                <tr>
                    <th>STT</th>
                    <th>Mã hàng</th>
                    <th>Tên mặt hàng</th>
                    <th>Lô hàng</th>
                    <th>ĐVT</th>
                    <th class="text-center">Tồn sổ sách</th>
                    <th class="text-center">Thực đếm</th>
                    <th class="text-center">Chênh lệch</th>
                    <th>Lý do giải trình</th>
                    <th>Chứng từ điều chỉnh</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $stt = 1;
                foreach ($countLines as $cl): 
                    $diff = (int)$cl['difference'];
                    $diffType = $diff > 0 ? 'excess' : ($diff < 0 ? 'shortage' : 'match');
                ?>
                    <tr data-diff-type="<?= $diffType ?>" data-has-diff="<?= $diff !== 0 ? 'yes' : 'no' ?>">
                        <td class="text-secondary small"><?= $stt++ ?></td>
                        <td><span class="text-secondary font-monospace small"><?= htmlspecialchars($cl['item_code']) ?></span></td>
                        <td><div class="fw-semibold text-dark"><?= htmlspecialchars($cl['item_name']) ?></div></td>
                        <td>
                            <?= $cl['batch_number'] ? "<span class='chip chip-indigo font-monospace'>{$cl['batch_number']}</span>" : '<span class="text-muted small">-</span>' ?>
                        </td>
                        <td class="text-secondary small"><?= htmlspecialchars($cl['unit']) ?></td>
                        <td class="text-center fw-semibold text-secondary"><?= number_format((float)$cl['system_quantity']) ?></td>
                        <td class="text-center fw-bold text-primary"><?= number_format((float)$cl['actual_quantity']) ?></td>
                        <td class="text-center">
                            <?php if ($diff > 0): ?>
                                <span class="chip chip-success">+<?= number_format((float)$diff) ?> (Thừa)</span>
                            <?php elseif ($diff < 0): ?>
                                <span class="chip chip-danger"><?= number_format((float)$diff) ?> (Thiếu)</span>
                            <?php else: ?>
                                <span class="chip chip-neutral">0 (Khớp)</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-secondary">
                            <?= htmlspecialchars((string)$cl['adjustment_reason']) ?: '<span class="text-muted">-</span>' ?>
                        </td>
                        <td>
                            <?php if ($cl['adjustment_voucher_code']): ?>
                                <a href="voucher_detail.php?code=<?= urlencode($cl['adjustment_voucher_code']) ?>" class="chip chip-primary text-decoration-none">
                                    <i class="bi bi-receipt me-1"></i><?= htmlspecialchars($cl['adjustment_voucher_code']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">Chưa lập</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
let currentLineFilter = 'all';

function setLineFilter(type, btn) {
    currentLineFilter = type;
    document.querySelectorAll('.line-filter-btn').forEach(b => {
        b.classList.remove('bg-primary', 'text-white', 'active');
        b.classList.add('bg-white', 'text-slate-700');
    });
    btn.classList.add('bg-primary', 'text-white', 'active');
    btn.classList.remove('bg-white', 'text-slate-700');
    filterCountLines();
}

function filterCountLines() {
    const q = (document.getElementById('lineSearchInput')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#countLinesTable tbody tr');

    rows.forEach(row => {
        const diffType = row.getAttribute('data-diff-type');
        const hasDiff = row.getAttribute('data-has-diff');
        const text = row.textContent.toLowerCase();

        let matchFilter = true;
        if (currentLineFilter === 'diff') {
            matchFilter = (hasDiff === 'yes');
        } else if (currentLineFilter === 'excess') {
            matchFilter = (diffType === 'excess');
        } else if (currentLineFilter === 'shortage') {
            matchFilter = (diffType === 'shortage');
        } else if (currentLineFilter === 'match') {
            matchFilter = (diffType === 'match');
        }

        const matchSearch = q === '' || text.includes(q);
        row.style.display = (matchFilter && matchSearch) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
