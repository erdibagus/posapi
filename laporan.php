<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');

    // Transactions with HPP
    $stmt = $db->prepare("SELECT t.*, u.nama AS kasir,
        COALESCE((SELECT SUM(dt.total_hpp) FROM detail_transaksi dt WHERE dt.id_transaksi=t.id),0) AS total_hpp,
        t.total_harga - COALESCE((SELECT SUM(dt.total_hpp) FROM detail_transaksi dt WHERE dt.id_transaksi=t.id),0) AS laba_kotor
        FROM transaksi t LEFT JOIN users u ON t.id_user=u.id
        WHERE DATE(t.tanggal) BETWEEN ? AND ?
        ORDER BY t.tanggal DESC");
    $stmt->execute([$from, $to]);
    $transactions = $stmt->fetchAll();

    // Summary
    $sStmt = $db->prepare("SELECT
        COALESCE(SUM(t.total_harga),0) AS total_omset,
        COALESCE((SELECT SUM(dt.total_hpp) FROM detail_transaksi dt JOIN transaksi t2 ON dt.id_transaksi=t2.id WHERE DATE(t2.tanggal) BETWEEN ? AND ?),0) AS total_hpp,
        COUNT(t.id) AS jml_transaksi
        FROM transaksi t WHERE DATE(t.tanggal) BETWEEN ? AND ?");
    $sStmt->execute([$from, $to, $from, $to]);
    $sum = $sStmt->fetch();
    $sum['laba_kotor'] = $sum['total_omset'] - $sum['total_hpp'];

    // Operational in period
    $oStmt = $db->prepare("SELECT COALESCE(SUM(jumlah),0) AS total FROM operasional WHERE tanggal BETWEEN ? AND ?");
    $oStmt->execute([$from, $to]);
    $sum['total_operasional'] = $oStmt->fetch()['total'];
    $sum['laba_bersih'] = $sum['laba_kotor'] - $sum['total_operasional'];

    res(true, ['transactions' => $transactions, 'summary' => $sum]);
}
res(false, null, 'Method tidak valid');
