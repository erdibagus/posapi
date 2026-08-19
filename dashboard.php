<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $today = date('Y-m-d');

    // Total Produk
    $stmt1 = $pdo->query("SELECT COUNT(*) as total FROM produk");
    $total_produk = $stmt1->fetch()['total'];

    // Low Stock Alert (stok <= minimum_stok)
    $stmt2 = $pdo->query("SELECT COUNT(*) as total FROM produk WHERE stok_pcs <= minimum_stok");
    $stok_menipis = $stmt2->fetch()['total'];

    // Today Omset
    $stmt3 = $pdo->prepare("SELECT COALESCE(SUM(total_harga), 0) as total FROM transaksi WHERE DATE(tanggal) = ?");
    $stmt3->execute([$today]);
    $omset_hari_ini = floatval($stmt3->fetch()['total']);

    // Today Transactions Count
    $stmt4 = $pdo->prepare("SELECT COUNT(*) as total FROM transaksi WHERE DATE(tanggal) = ?");
    $stmt4->execute([$today]);
    $transaksi_hari_ini = intval($stmt4->fetch()['total']);

    // Today HPP
    $stmt5 = $pdo->prepare("SELECT COALESCE(SUM(d.total_hpp), 0) as total FROM detail_transaksi d JOIN transaksi t ON d.id_transaksi = t.id WHERE DATE(t.tanggal) = ?");
    $stmt5->execute([$today]);
    $hpp_hari_ini = floatval($stmt5->fetch()['total']);

    // Today Expenses
    $stmt6 = $pdo->prepare("SELECT COALESCE(SUM(jumlah), 0) as total FROM operasional WHERE tanggal = ?");
    $stmt6->execute([$today]);
    $operasional_hari_ini = floatval($stmt6->fetch()['total']);

    $laba_bersih_hari_ini = ($omset_hari_ini - $hpp_hari_ini) - $operasional_hari_ini;

    // Recent 5 Transactions
    $stmt7 = $pdo->query("SELECT t.*, u.nama as kasir_nama FROM transaksi t LEFT JOIN users u ON t.id_user = u.id ORDER BY t.id DESC LIMIT 5");
    $recent_transaksi = $stmt7->fetchAll();

    jsonResponse(true, 'Data dashboard berhasil diambil', [
        'total_produk' => $total_produk,
        'stok_menipis' => $stok_menipis,
        'omset_hari_ini' => $omset_hari_ini,
        'transaksi_hari_ini' => $transaksi_hari_ini,
        'operasional_hari_ini' => $operasional_hari_ini,
        'laba_bersih_hari_ini' => $laba_bersih_hari_ini,
        'recent_transaksi' => $recent_transaksi
    ]);
}
