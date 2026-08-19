<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $preset = isset($_GET['preset']) ? $_GET['preset'] : 'this_month';
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

    if (empty($start_date) || empty($end_date)) {
        if ($preset === 'today') {
            $start_date = date('Y-m-d');
            $end_date = date('Y-m-d');
        } else if ($preset === 'this_month') {
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
        } else if ($preset === 'this_year') {
            $start_date = date('Y-01-01');
            $end_date = date('Y-12-31');
        } else {
            $start_date = '2000-01-01';
            $end_date = '2099-12-31';
        }
    }

    // Total Omset (Sales Revenue)
    $stmtOmset = $pdo->prepare("SELECT COALESCE(SUM(total_harga), 0) as total_omset, COUNT(id) as total_transaksi FROM transaksi WHERE DATE(tanggal) BETWEEN ? AND ?");
    $stmtOmset->execute([$start_date, $end_date]);
    $omsetRow = $stmtOmset->fetch();
    $total_omset = floatval($omsetRow['total_omset']);
    $total_transaksi = intval($omsetRow['total_transaksi']);

    // Total HPP (Cost of Goods Sold)
    $stmtHpp = $pdo->prepare("SELECT COALESCE(SUM(d.total_hpp), 0) as total_hpp 
                              FROM detail_transaksi d 
                              JOIN transaksi t ON d.id_transaksi = t.id 
                              WHERE DATE(t.tanggal) BETWEEN ? AND ?");
    $stmtHpp->execute([$start_date, $end_date]);
    $hppRow = $stmtHpp->fetch();
    $total_hpp = floatval($hppRow['total_hpp']);

    // Laba Kotor (Gross Profit)
    $laba_kotor = $total_omset - $total_hpp;

    // Total Operasional (Expenses)
    $stmtOp = $pdo->prepare("SELECT COALESCE(SUM(jumlah), 0) as total_operasional FROM operasional WHERE tanggal BETWEEN ? AND ?");
    $stmtOp->execute([$start_date, $end_date]);
    $opRow = $stmtOp->fetch();
    $total_operasional = floatval($opRow['total_operasional']);

    // Laba Bersih (Net Profit)
    $laba_bersih = $laba_kotor - $total_operasional;

    // Top 5 Products Sold
    $stmtTop = $pdo->prepare("SELECT p.nama_produk, SUM(d.jumlah) as total_terjual, d.satuan, SUM(d.subtotal) as total_omset_produk 
                              FROM detail_transaksi d 
                              JOIN transaksi t ON d.id_transaksi = t.id 
                              JOIN produk p ON d.id_produk = p.id 
                              WHERE DATE(t.tanggal) BETWEEN ? AND ? 
                              GROUP BY d.id_produk, d.satuan 
                              ORDER BY total_terjual DESC LIMIT 5");
    $stmtTop->execute([$start_date, $end_date]);
    $top_products = $stmtTop->fetchAll();

    // Daily Sales breakdown for chart
    $stmtDaily = $pdo->prepare("SELECT DATE(t.tanggal) as tgl, SUM(t.total_harga) as omset 
                                FROM transaksi t 
                                WHERE DATE(t.tanggal) BETWEEN ? AND ? 
                                GROUP BY DATE(t.tanggal) 
                                ORDER BY tgl ASC");
    $stmtDaily->execute([$start_date, $end_date]);
    $daily_sales = $stmtDaily->fetchAll();

    jsonResponse(true, 'Data laporan laba bersih berhasil diambil', [
        'period' => [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'preset' => $preset
        ],
        'summary' => [
            'total_omset' => $total_omset,
            'total_hpp' => $total_hpp,
            'laba_kotor' => $laba_kotor,
            'total_operasional' => $total_operasional,
            'laba_bersih' => $laba_bersih,
            'total_transaksi' => $total_transaksi
        ],
        'top_products' => $top_products,
        'daily_sales' => $daily_sales
    ]);
}
