<?php
require_once __DIR__ . '/config/database.php';
cors();
$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $status_filter = $_GET['status'] ?? 'all'; // 'all', 'aktif', 'nonaktif'
    
    if ($status_filter === 'aktif') {
        $stmt = $db->query("SELECT * FROM pelanggan WHERE status = 'aktif' ORDER BY nama_pelanggan");
    } elseif ($status_filter === 'nonaktif') {
        $stmt = $db->query("SELECT * FROM pelanggan WHERE status = 'nonaktif' ORDER BY nama_pelanggan");
    } else {
        $stmt = $db->query("SELECT * FROM pelanggan ORDER BY nama_pelanggan");
    }
    
    res(true, $stmt->fetchAll());
}
if ($method === 'POST') {
    $body = getBody();
    $action = $body['action'] ?? '';
    if ($action === 'create') {
        $db->prepare("INSERT INTO pelanggan (nama_pelanggan, tipe_pelanggan, limit_konsinyasi, telepon, email, alamat, keterangan) VALUES (?,?,?,?,?,?,?)")->execute([$body['nama_pelanggan'], $body['tipe_pelanggan'] ?? 'tunai', $body['limit_konsinyasi'] ?? 0, $body['telepon'] ?? '', $body['email'] ?? '', $body['alamat'] ?? '', $body['keterangan'] ?? '']);
        res(true, null, 'Pelanggan ditambahkan');
    }
    if ($action === 'update') {
        $db->prepare("UPDATE pelanggan SET nama_pelanggan=?, tipe_pelanggan=?, limit_konsinyasi=?, telepon=?, email=?, alamat=?, keterangan=? WHERE id=?")->execute([$body['nama_pelanggan'], $body['tipe_pelanggan'] ?? 'tunai', $body['limit_konsinyasi'] ?? 0, $body['telepon'] ?? '', $body['email'] ?? '', $body['alamat'] ?? '', $body['keterangan'] ?? '', $body['id']]);
        res(true, null, 'Pelanggan diperbarui');
    }
    if ($action === 'toggle_status') {
        $id = $body['id'] ?? 0;
        if (!$id) res(false, null, 'ID tidak valid');
        
        // Get current status
        $stmt = $db->prepare("SELECT status FROM pelanggan WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        
        if (!$current) res(false, null, 'Pelanggan tidak ditemukan');
        
        // Toggle status
        $new_status = $current['status'] === 'aktif' ? 'nonaktif' : 'aktif';
        $db->prepare("UPDATE pelanggan SET status = ? WHERE id = ?")->execute([$new_status, $id]);
        
        $msg = $new_status === 'aktif' ? 'Pelanggan diaktifkan' : 'Pelanggan dinonaktifkan';
        res(true, ['new_status' => $new_status], $msg);
    }
    if ($action === 'delete') {
        // Keep for backward compatibility or hard delete if really needed
        $db->prepare("DELETE FROM pelanggan WHERE id=?")->execute([$body['id']]);
        res(true, null, 'Pelanggan dihapus');
    }
}
res(false, null, 'Method tidak valid');
