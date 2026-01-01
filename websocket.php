<?php
// api/websocket.php - WebSocket эмуляция через long-polling
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Отключаем буферизацию Nginx

session_write_close(); // Закрываем сессию для работы

$token = $_GET['token'] ?? '';

if (empty($token)) {
    echo "data: {\"error\":\"No token\"}\n\n";
    flush();
    exit;
}

// Подключение к БД
require_once '../config/database.php';

try {
    $pdo = getPDO();
    
    // Проверяем токен
    $stmt = $pdo->prepare("SELECT id FROM users WHERE token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo "data: {\"error\":\"Invalid token\"}\n\n";
        flush();
        exit;
    }
    
    $userId = $user['id'];
    
    // Настройки
    $timeout = 30; // 30 секунд timeout
    $startTime = time();
    
    // Последний ID сообщения, который видел пользователь
    $lastMessageId = $_GET['last_id'] ?? 0;
    
    // Основной цикл long-polling
    while (time() - $startTime < $timeout) {
        // 1. Проверяем новые сообщения
        $stmt = $pdo->prepare("
            SELECT m.*, u.username as sender_name, u.avatar_url 
            FROM messages m 
            LEFT JOIN users u ON m.sender_id = u.id 
            WHERE m.id > ? AND (
                (m.sender_id = ? AND m.receiver_id = ?) OR 
                (m.sender_id = ? AND m.receiver_id = ?)
            )
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$lastMessageId, $userId, $_GET['chat_id'] ?? 0, $_GET['chat_id'] ?? 0, $userId]);
        $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Проверяем новые запросы в друзья
        $stmt = $pdo->prepare("
            SELECT * FROM friend_requests 
            WHERE receiver_id = ? AND status = 'pending' AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute([$userId]);
        $newFriendRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 3. Если есть новые данные - отправляем
        if (!empty($newMessages) || !empty($newFriendRequests)) {
            $response = [
                'success' => true,
                'messages' => $newMessages,
                'friend_requests' => $newFriendRequests,
                'timestamp' => time()
            ];
            
            echo "data: " . json_encode($response) . "\n\n";
            flush();
            
            // Обновляем lastMessageId если есть новые сообщения
            if (!empty($newMessages)) {
                $lastMessageId = end($newMessages)['id'];
            }
            
            break; // Выходим после отправки данных
        }
        
        // Ждем 1 секунду перед следующей проверкой
        sleep(1);
        
        // Отправляем keep-alive каждые 10 секунд
        if ((time() - $startTime) % 10 === 0) {
            echo ": keep-alive\n\n";
            flush();
        }
    }
    
    // Если время вышло
    if (time() - $startTime >= $timeout) {
        echo "data: {\"timeout\":true}\n\n";
        flush();
    }
    
} catch (Exception $e) {
    echo "data: {\"error\":\"" . addslashes($e->getMessage()) . "\"}\n\n";
    flush();
}
?>