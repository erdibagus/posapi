<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();

// Dashboard data
$today = date('Y-m-d');
$month = date('Y-m');

$data = [];

// Today's income
$s = $db->prepare("SELECT COALESCE(SUM(total_harga),0) as total, COUNT(*) as cnt FROM transaksi WHERE DATE(tanggal)=?");
$s->execute([$today]);
$row = $s->fetch();
$data['pendapatan_hari_ini'] = $row['total'];
$data['total_transaksi_hari_ini'] = $row['cnt'];

// Monthly omset
$s = $db->prepare("SELECT COALESCE(SUM(total_harga),0) as total, COUNT(*) as cnt FROM transaksi WHERE DATE_FORMAT(tanggal,'%Y-%m')=?");
$s->execute([$month]);
$row = $s->fetch();
$data['omset_bulan_ini'] = $row['total'];
$data['total_transaksi_bulan_ini'] = $row['cnt'];

// Total HPP this month
$s = $db->prepare("SELECT COALESCE(SUM(dt.total_hpp),0) as hpp FROM detail_transaksi dt JOIN transaksi t ON dt.id_transaksi=t.id WHERE DATE_FORMAT(t.tanggal,'%Y-%m')=?");
$s->execute([$month]);
$hpp = $s->fetch()['hpp'];

// Operational this month
$s = $db->prepare("SELECT COALESCE(SUM(jumlah),0) as ops FROM operasional WHERE DATE_FORMAT(tanggal,'%Y-%m')=?");
$s->execute([$month]);
$ops = $s->fetch()['ops'];

$data['laba_bersih'] = $data['omset_bulan_ini'] - $hpp - $ops;

// Total products & low stock
$data['total_produk'] = $db->query("SELECT COUNT(*) FROM produk")->fetchColumn();
$data['stok_menipis'] = $db->query("SELECT COUNT(*) FROM produk WHERE stok_pcs <= minimum_stok")->fetchColumn();

// Recent transactions
$s = $db->query("SELECT t.*, u.nama AS kasir FROM transaksi t LEFT JOIN users u ON t.id_user=u.id ORDER BY t.tanggal DESC LIMIT 10");
$data['recent_transactions'] = $s->fetchAll();

// Low stock products
$s = $db->query("SELECT * FROM produk WHERE stok_pcs <= minimum_stok ORDER BY stok_pcs ASC LIMIT 8");
$data['low_stock'] = $s->fetchAll();

res(true, $data);
