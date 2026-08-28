<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? (getBody()['action'] ?? '');

if ($method === 'GET') {
    if ($action === 'list') {
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-d');
        $stmt = $db->prepare("SELECT t.*, u.nama AS kasir FROM transaksi t LEFT JOIN users u ON t.id_user=u.id WHERE DATE(t.tanggal) BETWEEN ? AND ? ORDER BY t.tanggal DESC");
        $stmt->execute([$from, $to]);
        res(true, $stmt->fetchAll());
    }

    if ($action === 'detail') {
        $id = intval($_GET['id']);
        $tStmt = $db->prepare("SELECT t.*, u.nama AS kasir FROM transaksi t LEFT JOIN users u ON t.id_user=u.id WHERE t.id=?");
        $tStmt->execute([$id]);
        $tx = $tStmt->fetch();
        if (!$tx) res(false, null, 'Transaksi tidak ditemukan');

        $dStmt = $db->prepare("SELECT dt.*, p.nama_produk, p.kode_barcode, p.harga_jual_pcs, p.harga_jual_dus, p.isi_per_dus, p.harga_beli, p.stok_pcs FROM detail_transaksi dt JOIN produk p ON dt.id_produk=p.id WHERE dt.id_transaksi=?");
        $dStmt->execute([$id]);
        $tx['items'] = $dStmt->fetchAll();
        res(true, $tx);
    }
}

if ($method === 'POST') {
    $body = getBody();
    $act  = $body['action'] ?? '';

    if ($act === 'checkout') {
        $items = $body['items'] ?? [];
        if (empty($items)) res(false, null, 'Keranjang kosong');

        $total = array_reduce($items, function ($sum, $i) {
            $h = $i['satuan'] === 'dus' ? $i['harga_jual_dus'] : $i['harga_jual_pcs'];
            return $sum + $h * $i['jumlah'];
        }, 0);
        $kembalian = max(0, $body['total_bayar'] - $total);
        $faktur = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

        $db->beginTransaction();
        try {
            $tStmt = $db->prepare("INSERT INTO transaksi (no_faktur, id_user, total_harga, total_bayar, kembalian, metode_pembayaran) VALUES (?,?,?,?,?,?)");
            $tStmt->execute([$faktur, $body['id_user'], $total, $body['total_bayar'], $kembalian, $body['metode_pembayaran']]);
            $txId = $db->lastInsertId();

            foreach ($items as $i) {
                $harga   = $i['satuan'] === 'dus' ? floatval($i['harga_jual_dus']) : floatval($i['harga_jual_pcs']);
                $hpp     = floatval($i['harga_beli'] ?? 0);
                $jumlah  = intval($i['jumlah']);
                $subtotal = $harga * $jumlah;
                $totalHpp = $hpp * ($i['satuan'] === 'dus' ? $jumlah * ($i['isi_per_dus'] ?? 1) : $jumlah);

                $db->prepare("INSERT INTO detail_transaksi (id_transaksi, id_produk, harga_satuan, jumlah, satuan, subtotal, hpp_satuan, total_hpp) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$txId, $i['id'], $harga, $jumlah, $i['satuan'], $subtotal, $hpp, $totalHpp]);

                // Reduce stock
                $redPcs = $i['satuan'] === 'dus' ? $jumlah * ($i['isi_per_dus'] ?? 1) : $jumlah;
                $db->prepare("UPDATE produk SET stok_pcs = stok_pcs - ? WHERE id=?")->execute([$redPcs, $i['id']]);
            }
            $db->commit();

            // Return receipt data
            $recStmt = $db->prepare("SELECT t.*, u.nama AS kasir FROM transaksi t LEFT JOIN users u ON t.id_user=u.id WHERE t.id=?");
            $recStmt->execute([$txId]);
            $receipt = $recStmt->fetch();
            $dStmt = $db->prepare("SELECT dt.*, p.nama_produk FROM detail_transaksi dt JOIN produk p ON dt.id_produk=p.id WHERE dt.id_transaksi=?");
            $dStmt->execute([$txId]);
            $receipt['items'] = $dStmt->fetchAll();
            res(true, $receipt, 'Transaksi berhasil');
        } catch (Exception $e) {
            $db->rollBack();
            res(false, null, 'Gagal: ' . $e->getMessage());
        }
    }

    if ($act === 'delete') {
        $id = intval($body['id']);
        $db->beginTransaction();
        try {
            $dStmt = $db->prepare("SELECT dt.*, p.isi_per_dus FROM detail_transaksi dt JOIN produk p ON dt.id_produk=p.id WHERE dt.id_transaksi=?");
            $dStmt->execute([$id]);
            foreach ($dStmt->fetchAll() as $d) {
                $addPcs = $d['satuan'] === 'dus' ? $d['jumlah'] * ($d['isi_per_dus'] ?? 1) : $d['jumlah'];
                $db->prepare("UPDATE produk SET stok_pcs = stok_pcs + ? WHERE id=?")->execute([$addPcs, $d['id_produk']]);
            }
            $db->prepare("DELETE FROM transaksi WHERE id=?")->execute([$id]);
            $db->commit();
            res(true, null, 'Transaksi dihapus, stok dikembalikan');
        } catch (Exception $e) {
            $db->rollBack();
            res(false, null, $e->getMessage());
        }
    }

    if ($act === 'update') {
        $id = intval($body['id']);
        $items = $body['items'] ?? [];
        if (empty($items)) res(false, null, 'Keranjang kosong');

        $total = array_reduce($items, function ($sum, $i) {
            $h = $i['satuan'] === 'dus' ? $i['harga_jual_dus'] : $i['harga_jual_pcs'];
            return $sum + $h * $i['jumlah'];
        }, 0);
        $kembalian = max(0, $body['total_bayar'] - $total);

        $db->beginTransaction();
        try {
            // 1. Kembalikan stok dari transaksi lama
            $dStmt = $db->prepare("SELECT dt.*, p.isi_per_dus FROM detail_transaksi dt JOIN produk p ON dt.id_produk=p.id WHERE dt.id_transaksi=?");
            $dStmt->execute([$id]);
            foreach ($dStmt->fetchAll() as $d) {
                $addPcs = $d['satuan'] === 'dus' ? $d['jumlah'] * ($d['isi_per_dus'] ?? 1) : $d['jumlah'];
                $db->prepare("UPDATE produk SET stok_pcs = stok_pcs + ? WHERE id=?")->execute([$addPcs, $d['id_produk']]);
            }

            // 2. Hapus detail transaksi lama
            $db->prepare("DELETE FROM detail_transaksi WHERE id_transaksi=?")->execute([$id]);

            // 3. Update header transaksi
            $tStmt = $db->prepare("UPDATE transaksi SET total_harga=?, total_bayar=?, kembalian=?, metode_pembayaran=? WHERE id=?");
            $tStmt->execute([$total, $body['total_bayar'], $kembalian, $body['metode_pembayaran'], $id]);

            // 4. Insert detail transaksi baru dan kurangi stok
            foreach ($items as $i) {
                $harga   = $i['satuan'] === 'dus' ? floatval($i['harga_jual_dus']) : floatval($i['harga_jual_pcs']);
                $hpp     = floatval($i['harga_beli'] ?? 0);
                $jumlah  = intval($i['jumlah']);
                $subtotal = $harga * $jumlah;
                $totalHpp = $hpp * ($i['satuan'] === 'dus' ? $jumlah * ($i['isi_per_dus'] ?? 1) : $jumlah);

                $db->prepare("INSERT INTO detail_transaksi (id_transaksi, id_produk, harga_satuan, jumlah, satuan, subtotal, hpp_satuan, total_hpp) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$id, $i['id'], $harga, $jumlah, $i['satuan'], $subtotal, $hpp, $totalHpp]);

                // Kurangi stok
                $redPcs = $i['satuan'] === 'dus' ? $jumlah * ($i['isi_per_dus'] ?? 1) : $jumlah;
                $db->prepare("UPDATE produk SET stok_pcs = stok_pcs - ? WHERE id=?")->execute([$redPcs, $i['id']]);
            }

            $db->commit();

            // Return updated data
            $recStmt = $db->prepare("SELECT t.*, u.nama AS kasir FROM transaksi t LEFT JOIN users u ON t.id_user=u.id WHERE t.id=?");
            $recStmt->execute([$id]);
            $receipt = $recStmt->fetch();
            res(true, $receipt, 'Transaksi berhasil diupdate');
        } catch (Exception $e) {
            $db->rollBack();
            res(false, null, 'Gagal update: ' . $e->getMessage());
        }
    }
}
res(false, null, 'Tidak valid');
