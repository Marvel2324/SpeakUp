<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Получаем токен
$token = $_GET['token'] ?? '';
if (empty($token)) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
    }
}

if (empty($token)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['token'] ?? '';
}

if (empty($token)) {
    echo json_encode(['success' => false, 'error' => 'No token']);
    exit;
}

$payload = json_decode(base64_decode($token), true);
if (!$payload || !isset($payload['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$user_id = $payload['user_id'];

try {
    // Просто обновляем время последней активности без изменения статуса
    $stmt = $pdo->prepare("
        UPDATE users 
        SET last_seen = NOW() 
        WHERE id = ? AND status != 'offline'
    ");
    $stmt->execute([$user_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Ping successful',
        'timestamp' => time(),
        'datetime' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Ping error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>