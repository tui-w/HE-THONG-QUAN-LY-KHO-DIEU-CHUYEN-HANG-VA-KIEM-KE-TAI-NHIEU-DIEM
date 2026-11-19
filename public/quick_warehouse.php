<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';

use App\Database;
use App\Auth;

Auth::requireLogin();
$pdo = Database::getConnection();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_wh') {
    try {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $initBins = !empty($_POST['auto_init_bins']);
        if ($code === '' || $name === '') throw new RuntimeException('Vui lòng nhập Mã kho và Tên kho!');
        $pdo->prepare("INSERT INTO warehouses (code, name, address, phone) VALUES (?, ?, ?, ?)")->execute([$code, $name, $address, $phone]);
        $newId = (int)$pdo->lastInsertId();

        $admins = $pdo->query("SELECT id FROM users WHERE role IN ('admin', 'manager')")->fetchAll(PDO::FETCH_COLUMN);
        $stPerm = $pdo->prepare("INSERT OR IGNORE INTO user_warehouses (user_id, warehouse_id, can_view, can_create, can_approve) VALUES (?, ?, 1, 1, 1)");
        foreach ($admins as $uid) $stPerm->execute([(int)$uid, $newId]);

        if ($initBins) {
            $stLoc = $pdo->prepare("INSERT OR IGNORE INTO warehouse_locations (warehouse_id, zone_code, rack_code, level_code, bin_code, location_barcode, item_id, max_capacity) VALUES (?, ?, 'R01', ?, ?, ?, NULL, 200)");
            foreach (['A', 'B', 'C', 'D'] as $z) {
                foreach (['T1', 'T2'] as $l) {
                    foreach (['O01', 'O02'] as $b) {
                        $stLoc->execute([$newId, $z, $l, $b, sprintf('K%d-%s-R01-%s-%s', $newId, $z, $l, $b)]);
                    }
                }
            }
        }
        $msg = "Đã khởi tạo điểm kho mới [{$code}] {$name}" . ($initBins ? ' kèm 16 ô kệ!' : '!');
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }
}

$whRows = $pdo->query("
    SELECT w.*, COUNT(wl.id) AS total_bins,
           SUM(CASE WHEN wl.item_id IS NOT NULL THEN 1 ELSE 0 END) AS filled_bins
    FROM warehouses w
    LEFT JOIN warehouse_locations wl ON wl.warehouse_id = w.id
    GROUP BY w.id ORDER BY w.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Khởi tạo & Quản lý Nhanh Điểm kho';
$activePage = 'quick_warehouse';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold mb-0">Khởi tạo &amp; Quản lý Nhanh Điểm Kho</h4>
        <span class="badge bg-primary rounded-pill">Giao diện chuyên biệt</span>
    </div>
    <div class="d-flex gap-2">
        <a href="locations.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="bi bi-arrow-left me-1"></i>Về Sơ đồ Ô kệ</a>
        <a href="warehouses.php" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="bi bi-shield-lock me-1"></i>Ma trận RBAC</a>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success border-0 shadow-xs mb-3"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger border-0 shadow-xs mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="clean-card main-primary-card">
            <div class="clean-card-header"><span class="fw-bold small"><i class="bi bi-building-add text-primary me-1"></i>Thêm Điểm Kho Mới</span></div>
            <div class="clean-card-body p-4">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="action" value="create_wh">
                    <div class="col-4">
                        <label class="form-label small fw-bold">Mã kho</label>
                        <input type="text" name="code" class="form-control text-uppercase" placeholder="KHO-CT" required>
                    </div>
                    <div class="col-8">
                        <label class="form-label small fw-bold">Tên điểm kho</label>
                        <input type="text" name="name" class="form-control" placeholder="Kho Cần Thơ" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Địa chỉ</label>
                        <input type="text" name="address" class="form-control" placeholder="KCN Trà Nóc, Cần Thơ">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Số điện thoại</label>
                        <input type="text" name="phone" class="form-control" placeholder="0292-388-9999">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="auto_init_bins" id="qwInit" value="1" checked>
                            <label class="form-check-label small fw-semibold" for="qwInit">Tự động sinh 16 ô kệ (Khu A, B, C, D)</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100 fw-bold rounded-pill"><i class="bi bi-plus-circle me-1"></i>Khởi tạo điểm kho</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="clean-card main-secondary-card">
            <div class="clean-card-header"><span class="fw-bold small"><i class="bi bi-buildings-fill text-info me-1"></i>Danh sách Kho &amp; Tình trạng Ô kệ (<?= count($whRows) ?>)</span></div>
            <div class="clean-card-body p-3 d-flex flex-column gap-2.5">
                <?php foreach ($whRows as $w):
                    $tot = (int)$w['total_bins'];
                    $fil = (int)$w['filled_bins'];
                    $pct = $tot > 0 ? round(($fil / $tot) * 100) : 0;
                ?>
                    <div class="p-3 rounded-3 border bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary font-monospace"><?= htmlspecialchars($w['code']) ?></span>
                                <strong><?= htmlspecialchars($w['name']) ?></strong>
                            </div>
                            <div class="small text-muted mt-0.5"><?= htmlspecialchars($w['address'] ?: 'Chưa cập nhật địa chỉ') ?> &bull; <?= $fil ?>/<?= $tot ?> ô đã dùng (<?= $pct ?>%)</div>
                        </div>
                        <div class="d-flex gap-1.5">
                            <a href="locations.php?wh=<?= (int)$w['id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">Mở Sơ đồ Kệ</a>
                            <a href="warehouse_hub.php?wh=<?= (int)$w['id'] ?>" class="btn btn-sm btn-primary rounded-pill px-3">Hub Điều hành</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
