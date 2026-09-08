<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    
    // Daftar pelanggan konsinyasi
    if ($action === 'pelanggan') {
        $stmt = $db->query("SELECT * FROM pelanggan WHERE tipe_pelanggan='konsinyasi' ORDER BY nama_pelanggan");
        res(true, $stmt->fetchAll());
    }
    
    // Stok konsinyasi per pelanggan
    if ($action === 'stok') {
        $id_pelanggan = $_GET['id_pelanggan'] ?? 0;
        $stmt = $db->prepare("
            SELECT sk.*, p.nama_produk, p.kode_barcode, p.isi_per_dus, p.id_kategori,
                   pel.nama_pelanggan,
                   p.harga_jual_pcs as master_harga_pcs, 
                   p.harga_jual_dus as master_harga_dus,
                   COALESCE(kh.harga_jual_pcs, p.harga_jual_pcs) as harga_jual_pcs,
                   COALESCE(kh.harga_jual_dus, p.harga_jual_dus) as harga_jual_dus,
                   kh.harga_jual_pcs as custom_harga_pcs, 
                   kh.harga_jual_dus as custom_harga_dus
            FROM stok_konsinyasi sk
            JOIN produk p ON sk.id_produk = p.id
            JOIN pelanggan pel ON sk.id_pelanggan = pel.id
            LEFT JOIN konsinyasi_harga kh ON kh.id_pelanggan = sk.id_pelanggan AND kh.id_produk = sk.id_produk
            WHERE sk.id_pelanggan = ?
            ORDER BY p.nama_produk
        ");
        $stmt->execute([$id_pelanggan]);
        res(true, $stmt->fetchAll());
    }
    
    // Get setting harga untuk pelanggan (untuk UI setting)
    if ($action === 'harga_setting') {
        $id_pelanggan = $_GET['id_pelanggan'] ?? 0;
        $stmt = $db->prepare("
            SELECT kh.*, p.nama_produk, p.kode_barcode, p.harga_jual_pcs as master_harga_pcs, p.harga_jual_dus as master_harga_dus
            FROM konsinyasi_harga kh
            JOIN produk p ON kh.id_produk = p.id
            WHERE kh.id_pelanggan = ?
            ORDER BY p.nama_produk
        ");
        $stmt->execute([$id_pelanggan]);
        res(true, $stmt->fetchAll());
    }
    
    // Semua stok konsinyasi (untuk overview)
    if ($action === 'stok_all') {
        $stmt = $db->query("
            SELECT sk.*, p.nama_produk, p.kode_barcode, pel.nama_pelanggan,
                   (sk.stok_pcs * sk.harga_konsinyasi) as total_nilai
            FROM stok_konsinyasi sk
            JOIN produk p ON sk.id_produk = p.id
            JOIN pelanggan pel ON sk.id_pelanggan = pel.id
            ORDER BY pel.nama_pelanggan, p.nama_produk
        ");
        res(true, $stmt->fetchAll());
    }
    
    // Riwayat transaksi konsinyasi
    if ($action === 'riwayat') {
        $id_pelanggan = $_GET['id_pelanggan'] ?? 0;
        $from = $_GET['from'] ?? date('Y-m-01');
        $to = $_GET['to'] ?? date('Y-m-d');
        
        $stmt = $db->prepare("
            SELECT tk.*, pel.nama_pelanggan, u.nama as user_nama
            FROM transaksi_konsinyasi tk
            JOIN pelanggan pel ON tk.id_pelanggan = pel.id
            LEFT JOIN users u ON tk.id_user = u.id
            WHERE tk.id_pelanggan = ? AND DATE(tk.tanggal) BETWEEN ? AND ?
            ORDER BY tk.tanggal DESC
        ");
        $stmt->execute([$id_pelanggan, $from, $to]);
        res(true, $stmt->fetchAll());
    }
    
    // Riwayat transaksi konsinyasi semua pelanggan (untuk menu riwayat pengiriman)
    if ($action === 'riwayat_all') {
        $id_pelanggan = $_GET['id_pelanggan'] ?? null;
        $tipe = $_GET['tipe'] ?? null;
        $from = $_GET['from'] ?? date('Y-m-01');
        $to = $_GET['to'] ?? date('Y-m-d');
        
        $sql = "
            SELECT tk.*, pel.nama_pelanggan, u.nama as user_nama
            FROM transaksi_konsinyasi tk
            JOIN pelanggan pel ON tk.id_pelanggan = pel.id
            LEFT JOIN users u ON tk.id_user = u.id
            WHERE DATE(tk.tanggal) BETWEEN ? AND ?
        ";
        $params = [$from, $to];
        
        if ($id_pelanggan) {
            $sql .= " AND tk.id_pelanggan = ?";
            $params[] = $id_pelanggan;
        }
        
        if ($tipe) {
            $sql .= " AND tk.tipe = ?";
            $params[] = $tipe;
        }
        
        $sql .= " ORDER BY tk.tanggal DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        res(true, $stmt->fetchAll());
    }
    
    // Detail transaksi konsinyasi
    if ($action === 'detail') {
        $id = $_GET['id'] ?? 0;
        $stmt = $db->prepare("
            SELECT tk.*, pel.nama_pelanggan, pel.telepon, u.nama as user_nama
            FROM transaksi_konsinyasi tk
            JOIN pelanggan pel ON tk.id_pelanggan = pel.id
            LEFT JOIN users u ON tk.id_user = u.id
            WHERE tk.id = ?
        ");
        $stmt->execute([$id]);
        $transaksi = $stmt->fetch();
        
        if ($transaksi) {
            $detailStmt = $db->prepare("
                SELECT dk.*, p.nama_produk, p.kode_barcode
                FROM detail_konsinyasi dk
                JOIN produk p ON dk.id_produk = p.id
                WHERE dk.id_transaksi_konsinyasi = ?
            ");
            $detailStmt->execute([$id]);
            $transaksi['items'] = $detailStmt->fetchAll();
        }
        
        res(true, $transaksi);
    }
}

if ($method === 'POST') {
    $body = getBody();
    $action = $body['action'] ?? '';
    
    // Kirim barang konsinyasi
    if ($action === 'kirim') {
        $id_pelanggan = $body['id_pelanggan'] ?? 0;
        $items = $body['items'] ?? [];
        $id_user = $body['id_user'] ?? null;
        $keterangan = $body['keterangan'] ?? '';
        
        if (empty($items)) res(false, null, 'Item kosong');
        
        $total_nilai = array_reduce($items, function($sum, $i) {
            return $sum + ($i['harga_konsinyasi'] * $i['jumlah_pcs']);
        }, 0);
        
        $no_konsinyasi = 'KSG-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        
        $db->beginTransaction();
        try {
            // Insert transaksi konsinyasi
            $stmt = $db->prepare("INSERT INTO transaksi_konsinyasi (no_konsinyasi, id_pelanggan, tipe, total_nilai, id_user, keterangan) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$no_konsinyasi, $id_pelanggan, 'kirim', $total_nilai, $id_user, $keterangan]);
            $id_transaksi = $db->lastInsertId();
            
            foreach ($items as $item) {
                $id_produk = $item['id_produk'];
                $jumlah_pcs = $item['jumlah_pcs'];
                $harga = $item['harga_konsinyasi'];
                $subtotal = $harga * $jumlah_pcs;
                
                // Insert detail
                $db->prepare("INSERT INTO detail_konsinyasi (id_transaksi_konsinyasi, id_produk, jumlah, satuan, harga_satuan, subtotal) VALUES (?,?,?,?,?,?)")
                   ->execute([$id_transaksi, $id_produk, $jumlah_pcs, 'pcs', $harga, $subtotal]);
                
                // Update/insert stok konsinyasi
                $checkStmt = $db->prepare("SELECT id, stok_pcs FROM stok_konsinyasi WHERE id_pelanggan=? AND id_produk=?");
                $checkStmt->execute([$id_pelanggan, $id_produk]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    $db->prepare("UPDATE stok_konsinyasi SET stok_pcs = stok_pcs + ?, harga_konsinyasi = ? WHERE id = ?")
                       ->execute([$jumlah_pcs, $harga, $existing['id']]);
                } else {
                    $db->prepare("INSERT INTO stok_konsinyasi (id_pelanggan, id_produk, stok_pcs, harga_konsinyasi) VALUES (?,?,?,?)")
                       ->execute([$id_pelanggan, $id_produk, $jumlah_pcs, $harga]);
                }
                
                // Kurangi stok produk utama
                $db->prepare("UPDATE produk SET stok_pcs = stok_pcs - ? WHERE id = ?")
                   ->execute([$jumlah_pcs, $id_produk]);
            }
            
            $db->commit();
            res(true, ['id' => $id_transaksi, 'no_konsinyasi' => $no_konsinyasi], 'Barang konsinyasi berhasil dikirim');
        } catch (Exception $e) {
            $db->rollBack();
            res(false, null, 'Gagal: ' . $e->getMessage());
        }
    }
    
    // Tarik barang konsinyasi (return)
    if ($action === 'tarik') {
        $id_pelanggan = $body['id_pelanggan'] ?? 0;
        $items = $body['items'] ?? [];
        $id_user = $body['id_user'] ?? null;
        $keterangan = $body['keterangan'] ?? '';
        
        if (empty($items)) res(false, null, 'Item kosong');
        
        $total_nilai = array_reduce($items, function($sum, $i) {
            return $sum + ($i['harga_konsinyasi'] * $i['jumlah_pcs']);
        }, 0);
        
        $no_konsinyasi = 'TRK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        
        $db->beginTransaction();
        try {
            // Insert transaksi konsinyasi
            $stmt = $db->prepare("INSERT INTO transaksi_konsinyasi (no_konsinyasi, id_pelanggan, tipe, total_nilai, id_user, keterangan) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$no_konsinyasi, $id_pelanggan, 'tarik', $total_nilai, $id_user, $keterangan]);
            $id_transaksi = $db->lastInsertId();
            
            foreach ($items as $item) {
                $id_produk = $item['id_produk'];
                $jumlah_pcs = $item['jumlah_pcs'];
                $harga = $item['harga_konsinyasi'];
                $subtotal = $harga * $jumlah_pcs;
                
                // Insert detail
                $db->prepare("INSERT INTO detail_konsinyasi (id_transaksi_konsinyasi, id_produk, jumlah, satuan, harga_satuan, subtotal) VALUES (?,?,?,?,?,?)")
                   ->execute([$id_transaksi, $id_produk, $jumlah_pcs, 'pcs', $harga, $subtotal]);
                
                // Kurangi stok konsinyasi
                $db->prepare("UPDATE stok_konsinyasi SET stok_pcs = stok_pcs - ? WHERE id_pelanggan=? AND id_produk=?")
                   ->execute([$jumlah_pcs, $id_pelanggan, $id_produk]);
                
                // Kembalikan stok produk utama
                $db->prepare("UPDATE produk SET stok_pcs = stok_pcs + ? WHERE id = ?")
                   ->execute([$jumlah_pcs, $id_produk]);
            }
            
            // Hapus stok konsinyasi yang sudah 0
            $db->prepare("DELETE FROM stok_konsinyasi WHERE id_pelanggan=? AND stok_pcs <= 0")->execute([$id_pelanggan]);
            
            $db->commit();
            res(true, ['id' => $id_transaksi, 'no_konsinyasi' => $no_konsinyasi], 'Barang konsinyasi berhasil ditarik');
        } catch (Exception $e) {
            $db->rollBack();
            res(false, null, 'Gagal: ' . $e->getMessage());
        }
    }
    
    // Catat penjualan konsinyasi
    if ($action === 'jual') {
        $id_pelanggan = $body['id_pelanggan'] ?? 0;
        $items = $body['items'] ?? [];
        $id_user = $body['id_user'] ?? null;
        $keterangan = $body['keterangan'] ?? '';
        
        if (empty($items)) res(false, null, 'Item kosong');
        
        $total_nilai = array_reduce($items, function($sum, $i) {
            return $sum + ($i['harga_konsinyasi'] * $i['jumlah_pcs']);
        }, 0);
        
        $no_konsinyasi = 'JKS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        $no_faktur = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        
        $db->beginTransaction();
        try {
            // 1. Insert ke transaksi utama (untuk laporan)
            $tStmt = $db->prepare("INSERT INTO transaksi (no_faktur, id_user, id_pelanggan, total_harga, total_bayar, kembalian, metode_pembayaran, tipe_transaksi) VALUES (?,?,?,?,?,?,?,?)");
            $tStmt->execute([$no_faktur, $id_user, $id_pelanggan, $total_nilai, $total_nilai, 0, 'Konsinyasi', 'konsinyasi']);
            $id_transaksi_utama = $db->lastInsertId();
            
            // 2. Insert transaksi konsinyasi
            $stmt = $db->prepare("INSERT INTO transaksi_konsinyasi (no_konsinyasi, id_pelanggan, tipe, total_nilai, id_user, keterangan) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$no_konsinyasi, $id_pelanggan, 'jual', $total_nilai, $id_user, $keterangan]);
            $id_transaksi = $db->lastInsertId();
            
            foreach ($items as $item) {
                $id_produk = $item['id_produk'];
                $jumlah_pcs = $item['jumlah_pcs'];
                $harga = $item['harga_konsinyasi'];
                $subtotal = $harga * $jumlah_pcs;
                
                // Get HPP dari produk
                $prodStmt = $db->prepare("SELECT harga_beli FROM produk WHERE id=?");
                $prodStmt->execute([$id_produk]);
                $prod = $prodStmt->fetch();
                $hpp = $prod ? $prod['harga_beli'] : 0;
                $total_hpp = $hpp * $jumlah_pcs;
                
                // Insert detail konsinyasi
                $db->prepare("INSERT INTO detail_konsinyasi (id_transaksi_konsinyasi, id_produk, jumlah, satuan, harga_satuan, subtotal) VALUES (?,?,?,?,?,?)")
                   ->execute([$id_transaksi, $id_produk, $jumlah_pcs, 'pcs', $harga, $subtotal]);
                
                // Insert detail transaksi utama (untuk laporan dengan HPP)
                $db->prepare("INSERT INTO detail_transaksi (id_transaksi, id_produk, harga_satuan, jumlah, satuan, subtotal, hpp_satuan, total_hpp) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$id_transaksi_utama, $id_produk, $harga, $jumlah_pcs, 'pcs', $subtotal, $hpp, $total_hpp]);
                
                // Kurangi stok konsinyasi
                $db->prepare("UPDATE stok_konsinyasi SET stok_pcs = stok_pcs - ? WHERE id_pelanggan=? AND id_produk=?")
                   ->execute([$jumlah_pcs, $id_pelanggan, $id_produk]);
            }
            
            // Hapus stok konsinyasi yang sudah 0
            $db->prepare("DELETE FROM stok_konsinyasi WHERE id_pelanggan=? AND stok_pcs <= 0")->execute([$id_pelanggan]);
            
            $db->commit();
            res(true, ['id' => $id_transaksi, 'no_konsinyasi' => $no_konsinyasi, 'no_faktur' => $no_faktur], 'Penjualan konsinyasi berhasil dicatat');
        } catch (Exception $e) {
            $db->rollBack();
            res(false, null, 'Gagal: ' . $e->getMessage());
        }
    }
    
    // Hapus transaksi konsinyasi
    if ($action === 'delete') {
        $id = $body['id'] ?? 0;
        
        $db->beginTransaction();
        try {
            // Get transaksi detail
            $txStmt = $db->prepare("SELECT * FROM transaksi_konsinyasi WHERE id=?");
            $txStmt->execute([$id]);
            $tx = $txStmt->fetch();
            
            if (!$tx) res(false, null, 'Transaksi tidak ditemukan');
            
            // Get items
            $itemStmt = $db->prepare("SELECT * FROM detail_konsinyasi WHERE id_transaksi_konsinyasi=?");
            $itemStmt->execute([$id]);
            $items = $itemStmt->fetchAll();
            
            // VALIDASI: Jika tipe 'kirim', cek apakah barang sudah terjual
            if ($tx['tipe'] === 'kirim') {
                foreach ($items as $item) {
                    $jumlah_kirim = (int)$item['jumlah'];
                    $id_produk = $item['id_produk'];
                    $id_pelanggan = $tx['id_pelanggan'];
                    
                    // Cek stok konsinyasi saat ini
                    $stokStmt = $db->prepare("SELECT sk.stok_pcs, p.nama_produk FROM stok_konsinyasi sk JOIN produk p ON sk.id_produk=p.id WHERE sk.id_pelanggan=? AND sk.id_produk=?");
                    $stokStmt->execute([$id_pelanggan, $id_produk]);
                    $stok = $stokStmt->fetch();
                    
                    if ($stok) {
                        $stok_sekarang = (int)$stok['stok_pcs'];
                        
                        // Jika stok sekarang < jumlah yang dikirim, berarti sudah ada yang terjual
                        if ($stok_sekarang < $jumlah_kirim) {
                            $terjual = $jumlah_kirim - $stok_sekarang;
                            $db->rollBack();
                            res(false, null, "Tidak dapat menghapus! Produk \"{$stok['nama_produk']}\" sudah terjual {$terjual} pcs. Silakan tarik barang terlebih dahulu atau biarkan transaksi ini.");
                        }
                    }
                    // Jika stok tidak ada (sudah terjual habis atau ditarik semua), cek history penjualan
                    else {
                        // Ambil nama produk
                        $prodStmt = $db->prepare("SELECT nama_produk FROM produk WHERE id=?");
                        $prodStmt->execute([$id_produk]);
                        $prod = $prodStmt->fetch();
                        $nama_produk = $prod ? $prod['nama_produk'] : 'Produk';
                        
                        // Jika stok tidak ada tapi pernah dikirim, berarti sudah terjual/ditarik semua
                        $db->rollBack();
                        res(false, null, "Tidak dapat menghapus! Produk \"{$nama_produk}\" sudah terjual/ditarik semua ({$jumlah_kirim} pcs). Biarkan transaksi ini sebagai history.");
                    }
                }
            }
            
            // Reverse the transaction
            foreach ($items as $item) {
                $jumlah_pcs = $item['jumlah'];
                $id_produk = $item['id_produk'];
                $id_pelanggan = $tx['id_pelanggan'];
                
                if ($tx['tipe'] === 'kirim') {
                    // Kembalikan ke stok utama, kurangi dari konsinyasi
                    $db->prepare("UPDATE stok_konsinyasi SET stok_pcs = stok_pcs - ? WHERE id_pelanggan=? AND id_produk=?")
                       ->execute([$jumlah_pcs, $id_pelanggan, $id_produk]);
                    $db->prepare("UPDATE produk SET stok_pcs = stok_pcs + ? WHERE id=?")
                       ->execute([$jumlah_pcs, $id_produk]);
                } elseif ($tx['tipe'] === 'tarik') {
                    // Kembalikan ke konsinyasi, kurangi dari stok utama
                    $checkStmt = $db->prepare("SELECT id FROM stok_konsinyasi WHERE id_pelanggan=? AND id_produk=?");
                    $checkStmt->execute([$id_pelanggan, $id_produk]);
                    if ($checkStmt->fetch()) {
                        $db->prepare("UPDATE stok_konsinyasi SET stok_pcs = stok_pcs + ? WHERE id_pelanggan=? AND id_produk=?")
                           ->execute([$jumlah_pcs, $id_pelanggan, $id_produk]);
                    } else {
                        $db->prepare("INSERT INTO stok_konsinyasi (id_pelanggan, id_produk, stok_pcs, harga_konsinyasi) VALUES (?,?,?,?)")
                           ->execute([$id_pelanggan, $id_produk, $jumlah_pcs, $item['harga_satuan']]);
                    }
                    $db->prepare("UPDATE produk SET stok_pcs = stok_pcs - ? WHERE id=?")
                       ->execute([$jumlah_pcs, $id_produk]);
                } elseif ($tx['tipe'] === 'jual') {
                    // Kembalikan ke konsinyasi
                    $checkStmt = $db->prepare("SELECT id FROM stok_konsinyasi WHERE id_pelanggan=? AND id_produk=?");
                    $checkStmt->execute([$id_pelanggan, $id_produk]);
                    if ($checkStmt->fetch()) {
                        $db->prepare("UPDATE stok_konsinyasi SET stok_pcs = stok_pcs + ? WHERE id_pelanggan=? AND id_produk=?")
                           ->execute([$jumlah_pcs, $id_pelanggan, $id_produk]);
                    } else {
                        $db->prepare("INSERT INTO stok_konsinyasi (id_pelanggan, id_produk, stok_pcs, harga_konsinyasi) VALUES (?,?,?,?)")
                           ->execute([$id_pelanggan, $id_produk, $jumlah_pcs, $item['harga_satuan']]);
                    }
                }
            }
            
            // Jika tipe 'jual', hapus juga dari tabel transaksi utama
            if ($tx['tipe'] === 'jual') {
                // Cari transaksi utama berdasarkan id_pelanggan dan tanggal yang sama
                $transaksiStmt = $db->prepare("
                    SELECT id FROM transaksi 
                    WHERE id_pelanggan=? 
                    AND tipe_transaksi='konsinyasi' 
                    AND DATE(tanggal) = DATE(?)
                    AND total_harga = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $transaksiStmt->execute([$tx['id_pelanggan'], $tx['tanggal'], $tx['total_nilai']]);
                $transaksi_utama = $transaksiStmt->fetch();
                
                if ($transaksi_utama) {
                    // Hapus detail transaksi utama
                    $db->prepare("DELETE FROM detail_transaksi WHERE id_transaksi=?")->execute([$transaksi_utama['id']]);
                    // Hapus transaksi utama
                    $db->prepare("DELETE FROM transaksi WHERE id=?")->execute([$transaksi_utama['id']]);
                }
            }
            
            // Delete detail konsinyasi
            $db->prepare("DELETE FROM detail_konsinyasi WHERE id_transaksi_konsinyasi=?")->execute([$id]);
            
            // Delete transaction
            $db->prepare("DELETE FROM transaksi_konsinyasi WHERE id=?")->execute([$id]);
            
            // Clean up zero stock
            $db->prepare("DELETE FROM stok_konsinyasi WHERE stok_pcs <= 0")->execute();
            
            $db->commit();
            res(true, null, 'Transaksi konsinyasi dihapus');
        } catch (Exception $e) {
            $db->rollBack();
            res(false, null, 'Gagal: ' . $e->getMessage());
        }
    }
    
    // Save/Update setting harga konsinyasi
    if ($action === 'save_harga') {
        $id_pelanggan = $body['id_pelanggan'] ?? 0;
        $id_produk = $body['id_produk'] ?? 0;
        $harga_jual_pcs = $body['harga_jual_pcs'] ?? null;
        $harga_jual_dus = $body['harga_jual_dus'] ?? null;
        
        if (!$id_pelanggan || !$id_produk) {
            res(false, null, 'Data tidak lengkap');
        }
        
        try {
            // Cek apakah sudah ada setting
            $checkStmt = $db->prepare("SELECT id FROM konsinyasi_harga WHERE id_pelanggan=? AND id_produk=?");
            $checkStmt->execute([$id_pelanggan, $id_produk]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                // Update
                $stmt = $db->prepare("UPDATE konsinyasi_harga SET harga_jual_pcs=?, harga_jual_dus=? WHERE id=?");
                $stmt->execute([$harga_jual_pcs, $harga_jual_dus, $existing['id']]);
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO konsinyasi_harga (id_pelanggan, id_produk, harga_jual_pcs, harga_jual_dus) VALUES (?,?,?,?)");
                $stmt->execute([$id_pelanggan, $id_produk, $harga_jual_pcs, $harga_jual_dus]);
            }
            
            res(true, null, 'Setting harga berhasil disimpan');
        } catch (Exception $e) {
            res(false, null, 'Gagal: ' . $e->getMessage());
        }
    }
    
    // Delete setting harga (reset ke master)
    if ($action === 'delete_harga') {
        $id_pelanggan = $body['id_pelanggan'] ?? 0;
        $id_produk = $body['id_produk'] ?? 0;
        
        try {
            $stmt = $db->prepare("DELETE FROM konsinyasi_harga WHERE id_pelanggan=? AND id_produk=?");
            $stmt->execute([$id_pelanggan, $id_produk]);
            res(true, null, 'Setting harga dihapus, kembali ke harga master');
        } catch (Exception $e) {
            res(false, null, 'Gagal: ' . $e->getMessage());
        }
    }
}

res(false, null, 'Method tidak valid');
