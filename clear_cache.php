<?php
header('Content-Type: application/json');
require_once '../config/database.php';

session_start();
$token = $_GET['token'] ?? '';

if (empty($token)) {
    echo json_encode(['success' => false, 'error' => 'Токен не предоставлен']);
    exit;
}

try {
    // Получаем пользователя по токену
    $stmt = $pdo->prepare("
        SELECT users.* FROM user_sessions 
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
    
    // Очищаем старые данные кэша в базе данных, если они есть
    // В данном случае мы просто возвращаем успешный ответ
    // так как основное кэширование происходит на клиенте
    
    echo json_encode([
        'success' => true,
        'message' => 'Кэш готов к очистке'
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных: ' . $e->getMessage()]);
}
?>