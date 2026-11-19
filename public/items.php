<?php
declare(strict_types=1);

$pageTitle = 'Danh mục Mặt hàng & Lô hàng';
require_once __DIR__ . '/../templates/header.php';

$categoryId = isset($_GET['cat']) && $_GET['cat'] !== '' ? (int)$_GET['cat'] : null;
$search = trim((string)($_GET['q'] ?? ''));

// Xử lý thêm mặt hàng mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    try {
        $code = strtoupper(trim((string)$_POST['code']));
        $name = trim((string)$_POST['name']);
        $catId = (int)$_POST['category_id'];
        $unit = trim((string)$_POST['unit']);
        $minStock = max(0, (int)$_POST['min_stock']);
        $desc = trim((string)($_POST['description'] ?? ''));

        if (!$code || !$name || !$catId) {
            throw new \RuntimeException('Vui lòng điền đầy đủ mã hàng, tên mặt hàng và nhóm ngành!');
        }

        $stmtIns = $pdo->prepare("INSERT INTO items (code, name, category_id, unit, min_stock, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtIns->execute([$code, $name, $catId, $unit, $minStock, $desc]);
        $newItemId = (int)$pdo->lastInsertId();

        // Tạo sẵn lô hàng ban đầu
        $stmtB = $pdo->prepare("INSERT INTO batches (item_id, batch_number, manufacture_date, expiry_date) VALUES (?, 'L01-2026', CURRENT_DATE, DATE_ADD(CURRENT_DATE, INTERVAL 2 YEAR))");
        $stmtB->execute([$newItemId]);

        $_SESSION['flash_success'] = "Thêm thành công mặt hàng mới: {$name} ({$code})!";
        header('Location: items.php');
        exit;
    } catch (\Exception $e) {
        $_SESSION['flash_error'] = "LỖI: " . $e->getMessage();
        header('Location: items.php');
        exit;
    }
}
        // Xử lý thêm nhiều mặt hàng cùng lúc
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_add_items') {
            $lines = preg_split('/\r\n|\n|\r/', trim($_POST['bulk_data'] ?? ''));
            $pdo->beginTransaction();
            try {
                foreach ($lines as $line) {
                    if (trim($line) === '') continue;
                    // Expected CSV: code,name,category_id,unit,min_stock,description
                    $parts = str_getcsv($line);
                    if (count($parts) < 6) continue;
                    [$code, $name, $catId, $unit, $minStock, $desc] = $parts;
                    $code = strtoupper(trim($code));
                    $name = trim($name);
                    $catId = (int)trim($catId);
                    $unit = trim($unit);
                    $minStock = max(0, (int)trim($minStock));
                    $desc = trim($desc);
                    if (!$code || !$name || !$catId) {
                        throw new \RuntimeException('Missing required fields in bulk line.');
                    }
                    $stmt = $pdo->prepare("INSERT INTO items (code, name, category_id, unit, min_stock, description) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$code, $name, $catId, $unit, $minStock, $desc]);
                    $newItemId = (int)$pdo->lastInsertId();
                    $stmtB = $pdo->prepare("INSERT INTO batches (item_id, batch_number, manufacture_date, expiry_date) VALUES (?, 'L01-2026', CURRENT_DATE, DATE_ADD(CURRENT_DATE, INTERVAL 2 YEAR))");
                    $stmtB->execute([$newItemId]);
                }
                $pdo->commit();
                $_SESSION['flash_success'] = "Thêm thành công các mặt hàng mới (" . count($lines) . " dòng).";
                header('Location: items.php');
                exit;
            } catch (\Exception $e) {
                $pdo->rollBack();
                $_SESSION['flash_error'] = "LỖI: " . $e->getMessage();
                header('Location: items.php');
                exit;
            }
        }

// Lấy danh mục
$categories = $pdo->query("SELECT * FROM item_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Truy vấn danh mục 30 mặt hàng
$sql = "
    SELECT 
        i.*,
        c.name AS category_name,
        COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS total_stock
    FROM items i
    LEFT JOIN item_categories c ON i.category_id = c.id
    LEFT JOIN stock_movements sm ON sm.item_id = i.id
    WHERE 1=1
";
$params = [];

if ($categoryId !== null) {
    $sql .= " AND i.category_id = :cat";
    $params[':cat'] = $categoryId;
}
if ($search !== '') {
    $sql .= " AND (i.code LIKE :q OR i.name LIKE :q)";
    $params[':q'] = "%{$search}%";
}

$sql .= " GROUP BY i.id ORDER BY i.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$itemList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách lô hàng theo item
$batches = $pdo->query("SELECT * FROM batches ORDER BY item_id, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$itemBatches = [];
foreach ($batches as $b) {
    $itemBatches[$b['item_id']][] = $b;
}

// Thống kê mặt hàng
$totalItems = count($itemList);
$lowStockCount = 0;
$totalSystemStock = 0;
foreach ($itemList as $it) {
    $stk = (int)$it['total_stock'];
    $minStk = (int)$it['min_stock'];
    $totalSystemStock += $stk;
    if ($stk <= $minStk) {
        $lowStockCount++;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold text-slate-900 mb-0" style="letter-spacing: -0.02em;">Danh mục Mặt hàng & Lô hàng</h4>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="barcode.php" class="btn-clean btn-clean-secondary btn-sm" title="In tem nhãn mã vạch Barcode và QR Code">
            <i class="bi bi-upc-scan text-primary"></i>
            <span>In tem Barcode</span>
        </a>
        <button type="button" class="btn-clean btn-clean-secondary btn-sm" onclick="exportItemsToCSV()">
            <i class="bi bi-download"></i>
            <span>Xuất CSV</span>
        </button>
        <button type="button" class="btn-clean btn-clean-primary btn-sm shadow-xs" data-bs-toggle="modal" data-bs-target="#addItemModal">
            <i class="bi bi-plus-lg"></i>
            <span>Thêm mặt hàng</span>
        </button>
    </div>
</div>

<!-- Dải chỉ số KPI Mặt hàng -->
<div class="row g-2.5 mb-3">
    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Tổng mặt hàng</div>
                    <div class="metric-val"><?= number_format((float)$totalItems) ?></div>
                </div>
                <div class="metric-icon-box bg-primary-light text-primary">
                    <i class="bi bi-boxes"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Nhóm ngành</div>
                    <div class="metric-val"><?= count($categories) ?></div>
                </div>
                <div class="metric-icon-box bg-primary-light text-primary">
                    <i class="bi bi-grid-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Tồn thấp</div>
                    <div class="metric-val text-warning"><?= number_format((float)$lowStockCount) ?></div>
                </div>
                <div class="metric-icon-box bg-warning-subtle text-warning">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Tổng tồn 3 kho</div>
                    <div class="metric-val text-success"><?= number_format((float)$totalSystemStock) ?></div>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-check2-circle"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MAIN CHÍNH: Bộ lọc tìm kiếm & Bảng danh mục mặt hàng -->
<div class="clean-card main-primary-card mb-4 overflow-hidden">
    <!-- Tab Lọc Nhanh 1-Click -->
    <div class="clean-card-header d-flex flex-wrap gap-1.5 align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold text-slate-900 small">Danh mục sản phẩm & lô hàng</span>
        </div>
        <div class="d-flex flex-wrap gap-1.5 align-items-center">
            <button type="button" class="btn btn-sm btn-primary fw-bold text-white py-0.5 px-2.5 rounded-pill btn-item-filter active" data-filter="ALL" onclick="filterItemStockStatus('ALL', this)" style="font-size: 0.76rem;">
                Tất cả (<?= $totalItems ?>)
            </button>
            <button type="button" class="btn btn-sm btn-light border text-slate-600 py-0.5 px-2.5 rounded-pill btn-item-filter" data-filter="LOW" onclick="filterItemStockStatus('LOW', this)" style="font-size: 0.76rem;">
                Dưới định mức (<?= $lowStockCount ?>)
            </button>
            <button type="button" class="btn btn-sm btn-light border text-slate-600 py-0.5 px-2.5 rounded-pill btn-item-filter" data-filter="SAFE" onclick="filterItemStockStatus('SAFE', this)" style="font-size: 0.76rem;">
                Đủ tồn an toàn
            </button>
        </div>
    </div>

    <div class="bg-slate-50 border-bottom px-3 py-2">
        <form method="GET" action="items.php" class="row g-2 align-items-center w-100 m-0">
            <div class="col-md-5 ps-0">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0 text-slate-400"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="form-control border-start-0 ps-0" placeholder="Tìm theo mã hàng hoặc tên sản phẩm...">
                </div>
            </div>
            <div class="col-md-4">
                <select name="cat" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Nhóm hàng: Tất cả</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 pe-0 d-flex gap-2">
                <button type="submit" class="btn-clean btn-clean-primary btn-sm w-100 py-1 justify-content-center">
                    <i class="bi bi-funnel"></i> Tìm kiếm
                </button>
                <?php if ($categoryId !== null || $search !== ''): ?>
                    <a href="items.php" class="btn btn-sm btn-outline-secondary py-1 px-2.5 rounded-2" title="Xóa bộ lọc">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-clean align-middle mb-0" id="itemsTable">
            <thead>
                <tr>
                    <th class="ps-4">Mã SKU</th>
                    <th>Tên sản phẩm</th>
                    <th>Nhóm ngành</th>
                    <th>ĐVT</th>
                    <th class="text-center">Định mức</th>
                    <th>Lô hàng</th>
                    <th class="text-center">Tồn 3 kho</th>
                    <th class="text-end pe-4">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($itemList)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-slate-400">Không tìm thấy mặt hàng nào phù hợp.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($itemList as $it): 
                        $batchesOfItem = $itemBatches[$it['id']] ?? [];
                        $stock = (int)$it['total_stock'];
                        $minStock = (int)$it['min_stock'];
                        $stockStatus = ($stock <= $minStock) ? 'LOW' : 'SAFE';
                    ?>
                        <tr class="item-row" data-stock-status="<?= $stockStatus ?>">
                            <td class="ps-4">
                                <span class="font-monospace fw-bold text-primary">
                                    <?= htmlspecialchars($it['code']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-semibold text-slate-900" title="<?= htmlspecialchars((string)$it['description']) ?>">
                                    <?= htmlspecialchars($it['name']) ?>
                                </div>
                            </td>
                            <td>
                                <span class="chip chip-slate"><?= htmlspecialchars($it['category_name']) ?></span>
                            </td>
                            <td class="text-slate-600 small"><?= htmlspecialchars($it['unit']) ?></td>
                            <td class="text-center fw-semibold text-slate-700"><?= number_format((float)$minStock) ?></td>
                            <td>
                                <?php if (empty($batchesOfItem)): ?>
                                    <span class="text-slate-400 small">—</span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($batchesOfItem as $b): ?>
                                            <span class="chip chip-neutral py-0 px-1.5 font-monospace" style="font-size: 0.7rem;" title="HSD: <?= htmlspecialchars((string)$b['expiry_date']) ?>">
                                                <?= htmlspecialchars($b['batch_number']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($stock <= 0): ?>
                                    <span class="chip chip-danger">Hết (0)</span>
                                <?php elseif ($stock <= $minStock): ?>
                                    <span class="chip chip-warning">Thấp (<?= number_format((float)$stock) ?>)</span>
                                <?php else: ?>
                                    <span class="chip chip-success"><?= number_format((float)$stock) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <a href="barcode.php?item_id=<?= $it['id'] ?>" class="btn btn-sm btn-white border px-2.5 py-1 text-slate-700 rounded-2 me-1" title="In tem mã vạch Barcode / QR">
                                    <i class="bi bi-upc-scan me-1 text-success"></i> In tem
                                </a>
                                <a href="reports.php?tab=card&item_id=<?= $it['id'] ?>" class="btn btn-sm btn-white border px-2.5 py-1 text-slate-700 rounded-2" title="Tra cứu Sổ Thẻ kho">
                                    <i class="bi bi-card-checklist me-1 text-primary"></i> Thẻ kho
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="table-footer-bar">
        <div>
            <i class="bi bi-list-ul me-1"></i>
            Hiển thị <strong data-tt-count><?= count($itemList) ?></strong> mặt hàng
            <?php if ($categoryId !== null || $search !== ''): ?>
                <span class="ms-1">(đang lọc)</span>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span><i class="bi bi-circle-fill text-success me-1" style="font-size:.5rem"></i>An toàn</span>
            <span><i class="bi bi-circle-fill text-warning me-1" style="font-size:.5rem"></i>Tồn thấp</span>
            <span><i class="bi bi-circle-fill text-danger me-1" style="font-size:.5rem"></i>Hết hàng</span>
        </div>
    </div>
</div>

<!-- Modal Thêm Mặt Hàng Mới -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content overflow-hidden">
            <form method="POST" action="items.php">
                <input type="hidden" name="action" value="add_item">
                <div class="modal-header bg-white px-4 py-3 border-bottom">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-box-seam text-primary fs-5"></i>
                        <h6 class="modal-title fw-bold text-slate-900 mb-0">Thêm mặt hàng mới vào danh mục</h6>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Mã mặt hàng (SKU) <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control text-uppercase" placeholder="VD: LAP-ASUS-G14, MOUSE-M220..." required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Tên mặt hàng <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Tên sản phẩm chi tiết..." required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-slate-700">Nhóm ngành hàng <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-select" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-slate-700">Đơn vị tính (ĐVT) <span class="text-danger">*</span></label>
                            <input type="text" name="unit" class="form-control" value="Chiếc" placeholder="Chiếc, Hộp, Bộ, Thùng..." required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Định mức tồn an toàn tối thiểu</label>
                        <input type="number" name="min_stock" class="form-control" value="5" min="0" required>
                        <div class="text-slate-400 small mt-1" style="font-size: 0.74rem;">Khi tồn kho dưới số này, hệ thống sẽ tự động phát cảnh báo.</div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-slate-700">Mô tả quy cách</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Thông số kỹ thuật, quy cách đóng gói, xuất xứ..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-slate-50 px-4 py-3 border-top">
                    <button type="button" class="btn-clean btn-clean-secondary" data-bs-dismiss="modal">Hủy bỏ</button>
                    <button type="submit" class="btn-clean btn-clean-primary px-4 fw-bold">
                        <i class="bi bi-check2-circle"></i>
                        <span>Lưu sản phẩm</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function filterItemStockStatus(status, btn) {
    document.querySelectorAll('.btn-item-filter').forEach(b => {
        b.classList.remove('btn-primary', 'fw-bold', 'text-white', 'active');
        b.classList.add('btn-light', 'border', 'text-slate-600');
    });
    btn.classList.remove('btn-light', 'border', 'text-slate-600');
    btn.classList.add('btn-primary', 'fw-bold', 'text-white', 'active');

    const rows = document.querySelectorAll('#itemsTable tbody tr.item-row');
    rows.forEach(r => {
        const rowStatus = r.getAttribute('data-stock-status');
        if (status === 'ALL' || rowStatus === status) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}

function exportItemsToCSV() {
    nxExportTableToCSV('#itemsTable', 'Danh_muc_san_pham_' + new Date().toISOString().slice(0, 10) + '.csv', { skipLastCol: true });
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
