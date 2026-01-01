<?php
require_once '../config.php';

$db = Database::getInstance()->getConnection();

$token = $_GET['token'] ?? '';
$myId = Database::getTokenUser($token);

if (!$myId) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

try {
    // Получаем чаты пользователя
    $stmt = $db->prepare("
        SELECT uc.*,
               CASE 
                   WHEN uc.user1_id = :my_id THEN uc.user2_id
                   ELSE uc.user1_id
               END as other_user_id,
               CASE 
                   WHEN uc.user1_id = :my_id THEN u2.username
                   ELSE u1.username
               END as other_username,
               CASE 
                   WHEN uc.user1_id = :my_id THEN u2.display_name
                   ELSE u1.display_name
               END as other_display_name
        FROM user_chats uc
        LEFT JOIN users u1 ON uc.user1_id = u1.id
        LEFT JOIN users u2 ON uc.user2_id = u2.id
        WHERE uc.user1_id = :my_id OR uc.user2_id = :my_id
        ORDER BY uc.last_message_time DESC
    ");
    $stmt->execute([':my_id' => $myId]);
    $chats = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'chats' => $chats,
        'my_id' => $myId
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error'
    ]);
}
?>