<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';

use App\Database;
use App\Auth;

Auth::requireLogin();
$pdo = Database::getConnection();
$currentUser = Auth::getCurrentUser();

// Lấy số liệu tồn kho thực tế của 3 kho
$whRows = $pdo->query("
    SELECT w.id, w.code, w.name, w.address,
           COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS total_stock,
           COUNT(DISTINCT sm.item_id) AS sku_count
    FROM warehouses w
    LEFT JOIN stock_movements sm ON sm.warehouse_id = w.id
    GROUP BY w.id
    ORDER BY w.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Lấy top 3 mặt hàng tồn nhiều nhất ở mỗi kho + số lượng chuyển kho gần đây
$whByCode = [];
$grandTotal = 0;
foreach ($whRows as $r) {
    $wId = (int)$r['id'];
    $stTop = $pdo->prepare("
        SELECT i.name, i.code, COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS qty
        FROM stock_movements sm
        JOIN items i ON sm.item_id = i.id
        WHERE sm.warehouse_id = ?
        GROUP BY i.id
        HAVING qty > 0
        ORDER BY qty DESC
        LIMIT 3
    ");
    $stTop->execute([$wId]);
    $r['top_items'] = $stTop->fetchAll(PDO::FETCH_ASSOC);

    $binRow = $pdo->query("SELECT COUNT(*) AS tot, SUM(CASE WHEN item_id IS NOT NULL THEN 1 ELSE 0 END) AS fil FROM warehouse_locations WHERE warehouse_id = $wId")->fetch(PDO::FETCH_ASSOC);
    $r['fill_pct'] = ($binRow && $binRow['tot'] > 0) ? round(($binRow['fil'] / $binRow['tot']) * 100, 1) : 0;

    $whByCode[$r['code']] = $r;
    $grandTotal += (float)$r['total_stock'];
}

$totalV = (int)$pdo->query("SELECT COUNT(*) FROM vouchers")->fetchColumn();
$confV  = (int)$pdo->query("SELECT COUNT(*) FROM vouchers WHERE status = 'CONFIRMED'")->fetchColumn();
$voucherPct = $totalV > 0 ? round(($confV / $totalV) * 100) : 100;

$totalBinsAll = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_locations")->fetchColumn();
$filledBinsAll = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_locations WHERE item_id IS NOT NULL")->fetchColumn();
$binFillPct = $totalBinsAll > 0 ? round(($filledBinsAll / $totalBinsAll) * 100) : 0;

$totalItemsAll = (int)$pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
$lowItemsAll = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT i.id, i.min_stock, COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS qty FROM items i LEFT JOIN stock_movements sm ON sm.item_id = i.id GROUP BY i.id HAVING qty <= i.min_stock)")->fetchColumn();
$safePct = $totalItemsAll > 0 ? round((($totalItemsAll - $lowItemsAll) / $totalItemsAll) * 100) : 100;
$healthScore = round(($voucherPct + $binFillPct + $safePct) / 3);

$recentTransfers = $pdo->query("
    SELECT v.code, w1.code AS src_code, w2.code AS dst_code, v.created_at, v.note
    FROM vouchers v
    LEFT JOIN warehouses w1 ON v.source_warehouse_id = w1.id
    LEFT JOIN warehouses w2 ON v.dest_warehouse_id = w2.id
    WHERE v.type = 'TRANSFER'
    ORDER BY v.id DESC LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Mô hình 3D Kho (Digital Twin) - Logistix WMS';
$activePage = 'digital_twin';
require_once __DIR__ . '/../templates/header.php';
?>

<style>
    .dt-tower {
        position: absolute;
        width: 82px;
        height: 82px;
        transform-style: preserve-3d;
        cursor: pointer;
        transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
    }
    .dt-tower:hover {
        transform: translateZ(12px) scale(1.06);
    }
    .dt-tower-pulse {
        position: absolute;
        inset: -14px;
        border-radius: 50%;
        border: 1.5px solid var(--t-glow, #6366f1);
        opacity: 0.5;
        animation: dtRadarPulse 2.6s ease-out infinite;
        pointer-events: none;
    }
    @keyframes dtRadarPulse {
        0% { transform: scale(0.8); opacity: 0.75; }
        100% { transform: scale(1.45); opacity: 0; }
    }
    .dt-face-top {
        position: absolute;
        inset: 0;
        border-radius: 12px;
        background: var(--t-grad);
        border: 1px solid rgba(255, 255, 255, 0.55);
        box-shadow: inset 0 0 14px rgba(255, 255, 255, 0.25), 0 0 20px var(--t-glow);
        transform: translateZ(var(--t-h, 58px));
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: #fff;
        text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
    }
    .dt-face-front {
        position: absolute;
        left: 0; right: 0; bottom: 0;
        height: var(--t-h, 58px);
        background: var(--t-grad);
        filter: brightness(0.76);
        transform-origin: bottom;
        transform: rotateX(-90deg);
        border-radius: 0 0 10px 10px;
    }
    .dt-face-side {
        position: absolute;
        top: 0; bottom: 0; right: 0;
        width: var(--t-h, 58px);
        background: var(--t-grad);
        filter: brightness(0.58);
        transform-origin: right;
        transform: rotateY(90deg);
        border-radius: 0 10px 10px 0;
    }
    .dt-face-back {
        position: absolute;
        left: 0; right: 0; top: 0;
        height: var(--t-h, 58px);
        background: var(--t-grad);
        filter: brightness(0.62);
        transform-origin: top;
        transform: rotateX(90deg);
    }
    .dt-face-left {
        position: absolute;
        top: 0; bottom: 0; left: 0;
        width: var(--t-h, 58px);
        background: var(--t-grad);
        filter: brightness(0.68);
        transform-origin: left;
        transform: rotateY(-90deg);
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h4 class="fw-bold mb-0">Trung tâm Bản đồ 3D Mạng lưới Kho (Digital Twin)</h4>
        <span class="badge bg-primary rounded-pill px-2.5 py-1"><i class="bi bi-broadcast me-1"></i>LIVE 3D</span>
    </div>
    <div class="d-flex flex-wrap gap-1.5">
        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="setTwinPreset(54, -26, 0.96)">
            <i class="bi bi-aspect-ratio me-1"></i>Góc chuẩn
        </button>
        <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3" onclick="setTwinPreset(22, 0, 0.92)">
            <i class="bi bi-arrows-fullscreen me-1"></i>Nhìn từ trên
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setTwinPreset(68, -42, 1.02)">
            <i class="bi bi-box me-1"></i>Góc thấp 3D
        </button>
        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" id="twinPageSpinBtn" onclick="toggleTwin3DPageSpin()">
            <i class="bi bi-arrow-repeat me-1"></i>Tự xoay 360°
        </button>
        <a href="locations.php" class="btn btn-sm btn-success rounded-pill px-3">
            <i class="bi bi-grid-3x3-gap-fill me-1"></i>Vào Sơ đồ Ô Kệ
        </a>
    </div>
</div>

<!-- 3 Thẻ số liệu trực tiếp của 3 Kho -->
<div class="row g-3 mb-3">
    <?php foreach ($whRows as $wh):
        $pct = $grandTotal > 0 ? round(((float)$wh['total_stock'] / $grandTotal) * 100, 1) : 0;
    ?>
        <div class="col-md-4">
            <div class="metric-box" style="cursor:pointer;" onclick="focusTwinHub('<?= htmlspecialchars($wh['code']) ?>')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="metric-label"><?= htmlspecialchars($wh['code']) ?> &bull; <?= $pct ?>% TỔNG KHO</div>
                        <div class="metric-val"><?= number_format((float)$wh['total_stock']) ?></div>
                        <small class="opacity-75"><?= (int)$wh['sku_count'] ?> mặt hàng đang lưu trữ</small>
                    </div>
                    <div class="metric-icon-box"><i class="bi bi-building-fill"></i></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Bố cục 2 cột tinh gọn: Trái (8 cột) Bản đồ 3D thoáng sạch • Phải (4 cột) Bảng điều khiển Hub -->
<div class="row g-3 align-items-stretch">
    <div class="col-lg-8">
        <div class="clean-card main-primary-card overflow-hidden h-100">
            <div class="clean-card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold small"><i class="bi bi-globe-asia-australia text-primary me-1"></i>Mạng lưới 3D Đa Điểm Kho</span>
                <span class="badge bg-success-subtle text-success rounded-pill"><i class="bi bi-activity me-1"></i>3 Kho trực tuyến</span>
            </div>
            <div class="position-relative" id="twinPageStage" style="height: 490px; overflow: hidden; background: radial-gradient(circle at 50% 40%, #2e2b6b 0%, #17153b 60%, #0d1124 100%); cursor: grab;">
                <!-- 3D Floor (Thoáng rộng, không bị hộp đen che khuất) -->
                <div id="twinPageFloor" style="
                    width: 660px;
                    height: 390px;
                    position: absolute;
                    top: 48%;
                    left: 50%;
                    transform-style: preserve-3d;
                    transform: translate(-50%, -50%) rotateX(52deg) rotateZ(-22deg) scale(0.95);
                    transition: transform 0.14s cubic-bezier(0.22, 1, 0.36, 1);
                    will-change: transform;
                    background:
                        linear-gradient(rgba(165, 180, 252, 0.14) 1px, transparent 1px),
                        linear-gradient(90deg, rgba(165, 180, 252, 0.14) 1px, transparent 1px),
                        rgba(15, 23, 42, 0.85);
                    background-size: 34px 34px;
                    border: 1.5px solid rgba(165, 180, 252, 0.4);
                    border-radius: 20px;
                    box-shadow: 0 36px 85px rgba(0,0,0,0.75), inset 0 0 50px rgba(99, 102, 241, 0.18);
                ">
                    <svg width="660" height="390" class="position-absolute top-0 start-0" style="pointer-events:none;">
                        <defs>
                            <linearGradient id="twinBeam" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#818cf8"/>
                                <stop offset="50%" stop-color="#f472b6"/>
                                <stop offset="100%" stop-color="#22d3ee"/>
                            </linearGradient>
                        </defs>
                        <path d="M 95 75 Q 245 55 340 185 T 555 255 Q 430 335 280 265 T 95 75 Z"
                              fill="rgba(99, 102, 241, 0.1)" stroke="rgba(165, 180, 252, 0.25)" stroke-width="1.5"/>

                        <path id="routeHN_DN" d="M 140 120 Q 255 75 330 195" fill="none" stroke="url(#twinBeam)" stroke-width="3" stroke-dasharray="7 5">
                            <animate attributeName="stroke-dashoffset" from="96" to="0" dur="2.2s" repeatCount="indefinite"/>
                        </path>
                        <path id="routeDN_HCM" d="M 330 195 Q 430 290 525 255" fill="none" stroke="url(#twinBeam)" stroke-width="3" stroke-dasharray="7 5">
                            <animate attributeName="stroke-dashoffset" from="96" to="0" dur="2s" repeatCount="indefinite"/>
                        </path>
                        <path id="routeHN_HCM" d="M 140 120 Q 305 320 525 255" fill="none" stroke="rgba(16, 185, 129, 0.55)" stroke-width="2" stroke-dasharray="5 5">
                            <animate attributeName="stroke-dashoffset" from="0" to="96" dur="3s" repeatCount="indefinite"/>
                        </path>

                        <circle r="5" fill="#38bdf8"><animateMotion dur="2.4s" repeatCount="indefinite"><mpath href="#routeHN_DN"/></animateMotion></circle>
                        <circle r="5" fill="#f472b6"><animateMotion dur="2.2s" repeatCount="indefinite"><mpath href="#routeDN_HCM"/></animateMotion></circle>
                        <circle r="4.5" fill="#10b981"><animateMotion dur="3.4s" repeatCount="indefinite"><mpath href="#routeHN_HCM"/></animateMotion></circle>
                    </svg>

                    <?php
                    $hubConfigs = [
                        'KHO-HN'  => ['left' => 98,  'top' => 76,  'region' => 'MIỀN BẮC',  'grad' => 'linear-gradient(135deg, #6366f1, #8b5cf6)', 'glow' => 'rgba(99,102,241,0.55)', 'h' => 64],
                        'KHO-DN'  => ['left' => 288, 'top' => 152, 'region' => 'MIỀN TRUNG', 'grad' => 'linear-gradient(135deg, #ec4899, #f97316)', 'glow' => 'rgba(236,72,153,0.55)', 'h' => 50],
                        'KHO-HCM' => ['left' => 482, 'top' => 212, 'region' => 'MIỀN NAM',   'grad' => 'linear-gradient(135deg, #06b6d4, #3b82f6)', 'glow' => 'rgba(6,182,212,0.55)',  'h' => 60],
                    ];
                    foreach ($hubConfigs as $code => $cfg):
                        $info = $whByCode[$code] ?? ['id' => 1, 'total_stock' => 0, 'sku_count' => 0, 'name' => $code];
                    ?>
                        <div class="dt-tower"
                             style="left: <?= $cfg['left'] ?>px; top: <?= $cfg['top'] ?>px; --t-grad: <?= $cfg['grad'] ?>; --t-glow: <?= $cfg['glow'] ?>; --t-h: <?= $cfg['h'] ?>px;"
                             onclick="focusTwinHub('<?= $code ?>')"
                             ondblclick="window.location.href='warehouse_hub.php?wh=<?= (int)$info['id'] ?>'">
                            <div class="dt-tower-pulse"></div>
                            <div class="dt-face-top">
                                <span style="font-size: 0.56rem; opacity: 0.85; font-weight: 700;"><?= $cfg['region'] ?></span>
                                <div class="fw-bold" style="font-size: 0.82rem;"><?= $code ?></div>
                                <div style="font-size: 0.68rem;"><?= number_format((float)$info['total_stock']) ?></div>
                            </div>
                            <div class="dt-face-front"></div>
                            <div class="dt-face-side"></div>
                            <div class="dt-face-back"></div>
                            <div class="dt-face-left"></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Thanh gợi ý gọn ở đáy -->
                <div class="position-absolute bottom-0 start-0 end-0 px-3 py-2 d-flex justify-content-between align-items-center" style="background: linear-gradient(to top, rgba(13,17,36,0.9), transparent); pointer-events:none; font-size:0.72rem; color:rgba(226,232,240,0.8);">
                    <span><i class="bi bi-mouse me-1 text-info"></i>Kéo chuột để xoay 360° &bull; Cuộn để Zoom</span>
                    <span>Bấm tháp kho để xem nhanh &bull; Bấm đúp để vào Trung tâm kho</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Cột phải: Bảng điều khiển Hub sạch đẹp -->
    <div class="col-lg-4 d-flex flex-column gap-3">
        <div class="clean-card main-secondary-card">
            <div class="clean-card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold small"><i class="bi bi-building-fill text-primary me-1"></i>Chi tiết Điểm kho</span>
                <span class="badge bg-primary rounded-pill" id="twinHudBadge">KHO-HN</span>
            </div>
            <div class="clean-card-body">
                <h6 class="fw-bold mb-1" id="twinHudTitle">Kho Tổng Miền Bắc (Hà Nội)</h6>
                <div class="small text-muted mb-2.5" id="twinHudMeta">Đang tải dữ liệu...</div>
                <div class="small fw-bold text-uppercase text-muted mb-1.5" style="font-size:0.68rem;">Top mặt hàng tồn nhiều nhất</div>
                <div id="twinHudTopItems" class="d-flex flex-column gap-1.5 mb-3"></div>
                <a href="warehouse_hub.php?wh=1" id="twinHudLink" class="btn btn-primary btn-sm w-100 rounded-pill fw-semibold">
                    <i class="bi bi-building-gear me-1"></i>Mở Trung tâm Điều hành KHO-HN
                </a>
            </div>
        </div>

        <div class="clean-card main-secondary-card flex-grow-1">
            <div class="clean-card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold small"><i class="bi bi-truck text-warning me-1"></i>Điều chuyển gần đây</span>
                <a href="transfer.php" class="small text-decoration-none d-inline-flex align-items-center gap-0.5">Tất cả <i class="bi bi-arrow-right-short fs-6"></i></a>
            </div>
            <div class="clean-card-body d-flex flex-column gap-2">
                <?php foreach ($recentTransfers as $tr): ?>
                    <div class="p-2 rounded-3 border bg-light d-flex justify-content-between align-items-center" style="font-size: 0.76rem; cursor: pointer;" onclick="highlightRoute('<?= htmlspecialchars($tr['src_code'] ?: 'KHO-HN') ?>', '<?= htmlspecialchars($tr['dst_code'] ?: 'KHO-HCM') ?>')">
                        <div>
                            <div class="fw-bold text-primary"><?= htmlspecialchars($tr['src_code'] ?: 'HN') ?> → <?= htmlspecialchars($tr['dst_code'] ?: 'HCM') ?></div>
                            <div class="text-muted font-monospace" style="font-size:0.7rem;"><?= htmlspecialchars($tr['code']) ?></div>
                        </div>
                        <span class="badge bg-success-subtle text-success rounded-pill">Đã chuyển</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Hàng Phân tích Điều hành dưới Bản đồ 3D -->
<div class="row g-3 mt-1">
    <div class="col-lg-7">
        <div class="clean-card main-secondary-card h-100">
            <div class="clean-card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold small"><i class="bi bi-bar-chart-fill text-primary me-1"></i>So sánh Tồn kho &amp; Tỷ lệ Lấp đầy 3 Kho</span>
            </div>
            <div class="clean-card-body">
                <div style="height: 210px;"><canvas id="twinCompareChart"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="clean-card main-secondary-card h-100">
            <div class="clean-card-header d-flex justify-content-between align-items-center">
                <span class="fw-bold small"><i class="bi bi-heart-pulse-fill text-danger me-1"></i>Chỉ số Sức khỏe Chuỗi cung ứng</span>
                <span class="badge bg-success rounded-pill px-2.5"><?= $healthScore ?>/100 Điểm</span>
            </div>
            <div class="clean-card-body d-flex flex-column justify-content-center gap-3">
                <div>
                    <div class="d-flex justify-content-between small fw-semibold mb-1">
                        <span>Độ phủ Tồn kho An toàn</span>
                        <span class="text-success"><?= $safePct ?>%</span>
                    </div>
                    <div class="progress" style="height:9px;"><div class="progress-bar bg-success" style="width:<?= $safePct ?>%"></div></div>
                </div>
                <div>
                    <div class="d-flex justify-content-between small fw-semibold mb-1">
                        <span>Tỷ lệ Lấp đầy Ô kệ Toàn hệ thống</span>
                        <span class="text-primary"><?= $binFillPct ?>%</span>
                    </div>
                    <div class="progress" style="height:9px;"><div class="progress-bar bg-primary" style="width:<?= $binFillPct ?>%"></div></div>
                </div>
                <div>
                    <div class="d-flex justify-content-between small fw-semibold mb-1">
                        <span>Tỷ lệ Hoàn tất &amp; Ghi sổ Chứng từ</span>
                        <span class="text-info"><?= $voucherPct ?>%</span>
                    </div>
                    <div class="progress" style="height:9px;"><div class="progress-bar bg-info" style="width:<?= $voucherPct ?>%"></div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const twinHubData = <?= json_encode($whByCode, JSON_UNESCAPED_UNICODE) ?>;
    let tpRotX = 54, tpRotZ = -26, tpScale = 0.96, tpDrag = false, tpLX = 0, tpLY = 0, tpSpin = null;
    const tpStage = document.getElementById('twinPageStage');
    const tpFloor = document.getElementById('twinPageFloor');

    function renderTwin3DPage() {
        if (tpFloor) tpFloor.style.transform = `translate(-50%, -50%) rotateX(${tpRotX}deg) rotateZ(${tpRotZ}deg) scale(${tpScale})`;
    }
    function setTwinPreset(rx, rz, sc) {
        if (tpSpin) toggleTwin3DPageSpin();
        tpRotX = rx; tpRotZ = rz; tpScale = sc;
        renderTwin3DPage();
    }
    function toggleTwin3DPageSpin() {
        const btn = document.getElementById('twinPageSpinBtn');
        if (tpSpin) {
            clearInterval(tpSpin); tpSpin = null;
            btn?.classList.replace('btn-success', 'btn-primary');
        } else {
            tpSpin = setInterval(() => { tpRotZ = (tpRotZ + 0.5) % 360; renderTwin3DPage(); }, 28);
            btn?.classList.replace('btn-primary', 'btn-success');
        }
    }
    function focusTwinHub(code) {
        const info = twinHubData[code];
        if (!info) return;
        document.getElementById('twinHudBadge').textContent = code;
        document.getElementById('twinHudTitle').textContent = info.name;
        document.getElementById('twinHudMeta').textContent = `Tồn thực tế: ${Number(info.total_stock).toLocaleString('en-US')} đơn vị • ${info.sku_count} mặt hàng`;
        document.getElementById('twinHudLink').href = 'warehouse_hub.php?wh=' + info.id;
        document.getElementById('twinHudLink').innerHTML = `<i class="bi bi-building-gear me-1"></i>Mở Trung tâm Điều hành ${code}`;
        const topBox = document.getElementById('twinHudTopItems');
        topBox.innerHTML = (info.top_items || []).map(it =>
            `<div class="d-flex justify-content-between align-items-center px-2.5 py-1.5 rounded-3 border bg-light" style="font-size:0.76rem;">
                <span class="text-truncate me-2 fw-semibold">${it.name}</span>
                <span class="badge bg-primary-subtle text-primary">${Number(it.qty).toLocaleString('en-US')}</span>
            </div>`
        ).join('');
        if (window.showNxToast) window.showNxToast('Đang xem ' + code + ': ' + Number(info.total_stock).toLocaleString('en-US') + ' đơn vị', 'info');
    }

    if (tpStage) {
        tpStage.addEventListener('mousedown', (e) => { tpDrag = true; tpLX = e.clientX; tpLY = e.clientY; tpStage.style.cursor = 'grabbing'; });
        window.addEventListener('mousemove', (e) => {
            if (!tpDrag) return;
            tpRotZ += (e.clientX - tpLX) * 0.45;
            tpRotX = Math.max(18, Math.min(78, tpRotX - (e.clientY - tpLY) * 0.35));
            tpLX = e.clientX; tpLY = e.clientY;
            renderTwin3DPage();
        });
        window.addEventListener('mouseup', () => { if (tpDrag) { tpDrag = false; tpStage.style.cursor = 'grab'; } });
        tpStage.addEventListener('wheel', (e) => {
            e.preventDefault();
            tpScale = Math.max(0.6, Math.min(1.4, tpScale + (e.deltaY < 0 ? 0.06 : -0.06)));
            renderTwin3DPage();
        }, { passive: false });
    }
    function highlightRoute(src, dst) {
        const pairs = [
            ['routeHN_DN', ['KHO-HN', 'KHO-DN']],
            ['routeDN_HCM', ['KHO-DN', 'KHO-HCM']],
            ['routeHN_HCM', ['KHO-HN', 'KHO-HCM']],
        ];
        pairs.forEach(([id, hubs]) => {
            const el = document.getElementById(id);
            if (!el) return;
            const match = hubs.includes(src) && hubs.includes(dst);
            el.setAttribute('stroke-width', match ? '6' : '2');
            el.style.opacity = match ? '1' : '0.25';
        });
        focusTwinHub(src);
        if (window.showNxToast) window.showNxToast(`Đang nổi bật tuyến ${src} → ${dst}`, 'primary');
    }

    document.addEventListener('DOMContentLoaded', () => {
        focusTwinHub('KHO-HN');
        const ctx = document.getElementById('twinCompareChart');
        if (ctx && typeof Chart !== 'undefined') {
            const hubs = Object.values(twinHubData);
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: hubs.map(h => h.code),
                    datasets: [
                        { label: 'Tổng tồn kho (đv)', data: hubs.map(h => Number(h.total_stock)), backgroundColor: ['#6366f1', '#ec4899', '#06b6d4'], borderRadius: 8 },
                        { label: 'Số mặt hàng (SKU)', data: hubs.map(h => Number(h.sku_count) * 20), backgroundColor: 'rgba(16, 185, 129, 0.75)', borderRadius: 8 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        }
    });
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
