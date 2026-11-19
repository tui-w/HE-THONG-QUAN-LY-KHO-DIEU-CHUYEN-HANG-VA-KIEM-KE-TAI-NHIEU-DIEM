-- ========================================================
-- CƠ SỞ DỮ LIỆU ĐẦY ĐỦ: HỆ THỐNG QUẢN LÝ KHO ĐA ĐIỂM
-- Đề tài 10 - Phát triển phần mềm mã nguồn mở (FIT-DNU)
-- Tương thích: MySQL 8.x / MariaDB / SQLite 3 / PostgreSQL
-- ========================================================

-- Đề tài 10: Hệ thống Quản lý Kho, Điều chuyển hàng và Kiểm kê tại nhiều điểm
-- Tương thích SQLite 3 & MySQL 8+

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK(role IN ('admin', 'manager', 'staff', 'auditor')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS warehouses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    address VARCHAR(255) NOT NULL,
    phone VARCHAR(30),
    status VARCHAR(20) DEFAULT 'active' CHECK(status IN ('active', 'inactive')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_warehouses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    warehouse_id INTEGER NOT NULL,
    can_view INTEGER DEFAULT 1,
    can_create INTEGER DEFAULT 1,
    can_approve INTEGER DEFAULT 0,
    UNIQUE(user_id, warehouse_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS item_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    category_id INTEGER NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'Cái',
    min_stock INTEGER NOT NULL DEFAULT 5,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES item_categories(id)
);

CREATE TABLE IF NOT EXISTS batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER NOT NULL,
    batch_number VARCHAR(50) NOT NULL,
    manufacture_date DATE,
    expiry_date DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(item_id, batch_number),
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS vouchers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(50) NOT NULL UNIQUE,
    type VARCHAR(20) NOT NULL CHECK(type IN ('IN', 'OUT', 'TRANSFER', 'ADJUST_IN', 'ADJUST_OUT')),
    source_warehouse_id INTEGER,
    dest_warehouse_id INTEGER,
    status VARCHAR(20) NOT NULL DEFAULT 'DRAFT' CHECK(status IN ('DRAFT', 'CONFIRMED', 'CANCELLED')),
    created_by INTEGER NOT NULL,
    approved_by INTEGER,
    note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME,
    FOREIGN KEY (source_warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (dest_warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS voucher_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    voucher_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    batch_id INTEGER,
    quantity INTEGER NOT NULL CHECK(quantity > 0),
    unit_price REAL DEFAULT 0,
    note TEXT,
    FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (batch_id) REFERENCES batches(id)
);

-- Sổ phát sinh kho (Stock Movements Ledger) - Cốt lõi của Kỹ thuật 1 & 2
CREATE TABLE IF NOT EXISTS stock_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    movement_code VARCHAR(60) NOT NULL,
    voucher_id INTEGER NOT NULL,
    voucher_line_id INTEGER,
    warehouse_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    batch_id INTEGER,
    movement_type VARCHAR(20) NOT NULL CHECK(movement_type IN ('IN', 'OUT', 'TRANSFER_OUT', 'TRANSFER_IN', 'ADJUST_IN', 'ADJUST_OUT')),
    quantity_in INTEGER NOT NULL DEFAULT 0 CHECK(quantity_in >= 0),
    quantity_out INTEGER NOT NULL DEFAULT 0 CHECK(quantity_out >= 0),
    balance_after INTEGER NOT NULL DEFAULT 0 CHECK(balance_after >= 0),
    note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (voucher_id) REFERENCES vouchers(id),
    FOREIGN KEY (voucher_line_id) REFERENCES voucher_lines(id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (batch_id) REFERENCES batches(id)
);

CREATE TABLE IF NOT EXISTS stock_counts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(50) NOT NULL UNIQUE,
    warehouse_id INTEGER NOT NULL,
    title VARCHAR(150) NOT NULL,
    count_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'DRAFT' CHECK(status IN ('DRAFT', 'COMPLETED', 'ADJUSTED')),
    created_by INTEGER NOT NULL,
    completed_by INTEGER,
    note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (completed_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS stock_count_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stock_count_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    batch_id INTEGER,
    system_quantity INTEGER NOT NULL DEFAULT 0,
    actual_quantity INTEGER NOT NULL DEFAULT 0,
    difference INTEGER NOT NULL DEFAULT 0,
    adjustment_reason TEXT,
    adjustment_voucher_id INTEGER,
    FOREIGN KEY (stock_count_id) REFERENCES stock_counts(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (batch_id) REFERENCES batches(id),
    FOREIGN KEY (adjustment_voucher_id) REFERENCES vouchers(id)
);

-- Chỉ mục để tối ưu tốc độ tra cứu tồn kho và lịch sử thẻ kho
CREATE INDEX IF NOT EXISTS idx_stock_movements_wh_item ON stock_movements(warehouse_id, item_id);
CREATE INDEX IF NOT EXISTS idx_stock_movements_voucher ON stock_movements(voucher_id);
CREATE INDEX IF NOT EXISTS idx_vouchers_status ON vouchers(status);
CREATE INDEX IF NOT EXISTS idx_user_warehouses_user ON user_warehouses(user_id);


-- ========================================================
-- DỮ LIỆU MẪU ĐÃ GHI SỔ PHÁT SINH (>200 DÒNG)
-- ========================================================

-- --------------------------------------------------------
-- Bảng: users (6 bản ghi)
-- --------------------------------------------------------
INSERT INTO users (id, username, password_hash, full_name, role, created_at) VALUES ('1', 'tuoi', '$2y$12$tXtmY9HAGi7iuRs23Lzn/.hmbGxuWdIWZfSl51e32hN5qOO1PfEri', 'Đào Thị Tươi', 'admin', '2026-10-22 02:24:59');
INSERT INTO users (id, username, password_hash, full_name, role, created_at) VALUES ('2', 'manager', '$2y$12$MG46xsd5BeLtq/vQcx.4wu/VxCvxJ/oBiHGu5vyh1eD0XdzNWSMaO', 'Nguyễn Văn An', 'manager', '2026-10-22 02:24:59');
INSERT INTO users (id, username, password_hash, full_name, role, created_at) VALUES ('3', 'kho_hn', '$2y$12$c7/nVeWragfQsahI5B6URuUT3AxbNHqydDUY6jpFD2INI66KmIo6W', 'Trần Đức Minh', 'staff', '2026-10-22 02:24:59');
INSERT INTO users (id, username, password_hash, full_name, role, created_at) VALUES ('4', 'kho_dn', '$2y$12$lDEY3dCeH6n3p9o8ZHo35.ciNkugg5LKVrTdhY/.2EEGC/4Yu0INy', 'Hoàng Minh Tuấn', 'staff', '2026-10-22 02:24:59');
INSERT INTO users (id, username, password_hash, full_name, role, created_at) VALUES ('5', 'kho_hcm', '$2y$12$WzTTO0vO9l346c28m2gYJ.jI/No2Ju4tw4jhtfp.90DYr4BGI9I2i', 'Phạm Thùy Linh', 'staff', '2026-10-22 02:24:59');
INSERT INTO users (id, username, password_hash, full_name, role, created_at) VALUES ('6', 'auditor', '$2y$12$iNUxJDIjRmFXoW2xpRkiFuQL0M.52XQf168toukffgKy.V3LzNoYy', 'Lê Đình Huy', 'auditor', '2026-10-22 02:24:59');

-- --------------------------------------------------------
-- Bảng: warehouses (3 bản ghi)
-- --------------------------------------------------------
INSERT INTO warehouses (id, code, name, address, phone, status, created_at) VALUES ('1', 'KHO-HN', 'Kho Tổng Miền Bắc (Hà Nội)', 'Số 1 Phố Xốm, Hà Đông, Hà Nội', '024-3829-1111', 'active', '2026-10-22 02:24:59');
INSERT INTO warehouses (id, code, name, address, phone, status, created_at) VALUES ('2', 'KHO-DN', 'Kho Trung Chuyển Miền Trung (Đà Nẵng)', '45 Nguyễn Văn Linh, Hải Châu, Đà Nẵng', '023-6382-2222', 'active', '2026-10-22 02:24:59');
INSERT INTO warehouses (id, code, name, address, phone, status, created_at) VALUES ('3', 'KHO-HCM', 'Kho Phân Phối Miền Nam (TP.HCM)', '120 Võ Văn Tần, Quận 3, TP. Hồ Chí Minh', '028-3930-3333', 'active', '2026-10-22 02:24:59');

-- --------------------------------------------------------
-- Bảng: user_warehouses (12 bản ghi)
-- --------------------------------------------------------
INSERT INTO user_warehouses (id, user_id, warehouse_id, can_view, can_create, can_approve) VALUES ('1', '1', '1', '1', '1', '1');
INSERT INTO user_warehouses (id, user_id, warehouse_id, can_view, can_create, can_approve) VALUES ('2', '1', '2', '1', '1', '1');
INSERT INTO user_warehouses (id, user_id, warehouse_id, can_view, can_create, can_approve) VALUES ('3', '1', '3', '1', '1', '1');
INSERT INTO user_warehouses (id, user_id, warehouse_id, can_view, can_create, can_approve) VALUES ('4', '2', '1', '1', '1', '1');
INSERT INTO user_warehouses (id, user_id, warehouse_id, can_view, can_create, can_approve) VALUES ('5', '2', '2', '1', '1', '1');
INSERT INTO user_warehouses (id, user_id, warehouse_id, can_view, can_create, can_approve) VALUES ('6', '2', '3', '1', '1', '1');
INSERT INTO user_warehouses (id, user_id, warehouse_id, can_view, can_create, can_approve) VALUES ('7', '3', '1', '1', '1', '0');
INSERT INTO user_warehouses (id, user_id, warehouse_id, can_view, can_create, can_approve) VALUES ('8', '4', '2', '1', '1', '0');
INSERT INTO user_warehouses (id, user_id, warehouse_id, can_view, can_create, can_approve) VALUES ('9', '5', '3', '1', '1', '0');
INSERT INTO user_warehouses (id, user_id, warehouse_id, can_view, can_create, can_approve) VALUES ('10', '6', '1', '1', '1', '0');
INSERT INTO user_warehouses (id, user_id, warehouse_id, can_view, can_create, can_approve) VALUES ('11', '6', '2', '1', '1', '0');
INSERT INTO user_warehouses (id, user_id, warehouse_id, can_view, can_create, can_approve) VALUES ('12', '6', '3', '1', '1', '0');

-- --------------------------------------------------------
-- Bảng: item_categories (4 bản ghi)
-- --------------------------------------------------------
INSERT INTO item_categories (id, code, name) VALUES ('1', 'MAY_TINH', 'Máy tính & Laptop');
INSERT INTO item_categories (id, code, name) VALUES ('2', 'PHU_KIEN', 'Phụ kiện & Linh kiện');
INSERT INTO item_categories (id, code, name) VALUES ('3', 'THIET_BI_MANG', 'Thiết bị Mạng & Camera');
INSERT INTO item_categories (id, code, name) VALUES ('4', 'AM_THANH', 'Âm thanh & Multimedia');

-- --------------------------------------------------------
-- Bảng: items (30 bản ghi)
-- --------------------------------------------------------
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('1', 'LAP-DELL-15', 'Laptop Dell Inspiron 15 3520', '1', 'Chiếc', '5', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('2', 'LAP-THINKPAD-E14', 'Laptop ThinkPad E14 Gen 4', '1', 'Chiếc', '4', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('3', 'LAP-MAC-M2', 'Apple MacBook Air M2 13.6 inch', '1', 'Chiếc', '3', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('4', 'MOUSE-G102', 'Chuột Logitech G102 Lightsync', '2', 'Con', '10', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('5', 'MOUSE-M331', 'Chuột không dây Logitech M331 Silent', '2', 'Con', '10', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('6', 'KB-EK87', 'Bàn phím cơ DareU EK87 Brown Switch', '2', 'Chiếc', '8', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('7', 'KB-K2', 'Bàn phím không dây Keychron K2 V2', '2', 'Chiếc', '5', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('8', 'MON-DELL-24', 'Màn hình Dell S2421HN 24 inch IPS', '1', 'Màn', '5', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('9', 'MON-ASUS-27', 'Màn hình Gaming Asus VG279Q 27 inch', '1', 'Màn', '4', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('10', 'HEAD-SONY-XM5', 'Tai nghe Sony WH-1000XM5 Chống ồn', '4', 'Bộ', '3', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('11', 'HEAD-HYPERX', 'Tai nghe Gaming HyperX Cloud II', '4', 'Bộ', '6', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('12', 'CAB-TYPEC-100W', 'Cáp Type-C to Type-C Ugreen 100W 2m', '2', 'Sợi', '20', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('13', 'CAB-HDMI-21', 'Cáp HDMI 2.1 Baseus 8K UltraHD', '2', 'Sợi', '15', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('14', 'CHAR-ANKER-65W', 'Củ sạc nhanh Anker 735 GaN 65W', '2', 'Củ', '10', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('15', 'CHAR-BASEUS-30W', 'Củ sạc nhỏ gọn Baseus Compact 30W', '2', 'Củ', '15', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('16', 'RAM-FURY-16G', 'RAM Kingston Fury Beast DDR4 16GB 3200', '2', 'Thanh', '10', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('17', 'RAM-CORSAIR-32G', 'RAM Corsair Vengeance DDR5 32GB 5600', '2', 'Thanh', '6', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('18', 'SSD-SAM-980-500', 'Ổ cứng SSD Samsung 980 M.2 PCIe 500GB', '2', 'Chiếc', '8', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('19', 'SSD-NV2-1TB', 'Ổ cứng SSD Kingston NV2 M.2 NVMe 1TB', '2', 'Chiếc', '10', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('20', 'CAM-C922', 'Webcam Full HD Logitech C922 Pro', '3', 'Chiếc', '5', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('21', 'CAM-EZVIZ-C6N', 'Camera an ninh Wi-Fi EZVIZ C6N 1080P', '3', 'Chiếc', '8', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('22', 'ROUT-TPLINK-AX15', 'Router Wi-Fi 6 TP-Link Archer AX1500', '3', 'Bộ', '6', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('23', 'ROUT-ASUS-ZEN', 'Hệ thống Mesh Asus ZenWiFi XD4 (2 pack)', '3', 'Bộ', '4', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('24', 'HUB-TYPEC-8IN1', 'Hub chuyển đổi USB-C đa năng 8 in 1', '2', 'Chiếc', '12', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('25', 'CASE-XIGMATEK', 'Vỏ case máy tính Xigmatek NYX 3F Kính', '2', 'Thùng', '5', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('26', 'PSU-CV650', 'Nguồn máy tính Corsair CV650 80 Plus Bronze', '2', 'Chiếc', '5', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('27', 'COOL-AK400', 'Tản nhiệt CPU Deepcool AK400 Digital', '2', 'Hộp', '6', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('28', 'MIC-RODE-USB', 'Micro thu âm Podcast Rode NT-USB Mini', '4', 'Bộ', '4', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('29', 'UPS-APC-650', 'Bộ lưu điện UPS APC Back-UPS 650VA', '3', 'Bộ', '3', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');
INSERT INTO items (id, code, name, category_id, unit, min_stock, description, created_at) VALUES ('30', 'SPK-MICROLAB', 'Loa máy tính Bluetooth Microlab M-108BT', '4', 'Bộ', '5', 'Sản phẩm chính hãng', '2026-10-22 02:24:59');

-- --------------------------------------------------------
-- Bảng: batches (60 bản ghi)
-- --------------------------------------------------------
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('1', '13', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('2', '13', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('3', '12', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('4', '12', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('5', '20', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('6', '20', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('7', '21', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('8', '21', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('9', '25', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('10', '25', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('11', '14', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('12', '14', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('13', '15', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('14', '15', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('15', '27', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('16', '27', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('17', '11', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('18', '11', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('19', '10', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('20', '10', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('21', '24', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:24:59');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('22', '24', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('23', '6', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('24', '6', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('25', '7', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('26', '7', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('27', '1', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('28', '1', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('29', '3', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('30', '3', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('31', '2', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('32', '2', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('33', '28', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('34', '28', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('35', '9', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('36', '9', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('37', '8', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('38', '8', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('39', '4', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('40', '4', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('41', '5', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('42', '5', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('43', '26', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('44', '26', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('45', '17', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('46', '17', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('47', '16', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('48', '16', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('49', '23', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('50', '23', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('51', '22', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('52', '22', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('53', '30', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('54', '30', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('55', '19', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('56', '19', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('57', '18', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('58', '18', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('59', '29', 'L01-2026', '2026-01-15', '2028-01-15', '2026-10-22 02:25:00');
INSERT INTO batches (id, item_id, batch_number, manufacture_date, expiry_date, created_at) VALUES ('60', '29', 'L02-2026', '2026-03-01', '2028-03-01', '2026-10-22 02:25:00');

-- --------------------------------------------------------
-- Bảng: vouchers (11 bản ghi)
-- --------------------------------------------------------
INSERT INTO vouchers (id, code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES ('1', 'PN-202610-0001', 'IN', NULL, '1', 'CONFIRMED', '1', '2', 'Nhập hàng đầu kỳ cho kho HN', '2026-10-01 08:30:00', '2026-10-01 09:00:00');
INSERT INTO vouchers (id, code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES ('2', 'PN-202610-0002', 'IN', NULL, '2', 'CONFIRMED', '1', '2', 'Nhập hàng đầu kỳ cho kho DN', '2026-10-01 08:30:00', '2026-10-01 09:00:00');
INSERT INTO vouchers (id, code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES ('3', 'PN-202610-0003', 'IN', NULL, '3', 'CONFIRMED', '1', '2', 'Nhập hàng đầu kỳ cho kho HCM', '2026-10-01 08:30:00', '2026-10-01 09:00:00');
INSERT INTO vouchers (id, code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES ('4', 'DC-202610-0004', 'TRANSFER', '1', '2', 'CONFIRMED', '2', '2', 'Điều chuyển chi viện Kho Đà Nẵng', '2026-10-05 10:00:00', '2026-10-05 11:00:00');
INSERT INTO vouchers (id, code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES ('5', 'DC-202610-0005', 'TRANSFER', '1', '3', 'CONFIRMED', '2', '2', 'Điều chuyển chi viện Kho Hồ Chí Minh', '2026-10-08 14:00:00', '2026-10-08 14:30:00');
INSERT INTO vouchers (id, code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES ('6', 'PX-202610-0006', 'OUT', '1', NULL, 'CONFIRMED', '3', '2', 'Xuất kho giao đại lý miền Bắc', '2026-10-12 09:00:00', '2026-10-12 10:00:00');
INSERT INTO vouchers (id, code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES ('7', 'PX-202610-0007', 'OUT', '2', NULL, 'CONFIRMED', '4', '2', 'Xuất bán khu vực Miền Trung', '2026-10-14 15:00:00', '2026-10-14 16:00:00');
INSERT INTO vouchers (id, code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES ('8', 'PX-202610-0008', 'OUT', '3', NULL, 'CONFIRMED', '5', '2', 'Xuất bán dự án doanh nghiệp HCM', '2026-10-16 11:00:00', '2026-10-16 11:30:00');
INSERT INTO vouchers (id, code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES ('9', 'DC-ADJ-0009', 'ADJUST_IN', NULL, '1', 'CONFIRMED', '6', '2', 'Điều chỉnh tăng do thừa hàng kiểm kê KK-202610-0001', '2026-10-20 16:00:00', '2026-10-20 16:30:00');
INSERT INTO vouchers (id, code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES ('10', 'DC-ADJ-0010', 'ADJUST_OUT', '1', NULL, 'CONFIRMED', '6', '2', 'Điều chỉnh giảm do hao hụt kiểm kê KK-202610-0001', '2026-10-20 16:15:00', '2026-10-20 16:30:00');
INSERT INTO vouchers (id, code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES ('11', 'PN-202610-0011', 'IN', NULL, '1', 'CONFIRMED', '1', '2', 'Nhập lô hàng bổ sung L02-2026', '2026-10-21 08:30:00', '2026-10-21 09:00:00');

-- --------------------------------------------------------
-- Bảng: voucher_lines (182 bản ghi)
-- --------------------------------------------------------
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('1', '1', '1', '27', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('2', '1', '2', '31', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('3', '1', '3', '29', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('4', '1', '4', '39', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('5', '1', '5', '41', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('6', '1', '6', '23', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('7', '1', '7', '25', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('8', '1', '8', '37', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('9', '1', '9', '35', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('10', '1', '10', '19', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('11', '1', '11', '17', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('12', '1', '12', '3', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('13', '1', '13', '1', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('14', '1', '14', '11', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('15', '1', '15', '13', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('16', '1', '16', '47', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('17', '1', '17', '45', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('18', '1', '18', '57', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('19', '1', '19', '55', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('20', '1', '20', '5', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('21', '1', '21', '7', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('22', '1', '22', '51', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('23', '1', '23', '49', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('24', '1', '24', '21', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('25', '1', '25', '9', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('26', '1', '26', '43', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('27', '1', '27', '15', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('28', '1', '28', '33', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('29', '1', '29', '59', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('30', '1', '30', '53', '60', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('31', '2', '1', '27', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('32', '2', '2', '31', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('33', '2', '3', '29', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('34', '2', '4', '39', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('35', '2', '5', '41', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('36', '2', '6', '23', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('37', '2', '7', '25', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('38', '2', '8', '37', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('39', '2', '9', '35', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('40', '2', '10', '19', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('41', '2', '11', '17', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('42', '2', '12', '3', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('43', '2', '13', '1', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('44', '2', '14', '11', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('45', '2', '15', '13', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('46', '2', '16', '47', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('47', '2', '17', '45', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('48', '2', '18', '57', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('49', '2', '19', '55', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('50', '2', '20', '5', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('51', '2', '21', '7', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('52', '2', '22', '51', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('53', '2', '23', '49', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('54', '2', '24', '21', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('55', '2', '25', '9', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('56', '2', '26', '43', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('57', '2', '27', '15', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('58', '2', '28', '33', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('59', '2', '29', '59', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('60', '2', '30', '53', '40', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('61', '3', '1', '27', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('62', '3', '2', '31', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('63', '3', '3', '29', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('64', '3', '4', '39', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('65', '3', '5', '41', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('66', '3', '6', '23', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('67', '3', '7', '25', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('68', '3', '8', '37', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('69', '3', '9', '35', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('70', '3', '10', '19', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('71', '3', '11', '17', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('72', '3', '12', '3', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('73', '3', '13', '1', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('74', '3', '14', '11', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('75', '3', '15', '13', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('76', '3', '16', '47', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('77', '3', '17', '45', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('78', '3', '18', '57', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('79', '3', '19', '55', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('80', '3', '20', '5', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('81', '3', '21', '7', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('82', '3', '22', '51', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('83', '3', '23', '49', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('84', '3', '24', '21', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('85', '3', '25', '9', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('86', '3', '26', '43', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('87', '3', '27', '15', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('88', '3', '28', '33', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('89', '3', '29', '59', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('90', '3', '30', '53', '50', '500000', 'Nhập đợt 1');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('91', '4', '1', '27', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('92', '4', '2', '31', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('93', '4', '3', '29', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('94', '4', '4', '39', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('95', '4', '5', '41', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('96', '4', '6', '23', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('97', '4', '7', '25', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('98', '4', '8', '37', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('99', '4', '9', '35', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('100', '4', '10', '19', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('101', '4', '11', '17', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('102', '4', '12', '3', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('103', '4', '13', '1', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('104', '4', '14', '11', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('105', '4', '15', '13', '10', '500000', 'Chuyển HN -> DN');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('106', '5', '16', '47', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('107', '5', '17', '45', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('108', '5', '18', '57', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('109', '5', '19', '55', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('110', '5', '20', '5', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('111', '5', '21', '7', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('112', '5', '22', '51', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('113', '5', '23', '49', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('114', '5', '24', '21', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('115', '5', '25', '9', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('116', '5', '26', '43', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('117', '5', '27', '15', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('118', '5', '28', '33', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('119', '5', '29', '59', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('120', '5', '30', '53', '8', '500000', 'Chuyển HN -> HCM');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('121', '6', '1', '27', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('122', '6', '2', '31', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('123', '6', '3', '29', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('124', '6', '4', '39', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('125', '6', '5', '41', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('126', '6', '6', '23', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('127', '6', '7', '25', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('128', '6', '8', '37', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('129', '6', '9', '35', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('130', '6', '10', '19', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('131', '6', '11', '17', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('132', '6', '12', '3', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('133', '6', '13', '1', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('134', '6', '14', '11', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('135', '6', '15', '13', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('136', '6', '16', '47', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('137', '6', '17', '45', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('138', '6', '18', '57', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('139', '6', '19', '55', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('140', '6', '20', '5', '5', '650000', 'Xuất đại lý');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('141', '7', '1', '27', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('142', '7', '2', '31', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('143', '7', '3', '29', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('144', '7', '4', '39', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('145', '7', '5', '41', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('146', '7', '6', '23', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('147', '7', '7', '25', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('148', '7', '8', '37', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('149', '7', '9', '35', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('150', '7', '10', '19', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('151', '7', '11', '17', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('152', '7', '12', '3', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('153', '7', '13', '1', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('154', '7', '14', '11', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('155', '7', '15', '13', '4', '680000', 'Xuất bán lẻ');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('156', '8', '16', '47', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('157', '8', '17', '45', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('158', '8', '18', '57', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('159', '8', '19', '55', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('160', '8', '20', '5', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('161', '8', '21', '7', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('162', '8', '22', '51', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('163', '8', '23', '49', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('164', '8', '24', '21', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('165', '8', '25', '9', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('166', '8', '26', '43', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('167', '8', '27', '15', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('168', '8', '28', '33', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('169', '8', '29', '59', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('170', '8', '30', '53', '6', '720000', 'Xuất bán dự án');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('171', '9', '1', '27', '2', '0', 'Điều chỉnh thừa');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('172', '10', '4', '39', '1', '0', 'Điều chỉnh thiếu');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('173', '11', '1', '28', '20', '520000', 'Nhập bổ sung lô 2');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('174', '11', '2', '32', '20', '520000', 'Nhập bổ sung lô 2');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('175', '11', '3', '30', '20', '520000', 'Nhập bổ sung lô 2');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('176', '11', '4', '40', '20', '520000', 'Nhập bổ sung lô 2');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('177', '11', '5', '42', '20', '520000', 'Nhập bổ sung lô 2');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('178', '11', '6', '24', '20', '520000', 'Nhập bổ sung lô 2');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('179', '11', '7', '26', '20', '520000', 'Nhập bổ sung lô 2');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('180', '11', '8', '38', '20', '520000', 'Nhập bổ sung lô 2');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('181', '11', '9', '36', '20', '520000', 'Nhập bổ sung lô 2');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES ('182', '11', '10', '20', '20', '520000', 'Nhập bổ sung lô 2');

-- --------------------------------------------------------
-- Bảng: stock_movements (212 bản ghi)
-- --------------------------------------------------------
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('1', 'MV-000001', '1', '1', '1', '1', '27', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('2', 'MV-000002', '1', '2', '1', '2', '31', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('3', 'MV-000003', '1', '3', '1', '3', '29', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('4', 'MV-000004', '1', '4', '1', '4', '39', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('5', 'MV-000005', '1', '5', '1', '5', '41', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('6', 'MV-000006', '1', '6', '1', '6', '23', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('7', 'MV-000007', '1', '7', '1', '7', '25', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('8', 'MV-000008', '1', '8', '1', '8', '37', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('9', 'MV-000009', '1', '9', '1', '9', '35', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('10', 'MV-000010', '1', '10', '1', '10', '19', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('11', 'MV-000011', '1', '11', '1', '11', '17', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('12', 'MV-000012', '1', '12', '1', '12', '3', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('13', 'MV-000013', '1', '13', '1', '13', '1', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('14', 'MV-000014', '1', '14', '1', '14', '11', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('15', 'MV-000015', '1', '15', '1', '15', '13', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('16', 'MV-000016', '1', '16', '1', '16', '47', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('17', 'MV-000017', '1', '17', '1', '17', '45', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('18', 'MV-000018', '1', '18', '1', '18', '57', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('19', 'MV-000019', '1', '19', '1', '19', '55', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('20', 'MV-000020', '1', '20', '1', '20', '5', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('21', 'MV-000021', '1', '21', '1', '21', '7', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('22', 'MV-000022', '1', '22', '1', '22', '51', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('23', 'MV-000023', '1', '23', '1', '23', '49', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('24', 'MV-000024', '1', '24', '1', '24', '21', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('25', 'MV-000025', '1', '25', '1', '25', '9', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('26', 'MV-000026', '1', '26', '1', '26', '43', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('27', 'MV-000027', '1', '27', '1', '27', '15', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('28', 'MV-000028', '1', '28', '1', '28', '33', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('29', 'MV-000029', '1', '29', '1', '29', '59', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('30', 'MV-000030', '1', '30', '1', '30', '53', 'IN', '60', '0', '60', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('31', 'MV-000031', '2', '31', '2', '1', '27', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('32', 'MV-000032', '2', '32', '2', '2', '31', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('33', 'MV-000033', '2', '33', '2', '3', '29', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('34', 'MV-000034', '2', '34', '2', '4', '39', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('35', 'MV-000035', '2', '35', '2', '5', '41', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('36', 'MV-000036', '2', '36', '2', '6', '23', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('37', 'MV-000037', '2', '37', '2', '7', '25', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('38', 'MV-000038', '2', '38', '2', '8', '37', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('39', 'MV-000039', '2', '39', '2', '9', '35', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('40', 'MV-000040', '2', '40', '2', '10', '19', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('41', 'MV-000041', '2', '41', '2', '11', '17', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('42', 'MV-000042', '2', '42', '2', '12', '3', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('43', 'MV-000043', '2', '43', '2', '13', '1', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('44', 'MV-000044', '2', '44', '2', '14', '11', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('45', 'MV-000045', '2', '45', '2', '15', '13', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('46', 'MV-000046', '2', '46', '2', '16', '47', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('47', 'MV-000047', '2', '47', '2', '17', '45', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('48', 'MV-000048', '2', '48', '2', '18', '57', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('49', 'MV-000049', '2', '49', '2', '19', '55', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('50', 'MV-000050', '2', '50', '2', '20', '5', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('51', 'MV-000051', '2', '51', '2', '21', '7', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('52', 'MV-000052', '2', '52', '2', '22', '51', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('53', 'MV-000053', '2', '53', '2', '23', '49', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('54', 'MV-000054', '2', '54', '2', '24', '21', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('55', 'MV-000055', '2', '55', '2', '25', '9', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('56', 'MV-000056', '2', '56', '2', '26', '43', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('57', 'MV-000057', '2', '57', '2', '27', '15', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('58', 'MV-000058', '2', '58', '2', '28', '33', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('59', 'MV-000059', '2', '59', '2', '29', '59', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('60', 'MV-000060', '2', '60', '2', '30', '53', 'IN', '40', '0', '40', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('61', 'MV-000061', '3', '61', '3', '1', '27', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('62', 'MV-000062', '3', '62', '3', '2', '31', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('63', 'MV-000063', '3', '63', '3', '3', '29', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('64', 'MV-000064', '3', '64', '3', '4', '39', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('65', 'MV-000065', '3', '65', '3', '5', '41', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('66', 'MV-000066', '3', '66', '3', '6', '23', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('67', 'MV-000067', '3', '67', '3', '7', '25', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('68', 'MV-000068', '3', '68', '3', '8', '37', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('69', 'MV-000069', '3', '69', '3', '9', '35', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('70', 'MV-000070', '3', '70', '3', '10', '19', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('71', 'MV-000071', '3', '71', '3', '11', '17', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('72', 'MV-000072', '3', '72', '3', '12', '3', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('73', 'MV-000073', '3', '73', '3', '13', '1', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('74', 'MV-000074', '3', '74', '3', '14', '11', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('75', 'MV-000075', '3', '75', '3', '15', '13', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('76', 'MV-000076', '3', '76', '3', '16', '47', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('77', 'MV-000077', '3', '77', '3', '17', '45', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('78', 'MV-000078', '3', '78', '3', '18', '57', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('79', 'MV-000079', '3', '79', '3', '19', '55', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('80', 'MV-000080', '3', '80', '3', '20', '5', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('81', 'MV-000081', '3', '81', '3', '21', '7', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('82', 'MV-000082', '3', '82', '3', '22', '51', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('83', 'MV-000083', '3', '83', '3', '23', '49', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('84', 'MV-000084', '3', '84', '3', '24', '21', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('85', 'MV-000085', '3', '85', '3', '25', '9', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('86', 'MV-000086', '3', '86', '3', '26', '43', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('87', 'MV-000087', '3', '87', '3', '27', '15', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('88', 'MV-000088', '3', '88', '3', '28', '33', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('89', 'MV-000089', '3', '89', '3', '29', '59', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('90', 'MV-000090', '3', '90', '3', '30', '53', 'IN', '50', '0', '50', 'Ghi sổ nhập kho đầu kỳ', '2026-10-01 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('91', 'MV-000091', '4', '91', '1', '1', '27', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('92', 'MV-000092', '4', '91', '2', '1', '27', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('93', 'MV-000093', '4', '92', '1', '2', '31', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('94', 'MV-000094', '4', '92', '2', '2', '31', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('95', 'MV-000095', '4', '93', '1', '3', '29', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('96', 'MV-000096', '4', '93', '2', '3', '29', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('97', 'MV-000097', '4', '94', '1', '4', '39', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('98', 'MV-000098', '4', '94', '2', '4', '39', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('99', 'MV-000099', '4', '95', '1', '5', '41', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('100', 'MV-000100', '4', '95', '2', '5', '41', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('101', 'MV-000101', '4', '96', '1', '6', '23', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('102', 'MV-000102', '4', '96', '2', '6', '23', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('103', 'MV-000103', '4', '97', '1', '7', '25', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('104', 'MV-000104', '4', '97', '2', '7', '25', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('105', 'MV-000105', '4', '98', '1', '8', '37', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('106', 'MV-000106', '4', '98', '2', '8', '37', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('107', 'MV-000107', '4', '99', '1', '9', '35', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('108', 'MV-000108', '4', '99', '2', '9', '35', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('109', 'MV-000109', '4', '100', '1', '10', '19', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('110', 'MV-000110', '4', '100', '2', '10', '19', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('111', 'MV-000111', '4', '101', '1', '11', '17', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('112', 'MV-000112', '4', '101', '2', '11', '17', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('113', 'MV-000113', '4', '102', '1', '12', '3', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('114', 'MV-000114', '4', '102', '2', '12', '3', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('115', 'MV-000115', '4', '103', '1', '13', '1', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('116', 'MV-000116', '4', '103', '2', '13', '1', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('117', 'MV-000117', '4', '104', '1', '14', '11', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('118', 'MV-000118', '4', '104', '2', '14', '11', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('119', 'MV-000119', '4', '105', '1', '15', '13', 'TRANSFER_OUT', '0', '10', '50', 'Xuất điều chuyển sang KHO-DN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('120', 'MV-000120', '4', '105', '2', '15', '13', 'TRANSFER_IN', '10', '0', '50', 'Nhập điều chuyển từ KHO-HN', '2026-10-05 11:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('121', 'MV-000121', '5', '106', '1', '16', '47', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('122', 'MV-000122', '5', '106', '3', '16', '47', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('123', 'MV-000123', '5', '107', '1', '17', '45', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('124', 'MV-000124', '5', '107', '3', '17', '45', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('125', 'MV-000125', '5', '108', '1', '18', '57', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('126', 'MV-000126', '5', '108', '3', '18', '57', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('127', 'MV-000127', '5', '109', '1', '19', '55', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('128', 'MV-000128', '5', '109', '3', '19', '55', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('129', 'MV-000129', '5', '110', '1', '20', '5', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('130', 'MV-000130', '5', '110', '3', '20', '5', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('131', 'MV-000131', '5', '111', '1', '21', '7', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('132', 'MV-000132', '5', '111', '3', '21', '7', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('133', 'MV-000133', '5', '112', '1', '22', '51', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('134', 'MV-000134', '5', '112', '3', '22', '51', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('135', 'MV-000135', '5', '113', '1', '23', '49', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('136', 'MV-000136', '5', '113', '3', '23', '49', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('137', 'MV-000137', '5', '114', '1', '24', '21', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('138', 'MV-000138', '5', '114', '3', '24', '21', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('139', 'MV-000139', '5', '115', '1', '25', '9', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('140', 'MV-000140', '5', '115', '3', '25', '9', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('141', 'MV-000141', '5', '116', '1', '26', '43', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('142', 'MV-000142', '5', '116', '3', '26', '43', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('143', 'MV-000143', '5', '117', '1', '27', '15', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('144', 'MV-000144', '5', '117', '3', '27', '15', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('145', 'MV-000145', '5', '118', '1', '28', '33', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('146', 'MV-000146', '5', '118', '3', '28', '33', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('147', 'MV-000147', '5', '119', '1', '29', '59', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('148', 'MV-000148', '5', '119', '3', '29', '59', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('149', 'MV-000149', '5', '120', '1', '30', '53', 'TRANSFER_OUT', '0', '8', '52', 'Xuất điều chuyển sang KHO-HCM', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('150', 'MV-000150', '5', '120', '3', '30', '53', 'TRANSFER_IN', '8', '0', '58', 'Nhập điều chuyển từ KHO-HN', '2026-10-08 14:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('151', 'MV-000151', '6', '121', '1', '1', '27', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('152', 'MV-000152', '6', '122', '1', '2', '31', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('153', 'MV-000153', '6', '123', '1', '3', '29', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('154', 'MV-000154', '6', '124', '1', '4', '39', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('155', 'MV-000155', '6', '125', '1', '5', '41', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('156', 'MV-000156', '6', '126', '1', '6', '23', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('157', 'MV-000157', '6', '127', '1', '7', '25', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('158', 'MV-000158', '6', '128', '1', '8', '37', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('159', 'MV-000159', '6', '129', '1', '9', '35', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('160', 'MV-000160', '6', '130', '1', '10', '19', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('161', 'MV-000161', '6', '131', '1', '11', '17', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('162', 'MV-000162', '6', '132', '1', '12', '3', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('163', 'MV-000163', '6', '133', '1', '13', '1', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('164', 'MV-000164', '6', '134', '1', '14', '11', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('165', 'MV-000165', '6', '135', '1', '15', '13', 'OUT', '0', '5', '45', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('166', 'MV-000166', '6', '136', '1', '16', '47', 'OUT', '0', '5', '47', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('167', 'MV-000167', '6', '137', '1', '17', '45', 'OUT', '0', '5', '47', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('168', 'MV-000168', '6', '138', '1', '18', '57', 'OUT', '0', '5', '47', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('169', 'MV-000169', '6', '139', '1', '19', '55', 'OUT', '0', '5', '47', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('170', 'MV-000170', '6', '140', '1', '20', '5', 'OUT', '0', '5', '47', 'Xuất bán hàng', '2026-10-12 10:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('171', 'MV-000171', '7', '141', '2', '1', '27', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('172', 'MV-000172', '7', '142', '2', '2', '31', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('173', 'MV-000173', '7', '143', '2', '3', '29', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('174', 'MV-000174', '7', '144', '2', '4', '39', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('175', 'MV-000175', '7', '145', '2', '5', '41', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('176', 'MV-000176', '7', '146', '2', '6', '23', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('177', 'MV-000177', '7', '147', '2', '7', '25', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('178', 'MV-000178', '7', '148', '2', '8', '37', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('179', 'MV-000179', '7', '149', '2', '9', '35', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('180', 'MV-000180', '7', '150', '2', '10', '19', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('181', 'MV-000181', '7', '151', '2', '11', '17', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('182', 'MV-000182', '7', '152', '2', '12', '3', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('183', 'MV-000183', '7', '153', '2', '13', '1', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('184', 'MV-000184', '7', '154', '2', '14', '11', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('185', 'MV-000185', '7', '155', '2', '15', '13', 'OUT', '0', '4', '46', 'Xuất bán lẻ Miền Trung', '2026-10-14 16:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('186', 'MV-000186', '8', '156', '3', '16', '47', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('187', 'MV-000187', '8', '157', '3', '17', '45', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('188', 'MV-000188', '8', '158', '3', '18', '57', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('189', 'MV-000189', '8', '159', '3', '19', '55', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('190', 'MV-000190', '8', '160', '3', '20', '5', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('191', 'MV-000191', '8', '161', '3', '21', '7', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('192', 'MV-000192', '8', '162', '3', '22', '51', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('193', 'MV-000193', '8', '163', '3', '23', '49', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('194', 'MV-000194', '8', '164', '3', '24', '21', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('195', 'MV-000195', '8', '165', '3', '25', '9', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('196', 'MV-000196', '8', '166', '3', '26', '43', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('197', 'MV-000197', '8', '167', '3', '27', '15', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('198', 'MV-000198', '8', '168', '3', '28', '33', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('199', 'MV-000199', '8', '169', '3', '29', '59', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('200', 'MV-000200', '8', '170', '3', '30', '53', 'OUT', '0', '6', '52', 'Xuất bán dự án doanh nghiệp', '2026-10-16 11:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('201', 'MV-000201', '9', '171', '1', '1', '27', 'ADJUST_IN', '2', '0', '47', 'Điều chỉnh tăng tồn sau kiểm kê', '2026-10-20 16:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('202', 'MV-000202', '10', '172', '1', '4', '39', 'ADJUST_OUT', '0', '1', '44', 'Điều chỉnh giảm tồn sau kiểm kê', '2026-10-20 16:30:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('203', 'MV-000203', '11', '173', '1', '1', '28', 'IN', '20', '0', '20', 'Ghi sổ nhập bổ sung lô 2', '2026-10-21 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('204', 'MV-000204', '11', '174', '1', '2', '32', 'IN', '20', '0', '20', 'Ghi sổ nhập bổ sung lô 2', '2026-10-21 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('205', 'MV-000205', '11', '175', '1', '3', '30', 'IN', '20', '0', '20', 'Ghi sổ nhập bổ sung lô 2', '2026-10-21 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('206', 'MV-000206', '11', '176', '1', '4', '40', 'IN', '20', '0', '20', 'Ghi sổ nhập bổ sung lô 2', '2026-10-21 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('207', 'MV-000207', '11', '177', '1', '5', '42', 'IN', '20', '0', '20', 'Ghi sổ nhập bổ sung lô 2', '2026-10-21 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('208', 'MV-000208', '11', '178', '1', '6', '24', 'IN', '20', '0', '20', 'Ghi sổ nhập bổ sung lô 2', '2026-10-21 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('209', 'MV-000209', '11', '179', '1', '7', '26', 'IN', '20', '0', '20', 'Ghi sổ nhập bổ sung lô 2', '2026-10-21 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('210', 'MV-000210', '11', '180', '1', '8', '38', 'IN', '20', '0', '20', 'Ghi sổ nhập bổ sung lô 2', '2026-10-21 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('211', 'MV-000211', '11', '181', '1', '9', '36', 'IN', '20', '0', '20', 'Ghi sổ nhập bổ sung lô 2', '2026-10-21 09:00:00');
INSERT INTO stock_movements (id, movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES ('212', 'MV-000212', '11', '182', '1', '10', '20', 'IN', '20', '0', '20', 'Ghi sổ nhập bổ sung lô 2', '2026-10-21 09:00:00');

-- --------------------------------------------------------
-- Bảng: stock_counts (1 bản ghi)
-- --------------------------------------------------------
INSERT INTO stock_counts (id, code, warehouse_id, title, count_date, status, created_by, completed_by, note, created_at) VALUES ('1', 'KK-202610-0001', '1', 'Kiểm kê định kỳ tháng 10 Kho Hà Nội', '2026-10-20', 'ADJUSTED', '6', '2', 'Đã hoàn thành kiểm kê và lập chứng từ điều chỉnh', '2026-10-20 08:00:00');

-- --------------------------------------------------------
-- Bảng: stock_count_lines (10 bản ghi)
-- --------------------------------------------------------
INSERT INTO stock_count_lines (id, stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason, adjustment_voucher_id) VALUES ('1', '1', '1', '27', '45', '47', '2', 'Thừa do nhà cung cấp đóng gói dư 2 hộp', '9');
INSERT INTO stock_count_lines (id, stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason, adjustment_voucher_id) VALUES ('2', '1', '4', '39', '45', '44', '-1', 'Thiếu 1 con chuột do rơi vỡ trong quá trình bốc dỡ', '10');
INSERT INTO stock_count_lines (id, stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason, adjustment_voucher_id) VALUES ('3', '1', '5', '41', '45', '45', '0', 'Khớp số lượng', NULL);
INSERT INTO stock_count_lines (id, stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason, adjustment_voucher_id) VALUES ('4', '1', '6', '23', '45', '45', '0', 'Khớp số lượng', NULL);
INSERT INTO stock_count_lines (id, stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason, adjustment_voucher_id) VALUES ('5', '1', '7', '25', '45', '45', '0', 'Khớp số lượng', NULL);
INSERT INTO stock_count_lines (id, stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason, adjustment_voucher_id) VALUES ('6', '1', '8', '37', '45', '45', '0', 'Khớp số lượng', NULL);
INSERT INTO stock_count_lines (id, stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason, adjustment_voucher_id) VALUES ('7', '1', '9', '35', '45', '45', '0', 'Khớp số lượng', NULL);
INSERT INTO stock_count_lines (id, stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason, adjustment_voucher_id) VALUES ('8', '1', '10', '19', '45', '45', '0', 'Khớp số lượng', NULL);
INSERT INTO stock_count_lines (id, stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason, adjustment_voucher_id) VALUES ('9', '1', '11', '17', '45', '45', '0', 'Khớp số lượng', NULL);
INSERT INTO stock_count_lines (id, stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason, adjustment_voucher_id) VALUES ('10', '1', '12', '3', '45', '45', '0', 'Khớp số lượng', NULL);

