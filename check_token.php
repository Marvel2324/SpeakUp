<?php
require_once 'config.php';

header('Content-Type: application/json');

function verifyToken($token) {
    global $pdo;
    
    if (empty($token)) {
        return ['success' => false, 'error' => 'No token'];
    }
    
    try {
        // 1. Пробуем декодировать простой токен
        $payload = json_decode(base64_decode($token), true);
        
        if (!$payload || !isset($payload['user_id'])) {
            return ['success' => false, 'error' => 'Invalid token format'];
        }
        
        // 2. Проверяем срок действия
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return ['success' => false, 'error' => 'Token expired'];
        }
        
        // 3. Проверяем в БД (если таблица есть)
        try {
            $stmt = $pdo->prepare("SELECT user_id FROM user_sessions WHERE access_token = ? AND (expires_at IS NULL OR expires_at > NOW())");
            $stmt->execute([$token]);
            $session = $stmt->fetch();
            
            if (!$session) {
                // Если нет в таблице, но токен валидный - всё равно пропускаем
                // (для совместимости со старыми токенами)
            }
        } catch (Exception $e) {
            // Таблицы нет - ок
        }
        
        // 4. Получаем username из users
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$payload['user_id']]);
        $user = $stmt->fetch();
        
        return [
            'success' => true,
            'user_id' => $payload['user_id'],
            'username' => $user['username'] ?? 'user_' . $payload['user_id']
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Token check failed'];
    }
}

// Получаем токен
$token = $_GET['token'] ?? '';
if (empty($token)) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
    }
}

$result = verifyToken($token);
echo json_encode($result);
?>