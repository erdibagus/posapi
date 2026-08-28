<?php
require_once __DIR__ . '/config/database.php';
cors();

$db = (new Database())->connect();

// Reset password admin dan kasir ke "123456"
$newPassword = '123456';
$hash = password_hash($newPassword, PASSWORD_BCRYPT);

$db->prepare("UPDATE users SET password = ? WHERE username = 'admin'")->execute([$hash]);
$db->prepare("UPDATE users SET password = ? WHERE username = 'kasir'")->execute([$hash]);

// Verifikasi
$stmt = $db->query("SELECT username, role FROM users");
$users = $stmt->fetchAll();

// Test ulang
$testResults = [];
foreach ($users as $u) {
    $stmt2 = $db->prepare("SELECT password FROM users WHERE username = ?");
    $stmt2->execute([$u['username']]);
    $row = $stmt2->fetch();
    $testResults[] = [
        'username' => $u['username'],
        'verify_123456' => password_verify($newPassword, $row['password']),
    ];
}

echo json_encode([
    'status' => 'success',
    'message' => 'Password semua user direset ke 123456',
    'verify_results' => $testResults,
], JSON_PRETTY_PRINT);
