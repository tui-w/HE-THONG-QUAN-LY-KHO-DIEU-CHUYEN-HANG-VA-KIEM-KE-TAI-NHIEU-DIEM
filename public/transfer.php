<?php
declare(strict_types=1);

$pageTitle = 'Điều chuyển hàng giữa 2 kho';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../src/TransferService.php';
require_once __DIR__ . '/../src/StockService.php';

use App\TransferService;
use App\StockService;

$errorMsg = '';
$successMsg = '';
$simulatedCrashReport = null;

// Xử lý thực hiện điều chuyển
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'do_transfer') {
    try {
        $srcWhId = (int)$_POST['source_warehouse_id'];
        $destWhId = (int)$_POST['dest_warehouse_id'];
        $note = trim((string)($_POST['note'] ?? ''));
        $simulateCrash = !empty($_POST['simulate_crash']);

        $itemIds = $_POST['item_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $batchIds = $_POST['batch_id'] ?? [];

        $lines = [];
        for ($i = 0; $i < count($itemIds); $i++) {
            $qty = (int)($quantities[$i] ?? 0);
            if ($qty > 0 && !empty($itemIds[$i])) {
                $lines[] = [
                    'item_id' => (int)$itemIds[$i],
                    'batch_id' => !empty($batchIds[$i]) ? (int)$batchIds[$i] : null,
                    'quantity' => $qty,
                    'unit_price' => 0
                ];
            }
        }

        if (empty($lines)) {
            throw new \RuntimeException("Vui lòng chọn ít nhất một mặt hàng và số lượng chuyển > 0!");
        }

        // Lấy số tồn trước khi thực hiện để làm báo cáo đối chiếu
        $stockSrcBefore = StockService::getCurrentStock($pdo, $srcWhId, (int)$lines[0]['item_id']);
        $stockDestBefore = StockService::getCurrentStock($pdo, $destWhId, (int)$lines[0]['item_id']);

        $res = TransferService::executeTransfer(
            $pdo,
            $srcWhId,
            $destWhId,
            $lines,
            (int)$currentUser['id'],
            $note,
            $simulateCrash
        );

        $stockSrcAfter = StockService::getCurrentStock($pdo, $srcWhId, (int)$lines[0]['item_id']);
        $stockDestAfter = StockService::getCurrentStock($pdo, $destWhId, (int)$lines[0]['item_id']);

        $_SESSION['flash_success'] = "Điều chuyển thành công chứng từ {$res['code']}! " . count($lines) . " dòng hàng đã được xử lý trong một giao dịch nguyên tử.";
        header('Location: transfer.php');
        exit;
    } catch (\Exception $e) {
        if (!empty($simulateCrash)) {
            // Trường hợp mô phỏng lỗi (Phép thử số 2)
            $stockSrcAfter = StockService::getCurrentStock($pdo, $srcWhId, (int)$lines[0]['item_id']);
            $stockDestAfter = StockService::getCurrentStock($pdo, $destWhId, (int)$lines[0]['item_id']);

            $simulatedCrashReport = [
                'error' => $e->getMessage(),
                'item_id' => (int)$lines[0]['item_id'],
                'src_before' => $stockSrcBefore,
                'src_after' => $stockSrcAfter,
                'dest_before' => $stockDestBefore,
                'dest_after' => $stockDestAfter,
            ];
        } else {
            $errorMsg = "LỖI ĐIỀU CHUYỂN: " . $e->getMessage();
        }
    }
}

// Lấy danh sách phiếu điều chuyển (tối đa 200 để lọc client-side)
$transfers = $pdo->query("
    SELECT 
        v.*,
        wSrc.code AS src_code, wSrc.name AS src_name,
        wDest.code AS dest_code, wDest.name AS dest_name,
        u.full_name AS approver_name,
        COUNT(vl.id) AS lines_count,
        COALESCE(SUM(vl.quantity), 0) AS total_qty
    FROM vouchers v
    JOIN warehouses wSrc ON v.source_warehouse_id = wSrc.id
    JOIN warehouses wDest ON v.dest_warehouse_id = wDest.id
    LEFT JOIN users u ON v.approved_by = u.id
    LEFT JOIN voucher_lines vl ON vl.voucher_id = v.id
    WHERE v.type = 'TRANSFER'
    GROUP BY v.id
    ORDER BY v.id DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

// Danh sách sản phẩm
$items = $pdo->query("SELECT id, code, name, unit FROM items ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);

// Lấy tồn kho theo từng kho cho các sản phẩm
$itemsStockMap = [];
foreach ($items as $it) {
    $itId = (int)$it['id'];
    $itemsStockMap[$itId] = [];
    foreach ($warehouses as $wh) {
        $whId = (int)$wh['id'];
        $itemsStockMap[$itId][$whId] = StockService::getCurrentStock($pdo, $whId, $itId);
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold text-slate-900 mb-0" style="letter-spacing: -0.02em;">Điều chuyển hàng hóa liên kho</h4>
        <span class="chip chip-success"><i class="bi bi-shield-check"></i> ACID Transaction</span>
    </div>
</div>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-3 border-0 py-2 px-3 small rounded-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1.5 text-danger"></i> <?= htmlspecialchars($errorMsg) ?>
        <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($simulatedCrashReport): ?>
    <div class="clean-card mb-3 overflow-hidden border-warning" style="border-color: #fde68a !important; background: #fffbeb;">
        <div class="p-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <div class="chip chip-danger"><i class="bi bi-shield-slash-fill"></i> PHÉP THỬ SỐ 2</div>
                <h6 class="fw-bold text-slate-900 mb-0">Rollback thành công — Bảo toàn 100% tồn kho!</h6>
            </div>
            <div class="clean-card bg-white p-2.5">
                <div class="row g-2 text-center small">
                    <div class="col-6 border-end">
                        <span class="text-slate-500">Kho xuất:</span>
                        <strong class="text-slate-900 ms-1"><?= $simulatedCrashReport['src_after'] ?></strong>
                        <span class="chip chip-success py-0 px-1.5 ms-1">Trước: <?= $simulatedCrashReport['src_before'] ?></span>
                    </div>
                    <div class="col-6">
                        <span class="text-slate-500">Kho nhập:</span>
                        <strong class="text-slate-900 ms-1"><?= $simulatedCrashReport['dest_after'] ?></strong>
                        <span class="chip chip-success py-0 px-1.5 ms-1">Trước: <?= $simulatedCrashReport['dest_before'] ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<style>
.line-item-search {
    font-size: 0.82rem; border-radius: 8px; border: 1.5px solid #e2e8f0;
    padding: 5px 9px 5px 28px; width: 100%; transition: border-color .2s;
    background: #ffffff;
}
.line-item-search:focus { border-color: #6366f1; outline: none; box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
.line-dropdown {
    position: absolute; z-index: 1050; background: #fff;
    border: 1.5px solid #e2e8f0; border-radius: 10px;
    box-shadow: 0 8px 30px rgba(0,0,0,.12);
    max-height: 200px; overflow-y: auto; width: 100%;
    left: 0; top: calc(100% + 4px);
}
.line-dropdown-item {
    padding: 7px 12px; cursor: pointer; font-size: 0.8rem;
    display: flex; flex-direction: column; border-bottom: 1px solid #f1f5f9;
    transition: background .15s;
}
.line-dropdown-item:last-child { border-bottom: none; }
.line-dropdown-item:hover, .line-dropdown-item.highlighted { background: #eef2ff; }
.line-dropdown-item .item-code  { font-weight: 700; color: #4f46e5; font-size: 0.75rem; }
.line-dropdown-item .item-stock { font-size: 0.72rem; color: #64748b; margin-top: 1px; }
.line-qty-input {
    font-size: 0.85rem; border-radius: 8px; border: 1.5px solid #e2e8f0;
    padding: 5px 8px; width: 100%; text-align: right; transition: border-color .2s;
}
.line-qty-input:focus { border-color: #6366f1; outline: none; box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
.line-qty-input.qty-warn  { border-color: #ef4444; background: #fff5f5; }
.line-qty-input.qty-ok    { border-color: #22c55e; }
.line-stock-badge {
    font-size: 0.68rem; padding: 2px 7px; border-radius: 20px; font-weight: 600; white-space: nowrap;
}
.line-stock-badge.ok   { background: #dcfce7; color: #16a34a; }
.line-stock-badge.warn { background: #fee2e2; color: #dc2626; }
.line-stock-badge.none { background: #f1f5f9; color: #94a3b8; }
.btn-add-line {
    border: 1.5px dashed #c7d2fe; background: #ffffff; color: #6366f1;
    border-radius: 8px; padding: 6px 12px; font-size: 0.8rem; font-weight: 600;
    cursor: pointer; transition: all .2s; display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%;
}
.btn-add-line:hover { border-color: #6366f1; background: #eef2ff; }
.btn-remove-line {
    background: transparent; border: none; color: #94a3b8; cursor: pointer;
    padding: 4px 6px; border-radius: 6px; transition: all .15s; line-height: 1;
}
.btn-remove-line:hover { color: #ef4444; background: #fee2e2; }
.lines-summary-bar {
    background: #ffffff;
    border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 10px;
    font-size: 0.76rem; display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.lines-summary-bar .sum-chip {
    background: #eef2ff; border-radius: 20px;
    padding: 1px 8px; font-weight: 700; color: #4f46e5;
}
.selected-item-chip {
    display: inline-flex; align-items: center; gap: 6px;
    background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px;
    padding: 4px 10px; font-size: 0.8rem; font-weight: 600; color: #4338ca;
    position: absolute; inset: 0; z-index: 2; overflow: hidden;
}
.selected-item-chip span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
.selected-item-chip .chip-clear { cursor: pointer; color: #a5b4fc; flex-shrink: 0; font-size: 0.9rem; }
.selected-item-chip .chip-clear:hover { color: #ef4444; }
</style>

<div class="row g-3 mb-4 align-items-start">
    <!-- MAIN CHÍNH (100% chiều ngang bên dưới để không bao giờ bị cắt chữ hay cuộn ngang) -->
    <div class="col-12 order-2">
        <div class="clean-card main-primary-card overflow-hidden d-flex flex-column">
            <div class="clean-card-header">
                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap w-100">
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="fw-bold text-slate-900 mb-0">Nhật ký điều chuyển liên kho</h6>
                        <span class="badge bg-primary-light text-primary" id="historyCount"><?= count($transfers) ?></span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-2 px-2 py-0.5" id="btnClearHistoryFilter" style="display:none; font-size:.75rem;">
                        <i class="bi bi-x-circle me-1"></i>Xóa lọc
                    </button>
                </div>
            </div>

            <!-- Thanh tìm kiếm & lọc nhanh trên 1 hàng -->
            <div class="px-3 py-2 bg-slate-50 border-bottom d-flex flex-wrap gap-2 align-items-center">
                <div class="input-group input-group-sm flex-grow-1" style="min-width:150px; max-width:220px;">
                    <span class="input-group-text bg-white border-end-0 text-slate-400 px-2">
                        <i class="bi bi-search" style="font-size:.75rem;"></i>
                    </span>
                    <input type="text" id="historySearch" class="form-control form-control-sm border-start-0 ps-0"
                        placeholder="Tìm mã chứng từ..." style="font-size:.8rem;">
                </div>
                <select id="historyFilterSrc" class="form-select form-select-sm" style="max-width:135px; font-size:.8rem;">
                    <option value="">Kho xuất: Tất cả</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= htmlspecialchars($wh['code']) ?>">Từ <?= htmlspecialchars($wh['code']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="historyFilterDest" class="form-select form-select-sm" style="max-width:135px; font-size:.8rem;">
                    <option value="">Kho nhập: Tất cả</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= htmlspecialchars($wh['code']) ?>">Đến <?= htmlspecialchars($wh['code']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" id="historyFilterDate" class="form-control form-control-sm"
                    style="max-width:135px; font-size:.8rem;" title="Lọc theo ngày">
            </div>

            <div class="table-responsive flex-grow-1">
                <table class="table-clean align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Mã chứng từ</th>
                            <th>Thời gian</th>
                            <th>Tuyến điều chuyển</th>
                            <th class="text-center">Số lượng</th>
                            <th>Người lập</th>
                            <th class="text-end pe-4">Chi tiết</th>
                        </tr>
                    </thead>
                    <tbody id="historyTbody">
                        <?php if (empty($transfers)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-slate-400">Chưa có chứng từ điều chuyển nào.</td></tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $tr): ?>
                                <tr class="history-row"
                                    data-code="<?= strtolower(htmlspecialchars($tr['code'])) ?>"
                                    data-src="<?= htmlspecialchars($tr['src_code']) ?>"
                                    data-dest="<?= htmlspecialchars($tr['dest_code']) ?>"
                                    data-date="<?= date('Y-m-d', strtotime($tr['created_at'])) ?>">
                                    <td class="ps-4 text-nowrap">
                                        <a href="voucher_detail.php?id=<?= $tr['id'] ?>" class="fw-bold text-decoration-none font-monospace text-primary" style="font-size: 0.85rem;">
                                            <?= htmlspecialchars($tr['code']) ?>
                                        </a>
                                    </td>
                                    <td class="small text-slate-500 text-nowrap"><?= date('d/m/Y H:i', strtotime($tr['created_at'])) ?></td>
                                    <td>
                                        <?php
                                        $whBadge = function (string $code): string {
                                            return match ($code) {
                                                'KHO-HN'  => '<span class="badge rounded-pill px-2 py-1" style="background:rgba(99,102,241,0.14);color:#4f46e5;border:1px solid rgba(99,102,241,0.3);" title="Kho Hà Nội"><i class="bi bi-building-up me-1"></i>HN</span>',
                                                'KHO-DN'  => '<span class="badge rounded-pill px-2 py-1" style="background:rgba(236,72,153,0.14);color:#db2777;border:1px solid rgba(236,72,153,0.3);" title="Kho Đà Nẵng"><i class="bi bi-buildings-fill me-1"></i>ĐN</span>',
                                                'KHO-HCM' => '<span class="badge rounded-pill px-2 py-1" style="background:rgba(6,182,212,0.14);color:#0284c7;border:1px solid rgba(6,182,212,0.3);" title="Kho TP.HCM"><i class="bi bi-building-check me-1"></i>HCM</span>',
                                                default   => '<span class="badge rounded-pill bg-secondary-subtle text-secondary px-2 py-1"><i class="bi bi-building me-1"></i>' . htmlspecialchars($code) . '</span>',
                                            };
                                        };
                                        ?>
                                        <div class="d-inline-flex align-items-center gap-1.5" title="<?= htmlspecialchars($tr['src_code'] . ' → ' . $tr['dest_code']) ?>">
                                            <?= $whBadge((string)$tr['src_code']) ?>
                                            <span class="text-primary d-inline-flex align-items-center" style="font-size:0.82rem;"><i class="bi bi-truck"></i><i class="bi bi-chevron-right" style="font-size:0.65rem;"></i></span>
                                            <?= $whBadge((string)$tr['dest_code']) ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-bold text-slate-900"><?= number_format((int)$tr['total_qty']) ?></span>
                                        <div style="font-size:.68rem; color:#94a3b8;"><?= $tr['lines_count'] ?> dòng</div>
                                    </td>
                                    <td class="small text-slate-600"><?= htmlspecialchars((string)($tr['approver_name'] ?? 'Hệ thống')) ?></td>
                                    <td class="text-end pe-4">
                                        <a href="voucher_detail.php?id=<?= $tr['id'] ?>" class="btn btn-sm btn-white border px-2 py-1 text-slate-700 rounded-2" title="Xem chi tiết">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <!-- Hàng không có kết quả -->
                            <tr id="historyNoResult" style="display:none;">
                                <td colspan="6" class="text-center py-4 text-slate-400">
                                    <i class="bi bi-search me-1"></i>Không tìm thấy chứng từ nào khớp bộ lọc.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Form Lập lệnh điều chuyển (Rộng thoáng 100% ở phía trên, không bị bó hẹp) -->
    <div class="col-12 order-1">
        <div class="clean-card main-secondary-card">
            <div class="clean-card-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-1.5">
                    <i class="bi bi-send-plus text-primary"></i>
                    <span class="fw-bold text-slate-800 small">Lập lệnh điều chuyển</span>
                </div>
                </div>

            <div class="clean-card-body">
                <form method="POST" action="transfer.php" id="transferForm">
                    <input type="hidden" name="action" value="do_transfer">

                    <div class="row g-2 mb-2.5">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-slate-700 mb-1">Kho xuất <span class="text-danger">*</span></label>
                            <select name="source_warehouse_id" class="form-select form-select-sm" id="srcWhSelect" required>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>">[<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-slate-700 mb-1">Kho nhập <span class="text-danger">*</span></label>
                            <select name="dest_warehouse_id" class="form-select form-select-sm" id="destWhSelect" required>
                                <?php foreach ($warehouses as $idx => $wh): ?>
                                    <option value="<?= $wh['id'] ?>" <?= $idx === 1 ? 'selected' : '' ?>>
                                        [<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Bảng danh sách hàng điều chuyển (multi-line) -->
                    <div class="mb-2.5">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <label class="form-label small fw-semibold text-slate-700 mb-0">Mặt hàng <span class="text-danger">*</span></label>
                            <span class="badge bg-white border text-slate-600" id="lineCountBadge">0 dòng</span>
                        </div>

                        <div class="bg-white border" style="border-radius:8px;">
                            <div style="background:#f8fafc; padding:6px 8px 4px; border-bottom:1px solid #e2e8f0; border-radius:8px 8px 0 0;">
                                <div class="row g-1" style="font-size:.66rem; font-weight:700; text-transform:uppercase; color:#64748b;">
                                    <div class="col-auto" style="width:22px;">#</div>
                                    <div class="col">Mặt hàng</div>
                                    <div class="col-3 text-end">SL</div>
                                    <div class="col-2 text-center">Tồn</div>
                                    <div class="col-auto" style="width:24px;"></div>
                                </div>
                            </div>
                            <div id="linesContainer" style="overflow:visible; padding:8px;"></div>
                            <div style="padding:5px 6px; border-top:1px solid #e2e8f0; background:#f8fafc; border-radius:0 0 8px 8px;">
                                <button type="button" class="btn-add-line" id="btnAddLine">
                                    <i class="bi bi-plus-circle-fill"></i> Thêm dòng
                                </button>
                            </div>
                        </div>

                        <div class="lines-summary-bar mt-1.5">
                            <span class="text-slate-500">Tổng:</span>
                            <div class="d-flex gap-1.5">
                                <span class="sum-chip" id="sumTotalLines">0 dòng</span>
                                <span class="sum-chip" id="sumTotalQty">0 SP</span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-2.5">
                        <input type="text" name="note" class="form-control form-control-sm" placeholder="Ghi chú / Lý do điều chuyển...">
                    </div>

                    <!-- Toggle kiểm thử Rollback gọn 1 dòng -->
                    <div class="px-2.5 py-1.5 bg-white rounded-2 border mb-2.5">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="simulate_crash" value="1" id="crashCheck">
                            <label class="form-check-label fw-semibold text-danger" for="crashCheck" style="font-size: 0.76rem;">
                                <i class="bi bi-bug-fill me-1"></i>Mô phỏng lỗi Rollback (Test ACID #2)
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn-clean btn-clean-primary w-100 py-2 rounded-3 fw-bold" id="submitBtn">
                        <i class="bi bi-check2-circle"></i>
                        <span>Thực hiện điều chuyển</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    /* ─── Dữ liệu từ PHP ─── */
    const stockMap   = <?= json_encode($itemsStockMap, JSON_UNESCAPED_UNICODE) ?>;
    const warehouses = <?= json_encode(array_map(fn($w) => ['id' => (int)$w['id'], 'code' => $w['code'], 'name' => $w['name']], $warehouses), JSON_UNESCAPED_UNICODE) ?>;
    const itemsData  = <?= json_encode(array_values($items), JSON_UNESCAPED_UNICODE) ?>;

    const srcWhSelect    = document.getElementById('srcWhSelect');
    const destWhSelect   = document.getElementById('destWhSelect');
    const linesContainer = document.getElementById('linesContainer');
    const btnAddLine     = document.getElementById('btnAddLine');
    const lineCountBadge = document.getElementById('lineCountBadge');
    const sumTotalLines  = document.getElementById('sumTotalLines');
    const sumTotalQty    = document.getElementById('sumTotalQty');
    const submitBtn      = document.getElementById('submitBtn');

    let lineIndex = 0;

    function getSrcWhId()  { return parseInt(srcWhSelect.value, 10); }
    function getDestWhId() { return parseInt(destWhSelect.value, 10); }
    function getStockAtSrc(itemId) { const s = stockMap[itemId]; return s ? (s[getSrcWhId()] ?? 0) : 0; }
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function refreshSummary() {
        const rows = linesContainer.querySelectorAll('.transfer-line-row');
        let totalQty = 0, validLines = 0;
        rows.forEach(row => {
            const itemId = parseInt(row.dataset.itemId || '0', 10);
            const idx    = parseInt(row.dataset.idx, 10);
            const qty    = parseInt(document.getElementById('lineQty_' + idx)?.value || '0', 10);
            if (itemId > 0 && qty > 0) { totalQty += qty; validLines++; }
        });
        lineCountBadge.textContent = rows.length + ' dòng';
        sumTotalLines.textContent  = validLines + ' dòng hàng';
        sumTotalQty.textContent    = totalQty.toLocaleString('vi-VN') + ' sản phẩm';
        submitBtn.querySelector('span').textContent =
            validLines > 0 ? `Thực hiện điều chuyển (${validLines} mặt hàng)` : 'Thực hiện điều chuyển';
    }

    function reindexLineNumbers() {
        linesContainer.querySelectorAll('.transfer-line-row').forEach((r, i) => {
            const el = r.querySelector('[id^="lineNum_"]');
            if (el) el.textContent = (i + 1) + '.';
        });
    }

    function getUsedItemIds(excludeIdx) {
        const used = new Set();
        linesContainer.querySelectorAll('.transfer-line-row').forEach(r => {
            if (parseInt(r.dataset.idx, 10) !== excludeIdx) {
                const id = parseInt(r.dataset.itemId || '0', 10);
                if (id > 0) used.add(id);
            }
        });
        return used;
    }

    function createLine() {
        const idx = lineIndex++;
        const row = document.createElement('div');
        row.className = 'transfer-line-row row g-1 align-items-center mb-1';
        row.dataset.itemId = ''; row.dataset.idx = idx;
        row.innerHTML = `
            <div class="col-auto" style="width:26px;">
                <span style="font-size:.72rem; color:#94a3b8;" id="lineNum_${idx}"></span>
                <input type="hidden" name="item_id[]" value="">
                <input type="hidden" name="batch_id[]" value="">
            </div>
            <div class="col" style="position:relative;">
                <div style="position:relative;">
                    <i class="bi bi-search" style="position:absolute; left:8px; top:50%; transform:translateY(-50%); font-size:.72rem; color:#94a3b8; pointer-events:none;"></i>
                    <input type="text" class="line-item-search" id="lineSearch_${idx}" placeholder="Bấm để xem danh sách hoặc gõ tên/mã mặt hàng..." autocomplete="off" style="padding-right:28px;">
                    <button type="button" id="lineToggleBtn_${idx}" tabindex="-1" style="position:absolute; right:4px; top:50%; transform:translateY(-50%); border:none; background:transparent; color:#64748b; padding:2px 5px; cursor:pointer; line-height:1;" title="Mở/Đóng danh sách"><i class="bi bi-chevron-down" style="font-size:.75rem;"></i></button>
                    <div class="selected-item-chip" id="lineChip_${idx}" style="display:none; cursor:pointer;" title="Bấm để đổi mặt hàng khác">
                        <i class="bi bi-box-seam" style="font-size:.75rem; flex-shrink:0;"></i>
                        <span id="lineChipText_${idx}"></span>
                        <i class="bi bi-x chip-clear" id="lineChipClear_${idx}" title="Chọn lại"></i>
                    </div>
                </div>
                <div class="line-dropdown" id="lineDropdown_${idx}" style="display:none;"></div>
            </div>
            <div class="col-3">
                <input type="number" name="quantity[]" class="line-qty-input" id="lineQty_${idx}" min="1" value="" placeholder="SL" disabled>
            </div>
            <div class="col-2 text-center">
                <span class="line-stock-badge none" id="lineStockBadge_${idx}">—</span>
            </div>
            <div class="col-auto text-center" style="width:28px;">
                <button type="button" class="btn-remove-line" id="lineRemove_${idx}" title="Xóa dòng"><i class="bi bi-trash3"></i></button>
            </div>`;
        linesContainer.appendChild(row);
        reindexLineNumbers();
        bindLineEvents(row, idx);
        refreshSummary();
        linesContainer.scrollTop = linesContainer.scrollHeight;
        document.getElementById('lineSearch_' + idx)?.focus();
        return row;
    }

    function bindLineEvents(row, idx) {
        const searchInput = document.getElementById('lineSearch_' + idx);
        const dropdown    = document.getElementById('lineDropdown_' + idx);
        const chip        = document.getElementById('lineChip_' + idx);
        const chipText    = document.getElementById('lineChipText_' + idx);
        const chipClear   = document.getElementById('lineChipClear_' + idx);
        const qtyInput    = document.getElementById('lineQty_' + idx);
        const stockBadge  = document.getElementById('lineStockBadge_' + idx);
        const removeBtn   = document.getElementById('lineRemove_' + idx);
        const hiddenItemId = row.querySelector('input[name="item_id[]"]');

        let highlightedIdx = -1, filteredItems = [];

        const normVi = s => (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd');

        function openDropdown(query) {
            const q = normVi(query).trim();
            const usedIds = getUsedItemIds(idx);
            filteredItems = itemsData.filter(it =>
                !usedIds.has(it.id) &&
                (q === '' || normVi(it.code + ' ' + it.name).includes(q))
            );
            dropdown.innerHTML = '';
            if (filteredItems.length === 0) {
                dropdown.innerHTML = '<div class="line-dropdown-item" style="color:#94a3b8;">Không tìm thấy sản phẩm</div>';
            } else {
                filteredItems.forEach((it, i) => {
                    const srcStock = getStockAtSrc(it.id);
                    const div = document.createElement('div');
                    div.className = 'line-dropdown-item'; div.dataset.i = i;
                    const sc = srcStock > 0 ? '#16a34a' : '#dc2626';
                    div.innerHTML = `<div><span class="item-code">[${escHtml(it.code)}]</span> ${escHtml(it.name)}
                        <span style="background:#f1f5f9;color:#64748b;font-size:.65rem;border-radius:4px;padding:1px 5px;margin-left:4px;">${escHtml(it.unit)}</span></div>
                        <div class="item-stock">Tồn kho xuất: <strong style="color:${sc}">${srcStock.toLocaleString('vi-VN')}</strong></div>`;
                    div.addEventListener('mousedown', e => { e.preventDefault(); e.stopPropagation(); selectItem(it); });
                    div.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); selectItem(it); });
                    dropdown.appendChild(div);
                });
            }
            dropdown.style.display = 'block'; highlightedIdx = -1;
        }

        function closeDropdown() { dropdown.style.display = 'none'; highlightedIdx = -1; }

        function selectItem(it) {
            hiddenItemId.value = it.id; row.dataset.itemId = it.id;
            chipText.textContent = `[${it.code}] ${it.name}`;
            chip.style.display = 'flex'; searchInput.style.visibility = 'hidden';
            closeDropdown();
            qtyInput.disabled = false; qtyInput.value = ''; qtyInput.focus();
            updateStockBadge(); refreshSummary();
        }

        function updateStockBadge() {
            const itemId = parseInt(hiddenItemId.value, 10);
            if (!itemId) { stockBadge.textContent = '—'; stockBadge.className = 'line-stock-badge none'; return; }
            const srcStock = getStockAtSrc(itemId);
            const qty = parseInt(qtyInput.value, 10) || 0;
            stockBadge.textContent = srcStock.toLocaleString('vi-VN');
            if (srcStock <= 0 || (qty > 0 && qty > srcStock)) {
                stockBadge.className = 'line-stock-badge warn';
                qtyInput.className   = 'line-qty-input qty-warn';
            } else {
                stockBadge.className = 'line-stock-badge ok';
                qtyInput.className   = qty > 0 ? 'line-qty-input qty-ok' : 'line-qty-input';
            }
        }

        const toggleBtn = document.getElementById('lineToggleBtn_' + idx);
        if (toggleBtn) {
            toggleBtn.addEventListener('click', e => {
                e.preventDefault(); e.stopPropagation();
                if (dropdown.style.display === 'block') closeDropdown();
                else { searchInput.focus(); openDropdown(searchInput.value.trim()); }
            });
        }
        chip.addEventListener('click', () => chipClear.click());
        chipClear.addEventListener('click', e => {
            e.stopPropagation();
            hiddenItemId.value = ''; row.dataset.itemId = '';
            chip.style.display = 'none'; searchInput.style.visibility = 'visible'; searchInput.value = '';
            qtyInput.disabled = true; qtyInput.value = ''; qtyInput.className = 'line-qty-input';
            stockBadge.textContent = '—'; stockBadge.className = 'line-stock-badge none';
            closeDropdown(); searchInput.focus(); refreshSummary();
        });

        searchInput.addEventListener('input',    function() { openDropdown(this.value.trim()); });
        searchInput.addEventListener('focus',    function() { openDropdown(this.value.trim()); });
        searchInput.addEventListener('click',    function() { openDropdown(this.value.trim()); });
        searchInput.addEventListener('dblclick', function() { openDropdown(''); });
        document.addEventListener('mousedown', e => {
            if (!row.contains(e.target)) closeDropdown();
        });
        searchInput.addEventListener('keydown', function(e) {
            const items = dropdown.querySelectorAll('.line-dropdown-item[data-i]');
            if (e.key === 'ArrowDown') { e.preventDefault(); highlightedIdx = Math.min(highlightedIdx + 1, items.length - 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); highlightedIdx = Math.max(highlightedIdx - 1, 0); }
            else if (e.key === 'Enter' && highlightedIdx >= 0) { e.preventDefault(); if (filteredItems[highlightedIdx]) selectItem(filteredItems[highlightedIdx]); return; }
            else if (e.key === 'Escape') { closeDropdown(); return; }
            items.forEach((el, i) => el.classList.toggle('highlighted', i === highlightedIdx));
        });

        qtyInput.addEventListener('input', () => { updateStockBadge(); refreshSummary(); });

        removeBtn.addEventListener('click', () => {
            const rows = linesContainer.querySelectorAll('.transfer-line-row');
            if (rows.length <= 1) { chipClear.click(); return; }
            row.remove(); reindexLineNumbers(); refreshSummary();
        });
    }

    function onWarehouseChange() {
        linesContainer.querySelectorAll('.transfer-line-row').forEach(row => {
            const idx       = parseInt(row.dataset.idx, 10);
            const stockBadge = document.getElementById('lineStockBadge_' + idx);
            const qtyInput   = document.getElementById('lineQty_' + idx);
            const itemId     = parseInt(row.dataset.itemId || '0', 10);
            if (!itemId || !stockBadge) return;
            const srcStock = getStockAtSrc(itemId);
            const qty = parseInt(qtyInput?.value || '0', 10);
            stockBadge.textContent = srcStock.toLocaleString('vi-VN');
            if (srcStock <= 0 || (qty > 0 && qty > srcStock)) {
                stockBadge.className = 'line-stock-badge warn';
                if (qtyInput) qtyInput.className = 'line-qty-input qty-warn';
            } else {
                stockBadge.className = 'line-stock-badge ok';
                if (qtyInput) qtyInput.className = qty > 0 ? 'line-qty-input qty-ok' : 'line-qty-input';
            }
        });
    }

    srcWhSelect.addEventListener('change', onWarehouseChange);
    destWhSelect.addEventListener('change', onWarehouseChange);
    btnAddLine.addEventListener('click', () => createLine());

    document.getElementById('transferForm').addEventListener('submit', function(e) {
        let valid = 0;
        linesContainer.querySelectorAll('.transfer-line-row').forEach(row => {
            const itemId = parseInt(row.dataset.itemId || '0', 10);
            const idx    = parseInt(row.dataset.idx, 10);
            const qty    = parseInt(document.getElementById('lineQty_' + idx)?.value || '0', 10);
            if (itemId > 0 && qty > 0) valid++;
        });
        if (valid === 0) { e.preventDefault(); alert('Vui lòng thêm ít nhất một mặt hàng với số lượng > 0!'); }
    });

    /* Khởi tạo: thêm 1 dòng mặc định */
    createLine();

    /* ─── Lọc lịch sử điều chuyển ─── */
    (function() {
        const searchEl     = document.getElementById('historySearch');
        const filterSrc    = document.getElementById('historyFilterSrc');
        const filterDest   = document.getElementById('historyFilterDest');
        const filterDate   = document.getElementById('historyFilterDate');
        const clearBtn     = document.getElementById('btnClearHistoryFilter');
        const countBadge   = document.getElementById('historyCount');
        const noResult     = document.getElementById('historyNoResult');

        if (!searchEl) return; // không có dữ liệu lịch sử

        const allRows = Array.from(document.querySelectorAll('#historyTbody .history-row'));
        const total   = allRows.length;

        function applyFilter() {
            const q    = (searchEl.value || '').trim().toLowerCase();
            const src  = filterSrc.value;
            const dest = filterDest.value;
            const date = filterDate.value; // 'YYYY-MM-DD' hoặc ''

            let visible = 0;
            allRows.forEach(row => {
                const matchCode = !q    || row.dataset.code.includes(q);
                const matchSrc  = !src  || row.dataset.src  === src;
                const matchDest = !dest || row.dataset.dest === dest;
                const matchDate = !date || row.dataset.date === date;
                const show = matchCode && matchSrc && matchDest && matchDate;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            // Cập nhật badge đếm
            countBadge.textContent = visible === total ? total : `${visible} / ${total}`;

            // Hiện/ẩn hàng không có kết quả
            if (noResult) noResult.style.display = visible === 0 ? '' : 'none';

            // Hiện/ẩn nút xóa bộ lọc
            const hasFilter = q || src || dest || date;
            clearBtn.style.display = hasFilter ? 'inline-flex' : 'none';
        }

        function clearFilter() {
            searchEl.value  = '';
            filterSrc.value  = '';
            filterDest.value = '';
            filterDate.value = '';
            applyFilter();
            searchEl.focus();
        }

        searchEl.addEventListener('input',    applyFilter);
        filterSrc.addEventListener('change',  applyFilter);
        filterDest.addEventListener('change', applyFilter);
        filterDate.addEventListener('change', applyFilter);
        clearBtn.addEventListener('click',    clearFilter);
    })();
})();
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
