<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Seeder.php';

use App\Database;
use App\Seeder;

$pdo = Database::getConnection();
Database::initSchema($pdo);
$res = Seeder::run($pdo);

echo "CSDL_SAN_SANG: {$res['warehouses']} kho, {$res['items']} mặt hàng, {$res['movements']} dòng phát sinh.\n";
