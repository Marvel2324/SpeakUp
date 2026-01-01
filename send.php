<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Подключаем конфиг
require_once '../config.php';

// Получаем JSON данные
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Нет данных']);
    exit;
}

// Получаем токен
$token = $input['token'] ?? $_GET['token'] ?? '';
if (!$token) {
    // Пробуем из заголовка
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
    }
}

if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Токен не указан']);
    exit;
}

// Декодируем токен (простой base64)
$payload = json_decode(base64_decode($token), true);
if (!$payload || !isset($payload['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Неверный токен']);
    exit;
}

$sender_id = $payload['user_id'];
$receiver_id = intval($input['receiver_id'] ?? 0);
$message = trim($input['message'] ?? '');
$chat_id = intval($input['chat_id'] ?? 0); // Новый параметр

if (!$receiver_id) {
    echo json_encode(['success' => false, 'error' => 'Не указан получатель']);
    exit;
}

if (!$message) {
    echo json_encode(['success' => false, 'error' => 'Пустое сообщение']);
    exit;
}

try {
    // Проверяем, существует ли получатель
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
    $stmt->execute([$receiver_id]);
    $receiver = $stmt->fetch();
    
    if (!$receiver) {
        echo json_encode(['success' => false, 'error' => 'Получатель не найден']);
        exit;
    }
    
    // Если chat_id не передан, ищем или создаем чат
    if (!$chat_id) {
        $user1_id = min($sender_id, $receiver_id);
        $user2_id = max($sender_id, $receiver_id);
        
        // Проверяем существование чата
        $stmt = $pdo->prepare("SELECT id FROM user_chats WHERE user1_id = ? AND user2_id = ?");
        $stmt->execute([$user1_id, $user2_id]);
        $existing_chat = $stmt->fetch();
        
        if ($existing_chat) {
            $chat_id = $existing_chat['id'];
        } else {
            // Создаем новый чат
            $stmt = $pdo->prepare("
                INSERT INTO user_chats (user1_id, user2_id, created_at, updated_at)
                VALUES (?, ?, NOW(), NOW())
            ");
            $stmt->execute([$user1_id, $user2_id]);
            $chat_id = $pdo->lastInsertId();
            
            // Добавляем в контакты для ОБОИХ пользователей
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO user_contacts (user_id, contact_id, created_at)
                VALUES (?, ?, NOW()), (?, ?, NOW())
            ");
            $stmt->execute([$sender_id, $receiver_id, $receiver_id, $sender_id]);
            
            // Также создаем в таблице chats
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO chats (user1_id, user2_id, created_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$user1_id, $user2_id]);
        }
    }
    
    // Сохраняем сообщение
    $stmt = $pdo->prepare("
        INSERT INTO messages (sender_id, receiver_id, message, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$sender_id, $receiver_id, $message]);
    
    $message_id = $pdo->lastInsertId();
    
    // Получаем данные сообщения для ответа
    $stmt = $pdo->prepare("
        SELECT m.*, 
               u.username as sender_username,
               u.display_name as sender_display_name
        FROM messages m
        LEFT JOIN users u ON m.sender_id = u.id
        WHERE m.id = ?
    ");
    $stmt->execute([$message_id]);
    $message_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Обновляем чат - обновляем last_message и увеличиваем unread_count для получателя
    $user1_id = min($sender_id, $receiver_id);
    $user2_id = max($sender_id, $receiver_id);
    $message_preview = strlen($message) > 100 ? substr($message, 0, 100) . '...' : $message;
    
    // Обновляем user_chats
    $stmt = $pdo->prepare("
        UPDATE user_chats 
        SET 
            last_message = ?,
            last_message_time = NOW(),
            updated_at = NOW()
        WHERE user1_id = ? AND user2_id = ?
    ");
    $stmt->execute([$message_preview, $user1_id, $user2_id]);
    
    // Обновляем таблицу chats (если используется)
    $stmt = $pdo->prepare("
        UPDATE chats 
        SET 
            last_message = ?,
            last_message_time = NOW()
        WHERE user1_id = ? AND user2_id = ?
    ");
    $stmt->execute([$message_preview, $user1_id, $user2_id]);
    
    // Получаем данные отправителя для ответа
    $stmt = $pdo->prepare("
        SELECT username, display_name, avatar_url 
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$sender_id]);
    $sender_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true, 
        'message_id' => $message_id,
        'chat_id' => $chat_id,
        'message' => [
            'id' => $message_data['id'],
            'sender_id' => $message_data['sender_id'],
            'receiver_id' => $message_data['receiver_id'],
            'message' => $message_data['message'],
            'created_at' => $message_data['created_at'],
            'sender_name' => $sender_data['display_name'] ?? $sender_data['username'],
            'sender_username' => $sender_data['username']
        ],
        'debug' => DEBUG_MODE ? [
            'sender' => $sender_id,
            'receiver' => $receiver_id,
            'chat_id' => $chat_id,
            'user1_id' => $user1_id,
            'user2_id' => $user2_id
        ] : null
    ]);
    
} catch (PDOException $e) {
    error_log("Send message error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Ошибка базы данных',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>