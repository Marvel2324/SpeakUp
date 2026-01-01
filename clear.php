<?php
header('Content-Type: application/json');
require_once '../config/database.php';

$token = $_POST['token'] ?? '';
$cacheKey = $_POST['cache_key'] ?? '';

if (empty($token)) {
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
    
    if ($cacheKey) {
        // Очищаем конкретный кэш
        $stmt = $pdo->prepare("
            DELETE FROM message_cache 
            WHERE user_id = ? AND cache_key = ?
        ");
        $stmt->execute([$userId, $cacheKey]);
    } else {
        // Очищаем весь кэш пользователя
        $stmt = $pdo->prepare("
            DELETE FROM message_cache WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
    }
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
}
?>