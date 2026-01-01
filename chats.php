<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? '';
if (empty($token)) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
    }
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

$user_id = intval($payload['user_id']);

try {
    // Обновляем статус
    $updateStmt = $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
    $updateStmt->execute([$user_id]);
    
    // ПОЛУЧАЕМ ВСЕ ЧАТЫ ГДЕ ЕСТЬ СООБЩЕНИЯ
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            CASE 
                WHEN m.sender_id = ? THEN m.receiver_id
                ELSE m.sender_id
            END as other_user_id,
            u.username,
            u.display_name,
            u.avatar_url,
            u.status,
            u.last_seen,
            u.role,
            (SELECT message FROM messages 
             WHERE ((sender_id = ? AND receiver_id = other_user_id) 
                OR (sender_id = other_user_id AND receiver_id = ?))
             ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT created_at FROM messages 
             WHERE ((sender_id = ? AND receiver_id = other_user_id) 
                OR (sender_id = other_user_id AND receiver_id = ?))
             ORDER BY created_at DESC LIMIT 1) as last_message_time,
            (SELECT COUNT(*) FROM messages 
             WHERE sender_id = other_user_id AND receiver_id = ? AND is_read = 0) as unread_count
        FROM messages m
        JOIN users u ON u.id = CASE 
            WHEN m.sender_id = ? THEN m.receiver_id
            ELSE m.sender_id
        END
        WHERE m.sender_id = ? OR m.receiver_id = ?
        ORDER BY last_message_time DESC
    ");
    
    $stmt->execute([
        $user_id, $user_id, $user_id, 
        $user_id, $user_id, $user_id,
        $user_id, $user_id, $user_id
    ]);
    $chats_with_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ПОЛУЧАЕМ ЧАТЫ БЕЗ СООБЩЕНИЙ (НО С СОЗДАННОЙ ЗАПИСЬЮ В user_chats)
    $stmt = $pdo->prepare("
        SELECT 
            uc.id as chat_id,
            CASE 
                WHEN uc.user1_id = ? THEN uc.user2_id
                ELSE uc.user1_id
            END as other_user_id,
            u.username,
            u.display_name,
            u.avatar_url,
            u.status,
            u.last_seen,
            u.role,
            uc.last_message,
            uc.last_message_time,
            uc.unread_count
        FROM user_chats uc
        JOIN users u ON u.id = CASE 
            WHEN uc.user1_id = ? THEN uc.user2_id
            ELSE uc.user1_id
        END
        WHERE (uc.user1_id = ? OR uc.user2_id = ?)
          AND NOT EXISTS (
            SELECT 1 FROM messages m 
            WHERE (m.sender_id = ? AND m.receiver_id = u.id)
               OR (m.sender_id = u.id AND m.receiver_id = ?)
          )
        ORDER BY uc.created_at DESC
    ");
    
    $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
    $chats_without_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Объединяем результаты
    $all_chats = array_merge($chats_with_messages, $chats_without_messages);
    
    // Убираем дубликаты
    $unique_chats = [];
    $seen_users = [];
    
    foreach ($all_chats as $chat) {
        if (!in_array($chat['other_user_id'], $seen_users)) {
            $unique_chats[] = $chat;
            $seen_users[] = $chat['other_user_id'];
        }
    }
    
    // Форматируем данные
    foreach ($unique_chats as &$chat) {
        if ($chat['status'] === 'online') {
            $chat['last_seen_text'] = 'онлайн';
        } else if ($chat['last_seen']) {
            $lastSeen = new DateTime($chat['last_seen']);
            $now = new DateTime();
            $diff = $now->diff($lastSeen);
            
            if ($diff->days > 0) {
                $chat['last_seen_text'] = $diff->days . ' дн. назад';
            } else if ($diff->h > 0) {
                $chat['last_seen_text'] = $diff->h . ' ч. назад';
            } else if ($diff->i > 0) {
                $chat['last_seen_text'] = $diff->i . ' мин. назад';
            } else {
                $chat['last_seen_text'] = 'только что';
            }
        } else {
            $chat['last_seen_text'] = 'давно';
        }
        
        if (empty($chat['last_message'])) {
            $chat['last_message'] = 'Начните диалог';
        }
    }
    
    echo json_encode([
        'success' => true,
        'chats' => $unique_chats,
        'count' => count($unique_chats)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Chats list error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Ошибка загрузки чатов'
    ]);
}
?>