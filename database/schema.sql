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

-- Nhật ký kiểm toán hoạt động hệ thống (System Activity & Audit Log)
CREATE TABLE IF NOT EXISTS activity_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    username VARCHAR(50),
    action VARCHAR(50) NOT NULL,
    target_type VARCHAR(50),
    target_id VARCHAR(50),
    details TEXT,
    ip_address VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_activity_logs_action ON activity_logs(action);
CREATE INDEX IF NOT EXISTS idx_activity_logs_created ON activity_logs(created_at);

