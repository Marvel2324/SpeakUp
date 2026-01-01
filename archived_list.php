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

// Функция получения токена
function getToken() {
    $token = '';
    
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth, 'Bearer ') === 0) {
            $token = substr($auth, 7);
        } else {
            $token = $auth;
        }
        $token = trim($token);
    }
    
    if (empty($token)) {
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? $_GET['token'] ?? '';
    }
    
    return $token;
}

$token = getToken();

if (empty($token)) {
    echo json_encode(['success' => false, 'error' => 'No token']);
    exit;
}

try {
    $decoded = base64_decode($token);
    if ($decoded === false) {
        $payload = json_decode($token, true);
        if (!$payload || !isset($payload['user_id'])) {
            throw new Exception('Cannot decode token');
        }
        $user_id = intval($payload['user_id']);
    } else {
        $payload = json_decode($decoded, true);
        if (!$payload || !isset($payload['user_id'])) {
            throw new Exception('Invalid token payload');
        }
        $user_id = intval($payload['user_id']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Invalid token: ' . $e->getMessage()]);
    exit;
}

try {
    // Получаем архивные приватные чаты
    $stmt = $pdo->prepare("
        SELECT 
            ac.*,
            u.id as user_id,
            u.username,
            u.display_name,
            u.avatar_url,
            u.status,
            u.last_seen,
            u.role,
            uc.chat_id
        FROM archived_chats ac
        LEFT JOIN users u ON (
            (ac.chat_type = 'private' AND ac.target_id = u.id)
        )
        LEFT JOIN user_chats uc ON (
            ac.chat_type = 'private' AND 
            ((uc.user1_id = ? AND uc.user2_id = ac.target_id) OR 
             (uc.user2_id = ? AND uc.user1_id = ac.target_id))
        )
        WHERE ac.user_id = ? AND ac.chat_type = 'private'
        ORDER BY ac.archived_at DESC
    ");
    
    $stmt->execute([$user_id, $user_id, $user_id]);
    $private_chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Получаем архивные группы
    $stmt = $pdo->prepare("
        SELECT 
            ac.*,
            g.id as group_id,
            g.name,
            g.description,
            g.avatar_url,
            g.is_channel,
            g.username as group_username,
            g.creator_id,
            g.created_at as group_created
        FROM archived_chats ac
        LEFT JOIN chat_groups g ON (
            ac.chat_type IN ('group', 'channel') AND ac.target_id = g.id
        )
        WHERE ac.user_id = ? AND ac.chat_type IN ('group', 'channel')
        ORDER BY ac.archived_at DESC
    ");
    
    $stmt->execute([$user_id]);
    $group_chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Форматируем приватные чаты
    foreach ($private_chats as &$chat) {
        if ($chat['status'] === 'online') {
            $chat['last_seen_text'] = 'онлайн';
        } else if ($chat['last_seen']) {
            $lastSeen = new DateTime($chat['last_seen']);
            $now = new DateTime();
            $diff = $now->diff($lastSeen);
            
            if ($diff->days > 0) {
                $chat['last_seen_text'] = $diff->days . ' дн. назад';
            } else if ($diff->h > 0) {
                $chat['last_seen_text'] = $diff->h . ' ч. назад';
            } else if ($diff->i > 5) {
                $chat['last_seen_text'] = $diff->i . ' мин. назад';
            } else {
                $chat['last_seen_text'] = 'только что';
            }
        } else {
            $chat['last_seen_text'] = 'давно';
        }
        
        // Получаем последнее сообщение
        $stmt2 = $pdo->prepare("
            SELECT message, created_at 
            FROM messages 
            WHERE chat_id = ?
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt2->execute([$chat['chat_id']]);
        $last_msg = $stmt2->fetch();
        
        $chat['last_message'] = $last_msg['message'] ?? 'Нет сообщений';
        $chat['last_message_time'] = $last_msg['created_at'] ?? null;
    }
    
    // Форматируем группы
    foreach ($group_chats as &$group) {
        // Получаем последнее сообщение группы
        $stmt2 = $pdo->prepare("
            SELECT gm.message, gm.created_at, u.display_name as sender_name
            FROM group_messages gm
            JOIN users u ON gm.sender_id = u.id
            WHERE gm.group_id = ?
            ORDER BY gm.created_at DESC 
            LIMIT 1
        ");
        $stmt2->execute([$group['target_id']]);
        $last_msg = $stmt2->fetch();
        
        $group['last_message'] = $last_msg ? 
            ($last_msg['sender_name'] . ': ' . $last_msg['message']) : 
            'Нет сообщений';
        $group['last_message_time'] = $last_msg['created_at'] ?? null;
        
        // Получаем количество участников
        $stmt3 = $pdo->prepare("
            SELECT COUNT(*) as member_count 
            FROM group_members 
            WHERE group_id = ?
        ");
        $stmt3->execute([$group['target_id']]);
        $count = $stmt3->fetch();
        $group['member_count'] = $count['member_count'] ?? 0;
    }
    
    echo json_encode([
        'success' => true,
        'private_chats' => $private_chats,
        'group_chats' => $group_chats,
        'count' => count($private_chats) + count($group_chats)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Archived chats list error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>