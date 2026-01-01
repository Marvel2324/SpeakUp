<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$token) {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['token'] ?? '';
}

if (!$token) {
    echo json_encode(['success' => false, 'error' => 'No token']);
    exit;
}

$payload = json_decode(base64_decode($token), true);
if (!$payload || !isset($payload['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$user_id = $payload['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['password'])) {
    echo json_encode(['success' => false, 'error' => 'Enter password']);
    exit;
}

try {
    // Проверяем пароль
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($data['password'], $user['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Incorrect password']);
        exit;
    }
    
    // Удаляем пользователя
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    
    // Удаляем сообщения пользователя
    $stmt = $pdo->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?");
    $stmt->execute([$user_id, $user_id]);
    
    // Удаляем сессии
    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
    $stmt->execute([$user_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Account deleted successfully'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>