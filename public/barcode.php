<?php
declare(strict_types=1);

$pageTitle = 'In tem nhãn Barcode & Mã QR Code';
require_once __DIR__ . '/../templates/header.php';

// Lấy danh sách sản phẩm và nhóm hàng
$items = $pdo->query("
    SELECT i.*, c.name AS category_name
    FROM items i
    LEFT JOIN item_categories c ON i.category_id = c.id
    ORDER BY i.code ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Lấy tất cả lô hàng
$batches = $pdo->query("
    SELECT b.*, i.code AS item_code, i.name AS item_name
    FROM batches b
    JOIN items i ON b.item_id = i.id
    ORDER BY b.item_id ASC, b.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Gom nhóm lô theo item_id
$itemBatches = [];
foreach ($batches as $b) {
    $itemBatches[(int)$b['item_id']][] = $b;
}

$selectedItemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : ($items[0]['id'] ?? 1);
$selectedBatchId = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
?>

<!-- Extra Barcode & QR Libraries via CDN -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>

<style>
/* Styling cho giao diện thiết kế và in tem nhãn */
.label-preview-container {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    padding: 18px;
    border-radius: 14px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    align-items: stretch;
}

@media (max-width: 767.98px) {
    .label-preview-container {
        grid-template-columns: 1fr;
    }
}

/* Kiểu tem đơn (Single Thermal / Shipping Label 75x50mm) */
.warehouse-label {
    background: #ffffff;
    color: #0f172a;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 11px 13px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
    page-break-inside: avoid;
    break-inside: avoid;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    font-family: 'Plus Jakarta Sans', Arial, sans-serif;
    width: 100%;
    overflow: hidden;
}

.warehouse-label.size-standard {
    min-height: 188px;
}

.warehouse-label.size-compact {
    min-height: 155px;
    padding: 8px 10px;
}

.warehouse-label.size-large {
    min-height: 225px;
    padding: 14px 16px;
}

.label-header {
    border-bottom: 1px solid #cbd5e1;
    padding-bottom: 4px;
    margin-bottom: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.label-title {
    font-size: 0.82rem;
    font-weight: 800;
    line-height: 1.2;
    color: #0f172a;
    margin-bottom: 2px;
    max-height: 2.4em;
    overflow: hidden;
}

.label-code {
    font-family: monospace;
    font-size: 0.75rem;
    font-weight: 700;
    color: #2563eb;
}

.label-body {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-grow: 1;
}

.label-info {
    font-size: 0.72rem;
    color: #334155;
    line-height: 1.35;
}

.label-barcode-box {
    text-align: center;
    margin-top: 4px;
}

.label-barcode-box svg {
    max-width: 100%;
    height: 38px;
}

.label-qr-box {
    width: 58px;
    height: 58px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 3px;
}

.label-qr-box svg,
.label-qr-box img {
    width: 100% !important;
    height: 100% !important;
    display: block;
}

.warehouse-label.label-page-hidden {
    display: none !important;
}

.label-footer {
    border-top: 1px solid #e2e8f0;
    padding-top: 3px;
    font-size: 0.65rem;
    color: #64748b;
    display: flex;
    justify-content: space-between;
}

/* ==========================================================================
   CSS DÀNH RIÊNG CHO LỆNH IN (PRINT MODE)
   Ẩn toàn bộ thanh điều hướng, sidebar, nút bấm để in tem nhãn chuẩn xác
   ========================================================================== */
@media print {
    body {
        background: #ffffff !important;
        color: #000000 !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    .app-navbar,
    .sidebar,
    .footer,
    .no-print,
    .btn,
    .btn-clean,
    .card-header,
    .clean-card-header,
    .toast-container-custom,
    #sidebarMenu {
        display: none !important;
    }

    main {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        max-width: 100% !important;
    }

    .container-fluid {
        padding: 0 !important;
        margin: 0 !important;
        max-width: 100% !important;
    }

    .col-lg-8 {
        width: 100% !important;
        flex: 0 0 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
    }

    .clean-card {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        background: transparent !important;
    }

    .clean-card .p-4 {
        padding: 0 !important;
    }

    .label-preview-container {
        background: transparent !important;
        padding: 0 !important;
        gap: 12px !important;
        border: none !important;
        display: flex !important;
        flex-wrap: wrap !important;
    }

    .warehouse-label {
        border: 1px solid #000000 !important;
        box-shadow: none !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        margin-bottom: 8px !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .warehouse-label.label-page-hidden {
        display: flex !important;
    }
}
</style>

<!-- Header Top Banner Gọn Gàng -->
<div class="d-flex justify-content-between align-items-center gap-2 mb-3 no-print flex-wrap">
    <h4 class="fw-bold text-slate-900 mb-0">In tem nhãn Barcode & QR Code</h4>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn-clean btn-clean-secondary btn-sm" onclick="testCurrentBarcodeScan()">
            <i class="bi bi-qr-code-scan text-primary"></i> Quét thử tem này
        </button>
        <button type="button" class="btn-clean btn-clean-secondary btn-sm" onclick="downloadCurrentBarcodePng()">
            <i class="bi bi-download text-success"></i> Tải ảnh tem PNG
        </button>
        <button type="button" class="btn-clean btn-clean-primary btn-sm" onclick="window.print()">
            <i class="bi bi-printer-fill"></i> In tem nhãn (Print)
        </button>
        <a href="items.php" class="btn-clean btn-clean-secondary btn-sm">
            <i class="bi bi-boxes"></i> Danh mục hàng
        </a>
    </div>
</div>

<div class="row g-3 align-items-start">
    <!-- Cột trái: Bộ điều khiển & Tìm nhanh sản phẩm -->
    <div class="col-xl-4 col-lg-5 no-print">
        <div class="clean-card main-secondary-card mb-3">
            <div class="clean-card-header">
                <div class="fw-bold text-slate-800 small">
                    <i class="bi bi-sliders2 me-1 text-primary"></i> Cấu hình tem nhãn
                </div>
            </div>
            <div class="clean-card-body p-3">
                <!-- Tìm kiếm nhanh & Chọn Mặt hàng -->
                <div class="mb-3">
                    <label class="form-label small fw-bold text-slate-700 mb-1">Tìm & Chọn mặt hàng</label>
                    <div class="input-group input-group-sm mb-1.5" style="border-radius: 10px; overflow: hidden; border: 1px solid #cbd5e1; background: #fff;">
                        <span class="input-group-text bg-white border-0 text-slate-400 ps-2.5 pe-1.5"><i class="bi bi-search"></i></span>
                        <input type="text" id="barcodeItemFilterInput" class="form-control border-0 shadow-none ps-0 pe-2" style="font-size: 0.8rem; border-radius: 0 !important;" placeholder="Tìm mã hoặc tên SP..." onkeyup="filterBarcodeItems()">
                    </div>
                    <select id="itemSelect" class="form-select form-select-sm" style="border-radius: 10px !important; padding: 0.42rem 2rem 0.42rem 0.7rem !important; font-size: 0.8rem; text-overflow: ellipsis;" onchange="onItemChange()">
                        <?php foreach ($items as $it): ?>
                            <option value="<?= $it['id'] ?>" 
                                    data-code="<?= htmlspecialchars($it['code']) ?>"
                                    data-name="<?= htmlspecialchars($it['name']) ?>"
                                    data-unit="<?= htmlspecialchars($it['unit']) ?>"
                                    data-category="<?= htmlspecialchars($it['category_name']) ?>"
                                    <?= $it['id'] == $selectedItemId ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($it['code']) ?>] <?= htmlspecialchars($it['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Chọn Lô hàng -->
                <div class="mb-3">
                    <label class="form-label small fw-bold text-slate-700 mb-1">Lô hàng</label>
                    <select id="batchSelect" class="form-select form-select-sm" style="border-radius: 10px !important; padding: 0.42rem 2rem 0.42rem 0.7rem !important; font-size: 0.8rem; text-overflow: ellipsis;" onchange="renderLabels()"></select>
                </div>

                <!-- Chọn Điểm kho hiển thị trên nhãn -->
                <div class="mb-3">
                    <label class="form-label small fw-bold text-slate-700 mb-1">Điểm kho</label>
                    <select id="whSelect" class="form-select form-select-sm" style="border-radius: 10px !important; padding: 0.42rem 2rem 0.42rem 0.7rem !important; font-size: 0.8rem; text-overflow: ellipsis;" onchange="renderLabels()">
                        <option value="Kho Tổng Miền Bắc (Hà Nội)">Kho Hà Nội (KHO-HN)</option>
                        <option value="Kho Trung Chuyển (Đà Nẵng)">Kho Đà Nẵng (KHO-DN)</option>
                        <option value="Kho Phân Phối (TP.HCM)">Kho TP.HCM (KHO-HCM)</option>
                    </select>
                </div>

                <!-- Khổ tem & Số lượng -->
                <div class="row g-2 mb-2">
                    <div class="col-7">
                        <label class="form-label small fw-bold text-slate-700 mb-1">Kích cỡ tem</label>
                        <select id="sizeSelect" class="form-select form-select-sm" style="border-radius: 10px !important; padding: 0.42rem 1.8rem 0.42rem 0.65rem !important; font-size: 0.8rem; text-overflow: ellipsis;" onchange="renderLabels()">
                            <option value="size-standard">75 x 50 mm</option>
                            <option value="size-compact">50 x 30 mm</option>
                            <option value="size-large">100 x 75 mm</option>
                        </select>
                    </div>
                    <div class="col-5">
                        <label class="form-label small fw-bold text-slate-700 mb-1">Số lượng</label>
                        <input type="number" id="labelCount" class="form-control form-control-sm text-center fw-bold" style="border-radius: 10px !important; padding: 0.42rem 0.5rem !important; font-size: 0.82rem;" value="40" min="1" max="100" onchange="renderLabels()">
                    </div>
                </div>
                <div class="row g-1.5 mb-3">
                    <div class="col-3"><button type="button" class="btn btn-xs btn-light border w-100 py-1 rounded-3 fw-semibold" style="font-size: 0.72rem;" onclick="setLabelCount(4)">4 tem</button></div>
                    <div class="col-3"><button type="button" class="btn btn-xs btn-light border w-100 py-1 rounded-3 fw-semibold" style="font-size: 0.72rem;" onclick="setLabelCount(12)">12 tem</button></div>
                    <div class="col-3"><button type="button" class="btn btn-xs btn-light border w-100 py-1 rounded-3 fw-semibold" style="font-size: 0.72rem;" onclick="setLabelCount(20)">20 tem</button></div>
                    <div class="col-3"><button type="button" class="btn btn-xs btn-light border w-100 py-1 rounded-3 fw-semibold" style="font-size: 0.72rem;" onclick="setLabelCount(40)">40 tem</button></div>
                </div>

                <!-- Tùy chọn hiển thị trên tem -->
                <div class="bg-slate-50 border rounded-3 p-2.5 d-flex flex-column gap-1.5">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="form-check-label small text-slate-700 fw-medium mb-0" for="showBarcodeCheck"><i class="bi bi-upc me-1.5 text-primary"></i>Barcode 1D</label>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="showBarcodeCheck" checked onchange="renderLabels()">
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="form-check-label small text-slate-700 fw-medium mb-0" for="showQrCheck"><i class="bi bi-qr-code me-1.5 text-primary"></i>QR Code 2D</label>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="showQrCheck" checked onchange="renderLabels()">
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="form-check-label small text-slate-700 fw-medium mb-0" for="showDateCheck"><i class="bi bi-calendar-event me-1.5 text-info"></i>Hạn dùng (NSX/HSD)</label>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="showDateCheck" checked onchange="renderLabels()">
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="form-check-label small text-slate-700 fw-medium mb-0" for="showWhCheck"><i class="bi bi-building me-1.5 text-success"></i>Tên điểm kho</label>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" id="showWhCheck" checked onchange="renderLabels()">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cột phải: Vùng xem trước bản in chính -->
    <div class="col-xl-8 col-lg-7">
        <div class="clean-card main-primary-card">
            <div class="clean-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="fw-bold text-slate-900">
                    <i class="bi bi-eye-fill me-1.5 text-primary"></i> Bản xem trước tem nhãn (Live Print Preview)
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge bg-light text-secondary border small" id="previewCountBadge">40 tem</span>
                    <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i> In ngay
                    </button>
                </div>
            </div>
            <div class="p-3">
                <div class="label-preview-container" id="labelPreviewContainer">
                    <!-- Labels dynamically injected via Javascript -->
                </div>
                <div class="d-flex flex-column align-items-center gap-2 pt-3 mt-2 border-top no-print" id="labelPagerBar">
                    <div class="d-flex align-items-center justify-content-center gap-1.5 flex-wrap" id="labelPagerGroup">
                        <button type="button" class="btn btn-sm btn-light border rounded-circle d-inline-flex align-items-center justify-content-center shadow-xs" style="width: 32px; height: 32px;" id="labelPrevBtn" onclick="changeLabelPage(-1)" title="Trang trước">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <div class="d-flex align-items-center gap-1 flex-wrap justify-content-center" id="labelPageNumbers"></div>
                        <button type="button" class="btn btn-sm btn-light border rounded-circle d-inline-flex align-items-center justify-content-center shadow-xs" style="width: 32px; height: 32px;" id="labelNextBtn" onclick="changeLabelPage(1)" title="Trang sau">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                    <span class="text-muted" style="font-size: 0.74rem;" id="labelRangeInfo">Hiển thị tem 1 - 4 / 40</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Dữ liệu lô hàng truyền từ PHP
const itemBatchesData = <?= json_encode($itemBatches) ?>;

function filterBarcodeItems() {
    const norm = s => (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd');
    const q = norm(document.getElementById('barcodeItemFilterInput')?.value || '').trim();
    const sel = document.getElementById('itemSelect');
    if (!sel) return;
    let firstMatch = null;
    Array.from(sel.options).forEach(opt => {
        const match = (q === '' || norm(opt.text).includes(q));
        opt.hidden = !match;
        if (match && !firstMatch) firstMatch = opt;
    });
    if (firstMatch && sel.selectedOptions[0]?.hidden) {
        sel.value = firstMatch.value;
        onItemChange();
    }
}

function setLabelCount(n) {
    document.getElementById('labelCount').value = n;
    renderLabels();
}

function onItemChange() {
    const itemSelect = document.getElementById('itemSelect');
    const itemId = parseInt(itemSelect.value);
    const batchSelect = document.getElementById('batchSelect');
    
    batchSelect.innerHTML = '';
    const batches = itemBatchesData[itemId] || [];

    if (batches.length === 0) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'Mặc định (Không quản lý số lô)';
        opt.dataset.batchNumber = 'DEFAULT';
        opt.dataset.mfgDate = new Date().toISOString().slice(0, 10);
        opt.dataset.expDate = '2028-12-31';
        batchSelect.appendChild(opt);
    } else {
        batches.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.id;
            opt.textContent = `Lô: ${b.batch_number} (HSD: ${b.expiry_date || 'Không có'})`;
            opt.dataset.batchNumber = b.batch_number;
            opt.dataset.mfgDate = b.manufacture_date || '';
            opt.dataset.expDate = b.expiry_date || '';
            batchSelect.appendChild(opt);
        });
    }

    renderLabels();
}

    const LABEL_PER_PAGE = 4;
    let labelPage = 1;

    function getCurrentScanCode() {
        const itemSelect = document.getElementById('itemSelect');
        const selectedOption = itemSelect?.options[itemSelect.selectedIndex];
        if (!selectedOption) return '';
        const itemCode = selectedOption.dataset.code || '';
        const batchSelect = document.getElementById('batchSelect');
        const batchOption = batchSelect?.options[batchSelect.selectedIndex];
        const batchNumber = batchOption ? (batchOption.dataset.batchNumber || '') : '';
        return (batchNumber && batchNumber !== 'DEFAULT') ? `${itemCode}|${batchNumber}` : itemCode;
    }

    function goToLabelPage(p) {
        const total = document.querySelectorAll('#labelPreviewContainer .warehouse-label').length;
        const totalPages = Math.max(1, Math.ceil(total / LABEL_PER_PAGE));
        labelPage = Math.max(1, Math.min(totalPages, p));
        applyLabelPagination();
    }

    function changeLabelPage(delta) {
        goToLabelPage(labelPage + delta);
    }

    function applyLabelPagination() {
        const labels = Array.from(document.querySelectorAll('#labelPreviewContainer .warehouse-label'));
        const totalPages = Math.max(1, Math.ceil(labels.length / LABEL_PER_PAGE));
        if (labelPage > totalPages) labelPage = totalPages;
        const start = (labelPage - 1) * LABEL_PER_PAGE;
        const end = start + LABEL_PER_PAGE;

        labels.forEach((el, idx) => {
            el.classList.toggle('label-page-hidden', idx < start || idx >= end);
        });

        const numsBox = document.getElementById('labelPageNumbers');
        const rangeInfo = document.getElementById('labelRangeInfo');
        const prev = document.getElementById('labelPrevBtn');
        const next = document.getElementById('labelNextBtn');
        if (numsBox) {
            let btns = '';
            let lastWasEllipsis = false;
            for (let p = 1; p <= totalPages; p++) {
                const showPage = (p <= 2 || p >= totalPages - 1 || Math.abs(p - labelPage) <= 1);
                if (!showPage) {
                    if (!lastWasEllipsis) {
                        btns += '<span class="px-1 text-muted small">…</span>';
                        lastWasEllipsis = true;
                    }
                    continue;
                }
                lastWasEllipsis = false;
                const activeCls = p === labelPage
                    ? 'btn-primary text-white fw-bold shadow-sm'
                    : 'btn-light border text-slate-600';
                btns += `<button type="button" class="btn btn-sm rounded-pill px-2.5 ${activeCls}" style="min-width: 32px; height: 32px;" onclick="goToLabelPage(${p})">${p}</button>`;
            }
            numsBox.innerHTML = btns;
        }
        if (rangeInfo) {
            const shownEnd = Math.min(labels.length, end);
            rangeInfo.textContent = labels.length ? `Trang ${labelPage}/${totalPages} • Hiển thị tem ${start + 1} – ${shownEnd} / ${labels.length}` : 'Không có tem nào';
        }
        if (prev) prev.disabled = labelPage <= 1;
        if (next) next.disabled = labelPage >= totalPages;
    }

    function testCurrentBarcodeScan() {
        const code = getCurrentScanCode();
        if (!code) return;
        const modalEl = document.getElementById('liveScannerModal');
        if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
        const inp = document.getElementById('manualScanInput');
        if (inp) inp.value = code;
        if (typeof window.handleUniversalScan === 'function') {
            window.handleUniversalScan(code);
        }
    }

    function downloadCurrentBarcodePng() {
        const firstSvg = document.querySelector('#labelPreviewContainer .label-barcode-box svg');
        const code = getCurrentScanCode() || 'barcode';
        if (!firstSvg) {
            alert('Hãy bật Barcode 1D trước khi tải ảnh PNG!');
            return;
        }
        const svgData = new XMLSerializer().serializeToString(firstSvg);
        const svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
        const url = URL.createObjectURL(svgBlob);
        const img = new Image();
        img.onload = function () {
            const pad = 24;
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(320, img.width + pad * 2);
            canvas.height = Math.max(120, img.height + pad * 2);
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, (canvas.width - img.width) / 2, pad);
            URL.revokeObjectURL(url);
            const link = document.createElement('a');
            link.download = 'TEM_' + code.replace(/[^a-zA-Z0-9_-]/g, '_') + '.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        };
        img.src = url;
    }

    function renderLabels() {
    const container = document.getElementById('labelPreviewContainer');
    if (!container) return;

    const itemSelect = document.getElementById('itemSelect');
    const selectedOption = itemSelect.options[itemSelect.selectedIndex];
    if (!selectedOption) return;

    const itemCode = selectedOption.dataset.code;
    const itemName = selectedOption.dataset.name;
    const itemUnit = selectedOption.dataset.unit;
    const itemCategory = selectedOption.dataset.category;

    const batchSelect = document.getElementById('batchSelect');
    const batchOption = batchSelect.options[batchSelect.selectedIndex];
    const batchNumber = batchOption ? (batchOption.dataset.batchNumber || 'L01-2026') : 'L01-2026';
    const mfgDate = batchOption ? (batchOption.dataset.mfgDate || '2026-10-01') : '2026-10-01';
    const expDate = batchOption ? (batchOption.dataset.expDate || '2028-10-01') : '2028-10-01';

    const warehouseName = document.getElementById('whSelect').value;
    const sizeClass = document.getElementById('sizeSelect').value;
    const count = Math.max(1, Math.min(100, parseInt(document.getElementById('labelCount').value) || 1));

    const showBarcode = document.getElementById('showBarcodeCheck').checked;
    const showQr = document.getElementById('showQrCheck').checked;
    const showDate = document.getElementById('showDateCheck').checked;
    const showWh = document.getElementById('showWhCheck').checked;

    document.getElementById('previewCountBadge').textContent = count + ' tem';

    container.innerHTML = '';

    // Chuỗi mã hóa đồng bộ 100% với Trạm Quét Mã Vạch & QR
    const scanCode = (batchNumber && batchNumber !== 'DEFAULT') ? `${itemCode}|${batchNumber}` : itemCode;
    const barcodeValue = scanCode;
    const qrData = scanCode;

    for (let i = 0; i < count; i++) {
        const labelEl = document.createElement('div');
        labelEl.className = `warehouse-label ${sizeClass}`;

        const barcodeId = `barcode-${i}`;
        const qrId = `qrcode-${i}`;

        let html = `
            <div>
                <div class="label-header">
                    <span class="label-code">${itemCode}</span>
                    <span class="badge bg-light text-dark border py-0 px-1" style="font-size: 0.65rem;">Lô: ${batchNumber}</span>
                </div>
                <div class="label-title" title="${itemName}">${itemName}</div>
            </div>

            <div class="label-body">
                <div class="label-info flex-grow-1">
                    <div><strong>ĐVT:</strong> ${itemUnit} | <strong>Nhóm:</strong> ${itemCategory}</div>
                    ${showDate ? `<div><strong>NSX:</strong> ${mfgDate}</div><div><strong>HSD:</strong> ${expDate}</div>` : ''}
                    ${showWh ? `<div class="text-truncate" style="max-width: 170px;"><strong>Kho:</strong> ${warehouseName}</div>` : ''}
                </div>
                ${showQr ? `<div class="label-qr-box" id="${qrId}"></div>` : ''}
            </div>

            ${showBarcode ? `
                <div class="label-barcode-box">
                    <svg id="${barcodeId}"></svg>
                </div>
            ` : ''}

            <div class="label-footer">
                <span>Logistix Enterprise WMS</span>
                <span>Tem #${i + 1}</span>
            </div>
        `;

        labelEl.innerHTML = html;
        container.appendChild(labelEl);

        // Render Barcode Code 128
        if (showBarcode) {
            try {
                JsBarcode(`#${barcodeId}`, barcodeValue, {
                    format: "CODE128",
                    width: 1.3,
                    height: 32,
                    displayValue: true,
                    fontSize: 9,
                    margin: 0,
                    textMargin: 1
                });
            } catch (err) {
                console.error("Lỗi vẽ barcode: ", err);
            }
        }

        // Render QR Code 2D (sử dụng qrcode-generator SVG siêu nét)
        if (showQr) {
            const qrTarget = document.getElementById(qrId);
            if (qrTarget) {
                try {
                    if (typeof qrcode !== 'undefined') {
                        const qr = qrcode(0, 'M');
                        qr.addData(qrData);
                        qr.make();
                        qrTarget.innerHTML = qr.createSvgTag(2, 0);
                    } else {
                        qrTarget.innerHTML = '<span class="text-muted font-monospace" style="font-size:0.55rem;">QR</span>';
                    }
                } catch (err) {
                    console.error("Lỗi vẽ QR code: ", err);
                }
            }
        }
    }

    applyLabelPagination();
}

document.addEventListener('DOMContentLoaded', function() {
    onItemChange();
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
