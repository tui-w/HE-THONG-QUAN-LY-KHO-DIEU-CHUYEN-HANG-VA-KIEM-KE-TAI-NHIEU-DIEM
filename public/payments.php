<?php
declare(strict_types=1);

$pageTitle = 'Quản lý Thu Chi & Công nợ Kho (Payments & Receivables)';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../src/AuditLogger.php';

use App\AuditLogger;

$typeFilter = trim((string)($_GET['type'] ?? ''));
$partnerFilter = isset($_GET['partner_id']) && $_GET['partner_id'] !== '' ? (int)$_GET['partner_id'] : null;

// Xử lý lập phiếu thu/chi mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_payment') {
    try {
        $partnerId = (int)$_POST['partner_id'];
        $type = (string)$_POST['type']; // 'PAY' hoặc 'RECEIVE'
        $amount = (float)$_POST['amount'];
        $method = (string)($_POST['payment_method'] ?? 'BANK_TRANSFER');
        $voucherId = !empty($_POST['voucher_id']) ? (int)$_POST['voucher_id'] : null;
        $note = trim((string)($_POST['note'] ?? ''));

        if ($amount <= 0) {
            throw new \RuntimeException('Số tiền giao dịch thanh toán phải lớn hơn 0!');
        }

        $code = sprintf('%s-%s-%03d', $type === 'PAY' ? 'PAY' : 'REC', date('Ymd'), random_int(100, 999));

        $stmt = $pdo->prepare("
            INSERT INTO payments (payment_code, voucher_id, partner_id, type, amount, payment_method, status, created_by, note)
            VALUES (?, ?, ?, ?, ?, ?, 'PAID', ?, ?)
        ");
        $stmt->execute([$code, $voucherId, $partnerId, $type, $amount, $method, (int)$currentUser['id'], $note]);

        AuditLogger::log($pdo, 'CREATE_PAYMENT', 'payments', $code, "Lập phiếu {$type} trị giá " . number_format((float)$amount) . " VNĐ cho đối tác #{$partnerId}");

        $_SESSION['flash_success'] = "Tạo và xác nhận phiếu giao dịch {$code} thành công!";
        header('Location: payments.php');
        exit;
    } catch (\Throwable $e) {
        $_SESSION['flash_error'] = "LỖI: " . $e->getMessage();
        header('Location: payments.php');
        exit;
    }
}

// Xử lý thêm đối tác mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_partner') {
    try {
        $pCode = strtoupper(trim((string)$_POST['code']));
        $pName = trim((string)$_POST['name']);
        $pType = (string)$_POST['type']; // 'SUPPLIER' hoặc 'CUSTOMER'
        $pPhone = trim((string)($_POST['phone'] ?? ''));
        $pEmail = trim((string)($_POST['email'] ?? ''));
        $pAddress = trim((string)($_POST['address'] ?? ''));
        $pTax = trim((string)($_POST['tax_code'] ?? ''));

        $stmtP = $pdo->prepare("INSERT INTO partners (code, name, type, phone, email, address, tax_code) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmtP->execute([$pCode, $pName, $pType, $pPhone, $pEmail, $pAddress, $pTax]);

        $_SESSION['flash_success'] = "Thêm đối tác thành công: {$pName} ({$pCode})";
        header('Location: payments.php?tab=partners');
        exit;
    } catch (\Throwable $e) {
        $_SESSION['flash_error'] = "LỖI THÊM ĐỐI TÁC: " . $e->getMessage();
        header('Location: payments.php?tab=partners');
        exit;
    }
}

// Truy vấn danh sách phiếu thu/chi
$sqlPay = "
    SELECT 
        p.*,
        pt.name AS partner_name,
        pt.code AS partner_code,
        pt.type AS partner_type,
        v.code AS voucher_code,
        u.full_name AS creator_name
    FROM payments p
    JOIN partners pt ON p.partner_id = pt.id
    LEFT JOIN vouchers v ON p.voucher_id = v.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE 1=1
";
$paramsPay = [];
if ($typeFilter !== '') {
    $sqlPay .= " AND p.type = :type";
    $paramsPay[':type'] = $typeFilter;
}
if ($partnerFilter !== null) {
    $sqlPay .= " AND p.partner_id = :pid";
    $paramsPay[':pid'] = $partnerFilter;
}
$sqlPay .= " ORDER BY p.id DESC";

$stmtPay = $pdo->prepare($sqlPay);
$stmtPay->execute($paramsPay);
$paymentList = $stmtPay->fetchAll(PDO::FETCH_ASSOC);

// Thống kê tài chính
$totalPaid = 0;
$totalReceived = 0;
foreach ($pdo->query("SELECT type, amount FROM payments")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if ($row['type'] === 'PAY') {
        $totalPaid += (float)$row['amount'];
    } else {
        $totalReceived += (float)$row['amount'];
    }
}

// Lấy danh sách đối tác
$partners = $pdo->query("SELECT * FROM partners ORDER BY type DESC, code ASC")->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách chứng từ gần đây
$recentVouchers = $pdo->query("SELECT id, code, type, created_at FROM vouchers ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Header Top Banner Gọn Gàng -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold text-slate-900 mb-0" style="letter-spacing: -0.02em;">Thu Chi & Công nợ Kho</h4>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn-clean btn-clean-secondary btn-sm shadow-xs" data-bs-toggle="modal" data-bs-target="#addPartnerModal">
            <i class="bi bi-person-plus-fill"></i> Thêm đối tác
        </button>
        <button type="button" class="btn-clean btn-clean-primary btn-sm shadow-xs" data-bs-toggle="modal" data-bs-target="#createPaymentModal">
            <i class="bi bi-cash-stack"></i> Lập phiếu thu / chi
        </button>
    </div>
</div>

<!-- 4 Thẻ KPI Tài chính -->
<div class="row g-2.5 mb-3">
    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">TỔNG THU</div>
                    <div class="metric-val text-success"><?= number_format((float)$totalReceived) ?> <small class="fs-6 fw-semibold text-muted">đ</small></div>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-arrow-down-left-circle-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">TỔNG CHI</div>
                    <div class="metric-val text-danger"><?= number_format((float)$totalPaid) ?> <small class="fs-6 fw-semibold text-muted">đ</small></div>
                </div>
                <div class="metric-icon-box bg-danger-subtle text-danger">
                    <i class="bi bi-arrow-up-right-circle-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">DÒNG TIỀN THUẦN</div>
                    <div class="metric-val <?= ($totalReceived - $totalPaid) >= 0 ? 'text-primary' : 'text-danger' ?>">
                        <?= number_format((float)$totalReceived - $totalPaid) ?> <small class="fs-6 fw-semibold text-muted">đ</small>
                    </div>
                </div>
                <div class="metric-icon-box bg-primary-light text-primary">
                    <i class="bi bi-bank"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">ĐỐI TÁC</div>
                    <div class="metric-val text-dark"><?= count($partners) ?></div>
                </div>
                <div class="metric-icon-box bg-purple-subtle text-purple">
                    <i class="bi bi-people-fill"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bảng Danh Sách Giao Dịch Thu Chi Tích Hợp Tìm Kiếm & Lọc Nhanh -->
<div class="clean-card main-primary-card overflow-hidden mb-4">
    <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="fw-bold text-slate-900 mb-0">Sổ Nhật ký Thu Chi & Thanh toán Kho</h6>
        <div class="d-flex flex-wrap gap-1.5 align-items-center">
            <a href="payments.php<?= $partnerFilter ? '?partner_id=' . $partnerFilter : '' ?>" class="btn btn-sm <?= $typeFilter === '' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Tất cả (<?= count($paymentList) ?>)
            </a>
            <a href="payments.php?type=RECEIVE<?= $partnerFilter ? '&partner_id=' . $partnerFilter : '' ?>" class="btn btn-sm <?= $typeFilter === 'RECEIVE' ? 'btn-success fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Phiếu Thu (KH)
            </a>
            <a href="payments.php?type=PAY<?= $partnerFilter ? '&partner_id=' . $partnerFilter : '' ?>" class="btn btn-sm <?= $typeFilter === 'PAY' ? 'btn-danger fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">
                Phiếu Chi (NCC)
            </a>
        </div>
    </div>

    <div class="bg-slate-50 border-bottom px-3 py-2">
        <form method="GET" action="payments.php" class="row g-2 align-items-center m-0">
            <input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>">
            <div class="col-md-5 ps-0">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0 text-slate-400"><i class="bi bi-search"></i></span>
                    <input type="text" id="paymentSearchInput" class="form-control border-start-0 ps-0" placeholder="Tìm nhanh mã giao dịch, đối tác, chứng từ..." onkeyup="filterPaymentsTable()">
                </div>
            </div>
            <div class="col-md-5">
                <select name="partner_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Đối tác: Tất cả NCC & Khách hàng</option>
                    <?php foreach ($partners as $pt): ?>
                        <option value="<?= $pt['id'] ?>" <?= $partnerFilter === (int)$pt['id'] ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($pt['code']) ?>] <?= htmlspecialchars($pt['name']) ?> (<?= $pt['type'] === 'SUPPLIER' ? 'NCC' : 'KH' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 pe-0">
                <a href="payments.php" class="btn-clean btn-clean-secondary btn-sm w-100 py-1 justify-content-center">
                    <i class="bi bi-arrow-clockwise"></i> Đặt lại
                </a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-clean align-middle mb-0" id="paymentsTable">
            <thead>
                <tr>
                    <th class="ps-4">Mã giao dịch</th>
                    <th>Thời gian</th>
                    <th>Loại phiếu</th>
                    <th>Đối tác</th>
                    <th>Chứng từ kho</th>
                    <th class="text-center">Phương thức</th>
                    <th class="text-end">Số tiền (VNĐ)</th>
                    <th class="text-end pe-4">Trạng thái</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($paymentList)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-slate-400">
                            <i class="bi bi-receipt fs-2 d-block mb-2 text-slate-300"></i>
                            Chưa có giao dịch thu chi nào phù hợp với điều kiện lọc.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($paymentList as $pay): 
                        $isPay = ($pay['type'] === 'PAY');
                    ?>
                        <tr>
                            <td class="ps-4">
                                <span class="font-monospace fw-bold <?= $isPay ? 'text-danger' : 'text-success' ?>">
                                    <?= htmlspecialchars($pay['payment_code']) ?>
                                </span>
                            </td>
                            <td class="small text-slate-500 font-monospace">
                                <?= date('d/m/Y H:i', strtotime($pay['created_at'])) ?>
                            </td>
                            <td>
                                <?php if ($isPay): ?>
                                    <span class="chip chip-danger"><i class="bi bi-arrow-up-right"></i> Phiếu Chi</span>
                                <?php else: ?>
                                    <span class="chip chip-success"><i class="bi bi-arrow-down-left"></i> Phiếu Thu</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-bold text-slate-900"><?= htmlspecialchars($pay['partner_name']) ?></div>
                                <span class="font-monospace text-slate-500 small">[<?= htmlspecialchars($pay['partner_code']) ?>]</span>
                            </td>
                            <td>
                                <?php if ($pay['voucher_code']): ?>
                                    <a href="voucher_detail.php?id=<?= $pay['voucher_id'] ?>" class="chip chip-neutral font-monospace text-decoration-none text-primary">
                                        <?= htmlspecialchars($pay['voucher_code']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-slate-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center small">
                                <span class="chip chip-slate">
                                    <i class="bi <?= $pay['payment_method'] === 'BANK_TRANSFER' ? 'bi-credit-card-2-front' : 'bi-cash-coin' ?>"></i>
                                    <?= $pay['payment_method'] === 'BANK_TRANSFER' ? 'Chuyển khoản' : 'Tiền mặt' ?>
                                </span>
                            </td>
                            <td class="text-end fw-extrabold font-monospace <?= $isPay ? 'text-danger' : 'text-success' ?>">
                                <?= $isPay ? '-' : '+' ?><?= number_format((float)$pay['amount']) ?> đ
                            </td>
                            <td class="text-end pe-4">
                                <span class="chip chip-success"><i class="bi bi-check-circle-fill"></i> Đã hạch toán</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Lập Phiếu Thu / Chi Mới -->
<div class="modal fade" id="createPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-cash-coin text-primary fs-5"></i>
                    <h6 class="modal-title fw-bold mb-0">Lập Phiếu Thu / Chi Tiền Kho Vận</h6>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="payments.php">
                <input type="hidden" name="action" value="create_payment">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Loại giao dịch</label>
                        <select name="type" class="form-select form-select-sm" required>
                            <option value="PAY">Phiếu Chi (Thanh toán tiền hàng cho Nhà cung cấp)</option>
                            <option value="RECEIVE">Phiếu Thu (Thu tiền bán/xuất hàng từ Khách hàng)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Đối tác giao dịch</label>
                        <select name="partner_id" class="form-select form-select-sm" required>
                            <?php foreach ($partners as $pt): ?>
                                <option value="<?= $pt['id'] ?>">
                                    [<?= htmlspecialchars($pt['code']) ?>] <?= htmlspecialchars($pt['name']) ?> (<?= $pt['type'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-slate-700">Số tiền (VNĐ)</label>
                            <input type="number" name="amount" class="form-control form-control-sm text-end fw-bold" placeholder="0" min="1000" step="1000" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-slate-700">Hình thức thanh toán</label>
                            <select name="payment_method" class="form-select form-select-sm">
                                <option value="BANK_TRANSFER">Chuyển khoản Ngân hàng</option>
                                <option value="CASH">Tiền mặt</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Gắn với chứng từ kho (Tùy chọn)</label>
                        <select name="voucher_id" class="form-select form-select-sm">
                            <option value="">-- Không gắn chứng từ cụ thể --</option>
                            <?php foreach ($recentVouchers as $v): ?>
                                <option value="<?= $v['id'] ?>">[<?= $v['code'] ?>] Loại: <?= $v['type'] ?> (<?= date('d/m/Y', strtotime($v['created_at'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Ghi chú giao dịch</label>
                        <textarea name="note" class="form-control form-control-sm" rows="2" placeholder="Nội dung diễn giải thanh toán..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-light">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn-clean btn-clean-primary btn-sm">
                        <i class="bi bi-check-lg"></i> Hạch toán phiếu
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Thêm Đối Tác Mới -->
<div class="modal fade" id="addPartnerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-building-add text-primary fs-5"></i>
                    <h6 class="modal-title fw-bold mb-0">Thêm Đối tác & Nhà Cung Cấp / Khách Hàng</h6>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="payments.php">
                <input type="hidden" name="action" value="add_partner">
                <div class="modal-body p-4">
                    <div class="row g-2 mb-3">
                        <div class="col-5">
                            <label class="form-label small fw-semibold text-slate-700">Mã đối tác</label>
                            <input type="text" name="code" class="form-control form-control-sm text-uppercase" placeholder="NCC-..., KH-..." required>
                        </div>
                        <div class="col-7">
                            <label class="form-label small fw-semibold text-slate-700">Phân loại</label>
                            <select name="type" class="form-select form-select-sm" required>
                                <option value="SUPPLIER">Nhà cung cấp (Supplier)</option>
                                <option value="CUSTOMER">Khách hàng (Customer)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Tên doanh nghiệp / Đối tác</label>
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="Công ty TNHH..." required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-slate-700">Số điện thoại</label>
                            <input type="text" name="phone" class="form-control form-control-sm" placeholder="024-...">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-slate-700">Mã số thuế</label>
                            <input type="text" name="tax_code" class="form-control form-control-sm" placeholder="010...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Địa chỉ trụ sở</label>
                        <input type="text" name="address" class="form-control form-control-sm" placeholder="Số nhà, đường, quận/huyện...">
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-light">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn-clean btn-clean-primary btn-sm">
                        <i class="bi bi-save"></i> Lưu thông tin đối tác
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function filterPaymentsTable() {
    const q = (document.getElementById('paymentSearchInput')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#paymentsTable tbody tr').forEach(r => {
        const text = r.innerText.toLowerCase();
        r.style.display = (q === '' || text.includes(q)) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
