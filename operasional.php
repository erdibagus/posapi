<?php
require_once __DIR__ . '/config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = getJsonInput();

if ($method === 'GET') {
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';

    $sql = "SELECT o.*, u.nama as user_nama 
            FROM operasional o 
            LEFT JOIN users u ON o.id_user = u.id 
            WHERE 1=1";
    $params = [];

    if (!empty($start_date)) {
        $sql .= " AND o.tanggal >= ?";
        $params[] = $start_date;
    }

    if (!empty($end_date)) {
        $sql .= " AND o.tanggal <= ?";
        $params[] = $end_date;
    }

    $sql .= " ORDER BY o.tanggal DESC, o.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $operasionals = $stmt->fetchAll();

    jsonResponse(true, 'Data operasional berhasil diambil', $operasionals);
}

if ($method === 'POST') {
    $action = isset($data['action']) ? $data['action'] : 'create';

    if ($action === 'create') {
        $tanggal = isset($data['tanggal']) && !empty($data['tanggal']) ? $data['tanggal'] : date('Y-m-d');
        $kategori = trim(isset($data['kategori']) ? $data['kategori'] : '');
        $keterangan = trim(isset($data['keterangan']) ? $data['keterangan'] : '');
        $jumlah = floatval(isset($data['jumlah']) ? $data['jumlah'] : 0);
        $id_user = isset($data['id_user']) ? intval($data['id_user']) : 1;

        if (empty($kategori) || empty($keterangan) || $jumlah <= 0) {
            jsonResponse(false, 'Kategori, keterangan, dan jumlah wajib diisi!', null, 400);
        }

        $stmt = $pdo->prepare("INSERT INTO operasional (tanggal, kategori, keterangan, jumlah, id_user) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$tanggal, $kategori, $keterangan, $jumlah, $id_user]);

        jsonResponse(true, 'Biaya operasional berhasil dicatat', ['id' => $pdo->lastInsertId()]);
    }

    if ($action === 'update') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        $tanggal = isset($data['tanggal']) && !empty($data['tanggal']) ? $data['tanggal'] : date('Y-m-d');
        $kategori = trim(isset($data['kategori']) ? $data['kategori'] : '');
        $keterangan = trim(isset($data['keterangan']) ? $data['keterangan'] : '');
        $jumlah = floatval(isset($data['jumlah']) ? $data['jumlah'] : 0);

        if (!$id || empty($kategori) || empty($keterangan) || $jumlah <= 0) {
            jsonResponse(false, 'Data operasional tidak valid!', null, 400);
        }

        $stmt = $pdo->prepare("UPDATE operasional SET tanggal = ?, kategori = ?, keterangan = ?, jumlah = ? WHERE id = ?");
        $stmt->execute([$tanggal, $kategori, $keterangan, $jumlah, $id]);

        jsonResponse(true, 'Data operasional berhasil diperbarui');
    }

    if ($action === 'delete') {
        $id = isset($data['id']) ? intval($data['id']) : 0;
        if (!$id) {
            jsonResponse(false, 'ID operasional wajib diisi!', null, 400);
        }

        $stmt = $pdo->prepare("DELETE FROM operasional WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(true, 'Data operasional berhasil dihapus');
    }
}
