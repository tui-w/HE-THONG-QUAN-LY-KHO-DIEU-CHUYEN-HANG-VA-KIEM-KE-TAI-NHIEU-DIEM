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

$warehouses = Auth::getUserWarehouses($pdo, $userId);
$items = $pdo->query("SELECT id, code, name, unit FROM items ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$errorMsg = '';
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? 'IN';
    $whId = (int)($_POST['warehouse_id'] ?? 0);
    $itemId = (int)($_POST['item_id'] ?? 0);
    $batchId = !empty($_POST['batch_id']) ? (int)$_POST['batch_id'] : null;
    $qty = (int)($_POST['quantity'] ?? 0);
    $price = (float)($_POST['unit_price'] ?? 0);
    $note = trim((string)($_POST['note'] ?? 'Lập chứng từ nhanh'));
    $autoConfirm = !empty($_POST['auto_confirm']);

    try {
        if ($whId <= 0 || $itemId <= 0 || $qty <= 0) {
            throw new RuntimeException('Vui lòng chọn đầy đủ Kho, Mặt hàng và Số lượng > 0.');
        }
        $lines = [[
            'item_id' => $itemId,
            'batch_id' => $batchId,
            'quantity' => $qty,
            'unit_price' => $price,
            'note' => $note,
        ]];
        $srcWh = $type === 'OUT' ? $whId : null;
        $dstWh = $type === 'IN' ? $whId : null;
        $vId = StockService::createVoucher($pdo, $type, $srcWh, $dstWh, $lines, $userId, $note);
        if ($autoConfirm && Auth::checkWarehousePermission($pdo, $userId, $whId, 'approve')) {
            StockService::confirmVoucher($pdo, $vId, $userId);
            $successMsg = "Đã lập và ghi sổ thành công chứng từ #{$vId}!";
        } else {
            $successMsg = "Đã tạo chứng từ nháp #{$vId} thành công!";
        }
    } catch (\Throwable $e) {
        $errorMsg = $e->getMessage();
    }
}

$pageTitle = 'Lập Chứng Từ Nhanh - Logistix WMS';
$activePage = 'quick_voucher';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold mb-0">Lập Chứng Từ Nhanh (Nhập / Xuất)</h4>
        <span class="badge bg-primary rounded-pill">Giao diện chuyên biệt</span>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="bi bi-arrow-left me-1"></i>Về Tổng quan</a>
        <a href="vouchers.php" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="bi bi-list-ul me-1"></i>Xem danh sách phiếu</a>
    </div>
</div>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger border-0 shadow-xs mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>
<?php if ($successMsg): ?>
    <div class="alert alert-success border-0 shadow-xs mb-3"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="clean-card main-primary-card">
            <div class="clean-card-header">
                <span class="fw-bold small"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Biểu mẫu Nhập / Xuất Tốc hành</span>
            </div>
            <div class="clean-card-body p-4">
                <form method="POST" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Loại chứng từ</label>
                        <select name="type" class="form-select" id="qvType">
                            <option value="IN">Phiếu Nhập kho (IN)</option>
                            <option value="OUT">Phiếu Xuất kho (OUT)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Điểm kho thao tác</label>
                        <select name="warehouse_id" class="form-select" id="qvWh" onchange="fetchQvStock()">
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= $w['id'] ?>">[<?= htmlspecialchars($w['code']) ?>] <?= htmlspecialchars($w['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small fw-bold">Mặt hàng</label>
                        <select name="item_id" class="form-select" id="qvItem" onchange="fetchQvBatches()">
                            <?php foreach ($items as $it): ?>
                                <option value="<?= $it['id'] ?>">[<?= htmlspecialchars($it['code']) ?>] <?= htmlspecialchars($it['name']) ?> (<?= htmlspecialchars($it['unit']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Lô hàng (Batch)</label>
                        <select name="batch_id" class="form-select" id="qvBatch" onchange="fetchQvStock()">
                            <option value="">-- Không chọn lô --</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Số lượng</label>
                        <input type="number" name="quantity" class="form-control" min="1" value="5" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Đơn giá (VNĐ)</label>
                        <input type="number" name="unit_price" class="form-control" min="0" value="500000">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="auto_confirm" id="qvConfirm" value="1" checked>
                            <label class="form-check-label small fw-semibold" for="qvConfirm">Ghi sổ ngay lập tức</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Ghi chú nghiệp vụ</label>
                        <input type="text" name="note" class="form-control" placeholder="VD: Nhập hàng gấp cho đơn B2B...">
                    </div>
                    <div class="col-12 pt-2">
                        <button type="submit" class="btn btn-primary px-4 fw-bold"><i class="bi bi-check2-circle me-1"></i>Xác nhận lập chứng từ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="clean-card main-secondary-card">
            <div class="clean-card-header"><span class="fw-bold small"><i class="bi bi-box-seam text-primary me-1"></i>Tồn kho tức thời tại điểm chọn</span></div>
            <div class="clean-card-body text-center py-4">
                <div class="text-muted small mb-1">Số lượng hiện có</div>
                <div class="display-5 fw-bold text-primary" id="qvLiveStock">--</div>
                <div class="small text-muted mt-2">Tự động kiểm tra chống tồn âm khi xuất kho</div>
            </div>
        </div>
    </div>
</div>

<script>
async function fetchQvBatches() {
    const itemId = document.getElementById('qvItem')?.value;
    const sel = document.getElementById('qvBatch');
    if (!itemId || !sel) return;
    const r = await fetch('api.php?action=get_batches&item_id=' + itemId);
    const d = await r.json();
    sel.innerHTML = '<option value="">-- Tất cả lô --</option>' +
        (d.batches || []).map(b => `<option value="${b.id}">${b.batch_number} (HSD: ${b.expiry_date || 'N/A'})</option>`).join('');
    fetchQvStock();
}
async function fetchQvStock() {
    const wh = document.getElementById('qvWh')?.value;
    const it = document.getElementById('qvItem')?.value;
    const b = document.getElementById('qvBatch')?.value || '';
    const r = await fetch(`api.php?action=get_stock&warehouse_id=${wh}&item_id=${it}&batch_id=${b}`);
    const d = await r.json();
    document.getElementById('qvLiveStock').textContent = Number(d.stock ?? 0).toLocaleString('en-US');
}
document.addEventListener('DOMContentLoaded', fetchQvBatches);
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
