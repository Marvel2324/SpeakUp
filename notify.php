<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Нет данных']);
    exit;
}

// Получаем токен
$token = $input['token'] ?? '';
if (!$token) {
    echo json_encode(['success' => false, 'error' => 'Токен не указан']);
    exit;
}

// Декодируем токен
$payload = json_decode(base64_decode($token), true);
if (!$payload || !isset($payload['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Неверный токен']);
    exit;
}

$sender_id = $payload['user_id'];
$receiver_id = intval($input['receiver_id'] ?? 0);
$message_preview = substr(trim($input['message'] ?? ''), 0, 100);

if (!$receiver_id) {
    echo json_encode(['success' => false, 'error' => 'ID получателя не указан']);
    exit;
}

try {
    // Определяем порядок ID
    $user1_id = min($sender_id, $receiver_id);
    $user2_id = max($sender_id, $receiver_id);
    
    // Обновляем user_chats - увеличиваем unread_count для получателя
    // и обновляем last_message для обоих пользователей
    
    // Сначала получаем текущий чат
    $stmt = $pdo->prepare("
        SELECT id, unread_count 
        FROM user_chats 
        WHERE user1_id = ? AND user2_id = ?
    ");
    $stmt->execute([$user1_id, $user2_id]);
    $chat = $stmt->fetch();
    
    if (!$chat) {
        echo json_encode(['success' => false, 'error' => 'Чат не найден']);
        exit;
    }
    
    // Обновляем чат: 
    // 1. Для получателя увеличиваем счетчик непрочитанных
    // 2. Для обоих обновляем последнее сообщение и время
    
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
    
    // Также обновляем таблицу chats если она используется
    $stmt = $pdo->prepare("
        UPDATE chats 
        SET 
            last_message = ?,
            last_message_time = NOW()
        WHERE user1_id = ? AND user2_id = ?
    ");
    $stmt->execute([$message_preview, $user1_id, $user2_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Чат обновлен',
        'chat_id' => $chat['id'],
        'user1_id' => $user1_id,
        'user2_id' => $user2_id
    ]);
    
} catch (PDOException $e) {
    error_log("Notify new message error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Ошибка базы данных',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>