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

        $id_pelanggan = $body['id_pelanggan'] ?? null;
        $tipe_transaksi = $id_pelanggan ? 'konsinyasi' : 'tunai';

        $total = array_reduce($items, function ($sum, $i) {
            $h = $i['satuan'] === 'dus' ? $i['harga_jual_dus'] : $i['harga_jual_pcs'];
            return $sum + $h * $i['jumlah'];
        }, 0);
        $kembalian = max(0, $body['total_bayar'] - $total);
        $faktur = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

        $db->beginTransaction();
        try {
            $tStmt = $db->prepare("INSERT INTO transaksi (no_faktur, id_user, id_pelanggan, total_harga, total_bayar, kembalian, metode_pembayaran, tipe_transaksi) VALUES (?,?,?,?,?,?,?,?)");
            $tStmt->execute([$faktur, $body['id_user'], $id_pelanggan, $total, $body['total_bayar'], $kembalian, $body['metode_pembayaran'], $tipe_transaksi]);
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
                
                if ($tipe_transaksi === 'konsinyasi' && $id_pelanggan) {
                    // Kurangi dari stok konsinyasi
                    $db->prepare("UPDATE stok_konsinyasi SET stok_pcs = stok_pcs - ? WHERE id_pelanggan=? AND id_produk=?")
                       ->execute([$redPcs, $id_pelanggan, $i['id']]);
                    // Hapus jika stok 0
                    $db->prepare("DELETE FROM stok_konsinyasi WHERE id_pelanggan=? AND stok_pcs <= 0")->execute([$id_pelanggan]);
                } else {
                    // Kurangi dari stok produk utama (transaksi tunai)
                    $db->prepare("UPDATE produk SET stok_pcs = stok_pcs - ? WHERE id=?")->execute([$redPcs, $i['id']]);
                }
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
            // Get transaksi info
            $tStmt = $db->prepare("SELECT tipe_transaksi, id_pelanggan FROM transaksi WHERE id=?");
            $tStmt->execute([$id]);
            $tx = $tStmt->fetch();
            
            $dStmt = $db->prepare("SELECT dt.*, p.isi_per_dus FROM detail_transaksi dt JOIN produk p ON dt.id_produk=p.id WHERE dt.id_transaksi=?");
            $dStmt->execute([$id]);
            foreach ($dStmt->fetchAll() as $d) {
                $addPcs = $d['satuan'] === 'dus' ? $d['jumlah'] * ($d['isi_per_dus'] ?? 1) : $d['jumlah'];
                
                if ($tx && $tx['tipe_transaksi'] === 'konsinyasi' && $tx['id_pelanggan']) {
                    // Kembalikan ke stok konsinyasi
                    $checkStmt = $db->prepare("SELECT id FROM stok_konsinyasi WHERE id_pelanggan=? AND id_produk=?");
                    $checkStmt->execute([$tx['id_pelanggan'], $d['id_produk']]);
                    if ($checkStmt->fetch()) {
                        $db->prepare("UPDATE stok_konsinyasi SET stok_pcs = stok_pcs + ? WHERE id_pelanggan=? AND id_produk=?")
                           ->execute([$addPcs, $tx['id_pelanggan'], $d['id_produk']]);
                    } else {
                        $db->prepare("INSERT INTO stok_konsinyasi (id_pelanggan, id_produk, stok_pcs) VALUES (?,?,?)")
                           ->execute([$tx['id_pelanggan'], $d['id_produk'], $addPcs]);
                    }
                } else {
                    // Kembalikan ke stok produk utama
                    $db->prepare("UPDATE produk SET stok_pcs = stok_pcs + ? WHERE id=?")->execute([$addPcs, $d['id_produk']]);
                }
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
            // Get transaksi info
            $tStmt = $db->prepare("SELECT tipe_transaksi, id_pelanggan FROM transaksi WHERE id=?");
            $tStmt->execute([$id]);
            $tx = $tStmt->fetch();
            
            // 1. Kembalikan stok dari transaksi lama
            $dStmt = $db->prepare("SELECT dt.*, p.isi_per_dus FROM detail_transaksi dt JOIN produk p ON dt.id_produk=p.id WHERE dt.id_transaksi=?");
            $dStmt->execute([$id]);
            foreach ($dStmt->fetchAll() as $d) {
                $addPcs = $d['satuan'] === 'dus' ? $d['jumlah'] * ($d['isi_per_dus'] ?? 1) : $d['jumlah'];
                
                if ($tx && $tx['tipe_transaksi'] === 'konsinyasi' && $tx['id_pelanggan']) {
                    // Kembalikan ke stok konsinyasi
                    $checkStmt = $db->prepare("SELECT id FROM stok_konsinyasi WHERE id_pelanggan=? AND id_produk=?");
                    $checkStmt->execute([$tx['id_pelanggan'], $d['id_produk']]);
                    if ($checkStmt->fetch()) {
                        $db->prepare("UPDATE stok_konsinyasi SET stok_pcs = stok_pcs + ? WHERE id_pelanggan=? AND id_produk=?")
                           ->execute([$addPcs, $tx['id_pelanggan'], $d['id_produk']]);
                    } else {
                        $db->prepare("INSERT INTO stok_konsinyasi (id_pelanggan, id_produk, stok_pcs) VALUES (?,?,?)")
                           ->execute([$tx['id_pelanggan'], $d['id_produk'], $addPcs]);
                    }
                } else {
                    $db->prepare("UPDATE produk SET stok_pcs = stok_pcs + ? WHERE id=?")->execute([$addPcs, $d['id_produk']]);
                }
            }

            // 2. Hapus detail transaksi lama
            $db->prepare("DELETE FROM detail_transaksi WHERE id_transaksi=?")->execute([$id]);

            // 3. Update header transaksi
            $tStmt2 = $db->prepare("UPDATE transaksi SET total_harga=?, total_bayar=?, kembalian=?, metode_pembayaran=? WHERE id=?");
            $tStmt2->execute([$total, $body['total_bayar'], $kembalian, $body['metode_pembayaran'], $id]);

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
                
                if ($tx && $tx['tipe_transaksi'] === 'konsinyasi' && $tx['id_pelanggan']) {
                    // Kurangi dari stok konsinyasi
                    $db->prepare("UPDATE stok_konsinyasi SET stok_pcs = stok_pcs - ? WHERE id_pelanggan=? AND id_produk=?")
                       ->execute([$redPcs, $tx['id_pelanggan'], $i['id']]);
                    $db->prepare("DELETE FROM stok_konsinyasi WHERE id_pelanggan=? AND stok_pcs <= 0")->execute([$tx['id_pelanggan']]);
                } else {
                    $db->prepare("UPDATE produk SET stok_pcs = stok_pcs - ? WHERE id=?")->execute([$redPcs, $i['id']]);
                }
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
