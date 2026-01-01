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

$current_user_id = $payload['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$other_user_id = intval($input['user_id'] ?? 0);

if (!$other_user_id) {
    echo json_encode(['success' => false, 'error' => 'No user_id provided']);
    exit;
}

try {
    // Обновляем сообщения как прочитанные
    $stmt = $pdo->prepare("
        UPDATE messages 
        SET is_read = 1 
        WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
    ");
    $stmt->execute([$other_user_id, $current_user_id]);
    
    // Обновляем счетчик непрочитанных в user_chats
    $stmt = $pdo->prepare("
        UPDATE user_chats 
        SET unread_count = 0 
        WHERE (user1_id = ? AND user2_id = ?) 
           OR (user1_id = ? AND user2_id = ?)
    ");
    $stmt->execute([
        min($current_user_id, $other_user_id),
        max($current_user_id, $other_user_id),
        max($current_user_id, $other_user_id),
        min($current_user_id, $other_user_id)
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Messages marked as read'
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>