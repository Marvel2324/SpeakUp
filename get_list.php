<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../includes/functions.php';

$token = $_GET['token'] ?? '';
$user = verifyToken($token);

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Неверный токен']);
    exit;
}

try {
    $db = getDatabaseConnection();
    
    // 1. Приватные чаты (и контакты)
    $chats = [];
    
    // Получаем все чаты где пользователь участник
    $stmt = $db->prepare("
        SELECT 
            c.id as chat_id,
            u.id as other_user_id,
            u.username,
            u.display_name,
            u.avatar_url,
            u.role,
            u.is_blocked,
            u.blocked_until,
            (SELECT message FROM messages WHERE chat_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT created_at FROM messages WHERE chat_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_time,
            (SELECT COUNT(*) FROM messages m WHERE m.chat_id = c.id AND m.sender_id != ? AND m.is_read = 0) as unread_count
        FROM chats c
        JOIN chat_participants cp1 ON c.id = cp1.chat_id AND cp1.user_id = ?
        JOIN chat_participants cp2 ON c.id = cp2.chat_id AND cp2.user_id != ?
        JOIN users u ON cp2.user_id = u.id
        WHERE c.is_group = 0
        ORDER BY last_message_time DESC
    ");
    $stmt->execute([$user['id'], $user['id'], $user['id']]);
    $private_chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Группы и каналы
    $stmt = $db->prepare("
        SELECT 
            g.id,
            g.name,
            g.username,
            g.description,
            g.avatar_url,
            g.is_channel,
            g.created_at,
            (SELECT message FROM group_messages WHERE group_id = g.id ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT created_at FROM group_messages WHERE group_id = g.id ORDER BY created_at DESC LIMIT 1) as last_message_time,
            (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.id) as member_count
        FROM groups g
        JOIN group_members gm ON g.id = gm.group_id
        WHERE gm.user_id = ?
        ORDER BY last_message_time DESC
    ");
    $stmt->execute([$user['id']]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Контакты без чатов
    $stmt = $db->prepare("
        SELECT 
            u.id as user_id,
            u.username,
            u.display_name,
            u.avatar_url,
            u.role,
            'contact' as type,
            NULL as last_message,
            NULL as last_message_time,
            0 as unread_count
        FROM user_contacts uc
        JOIN users u ON uc.contact_id = u.id
        WHERE uc.user_id = ? 
        AND NOT EXISTS (
            SELECT 1 FROM chats c
            JOIN chat_participants cp1 ON c.id = cp1.chat_id AND cp1.user_id = ?
            JOIN chat_participants cp2 ON c.id = cp2.chat_id AND cp2.user_id = u.id
            WHERE c.is_group = 0
        )
    ");
    $stmt->execute([$user['id'], $user['id']]);
    $contacts_only = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Объединяем все
    $all_chats = array_merge($private_chats, $groups, $contacts_only);
    
    // Сортируем по времени
    usort($all_chats, function($a, $b) {
        $timeA = strtotime($a['last_message_time'] ?? '1970-01-01');
        $timeB = strtotime($b['last_message_time'] ?? '1970-01-01');
        return $timeB - $timeA;
    });
    
    echo json_encode([
        'success' => true,
        'chats' => $all_chats,
        'count' => count($all_chats)
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка БД: ' . $e->getMessage()]);
}
?>