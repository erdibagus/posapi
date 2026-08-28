<?php
require_once __DIR__ . '/config/database.php';
cors();

$db = (new Database())->connect();
$body = getBody();
$action = $body['action'] ?? $_GET['action'] ?? '';

if ($action === 'login') {
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) res(false, null, 'Username tidak ditemukan');
    if (!password_verify($password, $user['password'])) res(false, null, 'Password salah');

    $token = base64_encode($user['id'] . ':' . $user['role'] . ':' . time());
    unset($user['password']);
    res(true, ['user' => $user, 'token' => $token]);
}

if ($action === 'change_password') {
    $id  = intval($body['id'] ?? 0);
    $old = $body['old'] ?? '';
    $new = $body['new'] ?? '';

    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) res(false, null, 'User tidak ditemukan');
    if (!password_verify($old, $row['password'])) res(false, null, 'Password lama salah');

    $hash = password_hash($new, PASSWORD_BCRYPT);
    $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $id]);
    res(true, null, 'Password berhasil diubah');
}

res(false, null, 'Action tidak valid');
