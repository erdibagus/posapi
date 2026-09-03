<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->query("SELECT * FROM pelanggan ORDER BY nama_pelanggan");
    res(true, $stmt->fetchAll());
}
if ($method === 'POST') {
    $body = getBody();
    $action = $body['action'] ?? '';
    if ($action === 'create') {
        $db->prepare("INSERT INTO pelanggan (nama_pelanggan, tipe_pelanggan, limit_konsinyasi, telepon, email, alamat, keterangan) VALUES (?,?,?,?,?,?,?)")->execute([$body['nama_pelanggan'], $body['tipe_pelanggan'] ?? 'tunai', $body['limit_konsinyasi'] ?? 0, $body['telepon'] ?? '', $body['email'] ?? '', $body['alamat'] ?? '', $body['keterangan'] ?? '']);
        res(true, null, 'Pelanggan ditambahkan');
    }
    if ($action === 'update') {
        $db->prepare("UPDATE pelanggan SET nama_pelanggan=?, tipe_pelanggan=?, limit_konsinyasi=?, telepon=?, email=?, alamat=?, keterangan=? WHERE id=?")->execute([$body['nama_pelanggan'], $body['tipe_pelanggan'] ?? 'tunai', $body['limit_konsinyasi'] ?? 0, $body['telepon'] ?? '', $body['email'] ?? '', $body['alamat'] ?? '', $body['keterangan'] ?? '', $body['id']]);
        res(true, null, 'Pelanggan diperbarui');
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM pelanggan WHERE id=?")->execute([$body['id']]);
        res(true, null, 'Pelanggan dihapus');
    }
}
res(false, null, 'Method tidak valid');
