<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM supplier ORDER BY nama_supplier ASC");
    $suppliers = $stmt->fetchAll();
    jsonResponse(true, 'Data supplier berhasil diambil', $suppliers);
}

if ($method === 'POST') {
    $action = isset($data['action']) ? $data['action'] : 'create';

    if ($action === 'create') {
        $nama_supplier = trim(isset($data['nama_supplier']) ? $data['nama_supplier'] : '');
        $telepon = trim(isset($data['telepon']) ? $data['telepon'] : '');
        $alamat = trim(isset($data['alamat']) ? $data['alamat'] : '');
        $keterangan = trim(isset($data['keterangan']) ? $data['keterangan'] : '');

        if (empty($nama_supplier)) {
            jsonResponse(false, 'Nama supplier wajib diisi!', null, 400);
        }

        $stmt = $pdo->prepare("INSERT INTO supplier (nama_supplier, telepon, alamat, keterangan) VALUES (?, ?, ?, ?)");
        $stmt->execute([$nama_supplier, $telepon, $alamat, $keterangan]);
        jsonResponse(true, 'Supplier berhasil ditambahkan', ['id' => $pdo->lastInsertId()]);
    }

    if ($action === 'update') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        $nama_supplier = trim(isset($data['nama_supplier']) ? $data['nama_supplier'] : '');
        $telepon = trim(isset($data['telepon']) ? $data['telepon'] : '');
        $alamat = trim(isset($data['alamat']) ? $data['alamat'] : '');
        $keterangan = trim(isset($data['keterangan']) ? $data['keterangan'] : '');

        if (!$id || empty($nama_supplier)) {
            jsonResponse(false, 'Data tidak lengkap!', null, 400);
        }

        $stmt = $pdo->prepare("UPDATE supplier SET nama_supplier = ?, telepon = ?, alamat = ?, keterangan = ? WHERE id = ?");
        $stmt->execute([$nama_supplier, $telepon, $alamat, $keterangan, $id]);
        jsonResponse(true, 'Supplier berhasil diperbarui');
    }

    if ($action === 'delete') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        if (!$id) {
            jsonResponse(false, 'ID supplier wajib diisi!', null, 400);
        }

        $stmt = $pdo->prepare("DELETE FROM supplier WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(true, 'Supplier berhasil dihapus');
    }
}
