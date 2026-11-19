<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/StockService.php';
require_once __DIR__ . '/../src/InventoryService.php';

use App\Database;
use App\Auth;
use App\StockService;
use App\InventoryService;

header('Content-Type: application/json; charset=utf-8');

$user = Auth::getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'UNAUTHENTICATED']);
    exit;
}

$pdo = Database::getConnection();
$action = $_GET['action'] ?? '';

if ($action === 'get_stock') {
    $whId = (int)($_GET['warehouse_id'] ?? 0);
    $itemId = (int)($_GET['item_id'] ?? 0);
    $batchId = !empty($_GET['batch_id']) ? (int)$_GET['batch_id'] : null;

    $stock = StockService::getCurrentStock($pdo, $whId, $itemId, $batchId);
    echo json_encode(['warehouse_id' => $whId, 'item_id' => $itemId, 'batch_id' => $batchId, 'stock' => $stock]);
    exit;
}

if ($action === 'get_batches') {
    $itemId = (int)($_GET['item_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, batch_number, expiry_date FROM batches WHERE item_id = ? ORDER BY id ASC");
    $stmt->execute([$itemId]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['batches' => $batches]);
    exit;
}

if ($action === 'search_stock') {
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 1) {
        echo json_encode(['results' => []]);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT i.id, i.code, i.name, i.unit, i.min_stock, COALESCE(c.name, 'Chung') AS category 
        FROM items i 
        LEFT JOIN item_categories c ON i.category_id = c.id 
        WHERE i.code LIKE ? OR i.name LIKE ? OR c.name LIKE ? 
        ORDER BY i.id ASC LIMIT 15
    ");
    $term = "%{$q}%";
    $stmt->execute([$term, $term, $term]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $warehouses = $pdo->query("SELECT id, code, name FROM warehouses ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    foreach ($items as $item) {
        $whStock = [];
        $totalStock = 0;
        foreach ($warehouses as $wh) {
            $qty = StockService::getCurrentStock($pdo, (int)$wh['id'], (int)$item['id'], null);
            $whStock[] = [
                'warehouse_code' => $wh['code'],
                'warehouse_name' => $wh['name'],
                'stock' => $qty
            ];
            $totalStock += $qty;
        }
        $results[] = [
            'id' => (int)$item['id'],
            'code' => $item['code'],
            'name' => $item['name'],
            'category' => $item['category'],
            'unit' => $item['unit'],
            'min_stock' => (int)$item['min_stock'],
            'total_stock' => $totalStock,
            'warehouses' => $whStock
        ];
    }
    echo json_encode(['results' => $results]);
    exit;
}

if ($action === 'get_alerts') {
    $lowStock = $pdo->query("
        SELECT i.id, i.code, i.name, i.min_stock, COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS total_stock
        FROM items i
        LEFT JOIN stock_movements sm ON sm.item_id = i.id
        GROUP BY i.id
        HAVING total_stock <= i.min_stock
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $pending = $pdo->query("
        SELECT id, code, type, created_at FROM vouchers WHERE status = 'DRAFT' ORDER BY id DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $expiring = $pdo->query("
        SELECT b.id, b.batch_number, b.expiry_date, i.code AS item_code, i.name AS item_name
        FROM batches b
        JOIN items i ON b.item_id = i.id
        WHERE b.expiry_date IS NOT NULL AND b.expiry_date <= date('now', '+60 days')
        ORDER BY b.expiry_date ASC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'low_stock' => $lowStock,
        'pending_vouchers' => $pending,
        'expiring_batches' => $expiring,
        'total_count' => count($lowStock) + count($pending) + count($expiring)
    ]);
    exit;
}

if ($action === 'run_tests') {
    $suite = $_GET['suite'] ?? 'required'; // 'required' or 'extension'
    $testFile = ($suite === 'extension') ? __DIR__ . '/../tests/extension.php' : __DIR__ . '/../tests/run.php';
    
    $phpBin = PHP_BINARY ?: 'php';

    $cmd = sprintf('"%s" "%s"', $phpBin, $testFile);
    $output = [];
    $returnCode = 0;
    exec($cmd . ' 2>&1', $output, $returnCode);

    echo json_encode([
        'suite' => $suite,
        'exit_code' => $returnCode,
        'passed' => $returnCode === 0,
        'output' => implode("\n", $output)
    ]);
    exit;
}

if ($action === 'confirm_voucher_kanban' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $vId = (int)($_POST['voucher_id'] ?? 0);
    try {
        if ($vId <= 0) throw new RuntimeException('ID chứng từ không hợp lệ');
        StockService::confirmVoucher($pdo, $vId, (int)$user['id']);
        echo json_encode(['ok' => true, 'message' => 'Đã duyệt và ghi sổ chứng từ thành công!']);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'update_count_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cId = (int)($_POST['count_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    try {
        if ($cId <= 0 || !in_array($status, ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'ADJUSTED'], true)) {
            throw new RuntimeException('Dữ liệu cập nhật kiểm kê không hợp lệ');
        }
        $sc = $pdo->prepare("SELECT warehouse_id, status FROM stock_counts WHERE id = ?");
        $sc->execute([$cId]);
        $row = $sc->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Không tìm thấy đợt kiểm kê');
        if ($row['status'] === 'ADJUSTED') throw new RuntimeException('Đợt kiểm kê đã cân bằng, không thể đổi trạng thái');
        if ($status === 'ADJUSTED') {
            InventoryService::confirmAndApplyAdjustments($pdo, $cId, (int)$user['id']);
        } else {
            if (!Auth::checkWarehousePermission($pdo, (int)$user['id'], (int)$row['warehouse_id'], 'create')) {
                throw new RuntimeException('PERMISSION_DENIED: Bạn không có quyền cập nhật đợt kiểm kê này');
            }
            $pdo->prepare("UPDATE stock_counts SET status = ? WHERE id = ?")->execute([$status, $cId]);
        }
        echo json_encode(['ok' => true, 'message' => "Đã cập nhật trạng thái kiểm kê sang {$status}!"]);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'swap_bins' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $srcId = (int)($_POST['src_id'] ?? 0);
    $dstId = (int)($_POST['dst_id'] ?? 0);
    try {
        if ($srcId <= 0 || $dstId <= 0 || $srcId === $dstId) throw new RuntimeException('Vị trí ô kệ không hợp lệ');
        $stmt = $pdo->prepare("SELECT id, warehouse_id, item_id FROM warehouse_locations WHERE id IN (?, ?)");
        $stmt->execute([$srcId, $dstId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['id']] = $r;
        if (!isset($rows[$srcId], $rows[$dstId])) throw new RuntimeException('Không tìm thấy ô kệ');
        if (!Auth::checkWarehousePermission($pdo, (int)$user['id'], (int)$rows[$srcId]['warehouse_id'], 'create')) {
            throw new RuntimeException('PERMISSION_DENIED: Bạn không có quyền sắp xếp ô kệ tại kho này');
        }
        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE warehouse_locations SET item_id = ? WHERE id = ?");
        $upd->execute([$rows[$dstId]['item_id'], $srcId]);
        $upd->execute([$rows[$srcId]['item_id'], $dstId]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => 'Đã lưu hoán đổi vị trí ô kệ vào CSDL!']);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_bin_detail') {
    $locId = (int)($_GET['location_id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT wl.*, w.code AS warehouse_code, w.name AS warehouse_name
        FROM warehouse_locations wl
        JOIN warehouses w ON wl.warehouse_id = w.id
        WHERE wl.id = ?
    ");
    $stmt->execute([$locId]);
    $loc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$loc) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Không tìm thấy ô kệ']);
        exit;
    }

    $item = null;
    $batches = [];
    $movements = [];

    if (!empty($loc['item_id'])) {
        $itemId = (int)$loc['item_id'];
        $whId = (int)$loc['warehouse_id'];

        $stIt = $pdo->prepare("
            SELECT i.*, COALESCE(c.name, 'Chưa phân nhóm') AS category_name
            FROM items i
            LEFT JOIN item_categories c ON i.category_id = c.id
            WHERE i.id = ?
        ");
        $stIt->execute([$itemId]);
        $item = $stIt->fetch(PDO::FETCH_ASSOC);
        if ($item) {
            $item['current_stock'] = StockService::getCurrentStock($pdo, $whId, $itemId, null);
        }

        $stB = $pdo->prepare("
            SELECT b.id, b.batch_number, b.expiry_date,
                   COALESCE(SUM(sm.quantity_in) - SUM(sm.quantity_out), 0) AS qty
            FROM batches b
            LEFT JOIN stock_movements sm ON sm.batch_id = b.id AND sm.warehouse_id = ?
            WHERE b.item_id = ?
            GROUP BY b.id
            HAVING qty > 0
            ORDER BY b.expiry_date ASC, b.id ASC
        ");
        $stB->execute([$whId, $itemId]);
        $batches = $stB->fetchAll(PDO::FETCH_ASSOC);

        $stM = $pdo->prepare("
            SELECT movement_code, movement_type, quantity_in, quantity_out, balance_after, created_at
            FROM stock_movements
            WHERE warehouse_id = ? AND item_id = ?
            ORDER BY id DESC LIMIT 5
        ");
        $stM->execute([$whId, $itemId]);
        $movements = $stM->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'ok' => true,
        'location' => $loc,
        'item' => $item,
        'batches' => $batches,
        'movements' => $movements,
    ]);
    exit;
}

if ($action === 'universal_scan_suggest') {
    $q = trim((string)($_GET['q'] ?? ''));
    $norm = static function (string $s): string {
        $s = mb_strtolower($s, 'UTF-8');
        $map = [
            'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
            'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
            'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
            'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
            'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
            'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y','đ'=>'d',
        ];
        return strtr($s, $map);
    };
    $nq = $norm($q);
    $results = [];

    foreach ($pdo->query("SELECT code, name FROM items ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($nq === '' || str_contains($norm((string)$r['code'] . ' ' . (string)$r['name']), $nq)) {
            $results[] = ['type' => 'SKU', 'code' => $r['code'], 'label' => $r['name']];
            if (count($results) >= 12) break;
        }
    }

    $binCount = 0;
    foreach ($pdo->query("SELECT wl.location_barcode, i.code AS item_code, i.name AS item_name FROM warehouse_locations wl LEFT JOIN items i ON wl.item_id = i.id ORDER BY wl.location_barcode ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $hay = (string)$r['location_barcode'] . ' ' . (string)$r['item_code'] . ' ' . (string)$r['item_name'];
        if ($nq === '' || str_contains($norm($hay), $nq)) {
            $results[] = ['type' => 'BIN', 'code' => $r['location_barcode'], 'label' => $r['item_name'] ?: 'Ô kệ trống'];
            if (++$binCount >= 8) break;
        }
    }

    $vCount = 0;
    foreach ($pdo->query("SELECT code, type, status, note FROM vouchers ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $hay = (string)$r['code'] . ' ' . (string)$r['type'] . ' ' . (string)$r['note'];
        if ($nq === '' || str_contains($norm($hay), $nq)) {
            $results[] = ['type' => 'VOUCHER', 'code' => $r['code'], 'label' => $r['type'] . ' • ' . $r['status']];
            if (++$vCount >= 6) break;
        }
    }

    echo json_encode(['ok' => true, 'results' => $results]);
    exit;
}

if ($action === 'universal_scan') {
    $rawCode = trim((string)($_GET['code'] ?? ''));
    if ($rawCode === '') {
        echo json_encode(['ok' => false, 'type' => 'NONE']);
        exit;
    }

    // Hỗ trợ payload QR dạng JSON {"code":"...","batch":"..."} hoặc dạng SKU|BATCH
    $code = $rawCode;
    $batchHint = '';
    if (str_starts_with($rawCode, '{')) {
        $decodedJson = json_decode($rawCode, true);
        if (is_array($decodedJson) && !empty($decodedJson['code'])) {
            $code = trim((string)$decodedJson['code']);
            $batchHint = trim((string)($decodedJson['batch'] ?? ''));
        }
    } elseif (str_contains($rawCode, '|')) {
        [$left, $right] = explode('|', $rawCode, 2);
        $code = trim($left);
        $batchHint = trim($right);
    }

    // 1. Check location barcode (K1-A-R01-T1-O01...)
    $stLoc = $pdo->prepare("
        SELECT wl.*, w.code AS wh_code, w.name AS wh_name, i.code AS item_code, i.name AS item_name
        FROM warehouse_locations wl
        JOIN warehouses w ON wl.warehouse_id = w.id
        LEFT JOIN items i ON wl.item_id = i.id
        WHERE UPPER(wl.location_barcode) = UPPER(?)
        LIMIT 1
    ");
    $stLoc->execute([$code]);
    if ($loc = $stLoc->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(['ok' => true, 'type' => 'LOCATION', 'data' => $loc]);
        exit;
    }

    // 2. Check voucher code
    $stV = $pdo->prepare("SELECT id, code, type, status, note, created_at FROM vouchers WHERE UPPER(code) = UPPER(?) LIMIT 1");
    $stV->execute([$code]);
    if ($v = $stV->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(['ok' => true, 'type' => 'VOUCHER', 'data' => $v]);
        exit;
    }

    // 3. Check item code or name (hỗ trợ cả mã in tem dạng SKU|BATCH hoặc SKU-BATCH)
    $stIt = $pdo->prepare("SELECT i.*, COALESCE(c.name, 'Chung') AS category FROM items i LEFT JOIN item_categories c ON i.category_id = c.id WHERE UPPER(i.code) = UPPER(?) OR i.name LIKE ? LIMIT 1");
    $stIt->execute([$code, "%{$code}%"]);
    $it = $stIt->fetch(PDO::FETCH_ASSOC);
    if (!$it && str_contains($code, '-')) {
        $allItems = $pdo->query("SELECT i.*, COALESCE(c.name, 'Chung') AS category FROM items i LEFT JOIN item_categories c ON i.category_id = c.id ORDER BY LENGTH(i.code) DESC")->fetchAll(PDO::FETCH_ASSOC);
        $uCode = strtoupper($code);
        foreach ($allItems as $cand) {
            $prefix = strtoupper((string)$cand['code']) . '-';
            if (str_starts_with($uCode, $prefix)) {
                $it = $cand;
                if ($batchHint === '') {
                    $batchHint = substr($code, strlen($prefix));
                }
                break;
            }
        }
    }
    if ($it) {
        $whs = $pdo->query("SELECT id, code FROM warehouses ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $total = 0;
        $byWh = [];
        foreach ($whs as $w) {
            $q = StockService::getCurrentStock($pdo, (int)$w['id'], (int)$it['id'], null);
            $byWh[] = $w['code'] . ': ' . $q;
            $total += $q;
        }
        $it['total_stock'] = $total;
        $it['wh_breakdown'] = implode(' | ', $byWh);
        if ($batchHint !== '' && strtoupper($batchHint) !== 'DEFAULT') {
            $it['matched_batch'] = $batchHint;
        }
        echo json_encode(['ok' => true, 'type' => 'ITEM', 'data' => $it]);
        exit;
    }

    echo json_encode(['ok' => false, 'type' => 'NOT_FOUND', 'code' => $code]);
    exit;
}

if ($action === 'assign_bin_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $locId = (int)($_POST['location_id'] ?? 0);
    $itemId = !empty($_POST['item_id']) ? (int)$_POST['item_id'] : null;
    try {
        $st = $pdo->prepare("SELECT warehouse_id FROM warehouse_locations WHERE id = ?");
        $st->execute([$locId]);
        $whId = $st->fetchColumn();
        if (!$whId) throw new RuntimeException('Không tìm thấy ô kệ');
        if (!Auth::checkWarehousePermission($pdo, (int)$user['id'], (int)$whId, 'create')) {
            throw new RuntimeException('PERMISSION_DENIED: Bạn không có quyền sửa ô kệ tại kho này');
        }
        $pdo->prepare("UPDATE warehouse_locations SET item_id = ? WHERE id = ?")->execute([$itemId, $locId]);
        echo json_encode(['ok' => true, 'message' => $itemId ? 'Đã gán sản phẩm vào ô kệ!' : 'Đã làm trống ô kệ!']);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_bin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $locId = (int)($_POST['location_id'] ?? 0);
    try {
        $st = $pdo->prepare("SELECT warehouse_id FROM warehouse_locations WHERE id = ?");
        $st->execute([$locId]);
        $whId = $st->fetchColumn();
        if (!$whId) throw new RuntimeException('Không tìm thấy ô kệ');
        if (!Auth::checkWarehousePermission($pdo, (int)$user['id'], (int)$whId, 'create')) {
            throw new RuntimeException('PERMISSION_DENIED: Bạn không có quyền xóa ô kệ tại kho này');
        }
        $pdo->prepare("DELETE FROM warehouse_locations WHERE id = ?")->execute([$locId]);
        echo json_encode(['ok' => true, 'message' => 'Đã xóa ô kệ thành công!']);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'OK']);

