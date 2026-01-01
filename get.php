<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Подключаем config.php (как у вас)
require_once '../config.php';

// Получаем токен
$token = $_GET['token'] ?? '';
if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Токен не указан']);
    exit;
}

// Декодируем токен (ваш формат с base64)
$payload = json_decode(base64_decode($token), true);
if (!$payload || !isset($payload['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Неверный токен']);
    exit;
}

$user_id = $payload['user_id'];
$with_user = intval($_GET['with_user'] ?? 0);
$chat_id = intval($_GET['chat_id'] ?? 0);
$limit = intval($_GET['limit'] ?? 200); // Увеличим лимит
$offset = intval($_GET['offset'] ?? 0);

try {
    $messages = [];
    
    // Вариант 1: Загружаем по with_user (user_id собеседника)
    if ($with_user > 0) {
        $stmt = $pdo->prepare("
            SELECT m.*, 
                   u.username as sender_username,
                   u.display_name as sender_display_name,
                   u.avatar_url as sender_avatar_url
            FROM messages m
            LEFT JOIN users u ON m.sender_id = u.id
            WHERE (m.sender_id = ? AND m.receiver_id = ?)
               OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$user_id, $with_user, $with_user, $user_id, $limit]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } 
    // Вариант 2: Загружаем по chat_id
    else if ($chat_id > 0) {
        // Сначала получаем информацию о чате
        $stmt = $pdo->prepare("SELECT user1_id, user2_id FROM user_chats WHERE id = ?");
        $stmt->execute([$chat_id]);
        $chat = $stmt->fetch();
        
        if ($chat) {
            // Определяем ID собеседника
            $other_user_id = ($chat['user1_id'] == $user_id) ? $chat['user2_id'] : $chat['user1_id'];
            
            $stmt = $pdo->prepare("
                SELECT m.*, 
                       u.username as sender_username,
                       u.display_name as sender_display_name,
                       u.avatar_url as sender_avatar_url
                FROM messages m
                LEFT JOIN users u ON m.sender_id = u.id
                WHERE (m.sender_id = ? AND m.receiver_id = ?)
                   OR (m.sender_id = ? AND m.receiver_id = ?)
                ORDER BY m.created_at ASC
                LIMIT ?
            ");
            $stmt->execute([$user_id, $other_user_id, $other_user_id, $user_id, $limit]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Не указан пользователь или chat_id']);
        exit;
    }
    
    // Форматируем даты для удобства
    foreach ($messages as &$msg) {
        $msg['created_at_formatted'] = date('H:i', strtotime($msg['created_at']));
        // Добавляем имя отправителя, если его нет
        if (!isset($msg['sender_name']) && isset($msg['sender_display_name'])) {
            $msg['sender_name'] = $msg['sender_display_name'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'count' => count($messages),
        'debug' => DEBUG_MODE ? [
            'user_id' => $user_id,
            'with_user' => $with_user,
            'chat_id' => $chat_id,
            'found_messages' => count($messages)
        ] : null
    ]);
    
} catch (PDOException $e) {
    error_log("Get messages error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Ошибка базы данных',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>