<?php
require_once __DIR__ . '/config/database.php';
cors();

$db = (new Database())->connect();

// Test 1: Cek user di database
$stmt = $db->query("SELECT id, username, nama, role, password FROM users");
$users = $stmt->fetchAll();

// Test 2: Coba password_verify langsung
$testResults = [];
foreach ($users as $u) {
    $testResults[] = [
        'username' => $u['username'],
        'role'     => $u['role'],
        'verify_123456' => password_verify('123456', $u['password']),
        'hash_algo' => password_get_info($u['password'])['algoName'],
    ];
}

echo json_encode([
    'total_users' => count($users),
    'password_tests' => $testResults,
    'php_version' => PHP_VERSION,
], JSON_PRETTY_PRINT);
