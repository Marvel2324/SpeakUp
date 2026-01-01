<?php
header('Content-Type: application/json');
require_once '../config/database.php';

$token = $_GET['token'] ?? '';
$cacheKey = $_GET['cache_key'] ?? '';

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
    
    // Загружаем кэш
    $stmt = $pdo->prepare("
        SELECT messages_data FROM message_cache 
        WHERE user_id = ? AND cache_key = ?
    ");
    $stmt->execute([$userId, $cacheKey]);
    $cache = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cache) {
        echo json_encode([
            'success' => true,
            'messages' => json_decode($cache['messages_data'], true),
            'cached' => true
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'messages' => [],
            'cached' => false
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}
?>