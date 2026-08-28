<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->query("SELECT * FROM kategori ORDER BY nama_kategori");
    res(true, $stmt->fetchAll());
}
if ($method === 'POST') {
    $body = getBody();
    $action = $body['action'] ?? '';
    if ($action === 'create') {
        $db->prepare("INSERT INTO kategori (nama_kategori) VALUES (?)")->execute([$body['nama_kategori']]);
        res(true, null, 'Kategori ditambahkan');
    }
    if ($action === 'update') {
        $db->prepare("UPDATE kategori SET nama_kategori=? WHERE id=?")->execute([$body['nama_kategori'], $body['id']]);
        res(true, null, 'Kategori diperbarui');
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM kategori WHERE id=?")->execute([$body['id']]);
        res(true, null, 'Kategori dihapus');
    }
}
res(false, null, 'Method tidak valid');
