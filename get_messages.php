<?php
require_once '../config.php';

$db = Database::getInstance()->getConnection();

$token = $_GET['token'] ?? '';
$myId = Database::getTokenUser($token);

if (!$myId) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$other_user_id = $_GET['other_user_id'] ?? null;

if (!$other_user_id) {
    echo json_encode(['success' => false, 'error' => 'Missing other_user_id']);
    exit;
}

try {
    // Получаем только сообщения между двумя пользователями
    $stmt = $db->prepare("
        SELECT m.*, 
               u.username as sender_username,
               u.display_name as sender_display_name
        FROM messages m
        LEFT JOIN users u ON m.sender_id = u.id
        WHERE (m.sender_id = :user1 AND m.receiver_id = :user2)
           OR (m.sender_id = :user2 AND m.receiver_id = :user1)
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([
        ':user1' => $myId,
        ':user2' => $other_user_id
    ]);
    $messages = $stmt->fetchAll();
    
    // Отмечаем как прочитанные
    $stmt = $db->prepare("
        UPDATE messages 
        SET is_read = TRUE 
        WHERE receiver_id = ? AND sender_id = ? AND is_read = FALSE
    ");
    $stmt->execute([$myId, $other_user_id]);
    
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'my_id' => $myId
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error'
    ]);
}
?>