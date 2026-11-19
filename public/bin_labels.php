<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';

use App\Database;
use App\Auth;

Auth::requireLogin();
$pdo = Database::getConnection();

$whId = isset($_GET['wh']) ? (int)$_GET['wh'] : 1;
$zone = trim((string)($_GET['zone'] ?? ''));

$warehouses = $pdo->query("SELECT id, code, name FROM warehouses ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$sql = "
    SELECT wl.*, w.code AS wh_code, i.code AS item_code, i.name AS item_name
    FROM warehouse_locations wl
    JOIN warehouses w ON wl.warehouse_id = w.id
    LEFT JOIN items i ON wl.item_id = i.id
    WHERE wl.warehouse_id = :wh
";
$params = [':wh' => $whId];
if ($zone !== '') {
    $sql .= " AND wl.zone_code = :z";
    $params[':z'] = $zone;
}
$sql .= " ORDER BY wl.zone_code ASC, wl.rack_code ASC, wl.level_code ASC, wl.bin_code ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$bins = $st->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'In Tem Vị Trí Ô Kệ (Bin Labels)';
$activePage = 'bin_labels';
require_once __DIR__ . '/../templates/header.php';
?>

<style>
@media print {
    .app-navbar, .sidebar, .no-print, .floating-3d-dock, #scrollToTopBtn { display: none !important; }
    .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
    .bin-label-card { break-inside: avoid; border: 1.5px solid #000 !important; }
}
.bin-barcode-bars {
    height: 30px;
    background: repeating-linear-gradient(90deg, #0f172a 0 2px, transparent 2px 4px, #0f172a 4px 7px, transparent 7px 9px, #0f172a 9px 10px, transparent 10px 13px);
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold mb-0">In Tem Nhãn Vị Trí Ô Kệ</h4>
        <span class="badge bg-info text-white rounded-pill"><?= count($bins) ?> tem sẵn sàng</span>
    </div>
    <div class="d-flex gap-2">
        <a href="locations.php?wh=<?= $whId ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="bi bi-arrow-left me-1"></i>Về Sơ đồ Ô kệ</a>
        <button type="button" class="btn btn-sm btn-primary rounded-pill px-4 fw-bold" onclick="window.print()"><i class="bi bi-printer-fill me-1"></i>In tất cả tem (<?= count($bins) ?>)</button>
    </div>
</div>

<div class="clean-card main-secondary-card p-3 mb-3 no-print">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-md-5">
            <select name="wh" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($warehouses as $w): ?>
                    <option value="<?= $w['id'] ?>" <?= (int)$w['id'] === $whId ? 'selected' : '' ?>>[<?= htmlspecialchars($w['code']) ?>] <?= htmlspecialchars($w['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <select name="zone" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Tất cả phân khu (A, B, C, D)</option>
                <?php foreach (['A', 'B', 'C', 'D'] as $z): ?>
                    <option value="<?= $z ?>" <?= $zone === $z ? 'selected' : '' ?>>Phân khu <?= $z ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-sm btn-primary w-100 rounded-pill">Lọc tem nhãn</button>
        </div>
    </form>
</div>

<div class="row g-2.5">
    <?php foreach ($bins as $b): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="bin-label-card p-3 rounded-3 border bg-white text-center shadow-xs">
                <div class="d-flex justify-content-between align-items-center small fw-bold text-primary mb-1">
                    <span><?= htmlspecialchars($b['wh_code']) ?></span>
                    <span>Khu <?= htmlspecialchars($b['zone_code']) ?> &bull; <?= htmlspecialchars($b['rack_code']) ?></span>
                </div>
                <div class="bin-barcode-bars my-2 rounded-1"></div>
                <div class="font-monospace fw-bold fs-6 text-dark"><?= htmlspecialchars($b['location_barcode']) ?></div>
                <div class="small text-muted text-truncate mt-1">
                    <?= $b['item_name'] ? htmlspecialchars($b['item_name']) : 'Ô trống (Sức chứa: ' . (int)$b['max_capacity'] . ')' ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
