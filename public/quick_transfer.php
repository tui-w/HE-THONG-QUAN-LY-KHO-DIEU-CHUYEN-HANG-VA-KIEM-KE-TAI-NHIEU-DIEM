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

$allWarehouses = $pdo->query("SELECT id, code, name FROM warehouses ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$items = $pdo->query("SELECT id, code, name, unit FROM items ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$errorMsg = '';
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $srcWh = (int)($_POST['source_wh'] ?? 0);
    $dstWh = (int)($_POST['dest_wh'] ?? 0);
    $itemId = (int)($_POST['item_id'] ?? 0);
    $qty = (int)($_POST['quantity'] ?? 0);
    $note = trim((string)($_POST['note'] ?? 'Điều chuyển nhanh giữa 2 kho'));

    try {
        if ($srcWh <= 0 || $dstWh <= 0 || $srcWh === $dstWh) {
            throw new RuntimeException('Kho xuất và Kho nhận phải khác nhau.');
        }
        if ($itemId <= 0 || $qty <= 0) {
            throw new RuntimeException('Vui lòng chọn mặt hàng và số lượng > 0.');
        }
        $vId = StockService::createVoucher($pdo, 'TRANSFER', $srcWh, $dstWh, [[
            'item_id' => $itemId,
            'batch_id' => null,
            'quantity' => $qty,
            'unit_price' => 0,
            'note' => $note,
        ]], $userId, $note);
        if (Auth::checkWarehousePermission($pdo, $userId, $srcWh, 'approve')) {
            StockService::confirmVoucher($pdo, $vId, $userId);
            $successMsg = "Đã thực hiện điều chuyển nguyên tử thành công (Chứng từ #{$vId})!";
        } else {
            $successMsg = "Đã tạo lệnh điều chuyển nháp #{$vId}, chờ quản lý duyệt!";
        }
    } catch (\Throwable $e) {
        $errorMsg = $e->getMessage();
    }
}

$pageTitle = 'Lệnh Điều Chuyển Nhanh - Logistix WMS';
$activePage = 'quick_transfer';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold mb-0">Lệnh Điều Chuyển Liên Kho Nhanh</h4>
        <span class="badge bg-info text-white rounded-pill">ACID Transfer</span>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="bi bi-arrow-left me-1"></i>Về Tổng quan</a>
        <a href="transfer.php" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="bi bi-clock-history me-1"></i>Lịch sử điều chuyển</a>
    </div>
</div>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger border-0 shadow-xs mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>
<?php if ($successMsg): ?>
    <div class="alert alert-success border-0 shadow-xs mb-3"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>

<div class="clean-card main-primary-card">
    <div class="clean-card-header"><span class="fw-bold small"><i class="bi bi-truck text-primary me-1"></i>Thiết lập Tuyến Điều chuyển Trực tiếp</span></div>
    <div class="clean-card-body p-4">
        <form method="POST" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-bold">Kho xuất hàng (Nguồn)</label>
                <select name="source_wh" class="form-select" id="qtSrc" onchange="checkQtStock()">
                    <?php foreach ($allWarehouses as $w): ?>
                        <option value="<?= $w['id'] ?>">[<?= htmlspecialchars($w['code']) ?>] <?= htmlspecialchars($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Kho nhận hàng (Đích)</label>
                <select name="dest_wh" class="form-select" id="qtDst">
                    <?php foreach ($allWarehouses as $idx => $w): ?>
                        <option value="<?= $w['id'] ?>" <?= $idx === 1 ? 'selected' : '' ?>>[<?= htmlspecialchars($w['code']) ?>] <?= htmlspecialchars($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <div class="p-2.5 rounded-3 border bg-light text-center">
                    <span class="small text-muted">Tồn khả dụng tại kho xuất: </span>
                    <strong class="text-primary fs-5" id="qtStock">--</strong>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">Mặt hàng điều chuyển</label>
                <select name="item_id" class="form-select" id="qtItem" onchange="checkQtStock()">
                    <?php foreach ($items as $it): ?>
                        <option value="<?= $it['id'] ?>">[<?= htmlspecialchars($it['code']) ?>] <?= htmlspecialchars($it['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Số lượng</label>
                <input type="number" name="quantity" class="form-control" min="1" value="3" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Lý do điều chuyển</label>
                <input type="text" name="note" class="form-control" placeholder="VD: Bổ sung hàng bán chạy...">
            </div>
            <div class="col-12 pt-2">
                <button type="submit" class="btn btn-primary px-4 fw-bold"><i class="bi bi-arrow-left-right me-1"></i>Thực hiện điều chuyển ngay</button>
            </div>
        </form>
    </div>
</div>

<script>
async function checkQtStock() {
    const wh = document.getElementById('qtSrc')?.value;
    const it = document.getElementById('qtItem')?.value;
    const r = await fetch(`api.php?action=get_stock&warehouse_id=${wh}&item_id=${it}`);
    const d = await r.json();
    document.getElementById('qtStock').textContent = Number(d.stock ?? 0).toLocaleString('en-US');
}
document.addEventListener('DOMContentLoaded', checkQtStock);
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
