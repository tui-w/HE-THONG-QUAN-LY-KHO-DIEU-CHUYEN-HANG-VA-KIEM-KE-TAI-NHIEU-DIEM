<?php
declare(strict_types=1);

$pageTitle = 'Chi tiết chứng từ';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../src/StockService.php';

use App\StockService;
use App\Auth;

$voucherId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$voucherCode = isset($_GET['code']) ? trim((string)$_GET['code']) : null;

if ($voucherId) {
    $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = ?");
    $stmt->execute([$voucherId]);
} elseif ($voucherCode) {
    $stmt = $pdo->prepare("SELECT * FROM vouchers WHERE code = ?");
    $stmt->execute([$voucherCode]);
} else {
    header('Location: vouchers.php');
    exit;
}

$voucher = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$voucher) {
    $_SESSION['flash_error'] = 'Không tìm thấy chứng từ yêu cầu!';
    header('Location: vouchers.php');
    exit;
}

// Xử lý phê duyệt chứng từ DRAFT (Dành cho Quản lý / Admin)
$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_voucher') {
    try {
        $targetWh = (int)($voucher['source_warehouse_id'] ?: $voucher['dest_warehouse_id']);
        if (!Auth::checkWarehousePermission($pdo, (int)$currentUser['id'], $targetWh, 'approve')) {
            throw new \RuntimeException("Bạn không có quyền phê duyệt chứng từ tại kho này!");
        }
        StockService::confirmVoucher($pdo, (int)$voucher['id'], (int)$currentUser['id']);
        $_SESSION['flash_success'] = "Đã phê duyệt và ghi nhận chứng từ vào sổ cái kho thành công!";
        header('Location: voucher_detail.php?id=' . $voucher['id']);
        exit;
    } catch (\Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

// Xử lý nút thử nghiệm Idempotent (Phép thử bắt buộc số 3)
$resubmitMessage = '';
$resubmitSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_idempotent') {
    try {
        StockService::confirmVoucher($pdo, (int)$voucher['id'], (int)$currentUser['id']);
        $resubmitSuccess = true;
        $resubmitMessage = "Xác nhận thành công (Không mong đợi đối với voucher đã duyệt).";
    } catch (\RuntimeException $e) {
        $resubmitSuccess = false;
        $resubmitMessage = "HỆ THỐNG CHỐNG XÁC NHẬN LẶP (IDEMPOTENT BẢO TOÀN DỮ LIỆU): " . $e->getMessage();
    }
}

// Lấy thông tin kho và người dùng
$srcWh = $voucher['source_warehouse_id'] ? $pdo->query("SELECT * FROM warehouses WHERE id = {$voucher['source_warehouse_id']}")->fetch(PDO::FETCH_ASSOC) : null;
$destWh = $voucher['dest_warehouse_id'] ? $pdo->query("SELECT * FROM warehouses WHERE id = {$voucher['dest_warehouse_id']}")->fetch(PDO::FETCH_ASSOC) : null;
$creator = $pdo->query("SELECT full_name FROM users WHERE id = {$voucher['created_by']}")->fetchColumn();
$approver = $voucher['approved_by'] ? $pdo->query("SELECT full_name FROM users WHERE id = {$voucher['approved_by']}")->fetchColumn() : null;

// Lấy danh sách dòng chi tiết
$stmtLines = $pdo->prepare("
    SELECT 
        vl.*,
        i.code AS item_code,
        i.name AS item_name,
        i.unit,
        b.batch_number,
        b.expiry_date
    FROM voucher_lines vl
    JOIN items i ON vl.item_id = i.id
    LEFT JOIN batches b ON vl.batch_id = b.id
    WHERE vl.voucher_id = ?
    ORDER BY vl.id ASC
");
$stmtLines->execute([$voucher['id']]);
$lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

// Lấy các dòng phát sinh kho tương ứng trong sổ phát sinh
$stmtMovements = $pdo->prepare("
    SELECT 
        sm.*,
        w.name AS warehouse_name,
        w.code AS warehouse_code,
        i.code AS item_code,
        i.name AS item_name
    FROM stock_movements sm
    JOIN warehouses w ON sm.warehouse_id = w.id
    JOIN items i ON sm.item_id = i.id
    WHERE sm.voucher_id = ?
    ORDER BY sm.id ASC
");
$stmtMovements->execute([$voucher['id']]);
$movements = $stmtMovements->fetchAll(PDO::FETCH_ASSOC);

$statusChip = match ($voucher['status']) {
    'CONFIRMED' => 'chip-success',
    'DRAFT' => 'chip-warning',
    default => 'chip-danger'
};
$statusText = match ($voucher['status']) {
    'CONFIRMED' => 'Đã ghi nhận sổ cái',
    'DRAFT' => 'Bản nháp (Chờ duyệt)',
    default => $voucher['status']
};

$typeChip = match ($voucher['type']) {
    'IN' => 'chip-success',
    'OUT' => 'chip-danger',
    'TRANSFER' => 'chip-primary',
    default => 'chip-warning'
};
$typeText = match ($voucher['type']) {
    'IN' => 'Nhập kho',
    'OUT' => 'Xuất kho',
    'TRANSFER' => 'Điều chuyển liên kho',
    'ADJUST_IN' => 'Điều chỉnh tăng',
    'ADJUST_OUT' => 'Điều chỉnh giảm',
    default => $voucher['type']
};
?>

<!-- Header & Actions -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1.5">
            <a href="vouchers.php" class="text-secondary text-decoration-none small">
                <i class="bi bi-arrow-left"></i> Phiếu Nhập / Xuất
            </a>
            <span class="text-muted small">/</span>
            <span class="chip <?= $typeChip ?>"><?= $typeText ?></span>
            <span class="chip <?= $statusChip ?>"><?= $statusText ?></span>
        </div>
        <h4 class="fw-bold text-dark mb-0">
            Chứng từ: <span class="font-monospace text-primary"><?= htmlspecialchars($voucher['code']) ?></span>
        </h4>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <a href="print_voucher.php?id=<?= $voucher['id'] ?>" target="_blank" class="btn-clean btn-clean-secondary">
            <i class="bi bi-printer"></i> In phiếu / Lưu PDF
        </a>

        <?php if ($voucher['status'] === 'DRAFT'): ?>
            <?php 
            $targetWh = (int)($voucher['source_warehouse_id'] ?: $voucher['dest_warehouse_id']);
            $canApprove = Auth::checkWarehousePermission($pdo, (int)$currentUser['id'], $targetWh, 'approve');
            ?>
            <?php if ($canApprove): ?>
                <form method="POST" action="voucher_detail.php?id=<?= $voucher['id'] ?>" onsubmit="return confirm('Bạn có chắc chắn muốn PHÊ DUYỆT chứng từ này và ghi sổ cái?');">
                    <input type="hidden" name="action" value="confirm_voucher">
                    <button type="submit" class="btn-clean btn-clean-primary">
                        <i class="bi bi-check2-circle"></i> Phê duyệt & Ghi sổ cái
                    </button>
                </form>
            <?php else: ?>
                <span class="chip chip-warning px-3 py-1.5">
                    <i class="bi bi-hourglass-split me-1"></i> Chờ Quản lý duyệt
                </span>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($voucher['status'] === 'CONFIRMED'): ?>
            <form method="POST" action="voucher_detail.php?id=<?= $voucher['id'] ?>">
                <input type="hidden" name="action" value="test_idempotent">
                <button type="submit" class="btn-clean btn-clean-secondary text-dark" title="Thử gửi lại chứng từ đã duyệt để kiểm tra hệ thống có từ chối chống lặp không">
                    <i class="bi bi-shield-check text-warning"></i> Thử gửi lại (Test Idempotent)
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-4 border-0 shadow-sm rounded-3 py-2.5 px-3 small" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($errorMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" style="padding: 0.75rem;"></button>
    </div>
<?php endif; ?>

<?php if ($resubmitMessage): ?>
    <div class="alert <?= $resubmitSuccess ? 'alert-warning' : 'alert-info' ?> alert-dismissible fade show mb-4 border-0 shadow-sm rounded-3 p-3" role="alert">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-shield-lock-fill fs-5 text-primary"></i>
            <strong class="text-dark">Kiểm chứng tính Bất Biến & Idempotent (Phép thử số 3):</strong>
        </div>
        <div class="small ps-4 text-secondary"><?= htmlspecialchars($resubmitMessage) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" style="padding: 0.75rem;"></button>
    </div>
<?php endif; ?>

<!-- MAIN PHỤ: Thanh thông tin chung chứng từ gọn 1 dòng -->
<div class="clean-card main-secondary-card mb-3">
    <div class="clean-card-body py-2.5 px-3">
        <div class="row g-2 align-items-center small">
            <div class="col-md-3">
                <span class="text-secondary">Kho xuất:</span>
                <?= $srcWh ? "<strong class='text-dark ms-1'>" . htmlspecialchars($srcWh['code']) . "</strong>" : '<span class="text-muted ms-1">—</span>' ?>
                <span class="text-secondary ms-2">Kho nhập:</span>
                <?= $destWh ? "<strong class='text-dark ms-1'>" . htmlspecialchars($destWh['code']) . "</strong>" : '<span class="text-muted ms-1">—</span>' ?>
            </div>
            <div class="col-md-3">
                <span class="text-secondary">Ngày lập:</span>
                <strong class="text-dark ms-1"><?= date('d/m/Y H:i', strtotime($voucher['created_at'])) ?></strong>
            </div>
            <div class="col-md-3">
                <span class="text-secondary">Người lập:</span>
                <strong class="text-dark ms-1"><?= htmlspecialchars((string)$creator) ?></strong>
            </div>
            <div class="col-md-3">
                <span class="text-secondary">Người duyệt:</span>
                <?php if ($approver): ?>
                    <strong class="text-success ms-1"><i class="bi bi-check-circle me-0.5"></i><?= htmlspecialchars((string)$approver) ?></strong>
                <?php else: ?>
                    <strong class="text-warning ms-1"><i class="bi bi-clock me-0.5"></i>Chờ duyệt</strong>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- MAIN CHÍNH: Danh sách dòng chi tiết hàng hóa -->
<div class="clean-card main-primary-card mb-4 overflow-hidden">
    <div class="clean-card-header">
        <div class="d-flex align-items-center gap-2">
            <h6 class="fw-bold text-dark mb-0">Danh mục hàng hóa trên chứng từ</h6>
        </div>
        <span class="badge bg-light text-secondary border"><?= count($lines) ?> mặt hàng</span>
    </div>
    <div class="table-responsive">
        <table class="table-clean">
            <thead>
                <tr>
                    <th>STT</th>
                    <th>Mã hàng</th>
                    <th>Tên mặt hàng</th>
                    <th>Lô hàng</th>
                    <th>ĐVT</th>
                    <th class="text-center">Số lượng</th>
                    <th class="text-end">Đơn giá</th>
                    <th class="text-end pe-3">Thành tiền</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $stt = 1; 
                $totalQty = 0; 
                $totalAmount = 0;
                foreach ($lines as $l): 
                    $totalQty += $l['quantity'];
                    $amount = $l['quantity'] * $l['unit_price'];
                    $totalAmount += $amount;
                ?>
                    <tr>
                        <td class="text-secondary small"><?= $stt++ ?></td>
                        <td><span class="text-secondary font-monospace small"><?= htmlspecialchars($l['item_code']) ?></span></td>
                        <td class="fw-semibold text-dark"><?= htmlspecialchars($l['item_name']) ?></td>
                        <td>
                            <?= $l['batch_number'] ? "<span class='chip chip-indigo font-monospace'>{$l['batch_number']}</span>" : '<span class="text-muted small">-</span>' ?>
                        </td>
                        <td class="text-secondary small"><?= htmlspecialchars($l['unit']) ?></td>
                        <td class="text-center fw-bold text-primary"><?= number_format((float)$l['quantity']) ?></td>
                        <td class="text-end small"><?= number_format((float)$l['unit_price']) ?> đ</td>
                        <td class="text-end pe-3 fw-bold text-dark"><?= number_format((float)$amount) ?> đ</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bg-light">
                    <td colspan="5" class="fw-bold text-end py-3 text-secondary">TỔNG CỘNG:</td>
                    <td class="text-center fw-bold fs-6 text-primary py-3"><?= number_format((float)$totalQty) ?></td>
                    <td></td>
                    <td class="text-end pe-3 fw-bold fs-6 text-danger py-3"><?= number_format((float)$totalAmount) ?> đ</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Sổ phát sinh liên kết được ghi nhận trong cơ sở dữ liệu -->
<div class="clean-card mb-4">
    <div class="clean-card-header">
        <div>
            <h6 class="fw-bold text-dark mb-0">Dòng phát sinh ghi nhận trong Sổ cái (Stock Movements)</h6>
            <small class="text-muted">Ánh xạ trực tiếp từ chứng từ thành các phát sinh bất biến phục vụ đối chiếu</small>
        </div>
        <span class="badge bg-light text-secondary border"><?= count($movements) ?> phát sinh</span>
    </div>

    <?php if (empty($movements)): ?>
        <div class="p-4 text-center text-muted small">
            <i class="bi bi-journal-x fs-3 d-block mb-1 opacity-50"></i>
            Chứng từ này chưa ghi nhận phát sinh vào sổ cái (đang ở trạng thái BẢN NHÁP hoặc chưa duyệt).
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table-clean">
                <thead>
                    <tr>
                        <th>Mã phát sinh</th>
                        <th>Điểm kho</th>
                        <th>Loại phát sinh</th>
                        <th>Mặt hàng</th>
                        <th class="text-center">Số lượng nhập (+)</th>
                        <th class="text-center">Số lượng xuất (-)</th>
                        <th class="text-center">Số tồn sau phát sinh</th>
                        <th>Ghi chú</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $m): ?>
                        <tr>
                            <td><span class="text-secondary font-monospace small"><?= htmlspecialchars($m['movement_code']) ?></span></td>
                            <td><span class="chip chip-neutral"><?= htmlspecialchars($m['warehouse_code']) ?></span></td>
                            <td><span class="chip chip-primary"><?= htmlspecialchars($m['movement_type']) ?></span></td>
                            <td class="fw-semibold text-dark"><?= htmlspecialchars($m['item_name']) ?></td>
                            <td class="text-center text-success fw-bold"><?= $m['quantity_in'] > 0 ? '+' . number_format((float)$m['quantity_in']) : '<span class="text-muted fw-normal">-</span>' ?></td>
                            <td class="text-center text-danger fw-bold"><?= $m['quantity_out'] > 0 ? '-' . number_format((float)$m['quantity_out']) : '<span class="text-muted fw-normal">-</span>' ?></td>
                            <td class="text-center fw-bold text-dark"><?= number_format((float)$m['balance_after']) ?></td>
                            <td class="small text-secondary"><?= htmlspecialchars($m['note']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
