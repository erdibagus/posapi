<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM kategori ORDER BY nama_kategori ASC");
    $kategori = $stmt->fetchAll();
    jsonResponse(true, 'Data kategori berhasil diambil', $kategori);
}

if ($method === 'POST') {
    $action = isset($data['action']) ? $data['action'] : 'create';

    if ($action === 'create') {
        $nama_kategori = trim(isset($data['nama_kategori']) ? $data['nama_kategori'] : '');
        if (empty($nama_kategori)) {
            jsonResponse(false, 'Nama kategori wajib diisi!', null, 400);
        }

        $stmt = $pdo->prepare("INSERT INTO kategori (nama_kategori) VALUES (?)");
        $stmt->execute([$nama_kategori]);
        jsonResponse(true, 'Kategori berhasil ditambahkan', ['id' => $pdo->lastInsertId()]);
    }

    if ($action === 'update') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        $nama_kategori = trim(isset($data['nama_kategori']) ? $data['nama_kategori'] : '');

        if (!$id || empty($nama_kategori)) {
            jsonResponse(false, 'Data tidak lengkap!', null, 400);
        }

        $stmt = $pdo->prepare("UPDATE kategori SET nama_kategori = ? WHERE id = ?");
        $stmt->execute([$nama_kategori, $id]);
        jsonResponse(true, 'Kategori berhasil diperbarui');
    }

    if ($action === 'delete') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        if (!$id) {
            jsonResponse(false, 'ID kategori wajib diisi!', null, 400);
        }

        $stmt = $pdo->prepare("DELETE FROM kategori WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(true, 'Kategori berhasil dihapus');
    }
}
