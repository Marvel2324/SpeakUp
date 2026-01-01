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

if (!$data || !isset($data['current_password']) || !isset($data['new_password'])) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

try {
    // Проверяем текущий пароль
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($data['current_password'], $user['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
        exit;
    }
    
    // Обновляем пароль
    $new_hash = password_hash($data['new_password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$new_hash, $user_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Password changed successfully'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>