<?php
// Test CORS Configuration
header('Access-Control-Allow-Origin: https://pos.kumonpurinkendal.my.id');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

echo json_encode([
    'status' => true,
    'message' => 'CORS is working!',
    'origin' => $_SERVER['HTTP_ORIGIN'] ?? 'No origin header',
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders()
]);
