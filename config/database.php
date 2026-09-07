<?php
// backend/config/database.php
class Database {
    private $host = 'localhost';
    private $db   = 'kumonpur_kasir';
    private $user = 'kumonpur_bagus';
    private $pass = 'gagaso123!';
    // private $db   = 'kasirku_db';
    // private $user = 'root';
    // private $pass = '';
    private $charset = 'utf8mb4';
    public $conn;

    public function connect() {
        if ($this->conn) return $this->conn;
        $dsn = "mysql:host={$this->host};dbname={$this->db};charset={$this->charset}";
        try {
            $this->conn = new PDO($dsn, $this->user, $this->pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['status' => false, 'message' => 'DB Error: ' . $e->getMessage()]));
        }
        return $this->conn;
    }
}

function cors() {
    // Daftar origin yang diizinkan
    $allowed_origins = [
        'https://pos.kumonpurinkendal.my.id',
        'http://localhost:3000',
        'http://localhost:5173'
    ];
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    // Set Access-Control-Allow-Origin header
    if (in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
    } else {
        // Fallback ke origin pertama (production)
        header('Access-Control-Allow-Origin: https://pos.kumonpurinkendal.my.id');
    }
    
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400'); // 24 hours
    header('Content-Type: application/json; charset=UTF-8');
    
    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
        http_response_code(204); // No Content
        exit; 
    }
}

function res($status, $data = null, $message = '') {
    // Disable cache untuk semua API response
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo json_encode(['status' => $status, 'data' => $data, 'message' => $message]);
    exit;
}

function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function getToken() {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s(\S+)/', $auth, $m)) return $m[1];
    return null;
}
