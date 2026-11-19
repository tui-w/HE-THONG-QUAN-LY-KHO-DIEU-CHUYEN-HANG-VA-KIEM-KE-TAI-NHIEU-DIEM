<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/InventoryService.php';

use App\Database;
use App\Auth;
use App\InventoryService;

Auth::requireLogin();
$pdo = Database::getConnection();
$currentUser = Auth::getCurrentUser();
$userId = (int)$currentUser['id'];

$warehouses = Auth::getUserWarehouses($pdo, $userId);
$items = $pdo->query("SELECT id, code, name, unit FROM items ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$errorMsg = '';
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $whId = (int)($_POST['warehouse_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? 'Kiểm kê nhanh'));
    $itemId = (int)($_POST['item_id'] ?? 0);
    $actualQty = (int)($_POST['actual_quantity'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? 'Đối soát nhanh'));

    try {
        if ($whId <= 0 || $itemId <= 0 || $actualQty < 0) {
            throw new RuntimeException('Dữ liệu kiểm kê không hợp lệ.');
        }
        $countId = InventoryService::createStockCount(
            $pdo,
            $whId,
            $title,
            date('Y-m-d'),
            $userId,
            [['item_id' => $itemId, 'batch_id' => null, 'actual_quantity' => $actualQty, 'reason' => $reason]],
            $reason
        );
        $successMsg = "Đã tạo phiên kiểm kê nhanh #{$countId} thành công!";
    } catch (\Throwable $e) {
        $errorMsg = $e->getMessage();
    }
}

$pageTitle = 'Kiểm Kê Nhanh - Logistix WMS';
$activePage = 'quick_audit';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold mb-0">Phiên Kiểm Kê & Đối Soát Nhanh</h4>
        <span class="badge bg-success rounded-pill">Kiểm đếm tức thì</span>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="bi bi-arrow-left me-1"></i>Về Tổng quan</a>
        <a href="inventory.php" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="bi bi-clipboard2-check me-1"></i>Danh sách đợt kiểm kê</a>
    </div>
</div>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger border-0 shadow-xs mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>
<?php if ($successMsg): ?>
    <div class="alert alert-success border-0 shadow-xs mb-3"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>

<div class="clean-card main-primary-card">
    <div class="clean-card-header"><span class="fw-bold small"><i class="bi bi-upc-scan text-success me-1"></i>Ghi nhận Số đếm Thực tế Ngay</span></div>
    <div class="clean-card-body p-4">
        <form method="POST" class="row g-3">
            <div class="col-md-4">
                <label class="form-label small fw-bold">Kho kiểm kê</label>
                <select name="warehouse_id" class="form-select" id="qaWh" onchange="checkQaSys()">
                    <?php foreach ($warehouses as $w): ?>
                        <option value="<?= $w['id'] ?>">[<?= htmlspecialchars($w['code']) ?>] <?= htmlspecialchars($w['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label small fw-bold">Tên phiên kiểm kê</label>
                <input type="text" name="title" class="form-control" value="Kiểm kê nhanh ngày <?= date('d/m/Y') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">Mặt hàng cần đối soát</label>
                <select name="item_id" class="form-select" id="qaItem" onchange="checkQaSys()">
                    <?php foreach ($items as $it): ?>
                        <option value="<?= $it['id'] ?>">[<?= htmlspecialchars($it['code']) ?>] <?= htmlspecialchars($it['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Tồn sổ sách hiện tại</label>
                <input type="text" id="qaSysStock" class="form-control fw-bold text-primary" readonly value="--">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Số thực đếm</label>
                <input type="number" name="actual_quantity" id="qaActual" class="form-control fw-bold" min="0" value="0" required>
            </div>
            <div class="col-12">
                <label class="form-label small fw-bold">Ghi chú / Lý do chênh lệch</label>
                <input type="text" name="reason" class="form-control" placeholder="VD: Kiểm đếm đột xuất tại kệ...">
            </div>
            <div class="col-12 pt-2">
                <button type="submit" class="btn btn-success px-4 fw-bold"><i class="bi bi-clipboard2-check-fill me-1"></i>Lưu phiên kiểm kê nhanh</button>
            </div>
        </form>
    </div>
</div>

<script>
async function checkQaSys() {
    const wh = document.getElementById('qaWh')?.value;
    const it = document.getElementById('qaItem')?.value;
    const r = await fetch(`api.php?action=get_stock&warehouse_id=${wh}&item_id=${it}`);
    const d = await r.json();
    const qty = Number(d.stock ?? 0);
    document.getElementById('qaSysStock').value = qty.toLocaleString('en-US');
    document.getElementById('qaActual').value = qty;
}
document.addEventListener('DOMContentLoaded', checkQaSys);
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
