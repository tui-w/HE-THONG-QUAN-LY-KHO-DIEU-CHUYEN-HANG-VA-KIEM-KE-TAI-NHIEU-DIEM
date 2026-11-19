<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Seeder.php';

use App\Database;
use App\Auth;
use App\Seeder;

Auth::startSession();
$pdo = Database::getConnection();

// Tự động kiểm tra và seed CSDL nếu chưa có
$userCount = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($userCount === 0) {
    Database::initSchema($pdo);
    Seeder::run($pdo);
}

$error = '';
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

$getRoleLanding = static function (array $u): string {
    return match ($u['role'] ?? '') {
        'manager' => 'vouchers.php?status=DRAFT',
        'staff' => 'vouchers.php',
        'auditor' => 'inventory.php',
        default => 'index.php',
    };
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    try {
        $user = Auth::login($pdo, $username, $password);
        if ($user) {
            header('Location: ' . $getRoleLanding($user));
            exit;
        } else {
            $error = 'Tên đăng nhập hoặc mật khẩu không chính xác!';
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// Xử lý đăng nhập nhanh cho mục đích demo / chấm điểm
if (isset($_GET['quick_login'])) {
    $quickUser = $_GET['quick_login'];
    $validUsers = ['tuoi', 'admin', 'manager', 'kho_hn', 'kho_dn', 'kho_hcm', 'auditor'];
    if (in_array($quickUser, $validUsers, true)) {
        try {
            $u = Auth::login($pdo, $quickUser, '123456');
            header('Location: ' . ($u ? $getRoleLanding($u) : 'index.php'));
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
    <title>Đăng nhập - Logistix WMS</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        body.login-page {
            min-height: 100vh;
            background:
                radial-gradient(circle at 12% 18%, rgba(99, 102, 241, 0.22), transparent 38%),
                radial-gradient(circle at 88% 16%, rgba(236, 72, 153, 0.20), transparent 36%),
                radial-gradient(circle at 50% 92%, rgba(168, 85, 247, 0.16), transparent 42%),
                linear-gradient(135deg, #eef2ff 0%, #f5f3ff 48%, #fdf2f8 100%);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        .login-shell {
            border-radius: 26px;
            border: 1px solid rgba(255, 255, 255, 0.85);
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(18px);
            box-shadow: 0 24px 64px rgba(99, 102, 241, 0.16), 0 6px 20px rgba(236, 72, 153, 0.08);
            overflow: hidden;
        }

        .login-hero-bar {
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 52%, #ec4899 100%);
            padding: 18px 32px;
            color: #fff;
        }

        .login-brand-icon {
            width: 44px;
            height: 44px;
            border-radius: 13px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.32);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: #fff;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.12);
        }

        .login-wms-badge {
            background: #fff;
            color: #6366f1;
            font-weight: 800;
            font-size: 0.72rem;
            padding: 3px 9px;
            border-radius: 999px;
            letter-spacing: 0.04em;
        }

        .login-pill {
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.28);
            color: #fff;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .login-input-wrap {
            display: flex;
            align-items: center;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            background: #f8fafc;
            transition: all 0.2s ease;
            overflow: hidden;
        }

        .login-input-wrap:focus-within {
            border-color: #8b5cf6;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.14);
        }

        .login-input-wrap .input-icon {
            padding: 0 14px;
            color: #8b5cf6;
            font-size: 1.05rem;
        }

        .login-input-wrap input {
            border: 0;
            background: transparent;
            padding: 12px 14px 12px 0;
            width: 100%;
            font-size: 0.94rem;
            color: #0f172a;
            outline: none;
        }

        .btn-login-gradient {
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 55%, #ec4899 100%);
            color: #fff;
            border: 0;
            border-radius: 14px;
            padding: 13px 20px;
            font-weight: 700;
            font-size: 0.96rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 12px 24px rgba(139, 92, 246, 0.28);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .btn-login-gradient:hover {
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 16px 30px rgba(168, 85, 247, 0.36);
        }

        .quick-panel {
            background: linear-gradient(180deg, #faf8ff 0%, #fdf4ff 100%);
            border-left: 1px solid #ede9fe;
        }

        .quick-user-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 13px;
            border-radius: 14px;
            background: #fff;
            border: 1.5px solid #ede9fe;
            text-decoration: none;
            transition: all 0.18s ease;
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.04);
        }

        .quick-user-card:hover {
            border-color: #a855f7;
            transform: translateX(4px);
            box-shadow: 0 8px 20px rgba(139, 92, 246, 0.14);
        }

        .quick-avatar {
            width: 36px;
            height: 36px;
            border-radius: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.78rem;
            color: #fff;
            flex-shrink: 0;
        }

        .wh-mini-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 5px 11px;
            border-radius: 10px;
            background: #f5f3ff;
            color: #5b21b6;
            border: 1px solid #e9d5ff;
        }
    </style>
</head>

<body class="login-page d-flex align-items-center justify-content-center py-4">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-11 col-lg-10 col-xl-9">
                <div class="login-shell">
                    <!-- Thanh thương hiệu đồng bộ với Header bên trong -->
                    <div class="login-hero-bar d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="login-brand-icon">
                                <i class="bi bi-box-seam-fill"></i>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-bold fs-5" style="letter-spacing: -0.02em;">Logistix</span>
                                    <span class="login-wms-badge">WMS</span>
                                </div>
                                <div class="small" style="opacity: 0.9;">Hệ thống Quản lý Kho, Điều chuyển & Kiểm kê Đa
                                    điểm</div>
                            </div>
                        </div>
                        <div class="d-none d-md-flex align-items-center gap-2">
                            <span class="login-pill"><i class="bi bi-geo-alt-fill"></i> 3 Kho Online</span>
                            <span class="login-pill"><i class="bi bi-shield-check"></i> ACID & RBAC</span>
                        </div>
                    </div>

                    <div class="row g-0">
                        <!-- Cột trái: Form Đăng nhập -->
                        <div class="col-md-6 p-4 p-lg-5 bg-white d-flex flex-column justify-content-between">
                            <div>
                                <div class="mb-4">
                                    <h4 class="fw-bold text-slate-900 mb-1" style="letter-spacing: -0.02em;">Chào mừng
                                        trở lại 👋</h4>
                                    <p class="text-slate-500 small mb-0">Đăng nhập tài khoản để truy cập bảng điều khiển
                                        và sổ cái tồn kho.</p>
                                </div>

                                <?php if ($success): ?>
                                    <div class="alert alert-success py-2.5 px-3 small border-0 shadow-xs rounded-3 mb-3"
                                        role="alert">
                                        <i class="bi bi-check-circle-fill me-1 text-success"></i>
                                        <?= htmlspecialchars($success) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($error): ?>
                                    <div class="alert alert-danger py-2.5 px-3 small border-0 shadow-xs rounded-3 mb-3"
                                        role="alert">
                                        <i class="bi bi-exclamation-triangle-fill me-1 text-danger"></i>
                                        <?= htmlspecialchars($error) ?>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="login.php">
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-slate-700 mb-1.5">Tên đăng
                                            nhập</label>
                                        <div class="login-input-wrap">
                                            <span class="input-icon"><i class="bi bi-person-fill"></i></span>
                                            <input type="text" name="username"
                                                placeholder="tuoi, admin, manager, kho_hn..." required autofocus>
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label small fw-bold text-slate-700 mb-1.5">Mật khẩu</label>
                                        <div class="login-input-wrap">
                                            <span class="input-icon"><i class="bi bi-lock-fill"></i></span>
                                            <input type="password" name="password" placeholder="Mặc định: 123456"
                                                required>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn-login-gradient w-100">
                                        <span>Đăng nhập hệ thống</span>
                                        <i class="bi bi-arrow-right-circle-fill"></i>
                                    </button>
                                </form>

                                <div class="d-flex flex-wrap gap-2 mt-4">
                                    <span class="wh-mini-badge"><i class="bi bi-building"></i> KHO-HN</span>
                                    <span class="wh-mini-badge"><i class="bi bi-building"></i> KHO-DN</span>
                                    <span class="wh-mini-badge"><i class="bi bi-building"></i> KHO-HCM</span>
                                </div>
                            </div>

                            <div class="mt-4 pt-3 border-top text-center">
                                <span class="text-muted small">Chưa có tài khoản trên hệ thống?</span>
                                <a href="register.php" class="small fw-bold text-decoration-none ms-1"
                                    style="color: #7c3aed;">Đăng ký ngay</a>
                            </div>
                        </div>

                        <!-- Cột phải: Đăng nhập nhanh 1-Click phục vụ chấm điểm / demo -->
                        <div class="col-md-6 p-4 p-lg-5 quick-panel d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-lightning-charge-fill fs-5" style="color: #9333ea;"></i>
                                        <h6 class="fw-bold text-slate-900 mb-0">Đăng nhập nhanh (1 Click)</h6>
                                    </div>
                                    <span class="badge rounded-pill"
                                        style="background: #f3e8ff; color: #7e22ce; font-size: 0.68rem;">Server
                                        RBAC</span>
                                </div>
                                <p class="text-slate-500 mb-3" style="font-size: 0.78rem;">
                                    Chọn nhanh vai trò để kiểm thử phân quyền theo từng điểm kho:
                                </p>

                                <div class="d-flex flex-column gap-2">
                                    <a href="login.php?quick_login=tuoi" class="quick-user-card">
                                        <div class="position-relative flex-shrink-0" style="width: 38px; height: 38px;">
                                            <div class="rounded-circle overflow-hidden w-100 h-100" style="border: 2px solid #f472b6; box-shadow: 0 3px 10px rgba(236, 72, 153, 0.28);">
                                                <img src="assets/img/admin-avatar.jpg" alt="Đào Thị Tươi" style="width: 100%; height: 100%; object-fit: cover; object-position: center 18%; transform: scale(1.25); display: block;">
                                            </div>
                                            <span class="position-absolute bottom-0 end-0 rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 15px; height: 15px; font-size: 0.5rem; background: linear-gradient(135deg, #ec4899, #8b5cf6); border: 1.5px solid #fff;"><i class="bi bi-stars"></i></span>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <strong class="text-slate-900 small">Đào Thị Tươi</strong>
                                                <span class="chip chip-rose py-0 px-2"
                                                    style="font-size: 0.66rem;">ADMIN</span>
                                            </div>
                                            <div class="text-slate-500" style="font-size: 0.73rem;">@tuoi • Toàn quyền
                                                hệ thống & CSDL</div>
                                        </div>
                                    </a>

                                    <a href="login.php?quick_login=manager" class="quick-user-card">
                                        <span class="quick-avatar"
                                            style="background: linear-gradient(135deg, #6366f1, #8b5cf6);">NA</span>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <strong class="text-slate-900 small">Nguyễn Văn An</strong>
                                                <span class="chip chip-indigo py-0 px-2"
                                                    style="font-size: 0.66rem;">MANAGER</span>
                                            </div>
                                            <div class="text-slate-500" style="font-size: 0.73rem;">Phê duyệt phiếu &
                                                điều chuyển cả 3 kho</div>
                                        </div>
                                    </a>

                                    <a href="login.php?quick_login=kho_hn" class="quick-user-card">
                                        <span class="quick-avatar"
                                            style="background: linear-gradient(135deg, #10b981, #059669);">HN</span>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <strong class="text-slate-900 small">Trần Đức Minh</strong>
                                                <span class="chip chip-success py-0 px-2"
                                                    style="font-size: 0.66rem;">STAFF</span>
                                            </div>
                                            <div class="text-slate-500" style="font-size: 0.73rem;">Chỉ thao tác tại Kho
                                                Hà Nội (KHO-HN)</div>
                                        </div>
                                    </a>

                                    <a href="login.php?quick_login=kho_dn" class="quick-user-card">
                                        <span class="quick-avatar"
                                            style="background: linear-gradient(135deg, #14b8a6, #0d9488);">ĐN</span>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <strong class="text-slate-900 small">Hoàng Minh Tuấn</strong>
                                                <span class="chip chip-success py-0 px-2"
                                                    style="font-size: 0.66rem;">STAFF</span>
                                            </div>
                                            <div class="text-slate-500" style="font-size: 0.73rem;">Chỉ thao tác tại Kho
                                                Đà Nẵng (KHO-DN)</div>
                                        </div>
                                    </a>

                                    <a href="login.php?quick_login=kho_hcm" class="quick-user-card">
                                        <span class="quick-avatar"
                                            style="background: linear-gradient(135deg, #06b6d4, #0284c7);">SG</span>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <strong class="text-slate-900 small">Phạm Thùy Linh</strong>
                                                <span class="chip chip-success py-0 px-2"
                                                    style="font-size: 0.66rem;">STAFF</span>
                                            </div>
                                            <div class="text-slate-500" style="font-size: 0.73rem;">Chỉ thao tác tại Kho
                                                TP.HCM (KHO-HCM)</div>
                                        </div>
                                    </a>

                                    <a href="login.php?quick_login=auditor" class="quick-user-card">
                                        <span class="quick-avatar"
                                            style="background: linear-gradient(135deg, #a855f7, #7c3aed);">LH</span>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <strong class="text-slate-900 small">Lê Đình Huy</strong>
                                                <span class="chip chip-purple py-0 px-2"
                                                    style="font-size: 0.66rem;">AUDITOR</span>
                                            </div>
                                            <div class="text-slate-500" style="font-size: 0.73rem;">Kiểm kê & đối soát
                                                tồn kho thực tế</div>
                                        </div>
                                    </a>
                                </div>

                                <div class="mt-3 pt-2 text-center text-slate-400" style="font-size: 0.72rem;">
                                    <i class="bi bi-shield-lock-fill me-1" style="color: #8b5cf6;"></i>
                                    Bảo mật bởi mã hóa BCRYPT & Xác thực phiên máy chủ.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>

</html>