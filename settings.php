<?php
require_once __DIR__ . '/config/database.php';

// Auto-create settings table if not exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS toko_settings (
        `key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `value` TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Insert defaults if empty
$pdo->exec("
    INSERT IGNORE INTO toko_settings (`key`, `value`) VALUES
    ('nama_toko', 'POS Prime Toko'),
    ('alamat_toko', 'Jl. Merdeka No. 88, Jakarta'),
    ('telepon_toko', '0812-3456-7890'),
    ('footer_struk', 'Terima kasih atas kunjungan Anda!');
");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT `key`, `value` FROM toko_settings");
    $rows = $stmt->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['key']] = $row['value'];
    }
    jsonResponse(true, 'OK', $settings);
}

if ($method === 'POST') {
    $data = getJsonInput();
    $allowed = ['nama_toko', 'alamat_toko', 'telepon_toko', 'footer_struk'];

    $stmt = $pdo->prepare("INSERT INTO toko_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");

    foreach ($allowed as $key) {
        if (isset($data[$key])) {
            $stmt->execute([$key, trim($data[$key])]);
        }
    }

    jsonResponse(true, 'Pengaturan toko berhasil disimpan!');
}

jsonResponse(false, 'Method tidak didukung!', null, 405);
