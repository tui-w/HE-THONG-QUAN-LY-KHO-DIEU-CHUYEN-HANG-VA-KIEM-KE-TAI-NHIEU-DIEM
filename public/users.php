<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';

use App\Database;
use App\Auth;

$currentUser = Auth::requireRole(['admin']);
$pdo = Database::getConnection();

// Lấy danh sách kho hiện có
$warehouses = $pdo->query("SELECT id, code, name FROM warehouses ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Xử lý các thao tác POST từ Admin
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_user') {
            $username = trim((string)($_POST['username'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? 'staff');
            $status = (string)($_POST['status'] ?? 'active');

            if ($username === '' || $fullName === '' || $password === '') {
                throw new RuntimeException("Vui lòng điền đầy đủ Tên đăng nhập, Họ tên và Mật khẩu!");
            }
            if (mb_strlen($password) < 6) {
                throw new RuntimeException("Mật khẩu phải từ 6 ký tự trở lên!");
            }

            // Kiểm tra username trùng lặp
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetch()) {
                throw new RuntimeException("Tên đăng nhập '{$username}' đã tồn tại!");
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, email, role, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $fullName, $email ?: null, $role, $status]);
            $newId = (int)$pdo->lastInsertId();

            // Lưu phân quyền từng kho
            $whPerms = $_POST['wh_perms'] ?? [];
            $stmtUw = $pdo->prepare("INSERT INTO user_warehouses (user_id, warehouse_id, can_view, can_create, can_approve) VALUES (?, ?, ?, ?, ?)");
            foreach ($warehouses as $wh) {
                $whId = (int)$wh['id'];
                $canView = isset($whPerms[$whId]['view']) ? 1 : 0;
                $canCreate = isset($whPerms[$whId]['create']) ? 1 : 0;
                $canApprove = isset($whPerms[$whId]['approve']) ? 1 : 0;

                // Nếu là admin hoặc manager thì mặc định full quyền
                if ($role === 'admin' || $role === 'manager') {
                    $canView = 1;
                    $canCreate = 1;
                    $canApprove = 1;
                }

                if ($canView || $canCreate || $canApprove) {
                    $stmtUw->execute([$newId, $whId, $canView, $canCreate, $canApprove]);
                }
            }

            $_SESSION['flash_success'] = "Đã thêm thành công tài khoản '{$username}' ({$role})!";
            header('Location: users.php');
            exit;
        }

        if ($action === 'edit_user') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $role = (string)($_POST['role'] ?? 'staff');
            $status = (string)($_POST['status'] ?? 'active');
            $newPassword = (string)($_POST['new_password'] ?? '');

            if ($userId <= 0 || $fullName === '') {
                throw new RuntimeException("Dữ liệu cập nhật không hợp lệ!");
            }

            // Bảo vệ không cho tự đổi role hoặc tự khóa tài khoản của chính mình
            if ($userId === (int)$currentUser['id']) {
                $role = 'admin';
                $status = 'active';
            }

            if ($newPassword !== '') {
                if (mb_strlen($newPassword) < 6) {
                    throw new RuntimeException("Mật khẩu mới phải từ 6 ký tự trở lên!");
                }
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, status = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$fullName, $email ?: null, $role, $status, $hash, $userId]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, status = ? WHERE id = ?");
                $stmt->execute([$fullName, $email ?: null, $role, $status, $userId]);
            }

            // Cập nhật lại quyền kho
            $pdo->prepare("DELETE FROM user_warehouses WHERE user_id = ?")->execute([$userId]);
            $whPerms = $_POST['wh_perms'] ?? [];
            $stmtUw = $pdo->prepare("INSERT INTO user_warehouses (user_id, warehouse_id, can_view, can_create, can_approve) VALUES (?, ?, ?, ?, ?)");
            foreach ($warehouses as $wh) {
                $whId = (int)$wh['id'];
                $canView = isset($whPerms[$whId]['view']) ? 1 : 0;
                $canCreate = isset($whPerms[$whId]['create']) ? 1 : 0;
                $canApprove = isset($whPerms[$whId]['approve']) ? 1 : 0;

                if ($role === 'admin' || $role === 'manager') {
                    $canView = 1;
                    $canCreate = 1;
                    $canApprove = 1;
                }

                if ($canView || $canCreate || $canApprove) {
                    $stmtUw->execute([$userId, $whId, $canView, $canCreate, $canApprove]);
                }
            }

            $_SESSION['flash_success'] = "Đã cập nhật thông tin và phân quyền tài khoản ID #{$userId} thành công!";
            header('Location: users.php');
            exit;
        }

        if ($action === 'toggle_status') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId === (int)$currentUser['id']) {
                throw new RuntimeException("Bạn không thể tự khóa tài khoản của chính mình!");
            }

            $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $currStatus = $stmt->fetchColumn();

            $newStatus = ($currStatus === 'active') ? 'inactive' : 'active';
            $stmtUpdate = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmtUpdate->execute([$newStatus, $userId]);

            $statusText = ($newStatus === 'active') ? 'Kích hoạt' : 'Khóa';
            $_SESSION['flash_success'] = "Đã {$statusText} tài khoản ID #{$userId} thành công!";
            header('Location: users.php');
            exit;
        }

        if ($action === 'delete_user') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId === (int)$currentUser['id']) {
                throw new RuntimeException("Bạn không thể tự xóa tài khoản của chính mình!");
            }

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);

            $_SESSION['flash_success'] = "Đã xóa tài khoản ID #{$userId} khỏi hệ thống!";
            header('Location: users.php');
            exit;
        }
    } catch (\Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// Tìm kiếm & Lọc danh sách user
$search = trim($_GET['q'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');

$query = "SELECT * FROM users WHERE 1=1";
$params = [];
if ($search !== '') {
    $query .= " AND (username LIKE ? OR full_name LIKE ? OR email LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}
if ($roleFilter !== '') {
    $query .= " AND role = ?";
    $params[] = $roleFilter;
}
$query .= " ORDER BY id ASC";
$stmtUsers = $pdo->prepare($query);
$stmtUsers->execute($params);
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Thống kê số lượng theo vai trò
$stats = [
    'total' => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'admin' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
    'manager' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'manager'")->fetchColumn(),
    'staff' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'staff'")->fetchColumn(),
    'auditor' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'auditor'")->fetchColumn(),
    'active' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active' OR status IS NULL")->fetchColumn(),
    'inactive' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'inactive'")->fetchColumn(),
];

// Lấy bản đồ phân quyền kho cho từng user
$userPermissions = [];
$permRows = $pdo->query("
    SELECT uw.*, w.code as warehouse_code, w.name as warehouse_name 
    FROM user_warehouses uw
    JOIN warehouses w ON uw.warehouse_id = w.id
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($permRows as $p) {
    $userPermissions[(int)$p['user_id']][(int)$p['warehouse_id']] = $p;
}

$pageTitle = 'Quản trị Tài khoản & Phân quyền';
$activePage = 'users';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <div class="d-flex align-items-center gap-2">
            <h4 class="fw-bold mb-0 text-slate-900" style="letter-spacing: -0.02em;">Quản trị tài khoản & Phân quyền</h4>
            <span class="chip chip-rose fw-semibold">
                <i class="bi bi-shield-lock-fill"></i> Admin Console
            </span>
        </div>
        <p class="text-slate-500 small mb-0 mt-0.5">Thiết lập tài khoản người dùng, phân cấp quyền hạn theo từng điểm kho và giám sát bảo mật hệ thống.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn-clean btn-clean-secondary" onclick="exportUsersToCSV()">
            <i class="bi bi-download"></i>
            <span>Xuất CSV</span>
        </button>
        <button type="button" class="btn-clean btn-clean-primary shadow-xs" data-bs-toggle="modal" data-bs-target="#createUserModal">
            <i class="bi bi-person-plus-fill"></i>
            <span>Thêm tài khoản mới</span>
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show rounded-3 shadow-xs mb-4 border-0 py-2.5 px-3 small" role="alert">
        <i class="bi bi-info-circle-fill me-1.5"></i> <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close py-2.5" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Thẻ KPI Thống kê Vai trò -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="metric-label">Tổng tài khoản</div>
                    <div class="metric-val"><?= $stats['total'] ?></div>
                </div>
                <div class="d-inline-flex p-2 rounded-3 bg-primary-light text-primary">
                    <i class="bi bi-people-fill fs-5"></i>
                </div>
            </div>
            <div class="metric-sub">
                <span class="text-success fw-bold"><?= $stats['active'] ?></span> hoạt động • <span class="text-danger fw-bold"><?= $stats['inactive'] ?></span> bị khóa
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="metric-label">Quản trị (Admin)</div>
                    <div class="metric-val text-danger"><?= $stats['admin'] ?></div>
                </div>
                <div class="d-inline-flex p-2 rounded-3 bg-danger-subtle text-danger">
                    <i class="bi bi-shield-shaded fs-5"></i>
                </div>
            </div>
            <div class="metric-sub">
                Toàn quyền quản trị hệ thống
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="metric-label">Quản lý (Manager)</div>
                    <div class="metric-val text-primary"><?= $stats['manager'] ?></div>
                </div>
                <div class="d-inline-flex p-2 rounded-3 bg-primary-light text-primary">
                    <i class="bi bi-check2-circle fs-5"></i>
                </div>
            </div>
            <div class="metric-sub">
                Phê duyệt chứng từ toàn mạng lưới
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="metric-label">Thủ kho & Kiểm kê</div>
                    <div class="metric-val text-success"><?= $stats['staff'] + $stats['auditor'] ?></div>
                </div>
                <div class="d-inline-flex p-2 rounded-3 bg-success-subtle text-success">
                    <i class="bi bi-person-lines-fill fs-5"></i>
                </div>
            </div>
            <div class="metric-sub">
                <span class="text-success fw-bold"><?= $stats['staff'] ?></span> Thủ kho • <span class="text-purple fw-bold"><?= $stats['auditor'] ?></span> Kiểm kê
            </div>
        </div>
    </div>
</div>

<!-- Bộ lọc & Danh sách Tài khoản -->
<div class="clean-card main-primary-card mb-4 overflow-hidden">
    <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="fw-bold text-slate-900 mb-0">Danh sách Tài khoản & Phân quyền</h6>
        <div class="d-flex flex-wrap gap-1.5 align-items-center">
            <a href="users.php<?= $search ? '?q='.urlencode($search) : '' ?>" class="btn btn-sm <?= empty($roleFilter) ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">Tất cả (<?= $stats['total'] ?>)</a>
            <a href="users.php?role=admin<?= $search ? '&q='.urlencode($search) : '' ?>" class="btn btn-sm <?= $roleFilter === 'admin' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">Admin (<?= $stats['admin'] ?>)</a>
            <a href="users.php?role=manager<?= $search ? '&q='.urlencode($search) : '' ?>" class="btn btn-sm <?= $roleFilter === 'manager' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">Manager (<?= $stats['manager'] ?>)</a>
            <a href="users.php?role=staff<?= $search ? '&q='.urlencode($search) : '' ?>" class="btn btn-sm <?= $roleFilter === 'staff' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">Thủ kho (<?= $stats['staff'] ?>)</a>
            <a href="users.php?role=auditor<?= $search ? '&q='.urlencode($search) : '' ?>" class="btn btn-sm <?= $roleFilter === 'auditor' ? 'btn-primary fw-bold text-white' : 'btn-light border text-slate-600' ?> py-0.5 px-2.5 rounded-pill" style="font-size: 0.76rem;">Kiểm kê (<?= $stats['auditor'] ?>)</a>
        </div>
    </div>
    <div class="bg-slate-50 border-bottom px-3 py-2">
        <form method="GET" action="users.php" class="row g-2 align-items-center w-100 m-0">
            <div class="col-md-6 ps-0">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0 text-slate-400"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" id="userSearchInput" class="form-control border-start-0 ps-0" placeholder="Tìm theo tên đăng nhập, họ tên hoặc email..." value="<?= htmlspecialchars($search) ?>" onkeyup="filterUsersTable()">
                </div>
            </div>
            <div class="col-md-4">
                <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Chức vụ: Tất cả</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Quản trị viên (Admin)</option>
                    <option value="manager" <?= $roleFilter === 'manager' ? 'selected' : '' ?>>Quản lý kho (Manager)</option>
                    <option value="staff" <?= $roleFilter === 'staff' ? 'selected' : '' ?>>Thủ kho (Staff)</option>
                    <option value="auditor" <?= $roleFilter === 'auditor' ? 'selected' : '' ?>>Kiểm kê viên (Auditor)</option>
                </select>
            </div>
            <div class="col-md-2 pe-0 d-flex gap-1.5">
                <button type="submit" class="btn-clean btn-clean-primary btn-sm w-100 py-1 justify-content-center">
                    <i class="bi bi-funnel"></i> Lọc
                </button>
                <?php if ($search || $roleFilter): ?>
                    <a href="users.php" class="btn btn-sm btn-outline-secondary py-1 px-2"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-clean align-middle mb-0" id="usersTable">
            <thead>
                <tr>
                    <th class="ps-4">Tài khoản & Họ tên</th>
                    <th>Chức vụ</th>
                    <th>Email liên hệ</th>
                    <th>Phân quyền kho</th>
                    <th class="text-center">Trạng thái</th>
                    <th>Ngày tạo</th>
                    <th class="text-end pe-4">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-slate-500">Không tìm thấy tài khoản nào khớp với tiêu chí tìm kiếm.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <?php
                        $uId = (int)$u['id'];
                        $isSelf = ($uId === (int)$currentUser['id']);
                        $status = $u['status'] ?? 'active';
                        $uPerms = $userPermissions[$uId] ?? [];

                        // Role badge style
                        $roleChip = match($u['role']) {
                            'admin' => '<span class="chip chip-rose"><i class="bi bi-shield-lock-fill"></i> Quản trị (Admin)</span>',
                            'manager' => '<span class="chip chip-indigo"><i class="bi bi-check2-circle"></i> Quản lý (Manager)</span>',
                            'staff' => '<span class="chip chip-success"><i class="bi bi-person-fill"></i> Thủ kho (Staff)</span>',
                            'auditor' => '<span class="chip chip-purple"><i class="bi bi-clipboard2-check"></i> Kiểm kê (Auditor)</span>',
                            default => '<span class="chip chip-neutral">' . strtoupper($u['role']) . '</span>',
                        };
                        ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-2.5">
                                    <div class="user-avatar" style="width: 32px; height: 32px; font-size: 0.82rem;">
                                        <?= mb_substr($u['full_name'], 0, 1) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-slate-900" style="font-size: 0.88rem;">
                                            <?= htmlspecialchars($u['full_name']) ?>
                                            <?php if ($isSelf): ?>
                                                <span class="chip chip-neutral py-0.5 px-1.5 ms-1" style="font-size: 0.68rem;">Bạn</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-slate-500 small font-monospace" style="font-size: 0.78rem;">@<?= htmlspecialchars($u['username']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?= $roleChip ?>
                            </td>
                            <td>
                                <span class="text-slate-600 small"><?= htmlspecialchars($u['email'] ?: '—') ?></span>
                            </td>
                            <td>
                                <div class="d-flex gap-1.5 flex-wrap">
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="chip chip-neutral">
                                            <i class="bi bi-shield-check text-danger"></i> Toàn quyền 3 kho
                                        </span>
                                    <?php elseif ($u['role'] === 'manager'): ?>
                                        <span class="chip chip-neutral">
                                            <i class="bi bi-check2-all text-primary"></i> Duyệt chứng từ 3 kho
                                        </span>
                                    <?php else: ?>
                                        <?php 
                                        $renderedWh = 0;
                                        foreach ($warehouses as $wh): 
                                            $whId = (int)$wh['id'];
                                            $hasPerm = isset($uPerms[$whId]);
                                            $canView = $hasPerm && (int)$uPerms[$whId]['can_view'] === 1;
                                            $canCreate = $hasPerm && (int)$uPerms[$whId]['can_create'] === 1;
                                            if ($canView || $canCreate):
                                                $renderedWh++;
                                        ?>
                                                <span class="chip chip-slate" title="<?= htmlspecialchars($wh['name']) ?>">
                                                    <strong><?= $wh['code'] ?></strong>: <?= $canCreate ? 'Tác nghiệp' : 'Chỉ xem' ?>
                                                </span>
                                        <?php 
                                            endif;
                                        endforeach; 
                                        if ($renderedWh === 0):
                                        ?>
                                            <span class="text-slate-400 small fst-italic">Chưa gán kho</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <?php if ($status === 'active'): ?>
                                    <span class="chip chip-success">
                                        <i class="bi bi-check-circle-fill"></i> Hoạt động
                                    </span>
                                <?php else: ?>
                                    <span class="chip chip-danger">
                                        <i class="bi bi-slash-circle-fill"></i> Bị khóa
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-slate-500 small">
                                <?= date('d/m/Y', strtotime($u['created_at'])) ?>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-inline-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-white border px-2 py-1 text-slate-700 rounded-2" 
                                            onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u)) ?>, <?= htmlspecialchars(json_encode($uPerms)) ?>)"
                                            title="Chỉnh sửa & Phân quyền">
                                        <i class="bi bi-pencil"></i>
                                    </button>

                                    <?php if (!$isSelf): ?>
                                        <form method="POST" action="users.php" class="d-inline" onsubmit="return confirm('Bạn có chắc chắn muốn thay đổi trạng thái tài khoản này?');">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= $uId ?>">
                                            <button type="submit" class="btn btn-sm btn-white border px-2 py-1 <?= $status === 'active' ? 'text-amber-600' : 'text-success' ?> rounded-2" 
                                                    title="<?= $status === 'active' ? 'Khóa tài khoản' : 'Mở khóa tài khoản' ?>">
                                                <i class="bi <?= $status === 'active' ? 'bi-lock' : 'bi-unlock' ?>"></i>
                                            </button>
                                        </form>

                                        <form method="POST" action="users.php" class="d-inline" onsubmit="return confirm('Bạn có chắc chắn muốn XÓA vĩnh viễn tài khoản @<?= htmlspecialchars($u['username']) ?>?');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= $uId ?>">
                                            <button type="submit" class="btn btn-sm btn-white border px-2 py-1 text-danger rounded-2" title="Xóa tài khoản">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal 1: Thêm tài khoản mới trực tiếp -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-person-plus-fill text-primary fs-5"></i>
                    <h6 class="modal-title fw-bold mb-0">Thêm tài khoản người dùng mới</h6>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="users.php">
                <input type="hidden" name="action" value="create_user">
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Tên đăng nhập <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" placeholder="nguyen_kho, tran_staff..." required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Họ và tên <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" placeholder="Nguyễn Văn A" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email liên hệ</label>
                            <input type="email" name="email" class="form-control" placeholder="email@logistix.vn">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Mật khẩu khởi tạo <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" placeholder="Tối thiểu 6 ký tự" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Chức vụ / Vai trò <span class="text-danger">*</span></label>
                            <select name="role" id="createRoleSelect" class="form-select" onchange="handleRoleChange('create')">
                                <option value="staff">Thủ kho (STAFF) - Tác nghiệp tại kho được gán</option>
                                <option value="manager">Quản lý (MANAGER) - Phê duyệt chứng từ 3 miền</option>
                                <option value="auditor">Kiểm kê viên (AUDITOR) - Đếm hàng & đối chiếu</option>
                                <option value="admin">Quản trị viên (ADMIN) - Toàn quyền hệ thống</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Trạng thái ban đầu</label>
                            <select name="status" class="form-select">
                                <option value="active">Hoạt động (Active)</option>
                                <option value="inactive">Khóa tạm thời (Inactive)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Phân quyền chi tiết trên từng kho -->
                    <div id="createWarehousePermissions">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center gap-1.5 mb-2">
                            <i class="bi bi-buildings-fill text-primary"></i> Phân quyền chi tiết trên từng điểm kho
                        </label>
                        <div class="table-responsive border rounded-3">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light small">
                                    <tr>
                                        <th>Điểm kho</th>
                                        <th class="text-center">Quyền Xem (View)</th>
                                        <th class="text-center">Quyền Lập phiếu (Create)</th>
                                        <th class="text-center">Quyền Duyệt (Approve)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($warehouses as $wh): ?>
                                        <?php $wid = (int)$wh['id']; ?>
                                        <tr>
                                            <td class="fw-semibold">
                                                <span class="badge bg-light text-dark border me-1"><?= $wh['code'] ?></span>
                                                <?= htmlspecialchars($wh['name']) ?>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input" name="wh_perms[<?= $wid ?>][view]" value="1" checked>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input" name="wh_perms[<?= $wid ?>][create]" value="1" <?= $wid === 1 ? 'checked' : '' ?>>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input" name="wh_perms[<?= $wid ?>][approve]" value="1">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-white">
                    <button type="button" class="btn-clean btn-clean-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn-clean btn-clean-primary px-4 fw-bold">Lưu tài khoản</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2: Chỉnh sửa tài khoản & Phân quyền Kho -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-pencil-square text-primary fs-5"></i>
                    <h6 class="modal-title fw-bold mb-0">Chỉnh sửa thông tin & Phân quyền</h6>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="users.php">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Tên đăng nhập</label>
                            <input type="text" id="edit_username" class="form-control bg-light" readonly>
                            <div class="form-text small" style="font-size: 0.72rem;">* Tên đăng nhập là cố định để bảo toàn nhật ký sổ kho.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Họ và tên <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email liên hệ</label>
                            <input type="email" name="email" id="edit_email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Đổi mật khẩu mới</label>
                            <input type="password" name="new_password" class="form-control" placeholder="Để trống nếu giữ nguyên mật khẩu cũ">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Chức vụ / Vai trò</label>
                            <select name="role" id="edit_role" class="form-select" onchange="handleRoleChange('edit')">
                                <option value="staff">Thủ kho (STAFF) - Tác nghiệp kho được gán</option>
                                <option value="manager">Quản lý (MANAGER) - Duyệt chứng từ 3 miền</option>
                                <option value="auditor">Kiểm kê viên (AUDITOR) - Đếm hàng & đối chiếu</option>
                                <option value="admin">Quản trị viên (ADMIN) - Toàn quyền hệ thống</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Trạng thái tài khoản</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="active">Hoạt động (Active)</option>
                                <option value="inactive">Khóa tạm thời (Inactive)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Phân quyền chi tiết trên từng kho cho user -->
                    <div id="editWarehousePermissions">
                        <label class="form-label small fw-bold text-dark d-flex align-items-center gap-1.5 mb-2">
                            <i class="bi bi-buildings-fill text-primary"></i> Phân quyền thao tác trên từng điểm kho
                        </label>
                        <div class="table-responsive border rounded-3">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light small">
                                    <tr>
                                        <th>Điểm kho</th>
                                        <th class="text-center">Quyền Xem (View)</th>
                                        <th class="text-center">Quyền Lập phiếu (Create)</th>
                                        <th class="text-center">Quyền Duyệt (Approve)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($warehouses as $wh): ?>
                                        <?php $wid = (int)$wh['id']; ?>
                                        <tr>
                                            <td class="fw-semibold">
                                                <span class="badge bg-light text-dark border me-1"><?= $wh['code'] ?></span>
                                                <?= htmlspecialchars($wh['name']) ?>
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input" id="edit_wh_<?= $wid ?>_view" name="wh_perms[<?= $wid ?>][view]" value="1">
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input" id="edit_wh_<?= $wid ?>_create" name="wh_perms[<?= $wid ?>][create]" value="1">
                                            </td>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input" id="edit_wh_<?= $wid ?>_approve" name="wh_perms[<?= $wid ?>][approve]" value="1">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-white">
                    <button type="button" class="btn-clean btn-clean-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn-clean btn-clean-primary px-4 fw-bold">Cập nhật thay đổi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function handleRoleChange(prefix) {
    const roleSelect = document.getElementById(prefix === 'create' ? 'createRoleSelect' : 'edit_role');
    const box = document.getElementById(prefix === 'create' ? 'createWarehousePermissions' : 'editWarehousePermissions');
    if (!roleSelect || !box) return;

    if (roleSelect.value === 'admin' || roleSelect.value === 'manager') {
        box.style.opacity = '0.5';
        box.style.pointerEvents = 'none';
    } else {
        box.style.opacity = '1';
        box.style.pointerEvents = 'auto';
    }
}

function openEditUserModal(user, perms) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_full_name').value = user.full_name;
    document.getElementById('edit_email').value = user.email || '';
    document.getElementById('edit_role').value = user.role;
    document.getElementById('edit_status').value = user.status || 'active';

    // Reset all checkboxes
    const whIds = <?= json_encode(array_column($warehouses, 'id')) ?>;
    whIds.forEach(id => {
        const viewCb = document.getElementById('edit_wh_' + id + '_view');
        const createCb = document.getElementById('edit_wh_' + id + '_create');
        const approveCb = document.getElementById('edit_wh_' + id + '_approve');
        if (viewCb) viewCb.checked = false;
        if (createCb) createCb.checked = false;
        if (approveCb) approveCb.checked = false;

        if (perms && perms[id]) {
            if (viewCb) viewCb.checked = parseInt(perms[id].can_view) === 1;
            if (createCb) createCb.checked = parseInt(perms[id].can_create) === 1;
            if (approveCb) approveCb.checked = parseInt(perms[id].can_approve) === 1;
        }
    });

    handleRoleChange('edit');
    const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
    modal.show();
}

function filterUsersTable() {
    const input = document.getElementById('userSearchInput');
    if (!input) return;
    const filter = input.value.toLowerCase();
    const rows = document.querySelectorAll('#usersTable tbody tr');

    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
}

function exportUsersToCSV() {
    nxExportTableToCSV('#usersTable', 'danh_sach_tai_khoan_' + new Date().toISOString().slice(0, 10) + '.csv', { skipLastCol: true });
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
