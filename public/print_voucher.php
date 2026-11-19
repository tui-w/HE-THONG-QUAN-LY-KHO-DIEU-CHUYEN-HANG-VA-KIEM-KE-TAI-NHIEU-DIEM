<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';

use App\Database;
use App\Auth;

Auth::startSession();
$user = Auth::requireLogin();
$pdo = Database::getConnection();

$voucherId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT * FROM vouchers WHERE id = ?");
$stmt->execute([$voucherId]);
$voucher = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$voucher) {
    die("Không tìm thấy chứng từ.");
}

$srcWh = $voucher['source_warehouse_id'] ? $pdo->query("SELECT * FROM warehouses WHERE id = {$voucher['source_warehouse_id']}")->fetch(PDO::FETCH_ASSOC) : null;
$destWh = $voucher['dest_warehouse_id'] ? $pdo->query("SELECT * FROM warehouses WHERE id = {$voucher['dest_warehouse_id']}")->fetch(PDO::FETCH_ASSOC) : null;
$creator = $pdo->query("SELECT full_name FROM users WHERE id = {$voucher['created_by']}")->fetchColumn();
$approver = $voucher['approved_by'] ? $pdo->query("SELECT full_name FROM users WHERE id = {$voucher['approved_by']}")->fetchColumn() : null;

// Lấy danh sách dòng
$stmtLines = $pdo->prepare("
    SELECT vl.*, i.code AS item_code, i.name AS item_name, i.unit, b.batch_number
    FROM voucher_lines vl
    JOIN items i ON vl.item_id = i.id
    LEFT JOIN batches b ON vl.batch_id = b.id
    WHERE vl.voucher_id = ?
    ORDER BY vl.id ASC
");
$stmtLines->execute([$voucherId]);
$lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

$voucherTitle = match ($voucher['type']) {
    'IN' => 'PHIẾU NHẬP KHO',
    'OUT' => 'PHIẾU XUẤT KHO',
    'TRANSFER' => 'PHIẾU ĐIỀU CHUYỂN KHO',
    'ADJUST_IN' => 'PHIẾU ĐIỀU CHỈNH TĂNG TỒN',
    'ADJUST_OUT' => 'PHIẾU ĐIỀU CHỈNH GIẢM TỒN',
    default => 'PHIẾU KHO',
};
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $voucherTitle ?> - <?= htmlspecialchars($voucher['code']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', Arial, sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            padding: 30px 15px;
        }
        .print-container {
            max-width: 860px;
            margin: 0 auto;
            background: #ffffff;
            padding: 45px 50px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        }
        .barcode {
            font-family: monospace;
            font-size: 1.4rem;
            letter-spacing: 4px;
            border: 2px dashed #94a3b8;
            padding: 6px 14px;
            display: inline-block;
            background: #f8fafc;
        }
        @media print {
            body { background: #ffffff; padding: 0; }
            .print-container { box-shadow: none; padding: 0; max-width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="print-container">
    <!-- Nút điều khiển -->
    <div class="no-print d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
        <a href="voucher_detail.php?id=<?= $voucherId ?>" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8"/></svg>
            Quay lại chi tiết phiếu
        </a>
        <button onclick="window.print()" class="btn btn-primary btn-sm px-4 fw-bold d-inline-flex align-items-center gap-1.5">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M5 1a2 2 0 0 0-2 2v1h10V3a2 2 0 0 0-2-2zm6 8H5a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1"/><path d="M0 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-1v-2a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2H2a2 2 0 0 1-2-2zm2.5 1a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1"/></svg>
            In phiếu / Lưu PDF
        </button>
    </div>

    <!-- Header Phiếu -->
    <div class="row align-items-start mb-4">
        <div class="col-7">
            <h5 class="fw-bold text-primary mb-1">HỆ THỐNG PHÂN PHỐI & QUẢN LÝ KHO ĐA ĐIỂM</h5>
            <div class="small text-secondary">Trung tâm logistics và điều phối hàng hóa</div>
            <div class="small text-secondary">Điểm kho: <strong><?= $destWh ? htmlspecialchars($destWh['name']) : ($srcWh ? htmlspecialchars($srcWh['name']) : 'Kho Tổng') ?></strong></div>
        </div>
        <div class="col-5 text-end">
            <div class="barcode mb-1"><?= htmlspecialchars($voucher['code']) ?></div>
            <div class="small text-secondary">Ngày lập: <?= date('d/m/Y H:i', strtotime($voucher['created_at'])) ?></div>
        </div>
    </div>

    <div class="text-center my-4">
        <h3 class="fw-bold mb-1 tracking-tight"><?= $voucherTitle ?></h3>
        <div class="text-secondary small">Số chứng từ: <strong><?= htmlspecialchars($voucher['code']) ?></strong></div>
    </div>

    <!-- Thông tin chi tiết -->
    <div class="row g-2 mb-4 small">
        <div class="col-6">
            <div>Kho xuất hàng: <strong><?= $srcWh ? htmlspecialchars($srcWh['name']) . " ({$srcWh['code']})" : 'Không áp dụng' ?></strong></div>
            <div>Kho nhận hàng: <strong><?= $destWh ? htmlspecialchars($destWh['name']) . " ({$destWh['code']})" : 'Không áp dụng' ?></strong></div>
            <div class="mt-1">
                <?php if ($voucher['type'] === 'IN' || $voucher['type'] === 'ADJUST_IN'): ?>
                    <strong>Ngày nhập kho:</strong> <span class="badge bg-light text-dark border"><?= date('d/m/Y H:i', strtotime($voucher['created_at'])) ?></span>
                <?php elseif ($voucher['type'] === 'OUT' || $voucher['type'] === 'ADJUST_OUT'): ?>
                    <strong>Ngày xuất kho:</strong> <span class="badge bg-light text-dark border"><?= date('d/m/Y H:i', strtotime($voucher['created_at'])) ?></span>
                <?php else: ?>
                    <strong>Ngày điều chuyển:</strong> <span class="badge bg-light text-dark border"><?= date('d/m/Y H:i', strtotime($voucher['created_at'])) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-6 text-end">
            <div>Người lập phiếu: <strong><?= htmlspecialchars((string)$creator) ?></strong></div>
            <div>Người phê duyệt: <strong><?= $approver ? htmlspecialchars((string)$approver) : 'Chờ duyệt' ?></strong></div>
        </div>
        <div class="col-12 mt-2">
            <div class="p-2 bg-light rounded border">
                Lý do / Diễn giải: <em><?= htmlspecialchars($voucher['note'] ?: 'Theo kế hoạch điều phối kho') ?></em>
            </div>
        </div>
    </div>

    <!-- Bảng sản phẩm -->
    <table class="table table-bordered table-sm mb-4" style="border-color: #cbd5e1;">
        <thead class="table-light text-center small">
            <tr>
                <th style="width: 50px;">STT</th>
                <th>Mã hàng</th>
                <th>Tên nhãn hiệu, quy cách hàng hóa</th>
                <th>Lô hàng</th>
                <th>ĐVT</th>
                <th style="width: 90px;">Số lượng</th>
                <th style="width: 120px;">Đơn giá</th>
                <th style="width: 130px;">Thành tiền</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $stt = 1; 
            $sumQty = 0; 
            $sumAmount = 0;
            foreach ($lines as $l): 
                $sumQty += $l['quantity'];
                $amount = $l['quantity'] * $l['unit_price'];
                $sumAmount += $amount;
            ?>
                <tr>
                    <td class="text-center"><?= $stt++ ?></td>
                    <td><code><?= htmlspecialchars($l['item_code']) ?></code></td>
                    <td class="fw-semibold"><?= htmlspecialchars($l['item_name']) ?></td>
                    <td class="text-center"><?= $l['batch_number'] ?: '-' ?></td>
                    <td class="text-center"><?= htmlspecialchars($l['unit']) ?></td>
                    <td class="text-center fw-bold"><?= number_format((float)$l['quantity']) ?></td>
                    <td class="text-end"><?= number_format((float)$l['unit_price']) ?> đ</td>
                    <td class="text-end fw-semibold"><?= number_format((float)$amount) ?> đ</td>
                </tr>
            <?php endforeach; ?>
            <tr class="table-light fw-bold">
                <td colspan="5" class="text-end">Tổng cộng:</td>
                <td class="text-center text-primary fs-6"><?= number_format((float)$sumQty) ?></td>
                <td></td>
                <td class="text-end text-danger fs-6"><?= number_format((float)$sumAmount) ?> đ</td>
            </tr>
        </tbody>
    </table>

    <!-- Chữ ký các bên -->
    <div class="row text-center mt-5 pt-3" style="font-size: 0.9rem;">
        <div class="col-3">
            <div class="fw-bold">Người lập phiếu</div>
            <div class="text-muted small mb-5">(Ký, họ tên)</div>
            <div class="fw-semibold mt-4"><?= htmlspecialchars((string)$creator) ?></div>
        </div>
        <div class="col-3">
            <div class="fw-bold">Người giao hàng</div>
            <div class="text-muted small mb-5">(Ký, họ tên)</div>
            <div class="fw-semibold mt-4">.........................</div>
        </div>
        <div class="col-3">
            <div class="fw-bold">Thủ kho</div>
            <div class="text-muted small mb-5">(Ký, họ tên)</div>
            <div class="fw-semibold mt-4">.........................</div>
        </div>
        <div class="col-3">
            <div class="fw-bold">Kế toán trưởng / Duyệt</div>
            <div class="text-muted small mb-5">(Ký, họ tên)</div>
            <div class="fw-semibold mt-4"><?= $approver ? htmlspecialchars((string)$approver) : '.........................' ?></div>
        </div>
    </div>
</div>

</body>
</html>
