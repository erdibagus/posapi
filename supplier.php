<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->query("SELECT * FROM supplier ORDER BY nama_supplier");
    res(true, $stmt->fetchAll());
}
if ($method === 'POST') {
    $body = getBody();
    $action = $body['action'] ?? '';
    if ($action === 'create') {
        $db->prepare("INSERT INTO supplier (nama_supplier, telepon, alamat, keterangan) VALUES (?,?,?,?)")->execute([$body['nama_supplier'], $body['telepon'] ?? '', $body['alamat'] ?? '', $body['keterangan'] ?? '']);
        res(true, null, 'Supplier ditambahkan');
    }
    if ($action === 'update') {
        $db->prepare("UPDATE supplier SET nama_supplier=?, telepon=?, alamat=?, keterangan=? WHERE id=?")->execute([$body['nama_supplier'], $body['telepon'] ?? '', $body['alamat'] ?? '', $body['keterangan'] ?? '', $body['id']]);
        res(true, null, 'Supplier diperbarui');
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM supplier WHERE id=?")->execute([$body['id']]);
        res(true, null, 'Supplier dihapus');
    }
}
res(false, null, 'Method tidak valid');
