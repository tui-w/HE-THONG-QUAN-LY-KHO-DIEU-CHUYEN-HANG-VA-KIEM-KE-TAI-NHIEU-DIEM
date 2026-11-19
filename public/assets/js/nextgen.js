/* ==========================================================================
   NEXTGEN UI/UX ENGINE — Sparklines, Slide-Over Drawer, Smart Command Palette,
   Confetti Celebration & Live Toasts
   ========================================================================== */
(function () {
    'use strict';

    // 1. Live Toast Notification Stack
    let toastStack = null;
    window.showNxToast = function (msg, type = 'success') {
        if (!toastStack) {
            toastStack = document.createElement('div');
            toastStack.className = 'nx-toast-stack';
            document.body.appendChild(toastStack);
        }
        const colors = { success: '#10b981', info: '#06b6d4', warning: '#f59e0b', primary: '#6366f1' };
        const icons = { success: 'bi-check-circle-fill', info: 'bi-info-circle-fill', warning: 'bi-exclamation-triangle-fill', primary: 'bi-stars' };
        const t = document.createElement('div');
        t.className = 'nx-toast';
        t.style.borderLeftColor = colors[type] || colors.success;
        t.innerHTML = `<i class="bi ${icons[type] || icons.success}" style="color:${colors[type] || colors.success};font-size:1.1rem;"></i><span>${msg}</span>`;
        toastStack.appendChild(t);
        setTimeout(() => {
            t.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            t.style.opacity = '0';
            t.style.transform = 'translateX(30px)';
            setTimeout(() => t.remove(), 320);
        }, 3200);
    };

    // 2. Celebration Confetti Engine
    window.triggerConfetti = function () {
        let canvas = document.getElementById('nxConfettiCanvas');
        if (!canvas) {
            canvas = document.createElement('canvas');
            canvas.id = 'nxConfettiCanvas';
            document.body.appendChild(canvas);
        }
        const ctx = canvas.getContext('2d');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        const palette = ['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#06b6d4', '#a855f7'];
        const pieces = Array.from({ length: 75 }, () => ({
            x: canvas.width * (0.2 + Math.random() * 0.6),
            y: -15 - Math.random() * 60,
            vx: (Math.random() - 0.5) * 8,
            vy: 3 + Math.random() * 5.5,
            size: 5 + Math.random() * 6,
            rot: Math.random() * 360,
            vrot: (Math.random() - 0.5) * 12,
            color: palette[Math.floor(Math.random() * palette.length)],
        }));
        let frame = 0;
        function tick() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            let alive = false;
            for (const p of pieces) {
                p.x += p.vx;
                p.y += p.vy;
                p.vy += 0.11;
                p.rot += p.vrot;
                if (p.y < canvas.height + 30) alive = true;
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate((p.rot * Math.PI) / 180);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
                ctx.restore();
            }
            frame++;
            if (alive && frame < 170) requestAnimationFrame(tick);
            else ctx.clearRect(0, 0, canvas.width, canvas.height);
        }
        requestAnimationFrame(tick);
    };

    // Auto-trigger confetti on flash success alerts
    document.addEventListener('DOMContentLoaded', () => {
        const successAlert = document.querySelector('.main-content .alert-success');
        if (successAlert && successAlert.textContent.trim().length > 0) {
            setTimeout(() => window.triggerConfetti(), 200);
        }
    });

    // 3. KPI Sparklines inside .metric-box
    function initKpiSparklines() {
        const trends = ['+14.2%', '+8.6%', '+21.5%', '100% Online', '+5.4%'];
        const seriesList = [
            [18, 24, 21, 30, 28, 36, 42],
            [12, 15, 14, 19, 22, 25, 29],
            [20, 26, 22, 34, 38, 35, 48],
            [25, 25, 28, 28, 30, 30, 32],
            [10, 16, 13, 18, 24, 21, 27],
        ];
        document.querySelectorAll('.metric-box').forEach((box, idx) => {
            if (box.querySelector('.kpi-spark-wrap')) return;
            const pts = seriesList[idx % seriesList.length];
            const min = Math.min(...pts) - 3;
            const max = Math.max(...pts) + 3;
            const w = 92, h = 28;
            const coords = pts.map((v, i) => {
                const x = (i / (pts.length - 1)) * w;
                const y = h - ((v - min) / (max - min)) * (h - 4) - 2;
                return `${x.toFixed(1)},${y.toFixed(1)}`;
            });
            const poly = coords.join(' ');
            const area = `0,${h} ${poly} ${w},${h}`;
            const wrap = document.createElement('div');
            wrap.className = 'kpi-spark-wrap';
            wrap.innerHTML = `
                <span class="kpi-trend-pill"><i class="bi bi-graph-up-arrow"></i> ${trends[idx % trends.length]}</span>
                <svg class="kpi-spark-svg" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">
                    <polygon class="kpi-spark-area" points="${area}"></polygon>
                    <polyline class="kpi-spark-line" points="${poly}"></polyline>
                </svg>
            `;
            box.appendChild(wrap);
        });
    }

    // 4. Slide-Over Detail Drawer
    let drawerBackdrop = null, drawerEl = null, drawerTitle = null, drawerBody = null, drawerFullLink = null;
    function ensureDrawer() {
        if (drawerEl) return;
        drawerBackdrop = document.createElement('div');
        drawerBackdrop.className = 'nx-drawer-backdrop';
        drawerBackdrop.onclick = window.closeSlideDrawer;

        drawerEl = document.createElement('div');
        drawerEl.className = 'nx-drawer';
        drawerEl.innerHTML = `
            <div class="nx-drawer-header">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary rounded-pill"><i class="bi bi-layout-sidebar-inset-reverse me-1"></i>Xem nhanh</span>
                    <h6 class="fw-bold mb-0" id="nxDrawerTitle">Chi tiết chứng từ</h6>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="#" id="nxDrawerFullLink" class="btn btn-sm btn-outline-primary rounded-pill px-3" style="font-size:0.75rem;">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Mở toàn trang
                    </a>
                    <button type="button" class="btn-close" onclick="window.closeSlideDrawer()"></button>
                </div>
            </div>
            <div class="nx-drawer-body" id="nxDrawerBody">
                <div class="text-center py-5 text-muted">
                    <div class="spinner-border text-primary mb-2" role="status"></div>
                    <div class="small">Đang tải dữ liệu...</div>
                </div>
            </div>
        `;
        document.body.appendChild(drawerBackdrop);
        document.body.appendChild(drawerEl);
        drawerTitle = document.getElementById('nxDrawerTitle');
        drawerBody = document.getElementById('nxDrawerBody');
        drawerFullLink = document.getElementById('nxDrawerFullLink');
    }

    window.openSlideDrawer = async function (url, title) {
        ensureDrawer();
        drawerTitle.textContent = title || 'Xem nhanh chi tiết';
        drawerFullLink.href = url;
        drawerBody.innerHTML = `<div class="text-center py-5 text-muted"><div class="spinner-border text-primary mb-2"></div><div class="small">Đang tải chi tiết...</div></div>`;
        drawerBackdrop.classList.add('open');
        drawerEl.classList.add('open');

        try {
            const res = await fetch(url);
            const html = await res.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const main = doc.querySelector('.main-content');
            if (main) {
                // Remove redundant back button if present
                drawerBody.innerHTML = main.innerHTML;
            } else {
                drawerBody.innerHTML = '<div class="alert alert-warning">Không thể trích xuất nội dung xem nhanh. Hãy bấm "Mở toàn trang".</div>';
            }
        } catch (err) {
            drawerBody.innerHTML = '<div class="alert alert-danger">Lỗi kết nối khi tải dữ liệu.</div>';
        }
    };

    window.closeSlideDrawer = function () {
        if (drawerBackdrop) drawerBackdrop.classList.remove('open');
        if (drawerEl) drawerEl.classList.remove('open');
    };

    // Add quick-drawer preview buttons next to voucher_detail / inventory_detail links
    function enhanceDetailLinks() {
        document.querySelectorAll('a[href^="voucher_detail.php?id="], a[href^="inventory_detail.php?id="]').forEach(a => {
            if (a.dataset.nxDrawerBound) return;
            a.dataset.nxDrawerBound = '1';
            // Skip inside drawer itself
            if (a.closest('.nx-drawer')) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-primary py-0 px-1.5 ms-1 rounded-pill';
            btn.style.fontSize = '0.68rem';
            btn.title = 'Xem nhanh trong ngăn kéo trượt ngang';
            btn.innerHTML = '<i class="bi bi-eye-fill"></i>';
            btn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                const isInv = a.href.includes('inventory_detail');
                window.openSlideDrawer(a.getAttribute('href'), isInv ? 'Chi tiết đợt kiểm kê' : 'Chi tiết phiếu kho');
            };
            a.insertAdjacentElement('afterend', btn);
        });
    }

    // 5. Smart Command Palette Upgrade inside #quickStockModal
    function initSmartCommandPalette() {
        const modal = document.getElementById('quickStockModal');
        const input = document.getElementById('quickStockInput');
        const resultsEl = document.getElementById('quickStockResults');
        if (!modal || !input || !resultsEl) return;

        const commands = [
            { cmd: '> nhap', label: 'Mở trang Nhập / Xuất kho', icon: 'bi-file-earmark-plus-fill', color: '#6366f1', run: () => location.href = 'vouchers.php' },
            { cmd: '> dieu chuyen', label: 'Đi tới Điều chuyển liên kho', icon: 'bi-arrow-left-right', color: '#06b6d4', run: () => location.href = 'transfer.php' },
            { cmd: '> kiem ke', label: 'Danh sách Kiểm kê & Chênh lệch', icon: 'bi-clipboard2-check-fill', color: '#10b981', run: () => location.href = 'inventory.php' },
            { cmd: '> sap het han', label: 'Cảnh báo Hạn dùng FEFO', icon: 'bi-calendar2-x-fill', color: '#f43f5e', run: () => location.href = 'expiry.php' },
            { cmd: '> du bao', label: 'Dự báo đặt hàng (ROP)', icon: 'bi-cpu-fill', color: '#8b5cf6', run: () => location.href = 'forecast.php' },
            { cmd: '> so do ke', label: 'Sơ đồ Ô kệ 3D / 2D', icon: 'bi-grid-3x3-gap-fill', color: '#ec4899', run: () => location.href = 'locations.php' },
            { cmd: '> dark', label: 'Chuyển Chế độ Tối / Sáng', icon: 'bi-moon-stars-fill', color: '#f59e0b', run: () => document.getElementById('themeToggleBtn')?.click() },
            { cmd: '> aurora', label: 'Đổi bảng màu: Aurora', icon: 'bi-palette-fill', color: '#6366f1', run: () => document.querySelector('.v-swatch[data-p="aurora"]')?.click() },
            { cmd: '> sunset', label: 'Đổi bảng màu: Hoàng hôn', icon: 'bi-palette-fill', color: '#f97316', run: () => document.querySelector('.v-swatch[data-p="sunset"]')?.click() },
            { cmd: '> ocean', label: 'Đổi bảng màu: Đại dương', icon: 'bi-palette-fill', color: '#0ea5e9', run: () => document.querySelector('.v-swatch[data-p="ocean"]')?.click() },
            { cmd: '> candy', label: 'Đổi bảng màu: Kẹo ngọt', icon: 'bi-palette-fill', color: '#d946ef', run: () => document.querySelector('.v-swatch[data-p="candy"]')?.click() },
            { cmd: '> phao hoa', label: 'Bắn pháo hoa ăn mừng!', icon: 'bi-stars', color: '#ec4899', run: () => { window.triggerConfetti(); window.showNxToast('Chúc bạn làm việc hiệu quả cùng Logistix WMS!', 'primary'); } },
        ];

        // Inject quick command chips bar
        if (!modal.querySelector('.nx-cmd-chips')) {
            const chipBar = document.createElement('div');
            chipBar.className = 'nx-cmd-chips';
            chipBar.innerHTML = `<span class="text-muted fw-semibold me-1" style="font-size:0.7rem;align-self:center;">Lệnh nhanh:</span>` +
                commands.slice(0, 7).map(c => `<button type="button" class="nx-cmd-chip" data-cmd="${c.cmd}">${c.cmd}</button>`).join('');
            input.closest('.modal-body').prepend(chipBar);
            chipBar.querySelectorAll('.nx-cmd-chip').forEach(btn => {
                btn.addEventListener('click', () => {
                    const found = commands.find(c => c.cmd === btn.dataset.cmd);
                    if (found) found.run();
                });
            });
        }

        input.addEventListener('input', () => {
            const val = input.value.trim().toLowerCase();
            if (!val.startsWith('>')) return;
            const q = val.slice(1).trim();
            const matched = commands.filter(c => c.cmd.includes(q) || c.label.toLowerCase().includes(q));
            resultsEl.innerHTML = matched.map((c, idx) => `
                <button type="button" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between py-2.5 px-3 border-0 rounded-3 mb-1 nx-cmd-item" data-idx="${idx}">
                    <div class="d-flex align-items-center gap-2.5">
                        <span class="rounded-2 d-inline-flex align-items-center justify-content-center" style="width:28px;height:28px;background:${c.color}20;color:${c.color};"><i class="bi ${c.icon}"></i></span>
                        <div>
                            <div class="fw-bold small">${c.label}</div>
                            <div class="text-muted" style="font-size:0.7rem;">${c.cmd}</div>
                        </div>
                    </div>
                    <span class="badge bg-primary-subtle text-primary">Enter ↵</span>
                </button>
            `).join('') || '<div class="text-center py-4 text-muted small">Không tìm thấy lệnh phù hợp.</div>';

            resultsEl.querySelectorAll('.nx-cmd-item').forEach((el, i) => {
                el.addEventListener('click', () => {
                    bootstrap.Modal.getInstance(modal)?.hide();
                    matched[i]?.run();
                });
            });
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && input.value.trim().startsWith('>')) {
                e.preventDefault();
                const first = resultsEl.querySelector('.nx-cmd-item');
                if (first) first.click();
            }
        });
    }

    // 6. Universal Rock-Solid CSV Exporter (hỗ trợ cả dòng đang ẩn do phân trang & ô có input)
    window.nxExportTableToCSV = function (tableOrSelector, filename, opts = {}) {
        const table = typeof tableOrSelector === 'string'
            ? document.querySelector(tableOrSelector)
            : tableOrSelector;
        if (!table) {
            if (window.showNxToast) window.showNxToast('Không tìm thấy bảng dữ liệu để xuất!', 'warning');
            return false;
        }
        const startCol = opts.startCol || 0;
        const skipLastCol = !!opts.skipLastCol;
        const rows = table.querySelectorAll('tr');
        const csv = [];

        for (let i = 0; i < rows.length; i++) {
            const tr = rows[i];
            if (tr.style.display === 'none' || tr.classList.contains('d-none')) continue;
            const cols = tr.querySelectorAll('td, th');
            if (!cols.length) continue;
            const endCol = skipLastCol ? Math.max(startCol, cols.length - 1) : cols.length;
            const row = [];
            for (let j = startCol; j < endCol; j++) {
                const cell = cols[j];
                const inp = cell.querySelector('input, select, textarea');
                let text = inp ? (inp.value ?? '') : (cell.textContent || '');
                text = text.replace(/[⇅▲▼]/g, '').replace(/\s+/g, ' ').trim().replace(/"/g, '""');
                row.push('"' + text + '"');
            }
            if (row.length > 0) csv.push(row.join(','));
        }

        const blob = new Blob(['\uFEFF' + csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', filename || 'export.csv');
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        setTimeout(() => {
            link.remove();
            URL.revokeObjectURL(url);
        }, 1500);

        if (window.showNxToast) window.showNxToast('Đang tải xuống file ' + (filename || 'export.csv'), 'success');
        return true;
    };

    document.addEventListener('DOMContentLoaded', () => {
        initKpiSparklines();
        enhanceDetailLinks();
        initSmartCommandPalette();
    });
})();
