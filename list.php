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

$user_id = $payload['user_id'];

try {
    // ОБНОВЛЯЕМ СТАТУС
    $updateStmt = $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
    $updateStmt->execute([$user_id]);
    
    // ВАЖНО: Получаем ВСЕХ пользователей, сортируем по времени последнего сообщения
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.username,
            u.display_name,
            u.avatar_url,
            u.status,
            u.last_seen,
            u.created_at,
            -- Последнее сообщение
            (SELECT m.message FROM messages m 
             WHERE ((m.sender_id = u.id AND m.receiver_id = ?) 
                OR (m.sender_id = ? AND m.receiver_id = u.id))
             ORDER BY m.created_at DESC LIMIT 1) as last_message,
            -- Время последнего сообщения (ВОТ ЭТО ВАЖНО!)
            (SELECT MAX(m.created_at) FROM messages m 
             WHERE (m.sender_id = u.id AND m.receiver_id = ?) 
                OR (m.sender_id = ? AND m.receiver_id = u.id)) as last_message_time,
            -- Непрочитанные сообщения
            (SELECT COUNT(*) FROM messages m 
             WHERE m.sender_id = u.id AND m.receiver_id = ? AND m.is_read = 0) as unread_count
        FROM users u
        WHERE u.id != ? 
        ORDER BY 
            -- Сначала те, у кого есть last_message_time (есть диалоги)
            CASE 
                WHEN last_message_time IS NOT NULL THEN 1
                ELSE 2
            END,
            -- Затем сортируем по last_message_time (сначала новые диалоги)
            COALESCE(last_message_time, '1970-01-01') DESC,
            -- Затем по алфавиту
            u.username ASC
    ");
    
    $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Форматируем данные
    foreach ($users as &$user) {
        // Статус
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
            } else if ($diff->i > 0) {
                $user['last_seen_text'] = $diff->i . ' мин. назад';
            } else {
                $user['last_seen_text'] = 'только что';
            }
        } else {
            $user['last_seen_text'] = 'давно';
        }
        
        // Если нет last_message
        if (empty($user['last_message'])) {
            $user['last_message'] = 'Начните диалог';
        }
    }
    
    echo json_encode([
        'success' => true,
        'users' => $users,
        'count' => count($users)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Ошибка загрузки списка'
    ]);
}
?>