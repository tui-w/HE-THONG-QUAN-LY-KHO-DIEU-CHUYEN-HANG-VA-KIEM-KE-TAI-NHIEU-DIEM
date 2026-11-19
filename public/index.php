<?php
declare(strict_types=1);

$pageTitle = 'Tổng quan hệ thống';
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../src/StockService.php';
require_once __DIR__ . '/../src/ReportService.php';

use App\StockService;
use App\ReportService;

// Thống kê KPI
$totalWarehouses = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE status = 'active'")->fetchColumn();
$totalItems = (int)$pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
$totalMovements = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
$totalStock = (int)$pdo->query("SELECT COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) FROM stock_movements")->fetchColumn();
$totalCashFlow = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN type = 'RECEIVE' THEN amount ELSE -amount END), 0) FROM payments WHERE status = 'PAID'")->fetchColumn();
$totalPartners = (int)$pdo->query("SELECT COUNT(*) FROM partners")->fetchColumn();

// Thống kê tồn theo từng kho phục vụ Chart.js
$whStats = $pdo->query("
    SELECT 
        w.id,
        w.code,
        w.name,
        COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS total_qty
    FROM warehouses w
    LEFT JOIN stock_movements sm ON sm.warehouse_id = w.id
    GROUP BY w.id
    ORDER BY w.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Thống kê theo danh mục mặt hàng phục vụ Chart.js
$catStats = $pdo->query("
    SELECT 
        c.name AS category_name,
        COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS total_qty
    FROM item_categories c
    JOIN items i ON i.category_id = c.id
    LEFT JOIN stock_movements sm ON sm.item_id = i.id
    GROUP BY c.id
    ORDER BY total_qty DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách hàng tồn thấp (dưới min_stock)
$lowStockItems = $pdo->query("
    SELECT 
        w.name AS warehouse_name,
        w.code AS warehouse_code,
        i.code AS item_code,
        i.name AS item_name,
        i.unit,
        i.min_stock,
        COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS current_stock
    FROM items i
    CROSS JOIN warehouses w
    LEFT JOIN stock_movements sm ON sm.item_id = i.id AND sm.warehouse_id = w.id
    GROUP BY w.id, i.id
    HAVING current_stock < i.min_stock
    ORDER BY current_stock ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// 32 Dòng phát sinh gần nhất trong Sổ phát sinh (Phân trang 8 dòng / trang = 4 trang)
$recentMovements = ReportService::getLedgerMovements($pdo, ['limit' => 32]);
?>

<!-- Top Bar Gọn Gàng - Không chữ thừa -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold text-slate-900 mb-0" style="letter-spacing: -0.02em;">Bảng điều khiển</h4>
        <span class="chip chip-success py-0.5 px-2"><span class="live-pulse-dot me-1"></span>Trực tuyến</span>
        <a href="reports.php?tab=ledger&export=csv" class="btn btn-sm btn-light border text-primary fw-semibold py-0.5 px-2.5 rounded-pill" style="font-size: 0.74rem;" title="Tải nhanh toàn bộ sổ cái phát sinh ra file CSV">
            <i class="bi bi-download me-1"></i>Xuất Sổ cái
        </a>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="quick_voucher.php" class="btn-clean btn-clean-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Lập chứng từ
        </a>
        <a href="quick_transfer.php" class="btn-clean btn-clean-secondary btn-sm">
            <i class="bi bi-arrow-left-right text-primary"></i> Điều chuyển
        </a>
        <a href="quick_audit.php" class="btn-clean btn-clean-secondary btn-sm">
            <i class="bi bi-clipboard2-check text-info"></i> Kiểm kê
        </a>
    </div>
</div>

<!-- 4 KPI Metric Cards - Tinh gọn, rõ số liệu, không chữ rườm rà -->
<div class="row g-2.5 mb-3">
    <div class="col-6 col-xl-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">TỔNG TỒN KHO</div>
                    <div class="metric-val text-primary"><?= number_format((float)$totalStock) ?></div>
                </div>
                <div class="metric-icon-box bg-primary-light text-primary">
                    <i class="bi bi-box-seam-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">MẶT HÀNG</div>
                    <div class="metric-val"><?= number_format((float)$totalItems) ?></div>
                </div>
                <div class="metric-icon-box bg-purple-subtle text-purple">
                    <i class="bi bi-layers-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">DÒNG PHÁT SINH</div>
                    <div class="metric-val"><?= number_format((float)$totalMovements) ?></div>
                </div>
                <div class="metric-icon-box bg-info-subtle text-info">
                    <i class="bi bi-journal-code"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="metric-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">ĐIỂM KHO</div>
                    <div class="metric-val text-success"><?= number_format((float)$totalWarehouses) ?></div>
                </div>
                <div class="metric-icon-box bg-success-subtle text-success">
                    <i class="bi bi-buildings-fill"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- BỐ CỤC PHÂN CẤP RÕ RÀNG: MAIN CHÍNH (9 CỘT - RỘNG 75% & NỔI BẬT) vs MAIN PHỤ (3 CỘT - GỌN 25%) -->
<div class="row g-3 align-items-start">
    <!-- MAIN CHÍNH (Chiếm 75% không gian): Bảng Giao dịch & Phát sinh Sổ cái -->
    <div class="col-xl-9 col-lg-8">
        <div class="clean-card main-primary-card overflow-hidden">
            <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h6 class="fw-bold text-slate-900 mb-0">Nhật ký phát sinh sổ cái thời gian thực</h6>
                </div>

                <!-- Thanh Tìm kiếm & Tab Lọc nhanh gọn trên 1 hàng -->
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary active btn-dash-filter" data-type="ALL" onclick="filterDashType('ALL', this)">Tất cả</button>
                        <button type="button" class="btn btn-outline-primary btn-dash-filter" data-type="IN" onclick="filterDashType('IN', this)">Nhập</button>
                        <button type="button" class="btn btn-outline-primary btn-dash-filter" data-type="OUT" onclick="filterDashType('OUT', this)">Xuất</button>
                        <button type="button" class="btn btn-outline-primary btn-dash-filter" data-type="TRANSFER" onclick="filterDashType('TRANSFER', this)">Chuyển</button>
                    </div>

                    <div class="input-group input-group-sm" style="width: 210px;">
                        <span class="input-group-text bg-white border-end-0 text-slate-400">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" id="dashboardSearch" class="form-control border-start-0 ps-0" placeholder="Tìm mã GD, mặt hàng..." onkeyup="filterDashboardTable()">
                    </div>

                    <a href="reports.php?tab=ledger" class="btn btn-sm btn-light border fw-semibold text-primary">
                        Sổ cái &rarr;
                    </a>
                </div>
            </div>

            <div class="table-responsive" data-page-size="8">
                <table class="table-clean align-middle mb-0" id="dashboardMovementsTable">
                    <thead>
                        <tr>
                            <th class="ps-3">Mã GD</th>
                            <th>Kho</th>
                            <th>Nghiệp vụ</th>
                            <th>Tên mặt hàng</th>
                            <th class="text-center">Biến động</th>
                            <th class="text-end pe-3">Tồn sau</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentMovements as $mv): 
                            $rawType = $mv['movement_type'];
                            $typeChip = match ($rawType) {
                                'IN' => 'chip-success',
                                'OUT' => 'chip-danger',
                                'TRANSFER_OUT', 'TRANSFER_IN' => 'chip-primary',
                                default => 'chip-neutral',
                            };
                            $typeText = match ($rawType) {
                                'IN' => 'Nhập kho',
                                'OUT' => 'Xuất kho',
                                'TRANSFER_OUT' => 'Xuất chuyển',
                                'TRANSFER_IN' => 'Nhập chuyển',
                                default => 'Điều chỉnh',
                            };
                            $filterGroup = match ($rawType) {
                                'IN' => 'IN',
                                'OUT' => 'OUT',
                                'TRANSFER_OUT', 'TRANSFER_IN' => 'TRANSFER',
                                default => 'ADJUST',
                            };
                        ?>
                            <tr class="dash-row" data-type="<?= $filterGroup ?>" data-keyword="<?= strtolower(htmlspecialchars($mv['movement_code'] . ' ' . $mv['warehouse_code'] . ' ' . $mv['item_name'])) ?>">
                                <td class="ps-3">
                                    <span class="text-secondary font-monospace small fw-semibold"><?= htmlspecialchars($mv['movement_code']) ?></span>
                                </td>
                                <td><span class="chip chip-neutral"><?= htmlspecialchars($mv['warehouse_code']) ?></span></td>
                                <td><span class="chip <?= $typeChip ?>"><?= $typeText ?></span></td>
                                <td>
                                    <div class="text-dark fw-semibold text-truncate" style="max-width: 340px;" title="<?= htmlspecialchars($mv['item_name']) ?>">
                                        <?= htmlspecialchars($mv['item_name']) ?>
                                    </div>
                                </td>
                                <td class="text-center fw-bold <?= $mv['quantity_in'] > 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= $mv['quantity_in'] > 0 ? '+' . $mv['quantity_in'] : '-' . $mv['quantity_out'] ?>
                                </td>
                                <td class="text-end pe-3 fw-bold text-dark"><?= number_format((float)$mv['balance_after']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Bento Row cân bằng 2 cột dưới bảng chính (Xóa khoảng trắng thừa bên dưới) -->
        <div class="row g-3 mt-1">
            <div class="col-md-6">
                <div class="clean-card main-secondary-card h-100">
                    <div class="clean-card-header d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-slate-800 small d-flex align-items-center gap-1.5">
                            <i class="bi bi-bar-chart-steps text-primary"></i> Nhóm ngành hàng
                        </span>
                        <a href="reports.php" class="text-decoration-none text-primary fw-semibold d-inline-flex align-items-center gap-0.5" style="font-size: 0.72rem;">Báo cáo <i class="bi bi-arrow-right-short fs-6"></i></a>
                    </div>
                    <div class="clean-card-body p-3">
                        <div style="height: 245px;">
                            <canvas id="catChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="clean-card main-secondary-card h-100">
                    <div class="clean-card-header d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-slate-800 small d-flex align-items-center gap-1.5">
                            <i class="bi bi-shield-exclamation text-warning"></i> Định mức an toàn
                        </span>
                        <div class="d-flex align-items-center gap-2">
                            <?php if (empty($lowStockItems)): ?>
                                <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1" style="font-size: 0.68rem;">100% Đạt</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger rounded-pill px-2 py-1" style="font-size: 0.68rem;"><?= count($lowStockItems) ?> thấp</span>
                            <?php endif; ?>
                            <a href="forecast.php" class="text-decoration-none text-primary fw-semibold d-inline-flex align-items-center gap-0.5" style="font-size: 0.72rem;">ROP <i class="bi bi-arrow-right-short fs-6"></i></a>
                        </div>
                    </div>
                    <div class="clean-card-body p-3">
                        <?php if (empty($lowStockItems)): ?>
                            <div class="text-center py-4 text-success small fw-semibold">
                                <i class="bi bi-shield-check fs-4 d-block mb-1"></i>Tất cả mặt hàng đều đạt ngưỡng an toàn
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($lowStockItems as $lsi):
                                    $cur = (int)$lsi['current_stock'];
                                    $min = max(1, (int)$lsi['min_stock']);
                                    $pct = min(100, round(($cur / $min) * 100));
                                ?>
                                    <div class="px-3 py-2 rounded-3 border bg-slate-50 d-flex justify-content-between align-items-center gap-2 overflow-hidden">
                                        <div class="flex-grow-1" style="min-width: 0; overflow: hidden;">
                                            <div class="d-flex align-items-center gap-2 mb-1" style="min-width: 0;">
                                                <span class="rounded-circle flex-shrink-0 <?= $cur === 0 ? 'bg-danger' : 'bg-warning' ?>" style="width: 7px; height: 7px;"></span>
                                                <span class="fw-semibold text-slate-800 text-truncate d-block" style="font-size: 0.75rem;" title="<?= htmlspecialchars($lsi['item_name']) ?>"><?= htmlspecialchars($lsi['item_name']) ?></span>
                                            </div>
                                            <div class="progress" style="height: 4px; background: #e2e8f0;">
                                                <div class="progress-bar <?= $cur === 0 ? 'bg-danger' : 'bg-warning' ?>" style="width: <?= max(4, $pct) ?>%;"></div>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-1.5 flex-shrink-0">
                                            <span class="badge bg-danger-subtle text-danger rounded-pill px-2 py-1 font-monospace" style="font-size: 0.68rem;"><?= $cur ?>/<?= $lsi['min_stock'] ?></span>
                                            <a href="vouchers.php" class="btn btn-xs btn-outline-primary rounded-pill py-0 px-2 text-nowrap d-inline-flex align-items-center gap-0.5" style="font-size: 0.68rem;" title="Nhập bổ sung"><i class="bi bi-plus-lg"></i> Nhập</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN PHỤ (Thu gọn 25% không gian bên phải): Thống kê & Biểu đồ phụ -->
    <div class="col-xl-3 col-lg-4">
        <!-- Bản đồ Mạng lưới Điều chuyển Việt Nam (SVG Animated Map) -->
        <div class="clean-card main-secondary-card mb-3">
            <div class="clean-card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold text-slate-700 small"><i class="bi bi-geo-alt-fill text-danger me-1"></i>Bản đồ Điều chuyển VN</span>
                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none small" style="font-size:0.7rem;" onclick="filterDashHub('', this)">Tất cả</button>
            </div>
            <div class="clean-card-body p-2">
                <div class="vn-map-card-body">
                    <svg viewBox="0 0 240 190" class="w-100" style="height:165px;display:block;">
                        <defs>
                            <linearGradient id="vnRouteGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#6366f1"/>
                                <stop offset="50%" stop-color="#ec4899"/>
                                <stop offset="100%" stop-color="#06b6d4"/>
                            </linearGradient>
                        </defs>
                        <!-- Stylized Vietnam Silhouette Curve -->
                        <path d="M 78 16 C 95 14, 118 22, 112 42 C 106 58, 130 74, 152 94 C 166 108, 162 132, 146 154 C 132 172, 112 180, 98 172 C 92 162, 108 144, 118 124 C 126 104, 110 82, 86 62 C 68 46, 66 24, 78 16 Z"
                              fill="rgba(99, 102, 241, 0.14)" stroke="rgba(99, 102, 241, 0.45)" stroke-width="1.8"/>
                        <!-- Animated Cargo Transfer Routes -->
                        <path d="M 92 34 Q 145 55 146 98" fill="none" stroke="url(#vnRouteGrad)" stroke-width="2.4" stroke-dasharray="6 5">
                            <animate attributeName="stroke-dashoffset" from="44" to="0" dur="1.8s" repeatCount="indefinite"/>
                        </path>
                        <path d="M 146 98 Q 146 136 116 156" fill="none" stroke="url(#vnRouteGrad)" stroke-width="2.4" stroke-dasharray="6 5">
                            <animate attributeName="stroke-dashoffset" from="44" to="0" dur="1.6s" repeatCount="indefinite"/>
                        </path>
                        <path d="M 92 34 Q 58 98 116 156" fill="none" stroke="rgba(16, 185, 129, 0.7)" stroke-width="1.8" stroke-dasharray="5 5">
                            <animate attributeName="stroke-dashoffset" from="0" to="40" dur="2.6s" repeatCount="indefinite"/>
                        </path>
                        <!-- Hub Nodes -->
                        <g style="cursor:pointer;" onclick="filterDashHub('KHO-HN', this)">
                            <circle cx="92" cy="34" r="11" fill="rgba(99,102,241,0.25)"><animate attributeName="r" values="8;14;8" dur="2.2s" repeatCount="indefinite"/></circle>
                            <circle cx="92" cy="34" r="5.5" fill="#6366f1" stroke="#fff" stroke-width="1.8"/>
                            <text x="108" y="37" font-size="10" font-weight="700" fill="currentColor">HN</text>
                        </g>
                        <g style="cursor:pointer;" onclick="filterDashHub('KHO-DN', this)">
                            <circle cx="146" cy="98" r="11" fill="rgba(236,72,153,0.25)"><animate attributeName="r" values="8;14;8" dur="2.4s" repeatCount="indefinite"/></circle>
                            <circle cx="146" cy="98" r="5.5" fill="#ec4899" stroke="#fff" stroke-width="1.8"/>
                            <text x="160" y="101" font-size="10" font-weight="700" fill="currentColor">ĐN</text>
                        </g>
                        <g style="cursor:pointer;" onclick="filterDashHub('KHO-HCM', this)">
                            <circle cx="116" cy="156" r="11" fill="rgba(6,182,212,0.25)"><animate attributeName="r" values="8;14;8" dur="2s" repeatCount="indefinite"/></circle>
                            <circle cx="116" cy="156" r="5.5" fill="#06b6d4" stroke="#fff" stroke-width="1.8"/>
                            <text x="131" y="159" font-size="10" font-weight="700" fill="currentColor">HCM</text>
                        </g>
                    </svg>
                    <div class="d-flex justify-content-center gap-1.5 mt-1">
                        <span class="badge bg-primary-subtle text-primary vn-hub-pill" onclick="filterDashHub('KHO-HN', this)">KHO-HN</span>
                        <span class="badge bg-danger-subtle text-danger vn-hub-pill" onclick="filterDashHub('KHO-DN', this)">KHO-DN</span>
                        <span class="badge bg-info-subtle text-info vn-hub-pill" onclick="filterDashHub('KHO-HCM', this)">KHO-HCM</span>
                    </div>
                </div>
            </div>
        </div>
        <script>
            function filterDashHub(hubCode, el) {
                const inp = document.getElementById('dashSearchInput');
                if (inp) {
                    inp.value = hubCode;
                    inp.dispatchEvent(new Event('input', { bubbles: true }));
                }
                document.querySelectorAll('.vn-hub-pill').forEach(p => p.classList.remove('active'));
                if (el && el.classList.contains('vn-hub-pill')) el.classList.add('active');
                if (window.showNxToast) {
                    window.showNxToast(hubCode ? 'Đã lọc phát sinh tại ' + hubCode : 'Hiển thị tất cả 3 điểm kho', 'info');
                }
            }
        </script>

        <!-- Thẻ Phụ 1: Tỷ trọng 3 kho -->
        <div class="clean-card main-secondary-card mb-3">
            <div class="clean-card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold text-slate-700 small">Tỷ trọng 3 Kho</span>
                </div>
            <div class="clean-card-body">
                <div style="height: 125px;" class="d-flex align-items-center justify-content-center">
                    <canvas id="whChart"></canvas>
                </div>
                <div class="mt-2 pt-2 border-top d-flex flex-column gap-1" style="font-size: 0.76rem;">
                    <?php 
                    $whColors = ['#4f46e5', '#10b981', '#0ea5e9'];
                    $idx = 0;
                    foreach ($whStats as $wh): 
                        $pct = $totalStock > 0 ? round(($wh['total_qty'] / $totalStock) * 100, 1) : 0;
                    ?>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-secondary d-flex align-items-center gap-1.5">
                                <span style="width: 7px; height: 7px; border-radius: 50%; background: <?= $whColors[$idx % 3] ?>;"></span>
                                <?= htmlspecialchars($wh['code']) ?>
                            </span>
                            <strong class="text-dark"><?= number_format((float)$wh['total_qty']) ?> <span class="text-muted fw-normal">(<?= $pct ?>%)</span></strong>
                        </div>
                    <?php $idx++; endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Biểu đồ tròn tỷ trọng kho (Doughnut)
    const ctxWh = document.getElementById('whChart');
    if (ctxWh) {
        new Chart(ctxWh, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($whStats, 'name')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($whStats, 'total_qty')) ?>,
                    backgroundColor: ['#4f46e5', '#10b981', '#0ea5e9'],
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleFont: { family: "'Plus Jakarta Sans', sans-serif", size: 12, weight: '700' },
                        bodyFont: { family: "'Plus Jakarta Sans', sans-serif", size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        boxPadding: 4
                    }
                },
                cutout: '74%'
            }
        });
    }

    // 2. Biểu đồ ngang phân loại ngành hàng (Horizontal Rounded Bar Chart)
    const ctxCat = document.getElementById('catChart');
    if (ctxCat) {
        new Chart(ctxCat, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($catStats, 'category_name')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($catStats, 'total_qty')) ?>,
                    backgroundColor: ['#6366f1', '#8b5cf6', '#ec4899', '#06b6d4', '#10b981', '#f59e0b', '#3b82f6', '#64748b'],
                    borderRadius: 999,
                    borderSkipped: false,
                    barThickness: 12
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleFont: { family: "'Plus Jakarta Sans', sans-serif", size: 12, weight: '700' },
                        bodyFont: { family: "'Plus Jakarta Sans', sans-serif", size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        boxPadding: 4,
                        callbacks: {
                            label: function(context) {
                                return ' Số lượng tồn: ' + context.parsed.x.toLocaleString() + ' sản phẩm';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9', drawBorder: false },
                        ticks: { font: { family: "'Plus Jakarta Sans', sans-serif", size: 10 }, color: '#94a3b8' }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { font: { family: "'Plus Jakarta Sans', sans-serif", size: 11, weight: '600' }, color: '#475569' }
                    }
                }
            }
        });
    }
});

let currentDashType = 'ALL';

function filterDashType(type, btn) {
    currentDashType = type;
    document.querySelectorAll('.btn-dash-filter').forEach(b => b.classList.remove('active', 'btn-secondary'));
    btn.classList.add('active');
    filterDashboardTable();
}

function filterDashboardTable() {
    const q = (document.getElementById('dashboardSearch')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#dashboardMovementsTable .dash-row');
    rows.forEach(r => {
        const type = r.getAttribute('data-type');
        const kw = r.getAttribute('data-keyword') || '';
        const matchType = (currentDashType === 'ALL' || type === currentDashType);
        const matchKw = (!q || kw.includes(q));
        r.style.display = (matchType && matchKw) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
