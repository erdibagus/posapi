<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($data['action']) ? $data['action'] : '');

if ($method === 'GET') {
    if ($action === 'history') {
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

        $sql = "SELECT p.*, s.nama_supplier 
                FROM pembelian p 
                LEFT JOIN supplier s ON p.id_supplier = s.id 
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (p.no_faktur LIKE ? OR s.nama_supplier LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if (!empty($start_date)) {
            $sql .= " AND DATE(p.tanggal) >= ?";
            $params[] = $start_date;
        }
        if (!empty($end_date)) {
            $sql .= " AND DATE(p.tanggal) <= ?";
            $params[] = $end_date;
        }

        $sql .= " ORDER BY p.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetchAll();
        jsonResponse(true, 'History pembelian', $history);
    }
    
    if ($action === 'detail') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $stmt = $pdo->prepare("
            SELECT pd.*, pr.nama_produk, pr.kode_barcode 
            FROM pembelian_detail pd 
            JOIN produk pr ON pd.id_produk = pr.id 
            WHERE pd.id_pembelian = ?
        ");
        $stmt->execute([$id]);
        $details = $stmt->fetchAll();
        jsonResponse(true, 'Detail pembelian', $details);
    }
}

if ($method === 'POST') {
    if ($action === 'create') {
        $id_supplier = intval(isset($data['id_supplier']) ? $data['id_supplier'] : 0);
        $no_faktur = trim(isset($data['no_faktur']) ? $data['no_faktur'] : '');
        $keterangan = trim(isset($data['keterangan']) ? $data['keterangan'] : '');
        $items = isset($data['items']) ? $data['items'] : [];
        $total_harga = floatval(isset($data['total_harga']) ? $data['total_harga'] : 0);

        if (!$id_supplier || empty($items)) {
            jsonResponse(false, 'Data supplier atau item tidak lengkap!', null, 400);
        }

        if (empty($no_faktur)) {
            $no_faktur = 'PO-' . date('YmdHis') . '-' . rand(100, 999);
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO pembelian (no_faktur, id_supplier, total_harga, keterangan) VALUES (?, ?, ?, ?)");
            $stmt->execute([$no_faktur, $id_supplier, $total_harga, $keterangan]);
            $id_pembelian = $pdo->lastInsertId();

            $stmtDetail = $pdo->prepare("INSERT INTO pembelian_detail (id_pembelian, id_produk, jumlah, harga_beli, subtotal) VALUES (?, ?, ?, ?, ?)");
            $stmtUpdateStok = $pdo->prepare("UPDATE produk SET stok_pcs = stok_pcs + ?, harga_beli = ? WHERE id = ?");

            foreach ($items as $item) {
                $id_produk = intval($item['id']);
                $jumlah = intval($item['jumlah']);
                $harga_beli = floatval($item['harga_beli']);
                $subtotal = $jumlah * $harga_beli;

                $stmtDetail->execute([$id_pembelian, $id_produk, $jumlah, $harga_beli, $subtotal]);
                $stmtUpdateStok->execute([$jumlah, $harga_beli, $id_produk]);
            }

            $pdo->commit();
            jsonResponse(true, 'Pembelian berhasil dicatat dan stok diperbarui!');
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Gagal memproses pembelian: ' . $e->getMessage(), null, 500);
        }
    }
    
    if ($action === 'delete') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        if (!$id) {
            jsonResponse(false, 'ID pembelian wajib diisi!', null, 400);
        }

        try {
            $pdo->beginTransaction();

            // 1. Get all details for this purchase
            $stmt = $pdo->prepare("SELECT id_produk, jumlah FROM pembelian_detail WHERE id_pembelian = ?");
            $stmt->execute([$id]);
            $details = $stmt->fetchAll();

            // 2. Rollback stock
            $stmtUpdateStok = $pdo->prepare("UPDATE produk SET stok_pcs = stok_pcs - ? WHERE id = ?");
            foreach ($details as $row) {
                $stmtUpdateStok->execute([$row['jumlah'], $row['id_produk']]);
            }

            // 3. Delete purchase (cascade will delete details)
            $stmtDel = $pdo->prepare("DELETE FROM pembelian WHERE id = ?");
            $stmtDel->execute([$id]);

            $pdo->commit();
            jsonResponse(true, 'Riwayat pembelian berhasil dihapus dan stok dikembalikan.');
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Gagal menghapus pembelian: ' . $e->getMessage(), null, 500);
        }
    }
}

jsonResponse(false, 'Endpoint tidak ditemukan', null, 404);
