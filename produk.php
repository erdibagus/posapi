<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['barcode'])) {
        $stmt = $db->prepare("SELECT p.*, k.nama_kategori FROM produk p LEFT JOIN kategori k ON p.id_kategori=k.id WHERE p.kode_barcode = ?");
        $stmt->execute([$_GET['barcode']]);
        $row = $stmt->fetch();
        if ($row) res(true, $row); else res(false, null, 'Produk tidak ditemukan');
    }
    $stmt = $db->query("SELECT p.*, k.nama_kategori FROM produk p LEFT JOIN kategori k ON p.id_kategori=k.id ORDER BY p.nama_produk");
    res(true, $stmt->fetchAll());
}

if ($method === 'POST') {
    $body = getBody();
    $action = $body['action'] ?? '';

    if ($action === 'create') {
        $stmt = $db->prepare("INSERT INTO produk (kode_barcode, nama_produk, id_kategori, id_supplier, harga_beli, harga_jual_pcs, harga_jual_dus, isi_per_dus, stok_pcs, minimum_stok) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $body['kode_barcode'], $body['nama_produk'],
            $body['id_kategori'] ?: null, $body['id_supplier'] ?: null,
            $body['harga_beli'], $body['harga_jual_pcs'], $body['harga_jual_dus'],
            $body['isi_per_dus'] ?? 1, $body['stok_pcs'] ?? 0, $body['minimum_stok'] ?? 5
        ]);
        res(true, null, 'Produk berhasil ditambahkan');
    }

    if ($action === 'update') {
        $stmt = $db->prepare("UPDATE produk SET kode_barcode=?, nama_produk=?, id_kategori=?, id_supplier=?, harga_beli=?, harga_jual_pcs=?, harga_jual_dus=?, isi_per_dus=?, stok_pcs=?, minimum_stok=? WHERE id=?");
        $stmt->execute([
            $body['kode_barcode'], $body['nama_produk'],
            $body['id_kategori'] ?: null, $body['id_supplier'] ?: null,
            $body['harga_beli'], $body['harga_jual_pcs'], $body['harga_jual_dus'],
            $body['isi_per_dus'], $body['stok_pcs'], $body['minimum_stok'], $body['id']
        ]);
        res(true, null, 'Produk berhasil diperbarui');
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM produk WHERE id = ?")->execute([$body['id']]);
        res(true, null, 'Produk dihapus');
    }
}
res(false, null, 'Method tidak valid');
