<?php
header('Content-Type: application/json');
require_once '../config/database.php';

$token = $_POST['token'] ?? '';
$cacheKey = $_POST['cache_key'] ?? '';
$messagesData = $_POST['messages_data'] ?? '[]';

if (empty($token) || empty($cacheKey)) {
    echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
    exit;
}

try {
    // Получаем пользователя
    $stmt = $pdo->prepare("
        SELECT users.id FROM user_sessions 
        JOIN users ON user_sessions.user_id = users.id 
        WHERE user_sessions.access_token = ? 
        AND user_sessions.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Неверный токен']);
        exit;
    }
    
    $userId = $user['id'];
    
    // Сохраняем или обновляем кэш
    $stmt = $pdo->prepare("
        INSERT INTO message_cache (user_id, cache_key, messages_data) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        messages_data = VALUES(messages_data),
        last_updated = NOW()
    ");
    
    $stmt->execute([$userId, $cacheKey, $messagesData]);
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}
?>