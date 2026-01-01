<?php
// api/contacts/add.php
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
$contact_id = intval($input['contact_id'] ?? 0);

if (!$contact_id) {
    echo json_encode(['success' => false, 'error' => 'Need contact_id']);
    exit;
}

if ($user_id == $contact_id) {
    echo json_encode(['success' => false, 'error' => 'Cannot add yourself']);
    exit;
}

try {
    // Проверяем, не добавлен ли уже
    $stmt = $pdo->prepare("
        SELECT id FROM user_contacts 
        WHERE user_id = ? AND contact_id = ?
    ");
    $stmt->execute([$user_id, $contact_id]);
    
    if ($stmt->fetch()) {
        echo json_encode([
            'success' => false, 
            'error' => 'Already in contacts',
            'already' => true
        ]);
        exit;
    }
    
    // Добавляем в контакты
    $stmt = $pdo->prepare("
        INSERT INTO user_contacts (user_id, contact_id, created_at) 
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$user_id, $contact_id]);
    
    // Создаем чат автоматически (если его еще нет)
    $stmt = $pdo->prepare("
        SELECT chat_id FROM user_chats 
        WHERE (user1_id = ? AND user2_id = ?) 
           OR (user1_id = ? AND user2_id = ?)
    ");
    $stmt->execute([$user_id, $contact_id, $contact_id, $user_id]);
    
    if (!$stmt->fetch()) {
        // Создаем чат
        $stmt = $pdo->prepare("
            INSERT INTO user_chats (user1_id, user2_id, created_at) 
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$user_id, $contact_id]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Contact added successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Add contact error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Database error',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>