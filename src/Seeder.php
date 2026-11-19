<?php

declare(strict_types=1);

namespace App;

use PDO;

class Seeder
{
    public static function run(PDO $pdo): array
    {
        // 1. Tạo Users
        $users = [
            ['username' => 'tuoi', 'password_hash' => password_hash('123456', PASSWORD_DEFAULT), 'full_name' => 'Đào Thị Tươi', 'role' => 'admin'],
            ['username' => 'manager', 'password_hash' => password_hash('123456', PASSWORD_DEFAULT), 'full_name' => 'Nguyễn Văn An', 'role' => 'manager'],
            ['username' => 'kho_hn', 'password_hash' => password_hash('123456', PASSWORD_DEFAULT), 'full_name' => 'Trần Đức Minh', 'role' => 'staff'],
            ['username' => 'kho_dn', 'password_hash' => password_hash('123456', PASSWORD_DEFAULT), 'full_name' => 'Hoàng Minh Tuấn', 'role' => 'staff'],
            ['username' => 'kho_hcm', 'password_hash' => password_hash('123456', PASSWORD_DEFAULT), 'full_name' => 'Phạm Thùy Linh', 'role' => 'staff'],
            ['username' => 'auditor', 'password_hash' => password_hash('123456', PASSWORD_DEFAULT), 'full_name' => 'Lê Đình Huy', 'role' => 'auditor'],
        ];

        $stmtUser = $pdo->prepare("INSERT OR IGNORE INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)");
        foreach ($users as $u) {
            $stmtUser->execute([$u['username'], $u['password_hash'], $u['full_name'], $u['role']]);
        }

        // Lấy user IDs
        $userMap = [];
        $resUsers = $pdo->query("SELECT id, username FROM users")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($resUsers as $ru) {
            $userMap[$ru['username']] = (int) $ru['id'];
        }
        if (isset($userMap['tuoi']) && !isset($userMap['admin'])) {
            $userMap['admin'] = $userMap['tuoi'];
        }
        if (isset($userMap['admin']) && !isset($userMap['tuoi'])) {
            $userMap['tuoi'] = $userMap['admin'];
        }

        // 2. Tạo 3 Kho
        $warehouses = [
            ['code' => 'KHO-HN', 'name' => 'Kho Tổng Miền Bắc (Hà Nội)', 'address' => 'Số 1 Phố Xốm, Hà Đông, Hà Nội', 'phone' => '024-3829-1111'],
            ['code' => 'KHO-DN', 'name' => 'Kho Trung Chuyển Miền Trung (Đà Nẵng)', 'address' => '45 Nguyễn Văn Linh, Hải Châu, Đà Nẵng', 'phone' => '023-6382-2222'],
            ['code' => 'KHO-HCM', 'name' => 'Kho Phân Phối Miền Nam (TP.HCM)', 'address' => '120 Võ Văn Tần, Quận 3, TP. Hồ Chí Minh', 'phone' => '028-3930-3333'],
        ];
        $stmtWh = $pdo->prepare("INSERT OR IGNORE INTO warehouses (code, name, address, phone) VALUES (?, ?, ?, ?)");
        foreach ($warehouses as $w) {
            $stmtWh->execute([$w['code'], $w['name'], $w['address'], $w['phone']]);
        }

        $whMap = [];
        $resWh = $pdo->query("SELECT id, code FROM warehouses")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($resWh as $rw) {
            $whMap[$rw['code']] = (int) $rw['id'];
        }

        // 3. Phân quyền kho
        $whPerms = [
            // Admin & Manager được thao tác cả 3 kho
            ['user' => 'tuoi', 'wh' => 'KHO-HN', 'view' => 1, 'create' => 1, 'approve' => 1],
            ['user' => 'tuoi', 'wh' => 'KHO-DN', 'view' => 1, 'create' => 1, 'approve' => 1],
            ['user' => 'tuoi', 'wh' => 'KHO-HCM', 'view' => 1, 'create' => 1, 'approve' => 1],
            ['user' => 'manager', 'wh' => 'KHO-HN', 'view' => 1, 'create' => 1, 'approve' => 1],
            ['user' => 'manager', 'wh' => 'KHO-DN', 'view' => 1, 'create' => 1, 'approve' => 1],
            ['user' => 'manager', 'wh' => 'KHO-HCM', 'view' => 1, 'create' => 1, 'approve' => 1],
            // Thủ kho riêng từng kho
            ['user' => 'kho_hn', 'wh' => 'KHO-HN', 'view' => 1, 'create' => 1, 'approve' => 0],
            ['user' => 'kho_dn', 'wh' => 'KHO-DN', 'view' => 1, 'create' => 1, 'approve' => 0],
            ['user' => 'kho_hcm', 'wh' => 'KHO-HCM', 'view' => 1, 'create' => 1, 'approve' => 0],
            // Auditor có quyền xem và tạo kiểm kê ở cả 3 kho
            ['user' => 'auditor', 'wh' => 'KHO-HN', 'view' => 1, 'create' => 1, 'approve' => 0],
            ['user' => 'auditor', 'wh' => 'KHO-DN', 'view' => 1, 'create' => 1, 'approve' => 0],
            ['user' => 'auditor', 'wh' => 'KHO-HCM', 'view' => 1, 'create' => 1, 'approve' => 0],
        ];
        $stmtPerm = $pdo->prepare("INSERT OR IGNORE INTO user_warehouses (user_id, warehouse_id, can_view, can_create, can_approve) VALUES (?, ?, ?, ?, ?)");
        foreach ($whPerms as $p) {
            $stmtPerm->execute([$userMap[$p['user']], $whMap[$p['wh']], $p['view'], $p['create'], $p['approve']]);
        }

        // 4. Danh mục sản phẩm
        $cats = [
            ['code' => 'MAY_TINH', 'name' => 'Máy tính & Laptop'],
            ['code' => 'PHU_KIEN', 'name' => 'Phụ kiện & Linh kiện'],
            ['code' => 'THIET_BI_MANG', 'name' => 'Thiết bị Mạng & Camera'],
            ['code' => 'AM_THANH', 'name' => 'Âm thanh & Multimedia'],
        ];
        $stmtCat = $pdo->prepare("INSERT OR IGNORE INTO item_categories (code, name) VALUES (?, ?)");
        foreach ($cats as $c) {
            $stmtCat->execute([$c['code'], $c['name']]);
        }
        $catMap = [];
        $resCat = $pdo->query("SELECT id, code FROM item_categories")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($resCat as $rc) {
            $catMap[$rc['code']] = (int) $rc['id'];
        }

        // 5. 30 Mặt hàng mẫu
        $items = [
            ['code' => 'LAP-DELL-15', 'name' => 'Laptop Dell Inspiron 15 3520', 'cat' => 'MAY_TINH', 'unit' => 'Chiếc', 'min' => 5],
            ['code' => 'LAP-THINKPAD-E14', 'name' => 'Laptop ThinkPad E14 Gen 4', 'cat' => 'MAY_TINH', 'unit' => 'Chiếc', 'min' => 4],
            ['code' => 'LAP-MAC-M2', 'name' => 'Apple MacBook Air M2 13.6 inch', 'cat' => 'MAY_TINH', 'unit' => 'Chiếc', 'min' => 3],
            ['code' => 'MOUSE-G102', 'name' => 'Chuột Logitech G102 Lightsync', 'cat' => 'PHU_KIEN', 'unit' => 'Con', 'min' => 10],
            ['code' => 'MOUSE-M331', 'name' => 'Chuột không dây Logitech M331 Silent', 'cat' => 'PHU_KIEN', 'unit' => 'Con', 'min' => 10],
            ['code' => 'KB-EK87', 'name' => 'Bàn phím cơ DareU EK87 Brown Switch', 'cat' => 'PHU_KIEN', 'unit' => 'Chiếc', 'min' => 8],
            ['code' => 'KB-K2', 'name' => 'Bàn phím không dây Keychron K2 V2', 'cat' => 'PHU_KIEN', 'unit' => 'Chiếc', 'min' => 5],
            ['code' => 'MON-DELL-24', 'name' => 'Màn hình Dell S2421HN 24 inch IPS', 'cat' => 'MAY_TINH', 'unit' => 'Màn', 'min' => 5],
            ['code' => 'MON-ASUS-27', 'name' => 'Màn hình Gaming Asus VG279Q 27 inch', 'cat' => 'MAY_TINH', 'unit' => 'Màn', 'min' => 4],
            ['code' => 'HEAD-SONY-XM5', 'name' => 'Tai nghe Sony WH-1000XM5 Chống ồn', 'cat' => 'AM_THANH', 'unit' => 'Bộ', 'min' => 3],
            ['code' => 'HEAD-HYPERX', 'name' => 'Tai nghe Gaming HyperX Cloud II', 'cat' => 'AM_THANH', 'unit' => 'Bộ', 'min' => 6],
            ['code' => 'CAB-TYPEC-100W', 'name' => 'Cáp Type-C to Type-C Ugreen 100W 2m', 'cat' => 'PHU_KIEN', 'unit' => 'Sợi', 'min' => 20],
            ['code' => 'CAB-HDMI-21', 'name' => 'Cáp HDMI 2.1 Baseus 8K UltraHD', 'cat' => 'PHU_KIEN', 'unit' => 'Sợi', 'min' => 15],
            ['code' => 'CHAR-ANKER-65W', 'name' => 'Củ sạc nhanh Anker 735 GaN 65W', 'cat' => 'PHU_KIEN', 'unit' => 'Củ', 'min' => 10],
            ['code' => 'CHAR-BASEUS-30W', 'name' => 'Củ sạc nhỏ gọn Baseus Compact 30W', 'cat' => 'PHU_KIEN', 'unit' => 'Củ', 'min' => 15],
            ['code' => 'RAM-FURY-16G', 'name' => 'RAM Kingston Fury Beast DDR4 16GB 3200', 'cat' => 'PHU_KIEN', 'unit' => 'Thanh', 'min' => 10],
            ['code' => 'RAM-CORSAIR-32G', 'name' => 'RAM Corsair Vengeance DDR5 32GB 5600', 'cat' => 'PHU_KIEN', 'unit' => 'Thanh', 'min' => 6],
            ['code' => 'SSD-SAM-980-500', 'name' => 'Ổ cứng SSD Samsung 980 M.2 PCIe 500GB', 'cat' => 'PHU_KIEN', 'unit' => 'Chiếc', 'min' => 8],
            ['code' => 'SSD-NV2-1TB', 'name' => 'Ổ cứng SSD Kingston NV2 M.2 NVMe 1TB', 'cat' => 'PHU_KIEN', 'unit' => 'Chiếc', 'min' => 10],
            ['code' => 'CAM-C922', 'name' => 'Webcam Full HD Logitech C922 Pro', 'cat' => 'THIET_BI_MANG', 'unit' => 'Chiếc', 'min' => 5],
            ['code' => 'CAM-EZVIZ-C6N', 'name' => 'Camera an ninh Wi-Fi EZVIZ C6N 1080P', 'cat' => 'THIET_BI_MANG', 'unit' => 'Chiếc', 'min' => 8],
            ['code' => 'ROUT-TPLINK-AX15', 'name' => 'Router Wi-Fi 6 TP-Link Archer AX1500', 'cat' => 'THIET_BI_MANG', 'unit' => 'Bộ', 'min' => 6],
            ['code' => 'ROUT-ASUS-ZEN', 'name' => 'Hệ thống Mesh Asus ZenWiFi XD4 (2 pack)', 'cat' => 'THIET_BI_MANG', 'unit' => 'Bộ', 'min' => 4],
            ['code' => 'HUB-TYPEC-8IN1', 'name' => 'Hub chuyển đổi USB-C đa năng 8 in 1', 'cat' => 'PHU_KIEN', 'unit' => 'Chiếc', 'min' => 12],
            ['code' => 'CASE-XIGMATEK', 'name' => 'Vỏ case máy tính Xigmatek NYX 3F Kính', 'cat' => 'PHU_KIEN', 'unit' => 'Thùng', 'min' => 5],
            ['code' => 'PSU-CV650', 'name' => 'Nguồn máy tính Corsair CV650 80 Plus Bronze', 'cat' => 'PHU_KIEN', 'unit' => 'Chiếc', 'min' => 5],
            ['code' => 'COOL-AK400', 'name' => 'Tản nhiệt CPU Deepcool AK400 Digital', 'cat' => 'PHU_KIEN', 'unit' => 'Hộp', 'min' => 6],
            ['code' => 'MIC-RODE-USB', 'name' => 'Micro thu âm Podcast Rode NT-USB Mini', 'cat' => 'AM_THANH', 'unit' => 'Bộ', 'min' => 4],
            ['code' => 'UPS-APC-650', 'name' => 'Bộ lưu điện UPS APC Back-UPS 650VA', 'cat' => 'THIET_BI_MANG', 'unit' => 'Bộ', 'min' => 3],
            ['code' => 'SPK-MICROLAB', 'name' => 'Loa máy tính Bluetooth Microlab M-108BT', 'cat' => 'AM_THANH', 'unit' => 'Bộ', 'min' => 5],
        ];

        $stmtItem = $pdo->prepare("INSERT OR IGNORE INTO items (code, name, category_id, unit, min_stock, description) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($items as $it) {
            $stmtItem->execute([$it['code'], $it['name'], $catMap[$it['cat']], $it['unit'], $it['min'], 'Sản phẩm chính hãng']);
        }

        $itemMap = [];
        $resItem = $pdo->query("SELECT id, code FROM items")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($resItem as $ri) {
            $itemMap[$ri['code']] = (int) $ri['id'];
        }

        // 6. Lô hàng (Batches)
        $batchMap = [];
        $stmtBatch = $pdo->prepare("INSERT OR IGNORE INTO batches (item_id, batch_number, manufacture_date, expiry_date) VALUES (?, ?, ?, ?)");
        foreach ($itemMap as $code => $itemId) {
            $b1 = "L01-2026";
            $b2 = "L02-2026";
            $stmtBatch->execute([$itemId, $b1, '2026-01-15', '2028-01-15']);
            $stmtBatch->execute([$itemId, $b2, '2026-03-01', '2028-03-01']);
        }
        $resBatches = $pdo->query("SELECT id, item_id, batch_number FROM batches")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($resBatches as $rb) {
            $batchMap[$rb['item_id'] . '_' . $rb['batch_number']] = (int) $rb['id'];
        }

        // Kiểm tra xem đã có phát sinh chưa
        $existingCount = (int) $pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
        if ($existingCount >= 200) {
            return [
                'users' => count($users),
                'warehouses' => count($warehouses),
                'items' => count($items),
                'movements' => $existingCount
            ];
        }

        // 7. Tạo Chứng từ và Sổ phát sinh (>200 dòng phát sinh)
        $pdo->beginTransaction();

        $voucherIndex = 1;
        $movementIndex = 1;

        $whList = [
            'HN' => $whMap['KHO-HN'],
            'DN' => $whMap['KHO-DN'],
            'HCM' => $whMap['KHO-HCM'],
        ];

        // Mảng theo dõi số tồn hiện tại của từng (kho, item, batch) để ghi balance_after chính xác
        $stockLedger = [];
        $getBalance = function ($whId, $itemId, $batchId) use (&$stockLedger) {
            $key = "{$whId}_{$itemId}_{$batchId}";
            return $stockLedger[$key] ?? 0;
        };
        $updateBalance = function ($whId, $itemId, $batchId, $delta) use (&$stockLedger) {
            $key = "{$whId}_{$itemId}_{$batchId}";
            $stockLedger[$key] = ($stockLedger[$key] ?? 0) + $delta;
            return $stockLedger[$key];
        };

        // --- ĐỢT 1: NHẬP KHO BAN ĐẦU (INITIAL INBOUND) ---
        // Nhập cho tất cả 30 sản phẩm ở cả 3 kho
        foreach (['HN', 'DN', 'HCM'] as $whCode) {
            $wId = $whList[$whCode];
            $vCode = sprintf('PN-202610-%04d', $voucherIndex++);
            $stmtV = $pdo->prepare("INSERT INTO vouchers (code, type, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES (?, 'IN', ?, 'CONFIRMED', ?, ?, ?, '2026-10-01 08:30:00', '2026-10-01 09:00:00')");
            $stmtV->execute([$vCode, $wId, $userMap['admin'], $userMap['manager'], "Nhập hàng đầu kỳ cho kho {$whCode}"]);
            $vId = (int) $pdo->lastInsertId();

            foreach ($items as $it) {
                $itemId = $itemMap[$it['code']];
                $bId = $batchMap[$itemId . '_L01-2026'];
                $qty = ($whCode === 'HN') ? 60 : (($whCode === 'DN') ? 40 : 50);

                $stmtVL = $pdo->prepare("INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtVL->execute([$vId, $itemId, $bId, $qty, 500000, "Nhập đợt 1"]);
                $vlId = (int) $pdo->lastInsertId();

                $newBal = $updateBalance($wId, $itemId, $bId, $qty);
                $mCode = sprintf('MV-%06d', $movementIndex++);
                $stmtM = $pdo->prepare("INSERT INTO stock_movements (movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, 'IN', ?, 0, ?, ?, '2026-10-01 09:00:00')");
                $stmtM->execute([$mCode, $vId, $vlId, $wId, $itemId, $bId, $qty, $newBal, "Ghi sổ nhập kho đầu kỳ"]);
            }
        }
        // Hiện tại đã có: 30 items x 3 kho = 90 movements.

        // --- ĐỢT 2: ĐIỀU CHUYỂN HÀNG GIỮA CÁC KHO (STOCK TRANSFERS) ---
        // Chuyển 15 mặt hàng từ HN sang DN, và 15 mặt hàng từ HN sang HCM
        $transferItems1 = array_slice($items, 0, 15);
        $vCodeTrans1 = sprintf('DC-202610-%04d', $voucherIndex++);
        $stmtVT1 = $pdo->prepare("INSERT INTO vouchers (code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES (?, 'TRANSFER', ?, ?, 'CONFIRMED', ?, ?, ?, '2026-10-05 10:00:00', '2026-10-05 11:00:00')");
        $stmtVT1->execute([$vCodeTrans1, $whList['HN'], $whList['DN'], $userMap['manager'], $userMap['manager'], "Điều chuyển chi viện Kho Đà Nẵng"]);
        $vIdT1 = (int) $pdo->lastInsertId();

        foreach ($transferItems1 as $it) {
            $itemId = $itemMap[$it['code']];
            $bId = $batchMap[$itemId . '_L01-2026'];
            $qty = 10;

            $stmtVL = $pdo->prepare("INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtVL->execute([$vIdT1, $itemId, $bId, $qty, 500000, "Chuyển HN -> DN"]);
            $vlId = (int) $pdo->lastInsertId();

            // Trừ kho xuất HN
            $balHN = $updateBalance($whList['HN'], $itemId, $bId, -$qty);
            $mCodeOut = sprintf('MV-%06d', $movementIndex++);
            $stmtMOut = $pdo->prepare("INSERT INTO stock_movements (movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, 'TRANSFER_OUT', 0, ?, ?, ?, '2026-10-05 11:00:00')");
            $stmtMOut->execute([$mCodeOut, $vIdT1, $vlId, $whList['HN'], $itemId, $bId, $qty, $balHN, "Xuất điều chuyển sang KHO-DN"]);

            // Cộng kho nhập DN
            $balDN = $updateBalance($whList['DN'], $itemId, $bId, $qty);
            $mCodeIn = sprintf('MV-%06d', $movementIndex++);
            $stmtMIn = $pdo->prepare("INSERT INTO stock_movements (movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, 'TRANSFER_IN', ?, 0, ?, ?, '2026-10-05 11:00:00')");
            $stmtMIn->execute([$mCodeIn, $vIdT1, $vlId, $whList['DN'], $itemId, $bId, $qty, $balDN, "Nhập điều chuyển từ KHO-HN"]);
        }
        // Thêm 15 x 2 = 30 movements (Tổng 120)

        // Chuyển tiếp 15 mặt hàng từ HN sang HCM
        $transferItems2 = array_slice($items, 15, 15);
        $vCodeTrans2 = sprintf('DC-202610-%04d', $voucherIndex++);
        $stmtVT2 = $pdo->prepare("INSERT INTO vouchers (code, type, source_warehouse_id, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES (?, 'TRANSFER', ?, ?, 'CONFIRMED', ?, ?, ?, '2026-10-08 14:00:00', '2026-10-08 14:30:00')");
        $stmtVT2->execute([$vCodeTrans2, $whList['HN'], $whList['HCM'], $userMap['manager'], $userMap['manager'], "Điều chuyển chi viện Kho Hồ Chí Minh"]);
        $vIdT2 = (int) $pdo->lastInsertId();

        foreach ($transferItems2 as $it) {
            $itemId = $itemMap[$it['code']];
            $bId = $batchMap[$itemId . '_L01-2026'];
            $qty = 8;

            $stmtVL = $pdo->prepare("INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtVL->execute([$vIdT2, $itemId, $bId, $qty, 500000, "Chuyển HN -> HCM"]);
            $vlId = (int) $pdo->lastInsertId();

            $balHN = $updateBalance($whList['HN'], $itemId, $bId, -$qty);
            $mCodeOut = sprintf('MV-%06d', $movementIndex++);
            $stmtMOut = $pdo->prepare("INSERT INTO stock_movements (movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, 'TRANSFER_OUT', 0, ?, ?, ?, '2026-10-08 14:30:00')");
            $stmtMOut->execute([$mCodeOut, $vIdT2, $vlId, $whList['HN'], $itemId, $bId, $qty, $balHN, "Xuất điều chuyển sang KHO-HCM"]);

            $balHCM = $updateBalance($whList['HCM'], $itemId, $bId, $qty);
            $mCodeIn = sprintf('MV-%06d', $movementIndex++);
            $stmtMIn = $pdo->prepare("INSERT INTO stock_movements (movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, 'TRANSFER_IN', ?, 0, ?, ?, '2026-10-08 14:30:00')");
            $stmtMIn->execute([$mCodeIn, $vIdT2, $vlId, $whList['HCM'], $itemId, $bId, $qty, $balHCM, "Nhập điều chuyển từ KHO-HN"]);
        }
        // Thêm 15 x 2 = 30 movements (Tổng 150)

        // --- ĐỢT 3: XUẤT KHO BÁN HÀNG HOẶC SỬ DỤNG (OUTBOUND) ---
        // Xuất 20 mặt hàng tại KHO-HN
        $vCodeOutHN = sprintf('PX-202610-%04d', $voucherIndex++);
        $stmtVOutHN = $pdo->prepare("INSERT INTO vouchers (code, type, source_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES (?, 'OUT', ?, 'CONFIRMED', ?, ?, ?, '2026-10-12 09:00:00', '2026-10-12 10:00:00')");
        $stmtVOutHN->execute([$vCodeOutHN, $whList['HN'], $userMap['kho_hn'], $userMap['manager'], "Xuất kho giao đại lý miền Bắc"]);
        $vIdOutHN = (int) $pdo->lastInsertId();

        for ($i = 0; $i < 20; $i++) {
            $it = $items[$i];
            $itemId = $itemMap[$it['code']];
            $bId = $batchMap[$itemId . '_L01-2026'];
            $qty = 5;

            $stmtVL = $pdo->prepare("INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtVL->execute([$vIdOutHN, $itemId, $bId, $qty, 650000, "Xuất đại lý"]);
            $vlId = (int) $pdo->lastInsertId();

            $balHN = $updateBalance($whList['HN'], $itemId, $bId, -$qty);
            $mCode = sprintf('MV-%06d', $movementIndex++);
            $stmtM = $pdo->prepare("INSERT INTO stock_movements (movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, 'OUT', 0, ?, ?, ?, '2026-10-12 10:00:00')");
            $stmtM->execute([$mCode, $vIdOutHN, $vlId, $whList['HN'], $itemId, $bId, $qty, $balHN, "Xuất bán hàng"]);
        }
        // Thêm 20 movements (Tổng 170)

        // Xuất 15 mặt hàng tại KHO-DN
        $vCodeOutDN = sprintf('PX-202610-%04d', $voucherIndex++);
        $stmtVOutDN = $pdo->prepare("INSERT INTO vouchers (code, type, source_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES (?, 'OUT', ?, 'CONFIRMED', ?, ?, ?, '2026-10-14 15:00:00', '2026-10-14 16:00:00')");
        $stmtVOutDN->execute([$vCodeOutDN, $whList['DN'], $userMap['kho_dn'], $userMap['manager'], "Xuất bán khu vực Miền Trung"]);
        $vIdOutDN = (int) $pdo->lastInsertId();

        for ($i = 0; $i < 15; $i++) {
            $it = $items[$i];
            $itemId = $itemMap[$it['code']];
            $bId = $batchMap[$itemId . '_L01-2026'];
            $qty = 4;

            $stmtVL = $pdo->prepare("INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtVL->execute([$vIdOutDN, $itemId, $bId, $qty, 680000, "Xuất bán lẻ"]);
            $vlId = (int) $pdo->lastInsertId();

            $balDN = $updateBalance($whList['DN'], $itemId, $bId, -$qty);
            $mCode = sprintf('MV-%06d', $movementIndex++);
            $stmtM = $pdo->prepare("INSERT INTO stock_movements (movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, 'OUT', 0, ?, ?, ?, '2026-10-14 16:00:00')");
            $stmtM->execute([$mCode, $vIdOutDN, $vlId, $whList['DN'], $itemId, $bId, $qty, $balDN, "Xuất bán lẻ Miền Trung"]);
        }
        // Thêm 15 movements (Tổng 185)

        // Xuất 15 mặt hàng tại KHO-HCM
        $vCodeOutHCM = sprintf('PX-202610-%04d', $voucherIndex++);
        $stmtVOutHCM = $pdo->prepare("INSERT INTO vouchers (code, type, source_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES (?, 'OUT', ?, 'CONFIRMED', ?, ?, ?, '2026-10-16 11:00:00', '2026-10-16 11:30:00')");
        $stmtVOutHCM->execute([$vCodeOutHCM, $whList['HCM'], $userMap['kho_hcm'], $userMap['manager'], "Xuất bán dự án doanh nghiệp HCM"]);
        $vIdOutHCM = (int) $pdo->lastInsertId();

        for ($i = 15; $i < 30; $i++) {
            $it = $items[$i];
            $itemId = $itemMap[$it['code']];
            $bId = $batchMap[$itemId . '_L01-2026'];
            $qty = 6;

            $stmtVL = $pdo->prepare("INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtVL->execute([$vIdOutHCM, $itemId, $bId, $qty, 720000, "Xuất bán dự án"]);
            $vlId = (int) $pdo->lastInsertId();

            $balHCM = $updateBalance($whList['HCM'], $itemId, $bId, -$qty);
            $mCode = sprintf('MV-%06d', $movementIndex++);
            $stmtM = $pdo->prepare("INSERT INTO stock_movements (movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, 'OUT', 0, ?, ?, ?, '2026-10-16 11:30:00')");
            $stmtM->execute([$mCode, $vIdOutHCM, $vlId, $whList['HCM'], $itemId, $bId, $qty, $balHCM, "Xuất bán dự án doanh nghiệp"]);
        }
        // Thêm 15 movements (Tổng 200)

        // --- ĐỢT 4: ĐỢT KIỂM KÊ VÀ CHỨNG TỪ ĐIỀU CHỈNH CHÊNH LỆCH (INVENTORY COUNTS & ADJUSTMENTS) ---
        // Tạo 1 đợt kiểm kê tại Kho Hà Nội cho 10 mặt hàng, trong đó phát hiện 2 mặt hàng lệch
        $countCode = "KK-202610-0001";
        $stmtSC = $pdo->prepare("INSERT INTO stock_counts (code, warehouse_id, title, count_date, status, created_by, completed_by, note, created_at) VALUES (?, ?, ?, '2026-10-20', 'ADJUSTED', ?, ?, ?, '2026-10-20 08:00:00')");
        $stmtSC->execute([$countCode, $whList['HN'], "Kiểm kê định kỳ tháng 10 Kho Hà Nội", $userMap['auditor'], $userMap['manager'], "Đã hoàn thành kiểm kê và lập chứng từ điều chỉnh"]);
        $countId = (int) $pdo->lastInsertId();

        // Mặt hàng thừa 2 chiếc (LAP-DELL-15): Cần phiếu điều chỉnh TĂNG (ADJUST_IN)
        $itSurplus = $items[0];
        $itSurplusId = $itemMap[$itSurplus['code']];
        $bSurplusId = $batchMap[$itSurplusId . '_L01-2026'];
        $sysQty1 = $getBalance($whList['HN'], $itSurplusId, $bSurplusId);
        $actQty1 = $sysQty1 + 2; // Thừa 2
        $diff1 = 2;

        // Phiếu điều chỉnh tăng
        $vCodeAdjIn = sprintf('DC-ADJ-%04d', $voucherIndex++);
        $stmtAdjIn = $pdo->prepare("INSERT INTO vouchers (code, type, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES (?, 'ADJUST_IN', ?, 'CONFIRMED', ?, ?, ?, '2026-10-20 16:00:00', '2026-10-20 16:30:00')");
        $stmtAdjIn->execute([$vCodeAdjIn, $whList['HN'], $userMap['auditor'], $userMap['manager'], "Điều chỉnh tăng do thừa hàng kiểm kê KK-202610-0001"]);
        $vIdAdjIn = (int) $pdo->lastInsertId();

        $stmtVLAdjIn = $pdo->prepare("INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtVLAdjIn->execute([$vIdAdjIn, $itSurplusId, $bSurplusId, 2, 0, "Điều chỉnh thừa"]);
        $vlIdAdjIn = (int) $pdo->lastInsertId();

        $balAdjIn = $updateBalance($whList['HN'], $itSurplusId, $bSurplusId, 2);
        $mCodeAdjIn = sprintf('MV-%06d', $movementIndex++);
        $stmtMAdjIn = $pdo->prepare("INSERT INTO stock_movements (movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, 'ADJUST_IN', 2, 0, ?, ?, '2026-10-20 16:30:00')");
        $stmtMAdjIn->execute([$mCodeAdjIn, $vIdAdjIn, $vlIdAdjIn, $whList['HN'], $itSurplusId, $bSurplusId, $balAdjIn, "Điều chỉnh tăng tồn sau kiểm kê"]);

        $stmtSCL1 = $pdo->prepare("INSERT INTO stock_count_lines (stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason, adjustment_voucher_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtSCL1->execute([$countId, $itSurplusId, $bSurplusId, $sysQty1, $actQty1, $diff1, "Thừa do nhà cung cấp đóng gói dư 2 hộp", $vIdAdjIn]);

        // Mặt hàng thiếu 1 chiếc (MOUSE-G102): Cần phiếu điều chỉnh GIẢM (ADJUST_OUT)
        $itDeficit = $items[3];
        $itDeficitId = $itemMap[$itDeficit['code']];
        $bDeficitId = $batchMap[$itDeficitId . '_L01-2026'];
        $sysQty2 = $getBalance($whList['HN'], $itDeficitId, $bDeficitId);
        $actQty2 = $sysQty2 - 1; // Thiếu 1
        $diff2 = -1;

        $vCodeAdjOut = sprintf('DC-ADJ-%04d', $voucherIndex++);
        $stmtAdjOut = $pdo->prepare("INSERT INTO vouchers (code, type, source_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES (?, 'ADJUST_OUT', ?, 'CONFIRMED', ?, ?, ?, '2026-10-20 16:15:00', '2026-10-20 16:30:00')");
        $stmtAdjOut->execute([$vCodeAdjOut, $whList['HN'], $userMap['auditor'], $userMap['manager'], "Điều chỉnh giảm do hao hụt kiểm kê KK-202610-0001"]);
        $vIdAdjOut = (int) $pdo->lastInsertId();

        $stmtVLAdjOut = $pdo->prepare("INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtVLAdjOut->execute([$vIdAdjOut, $itDeficitId, $bDeficitId, 1, 0, "Điều chỉnh thiếu"]);
        $vlIdAdjOut = (int) $pdo->lastInsertId();

        $balAdjOut = $updateBalance($whList['HN'], $itDeficitId, $bDeficitId, -1);
        $mCodeAdjOut = sprintf('MV-%06d', $movementIndex++);
        $stmtMAdjOut = $pdo->prepare("INSERT INTO stock_movements (movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, 'ADJUST_OUT', 0, 1, ?, ?, '2026-10-20 16:30:00')");
        $stmtMAdjOut->execute([$mCodeAdjOut, $vIdAdjOut, $vlIdAdjOut, $whList['HN'], $itDeficitId, $bDeficitId, $balAdjOut, "Điều chỉnh giảm tồn sau kiểm kê"]);

        $stmtSCL2 = $pdo->prepare("INSERT INTO stock_count_lines (stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason, adjustment_voucher_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtSCL2->execute([$countId, $itDeficitId, $bDeficitId, $sysQty2, $actQty2, $diff2, "Thiếu 1 con chuột do rơi vỡ trong quá trình bốc dỡ", $vIdAdjOut]);

        // Thêm 8 mặt hàng kiểm kê khớp 100% (chênh lệch = 0)
        for ($k = 4; $k < 12; $k++) {
            $itK = $items[$k];
            $itemIdK = $itemMap[$itK['code']];
            $bIdK = $batchMap[$itemIdK . '_L01-2026'];
            $sysK = $getBalance($whList['HN'], $itemIdK, $bIdK);
            $stmtSCLK = $pdo->prepare("INSERT INTO stock_count_lines (stock_count_id, item_id, batch_id, system_quantity, actual_quantity, difference, adjustment_reason) VALUES (?, ?, ?, ?, ?, 0, 'Khớp số lượng')");
            $stmtSCLK->execute([$countId, $itemIdK, $bIdK, $sysK, $sysK]);
        }

        // Tạo thêm một số phiếu nhập Lô 2 để tổng số movements đạt trên 210 dòng
        $vCodeL02 = sprintf('PN-202610-%04d', $voucherIndex++);
        $stmtVL02 = $pdo->prepare("INSERT INTO vouchers (code, type, dest_warehouse_id, status, created_by, approved_by, note, created_at, approved_at) VALUES (?, 'IN', ?, 'CONFIRMED', ?, ?, ?, '2026-10-21 08:30:00', '2026-10-21 09:00:00')");
        $stmtVL02->execute([$vCodeL02, $whList['HN'], $userMap['admin'], $userMap['manager'], "Nhập lô hàng bổ sung L02-2026"]);
        $vIdL02 = (int) $pdo->lastInsertId();

        for ($i = 0; $i < 10; $i++) {
            $it = $items[$i];
            $itemId = $itemMap[$it['code']];
            $bId = $batchMap[$itemId . '_L02-2026'];
            $qty = 20;

            $stmtVL = $pdo->prepare("INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, unit_price, note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtVL->execute([$vIdL02, $itemId, $bId, $qty, 520000, "Nhập bổ sung lô 2"]);
            $vlId = (int) $pdo->lastInsertId();

            $balHN = $updateBalance($whList['HN'], $itemId, $bId, $qty);
            $mCode = sprintf('MV-%06d', $movementIndex++);
            $stmtM = $pdo->prepare("INSERT INTO stock_movements (movement_code, voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, movement_type, quantity_in, quantity_out, balance_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, 'IN', ?, 0, ?, ?, '2026-10-21 09:00:00')");
            $stmtM->execute([$mCode, $vIdL02, $vlId, $whList['HN'], $itemId, $bId, $qty, $balHN, "Ghi sổ nhập bổ sung lô 2"]);
        }

        // Ghi nhận các nhật ký hoạt động mẫu cho hệ thống
        try {
            $stmtLog = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, target_type, target_id, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $initialLogs = [
                [$userMap['tuoi'], 'tuoi', 'SYSTEM_INIT', 'system', 'INIT', 'Khởi tạo cơ sở dữ liệu mẫu và đồng bộ danh mục 3 kho', '127.0.0.1', '2026-10-20 07:00:00'],
                [$userMap['tuoi'], 'tuoi', 'LOGIN', 'users', (string) $userMap['tuoi'], 'Đăng nhập thành công với vai trò Quản trị viên (Đào Thị Tươi)', '127.0.0.1', '2026-10-20 07:15:00'],
                [$userMap['tuoi'], 'tuoi', 'CREATE_VOUCHER', 'vouchers', 'PN-202610-0001', 'Tạo phiếu nhập kho ban đầu cho Kho Hà Nội', '127.0.0.1', '2026-10-20 07:30:00'],
                [$userMap['manager'], 'manager', 'APPROVE_VOUCHER', 'vouchers', 'PN-202610-0001', 'Duyệt và ghi sổ thành công phiếu nhập kho', '127.0.0.1', '2026-10-20 08:00:00'],
                [$userMap['kho_hn'], 'kho_hn', 'CREATE_VOUCHER', 'vouchers', 'DC-202610-TRANSFER', 'Lập phiếu điều chuyển liên kho từ KHO-HN sang KHO-DN', '192.168.1.10', '2026-10-20 14:00:00'],
                [$userMap['manager'], 'manager', 'APPROVE_VOUCHER', 'vouchers', 'DC-202610-TRANSFER', 'Xác nhận điều chuyển nguyên tử 2 đầu kho thành công', '127.0.0.1', '2026-10-20 14:30:00'],
                [$userMap['auditor'], 'auditor', 'COUNT_STOCK', 'stock_counts', $countCode, 'Tạo đợt kiểm kê định kỳ thực tế tại Kho Hà Nội', '192.168.1.25', '2026-10-21 08:00:00'],
                [$userMap['manager'], 'manager', 'ADJUST_STOCK', 'stock_counts', $vCodeAdjOut, 'Xác nhận kết quả kiểm kê và tự động sinh chứng từ điều chỉnh', '127.0.0.1', '2026-10-21 11:30:00'],
                [$userMap['tuoi'], 'tuoi', 'UPDATE_USER', 'users', (string) $userMap['kho_dn'], 'Cập nhật phân quyền thao tác cho thủ kho', '127.0.0.1', '2026-10-22 09:15:00'],
            ];
            foreach ($initialLogs as $log) {
                $stmtLog->execute($log);
            }
        } catch (\Throwable $e) {
        }

        $pdo->commit();

        $finalMovements = (int) $pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

        return [
            'users' => count($users),
            'warehouses' => count($warehouses),
            'items' => count($items),
            'movements' => $finalMovements
        ];
    }
}
