<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->query("SELECT o.*, u.nama AS petugas FROM operasional o LEFT JOIN users u ON o.id_user=u.id ORDER BY o.tanggal DESC");
    res(true, $stmt->fetchAll());
}
if ($method === 'POST') {
    $body = getBody();
    $action = $body['action'] ?? '';
    if ($action === 'create') {
        $db->prepare("INSERT INTO operasional (tanggal, kategori, keterangan, jumlah, id_user) VALUES (?,?,?,?,?)")
           ->execute([$body['tanggal'], $body['kategori'], $body['keterangan'], $body['jumlah'], $body['id_user'] ?? null]);
        res(true, null, 'Data operasional ditambahkan');
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM operasional WHERE id=?")->execute([$body['id']]);
        res(true, null, 'Data dihapus');
    }
}
res(false, null, 'Method tidak valid');
