<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? '';
$other_user_id = intval($_GET['user_id'] ?? 0);

if (empty($token) || !$other_user_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

$payload = json_decode(base64_decode($token), true);
if (!$payload || !isset($payload['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$user_id = intval($payload['user_id']);

try {
    // Находим chat_id между пользователями
    $stmt = $pdo->prepare("
        SELECT id FROM user_chats 
        WHERE (user1_id = ? AND user2_id = ?) 
           OR (user1_id = ? AND user2_id = ?)
    ");
    $stmt->execute([$user_id, $other_user_id, $other_user_id, $user_id]);
    $chat = $stmt->fetch();
    
    if (!$chat) {
        echo json_encode(['success' => true, 'messages' => [], 'count' => 0]);
        exit;
    }
    
    $chat_id = $chat['id'];
    
    // Получаем сообщения для этого чата
    $stmt = $pdo->prepare("
        SELECT 
            m.*,
            u_sender.username as sender_name,
            u_sender.display_name as sender_display_name,
            u_sender.avatar_url as sender_avatar
        FROM messages m
        LEFT JOIN users u_sender ON m.sender_id = u_sender.id
        WHERE (m.sender_id = ? AND m.receiver_id = ?)
           OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$user_id, $other_user_id, $other_user_id, $user_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Отмечаем сообщения как прочитанные
    $stmt = $pdo->prepare("
        UPDATE messages 
        SET is_read = 1 
        WHERE receiver_id = ? AND sender_id = ? AND is_read = 0
    ");
    $stmt->execute([$user_id, $other_user_id]);
    
    // Обновляем счетчик в user_chats
    $stmt = $pdo->prepare("
        UPDATE user_chats 
        SET unread_count = 0 
        WHERE id = ?
    ");
    $stmt->execute([$chat_id]);
    
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'chat_id' => $chat_id,
        'count' => count($messages)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Get messages by user error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Ошибка загрузки сообщений'
    ]);
}
?>