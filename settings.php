<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->query("SELECT * FROM settings");
    $rows = $stmt->fetchAll();
    $cfg = [];
    foreach ($rows as $r) {
        $value = $r['value'];
        // Convert string boolean back to actual boolean
        if ($r['key'] === 'suara_barcode') {
            $value = $value === '1' || $value === 'true' || $value === true;
        }
        $cfg[$r['key']] = $value;
    }
    res(true, $cfg);
}
if ($method === 'POST') {
    $body = getBody();
    if (($body['action'] ?? '') === 'update') {
        $fields = ['nama_toko', 'alamat', 'telepon', 'tagline', 'suara_barcode'];
        foreach ($fields as $f) {
            if (isset($body[$f])) {
                $exists = $db->prepare("SELECT COUNT(*) FROM settings WHERE `key`=?")->execute([$f]);
                $count = $db->query("SELECT COUNT(*) FROM settings WHERE `key`='$f'")->fetchColumn();
                $value = $body[$f];
                // Convert boolean to string for storage
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                if ($count > 0) {
                    $db->prepare("UPDATE settings SET value=? WHERE `key`=?")->execute([$value, $f]);
                } else {
                    $db->prepare("INSERT INTO settings (`key`, value) VALUES (?,?)")->execute([$f, $value]);
                }
            }
        }
        res(true, null, 'Pengaturan disimpan');
    }
}
res(false, null, 'Method tidak valid');
