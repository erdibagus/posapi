<?php
// Database Configuration Example
// Copy file ini menjadi database.php dan sesuaikan kredensial

// Development
// $host = 'localhost';
// $db   = 'pos_db';
// $user = 'root';
// $pass = '';

// Production
$host = 'localhost';  // atau IP server database
$db   = 'your_database_name';
$user = 'your_database_user';
$pass = 'your_database_password';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode([
        'status' => false,
        'message' => 'Database connection failed'
    ]));
}
