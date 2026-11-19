<?php
$passwords = ['', '12345678', 'root', '123456'];
$connected = false;
foreach ($passwords as $p) {
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', $p);
        echo "CONNECTED_WITH_PASS: [{$p}]\n";
        $connected = true;
        break;
    } catch (Exception $e) {
        // try next
    }
}
if (!$connected) {
    echo "CANNOT_CONNECT\n";
}
