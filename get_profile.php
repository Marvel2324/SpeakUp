<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Получаем токен
$token = $_GET['token'] ?? '';
if (empty($token)) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
    }
}

if (empty($token)) {
    echo json_encode(['success' => false, 'error' => 'No token']);
    exit;
}

$payload = json_decode(base64_decode($token), true);
if (!$payload || !isset($payload['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$current_user_id = $payload['user_id'];
$requested_user_id = intval($_GET['user_id'] ?? 0);

if (!$requested_user_id) {
    echo json_encode(['success' => false, 'error' => 'No user_id provided']);
    exit;
}

try {
    // Получаем данные пользователя с ролью
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            username, 
            email, 
            display_name, 
            avatar_url, 
            status, 
            bio, 
            last_seen, 
            created_at,
            role
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$requested_user_id]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Форматируем last_seen
        if ($user['status'] === 'online') {
            $user['last_seen_text'] = 'онлайн';
        } else if ($user['last_seen']) {
            $lastSeen = new DateTime($user['last_seen']);
            $now = new DateTime();
            $diff = $now->diff($lastSeen);
            
            if ($diff->days > 0) {
                $user['last_seen_text'] = $diff->days . ' дн. назад';
            } else if ($diff->h > 0) {
                $user['last_seen_text'] = $diff->h . ' ч. назад';
            } else if ($diff->i > 5) {
                $user['last_seen_text'] = $diff->i . ' мин. назад';
            } else {
                $user['last_seen_text'] = 'только что';
            }
        } else {
            $user['last_seen_text'] = 'давно';
        }
        
        // Убедимся, что роль есть
        if (!isset($user['role'])) {
            $user['role'] = 'user';
        }
        
        echo json_encode([
            'success' => true,
            'user' => $user
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'error' => 'User not found']);
    }
    
} catch (Exception $e) {
    error_log("Get profile error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>