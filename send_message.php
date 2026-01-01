<?php
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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
        $token = $input['token'] ?? '';
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

$input = json_decode(file_get_contents('php://input'), true);
$group_id = intval($input['group_id'] ?? 0);
$message = trim($input['message'] ?? '');

if (!$group_id || !$message) {
    echo json_encode(['success' => false, 'error' => 'Need group_id and message']);
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
    
    // Проверяем, является ли группа каналом и имеет ли пользователь право писать
    $stmt = $pdo->prepare("SELECT is_channel FROM chat_groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch();
    
    if ($group['is_channel'] && !in_array($member['role'], ['owner', 'admin'])) {
        echo json_encode(['success' => false, 'error' => 'Only admins can post in channels']);
        exit;
    }
    
    $pdo->beginTransaction();
    
    // Вставляем сообщение
    $stmt = $pdo->prepare("
        INSERT INTO group_messages (group_id, sender_id, message) 
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$group_id, $user_id, $message]);
    $message_id = $pdo->lastInsertId();
    $created_at = date('Y-m-d H:i:s');
    
    // Обновляем время обновления группы
    $stmt = $pdo->prepare("
        UPDATE chat_groups 
        SET updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$group_id]);
    
    $pdo->commit();
    
    // Получаем данные отправленного сообщения
    $stmt = $pdo->prepare("
        SELECT gm.*, u.username as sender_name, u.display_name as sender_display_name
        FROM group_messages gm
        JOIN users u ON gm.sender_id = u.id
        WHERE gm.id = ?
    ");
    $stmt->execute([$message_id]);
    $sent_message = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'sent_at' => $created_at,
        'message' => $sent_message
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Send group message error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>