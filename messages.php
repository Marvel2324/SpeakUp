<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

$group_id = intval($_GET['group_id'] ?? 0);
$limit = intval($_GET['limit'] ?? 50);
$offset = intval($_GET['offset'] ?? 0);

if (!$group_id) {
    echo json_encode(['success' => false, 'error' => 'Need group_id']);
    exit;
}

try {
    // Проверяем, состоит ли пользователь в группе
    $stmt = $pdo->prepare("
        SELECT role FROM group_members 
        WHERE group_id = ? AND user_id = ?
    ");
    $stmt->execute([$group_id, $user_id]);
    $member = $stmt->fetch();
    
    if (!$member) {
        echo json_encode(['success' => false, 'error' => 'Not a member of this group']);
        exit;
    }
    
    // Получаем сообщения группы
    $stmt = $pdo->prepare("
        SELECT 
            gm.*,
            u.username as sender_name,
            u.display_name as sender_display_name,
            u.avatar_url as sender_avatar,
            CASE 
                WHEN gm.sender_id = ? THEN 1 
                ELSE 0 
            END as is_outgoing
        FROM group_messages gm
        JOIN users u ON gm.sender_id = u.id
        WHERE gm.group_id = ?
        ORDER BY gm.created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute([$user_id, $group_id, $limit, $offset]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Получаем информацию о группе
    $stmt = $pdo->prepare("
        SELECT g.*, gm.role as user_role
        FROM chat_groups g
        JOIN group_members gm ON g.id = gm.group_id
        WHERE g.id = ? AND gm.user_id = ?
    ");
    $stmt->execute([$group_id, $user_id]);
    $group = $stmt->fetch();
    
    // Получаем количество участников
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as member_count 
        FROM group_members 
        WHERE group_id = ?
    ");
    $stmt->execute([$group_id]);
    $member_count = $stmt->fetch()['member_count'];
    
    echo json_encode([
        'success' => true,
        'messages' => array_reverse($messages), // Самые старые первыми
        'group' => $group,
        'member_count' => $member_count,
        'user_role' => $member['role'],
        'count' => count($messages)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Group messages error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>