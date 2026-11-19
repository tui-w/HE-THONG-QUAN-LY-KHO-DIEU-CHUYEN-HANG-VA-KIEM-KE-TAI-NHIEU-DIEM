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

$whFilter = isset($_GET['wh']) && $_GET['wh'] !== '' ? (int) $_GET['wh'] : 1;
$zoneFilter = trim((string) ($_GET['zone'] ?? ''));

// Thêm vị trí mới (Phải chạy TRƯỚC khi xuất HTML header.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_location') {
    try {
        $whId = (int) $_POST['warehouse_id'];
        $zone = strtoupper(trim((string) $_POST['zone_code']));
        $rack = strtoupper(trim((string) $_POST['rack_code']));
        $level = strtoupper(trim((string) $_POST['level_code']));
        $bin = strtoupper(trim((string) $_POST['bin_code']));
        $itemId = !empty($_POST['item_id']) ? (int) $_POST['item_id'] : null;
        $capacity = max(10, (int) $_POST['max_capacity']);

        $barcode = sprintf('K%d-%s-%s-%s-%s', $whId, $zone, $rack, $level, $bin);

        $chk = $pdo->prepare("SELECT COUNT(*) FROM warehouse_locations WHERE location_barcode = ?");
        $chk->execute([$barcode]);
        if ((int) $chk->fetchColumn() > 0) {
            throw new RuntimeException("Vị trí {$barcode} đã tồn tại trong sơ đồ kho! Vui lòng chọn mã Ô hoặc Dãy/Tầng khác.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO warehouse_locations (warehouse_id, zone_code, rack_code, level_code, bin_code, location_barcode, item_id, max_capacity)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$whId, $zone, $rack, $level, $bin, $barcode, $itemId, $capacity]);

        $_SESSION['flash_success'] = "Thêm vị trí mới thành công: {$barcode}";
        header("Location: locations.php?wh={$whId}");
        exit;
    } catch (\Throwable $e) {
        $_SESSION['flash_error'] = "LỖI: " . $e->getMessage();
        header("Location: locations.php?wh={$whFilter}");
        exit;
    }
}

// Thêm Dãy Kệ Hàng Loạt (Phải chạy TRƯỚC khi xuất HTML header.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_rack_batch') {
    try {
        $whId = (int) $_POST['warehouse_id'];
        $zone = strtoupper(trim((string) $_POST['zone_code']));
        $rack = strtoupper(trim((string) $_POST['rack_code']));
        $levels = max(1, min(5, (int) ($_POST['levels'] ?? 2)));
        $binsPerLevel = max(1, min(8, (int) ($_POST['bins_per_level'] ?? 2)));
        $capacity = max(10, (int) ($_POST['max_capacity'] ?? 200));
        if ($whId <= 0 || $rack === '') throw new RuntimeException('Vui lòng nhập đầy đủ Kho và Mã dãy kệ!');

        $stmt = $pdo->prepare("INSERT OR IGNORE INTO warehouse_locations (warehouse_id, zone_code, rack_code, level_code, bin_code, location_barcode, item_id, max_capacity) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)");
        $created = 0;
        for ($l = 1; $l <= $levels; $l++) {
            for ($b = 1; $b <= $binsPerLevel; $b++) {
                $lvlCode = sprintf('T%d', $l);
                $binCode = sprintf('O%02d', $b);
                $barcode = sprintf('K%d-%s-%s-%s-%s', $whId, $zone, $rack, $lvlCode, $binCode);
                $stmt->execute([$whId, $zone, $rack, $lvlCode, $binCode, $barcode, $capacity]);
                $created += $stmt->rowCount();
            }
        }
        if ($created > 0) {
            $_SESSION['flash_success'] = "Đã thêm dãy kệ {$rack} (Khu {$zone}) với {$created} ô kệ mới!";
        } else {
            $_SESSION['flash_error'] = "Dãy kệ {$rack} (Khu {$zone}) đã tồn tại tất cả các ô tương ứng! Vui lòng nhập mã dãy kệ khác.";
        }
        header("Location: locations.php?wh={$whId}");
        exit;
    } catch (\Throwable $e) {
        $_SESSION['flash_error'] = "LỖI: " . $e->getMessage();
        header("Location: locations.php?wh={$whFilter}");
        exit;
    }
}

$pageTitle = 'Sơ đồ Vị trí Lưu kho (Khu vực / Dãy / Tầng / Ô)';
require_once __DIR__ . '/../templates/header.php';

// Lấy danh sách vị trí
$sql = "
    SELECT 
        l.*,
        w.name AS warehouse_name,
        w.code AS warehouse_code,
        i.code AS item_code,
        i.name AS item_name,
        i.unit,
        COALESCE(c.name, 'Chung') AS category_name
    FROM warehouse_locations l
    JOIN warehouses w ON l.warehouse_id = w.id
    LEFT JOIN items i ON l.item_id = i.id
    LEFT JOIN item_categories c ON i.category_id = c.id
    WHERE l.warehouse_id = :wh
";
$params = [':wh' => $whFilter];

if ($zoneFilter !== '') {
    $sql .= " AND l.zone_code = :z";
    $params[':z'] = $zoneFilter;
}

$sql .= " ORDER BY l.zone_code ASC, l.rack_code ASC, l.level_code ASC, l.bin_code ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Thống kê nhanh
$totalBins = count($locations);
$occupiedBins = 0;
foreach ($locations as $loc) {
    if ($loc['item_id'] !== null) {
        $occupiedBins++;
    }
}
$occupancyRate = $totalBins > 0 ? round(($occupiedBins / $totalBins) * 100, 1) : 0;

// Danh sách sản phẩm cho modal
$itemList = $pdo->query("SELECT id, code, name FROM items ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);

// Tính mã dãy kệ kế tiếp chưa sử dụng cho từng khu (A, B, C, D) trong kho đang chọn
$nextRackByZone = ['A' => 'R05', 'B' => 'R05', 'C' => 'R05', 'D' => 'R03'];
$maxRackNum = [];
foreach ($locations as $loc) {
    $z = strtoupper((string) $loc['zone_code']);
    if (preg_match('/^R(\d+)$/i', (string) $loc['rack_code'], $m)) {
        $num = (int) $m[1];
        if ($num > ($maxRackNum[$z] ?? 0)) {
            $maxRackNum[$z] = $num;
        }
    }
}
foreach (['A', 'B', 'C', 'D'] as $z) {
    $nextNum = ($maxRackNum[$z] ?? 0) + 1;
    $nextRackByZone[$z] = sprintf('R%02d', max(1, $nextNum));
}
?>

<!-- Header Top Banner Gọn Gàng -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold text-slate-900 mb-0" style="letter-spacing: -0.02em;">Sơ đồ Vị trí Ô kệ (Bins)</h4>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="quick_warehouse.php" class="btn-clean btn-clean-secondary btn-sm" title="Trang riêng Khởi tạo & Quản lý Điểm kho">
            <i class="bi bi-buildings text-info"></i> Quản lý Kho
        </a>
        <a href="bin_labels.php?wh=<?= $whFilter ?>" class="btn-clean btn-clean-secondary btn-sm" title="Trang riêng In tem nhãn vị trí ô kệ">
            <i class="bi bi-upc-scan text-primary"></i> In tem vị trí
        </a>
        <button type="button" class="btn-clean btn-clean-secondary btn-sm"
            onclick="openLocModal('addRackModal')">
            <i class="bi bi-columns-gap text-success"></i> Thêm Dãy Kệ
        </button>
        <button type="button" class="btn-clean btn-clean-primary btn-sm shadow-xs"
            onclick="openLocModal('addLocationModal')">
            <i class="bi bi-plus-lg"></i> Thêm 1 Ô
        </button>
    </div>
</div>

<!-- 4 Thẻ KPI Vị trí -->
<div class="row g-2.5 mb-3">
    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">TỔNG Ô KỆ</div>
                    <div class="metric-val text-primary"><?= number_format((float) $totalBins) ?></div>
                </div>
                <div class="metric-icon-box bg-primary-light text-primary">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Ô ĐÃ CÓ HÀNG</div>
                    <div class="metric-val text-success"><?= number_format((float) $occupiedBins) ?></div>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-box-seam-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Ô TRỐNG</div>
                    <div class="metric-val text-info"><?= number_format((float) $totalBins - $occupiedBins) ?></div>
                </div>
                <div class="metric-icon-box bg-info-subtle text-info">
                    <i class="bi bi-inbox-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">TỶ LỆ LẤP ĐẦY</div>
                    <div class="metric-val text-dark"><?= $occupancyRate ?>%</div>
                </div>
                <div class="metric-icon-box bg-purple-subtle text-purple">
                    <i class="bi bi-pie-chart-fill"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Lưới Ma Trận Sơ Đồ Ô Kệ Trực Quan Tích Hợp Mô Hình 3D Xoay 360° -->
<div class="clean-card main-primary-card mb-4">
    <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2">
            <h6 class="fw-bold text-slate-900 mb-0">Bản đồ Không gian Ô Kệ</h6>
            <div class="btn-group btn-group-sm ms-2" role="group">
                <button type="button" class="btn btn-primary fw-bold" id="btnView3DMode"
                    onclick="switchBinViewMode('3D')">
                    <i class="bi bi-box me-1"></i>Không gian 3D
                </button>
                <button type="button" class="btn btn-outline-primary fw-semibold" id="btnView2DMode"
                    onclick="switchBinViewMode('2D')">
                    <i class="bi bi-grid-3x3-gap me-1"></i>Lưới 2D
                </button>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-1.5 align-items-center">
            <button type="button"
                class="btn btn-sm btn-primary fw-bold text-white py-0.5 px-2.5 rounded-pill btn-bin-filter active"
                onclick="filterBinStatus('ALL', this)" style="font-size: 0.76rem;">
                Tất cả (<?= $totalBins ?>)
            </button>
            <button type="button"
                class="btn btn-sm btn-light border text-slate-600 py-0.5 px-2.5 rounded-pill btn-bin-filter"
                onclick="filterBinStatus('FILLED', this)" style="font-size: 0.76rem;">
                Đã có hàng (<?= $occupiedBins ?>)
            </button>
            <button type="button"
                class="btn btn-sm btn-light border text-slate-600 py-0.5 px-2.5 rounded-pill btn-bin-filter"
                onclick="filterBinStatus('EMPTY', this)" style="font-size: 0.76rem;">
                Ô trống (<?= $totalBins - $occupiedBins ?>)
            </button>
        </div>
    </div>

    <div class="bg-slate-50 border-bottom px-3 py-2">
        <form method="GET" action="locations.php" class="row g-2 align-items-center m-0">
            <div class="col-md-5 ps-0 position-relative">
                <div class="input-group input-group-sm" style="border-radius: 999px; overflow: hidden; border: 1px solid #cbd5e1; background: #fff;">
                    <span class="input-group-text bg-white border-0 text-slate-400 ps-3 pe-2"><i class="bi bi-search"></i></span>
                    <input type="text" id="locationSearchInput" class="form-control border-0 shadow-none ps-0 pe-1"
                        placeholder="Tìm nhanh mã vị trí, khu, kệ, tên sản phẩm..." autocomplete="off"
                        onfocus="showLocSuggestList()" onclick="showLocSuggestList()" ondblclick="showLocSuggestList()" oninput="applyBinFilters(); showLocSuggestList();">
                    <button type="button" class="btn btn-sm bg-white border-0 text-slate-500 px-2.5" title="Mở danh sách vị trí"
                        onclick="const dd=document.getElementById('locationSearchDropdown'); if(dd && !dd.classList.contains('d-none')){dd.classList.add('d-none');} else {showLocSuggestList();}">
                        <i class="bi bi-chevron-down" style="font-size: 0.72rem;"></i>
                    </button>
                </div>
                <div id="locationSearchDropdown" class="shadow-lg border rounded-3 bg-white position-absolute start-0 end-0 mt-1 d-none"
                    style="z-index: 1055; max-height: 280px; overflow-y: auto; font-size: 0.8rem;"></div>
            </div>
            <?php
            $currentWhLabel = 'Chọn kho';
            foreach ($warehouses as $wh) {
                if ($whFilter === (int) $wh['id']) {
                    $currentWhLabel = '[' . $wh['code'] . '] ' . $wh['name'];
                    break;
                }
            }
            $zoneLabels = [
                ''  => 'Tất cả phân khu (A, B, C, D)',
                'A' => 'Khu A - Điện tử & Thiết bị',
                'B' => 'Khu B - Linh kiện máy tính',
                'C' => 'Khu C - Phụ kiện & Ngoại vi',
                'D' => 'Khu D - Lưu trữ, IoT & Đóng gói',
            ];
            $currentZoneLabel = $zoneLabels[$zoneFilter] ?? $zoneLabels[''];
            ?>
            <div class="col-md-3">
                <div class="dropdown w-100">
                    <button type="button"
                        class="btn btn-sm bg-white border rounded-pill w-100 d-flex align-items-center justify-content-between gap-2 px-3 py-1.5 text-slate-700 loc-filter-btn"
                        aria-expanded="false"
                        onclick="toggleLocFilterMenu(this, event)">
                        <span class="text-truncate"><?= htmlspecialchars($currentWhLabel) ?></span>
                        <i class="bi bi-chevron-down text-slate-400 flex-shrink-0" style="font-size: 0.72rem;"></i>
                    </button>
                    <ul class="dropdown-menu shadow-lg border rounded-3 py-1" style="z-index: 1065; top: calc(100% + 4px); left: 0; min-width: 100%; width: max-content; max-width: 360px;">
                        <?php foreach ($warehouses as $wh): ?>
                            <li>
                                <a class="dropdown-item small py-2 px-3 text-truncate <?= $whFilter === (int) $wh['id'] ? 'active fw-semibold' : '' ?>"
                                    href="locations.php?wh=<?= (int) $wh['id'] ?><?= $zoneFilter !== '' ? '&zone=' . urlencode($zoneFilter) : '' ?>">
                                    [<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="col-md-3">
                <div class="dropdown w-100">
                    <button type="button"
                        class="btn btn-sm bg-white border rounded-pill w-100 d-flex align-items-center justify-content-between gap-2 px-3 py-1.5 text-slate-700 loc-filter-btn"
                        aria-expanded="false"
                        onclick="toggleLocFilterMenu(this, event)">
                        <span class="text-truncate"><?= htmlspecialchars($currentZoneLabel) ?></span>
                        <i class="bi bi-chevron-down text-slate-400 flex-shrink-0" style="font-size: 0.72rem;"></i>
                    </button>
                    <ul class="dropdown-menu shadow-lg border rounded-3 py-1" style="z-index: 1065; top: calc(100% + 4px); right: 0; left: auto; min-width: 100%; width: max-content; max-width: 340px;">
                        <?php foreach ($zoneLabels as $zVal => $zLbl): ?>
                            <li>
                                <a class="dropdown-item small py-2 px-3 text-truncate <?= $zoneFilter === $zVal ? 'active fw-semibold' : '' ?>"
                                    href="locations.php?wh=<?= $whFilter ?><?= $zVal !== '' ? '&zone=' . urlencode($zVal) : '' ?>">
                                    <?= htmlspecialchars($zLbl) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="col-md-1 pe-0">
                <a href="locations.php?wh=<?= $whFilter ?>"
                    class="btn-clean btn-clean-secondary btn-sm w-100 py-1 justify-content-center" title="Đặt lại">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
            </div>
        </form>
    </div>

    <div class="clean-card-body p-3 bg-white">
        <!-- CHẾ ĐỘ 1: MÔ HÌNH KHÔNG GIAN KHO 3D XOAY 360° (INTERACTIVE 3D DIGITAL TWIN) -->
        <div id="wh3dViewWrap" class="mb-3">
            <div class="wh3d-stage-wrap" id="wh3dStage">
                <div class="wh3d-grid-bg"></div>
                <div class="wh3d-hud-controls">
                    <button type="button" class="btn btn-sm btn-dark border-secondary-subtle py-1 px-2.5"
                        style="font-size: 0.74rem;" onclick="toggleSpin3D(this)">
                        <i class="bi bi-arrow-repeat me-1 text-info"></i>Xoay 360° Tự động
                    </button>
                    <button type="button" class="btn btn-sm btn-dark border-secondary-subtle py-1 px-2"
                        style="font-size: 0.74rem;" onclick="resetCamera3D()">
                        <i class="bi bi-aspect-ratio me-1"></i>Góc chuẩn
                    </button>
                    <button type="button" class="btn btn-sm btn-dark border-secondary-subtle py-1 px-2"
                        style="font-size: 0.74rem;" onclick="zoomCamera3D(0.12)">
                        <i class="bi bi-zoom-in"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-dark border-secondary-subtle py-1 px-2"
                        style="font-size: 0.74rem;" onclick="zoomCamera3D(-0.12)">
                        <i class="bi bi-zoom-out"></i>
                    </button>
                    <div class="d-none d-xl-flex align-items-center gap-2 ms-2 px-2.5 py-1 rounded-2"
                        style="background:rgba(15,23,42,0.78);font-size:0.68rem;color:#e2e8f0;">
                        <span><i class="bi bi-square-fill me-1" style="color:#6366f1;"></i>Khu A</span>
                        <span><i class="bi bi-square-fill me-1" style="color:#06b6d4;"></i>Khu B</span>
                        <span><i class="bi bi-square-fill me-1" style="color:#ec4899;"></i>Khu C</span>
                        <span><i class="bi bi-square-fill me-1" style="color:#10b981;"></i>Khu D</span>
                        <span><i class="bi bi-square me-1 text-secondary"></i>Ô trống</span>
                    </div>
                </div>

                <?php
                $locsByZone = ['A' => [], 'B' => [], 'C' => [], 'D' => []];
                foreach ($locations as $loc) {
                    $z = $loc['zone_code'] ?: 'A';
                    $locsByZone[$z][] = $loc;
                }
                $zoneNames = [
                    'A' => 'KHU A • ĐIỆN TỬ & LAPTOP',
                    'B' => 'KHU B • LINH KIỆN & MẠNG',
                    'C' => 'KHU C • PHỤ KIỆN & NGOẠI VI',
                    'D' => 'KHU D • LƯU TRỮ, IOT & ĐÓNG GÓI',
                ];
                ?>

                <div class="wh3d-floor" id="wh3dFloor">
                    <!-- Cửa Nhập & Khu Tiếp Nhận -->
                    <div class="wh3d-dock-strip">
                        <span class="wh3d-dock-gate"><i class="bi bi-box-arrow-in-down me-1"></i>CỬA NHẬP HÀNG (INBOUND
                            DOCK)</span>
                        <span><i class="bi bi-upc-scan me-1"></i>KHU KIỂM ĐẾM &amp; PHÂN LOẠI ĐẦU VÀO</span>
                        <span class="badge bg-warning text-dark">XE NÂNG ĐANG HOẠT ĐỘNG</span>
                    </div>

                    <!-- 4 Phân Khu Kệ 3D + Lối Đi Xe Nâng Ở Giữa -->
                    <div class="wh3d-zones-wrap">
                        <?php
                        $renderZone = function (string $zKey) use ($locsByZone, $zoneNames) {
                            $list = $locsByZone[$zKey] ?? [];
                            echo '<div class="wh3d-zone-bay">';
                            echo '<div class="wh3d-zone-label"><span>' . $zoneNames[$zKey] . '</span><span class="badge bg-dark bg-opacity-50">' . count($list) . ' ô</span></div>';
                            echo '<div class="wh3d-zone-grid">';
                            foreach ($list as $loc) {
                                $isFilled = ($loc['item_id'] !== null);
                                $zoneCls = 'zone-' . htmlspecialchars($loc['zone_code']);
                                ?>
                                <div class="wh3d-cube <?= $isFilled ? 'is-filled ' . $zoneCls : 'is-empty' ?>"
                                    data-loc-id="<?= (int) $loc['id'] ?>" data-status="<?= $isFilled ? 'FILLED' : 'EMPTY' ?>"
                                    data-barcode="<?= htmlspecialchars($loc['location_barcode']) ?>"
                                    data-zone="Khu <?= htmlspecialchars($loc['zone_code']) ?> • Kệ <?= htmlspecialchars($loc['rack_code']) ?> • <?= htmlspecialchars($loc['level_code']) ?>-<?= htmlspecialchars($loc['bin_code']) ?>"
                                    data-item="<?= $isFilled ? htmlspecialchars($loc['item_name'] . ' [' . $loc['item_code'] . ']') : 'Ô kệ trống (Sẵn sàng nhập hàng)' ?>"
                                    data-cap="<?= (int) $loc['max_capacity'] ?>" onclick="inspect3DCube(this, true)">
                                    <div class="wh3d-cube-top">
                                        <span><?= htmlspecialchars($loc['rack_code'] . '-' . $loc['bin_code']) ?></span>
                                    </div>
                                    <div class="wh3d-cube-front"></div>
                                    <div class="wh3d-cube-side"></div>
                                </div>
                                <?php
                            }
                            echo '</div></div>';
                        };

                        $renderZone('A');
                        echo '<div class="wh3d-aisle-v"></div>';
                        $renderZone('B');
                        echo '<div class="wh3d-aisle-h">LỐI ĐI XE NÂNG TRUNG TÂM (FORKLIFT AISLE)</div>';
                        $renderZone('C');
                        echo '<div class="wh3d-aisle-v"></div>';
                        $renderZone('D');
                        ?>
                    </div>

                    <!-- Cửa Xuất & Điều Chuyển -->
                    <div class="wh3d-dock-strip">
                        <span class="wh3d-dock-gate outbound"><i class="bi bi-truck me-1"></i>CỬA XUẤT HÀNG (OUTBOUND
                            DOCK)</span>
                        <span>KHU ĐÓNG GÓI &amp; DÁN TEM BARCODE</span>
                        <span class="badge bg-info text-dark">SẴN SÀNG XUẤT B2B</span>
                    </div>
                </div>

                <div class="wh3d-hud-info" id="wh3dHudInfo">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="badge bg-primary"
                            id="hud3dBarcode"><?= htmlspecialchars($locations[0]['location_barcode'] ?? 'K1-A-R01') ?></span>
                        <span class="text-info small fw-bold" style="font-size: 0.68rem;"><i
                                class="bi bi-mouse me-1"></i>Kéo chuột để xoay 3D</span>
                    </div>
                    <div class="fw-bold text-white small" id="hud3dZone">Bấm vào khối kệ 3D để xem chi tiết</div>
                    <div class="text-slate-300 mt-0.5" style="font-size: 0.73rem;" id="hud3dItem">Màu sáng nổi: Đã chứa
                        hàng • Khối thấp: Ô trống</div>
                </div>
            </div>
        </div>

        <!-- Thanh Số Trang Chuyển Bản Đồ Kho (1: KHO-HN, 2: KHO-DN, 3: KHO-HCM) -->
        <?php
        $whIds = array_map(fn($w) => (int)$w['id'], $warehouses);
        $curWhIdx = array_search($whFilter, $whIds, true);
        if ($curWhIdx === false) $curWhIdx = 0;
        $prevWhId = $curWhIdx > 0 ? $whIds[$curWhIdx - 1] : null;
        $nextWhId = ($curWhIdx < count($whIds) - 1) ? $whIds[$curWhIdx + 1] : null;
        $zoneParam = $zoneFilter !== '' ? '&zone=' . urlencode($zoneFilter) : '';
        ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2.5 mt-2 rounded-3 border bg-light" id="warehouseMapPagination">
            <div class="small text-muted">
                <i class="bi bi-buildings-fill text-primary me-1"></i>
                Bản đồ kho <strong><?= $curWhIdx + 1 ?> / <?= count($warehouses) ?></strong>:
                <strong class="text-dark"><?= htmlspecialchars($warehouses[$curWhIdx]['code'] ?? '') ?></strong>
                <span class="d-none d-sm-inline">— <?= htmlspecialchars($warehouses[$curWhIdx]['name'] ?? '') ?></span>
            </div>
            <nav aria-label="Chuyển bản đồ kho">
                <ul class="pagination pagination-sm mb-0 gap-1">
                    <li class="page-item <?= $prevWhId ? '' : 'disabled' ?>">
                        <a class="page-link rounded-2 d-inline-flex align-items-center gap-1" href="<?= $prevWhId ? "locations.php?wh={$prevWhId}{$zoneParam}" : '#' ?>"><i class="bi bi-chevron-left"></i> Kho trước</a>
                    </li>
                    <?php foreach ($warehouses as $idx => $wh):
                        $wId = (int)$wh['id'];
                        $isActive = ($wId === $whFilter);
                    ?>
                        <li class="page-item <?= $isActive ? 'active' : '' ?>">
                            <a class="page-link rounded-2 fw-semibold" href="locations.php?wh=<?= $wId . $zoneParam ?>" title="<?= htmlspecialchars($wh['name']) ?>">
                                <?= $idx + 1 ?> <span class="d-none d-md-inline">&bull; <?= htmlspecialchars($wh['code']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li class="page-item <?= $nextWhId ? '' : 'disabled' ?>">
                        <a class="page-link rounded-2 d-inline-flex align-items-center gap-1" href="<?= $nextWhId ? "locations.php?wh={$nextWhId}{$zoneParam}" : '#' ?>">Kho sau <i class="bi bi-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>

        <!-- CHẾ ĐỘ 2: LƯỚI 2D GỌN ĐẸP THEO MÀU PHÂN KHU -->
        <div class="row g-3 d-none mt-1" id="binGridContainer">
            <?php
            $zoneAccent = [
                'A' => '#6366f1',
                'B' => '#06b6d4',
                'C' => '#ec4899',
                'D' => '#10b981',
            ];
            foreach ($locations as $loc):
                $isFilled = ($loc['item_id'] !== null);
                $zCol = $zoneAccent[$loc['zone_code']] ?? '#6366f1';
                $accentStyle = $isFilled
                    ? "border-left: 4px solid {$zCol}; cursor: pointer;"
                    : "border-left: 4px dashed #94a3b8; cursor: pointer; opacity: 0.85;";
            ?>
                <div class="col-12 col-sm-6 col-lg-4 col-xl-3 bin-card-col"
                    data-status="<?= $isFilled ? 'FILLED' : 'EMPTY' ?>">
                    <div class="bin-card p-3 rounded-3 border text-start transition-all h-100 <?= $isFilled ? 'bg-white shadow-xs' : 'bg-slate-50' ?>"
                        style="<?= $accentStyle ?>" data-loc-id="<?= (int) $loc['id'] ?>"
                        onclick="openBinDetailModal(<?= (int) $loc['id'] ?>)" draggable="true"
                        ondragstart="window._dragBin = this; this.classList.add('nx-dragging')"
                        ondragend="this.classList.remove('nx-dragging')"
                        ondragover="event.preventDefault(); this.classList.add('nx-drop-target')"
                        ondragleave="this.classList.remove('nx-drop-target')" ondrop="dropBinSwap(event, this)">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-1.5">
                            <span class="fw-bold font-monospace small" style="color: <?= $isFilled ? $zCol : '#64748b' ?>;">
                                Khu <?= htmlspecialchars($loc['zone_code']) ?> &bull; <?= htmlspecialchars($loc['rack_code']) ?> &bull; <?= htmlspecialchars($loc['level_code'] . '-' . $loc['bin_code']) ?>
                            </span>
                            <?php if ($isFilled): ?>
                                <span class="badge rounded-pill font-monospace" style="background: <?= $zCol ?>1a; color: <?= $zCol ?>; font-size: 0.65rem;">
                                    <?= htmlspecialchars($loc['item_code']) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary rounded-pill" style="font-size: 0.65rem;">Ô trống</span>
                            <?php endif; ?>
                        </div>
                        <div class="fw-semibold text-slate-900 text-truncate" style="font-size: 0.85rem;"
                            title="<?= htmlspecialchars($loc['item_name'] ?? 'Ô kệ trống') ?>">
                            <?= $isFilled ? htmlspecialchars($loc['item_name']) : '<span class="text-muted fw-normal">Sẵn sàng lưu trữ hàng hóa</span>' ?>
                        </div>
                        <div class="text-muted font-monospace mt-1" style="font-size: 0.68rem;">
                            <i class="bi bi-upc me-1"></i><?= htmlspecialchars($loc['location_barcode']) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Modal Chi Tiết Sản Phẩm Trong Ô Kệ -->
<div class="modal fade" id="binDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 18px;">
            <div class="modal-header px-4 py-3 border-bottom">
                <div>
                    <span class="badge bg-primary font-monospace me-2" id="bdModalBarcode">--</span>
                    <h6 class="modal-title fw-bold d-inline mb-0" id="bdModalTitle">Chi tiết Ô kệ & Sản phẩm</h6>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="bdModalBody">
                <div class="text-center py-4 text-muted">
                    <div class="spinner-border text-primary mb-2"></div>
                    <div class="small">Đang tải thông tin sản phẩm...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    async function dropBinSwap(e, targetCard) {
        e.preventDefault();
        targetCard.classList.remove('nx-drop-target');
        const src = window._dragBin;
        window._dragBin = null;
        if (!src || src === targetCard) return;
        const fd = new FormData();
        fd.append('src_id', src.dataset.locId);
        fd.append('dst_id', targetCard.dataset.locId);
        const res = await fetch('api.php?action=swap_bins', { method: 'POST', body: fd });
        const data = await res.json();
        if (!res.ok || !data.ok) {
            if (window.showNxToast) window.showNxToast(data.error || 'Không thể hoán đổi ô kệ', 'warning');
            return;
        }
        const srcBody = src.querySelector('.border-top');
        const dstBody = targetCard.querySelector('.border-top');
        if (srcBody && dstBody) {
            const tmp = srcBody.innerHTML;
            srcBody.innerHTML = dstBody.innerHTML;
            dstBody.innerHTML = tmp;
            if (window.showNxToast) window.showNxToast(data.message, 'success');
            if (window.triggerConfetti) window.triggerConfetti();
        }
    }

    let currentBinStatus = 'ALL';
    let rotX = 54, rotZ = -34, scale3D = 0.76;
    let isDragging3D = false, startX3D = 0, startY3D = 0, downX3D = 0, downY3D = 0;

    function switchBinViewMode(mode) {
        const wrap3D = document.getElementById('wh3dViewWrap');
        const grid2D = document.getElementById('binGridContainer');
        const footerBar = grid2D?.parentElement?.nextElementSibling;
        const btn3D = document.getElementById('btnView3DMode');
        const btn2D = document.getElementById('btnView2DMode');

        if (mode === '3D') {
            wrap3D.classList.remove('d-none');
            grid2D.classList.add('d-none');
            if (footerBar && footerBar.classList.contains('table-footer-bar')) footerBar.classList.add('d-none');
            btn3D.className = 'btn btn-primary fw-bold';
            btn2D.className = 'btn btn-outline-primary fw-semibold';
        } else {
            wrap3D.classList.add('d-none');
            grid2D.classList.remove('d-none');
            if (footerBar && footerBar.classList.contains('table-footer-bar')) footerBar.classList.remove('d-none');
            btn2D.className = 'btn btn-primary fw-bold';
            btn3D.className = 'btn btn-outline-primary fw-semibold';
        }
    }

    function updateFloorTransform() {
        const floor = document.getElementById('wh3dFloor');
        if (floor && !floor.classList.contains('auto-spin')) {
            floor.style.transform = `rotateX(${rotX}deg) rotateZ(${rotZ}deg) scale(${scale3D})`;
        }
    }

    function toggleSpin3D(btn) {
        const floor = document.getElementById('wh3dFloor');
        if (!floor) return;
        floor.classList.toggle('auto-spin');
        btn.classList.toggle('btn-primary');
        btn.classList.toggle('btn-dark');
    }

    function resetCamera3D() {
        const floor = document.getElementById('wh3dFloor');
        if (floor) floor.classList.remove('auto-spin');
        rotX = 54; rotZ = -34; scale3D = 0.76;
        updateFloorTransform();
    }

    function zoomCamera3D(delta) {
        scale3D = Math.min(1.5, Math.max(0.65, scale3D + delta));
        updateFloorTransform();
    }

    function inspect3DCube(cube, openModal = false) {
        if (window._lastInspectTs && (Date.now() - window._lastInspectTs) < 120) return;
        window._lastInspectTs = Date.now();
        document.getElementById('hud3dBarcode').textContent = cube.getAttribute('data-barcode');
        document.getElementById('hud3dZone').textContent = cube.getAttribute('data-zone') + ' (Sức chứa: ' + cube.getAttribute('data-cap') + ')';
        document.getElementById('hud3dItem').textContent = cube.getAttribute('data-item');
        if (openModal && !window._was3DDrag) {
            const locId = cube.getAttribute('data-loc-id');
            if (locId) openBinDetailModal(locId);
        }
    }

    async function openBinDetailModal(locId) {
        const modalEl = document.getElementById('binDetailModal');
        const bodyEl = document.getElementById('bdModalBody');
        const codeEl = document.getElementById('bdModalBarcode');
        const titleEl = document.getElementById('bdModalTitle');
        if (!modalEl || !bodyEl) return;

        bodyEl.innerHTML = `<div class="text-center py-4 text-muted"><div class="spinner-border text-primary mb-2"></div><div class="small">Đang tải thông tin sản phẩm...</div></div>`;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        try {
            const res = await fetch('api.php?action=get_bin_detail&location_id=' + encodeURIComponent(locId));
            const data = await res.json();
            if (!res.ok || !data.ok) {
                bodyEl.innerHTML = `<div class="alert alert-danger">${data.error || 'Không tải được chi tiết ô kệ'}</div>`;
                return;
            }
            const loc = data.location;
            const item = data.item;
            codeEl.textContent = loc.location_barcode;
            titleEl.textContent = `${loc.warehouse_code} • Khu ${loc.zone_code} • Kệ ${loc.rack_code} • ${loc.level_code}-${loc.bin_code}`;

            if (!item) {
                bodyEl.innerHTML = `
                <div class="text-center py-4">
                    <div class="display-5 text-muted mb-2"><i class="bi bi-inbox"></i></div>
                    <h6 class="fw-bold">Ô kệ hiện đang trống</h6>
                    <p class="text-muted small mb-3">Sức chứa tối đa: <strong>${loc.max_capacity}</strong> đơn vị tại ${loc.warehouse_name}</p>
                    <div class="d-flex justify-content-center gap-2 flex-wrap mb-3">
                        <select id="bdAssignSelect" class="form-select form-select-sm" style="max-width:260px;">
                            <option value="">-- Chọn sản phẩm để gán vào ô --</option>
                            ${(window._binItemOpts || []).map(it => `<option value="${it.id}">[${it.code}] ${it.name}</option>`).join('')}
                        </select>
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" onclick="assignItemToBin(${loc.id})"><i class="bi bi-check2-circle me-1"></i>Gán vào ô</button>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3" onclick="deleteBinLocation(${loc.id})"><i class="bi bi-trash3 me-1"></i>Xóa ô kệ này</button>
                </div>`;
                return;
            }

            const cap = Math.max(1, Number(loc.max_capacity || 200));
            const stock = Number(item.current_stock || 0);
            const pct = Math.min(100, Math.round((stock / cap) * 100));
            const barCls = pct > 85 ? 'bg-danger' : (pct > 60 ? 'bg-warning' : 'bg-success');

            const batchRows = (data.batches || []).map(b => `
            <tr>
                <td class="font-monospace fw-semibold">${b.batch_number}</td>
                <td>${b.expiry_date || '<span class="text-muted">Không HSD</span>'}</td>
                <td class="text-end fw-bold text-primary">${Number(b.qty).toLocaleString('en-US')} ${item.unit}</td>
            </tr>`).join('') || '<tr><td colspan="3" class="text-center text-muted small py-2">Chưa có tồn theo lô</td></tr>';

            const mvRows = (data.movements || []).map(m => `
            <tr>
                <td class="font-monospace small">${m.movement_code}</td>
                <td><span class="badge bg-secondary-subtle text-secondary">${m.movement_type}</span></td>
                <td class="text-end fw-bold ${m.quantity_in > 0 ? 'text-success' : 'text-danger'}">${m.quantity_in > 0 ? '+' + m.quantity_in : '-' + m.quantity_out}</td>
                <td class="text-end fw-semibold">${Number(m.balance_after).toLocaleString('en-US')}</td>
            </tr>`).join('') || '<tr><td colspan="4" class="text-center text-muted small py-2">Chưa có giao dịch gần đây</td></tr>';

            bodyEl.innerHTML = `
            <div class="p-3 rounded-3 border bg-light mb-3">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <span class="badge bg-primary-subtle text-primary mb-1">${item.category_name}</span>
                        <h5 class="fw-bold mb-1">${item.name}</h5>
                        <div class="small text-muted">Mã SKU: <span class="font-monospace fw-bold">${item.code}</span> &bull; ĐVT: <strong>${item.unit}</strong> &bull; Định mức tối thiểu: <strong>${item.min_stock}</strong></div>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted">Tồn tại ${loc.warehouse_code}</div>
                        <div class="fs-3 fw-bold text-primary">${stock.toLocaleString('en-US')} <span class="fs-6 fw-normal">${item.unit}</span></div>
                    </div>
                </div>
                <div class="mt-2">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>Mức lấp đầy ô kệ (${stock}/${cap})</span>
                        <strong>${pct}%</strong>
                    </div>
                    <div class="progress" style="height:8px;"><div class="progress-bar ${barCls}" style="width:${pct}%"></div></div>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <h6 class="fw-bold small mb-2"><i class="bi bi-calendar2-check text-primary me-1"></i>Lô hàng đang tồn (FEFO)</h6>
                    <div class="table-responsive border rounded-3">
                        <table class="table table-sm mb-0 align-middle">
                            <thead><tr><th>Mã lô</th><th>Hạn dùng</th><th class="text-end">SL</th></tr></thead>
                            <tbody>${batchRows}</tbody>
                        </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-bold small mb-2"><i class="bi bi-clock-history text-info me-1"></i>5 giao dịch gần nhất</h6>
                    <div class="table-responsive border rounded-3">
                        <table class="table table-sm mb-0 align-middle">
                            <thead><tr><th>Mã GD</th><th>Loại</th><th class="text-end">+/-</th><th class="text-end">Tồn</th></tr></thead>
                            <tbody>${mvRows}</tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3 pt-2 border-top">
                <div class="d-flex gap-1.5">
                    <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-2.5" onclick="emptyBinLocation(${loc.id})"><i class="bi bi-box-arrow-up me-1"></i>Làm trống ô</button>
                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2.5" onclick="deleteBinLocation(${loc.id})"><i class="bi bi-trash3 me-1"></i>Xóa ô</button>
                </div>
                <div class="d-flex gap-1.5">
                    <a href="reports.php?tab=card&wh=${loc.warehouse_id}&item=${item.id}" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="bi bi-journal-text me-1"></i>Thẻ kho</a>
                    <a href="quick_voucher.php" class="btn btn-sm btn-primary rounded-pill px-3"><i class="bi bi-plus-lg me-1"></i>Nhập / Xuất</a>
                </div>
            </div>
        `;
        } catch (err) {
            bodyEl.innerHTML = `<div class="alert alert-danger">Lỗi kết nối khi tải chi tiết sản phẩm.</div>`;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const stage = document.getElementById('wh3dStage');
        const grid2D = document.getElementById('binGridContainer');
        // Ẩn thanh phân trang 2D khi đang ở chế độ 3D mặc định
        setTimeout(() => {
            const fb = grid2D?.parentElement?.nextElementSibling;
            if (fb && fb.classList.contains('table-footer-bar') && grid2D.classList.contains('d-none')) {
                fb.classList.add('d-none');
            }
        }, 50);

        if (!stage) return;
        stage.addEventListener('mousedown', (e) => {
            if (e.target.closest('button')) return;
            isDragging3D = true;
            window._was3DDrag = false;
            window._downCube = e.target.closest('.wh3d-cube');
            startX3D = downX3D = e.clientX;
            startY3D = downY3D = e.clientY;
            const floor = document.getElementById('wh3dFloor');
            if (floor) floor.classList.remove('auto-spin');
        });
        window.addEventListener('mousemove', (e) => {
            if (!isDragging3D) return;
            if (Math.hypot(e.clientX - downX3D, e.clientY - downY3D) > 10) window._was3DDrag = true;
            const dx = e.clientX - startX3D;
            const dy = e.clientY - startY3D;
            startX3D = e.clientX;
            startY3D = e.clientY;
            rotZ += dx * 0.45;
            rotX = Math.min(78, Math.max(20, rotX - dy * 0.35));
            updateFloorTransform();
        });
        window.addEventListener('mouseup', (e) => {
            const wasDrag = window._was3DDrag;
            const downCube = window._downCube;
            isDragging3D = false;
            window._downCube = null;
            if (!wasDrag && downCube) {
                inspect3DCube(downCube, true);
            }
            setTimeout(() => { window._was3DDrag = false; }, 80);
        });
    });

    function filterBinStatus(st, btn) {
        currentBinStatus = st;
        document.querySelectorAll('.btn-bin-filter').forEach(b => {
            b.classList.remove('btn-primary', 'fw-bold', 'text-white', 'active');
            b.classList.add('btn-light', 'border', 'text-slate-600');
        });
        btn.classList.remove('btn-light', 'border', 'text-slate-600');
        btn.classList.add('btn-primary', 'fw-bold', 'text-white', 'active');
        applyBinFilters();
    }
    function applyBinFilters() {
        const q = (document.getElementById('locationSearchInput')?.value || '').toLowerCase().trim();
        document.querySelectorAll('#binGridContainer .bin-card-col').forEach(col => {
            const st = col.getAttribute('data-status');
            const text = col.innerText.toLowerCase();
            const matchStatus = (currentBinStatus === 'ALL' || st === currentBinStatus);
            const matchText = (q === '' || text.includes(q));
            col.style.display = (matchStatus && matchText) ? '' : 'none';
        });
        document.querySelectorAll('#wh3dFloor .wh3d-cube').forEach(cube => {
            const st = cube.getAttribute('data-status');
            const text = (cube.getAttribute('data-barcode') + ' ' + cube.getAttribute('data-zone') + ' ' + cube.getAttribute('data-item')).toLowerCase();
            const matchStatus = (currentBinStatus === 'ALL' || st === currentBinStatus);
            const matchText = (q === '' || text.includes(q));
            cube.style.opacity = (matchStatus && matchText) ? '1' : '0.12';
            cube.style.pointerEvents = (matchStatus && matchText) ? 'auto' : 'none';
        });
    }
</script>

<!-- Modal Thêm Vị Trí Mới -->
<div class="modal fade" id="addLocationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-geo-alt-fill text-primary fs-5"></i>
                    <h6 class="modal-title fw-bold mb-0">Thêm Vị trí Lưu kho mới</h6>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="locations.php">
                <input type="hidden" name="action" value="add_location">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Điểm kho</label>
                        <select name="warehouse_id" class="form-select form-select-sm" required>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['id'] ?>" <?= $whFilter === (int) $wh['id'] ? 'selected' : '' ?>>
                                    [<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-slate-700">Khu vực (Zone)</label>
                            <input type="text" name="zone_code" class="form-control form-control-sm text-uppercase"
                                placeholder="A, B, C..." required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-slate-700">Dãy kệ (Rack)</label>
                            <input type="text" name="rack_code" class="form-control form-control-sm text-uppercase"
                                placeholder="R01, R02..." required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-slate-700">Tầng (Level)</label>
                            <input type="text" name="level_code" class="form-control form-control-sm text-uppercase"
                                placeholder="T1, T2..." required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-slate-700">Ô (Bin)</label>
                            <input type="text" name="bin_code" class="form-control form-control-sm text-uppercase"
                                placeholder="O01, O02..." required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Mặt hàng gán vị trí (Tùy
                            chọn)</label>
                        <select name="item_id" class="form-select form-select-sm">
                            <option value="">-- Để trống ô kệ này --</option>
                            <?php foreach ($itemList as $it): ?>
                                <option value="<?= $it['id'] ?>">[<?= htmlspecialchars($it['code']) ?>]
                                    <?= htmlspecialchars($it['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Sức chứa tối đa (Đơn vị SP)</label>
                        <input type="number" name="max_capacity" class="form-control form-control-sm" value="200"
                            min="10" max="5000">
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-light">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn-clean btn-clean-primary btn-sm">
                        <i class="bi bi-check-lg"></i> Lưu vị trí
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Thêm Dãy Kệ Hàng Loạt -->
<div class="modal fade" id="addRackModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 18px;">
            <div class="modal-header px-4 py-3 border-bottom">
                <h6 class="modal-title fw-bold mb-0"><i class="bi bi-columns-gap text-success me-2"></i>Thêm Dãy Kệ Mới Hàng Loạt</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_rack_batch">
                <div class="modal-body p-4 row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Kho lưu trữ</label>
                        <select name="warehouse_id" class="form-select">
                            <?php foreach ($warehouses as $w): ?>
                                <option value="<?= $w['id'] ?>" <?= (int)$w['id'] === $whFilter ? 'selected' : '' ?>>[<?= htmlspecialchars($w['code']) ?>] <?= htmlspecialchars($w['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Phân khu (Zone)</label>
                        <select name="zone_code" id="batchRackZone" class="form-select" onchange="updateNextRackSuggestion(this.value)">
                            <option value="A">Khu A (Tím)</option>
                            <option value="B">Khu B (Xanh dương)</option>
                            <option value="C">Khu C (Hồng - Cam)</option>
                            <option value="D">Khu D (Xanh lá)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Mã dãy kệ</label>
                        <input type="text" name="rack_code" id="batchRackCode" class="form-control text-uppercase" value="<?= htmlspecialchars($nextRackByZone['A']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Số tầng</label>
                        <input type="number" name="levels" class="form-control" min="1" max="5" value="2">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Số ô / tầng</label>
                        <input type="number" name="bins_per_level" class="form-control" min="1" max="8" value="2">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Sức chứa tối đa / ô</label>
                        <input type="number" name="max_capacity" class="form-control" min="10" value="200">
                    </div>
                </div>
                <div class="modal-footer px-4 py-3 border-top">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-sm btn-success rounded-pill px-4 fw-bold">Sinh dãy kệ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window._binItemOpts = <?= json_encode($itemList, JSON_UNESCAPED_UNICODE) ?>;
window._nextRackByZone = <?= json_encode($nextRackByZone, JSON_UNESCAPED_UNICODE) ?>;
window._locSuggestData = <?= json_encode(array_map(fn($l) => [
    'barcode' => $l['location_barcode'],
    'zone' => $l['zone_code'],
    'rack' => $l['rack_code'],
    'item' => $l['item_id'] ? ('[' . $l['item_code'] . '] ' . $l['item_name']) : 'Ô trống',
    'filled' => $l['item_id'] !== null,
], $locations), JSON_UNESCAPED_UNICODE) ?>;

function showLocSuggestList() {
    const inp = document.getElementById('locationSearchInput');
    const dd = document.getElementById('locationSearchDropdown');
    if (!inp || !dd) return;
    const q = inp.value.toLowerCase().trim();
    const list = (window._locSuggestData || []).filter(r =>
        q === '' || (r.barcode + ' ' + r.zone + ' ' + r.rack + ' ' + r.item).toLowerCase().includes(q)
    ).slice(0, 30);

    if (list.length === 0) {
        dd.innerHTML = '<div class="p-3 text-center text-muted small">Không có vị trí hoặc sản phẩm phù hợp.</div>';
    } else {
        dd.innerHTML = list.map(r => {
            const safeBc = r.barcode.replace(/'/g, "\\'");
            const badge = r.filled
                ? '<span class="badge bg-primary-subtle text-primary rounded-pill">Có hàng</span>'
                : '<span class="badge bg-light text-secondary border rounded-pill">Trống</span>';
            return `<button type="button" class="dropdown-item d-flex justify-content-between align-items-center py-2 px-3 border-bottom text-wrap" onclick="pickLocSuggest('${safeBc}')">
                <div>
                    <div class="fw-bold text-dark">${r.barcode} <span class="text-muted fw-normal">• Khu ${r.zone} • ${r.rack}</span></div>
                    <div class="text-muted small">${r.item}</div>
                </div>
                ${badge}
            </button>`;
        }).join('');
    }
    dd.classList.remove('d-none');
}

function pickLocSuggest(barcode) {
    const inp = document.getElementById('locationSearchInput');
    const dd = document.getElementById('locationSearchDropdown');
    if (inp) {
        inp.value = barcode;
        applyBinFilters();
    }
    if (dd) dd.classList.add('d-none');
}

function toggleLocFilterMenu(btn, e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const menu = btn.nextElementSibling;
    if (!menu) return;
    const willOpen = !menu.classList.contains('show');
    document.querySelectorAll('.dropdown-menu.show').forEach(m => {
        m.classList.remove('show');
        m.style.display = '';
    });
    if (willOpen) {
        menu.classList.add('show');
        menu.style.display = 'block';
    }
    btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
}

document.addEventListener('click', (e) => {
    const dd = document.getElementById('locationSearchDropdown');
    const inp = document.getElementById('locationSearchInput');
    const wrap = inp ? inp.closest('.position-relative') : null;
    if (dd && (!wrap || !wrap.contains(e.target))) {
        dd.classList.add('d-none');
    }
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown-menu.show').forEach(m => {
            m.classList.remove('show');
            m.style.display = '';
        });
    }
});

function updateNextRackSuggestion(zone) {
    const inp = document.getElementById('batchRackCode');
    if (inp && window._nextRackByZone && window._nextRackByZone[zone]) {
        inp.value = window._nextRackByZone[zone];
    }
}

function closeLocModalFallback(m) {
    m.classList.remove('show');
    m.style.display = 'none';
    m.style.background = '';
    m.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
}

function openLocModal(id) {
    const m = document.getElementById(id);
    if (!m) { alert('Không tìm thấy hộp thoại: ' + id); return; }
    if (!document.querySelector('.modal.show')) {
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
    }
    if (m.parentElement !== document.body) document.body.appendChild(m);
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(m).show();
    } else {
        m.style.display = 'block';
        m.style.background = 'rgba(15, 23, 42, 0.55)';
        m.style.zIndex = '1065';
        m.classList.add('show');
        m.removeAttribute('aria-hidden');
        document.body.classList.add('modal-open');
        m.querySelectorAll('[data-bs-dismiss="modal"]').forEach(btn => {
            btn.onclick = () => closeLocModalFallback(m);
        });
    }
}

async function assignItemToBin(locId) {
    const itemId = document.getElementById('bdAssignSelect')?.value || '';
    if (!itemId) { alert('Vui lòng chọn sản phẩm!'); return; }
    const fd = new FormData();
    fd.append('location_id', locId);
    fd.append('item_id', itemId);
    const r = await fetch('api.php?action=assign_bin_item', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) location.reload();
    else alert(d.error || 'Lỗi gán sản phẩm');
}

async function emptyBinLocation(locId) {
    if (!confirm('Làm trống ô kệ này?')) return;
    const fd = new FormData();
    fd.append('location_id', locId);
    const r = await fetch('api.php?action=assign_bin_item', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) location.reload();
    else alert(d.error || 'Lỗi làm trống ô');
}

async function deleteBinLocation(locId) {
    if (!confirm('Xác nhận xóa ô kệ này khỏi sơ đồ?')) return;
    const fd = new FormData();
    fd.append('location_id', locId);
    const r = await fetch('api.php?action=delete_bin', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) location.reload();
    else alert(d.error || 'Lỗi xóa ô kệ');
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>