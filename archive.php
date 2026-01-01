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
$chat_type = $input['chat_type'] ?? 'private';
$target_id = intval($input['target_id'] ?? 0);
$action = $input['action'] ?? 'archive'; // 'archive' или 'unarchive'

if (!$target_id) {
    echo json_encode(['success' => false, 'error' => 'Need target_id']);
    exit;
}

try {
    if ($action === 'archive') {
        // Проверяем, не в архиве ли уже чат
        $stmt = $pdo->prepare("
            SELECT id FROM archived_chats 
            WHERE user_id = ? AND chat_type = ? AND target_id = ?
        ");
        $stmt->execute([$user_id, $chat_type, $target_id]);
        
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Chat already archived']);
            exit;
        }
        
        // Добавляем в архив
        $stmt = $pdo->prepare("
            INSERT INTO archived_chats (user_id, chat_type, target_id) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$user_id, $chat_type, $target_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Chat archived successfully'
        ]);
    } else {
        // Удаляем из архива
        $stmt = $pdo->prepare("
            DELETE FROM archived_chats 
            WHERE user_id = ? AND chat_type = ? AND target_id = ?
        ");
        $stmt->execute([$user_id, $chat_type, $target_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Chat unarchived successfully'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Archive chat error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>