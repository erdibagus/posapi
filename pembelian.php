<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Get single purchase detail
    if (isset($_GET['id'])) {
        $stmt = $db->prepare("SELECT pb.*, s.nama_supplier FROM pembelian pb LEFT JOIN supplier s ON pb.id_supplier=s.id WHERE pb.id=?");
        $stmt->execute([$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) res(false, null, 'Data tidak ditemukan');

        $dStmt = $db->prepare("SELECT dp.*, p.nama_produk FROM detail_pembelian dp JOIN produk p ON dp.id_produk=p.id WHERE dp.id_pembelian=?");
        $dStmt->execute([$row['id']]);
        $row['details'] = $dStmt->fetchAll();
        res(true, $row);
    }

    // List all purchases with optional filters
    $where = ['1=1'];
    $params = [];
    if (!empty($_GET['from'])) { $where[] = 'pb.tanggal >= ?'; $params[] = $_GET['from']; }
    if (!empty($_GET['to']))   { $where[] = 'pb.tanggal <= ?'; $params[] = $_GET['to']; }
    if (!empty($_GET['id_supplier'])) { $where[] = 'pb.id_supplier = ?'; $params[] = intval($_GET['id_supplier']); }
    $sql = "SELECT pb.*, s.nama_supplier FROM pembelian pb LEFT JOIN supplier s ON pb.id_supplier=s.id WHERE " . implode(' AND ', $where) . " ORDER BY pb.tanggal DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    res(true, $stmt->fetchAll());

}

if ($method === 'POST') {
    $body = getBody();
    $action = $body['action'] ?? '';

    if ($action === 'create') {
        $items = $body['items'] ?? [];
        if (empty($items)) res(false, null, 'Item pembelian kosong');

        $total = array_reduce($items, fn($sum, $i) => $sum + ($i['harga_beli'] * $i['jumlah']), 0);

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO pembelian (no_faktur, id_supplier, tanggal, keterangan, total) VALUES (?,?,?,?,?)");
            $stmt->execute([$body['no_faktur'], $body['id_supplier'] ?: null, $body['tanggal'], $body['keterangan'] ?? '', $total]);
            $pbId = $db->lastInsertId();

            foreach ($items as $item) {
                $jumlah = intval($item['jumlah']);
                $satuan = $item['satuan'] ?? 'pcs';
                $subtotal = $item['harga_beli'] * $jumlah;

                $db->prepare("INSERT INTO detail_pembelian (id_pembelian, id_produk, jumlah, satuan, harga_beli, subtotal) VALUES (?,?,?,?,?,?)")
                   ->execute([$pbId, $item['id_produk'], $jumlah, $satuan, $item['harga_beli'], $subtotal]);

                // Get product isi_per_dus
                $pStmt = $db->prepare("SELECT isi_per_dus FROM produk WHERE id=?");
                $pStmt->execute([$item['id_produk']]);
                $prod = $pStmt->fetch();
                $addPcs = $satuan === 'dus' ? $jumlah * ($prod['isi_per_dus'] ?? 1) : $jumlah;

                $db->prepare("UPDATE produk SET stok_pcs = stok_pcs + ? WHERE id=?")->execute([$addPcs, $item['id_produk']]);
            }
            $db->commit();
            res(true, null, 'Pembelian berhasil disimpan dan stok diperbarui');
        } catch (Exception $e) {
            $db->rollBack();
            res(false, null, 'Gagal: ' . $e->getMessage());
        }
    }

    if ($action === 'delete') {
        $id = intval($body['id']);
        $db->beginTransaction();
        try {
            // Rollback stock
            $dStmt = $db->prepare("SELECT * FROM detail_pembelian WHERE id_pembelian=?");
            $dStmt->execute([$id]);
            $details = $dStmt->fetchAll();

            foreach ($details as $d) {
                $pStmt = $db->prepare("SELECT isi_per_dus FROM produk WHERE id=?");
                $pStmt->execute([$d['id_produk']]);
                $prod = $pStmt->fetch();
                $subPcs = $d['satuan'] === 'dus' ? $d['jumlah'] * ($prod['isi_per_dus'] ?? 1) : $d['jumlah'];
                $db->prepare("UPDATE produk SET stok_pcs = stok_pcs - ? WHERE id=?")->execute([$subPcs, $d['id_produk']]);
            }

            $db->prepare("DELETE FROM pembelian WHERE id=?")->execute([$id]);
            $db->commit();
            res(true, null, 'Pembelian dihapus dan stok di-rollback');
        } catch (Exception $e) {
            $db->rollBack();
            res(false, null, $e->getMessage());
        }
    }
}
res(false, null, 'Method tidak valid');
