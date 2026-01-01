<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');

// Получаем токен
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (strpos($token, 'Bearer ') === 0) {
    $token = substr($token, 7);
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

$input = json_decode(file_get_contents('php://input'), true);
$status = $input['status'] ?? 'online';

try {
    $stmt = $pdo->prepare("
        UPDATE users 
        SET status = ?, 
            last_seen = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$status, $user_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Status updated'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>