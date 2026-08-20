<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();

if ($method === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : 'history';

    if ($action === 'history') {
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';

        $sql = "SELECT t.*, u.nama as kasir_nama 
                FROM transaksi t 
                LEFT JOIN users u ON t.id_user = u.id 
                WHERE 1=1";
        $params = [];

        if (!empty($start_date)) {
            $sql .= " AND DATE(t.tanggal) >= ?";
            $params[] = $start_date;
        }

        if (!empty($end_date)) {
            $sql .= " AND DATE(t.tanggal) <= ?";
            $params[] = $end_date;
        }

        if (!empty($search)) {
            $sql .= " AND (t.no_faktur LIKE ? OR u.nama LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $sql .= " ORDER BY t.id DESC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $transactions = $stmt->fetchAll();

        jsonResponse(true, 'Data riwayat transaksi berhasil diambil', $transactions);
    }

    if ($action === 'detail') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$id) {
            jsonResponse(false, 'ID transaksi tidak valid', null, 400);
        }

        $stmtHeader = $pdo->prepare("SELECT t.*, u.nama as kasir_nama FROM transaksi t LEFT JOIN users u ON t.id_user = u.id WHERE t.id = ?");
        $stmtHeader->execute([$id]);
        $header = $stmtHeader->fetch();

        if (!$header) {
            jsonResponse(false, 'Transaksi tidak ditemukan', null, 404);
        }

        $stmtItems = $pdo->prepare("SELECT d.*, p.nama_produk, p.kode_barcode, p.harga_jual_pcs, p.harga_jual_dus, p.isi_per_dus, p.harga_beli, p.stok_pcs 
                                   FROM detail_transaksi d 
                                   JOIN produk p ON d.id_produk = p.id 
                                   WHERE d.id_transaksi = ?");
        $stmtItems->execute([$id]);
        $items = $stmtItems->fetchAll();

        $header['items'] = $items;
        jsonResponse(true, 'Detail transaksi berhasil diambil', $header);
    }
}

if ($method === 'POST') {
    $action = isset($data['action']) ? $data['action'] : 'checkout';

    if ($action === 'checkout') {
        $id_user = isset($data['id_user']) ? intval($data['id_user']) : 1;
        $items = isset($data['items']) ? $data['items'] : [];
        $total_bayar = floatval(isset($data['total_bayar']) ? $data['total_bayar'] : 0);
        $metode_pembayaran = isset($data['metode_pembayaran']) ? $data['metode_pembayaran'] : 'Tunai';

        if (empty($items) || !is_array($items)) {
            jsonResponse(false, 'Keranjang belanja kosong!', null, 400);
        }

        // Generate Faktur: INV-YYYYMMDD-HHMMSS-RAND
        $no_faktur = 'INV-' . date('YmdHis') . '-' . rand(100, 999);
        $total_harga = 0;

        $pdo->beginTransaction();

        try {
            // First calculate total and validate products
            $processedItems = [];

            foreach ($items as $item) {
                $produk_id = intval($item['id']);
                $jumlah = intval($item['jumlah']);
                $satuan = strtolower(isset($item['satuan']) ? $item['satuan'] : 'pcs'); // pcs or dus

                if ($jumlah <= 0) continue;

                $stmtP = $pdo->prepare("SELECT * FROM produk WHERE id = ? FOR UPDATE");
                $stmtP->execute([$produk_id]);
                $product = $stmtP->fetch();

                if (!$product) {
                    throw new Exception("Produk ID {$produk_id} tidak ditemukan!");
                }

                $isi_per_dus = max(1, intval($product['isi_per_dus']));
                $hpp_satuan = floatval($product['harga_beli']);

                if ($satuan === 'dus') {
                    $harga_satuan = floatval($product['harga_jual_dus']);
                    $stok_reduction = $jumlah * $isi_per_dus;
                    $total_hpp_item = $hpp_satuan * $stok_reduction;
                } else {
                    $harga_satuan = floatval($product['harga_jual_pcs']);
                    $stok_reduction = $jumlah;
                    $total_hpp_item = $hpp_satuan * $jumlah;
                }

                if ($product['stok_pcs'] < $stok_reduction) {
                    throw new Exception("Stok untuk {$product['nama_produk']} tidak mencukupi! (Sisa stok: {$product['stok_pcs']} pcs, butuh: {$stok_reduction} pcs)");
                }

                $subtotal = $harga_satuan * $jumlah;
                $total_harga += $subtotal;

                $processedItems[] = [
                    'id_produk' => $produk_id,
                    'nama_produk' => $product['nama_produk'],
                    'kode_barcode' => $product['kode_barcode'],
                    'harga_satuan' => $harga_satuan,
                    'jumlah' => $jumlah,
                    'satuan' => $satuan,
                    'subtotal' => $subtotal,
                    'hpp_satuan' => $hpp_satuan,
                    'total_hpp' => $total_hpp_item,
                    'stok_reduction' => $stok_reduction,
                    'new_stok' => $product['stok_pcs'] - $stok_reduction
                ];
            }

            if ($total_bayar < $total_harga) {
                throw new Exception("Jumlah bayar (" . number_format($total_bayar) . ") kurang dari total harga (" . number_format($total_harga) . ")!");
            }

            $kembalian = $total_bayar - $total_harga;

            // Insert Transaksi Header
            $stmtHeader = $pdo->prepare("INSERT INTO transaksi (no_faktur, tanggal, id_user, total_harga, total_bayar, kembalian, metode_pembayaran) VALUES (?, NOW(), ?, ?, ?, ?, ?)");
            $stmtHeader->execute([$no_faktur, $id_user, $total_harga, $total_bayar, $kembalian, $metode_pembayaran]);
            $id_transaksi = $pdo->lastInsertId();

            // Insert Items & Update Stock
            $stmtDetail = $pdo->prepare("INSERT INTO detail_transaksi (id_transaksi, id_produk, harga_satuan, jumlah, satuan, subtotal, hpp_satuan, total_hpp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtUpdateStok = $pdo->prepare("UPDATE produk SET stok_pcs = ? WHERE id = ?");

            foreach ($processedItems as $pi) {
                $stmtDetail->execute([
                    $id_transaksi,
                    $pi['id_produk'],
                    $pi['harga_satuan'],
                    $pi['jumlah'],
                    $pi['satuan'],
                    $pi['subtotal'],
                    $pi['hpp_satuan'],
                    $pi['total_hpp']
                ]);

                $stmtUpdateStok->execute([
                    $pi['new_stok'],
                    $pi['id_produk']
                ]);
            }

            $pdo->commit();

            // Get cashier name for receipt
            $stmtUser = $pdo->prepare("SELECT nama FROM users WHERE id = ?");
            $stmtUser->execute([$id_user]);
            $userRow = $stmtUser->fetch();
            $kasir_nama = $userRow ? $userRow['nama'] : 'Kasir';

            jsonResponse(true, 'Transaksi berhasil dilakukan!', [
                'id' => $id_transaksi,
                'no_faktur' => $no_faktur,
                'tanggal' => date('Y-m-d H:i:s'),
                'kasir_nama' => $kasir_nama,
                'total_harga' => $total_harga,
                'total_bayar' => $total_bayar,
                'kembalian' => $kembalian,
                'metode_pembayaran' => $metode_pembayaran,
                'items' => $processedItems
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, $e->getMessage(), null, 400);
        }
    }

    if ($action === 'update') {
        $id_transaksi = isset($data['id']) ? intval($data['id']) : 0;
        $id_user = isset($data['id_user']) ? intval($data['id_user']) : 1;
        $items = isset($data['items']) ? $data['items'] : [];
        $total_bayar = floatval(isset($data['total_bayar']) ? $data['total_bayar'] : 0);
        $metode_pembayaran = isset($data['metode_pembayaran']) ? $data['metode_pembayaran'] : 'Tunai';

        if (!$id_transaksi || empty($items) || !is_array($items)) {
            jsonResponse(false, 'Data transaksi tidak lengkap!', null, 400);
        }

        $pdo->beginTransaction();

        try {
            // 1. Dapatkan detail lama untuk rollback stok
            $stmtOld = $pdo->prepare("SELECT id_produk, jumlah, satuan FROM detail_transaksi WHERE id_transaksi = ?");
            $stmtOld->execute([$id_transaksi]);
            $oldItems = $stmtOld->fetchAll();

            $stmtProd = $pdo->prepare("SELECT * FROM produk WHERE id = ? FOR UPDATE");
            $stmtRollback = $pdo->prepare("UPDATE produk SET stok_pcs = stok_pcs + ? WHERE id = ?");

            foreach ($oldItems as $old) {
                $stmtProd->execute([$old['id_produk']]);
                $prod = $stmtProd->fetch();
                if ($prod) {
                    $isi_per_dus = max(1, intval($prod['isi_per_dus']));
                    $stok_reduction = ($old['satuan'] === 'dus') ? ($old['jumlah'] * $isi_per_dus) : $old['jumlah'];
                    $stmtRollback->execute([$stok_reduction, $old['id_produk']]);
                }
            }

            // 2. Kalkulasi item baru dan kurangi stok
            $total_harga = 0;
            $processedItems = [];

            foreach ($items as $item) {
                $produk_id = intval($item['id']);
                $jumlah = intval($item['jumlah']);
                $satuan = strtolower(isset($item['satuan']) ? $item['satuan'] : 'pcs');

                if ($jumlah <= 0) continue;

                $stmtProd->execute([$produk_id]);
                $product = $stmtProd->fetch();

                if (!$product) {
                    throw new Exception("Produk ID {$produk_id} tidak ditemukan!");
                }

                $isi_per_dus = max(1, intval($product['isi_per_dus']));
                $hpp_satuan = floatval($product['harga_beli']);

                if ($satuan === 'dus') {
                    $harga_satuan = floatval($product['harga_jual_dus']);
                    $stok_reduction = $jumlah * $isi_per_dus;
                    $total_hpp_item = $hpp_satuan * $stok_reduction;
                } else {
                    $harga_satuan = floatval($product['harga_jual_pcs']);
                    $stok_reduction = $jumlah;
                    $total_hpp_item = $hpp_satuan * $jumlah;
                }

                if ($product['stok_pcs'] < $stok_reduction) {
                    throw new Exception("Stok untuk {$product['nama_produk']} tidak mencukupi! (Sisa stok: {$product['stok_pcs']} pcs, butuh: {$stok_reduction} pcs)");
                }

                $subtotal = $harga_satuan * $jumlah;
                $total_harga += $subtotal;

                $processedItems[] = [
                    'id_produk' => $produk_id,
                    'harga_satuan' => $harga_satuan,
                    'jumlah' => $jumlah,
                    'satuan' => $satuan,
                    'subtotal' => $subtotal,
                    'hpp_satuan' => $hpp_satuan,
                    'total_hpp' => $total_hpp_item,
                    'new_stok' => $product['stok_pcs'] - $stok_reduction
                ];
            }

            if ($total_bayar < $total_harga) {
                throw new Exception("Jumlah bayar kurang dari total harga!");
            }

            $kembalian = $total_bayar - $total_harga;

            // 3. Update tabel transaksi
            $stmtUpdateTrans = $pdo->prepare("UPDATE transaksi SET total_harga = ?, total_bayar = ?, kembalian = ?, metode_pembayaran = ? WHERE id = ?");
            $stmtUpdateTrans->execute([$total_harga, $total_bayar, $kembalian, $metode_pembayaran, $id_transaksi]);

            // 4. Hapus detail lama
            $stmtDel = $pdo->prepare("DELETE FROM detail_transaksi WHERE id_transaksi = ?");
            $stmtDel->execute([$id_transaksi]);

            // 5. Insert detail baru & update stok
            $stmtDetail = $pdo->prepare("INSERT INTO detail_transaksi (id_transaksi, id_produk, harga_satuan, jumlah, satuan, subtotal, hpp_satuan, total_hpp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtUpdateStok = $pdo->prepare("UPDATE produk SET stok_pcs = ? WHERE id = ?");

            foreach ($processedItems as $pi) {
                $stmtDetail->execute([
                    $id_transaksi, $pi['id_produk'], $pi['harga_satuan'], $pi['jumlah'], $pi['satuan'], $pi['subtotal'], $pi['hpp_satuan'], $pi['total_hpp']
                ]);
                $stmtUpdateStok->execute([$pi['new_stok'], $pi['id_produk']]);
            }

            $pdo->commit();
            jsonResponse(true, 'Transaksi berhasil diupdate!');
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Gagal mengupdate transaksi: ' . $e->getMessage(), null, 500);
        }
    }

    if ($action === 'delete') {
        $id_transaksi = isset($data['id']) ? intval($data['id']) : 0;
        if (!$id_transaksi) {
            jsonResponse(false, 'ID transaksi wajib diisi!', null, 400);
        }

        $pdo->beginTransaction();
        try {
            // 1. Dapatkan detail transaksi untuk rollback stok
            $stmtOld = $pdo->prepare("SELECT id_produk, jumlah, satuan FROM detail_transaksi WHERE id_transaksi = ?");
            $stmtOld->execute([$id_transaksi]);
            $oldItems = $stmtOld->fetchAll();

            $stmtProd = $pdo->prepare("SELECT isi_per_dus FROM produk WHERE id = ? FOR UPDATE");
            $stmtRollback = $pdo->prepare("UPDATE produk SET stok_pcs = stok_pcs + ? WHERE id = ?");

            foreach ($oldItems as $old) {
                $stmtProd->execute([$old['id_produk']]);
                $prod = $stmtProd->fetch();
                if ($prod) {
                    $isi_per_dus = max(1, intval($prod['isi_per_dus']));
                    $stok_reduction = ($old['satuan'] === 'dus') ? ($old['jumlah'] * $isi_per_dus) : $old['jumlah'];
                    $stmtRollback->execute([$stok_reduction, $old['id_produk']]);
                }
            }

            // 2. Hapus dari tabel transaksi (CASCADE akan menghapus detail jika di-set di DB, tapi lebih aman eksekusi DELETE dari detail dulu)
            $stmtDelDetail = $pdo->prepare("DELETE FROM detail_transaksi WHERE id_transaksi = ?");
            $stmtDelDetail->execute([$id_transaksi]);

            $stmtDelTrans = $pdo->prepare("DELETE FROM transaksi WHERE id = ?");
            $stmtDelTrans->execute([$id_transaksi]);

            $pdo->commit();
            jsonResponse(true, 'Data riwayat transaksi berhasil dihapus dan stok dikembalikan.');
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Gagal menghapus transaksi: ' . $e->getMessage(), null, 500);
        }
    }
}
