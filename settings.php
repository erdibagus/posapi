<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->query("SELECT * FROM settings");
    $rows = $stmt->fetchAll();
    $cfg = [];
    foreach ($rows as $r) $cfg[$r['key']] = $r['value'];
    res(true, $cfg);
}
if ($method === 'POST') {
    $body = getBody();
    if (($body['action'] ?? '') === 'update') {
        $fields = ['nama_toko', 'alamat', 'telepon', 'tagline'];
        foreach ($fields as $f) {
            if (isset($body[$f])) {
                $exists = $db->prepare("SELECT COUNT(*) FROM settings WHERE `key`=?")->execute([$f]);
                $count = $db->query("SELECT COUNT(*) FROM settings WHERE `key`='$f'")->fetchColumn();
                if ($count > 0) {
                    $db->prepare("UPDATE settings SET value=? WHERE `key`=?")->execute([$body[$f], $f]);
                } else {
                    $db->prepare("INSERT INTO settings (`key`, value) VALUES (?,?)")->execute([$f, $body[$f]]);
                }
            }
        }
        res(true, null, 'Pengaturan disimpan');
    }
}
res(false, null, 'Method tidak valid');
