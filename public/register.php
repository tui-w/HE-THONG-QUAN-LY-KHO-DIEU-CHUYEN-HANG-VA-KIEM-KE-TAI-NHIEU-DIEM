<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';

use App\Database;
use App\Auth;

Auth::startSession();
$pdo = Database::getConnection();

// Lấy danh sách kho cho form
$warehouses = $pdo->query("SELECT id, code, name FROM warehouses ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$form = [
    'username' => '',
    'full_name' => '',
    'email' => '',
    'role' => 'staff',
    'warehouse_id' => $warehouses[0]['id'] ?? 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['username'] = trim((string)($_POST['username'] ?? ''));
    $form['full_name'] = trim((string)($_POST['full_name'] ?? ''));
    $form['email'] = trim((string)($_POST['email'] ?? ''));
    $form['role'] = trim((string)($_POST['role'] ?? 'staff'));
    $form['warehouse_id'] = (int)($_POST['warehouse_id'] ?? 1);
    
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($password !== $confirmPassword) {
        $error = 'Mật khẩu xác nhận không khớp, vui lòng kiểm tra lại!';
    } else {
        try {
            $newUserId = Auth::register($pdo, [
                'username' => $form['username'],
                'password' => $password,
                'full_name' => $form['full_name'],
                'email' => $form['email'],
                'role' => $form['role'],
                'warehouse_id' => $form['warehouse_id'],
            ]);

            $_SESSION['flash_success'] = "Đăng ký tài khoản '{$form['username']}' thành công với chức vụ " . strtoupper($form['role']) . "! Bạn có thể đăng nhập ngay.";
            header('Location: login.php');
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký tài khoản - Logistix WMS</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.3 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center py-5" style="min-height: 100vh;">

<div class="container">
    <div class="row justify-content-center">
        <div class="col-11 col-sm-10 col-md-8 col-lg-6 col-xl-5">
            <div class="clean-card p-4 p-sm-5 bg-white shadow-sm">
                
                <!-- Brand & Title -->
                <div class="text-center mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center brand-icon-box mx-auto mb-2.5" style="width: 44px; height: 44px; font-size: 1.35rem; border-radius: 10px;">
                        <i class="bi bi-person-plus-fill"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">Đăng ký tài khoản Logistix</h5>
                    <div class="text-muted small">Cấp tài khoản & phân cấp chức vụ trong hệ thống WMS</div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2.5 px-3 small border-0 shadow-xs rounded-3 mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-1 text-danger"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register.php">
                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold text-slate-700">Tên đăng nhập <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" placeholder="VD: nguyen_kho" value="<?= htmlspecialchars($form['username']) ?>" required autofocus>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold text-slate-700">Họ và tên <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" placeholder="VD: Nguyễn Văn A" value="<?= htmlspecialchars($form['full_name']) ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Email liên hệ</label>
                        <input type="email" name="email" class="form-control" placeholder="VD: nguyenvana@logistix.vn" value="<?= htmlspecialchars($form['email']) ?>">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold text-slate-700">Mật khẩu <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" placeholder="Tối thiểu 6 ký tự" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold text-slate-700">Xác nhận mật khẩu <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Nhập lại mật khẩu" required>
                        </div>
                    </div>

                    <!-- Lựa chọn Chức vụ -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-slate-700">Chức vụ trong hệ thống <span class="text-danger">*</span></label>
                        <select name="role" id="roleSelect" class="form-select" onchange="onRoleChanged()">
                            <option value="staff" <?= $form['role'] === 'staff' ? 'selected' : '' ?>>Thủ kho (Staff) - Tác nghiệp nhập/xuất tại kho</option>
                            <option value="auditor" <?= $form['role'] === 'auditor' ? 'selected' : '' ?>>Kiểm kê viên (Auditor) - Đếm hàng & đối soát tồn kho</option>
                            <option value="manager" <?= $form['role'] === 'manager' ? 'selected' : '' ?>>Quản lý (Manager) - Phê duyệt chứng từ toàn hệ thống</option>
                            <option value="admin" <?= $form['role'] === 'admin' ? 'selected' : '' ?>>Quản trị viên (Admin) - Toàn quyền cấu hình & tài khoản</option>
                        </select>
                    </div>

                    <!-- Ghi chú vai trò -->
                    <div id="roleInfoBox" class="p-2.5 rounded-3 mb-3 bg-slate-50 border small text-muted" style="font-size: 0.78rem;">
                        <i class="bi bi-info-circle text-primary me-1"></i>
                        <span id="roleInfoText">Thủ kho chỉ được tạo phiếu và quản lý hàng tại kho được phân công.</span>
                    </div>

                    <!-- Chọn Kho phụ trách cho Thủ kho -->
                    <div class="mb-4" id="warehouseSelectBox">
                        <label class="form-label small fw-semibold text-slate-700">Kho phụ trách ban đầu</label>
                        <select name="warehouse_id" class="form-select">
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['id'] ?>" <?= $form['warehouse_id'] == $wh['id'] ? 'selected' : '' ?>>
                                    [<?= htmlspecialchars($wh['code']) ?>] <?= htmlspecialchars($wh['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn-clean btn-clean-primary w-100 py-2.5 rounded-3 fw-bold">
                        <span>Hoàn tất đăng ký tài khoản</span>
                        <i class="bi bi-arrow-right"></i>
                    </button>
                </form>

                <div class="mt-4 pt-3 border-top text-center">
                    <span class="text-muted small">Đã có tài khoản trên hệ thống?</span>
                    <a href="login.php" class="small fw-semibold text-decoration-none text-primary ms-1">Đăng nhập ngay</a>
                </div>
            </div>

            <!-- Bảo mật footer note -->
            <div class="mt-3 text-center text-muted" style="font-size: 0.74rem;">
                <i class="bi bi-shield-check me-1"></i> Mã hóa bảo mật BCRYPT & Kiểm soát phân quyền máy chủ RBAC.
            </div>
        </div>
    </div>
</div>

<script>
function onRoleChanged() {
    const role = document.getElementById('roleSelect').value;
    const whBox = document.getElementById('warehouseSelectBox');
    const infoText = document.getElementById('roleInfoText');
    
    if (role === 'staff') {
        whBox.style.display = 'block';
        infoText.textContent = 'Thủ kho chỉ được tạo phiếu xuất/nhập và quản trị tồn tại kho phụ trách được gán.';
    } else if (role === 'auditor') {
        whBox.style.display = 'none';
        infoText.textContent = 'Kiểm kê viên độc lập thực hiện kiểm đếm và lập chứng từ đối soát chênh lệch sổ sách.';
    } else if (role === 'manager') {
        whBox.style.display = 'none';
        infoText.textContent = 'Quản lý có thẩm quyền xem báo cáo và phê duyệt phiếu điều chuyển/xuất nhập trên cả 3 miền.';
    } else if (role === 'admin') {
        whBox.style.display = 'none';
        infoText.textContent = 'Quản trị viên có đặc quyền quản trị tài khoản, thiết lập kho hàng và giám sát toàn hệ thống.';
    }
}
document.addEventListener('DOMContentLoaded', onRoleChanged);
</script>

</body>
</html>
