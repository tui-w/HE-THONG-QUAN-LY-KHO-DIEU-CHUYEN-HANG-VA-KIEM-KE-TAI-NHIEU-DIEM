</main>
</div>
</div>

<footer class="footer py-2">
    <div class="container-fluid px-4">
        <div class="d-flex justify-content-between align-items-center small text-slate-400">
            <span><strong>Logistix WMS</strong> — Quản lý kho đa điểm</span>
            <span>© 2026 FIT-DNU</span>
        </div>
    </div>
</footer>

<!-- Toast Notifications Floating Container -->
<div class="toast-container-custom" id="customToastContainer"></div>

<!-- Modal Bảng Hướng Dẫn Phím Tắt Hệ Thống (Feature Chuyên Nghiệp) -->
<div class="modal fade" id="shortcutsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-keyboard text-primary fs-5"></i>
                    <h6 class="modal-title fw-bold mb-0">Phím tắt thao tác nhanh (Keyboard Shortcuts)</h6>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="list-group list-group-flush border rounded-3 overflow-hidden">
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2.5 px-3">
                        <span class="small text-dark"><i class="bi bi-search me-2 text-primary"></i>Tra cứu tồn kho tức
                            thời</span>
                        <div><kbd>Ctrl</kbd> + <kbd>K</kbd></div>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2.5 px-3">
                        <span class="small text-dark"><i class="bi bi-moon-stars me-2 text-info"></i>Chuyển chế độ Sáng
                            / Tối</span>
                        <div><kbd>Ctrl</kbd> + <kbd>D</kbd></div>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2.5 px-3">
                        <span class="small text-dark"><i class="bi bi-question-circle me-2 text-warning"></i>Mở bảng
                            phím tắt này</span>
                        <div><kbd>Ctrl</kbd> + <kbd>/</kbd></div>
                    </div>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2.5 px-3">
                        <span class="small text-dark"><i class="bi bi-x-circle me-2 text-danger"></i>Đóng cửa sổ modal
                            đang mở</span>
                        <div><kbd>Esc</kbd></div>
                    </div>
                </div>
                <div class="text-muted small text-center mt-3">
                    <i class="bi bi-lightning-charge-fill text-warning me-1"></i> Phím tắt giúp thủ kho và quản trị thao
                    tác nhanh mà không cần chuột.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Fallback an toàn nếu CDN Bootstrap JS bị chậm/chặn để mọi Modal, Dropdown, Collapse, Tab luôn hoạt động
    if (typeof window.bootstrap === 'undefined') {
        (function () {
            const modalInstances = new WeakMap();
            class FallbackModal {
                constructor(el) {
                    this.el = typeof el === 'string' ? document.querySelector(el) : el;
                }
                show() {
                    const el = this.el;
                    if (!el) return;
                    if (el.parentElement !== document.body) document.body.appendChild(el);
                    el.style.display = 'block';
                    el.style.background = 'rgba(15, 23, 42, 0.55)';
                    el.style.zIndex = '1065';
                    el.classList.add('show');
                    el.removeAttribute('aria-hidden');
                    document.body.classList.add('modal-open');
                    el.dispatchEvent(new Event('shown.bs.modal'));
                }
                hide() {
                    const el = this.el;
                    if (!el) return;
                    el.classList.remove('show');
                    el.style.display = 'none';
                    el.style.background = '';
                    el.setAttribute('aria-hidden', 'true');
                    if (!document.querySelector('.modal.show')) {
                        document.body.classList.remove('modal-open');
                        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
                    }
                    el.dispatchEvent(new Event('hidden.bs.modal'));
                }
                static getOrCreateInstance(el) {
                    const target = typeof el === 'string' ? document.querySelector(el) : el;
                    if (!target) return { show() {}, hide() {} };
                    if (!modalInstances.has(target)) modalInstances.set(target, new FallbackModal(target));
                    return modalInstances.get(target);
                }
                static getInstance(el) {
                    const target = typeof el === 'string' ? document.querySelector(el) : el;
                    return target ? (modalInstances.get(target) || null) : null;
                }
            }
            window.bootstrap = { Modal: FallbackModal };

            document.addEventListener('click', function (e) {
                const dismissBtn = e.target.closest('[data-bs-dismiss="modal"]');
                if (dismissBtn) {
                    const m = dismissBtn.closest('.modal');
                    if (m) FallbackModal.getOrCreateInstance(m).hide();
                    return;
                }
                if (e.target.classList && e.target.classList.contains('modal') && e.target.classList.contains('show')) {
                    FallbackModal.getOrCreateInstance(e.target).hide();
                    return;
                }
                const modalTrigger = e.target.closest('[data-bs-toggle="modal"]');
                if (modalTrigger) {
                    const sel = modalTrigger.getAttribute('data-bs-target') || modalTrigger.getAttribute('href');
                    if (sel && sel.startsWith('#')) {
                        e.preventDefault();
                        FallbackModal.getOrCreateInstance(sel).show();
                    }
                    return;
                }
                const ddTrigger = e.target.closest('[data-bs-toggle="dropdown"]');
                if (ddTrigger) {
                    e.preventDefault();
                    e.stopPropagation();
                    const parent = ddTrigger.closest('.dropdown, .btn-group') || ddTrigger.parentElement;
                    const menu = parent ? parent.querySelector('.dropdown-menu') : null;
                    document.querySelectorAll('.dropdown-menu.show').forEach(m => { if (m !== menu) m.classList.remove('show'); });
                    if (menu) menu.classList.toggle('show');
                    return;
                }
                if (!e.target.closest('.dropdown-menu')) {
                    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
                }
                const collapseTrigger = e.target.closest('[data-bs-toggle="collapse"]');
                if (collapseTrigger) {
                    const sel = collapseTrigger.getAttribute('data-bs-target') || collapseTrigger.getAttribute('href');
                    if (sel && sel.startsWith('#')) {
                        e.preventDefault();
                        document.querySelectorAll(sel).forEach(t => t.classList.toggle('show'));
                    }
                }
            });
        })();
    }
</script>

<script>
    // Hệ thống hiển thị Toast chuyên nghiệp
    window.showToast = function (message, type = 'success', duration = 3500) {
        const container = document.getElementById('customToastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'custom-toast';

        let icon = 'bi-check-circle-fill text-success';
        if (type === 'error' || type === 'danger') icon = 'bi-exclamation-triangle-fill text-danger';
        else if (type === 'warning') icon = 'bi-exclamation-circle-fill text-warning';
        else if (type === 'info') icon = 'bi-info-circle-fill text-info';

        toast.innerHTML = `
        <i class="bi ${icon} fs-5 flex-shrink-0"></i>
        <div class="flex-grow-1 text-dark fw-medium">${message}</div>
        <button type="button" class="btn-close btn-close-sm ms-2" style="font-size: 0.7rem;"></button>
    `;

        toast.querySelector('.btn-close').addEventListener('click', () => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            setTimeout(() => toast.remove(), 200);
        });

        container.appendChild(toast);

        setTimeout(() => {
            if (toast.parentElement) {
                toast.style.transition = 'all 0.3s ease';
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(10px)';
                setTimeout(() => toast.remove(), 300);
            }
        }, duration);
    };

    document.addEventListener('DOMContentLoaded', function () {
        // 1. Quản lý Dark Mode & Toggle Icon
        const themeBtn = document.getElementById('themeToggleBtn');
        const themeIcon = document.getElementById('themeToggleIcon');

        function updateThemeIcon(theme) {
            if (!themeIcon) return;
            if (theme === 'dark') {
                themeIcon.className = 'bi bi-sun-fill text-warning';
            } else {
                themeIcon.className = 'bi bi-moon-stars text-secondary';
            }
        }

        const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
        updateThemeIcon(currentTheme);

        if (themeBtn) {
            themeBtn.addEventListener('click', function () {
                const nowTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
                const nextTheme = (nowTheme === 'dark') ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', nextTheme);
                localStorage.setItem('logistix_theme', nextTheme);
                updateThemeIcon(nextTheme);
                showToast(nextTheme === 'dark' ? 'Đã bật Chế độ Tối (Dark Mode)' : 'Đã chuyển về Chế độ Sáng', 'info', 2000);
            });
        }

        // 1b. Thu gọn / Mở rộng Sidebar để tối đa hóa không gian Main Content
        const sidebarCollapseBtn = document.getElementById('sidebarCollapseBtn');
        if (localStorage.getItem('logistix_sidebar_collapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
        if (sidebarCollapseBtn) {
            sidebarCollapseBtn.addEventListener('click', function () {
                document.body.classList.toggle('sidebar-collapsed');
                const isCollapsed = document.body.classList.contains('sidebar-collapsed');
                localStorage.setItem('logistix_sidebar_collapsed', isCollapsed ? '1' : '0');
            });
        }

        // Tự động cuộn thanh Menu Nổi đến đúng mục đang active
        const sidebarEl = document.getElementById('sidebarMenu');
        const activeSidebarLink = sidebarEl ? sidebarEl.querySelector('.nav-link.active') : null;
        if (sidebarEl && activeSidebarLink) {
            activeSidebarLink.scrollIntoView({ block: 'nearest', behavior: 'instant' });
        }

        // 2. Tra cứu kho nhanh qua Modal (Ctrl + K)
        const modalEl = document.getElementById('quickStockModal');
        const inputEl = document.getElementById('quickSearchInput');
        const resultsEl = document.getElementById('quickSearchResults');
        const shortcutsModalEl = document.getElementById('shortcutsModal');

        let bsModal = modalEl ? new bootstrap.Modal(modalEl) : null;
        let bsShortcutsModal = shortcutsModalEl ? new bootstrap.Modal(shortcutsModalEl) : null;

        // Phím tắt toàn cục
        document.addEventListener('keydown', function (e) {
            // Ctrl + K hoặc Cmd + K: Mở tìm kiếm
            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                if (bsModal) bsModal.show();
            }
            // Ctrl + D hoặc Cmd + D: Chuyển đổi Dark Mode
            else if ((e.ctrlKey || e.metaKey) && (e.key === 'd' || e.key === 'D')) {
                e.preventDefault();
                if (themeBtn) themeBtn.click();
            }
            // Ctrl + / hoặc Cmd + /: Mở bảng phím tắt
            else if ((e.ctrlKey || e.metaKey) && (e.key === '/' || e.key === '?')) {
                e.preventDefault();
                if (bsShortcutsModal) bsShortcutsModal.show();
            }
        });

        if (modalEl && inputEl && resultsEl) {
            const runQuickSearch = (query) => {
                resultsEl.innerHTML = `
                <div class="p-4 text-center text-muted small">
                    <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                    <div>Đang kiểm tra sổ kho thời gian thực...</div>
                </div>`;
                fetch('api.php?action=search_stock&q=' + encodeURIComponent(query))
                    .then(res => res.json())
                    .then(data => {
                        const items = data.results || [];
                        if (items.length === 0) {
                            resultsEl.innerHTML = `
                            <div class="p-4 text-center text-muted small">
                                <i class="bi bi-search fs-3 d-block mb-1 text-secondary opacity-50"></i>
                                Không tìm thấy sản phẩm nào khớp với <strong>"${query}"</strong>.
                            </div>`;
                            return;
                        }
                        let html = '';
                        items.forEach(it => {
                            const isLow = it.total_stock <= it.min_stock;
                            const badgeColor = isLow ? 'bg-danger-subtle text-danger border border-danger-subtle' : 'bg-success-subtle text-success border border-success-subtle';
                            let whBadges = '';
                            it.warehouses.forEach(wh => {
                                whBadges += `<span class="badge bg-light text-dark border px-2 py-1"><span class="text-secondary fw-normal">${wh.warehouse_code}:</span> <strong class="${wh.stock > 0 ? 'text-primary' : 'text-muted'}">${wh.stock}</strong></span>`;
                            });
                            html += `
                            <div class="list-group-item list-group-item-action py-3 px-3">
                                <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                    <div>
                                        <span class="badge bg-dark-subtle text-dark font-monospace me-1">${it.code}</span>
                                        <strong class="text-dark">${it.name}</strong>
                                        <span class="badge bg-secondary-subtle text-secondary ms-1">${it.category}</span>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge ${badgeColor} px-2.5 py-1.5 fs-7">Tổng tồn: <strong>${it.total_stock}</strong> ${it.unit}</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center justify-content-between mt-2 pt-2 border-top">
                                    <div class="d-flex gap-1.5 flex-wrap">${whBadges}</div>
                                    <div>
                                        <a href="reports.php?tab=card&item_id=${it.id}" class="btn btn-xs btn-outline-primary py-0.5 px-2 text-decoration-none" style="font-size: 0.75rem;"><i class="bi bi-clock-history me-1"></i>Sổ thẻ kho</a>
                                    </div>
                                </div>
                            </div>`;
                        });
                        resultsEl.innerHTML = html;
                    })
                    .catch(err => {
                        resultsEl.innerHTML = `<div class="p-3 text-center text-danger small">Lỗi tải dữ liệu: ${err.message}</div>`;
                    });
            };

            modalEl.addEventListener('shown.bs.modal', function () {
                inputEl.focus();
                inputEl.select();
                runQuickSearch(inputEl.value.trim());
            });
            inputEl.addEventListener('dblclick', function () {
                runQuickSearch('');
            });

            let debounceTimer = null;
            inputEl.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                const query = this.value.trim();
                debounceTimer = setTimeout(() => runQuickSearch(query), 220);
            });
        }

        // 3. Xử lý Quét mã vạch & QR đa năng (Live Camera + Quét ảnh + Lịch sử quét)
        const scannerModalEl = document.getElementById('liveScannerModal');
        const startScanBtn = document.getElementById('startLiveScanBtn');
        const stopScanBtn = document.getElementById('stopLiveScanBtn');
        const scanBadge = document.getElementById('scanResultBadge');
        const scannedCodeText = document.getElementById('scannedCodeText');
        const scannedItemDetail = document.getElementById('scannedItemDetail');
        const scanStatusText = document.getElementById('scanStatusText');
        const cameraSelect = document.getElementById('cameraSelect');
        const scanImageInput = document.getElementById('scanImageFileInput');

        let html5QrCode = null;
        let isScanning = false;

        function playBeepSound() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = 880;
                gain.gain.value = 0.15;
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                setTimeout(() => { osc.stop(); ctx.close(); }, 120);
            } catch (e) { }
        }

        window.renderScanHistory = function () {
            const box = document.getElementById('scanHistoryBox');
            const chips = document.getElementById('scanHistoryChips');
            if (!box || !chips) return;
            let hist = [];
            try { hist = JSON.parse(localStorage.getItem('wms_scan_history') || '[]'); } catch (e) { hist = []; }
            if (!hist.length) { box.classList.add('d-none'); return; }
            box.classList.remove('d-none');
            chips.innerHTML = hist.map(c => {
                const esc = String(c).replace(/'/g, "\\'").replace(/</g, '&lt;');
                return `<button type="button" class="btn btn-xs btn-light border rounded-pill py-0 px-2 font-monospace" style="font-size:0.7rem;" onclick="document.getElementById('manualScanInput').value='${esc}';handleUniversalScan('${esc}')">${esc}</button>`;
            }).join('');
        };

        function saveScanHistory(code) {
            let hist = [];
            try { hist = JSON.parse(localStorage.getItem('wms_scan_history') || '[]'); } catch (e) { hist = []; }
            hist = [code, ...hist.filter(x => x !== code)].slice(0, 8);
            localStorage.setItem('wms_scan_history', JSON.stringify(hist));
            window.renderScanHistory();
        }

        let scanSuggestTimer = null;
        window.loadScanSuggestions = function (q) {
            const dd = document.getElementById('scanSuggestDropdown');
            if (!dd) return;
            clearTimeout(scanSuggestTimer);
            scanSuggestTimer = setTimeout(() => {
                fetch('api.php?action=universal_scan_suggest&q=' + encodeURIComponent((q || '').trim()))
                    .then(r => r.json())
                    .then(res => {
                        const list = res.results || [];
                        if (!list.length) {
                            dd.innerHTML = '<div class="p-3 text-center text-muted small">Không tìm thấy mã SKU, Ô kệ hoặc Chứng từ phù hợp.</div>';
                        } else {
                            dd.innerHTML = list.map(item => {
                                const badgeCls = item.type === 'BIN'
                                    ? 'bg-info-subtle text-info'
                                    : (item.type === 'VOUCHER' ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary');
                                const escCode = String(item.code).replace(/'/g, "\\'");
                                return `<button type="button" class="dropdown-item d-flex justify-content-between align-items-center py-2 px-3 border-bottom text-wrap"
                                    onclick="document.getElementById('manualScanInput').value='${escCode}';document.getElementById('scanSuggestDropdown').classList.add('d-none');handleUniversalScan('${escCode}');">
                                    <div>
                                        <div class="fw-bold font-monospace text-dark">${item.code}</div>
                                        <div class="text-muted small">${item.label}</div>
                                    </div>
                                    <span class="badge ${badgeCls} rounded-pill">${item.type}</span>
                                </button>`;
                            }).join('');
                        }
                        dd.classList.remove('d-none');
                    })
                    .catch(() => {});
            }, 120);
        };

        document.addEventListener('click', (e) => {
            const dd = document.getElementById('scanSuggestDropdown');
            const inp = document.getElementById('manualScanInput');
            const wrap = inp ? inp.closest('.position-relative') : null;
            if (dd && (!wrap || !wrap.contains(e.target))) {
                dd.classList.add('d-none');
            }
        });

        window.handleUniversalScan = function (decodedText) {
            decodedText = (decodedText || '').trim();
            if (!decodedText || !scanBadge || !scannedCodeText) return;
            saveScanHistory(decodedText);
            scanBadge.classList.remove('d-none');
            scannedCodeText.innerText = decodedText;
            scannedItemDetail.innerHTML = '<span class="spinner-border spinner-border-sm text-primary"></span> Đang đối chiếu dữ liệu...';

            fetch('api.php?action=universal_scan&code=' + encodeURIComponent(decodedText))
                .then(r => r.json())
                .then(res => {
                    if (!res.ok) {
                        scannedItemDetail.innerHTML = `<span class="text-warning"><i class="bi bi-question-circle"></i> Không tìm thấy mã <strong>${decodedText}</strong> trong Sản phẩm, Ô kệ hay Chứng từ.</span>`;
                        return;
                    }
                    const d = res.data;
                    if (res.type === 'LOCATION') {
                        scannedItemDetail.innerHTML = `
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-info-subtle text-info rounded-pill">Ô KỆ</span>
                                <span class="fw-bold text-dark">${d.wh_code} &bull; Khu ${d.zone_code} &bull; ${d.rack_code}</span>
                            </div>
                            <div class="small">${d.item_name ? 'Đang chứa: <strong>' + d.item_name + '</strong> [' + d.item_code + ']' : '<span class="text-muted">Ô kệ đang trống</span>'}</div>
                            <div class="mt-2 d-flex flex-wrap gap-1.5">
                                <a href="locations.php?wh=${d.warehouse_id}&zone=${encodeURIComponent(d.zone_code)}" class="btn btn-sm btn-info text-white py-0.5 px-2.5 rounded-pill d-inline-flex align-items-center gap-1"><i class="bi bi-grid-3x3-gap"></i> Mở Sơ đồ Ô kệ <i class="bi bi-arrow-right-short"></i></a>
                            </div>`;
                    } else if (res.type === 'VOUCHER') {
                        scannedItemDetail.innerHTML = `
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-success-subtle text-success rounded-pill">CHỨNG TỪ</span>
                                <span class="fw-bold text-dark">${d.type} (${d.status})</span>
                            </div>
                            <div class="small">${d.note || 'Chứng từ kho'}</div>
                            <div class="mt-2 d-flex flex-wrap gap-1.5">
                                <a href="voucher_detail.php?id=${d.id}" class="btn btn-sm btn-success text-white py-0.5 px-2.5 rounded-pill d-inline-flex align-items-center gap-1"><i class="bi bi-file-earmark-text"></i> Chi tiết phiếu <i class="bi bi-arrow-right-short"></i></a>
                                <a href="print_voucher.php?id=${d.id}" target="_blank" class="btn btn-sm btn-outline-secondary py-0.5 px-2.5 rounded-pill"><i class="bi bi-printer me-1"></i>In phiếu</a>
                            </div>`;
                    } else {
                        const batchTag = d.matched_batch
                            ? `<span class="badge bg-warning-subtle text-dark border rounded-pill">Lô: ${d.matched_batch}</span>`
                            : '';
                        scannedItemDetail.innerHTML = `
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <span class="badge bg-primary-subtle text-primary rounded-pill">SẢN PHẨM</span>
                                ${batchTag}
                                <span class="fw-bold text-dark">${d.name} (${d.category})</span>
                            </div>
                            <div class="text-success small">Tổng tồn: <strong>${d.total_stock} ${d.unit}</strong> &bull; ${d.wh_breakdown}</div>
                            <div class="mt-2 d-flex flex-wrap gap-1.5">
                                <a href="reports.php?tab=card&item_id=${d.id}" class="btn btn-sm btn-primary text-white py-0.5 px-2.5 rounded-pill d-inline-flex align-items-center gap-1"><i class="bi bi-clock-history"></i> Xem Thẻ kho <i class="bi bi-arrow-right-short"></i></a>
                                <a href="barcode.php?item_id=${d.id}" class="btn btn-sm btn-outline-info py-0.5 px-2.5 rounded-pill"><i class="bi bi-upc-scan me-1"></i>In tem mã</a>
                                <a href="vouchers.php" class="btn btn-sm btn-outline-primary py-0.5 px-2.5 rounded-pill">Lập phiếu Nhập/Xuất</a>
                            </div>`;
                    }
                })
                .catch(err => {
                    scannedItemDetail.innerText = 'Lỗi tra cứu: ' + err.message;
                });
        };

        async function loadCamerasIfNeeded() {
            if (typeof Html5Qrcode === 'undefined' || !cameraSelect) return;
            try {
                const devices = await Html5Qrcode.getCameras();
                if (devices && devices.length > 1) {
                    cameraSelect.innerHTML = devices.map((cam, idx) =>
                        `<option value="${cam.id}">${cam.label || ('Camera ' + (idx + 1))}</option>`
                    ).join('');
                    cameraSelect.classList.remove('d-none');
                }
            } catch (e) { }
        }

        async function startScanner() {
            if (typeof Html5Qrcode === 'undefined') {
                alert('Thư viện Html5Qrcode chưa sẵn sàng. Vui lòng kiểm tra kết nối mạng!');
                return;
            }
            if (isScanning) return;

            try {
                if (!html5QrCode) {
                    html5QrCode = new Html5Qrcode("liveCameraReader");
                }
                await loadCamerasIfNeeded();
                const selectedCam = cameraSelect && !cameraSelect.classList.contains('d-none') && cameraSelect.value
                    ? cameraSelect.value
                    : { facingMode: "environment" };
                const config = { fps: 10, qrbox: { width: 240, height: 165 } };

                await html5QrCode.start(
                    selectedCam,
                    config,
                    (decodedText) => {
                        playBeepSound();
                        window.handleUniversalScan(decodedText);
                        showToast('Đã quét thành công mã: ' + decodedText, 'success', 2500);
                    },
                    () => {}
                );
                isScanning = true;
                if (scanStatusText) scanStatusText.innerHTML = '<span class="text-success fw-semibold"><i class="bi bi-record-circle-fill me-1"></i>Đang quét trực tiếp từ Camera...</span>';
                if (startScanBtn) startScanBtn.classList.add('d-none');
                if (stopScanBtn) stopScanBtn.classList.remove('d-none');
            } catch (err) {
                if (scanStatusText) scanStatusText.innerHTML = '<span class="text-danger">Không thể mở Camera</span>';
                alert('Không thể mở Camera: ' + err);
            }
        }

        async function stopScanner() {
            if (html5QrCode && isScanning) {
                try {
                    await html5QrCode.stop();
                    isScanning = false;
                    if (scanStatusText) scanStatusText.textContent = 'Camera đã tắt • Sẵn sàng quét lại';
                    if (startScanBtn) startScanBtn.classList.remove('d-none');
                    if (stopScanBtn) stopScanBtn.classList.add('d-none');
                } catch (e) { }
            }
        }

        if (scanImageInput) {
            scanImageInput.addEventListener('change', async (e) => {
                const file = e.target.files?.[0];
                if (!file) return;
                if (typeof Html5Qrcode === 'undefined') {
                    alert('Thư viện Html5Qrcode chưa sẵn sàng.');
                    return;
                }
                try {
                    if (isScanning) await stopScanner();
                    if (!html5QrCode) html5QrCode = new Html5Qrcode("liveCameraReader");
                    const decoded = await html5QrCode.scanFile(file, true);
                    playBeepSound();
                    document.getElementById('manualScanInput').value = decoded;
                    window.handleUniversalScan(decoded);
                    showToast('Đã giải mã từ ảnh: ' + decoded, 'success', 2500);
                } catch (err) {
                    showToast('Không nhận diện được mã QR/Barcode trong ảnh này.', 'warning', 3000);
                } finally {
                    scanImageInput.value = '';
                }
            });
        }

        if (cameraSelect) {
            cameraSelect.addEventListener('change', async () => {
                if (isScanning) {
                    await stopScanner();
                    await startScanner();
                }
            });
        }

        if (startScanBtn) startScanBtn.addEventListener('click', startScanner);
        if (stopScanBtn) stopScanBtn.addEventListener('click', stopScanner);
        if (scannerModalEl) {
            scannerModalEl.addEventListener('shown.bs.modal', () => {
                window.renderScanHistory();
                document.getElementById('manualScanInput')?.focus();
            });
            scannerModalEl.addEventListener('hidden.bs.modal', stopScanner);
        }
        window.renderScanHistory();
    });

    /* ==========================================================================
       SMART TABLE & GRID ENHANCER (KẾT HỢP CUỘN DỌC + PHÂN TRANG SANG TRANG)
       Tự động áp dụng cho mọi bảng .table-responsive (ngoài modal) & lưới ô kệ:
       - Thanh cuộn riêng (.table-scroll-box) + header cột dính trên cùng
       - Phân trang thông minh (Sang trang: « ‹ 1 2 3 › ») + Tùy chọn số dòng/trang (8, 15, 30, 50, Tất cả)
       - Tương thích 100% với mọi ô tìm kiếm và nút lọc tab trên từng trang
       ========================================================================== */
    (function () {
        function normalize(s) {
            return (s || '')
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/đ/g, 'd').replace(/Đ/g, 'D')
                .toLowerCase().replace(/\s+/g, ' ').trim();
        }

        function isPlaceholderRow(row) {
            return row.id === 'historyNoResult' ||
                row.classList.contains('tt-noresult') ||
                (row.cells && row.cells.length === 1 && row.cells[0].colSpan > 1);
        }

        function buildPageButtons(navEl, currentPage, totalPages, onSelectPage) {
            navEl.innerHTML = '';

            const makeBtn = (html, targetPage, disabled, active, title) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'pg-btn' + (active ? ' active' : '');
                b.innerHTML = html;
                b.disabled = disabled;
                if (title) b.title = title;
                if (!disabled && !active) {
                    b.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        onSelectPage(targetPage);
                    });
                }
                return b;
            };

            navEl.appendChild(makeBtn('<i class="bi bi-chevron-double-left"></i>', 1, currentPage <= 1, false, 'Trang đầu'));
            navEl.appendChild(makeBtn('<i class="bi bi-chevron-left"></i>', currentPage - 1, currentPage <= 1, false, 'Trang trước'));

            const pages = [];
            if (totalPages <= 7) {
                for (let i = 1; i <= totalPages; i++) pages.push(i);
            } else {
                pages.push(1);
                if (currentPage > 3) pages.push('...');
                const start = Math.max(2, currentPage - 1);
                const end = Math.min(totalPages - 1, currentPage + 1);
                for (let i = start; i <= end; i++) pages.push(i);
                if (currentPage < totalPages - 2) pages.push('...');
                pages.push(totalPages);
            }

            pages.forEach(p => {
                if (p === '...') {
                    const span = document.createElement('span');
                    span.className = 'pg-ellipsis';
                    span.textContent = '…';
                    navEl.appendChild(span);
                } else {
                    navEl.appendChild(makeBtn(String(p), p, false, p === currentPage, 'Trang ' + p));
                }
            });

            navEl.appendChild(makeBtn('<i class="bi bi-chevron-right"></i>', currentPage + 1, currentPage >= totalPages, false, 'Trang sau'));
            navEl.appendChild(makeBtn('<i class="bi bi-chevron-double-right"></i>', totalPages, currentPage >= totalPages, false, 'Trang cuối'));
        }

        function enhance(wrap) {
            if (wrap.dataset.enhanced || wrap.hasAttribute('data-no-enhance') || wrap.closest('.modal')) return;
            const table = wrap.querySelector('table');
            if (!table || !table.tBodies.length || !table.tHead) return;
            wrap.dataset.enhanced = '1';
            wrap.classList.add('table-scroll-box');

            const tbody = table.tBodies[0];
            const rows = Array.from(tbody.rows).filter(r => !isPlaceholderRow(r));
            const total = rows.length;
            if (total < 8) wrap.classList.add('is-short');
            if (total === 0) return;

            const colCount = table.tHead.rows[0] ? table.tHead.rows[0].cells.length : 1;
            const card = wrap.closest('.clean-card, .card, .modern-card');
            if (card && !card.classList.contains('main-secondary-card')) {
                card.classList.add('main-primary-card');
            }
            const hasOwnSearch = card && card.querySelector('input[type="text"]:not(.tt-input), input[type="search"]:not(.tt-input)');

            // ---- Thanh tìm nhanh dự phòng nếu card chưa có ô tìm kiếm ----
            let fallbackInput = null;
            const cache = new Map();
            if (!hasOwnSearch && total >= 5) {
                const toolbar = document.createElement('div');
                toolbar.className = 'table-toolbar';
                toolbar.innerHTML = `
                <div class="tt-search">
                    <i class="bi bi-search"></i>
                    <input type="search" class="form-control form-control-sm tt-input" placeholder="Tìm nhanh trong bảng..." aria-label="Tìm nhanh trong bảng">
                </div>
                <span class="tt-hint d-none d-md-inline"><kbd>Esc</kbd> để xóa</span>`;
                wrap.before(toolbar);
                fallbackInput = toolbar.querySelector('input');
                rows.forEach(r => cache.set(r, normalize(r.innerText)));
            }

            // ---- Chân bảng Kết hợp Cuộn & Phân trang (Sang trang) ----
            let footer = wrap.nextElementSibling;
            if (!footer || !footer.classList.contains('table-footer-bar')) {
                footer = document.createElement('div');
                footer.className = 'table-footer-bar';
                wrap.after(footer);
            }

            const defaultPageSize = parseInt(wrap.getAttribute('data-page-size') || '8', 10);
            let pageSize = defaultPageSize;
            let currentPage = 1;
            let lastMatchedCount = total;

            footer.innerHTML = `<div class="pg-nav"></div>`;

            const rangeEl = footer.querySelector('[data-pg-range]');
            const countEl = footer.querySelector('[data-tt-count]');
            const sizeSelect = footer.querySelector('.pg-size-select');
            const navEl = footer.querySelector('.pg-nav');
            let noRow = null;

            function renderPagination(resetPageIfChanged = false) {
                if (fallbackInput) {
                    const q = normalize(fallbackInput.value);
                    const terms = q ? q.split(' ') : [];
                    rows.forEach(r => {
                        const text = cache.get(r);
                        const ok = terms.every(t => text.includes(t));
                        r.classList.toggle('tt-hidden', !ok);
                    });
                }

                // Những dòng thỏa bộ lọc tìm kiếm / tab hiện tại
                const matchedRows = rows.filter(r => !r.classList.contains('tt-hidden') && r.style.display !== 'none');
                const matchedTotal = matchedRows.length;

                if (resetPageIfChanged && matchedTotal !== lastMatchedCount) {
                    currentPage = 1;
                }
                lastMatchedCount = matchedTotal;

                const effectiveSize = pageSize > 0 ? pageSize : Math.max(1, matchedTotal);
                const totalPages = Math.max(1, Math.ceil(matchedTotal / effectiveSize));
                if (currentPage > totalPages) currentPage = totalPages;
                if (currentPage < 1) currentPage = 1;

                const startIdx = (currentPage - 1) * effectiveSize;
                const endIdx = startIdx + effectiveSize;

                // Ẩn các dòng bị lọc loại bỏ
                rows.forEach(r => r.classList.add('pg-page-hidden'));

                // Chỉ hiển thị các dòng thuộc trang hiện tại
                matchedRows.forEach((r, idx) => {
                    if (pageSize === 0 || (idx >= startIdx && idx < endIdx)) {
                        r.classList.remove('pg-page-hidden');
                    }
                });

                if (countEl) countEl.textContent = matchedTotal;
                if (rangeEl) {
                    if (matchedTotal === 0) {
                        rangeEl.textContent = '0';
                    } else if (pageSize === 0) {
                        rangeEl.textContent = `1–${matchedTotal}`;
                    } else {
                        rangeEl.textContent = `${startIdx + 1}–${Math.min(endIdx, matchedTotal)}`;
                    }
                }

                if (matchedTotal === 0 && fallbackInput) {
                    if (!noRow) {
                        noRow = document.createElement('tr');
                        noRow.className = 'tt-noresult';
                        noRow.innerHTML = `<td colspan="${colCount}"><i class="bi bi-search d-block fs-3 mb-1 opacity-50"></i>Không có dòng nào khớp "<span></span>"</td>`;
                    }
                    noRow.querySelector('span').textContent = fallbackInput.value;
                    tbody.appendChild(noRow);
                } else if (noRow && noRow.parentNode) {
                    noRow.remove();
                }

                buildPageButtons(navEl, currentPage, totalPages, (targetPage) => {
                    currentPage = targetPage;
                    renderPagination(false);
                    wrap.scrollTop = 0;
                });
            }

            if (sizeSelect) sizeSelect.addEventListener('change', () => {
                pageSize = parseInt(sizeSelect.value, 10);
                currentPage = 1;
                renderPagination(false);
                wrap.scrollTop = 0;
            });

            if (fallbackInput) {
                let timer = null;
                fallbackInput.addEventListener('input', () => {
                    clearTimeout(timer);
                    timer = setTimeout(() => {
                        currentPage = 1;
                        renderPagination(false);
                        wrap.scrollTop = 0;
                    }, 100);
                });
                fallbackInput.addEventListener('keydown', e => {
                    if (e.key === 'Escape') {
                        fallbackInput.value = '';
                        currentPage = 1;
                        renderPagination(false);
                    }
                    if (e.key === 'Enter') e.preventDefault();
                });
            }

            // Lắng nghe các ô tìm kiếm và nút lọc tab có sẵn trên trang để tự động cập nhật phân trang
            const scopeEl = card || document;
            scopeEl.addEventListener('input', (e) => {
                if (e.target && e.target !== fallbackInput && !e.target.classList.contains('pg-size-select')) {
                    setTimeout(() => renderPagination(true), 20);
                }
            });
            scopeEl.addEventListener('keyup', (e) => {
                if (e.target && e.target !== fallbackInput && !e.target.classList.contains('pg-size-select')) {
                    setTimeout(() => renderPagination(true), 20);
                }
            });
            scopeEl.addEventListener('change', (e) => {
                if (e.target && !e.target.classList.contains('pg-size-select') && e.target.type !== 'checkbox') {
                    setTimeout(() => renderPagination(true), 20);
                }
            });
            scopeEl.addEventListener('click', (e) => {
                const btn = e.target.closest('button:not(.pg-btn)');
                if (btn) {
                    setTimeout(() => renderPagination(true), 25);
                }
            });

            // ---- Sắp xếp cột động (Dynamic Interactive Column Sorting - 3 trạng thái & đồng bộ icon) ----
            if (table.tHead && table.tHead.rows[0]) {
                const originalRows = rows.slice();
                const parseDateMs = (str) => {
                    const m = str.match(/^(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2}))?/);
                    if (!m) return NaN;
                    return new Date(+m[3], +m[2] - 1, +m[1], +(m[4] || 0), +(m[5] || 0)).getTime();
                };

                Array.from(table.tHead.rows[0].cells).forEach((th, colIdx) => {
                    const txt = th.innerText.trim();
                    if (!txt || txt === 'Thao tác' || txt === 'Chi tiết' || th.querySelector('input')) return;
                    th.classList.add('sortable-th');
                    th.title = 'Bấm để sắp xếp theo cột ' + txt;
                    const ind = document.createElement('span');
                    ind.className = 'sort-indicator';
                    ind.innerHTML = '<i class="bi bi-arrow-down-up"></i>';
                    th.appendChild(ind);

                    th.addEventListener('click', () => {
                        const wasAsc = th.classList.contains('sort-asc');
                        const wasDesc = th.classList.contains('sort-desc');
                        const nextState = !wasAsc && !wasDesc ? 'asc' : (wasAsc ? 'desc' : 'none');

                        Array.from(table.tHead.rows[0].cells).forEach(c => {
                            c.classList.remove('sort-asc', 'sort-desc');
                            const s = c.querySelector('.sort-indicator');
                            if (s) s.innerHTML = '<i class="bi bi-arrow-down-up"></i>';
                        });

                        if (nextState === 'none') {
                            rows.splice(0, rows.length, ...originalRows);
                        } else {
                            const isAsc = nextState === 'asc';
                            th.classList.add(isAsc ? 'sort-asc' : 'sort-desc');
                            ind.innerHTML = isAsc ? '<i class="bi bi-sort-up"></i>' : '<i class="bi bi-sort-down"></i>';

                            rows.sort((a, b) => {
                                const cellA = (a.cells[colIdx]?.innerText || '').trim();
                                const cellB = (b.cells[colIdx]?.innerText || '').trim();
                                const dtA = parseDateMs(cellA);
                                const dtB = parseDateMs(cellB);
                                if (!isNaN(dtA) && !isNaN(dtB)) {
                                    return isAsc ? (dtA - dtB) : (dtB - dtA);
                                }
                                const numA = parseFloat(cellA.replace(/[^0-9.-]+/g, ''));
                                const numB = parseFloat(cellB.replace(/[^0-9.-]+/g, ''));
                                if (!isNaN(numA) && !isNaN(numB) && /^[+\-0-9.,\sđ%]+$/i.test(cellA) && /^[+\-0-9.,\sđ%]+$/i.test(cellB)) {
                                    return isAsc ? (numA - numB) : (numB - numA);
                                }
                                return isAsc ? cellA.localeCompare(cellB, 'vi') : cellB.localeCompare(cellA, 'vi');
                            });
                        }
                        rows.forEach(r => tbody.appendChild(r));
                        currentPage = 1;
                        renderPagination(false);
                    });
                });
            }

            renderPagination(false);
        }

        // Hỗ trợ phân trang cho cả Lưới Sơ đồ Ô kệ (#binGridContainer trên locations.php)
        function enhanceBinGrid(grid) {
            if (!grid || grid.dataset.enhanced) return;
            grid.dataset.enhanced = '1';
            const cols = Array.from(grid.querySelectorAll('.bin-card-col'));
            const total = cols.length;
            if (total === 0) return;

            const card = grid.closest('.clean-card, .card');
            const body = grid.parentElement;
            const footer = document.createElement('div');
            footer.className = 'table-footer-bar';
            body.after(footer);

            let pageSize = 12;
            let currentPage = 1;
            let lastMatched = total;

            footer.innerHTML = `<div class="pg-nav"></div>`;

            const rangeEl = footer.querySelector('[data-pg-range]');
            const countEl = footer.querySelector('[data-tt-count]');
            const sizeSelect = footer.querySelector('.pg-size-select');
            const navEl = footer.querySelector('.pg-nav');

            function renderGridPage(resetIfChanged = false) {
                const matched = cols.filter(c => c.style.display !== 'none');
                const matchedTotal = matched.length;
                if (resetIfChanged && matchedTotal !== lastMatched) currentPage = 1;
                lastMatched = matchedTotal;

                const eff = pageSize > 0 ? pageSize : Math.max(1, matchedTotal);
                const totalPages = Math.max(1, Math.ceil(matchedTotal / eff));
                if (currentPage > totalPages) currentPage = totalPages;
                if (currentPage < 1) currentPage = 1;

                const startIdx = (currentPage - 1) * eff;
                const endIdx = startIdx + eff;

                cols.forEach(c => c.classList.add('pg-page-hidden'));
                matched.forEach((c, idx) => {
                    if (pageSize === 0 || (idx >= startIdx && idx < endIdx)) {
                        c.classList.remove('pg-page-hidden');
                    }
                });

                if (countEl) countEl.textContent = matchedTotal;
                if (rangeEl) {
                    rangeEl.textContent = matchedTotal === 0 ? '0' : (pageSize === 0 ? `1–${matchedTotal}` : `${startIdx + 1}–${Math.min(endIdx, matchedTotal)}`);
                }

                buildPageButtons(navEl, currentPage, totalPages, (p) => {
                    currentPage = p;
                    renderGridPage(false);
                });
            }

            if (sizeSelect) sizeSelect.addEventListener('change', () => {
                pageSize = parseInt(sizeSelect.value, 10);
                currentPage = 1;
                renderGridPage(false);
            });

            const scope = card || document;
            scope.addEventListener('keyup', () => setTimeout(() => renderGridPage(true), 20));
            scope.addEventListener('click', (e) => {
                if (e.target.closest('button:not(.pg-btn)')) {
                    setTimeout(() => renderGridPage(true), 25);
                }
            });

            renderGridPage(false);
        }

        function initUniversalSearchSuggestions() {
            const skipIds = new Set(['locationSearchInput', 'manualScanInput', 'sidebarQuickFilter', 'quickSearchInput']);
            const box = document.createElement('div');
            box.id = 'nxGlobalSearchSuggest';
            box.className = 'shadow-lg border rounded-3 bg-white position-fixed d-none';
            box.style.cssText = 'z-index: 1085; max-height: 260px; overflow-y: auto; font-size: 0.8rem;';
            document.body.appendChild(box);

            let activeInput = null;

            const collectItemsForInput = (inp) => {
                if (inp.id === 'barcodeItemFilterInput') {
                    const sel = document.getElementById('itemSelect');
                    if (!sel) return [];
                    return Array.from(sel.options).map(o => ({
                        value: o.dataset.code || o.textContent.trim(),
                        title: o.textContent.trim(),
                        sub: 'Nhấn để chọn in tem',
                        selectVal: o.value
                    }));
                }
                const card = inp.closest('.clean-card, .card') || document;
                const tbl = card.querySelector('table');
                if (!tbl || !tbl.tBodies.length) return [];
                const seen = new Set();
                const list = [];
                Array.from(tbl.tBodies[0].rows).forEach(tr => {
                    if (isPlaceholderRow(tr)) return;
                    const cells = Array.from(tr.cells).map(td => td.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean);
                    if (!cells.length) return;
                    const primary = cells[0].split(' ')[0].trim();
                    const title = cells.slice(0, 2).join(' — ');
                    const sub = cells.slice(2, 4).join(' • ');
                    const key = primary + '|' + title;
                    if (!seen.has(key) && primary) {
                        seen.add(key);
                        list.push({ value: primary, title: title, sub: sub });
                    }
                });
                return list;
            };

            const positionBox = (inp) => {
                const r = inp.getBoundingClientRect();
                box.style.left = r.left + 'px';
                box.style.top = (r.bottom + 4) + 'px';
                box.style.width = Math.max(260, r.width) + 'px';
            };

            const showForInput = (inp, forceAll = false) => {
                const items = collectItemsForInput(inp);
                if (!items.length) { box.classList.add('d-none'); return; }
                const nq = forceAll ? '' : normalize(inp.value);
                const filtered = items.filter(it => !nq || normalize(it.title + ' ' + it.sub).includes(nq)).slice(0, 25);
                activeInput = inp;
                positionBox(inp);

                if (!filtered.length) {
                    box.innerHTML = '<div class="p-3 text-center text-muted small">Không có kết quả phù hợp.</div>';
                } else {
                    box.innerHTML = filtered.map((it, idx) => `
                        <button type="button" class="dropdown-item py-2 px-3 border-bottom text-wrap nx-gs-item" data-idx="${idx}">
                            <div class="fw-semibold text-dark text-truncate">${it.title}</div>
                            ${it.sub ? `<div class="text-muted small text-truncate">${it.sub}</div>` : ''}
                        </button>
                    `).join('');
                    box.querySelectorAll('.nx-gs-item').forEach((btn, idx) => {
                        btn.addEventListener('mousedown', (e) => {
                            e.preventDefault();
                            const picked = filtered[idx];
                            if (!picked) return;
                            if (inp.id === 'barcodeItemFilterInput' && picked.selectVal) {
                                inp.value = picked.value;
                                const sel = document.getElementById('itemSelect');
                                if (sel) { sel.value = picked.selectVal; if (typeof onItemChange === 'function') onItemChange(); }
                            } else {
                                inp.value = picked.value;
                                inp.dispatchEvent(new Event('input', { bubbles: true }));
                                inp.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
                                const form = inp.closest('form');
                                if (form && (form.method || '').toLowerCase() === 'get' && !inp.hasAttribute('onkeyup') && !inp.hasAttribute('oninput')) {
                                    form.submit();
                                }
                            }
                            box.classList.add('d-none');
                        });
                    });
                }
                box.classList.remove('d-none');
            };

            document.querySelectorAll('.main-content input[type="text"], .main-content input[type="search"], .main-content input:not([type])').forEach(inp => {
                if (skipIds.has(inp.id) || inp.classList.contains('line-item-search') || inp.closest('.modal')) return;
                const ph = (inp.getAttribute('placeholder') || '').toLowerCase();
                if (!ph.includes('tìm') && !ph.includes('mã') && !ph.includes('lọc') && !ph.includes('search') && inp.name !== 'q') return;
                inp.setAttribute('autocomplete', 'off');
                inp.addEventListener('focus', () => showForInput(inp, false));
                inp.addEventListener('click', () => showForInput(inp, false));
                inp.addEventListener('dblclick', () => showForInput(inp, true));
                inp.addEventListener('input', () => showForInput(inp, false));
            });

            document.addEventListener('mousedown', (e) => {
                if (activeInput && !box.contains(e.target) && e.target !== activeInput) {
                    box.classList.add('d-none');
                }
            });
            window.addEventListener('scroll', () => {
                if (activeInput && !box.classList.contains('d-none')) positionBox(activeInput);
            }, true);
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.table-responsive').forEach(enhance);
            const binGrid = document.getElementById('binGridContainer');
            if (binGrid) enhanceBinGrid(binGrid);
            initUniversalSearchSuggestions();
        });
    })();
</script>
<!-- Cute Free-Roaming 4-Angle Cow-Cat Mascot -->
<style>
@keyframes catCapturedHop {
    0%, 100% { transform: translateY(0) rotate(-3deg) scale(1); }
    50% { transform: translateY(-4px) rotate(3deg) scale(1.04); }
}
@keyframes catPanicShake {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    25% { transform: translate(-2px, -1px) rotate(-3deg); }
    75% { transform: translate(2px, -1px) rotate(3deg); }
}
.cute-cats-stage {
    z-index: 1080 !important;
}
.cute-cat-runner {
    cursor: grab;
    user-select: none;
    -webkit-user-select: none;
}
.cute-cat-runner.is-dragging {
    cursor: grabbing !important;
    transition: none !important;
}
.cute-cat-runner.is-captured {
    z-index: 1085 !important;
}
.cute-cat-runner.is-captured .cute-cat-img {
    animation: catCapturedHop 0.5s infinite ease-in-out !important;
    filter: drop-shadow(0 2px 10px rgba(244, 63, 94, 0.55)) !important;
}
.cute-cat-runner.is-deadline .cute-cat-img {
    animation: catPanicShake 0.45s infinite ease-in-out !important;
    filter: drop-shadow(0 0 10px rgba(245, 158, 11, 0.9)) !important;
}
</style>
<div class="cute-cats-stage" aria-hidden="true">
    <div class="cute-cat-runner" id="cowCatMascot" title="Bấm để trêu hoặc kéo bé mèo đi khắp trang web!">
        <img src="assets/img/cat-front.png?v=1" alt="Mèo bò sữa cute" class="cute-cat-img" draggable="false">
    </div>
</div>
<script>
(function () {
    const cat = document.getElementById('cowCatMascot');
    if (!cat) return;

    let x = Math.max(40, Math.min(window.innerWidth - 110, window.innerWidth * 0.72));
    let y = Math.max(80, Math.min(window.innerHeight - 110, window.innerHeight * 0.75));
    let vx = (Math.random() > 0.5 ? 1 : -1) * 1.15;
    let vy = (Math.random() > 0.5 ? 1 : -1) * 0.7;
    let state = 'walk'; // walk | zoom | sleep | sulk | pretend | phone | break | work | blanket | starry | water
    let hovered = false;
    let dragging = false;
    let movedDuringDrag = false;
    let dragOffX = 0, dragOffY = 0;
    let teaseCount = 0;
    let energy = 100;
    let stateTimer = null;
    let spinning360 = false;

    const catImg = cat.querySelector('.cute-cat-img');
    const SPRITES = {
        front:   'assets/img/cat-front.png?v=1',
        left:    'assets/img/cat-left.png?v=1',
        right:   'assets/img/cat-right.png?v=1',
        back:    'assets/img/cat-back.png?v=1',
        phone:   'assets/img/phone-cat.png?v=2',
        break:   'assets/img/cat-break.png?v=1',
        work:    'assets/img/cat-work.png?v=1',
        blanket: 'assets/img/cat-blanket.png?v=1',
        starry:  'assets/img/cat-starry.png?v=1',
        water:    'assets/img/cat-water.png?v=1',
        sleep:    'assets/img/cat-sleep.png?v=1',
        captured: 'assets/img/cat-help.png?v=1',
        deadline: 'assets/img/cat-work.png?v=1'
    };
    Object.values(SPRITES).forEach(src => { const i = new Image(); i.src = src; });

    function setSprite(key) {
        if (catImg && SPRITES[key] && catImg.dataset.sprite !== key) {
            catImg.dataset.sprite = key;
            catImg.src = SPRITES[key];
        }
    }

    function updateDirectionSprite() {
        if (spinning360) return;
        if (SPRITES[state]) { setSprite(state); return; }
        if (state === 'sulk') { setSprite('back'); return; }
        if (hovered || state === 'sleep' || state === 'pretend') { setSprite('front'); return; }
        if (vy < -0.45 && Math.abs(vx) < 0.55) { setSprite('back'); return; }
        if (vx > 0.3) { setSprite('right'); return; }
        if (vx < -0.3) { setSprite('left'); return; }
        setSprite('front');
    }

    function say(text, ms = 1600) {
        document.querySelectorAll('.cat-speech-bubble').forEach(b => b.remove());
        const r = cat.getBoundingClientRect();
        const bubble = document.createElement('div');
        bubble.className = 'cat-speech-bubble';
        bubble.textContent = text;
        bubble.style.left = Math.min(window.innerWidth - 120, Math.max(120, r.left + r.width / 2)) + 'px';
        bubble.style.top = (state === 'captured' ? Math.max(72, r.bottom + 24) : Math.max(42, r.top - 8)) + 'px';
        bubble.style.zIndex = '1090';
        document.body.appendChild(bubble);
        setTimeout(() => {
            bubble.style.opacity = '0';
            bubble.style.transform = 'translate(-50%, -130%)';
            setTimeout(() => bubble.remove(), 260);
        }, ms);
    }

    let panicTimer = null;
    let deadlineTimer = null;
    let captureClicks = 0;
    let captureAnchorX = 0;
    let capturedPhase = 'run'; // run | beg | hide | cry

    function getTopbarBottom() {
        const nav = document.querySelector('.app-navbar');
        return nav ? nav.getBoundingClientRect().bottom : 64;
    }

    function isInTopbar() {
        return y < getTopbarBottom() - 16;
    }

    function spawnFloatingIcon(icon) {
        const r = cat.getBoundingClientRect();
        const el = document.createElement('span');
        el.className = 'cat-zzz';
        el.textContent = icon;
        el.style.fontSize = '15px';
        el.style.zIndex = '1090';
        el.style.left = (r.left + 14 + Math.random() * 34) + 'px';
        el.style.top = (r.top + 6) + 'px';
        document.body.appendChild(el);
        requestAnimationFrame(() => {
            el.style.transform = `translate(${(Math.random() - 0.5) * 28}px, -32px) scale(1.25)`;
            el.style.opacity = '0';
        });
        setTimeout(() => el.remove(), 1100);
    }

    function stopPanicLoop() {
        if (panicTimer) {
            clearInterval(panicTimer);
            panicTimer = null;
        }
        captureClicks = 0;
        capturedPhase = 'run';
    }

    function stopDeadlineLoop() {
        if (deadlineTimer) {
            clearInterval(deadlineTimer);
            deadlineTimer = null;
        }
    }

    function startDeadlineLoop() {
        stopDeadlineLoop();
        const warnings = [
            'CHẠY DEADLINE ĐI MÁ ƠI!! ⏰🔥',
            'Còn ngồi trêu mèo hả?! LÀM VIỆC ĐIII 💻🚨',
            'Deadline dí tới mông rồi kìa!! 🏃💨',
            'Dẹp chơi đi, chạy deadline lẹ lên!! ⏳🔥',
            'Hết giờ rồi!! Mở code / báo cáo lên làm ngay!! 📢💻'
        ];
        let wIdx = 0;
        say(warnings[0], 1800);
        deadlineTimer = setInterval(() => {
            if (state !== 'deadline') { stopDeadlineLoop(); return; }
            wIdx = (wIdx + 1) % warnings.length;
            say(warnings[wIdx], 1800);
        }, 2100);
    }

    function startPanicLoop() {
        stopPanicLoop();
        captureAnchorX = x;
        const stages = [
            { sprite: 'captured', phase: 'run',  icon: '🆘', msg: 'Cứu bé với!! Bị bắt lên thanh bar rồi 🆘😭' },
            { sprite: 'starry',   phase: 'beg',  icon: '✨', msg: 'Kéo tui xuống đi mà... năn nỉ đó ✨🥺' },
            { sprite: 'back',     phase: 'hide', icon: '💢', msg: 'Úp mặt dỗi luôn, thả tui raaa 😤' },
            { sprite: 'blanket',  phase: 'cry',  icon: '💧', msg: 'Lạnh quá hu hu... cứu mèo bò sữa 🧣😿' }
        ];
        let sIdx = 0;
        capturedPhase = stages[0].phase;
        setSprite(stages[0].sprite);
        say(stages[0].msg, 1800);
        spawnFloatingIcon(stages[0].icon);

        panicTimer = setInterval(() => {
            if (state !== 'captured') { stopPanicLoop(); return; }
            if (dragging) return;
            sIdx = (sIdx + 1) % stages.length;
            const cur = stages[sIdx];
            capturedPhase = cur.phase;
            setSprite(cur.sprite);
            say(cur.msg, 1800);
            spawnFloatingIcon(cur.icon);
        }, 2400);
    }

    function setCatState(next, durationMs = 0) {
        clearTimeout(stateTimer);
        if (next !== 'captured') stopPanicLoop();
        if (next !== 'deadline') stopDeadlineLoop();
        state = next;
        cat.classList.remove('is-sleeping', 'is-sulking', 'is-pretend', 'is-phone', 'is-break', 'is-captured', 'is-deadline');
        if (next === 'sleep') cat.classList.add('is-sleeping');
        if (next === 'sulk') cat.classList.add('is-sulking');
        if (next === 'pretend') cat.classList.add('is-pretend');
        if (next === 'phone') cat.classList.add('is-phone');
        if (next === 'break') cat.classList.add('is-break');
        if (next === 'captured') cat.classList.add('is-captured');
        if (next === 'deadline') cat.classList.add('is-deadline');
        updateDirectionSprite();
        if (durationMs > 0) {
            stateTimer = setTimeout(() => {
                if (state === next) {
                    energy = 100;
                    teaseCount = Math.max(0, teaseCount - 2);
                    setCatState('walk');
                }
            }, durationMs);
        }
    }

    function applyPos() {
        cat.style.transform = `translate3d(${x.toFixed(1)}px, ${y.toFixed(1)}px, 0)`;
    }
    applyPos();
    updateDirectionSprite();

    let lastPawAt = 0;
    let lastZzzAt = 0;
    function step(ts) {
        if (!dragging && !document.body.classList.contains('bling-off')) {
            if ((state === 'walk' || state === 'zoom') && !hovered) {
                const mul = state === 'zoom' ? 1.85 : 1;
                x += vx * mul;
                y += vy * mul;
                energy = Math.max(0, energy - (state === 'zoom' ? 0.09 : 0.03));

                const maxX = Math.max(60, window.innerWidth - 80);
                const maxY = Math.max(80, window.innerHeight - 95);
                if (x <= 12) { x = 12; vx = Math.abs(vx); }
                else if (x >= maxX) { x = maxX; vx = -Math.abs(vx); }
                if (y <= 68) { y = 68; vy = Math.abs(vy); }
                else if (y >= maxY) { y = maxY; vy = -Math.abs(vy); }
                updateDirectionSprite();
                applyPos();

                if (ts - lastPawAt > (state === 'zoom' ? 320 : 620)) {
                    lastPawAt = ts;
                    const paw = document.createElement('span');
                    paw.className = 'cat-paw-trail';
                    paw.textContent = '🐾';
                    paw.style.left = (x + 24) + 'px';
                    paw.style.top = (y + 66) + 'px';
                    document.body.appendChild(paw);
                    requestAnimationFrame(() => { paw.style.opacity = '0'; paw.style.transform = 'scale(0.55)'; });
                    setTimeout(() => paw.remove(), 900);
                }

                if (energy <= 0) {
                    energy = 100;
                    triggerNextActivity(true);
                }
            } else if (state === 'captured') {
                if (capturedPhase === 'run') {
                    const minC = Math.max(12, captureAnchorX - 55);
                    const maxC = Math.min(Math.max(60, window.innerWidth - 80), captureAnchorX + 55);
                    if (Math.abs(vx) < 0.7 || Math.abs(vx) > 1.2) vx = (vx >= 0 ? 1 : -1) * 0.85;
                    x += vx * 0.75;
                    if (x <= minC) { x = minC; vx = Math.abs(vx); }
                    else if (x >= maxC) { x = maxC; vx = -Math.abs(vx); }
                }
                y = Math.max(2, Math.round((getTopbarBottom() - 62) / 2));
                applyPos();
            } else if (state === 'sleep' && ts - lastZzzAt > 1100) {
                lastZzzAt = ts;
                const r = cat.getBoundingClientRect();
                const z = document.createElement('span');
                z.className = 'cat-zzz';
                z.textContent = Math.random() > 0.5 ? 'Z' : 'z';
                z.style.fontSize = (11 + Math.random() * 6) + 'px';
                z.style.left = (r.left + 38) + 'px';
                z.style.top = (r.top + 4) + 'px';
                document.body.appendChild(z);
                requestAnimationFrame(() => {
                    z.style.transform = 'translate(14px, -28px) scale(1.25)';
                    z.style.opacity = '0';
                });
                setTimeout(() => z.remove(), 1200);
            }
        }
        requestAnimationFrame(step);
    }
    requestAnimationFrame(step);

    // Bộ bài xáo trộn đảm bảo TẤT CẢ hoạt động đều xuất hiện, không lặp liên tiếp
    const ACTIVITIES = [
        { key: 'work',    ms: 5200, rest: false, msgs: ['Đang kiểm kho trên laptop nha 💻', 'Chạy deadline xuất nhập kho... 🐹'] },
        { key: 'water',   ms: 4600, rest: true,  msgs: ['Nhớ uống nước đủ nha bạn ơi 💧', 'Uống ngụm nước giải lao cái đã~ ✨'] },
        { key: 'break',   ms: 5000, rest: true,  msgs: ['Nghỉ giải lao lắc vòng xíu nha 🌀', 'Thư giãn gân cốt nào~ 💪'] },
        { key: 'phone',   ms: 5400, rest: true,  msgs: ['Mệt quá nằm xem điện thoại đã 📱', 'Đợi xíu đang lướt TikTok 🐾'] },
        { key: 'starry',  ms: 4500, rest: false, msgs: ['Cho xin thêm cá khô đi mà ✨', 'Ngơ ngác thổi bong bóng mũi... 🫧'] },
        { key: 'blanket', ms: 5200, rest: true,  msgs: ['Lạnh quá quấn chăn hồng thôi 🧣', 'Ấm áp quá hổng muốn làm việc 💤'] },
        { key: 'sleep',   ms: 5400, rest: true,  msgs: ['Ngủ úp bụng khò khò nha~ 💤', 'Mơ thấy cá khô rùi... 🐟'] },
        { key: 'pretend', ms: 4200, rest: false, msgs: ['Giả vờ xỉu trốn kiểm kho 🐟', 'Ớ... xỉu ngang nè 😵'] },
        { key: 'zoom',    ms: 3200, rest: false, msgs: ['Chạy tăng tốc tuần tra kho! 💨', 'Lon ton đi kiểm hàng~ 🐾'] }
    ];
    let activityQueue = [];
    let lastActivityKey = null;

    function refillActivityQueue() {
        activityQueue = ACTIVITIES.slice();
        for (let i = activityQueue.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [activityQueue[i], activityQueue[j]] = [activityQueue[j], activityQueue[i]];
        }
        if (lastActivityKey && activityQueue.length > 1 && activityQueue[0].key === lastActivityKey) {
            const swapIdx = 1 + Math.floor(Math.random() * (activityQueue.length - 1));
            [activityQueue[0], activityQueue[swapIdx]] = [activityQueue[swapIdx], activityQueue[0]];
        }
    }

    function triggerNextActivity(preferRest = false) {
        if (activityQueue.length === 0) refillActivityQueue();
        let idx = 0;
        if (preferRest) {
            const restIdx = activityQueue.findIndex(a => a.rest);
            if (restIdx !== -1) idx = restIdx;
        }
        const act = activityQueue.splice(idx, 1)[0];
        lastActivityKey = act.key;
        if (act.key === 'zoom') {
            vx = (Math.random() > 0.5 ? 1 : -1) * (1.3 + Math.random() * 0.9);
            vy = (Math.random() > 0.5 ? 1 : -1) * (0.8 + Math.random() * 0.7);
        } else {
            vx = (Math.random() > 0.5 ? 1 : -1) * (0.8 + Math.random() * 0.8);
            vy = (Math.random() > 0.5 ? 1 : -1) * (0.5 + Math.random() * 0.6);
        }
        setCatState(act.key, act.ms);
        say(act.msgs[Math.floor(Math.random() * act.msgs.length)], 1800);
    }

    // Đổi tâm trạng tự nhiên theo hàng đợi không lặp
    setInterval(() => {
        if (hovered || dragging || state !== 'walk') return;
        triggerNextActivity(false);
    }, 3600);

    cat.addEventListener('mouseenter', () => {
        hovered = true;
        if (state === 'sulk') {
            setCatState('walk');
            teaseCount = 0;
            say('Hết dỗi rồi nè 💖', 1400);
        } else {
            updateDirectionSprite();
        }
    });
    cat.addEventListener('mouseleave', () => { if (!dragging) { hovered = false; updateDirectionSprite(); } });

    cat.addEventListener('mousedown', (e) => {
        e.preventDefault();
        dragging = true;
        movedDuringDrag = false;
        cat.classList.add('is-dragging');
        dragOffX = e.clientX - x;
        dragOffY = e.clientY - y;
    });
    window.addEventListener('mousemove', (e) => {
        if (!dragging) return;
        movedDuringDrag = true;
        x = Math.max(0, Math.min(window.innerWidth - 70, e.clientX - dragOffX));
        y = Math.max(0, Math.min(window.innerHeight - 85, e.clientY - dragOffY));
        applyPos();
        if (isInTopbar()) {
            if (state !== 'captured') {
                setCatState('captured');
                say('Ááá đừng bắt tui lên thanh trên mà!! 🆘', 1500);
            }
        } else if (state === 'captured') {
            setCatState('walk');
            say('Hú hồn! Thoát nạn rồi 😮‍💨💖', 1500);
        }
    });
    window.addEventListener('mouseup', () => {
        if (dragging) {
            dragging = false;
            hovered = false;
            cat.classList.remove('is-dragging');
            if (isInTopbar()) {
                y = Math.max(2, Math.round((getTopbarBottom() - 62) / 2));
                applyPos();
                setCatState('captured');
                startPanicLoop();
            } else if (state === 'captured') {
                setCatState('walk');
                say('Hú hồn! Thoát nạn rồi 😮‍💨💖', 1500);
            }
        }
    });

    cat.addEventListener('click', () => {
        if (movedDuringDrag) return;
        if (state === 'captured') {
            captureClicks++;
            if (captureClicks >= 2) {
                y = getTopbarBottom() + 14;
                applyPos();
                setCatState('walk');
                say('Yeah! Được giải cứu xuống rồi 💖🐾', 1800);
            } else {
                say('Cứu với!! Kéo tui xuống hoặc bấm thêm cái nữa để giải thoát 😭🆘', 1800);
            }
            return;
        }
        if (state === 'deadline') {
            say('Đừng bấm nữa! CHẠY DEADLINE NGAY ĐIII!! ⏰🔥', 1800);
            return;
        }
        teaseCount++;
        energy = Math.max(0, energy - 18);

        if (state === 'sleep') {
            setCatState('walk');
            energy = 70;
            say('Ủa gì dợ, đang ngủ ngon mà! 😿', 1700);
            return;
        }
        if (state === 'phone') {
            setCatState('walk');
            energy = 80;
            say('Đang xem clip hay mà, đừng làm phiền! 📱😾', 1800);
            return;
        }
        if (teaseCount >= 4) {
            setCatState('deadline', 15000);
            startDeadlineLoop();
            teaseCount = 0;
            return;
        }
        if (teaseCount === 3) {
            setCatState('phone', 6500);
            say('Chơi mệt quá... nằm xem điện thoại đây 📱', 1900);
            return;
        }

        cat.classList.remove('is-teased');
        void cat.offsetWidth;
        cat.classList.add('is-teased');
        spinning360 = true;
        ['front', 'right', 'back', 'left', 'front'].forEach((dir, stepIdx) => {
            setTimeout(() => setSprite(dir), stepIdx * 110);
        });
        setTimeout(() => {
            spinning360 = false;
            cat.classList.remove('is-teased');
            updateDirectionSprite();
        }, 650);
        vx = (Math.random() > 0.5 ? 1 : -1) * (1.5 + Math.random() * 1.1);
        vy = (Math.random() > 0.5 ? 1 : -1) * (0.9 + Math.random() * 0.8);

        const msg = teaseCount >= 3
            ? ['Mệt rồi á... cho nghỉ xíu 🥺', 'Chóng mặt quá sếp ơi 😵‍💫'][Math.floor(Math.random() * 2)]
            : ['Ụm bò~ Meoww! 🐮', 'Lêu lêu bắt hụt nè 😝', 'Đang tuần tra kho đó 📦', 'Bắt em đi nè ✨'][Math.floor(Math.random() * 4)];
        say(msg, 1600);
    });
})();
</script>

<!-- Floating Scroll to Top Button -->
<button type="button" id="scrollToTopBtn" title="Cuộn lên đầu trang" style="bottom: 20px; right: 24px;">
    <i class="bi bi-arrow-up-short"></i>
</button>

<!-- Global 3D Multi-Warehouse Digital Twin Modal -->
<div class="modal fade" id="digitalTwin3DModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg overflow-hidden" style="border-radius: 22px; background: #0f172a; color: #f8fafc;">
            <div class="modal-header border-bottom border-secondary border-opacity-25 px-4 py-3" style="background: rgba(15,23,42,0.9);">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary px-2 py-1 rounded-pill"><i class="bi bi-boxes me-1"></i>3D DIGITAL TWIN</span>
                    <h6 class="modal-title fw-bold mb-0 text-white">Mô Hình Không Gian 3D Mạng Lưới Đa Điểm Kho Thời Gian Thực</h6>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-light py-1 px-2" style="font-size: 0.75rem;" onclick="window.resetGlobalTwin3D()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Góc chuẩn
                    </button>
                    <button type="button" class="btn btn-sm btn-primary py-1 px-2" id="globalTwinAutoSpinBtn" style="font-size: 0.75rem;" onclick="window.toggleGlobalTwinSpin()">
                        <i class="bi bi-arrow-repeat me-1"></i>Tự xoay 360°
                    </button>
                    <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0 position-relative" style="height: 470px; overflow: hidden; background: radial-gradient(circle at 50% 35%, #1e293b 0%, #090d16 100%); cursor: grab;" id="globalTwinStage">
                <!-- Overlay Telemetry -->
                <div class="position-absolute top-0 start-0 p-3 z-3 d-flex flex-wrap gap-2" style="pointer-events: none;">
                    <div class="px-3 py-2 rounded-3" style="background: rgba(15,23,42,0.78); border: 1px solid rgba(255,255,255,0.12); backdrop-filter: blur(8px);">
                        <div class="text-white-50" style="font-size: 0.68rem;">TRẠNG THÁI ĐỒNG BỘ</div>
                        <div class="fw-bold text-success d-flex align-items-center gap-1" style="font-size: 0.82rem;">
                            <span class="spinner-grow spinner-grow-sm text-success" style="width: 8px; height: 8px;"></span> 3 Điểm kho trực tuyến
                        </div>
                    </div>
                    <div class="px-3 py-2 rounded-3" style="background: rgba(15,23,42,0.78); border: 1px solid rgba(255,255,255,0.12); backdrop-filter: blur(8px);">
                        <div class="text-white-50" style="font-size: 0.68rem;">TƯƠNG TÁC 3D</div>
                        <div class="fw-semibold text-white" style="font-size: 0.78rem;">Kéo chuột để xoay • Cuộn để phóng to</div>
                    </div>
                </div>

                <!-- 3D Network Floor -->
                <div id="globalTwinFloor" style="
                    width: 680px;
                    height: 420px;
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform-style: preserve-3d;
                    transform: translate(-50%, -50%) rotateX(56deg) rotateZ(-28deg) scale(0.95);
                    transition: transform 0.12s ease-out;
                    background:
                        linear-gradient(rgba(56, 189, 248, 0.12) 1px, transparent 1px),
                        linear-gradient(90deg, rgba(56, 189, 248, 0.12) 1px, transparent 1px),
                        rgba(15, 23, 42, 0.85);
                    background-size: 40px 40px;
                    border: 2px solid rgba(56, 189, 248, 0.45);
                    border-radius: 20px;
                    box-shadow: 0 35px 90px rgba(0,0,0,0.85), inset 0 0 50px rgba(37, 99, 235, 0.22);
                ">
                    <!-- Animated Laser Transfer Lines on Floor -->
                    <svg width="680" height="420" class="position-absolute top-0 start-0" style="pointer-events:none;">
                        <defs>
                            <linearGradient id="beamGrad1" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#38bdf8" stop-opacity="0.9"/>
                                <stop offset="50%" stop-color="#818cf8" stop-opacity="1"/>
                                <stop offset="100%" stop-color="#10b981" stop-opacity="0.9"/>
                            </linearGradient>
                        </defs>
                        <path d="M 140 130 Q 340 60 340 210" fill="none" stroke="url(#beamGrad1)" stroke-width="3" stroke-dasharray="8 6">
                            <animate attributeName="stroke-dashoffset" from="100" to="0" dur="3s" repeatCount="indefinite"/>
                        </path>
                        <path d="M 340 210 Q 450 320 535 280" fill="none" stroke="url(#beamGrad1)" stroke-width="3" stroke-dasharray="8 6">
                            <animate attributeName="stroke-dashoffset" from="100" to="0" dur="2.5s" repeatCount="indefinite"/>
                        </path>
                        <path d="M 140 130 Q 340 330 535 280" fill="none" stroke="rgba(245,158,11,0.55)" stroke-width="2" stroke-dasharray="6 6">
                            <animate attributeName="stroke-dashoffset" from="0" to="100" dur="4s" repeatCount="indefinite"/>
                        </path>
                    </svg>

                    <!-- Hub 1: KHO-HN -->
                    <div class="wh3d-cube level-safe" style="left: 95px; top: 85px; width: 92px; height: 92px; --cube-h: 72px;" onclick="window.location.href='locations.php'">
                        <div class="wh3d-cube-top d-flex flex-column justify-content-center align-items-center">
                            <span class="badge bg-dark bg-opacity-50 mb-1" style="font-size: 0.6rem;">MIỀN BẮC</span>
                            <div class="fw-bold" style="font-size: 0.82rem;">KHO-HN</div>
                            <div style="font-size: 0.68rem;">Sức chứa: 68%</div>
                        </div>
                        <div class="wh3d-cube-front"></div>
                        <div class="wh3d-cube-side"></div>
                    </div>

                    <!-- Hub 2: KHO-DN -->
                    <div class="wh3d-cube level-warn" style="left: 295px; top: 165px; width: 92px; height: 92px; --cube-h: 58px;" onclick="window.location.href='locations.php'">
                        <div class="wh3d-cube-top d-flex flex-column justify-content-center align-items-center">
                            <span class="badge bg-dark bg-opacity-50 mb-1" style="font-size: 0.6rem;">MIỀN TRUNG</span>
                            <div class="fw-bold" style="font-size: 0.82rem;">KHO-ĐN</div>
                            <div style="font-size: 0.68rem;">Sức chứa: 82%</div>
                        </div>
                        <div class="wh3d-cube-front"></div>
                        <div class="wh3d-cube-side"></div>
                    </div>

                    <!-- Hub 3: KHO-HCM -->
                    <div class="wh3d-cube level-safe" style="left: 490px; top: 235px; width: 92px; height: 92px; --cube-h: 84px;" onclick="window.location.href='locations.php'">
                        <div class="wh3d-cube-top d-flex flex-column justify-content-center align-items-center">
                            <span class="badge bg-dark bg-opacity-50 mb-1" style="font-size: 0.6rem;">MIỀN NAM</span>
                            <div class="fw-bold" style="font-size: 0.82rem;">KHO-HCM</div>
                            <div style="font-size: 0.68rem;">Sức chứa: 74%</div>
                        </div>
                        <div class="wh3d-cube-front"></div>
                        <div class="wh3d-cube-side"></div>
                    </div>
                </div>

                <!-- Bottom Legend & Quick Links -->
                <div class="position-absolute bottom-0 start-0 end-0 p-3 d-flex flex-wrap justify-content-between align-items-center z-3" style="background: linear-gradient(to top, rgba(9,13,22,0.95), transparent);">
                    <div class="d-flex align-items-center gap-3 small">
                        <span class="text-white-50"><i class="bi bi-circle-fill text-success me-1"></i>KHO-HN: Hoạt động tốt</span>
                        <span class="text-white-50"><i class="bi bi-circle-fill text-warning me-1"></i>KHO-ĐN: Mật độ cao</span>
                        <span class="text-white-50"><i class="bi bi-circle-fill text-info me-1"></i>Luồng điều chuyển liên kho</span>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="locations.php" class="btn btn-sm btn-primary fw-semibold"><i class="bi bi-grid-3x3-gap me-1"></i>Chi tiết Ô Kệ 3D</a>
                        <a href="warehouses.php" class="btn btn-sm btn-outline-light">Quản lý Điểm kho</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Dynamic Quick ROP & Pallet Calculator Modal -->
<div class="modal fade" id="quickCalcModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 18px;">
            <div class="modal-header border-bottom-0 pb-0 pt-3 px-4">
                <h6 class="modal-title fw-bold d-flex align-items-center gap-2">
                    <span class="badge bg-warning-subtle text-warning p-2 rounded-3"><i class="bi bi-calculator-fill"></i></span>
                    Máy Tính Động ROP & Quy Đổi Sức Chứa Kệ
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <div class="p-3 rounded-3 mb-3" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">Tiêu thụ TB / ngày</label>
                            <input type="number" id="calcDemand" class="form-control form-control-sm" value="25" min="1" oninput="window.runQuickCalc()">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">Lead Time giao hàng (ngày)</label>
                            <input type="number" id="calcLeadTime" class="form-control form-control-sm" value="4" min="1" oninput="window.runQuickCalc()">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">Tồn an toàn (Safety Stock)</label>
                            <input type="number" id="calcSafety" class="form-control form-control-sm" value="30" min="0" oninput="window.runQuickCalc()">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold mb-1">Định mức Đơn vị / Pallet</label>
                            <input type="number" id="calcPerPallet" class="form-control form-control-sm" value="40" min="1" oninput="window.runQuickCalc()">
                        </div>
                    </div>
                </div>
                <div class="row g-2 text-center">
                    <div class="col-6">
                        <div class="p-3 rounded-3 border bg-primary-subtle border-primary-subtle">
                            <div class="text-primary small fw-semibold">Điểm đặt hàng lại (ROP)</div>
                            <div class="fs-3 fw-bold text-primary my-1" id="calcRopResult">130</div>
                            <div class="text-muted" style="font-size: 0.7rem;">Công thức: (D × LT) + SS</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 border bg-success-subtle border-success-subtle">
                            <div class="text-success small fw-semibold">Số Pallet Ô Kệ Cần</div>
                            <div class="fs-3 fw-bold text-success my-1" id="calcPalletResult">4 Pallet</div>
                            <div class="text-muted" style="font-size: 0.7rem;">Tương đương sức chứa chuẩn</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        // 1. Top Scroll Progress Bar
        const progressBar = document.getElementById('topProgressBar');
        const scrollBtn = document.getElementById('scrollToTopBtn');

        window.addEventListener('scroll', function () {
            const winScroll = document.body.scrollTop || document.documentElement.scrollTop;
            const height = document.documentElement.scrollHeight - document.documentElement.clientHeight;
            const scrolled = (height > 0) ? (winScroll / height) * 100 : 0;
            if (progressBar) progressBar.style.width = scrolled + '%';

            // Toggle Scroll to top button
            if (scrollBtn) {
                if (winScroll > 280) {
                    scrollBtn.classList.add('show');
                } else {
                    scrollBtn.classList.remove('show');
                }
            }
        });

        if (scrollBtn) {
            scrollBtn.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }

        // 2. WebAudio Subtle 3D UI Sound Feedback
        let uiSoundEnabled = localStorage.getItem('wms_ui_sound') !== '0';
        function playSubtleClick(freq = 580, duration = 0.035) {
            if (!uiSoundEnabled) return;
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                if (!window._wmsAudioCtx) window._wmsAudioCtx = new Ctx();
                const ctx = window._wmsAudioCtx;
                if (ctx.state === 'suspended') ctx.resume();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(freq * 1.35, ctx.currentTime + duration);
                gain.gain.setValueAtTime(0.025, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + duration);
            } catch (err) {}
        }

        window.toggleUISound = function (btn) {
            uiSoundEnabled = !uiSoundEnabled;
            localStorage.setItem('wms_ui_sound', uiSoundEnabled ? '1' : '0');
            const label = document.getElementById('uiSoundLabel');
            const icon = document.getElementById('uiSoundIcon');
            if (label) label.textContent = 'Âm thanh Tương tác: ' + (uiSoundEnabled ? 'BẬT' : 'TẮT');
            if (icon) icon.className = uiSoundEnabled ? 'bi bi-volume-up-fill' : 'bi bi-volume-mute-fill';
            if (uiSoundEnabled) playSubtleClick(660, 0.06);
        };

        // 3. Ripple click effect on buttons + subtle sound
        document.addEventListener('click', function (e) {
            const target = e.target.closest('.btn, .nav-link, .search-trigger-btn, .dock-action-item');
            if (!target) return;

            playSubtleClick(540, 0.03);

            target.classList.add('btn-ripple');
            const rect = target.getBoundingClientRect();
            const circle = document.createElement('span');
            const diameter = Math.max(rect.width, rect.height);
            const radius = diameter / 2;

            circle.style.width = circle.style.height = `${diameter}px`;
            circle.style.left = `${e.clientX - rect.left - radius}px`;
            circle.style.top = `${e.clientY - rect.top - radius}px`;
            circle.classList.add('ripple-circle');

            const existing = target.querySelector('.ripple-circle');
            if (existing) existing.remove();

            target.appendChild(circle);
            setTimeout(() => circle.remove(), 600);
        });

        // 4. 3D Mouse-Tracking Perspective Tilt & Specular Spotlight on KPI Cards (.metric-box)
        function init3DTiltCards() {
            const cards = document.querySelectorAll('.metric-box');
            cards.forEach(card => {
                card.addEventListener('mousemove', (e) => {
                    const rect = card.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    const centerX = rect.width / 2;
                    const centerY = rect.height / 2;
                    const rotateX = ((y - centerY) / centerY) * -7;
                    const rotateY = ((x - centerX) / centerX) * 7;

                    card.style.setProperty('--mouse-x', `${(x / rect.width) * 100}%`);
                    card.style.setProperty('--mouse-y', `${(y / rect.height) * 100}%`);
                    card.style.transform = `perspective(900px) rotateX(${rotateX.toFixed(2)}deg) rotateY(${rotateY.toFixed(2)}deg) translateY(-5px) scale(1.015)`;
                });

                card.addEventListener('mouseleave', () => {
                    card.style.transform = '';
                });
            });
        }

        // 5. Floating 3D Command Dock toggle
        const dock = document.getElementById('floating3DDock');
        const orbBtn = document.getElementById('dockOrbBtn');
        if (dock && orbBtn) {
            orbBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                dock.classList.toggle('open');
                playSubtleClick(680, 0.05);
            });
            document.addEventListener('click', (e) => {
                if (!dock.contains(e.target)) {
                    dock.classList.remove('open');
                }
            });
        }

        // 6. Global 3D Digital Twin Modal Drag-to-Rotate & Auto-Spin
        const globalStage = document.getElementById('globalTwinStage');
        const globalFloor = document.getElementById('globalTwinFloor');
        let gRotX = 56, gRotZ = -28, gScale = 0.95;
        let gDragging = false, gLastX = 0, gLastY = 0, gSpinTimer = null;

        function renderGlobalTwin() {
            if (!globalFloor) return;
            globalFloor.style.transform = `translate(-50%, -50%) rotateX(${gRotX}deg) rotateZ(${gRotZ}deg) scale(${gScale})`;
        }

        window.resetGlobalTwin3D = function () {
            gRotX = 56; gRotZ = -28; gScale = 0.95;
            renderGlobalTwin();
        };

        window.toggleGlobalTwinSpin = function () {
            const btn = document.getElementById('globalTwinAutoSpinBtn');
            if (gSpinTimer) {
                clearInterval(gSpinTimer);
                gSpinTimer = null;
                if (btn) btn.classList.replace('btn-success', 'btn-primary');
            } else {
                gSpinTimer = setInterval(() => {
                    gRotZ = (gRotZ + 0.6) % 360;
                    renderGlobalTwin();
                }, 30);
                if (btn) btn.classList.replace('btn-primary', 'btn-success');
            }
        };

        if (globalStage && globalFloor) {
            globalStage.addEventListener('mousedown', (e) => {
                gDragging = true;
                gLastX = e.clientX;
                gLastY = e.clientY;
                globalStage.style.cursor = 'grabbing';
            });
            window.addEventListener('mousemove', (e) => {
                if (!gDragging) return;
                const dx = e.clientX - gLastX;
                const dy = e.clientY - gLastY;
                gLastX = e.clientX;
                gLastY = e.clientY;
                gRotZ += dx * 0.45;
                gRotX = Math.max(20, Math.min(78, gRotX - dy * 0.35));
                renderGlobalTwin();
            });
            window.addEventListener('mouseup', () => {
                if (gDragging) {
                    gDragging = false;
                    globalStage.style.cursor = 'grab';
                }
            });
            globalStage.addEventListener('wheel', (e) => {
                e.preventDefault();
                gScale = Math.max(0.6, Math.min(1.35, gScale + (e.deltaY < 0 ? 0.06 : -0.06)));
                renderGlobalTwin();
            }, { passive: false });
        }

        // 7. Quick ROP & Pallet Calculator
        window.runQuickCalc = function () {
            const d = parseFloat(document.getElementById('calcDemand')?.value || 0);
            const lt = parseFloat(document.getElementById('calcLeadTime')?.value || 0);
            const ss = parseFloat(document.getElementById('calcSafety')?.value || 0);
            const pp = Math.max(1, parseFloat(document.getElementById('calcPerPallet')?.value || 40));
            const rop = Math.round((d * lt) + ss);
            const pallets = Math.ceil(rop / pp);
            const ropEl = document.getElementById('calcRopResult');
            const palEl = document.getElementById('calcPalletResult');
            if (ropEl) ropEl.textContent = rop.toLocaleString('en-US');
            if (palEl) palEl.textContent = pallets + ' Pallet';
        };

        // 8. Fullscreen Mode (hỗ trợ đa trình duyệt + cập nhật nhãn)
        function syncFullscreenUi() {
            const isFs = !!(document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement);
            const lbl = document.getElementById('fullscreenLabel');
            const ico = document.getElementById('fullscreenIcon');
            if (lbl) lbl.textContent = isFs ? 'Thoát Toàn màn hình' : 'Chế độ Toàn màn hình';
            if (ico) ico.className = isFs ? 'bi bi-fullscreen-exit' : 'bi bi-arrows-fullscreen';
        }
        document.addEventListener('fullscreenchange', syncFullscreenUi);
        document.addEventListener('webkitfullscreenchange', syncFullscreenUi);

        window.toggleFullscreenMode = async function () {
            const isFs = !!(document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement);
            try {
                if (!isFs) {
                    const el = document.documentElement;
                    if (el.requestFullscreen) await el.requestFullscreen();
                    else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
                    else if (el.msRequestFullscreen) el.msRequestFullscreen();
                } else {
                    if (document.exitFullscreen) await document.exitFullscreen();
                    else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
                    else if (document.msExitFullscreen) document.msExitFullscreen();
                }
                syncFullscreenUi();
            } catch (err) {
                if (window.showToast) showToast('Trình duyệt không cho phép chuyển toàn màn hình tự động (có thể nhấn F11).', 'warning', 2500);
            }
            if (dock) dock.classList.remove('open');
        };

        // 9. Number Counter Animation for KPI Cards
        function animateCounters() {
            const counters = document.querySelectorAll('.metric-val, .fw-bold.fs-3, .fs-2.fw-bold');
            counters.forEach(c => {
                const text = c.innerText.trim().replace(/,/g, '');
                const targetNum = parseInt(text, 10);
                if (isNaN(targetNum) || targetNum <= 0 || targetNum > 50000) return;

                let current = 0;
                const step = Math.max(1, Math.floor(targetNum / 35));
                const timer = setInterval(() => {
                    current += step;
                    if (current >= targetNum) {
                        current = targetNum;
                        clearInterval(timer);
                    }
                    c.innerText = current.toLocaleString('en-US');
                }, 25);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                animateCounters();
                init3DTiltCards();
            });
        } else {
            animateCounters();
            init3DTiltCards();
        }

        // Tự động di chuyển các popup modal ra document.body để tránh bị lớp phủ backdrop làm tối đen
        document.querySelectorAll('.modal').forEach(m => {
            if (m.parentElement !== document.body) {
                document.body.appendChild(m);
            }
        });
    })();
</script>
<script>
    /* VIVID THEME: blobs nền, đổi bảng màu, hiện dần khi cuộn, tia lấp lánh khi bấm */
    (function () {
        // 1. Floating colour blobs
        const blobs = document.createElement('div');
        blobs.className = 'vivid-blobs';
        blobs.innerHTML = '<span></span><span></span><span></span>';
        document.body.prepend(blobs);

        // 2. Palette switcher
        const root = document.documentElement;
        const current = localStorage.getItem('wms_palette') || 'aurora';
        document.querySelectorAll('.v-swatch').forEach(sw => {
            if (sw.dataset.p === current) sw.classList.add('active');
            sw.addEventListener('click', (e) => {
                e.stopPropagation();
                const p = sw.dataset.p;
                if (p === 'aurora') root.removeAttribute('data-palette'); else root.setAttribute('data-palette', p);
                localStorage.setItem('wms_palette', p);
                document.querySelectorAll('.v-swatch').forEach(s => s.classList.toggle('active', s === sw));
                const r = sw.getBoundingClientRect();
                burst(r.left + r.width / 2, r.top + r.height / 2, 18);
            });
        });

        // 3. Scroll reveal for cards below the fold
        if ('IntersectionObserver' in window) {
            const io = new IntersectionObserver((entries) => {
                entries.forEach(en => { if (en.isIntersecting) { en.target.classList.add('v-in'); io.unobserve(en.target); } });
            }, { threshold: 0.08 });
            document.querySelectorAll('.main-content .card, .main-content .metric-box').forEach(el => {
                if (el.getBoundingClientRect().top > window.innerHeight) { el.classList.add('v-reveal'); io.observe(el); }
            });
        }

        // 4. Colourful click sparkles + Bling star bursts & mouse trail
        const colors = ['#6366f1', '#ec4899', '#f59e0b', '#06b6d4', '#10b981', '#a855f7', '#fb7185'];
        const glyphs = ['✦', '✧', '★', '◆', '✨'];
        let blingOn = localStorage.getItem('wms_bling') !== '0';
        if (!blingOn) document.body.classList.add('bling-off');

        // Twinkling background stars
        const starLayer = document.createElement('div');
        starLayer.className = 'bling-stars-layer';
        for (let i = 0; i < 20; i++) {
            const st = document.createElement('span');
            st.className = 'bling-star';
            st.textContent = glyphs[i % 3];
            st.style.left = (4 + (i * 19) % 92) + '%';
            st.style.top = (6 + (i * 23) % 88) + '%';
            st.style.color = colors[i % colors.length];
            st.style.fontSize = (10 + (i % 4) * 3) + 'px';
            st.style.setProperty('--dur', (2.6 + (i % 5) * 0.7) + 's');
            st.style.setProperty('--del', (-0.4 * i) + 's');
            starLayer.appendChild(st);
        }
        document.body.prepend(starLayer);

        window.toggleBlingMode = function () {
            blingOn = !blingOn;
            localStorage.setItem('wms_bling', blingOn ? '1' : '0');
            document.body.classList.toggle('bling-off', !blingOn);
            if (!blingOn) {
                document.querySelectorAll('.v-spark, .v-star-trail').forEach(el => el.remove());
            }
            const lbl = document.getElementById('blingLabel');
            if (lbl) lbl.textContent = 'Hiệu ứng Bling: ' + (blingOn ? 'BẬT' : 'TẮT');
            if (window.showToast) showToast('Hiệu ứng Bling: ' + (blingOn ? 'Đã bật' : 'Đã tắt'), 'info', 1600);
        };
        if (!blingOn) {
            const lbl = document.getElementById('blingLabel');
            if (lbl) lbl.textContent = 'Hiệu ứng Bling: TẮT';
        }

        function burst(x, y, n) {
            if (!blingOn) return;
            for (let i = 0; i < n; i++) {
                const useStar = i % 2 === 0;
                const s = document.createElement('span');
                s.className = useStar ? 'v-star-trail' : 'v-spark';
                if (useStar) {
                    s.textContent = glyphs[i % glyphs.length];
                    s.style.color = colors[i % colors.length];
                } else {
                    s.style.background = colors[i % colors.length];
                }
                s.style.left = x + 'px';
                s.style.top = y + 'px';
                document.body.appendChild(s);
                const ang = (Math.PI * 2 * i) / n + Math.random() * 0.5;
                const dist = 32 + Math.random() * 44;
                requestAnimationFrame(() => {
                    s.style.transform = `translate(${Math.cos(ang) * dist}px, ${Math.sin(ang) * dist}px) scale(0.3) rotate(${Math.round(Math.random() * 180)}deg)`;
                    s.style.opacity = '0';
                });
                setTimeout(() => s.remove(), 850);
            }
        }
        document.addEventListener('click', (e) => {
            const t = e.target.closest('.btn, .dock-orb-btn, .metric-box, .nav-link');
            if (t) burst(e.clientX, e.clientY, 10);
        });

        // 5. Flexible Sidebar: Mini-Rail Toggle & Live Quick Filter
        const miniBtn = document.getElementById('sidebarMiniToggle');
        if (localStorage.getItem('wms_sidebar_mini') === '1') {
            document.body.classList.add('sidebar-mini');
        }
        if (miniBtn) {
            miniBtn.addEventListener('click', () => {
                document.body.classList.toggle('sidebar-mini');
                localStorage.setItem('wms_sidebar_mini', document.body.classList.contains('sidebar-mini') ? '1' : '0');
            });
        }
        const sbFilter = document.getElementById('sidebarQuickFilter');
        if (sbFilter) {
            sbFilter.addEventListener('input', () => {
                const q = sbFilter.value.toLowerCase().trim();
                document.querySelectorAll('#sidebarMenu .nav-item').forEach(li => {
                    li.style.display = (q === '' || li.textContent.toLowerCase().includes(q)) ? '' : 'none';
                });
            });
        }
    })();
</script>
<script src="assets/js/nextgen.js?v=<?= @filemtime(__DIR__ . '/../public/assets/js/nextgen.js') ?: time() ?>"></script>
</body>

</html>