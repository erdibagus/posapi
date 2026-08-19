<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();

if ($method === 'GET') {
    $barcode = isset($_GET['barcode']) ? trim($_GET['barcode']) : '';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $kategori = isset($_GET['kategori']) ? intval($_GET['kategori']) : 0;

    // Fast barcode lookup for scanner
    if (!empty($barcode)) {
        $stmt = $pdo->prepare("SELECT p.*, k.nama_kategori, s.nama_supplier 
                               FROM produk p 
                               LEFT JOIN kategori k ON p.id_kategori = k.id 
                               LEFT JOIN supplier s ON p.id_supplier = s.id 
                               WHERE p.kode_barcode = ?");
        $stmt->execute([$barcode]);
        $product = $stmt->fetch();
        if ($product) {
            jsonResponse(true, 'Produk ditemukan', $product);
        } else {
            jsonResponse(false, 'Produk dengan barcode tersebut tidak ditemukan', null, 444);
        }
    }

    $sql = "SELECT p.*, k.nama_kategori, s.nama_supplier 
            FROM produk p 
            LEFT JOIN kategori k ON p.id_kategori = k.id 
            LEFT JOIN supplier s ON p.id_supplier = s.id 
            WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (p.nama_produk LIKE ? OR p.kode_barcode LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    if ($kategori > 0) {
        $sql .= " AND p.id_kategori = ?";
        $params[] = $kategori;
    }

    $sql .= " ORDER BY p.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $produk = $stmt->fetchAll();

    jsonResponse(true, 'Data produk berhasil diambil', $produk);
}

if ($method === 'POST') {
    $action = isset($data['action']) ? $data['action'] : 'create';

    if ($action === 'create') {
        $kode_barcode = trim(isset($data['kode_barcode']) ? $data['kode_barcode'] : '');
        $nama_produk = trim(isset($data['nama_produk']) ? $data['nama_produk'] : '');
        $id_kategori = !empty($data['id_kategori']) ? intval($data['id_kategori']) : null;
        $id_supplier = !empty($data['id_supplier']) ? intval($data['id_supplier']) : null;
        $harga_beli = floatval(isset($data['harga_beli']) ? $data['harga_beli'] : 0);
        $harga_jual_pcs = floatval(isset($data['harga_jual_pcs']) ? $data['harga_jual_pcs'] : 0);
        $harga_jual_dus = floatval(isset($data['harga_jual_dus']) ? $data['harga_jual_dus'] : 0);
        $isi_per_dus = max(1, intval(isset($data['isi_per_dus']) ? $data['isi_per_dus'] : 1));
        $stok_pcs = intval(isset($data['stok_pcs']) ? $data['stok_pcs'] : 0);
        $minimum_stok = intval(isset($data['minimum_stok']) ? $data['minimum_stok'] : 5);

        if (empty($kode_barcode) || empty($nama_produk)) {
            jsonResponse(false, 'Kode barcode dan nama produk wajib diisi!', null, 400);
        }

        // Check duplicate barcode
        $check = $pdo->prepare("SELECT id FROM produk WHERE kode_barcode = ?");
        $check->execute([$kode_barcode]);
        if ($check->fetch()) {
            jsonResponse(false, 'Kode barcode sudah digunakan oleh produk lain!', null, 400);
        }

        $stmt = $pdo->prepare("INSERT INTO produk (kode_barcode, nama_produk, id_kategori, id_supplier, harga_beli, harga_jual_pcs, harga_jual_dus, isi_per_dus, stok_pcs, minimum_stok) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$kode_barcode, $nama_produk, $id_kategori, $id_supplier, $harga_beli, $harga_jual_pcs, $harga_jual_dus, $isi_per_dus, $stok_pcs, $minimum_stok]);
        
        jsonResponse(true, 'Produk berhasil ditambahkan', ['id' => $pdo->lastInsertId()]);
    }

    if ($action === 'update') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        $kode_barcode = trim(isset($data['kode_barcode']) ? $data['kode_barcode'] : '');
        $nama_produk = trim(isset($data['nama_produk']) ? $data['nama_produk'] : '');
        $id_kategori = !empty($data['id_kategori']) ? intval($data['id_kategori']) : null;
        $id_supplier = !empty($data['id_supplier']) ? intval($data['id_supplier']) : null;
        $harga_beli = floatval(isset($data['harga_beli']) ? $data['harga_beli'] : 0);
        $harga_jual_pcs = floatval(isset($data['harga_jual_pcs']) ? $data['harga_jual_pcs'] : 0);
        $harga_jual_dus = floatval(isset($data['harga_jual_dus']) ? $data['harga_jual_dus'] : 0);
        $isi_per_dus = max(1, intval(isset($data['isi_per_dus']) ? $data['isi_per_dus'] : 1));
        $stok_pcs = intval(isset($data['stok_pcs']) ? $data['stok_pcs'] : 0);
        $minimum_stok = intval(isset($data['minimum_stok']) ? $data['minimum_stok'] : 5);

        if (!$id || empty($kode_barcode) || empty($nama_produk)) {
            jsonResponse(false, 'Data produk tidak lengkap!', null, 400);
        }

        // Check duplicate barcode for other products
        $check = $pdo->prepare("SELECT id FROM produk WHERE kode_barcode = ? AND id != ?");
        $check->execute([$kode_barcode, $id]);
        if ($check->fetch()) {
            jsonResponse(false, 'Kode barcode sudah digunakan oleh produk lain!', null, 400);
        }

        $stmt = $pdo->prepare("UPDATE produk SET kode_barcode = ?, nama_produk = ?, id_kategori = ?, id_supplier = ?, harga_beli = ?, harga_jual_pcs = ?, harga_jual_dus = ?, isi_per_dus = ?, stok_pcs = ?, minimum_stok = ? WHERE id = ?");
        $stmt->execute([$kode_barcode, $nama_produk, $id_kategori, $id_supplier, $harga_beli, $harga_jual_pcs, $harga_jual_dus, $isi_per_dus, $stok_pcs, $minimum_stok, $id]);

        jsonResponse(true, 'Produk berhasil diperbarui');
    }

    if ($action === 'delete') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        if (!$id) {
            jsonResponse(false, 'ID produk wajib diisi!', null, 400);
        }

        $stmt = $pdo->prepare("DELETE FROM produk WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(true, 'Produk berhasil dihapus');
    }
}
